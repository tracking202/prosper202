<?php

declare(strict_types=1);

namespace Tests\Conversion;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\OfflineConversionExport;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;

/**
 * The offline conversion files, over real ledger rows: which conversions go
 * in, at what value, and the exact lines each platform's import reads.
 *
 * @group integration
 */
final class OfflineConversionExportIntegrationTest extends TestCase
{
    private const T = 1_780_000_000;

    private static ?\mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        mysqli_report(MYSQLI_REPORT_STRICT);
        try {
            $db = @mysqli_connect(
                $host,
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
        } catch (\Throwable) {
            return;
        }
        if (!$db) {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            require_once __DIR__ . '/../../202-config/functions.php';
        }
        $db->query("SET SESSION sql_mode=''");
        (new SchemaInstaller($db))->install();
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        self::$db?->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (!self::$db) {
            self::markTestSkipped('No test database configured (set P202_TEST_DB_HOST).');
        }
        foreach (['202_conversion_logs', '202_google', '202_bing'] as $table) {
            self::q('TRUNCATE TABLE ' . $table);
        }
    }

    private static function q(string $sql): void
    {
        self::assertNotFalse(self::$db?->query($sql), $sql);
    }

    private static function gclid(int $clickId, string $gclid): void
    {
        self::q("INSERT INTO 202_google SET click_id = $clickId, gclid = '$gclid', utm_source_id = 0,"
            . ' utm_medium_id = 0, utm_campaign_id = 0, utm_term_id = 0, utm_content_id = 0');
    }

    /** @param array<string, string|int|null> $set */
    private static function conversion(int $convId, int $clickId, string $amount, int $convTime, array $set = []): void
    {
        $set += [
            'user_id' => 1, 'campaign_id' => 1, 'click_time' => $convTime - 3600, 'time_difference' => '',
            'pixel_type' => 2, 'user_agent' => '', 'source' => 'postback', 'payable' => 1, 'deleted' => 0,
            'dedupe_key' => 'k' . $convId,
        ];
        $sql = "INSERT INTO 202_conversion_logs SET conv_id = $convId, click_id = $clickId, click_payout = $amount,"
            . " conv_time = $convTime";
        foreach ($set as $column => $value) {
            $quoted = $value === null ? 'NULL' : "'" . self::$db?->real_escape_string((string) $value) . "'";
            $sql .= ", $column = " . $quoted;
        }
        self::q($sql);
    }

    /** @return array<string, mixed> */
    private static function export(
        string $platform = 'google',
        int $from = self::T,
        int $to = self::T + 86400,
        int $max = 100000,
        int $page = 5000
    ): array {
        $exporter = new OfflineConversionExport(new Connection(self::$db), $max, $page);

        return $exporter->export(1, $platform, $from, $to, 'Purchase, offline', 'USD');
    }

    public function testTheGoogleFileHoldsTheCountedConversionsOnGoogleClicks(): void
    {
        self::gclid(10, 'Cj0KCQjw-AAA_BwE');
        self::gclid(11, 'Cj0KCQjw-BBB_BwE');
        self::gclid(12, 'Cj0KCQjw-CCC_BwE');
        self::gclid(13, '=HYPERLINK("x")');
        self::gclid(14, 'Cj0KCQjw-OLD_BwE');
        self::gclid(15, 'Cj0KCQjw-NET_BwE');
        // The click path writes an empty gclid when a click carried only UTM
        // values (insertGclid()): beside a real one, and alone.
        self::gclid(10, '');
        self::gclid(16, '');
        self::conversion(1, 10, '47.12345', self::T + 60);
        self::conversion(2, 11, '9.995', self::T + 120);
        self::conversion(3, 12, '5', self::T + 180, ['deleted' => 1]);
        self::conversion(4, 12, '5', self::T + 181, ['payable' => 0]);
        self::conversion(5, 12, '5', self::T + 182, ['superseded_reason' => 'replace']);
        self::conversion(6, 12, '5', self::T + 183, ['source' => 'legacy_baseline']);
        self::conversion(7, 13, '5', self::T + 184);
        self::conversion(8, 14, '5', self::T + 185, ['click_time' => self::T + 185 - 91 * 86400]);
        self::conversion(9, 15, '20', self::T + 186);
        self::conversion(10, 15, '-20', self::T + 187, ['reverses_conv_id' => 9]);
        self::conversion(11, 10, '3', self::T + 188);
        self::conversion(12, 10, '-1', self::T + 189, ['reverses_conv_id' => 11]);
        self::conversion(13, 99, '5', self::T + 190);
        self::conversion(14, 10, '5', self::T + 191, ['user_id' => 2]);
        self::conversion(15, 10, '5', self::T + 86401);
        self::conversion(16, 16, '5', self::T + 192);

        $out = self::export();

        self::assertSame(
            "Parameters:TimeZone=+0000\r\n"
            . "Google Click ID,Conversion Name,Conversion Time,Conversion Value,Conversion Currency,Order ID\r\n"
            . 'Cj0KCQjw-AAA_BwE,"Purchase, offline",' . gmdate('Y-m-d H:i:s', self::T + 60) . ",47.12,USD,p202-1\r\n"
            . 'Cj0KCQjw-BBB_BwE,"Purchase, offline",' . gmdate('Y-m-d H:i:s', self::T + 120) . ",10.00,USD,p202-2\r\n"
            . 'Cj0KCQjw-AAA_BwE,"Purchase, offline",' . gmdate('Y-m-d H:i:s', self::T + 188) . ",2.00,USD,p202-11\r\n",
            $out['csv']
        );
        self::assertSame(3, $out['rows']);
        self::assertSame(['reversed' => 1, 'click_too_old' => 1, 'malformed_click_id' => 1], $out['skipped']);
        self::assertFalse($out['truncated']);
        self::assertNull($out['next_time_from']);
    }

    public function testTheMicrosoftFileFollowsItsTemplate(): void
    {
        self::q("INSERT INTO 202_bing SET click_id = 20, msclkid = '1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d',"
            . ' utm_source_id = 0, utm_medium_id = 0, utm_campaign_id = 0, utm_term_id = 0, utm_content_id = 0');
        self::gclid(21, 'Cj0KCQjw-AAA_BwE');
        self::conversion(1, 20, '12.3456', self::T + 60);
        self::conversion(2, 21, '5', self::T + 61);

        self::assertSame(
            "\xEF\xBB\xBF### TEMPLATE ###,,,,,,,\r\n"
            . "Parameters:TimeZone=+0000,,,,,,,\r\n"
            . 'Conversion Name,Conversion Time,Conversion Value,Conversion Currency,Microsoft Click ID,'
            . "Hashed Email Address,Hashed Phone Number,\r\n"
            . '"Purchase, offline",' . gmdate('Y-m-d H:i:s', self::T + 60)
            . ",12.346,USD,1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d,,,\r\n",
            self::export('microsoft')['csv']
        );
    }

    public function testALongExportIsReadInPagesAndStopsAtTheCapSayingWhereToResume(): void
    {
        // 2 and 3 share a second, across the first page's end: the cursor
        // is the time and the id, or one of them is read twice or never.
        for ($i = 1; $i <= 7; $i++) {
            self::gclid(100 + $i, 'Cj0KCQjw-' . $i . '_BwE');
            self::conversion($i, 100 + $i, '1', self::T + ($i === 3 ? 20 : $i * 10));
        }

        $all = self::export(page: 2);
        self::assertSame(7, $all['rows'], 'four pages of two read every row');
        self::assertSame(7, preg_match_all('/,p202-[1-7]\r\n/', $all['csv']), 'each of them once');
        self::assertFalse($all['truncated']);

        $capped = self::export(max: 5, page: 2);
        self::assertSame(5, $capped['rows']);
        self::assertTrue($capped['truncated']);
        self::assertSame(self::T + 60, $capped['next_time_from'], 'the sixth conversion is where the next starts');
        self::assertStringNotContainsString('p202-6', $capped['csv']);
    }
}
