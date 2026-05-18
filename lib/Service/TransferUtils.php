<?php

declare(strict_types=1);

namespace OCA\Transfer\Service;

/**
 * Pure-function utilities extracted from TransferController and TransferService.
 * No Nextcloud dependencies — safe to unit-test without the full NC stack.
 */
class TransferUtils {
	/**
	 * Returns true only for absolute http/https URLs with a non-empty host.
	 * Rejects file://, gopher://, data: and other non-HTTP schemes (SSRF).
	 */
	public static function isValidRemoteUrl(string $url): bool {
		$parsed = parse_url($url);
		return $parsed !== false
			&& isset($parsed['scheme'], $parsed['host'])
			&& $parsed['host'] !== ''
			&& in_array($parsed['scheme'], ['http', 'https'], true);
	}

	/**
	 * Returns true if the host matches any entry in the blocklist.
	 * Supports exact matches ("evil.com") and wildcard subdomains ("*.evil.com").
	 * Matching is case-insensitive; blank entries are skipped.
	 *
	 * @param string[] $blocklist
	 */
	public static function isDomainBlocked(string $host, array $blocklist): bool {
		$host = strtolower($host);
		foreach ($blocklist as $entry) {
			$entry = strtolower(trim($entry));
			if ($entry === '') {
				continue;
			}
			if (str_starts_with($entry, '*.')) {
				// "*.evil.com" matches "sub.evil.com" but not "evil.com" itself
				$suffix = substr($entry, 1); // ".evil.com"
				if (str_ends_with($host, $suffix)) {
					return true;
				}
			} elseif ($host === $entry) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns true if integrity verification is disabled or the file's hash matches.
	 * Uses hash_equals() for constant-time comparison; normalises to lowercase.
	 */
	public static function integrityCheckPasses(string $hashAlgo, string $hash, string $tmpPath): bool {
		if ($hash === '') {
			return true;
		}
		if (!is_readable($tmpPath)) {
			return false;
		}
		$computed = hash_file($hashAlgo, $tmpPath);
		if ($computed === false) {
			return false;
		}
		return hash_equals($computed, strtolower(trim($hash)));
	}

	/**
	 * Strip the userinfo component (user:password@) from a URL before logging.
	 */
	public static function sanitizeUrlForLog(string $url): string {
		$parsed = parse_url($url);
		if ($parsed === false || !isset($parsed['host'])) {
			return '[invalid URL]';
		}
		return ($parsed['scheme'] ?? 'https') . '://'
			. $parsed['host']
			. (isset($parsed['port']) ? ':' . $parsed['port'] : '')
			. ($parsed['path'] ?? '');
	}

	/**
	 * Strip embedded credentials from any URL-like strings in an exception message
	 * before storing in DB or logs. Guzzle errors may include the full request URL.
	 */
	public static function sanitizeErrorMessage(string $msg): string {
		return (string) preg_replace('#([a-z][a-z0-9+\-.]*://)([^@/\s]+@)#i', '$1', $msg);
	}
}
