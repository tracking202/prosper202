<?php

declare(strict_types=1);

namespace Tests\StaticEndpoint;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\LatestVisit;
use Prosper202\Database\Connection;
use Prosper202\Identity\ClickIdentity;

/**
 * A conversion request's own p202_consent, as the web server runs each
 * conversion endpoint (fixtures/postback-endpoint-runner.php): it reaches
 * the ledger row (202_conversion_logs.consent_withheld), read as the click
 * path reads the same parameter (ClickIdentity::fromRequest(), CLAUDE.md
 * #11), on a server postback (gpb, pb) and a browser pixel (upx, gpx, px).
 * DestinationsIntegrationTest shows what a destination is then sent.
 *
 * @group integration
 */
final class ConversionConsentServedIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990411;
    private const CAMPAIGN = 9041101;
    private const CLICK = 99041111;
    private const NOW = 1_800_000_000;

    private static int $tx = 0;

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
        self::postbackAuthOff(self::USER);
        self::q('INSERT INTO 202_aff_campaigns SET aff_campaign_id = ' . self::CAMPAIGN . ', aff_campaign_id_public = '
            . self::CAMPAIGN . ', user_id = ' . self::USER . ", aff_network_id = 0, aff_campaign_name = 'consent',"
            . " aff_campaign_url = 'https://shop.example/?s=[[subid]]', aff_campaign_payout = 5.00,"
            . " aff_campaign_foreign_payout = 5.00, aff_campaign_time = 0, payout_mode = 'accumulate'");
        $columns = 'click_id = ' . self::CLICK . ', user_id = ' . self::USER . ', aff_campaign_id = ' . self::CAMPAIGN
            . ', landing_page_id = 0, ppc_account_id = 0, click_cpc = 0.10, click_payout = 5.00, click_lead = 0,'
            . ' click_time = ' . (self::NOW - 600);
        self::q("INSERT INTO 202_clicks SET $columns, rotator_id = 0, rule_id = 0");
        self::q("INSERT INTO 202_clicks_spy SET $columns");
        self::q('INSERT INTO 202_clicks_tracking SET click_id = ' . self::CLICK
            . ', c1_id = 0, c2_id = 0, c3_id = 0, c4_id = 0');
        LatestVisit::refresh(new Connection(self::$db), self::CLICK);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
        }
    }

    private static function cleanUp(): void
    {
        $click = self::CLICK;
        self::$db->query('DELETE FROM 202_attribution_pending WHERE conv_id IN'
            . " (SELECT conv_id FROM 202_conversion_logs WHERE click_id = $click)");
        $tables = [
            '202_clicks', '202_clicks_spy', '202_clicks_tracking', '202_click_latest_visits', '202_conversion_logs',
            '202_dataengine', '202_click_consent_refusals',
        ];
        foreach ($tables as $table) {
            self::$db->query("DELETE FROM $table WHERE click_id = $click");
        }
        foreach (['202_aff_campaigns', '202_postback_auth', '202_revenue_events', '202_dirty_hours'] as $table) {
            self::$db->query("DELETE FROM $table WHERE user_id = " . self::USER);
        }
    }

    /**
     * Each spelling of p202_consent (null: absent), as a raw query string.
     *
     * @return array<string, array{0: string|null}>
     */
    public static function spellings(): array
    {
        return [
            'refused' => ['p202_consent=0'],
            'zero twice' => ['p202_consent=00'],
            'empty' => ['p202_consent='],
            'a space before 1' => ['p202_consent=%201'],
            'given' => ['p202_consent=1'],
            'a list' => ['p202_consent%5B%5D=1'],
            'absent' => [null],
        ];
    }

    /** @dataProvider spellings */
    public function testTheConversionRequestIsReadAsTheClickRequestIs(?string $consent): void
    {
        self::requireScratch();
        parse_str((string) $consent, $get);
        $expected = ClickIdentity::fromRequest($get, [], true)->withheld ? 1 : 0;
        foreach (['gpb', 'upx'] as $endpoint) {
            $tx = $this->tx();
            $query = 'subid=' . self::CLICK . '&txid=' . $tx . ($consent === null ? '' : '&' . $consent);
            [$status, $body] = self::runQuery($endpoint, $query);
            self::assertContains($status, [200, 202], "$endpoint: $body");
            self::assertSame(
                [$expected],
                self::stored($tx),
                "$endpoint stores the request's consent as the click path reads it"
            );
        }
    }

    public function testEveryConversionEndpointStoresItsRequestsRefusal(): void
    {
        self::requireScratch();
        $cookie = ['tracking202subid' => (string) self::CLICK];
        $naming = [
            'pb' => static fn (string $tx): string
                => 'acip=' . self::CAMPAIGN . '&subid=' . self::CLICK . '&txid=' . $tx,
            'gpx' => static fn (string $tx): string => 'subid=' . self::CLICK . '&txid=' . $tx,
            'px' => static fn (string $tx): string => 'acip=' . self::CAMPAIGN . '&txid=' . $tx,
        ];
        foreach ($naming as $endpoint => $query) {
            foreach (['&p202_consent=0' => 1, '' => 0] as $consent => $expected) {
                $tx = $this->tx();
                if ($endpoint === 'px') {
                    // px.php reads no transaction id: it converts a click that is not yet a lead, once.
                    self::unconvert();
                }
                [$status, $body] = self::runQuery($endpoint, $query($tx) . $consent, $endpoint === 'px' ? $cookie : []);
                self::assertContains($status, [200, 202], "$endpoint: $body");
                $stored = $endpoint === 'px' ? self::stored(null) : self::stored($tx);
                self::assertSame([$expected], $stored, "$endpoint '$consent'");
            }
        }
    }

    /**
     * A repeat with no transaction id on a click that is already a lead
     * records nothing more, but when it refuses consent (the thank-you page
     * reloaded after the visitor declined) the refusal is the click's from
     * then on: px, pb and gpx each store it, and a consenting repeat does
     * not. Still one conversion row.
     */
    public function testARefusingRepeatOnAClickThatIsAlreadyALeadRefusesTheClick(): void
    {
        self::requireScratch();
        $cookie = ['tracking202subid' => (string) self::CLICK];
        $naming = [
            'pb' => 'acip=' . self::CAMPAIGN . '&subid=' . self::CLICK,
            'gpx' => 'subid=' . self::CLICK,
            'px' => 'acip=' . self::CAMPAIGN,
        ];
        foreach ($naming as $endpoint => $query) {
            self::unconvert();
            self::q('DELETE FROM 202_click_consent_refusals WHERE click_id = ' . self::CLICK);
            $cookies = $endpoint === 'px' ? $cookie : [];
            foreach (['', '&p202_consent=1', '&p202_consent=0'] as $i => $consent) {
                [$status, $body] = self::runQuery($endpoint, $query . $consent, $cookies);
                self::assertContains($status, [200, 202], "$endpoint '$consent': $body");
                $rows = (int) self::$db->query('SELECT COUNT(*) FROM 202_conversion_logs WHERE click_id = '
                    . self::CLICK)->fetch_row()[0];
                self::assertSame(1, $rows, "$endpoint '$consent': the click converts once");
                $what = $i < 2 ? 'a consenting request stores no refusal' : 'the refusing repeat refuses the click';
                self::assertSame($i < 2 ? 0 : 1, self::refusals(), "$endpoint: $what");
            }
        }
    }

    private static function refusals(): int
    {
        return (int) self::$db->query('SELECT COUNT(*) FROM 202_click_consent_refusals WHERE click_id = '
            . self::CLICK)->fetch_row()[0];
    }

    private function tx(): string
    {
        return 'consent-' . ++self::$tx . '-' . bin2hex(random_bytes(3));
    }

    private static function unconvert(): void
    {
        $click = self::CLICK;
        self::q('DELETE FROM 202_attribution_pending WHERE conv_id IN'
            . " (SELECT conv_id FROM 202_conversion_logs WHERE click_id = $click)");
        self::q("DELETE FROM 202_conversion_logs WHERE click_id = $click");
        foreach (['202_clicks', '202_clicks_spy'] as $table) {
            self::q("UPDATE $table SET click_lead = 0, click_payout = 5.00 WHERE click_id = $click");
        }
    }

    /** @return list<int> the consent_withheld of the rows recorded under $tx (null: under none) */
    private static function stored(?string $tx): array
    {
        $rows = self::$db->query('SELECT consent_withheld FROM 202_conversion_logs WHERE click_id = ' . self::CLICK
            . ' AND transaction_id ' . ($tx === null ? 'IS NULL' : '= ' . self::str($tx)))->fetch_all(MYSQLI_NUM);

        return array_map(static fn (array $row): int => (int) $row[0], $rows);
    }

    /**
     * @param array<string, string> $cookies
     * @return array{0: int, 1: string}
     */
    private static function runQuery(string $endpoint, string $query, array $cookies = []): array
    {
        $env = getenv() + [];
        $env['P202_TEST_QUERY'] = $query;
        $env['P202_TEST_COOKIES'] = json_encode($cookies === [] ? new \stdClass() : $cookies);
        $proc = proc_open(
            [
                PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_log=',
                __DIR__ . '/fixtures/postback-endpoint-runner.php', $endpoint,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertNotSame(2, proc_close($proc), "the runner could not set up: $err");
        if (preg_match('/\ASTATUS (\d{3})\n(.*)\z/s', $out, $m) !== 1) {
            self::fail("the runner printed no status:\n$out\n$err");
        }

        return [(int) $m[1], $m[2]];
    }
}
