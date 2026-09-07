<?php

declare(strict_types=1);

namespace Tests\Skan;

use Tests\TestCase;

/**
 * The SKAN tables ship inside version 1.9.75, which master's databases
 * already carry — so no version-gated upgrade block can ever create them
 * for installs upgraded from source. Convergence therefore hangs on
 * UPGRADE::ensure_schema_current() being wired into every upgrade surface,
 * including the "Already Upgraded" bounce an equal-version install hits.
 * This pins that wiring textually so a refactor cannot silently strand
 * those installs again (the failure is invisible to CI, which always
 * installs fresh).
 */
final class SkanSchemaConvergenceTest extends TestCase
{
    public function testEnsureSchemaCurrentBuildsFromTheInstallerDefinitions(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/202-config/functions-upgrade.php');

        $this->assertStringContainsString('function ensure_schema_current', $source);

        // The DDL must come from the same definitions the fresh installer
        // uses, inside ensure_schema_current — never a hand-copied CREATE.
        $fnStart = strpos($source, 'function ensure_schema_current');
        $this->assertNotFalse($fnStart);
        $fnBody = substr($source, $fnStart, 1500);
        $this->assertStringContainsString('SkanTables::getDefinitions()', $fnBody);
    }

    public function testUpgradeDatabasesRunsTheConvergenceUnconditionally(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/202-config/functions-upgrade.php');
        $fnStart = strpos($source, 'function upgrade_databases');
        $this->assertNotFalse($fnStart);

        // The call must appear before the first version-gated block, so it
        // runs regardless of what the version ladder decides.
        $firstGate = strpos($source, "prosper202_version == '", $fnStart);
        $ensureCall = strpos($source, 'self::ensure_schema_current()', $fnStart);
        $this->assertNotFalse($firstGate);
        $this->assertNotFalse($ensureCall);
        $this->assertLessThan($firstGate, $ensureCall,
            'upgrade_databases() must call ensure_schema_current() before the version ladder');
    }

    public function testTheUpgradePageConvergesBeforeTheAlreadyUpgradedBounce(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/202-config/upgrade.php');

        $ensureCall = strpos($source, 'UPGRADE::ensure_schema_current()');
        $bounce = strpos($source, 'upgrade_needed()');
        $this->assertNotFalse($ensureCall, 'upgrade.php must call ensure_schema_current()');
        $this->assertNotFalse($bounce);
        $this->assertLessThan($bounce, $ensureCall,
            'The convergence call must run before the "Already Upgraded" redirect, '
            . 'or equal-version installs never execute it');
    }
}
