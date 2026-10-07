<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;
use Prosper202\Report\AccountZone;

/**
 * One rule for the account's zone: a name PHP lists, spelled as listed;
 * anything else is UTC.
 *
 * The pages (AUTH::accountTimezone()) took only a listed name, and the API
 * (AccountZone::normalize()) took anything `new DateTimeZone()` accepts, so
 * a stored `+05:30` counted the pages' days in UTC and GET /reports/* and the
 * LTV cohorts in a fixed +05:30 -- measured on a live instance:
 * `"timezone":"+05:30"` from the API beside UTC on the pages. An offset is
 * not a zone (CLAUDE.md #29). AUTH now asks this class
 * (SetTimezoneReadsTheAccountIntegrationTest holds the two to one answer
 * against a real account row); this pins the rule itself, and that
 * AUTH::set_timezone() holds the zone it sets to it too.
 */
final class AccountZoneTest extends TestCase
{
    /** @return iterable<string, array{?string, string}> */
    public static function stored(): iterable
    {
        yield 'a listed zone' => ['Asia/Kolkata', 'Asia/Kolkata'];
        yield 'another listed zone' => ['America/New_York', 'America/New_York'];
        yield 'UTC' => ['UTC', 'UTC'];
        yield 'surrounding whitespace is not part of the name' => ['  Europe/Paris ', 'Europe/Paris'];
        yield 'an offset is not a zone' => ['+05:30', 'UTC'];
        yield 'a negative offset' => ['-05:00', 'UTC'];
        yield 'an offset without a colon' => ['+0530', 'UTC'];
        yield 'GMT plus hours, which PHP reads as an offset' => ['GMT+5', 'UTC'];
        yield 'Z, which PHP reads as UTC+0 by abbreviation' => ['Z', 'UTC'];
        yield 'a listed name in another case' => ['america/new_york', 'UTC'];
        yield 'a misspelling' => ['Not/AZone', 'UTC'];
        yield 'nothing stored' => ['', 'UTC'];
        yield 'null' => [null, 'UTC'];
    }

    /** @dataProvider stored */
    public function testAZoneIsANamePhpListsAndAnythingElseIsUtc(?string $stored, string $zone): void
    {
        self::assertSame($zone, AccountZone::normalize($stored));
    }

    public function testEveryNameAWriterAcceptsIsAZone(): void
    {
        // Personal settings and PUT /users/{id} take listIdentifiers().
        foreach (\DateTimeZone::listIdentifiers() as $name) {
            self::assertTrue(AccountZone::isZone($name), $name);
        }
    }

    /**
     * The redirects and pixels set the zone of a row they read with no
     * session; an offset there was refused by date_default_timezone_set()
     * with a notice and the server's own zone stayed in force. Executed in a
     * child PHP, so the default zone of this process is not touched.
     */
    public function testSetTimezoneHoldsWhatItSetsToTheRule(): void
    {
        $root = dirname(__DIR__, 2);
        $code = 'require ' . var_export($root . '/vendor/autoload.php', true) . ';'
            . ' require ' . var_export($root . '/202-config/functions-auth.php', true) . ';'
            . ' $out = [];'
            . ' foreach (["Asia/Tokyo", "+05:30", "america/new_york", ""] as $z) {'
            . '   date_default_timezone_set("Pacific/Pitcairn");'
            . '   AUTH::set_timezone($z); $out[$z] = date_default_timezone_get(); }'
            . ' echo json_encode($out);';
        $process = proc_open(
            [PHP_BINARY, '-d', 'error_reporting=E_ALL', '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        proc_close($process);

        self::assertSame(
            ['Asia/Tokyo' => 'Asia/Tokyo', '+05:30' => 'UTC', 'america/new_york' => 'UTC', '' => 'UTC'],
            json_decode($out, true),
            "answered: $out $err"
        );
    }
}
