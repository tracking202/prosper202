<?php

declare(strict_types=1);

namespace Prosper202\Report;

/**
 * The one decision about who may see campaign figures, and the one list of
 * which figures those are.
 *
 * A user without the access_to_campaign_data permission sees the absolute
 * click, lead and money columns as '?' on every report surface: the HTML
 * report tables and their excel downloads (class-dataengine.php), the JSON
 * report payloads (FlatReportPayloadBuilder), the rotator screen
 * (sort_rotator.php) and the summary-form date column (ReportSummaryForm).
 * Ratios -- CTR, ROI, EPC, CPC, S/U, payout -- stay visible.
 *
 * Before this class each of those surfaces restated both the predicate and
 * the list by hand, and they drifted: one totals row masked 'net' while
 * printing 'total_net'; the variable report masked its rows but not its
 * totals; the rotator screen used a shorter list and skipped the publisher
 * exemption; the summary form read $_SESSION['publisher'] without isset().
 * CampaignDataMaskTest asserts the predicate is not restated anywhere and
 * that no file masks a metric key by hand.
 */
final class CampaignDataMask
{
    /** Row keys hidden from a restricted viewer. Prefixed per surface (total_, rotator_, rule_, ...). */
    public const array METRICS = ['clicks', 'click_out', 'leads', 'income', 'cost', 'net'];

    /**
     * The parenthesised display form of cost that the HTML templates print
     * instead of the raw value. Masked alongside the metrics because a template
     * reads it, not 'cost'.
     */
    public const string COST_WRAPPER = 'cost_wrapper';

    /**
     * True when campaign figures must be hidden from the current viewer.
     *
     * Publishers are exempt: a publisher session is already scoped to its own
     * data by the query layer, and $_SESSION['publisher'] is what marks it.
     * $userObj is the global set by 202-config/connect.php; when there is no
     * authenticated user there is nothing to restrict.
     */
    public static function hidden(): bool
    {
        global $userObj;

        return (bool) (
            $userObj
            && !$userObj->hasPermission('access_to_campaign_data')
            && empty($_SESSION['publisher'])
        );
    }

    /**
     * Replace the sensitive metrics in one report row with '?'.
     *
     * Only keys already present are touched, so this never invents a column a
     * template does not expect. Callers build `{$prefix}cost_wrapper` BEFORE
     * applying the mask; it is masked here like any other key.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function apply(array $row, string $prefix = ''): array
    {
        foreach ([...self::METRICS, self::COST_WRAPPER] as $key) {
            $key = $prefix . $key;
            if (array_key_exists($key, $row)) {
                $row[$key] = '?';
            }
        }

        return $row;
    }

    /**
     * apply() over a nested report structure, for every array node and every
     * prefix given. The variable report nests network -> variable -> value rows
     * and carries its totals on the last node under total_* keys; masking only
     * the per-row keys there left the "Totals for report" line unmasked.
     *
     * @param array<mixed> $data
     * @param list<string> $prefixes
     * @return array<mixed>
     */
    public static function applyDeep(array $data, array $prefixes = ['', 'total_']): array
    {
        foreach ($data as $key => $item) {
            if (is_array($item)) {
                $data[$key] = self::applyDeep($item, $prefixes);
            }
        }
        foreach ($prefixes as $prefix) {
            $data = self::apply($data, $prefix);
        }

        return $data;
    }
}
