<?php

declare(strict_types=1);

namespace Prosper202\DataEngine;

/**
 * Formats a raw report row (or totals row) into the HTML-escaped display
 * array consumed by DisplayData and the AJAX report templates.
 *
 * Pure with respect to the database: the user's currency symbol is injected
 * once via the constructor. The legacy implementation re-queried the
 * currency preference for every single row it formatted.
 */
final class HtmlReportFormatter
{
    /** Money columns rendered through dollar_format(). */
    private const MONEY_KEYS = ['payout', 'income', 'epc', 'cpc', 'cost', 'net'];

    /** Count columns rendered through number_format(). */
    private const COUNT_KEYS = ['clicks', 'leads', 'click_out'];

    /** Dimension columns and the placeholder shown when the value is empty. */
    private const EMPTY_PLACEHOLDERS = [
        'keyword' => '[no keyword]',
        'text_ad_name' => '[no text ad]',
        'referer_name' => '[no referer]',
        'country_name' => '[no country]',
        'region_name' => '[no region]',
        'city_name' => '[no city]',
        'country_code' => 'non',
        'isp_name' => '[no isp]',
        'landing_page_nickname' => '[direct link]',
        'device' => '[no device]',
        'browser' => '[no browser]',
        'platform' => '[no platform]',
    ];

    /** Totals keys that must always be present (default '0') in the output. */
    private const REQUIRED_TOTAL_KEYS = [
        'total_clicks',
        'total_click_out',
        'total_ctr',
        'total_cost',
        'total_cpc',
        'total_leads',
        'total_su_ratio',
        'total_payout',
        'total_income',
        'total_epc',
        'total_net',
        'total_roi',
    ];

    public function __construct(private readonly string $currency)
    {
    }

    /**
     * @param array<string, mixed> $row Raw metric/dimension values.
     * @param string $type    'total' prefixes every key with "total_".
     * @param string $mainKey Calling-context marker (kept for signature
     *                        compatibility with the legacy htmlFormat()).
     *
     * @return array<string, string>
     */
    public function format(array $row, string $type = '', string $mainKey = ''): array
    {
        $prepend = $type === 'total' ? 'total_' : '';

        $clicks = (float) ($row['clicks'] ?? 0);
        $ctr = $clicks > 0 ? round((float) ($row['click_out'] ?? 0) / $clicks * 100, 2) : 0;

        $html = [];
        foreach ($row as $key => $value) {
            $html[$prepend . $key] = $this->formatValue((string) $key, $value, $ctr);
        }

        if (!isset($html['aff_campaign_name']) || strlen($html['aff_campaign_name']) == 0) {
            $html['aff_campaign_name'] = '[Landing Page/Smart Redirector Campaign]';
        }

        foreach (self::REQUIRED_TOTAL_KEYS as $key) {
            if (!isset($html[$key]) || $html[$key] === '') {
                $html[$key] = '0';
            }
        }

        return $html;
    }

    private function formatValue(string $key, mixed $value, float|int $ctr): string
    {
        if (in_array($key, self::COUNT_KEYS, true)) {
            return $this->escape(number_format((float) $value));
        }

        if (in_array($key, self::MONEY_KEYS, true)) {
            return $this->escape((string) \dollar_format($value, $this->currency));
        }

        if (isset(self::EMPTY_PLACEHOLDERS[$key])) {
            if (!$value) {
                return $this->escape(self::EMPTY_PLACEHOLDERS[$key]);
            }

            return $this->escape((string) $value);
        }

        return match ($key) {
            'su_ratio' => $this->escape(round((float) $value, 2) . '%'),
            'ctr' => $this->escape(round($ctr, 2) . '%'),
            'roi' => $this->escape(number_format((float) ($value ?? 0)) . '%'),
            'click_time_from_disp' => $this->escape(str_replace(['AM', 'PM'], ['am', 'pm'], (string) $value)),
            'ip_address' => $this->formatIpAddress($value),
            default => $this->escape((string) $value),
        };
    }

    private function formatIpAddress(mixed $value): string
    {
        if (!$value) {
            return $this->escape('[no ip]');
        }

        if (ctype_print((string) $value)) {
            return $this->escape((string) $value);
        }

        // Binary value: decode the packed IPv6 address for display.
        return $this->escape((string) \inet6_ntoa($value));
    }

    private function escape(string $value): string
    {
        return htmlentities($value, ENT_QUOTES, 'UTF-8');
    }
}
