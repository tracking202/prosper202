<?php

declare(strict_types=1);

namespace Tracking202\Report\Json;

use UserPrefs;

final class FlatReportPayloadBuilder
{
    /**
     * @var array<string, array{featureLabel: string, downloadPath: string}>
     */
    private const REPORTS = [
        'keyword' => [
            'featureLabel' => 'Keyword',
            'downloadPath' => 'tracking202/analyze/keywords_download.php',
        ],
        'textad' => [
            'featureLabel' => 'Text ad',
            'downloadPath' => 'tracking202/analyze/text_ads_download.php',
        ],
        'referer' => [
            'featureLabel' => 'Referer',
            'downloadPath' => 'tracking202/analyze/referers_download.php',
        ],
        'ip' => [
            'featureLabel' => 'IP',
            'downloadPath' => 'tracking202/analyze/ips_download.php',
        ],
        'country' => [
            'featureLabel' => 'Country',
            'downloadPath' => 'tracking202/analyze/countries_download.php',
        ],
        'region' => [
            'featureLabel' => 'Region',
            'downloadPath' => 'tracking202/analyze/regions_download.php',
        ],
        'city' => [
            'featureLabel' => 'City',
            'downloadPath' => 'tracking202/analyze/cities_download.php',
        ],
        'isp' => [
            'featureLabel' => 'ISP/Carrier',
            'downloadPath' => 'tracking202/analyze/isps_download.php',
        ],
        'landingpage' => [
            'featureLabel' => 'Landing Page',
            'downloadPath' => 'tracking202/analyze/landing_pages_download.php',
        ],
        'device' => [
            'featureLabel' => 'Device',
            'downloadPath' => 'tracking202/analyze/device_download.php',
        ],
        'browser' => [
            'featureLabel' => 'Browser',
            'downloadPath' => 'tracking202/analyze/browser_download.php',
        ],
        'platform' => [
            'featureLabel' => 'Platform',
            'downloadPath' => 'tracking202/analyze/platform_download.php',
        ],
    ];

    /**
     * @var list<array{id: string, label: string, type: string, colspan?: int}>
     */
    private const COLUMNS = [
        ['id' => 'feature', 'label' => 'Feature', 'type' => 'feature', 'colspan' => 4],
        ['id' => 'clicks', 'label' => 'Clicks', 'type' => 'metric'],
        ['id' => 'clickOut', 'label' => 'Click Throughs', 'type' => 'metric'],
        ['id' => 'ctr', 'label' => 'CTR', 'type' => 'metric'],
        ['id' => 'leads', 'label' => 'Leads', 'type' => 'metric'],
        ['id' => 'avgSu', 'label' => 'Avg S/U', 'type' => 'metric'],
        ['id' => 'avgPayout', 'label' => 'Avg Payout', 'type' => 'metric'],
        ['id' => 'avgEpc', 'label' => 'Avg EPC', 'type' => 'metric'],
        ['id' => 'avgCpc', 'label' => 'Avg CPC', 'type' => 'metric'],
        ['id' => 'income', 'label' => 'Income', 'type' => 'metric'],
        ['id' => 'cost', 'label' => 'Cost', 'type' => 'metric'],
        ['id' => 'net', 'label' => 'Net', 'type' => 'metric'],
        ['id' => 'roi', 'label' => 'ROI', 'type' => 'metric'],
    ];

    /**
     * @param array<int, array<string, mixed>> $reportData
     * @param array<string, mixed> $userPreferences
     * @return array<string, mixed>
     */
    public static function build(
        string $reportType,
        array $reportData,
        int $foundRows,
        ReportDispatchRequest $request,
        array $userPreferences = []
    ): array {
        $definition = self::REPORTS[$reportType] ?? null;
        if ($definition === null) {
            throw new \InvalidArgumentException('Unsupported flat report type "' . $reportType . '".');
        }

        $totalsRow = null;
        if ($reportData !== []) {
            $totalsRow = array_pop($reportData);
        }

        $campaignDataRestricted = self::campaignDataRestricted();

        return [
            'family' => 'flat',
            'type' => $reportType,
            'featureLabel' => $definition['featureLabel'],
            'download' => [
                'label' => 'Download to excel',
                'url' => get_absolute_url() . $definition['downloadPath'],
            ],
            'columns' => self::columnsFor($definition['featureLabel']),
            'rows' => self::buildRows($reportType, $reportData, $campaignDataRestricted),
            'totals' => self::buildTotals($totalsRow, $campaignDataRestricted),
            'pagination' => self::buildPagination($foundRows, $request->offset, $request->order),
            'access' => [
                'publisher' => !empty($_SESSION['publisher']),
                'campaignDataRestricted' => $campaignDataRestricted,
            ],
            'dependentFilters' => DependentFilterPayloadBuilder::build($request, $userPreferences),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $reportData
     * @return list<array<string, mixed>>
     */
    private static function buildRows(string $reportType, array $reportData, bool $campaignDataRestricted): array
    {
        $rows = [];

        foreach ($reportData as $row) {
            $rows[] = [
                'kind' => 'row',
                'feature' => self::buildFeaturePayload($reportType, $row),
                'metrics' => self::buildMetricPayload($row, $campaignDataRestricted, false),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>
     */
    private static function buildTotals(?array $row, bool $campaignDataRestricted): array
    {
        if ($row === null) {
            return [
                'kind' => 'totals',
                'feature' => [
                    'text' => 'Totals for report',
                    'title' => 'Totals for report',
                    'variant' => 'totals',
                ],
                'metrics' => self::buildMetricPayload([], $campaignDataRestricted, true),
            ];
        }

        return [
            'kind' => 'totals',
            'feature' => [
                'text' => 'Totals for report',
                'title' => 'Totals for report',
                'variant' => 'totals',
            ],
            'metrics' => self::buildMetricPayload($row, $campaignDataRestricted, true),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function columnsFor(string $featureLabel): array
    {
        $columns = self::COLUMNS;
        $columns[0]['label'] = $featureLabel;

        return $columns;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function buildFeaturePayload(string $reportType, array $row): array
    {
        switch ($reportType) {
            case 'keyword':
                $keyword = (string) ($row['keyword'] ?? '[no keyword]');

                return [
                    'text' => $keyword,
                    'title' => $keyword,
                    'variant' => 'truncated_text',
                    'maxWidthPx' => 250,
                ];
            case 'textad':
                return [
                    'text' => (string) ($row['text_ad_name'] ?? 'Unknown'),
                    'title' => (string) ($row['text_ad_name'] ?? 'Unknown'),
                    'variant' => 'plain_text',
                ];
            case 'referer':
                $referer = (string) ($row['referer_name'] ?? 'Unknown');

                return [
                    'text' => $referer,
                    'title' => $referer,
                    'variant' => 'truncated_text',
                    'maxWidthPx' => 250,
                ];
            case 'ip':
                return [
                    'text' => (string) ($row['ip_address'] ?? 'Unknown'),
                    'title' => (string) ($row['ip_address'] ?? 'Unknown'),
                    'variant' => 'plain_text',
                ];
            case 'country':
                return self::buildFlaggedLocationPayload(
                    (string) ($row['country_name'] ?? 'Unknown'),
                    (string) ($row['country_code'] ?? 'unknown')
                );
            case 'region':
                return self::buildFlaggedLocationPayload(
                    (string) ($row['region_name'] ?? 'Unknown'),
                    (string) ($row['country_code'] ?? 'unknown')
                );
            case 'city':
                return self::buildFlaggedLocationPayload(
                    (string) ($row['city_name'] ?? 'Unknown'),
                    (string) ($row['country_code'] ?? 'unknown')
                );
            case 'isp':
                return [
                    'text' => (string) ($row['isp_name'] ?? 'Unknown'),
                    'title' => (string) ($row['isp_name'] ?? 'Unknown'),
                    'variant' => 'plain_text',
                ];
            case 'landingpage':
                $landingPage = (string) ($row['landing_page_nickname'] ?? 'Unknown');

                return [
                    'text' => $landingPage,
                    'title' => $landingPage,
                    'variant' => 'truncated_text',
                    'maxWidthPx' => 240,
                ];
            case 'device':
                return [
                    'text' => (string) ($row['device_name'] ?? 'Unknown'),
                    'title' => (string) ($row['device_name'] ?? 'Unknown'),
                    'variant' => 'plain_text',
                ];
            case 'browser':
                return [
                    'text' => (string) ($row['browser_name'] ?? 'Unknown'),
                    'title' => (string) ($row['browser_name'] ?? 'Unknown'),
                    'variant' => 'plain_text',
                ];
            case 'platform':
                return [
                    'text' => (string) ($row['platform_name'] ?? 'Unknown'),
                    'title' => (string) ($row['platform_name'] ?? 'Unknown'),
                    'variant' => 'plain_text',
                ];
        }

        return [
            'text' => '',
            'title' => '',
            'variant' => 'plain_text',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, array<string, mixed>>
     */
    private static function buildMetricPayload(array $row, bool $campaignDataRestricted, bool $totals): array
    {
        $prefix = $totals ? 'total_' : '';
        $clicks = self::metricCell((string) ($row[$prefix . 'clicks'] ?? '0'));
        $clickOut = self::metricCell((string) ($row[$prefix . 'click_out'] ?? '0'));
        $ctr = self::metricCell((string) ($row[$prefix . 'ctr'] ?? '0%'));
        $leads = self::metricCell((string) ($row[$prefix . 'leads'] ?? '0'));
        $avgSu = self::metricCell((string) ($row[$prefix . 'su_ratio'] ?? '0%'));
        $avgPayout = self::metricCell((string) ($row[$prefix . 'payout'] ?? '$0.00'));
        $avgEpc = self::metricCell((string) ($row[$prefix . 'epc'] ?? '$0.00'));
        $avgCpc = self::metricCell((string) ($row[$prefix . 'cpc'] ?? '$0.00'));
        $income = self::metricCell((string) ($row[$prefix . 'income'] ?? '$0.00'), 'info');
        $cost = self::metricCell(
            self::wrapCost((string) ($row[$prefix . 'cost'] ?? '$0.00')),
            'info'
        );
        $net = self::metricCell(
            (string) ($row[$prefix . 'net'] ?? '$0.00'),
            self::toneFromDisplay((string) ($row[$prefix . 'net'] ?? '$0.00'))
        );
        $roi = self::metricCell(
            (string) ($row[$prefix . 'roi'] ?? '0%'),
            self::toneFromDisplay((string) ($row[$prefix . 'roi'] ?? '0%'))
        );

        if ($campaignDataRestricted) {
            $clicks = self::metricCell('?');
            $clickOut = self::metricCell('?');
            $leads = self::metricCell('?');
            $income = self::metricCell('?', 'info');
            $cost = self::metricCell('?', 'info');
            $net = self::metricCell('?', 'default');
        }

        return [
            'clicks' => $clicks,
            'clickOut' => $clickOut,
            'ctr' => $ctr,
            'leads' => $leads,
            'avgSu' => $avgSu,
            'avgPayout' => $avgPayout,
            'avgEpc' => $avgEpc,
            'avgCpc' => $avgCpc,
            'income' => $income,
            'cost' => $cost,
            'net' => $net,
            'roi' => $roi,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildPagination(int $foundRows, int $offset, string $order): array
    {
        $limit = 0;
        if (isset($_SESSION['user_id'])) {
            new UserPrefs();
            $limit = (int) UserPrefs::getPref('user_pref_limit');
        }

        return self::paginationFor($foundRows, $limit, $offset, $order);
    }

    /**
     * The pure pagination math, split from the preference lookup above so it can be
     * exercised without a database or session.
     *
     * @return array<string, mixed>
     */
    public static function paginationFor(int $foundRows, int $limit, int $offset, string $order): array
    {
        if ($limit <= 0) {
            $limit = max($foundRows, 1);
        }

        $pageCount = max((int) ceil($foundRows / $limit), 1);
        $maxOffset = max($pageCount - 1, 0);

        // Report the offset the query actually ran with, not a clamped one. The LIMIT has
        // already executed by the time we get here, so an offset past the end returned zero
        // rows; clamping only the metadata would describe those empty rows as the last page.
        // Flag it instead, and point previousOffset at the last real page so the client can
        // always get back to data.
        $outOfRange = $offset > $maxOffset;

        return [
            'serverPaginated' => true,
            'totalRows' => $foundRows,
            'limit' => $limit,
            'currentOffset' => $offset,
            'currentPageNumber' => $offset + 1,
            'pageCount' => $pageCount,
            'outOfRange' => $outOfRange,
            'hasPreviousPage' => $offset > 0,
            'previousOffset' => $outOfRange ? $maxOffset : max($offset - 1, 0),
            'hasNextPage' => $offset < $maxOffset,
            'nextOffset' => $offset < $maxOffset ? $offset + 1 : $offset,
            'orderToken' => $order,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildFlaggedLocationPayload(string $name, string $countryCode): array
    {
        $normalizedCountryCode = strtolower(trim($countryCode)) ?: 'unknown';

        return [
            'text' => $name . ' (' . strtoupper($normalizedCountryCode) . ')',
            'title' => $name . ' (' . strtoupper($normalizedCountryCode) . ')',
            'variant' => 'flagged_location',
            'flagUrl' => get_absolute_url() . '202-img/flags/' . $normalizedCountryCode . '.png',
            'flagCode' => strtoupper($normalizedCountryCode),
        ];
    }

    private static function campaignDataRestricted(): bool
    {
        global $userObj;

        return (bool) (
            $userObj
            && !$userObj->hasPermission('access_to_campaign_data')
            && empty($_SESSION['publisher'])
        );
    }

    /**
     * @return array{display: string, tone: string}
     */
    private static function metricCell(string $display, string $tone = 'default'): array
    {
        return [
            'display' => $display,
            'tone' => $tone,
        ];
    }

    private static function wrapCost(string $display): string
    {
        return '(' . $display . ')';
    }

    private static function toneFromDisplay(string $display): string
    {
        $normalized = trim($display);
        if ($normalized === '' || $normalized === '?') {
            return 'default';
        }

        $negative = str_starts_with($normalized, '(') || str_starts_with($normalized, '-');
        $numeric = preg_replace('/[^0-9.]/', '', $normalized);
        $value = (float) ($numeric !== '' ? $numeric : '0');

        if ($value === 0.0) {
            return 'default';
        }

        return $negative ? 'important' : 'primary';
    }
}
