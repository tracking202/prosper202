<?php

declare(strict_types=1);

namespace Prosper202\Click;

use InvalidArgumentException;
use Prosper202\Identity\ClickIdentity;
use Prosper202\Repository\StoredText;

/**
 * One click's rows, as MysqlClickRepository writes them. The click scripts
 * build one with ClickRecordBuilder; a value its column cannot hold is
 * refused here, naming the field, rather than stored as something else.
 *
 * $recordsCampaignRows: whether the click's path records the ad platforms'
 * click ids, UTM values and tracking variables (202_google,
 * 202_clicks_variable). The redirector records none, and writes no rows
 * there: the rollup reads a missing row as NULL, which a row of zeros would
 * turn into 0.
 */
final readonly class ClickRecord
{
    /** 202_google.gclid is a VARCHAR(150). */
    public const GCLID_CHARS = 150;

    /**
     * Google's iOS click ids, 202_google.gbraid and wbraid: VARCHAR(150), as
     * the gclid, for values measured at 34 and 87 characters.
     */
    public const GBRAID_CHARS = 150;
    public const WBRAID_CHARS = 150;

    /**
     * TikTok's click id, 202_google.ttclid, a VARCHAR(1000): measured at 313
     * to 337 characters, so the gclid's 150 would have cut every one, and an
     * id cut short matches no click on the platform.
     */
    public const TTCLID_CHARS = 1000;

    /**
     * Microsoft Advertising's click id, 202_google.msclkid: a 32-character
     * GUID as Microsoft documents it, in a VARCHAR(150) as the gclid.
     */
    public const MSCLKID_CHARS = 150;

    /**
     * Meta's click id, 202_google.fbclid, a VARCHAR(1000): measured at 61
     * characters in its old form and 200 in the form with an `_aem_`
     * payload, past the 150 that 202_facebook's column holds.
     */
    public const FBCLID_CHARS = 1000;

    /**
     * The ad platforms' click ids a click keeps, each by the request
     * parameter it arrives in, with its column's width in characters.
     */
    public const AD_CLICK_IDS = [
        'gclid' => self::GCLID_CHARS,
        'gbraid' => self::GBRAID_CHARS,
        'wbraid' => self::WBRAID_CHARS,
        'ttclid' => self::TTCLID_CHARS,
        'msclkid' => self::MSCLKID_CHARS,
        'fbclid' => self::FBCLID_CHARS,
    ];

    private const DECIMAL = '/\A-?\d+(?:\.\d+)?\z/';

    /** A gclid as its column keeps it. */
    public static function storedGclid(string $gclid): string
    {
        return StoredText::chars($gclid, self::GCLID_CHARS);
    }

    public static function storedGbraid(string $gbraid): string
    {
        return StoredText::chars($gbraid, self::GBRAID_CHARS);
    }

    public static function storedWbraid(string $wbraid): string
    {
        return StoredText::chars($wbraid, self::WBRAID_CHARS);
    }

    public static function storedTtclid(string $ttclid): string
    {
        return StoredText::chars($ttclid, self::TTCLID_CHARS);
    }

    public static function storedMsclkid(string $msclkid): string
    {
        return StoredText::chars($msclkid, self::MSCLKID_CHARS);
    }

    public static function storedFbclid(string $fbclid): string
    {
        return StoredText::chars($fbclid, self::FBCLID_CHARS);
    }

    /**
     * An ad click id (AD_CLICK_IDS, by name) as its column keeps it: cut to
     * the column's width in characters, as the gclid always was.
     *
     * @throws InvalidArgumentException for a name that is not one
     */
    public static function storedAdClickId(string $name, string $value): string
    {
        return match ($name) {
            'gclid' => self::storedGclid($value),
            'gbraid' => self::storedGbraid($value),
            'wbraid' => self::storedWbraid($value),
            'ttclid' => self::storedTtclid($value),
            'msclkid' => self::storedMsclkid($value),
            'fbclid' => self::storedFbclid($value),
            default => throw new InvalidArgumentException("$name is not an ad click id a click keeps"),
        };
    }

    /**
     * Every ad click id as its column keeps it, '' for one $ids does not
     * have: what a URL's tokens send for a click about to be stored.
     *
     * @param array<string, string> $ids by name
     * @return array<string, string>
     */
    public static function storedAdClickIds(array $ids): array
    {
        $stored = [];
        foreach (array_keys(self::AD_CLICK_IDS) as $name) {
            $stored[$name] = self::storedAdClickId($name, $ids[$name] ?? '');
        }

        return $stored;
    }

    public function __construct(
        // allocated first (MysqlClickRepository::allocateClickId()): every
        // path needs it before the write, for the public id and the URLs
        public int $clickId,
        public int $userId,
        public int $clickTime,
        // 202_clicks (and 202_clicks_spy)
        public int $affCampaignId = 0,
        public int $landingPageId = 0,
        public int $ppcAccountId = 0,
        public string $clickCpc = '0',
        public string $clickPayout = '0',
        public int $clickFiltered = 0,
        public int $clickBot = 0,
        public int $clickAlp = 0,
        // 202_clicks_advance
        public int $textAdId = 0,
        public int $keywordId = 0,
        public int $ipId = 0,
        public int $countryId = 0,
        public int $regionId = 0,
        public int $ispId = 0,
        public int $cityId = 0,
        public int $platformId = 0,
        public int $browserId = 0,
        public int $deviceId = 0,
        // 202_clicks_tracking
        public int $c1Id = 0,
        public int $c2Id = 0,
        public int $c3Id = 0,
        public int $c4Id = 0,
        // 202_clicks_variable and 202_google
        public bool $recordsCampaignRows = true,
        public int $variableSetId = 0,
        public string $gclid = '',
        public string $gbraid = '',
        public string $wbraid = '',
        public string $ttclid = '',
        public string $msclkid = '',
        public string $fbclid = '',
        public int $utmSourceId = 0,
        public int $utmMediumId = 0,
        public int $utmCampaignId = 0,
        public int $utmTermId = 0,
        public int $utmContentId = 0,
        // 202_clicks_record. The public id stays a string: a digit, the click
        // id and a digit, it passes a 32-bit PHP's int range at tens of
        // millions of clicks. '0' when the click has none.
        public string $clickIdPublic = '0',
        // -1: the campaign decides when the visitor leaves (off.php)
        public int $clickCloaking = 0,
        public int $clickIn = 1,
        public int $clickOut = 0,
        // 202_clicks_site
        public int $clickRefererSiteUrlId = 0,
        public int $clickLandingSiteUrlId = 0,
        public int $clickOutboundSiteUrlId = 0,
        public int $clickCloakingSiteUrlId = 0,
        public int $clickRedirectSiteUrlId = 0,
        // 202_clicks.rotator_id/rule_id and 202_clicks_rotator; null off the redirector
        public ?RotatorRoute $rotator = null,
        // linked after the click's own transaction commits
        public ?ClickIdentity $identity = null,
    ) {
        foreach (['clickId' => $clickId, 'userId' => $userId, 'clickTime' => $clickTime] as $field => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException("A click's $field is a positive number, not $value");
            }
        }
        $ids = [
            'affCampaignId' => $affCampaignId, 'landingPageId' => $landingPageId, 'ppcAccountId' => $ppcAccountId,
            'textAdId' => $textAdId, 'keywordId' => $keywordId, 'ipId' => $ipId, 'countryId' => $countryId,
            'regionId' => $regionId, 'ispId' => $ispId, 'cityId' => $cityId, 'platformId' => $platformId,
            'browserId' => $browserId, 'deviceId' => $deviceId,
            'c1Id' => $c1Id, 'c2Id' => $c2Id, 'c3Id' => $c3Id, 'c4Id' => $c4Id,
            'variableSetId' => $variableSetId, 'utmSourceId' => $utmSourceId, 'utmMediumId' => $utmMediumId,
            'utmCampaignId' => $utmCampaignId, 'utmTermId' => $utmTermId, 'utmContentId' => $utmContentId,
            'clickRefererSiteUrlId' => $clickRefererSiteUrlId, 'clickLandingSiteUrlId' => $clickLandingSiteUrlId,
            'clickOutboundSiteUrlId' => $clickOutboundSiteUrlId, 'clickCloakingSiteUrlId' => $clickCloakingSiteUrlId,
            'clickRedirectSiteUrlId' => $clickRedirectSiteUrlId,
        ];
        foreach ($ids as $field => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException("A click's $field is an id or 0, not $value");
            }
        }
        $flags = [
            'clickFiltered' => $clickFiltered, 'clickBot' => $clickBot, 'clickAlp' => $clickAlp,
            'clickIn' => $clickIn, 'clickOut' => $clickOut,
        ];
        foreach ($flags as $field => $value) {
            if ($value !== 0 && $value !== 1) {
                throw new InvalidArgumentException("A click's $field is 0 or 1, not $value");
            }
        }
        if (!in_array($clickCloaking, [-1, 0, 1], true)) {
            throw new InvalidArgumentException("A click's clickCloaking is -1, 0 or 1, not $clickCloaking");
        }
        foreach (['clickCpc' => $clickCpc, 'clickPayout' => $clickPayout] as $field => $value) {
            if (preg_match(self::DECIMAL, $value) !== 1) {
                throw new InvalidArgumentException("A click's $field is a decimal number, not '$value'");
            }
        }
        if (preg_match('/\A\d+\z/', $clickIdPublic) !== 1) {
            throw new InvalidArgumentException("A click's clickIdPublic is digits, not '$clickIdPublic'");
        }
        $adClickIds = [
            'gclid' => $gclid, 'gbraid' => $gbraid, 'wbraid' => $wbraid, 'ttclid' => $ttclid,
            'msclkid' => $msclkid, 'fbclid' => $fbclid,
        ];
        foreach ($adClickIds as $name => $value) {
            if (self::storedAdClickId($name, $value) !== $value) {
                throw new InvalidArgumentException(
                    "A click's $name is at most " . self::AD_CLICK_IDS[$name] . ' characters, as its column holds'
                );
            }
        }
        $campaignValues = [$variableSetId, $utmSourceId, $utmMediumId, $utmCampaignId, $utmTermId, $utmContentId];
        if (!$recordsCampaignRows && (implode('', $adClickIds) !== '' || array_filter($campaignValues) !== [])) {
            throw new InvalidArgumentException(
                'A click that records no gclid, gbraid, wbraid, ttclid, msclkid, fbclid, UTM values or'
                    . ' tracking variables was given one'
            );
        }
    }
}
