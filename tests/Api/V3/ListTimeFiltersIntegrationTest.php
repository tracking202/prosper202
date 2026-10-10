<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\AffNetworksController;
use Api\V3\Controllers\CampaignsController;
use Api\V3\Controllers\ForecastEventsController;
use Api\V3\Controllers\LandingPagesController;
use Api\V3\Controllers\PpcAccountsController;
use Api\V3\Controllers\PpcNetworksController;
use Api\V3\Controllers\TextAdsController;
use Api\V3\Controllers\TrackersController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;

/**
 * A CRUD list's time filters, against a real server: `updated_since`
 * filters where the records keep an update time (forecast events'
 * updated_at) and is refused naming it where they keep none (every Setup
 * table); `deleted_since` is refused on every list, which answers live
 * records only.
 *
 * Both used to probe the table with `SHOW COLUMNS FROM t LIKE ?`, which no
 * server prepares: measured live, GET /campaigns?updated_since=<now> and
 * GET /aff-networks?deleted_since=1 answered 500. The unit tests' fake
 * connection prepared the probe happily, which is why only a real server
 * shows it (CLAUDE.md #9). And had the probe answered, none of the Setup
 * tables has the columns it looked for, so the filter would have been
 * dropped and every row answered as though filtered.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME);
 * it installs the schema there and writes user 5901's forecast events.
 *
 * @group integration
 */
final class ListTimeFiltersIntegrationTest extends TestCase
{
    private const USER = 5901;

    private static ?\mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) { return $sql === null ? null : $dbOrSql->query($sql); }');
        }
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
        if (self::$db !== null) {
            self::$db->query('DELETE FROM 202_forecast_events WHERE user_id = ' . self::USER);
            self::$db->close();
        }
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        self::$db->query('DELETE FROM 202_forecast_events WHERE user_id = ' . self::USER);
    }

    /** @return array<string, object> */
    private function setupLists(): array
    {
        return [
            'campaigns' => new CampaignsController(self::$db, self::USER),
            'aff-networks' => new AffNetworksController(self::$db, self::USER),
            'ppc-networks' => new PpcNetworksController(self::$db, self::USER),
            'ppc-accounts' => new PpcAccountsController(self::$db, self::USER),
            'trackers' => new TrackersController(self::$db, self::USER),
            'landing-pages' => new LandingPagesController(self::$db, self::USER),
            'text-ads' => new TextAdsController(self::$db, self::USER),
        ];
    }

    /** @param array<string, string> $params */
    private function assertRefused(object $controller, array $params, string $field, string $what): void
    {
        try {
            $controller->list($params);
        } catch (ValidationException $e) {
            self::assertSame([$field], array_keys($e->getFieldErrors()), "$what: the 422 names $field");

            return;
        }
        self::fail("$what answered a list");
    }

    public function testASetupListRefusesBothTimeFiltersNamingThem(): void
    {
        foreach ($this->setupLists() as $resource => $controller) {
            $this->assertRefused($controller, ['updated_since' => (string) time()], 'updated_since', "GET /$resource?updated_since");
            $this->assertRefused($controller, ['deleted_since' => '1'], 'deleted_since', "GET /$resource?deleted_since");
            self::assertArrayHasKey('data', $controller->list([]), "GET /$resource without them still lists");
        }
    }

    public function testForecastEventsFilterOnTheirUpdateTime(): void
    {
        $insert = self::$db->prepare(
            'INSERT INTO 202_forecast_events (user_id, event_name, event_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ([['old', 1000], ['new', 2000]] as [$name, $at]) {
            $user = self::USER;
            $date = '2026-10-01';
            $insert->bind_param('issii', $user, $name, $date, $at, $at);
            self::assertTrue($insert->execute());
        }
        $insert->close();

        $events = new ForecastEventsController(self::$db, self::USER);
        self::assertCount(2, $events->list([])['data'], 'both events are listed without the filter');
        self::assertSame(['new'], array_column($events->list(['updated_since' => '1500'])['data'], 'event_name'), 'only what was written since');
        self::assertCount(2, $events->list(['updated_since' => '1000'])['data'], 'updated_since is inclusive');
        self::assertCount(0, $events->list(['updated_since' => '2001'])['data']);
        $this->assertRefused($events, ['deleted_since' => '1'], 'deleted_since', 'GET /forecast-events?deleted_since');
    }
}
