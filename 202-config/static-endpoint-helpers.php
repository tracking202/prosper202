<?php

declare(strict_types=1);

if (!function_exists('p202ResolveAdvertiserId')) {
    /**
     * @return int|null
     */
    function p202ResolveAdvertiserId(mysqli $db, int $campaignId)
    {
        if ($campaignId <= 0) {
            return null;
        }

        $stmt = $db->prepare('SELECT aff_network_id FROM 202_aff_campaigns WHERE aff_campaign_id = ? LIMIT 1');
        if ($stmt === false) {
            return null;
        }

        $stmt->bind_param('i', $campaignId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        if ($result) {
            $result->free();
        }
        $stmt->close();

        if (!is_array($row)) {
            return null;
        }

        $advertiserId = (int) ($row['aff_network_id'] ?? 0);

        return $advertiserId > 0 ? $advertiserId : null;
    }
}

if (!function_exists('p202RespondJsonError')) {
    function p202RespondJsonError(int $code, string $message): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        print_r(json_encode(['error' => true, 'code' => $code, 'msg' => $message]));
        die();
    }
}

const P202_POSTBACK_USER_AGENT = 'Mozilla/5.0 Postback202-Bot v1.8';

if (!function_exists('p202ApplyConversionUpdate')) {
    function p202ApplyConversionUpdate(
        mysqli $db,
        string $clickId,
        string $clickCpa,
        bool $usePixelPayout = false,
        string $clickPayout = '',
        ?string $affCampaignId = null
    ): void {
        $escapedCpa = $db->real_escape_string($clickCpa);
        $sqlSet = $escapedCpa !== ''
            ? "click_cpc='" . $escapedCpa . "', click_lead='1', click_filtered='0'"
            : "click_lead='1', click_filtered='0'";

        $where = "click_id='" . $db->real_escape_string($clickId) . "'";
        if ($affCampaignId !== null) {
            $where .= " AND aff_campaign_id='" . $db->real_escape_string($affCampaignId) . "'";
        }

        $escapedPayout = $db->real_escape_string($clickPayout);

        $updateClicksSql = "\n\t\tUPDATE\n\t\t\t202_clicks\n\t\tSET\n\t\t\t" . $sqlSet;
        if ($usePixelPayout) {
            $updateClicksSql .= "\n\t\t\t, click_payout='" . $escapedPayout . "'";
        }
        $updateClicksSql .= "\n\t\tWHERE\n\t\t\t" . $where;
        if (!$db->query($updateClicksSql)) {
            error_log('p202ApplyConversionUpdate: failed to update 202_clicks: ' . $db->error);
        }

        $updateSpySql = "\n\t\tUPDATE\n\t\t\t202_clicks_spy\n\t\tSET\n\t\t\t" . $sqlSet;
        if ($usePixelPayout) {
            $updateSpySql .= "\n\t\t\t, click_payout='" . $escapedPayout . "'";
        }
        $updateSpySql .= "\n\t\tWHERE\n\t\t\t" . $where;
        if (!$db->query($updateSpySql)) {
            error_log('p202ApplyConversionUpdate: failed to update 202_clicks_spy: ' . $db->error);
        }

        $de = new DataEngine();
        $de->setDirtyHour($clickId);
    }
}
