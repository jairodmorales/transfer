<?php

declare(strict_types=1);

namespace OCA\Transfer\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\BackgroundJob\IJobList;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\LocalServerException;
use OCP\IRequest;
use OCP\Security\ISecureRandom;

use OCA\Transfer\BackgroundJob\TransferJob;
use OCA\Transfer\Db\TransferJobEntity;
use OCA\Transfer\Db\TransferJobMapper;
use OCA\Transfer\Service\TransferService;

class TransferController extends Controller {
	private string $userId;
	private IJobList $jobList;
	private IClientService $clientService;
	private TransferService $service;
	private ISecureRandom $secureRandom;
	private TransferJobMapper $mapper;

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
		string $UserId,
	) {
		parent::__construct($AppName, $request);
		$this->userId = $UserId;
		$this->jobList = $jobList;
		$this->clientService = $clientService;
		$this->service = $service;
		$this->secureRandom = $secureRandom;
		$this->mapper = $mapper;
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
		if (basename($path) === '') {
			return new DataResponse('File name is required', Http::STATUS_BAD_REQUEST);
		}

		// Reject traversal sequences and null bytes before the path reaches the
		// filesystem layer. Nextcloud's virtual FS also rejects these, but
		// doing it here avoids queuing a job that will fail immediately.
		if (str_contains($path, '..') || str_contains($path, "\0")) {
			return new DataResponse('Invalid path', Http::STATUS_BAD_REQUEST);
		}

		if (!$this->isValidRemoteUrl($url)) {
			return new DataResponse('Only http and https URLs are supported', Http::STATUS_BAD_REQUEST);
		}

		if ($hash !== '' && $hashAlgo === '') {
			return new DataResponse('A hash algorithm is required when a checksum is provided', Http::STATUS_BAD_REQUEST);
		}

		if ($hashAlgo !== '' && !in_array($hashAlgo, ['md5', 'sha1', 'sha256', 'sha512'], true)) {
			return new DataResponse('Unsupported hash algorithm', Http::STATUS_BAD_REQUEST);
		}

		// The token is the shared secret between the browser session and the
		// background job. It is stored in the DB and returned to the frontend
		// so it can poll /ajax/status.php for progress.
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
	 * Return the status of recent transfer jobs for the current user.
	 *
	 * The frontend polls this endpoint to update the progress panel. Only jobs
	 * created within the last 24 hours are returned, keeping the response small.
	 *
	 * @return DataResponse<Http::STATUS_OK, list<array{token: string, path: string, status: string, error: ?string, createdAt: int}>, array{}>
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function status(): DataResponse {
		$since = time() - 86400; // 24 hours
		$jobs = $this->mapper->findRecentByUser($this->userId, $since);

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
		if (!$this->isValidRemoteUrl($url)) {
			return new DataResponse('Only http and https URLs are supported', Http::STATUS_BAD_REQUEST);
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
	 * Returns true only for absolute http/https URLs with a non-empty host.
	 *
	 * Rejects file://, gopher://, data: and other non-HTTP schemes that could
	 * be used to read local files or probe internal services (SSRF).
	 */
	private function isValidRemoteUrl(string $url): bool {
		$parsed = parse_url($url);
		return $parsed !== false
			&& isset($parsed['scheme'], $parsed['host'])
			&& $parsed['host'] !== ''
			&& in_array($parsed['scheme'], ['http', 'https'], true);
	}
}
