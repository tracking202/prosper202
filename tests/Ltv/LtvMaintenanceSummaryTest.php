<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;
use Prosper202\Ltv\LtvMaintenance;

/**
 * The line both entry points print, and when index.php prints one.
 */
final class LtvMaintenanceSummaryTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function report(array $with = []): array
    {
        return $with + [
            'jobs' => LtvMaintenance::JOBS,
            'past_due' => 0,
            'canceled' => 0,
            'swept_customers' => 0,
            'reconciled' => 0,
            'checked' => 0,
            'full' => false,
            'transitions' => 0,
            'recommendation_conversions' => 0,
            'companies_linked' => 0,
            'company_strings' => 0,
            'tokens_purged' => 0,
            'more' => [],
            'failed' => [],
        ];
    }

    public function testTheLineNamesOnlyTheJobsThatRan(): void
    {
        self::assertSame(
            'subscriptions 3 to past_due, 2 to canceled, 4 customer rollups refreshed',
            LtvMaintenance::summary(self::report([
                'jobs' => [LtvMaintenance::SWEEP], 'past_due' => 3, 'canceled' => 2, 'swept_customers' => 4,
            ]))
        );
        self::assertSame(
            'rollups 1 of 9 customers corrected (active in 48 hours); offer transitions 0 pairs, 0 recommendation'
                . ' conversions stamped; companies 0 customers attached, 0 names converged; personalization 0'
                . ' expired tokens removed; out of time, next run: companies, purge; FAILED: transitions',
            LtvMaintenance::summary(self::report([
                'jobs' => LtvMaintenance::DAILY_JOBS, 'reconciled' => 1, 'checked' => 9,
                'more' => [LtvMaintenance::COMPANIES, LtvMaintenance::PURGE],
                'failed' => [LtvMaintenance::TRANSITIONS => 'Table doesn\'t exist'],
            ]))
        );
    }

    public function testARunThatChangedNothingAndLeftNothingHasNothingToSay(): void
    {
        self::assertFalse(LtvMaintenance::didWork(self::report(['checked' => 40])));
        self::assertTrue(LtvMaintenance::didWork(self::report(['canceled' => 1])));
        self::assertTrue(LtvMaintenance::didWork(self::report(['more' => [LtvMaintenance::SWEEP]])));
        self::assertTrue(LtvMaintenance::didWork(self::report(['failed' => [LtvMaintenance::SWEEP => 'x']])));
    }
}
