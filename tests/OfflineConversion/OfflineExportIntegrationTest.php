<?php

declare(strict_types=1);

namespace Tests\OfflineConversion;

use Api\V3\Controllers\ClicksController;
use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\Ledger\MatchMethod;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Database\Connection;
use Prosper202\Database\DataSeeder;
use Prosper202\Database\SchemaInstaller;
use Prosper202\OfflineConversion\Consent;
use Prosper202\OfflineConversion\ConversionNames;
use Prosper202\OfflineConversion\ExportOptions;
use Prosper202\OfflineConversion\FeedStore;
use Prosper202\OfflineConversion\Format;
use Prosper202\OfflineConversion\OfflineExport;
use Prosper202\OfflineConversion\OfflineFile;

/**
 * The offline conversion files over a real server: conversions recorded by
 * the ledger's own writer, each in a shape a file must send or leave out,
 * read back through the one read every surface uses (OfflineExport), and
 * the fetch URLs' keys.
 *
 * Skips unless P202_TEST_DB_HOST (and friends) name a scratch database.
 *
 * @group integration
 */
final class OfflineExportIntegrationTest extends TestCase
{
    private const USER = 1;
    private const OTHER = 2;

    private static ?\mysqli $db = null;
    private MysqlConversionRepository $repo;
    private int $now;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) { return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        if (!class_exists('DataEngine', false)) {
            eval('class DataEngine { public function setDirtyHour($id) {} public function getSummary($s,$e,$p,$u=1,$up=false,$n=false){ return ""; } }');
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
        $db->query("SET SESSION sql_mode=''");
        (new SchemaInstaller($db))->install();
        $seeder = new DataSeeder($db);
        $seeder->seedRoles();
        $seeder->seedPermissions();
        $seeder->seedRolePermissions();
        $db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES'");
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
        $tables = ['202_conversion_logs', '202_clicks', '202_clicks_spy', '202_click_latest_visits', '202_aff_campaigns',
            '202_attribution_pending', '202_dataengine', '202_google', '202_offline_conversion_names',
            '202_offline_conversion_feeds', '202_notification_pending'];
        foreach ($tables as $t) {
            self::assertTrue(self::$db->query('TRUNCATE TABLE ' . $t), $t . ': ' . self::$db->error);
        }
        // 202_users is referenced by a foreign key, which TRUNCATE refuses.
        foreach (['202_user_role', '202_users_pref', '202_users'] as $t) {
            self::assertTrue(self::$db->query('DELETE FROM ' . $t . ' WHERE user_id IN (' . self::USER . ', ' . self::OTHER . ')'), $t . ': ' . self::$db->error);
        }
        $this->repo = new MysqlConversionRepository(new Connection(self::$db));
        $this->now = time();
        self::user(self::USER, 'America/New_York', 'EUR');
        self::user(self::OTHER, 'UTC', 'USD');
        $this->campaign(7);
        $this->campaign(8, 'accumulate');
        $this->campaign(9, 'replace', self::OTHER);
    }

    private static function fixture(string $sql): void
    {
        self::$db->query("SET SESSION sql_mode=''");
        try {
            if (self::$db->query($sql) !== true) {
                throw new \RuntimeException('fixture failed: ' . self::$db->error);
            }
        } finally {
            self::$db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES'");
        }
    }

    private static function user(int $id, string $zone, string $currency, int $role = 1): void
    {
        self::fixture("INSERT INTO 202_users SET user_id=$id, user_name='u$id', user_timezone='$zone', user_active=1, user_deleted=0");
        self::fixture("INSERT INTO 202_users_pref SET user_id=$id, user_account_currency='$currency'");
        self::fixture("INSERT INTO 202_user_role SET user_id=$id, role_id=$role");
    }

    private function campaign(int $id, string $mode = 'replace', int $user = self::USER): void
    {
        self::fixture("INSERT INTO 202_aff_campaigns SET aff_campaign_id=$id, user_id=$user, aff_network_id=1, aff_campaign_name='Camp $id',
            aff_campaign_url='http://x', aff_campaign_payout=10, aff_campaign_time=1, aff_campaign_foreign_payout=10, payout_mode='$mode'");
    }

    /** @param array<string, string> $ids 202_google's columns */
    private function click(int $id, int $campaign, array $ids, int $age = 2 * 86400, int $user = self::USER): void
    {
        $time = $this->now - $age;
        foreach (['202_clicks', '202_clicks_spy'] as $t) {
            self::fixture("INSERT INTO $t SET click_id=$id, user_id=$user, aff_campaign_id=$campaign, click_payout=0, click_cpc=0,"
                . " click_lead=0, click_time=$time");
        }
        $ids += ['gclid' => '', 'gbraid' => '', 'wbraid' => '', 'ttclid' => '', 'msclkid' => '', 'fbclid' => ''];
        $set = [];
        foreach ($ids as $column => $value) {
            $set[] = $column . " = '" . self::$db->real_escape_string($value) . "'";
        }
        self::fixture("INSERT INTO 202_google SET click_id=$id, utm_source_id=0, utm_medium_id=0, utm_campaign_id=0,"
            . ' utm_term_id=0, utm_content_id=0, ' . implode(', ', $set));
    }

    /** @param array<string, mixed> $data */
    private function record(int $clickId, MatchMethod $method, array $data = []): int
    {
        $r = $this->repo->record(self::USER, ['click_id' => $clickId] + $data + ['source' => 'postback'], $method);
        self::assertTrue((bool) $r['clickFound'], 'click ' . $clickId . ' is the account\'s');
        self::assertFalse((bool) $r['duplicate'], 'fixture rows are new');

        return (int) $r['convId'];
    }

    /** @return array{0: list<string>, 1: \Prosper202\OfflineConversion\ExportSummary} the file's lines and its summary */
    private function file(ExportOptions $options, int $user = self::USER): array
    {
        [$handle, $summary] = OfflineFile::write(new Connection(self::$db), $user, $options);
        rewind($handle);
        $text = (string) stream_get_contents($handle);
        fclose($handle);
        self::assertStringEndsWith("\r\n", $text);

        return [explode("\r\n", substr($text, 0, -2)), $summary];
    }

    private function names(): void
    {
        (new ConversionNames(new Connection(self::$db)))->save(self::USER, [
            ConversionNames::GOOGLE => [0 => 'Sale', 8 => 'Accumulated, "8"'],
            ConversionNames::MICROSOFT => [8 => 'MS Sale'],
        ], $this->now);
    }

    /** Every shape a conversion takes, and each line the files send or the reason they leave it out. */
    public function testEachFileSendsTheConversionsThatNamedAnAdClickAndCountsTheRest(): void
    {
        $t = fn (int $daysAgo, int $s = 0): int => $this->now - $daysAgo * 86400 + $s;
        $this->click(100, 7, ['gclid' => 'EAIaIQ_100']);
        $this->click(101, 7, ['gbraid' => '0AAAA_101']);
        $this->click(102, 7, ['wbraid' => 'CkIK_102']);
        $this->click(103, 7, ['gclid' => 'EAIaIQ_103']);
        $this->click(104, 7, []);
        $this->click(105, 8, ['gclid' => 'EAIaIQ_105', 'gbraid' => '0AAAA_105', 'msclkid' => '0123456789abcdef0123456789abcdef']);
        $this->click(106, 7, ['gclid' => 'EAIaIQ_106']);
        $this->click(107, 7, ['gclid' => 'EAIaIQ_107']);
        $this->click(108, 7, ['gclid' => 'EAIaIQ_108']);
        $this->click(109, 9, ['gclid' => 'EAIaIQ_other'], 2 * 86400, self::OTHER);
        $this->click(110, 7, ['gclid' => 'a b']);
        $this->click(111, 7, ['gclid' => 'EAIaIQ_111'], 100 * 86400);
        $this->click(112, 7, ['gclid' => 'EAIaIQ_112'], 3600);
        $this->click(113, 7, ['gclid' => 'EAIaIQ_113']);

        $sale = $this->record(100, MatchMethod::CLICK_ID, ['payout' => '12.50', 'conv_time' => $t(1)]);
        $braid = $this->record(101, MatchMethod::COOKIE, ['payout' => '3', 'conv_time' => $t(1, 1)]);
        $this->record(102, MatchMethod::VISITOR_ID, ['payout' => '3', 'conv_time' => $t(1, 2)]);
        $this->record(103, MatchMethod::SAME_IP, ['payout' => '3', 'conv_time' => $t(1, 3)]);
        $this->record(104, MatchMethod::CLICK_ID, ['payout' => '3', 'conv_time' => $t(1, 4)]);
        $netted = $this->record(105, MatchMethod::CLICK_ID, ['payout' => '10', 'transaction_id' => 'A', 'conv_time' => $t(1, 5)]);
        $this->record(105, MatchMethod::CLICK_ID, ['payout' => '-4', 'transaction_id' => 'A', 'conv_time' => $t(1, 6)]);
        $this->record(106, MatchMethod::CLICK_ID, ['payout' => '5', 'transaction_id' => 'B', 'conv_time' => $t(1, 7)]);
        $this->record(106, MatchMethod::CLICK_ID, ['transaction_id' => 'B', 'reversal' => true, 'conv_time' => $t(1, 8)]);
        $this->record(107, MatchMethod::CLICK_ID, ['payout' => '5', 'transaction_id' => 'C1', 'conv_time' => $t(1, 9)]);
        $latest = $this->record(107, MatchMethod::CLICK_ID, ['payout' => '7', 'transaction_id' => 'C2', 'conv_time' => $t(1, 10)]);
        $gone = $this->record(108, MatchMethod::CLICK_ID, ['payout' => '5', 'conv_time' => $t(1, 11)]);
        $this->repo->softDelete($gone, self::USER);
        // A row written before the ledger checked the click's owner: this
        // account's conversion naming the other account's click.
        self::fixture('INSERT INTO 202_conversion_logs SET click_id=109, campaign_id=7, click_payout=5, user_id=' . self::USER
            . ', click_time=' . $t(2) . ', conv_time=' . $t(1, 12) . ", time_difference='', ip='', pixel_type=0, user_agent='',"
            . " deleted=0, source='api', payable=1, dedupe_key='forged', match_method='click_id'");
        $this->record(110, MatchMethod::CLICK_ID, ['payout' => '5', 'conv_time' => $t(1, 13)]);
        $this->record(111, MatchMethod::CLICK_ID, ['payout' => '5', 'conv_time' => $t(1, 14)]);
        $this->record(112, MatchMethod::CLICK_ID, ['payout' => '5', 'conv_time' => $this->now - 60]);
        $unnamed = $this->record(113, MatchMethod::CLICK_ID, ['payout' => '0', 'conv_time' => $t(1, 15)]);
        $this->names();

        [$lines, $summary] = $this->file(new ExportOptions(Format::GOOGLE, 30, $this->now, null, null, null, Consent::GRANTED));
        $zone = new \DateTimeZone('America/New_York');
        $time = static fn (int $ts): string => (new \DateTimeImmutable('@' . $ts))->setTimezone($zone)->format('Y-m-d\TH:i:sP');
        self::assertSame([
            'Google Click ID,GBRAID,WBRAID,Conversion Name,Conversion Time,Conversion Value,Conversion Currency,Order ID,Ad User Data,Ad Personalization',
            'EAIaIQ_113,,,Sale,' . $time($t(1, 15)) . ',0,EUR,P202-' . $unnamed . ',Granted,',
            'EAIaIQ_107,,,Sale,' . $time($t(1, 10)) . ',7,EUR,P202-' . $latest . ',Granted,',
            'EAIaIQ_105,0AAAA_105,,"Accumulated, ""8""",' . $time($t(1, 5)) . ',6,EUR,P202-' . $netted . ',Granted,',
            ',0AAAA_101,,Sale,' . $time($t(1, 1)) . ',3,EUR,P202-' . $braid . ',Granted,',
            'EAIaIQ_100,,,Sale,' . $time($t(1)) . ',12.5,EUR,P202-' . $sale . ',Granted,',
        ], $lines);
        self::assertSame(15, $summary->conversions, 'every conversion but the two reversals');
        self::assertSame(5, $summary->rows);
        self::assertSame([
            'not_counted' => 3, 'visitor_id' => 1, 'same_ip' => 1, 'match_unknown' => 0, 'no_click_id' => 1,
            'gbraid_wbraid_only' => 0, 'malformed_click_id' => 1, 'click_too_old' => 1, 'before_click' => 0,
            'not_accounts_click' => 1, 'click_too_recent' => 1, 'negative_value' => 0, 'no_conversion_name' => 0,
            'over_row_limit' => 0,
        ], $summary->leftOut);
        self::assertSame(['EUR', 'America/New_York'], [$summary->currency, $summary->zone]);

        [$lines, $summary] = $this->file(new ExportOptions(Format::GOOGLE_UPLOADS, 30, $this->now));
        self::assertSame('Parameters:TimeZone=America/New_York,,,,,,,', $lines[0]);
        self::assertSame(['EAIaIQ_113', 'EAIaIQ_107', 'EAIaIQ_105', 'EAIaIQ_100'], array_map(
            static fn (string $line): string => explode(',', $line)[0],
            array_slice($lines, 2)
        ));
        self::assertSame(1, $summary->leftOut['gbraid_wbraid_only'], 'click 101 has a gbraid and no gclid');
        self::assertSame(1, $summary->leftOut['no_click_id'], 'click 104 has none');

        [$lines, $summary] = $this->file(new ExportOptions(Format::MICROSOFT, 30, $this->now));
        $utc = gmdate('Y-m-d\TH:i:s', $t(1, 5)) . '+0000';
        self::assertSame([
            'Parameters:TimeZone=+0000,,,,,,',
            'Conversion Name,Conversion Time,Conversion Value,Conversion Currency,Microsoft Click ID,Hashed Email Address,Hashed Phone Number',
            'MS Sale,' . $utc . ',6,EUR,0123456789abcdef0123456789abcdef,,',
        ], $lines);
        self::assertSame(1, $summary->rows);
        self::assertSame(0, $summary->leftOut['no_conversion_name'], 'only click 105 has an msclkid, and its campaign is named');

        // With the account's Google name gone, the campaigns without their own are left out.
        (new ConversionNames(new Connection(self::$db)))->save(self::USER, [ConversionNames::GOOGLE => [0 => '']], $this->now);
        [, $summary] = $this->file(new ExportOptions(Format::GOOGLE, 30, $this->now));
        self::assertSame([1, 4], [$summary->rows, $summary->leftOut['no_conversion_name']]);
    }

    public function testTheWindowAndTheNameOptionsChooseTheLines(): void
    {
        $this->click(100, 7, ['gclid' => 'EAIaIQ_100'], 20 * 86400);
        $this->click(101, 8, ['gclid' => 'EAIaIQ_101'], 3 * 86400);
        $this->record(100, MatchMethod::CLICK_ID, ['payout' => '1', 'conv_time' => $this->now - 15 * 86400]);
        $this->record(101, MatchMethod::CLICK_ID, ['payout' => '2', 'conv_time' => $this->now - 2 * 86400]);
        $this->names();

        [$lines, $summary] = $this->file(new ExportOptions(Format::GOOGLE, 7, $this->now));
        self::assertSame([1, 1], [$summary->conversions, $summary->rows], 'the window is the last 7 days of conversion times');
        self::assertStringStartsWith('EAIaIQ_101,', $lines[1]);

        [, $summary] = $this->file(new ExportOptions(Format::GOOGLE, 30, $this->now, 7));
        self::assertSame([1, 1], [$summary->conversions, $summary->rows], 'only campaign 7');

        [$lines] = $this->file(new ExportOptions(Format::GOOGLE, 30, $this->now, null, 'Everything'));
        self::assertSame(['Everything', 'Everything'], array_map(static fn (string $l): string => explode(',', $l)[3], array_slice($lines, 1)));

        [$lines, $summary] = $this->file(new ExportOptions(Format::GOOGLE, 30, $this->now, null, null, 'Sale'));
        self::assertSame([1, 1], [$summary->conversions, $summary->rows], 'a URL for one conversion action holds only that action\'s');
        self::assertStringStartsWith('EAIaIQ_100,', $lines[1]);

        [, $summary] = $this->file(new ExportOptions(Format::GOOGLE, 30, $this->now), self::OTHER);
        self::assertSame(0, $summary->conversions, 'another account reads none of them');
    }

    /**
     * 202_google's key is (click_id, gclid), so a click can have two rows,
     * and the one read first can have no gclid. The conversion is one line,
     * with each id any row has: the gclid for Google, the msclkid for
     * Microsoft, as GET /clicks names them.
     */
    public function testAClickWithTwoGoogleRowsIsSentOnceWithEachId(): void
    {
        $this->click(130, 7, ['gclid' => 'EAIaIQ_130']);
        self::fixture("INSERT INTO 202_google SET click_id = 130, gclid = '',"
            . " msclkid = '0123456789abcdef0123456789abc130', utm_source_id = 0, utm_medium_id = 0,"
            . ' utm_campaign_id = 0, utm_term_id = 0, utm_content_id = 0');
        $this->record(130, MatchMethod::CLICK_ID, ['payout' => '5', 'conv_time' => $this->now - 86400]);

        [$lines, $summary] = $this->file(new ExportOptions(Format::GOOGLE, 30, $this->now, null, 'Sale'));
        self::assertSame([1, 1], [$summary->conversions, $summary->rows], 'one conversion, one line');
        self::assertStringStartsWith('EAIaIQ_130,,,Sale,', $lines[1]);

        [$lines, $summary] = $this->file(new ExportOptions(Format::MICROSOFT, 30, $this->now, null, 'Sale'));
        self::assertSame([1, 1], [$summary->conversions, $summary->rows]);
        self::assertSame('0123456789abcdef0123456789abc130', explode(',', $lines[2])[4]);

        $api = (new ClicksController(self::$db, self::USER))->get(130)['data'];
        self::assertSame(['EAIaIQ_130', '0123456789abcdef0123456789abc130'], [$api['gclid'], $api['msclkid']]);
    }

    /**
     * Two sales on one click in one second, recorded by the ledger's writer,
     * and one a second later: Microsoft keeps one conversion per msclkid and
     * second, so the server reads the one recorded first first and the file
     * sends the other at the next free second. Google's file keeps the times.
     */
    public function testTwoConversionsOnOneClickInOneSecondAreTwoMicrosoftSeconds(): void
    {
        $msclkid = '0123456789abcdef0123456789abc140';
        $this->click(140, 8, ['gclid' => 'EAIaIQ_140', 'msclkid' => $msclkid]);
        $t = $this->now - 86400;
        $sale = static fn (string $payout, int $time): array => [
            'payout' => $payout, 'transaction_id' => 'T' . $payout, 'conv_time' => $time,
        ];
        $first = $this->record(140, MatchMethod::CLICK_ID, $sale('1', $t));
        $second = $this->record(140, MatchMethod::CLICK_ID, $sale('2', $t));
        $later = $this->record(140, MatchMethod::CLICK_ID, $sale('3', $t + 1));
        $this->names();
        $utc = static fn (int $ts): string => gmdate('Y-m-d\TH:i:s', $ts) . '+0000';

        [$lines, $summary] = $this->file(new ExportOptions(Format::MICROSOFT, 30, $this->now));
        self::assertSame([
            'MS Sale,' . $utc($t + 1) . ',3,EUR,' . $msclkid . ',,',
            'MS Sale,' . $utc($t) . ',1,EUR,' . $msclkid . ',,',
            'MS Sale,' . $utc($t + 2) . ',2,EUR,' . $msclkid . ',,',
        ], array_slice($lines, 2));
        self::assertSame(3, $summary->rows);
        [$again] = $this->file(new ExportOptions(Format::MICROSOFT, 30, $this->now));
        self::assertSame($lines, $again);

        $this->repo->softDelete($first, self::USER);
        [$lines] = $this->file(new ExportOptions(Format::MICROSOFT, 30, $this->now));
        self::assertSame(
            'MS Sale,' . $utc($t + 2) . ',2,EUR,' . $msclkid . ',,',
            $lines[3],
            'a deleted conversion still holds its second'
        );

        [$lines] = $this->file(new ExportOptions(Format::GOOGLE, 30, $this->now));
        $zone = new \DateTimeZone('America/New_York');
        $time = static fn (int $ts): string
            => (new \DateTimeImmutable('@' . $ts))->setTimezone($zone)->format('Y-m-d\TH:i:sP');
        $name = '"Accumulated, ""8"""';
        self::assertSame([
            'EAIaIQ_140,,,' . $name . ',' . $time($t + 1) . ',3,EUR,P202-' . $later . ',,',
            'EAIaIQ_140,,,' . $name . ',' . $time($t) . ',2,EUR,P202-' . $second . ',,',
        ], array_slice($lines, 1));
    }

    /**
     * Whose click is asked only of the visits in the file's range: a click
     * re-clicked within it is the account's whatever its first visit, and
     * another account's re-clicked click is still another account's.
     */
    public function testAReClickIsReadByTheVisitInTheFilesRange(): void
    {
        $recent = $this->now - 2 * 86400;
        foreach ([[120, self::USER, 'EAIaIQ_120'], [121, self::OTHER, 'EAIaIQ_121']] as [$id, $user, $gclid]) {
            $this->click($id, 7, ['gclid' => $gclid], 120 * 86400, $user);
            self::fixture("INSERT INTO 202_clicks SET click_id=$id, user_id=$user, aff_campaign_id=7,"
                . " click_payout=0, click_cpc=0, click_lead=0, click_time=$recent");
            self::fixture("INSERT INTO 202_click_latest_visits SET click_id=$id, click_time=$recent");
        }
        $this->record(120, MatchMethod::CLICK_ID, ['payout' => '4', 'conv_time' => $this->now - 86400]);
        // A row written before writes checked the click's owner (CLAUDE.md #27).
        self::fixture('INSERT INTO 202_conversion_logs (click_id, campaign_id, click_payout, user_id, click_time,'
            . ' conv_time, time_difference, ip, pixel_type, user_agent, deleted, source, payable, dedupe_key,'
            . ' match_method) VALUES (121, 7, 4, ' . self::USER . ", $recent, " . ($this->now - 86400)
            . ", '', '', 0, '', 0, 'postback', 1, 'foreign:121', 'click_id')");
        $named = self::$db->query('SELECT click_time FROM 202_conversion_logs WHERE click_id = 120');
        self::assertInstanceOf(\mysqli_result::class, $named, self::$db->error);
        self::assertSame([(string) $recent], $named->fetch_row(), 'the conversion names the re-click\'s visit');
        $this->names();

        [$lines, $summary] = $this->file(new ExportOptions(Format::GOOGLE, 30, $this->now));
        self::assertSame(
            [2, 1, 1],
            [$summary->conversions, $summary->rows, $summary->leftOut['not_accounts_click']]
        );
        self::assertStringStartsWith('EAIaIQ_120,', $lines[1]);
    }

    /**
     * A window of any size is read and written a row at a time, and a
     * Microsoft file stops at 100,000 rows, leaving out the oldest.
     */
    public function testAMicrosoftFileStopsAtItsRowLimitHoldingOneRowAtATime(): void
    {
        $count = Format::MICROSOFT_ROW_LIMIT + 2;
        self::fixture('CREATE TEMPORARY TABLE p202_seq (n INT PRIMARY KEY)');
        self::fixture('INSERT INTO p202_seq (n) SELECT a.n + b.n * 10 + c.n * 100 + d.n * 1000 + e.n * 10000 + f.n * 100000 FROM '
            . implode(', ', array_map(
                static fn (string $alias): string => '(SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4'
                    . ' UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) ' . $alias,
                ['a', 'b', 'c', 'd', 'e', 'f']
            )) . ' WHERE a.n + b.n * 10 + c.n * 100 + d.n * 1000 + e.n * 10000 + f.n * 100000 < ' . $count);
        $clickTime = $this->now - 3 * 86400;
        self::fixture('INSERT INTO 202_clicks (click_id, user_id, aff_campaign_id, click_payout, click_cpc, click_lead, click_time)'
            . ' SELECT n + 1, ' . self::USER . ", 7, 0, 0, 1, $clickTime FROM p202_seq");
        self::fixture("INSERT INTO 202_google (click_id, gclid, msclkid, utm_source_id, utm_medium_id, utm_campaign_id, utm_term_id, utm_content_id)"
            . " SELECT n + 1, '', LPAD(HEX(n + 1), 32, '0'), 0, 0, 0, 0, 0 FROM p202_seq");
        self::fixture('INSERT INTO 202_conversion_logs (click_id, campaign_id, click_payout, user_id, click_time, conv_time,'
            . ' time_difference, ip, pixel_type, user_agent, deleted, source, payable, dedupe_key, match_method)'
            . ' SELECT n + 1, 7, 1.5, ' . self::USER . ", $clickTime, " . ($this->now - 200000) . " + n, '', '', 0, '', 0, 'api', 1,"
            . " CONCAT('row:', n), 'click_id' FROM p202_seq");
        (new ConversionNames(new Connection(self::$db)))->save(self::USER, [ConversionNames::MICROSOFT => [0 => 'MS']], $this->now);

        $before = memory_get_usage();
        $sink = fopen('php://memory', 'w+b');
        self::assertIsResource($sink);
        $summary = (new OfflineExport(new Connection(self::$db)))->write(self::USER, new ExportOptions(Format::MICROSOFT, 30, $this->now), $sink);
        $peak = memory_get_peak_usage() - $before;
        self::assertSame([$count, Format::MICROSOFT_ROW_LIMIT, 2], [$summary->conversions, $summary->rows, $summary->leftOut['over_row_limit']]);
        rewind($sink);
        fgets($sink);
        fgets($sink);
        self::assertStringContainsString(',' . str_pad(strtoupper(dechex($count)), 32, '0', STR_PAD_LEFT) . ',', (string) fgets($sink), 'newest first');
        fclose($sink);
        // The file itself is in memory here (php://memory, about 9 MB); the
        // read beside it holds one row.
        self::assertLessThan(24 * 1024 * 1024, $peak);
    }

    public function testAFetchUrlOpensItsAccountsFileUntilItIsReplacedOrDeleted(): void
    {
        $store = new FeedStore(new Connection(self::$db));
        [$settings] = FeedStore::settings(['format' => 'google', 'days' => '7', 'conversion_name' => '', 'ad_user_data' => 'denied', 'ad_personalization' => '']);
        self::assertNotNull($settings);
        [$feed, $key] = $store->create(self::USER, $settings, $this->now);
        self::assertTrue(FeedStore::isKey($key));
        self::assertSame(self::USER, $store->findByKey($key)?->userId);
        self::assertSame(Consent::DENIED, $store->findByKey($key)?->adUserData);
        self::assertNull($store->findByKey(substr($key, 0, -1) . (str_ends_with($key, 'A') ? 'B' : 'A')));
        $stored = self::$db->query('SELECT key_hash FROM 202_offline_conversion_feeds')->fetch_row();
        self::assertSame(hash('sha256', $key, true), $stored[0] ?? null, 'only the hash is kept');

        self::assertNull($store->replaceKey(self::OTHER, $feed->feedId, $this->now), 'another account cannot replace it');
        self::assertFalse($store->delete(self::OTHER, $feed->feedId), 'or delete it');
        $newKey = $store->replaceKey(self::USER, $feed->feedId, $this->now + 1);
        self::assertIsString($newKey);
        self::assertNull($store->findByKey($key), 'the old URL stops answering');
        self::assertSame($feed->feedId, $store->findByKey($newKey)?->feedId);

        self::fixture('UPDATE 202_users SET user_active = 0 WHERE user_id = ' . self::USER);
        self::assertNull($store->findByKey($newKey), 'a deactivated account\'s URL opens nothing');
        self::fixture('UPDATE 202_users SET user_active = 1 WHERE user_id = ' . self::USER);
        self::fixture('UPDATE 202_user_role SET role_id = 5 WHERE user_id = ' . self::USER);
        self::assertNull($store->findByKey($newKey), 'nor one whose role no longer reads campaign data');
        self::fixture('UPDATE 202_user_role SET role_id = 1 WHERE user_id = ' . self::USER);
        self::assertNotNull($store->findByKey($newKey));

        $store->recordFetch($feed->feedId, 12, $this->now + 5);
        self::assertSame([$this->now + 5, 12], [$store->list(self::USER)[0]->lastFetchedAt, $store->list(self::USER)[0]->lastFetchRows]);
        self::assertTrue($store->delete(self::USER, $feed->feedId));
        self::assertNull($store->findByKey($newKey));
        self::assertSame([], $store->list(self::USER));
    }

    public function testAnAccountHasAtMostTwentyFetchUrls(): void
    {
        $store = new FeedStore(new Connection(self::$db));
        [$settings] = FeedStore::settings(['format' => 'microsoft', 'days' => '3', 'conversion_name' => '', 'ad_user_data' => '', 'ad_personalization' => '']);
        self::assertNotNull($settings);
        for ($i = 0; $i < FeedStore::MAX_FEEDS; $i++) {
            $store->create(self::USER, $settings, $this->now);
        }
        $store->create(self::OTHER, $settings, $this->now);
        $this->expectException(\OverflowException::class);
        $store->create(self::USER, $settings, $this->now);
    }

    public function testASaveSetsAndClearsOnlyTheNamesItNames(): void
    {
        $names = new ConversionNames(new Connection(self::$db));
        $this->names();
        $names->save(self::USER, [ConversionNames::GOOGLE => [8 => '', 7 => 'Seven']], $this->now);
        self::assertSame([
            ConversionNames::GOOGLE => ['default' => 'Sale', 'campaigns' => [7 => 'Seven']],
            ConversionNames::MICROSOFT => ['default' => null, 'campaigns' => [8 => 'MS Sale']],
        ], $names->read(self::USER));
        self::assertSame([
            ConversionNames::GOOGLE => ['default' => null, 'campaigns' => []],
            ConversionNames::MICROSOFT => ['default' => null, 'campaigns' => []],
        ], $names->read(self::OTHER));
    }
}
