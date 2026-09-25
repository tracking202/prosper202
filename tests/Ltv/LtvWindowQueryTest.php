<?php

declare(strict_types=1);

namespace Tests\Ltv;

use Tests\TestCase;

/**
 * The window an LTV view's tabs carry (tracking202/ajax/ltv_ui.php).
 *
 * The tabs are real links, so a middle-click or a copied link opens ltv.php
 * with exactly the query they hold; without the window it opened with none,
 * and ltv.php then showed whatever window was stored by then. The hrefs
 * themselves are read in the browser (analyze-ltv.spec.js); this pins what
 * p202_ltv_window_query() makes of each shape grab_timeframe() answers.
 */
final class LtvWindowQueryTest extends TestCase
{
    private string $timezone;

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/tracking202/ajax/ltv_ui.php';
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('America/New_York');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
        parent::tearDown();
    }

    public function testAPresetTravelsAsItsName(): void
    {
        self::assertSame(['range' => 'last7'], p202_ltv_window_query(['user_pref_time_predefined' => 'last7', 'from' => 1, 'to' => 2]),
            'a preset is resolved when the link is opened, not frozen to today\'s dates');
    }

    public function testACustomWindowTravelsWithItsTwoDaysInTheAccountsTimezone(): void
    {
        $from = mktime(0, 0, 0, 8, 1, 2026);
        $to = mktime(23, 59, 59, 8, 31, 2026);
        self::assertSame(['range' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-31'],
            p202_ltv_window_query(['user_pref_time_predefined' => '', 'from' => $from, 'to' => $to]));
    }

    public function testNoWindowToSayIsNoWindowRatherThanAnInventedOne(): void
    {
        self::assertSame([], p202_ltv_window_query(['user_pref_time_predefined' => '', 'from' => 0, 'to' => 0]));
        self::assertSame([], p202_ltv_window_query(['user_pref_time_predefined' => 'last900']), 'an unknown preset with no dates');
    }
}
