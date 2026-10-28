<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controller;
use Api\V3\Controllers\AffNetworksController;
use Api\V3\Controllers\PpcAccountsController;
use Api\V3\Controllers\PpcNetworksController;
use Api\V3\Exception\NotFoundException;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;

/**
 * The traffic source, traffic source account and category writes the MCP
 * tools ppc_network_update/_delete, ppc_account_update/_delete and
 * aff_network_update/_delete run (through p202 ppc-network, ppc-account and
 * aff-network update and delete): a rename changes the record in place, a
 * write names only the caller's own record, and a delete removes the one
 * record, as Setup › Traffic Sources and Setup › Categories remove it,
 * leaving its accounts or campaigns where they are.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME);
 * it installs the schema there and writes users 5811 and 5812's rows.
 *
 * @group integration
 */
final class SetupObjectsWriteScopeIntegrationTest extends TestCase
{
    private const USER = 5811;
    private const OTHER = 5812;

    private static ?\mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) '
                . '{ return $sql === null ? null : $dbOrSql->query($sql); }');
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
        $db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
        }
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        self::cleanUp();
    }

    private static function cleanUp(): void
    {
        foreach ([self::USER, self::OTHER] as $u) {
            foreach (['202_aff_campaigns', '202_aff_networks', '202_ppc_accounts', '202_ppc_networks'] as $table) {
                self::$db->query("DELETE FROM $table WHERE user_id = $u");
            }
        }
    }

    private static function q(string $sql): int
    {
        self::assertTrue(self::$db->query($sql), self::$db->error . ' in ' . $sql);

        return (int) self::$db->insert_id;
    }

    /** @return array<string, int> a category with a campaign, a traffic source with an account */
    private static function records(int $user): array
    {
        $network = self::q(
            "INSERT INTO 202_aff_networks SET user_id = $user, aff_network_name = 'Offers $user',"
            . ' aff_network_deleted = 0, aff_network_time = 0'
        );
        $campaign = self::q(
            "INSERT INTO 202_aff_campaigns SET user_id = $user, aff_network_id = $network,"
            . " aff_campaign_name = 'c$user', aff_campaign_url = 'https://o.example', aff_campaign_payout = 1,"
            . ' aff_campaign_foreign_payout = 0, aff_campaign_time = 0, aff_campaign_deleted = 0'
        );
        $source = self::q(
            "INSERT INTO 202_ppc_networks SET user_id = $user, ppc_network_name = 'Google Ads - Main $user',"
            . ' ppc_network_deleted = 0, ppc_network_time = 0'
        );
        $account = self::q(
            "INSERT INTO 202_ppc_accounts SET user_id = $user, ppc_network_id = $source, ppc_account_name = 'a$user',"
            . ' ppc_account_default = 0, ppc_account_deleted = 0, ppc_account_time = 0'
        );

        return [
            'aff_network_id' => $network,
            'aff_campaign_id' => $campaign,
            'ppc_network_id' => $source,
            'ppc_account_id' => $account,
        ];
    }

    /** @return array<string, string|null> */
    private static function row(string $table, string $key, int $id): array
    {
        $result = self::$db->query("SELECT * FROM $table WHERE $key = $id");
        self::assertNotFalse($result, self::$db->error);
        $row = $result->fetch_assoc();
        self::assertIsArray($row, "$table $id is gone");

        return $row;
    }

    /** One column of the row of $table whose $key column is $id. */
    private static function column(string $table, string $key, int $id, string $column): ?string
    {
        return self::row($table, $key, $id)[$column];
    }

    private static function rowsOf(string $table, int $user): int
    {
        $result = self::$db->query("SELECT COUNT(*) FROM $table WHERE user_id = $user");
        self::assertNotFalse($result, self::$db->error);

        return (int) $result->fetch_row()[0];
    }

    /**
     * @return list<array{0: Controller, 1: string, 2: string, 3: array<string, mixed>}>
     *         controller as USER, table, id key, a body that changes the record
     */
    private function writes(): array
    {
        return [
            [
                new PpcNetworksController(self::$db, self::USER),
                '202_ppc_networks', 'ppc_network_id', ['ppc_network_name' => 'Main'],
            ],
            [
                new PpcAccountsController(self::$db, self::USER),
                '202_ppc_accounts', 'ppc_account_id', ['ppc_account_default' => 1],
            ],
            [
                new AffNetworksController(self::$db, self::USER),
                '202_aff_networks', 'aff_network_id', ['aff_network_name' => 'Renamed'],
            ],
        ];
    }

    public function testARenameChangesTheRecordInPlace(): void
    {
        $mine = self::records(self::USER);
        foreach ($this->writes() as [$controller, $table, $key, $body]) {
            $before = self::rowsOf($table, self::USER);
            $answer = (array) $controller->update($mine[$key], $body)['data'];
            self::assertSame($mine[$key], (int) $answer[$key], "$table: the update answered another record");
            $stored = self::row($table, $key, $mine[$key]);
            foreach ($body as $field => $value) {
                self::assertSame((string) $value, $stored[$field], "$table.$field was not stored");
            }
            self::assertSame($before, self::rowsOf($table, self::USER), "$table: the update made or removed a row");
        }

        // The account still names the renamed source; the campaign the renamed category.
        $accountSource = self::column('202_ppc_accounts', 'ppc_account_id', $mine['ppc_account_id'], 'ppc_network_id');
        self::assertSame((string) $mine['ppc_network_id'], $accountSource);
        $campaign = $mine['aff_campaign_id'];
        $campaignCategory = self::column('202_aff_campaigns', 'aff_campaign_id', $campaign, 'aff_network_id');
        self::assertSame((string) $mine['aff_network_id'], $campaignCategory);
    }

    public function testAnotherAccountsRecordIsNotFoundForAnUpdateOrADelete(): void
    {
        self::records(self::USER);
        $theirs = self::records(self::OTHER);
        foreach ($this->writes() as [$controller, $table, $key, $body]) {
            $before = self::row($table, $key, $theirs[$key]);
            $writes = [
                'update' => fn () => $controller->update($theirs[$key], $body),
                'delete' => fn () => $controller->delete($theirs[$key]),
                'dry-run delete' => fn () => $controller->deletePreview($theirs[$key]),
            ];
            foreach ($writes as $what => $write) {
                try {
                    $write();
                    self::fail("$table: the $what of another account's record was not refused");
                } catch (NotFoundException) {
                }
            }
            $after = self::row($table, $key, $theirs[$key]);
            self::assertSame($before, $after, "$table: another account's record changed");
        }
    }

    /**
     * Setup › Traffic Sources removes a source alone and Setup › Categories a
     * category alone (the soft delete flag on that one row); the API does the
     * same, and its dry run says so with an empty cascade.
     */
    public function testADeleteRemovesTheOneRecordAndLeavesWhatItHolds(): void
    {
        $mine = self::records(self::USER);
        $sources = new PpcNetworksController(self::$db, self::USER);
        $categories = new AffNetworksController(self::$db, self::USER);
        $accounts = new PpcAccountsController(self::$db, self::USER);
        $source = $mine['ppc_network_id'];
        $account = $mine['ppc_account_id'];
        $category = $mine['aff_network_id'];

        $previews = [[$sources, $source], [$categories, $category], [$accounts, $account]];
        foreach ($previews as [$controller, $id]) {
            $preview = (array) $controller->deletePreview($id)['data'];
            self::assertSame('soft', $preview['mode'], (string) $preview['resource']);
            self::assertSame([], $preview['cascade'], (string) $preview['resource']);
        }
        $flag = self::column('202_ppc_networks', 'ppc_network_id', $source, 'ppc_network_deleted');
        self::assertSame('0', $flag, 'a dry run deleted the source');

        $sources->delete($source);
        self::assertSame('1', self::column('202_ppc_networks', 'ppc_network_id', $source, 'ppc_network_deleted'));
        $row = self::row('202_ppc_accounts', 'ppc_account_id', $account);
        self::assertSame('0', $row['ppc_account_deleted'], 'deleting a source deleted its account');
        self::assertSame((string) $source, $row['ppc_network_id']);

        $categories->delete($category);
        self::assertSame('1', self::column('202_aff_networks', 'aff_network_id', $category, 'aff_network_deleted'));
        $campaign = $mine['aff_campaign_id'];
        $campaignFlag = self::column('202_aff_campaigns', 'aff_campaign_id', $campaign, 'aff_campaign_deleted');
        self::assertSame('0', $campaignFlag, 'deleting a category deleted its campaign');

        $accounts->delete($account);
        self::assertSame('1', self::column('202_ppc_accounts', 'ppc_account_id', $account, 'ppc_account_deleted'));

        // A removed record is not found again.
        $this->expectException(NotFoundException::class);
        $sources->update($source, ['ppc_network_name' => 'Back']);
    }
}
