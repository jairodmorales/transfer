<?php

declare(strict_types=1);

namespace OCA\Transfer\Tests\Unit\Service;

use OCA\Transfer\Service\TransferUtils;
use PHPUnit\Framework\TestCase;

class TransferUtilsTest extends TestCase {

	// -------------------------------------------------------------------------
	// isValidRemoteUrl
	// -------------------------------------------------------------------------

	/** @dataProvider provideValidUrls */
	public function testIsValidRemoteUrlAcceptsHttpAndHttps(string $url): void {
		$this->assertTrue(TransferUtils::isValidRemoteUrl($url));
	}

	public static function provideValidUrls(): array {
		return [
			['http://example.com/file.pdf'],
			['https://example.com/file.pdf'],
			['https://example.com:8443/path/to/file'],
			['http://192.168.1.1/resource'],
			['https://user:pass@example.com/file'],  // credentials present but scheme+host valid
		];
	}

	/** @dataProvider provideInvalidUrls */
	public function testIsValidRemoteUrlRejectsNonHttp(string $url): void {
		$this->assertFalse(TransferUtils::isValidRemoteUrl($url));
	}

	public static function provideInvalidUrls(): array {
		return [
			['file:///etc/passwd'],
			['gopher://evil.com/'],
			['ftp://ftp.example.com/pub/file'],
			['data:text/html,<script>alert(1)</script>'],
			['javascript:alert(1)'],
			['/relative/path/file.txt'],
			['not-a-url'],
			[''],
			['http://'],          // empty host
			['https://'],         // empty host
		];
	}

	// -------------------------------------------------------------------------
	// isDomainBlocked
	// -------------------------------------------------------------------------

	public function testIsDomainBlockedExactMatch(): void {
		$this->assertTrue(TransferUtils::isDomainBlocked('evil.com', ['evil.com']));
	}

	public function testIsDomainBlockedExactMatchCaseInsensitive(): void {
		$this->assertTrue(TransferUtils::isDomainBlocked('Evil.COM', ['evil.com']));
	}

	public function testIsDomainBlockedWildcardMatchesSubdomain(): void {
		$this->assertTrue(TransferUtils::isDomainBlocked('sub.evil.com', ['*.evil.com']));
	}

	public function testIsDomainBlockedWildcardDoesNotMatchApex(): void {
		// *.evil.com should NOT match evil.com itself
		$this->assertFalse(TransferUtils::isDomainBlocked('evil.com', ['*.evil.com']));
	}

	public function testIsDomainBlockedWildcardDoesNotMatchUnrelated(): void {
		$this->assertFalse(TransferUtils::isDomainBlocked('notevilcom', ['*.evil.com']));
	}

	public function testIsDomainBlockedEmptyBlocklistNeverBlocks(): void {
		$this->assertFalse(TransferUtils::isDomainBlocked('evil.com', []));
	}

	public function testIsDomainBlockedIgnoresBlankEntries(): void {
		$this->assertFalse(TransferUtils::isDomainBlocked('safe.com', ['', '   ', "\t"]));
	}

	public function testIsDomainBlockedNoMatchReturnsfalse(): void {
		$this->assertFalse(TransferUtils::isDomainBlocked('safe.com', ['evil.com', '*.malicious.org']));
	}

	// -------------------------------------------------------------------------
	// integrityCheckPasses
	// -------------------------------------------------------------------------

	public function testIntegrityCheckPassesWithNoHash(): void {
		// Empty hash means "skip check" — always true regardless of file content
		$this->assertTrue(TransferUtils::integrityCheckPasses('', '', '/nonexistent/path'));
	}

	public function testIntegrityCheckPassesWithCorrectSha256(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'transfer_test_');
		file_put_contents($tmpFile, 'hello world');
		$expected = hash('sha256', 'hello world');

		try {
			$this->assertTrue(TransferUtils::integrityCheckPasses('sha256', $expected, $tmpFile));
		} finally {
			unlink($tmpFile);
		}
	}

	public function testIntegrityCheckPassesIsCaseInsensitive(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'transfer_test_');
		file_put_contents($tmpFile, 'hello world');
		$expected = strtoupper(hash('sha256', 'hello world'));

		try {
			$this->assertTrue(TransferUtils::integrityCheckPasses('sha256', $expected, $tmpFile));
		} finally {
			unlink($tmpFile);
		}
	}

	public function testIntegrityCheckFailsWithWrongHash(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'transfer_test_');
		file_put_contents($tmpFile, 'hello world');

		try {
			$this->assertFalse(TransferUtils::integrityCheckPasses('sha256', 'deadbeef', $tmpFile));
		} finally {
			unlink($tmpFile);
		}
	}

	public function testIntegrityCheckFailsWithMissingFile(): void {
		$this->assertFalse(
			TransferUtils::integrityCheckPasses('sha256', 'abc123', '/nonexistent/file/path')
		);
	}

	public function testIntegrityCheckPassesWithMd5(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'transfer_test_');
		file_put_contents($tmpFile, 'test content');
		$expected = md5('test content');

		try {
			$this->assertTrue(TransferUtils::integrityCheckPasses('md5', $expected, $tmpFile));
		} finally {
			unlink($tmpFile);
		}
	}

	// -------------------------------------------------------------------------
	// sanitizeUrlForLog
	// -------------------------------------------------------------------------

	public function testSanitizeUrlForLogStripsCredentials(): void {
		$result = TransferUtils::sanitizeUrlForLog('https://user:secret@example.com/path');
		$this->assertSame('https://example.com/path', $result);
		$this->assertStringNotContainsString('secret', $result);
	}

	public function testSanitizeUrlForLogPreservesCleanUrl(): void {
		$url = 'https://example.com/path/to/file.pdf';
		$this->assertSame($url, TransferUtils::sanitizeUrlForLog($url));
	}

	public function testSanitizeUrlForLogPreservesPort(): void {
		$result = TransferUtils::sanitizeUrlForLog('https://example.com:8443/path');
		$this->assertSame('https://example.com:8443/path', $result);
	}

	public function testSanitizeUrlForLogHandlesInvalidUrl(): void {
		$this->assertSame('[invalid URL]', TransferUtils::sanitizeUrlForLog('not a url'));
	}

	// -------------------------------------------------------------------------
	// sanitizeErrorMessage
	// -------------------------------------------------------------------------

	public function testSanitizeErrorMessageStripsCredentials(): void {
		$msg = 'cURL error 6: Could not connect to https://user:pass@evil.com/file';
		$result = TransferUtils::sanitizeErrorMessage($msg);
		$this->assertStringNotContainsString('user:pass@', $result);
		$this->assertStringContainsString('https://evil.com/file', $result);
	}

	public function testSanitizeErrorMessageLeavesCleanMessageUntouched(): void {
		$msg = 'Connection refused: example.com port 443';
		$this->assertSame($msg, TransferUtils::sanitizeErrorMessage($msg));
	}
}
