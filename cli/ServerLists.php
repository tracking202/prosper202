<?php

declare(strict_types=1);

namespace P202Cli;

use Api\V3\Controllers\AppNotificationsController;
use Api\V3\Controllers\ReportsController;
use Api\V3\Support\TimeBound;
use Prosper202\Conversion\Ledger\ConversionSource;

/**
 * The value lists this CLI's help and offline checks name — periods,
 * breakdown dimensions, sorts, statuses — taken from the server code that
 * accepts them, so the help cannot drift from the server it ships with.
 *
 * It did drift: report:breakdown's --sort listed 8 sorts while the server
 * took 11, ltv:predict's --by left out product, and the period and
 * dimension lists had each been brought up to date by hand, one commit at a
 * time. Where the server exposes its list (TimeBound::PERIODS,
 * ReportsController::breakdownDimensions(), ConversionSource,
 * AppNotificationsController::STATUSES and KINDS, AppReportController's
 * groupings) it is read from there; none of these needs a database. Where
 * the server keeps it private, it is written below and
 * tests/Cli/ServerListsTest pins it to the server's own answer — the list a
 * 422 names, or the constant itself — so a change on either side fails a
 * test instead of a user.
 *
 * This is the legacy PHP CLI (bin/p202): the lists describe the server code
 * in this checkout. The server's 422 for a value it does not take is the
 * authority, and names what it does take.
 */
final class ServerLists
{
    /** GET /reports/breakdown's sort: every metric a row carries. */
    public const BREAKDOWN_SORTS = [
        'total_clicks', 'total_click_throughs', 'total_leads', 'total_income', 'total_cost', 'total_net',
        'epc', 'avg_cpc', 'conv_rate', 'roi', 'cpa',
    ];

    /** GET /reports/daypart's sort. */
    public const DAYPART_SORTS = ['hour_of_day', ...self::BREAKDOWN_SORTS];

    /** GET /reports/weekpart's sort. */
    public const WEEKPART_SORTS = ['day_of_week', ...self::BREAKDOWN_SORTS];

    /** GET /reports/timeseries's interval. */
    public const TIMESERIES_INTERVALS = ['hour', 'day', 'week', 'month'];

    /**
     * GET /ltv/breakdown's and GET /ltv/predict's by: the acquisition
     * dimensions, and product (predict projects per row of the same
     * breakdown; ltv:predict's help left product out).
     */
    public const LTV_BREAKDOWNS = ['campaign', 'ppc_account', 'landing_page', 'product'];

    /** GET /ltv/customers's sort. */
    public const LTV_CUSTOMER_SORTS = ['total_revenue', 'order_count', 'last_activity_time', 'first_seen_time', 'mrr'];

    /** A subscription's status. */
    public const LTV_SUBSCRIPTION_STATUSES = ['trialing', 'active', 'past_due', 'paused', 'canceled'];

    private function __construct()
    {
    }

    /** The named windows every report, LTV and attribution read takes. */
    public static function periods(): array
    {
        return TimeBound::PERIODS;
    }

    /** GET /reports/breakdown's dimensions, in the server's order. */
    public static function breakdownDimensions(): array
    {
        return ReportsController::breakdownDimensions();
    }

    /** What produced a conversion (GET /conversions?source=). */
    public static function conversionSources(): array
    {
        return array_map(static fn (ConversionSource $s): string => $s->value, ConversionSource::cases());
    }

    /** An app notification's status (GET /apps/notifications?status=). */
    public static function appNotificationStatuses(): array
    {
        return AppNotificationsController::STATUSES;
    }

    /** "a, b, c" for help text. */
    public static function list(array $values): string
    {
        return implode(', ', $values);
    }
}
