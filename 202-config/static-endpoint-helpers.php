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
        ?string $affCampaignId = null
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

        $de = new DataEngine();
        $de->setDirtyHour($clickId);

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

if (!function_exists('p202SafeDbError')) {
    /**
     * Read mysqli::$error without risking a fatal Error. On PHP 8.4 accessing
     * ->error on a closed/never-opened connection throws "object is already
     * closed"; we never want diagnostics to crash the caller.
     */
    function p202SafeDbError(mysqli $db): string
    {
        try {
            return (string) $db->error;
        } catch (\Error $e) {
            return '(error unavailable)';
        }
    }
}

if (!function_exists('p202RecordConversion')) {
    /**
     * Record a conversion atomically and idempotently for the legacy static
     * postback/pixel endpoints (gpx/gpb/upx).
     *
     * Everything runs in a single transaction:
     *   1. the source click row is locked with SELECT ... FOR UPDATE so concurrent
     *      or retried postbacks for the same click serialise here instead of both
     *      recording a conversion (TOCTOU race / double count);
     *   2. when $transactionId is a non-empty network order id that was already
     *      recorded for this click, the existing conv_id is returned and nothing
     *      is written (idempotent replay);
     *   3. otherwise the click-side update (lead flag / payout) and the
     *      202_conversion_logs insert are applied together — if either fails the
     *      whole thing is rolled back, so a click is never flagged converted
     *      without an audit row and vice versa.
     *
     * Values in $log are raw (unescaped); this function escapes them.
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

        $transactionId = trim($transactionId);

        $db->begin_transaction();
        try {
            // Lock the source click row so concurrent/retried postbacks for the
            // same click serialise here instead of both recording a conversion.
            $lockResult = $db->query('SELECT click_id FROM 202_clicks WHERE click_id = ' . $clickId . ' LIMIT 1 FOR UPDATE');
            if ($lockResult === false) {
                throw new \RuntimeException('p202RecordConversion: failed to lock click row: ' . p202SafeDbError($db));
            }
            $clickExists = $lockResult->fetch_assoc();
            $lockResult->free();
            if (!$clickExists) {
                // The click is gone; do not insert an orphan conversion.
                $db->rollback();
                return ['conv_id' => 0, 'duplicate' => false];
            }

            // Idempotency: a non-empty network order id already recorded for this
            // click means this is a replay/retry — return the existing conversion.
            if ($transactionId !== '') {
                $txEsc = $db->real_escape_string($transactionId);
                $dupSql = "SELECT conv_id FROM 202_conversion_logs"
                    . " WHERE click_id = " . $clickId
                    . " AND transaction_id = '" . $txEsc . "'"
                    . " AND deleted = 0 LIMIT 1";
                $dupResult = $db->query($dupSql);
                if ($dupResult === false) {
                    throw new \RuntimeException('p202RecordConversion: idempotency lookup failed: ' . p202SafeDbError($db));
                }
                $dupRow = $dupResult->fetch_assoc();
                $dupResult->free();
                if ($dupRow) {
                    $db->commit();
                    return ['conv_id' => (int) $dupRow['conv_id'], 'duplicate' => true];
                }
            }

            // Apply the click-side conversion update (lead flag, cpa, payout).
            if (!p202ApplyConversionUpdate($db, (string) $clickId, $clickCpa, $usePixelPayout, $clickPayout)) {
                throw new \RuntimeException('p202RecordConversion: click update failed for click ' . $clickId);
            }

            // Empty transaction ids are stored as NULL (not '') so the UNIQUE key on
            // (click_id, transaction_id) still allows a click to convert more than
            // once when no network order id is supplied.
            $transactionSql = $transactionId !== ''
                ? "'" . $db->real_escape_string($transactionId) . "'"
                : 'NULL';

            $insertSql = "INSERT INTO 202_conversion_logs SET"
                . " click_id = " . $clickId . ","
                . " transaction_id = " . $transactionSql . ","
                . " campaign_id = '" . $db->real_escape_string((string) ($log['campaign_id'] ?? '0')) . "',"
                . " click_payout = '" . $db->real_escape_string((string) ($log['click_payout'] ?? '0')) . "',"
                . " user_id = '" . $db->real_escape_string((string) ($log['user_id'] ?? '0')) . "',"
                . " click_time = '" . $db->real_escape_string((string) ($log['click_time'] ?? '0')) . "',"
                . " conv_time = '" . $db->real_escape_string((string) ($log['conv_time'] ?? '0')) . "',"
                . " time_difference = '" . $db->real_escape_string((string) ($log['time_difference'] ?? '')) . "',"
                . " ip = '" . $db->real_escape_string((string) ($log['ip'] ?? '')) . "',"
                . " pixel_type = '" . $db->real_escape_string((string) ($log['pixel_type'] ?? '0')) . "',"
                . " user_agent = '" . $db->real_escape_string((string) ($log['user_agent'] ?? '')) . "',"
                . " deleted = 0";

            if (!$db->query($insertSql)) {
                throw new \RuntimeException('p202RecordConversion: conversion_logs insert failed: ' . p202SafeDbError($db));
            }
            $convId = (int) $db->insert_id;

            $db->commit();

            return ['conv_id' => $convId, 'duplicate' => false];
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }
}
