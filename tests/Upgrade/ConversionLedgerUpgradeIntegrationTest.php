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

        $identity = $db->query("SHOW COLUMNS FROM 202_aff_campaigns WHERE Field = 'identity_signals'")->fetch_assoc();
        $this->assertIsArray($identity, 'the campaign identity switch is added');
        $this->assertSame('1', (string) $identity['Default'], 'existing campaigns capture identity, as a new one does');

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

        // The reconciler compares names and nullability, not types or
        // collations; install == upgrade needs the whole column. A table
        // created from the installer's definition, under another name, is
        // what a fresh install has.
        $db->query('DROP TABLE IF EXISTS 202_conversion_logs_fresh');
        $fresh = str_replace('`202_conversion_logs`', '`202_conversion_logs_fresh`', ConversionTables::conversionLogs()->createStatement);
        $this->assertTrue($db->query($fresh), 'the installer definition creates');
        // By name: the pre-ledger rungs appended transaction_id and
        // click_payout where the installer declares them earlier, an order
        // no reader depends on.
        $columns = static function (string $table) use ($db): array {
            $out = [];
            foreach ($db->query('SHOW FULL COLUMNS FROM ' . $table)->fetch_all(MYSQLI_ASSOC) as $c) {
                $out[$c['Field']] = [$c['Field'], $c['Type'], $c['Collation'], $c['Null'], $c['Default']];
            }
            ksort($out);

            return $out;
        };
        $upgraded = $columns('202_conversion_logs');
        $installed = $columns('202_conversion_logs_fresh');
        $db->query('DROP TABLE 202_conversion_logs_fresh');
        $this->assertSame($installed, $upgraded, 'every column, its type, collation, nullability and default, as a fresh install has it');
        $byName = array_map(static fn (array $c): ?string => $c[2], $upgraded);
        $this->assertSame('utf8mb4_bin', $byName['dedupe_key'], 'the dedupe key compares exactly');
        $this->assertSame('utf8mb4_bin', $byName['transaction_id'], 'so does the transaction id a reversal is found by');
    }

    /**
     * A pre-ledger table's transaction_id is case-insensitive, and so is a
     * dedupe_key an earlier run of this step added; the step makes both
     * exact, and afterwards two sales whose ids differ only in case are two
     * rows on one click.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheStepMakesTheKeysCompareExactly(): void
    {
        $db = $this->preLedgerDatabase();
        // An earlier run that added dedupe_key under the table collation.
        $db->query('ALTER TABLE 202_conversion_logs ADD COLUMN dedupe_key varchar(320) DEFAULT NULL');

        $this->assertTrue(_upgrade_conversion_ledger());

        $collation = array_column($db->query('SHOW FULL COLUMNS FROM 202_conversion_logs')->fetch_all(MYSQLI_ASSOC), 'Collation', 'Field');
        $this->assertSame('utf8mb4_bin', $collation['dedupe_key']);
        $this->assertSame('utf8mb4_bin', $collation['transaction_id']);

        foreach (['tx:CASE-1', 'tx:case-1'] as $key) {
            $this->assertTrue($db->query("INSERT INTO 202_conversion_logs (click_id, campaign_id, user_id, click_time, conv_time, time_difference, ip,
                pixel_type, user_agent, transaction_id, click_payout, deleted, source, dedupe_key)
                VALUES (9, 7, 1, 1, 2, '', '', 2, '', '" . substr($key, 3) . "', 5, 0, 'postback', '$key')"), $key . ' is its own key');
        }
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheRungCreatesTheIdentityGraphAsTheInstallerDeclaresIt(): void
    {
        $db = $this->preLedgerDatabase();
        foreach (\Prosper202\Database\Tables\IdentityTables::getDefinitions() as $def) {
            $db->query('DROP TABLE IF EXISTS `' . $def->tableName . '`');
        }
        // The rung's own list.
        $this->assertTrue(_upgrade_measurement_tables(array_merge(
            \Prosper202\Database\Tables\AppTables::getDefinitions(),
            \Prosper202\Database\Tables\ConversionTables::getDefinitions(),
            \Prosper202\Database\Tables\IdentityTables::getDefinitions(),
            \Prosper202\Database\Tables\GoalTables::getDefinitions()
        )));
        $reconciler = new SchemaReconciler(static fn (string $sql) => _upgrade_query($sql));
        foreach (\Prosper202\Database\Tables\IdentityTables::getDefinitions() as $def) {
            $this->assertNotNull($db->query("SHOW TABLES LIKE '" . $def->tableName . "'")->fetch_row(), $def->tableName);
            $this->assertTrue($reconciler->reconcile($def));
        }
        $this->assertSame([], $reconciler->getApplied(), 'created with every column and key the installer declares');
    }

    /**
     * The app tables arrive under their new names in their new shape, from
     * the installer's definitions, and nothing is created under the names
     * they had before the reshape (plan §4.1).
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheRungCreatesTheAppTablesInTheirNewShapeAndNoneOfTheOldNames(): void
    {
        $db = $this->preLedgerDatabase();
        $old = ['202_attribution_apps', '202_attribution_postbacks', '202_attribution_conversion_values'];
        foreach (array_merge($old, array_map(
            static fn ($def): string => $def->tableName,
            \Prosper202\Database\Tables\AppTables::getDefinitions()
        )) as $table) {
            $db->query('DROP TABLE IF EXISTS `' . $table . '`');
        }

        $this->assertTrue(_upgrade_measurement_tables(array_merge(
            \Prosper202\Database\Tables\AppTables::getDefinitions(),
            \Prosper202\Database\Tables\ConversionTables::getDefinitions(),
            \Prosper202\Database\Tables\IdentityTables::getDefinitions(),
            \Prosper202\Database\Tables\GoalTables::getDefinitions()
        )));

        foreach ($old as $table) {
            $this->assertNull($db->query("SHOW TABLES LIKE '" . $table . "'")->fetch_row(), "$table must not be created");
        }
        $reconciler = new SchemaReconciler(static fn (string $sql) => _upgrade_query($sql));
        foreach (\Prosper202\Database\Tables\AppTables::getDefinitions() as $def) {
            $this->assertTrue($reconciler->reconcile($def), $def->tableName);
        }
        $this->assertSame([], $reconciler->getApplied(), 'created with every column and key the installer declares');

        $create = (string)$db->query('SHOW CREATE TABLE 202_app_registrations')->fetch_row()[1];
        $this->assertMatchesRegularExpression('/`app_key` varchar\(255\)[^,]*COLLATE utf8mb4_bin/', $create,
            'app_key compares byte for byte: Android application ids are case-sensitive');
        $this->assertStringContainsString('UNIQUE KEY `platform_app_key` (`platform`,`app_key`)', $create);

        $columns = [];
        $result = $db->query('SHOW COLUMNS FROM 202_app_postbacks');
        while ($row = $result->fetch_assoc()) {
            $columns[$row['Field']] = $row['Null'];
        }
        $this->assertSame('YES', $columns['registration_id'] ?? null, 'an unclaimed postback has no registration');
        $this->assertSame('YES', $columns['trusted'] ?? null, 'unvouched is NULL');
        $this->assertArrayNotHasKey('signature_valid', $columns);
    }

    /**
     * The goals engine's tables (PR 4) arrive with the rest of the
     * measurement schema, from the installer's own definitions — and the
     * 1.9.75 rung names them, so an upgraded database is not missing them.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheRungCreatesTheGoalTablesAsTheInstallerDeclaresThem(): void
    {
        $db = $this->preLedgerDatabase();
        foreach (\Prosper202\Database\Tables\GoalTables::getDefinitions() as $def) {
            $db->query('DROP TABLE IF EXISTS `' . $def->tableName . '`');
        }
        $this->assertTrue(_upgrade_measurement_tables(array_merge(
            \Prosper202\Database\Tables\AppTables::getDefinitions(),
            \Prosper202\Database\Tables\ConversionTables::getDefinitions(),
            \Prosper202\Database\Tables\IdentityTables::getDefinitions(),
            \Prosper202\Database\Tables\GoalTables::getDefinitions()
        )));
        $reconciler = new SchemaReconciler(static fn (string $sql) => _upgrade_query($sql));
        foreach (\Prosper202\Database\Tables\GoalTables::getDefinitions() as $def) {
            $this->assertNotNull($db->query("SHOW TABLES LIKE '" . $def->tableName . "'")->fetch_row(), $def->tableName);
            $this->assertTrue($reconciler->reconcile($def));
        }
        $this->assertSame([], $reconciler->getApplied(), 'created with every column and key the installer declares');
        $create = (string)$db->query('SHOW CREATE TABLE 202_app_skan_encodings')->fetch_row()[1];
        // MySQL 8 prints `int unsigned`; MariaDB still prints the display width.
        $this->assertMatchesRegularExpression('/`goal_id` int(\(10\))? unsigned NOT NULL/', $create, 'an encoding names a goal');
        $this->assertStringNotContainsString('`event_name`', $create);

        $rung = (string)file_get_contents(dirname(__DIR__, 2) . '/202-config/functions-upgrade.php');
        $this->assertMatchesRegularExpression(
            '/_upgrade_measurement_tables\(array_merge\([^;]*GoalTables::getDefinitions\(\)[^;]*\)\);/s',
            $rung,
            'the 1.9.75 rung creates the goal tables'
        );
    }

    /**
     * The Android install-token key (plan §5.1) is minted by the rung, once:
     * a second run keeps the key, so links already in circulation keep
     * verifying. The installer mints it with the same statement; the fresh
     * path is asserted in InstallIntakeIntegrationTest and by the live pass,
     * which installs an instance and signs a click with it.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheRungMintsTheInstallTokenKeyOnceAndLinksCampaignsToApps(): void
    {
        $db = $this->preLedgerDatabase();
        $db->query('DROP TABLE IF EXISTS 202_deployment_secrets');
        $this->assertTrue(_upgrade_measurement_tables(array_merge(
            \Prosper202\Database\Tables\AppTables::getDefinitions(),
            \Prosper202\Database\Tables\ConversionTables::getDefinitions(),
            \Prosper202\Database\Tables\IdentityTables::getDefinitions(),
            \Prosper202\Database\Tables\GoalTables::getDefinitions(),
            \Prosper202\Database\Tables\SecretTables::getDefinitions()
        )));
        $first = \Api\V3\Apps\Android\InstallTokenKey::load($db);
        $this->assertIsString($first, 'the rung mints the key');
        $this->assertSame(32, strlen($first));
        $this->assertTrue(_upgrade_measurement_tables(\Prosper202\Database\Tables\SecretTables::getDefinitions()));
        $this->assertSame($first, \Api\V3\Apps\Android\InstallTokenKey::load($db), 'a second run never replaces the key');

        $columns = [];
        $result = $db->query('SHOW COLUMNS FROM 202_aff_campaigns');
        while ($row = $result->fetch_assoc()) {
            $columns[$row['Field']] = $row['Null'];
        }
        $this->assertSame('YES', $columns['app_registration_id'] ?? null, 'a campaign may name the Android app its links install');

        $rung = (string)file_get_contents(dirname(__DIR__, 2) . '/202-config/functions-upgrade.php');
        $this->assertMatchesRegularExpression(
            '/_upgrade_measurement_tables\(array_merge\([^;]*SecretTables::getDefinitions\(\)[^;]*\)\);/s',
            $rung,
            'the 1.9.75 rung creates the secrets table (and so mints the key)'
        );
        // Code only, and the very next statement after the version seed: a
        // commented-out call, or one in a branch somewhere below, is not a
        // mint that runs whenever the install does.
        $install = '';
        foreach (token_get_all((string)file_get_contents(dirname(__DIR__, 2) . '/202-config/functions-install.php')) as $token) {
            if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                continue;
            }
            $install .= is_array($token) ? $token[1] : $token;
        }
        $this->assertMatchesRegularExpression(
            '/\$seeder->seedVersion\(\$php_version\);\s*\\\\Api\\\\V3\\\\Apps\\\\Android\\\\InstallTokenKey::ensure\(\$db\);/',
            $install,
            'a fresh install never runs a rung, so the installer mints the key itself, right after seeding the version'
        );
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
