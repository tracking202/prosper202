<?php

declare(strict_types=1);

namespace Prosper202\Report;

/**
 * The Visitors download (tracking202/visitors/download/): one line per click
 * of the page's query, every cell written by TsvCell::text().
 */
final class VisitorsDownload
{
    /** The header, and the order every row's cells are written in. */
    public const HEADER = [
        'Subid', 'Date', 'Browser', 'OS', 'PPC Network', 'PPC account', 'Click Real/Filtered', 'IP Address',
        'ISP/Carrier', 'Country', 'Country Code', 'City', 'Offer/LP', 'Text Ad', 'Referer', 'Landing', 'Outbound',
        'Cloaked Referer', 'Redirect', 'Keyword', 'gclid', 'gbraid', 'wbraid', 'ttclid', 'msclkid', 'fbclid', 'oppref',
        'utm_campaign', 'utm_content', 'utm_medium', 'utm_source', 'utm_term',
    ];

    /** The query's values written as read, in the order of HEADER around Date, the status and the cloaked link. */
    private const AS_READ = [
        'browser_name', 'platform_name', 'ppc_network_name', 'ppc_account_name',
    ];

    private const AFTER_STATUS = [
        'ip_address', 'isp_name', 'country_name', 'country_code', 'city_name', 'aff_campaign_name', 'text_ad_name',
        'referer', 'landing', 'outbound',
    ];

    private const AFTER_CLOAKING = [
        'redirect', 'keyword', 'gclid', 'gbraid', 'wbraid', 'ttclid', 'msclkid', 'fbclid', 'oppref',
        'utm_campaign', 'utm_content', 'utm_medium', 'utm_source', 'utm_term',
    ];

    private function __construct()
    {
    }

    public static function header(): string
    {
        return TsvCell::row(self::HEADER);
    }

    /**
     * @param array<string, string|int|float|null> $click a row of the page's query
     * @param string $trackingBase the base the click's cloaked link is built on
     *   (TrackingBaseUrl::forRequest())
     */
    public static function row(array $click, string $trackingBase): string
    {
        $cells = [$click['click_id'] ?? null, date('m/d/y g:ia', (int) ($click['click_time'] ?? 0))];
        foreach (self::AS_READ as $key) {
            $cells[] = $click[$key] ?? null;
        }
        $cells[] = self::status($click);
        foreach (self::AFTER_STATUS as $key) {
            $cells[] = $click[$key] ?? null;
        }
        $cells[] = self::cloakedLink($click, $trackingBase);
        foreach (self::AFTER_CLOAKING as $key) {
            $cells[] = $click[$key] ?? null;
        }

        return TsvCell::row($cells);
    }

    /** @param array<string, string|int|float|null> $click */
    private static function status(array $click): string
    {
        if ((string) ($click['click_filtered'] ?? '') === '1') {
            return 'filtered';
        }

        return (string) ($click['click_lead'] ?? '') === '1' ? 'conversion' : 'real';
    }

    /** @param array<string, string|int|float|null> $click */
    private static function cloakedLink(array $click, string $trackingBase): string
    {
        if (!($click['click_cloaking'] ?? false)) {
            return '';
        }
        $pci = (string) ($click['click_id_public'] ?? '');
        if (!($click['click_alp'] ?? false)) {
            return $trackingBase . 'tracking202/redirect/cl.php?pci=' . $pci;
        }

        return $trackingBase . 'tracking202/redirect/off.php?acip=' . ($click['aff_campaign_id_public'] ?? '')
            . '&pci=' . $pci;
    }
}
