<?php

declare(strict_types=1);

namespace Prosper202\Click;

use InvalidArgumentException;
use Prosper202\Identity\ClickIdentity;

/**
 * Builds a ClickRecord from the $mysql array the click scripts fill. An
 * absent or empty value is its column's default; a value that is present
 * and is not what its column holds is refused, naming the key, where the
 * cast this replaced stored `'12abc'` as 12 and `'abc'` as 0.
 */
final class ClickRecordBuilder
{
    private const INTEGER = '/\A-?\d+\z/';
    private const DECIMAL = '/\A-?\d+(?:\.\d+)?\z/';

    private function __construct()
    {
    }

    /**
     * A direct link or landing page click (dl.php, record_simple.php,
     * record_adv.php).
     *
     * @param array<string, mixed> $mysql
     * @throws InvalidArgumentException naming the key whose value its column cannot hold
     */
    public static function fromLegacyArray(array $mysql, int $clickId, ?ClickIdentity $identity = null): ClickRecord
    {
        return self::build($mysql, $clickId, $identity, null);
    }

    /**
     * A redirector click (rtr.php): its route from rotator_id, rule_id and
     * rule_redirect_id, and no ad click id, UTM or variable rows.
     *
     * @param array<string, mixed> $mysql
     * @throws InvalidArgumentException naming the key whose value its column cannot hold
     */
    public static function forRedirector(array $mysql, int $clickId, ?ClickIdentity $identity = null): ClickRecord
    {
        $route = new RotatorRoute(
            self::int($mysql, 'rotator_id'),
            self::int($mysql, 'rule_id'),
            self::int($mysql, 'rule_redirect_id'),
        );

        return self::build($mysql, $clickId, $identity, $route);
    }

    /** @param array<string, mixed> $mysql */
    private static function build(
        array $mysql,
        int $clickId,
        ?ClickIdentity $identity,
        ?RotatorRoute $route
    ): ClickRecord {
        $campaignRows = $route === null;

        return new ClickRecord(
            clickId: $clickId,
            userId: self::int($mysql, 'user_id'),
            clickTime: self::int($mysql, 'click_time'),
            affCampaignId: self::int($mysql, 'aff_campaign_id'),
            landingPageId: self::int($mysql, 'landing_page_id'),
            ppcAccountId: self::int($mysql, 'ppc_account_id'),
            clickCpc: self::decimal($mysql, 'click_cpc'),
            clickPayout: self::decimal($mysql, 'click_payout'),
            clickFiltered: self::int($mysql, 'click_filtered'),
            clickBot: self::int($mysql, 'click_bot'),
            clickAlp: self::int($mysql, 'click_alp'),
            textAdId: self::int($mysql, 'text_ad_id'),
            keywordId: self::int($mysql, 'keyword_id'),
            ipId: self::int($mysql, 'ip_id'),
            countryId: self::int($mysql, 'country_id'),
            regionId: self::int($mysql, 'region_id'),
            ispId: self::int($mysql, 'isp_id'),
            cityId: self::int($mysql, 'city_id'),
            platformId: self::int($mysql, 'platform_id'),
            browserId: self::int($mysql, 'browser_id'),
            deviceId: self::int($mysql, 'device_id'),
            c1Id: self::int($mysql, 'c1_id'),
            c2Id: self::int($mysql, 'c2_id'),
            c3Id: self::int($mysql, 'c3_id'),
            c4Id: self::int($mysql, 'c4_id'),
            recordsCampaignRows: $campaignRows,
            variableSetId: $campaignRows ? self::int($mysql, 'variable_set_id') : 0,
            gclid: $campaignRows ? ClickRecord::storedGclid(self::text($mysql, 'gclid')) : '',
            gbraid: $campaignRows ? ClickRecord::storedGbraid(self::text($mysql, 'gbraid')) : '',
            wbraid: $campaignRows ? ClickRecord::storedWbraid(self::text($mysql, 'wbraid')) : '',
            ttclid: $campaignRows ? ClickRecord::storedTtclid(self::text($mysql, 'ttclid')) : '',
            utmSourceId: $campaignRows ? self::int($mysql, 'utm_source_id') : 0,
            utmMediumId: $campaignRows ? self::int($mysql, 'utm_medium_id') : 0,
            utmCampaignId: $campaignRows ? self::int($mysql, 'utm_campaign_id') : 0,
            utmTermId: $campaignRows ? self::int($mysql, 'utm_term_id') : 0,
            utmContentId: $campaignRows ? self::int($mysql, 'utm_content_id') : 0,
            clickIdPublic: self::publicId($mysql),
            clickCloaking: self::int($mysql, 'click_cloaking'),
            clickIn: self::int($mysql, 'click_in', 1),
            clickOut: self::int($mysql, 'click_out'),
            clickRefererSiteUrlId: self::int($mysql, 'click_referer_site_url_id'),
            clickLandingSiteUrlId: self::int($mysql, 'click_landing_site_url_id'),
            clickOutboundSiteUrlId: self::int($mysql, 'click_outbound_site_url_id'),
            clickCloakingSiteUrlId: self::int($mysql, 'click_cloaking_site_url_id'),
            clickRedirectSiteUrlId: self::int($mysql, 'click_redirect_site_url_id'),
            rotator: $route,
            identity: $identity,
        );
    }

    /** @param array<string, mixed> $mysql */
    private static function int(array $mysql, string $key, int $absent = 0): int
    {
        $value = $mysql[$key] ?? null;
        if ($value === null || $value === '') {
            return $absent;
        }
        if (is_int($value)) {
            return $value;
        }
        // (string) (int) refuses what does not survive the round trip: a
        // leading zero or sign, and a number past PHP_INT_MAX.
        if (is_string($value) && preg_match(self::INTEGER, $value) === 1 && (string) (int) $value === $value) {
            return (int) $value;
        }

        throw self::refused($key, 'a whole number', $value);
    }

    /** @param array<string, mixed> $mysql */
    private static function decimal(array $mysql, string $key): string
    {
        $value = $mysql[$key] ?? null;
        if ($value === null || $value === '') {
            return '0';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_string($value) && preg_match(self::DECIMAL, $value) === 1) {
            return $value;
        }

        throw self::refused($key, 'a decimal number', $value);
    }

    /**
     * The public id as its BIGINT UNSIGNED column takes it: '0' when the
     * click has none, which dl.php writes as ''. Under a strict sql_mode the
     * '' was refused and the click lost.
     *
     * @param array<string, mixed> $mysql
     */
    private static function publicId(array $mysql): string
    {
        $value = $mysql['click_id_public'] ?? null;
        if ($value === null || $value === '') {
            return '0';
        }
        if (is_int($value) && $value >= 0) {
            return (string) $value;
        }
        if (is_string($value) && preg_match('/\A\d+\z/', $value) === 1) {
            return $value;
        }

        throw self::refused('click_id_public', 'digits', $value);
    }

    /** @param array<string, mixed> $mysql */
    private static function text(array $mysql, string $key): string
    {
        $value = $mysql[$key] ?? null;
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return $value;
        }

        throw self::refused($key, 'a string', $value);
    }

    private static function refused(string $key, string $expected, mixed $value): InvalidArgumentException
    {
        $shown = is_string($value) ? "'" . substr($value, 0, 40) . (strlen($value) > 40 ? '…' : '') . "'"
            : get_debug_type($value);

        return new InvalidArgumentException("The click's $key is $expected, not $shown");
    }
}
