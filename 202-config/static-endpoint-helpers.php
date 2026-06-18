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

        // @phpstan-ignore-next-line static endpoint uses raw mysqli; no Connection instance available
        $stmt->bind_param('i', $campaignId);
        // @phpstan-ignore-next-line static endpoint uses raw mysqli; no Connection instance available
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
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
        echo json_encode(['error' => true, 'code' => $code, 'msg' => $message]);
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
        ?string $affCampaignId = null,
        bool $deferDirtyHour = false
    ): bool {
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
        $clicksUpdateOk = true;
        if (!$db->query($updateClicksSql)) {
            $clicksUpdateOk = false;
            try {
                error_log('p202ApplyConversionUpdate: failed to update 202_clicks: ' . $db->error);
            } catch (\Error $e) {
                error_log('p202ApplyConversionUpdate: failed to update 202_clicks (error inaccessible)');
            }
        }

        $updateSpySql = "\n\t\tUPDATE\n\t\t\t202_clicks_spy\n\t\tSET\n\t\t\t" . $sqlSet;
        if ($usePixelPayout) {
            $updateSpySql .= "\n\t\t\t, click_payout='" . $escapedPayout . "'";
        }
        $updateSpySql .= "\n\t\tWHERE\n\t\t\t" . $where;
        if (!$db->query($updateSpySql)) {
            try {
                error_log('p202ApplyConversionUpdate: failed to update 202_clicks_spy: ' . $db->error);
            } catch (\Error $e) {
                error_log('p202ApplyConversionUpdate: failed to update 202_clicks_spy (error inaccessible)');
            }
        }

        // Cache invalidation (memcache write). Transactional callers defer this
        // until AFTER commit so the click-row lock is not held across cache I/O.
        if (!$deferDirtyHour) {
            $de = new DataEngine();
            $de->setDirtyHour($clickId);
        }

        // Report whether the primary 202_clicks update succeeded so transactional
        // callers (p202RecordConversion) can roll back instead of committing a
        // conversion whose click never got flagged. The 202_clicks_spy update is a
        // denormalised real-time copy: its failure is logged but not fatal.
        return $clicksUpdateOk;
    }
}

if (!function_exists('p202ExtractTransactionId')) {
    /**
     * Pull a network-supplied transaction/order id from a request array so a
     * conversion can be recorded idempotently. Returns '' when none is present,
     * which means "no idempotency key available" (the conversion is still
     * recorded, it just cannot be de-duplicated on retry).
     *
     * @param array<string,mixed> $source Typically $_GET.
     */
    function p202ExtractTransactionId(array $source): string
    {
        foreach (['txid', 'transaction_id', 'transactionid', 'order_id', 'orderid', 'oid'] as $key) {
            if (array_key_exists($key, $source) && is_scalar($source[$key])) {
                $value = trim((string) $source[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }
}

if (!function_exists('p202RecordConversion')) {
    /**
     * Record a conversion atomically and idempotently for the legacy static
     * postback/pixel endpoints (gpx/gpb/upx).
     *
     * Thin adapter over the canonical writer MysqlConversionRepository::record():
     * the click is locked (SELECT ... FOR UPDATE), the conversion is de-duplicated
     * on a non-empty transaction id (idempotent replay), and the conversion_logs
     * insert plus the legacy click-side update (lead flag / cpa / payout / spy
     * table) commit or roll back together. The dirty-hour cache write happens
     * after commit, only for a newly recorded conversion.
     *
     * @param array<string,int|float|string> $log Conversion_logs column values.
     *        Required keys: click_id, campaign_id, user_id, click_time, conv_time,
     *        time_difference, ip, pixel_type, user_agent, click_payout.
     * @return array{conv_id:int, duplicate:bool} conv_id is 0 only when the source
     *         click no longer exists (no orphan row is written).
     */
    function p202RecordConversion(
        mysqli $db,
        array $log,
        string $clickCpa,
        bool $usePixelPayout,
        string $clickPayout,
        string $transactionId = ''
    ): array {
        $clickId = (int) ($log['click_id'] ?? 0);
        if ($clickId <= 0) {
            throw new \InvalidArgumentException('p202RecordConversion: click_id must be a positive integer');
        }

        // Delegate the transactional lock + idempotency + insert to the single
        // canonical conversion writer (MysqlConversionRepository). The legacy
        // click-side update (lead flag, cpa, spy table) runs inside that same
        // transaction via the callback, so the click flag and the audit row commit
        // or roll back together. Dirty-hour cache invalidation is deferred to after
        // commit so the click-row lock is not held across memcache I/O.
        $conn = new \Prosper202\Database\Connection($db);
        $repo = new \Prosper202\Conversion\MysqlConversionRepository($conn);

        $data = [
            'click_id'        => $clickId,
            'transaction_id'  => trim($transactionId),
            'campaign_id'     => (int) ($log['campaign_id'] ?? 0),
            'payout'          => (float) ($log['click_payout'] ?? 0),
            'click_time'      => (int) ($log['click_time'] ?? 0),
            'conv_time'       => (int) ($log['conv_time'] ?? time()),
            'time_difference' => (string) ($log['time_difference'] ?? ''),
            'ip'              => (string) ($log['ip'] ?? ''),
            'pixel_type'      => (int) ($log['pixel_type'] ?? 0),
            'user_agent'      => (string) ($log['user_agent'] ?? ''),
        ];

        $result = $repo->record(
            (int) ($log['user_id'] ?? 0),
            $data,
            function (int $lockedClickId, float $payout) use ($db, $clickCpa, $usePixelPayout, $clickPayout): void {
                if (!p202ApplyConversionUpdate($db, (string) $lockedClickId, $clickCpa, $usePixelPayout, $clickPayout, null, true)) {
                    throw new \RuntimeException('p202RecordConversion: click update failed for click ' . $lockedClickId);
                }
            }
        );

        // Invalidate the hour cache only when a NEW conversion was recorded — not on
        // an idempotent duplicate, and not when the click was missing.
        if ($result['clickFound'] && !$result['duplicate']) {
            $de = new DataEngine();
            $de->setDirtyHour((string) $clickId);
        }

        return ['conv_id' => $result['convId'], 'duplicate' => $result['duplicate']];
    }
}
