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
        header(sprintf('HTTP/1.1 %d %s', $code, $code === 404 ? 'Not Found' : 'Error'), true, $code);
        header('Content-Type: application/json');
        print_r(json_encode(['error' => true, 'code' => $code, 'msg' => $message]));
        die();
    }
}

if (!function_exists('p202FirePostbackUrl')) {
    function p202FirePostbackUrl(
        string $url,
        int $connectTimeout = 5,
        int $timeout = 10,
        string $userAgent = 'Mozilla/5.0 Postback202-Bot v1.8'
    ): void {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_exec($ch);
        curl_close($ch);
    }
}

if (!function_exists('p202ApplyConversionUpdate')) {
    function p202ApplyConversionUpdate(
        mysqli $db,
        string $clickId,
        string $clickCpa,
        bool $usePixelPayout = false,
        string $clickPayout = '',
        ?string $affCampaignId = null
    ): void {
        $sqlSet = $clickCpa !== ''
            ? "click_cpc='" . $clickCpa . "', click_lead='1', click_filtered='0'"
            : "click_lead='1', click_filtered='0'";

        $where = "click_id='" . $db->real_escape_string($clickId) . "'";
        if ($affCampaignId !== null) {
            $where .= " AND aff_campaign_id='" . $db->real_escape_string($affCampaignId) . "'";
        }

        $updateClicksSql = "\n\t\tUPDATE\n\t\t\t202_clicks\n\t\tSET\n\t\t\t" . $sqlSet;
        if ($usePixelPayout) {
            $updateClicksSql .= "\n\t\t\t, click_payout='" . $clickPayout . "'";
        }
        $updateClicksSql .= "\n\t\tWHERE\n\t\t\t" . $where;
        $db->query($updateClicksSql);

        $updateSpySql = "\n\t\tUPDATE\n\t\t\t202_clicks_spy\n\t\tSET\n\t\t\t" . $sqlSet;
        if ($usePixelPayout) {
            $updateSpySql .= "\n\t\t\t, click_payout='" . $clickPayout . "'";
        }
        $updateSpySql .= "\n\t\tWHERE\n\t\t\t" . $where;
        $db->query($updateSpySql);

        $de = new DataEngine();
        $de->setDirtyHour($clickId);
    }
}
