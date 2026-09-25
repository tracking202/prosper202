<?php

declare(strict_types=1);

namespace Tests\Apps\Android;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\Ledger\Amount;
use Prosper202\Goals\GoalEngine;
use Prosper202\Goals\Outcome;

/**
 * What the built-in install goal's outcome pays (plan §5.4): the campaign
 * decides, and an install with no click, or an outcome that is not
 * eligible, is never payable whatever the campaign says. Pure, so every
 * branch is pinned here; the integration test drives the reachable ones
 * through the intake.
 */
final class InstallPayabilityTest extends TestCase
{
    private static function outcome(?string $ineligible = null): Outcome
    {
        return new Outcome(1, 1, 1, '@install', 1_700_000_000, null, Outcome::SOURCE_NONE, null, $ineligible);
    }

    public function testAnIneligibleOutcomeIsNeverPayable(): void
    {
        $term = ['payout' => '4.00'];
        self::assertSame([false, null, 'install', null], GoalEngine::installPayability(self::outcome('no_click'), 30, [7 => $term], $term, '2.50'));
        self::assertSame([false, null, 'install', null], GoalEngine::installPayability(self::outcome('no_click'), 30, [], null, '2.50'));
    }

    public function testAnInstallWithNoCampaignIsCountedNotPaid(): void
    {
        self::assertSame([false, null, 'install', null], GoalEngine::installPayability(self::outcome(), null, [], null, '2.50'));
    }

    public function testTheCampaignDecides(): void
    {
        $units = static fn (string $v): int => Amount::toUnits($v);
        // The install goal attached with its own payout.
        self::assertSame([true, $units('4.00'), 'payout', null], GoalEngine::installPayability(self::outcome(), 30, [7 => ['payout' => '4.00']], ['payout' => '4.00'], '2.50'));
        // Attached with no payout: the campaign's default.
        self::assertSame([true, $units('2.50'), 'campaign_default', null], GoalEngine::installPayability(self::outcome(), 30, [7 => ['payout' => null]], ['payout' => null], '2.50'));
        // A campaign that lists no goals pays on install.
        self::assertSame([true, $units('2.50'), 'campaign_default', null], GoalEngine::installPayability(self::outcome(), 30, [], null, '2.50'));
        // A campaign that lists other goals and not this one does not.
        self::assertSame([false, $units('2.50'), 'campaign_default', 'not_payable_on_campaign'], GoalEngine::installPayability(self::outcome(), 30, [8 => ['payout' => '1.00']], null, '2.50'));
    }
}
