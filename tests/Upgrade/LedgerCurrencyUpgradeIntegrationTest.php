<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;

/**
 * The 1.9.79 -> 1.9.80 rung gives 202_conversion_logs the currency each
 * row's amount is in (currency, stated_amount, stated_currency and the
 * reports' key) and makes 202_exchange_rates, as the installer makes them,
 * and logs nothing.
 *
 * The rows already there take their account's currency, which is what every
 * reader showed them in, unless the account shows it changed its currency
 * before (a campaign holding the payout it had under the earlier one): which
 * of its rows were written under which cannot be known, so they keep none,
 * as do the rows of an account with no preferences. A row that already has
 * a currency is left alone, so the step can run again.
 *
 * The rung runs as the upgrade page runs it (fixtures/ladder-runner.php).
 *
 * @group integration
 */
final class LedgerCurrencyUpgradeIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const TABLES = ['202_conversion_logs', '202_exchange_rates', '202_currency_changes', '202_aff_campaigns'];
    private const STEADY = 7871;
    private const CHANGED = 7872;
    private const NO_PREFS = 7873;
    private const UNREADABLE = 7874;
    private const DELETED_CAMPAIGN = 7875;

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        $found = self::$db->query('SELECT version FROM 202_version');
        $rows = $found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [];
        self::$versions = array_column($rows, 'version');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db === null) {
            return;
        }
        self::clear();
        self::q('DELETE FROM 202_version');
        foreach (self::$versions ?? [] as $version) {
            self::row('202_version', ['version' => $version]);
        }
        self::$db->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::clear();
    }

    private static function clear(): void
    {
        $users = implode(',', [self::STEADY, self::CHANGED, self::NO_PREFS, self::UNREADABLE, self::DELETED_CAMPAIGN]);
        self::q("DELETE FROM 202_conversion_logs WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_aff_campaigns WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_users_pref WHERE user_id IN ($users)");
        $history = self::$db->query("SHOW TABLES LIKE '202\\_currency\\_changes'");
        if ($history instanceof \mysqli_result && $history->num_rows > 0) {
            self::q("DELETE FROM 202_currency_changes WHERE user_id IN ($users)");
        }
    }

    /** A 1.9.79 table: no currency or consent columns, no key over them, no rates table. */
    private static function as1979(): void
    {
        self::q('ALTER TABLE 202_conversion_logs DROP INDEX user_currency_click_time, DROP INDEX superseded_by,'
            . ' DROP COLUMN currency,'
            . ' DROP COLUMN stated_amount, DROP COLUMN stated_currency, DROP COLUMN currency_basis,'
            . ' DROP COLUMN consent_withheld');
        self::q('DROP TABLE 202_exchange_rates');
        self::q('DROP TABLE 202_currency_changes');
        self::q('ALTER TABLE 202_aff_campaigns DROP COLUMN aff_campaign_currency_notice');
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => '1.9.79']);
    }

    private static function conversion(int $click, int $user, string $extra = ''): void
    {
        self::q("INSERT INTO 202_conversion_logs SET click_id = $click, campaign_id = 1, click_payout = 5, user_id = $user,"
            . " click_time = 1, conv_time = 2, time_difference = '', pixel_type = 0, user_agent = '', dedupe_key = 'k$click'$extra");
    }

    /** @return array<int, string> click_id => currency */
    private static function currencies(): array
    {
        $users = implode(',', [self::STEADY, self::CHANGED, self::NO_PREFS, self::UNREADABLE, self::DELETED_CAMPAIGN]);
        $rows = self::$db->query("SELECT click_id, currency FROM 202_conversion_logs WHERE user_id IN ($users) ORDER BY click_id");
        self::assertInstanceOf(\mysqli_result::class, $rows);

        return array_map('strval', array_column($rows->fetch_all(MYSQLI_ASSOC), 'currency', 'click_id'));
    }

    public function testTheRungAddsTheColumnsAsTheInstallerDoesAndGivesEachRowTheCurrencyThatCanBeKnown(): void
    {
        $installed = self::shapes();
        self::as1979();
        self::q('INSERT INTO 202_users_pref SET user_id = ' . self::STEADY . ", user_account_currency = 'EUR'");
        self::q('INSERT INTO 202_users_pref SET user_id = ' . self::CHANGED . ", user_account_currency = 'GBP'");
        // The sign a change of currency left: a campaign holding the payout it had before.
        self::q('INSERT INTO 202_aff_campaigns SET user_id = ' . self::CHANGED . ", aff_network_id = 1, aff_campaign_name = 'store',"
            . " aff_campaign_url = 'http://x', aff_campaign_payout = 8.00, aff_campaign_currency = 'USD',"
            . ' aff_campaign_foreign_payout = 10.00, aff_campaign_time = 1');
        self::q('INSERT INTO 202_aff_campaigns SET user_id = ' . self::STEADY . ", aff_network_id = 1, aff_campaign_name = 'store',"
            . " aff_campaign_url = 'http://x', aff_campaign_payout = 8.00, aff_campaign_currency = 'USD',"
            . ' aff_campaign_foreign_payout = 0, aff_campaign_time = 1');
        // Campaigns paid only by their postbacks' amounts: on the account that shows a change of currency the
        // old label may be the currency its network still reports in; on the steady one it is the column default.
        self::q('INSERT INTO 202_aff_campaigns SET user_id = ' . self::CHANGED . ", aff_network_id = 1, aff_campaign_name = 'shop',"
            . " aff_campaign_url = 'http://x', aff_campaign_payout = 0, aff_campaign_currency = 'USD',"
            . ' aff_campaign_foreign_payout = 0, aff_campaign_time = 1');
        self::q('INSERT INTO 202_aff_campaigns SET user_id = ' . self::STEADY . ", aff_network_id = 1, aff_campaign_name = 'shop',"
            . " aff_campaign_url = 'http://x', aff_campaign_payout = 0, aff_campaign_currency = 'USD',"
            . ' aff_campaign_foreign_payout = 0, aff_campaign_time = 1');
        self::conversion(978710001, self::STEADY);
        self::conversion(978710002, self::STEADY);
        self::conversion(978720001, self::CHANGED);
        self::conversion(978730001, self::NO_PREFS);
        // A stored value no page could print reads as USD everywhere, so that is what its rows were shown in.
        self::q('INSERT INTO 202_users_pref SET user_id = ' . self::UNREADABLE . ", user_account_currency = 'XYZ'");
        self::conversion(978740001, self::UNREADABLE);
        // The sign on a campaign since deleted still stands: nothing else says the currency changed.
        $deleted = self::DELETED_CAMPAIGN;
        self::q('INSERT INTO 202_users_pref SET user_id = ' . $deleted . ", user_account_currency = 'GBP'");
        self::q('INSERT INTO 202_aff_campaigns SET user_id = ' . $deleted . ', aff_network_id = 1,'
            . " aff_campaign_name = 'old', aff_campaign_url = 'http://x', aff_campaign_payout = 8.00,"
            . " aff_campaign_currency = 'USD', aff_campaign_foreign_payout = 10.00,"
            . ' aff_campaign_deleted = 1, aff_campaign_time = 1');
        self::conversion(978750001, self::DELETED_CAMPAIGN);

        [$out, $err] = self::upgrade();

        self::assertSame('RETURNED true', $out, $err);
        self::assertStringNotContainsString('Prosper202 upgrade', $err, 'a rung that converged logs nothing');
        self::assertSame($installed, self::shapes(), 'each table is the installed one');
        self::assertSame(
            [
                978710001 => 'EUR', 978710002 => 'EUR', 978720001 => '', 978730001 => '',
                978740001 => 'USD', 978750001 => '',
            ],
            self::currencies(),
            'the account\'s currency where nothing says it changed; none where it did, or where there is no account to ask'
        );
        $basis = self::$db->query('SELECT click_id, currency_basis FROM 202_conversion_logs WHERE click_id BETWEEN 978710001 AND 978750001 ORDER BY click_id');
        self::assertInstanceOf(\mysqli_result::class, $basis);
        self::assertSame(
            [978710001 => '1', 978710002 => '1', 978720001 => '0', 978730001 => '0', 978740001 => '1', 978750001 => '0'],
            array_map('strval', array_column($basis->fetch_all(MYSQLI_ASSOC), 'currency_basis', 'click_id')),
            'each row the upgrade labelled says so, for the owner to correct; an unrecorded one is known by its currency'
        );
        // Each account's currency history begins at the upgrade, in its currency as every page read it.
        $starts = self::$db->query("SELECT user_id, from_currency, to_currency FROM 202_currency_changes WHERE kind = 'upgrade' AND user_id IN ("
            . self::STEADY . ', ' . self::UNREADABLE . ') ORDER BY user_id');
        self::assertInstanceOf(\mysqli_result::class, $starts);
        self::assertSame([[(string) self::STEADY, 'EUR', 'EUR'], [(string) self::UNREADABLE, 'USD', 'USD']], $starts->fetch_all());
        // A campaign saved before the field returned (the column default, no foreign payout) says the account's currency.
        $shops = self::$db->query("SELECT user_id, aff_campaign_name, aff_campaign_currency, aff_campaign_currency_notice FROM 202_aff_campaigns"
            . ' WHERE user_id IN (' . self::STEADY . ', ' . self::CHANGED . ') ORDER BY user_id, aff_campaign_name');
        self::assertInstanceOf(\mysqli_result::class, $shops);
        self::assertSame([
            [(string) self::STEADY, 'shop', 'EUR', '0'], [(string) self::STEADY, 'store', 'EUR', '0'],
            [(string) self::CHANGED, 'shop', 'USD', '1'], [(string) self::CHANGED, 'store', 'USD', '1'],
        ], $shops->fetch_all(), 'the steady account\'s campaigns say its currency; on the one that changed, a campaign paid by'
            . ' its postbacks keeps its old currency and is listed with the others');
        // The campaigns paid in another currency are listed on Personal settings; a deleted one is not.
        $marked = self::$db->query('SELECT user_id, aff_campaign_deleted, aff_campaign_currency_notice FROM 202_aff_campaigns WHERE user_id IN ('
            . self::STEADY . ', ' . self::CHANGED . ', ' . self::DELETED_CAMPAIGN . ") AND aff_campaign_name <> 'shop' ORDER BY user_id");
        self::assertInstanceOf(\mysqli_result::class, $marked);
        self::assertSame(
            [[(string) self::STEADY, '0', '0'], [(string) self::CHANGED, '0', '1'], [(string) self::DELETED_CAMPAIGN, '1', '0']],
            $marked->fetch_all()
        );
        $version = self::$db->query('SELECT version FROM 202_version');
        self::assertInstanceOf(\mysqli_result::class, $version);
        self::assertSame('1.9.80', $version->fetch_row()[0]);
    }

    public function testARowThatHasACurrencyKeepsItWhenTheStepRunsAgain(): void
    {
        self::q('INSERT INTO 202_users_pref SET user_id = ' . self::STEADY . ", user_account_currency = 'EUR'");
        self::conversion(978710003, self::STEADY, ", currency = 'USD'");
        self::conversion(978710004, self::STEADY);
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => '1.9.79']);

        [$out, $err] = self::upgrade();

        self::assertSame('RETURNED true', $out, $err);
        self::assertStringNotContainsString('Prosper202 upgrade', $err);
        self::assertSame([978710003 => 'USD', 978710004 => 'EUR'], self::currencies());
        $starts = self::$db->query("SELECT COUNT(*) FROM 202_currency_changes WHERE kind = 'upgrade' AND user_id = " . self::STEADY);
        self::assertInstanceOf(\mysqli_result::class, $starts);
        self::assertSame('1', (string) $starts->fetch_row()[0], 'one start, however often the step runs');
        $basis = self::$db->query('SELECT click_id, currency_basis FROM 202_conversion_logs WHERE click_id IN (978710003, 978710004) ORDER BY click_id');
        self::assertInstanceOf(\mysqli_result::class, $basis);
        self::assertSame([978710003 => '0', 978710004 => '1'], array_map('strval', array_column($basis->fetch_all(MYSQLI_ASSOC), 'currency_basis', 'click_id')));
    }

    /** @return array<string, string> */
    private static function shapes(): array
    {
        $out = [];
        foreach (self::TABLES as $table) {
            $ddl = self::$db->query("SHOW CREATE TABLE $table");
            self::assertInstanceOf(\mysqli_result::class, $ddl);
            $out[$table] = (string) preg_replace('/ AUTO_INCREMENT=\d+/', '', (string) $ddl->fetch_row()[1]);
        }

        return $out;
    }

    /** @return array{string, string} what the runner printed, and what was logged */
    private static function upgrade(): array
    {
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_log=', __DIR__ . '/fixtures/ladder-runner.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            getenv()
        );
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertNotSame(2, proc_close($proc), "the runner could not set up: $err");

        return [trim($out), $err];
    }
}
