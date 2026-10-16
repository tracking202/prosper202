<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Prosper202\Http\StoredVisitorIp;

/**
 * The visitor's address as the click path stores it: VisitorIp's, masked
 * (/24, /48) when privacy holds back for the visitor.
 */
final class StoredVisitorIpTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function masks(): iterable
    {
        yield 'IPv4 keeps its /24' => ['203.0.113.77', '203.0.113.0'];
        yield 'IPv4 already masked' => ['203.0.113.0', '203.0.113.0'];
        yield 'IPv6 keeps its /48' => ['2001:db8:85a3:8d3:1319:8a2e:370:7348', '2001:db8:85a3::'];
        yield 'compressed IPv6' => ['2a00:1450:4001:80b::200e', '2a00:1450:4001::'];
        // The string version split on ':' and kept the wrong groups here.
        yield 'loopback IPv6' => ['::1', '::'];
        yield 'a short prefix before ::' => ['fe80::1:2:3:4', 'fe80::'];
        yield 'IPv4-mapped IPv6' => ['::ffff:203.0.113.77', '::'];
        yield 'nothing' => ['', ''];
        yield 'not an address' => ['nope', ''];
    }

    /** @dataProvider masks */
    public function testTheMask(string $address, string $expected): void
    {
        self::assertSame($expected, StoredVisitorIp::mask($address));
    }

    public function testPrivacyMasksTheVisitorsAddress(): void
    {
        $server = ['HTTP_X_FORWARDED_FOR' => '198.51.100.7', 'REMOTE_ADDR' => '10.0.0.1'];
        self::assertSame('198.51.100.7', StoredVisitorIp::fromServer($server, false));
        self::assertSame('198.51.100.0', StoredVisitorIp::fromServer($server, true));
        self::assertSame('', StoredVisitorIp::fromServer([], true));
    }

    public function testTheAddressGlobalsMaskIsTheSameMask(): void
    {
        // ipAddress() masks the $ip_address global the click path builds; it
        // must give what is stored. Run in a child PHP: the shared functions
        // would collide with other suites' stand-ins in this process.
        $root = dirname(__DIR__, 2);
        $code = 'require ' . var_export($root . '/vendor/autoload.php', true) . ';'
            . ' require ' . var_export($root . '/202-config/mysql-error-args.php', true) . ';'
            . ' require ' . var_export($root . '/202-config/functions-shared.php', true) . ';'
            . ' $out = [];'
            . ' foreach (["198.51.100.7", "2001:db8:85a3::8a2e:370:7334", "not an address", ""] as $a) {'
            . ' $out[] = [ipAddress($a, true)->address, ipAddress($a, false)->address, ipAddress($a, false)->type]; }'
            . ' try { ipAddress("198.51.100.7"); $out[] = "answered without saying whether to mask"; }'
            . ' catch (\\ArgumentCountError) { $out[] = "refused"; }'
            . ' echo json_encode($out);';
        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1');
        $seen = json_decode((string) $out, true);
        self::assertIsArray($seen, (string) $out);

        self::assertSame([StoredVisitorIp::mask('198.51.100.7'), '198.51.100.7', 'ipv4'], $seen[0]);
        self::assertSame(
            [StoredVisitorIp::mask('2001:db8:85a3::8a2e:370:7334'), '2001:db8:85a3::8a2e:370:7334', 'ipv6'],
            $seen[1]
        );
        self::assertSame(['0.0.0.0', '0.0.0.0', 'invalid'], $seen[2], 'not an address is the unspecified one');
        self::assertSame(['0.0.0.0', '0.0.0.0', 'invalid'], $seen[3]);
        self::assertSame('refused', $seen[4], 'a caller must say whether the address is masked');
    }
}
