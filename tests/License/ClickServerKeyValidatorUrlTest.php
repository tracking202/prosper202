<?php

declare(strict_types=1);

namespace Tests\License;

use PHPUnit\Framework\TestCase;
use Prosper202\License\ClickServerKeyValidator;

/**
 * The licence URL override is a CI test seam: loopback only, else ignored.
 */
final class ClickServerKeyValidatorUrlTest extends TestCase
{
    private const REAL = 'https://my.tracking202.com/api/v2/validate-customers-key';

    protected function tearDown(): void
    {
        putenv('P202_LICENSE_VALIDATE_URL');
    }

    public function testDefaultsToTheRealEndpoint(): void
    {
        putenv('P202_LICENSE_VALIDATE_URL');
        self::assertSame(self::REAL, ClickServerKeyValidator::url());
    }

    /** @return array<string, array{string}> */
    public static function loopback(): array
    {
        return [
            'ipv4' => ['http://127.0.0.1:8099/'],
            'localhost' => ['http://localhost:8099/validate'],
            'ipv6' => ['http://[::1]:8099/'],
            'https loopback' => ['https://127.0.0.1/'],
        ];
    }

    /**
     * @dataProvider loopback
     */
    public function testLoopbackOverrideIsHonoured(string $url): void
    {
        putenv('P202_LICENSE_VALIDATE_URL=' . $url);
        self::assertSame($url, ClickServerKeyValidator::url());
    }

    /** @return array<string, array{string}> */
    public static function notLoopback(): array
    {
        return [
            'other host' => ['https://licence.example.com/'],
            'loopback-looking subdomain' => ['http://127.0.0.1.attacker.example/'],
            'localhost-looking subdomain' => ['http://localhost.attacker.example/'],
            'userinfo trick' => ['http://localhost@attacker.example/'],
            'non-http scheme' => ['file://localhost/etc/passwd'],
            'garbage' => ['not a url'],
        ];
    }

    /**
     * @dataProvider notLoopback
     */
    public function testNonLoopbackOverrideIsIgnored(string $url): void
    {
        putenv('P202_LICENSE_VALIDATE_URL=' . $url);
        self::assertSame(self::REAL, ClickServerKeyValidator::url());
    }
}
