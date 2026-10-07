<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Tests\Support\CallArgs;
use Tests\Support\SourceScan;

/**
 * Every visitor address the click path keeps is p202StoredVisitorIp(): the
 * VisitorIp address, masked when the owner's privacy setting holds back for
 * the visitor. Privacy mode used to mask only the $ip_address global, which
 * none of the storage read — dl.php, rtr.php and the landing-page recorders
 * stored the address as it arrived whatever the setting said.
 *
 * What it reads, in tracking202/ and connect2.php (the click path; the
 * admin pages' own uses of an address — the login log, a report filtered
 * by an IP the owner typed — are not a visitor's): every call to
 * findOrCreateIp() and get_ip_id(), whose address is the last argument, and
 * the 'ip' entry of the array handed to p202RecordConversion() and
 * p202RecordLegacyConversion(). Each must be exactly `p202StoredVisitorIp()`
 * unless ALLOWED names the site and why.
 */
final class StoredVisitorIpSourceTest extends TestCase
{
    private const STORED = 'p202StoredVisitorIp()';

    /** @var array<string, array{string, string}> file => [allowed value, why] */
    private const ALLOWED = [
        'tracking202/static/cb202.php' => [
            '\Prosper202\Http\VisitorIp::fromServer($_SERVER)',
            'ClickBank\'s INS notification: the address is ClickBank\'s server, never a visitor\'s,'
                . ' and connect.php (this endpoint\'s bootstrap) has no privacy state',
        ],
    ];

    public function testEveryStoredVisitorAddressIsTheMaskedOne(): void
    {
        $problems = [];
        $read = 0;
        foreach (self::sites() as [$path, $line, $what, $value]) {
            $read++;
            $allowed = self::ALLOWED[$path][0] ?? null;
            if ($value !== self::STORED && $value !== $allowed) {
                $problems[] = sprintf('%s:%d  %s stores %s', $path, $line, $what, $value === '' ? '(nothing)' : $value);
            }
        }

        // dl.php, rtr.php, the two landing-page recorders and the error log
        // store a click address; gpb, gpx, upx, pb, px and cb202 a conversion's.
        self::assertGreaterThanOrEqual(11, $read, 'the scan finds the storage sites');
        self::assertSame([], $problems, "A visitor address is stored without the privacy mask:\n  "
            . implode("\n  ", $problems)
            . "\nPass p202StoredVisitorIp(): the VisitorIp address, masked when trackingEnabled() is false.");
    }

    /** @return iterable<string, array{string, int}> source, sites expected */
    public static function shapes(): iterable
    {
        yield 'a repository store' => ['<?php $id = $repo->findOrCreateIp($ip);', 1];
        yield 'a static store with the connection' => ['<?php $id = INDEXES::get_ip_id($db, $ip_address);', 1];
        yield 'a conversion row' => ['<?php p202RecordConversion($db, ["click_id" => 1, "ip" => $ip]);', 1];
        yield 'a legacy conversion row' => ['<?php p202RecordLegacyConversion($db, 1, 2, ["ip" => $ip]);', 1];
        yield 'a conversion row joined to more fields' => [
            '<?php p202RecordLegacyConversion($db, 1, 2, ["ip" => $ip] + $more);',
            1,
        ];
        yield 'a conversion row whose ip cannot be read' => ['<?php p202RecordConversion($db, $fields);', 1];
        yield 'a union whose left side is not the literal' => [
            '<?php p202RecordConversion($db, $fields + ["ip" => p202StoredVisitorIp()]);',
            1,
        ];
        yield 'the definition is not a call' => ['<?php class INDEXES { public static function get_ip_id($a) {} }', 0];
    }

    /** @dataProvider shapes */
    public function testEveryShapeOfAStoreIsRead(string $source, int $expected): void
    {
        $sites = self::sitesIn('x.php', $source);
        self::assertCount($expected, $sites);
        foreach ($sites as $site) {
            self::assertNotSame(self::STORED, $site[3]);
        }
    }

    /** @return list<array{string, int, string, string}> [file, line, what, value text] */
    private static function sites(): array
    {
        $sites = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (!str_starts_with($path, 'tracking202/') && $path !== '202-config/connect2.php') {
                continue;
            }
            array_push($sites, ...self::sitesIn($path, $source));
        }

        return $sites;
    }

    /** @return list<array{string, int, string, string}> */
    private static function sitesIn(string $path, string $source): array
    {
        $sites = [];
        foreach (CallArgs::calls($source, ['findOrCreateIp', 'get_ip_id']) as $call) {
            $last = $call['args'] === [] ? [] : $call['args'][count($call['args']) - 1];
            $sites[] = [$path, $call['line'], $call['name'] . '()', CallArgs::text($last)];
        }
        foreach (CallArgs::calls($source, ['p202RecordConversion', 'p202RecordLegacyConversion']) as $call) {
            // The row's fields are an inline array, alone or as the left
            // operand of a union (`[...] + p202ExtractReversal($_GET)`; the
            // left operand's keys win). A call whose 'ip' this cannot read is
            // reported as storing nothing readable, not skipped: an address
            // in a variable array, or a union whose left side is not the
            // literal, would go unread.
            $ip = null;
            foreach ($call['args'] as $arg) {
                $entries = CallArgs::leadingArray($arg);
                if ($entries !== null && array_key_exists('ip', $entries)) {
                    $ip = CallArgs::text($entries['ip']);
                }
            }
            $sites[] = [$path, $call['line'], $call['name'] . '() ip', $ip ?? ''];
        }

        return $sites;
    }
}
