<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controller;
use Api\V3\Controllers\AffNetworksController;
use Api\V3\Controllers\CampaignsController;
use Api\V3\Controllers\ForecastEventsController;
use Api\V3\Controllers\LandingPagesController;
use Api\V3\Controllers\PpcAccountsController;
use Api\V3\Controllers\PpcNetworksController;
use Api\V3\Controllers\TextAdsController;
use Api\V3\Controllers\TrackersController;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\ValidationException;
use Api\V3\RequestContext;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;

/**
 * What the CRUD resources do with a body, on the real controllers against a
 * real database (CLAUDE.md #9: the validation is the seam, so it is not
 * mocked).
 *
 * The base controller used to skip a key it did not write, skip a null, and
 * cast whatever is_numeric() let through. So a typo'd field or one the
 * resource does not write answered 200/201 having done less than asked, a
 * null meant to clear a field changed nothing, and "1e3" or a 20-digit
 * string was stored as some other number. Each is now refused by name, a
 * null clears a column that can hold one — and a body read with GET can
 * still be sent back whole, as `p202 sync`, `import` and API clients do.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_*); it
 * installs the schema there and writes users 5201 and 5202's rows.
 *
 * @group integration
 */
final class PayloadValidationIntegrationTest extends TestCase
{
    private const USER = 5201;

    private static ?\mysqli $db = null;

    private int $network = 0;
    private int $ppcNetwork = 0;
    private int $ppcAccount = 0;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) { return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        // The API's own error mode: index.php never calls connect.php's
        // mysqli_report(), so PHP's default ERROR|STRICT applies.
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $db = mysqli_connect(
                $host,
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
        } catch (\Throwable) {
            return;
        }
        $db->query("SET SESSION sql_mode=''");
        (new SchemaInstaller($db))->install();
        $db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        self::$db?->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        RequestContext::reset();
        foreach (['202_trackers', '202_text_ads', '202_landing_pages', '202_aff_campaigns', '202_aff_networks', '202_ppc_accounts', '202_ppc_networks', '202_forecast_events'] as $table) {
            self::$db->query("DELETE FROM $table WHERE user_id = " . self::USER);
        }
        $this->network = (int) (new AffNetworksController(self::$db, self::USER))->create(['aff_network_name' => 'cat'])['data']['aff_network_id'];
        $this->ppcNetwork = (int) (new PpcNetworksController(self::$db, self::USER))->create(['ppc_network_name' => 'src'])['data']['ppc_network_id'];
        $this->ppcAccount = (int) (new PpcAccountsController(self::$db, self::USER))->create(['ppc_account_name' => 'acct', 'ppc_network_id' => $this->ppcNetwork])['data']['ppc_account_id'];
    }

    protected function tearDown(): void
    {
        RequestContext::reset();
    }

    /** @return array<string, mixed> */
    private function campaign(array $extra = []): array
    {
        return (new CampaignsController(self::$db, self::USER))->create($extra + [
            'aff_campaign_name' => 'offer', 'aff_campaign_url' => 'https://o.example', 'aff_campaign_payout' => '2.50',
            'aff_network_id' => $this->network,
        ])['data'];
    }

    /**
     * One live record of each CRUD resource, made the way a client makes it.
     *
     * @return iterable<string, array{class-string<Controller>, callable(self): array<string, mixed>}>
     */
    public static function resources(): iterable
    {
        yield 'campaign' => [CampaignsController::class, static fn (self $t): array => $t->campaign(['aff_campaign_url_2' => 'https://two.example'])];
        yield 'aff network' => [AffNetworksController::class, static fn (self $t): array => (new AffNetworksController(self::$db, self::USER))->create(['aff_network_name' => 'n2'])['data']];
        yield 'ppc network' => [PpcNetworksController::class, static fn (self $t): array => (new PpcNetworksController(self::$db, self::USER))->create(['ppc_network_name' => 's2'])['data']];
        yield 'ppc account' => [PpcAccountsController::class, static fn (self $t): array => (new PpcAccountsController(self::$db, self::USER))->create(['ppc_account_name' => 'a2', 'ppc_network_id' => $t->ppcNetwork])['data']];
        yield 'landing page' => [LandingPagesController::class, static fn (self $t): array => (new LandingPagesController(self::$db, self::USER))->create([
            'landing_page_url' => 'https://lp.example', 'aff_campaign_id' => $t->campaign()['aff_campaign_id'], 'landing_page_nickname' => 'lp',
        ])['data']];
        yield 'text ad' => [TextAdsController::class, static fn (self $t): array => (new TextAdsController(self::$db, self::USER))->create([
            'text_ad_name' => 'ad', 'text_ad_headline' => 'h', 'text_ad_description' => 'd', 'text_ad_display_url' => 'x.example',
        ])['data']];
        yield 'tracker with a CPC' => [TrackersController::class, static fn (self $t): array => (new TrackersController(self::$db, self::USER))->create([
            'aff_campaign_id' => $t->campaign()['aff_campaign_id'], 'ppc_account_id' => $t->ppcAccount, 'click_cpc' => '0.25',
        ])['data']];
        yield 'forecast event' => [ForecastEventsController::class, static fn (self $t): array => (new ForecastEventsController(self::$db, self::USER))->create([
            'event_name' => 'sale', 'event_date' => '2026-11-27', 'tags' => 'retail',
        ])['data']];
    }

    /**
     * @dataProvider resources
     * @param class-string<Controller> $class
     * @param callable(self): array<string, mixed> $make
     */
    public function testAGetBodySentBackWholeIsAcceptedAndChangesNothing(string $class, callable $make): void
    {
        $made = $make($this);
        $controller = new $class(self::$db, self::USER);
        $pk = (new \ReflectionMethod($controller, 'primaryKey'))->invoke($controller);
        $id = (int) $made[$pk];
        $read = $controller->get($id)['data'];

        // Every key GET answered: the id, the owner, read-only fields (public
        // ids, a campaign's links), nulls, and the version.
        $after = $controller->update($id, $read)['data'];

        self::assertSame($read, $after, 'the record is as it was, version included');
    }

    /**
     * A DATE column under strict SQL mode refuses "" and any day that does
     * not exist with an error, which the API answered as a 500: `p202
     * forecast-event update <id> --end_date ""` sent exactly that. Each is a
     * 422 on the field now, the row is untouched, and null clears end_date.
     */
    public function testAForecastEventDateIsADayOrARefusalNeverADatabaseError(): void
    {
        $events = new ForecastEventsController(self::$db, self::USER);
        $made = $events->create(['event_name' => 'sale', 'event_date' => '2026-11-27', 'end_date' => '2026-11-30']);
        $id = (int) $made['data']['event_id'];

        $refused = [
            ['end_date' => ''], ['end_date' => '2026-02-30'], ['event_date' => '27/11/2026'], ['event_date' => ''],
        ];
        foreach ($refused as $body) {
            try {
                $events->update($id, $body);
                self::fail('expected ' . json_encode($body) . ' to be refused');
            } catch (ValidationException $e) {
                self::assertSame(array_keys($body), array_keys($e->getFieldErrors()));
            }
        }
        $row = self::$db->query("SELECT event_date, end_date FROM 202_forecast_events WHERE event_id = $id")
            ->fetch_assoc();
        self::assertSame(['event_date' => '2026-11-27', 'end_date' => '2026-11-30'], $row, 'refused, nothing written');

        self::assertNull($events->update($id, ['end_date' => null])['data']['end_date']);
        $set = $events->update($id, ['end_date' => '2026-12-01']);
        self::assertSame('2026-12-01', $set['data']['end_date']);
    }

    public function testATrackerSentBackWithItsUnusedCostAsNullKeepsTheCostItUses(): void
    {
        // TrackersController::beforeUpdate() clears the other cost when one
        // is set; a GET body carries the unused one as null, which must not
        // read as "switch to it" now that a null reaches the hook.
        $trackers = new TrackersController(self::$db, self::USER);
        $id = (int) $trackers->create(['aff_campaign_id' => $this->campaign()['aff_campaign_id'], 'click_cpc' => '0.25'])['data']['tracker_id'];
        $read = $trackers->get($id)['data'];
        self::assertNull($read['click_cpa']);

        $trackers->update($id, $read);
        $row = self::$db->query("SELECT click_cpc, click_cpa FROM 202_trackers WHERE tracker_id = $id")->fetch_assoc();
        self::assertSame(['click_cpc' => '0.25000', 'click_cpa' => null], $row);

        // An explicit switch still switches.
        $trackers->update($id, ['click_cpa' => '1.5']);
        $row = self::$db->query("SELECT click_cpc, click_cpa FROM 202_trackers WHERE tracker_id = $id")->fetch_assoc();
        self::assertSame(['click_cpc' => null, 'click_cpa' => '1.50000'], $row);
    }

    public function testAFieldTheResourceDoesNotWriteIsRefusedAndNothingIsWritten(): void
    {
        $campaigns = new CampaignsController(self::$db, self::USER);
        $id = (int) $this->campaign()['aff_campaign_id'];
        $before = $campaigns->get($id)['data'];

        try {
            $campaigns->update($id, ['aff_campaign_nmae' => 'typo', 'aff_campaign_payout' => '9']);
            self::fail('a misspelled field was accepted');
        } catch (ValidationException $e) {
            self::assertSame(['aff_campaign_nmae'], array_keys($e->getFieldErrors()));
            self::assertStringContainsString('aff_campaign_name', $e->getFieldErrors()['aff_campaign_nmae'], 'the writable fields are listed');
        }
        self::assertSame($before, $campaigns->get($id)['data'], 'the valid field beside it was not written either');

        $count = static fn (): int => (int) self::$db->query('SELECT COUNT(*) FROM 202_aff_campaigns WHERE user_id = ' . self::USER)->fetch_row()[0];
        $rows = $count();
        try {
            $this->campaign(['aff_campaign_notes' => 'not a column']);
            self::fail('a create carrying a field the resource does not have was accepted');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('aff_campaign_notes', $e->getFieldErrors());
        }
        self::assertSame($rows, $count(), 'no campaign was created');
    }

    public function testNullClearsAColumnThatCanHoldItAndIsRefusedForOneThatCannot(): void
    {
        $campaigns = new CampaignsController(self::$db, self::USER);
        $id = (int) $this->campaign(['aff_campaign_url_2' => 'https://two.example'])['aff_campaign_id'];

        $campaigns->update($id, ['aff_campaign_url_2' => null]);
        self::assertNull(self::$db->query("SELECT aff_campaign_url_2 FROM 202_aff_campaigns WHERE aff_campaign_id = $id")->fetch_row()[0], 'the null was written, not skipped');

        foreach (['aff_campaign_payout', 'aff_campaign_name', 'aff_network_id'] as $field) {
            try {
                $campaigns->update($id, [$field => null]);
                self::fail("null for NOT NULL $field was accepted");
            } catch (ValidationException $e) {
                self::assertStringContainsString('cannot be null', $e->getFieldErrors()[$field] ?? '');
            }
        }
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function numbersTheColumnCannotHold(): iterable
    {
        // Each was cast and stored as another number (or, past the column,
        // was a strict-mode 500 / a non-strict clamp).
        yield 'a fraction for an id' => ['aff_network_id', '1.5'];
        yield 'an exponent for an id' => ['aff_network_id', '1e3'];
        yield 'twenty digits' => ['aff_network_id', '99999999999999999999'];
        yield 'past MEDIUMINT UNSIGNED' => ['aff_network_id', '16777216'];
        yield 'a negative id' => ['aff_network_id', '-1'];
        yield 'past TINYINT' => ['aff_campaign_cloaking', 128];
        yield 'past DECIMAL(8,2)' => ['aff_campaign_payout', '1000000'];
        yield 'an infinite payout' => ['aff_campaign_payout', '1e400'];
        yield 'a name past VARCHAR(50)' => ['aff_campaign_name', str_repeat('n', 51)];
    }

    /** @dataProvider numbersTheColumnCannotHold */
    public function testAValueTheColumnCannotHoldIsRefusedNotChanged(string $field, mixed $value): void
    {
        $campaigns = new CampaignsController(self::$db, self::USER);
        $id = (int) $this->campaign()['aff_campaign_id'];
        $before = $campaigns->get($id)['data'];
        try {
            $campaigns->update($id, [$field => $value]);
            self::fail("$field = " . var_export($value, true) . ' was accepted');
        } catch (ValidationException $e) {
            self::assertSame([$field], array_keys($e->getFieldErrors()));
        }
        self::assertSame($before, $campaigns->get($id)['data']);
    }

    public function testAReadOnlyFieldIsAcceptedOnlyWithTheRecordsOwnValue(): void
    {
        $campaigns = new CampaignsController(self::$db, self::USER);
        $made = $this->campaign();
        $id = (int) $made['aff_campaign_id'];
        $public = (int) $made['aff_campaign_id_public'];

        $campaigns->update($id, ['aff_campaign_id_public' => (string) $public, 'aff_campaign_name' => 'renamed']);
        try {
            $campaigns->update($id, ['aff_campaign_id_public' => $public + 1]);
            self::fail('a changed public id was accepted');
        } catch (ValidationException $e) {
            self::assertStringContainsString('read-only', $e->getFieldErrors()['aff_campaign_id_public'] ?? '');
        }
        try {
            $this->campaign(['aff_campaign_id_public' => 12345]);
            self::fail('a create naming its own public id was accepted (and the id silently replaced)');
        } catch (ValidationException $e) {
            self::assertStringContainsString('set by the server', $e->getFieldErrors()['aff_campaign_id_public'] ?? '');
        }
    }

    public function testABodyReadBeforeAnotherWriteIsAConflict(): void
    {
        $campaigns = new CampaignsController(self::$db, self::USER);
        $id = (int) $this->campaign()['aff_campaign_id'];
        $stale = $campaigns->get($id)['data'];
        $campaigns->update($id, ['aff_campaign_payout' => '7']);

        try {
            $campaigns->update($id, ['aff_campaign_name' => 'mine'] + $stale);
            self::fail('a whole body read before the payout changed was written back over it');
        } catch (ConflictException $e) {
            self::assertSame('Version mismatch', $e->getMessage());
        }
        self::assertSame('7.00', (string) self::$db->query("SELECT aff_campaign_payout FROM 202_aff_campaigns WHERE aff_campaign_id = $id")->fetch_row()[0]);
    }

    public function testACampaignsLinkSentWithOnlyItsReadOnlyFieldsIsWritten(): void
    {
        // The rest of the body changes nothing (NothingToUpdateException from
        // the base); the link is the write.
        $campaigns = new CampaignsController(self::$db, self::USER);
        $id = (int) $this->campaign()['aff_campaign_id'];
        $after = $campaigns->update($id, ['aff_campaign_id' => $id, 'user_id' => self::USER, 'attribution_model_id' => null])['data'];
        self::assertNull($after['attribution_model_id']);
    }

    public function testBulkUpsertHoldsEachRowToTheSameRules(): void
    {
        $campaigns = new CampaignsController(self::$db, self::USER);
        $id = (int) $this->campaign()['aff_campaign_id'];
        $stateDir = sys_get_temp_dir() . '/p202-payload-it-' . bin2hex(random_bytes(4));
        mkdir($stateDir, 0700, true);
        putenv('P202_SERVER_STATE_DIR=' . $stateDir);
        RequestContext::setHeaders(['Idempotency-Key' => 'payload-it-' . bin2hex(random_bytes(6))]);
        try {
            $result = $campaigns->bulkUpsert(['rows' => [
                ['aff_campaign_id' => $id],
                ['aff_campaign_id' => $id, 'aff_campaign_nmae' => 'typo'],
                ['id' => $id, 'aff_campaign_name' => 'by alias'],
                ['aff_campaign_id' => 9999999, 'aff_campaign_name' => 'new', 'aff_campaign_url' => 'https://n.example',
                    'aff_campaign_payout' => 1, 'aff_network_id' => $this->network],
                ['aff_campaign_id' => $id, 'id' => $id + 1, 'aff_campaign_name' => 'which'],
            ]]);
        } finally {
            putenv('P202_SERVER_STATE_DIR');
        }

        $status = array_column($result['data'], 'status', 'index');
        self::assertSame(['skipped', 'error', 'updated', 'created', 'error'], array_values($status));
        self::assertArrayHasKey('aff_campaign_nmae', $result['data'][1]['field_errors']);
        self::assertSame('by alias', $campaigns->get($id)['data']['aff_campaign_name']);
        self::assertNotSame(9999999, (int) $result['data'][3]['data']['aff_campaign_id'], 'an id that names no record is a lookup key, not the new row\'s id');

        try {
            $campaigns->bulkUpsert(['rowz' => []]);
            self::fail('a body with a misspelled rows was read as rows');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('rowz', $e->getFieldErrors());
        }
    }
}
