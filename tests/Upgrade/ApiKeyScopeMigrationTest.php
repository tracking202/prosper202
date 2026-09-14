<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use Tests\TestCase;

/**
 * `202_api_keys.scope` was added by two separate places in the upgrade ladder.
 *
 * One was an ALTER inserted into the block that ends at 1.9.60 — a step that
 * had already shipped. Upgrade blocks are gated `if ($prosper202_version ==
 * 'X')`, so that ALTER could only ever reach installs still walking up from
 * below 1.9.60, while reading in the source as though it covered everyone.
 * The other was a guarded repeat in the 1.9.74 step, carrying a comment
 * arguing it was not redundant because the first one might have failed — a
 * repair for a population that does not exist, which existed only because the
 * first site was there at all.
 *
 * The column is now added in exactly one place. These tests pin that, because
 * the failure mode is invisible by inspection: a second ALTER for the same
 * column reads as harmless defensive duplication, and the SHOW COLUMNS guard
 * in front of each one makes both look correct in isolation.
 *
 * Textual rather than behavioural on purpose. The behaviour was verified
 * against a real MariaDB across every population (fresh install, below
 * 1.9.60, 1.9.61, 1.9.70, 1.9.74, a re-run with the column already present,
 * a re-run at the current version, and a denied ALTER followed by a retry),
 * but none of that can run in CI, which has no database and always installs
 * fresh. What CI can hold is the shape: one site, in the right block, gating
 * its version bump on the column existing.
 */
final class ApiKeyScopeMigrationTest extends TestCase
{
    /** The step that owns the column: it is the release that introduced scopes. */
    private const OWNING_GATE = '1.9.74';
    private const OWNING_BUMP = '1.9.75';

    private const ALTER = "ALTER TABLE `202_api_keys` ADD COLUMN `scope` text DEFAULT NULL AFTER `api_key`";

    private function upgradeSource(): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/202-config/functions-upgrade.php');
    }

    /**
     * The whole point. A second site cannot be reached by any install the
     * first does not already cover, so it is dead code that reads as a
     * safety net.
     */
    public function testTheScopeColumnIsAddedInExactlyOnePlace(): void
    {
        $occurrences = substr_count($this->upgradeSource(), self::ALTER);

        $this->assertSame(
            1,
            $occurrences,
            'The 202_api_keys.scope ALTER must appear exactly once in the upgrade ladder. '
            . 'Found ' . $occurrences . '. A second one is unreachable for every install the '
            . 'first already covers; if a population is genuinely missed, fix the step that '
            . 'owns the column rather than adding a repair beside it.'
        );
    }

    public function testTheScopeColumnBelongsToTheStepThatIntroducedScopes(): void
    {
        $source = $this->upgradeSource();

        $gate = strpos($source, "if (\$prosper202_version == '" . self::OWNING_GATE . "')");
        $this->assertNotFalse($gate, 'there must be an upgrade block gated on ' . self::OWNING_GATE);

        // Bounded by the next version gate, not a byte count: a fixed window
        // slides off the end as the block grows, or spills into the next
        // block and matches ITS statements.
        $nextGate = strpos($source, "if (\$prosper202_version == '", $gate + 1);
        $block = $nextGate === false ? substr($source, $gate) : substr($source, $gate, $nextGate - $gate);

        $this->assertStringContainsString(
            self::ALTER,
            $block,
            'the scope ALTER must live in the ' . self::OWNING_GATE . ' block'
        );
    }

    /**
     * Re-running an upgrade has to stay safe, so the ALTER is guarded by an
     * existence check. That guard is what makes the step idempotent — it is
     * not, and must not become, a repair path for an earlier step.
     */
    public function testTheAlterIsGuardedByAnExistenceCheck(): void
    {
        $block = $this->owningBlock();

        $this->assertStringContainsString("SHOW COLUMNS FROM `202_api_keys` LIKE 'scope'", $block);

        $checkPos = strpos($block, 'SHOW COLUMNS');
        $alterPos = strpos($block, self::ALTER);
        $this->assertNotFalse($checkPos);
        $this->assertNotFalse($alterPos);
        $this->assertLessThan(
            $alterPos,
            $checkPos,
            'the existence check must come before the ALTER, or re-running the upgrade errors'
        );
    }

    /**
     * The ordinary contract for a migration step, and the reason no repair
     * path is needed: a step that could not apply its change must not record
     * the version that change defines. Leaving the version where it was is
     * what makes the next run retry.
     */
    public function testTheVersionBumpIsGatedOnTheColumnExisting(): void
    {
        $block = $this->owningBlock();

        $bump = strpos($block, "UPDATE 202_version SET version='" . self::OWNING_BUMP . "'");
        $this->assertNotFalse($bump, 'the block must persist ' . self::OWNING_BUMP);

        // The conditional itself, not merely a mention of the variable.
        // Asserting only that '$scope_ok' appears somewhere before the bump
        // passes against `if (true)` with the assignment left in place, which
        // is precisely the regression this is here to catch.
        $guard = strpos($block, 'if ($scope_ok) {');
        $this->assertNotFalse(
            $guard,
            'the version bump must be wrapped in `if ($scope_ok) {`. If the variable was '
            . 'renamed, update this test; if the guard was removed, the step can record '
            . '1.9.75 on an install that has no scope column.'
        );
        $this->assertLessThan($bump, $guard, 'the version bump must sit behind that guard');

        // A failed ALTER has to be audible, and the two failure paths are not
        // interchangeable: one means the column was never added, the other
        // means it was added but the version did not stick. Asserting only
        // that the word error_log appears passes with either one deleted.
        $this->assertStringContainsString(
            'failed to add 202_api_keys scope column',
            $block,
            'a failed ALTER must say so; silence here is CLAUDE.md error pattern #1'
        );
        $this->assertStringContainsString(
            'failed to persist version ' . self::OWNING_BUMP,
            $block,
            'an ALTER that applied but whose version write failed must say so separately'
        );
    }

    /**
     * The step it was wrongly attached to must be back to what shipped as
     * 1.9.60. Blocks below the owning gate are already-released migrations;
     * editing one changes what a past release did for anyone still walking
     * through it.
     */
    public function testNoEarlierBlockTouchesTheScopeColumn(): void
    {
        $source = $this->upgradeSource();

        $owning = strpos($source, "if (\$prosper202_version == '" . self::OWNING_GATE . "')");
        $this->assertNotFalse($owning);

        $before = substr($source, 0, $owning);

        $this->assertStringNotContainsString(
            "LIKE 'scope'",
            $before,
            'an earlier upgrade block probes for the scope column. Steps below the owning '
            . 'gate are shipped migrations; adding the column there reaches only installs '
            . 'that have not passed that version yet, while looking like it covers everyone.'
        );
    }

    private function owningBlock(): string
    {
        $source = $this->upgradeSource();
        $gate = strpos($source, "if (\$prosper202_version == '" . self::OWNING_GATE . "')");
        $this->assertNotFalse($gate);
        $nextGate = strpos($source, "if (\$prosper202_version == '", $gate + 1);

        return $nextGate === false ? substr($source, $gate) : substr($source, $gate, $nextGate - $gate);
    }
}
