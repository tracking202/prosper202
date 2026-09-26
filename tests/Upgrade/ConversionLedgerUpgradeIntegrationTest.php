<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaReconciler;
use Prosper202\Database\Tables\ConversionTables;

/**
 * The ledger step of the 1.9.75 -> 1.9.76 rung, against a real server and a
 * 202_conversion_logs in the shape every install at or below 1.9.55 upgrades
 * into: no ledger columns, and UNIQUE (click_id, transaction_id).
 *
 * What it proves: the columns arrive, every existing row gets a key and a
 * source without two rows colliding, the old unique key goes, the step is
 * idempotent, and afterwards the reconciler finds nothing the installer's
 * definition has that the upgraded table lacks.
 *
 * Loading functions-upgrade.php pulls in the real DataEngine, which breaks
 * later suites that stub it, so every test runs in its own process.
 *
 * @group integration
 */
final class ConversionLedgerUpgradeIntegrationTest extends TestCase
{
    private static function connect(): ?\mysqli
    {
        $name = getenv('P202_TEST_DB_NAME') ?: '';
        if ($name === '' || (getenv('P202_TEST_DB_HOST') ?: '') === '') {
            return null;
        }
        mysqli_report(MYSQLI_REPORT_OFF);
        $db = @new \mysqli(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            $name,
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        mysqli_report(MYSQLI_REPORT_STRICT);

        return $db->connect_errno ? null : $db;
    }

    private function preLedgerDatabase(): \mysqli
    {
        $db = self::connect();
        if ($db === null) {
            self::markTestSkipped('Set P202_TEST_DB_HOST and P202_TEST_DB_NAME to a scratch database.');
        }
        require_once __DIR__ . '/../../202-config/functions-upgrade.php';
        $GLOBALS['db'] = $db;
        // The step logs what it does; in a child process PHPUnit would read
        // that stderr line as a failure.
        ini_set('error_log', sys_get_temp_dir() . '/p202-ledger-upgrade-it.log');

        $db->query('DROP TABLE IF EXISTS 202_conversion_logs');
        $db->query('DROP TABLE IF EXISTS 202_aff_campaigns');
        // The upgraded 1.9.55 shape: the 1.9.x rungs appended transaction_id,
        // click_payout and deleted, 1.9.60 made (click_id, transaction_id)
        // unique, 1.9.62 widened ip, 1.9.64 added customer_id.
        $db->query("CREATE TABLE 202_conversion_logs (
            conv_id int(11) unsigned NOT NULL AUTO_INCREMENT,
            click_id bigint(20) unsigned NOT NULL,
            campaign_id mediumint(8) unsigned NOT NULL,
            user_id mediumint(8) unsigned NOT NULL,
            click_time int(10) NOT NULL,
            conv_time int(10) NOT NULL,
            time_difference text NOT NULL,
            ip varchar(45) NOT NULL DEFAULT '',
            pixel_type int(11) unsigned NOT NULL,
            user_agent text NOT NULL,
            transaction_id varchar(255) DEFAULT NULL,
            click_payout decimal(11,5) NOT NULL,
            deleted tinyint(4) NOT NULL DEFAULT '0',
            customer_id bigint(20) unsigned DEFAULT NULL,
            PRIMARY KEY (conv_id),
            UNIQUE KEY uniq_click_transaction (click_id, transaction_id),
            KEY user_id (user_id),
            KEY campaign_id (campaign_id),
            KEY customer_id (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        $db->query("CREATE TABLE 202_aff_campaigns (
            aff_campaign_id mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
            user_id mediumint(8) unsigned NOT NULL,
            aff_campaign_payout decimal(8,2) NOT NULL,
            attribution_model_id int(11) DEFAULT NULL,
            PRIMARY KEY (aff_campaign_id)
        ) ENGINE=InnoDB");

        $rows = [
            // click 1: a network id and two blank-id rows (never deduped)
            [1, 'TX-1', 2, ''], [1, null, 2, ''], [1, null, 2, ''],
            [2, null, 1, 'Mozilla'], [3, null, 3, ''], [4, null, 0, 'subid-upload'], [5, null, 0, ''],
            // an empty-string id, which 1.9.60 normalised to NULL but a row
            // written since by a path that did not trim could still hold
            [6, '', 2, ''],
        ];
        foreach ($rows as [$click, $tx, $pixel, $ua]) {
            $txSql = $tx === null ? 'NULL' : "'" . $db->real_escape_string($tx) . "'";
            $db->query("INSERT INTO 202_conversion_logs (click_id, campaign_id, user_id, click_time, conv_time, time_difference, ip,
                pixel_type, user_agent, transaction_id, click_payout, deleted) VALUES ($click, 7, 1, 1, 2, '', '', $pixel, '$ua', $txSql, 5, 0)");
        }

        return $db;
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testThePreLedgerTableBecomesTheLedger(): void
    {
        $db = $this->preLedgerDatabase();

        $this->assertTrue(_upgrade_conversion_ledger());

        $rows = $db->query('SELECT conv_id, click_id, source, dedupe_key, superseded_reason FROM 202_conversion_logs ORDER BY conv_id')->fetch_all(MYSQLI_ASSOC);
        $this->assertSame(
            ['postback', 'postback', 'postback', 'pixel', 'universal_pixel', 'subid_upload', 'api', 'postback'],
            array_column($rows, 'source')
        );
        $this->assertSame(
            ['tx:TX-1', 'row:2', 'row:3', 'row:4', 'row:5', 'row:6', 'row:7', 'row:8'],
            array_column($rows, 'dedupe_key'),
            'a network id keeps its key; a blank or empty one gets its row\'s own'
        );
        $this->assertSame(['pre_ledger'], array_values(array_unique(array_column($rows, 'superseded_reason'))));

        $cols = $db->query("SHOW COLUMNS FROM 202_conversion_logs WHERE Field = 'dedupe_key'")->fetch_assoc();
        $this->assertSame('NO', $cols['Null'], 'dedupe_key is NOT NULL once every row holds one');

        $indexes = array_unique(array_column($db->query('SHOW INDEX FROM 202_conversion_logs')->fetch_all(MYSQLI_ASSOC), 'Key_name'));
        $this->assertContains('uniq_click_dedupe', $indexes);
        $this->assertContains('click_transaction', $indexes);
        $this->assertNotContains('uniq_click_transaction', $indexes, 'a reversal carries its sale\'s id, so the id cannot stay unique');

        $mode = $db->query("SHOW COLUMNS FROM 202_aff_campaigns WHERE Field = 'payout_mode'")->fetch_assoc();
        $this->assertSame("enum('replace','accumulate')", $mode['Type']);
        $this->assertSame('replace', $mode['Default'], 'every existing campaign keeps replacing');

        // A reversal row can now share a sale's transaction id.
        $this->assertTrue($db->query("INSERT INTO 202_conversion_logs (click_id, campaign_id, user_id, click_time, conv_time, time_difference, ip,
            pixel_type, user_agent, transaction_id, click_payout, deleted, source, dedupe_key, reverses_conv_id)
            VALUES (1, 7, 1, 1, 2, '', '', 2, '', 'TX-1', -5, 0, 'postback', 'rev:1:1', 1)"));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheStepIsIdempotentAndMatchesTheInstallersDefinition(): void
    {
        $db = $this->preLedgerDatabase();
        $this->assertTrue(_upgrade_conversion_ledger());
        $before = $db->query('SHOW CREATE TABLE 202_conversion_logs')->fetch_row()[1];

        $this->assertTrue(_upgrade_conversion_ledger(), 'a second run succeeds');
        $this->assertSame($before, $db->query('SHOW CREATE TABLE 202_conversion_logs')->fetch_row()[1], 'and changes nothing');

        $reconciler = new SchemaReconciler(static fn (string $sql) => _upgrade_query($sql));
        $this->assertTrue($reconciler->reconcile(ConversionTables::conversionLogs()));
        $this->assertSame([], $reconciler->getApplied(), 'the upgraded table already has every column and key the installer declares');
        $this->assertSame([], $reconciler->getUnreconciled());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAFailedStepLeavesTheVersionForTheNextRunToRetry(): void
    {
        $db = $this->preLedgerDatabase();
        $db->query('DROP TABLE 202_aff_campaigns');

        $this->assertFalse(_upgrade_conversion_ledger(), 'an unreadable campaigns table fails the step');
        // What ran is kept and the rest is retried: a second run, once the
        // table exists, completes.
        $db->query('CREATE TABLE 202_aff_campaigns (aff_campaign_id mediumint(8) unsigned NOT NULL, PRIMARY KEY (aff_campaign_id)) ENGINE=InnoDB');
        $this->assertTrue(_upgrade_conversion_ledger());
    }
}
