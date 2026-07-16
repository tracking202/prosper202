<?php

declare(strict_types=1);

namespace Tracking202\Data;

use DB;
use mysqli;

final class StaticFilterOptionsProvider
{
    private const FILTERS = [
        'country' => [
            'query' => 'SELECT country_id AS option_value, country_name AS option_label FROM 202_locations_country GROUP BY country_name ORDER BY country_name ASC',
        ],
        'region' => [
            'query' => 'SELECT region_id AS option_value, region_name AS option_label FROM 202_locations_region GROUP BY region_name ORDER BY region_name ASC',
        ],
        'isp' => [
            'query' => 'SELECT isp_id AS option_value, isp_name AS option_label FROM 202_locations_isp GROUP BY isp_name ORDER BY isp_name ASC',
        ],
        'device' => [
            'query' => 'SELECT type_id AS option_value, type_name AS option_label FROM 202_device_types ORDER BY type_name ASC',
        ],
        'browser' => [
            'query' => 'SELECT browser_id AS option_value, browser_name AS option_label FROM 202_browsers GROUP BY browser_name ORDER BY browser_name ASC',
        ],
        'platform' => [
            'query' => 'SELECT platform_id AS option_value, platform_name AS option_label FROM 202_platforms GROUP BY platform_name ORDER BY platform_name ASC',
        ],
    ];

    /**
     * @param array<string, string> $selectedValues
     * @return array<string, array{enabled: bool, option_count: int, estimated_bytes: int, options_html: string}>
     */
    public static function build(array $selectedValues): array
    {
        $states = self::disabledStates();
        if (!function_exists('tracking202StaticFilterSsrEnabled') || !tracking202StaticFilterSsrEnabled()) {
            return $states;
        }

        $database = DB::getInstance();
        $db = $database->getConnection();

        $combinedBytes = 0;
        foreach (self::FILTERS as $filterName => $config) {
            $state = self::buildFilterState($db, $config['query'], $selectedValues[$filterName] ?? '');
            $states[$filterName] = $state;

            if ($state['enabled']) {
                $combinedBytes += $state['estimated_bytes'];
            }
        }

        if (function_exists('tracking202StaticFilterSsrMaxBytes') && $combinedBytes > tracking202StaticFilterSsrMaxBytes()) {
            return self::disabledStates();
        }

        return $states;
    }

    /**
     * @return array<string, array{enabled: bool, option_count: int, estimated_bytes: int, options_html: string}>
     */
    private static function disabledStates(): array
    {
        $states = [];
        foreach (array_keys(self::FILTERS) as $filterName) {
            $states[$filterName] = [
                'enabled' => false,
                'option_count' => 0,
                'estimated_bytes' => 0,
                'options_html' => '',
            ];
        }

        return $states;
    }

    /**
     * @return array{enabled: bool, option_count: int, estimated_bytes: int, options_html: string}
     */
    private static function buildFilterState(mysqli $db, string $query, string $selectedValue): array
    {
        $result = $db->query($query);
        if (!$result) {
            // Degrade rather than throw, unlike DependentFilterPayloadBuilder. A disabled
            // static filter falls back to the legacy load_<name>_id() AJAX call, which
            // fetches the same options — the user sees an identical dropdown, so there is
            // no data loss or wrong number to report. Still logged: a persistently failing
            // query here would silently cost every page an extra round-trip forever.
            error_log('tracking202 static filter SSR: query failed, falling back to AJAX: ' . $db->error);

            return [
                'enabled' => false,
                'option_count' => 0,
                'estimated_bytes' => 0,
                'options_html' => '',
            ];
        }

        $optionCount = 0;
        $optionsHtml = '';
        while ($row = $result->fetch_assoc()) {
            $optionCount++;
            $rawValue = (string) ($row['option_value'] ?? '');
            $value = htmlentities($rawValue, ENT_QUOTES, 'UTF-8');
            $label = htmlentities((string) ($row['option_label'] ?? ''), ENT_QUOTES, 'UTF-8');
            $selected = ($selectedValue !== '' && $selectedValue === $rawValue) ? 'selected=""' : '';
            $optionsHtml .= sprintf('<option %s value="%s">%s</option>', $selected, $value, $label);
        }

        $enabled = !function_exists('tracking202StaticFilterSsrMaxOptions')
            || $optionCount <= tracking202StaticFilterSsrMaxOptions();

        return [
            'enabled' => $enabled,
            'option_count' => $optionCount,
            'estimated_bytes' => strlen($optionsHtml),
            'options_html' => $enabled ? $optionsHtml : '',
        ];
    }
}
