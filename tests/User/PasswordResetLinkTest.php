<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;
use Prosper202\User\PasswordResetLink;

/**
 * The password-reset email links to this install's stored address, whatever
 * Host the request that asked for it carried (PasswordResetLink). The request
 * is a stranger's to write; the email reaches the account's owner.
 */
final class PasswordResetLinkTest extends TestCase
{
    /** A request whose Host and SERVER_NAME are the attacker's, as Apache's default makes them. */
    private static function hostile(array $extra = []): array
    {
        return $extra + [
            'HTTP_HOST' => 'attacker.example',
            'SERVER_NAME' => 'attacker.example',
            'SERVER_PORT' => '80',
            'DOCUMENT_ROOT' => dirname(__DIR__, 2),
        ];
    }

    public function testTheLinkIsTheStoredAddressNotTheRequestsHost(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertSame('http://127.0.0.1:8131/', PasswordResetLink::base('http://127.0.0.1:8131', self::hostile(), $root));
        self::assertSame('https://track.example.com/', PasswordResetLink::base('https://track.example.com/', self::hostile(), $root));
        // A stored host without a scheme takes the request's scheme, and still its own host.
        self::assertSame('https://track.example.com/', PasswordResetLink::base('track.example.com', self::hostile(['HTTPS' => 'on']), $root));
        self::assertSame('http://track.example.com/', PasswordResetLink::base('track.example.com', self::hostile(), $root));
    }

    public function testNoStoredAddressIsNoLink(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['', '   ', 'https://', '///', 'not a host'] as $stored) {
            self::assertNull(PasswordResetLink::base($stored, self::hostile(), $root), var_export($stored, true));
        }
    }

    public function testTheInstallDirectoryIsKept(): void
    {
        $root = dirname(__DIR__, 2);
        $server = self::hostile(['DOCUMENT_ROOT' => dirname($root)]);
        self::assertSame('http://track.example.com/' . basename($root) . '/', PasswordResetLink::base('http://track.example.com', $server, $root));
    }

    public function testTheHostIsWithoutItsPort(): void
    {
        self::assertSame('127.0.0.1', PasswordResetLink::host('http://127.0.0.1:8131/'));
        self::assertSame('track.example.com', PasswordResetLink::host('https://track.example.com/p202/'));
    }

    /**
     * The page builds its email from PasswordResetLink and from nothing that
     * reads the request's host: not the server name or Host header directly
     * (RequestHostSourceTest), and not a helper that does — the request
     * origin, or getTrackingDomain(), which falls back to SERVER_NAME.
     */
    public function testThePageTakesNoHostFromTheRequest(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/202-lost-pass.php');
        self::assertStringContainsString('PasswordResetLink::base(', $source);
        foreach (['forRequest(', 'requestUrl(', 'requestOrigin(', 'requestHost(', 'RequestHost::', 'getTrackingDomain(', 'HTTP_HOST', 'SERVER_NAME', 'HTTP_X_FORWARDED_HOST'] as $reader) {
            self::assertStringNotContainsString($reader, $source, "202-lost-pass.php reads the request's host through $reader");
        }
    }
}
