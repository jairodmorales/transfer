<?php

declare(strict_types=1);

namespace OCA\Transfer\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\BackgroundJob\IJobList;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\LocalServerException;
use OCP\IRequest;
use OCP\Security\ISecureRandom;

use OCA\Transfer\BackgroundJob\TransferJob;
use OCA\Transfer\Db\TransferJobEntity;
use OCA\Transfer\Db\TransferJobMapper;
use OCA\Transfer\Service\TransferService;
use OCA\Transfer\Service\TransferUtils;

class TransferController extends Controller {
	private string $userId;
	private IJobList $jobList;
	private IClientService $clientService;
	private TransferService $service;
	private ISecureRandom $secureRandom;
	private TransferJobMapper $mapper;
	private IAppConfig $appConfig;

	/**
	 * Maps MIME types returned by Content-Type headers to file extensions.
	 * Used by the probe endpoint to suggest a filename when the URL path
	 * does not include a recognisable extension.
	 *
	 * @var array<string, string>
	 */
	private const MIME_EXTENSIONS = [
		'image/jpeg' => 'jpg',
		'image/png' => 'png',
		'image/gif' => 'gif',
		'image/webp' => 'webp',
		'image/svg+xml' => 'svg',
		'image/bmp' => 'bmp',
		'image/tiff' => 'tiff',
		'application/pdf' => 'pdf',
		'application/zip' => 'zip',
		'application/gzip' => 'gz',
		'application/x-tar' => 'tar',
		'application/x-bzip2' => 'bz2',
		'application/x-xz' => 'xz',
		'application/x-7z-compressed' => '7z',
		'application/x-rar-compressed' => 'rar',
		'application/json' => 'json',
		'application/xml' => 'xml',
		'application/javascript' => 'js',
		'text/html' => 'html',
		'text/plain' => 'txt',
		'text/css' => 'css',
		'text/csv' => 'csv',
		'audio/mpeg' => 'mp3',
		'audio/ogg' => 'ogg',
		'audio/flac' => 'flac',
		'audio/wav' => 'wav',
		'video/mp4' => 'mp4',
		'video/webm' => 'webm',
		'video/x-matroska' => 'mkv',
		'application/ogg' => 'ogx',
	];

	public function __construct(
		string $AppName,
		IRequest $request,
		IJobList $jobList,
		IClientService $clientService,
		TransferService $service,
		ISecureRandom $secureRandom,
		TransferJobMapper $mapper,
		IAppConfig $appConfig,
		string $UserId,
	) {
		parent::__construct($AppName, $request);
		$this->userId = $UserId;
		$this->jobList = $jobList;
		$this->clientService = $clientService;
		$this->service = $service;
		$this->secureRandom = $secureRandom;
		$this->mapper = $mapper;
		$this->appConfig = $appConfig;
	}

	/**
	 * Queue a background download of a remote file into the user's storage.
	 *
	 * Returns a token that the client can pass to the status endpoint to track
	 * progress without polling the Activity log.
	 *
	 * @param string $path     Destination path within the user's file space (e.g. /Documents/report.pdf)
	 * @param string $url      Remote URL to download — http and https only
	 * @param string $hashAlgo Optional algorithm to verify integrity: md5, sha1, sha256, sha512
	 * @param string $hash     Optional expected checksum value (hex string, case-insensitive)
	 *
	 * @return DataResponse<Http::STATUS_OK, array{token: string}, array{}>
	 *       | DataResponse<Http::STATUS_BAD_REQUEST, string, array{}>
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function transfer(string $path, string $url, string $hashAlgo, string $hash): DataResponse {
		$error = $this->validateTransferInput($path, $url, $hashAlgo, $hash);
		if ($error !== null) {
			return $error;
		}

		$token = $this->secureRandom->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);

		$now = time();
		$entity = new TransferJobEntity();
		$entity->setToken($token);
		$entity->setUserId($this->userId);
		$entity->setUrl($url);
		$entity->setPath($path);
		$entity->setStatus(TransferJobEntity::STATUS_QUEUED);
		$entity->setCreatedAt($now);
		$entity->setUpdatedAt($now);
		$this->mapper->insert($entity);

		$this->jobList->add(TransferJob::class, [
			'userId'   => $this->userId,
			'path'     => $path,
			'url'      => $url,
			'hashAlgo' => $hashAlgo,
			'hash'     => $hash,
			'token'    => $token,
		]);

		return new DataResponse(['token' => $token], Http::STATUS_OK);
	}

	/**
	 * Queue multiple background downloads at once.
	 *
	 * Accepts an array of transfer objects and enqueues each one as a separate
	 * background job. Returns an array of tokens in the same order so the
	 * frontend can track each job independently.
	 *
	 * The maximum number of transfers per call is governed by the admin setting
	 * `max_urls` (default 3, maximum 10).
	 *
	 * @param list<array{url: string, path: string, hashAlgo: string, hash: string}> $transfers
	 *
	 * @return DataResponse<Http::STATUS_OK, array{jobs: list<array{token: string, path: string}>}, array{}>
	 *       | DataResponse<Http::STATUS_BAD_REQUEST, string, array{}>
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 60)]
	public function batch(array $transfers): DataResponse {
		$maxUrls = $this->appConfig->getAppValueInt('max_urls', 3);

		if (empty($transfers)) {
			return new DataResponse('At least one transfer is required', Http::STATUS_BAD_REQUEST);
		}

		if (count($transfers) > $maxUrls) {
			return new DataResponse(
				sprintf('Maximum %d URLs are allowed per request', $maxUrls),
				Http::STATUS_BAD_REQUEST,
			);
		}

		// Validate all items first so no DB rows are inserted if any item fails.
		// This makes the operation atomic: all-or-nothing, no orphaned rows.
		// Parse the blocklist once here so it is not re-read from config per item.
		$blocklist = $this->getBlocklist();
		$validated = [];
		foreach ($transfers as $transfer) {
			$path     = (string) ($transfer['path']     ?? '');
			$url      = (string) ($transfer['url']      ?? '');
			$hashAlgo = (string) ($transfer['hashAlgo'] ?? '');
			$hash     = (string) ($transfer['hash']     ?? '');

			$error = $this->validateTransferInput($path, $url, $hashAlgo, $hash, $blocklist);
			if ($error !== null) {
				return $error;
			}

			$validated[] = compact('path', 'url', 'hashAlgo', 'hash');
		}

		$now = time();
		$jobs = [];
		foreach ($validated as ['path' => $path, 'url' => $url, 'hashAlgo' => $hashAlgo, 'hash' => $hash]) {
			$token = $this->secureRandom->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);

			$entity = new TransferJobEntity();
			$entity->setToken($token);
			$entity->setUserId($this->userId);
			$entity->setUrl($url);
			$entity->setPath($path);
			$entity->setStatus(TransferJobEntity::STATUS_QUEUED);
			$entity->setCreatedAt($now);
			$entity->setUpdatedAt($now);
			$this->mapper->insert($entity);

			$this->jobList->add(TransferJob::class, [
				'userId'   => $this->userId,
				'path'     => $path,
				'url'      => $url,
				'hashAlgo' => $hashAlgo,
				'hash'     => $hash,
				'token'    => $token,
			]);

			$jobs[] = ['token' => $token, 'path' => $path];
		}

		return new DataResponse(['jobs' => $jobs], Http::STATUS_OK);
	}

	/**
	 * Return the status of recent transfer jobs for the current user.
	 *
	 * Callers may pass ?since=<unix_timestamp> to narrow the window (e.g. the
	 * last hour on page load). Default is 24 h so the panel works without any
	 * parameter.
	 *
	 * @return DataResponse<Http::STATUS_OK, list<array{token: string, path: string, status: string, error: ?string, createdAt: int}>, array{}>
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function status(int $since = 0): DataResponse {
		$cutoff = $since > 0 ? $since : time() - 24 * 3600;
		$jobs = $this->mapper->findRecentByUser($this->userId, $cutoff);

		$data = array_map(static fn (TransferJobEntity $job): array => [
			'token'     => $job->getToken(),
			'path'      => $job->getPath(),
			'status'    => $job->getStatus(),
			'error'     => $job->getError(),
			'createdAt' => $job->getCreatedAt(),
		], $jobs);

		return new DataResponse($data, Http::STATUS_OK);
	}

	/**
	 * Probe a URL server-side to suggest a file extension from its Content-Type.
	 *
	 * Called by the frontend while the user fills in the URL field so that the
	 * filename can be pre-populated. Returns an empty extension string on any
	 * failure so the user can still type the filename manually.
	 *
	 * CSRF protection is kept enabled intentionally. The frontend sends the
	 * Nextcloud request token via @nextcloud/axios, which prevents a malicious
	 * third-party site from using this endpoint to probe internal services
	 * (cross-site HEAD requests would not carry the session token).
	 *
	 * @param string $url Remote URL to probe — http and https only
	 *
	 * @return DataResponse<Http::STATUS_OK, array{extension: string}, array{}>
	 *       | DataResponse<Http::STATUS_BAD_REQUEST, string, array{}>
	 *       | DataResponse<Http::STATUS_FORBIDDEN, array{extension: string}, array{}>
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function probe(string $url): DataResponse {
		if (!TransferUtils::isValidRemoteUrl($url)) {
			return new DataResponse('Only http and https URLs are supported', Http::STATUS_BAD_REQUEST);
		}

		$host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
		if ($host !== '' && TransferUtils::isDomainBlocked($host, $this->getBlocklist())) {
			return new DataResponse(['extension' => ''], Http::STATUS_FORBIDDEN);
		}

		try {
			$client = $this->clientService->newClient();
			$response = $client->head($url, ['timeout' => 10]);
			$contentType = $response->getHeader('Content-Type');
			// Strip parameters such as "; charset=utf-8" before the MIME lookup
			$mime = strtolower(trim(explode(';', $contentType)[0]));
			$extension = self::MIME_EXTENSIONS[$mime] ?? '';
			return new DataResponse(['extension' => $extension], Http::STATUS_OK);
		} catch (LocalServerException $e) {
			return new DataResponse(['extension' => ''], Http::STATUS_FORBIDDEN);
		} catch (\Exception $e) {
			return new DataResponse(['extension' => ''], Http::STATUS_OK);
		}
	}

	/**
	 * Parse the admin-configured domain blocklist into an array of normalised entries.
	 * Stored as a newline-delimited string; blank lines and surrounding whitespace are
	 * stripped and entries are lowercased so isDomainBlocked() receives clean input.
	 *
	 * @return string[]
	 */
	private function getBlocklist(): array {
		$raw = $this->appConfig->getAppValueString('domain_blocklist', '');
		if ($raw === '') {
			return [];
		}
		return array_values(array_filter(array_map(
			static fn(string $e): string => strtolower(trim($e)),
			explode("\n", $raw)
		)));
	}

	/**
	 * Validate a single transfer input tuple.
	 *
	 * Returns a 400 DataResponse if any constraint is violated, null otherwise.
	 * Pass a pre-computed $blocklist when calling from a loop to avoid re-parsing
	 * the config on every iteration.
	 *
	 * @param string[] $blocklist Pre-parsed domain blocklist (defaults to reading config)
	 * @return DataResponse<Http::STATUS_BAD_REQUEST, string, array{}>|null
	 */
	private function validateTransferInput(string $path, string $url, string $hashAlgo, string $hash, array $blocklist = []): ?DataResponse {
		if (basename($path) === '') {
			return new DataResponse('File name is required', Http::STATUS_BAD_REQUEST);
		}

		// Reject traversal sequences and null bytes before the path reaches the
		// filesystem layer. Nextcloud's virtual FS also rejects these, but
		// doing it here avoids queuing a job that will fail immediately.
		if (str_contains($path, '..') || str_contains($path, "\0")) {
			return new DataResponse('Invalid path', Http::STATUS_BAD_REQUEST);
		}

		if (!TransferUtils::isValidRemoteUrl($url)) {
			return new DataResponse('Only http and https URLs are supported', Http::STATUS_BAD_REQUEST);
		}

		$list = $blocklist !== [] ? $blocklist : $this->getBlocklist();
		$host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
		if ($host !== '' && TransferUtils::isDomainBlocked($host, $list)) {
			return new DataResponse('Domain is blocked by administrator', Http::STATUS_BAD_REQUEST);
		}

		if ($hash !== '' && $hashAlgo === '') {
			return new DataResponse('A hash algorithm is required when a checksum is provided', Http::STATUS_BAD_REQUEST);
		}

		if ($hashAlgo !== '' && !in_array($hashAlgo, ['md5', 'sha1', 'sha256', 'sha512'], true)) {
			return new DataResponse('Unsupported hash algorithm', Http::STATUS_BAD_REQUEST);
		}

		return null;
	}
}
