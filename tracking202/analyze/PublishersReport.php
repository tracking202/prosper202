<?php

declare(strict_types=1);

namespace Tracking202\Analyze;

use Api\V3\Controllers\ReportsController;

/**
 * Analyze › Publishers' figures, for the page and its download: GET
 * /reports/groups?by=publisher,publisher_link,campaign from the API's own
 * controller, as one row per publisher, link and campaign.
 */
final class PublishersReport
{
    /** The download's columns after Publisher, Link and Campaign: heading => the row's key. */
    public const METRICS = [
        'Clicks' => 'total_clicks',
        'Click Throughs' => 'total_click_throughs',
        'Leads' => 'total_leads',
        'Conv Rate' => 'conv_rate',
        'Income' => 'total_income',
        'Cost' => 'total_cost',
        'Net' => 'total_net',
        'ROI' => 'roi',
    ];

    private function __construct()
    {
    }

    /**
     * @return array{publishers: list<array<string, mixed>>, uncredited: ?array<string, mixed>,
     *   totals: array<string, mixed>, unrecorded_links: bool, currency: string,
     *   money: array{currency: string, others: string}, spend_ledger: ?array<string, mixed>}
     */
    public static function read(\mysqli $db, int $accountId, int $from, int $to): array
    {
        $reports = new ReportsController($db, $accountId);
        $groups = $reports->groups([
            'by' => 'publisher,publisher_link,campaign',
            'time_from' => (string) $from,
            'time_to' => (string) $to,
        ]);

        // The figures add each click's value as it stands: the money of the
        // period in other currencies is said with them (RevenueCurrencies).
        return self::shape($groups) + [
            'currency' => $reports->accountCurrency(),
            'money' => \Prosper202\Conversion\Ledger\RevenueCurrencies::forExport($db, $accountId, $from, $to),
            'spend_ledger' => $groups['spend_ledger'] ?? null,
        ];
    }

    /**
     * The groups answer as the page lists it: each publisher with a row per
     * link and campaign (a link sends to one campaign unless it was edited),
     * and the clicks credited to no publisher apart.
     *
     * @param array<string, mixed> $groups
     * @return array{publishers: list<array<string, mixed>>, uncredited: ?array<string, mixed>,
     *   totals: array<string, mixed>, unrecorded_links: bool}
     */
    public static function shape(array $groups): array
    {
        $publishers = [];
        $uncredited = null;
        $unrecorded = false;
        foreach ($groups['data'] as $group) {
            if ($group['id'] === null) {
                $uncredited = $group;
                continue;
            }
            $rows = [];
            foreach ($group['children'] as $link) {
                $unrecorded = $unrecorded || $link['id'] === null;
                foreach ($link['children'] as $campaign) {
                    $rows[] = [
                        'link' => $link['id'] === null ? null : (string) $link['id'],
                        'campaign' => $campaign['id'] === null ? null : (string) $campaign['name'],
                    ] + $campaign;
                }
            }
            $publishers[] = ['rows' => $rows] + $group;
        }

        return [
            'publishers' => $publishers,
            'uncredited' => $uncredited,
            'totals' => $groups['totals'],
            'unrecorded_links' => $unrecorded,
        ];
    }

    /**
     * The download: tab-separated lines, a heading first, as the other
     * Analyze downloads are written.
     *
     * Every row ends with the account's currency and the money of the
     * period in others, as every report download does.
     *
     * @param array{publishers: list<array<string, mixed>>, uncredited: ?array<string, mixed>,
     *   money?: array{currency: string, others: string}} $report
     * @return list<string>
     */
    public static function exportLines(array $report): array
    {
        $money = $report['money'] ?? ['currency' => '', 'others' => ''];
        $lines = [implode("\t", ['Publisher', 'Link', 'Campaign', ...array_keys(self::METRICS), 'Currency', 'Other currencies in period'])];
        $line = static function (string $publisher, string $link, string $campaign, array $row) use ($money): string {
            $cells = [self::cell($publisher), self::cell($link), self::cell($campaign)];
            foreach (self::METRICS as $key) {
                $cells[] = in_array($key, ['total_clicks', 'total_click_throughs', 'total_leads'], true)
                    ? (string) (int) $row[$key]
                    : number_format((float) $row[$key], 2, '.', '');
            }
            $cells[] = self::cell($money['currency']);
            $cells[] = self::cell($money['others']);

            return implode("\t", $cells);
        };
        foreach ($report['publishers'] as $publisher) {
            foreach ($publisher['rows'] as $row) {
                $lines[] = $line(
                    (string) $publisher['name'],
                    $row['link'] ?? 'Link not recorded',
                    $row['campaign'] ?? 'No campaign',
                    $row
                );
            }
        }
        if ($report['uncredited'] !== null) {
            $lines[] = $line('No publisher', '', '', $report['uncredited']);
        }

        return $lines;
    }

    /** A text cell: one line, no tab, and never read by a spreadsheet as a formula. */
    private static function cell(string $text): string
    {
        $text = trim((string) preg_replace('/[\t\r\n]+/', ' ', $text));
        if ($text !== '' && str_contains('=+-@', $text[0])) {
            $text = "'" . $text;
        }

        return $text;
    }
}
