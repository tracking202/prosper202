<?php

declare(strict_types=1);

namespace Tests\User;

use Api\V3\Controllers\UsersController;
use Api\V3\Exception\ValidationException;
use Api\V3\HttpException;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;

/**
 * PUT /users/{id}/preferences against a real database: the side effects
 * the settings pages have, and all-or-nothing when one of them fails.
 *
 * Skips unless a scratch test database is configured: P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS,
 * P202_TEST_DB_NAME. It installs the schema there and writes user 5001.
 *
 * @group integration
 */
final class PreferencesWriteIntegrationTest extends TestCase
{
    private const USER = 5001;

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
        // The API's own connection runs strict, so a value a column cannot
        // hold fails here as it would there.
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
        $u = self::USER;
        foreach (["DELETE FROM 202_users WHERE user_id = $u", "DELETE FROM 202_users_pref WHERE user_id = $u",
            "DELETE FROM 202_aff_campaigns WHERE user_id = $u", "DELETE FROM 202_charts WHERE user_id = $u"] as $sql) {
            self::$db->query($sql);
        }
        self::$db->query("INSERT INTO 202_users SET user_id = $u, user_name = 'prefs$u', user_pass = 'x', user_email = 'p$u@example.com',"
            . " user_dash_email = '', user_timezone = 'UTC', user_time_register = 0, install_hash = '', user_hash = '', user_deleted = 0");
        self::$db->query("INSERT INTO 202_users_pref SET user_id = $u, user_account_currency = 'USD', user_pref_limit = 50, cb_key = 'old', cb_verified = 1");
        foreach ([[9101, '12.50'], [9102, '4.00']] as [$id, $payout]) {
            self::$db->query("INSERT INTO 202_aff_campaigns SET aff_campaign_id = $id, user_id = $u, aff_network_id = 1, aff_campaign_name = 'c$id',"
                . " aff_campaign_url = 'https://x.example', aff_campaign_payout = '$payout', aff_campaign_currency = 'USD',"
                . " aff_campaign_foreign_payout = '0.00', aff_campaign_deleted = 0, aff_campaign_time = 0");
        }
    }

    private function prefs(): array
    {
        return (new UsersController(self::$db))->getPreferences(self::USER)['data'];
    }

    /** @return array<int, array{0: string, 1: string}> id => [payout, foreign payout] */
    private function payouts(): array
    {
        $out = [];
        $result = self::$db->query('SELECT aff_campaign_id, aff_campaign_payout, aff_campaign_foreign_payout FROM 202_aff_campaigns WHERE user_id = ' . self::USER);
        foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
            $out[(int) $row['aff_campaign_id']] = [$row['aff_campaign_payout'], $row['aff_campaign_foreign_payout']];
        }
        ksort($out);

        return $out;
    }

    public function testACurrencyChangeRepricesTheCampaignsWithEverythingElseInTheRequest(): void
    {
        $asked = [];
        $rate = static function (string $to, string $from, string $payout) use (&$asked): array {
            $asked[] = "$from>$to:$payout";
            return ['exchange_payout' => number_format((float) $payout * 2, 2, '.', '')];
        };
        (new UsersController(self::$db))->updatePreferences(self::USER, ['user_account_currency' => 'EUR', 'user_pref_limit' => 100], $rate);

        $prefs = $this->prefs();
        self::assertSame('EUR', $prefs['user_account_currency']);
        self::assertSame(100, (int) $prefs['user_pref_limit']);
        self::assertSame([9101 => ['25.00', '12.50'], 9102 => ['8.00', '4.00']], $this->payouts(), 'converted, the original kept as the foreign payout');
        self::assertSame(['USD>EUR:12.50', 'USD>EUR:4.00'], $asked);

        // Back to the campaigns' own currency: the originals return, no rate asked.
        $asked = [];
        (new UsersController(self::$db))->updatePreferences(self::USER, ['user_account_currency' => 'USD'], $rate);
        self::assertSame([9101 => ['12.50', '0.00'], 9102 => ['4.00', '0.00']], $this->payouts());
        self::assertSame([], $asked);
    }

    public function testARateTheServiceDoesNotGiveChangesNothingInTheRequest(): void
    {
        $rate = static fn (string $to, string $from, string $payout): ?array => $payout === '4.00' ? null : ['exchange_payout' => '20.00'];
        try {
            (new UsersController(self::$db))->updatePreferences(self::USER, ['user_account_currency' => 'EUR', 'user_pref_limit' => 100], $rate);
            self::fail('a missing rate must refuse the change');
        } catch (HttpException $e) {
            self::assertSame(502, $e->getHttpStatus());
            self::assertStringContainsString('not changed', $e->getMessage());
        }
        $prefs = $this->prefs();
        self::assertSame('USD', $prefs['user_account_currency']);
        self::assertSame(50, (int) $prefs['user_pref_limit'], 'the other preference in the request is not written either');
        self::assertSame([9101 => ['12.50', '0.00'], 9102 => ['4.00', '0.00']], $this->payouts());
    }

    public function testAnInvalidValueWritesNothing(): void
    {
        try {
            (new UsersController(self::$db))->updatePreferences(self::USER, ['user_pref_limit' => 100, 'user_daily_email' => 'off']);
            self::fail('off is not a daily-email hour');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('user_daily_email', $e->getFieldErrors());
        }
        self::assertSame(50, (int) $this->prefs()['user_pref_limit']);
    }

    public function testChangingTheClickBankKeyResetsItsVerification(): void
    {
        $users = new UsersController(self::$db);
        $users->updatePreferences(self::USER, ['cb_key' => 'old']);
        self::assertSame(1, (int) $this->prefs()['cb_verified'], 'the same key keeps its verification');
        $users->updatePreferences(self::USER, ['cb_key' => 'new']);
        self::assertSame(0, (int) $this->prefs()['cb_verified']);
    }

    public function testTheChartResolutionIsWrittenWhereTheChartReadsIt(): void
    {
        $users = new UsersController(self::$db);
        $users->updatePreferences(self::USER, ['chart_time_range' => 'hours']);
        $row = self::$db->query('SELECT data, chart_time_range FROM 202_charts WHERE user_id = ' . self::USER)->fetch_all(MYSQLI_ASSOC);
        self::assertCount(1, $row, 'an account with no chart gets one');
        self::assertSame('hours', $row[0]['chart_time_range']);
        self::assertIsArray(unserialize($row[0]['data']), 'with the installer\'s default chart');
        self::assertSame('hours', $this->prefs()['chart_time_range'], 'and reading it back says so');

        $users->updatePreferences(self::USER, ['chart_time_range' => 'days']);
        self::assertSame('1', (string) self::$db->query('SELECT COUNT(*) FROM 202_charts WHERE user_id = ' . self::USER)->fetch_row()[0], 'updated, not duplicated');
        self::assertSame('days', $this->prefs()['chart_time_range']);
    }

    /**
     * GET /users/{id}/preferences answers with the account's row, and the
     * PayKickstart IPN keys are never in it: whether any is stored, as every
     * secret (ServedPreferences), on the GET and on the PUT's answer.
     */
    public function testPayKickstartKeysAreWrittenAndNeverServed(): void
    {
        $users = new UsersController(self::$db);
        $column = 'paykickstart_ipn_secret_keys';
        $answer = $users->updatePreferences(self::USER, [$column => 'pk-key-one-0001, pk-key-two-0002']);

        foreach ([$answer['data'], $this->prefs()] as $served) {
            self::assertSame(['stored' => true], $served[$column]);
            self::assertStringNotContainsString('pk-key-', (string) json_encode($served));
        }
        $stored = self::$db->query("SELECT $column FROM 202_users_pref WHERE user_id = " . self::USER)->fetch_row()[0];
        self::assertSame("pk-key-one-0001\npk-key-two-0002", $stored);

        try {
            $users->updatePreferences(self::USER, [$column => 'pk-key-one-0001,short']);
            self::fail('a key that is not one was written');
        } catch (ValidationException $e) {
            self::assertSame(
                [$column => 'Key 2 is 5 characters long; a PayKickstart IPN secret key is 8 to 128.'],
                $e->getFieldErrors()
            );
        }
        $stored = self::$db->query("SELECT $column FROM 202_users_pref WHERE user_id = " . self::USER)->fetch_row()[0];
        self::assertSame("pk-key-one-0001\npk-key-two-0002", $stored, 'nothing written');

        // Its stored state sent back keeps the keys, as GET's body does.
        $users->updatePreferences(self::USER, [$column => ['stored' => true], 'user_pref_limit' => '25']);
        $stored = self::$db->query("SELECT $column FROM 202_users_pref WHERE user_id = " . self::USER)->fetch_row()[0];
        self::assertSame("pk-key-one-0001\npk-key-two-0002", $stored, 'kept');

        $users->updatePreferences(self::USER, [$column => '']);
        self::assertSame(['stored' => false], $this->prefs()[$column]);
    }

    /** The JVZIPN key is stored as the endpoint signs with it, or refused before anything is written. */
    public function testTheJvzooKeyIsStoredTrimmedOrRefused(): void
    {
        $users = new UsersController(self::$db);
        $column = 'jvzoo_ipn_secret_key';
        $users->updatePreferences(self::USER, [$column => ' 7hQe2LmZp4WvX9cRb31 ']);
        $stored = static fn (): ?string => self::$db->query("SELECT $column FROM 202_users_pref WHERE user_id = "
            . self::USER)->fetch_row()[0];
        self::assertSame('7hQe2LmZp4WvX9cRb31', $stored());

        try {
            $users->updatePreferences(self::USER, [$column => "7hQe2LmZp4Wv\nCvEEEE1"]);
            self::fail('a key that is not one was written');
        } catch (ValidationException $e) {
            self::assertStringStartsWith('The key has a character that is not text', $e->getFieldErrors()[$column]);
        }
        self::assertSame('7hQe2LmZp4WvX9cRb31', $stored(), 'nothing written');
        $users->updatePreferences(self::USER, [$column => '']);
        self::assertSame('', $stored());
    }
}
