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
 * p202RecordLegacyConversion(), and the address LastClickFromAddress::find()
 * looks a visitor's last click up by. Each must be exactly
 * `p202StoredVisitorIp()` unless ALLOWED names the site and why. And no file
 * there writes its own by-address lookup in SQL: the five that did matched
 * REMOTE_ADDR — a proxy's address, under which no click is stored — and never
 * an IPv6 click.
 */
final class StoredVisitorIpSourceTest extends TestCase
{
    private const STORED = 'p202StoredVisitorIp()';

    /** An address compared in SQL: `202_ips.ip_address = …`, any table alias. */
    private const ADDRESS_MATCH = '/\\bip_address\\s*=/i';

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
                $shown = $value === '' ? '(nothing readable)' : $value;
                $problems[] = sprintf('%s:%d  %s takes %s', $path, $line, $what, $shown);
            }
        }

        // dl.php, rtr.php, the two landing-page recorders and the error log
        // store a click address; gpb, gpx, upx, pb, px and cb202 a
        // conversion's; off.php, px.php, gpx.php, upx.php and rtr.php look
        // a visitor's last click up by it.
        self::assertGreaterThanOrEqual(16, $read, 'the scan finds the storage and lookup sites');
        self::assertSame([], $problems, "A visitor address is stored or looked up without the privacy mask:\n  "
            . implode("\n  ", $problems)
            . "\nPass p202StoredVisitorIp(): the VisitorIp address, masked when trackingEnabled() is false.");
    }

    public function testNoClickPathFileLooksAClickUpByAddressInItsOwnSql(): void
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (!str_starts_with($path, 'tracking202/')) {
                continue;
            }
            foreach (token_get_all($source) as $t) {
                if (
                    is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                    && preg_match(self::ADDRESS_MATCH, $t[1]) === 1
                ) {
                    $found[] = $path . ':' . $t[2];
                }
            }
        }

        self::assertSame([], $found, "A click is looked up by address in hand-written SQL:\n  "
            . implode("\n  ", $found)
            . "\nUse LastClickFromAddress::find(\$conn, p202StoredVisitorIp(), \$userId, \$since).");
    }

    /** @return iterable<string, array{string, bool}> */
    public static function sqlShapes(): iterable
    {
        yield 'the old pixel lookup' => ["WHERE \t202_ips.ip_address='\" . \$mysql['ip_address'] . \"'", true];
        yield 'with spaces' => ['WHERE 202_ips.ip_address = ?', true];
        yield 'the IPv6 table' => ['WHERE 202_ips_v6.ip_address = ?', true];
        yield 'an alias' => ['WHERE i.ip_address = "x"', true];
        yield 'a join on the id' => ['LEFT JOIN 202_ips AS ips ON (c.ip_id = ips.ip_id)', false];
        yield 'a column read' => ['SELECT ips.ip_address FROM 202_ips AS ips', false];
    }

    /** @dataProvider sqlShapes */
    public function testTheSqlPatternSeesEveryLookupShape(string $sql, bool $expected): void
    {
        self::assertSame($expected, preg_match(self::ADDRESS_MATCH, $sql) === 1);
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
        yield 'a last-click lookup' => [
            '<?php $r = \\Prosper202\\Click\\LastClickFromAddress::find($conn, $_SERVER["REMOTE_ADDR"], 1, 0);',
            1,
        ];
        yield 'another class\'s find is not one' => ['<?php $r = Other::find($conn, $ip, 1, 0);', 0];
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
        foreach (CallArgs::calls($source, ['find']) as $call) {
            if ($call['operator'] === '::' && str_ends_with($call['receiver'], 'LastClickFromAddress')) {
                $address = CallArgs::text($call['args'][1] ?? []);
                $sites[] = [$path, $call['line'], 'LastClickFromAddress::find()', $address];
            }
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
