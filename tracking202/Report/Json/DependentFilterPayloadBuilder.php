<?php

declare(strict_types=1);

namespace Tracking202\Report\Json;

/**
 * Builds the first-pass dependent filter payload for flat report canaries.
 *
 * The current UI expects server-rendered select markup with inline change handlers,
 * so this adapter keeps those fragments server-owned while transport moves to JSON.
 */
final class DependentFilterPayloadBuilder
{
    /**
     * @param array<string, mixed> $userPreferences
     * @return array<string, mixed>
     */
    public static function build(ReportDispatchRequest $request, array $userPreferences): array
    {
        if (!$request->includeDependentFilters) {
            return [
                'requested' => false,
                'included' => false,
                'reason' => 'not_requested',
            ];
        }

        if (!empty($_SESSION['publisher'])) {
            return [
                'requested' => true,
                'included' => false,
                'reason' => 'publisher_sections_hidden',
            ];
        }

        $preferences = self::normalizePreferences($userPreferences);

        return [
            'requested' => true,
            'included' => true,
            'fragments' => [
                'affCampaign' => [
                    'targetId' => 'aff_campaign_id_div',
                    'html' => self::renderAffiliateCampaignSelect(
                        $preferences['affNetworkId'],
                        $preferences['affCampaignId']
                    ),
                ],
                'textAd' => [
                    'targetId' => 'text_ad_id_div',
                    'html' => self::renderTextAdSelect(
                        $preferences['affCampaignId'],
                        $preferences['textAdId']
                    ),
                ],
                'landingPage' => [
                    'targetId' => 'landing_page_div',
                    'html' => self::renderLandingPageSelect(
                        $preferences['affCampaignId'],
                        $preferences['landingPageId'],
                        $preferences['methodOfPromotion']
                    ),
                ],
                'adPreview' => [
                    'targetId' => 'ad_preview_div',
                    'html' => self::renderAdPreview($preferences['textAdId']),
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $userPreferences
     * @return array{affNetworkId: int, affCampaignId: int, textAdId: int, landingPageId: int, methodOfPromotion: string}
     */
    private static function normalizePreferences(array $userPreferences): array
    {
        $methodOfPromotion = trim((string) ($userPreferences['user_pref_method_of_promotion'] ?? ''));
        if ($methodOfPromotion === 'landingpage') {
            $methodOfPromotion = 'landingpages';
        }

        return [
            'affNetworkId' => self::normalizeId($userPreferences['user_pref_aff_network_id'] ?? 0),
            'affCampaignId' => self::normalizeId($userPreferences['user_pref_aff_campaign_id'] ?? 0),
            'textAdId' => self::normalizeId($userPreferences['user_pref_text_ad_id'] ?? 0),
            'landingPageId' => self::normalizeId($userPreferences['user_pref_landing_page_id'] ?? 0),
            'methodOfPromotion' => $methodOfPromotion,
        ];
    }

    private static function normalizeId(mixed $value): int
    {
        if (is_int($value)) {
            return max($value, 0);
        }

        $stringValue = trim((string) $value);
        if ($stringValue === '' || preg_match('/^\d+$/', $stringValue) !== 1) {
            return 0;
        }

        return (int) $stringValue;
    }

    private static function renderAffiliateCampaignSelect(int $affNetworkId, int $selectedAffCampaignId): string
    {
        if ($selectedAffCampaignId <= 0) {
            return '';
        }

        $db = \DB::getInstance()->getConnection();
        $userId = $db->real_escape_string((string) ($_SESSION['user_id'] ?? 0));
        $escapedAffNetworkId = $db->real_escape_string((string) $affNetworkId);

        $sql = "SELECT * 
                FROM `202_aff_campaigns`
                WHERE `user_id`='" . $userId . "'
                AND `aff_network_id`='" . $escapedAffNetworkId . "'
                AND `aff_campaign_deleted`='0'
                ORDER BY `aff_campaign_name` ASC";
        $result = $db->query($sql);
        if ($result === false) {
            error_log('tracking202 dependent filter: failed to load affiliate campaign options: ' . $db->error);
            throw new \RuntimeException('Unable to load affiliate campaign options.');
        }

        if ($result->num_rows === 0) {
            return self::renderDisabledSelect('aff_campaign_id');
        }

        $options = ['<option value="0"> -- </option>'];
        while ($row = $result->fetch_assoc()) {
            $campaignId = self::escapeAttribute((string) ($row['aff_campaign_id'] ?? ''));
            $campaignName = self::escapeHtml((string) ($row['aff_campaign_name'] ?? ''));
            $campaignPayout = self::escapeHtml((string) ($row['aff_campaign_payout'] ?? ''));
            $selected = ((string) $selectedAffCampaignId === (string) ($row['aff_campaign_id'] ?? ''))
                ? ' selected=""'
                : '';

            $options[] = sprintf(
                '<option%s value="%s">%s &middot; &#36;%s</option>',
                $selected,
                $campaignId,
                $campaignName,
                $campaignPayout
            );
        }

        return '<select class="form-control input-sm" name="aff_campaign_id" id="aff_campaign_id" onchange="load_text_ad_id(this.value); if($(\'#landing_page_style_type\')){load_landing_page($(\'#aff_campaign_id option:selected\').val(), 0, $(\'input:radio[name=landing_page_type]:checked\').val()?$(\'input:radio[name=landing_page_type]:checked\').val():\'landingpage\');} if($(\'#unsecure_pixel\').length != 0) { change_pixel_data();}">'
            . implode('', $options)
            . '</select>';
    }

    private static function renderTextAdSelect(int $affCampaignId, int $selectedTextAdId): string
    {
        if ($selectedTextAdId <= 0) {
            return '';
        }

        $db = \DB::getInstance()->getConnection();
        $userId = $db->real_escape_string((string) ($_SESSION['user_id'] ?? 0));
        $escapedAffCampaignId = $db->real_escape_string((string) $affCampaignId);

        $sql = "SELECT *
                FROM `202_text_ads`
                WHERE `user_id`='" . $userId . "'
                AND `aff_campaign_id`='" . $escapedAffCampaignId . "'
                AND `text_ad_deleted`='0'
                ORDER BY `aff_campaign_id`, `text_ad_name` ASC";
        $result = $db->query($sql);
        if ($result === false) {
            error_log('tracking202 dependent filter: failed to load text ad options: ' . $db->error);
            throw new \RuntimeException('Unable to load text ad options.');
        }

        if ($result->num_rows === 0) {
            return self::renderDisabledSelect('text_ad_id', '--');
        }

        $options = ['<option value="0"> -- </option>'];
        while ($row = $result->fetch_assoc()) {
            $textAdId = self::escapeAttribute((string) ($row['text_ad_id'] ?? ''));
            $textAdName = self::escapeHtml((string) ($row['text_ad_name'] ?? ''));
            $selected = ((string) $selectedTextAdId === (string) ($row['text_ad_id'] ?? ''))
                ? ' selected=""'
                : '';

            $options[] = sprintf('<option%s value="%s">%s</option>', $selected, $textAdId, $textAdName);
        }

        return '<select class="form-control input-sm" id="text_ad_id" name="text_ad_id" onchange="load_ad_preview(this.value);">'
            . implode('', $options)
            . '</select>';
    }

    private static function renderLandingPageSelect(int $affCampaignId, int $selectedLandingPageId, string $type): string
    {
        if ($selectedLandingPageId <= 0) {
            return '';
        }

        if ($type !== 'landingpage' && $type !== 'landingpages' && $type !== 'advlandingpage') {
            return self::renderDisabledSelect('landing_page_id');
        }

        $db = \DB::getInstance()->getConnection();
        $userId = $db->real_escape_string((string) ($_SESSION['user_id'] ?? 0));
        $escapedAffCampaignId = $db->real_escape_string((string) $affCampaignId);
        $eq = $affCampaignId === 0 ? '>=' : '=';

        if ($type === 'landingpage') {
            $sql = "SELECT *
                    FROM `202_landing_pages` AS 2lp
                    JOIN 202_aff_campaigns USING(aff_campaign_id)
                    JOIN 202_aff_networks USING(aff_network_id)
                    WHERE 2lp.user_id='" . $userId . "'
                    AND 2lp.aff_campaign_id" . $eq . "'" . $escapedAffCampaignId . "'
                    AND `landing_page_deleted`='0'
                    AND aff_campaign_deleted='0'
                    AND `aff_network_deleted`='0'
                    ORDER BY `aff_campaign_id`, `landing_page_nickname` ASC";
        } elseif ($type === 'advlandingpage') {
            $sql = "SELECT *
                    FROM `202_landing_pages`
                    WHERE `user_id`='" . $userId . "'
                    AND `landing_page_type`='1'
                    AND `landing_page_deleted`='0'
                    ORDER BY `landing_page_nickname` ASC";
        } else {
            $sql = "(SELECT landing_page_id, landing_page_nickname
                    FROM `202_landing_pages` AS 2lp
                    JOIN 202_aff_campaigns USING(aff_campaign_id)
                    JOIN 202_aff_networks USING(aff_network_id)
                    WHERE 2lp.user_id='" . $userId . "'
                    AND 2lp.aff_campaign_id" . $eq . "'" . $escapedAffCampaignId . "'
                    AND `landing_page_deleted`='0'
                    AND aff_campaign_deleted='0'
                    AND `aff_network_deleted`='0'
                    ORDER BY `aff_campaign_id`, `landing_page_nickname` ASC)
                    UNION
                    (SELECT landing_page_id, landing_page_nickname
                    FROM `202_landing_pages`
                    WHERE `user_id`='" . $userId . "'
                    AND `landing_page_type`='1'
                    AND `landing_page_deleted`='0'
                    ORDER BY `landing_page_nickname` ASC)";
        }

        $result = $db->query($sql);
        if ($result === false) {
            error_log('tracking202 dependent filter: failed to load landing page options: ' . $db->error);
            throw new \RuntimeException('Unable to load landing page options.');
        }

        $hiddenInput = '<input id="landing_page_style_type" type="hidden" name="landing_page_style_type" value="' . self::escapeAttribute($type) . '"/>';
        if ($result->num_rows === 0) {
            return $hiddenInput . self::renderBaseSelect('landing_page_id', ['<option value="0"> -- </option>']);
        }

        $options = ['<option value="0"> -- </option>'];
        while ($row = $result->fetch_assoc()) {
            $landingPageId = self::escapeAttribute((string) ($row['landing_page_id'] ?? ''));
            $landingPageNickname = self::escapeHtml((string) ($row['landing_page_nickname'] ?? ''));
            $selected = ((string) $selectedLandingPageId === (string) ($row['landing_page_id'] ?? ''))
                ? ' selected=""'
                : '';

            $options[] = sprintf('<option%s value="%s">%s</option>', $selected, $landingPageId, $landingPageNickname);
        }

        $onChange = $type === 'advlandingpage'
            ? 'load_adv_text_ad_id(this.value);'
            : 'load_text_ad_id($(\'#aff_campaign_id\').val());';

        return $hiddenInput
            . '<select class="form-control input-sm" name="landing_page_id" id="landing_page_id" onchange="' . self::escapeAttribute($onChange) . '">'
            . implode('', $options)
            . '</select>';
    }

    private static function renderAdPreview(int $textAdId): string
    {
        if ($textAdId <= 0) {
            return '';
        }

        $db = \DB::getInstance()->getConnection();
        $userId = $db->real_escape_string((string) ($_SESSION['user_id'] ?? 0));
        $escapedTextAdId = $db->real_escape_string((string) $textAdId);

        $sql = "SELECT *
                FROM `202_text_ads`
                WHERE `text_ad_id`='" . $escapedTextAdId . "'
                AND `user_id`='" . $userId . "'";
        $result = $db->query($sql);
        if ($result === false) {
            error_log('tracking202 dependent filter: failed to load text ad preview: ' . $db->error);
            throw new \RuntimeException('Unable to load text ad preview.');
        }

        if ($result->num_rows === 0) {
            return '<div class="panel panel-default" style="opacity:0.5; border-color: #3498db; margin-bottom:0px; width: 220px"><div class="panel-body" style="width: 220px"><span id="ad-preview-headline">aLuxury Cruise to Mars</span><br/><span id="ad-preview-body">Visit the Red Planet in style. Low-gravity fun for everyone!</span><br/><span id="ad-preview-url">www.example.com</span></div></div>';
        }

        $row = $result->fetch_assoc() ?: [];
        $headline = self::escapeHtml((string) ($row['text_ad_headline'] ?? ''));
        $description = self::escapeHtml((string) ($row['text_ad_description'] ?? ''));
        $displayUrl = self::escapeHtml((string) ($row['text_ad_display_url'] ?? ''));

        return '<div class="panel panel-default" style="border-color: #3498db; margin-bottom:0px; width:220px"><div class="panel-body" style="width: 220px"><span id="ad-preview-headline">' . $headline . '</span><br/><span id="ad-preview-body">' . $description . '</span><br/><span id="ad-preview-url">' . $displayUrl . '</span></div></div>';
    }

    private static function renderDisabledSelect(string $selectId, string $placeholder = ' -- '): string
    {
        return self::renderBaseSelect(
            $selectId,
            ['<option value="0">' . self::escapeHtml($placeholder) . '</option>'],
            true
        );
    }

    /**
     * @param list<string> $options
     */
    private static function renderBaseSelect(string $selectId, array $options, bool $disabled = false): string
    {
        $disabledAttribute = $disabled ? ' disabled=""' : '';

        return '<select class="form-control input-sm" name="' . self::escapeAttribute($selectId) . '" id="' . self::escapeAttribute($selectId) . '"' . $disabledAttribute . '>'
            . implode('', $options)
            . '</select>';
    }

    private static function escapeHtml(string $value): string
    {
        return htmlentities($value, ENT_QUOTES, 'UTF-8');
    }

    private static function escapeAttribute(string $value): string
    {
        return htmlentities($value, ENT_QUOTES, 'UTF-8');
    }
}
