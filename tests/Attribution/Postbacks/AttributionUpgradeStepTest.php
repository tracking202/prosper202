<?php

declare(strict_types=1);

namespace Tests\Attribution\Postbacks;

use Api\V3\Attribution\AdAttributionKitProtocol;
use Api\V3\Attribution\SignatureState;
use Api\V3\Attribution\SkadnetworkProtocol;
use Prosper202\Database\Schema\SchemaBuilder;
use Prosper202\Database\SchemaReconciler;
use Prosper202\Database\Tables\AttributionPostbackTables;
use Tests\TestCase;

/**
 * The SKAN tables ship in version 1.9.76. An install sitting at 1.9.75
 * (master's version) reaches them only through a normal version-gated
 * upgrade step, so the bump must be internally consistent: version.php's
 * constant, an upgrade block that creates the tables and persists that
 * constant, and the downgrade guard all have to agree. F1 was exactly this
 * going wrong — a version whose schema no upgrade step created — and CI
 * never catches it because CI always installs fresh. This pins it textually.
 */
final class AttributionUpgradeStepTest extends TestCase
{
    private const CURRENT_VERSION = '1.9.76';
    private const PRIOR_VERSION = '1.9.75';

    private function upgradeSource(): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/202-config/functions-upgrade.php');
    }

    public function testVersionConstantIsTheBumpedVersion(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/202-config/version.php');
        $this->assertStringContainsString("\$version_string = '" . self::CURRENT_VERSION . "'", $source);
    }

    public function testAnUpgradeStepGatedOnThePriorVersionCreatesTheAttributionPostbackTables(): void
    {
        $source = $this->upgradeSource();

        $gate = strpos($source, "if (\$prosper202_version == '" . self::PRIOR_VERSION . "')");
        $this->assertNotFalse($gate, 'there must be an upgrade block gated on ' . self::PRIOR_VERSION);

        // The block's DDL must come from the installer definitions (never a
        // hand-copied CREATE that can drift), and it must persist the bumped
        // version so a 1.9.75 install converges to 1.9.76.
        // Bounded by the NEXT version gate rather than a byte count: a
        // fixed window silently slides off the end as the block grows (a
        // false failure) or, once a 1.9.76 block is appended, spills into
        // it and matches ITS version UPDATE — passing while this block no
        // longer persists a version at all, the exact defect this test
        // exists to catch.
        $nextGate = strpos($source, "if (\$prosper202_version == '", $gate + 1);
        $block = $nextGate === false
            ? substr($source, $gate)
            : substr($source, $gate, $nextGate - $gate);
        $this->assertStringContainsString('AttributionPostbackTables::getDefinitions()', $block);
        $this->assertStringContainsString("version='" . self::CURRENT_VERSION . "'", $block);
    }

    public function testThePriorVersionBlockReconcilesExistingTablesAndDoesNotOnlyCreateThem(): void
    {
        // CREATE TABLE IF NOT EXISTS converges nothing: against a table that
        // already exists in an older shape it is a no-op, and the block used
        // to contain nothing else. An install carrying an earlier shape of
        // these tables therefore came out of the upgrade still missing
        // columns the running code selects.
        $source = $this->upgradeSource();

        $gate = strpos($source, "if (\$prosper202_version == '" . self::PRIOR_VERSION . "')");
        $this->assertNotFalse($gate);
        $nextGate = strpos($source, "if (\$prosper202_version == '", $gate + 1);
        $block = $nextGate === false
            ? substr($source, $gate)
            : substr($source, $gate, $nextGate - $gate);

        $this->assertStringContainsString('_upgrade_attribution_tables(', $block);
        $this->assertStringContainsString('SchemaReconciler', $source);
    }

    public function testAnUpgradeStepConvergesInstallsAlreadyAtTheCurrentVersion(): void
    {
        // The 1.9.76 attribution tables were reshaped in place before
        // release, so an install can read 1.9.76 and still hold a table
        // shape the running code cannot query. That install never enters the
        // 1.9.75 gate, so a step gated on the CURRENT version has to converge
        // it. Bounded by the downgrade guard that closes the ladder.
        //
        // 202-config/upgrade.php cannot reach this block — upgrade_needed()
        // is false when the stored and code versions match, and the page
        // _die()s "Already Upgraded" first. The 1-click upgrade pages can:
        // 202-account/auto-upgrade.php:204 and auto-upgrade-premium.php:147
        // call UPGRADE::upgrade_databases() with no upgrade_needed() gate,
        // and the include_once of the already-loaded functions-upgrade.php
        // is a no-op, so THIS release's ladder runs against a stored 1.9.76.
        // Executed against a scratch database, not inferred.
        $source = $this->upgradeSource();

        $gate = strpos($source, "if (\$prosper202_version == '" . self::CURRENT_VERSION . "')");
        $this->assertNotFalse(
            $gate,
            'there must be an upgrade block gated on ' . self::CURRENT_VERSION . ' that converges the reshaped tables'
        );

        $end = strpos($source, 'This will enable p202 to downgrade', $gate);
        $this->assertNotFalse($end);
        $block = substr($source, $gate, $end - $gate);

        $this->assertStringContainsString('_upgrade_attribution_tables(', $block);
        $this->assertStringContainsString('AttributionPostbackTables::getDefinitions()', $block);
        // It must not bump the version: 1.9.76 is the current one, and
        // writing a version this release does not know would strand the
        // install above the ladder.
        $this->assertStringNotContainsString('UPDATE 202_version', $block);
    }

    public function testTheRenamedLegacyTablesAreDetectedRatherThanSilentlyReplaced(): void
    {
        // The pre-release tables were called 202_skan_*. Those are different
        // table NAMES, so CREATE TABLE IF NOT EXISTS happily builds empty
        // 202_attribution_* tables beside them and every postback already
        // received disappears from the API while the upgrade reports success.
        $source = $this->upgradeSource();

        $this->assertStringContainsString('202_skan_postbacks', $source);
        $this->assertStringContainsString('_upgrade_attribution_legacy_skan_state', $source);

        $start = strpos($source, 'function _upgrade_attribution_tables');
        $this->assertNotFalse($start);
        $body = substr($source, $start, 6000);

        // Loud stop, not a silent skip, and the message has to name the
        // manual step (error pattern #4).
        $this->assertStringContainsString("'legacy-data'", $body);
        $this->assertStringContainsString('_die(', $body);
        // An unreadable probe must not read as "no legacy data" and let the
        // CREATEs run (error pattern #11).
        $this->assertStringContainsString("'unknown'", $body);

        // Legacy rows AND current rows is not evidence that anyone copied
        // anything: postbacks received fresh under the new names produce the
        // same counts. That state used to resolve to 'legacy-migrated' and
        // continue, which strands the legacy rows behind a log line. Row
        // counts cannot answer the question, so it fails closed like the
        // branch above.
        $this->assertStringNotContainsString('legacy-migrated', $source);
        $this->assertStringContainsString("'legacy-and-current-data'", $body);

        $ambiguous = strpos($body, "if (\$state === 'legacy-and-current-data')");
        $this->assertNotFalse($ambiguous, 'the ambiguous state needs its own branch');
        $branch = substr($body, $ambiguous, 2600);
        $this->assertStringContainsString('_die(', $branch);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testEveryColumnAddedNotNullWithoutADefaultIsBackfilled(): void
    {
        // A column added NOT NULL with no DEFAULT gets the server's implicit
        // default ('') on every pre-existing row, and '' is not a value any
        // reader accepts: the API's protocol filter matches none of those
        // rows and every GROUP BY buckets them under ''. Adding the column
        // without backfilling it is the same "the postbacks vanish from the
        // API and the reports" loss the legacy halt exists to prevent, so
        // whatever the reconciler adds in that shape must be backfilled in
        // the same step. Scope: 202_attribution_postbacks, the only one of
        // the three tables this test has a pre-release column list for —
        // 202_attribution_apps' added column carries DEFAULT '0'.
        $plan = SchemaReconciler::planChanges(
            AttributionPostbackTables::attributionPostbacks(),
            $this->preReleasePostbackColumns(),
            []
        );

        $needBackfill = [];
        foreach ($plan['statements'] as $statement) {
            if (preg_match('/ADD COLUMN `([^`]+)`(.*)$/', $statement, $match) !== 1) {
                continue;
            }
            if (stripos($match[2], 'NOT NULL') === false) {
                continue;
            }
            if (preg_match('/\bDEFAULT\b/i', $match[2]) === 1) {
                continue;
            }
            $needBackfill[] = $match[1];
        }
        sort($needBackfill);

        // Not vacuous: the pre-release shape really is missing two of them.
        $this->assertSame(['protocol', 'signature_state'], $needBackfill);

        $statements = $this->backfillStatements();
        $this->assertNotSame([], $statements);

        foreach ($needBackfill as $column) {
            $targeted = array_filter(
                $statements,
                static fn (string $sql): bool => str_contains($sql, 'SET `' . $column . '` = ')
            );
            $this->assertNotSame([], $targeted, $column . ' is added NOT NULL with no default and never backfilled');

            foreach ($targeted as $sql) {
                // Only rows still holding the implicit default, so a real
                // value is never overwritten and a re-run changes nothing.
                $this->assertStringContainsString("WHERE `" . $column . "` = ''", $sql);
            }
        }
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheBackfilledSignatureStateIsTheOneTheReceiverWouldHaveStored(): void
    {
        // The trust bit already on the row is what the state produced:
        // PostbackReceiver stores signature_valid = SignatureState::trustBit()
        // and AttributionPostbacksController's `signature` filter reads the
        // pair back the same way. Derive the expected predicate from the enum
        // so a change to trustBit() breaks this rather than the upgrade.
        $statements = implode("\n", $this->backfillStatements());

        foreach ([SignatureState::VALID, SignatureState::INVALID, SignatureState::UNVERIFIABLE] as $state) {
            $bit = $state->trustBit(false);
            $predicate = $bit === null ? '`signature_valid` IS NULL' : '`signature_valid` = ' . $bit;
            $this->assertStringContainsString(
                "SET `signature_state` = '" . $state->value . "' WHERE `signature_state` = '' AND " . $predicate,
                $statements
            );
        }

        // DEVELOPMENT shares its trust bits with the other two (1 when the app
        // opted in, NULL when it did not) and its opt-in column arrived with
        // signature_state, so no row that predates the column can be one. It
        // must not be guessed onto a row.
        $this->assertStringNotContainsString(SignatureState::DEVELOPMENT->value, $statements);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheProtocolBackfillNamesTheProtocolThatPredatesTheColumn(): void
    {
        // AdAttributionKit support arrived WITH the protocol column, so a row
        // that has no protocol can only be SKAdNetwork.
        $statements = implode("\n", $this->backfillStatements());

        $this->assertStringContainsString(
            "SET `protocol` = '" . SkadnetworkProtocol::NAME . "' WHERE `protocol` = ''",
            $statements
        );
        $this->assertStringNotContainsString(AdAttributionKitProtocol::NAME, $statements);
    }

    /**
     * The upgrade's backfill statements, from the upgrade file itself.
     *
     * @return array<int, string>
     */
    private function backfillStatements(): array
    {
        // Loading the upgrade file pulls in 202-config/class-dataengine.php
        // (functions-upgrade.php:7), and DataEngine's constructor resolves a
        // real connection. Once that class is in the process, any later suite
        // that builds a DataEngine gets the real one instead of the stub it
        // expects, and fails with "mysqli object is not fully initialized" —
        // tests/StaticEndpoint runs after tests/Attribution, so it was the
        // one that broke. The callers therefore run in their own process.
        require_once dirname(__DIR__, 3) . '/202-config/functions-upgrade.php';

        $this->assertTrue(
            function_exists('_upgrade_attribution_backfill_statements'),
            'the upgrade must backfill the columns it adds NOT NULL with no default'
        );

        return _upgrade_attribution_backfill_statements();
    }

    public function testReconcilingThePreReleaseShapeEmitsExactlyTheMissingPiecesInOrder(): void
    {
        // The columns and indexes a 1524b62-era install actually has. The
        // plan against them is what converges that install; if the
        // reconciler stops emitting any of these, that install goes back to
        // answering device postbacks with "Unknown column 'protocol'".
        $definition = AttributionPostbackTables::attributionPostbacks();
        $live = $this->preReleasePostbackColumns();
        $liveIndexes = ['PRIMARY', 'dedupe_hash', 'user_received', 'user_app', 'user_ad_network', 'transaction', 'signature_received'];

        $plan = SchemaReconciler::planChanges($definition, $live, $liveIndexes);

        $this->assertSame([
            'ALTER TABLE `202_attribution_postbacks` ADD COLUMN `protocol` varchar(24) NOT NULL AFTER `received_at`',
            // version went from NOT NULL to nullable because AdAttributionKit
            // postbacks carry no version and their INSERT omits the column;
            // left NOT NULL the INSERT dies with errno 1364 and the postback
            // — the only copy Apple sends — is dropped.
            'ALTER TABLE `202_attribution_postbacks` MODIFY COLUMN `version` varchar(8) DEFAULT NULL',
            'ALTER TABLE `202_attribution_postbacks` ADD COLUMN `conversion_type` varchar(16) DEFAULT NULL AFTER `postback_sequence_index`',
            'ALTER TABLE `202_attribution_postbacks` ADD COLUMN `ad_interaction_type` varchar(5) DEFAULT NULL AFTER `did_win`',
            'ALTER TABLE `202_attribution_postbacks` ADD COLUMN `marketplace_id` varchar(255) DEFAULT NULL AFTER `source_domain`',
            'ALTER TABLE `202_attribution_postbacks` ADD COLUMN `signature_state` varchar(16) NOT NULL AFTER `attribution_signature`',
            'ALTER TABLE `202_attribution_postbacks` ADD COLUMN `key_id` varchar(64) DEFAULT NULL AFTER `signature_valid`',
            'ALTER TABLE `202_attribution_postbacks` ADD KEY `user_protocol` (`user_id`,`protocol`)',
        ], $plan['statements']);
        $this->assertSame([], $plan['unreconciled']);
    }

    public function testReconciliationIsAdditiveAndIdempotent(): void
    {
        foreach (AttributionPostbackTables::getDefinitions() as $definition) {
            // A table that already matches its definition: nothing to do.
            $live = [];
            foreach (SchemaReconciler::requiredColumns($definition) as $name => $columnSql) {
                $live[$name] = [
                    'Field' => $name,
                    'Type' => $this->typeOf($columnSql),
                    'Null' => stripos($columnSql, 'NOT NULL') === false ? 'YES' : 'NO',
                ];
            }
            $indexes = array_keys(SchemaReconciler::requiredIndexes($definition));
            $indexes[] = 'PRIMARY';

            $plan = SchemaReconciler::planChanges($definition, $live, $indexes);
            $this->assertSame([], $plan['statements'], $definition->tableName . ' should need no changes');
            $this->assertSame([], $plan['unreconciled'], $definition->tableName);
        }
    }

    public function testReconciliationNeverEmitsADestructiveStatement(): void
    {
        // Reconciliation runs unattended inside an upgrade against a live
        // table. Whatever the live shape, it may only add and widen.
        foreach (AttributionPostbackTables::getDefinitions() as $definition) {
            foreach ([[], $this->preReleasePostbackColumns()] as $live) {
                $plan = SchemaReconciler::planChanges($definition, $live, []);
                foreach ($plan['statements'] as $statement) {
                    $this->assertMatchesRegularExpression(
                        '/^ALTER TABLE `[0-9a-z_]+` (ADD COLUMN|ADD UNIQUE KEY|ADD KEY|MODIFY COLUMN) /',
                        $statement
                    );
                    $this->assertDoesNotMatchRegularExpression(
                        '/\b(DROP|RENAME|TRUNCATE|DELETE|CHANGE)\b/i',
                        $statement
                    );
                }
            }
        }
    }

    public function testANarrowingDifferenceIsReportedRatherThanApplied(): void
    {
        // Definition says NOT NULL, live column is nullable: applying that
        // fails on any stored NULL, so it is reported for a human instead.
        $definition = SchemaBuilder::fromRawSql(
            '202_reconciler_probe',
            "CREATE TABLE IF NOT EXISTS `202_reconciler_probe` (
                `id` int(10) unsigned NOT NULL,
                `label` varchar(20) NOT NULL
            ) ENGINE=InnoDB"
        );
        $live = [
            'id' => ['Field' => 'id', 'Type' => 'int(10) unsigned', 'Null' => 'NO'],
            'label' => ['Field' => 'label', 'Type' => 'varchar(20)', 'Null' => 'YES'],
        ];

        $plan = SchemaReconciler::planChanges($definition, $live, []);

        $this->assertSame([], $plan['statements']);
        $this->assertCount(1, $plan['unreconciled']);
        $this->assertStringContainsString('202_reconciler_probe.label', $plan['unreconciled'][0]);
    }

    public function testATypeDifferenceWithMatchingNullabilityIsInvisibleAsDocumented(): void
    {
        // The class docblock used to say a drifted type is "reported through
        // getUnreconciled() for a human". It is not: planChanges() compares
        // names and nullability, and a column whose nullability already
        // agrees is skipped before any type is looked at. This pins what the
        // docblock now says, so the two cannot drift apart again — if this
        // starts reporting, the docblock has to change with it.
        $definition = SchemaBuilder::fromRawSql(
            '202_reconciler_probe',
            "CREATE TABLE IF NOT EXISTS `202_reconciler_probe` (
                `id` int(10) unsigned NOT NULL,
                `label` varchar(20) NOT NULL,
                `note` text NULL
            ) ENGINE=InnoDB"
        );
        $live = [
            'id' => ['Field' => 'id', 'Type' => 'int(10) unsigned', 'Null' => 'NO'],
            // Same nullability, different type, in both directions.
            'label' => ['Field' => 'label', 'Type' => 'varchar(5)', 'Null' => 'NO'],
            'note' => ['Field' => 'note', 'Type' => 'int(11)', 'Null' => 'YES'],
        ];

        $plan = SchemaReconciler::planChanges($definition, $live, []);

        $this->assertSame([], $plan['statements']);
        $this->assertSame([], $plan['unreconciled']);
    }

    public function testAnUnreadableTableFailsInsteadOfLookingEmpty(): void
    {
        // SHOW COLUMNS returning false must not read as "the table has no
        // columns", which would plan an ADD for every column in the
        // definition (error pattern #11). The runner here fails the way
        // _upgrade_query() does.
        $reconciler = new SchemaReconciler(static fn (string $sql) => false);

        $this->assertFalse($reconciler->reconcile(AttributionPostbackTables::attributionApps()));
        $this->assertSame([], $reconciler->getApplied());
        // Named probe, so that a columns probe which silently returns an
        // empty set (and is then caught only by the index probe behind it)
        // cannot pass this test.
        $this->assertSame(
            ['could not read the columns of 202_attribution_apps (SHOW COLUMNS failed)'],
            $reconciler->getErrors()
        );

        // The same for the existence and row-count probes: not-known is not
        // the same answer as no.
        $this->assertNull($reconciler->tableExists('202_attribution_apps'));
        $this->assertNull($reconciler->tableRowCount('202_attribution_apps'));
    }

    public function testAnUnparseableDefinitionFailsRatherThanReconcilingHalfOfIt(): void
    {
        $definition = SchemaBuilder::fromRawSql(
            '202_reconciler_probe',
            "CREATE TABLE IF NOT EXISTS `202_reconciler_probe` (
                `id` int(10) unsigned NOT NULL,
                CONSTRAINT `fk` FOREIGN KEY (`id`) REFERENCES `other` (`id`)
            ) ENGINE=InnoDB"
        );

        $this->expectException(\RuntimeException::class);
        SchemaReconciler::planChanges($definition, [], []);
    }

    /**
     * The 202_attribution_postbacks columns as a 1524b62-era install has
     * them, in the order that install has them.
     *
     * @return array<string, array<string, string>>
     */
    private function preReleasePostbackColumns(): array
    {
        $columns = [
            'postback_id' => ['bigint(20) unsigned', 'NO'],
            'user_id' => ['mediumint(8) unsigned', 'NO'],
            'received_at' => ['int(10) unsigned', 'NO'],
            'version' => ['varchar(8)', 'NO'],
            'ad_network_id' => ['varchar(100)', 'NO'],
            'transaction_id' => ['varchar(64)', 'NO'],
            'app_id' => ['bigint(20) unsigned', 'NO'],
            'source_identifier' => ['varchar(4)', 'YES'],
            'campaign_id' => ['bigint(20) unsigned', 'YES'],
            'conversion_value' => ['tinyint(3) unsigned', 'YES'],
            'coarse_conversion_value' => ['varchar(6)', 'YES'],
            'postback_sequence_index' => ['tinyint(3) unsigned', 'YES'],
            'redownload' => ['tinyint(1) unsigned', 'YES'],
            'did_win' => ['tinyint(1) unsigned', 'YES'],
            'source_app_id' => ['bigint(20) unsigned', 'YES'],
            'source_domain' => ['varchar(255)', 'YES'],
            'fidelity_type' => ['tinyint(3) unsigned', 'YES'],
            'country_code' => ['varchar(8)', 'YES'],
            'attribution_signature' => ['text', 'NO'],
            'signature_valid' => ['tinyint(1) unsigned', 'YES'],
            'dedupe_hash' => ['char(40)', 'NO'],
            'raw_payload' => ['text', 'NO'],
            'remote_ip' => ['varchar(45)', 'NO'],
            'created_at' => ['int(10) unsigned', 'NO'],
        ];

        $rows = [];
        foreach ($columns as $name => [$type, $null]) {
            $rows[$name] = ['Field' => $name, 'Type' => $type, 'Null' => $null];
        }

        return $rows;
    }

    /**
     * The type SHOW COLUMNS would report for a column definition.
     */
    private function typeOf(string $columnSql): string
    {
        $afterName = (string)preg_replace('/^`[^`]+`\s*/', '', $columnSql, 1);
        preg_match('/^([a-z]+(?:\s*\([^)]*\))?(?:\s+unsigned)?)/i', $afterName, $match);

        return $match[1] ?? '';
    }

    public function testDowngradeGuardMatchesTheCurrentVersion(): void
    {
        $source = $this->upgradeSource();
        $this->assertStringContainsString(
            "version_compare((string) \$prosper202_version, '" . self::CURRENT_VERSION . "', '>')",
            $source
        );
    }

    public function testTheConvergenceHackIsGone(): void
    {
        // The version bump is the proper fix for installs already at the
        // prior version; the version-independent ensure_schema_current()
        // workaround must not linger beside it (it would create the SKAN
        // tables outside the version ladder, the exact "weird flag" the
        // bump exists to avoid).
        $this->assertStringNotContainsString('ensure_schema_current', $this->upgradeSource());
        $upgradePage = (string)file_get_contents(dirname(__DIR__, 3) . '/202-config/upgrade.php');
        $this->assertStringNotContainsString('ensure_schema_current', $upgradePage);
    }
}
