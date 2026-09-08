<?php

declare(strict_types=1);

namespace Tests\Skan;

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
final class SkanUpgradeStepTest extends TestCase
{
    private const CURRENT_VERSION = '1.9.76';
    private const PRIOR_VERSION = '1.9.75';

    private function upgradeSource(): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/202-config/functions-upgrade.php');
    }

    public function testVersionConstantIsTheBumpedVersion(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/202-config/version.php');
        $this->assertStringContainsString("\$version_string = '" . self::CURRENT_VERSION . "'", $source);
    }

    public function testAnUpgradeStepGatedOnThePriorVersionCreatesTheSkanTables(): void
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
        $this->assertStringContainsString('SkanTables::getDefinitions()', $block);
        $this->assertStringContainsString("version='" . self::CURRENT_VERSION . "'", $block);
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
        $upgradePage = (string)file_get_contents(dirname(__DIR__, 2) . '/202-config/upgrade.php');
        $this->assertStringNotContainsString('ensure_schema_current', $upgradePage);
    }
}
