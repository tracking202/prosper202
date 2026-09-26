<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\TrackingDomain;

/**
 * The stored tracking-domain preference is free text; every URL builder
 * prefixes a scheme and appends a path, so what they get must be host[:port].
 */
final class TrackingDomainTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function values(): array
    {
        return [
            'a bare host' => ['track.example.com', 'track.example.com'],
            'with a port' => ['127.0.0.1:8110', '127.0.0.1:8110'],
            'a pasted URL' => ['http://127.0.0.1:8110', '127.0.0.1:8110'],
            'https with a slash' => ['https://track.example.com/', 'track.example.com'],
            'with a path and query' => ['https://track.example.com/sub/dir?x=1#y', 'track.example.com'],
            'protocol-relative' => ['//track.example.com', 'track.example.com'],
            'surrounding space' => ["  track.example.com \n", 'track.example.com'],
            'credentials dropped' => ['https://user:pw@track.example.com', 'track.example.com'],
            'IPv6 literal' => ['http://[2001:db8::1]:8080/', '[2001:db8::1]:8080'],
            'empty' => ['', ''],
            'markup is not a host' => ['track.example.com"><script>', ''],
        ];
    }

    /** @dataProvider values */
    public function testWhatTheUrlBuildersGet(string $stored, string $expected): void
    {
        self::assertSame($expected, TrackingDomain::normalize($stored));
    }
}
