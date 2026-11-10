<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaReconciler;
use Prosper202\Database\Tables\TrackingTables;
use Prosper202\Database\Tables\UserTables;

/**
 * The 1.9.79 -> 1.9.80 rung gives 202_google a key on each ad click id and
 * 202_users_pref the account's click id parameters, which a global postback
 * with no SubID finds its click by (Prosper202\Click\ClickByAdClickId,
 * Prosper202\Conversion\ClickIdParameters): upgraded, both tables are what an
 * install creates, with the rows they held.
 *
 * @group integration
 */
final class AdClickIdLookupUpgradeIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const KEYS = ['by_gclid', 'by_gbraid', 'by_wbraid', 'by_ttclid', 'by_msclkid', 'by_fbclid'];
    private const CLICK = 99072801;
    private const USER = 990728;

    /** @var array<string, string> the tables as the installer creates them */
    private static array $installed = [];

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        self::reconcile();
        foreach (['202_google', '202_users_pref'] as $table) {
            self::$installed[$table] = self::createTable($table);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::reconcile();
            self::q('DELETE FROM 202_google WHERE click_id = ' . self::CLICK);
            self::q('DELETE FROM 202_users_pref WHERE user_id = ' . self::USER);
        }
    }

    private static function reconcile(): void
    {
        $reconciler = new SchemaReconciler(static fn (string $sql): mixed => self::$db->query($sql));
        foreach ([TrackingTables::google(), UserTables::usersPref()] as $definition) {
            self::assertTrue($reconciler->reconcile($definition), implode('; ', $reconciler->getErrors()));
        }
    }

    private static function createTable(string $table): string
    {
        $result = self::$db->query("SHOW CREATE TABLE $table");
        self::assertInstanceOf(\mysqli_result::class, $result);

        return (string) $result->fetch_row()[1];
    }

    public function testTheRungBringsBothTablesToWhatAnInstallCreates(): void
    {
        self::requireScratch();
        // As 1.9.79 left them.
        self::q('ALTER TABLE 202_google DROP KEY ' . implode(', DROP KEY ', self::KEYS));
        self::q('ALTER TABLE 202_users_pref DROP COLUMN user_pref_click_id_params');
        self::q('DELETE FROM 202_google WHERE click_id = ' . self::CLICK);
        self::q('DELETE FROM 202_users_pref WHERE user_id = ' . self::USER);
        self::q('INSERT INTO 202_google SET click_id = ' . self::CLICK . ", gclid = 'Cj0KCQiA-demo', utm_source_id = 0,"
            . " utm_medium_id = 0, utm_campaign_id = 0, utm_term_id = 0, utm_content_id = 0, msclkid = 'abc'");
        self::row('202_users_pref', ['user_id' => self::USER]);
        self::assertNotSame(self::$installed['202_google'], self::createTable('202_google'));

        self::reconcile();

        foreach (self::$installed as $table => $installed) {
            self::assertSame($installed, self::createTable($table), "upgraded, $table is what an install creates");
        }
        foreach (self::KEYS as $key) {
            $declared = "KEY `$key` (`" . substr($key, 3) . '`(40))';
            self::assertStringContainsString($declared, self::$installed['202_google']);
        }
        $kept = self::$db->query('SELECT gclid, msclkid FROM 202_google WHERE click_id = ' . self::CLICK);
        self::assertInstanceOf(\mysqli_result::class, $kept);
        self::assertSame(['Cj0KCQiA-demo', 'abc'], $kept->fetch_row());
        $none = self::$db->query('SELECT user_pref_click_id_params FROM 202_users_pref WHERE user_id = ' . self::USER);
        self::assertInstanceOf(\mysqli_result::class, $none);
        self::assertSame([''], $none->fetch_row(), 'an account already there lists no parameter');
    }

    public function testTheStepReconcilesBothTables(): void
    {
        $ladder = (string) file_get_contents(dirname(__DIR__, 2) . '/202-config/functions-upgrade.php');
        $from = strpos($ladder, "if (\$prosper202_version == '1.9.79') {");
        $to = strpos($ladder, "UPDATE 202_version SET version='1.9.80'");
        self::assertIsInt($from);
        self::assertIsInt($to);
        $step = substr($ladder, $from, $to - $from);
        self::assertStringContainsString('TrackingTables::google()', $step);
        self::assertStringContainsString('UserTables::usersPref()', $step);
    }
}
