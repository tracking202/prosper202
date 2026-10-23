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
 * $recordsCampaignRows: whether the click's path records the gclid, UTM
 * values and tracking variables (202_google, 202_clicks_variable). The
 * redirector records none, and writes no rows there: the rollup reads a
 * missing row as NULL, which a row of zeros would turn into 0.
 */
final readonly class ClickRecord
{
    /** 202_google.gclid is a VARCHAR(150). */
    public const GCLID_CHARS = 150;

    private const DECIMAL = '/\A-?\d+(?:\.\d+)?\z/';

    /** A gclid as its column keeps it. */
    public static function storedGclid(string $gclid): string
    {
        return StoredText::chars($gclid, self::GCLID_CHARS);
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
        if (self::storedGclid($gclid) !== $gclid) {
            throw new InvalidArgumentException(
                "A click's gclid is at most " . self::GCLID_CHARS . ' characters, as its column holds'
            );
        }
        $campaignValues = [$variableSetId, $utmSourceId, $utmMediumId, $utmCampaignId, $utmTermId, $utmContentId];
        if (!$recordsCampaignRows && ($gclid !== '' || array_filter($campaignValues) !== [])) {
            throw new InvalidArgumentException(
                'A click that records no gclid, UTM values or tracking variables was given one'
            );
        }
    }
}
