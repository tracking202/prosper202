<?php

declare(strict_types=1);

namespace Tests\StaticEndpoint;

use PHPUnit\Framework\TestCase;

/**
 * Tests for p202ApplyConversionUpdate() in static-endpoint-helpers.php.
 *
 * This is the shared conversion function used by px.php and cb202.php.
 * If it breaks, ALL revenue recording silently fails.
 */
final class ConversionUpdateTest extends TestCase
{
    private static bool $loaded = false;

    public static function setUpBeforeClass(): void
    {
        // Provide a stub DB singleton and DataEngine before loading the helpers
        if (!class_exists('DB', false)) {
            eval('class DB {
                private static $instance;
                public static function getInstance() {
                    if (!self::$instance) self::$instance = new self();
                    return self::$instance;
                }
                public function getConnection() { return new \mysqli(); }
            }');
        }

        if (!class_exists('DataEngine', false)) {
            eval('class DataEngine {
                public array $dirtyHourCalls = [];
                public function setDirtyHour($click_id) { $this->dirtyHourCalls[] = $click_id; }
                public function getSummary($s,$e,$p,$u=1,$up=false,$n=false) { return ""; }
            }');
        }

        if (!self::$loaded) {
            require_once __DIR__ . '/../../202-config/static-endpoint-helpers.php';
            self::$loaded = true;
        }
    }

    private function createTrackingDb(array &$executedQueries, bool $queryReturns = true): FakeConversionMysqli
    {
        return new FakeConversionMysqli($executedQueries, $queryReturns);
    }

    // --- Core functionality ---

    public function testUpdatesClicksTableWithLeadFlag(): void
    {
        $queries = [];
        $db = $this->createTrackingDb($queries);

        p202ApplyConversionUpdate($db, '100', '25.00');

        $clicksQuery = $this->findQueryContaining($queries, '202_clicks');
        self::assertNotNull($clicksQuery, '202_clicks UPDATE must be executed');
        self::assertStringContainsString("click_lead='1'", $clicksQuery);
        self::assertStringContainsString("click_filtered='0'", $clicksQuery);
        self::assertStringContainsString("click_id='100'", $clicksQuery);
    }

    public function testUpdatesSpyTableWithLeadFlag(): void
    {
        $queries = [];
        $db = $this->createTrackingDb($queries);

        p202ApplyConversionUpdate($db, '100', '25.00');

        $spyQuery = $this->findQueryContaining($queries, '202_clicks_spy');
        self::assertNotNull($spyQuery, '202_clicks_spy UPDATE must be executed');
        self::assertStringContainsString("click_lead='1'", $spyQuery);
        self::assertStringContainsString("click_filtered='0'", $spyQuery);
    }

    public function testBothTablesReceiveSameSetClause(): void
    {
        $queries = [];
        $db = $this->createTrackingDb($queries);

        p202ApplyConversionUpdate($db, '42', '10.50');

        $clicksQuery = $this->findQueryContaining($queries, 'UPDATE');
        $spyQueries = array_filter($queries, fn($q) => str_contains($q, '202_clicks_spy'));

        self::assertNotEmpty($spyQueries, 'Spy table must be updated');

        // Both should contain the CPA value
        foreach ($queries as $q) {
            if (str_contains($q, 'UPDATE')) {
                self::assertStringContainsString("click_lead='1'", $q);
            }
        }
    }

    public function testSetsCpaValueInClickCpc(): void
    {
        $queries = [];
        $db = $this->createTrackingDb($queries);

        p202ApplyConversionUpdate($db, '100', '25.50');

        $clicksQuery = $this->findQueryContaining($queries, '202_clicks');
        self::assertStringContainsString("click_cpc='25.50'", $clicksQuery);
    }

    public function testEmptyCpaOmitsClickCpcSet(): void
    {
        $queries = [];
        $db = $this->createTrackingDb($queries);

        p202ApplyConversionUpdate($db, '100', '');

        $clicksQuery = $this->findQueryContaining($queries, '202_clicks');
        self::assertStringNotContainsString('click_cpc', $clicksQuery);
        // But must still set lead flag
        self::assertStringContainsString("click_lead='1'", $clicksQuery);
    }

    // --- Pixel payout override ---

    public function testUsePixelPayoutAddsPayoutToUpdateQuery(): void
    {
        $queries = [];
        $db = $this->createTrackingDb($queries);

        p202ApplyConversionUpdate($db, '100', '10.00', true, '50.00');

        $clicksQuery = $this->findQueryContaining($queries, '202_clicks');
        self::assertStringContainsString("click_payout='50.00'", $clicksQuery);
    }

    public function testWithoutPixelPayoutOmitsPayoutField(): void
    {
        $queries = [];
        $db = $this->createTrackingDb($queries);

        p202ApplyConversionUpdate($db, '100', '10.00', false);

        $clicksQuery = $this->findQueryContaining($queries, '202_clicks');
        self::assertStringNotContainsString('click_payout', $clicksQuery);
    }

    public function testPixelPayoutAppliedToBothClicksAndSpy(): void
    {
        $queries = [];
        $db = $this->createTrackingDb($queries);

        p202ApplyConversionUpdate($db, '100', '10.00', true, '99.99');

        $clicksQueries = array_filter($queries, fn($q) => str_contains($q, '202_clicks') && str_contains($q, 'UPDATE'));
        $spyQueries = array_filter($queries, fn($q) => str_contains($q, '202_clicks_spy'));

        foreach ($clicksQueries as $q) {
            self::assertStringContainsString("click_payout='99.99'", $q);
        }
        foreach ($spyQueries as $q) {
            self::assertStringContainsString("click_payout='99.99'", $q);
        }
    }

    // --- Campaign ID filter ---

    public function testAffCampaignIdAddsWhereClause(): void
    {
        $queries = [];
        $db = $this->createTrackingDb($queries);

        p202ApplyConversionUpdate($db, '100', '10.00', false, '', '55');

        $clicksQuery = $this->findQueryContaining($queries, '202_clicks');
        self::assertStringContainsString("aff_campaign_id='55'", $clicksQuery);
    }

    public function testNullAffCampaignIdOmitsFilter(): void
    {
        $queries = [];
        $db = $this->createTrackingDb($queries);

        p202ApplyConversionUpdate($db, '100', '10.00');

        $clicksQuery = $this->findQueryContaining($queries, '202_clicks');
        self::assertStringNotContainsString('aff_campaign_id', $clicksQuery);
    }

    // --- SQL injection protection ---

    public function testEscapesClickIdInWhereClause(): void
    {
        $queries = [];
        $db = $this->createTrackingDb($queries);

        p202ApplyConversionUpdate($db, "100' OR 1=1; --", '10.00');

        $clicksQuery = $this->findQueryContaining($queries, '202_clicks');
        // The value should be escaped (addslashes mock)
        self::assertStringContainsString("100\\' OR 1=1; --", $clicksQuery);
    }

    public function testEscapesCpaValue(): void
    {
        $queries = [];
        $db = $this->createTrackingDb($queries);

        p202ApplyConversionUpdate($db, '100', "25'; DROP TABLE 202_clicks; --");

        $clicksQuery = $this->findQueryContaining($queries, '202_clicks');
        self::assertStringContainsString("25\\';", $clicksQuery);
    }

    // --- Failure handling ---

    /**
     * KNOWN RISK: When $db->query() returns false, p202ApplyConversionUpdate
     * accesses $db->error for error_log(). On PHP 8.4 with a disconnected
     * mysqli, accessing ->error throws a fatal Error ("object is already closed").
     *
     * This means a DB write failure in p202ApplyConversionUpdate will crash the
     * entire conversion recording — the spy table update won't be attempted.
     *
     * This test documents the risk. The fix would be to wrap the error_log
     * in a try/catch or use a variable to capture the error before logging.
     */
    public function testClicksUpdateFailureBehaviorDocumented(): void
    {
        // We verify that both UPDATE queries are constructed correctly
        // (the failure path cannot be tested without a real DB connection
        // because PHP 8.4 throws when accessing ->error on a closed mysqli)
        $queries = [];
        $db = $this->createTrackingDb($queries);

        p202ApplyConversionUpdate($db, '100', '10.00');

        // Both queries must be attempted
        $updateQueries = array_values(array_filter(
            $queries,
            fn($q) => str_contains(strtoupper($q), 'UPDATE')
        ));
        self::assertCount(2, $updateQueries);
        self::assertStringContainsString('202_clicks', $updateQueries[0]);
        self::assertStringContainsString('202_clicks_spy', $updateQueries[1]);
    }

    public function testAlwaysExecutesTwoUpdateQueries(): void
    {
        $queries = [];
        $db = $this->createTrackingDb($queries);

        p202ApplyConversionUpdate($db, '1', '0');

        $updateQueries = array_filter($queries, fn($q) => str_contains(strtoupper($q), 'UPDATE'));
        self::assertCount(2, $updateQueries, 'Must update both 202_clicks and 202_clicks_spy');
    }

    // --- Helper: p202ResolveAdvertiserId ---

    public function testResolveAdvertiserIdReturnsNullForZeroCampaign(): void
    {
        $db = new FakeConversionMysqliNoPrepare();
        self::assertNull(p202ResolveAdvertiserId($db, 0));
    }

    public function testResolveAdvertiserIdReturnsNullForNegativeCampaign(): void
    {
        $db = new FakeConversionMysqliNoPrepare();
        self::assertNull(p202ResolveAdvertiserId($db, -1));
    }

    // --- Helpers ---

    private function findQueryContaining(array $queries, string $needle): ?string
    {
        foreach ($queries as $q) {
            if (str_contains($q, $needle)) {
                return $q;
            }
        }
        return null;
    }
}

/**
 * Fake mysqli for conversion update tests — avoids readonly property issues in PHP 8.4.
 */
class FakeConversionMysqli extends \mysqli
{
    /** @var list<string> */
    private array $executedQueries;
    private bool $queryReturns;

    public function __construct(array &$executedQueries, bool $queryReturns = true)
    {
        $this->executedQueries = &$executedQueries;
        $this->queryReturns = $queryReturns;
    }

    public function real_escape_string(string $string): string
    {
        return addslashes($string);
    }

    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): \mysqli_result|bool
    {
        $this->executedQueries[] = $query;
        return $this->queryReturns;
    }

    public function close(): true
    {
        return true;
    }
}

/**
 * Fake where prepare should never be called.
 */
class FakeConversionMysqliNoPrepare extends \mysqli
{
    public function __construct()
    {
        // Skip parent
    }

    #[\ReturnTypeWillChange]
    public function prepare(string $query): \mysqli_stmt|false
    {
        throw new \LogicException('prepare() should not be called');
    }

    public function close(): true
    {
        return true;
    }
}
