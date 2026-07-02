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

if (!function_exists('p202ExtractCustomer')) {
    /**
     * Pull the LTV customer reference from a request array so the conversion
     * can be attributed to a persistent customer. Networks/carts pass their
     * stable id (merchant customer id, ESP hash, hashed email) as `cust`
     * (aliases: customer_ref, customer_id) with an optional `cust_type`.
     *
     * Returns [] when absent; otherwise keys customer_ref / customer_ref_type
     * ready to merge into the MysqlConversionRepository::record() payload.
     *
     * @param array<string,mixed> $source Typically $_GET.
     * @return array{customer_ref?: string, customer_ref_type?: string}
     */
    function p202ExtractCustomer(array $source): array
    {
        $ref = '';
        foreach (['cust', 'customer_ref', 'customer_id'] as $key) {
            if (array_key_exists($key, $source) && is_scalar($source[$key])) {
                $value = trim((string) $source[$key]);
                if ($value !== '') {
                    $ref = $value;
                    break;
                }
            }
        }
        if ($ref === '') {
            return [];
        }

        $out = ['customer_ref' => $ref];
        foreach (['cust_type', 'customer_ref_type'] as $key) {
            if (array_key_exists($key, $source) && is_scalar($source[$key])) {
                $type = trim((string) $source[$key]);
                if ($type !== '') {
                    $out['customer_ref_type'] = $type;
                    break;
                }
            }
        }

        return $out;
    }
}

if (!function_exists('p202ExtractItems')) {
    /**
     * Pull a single product line item from pixel/postback query params
     * (`sku` and/or `product_id`, optional `product_name`, `qty`,
     * `unit_price`). Pixels carry at most one product; multi-line orders go
     * through the authenticated V3 API. Returns [] when no product params
     * are present.
     *
     * @param array<string,mixed> $source Typically $_GET.
     * @return list<array<string,mixed>>
     */
    function p202ExtractItems(array $source): array
    {
        $sku = isset($source['sku']) && is_scalar($source['sku']) ? trim((string) $source['sku']) : '';
        $productId = isset($source['product_id']) && is_scalar($source['product_id']) ? trim((string) $source['product_id']) : '';
        if ($sku === '' && $productId === '') {
            return [];
        }

        $item = [];
        if ($productId !== '') {
            $item['external_product_id'] = $productId;
        }
        if ($sku !== '') {
            $item['sku'] = $sku;
        }
        if (isset($source['product_name']) && is_scalar($source['product_name']) && trim((string) $source['product_name']) !== '') {
            $item['name'] = trim((string) $source['product_name']);
        }
        if (isset($source['qty']) && is_numeric($source['qty'])) {
            $item['quantity'] = (float) $source['qty'];
        }
        if (isset($source['unit_price']) && is_numeric($source['unit_price'])) {
            $item['unit_price'] = (float) $source['unit_price'];
        }

        return [$item];
    }
}

if (!function_exists('p202MintPersonalizationCookieJs')) {
    /**
     * LTV landing-page personalization: mint a token for a visitor who
     * resolves to a known customer through an EXPLICIT signal (cust/c-param
     * alias from the beacon params, or a prior click already stamped with a
     * customer — never IP guessing) and return the JS statement that stores
     * it as a FIRST-PARTY cookie on the landing page's own domain (the same
     * createCookie() delivery record_simple/record_adv already use for the
     * subid — the tracker and the LP are usually different domains, so a
     * Set-Cookie header here would be invisible to the LP's JS).
     *
     * Returns '' when personalization is disabled, the visitor is unknown,
     * or anything fails — the beacon response must never break.
     *
     * @param array<string,mixed> $get The beacon's $_GET (carries c1-c4/cust).
     */
    function p202MintPersonalizationCookieJs(mysqli $db, int $userId, array $get, int $clickId): string
    {
        try {
            $conn = new \Prosper202\Database\Connection($db);
            $repo = new \Prosper202\Ltv\MysqlPersonalizationRepository($conn);
            if ($repo->allowedFields($userId) === []) {
                return '';
            }

            // The beacon request hits the tracking domain, so the request
            // cookies are the tracker's own: the prior click's subid.
            $cookieClickId = isset($_COOKIE['tracking202subid']) && is_numeric($_COOKIE['tracking202subid'])
                ? (int) $_COOKIE['tracking202subid']
                : 0;

            // The LP tells us via the beacon whether it already holds a token
            // (its cookie lives on the LP domain, invisible to this request).
            // If it does, only a fresh EXPLICIT identity signal justifies
            // re-minting — repeat pageviews within a visit reuse one token.
            $pageHasToken = isset($get['p13n_have']) && (string) $get['p13n_have'] === '1';

            $customerId = $repo->resolveVisitorCustomer($userId, $get, $cookieClickId, !$pageHasToken);
            if ($customerId === null) {
                return '';
            }

            $token = $repo->mint($userId, $customerId, $clickId, time());

            // 30-day LP-domain cookie; token is base64url so json_encode
            // yields a clean JS string literal.
            return 'createCookie(\'tracking202p13n\',' . json_encode($token) . ',30);';
        } catch (\Throwable $e) {
            error_log('p202MintPersonalizationCookieJs failed: ' . $e->getMessage());
            return '';
        }
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
     * @param array{customer_ref?: string, customer_ref_type?: string} $customer
     *        LTV customer identity (see p202ExtractCustomer); [] = unlinked.
     * @param list<array<string,mixed>> $items Product line items for the
     *        revenue ledger event (see p202ExtractItems); [] = none.
     * @return array{conv_id:int, duplicate:bool} conv_id is 0 only when the source
     *         click no longer exists (no orphan row is written).
     */
    function p202RecordConversion(
        mysqli $db,
        array $log,
        string $clickCpa,
        bool $usePixelPayout,
        string $clickPayout,
        string $transactionId = '',
        array $customer = [],
        array $items = []
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

        // LTV: customer identity + product line items ride the same
        // transactional write (customer upsert, ledger event, line items and
        // rollup bump commit together with the conversion).
        if (!empty($customer['customer_ref'])) {
            $data['customer_ref'] = (string) $customer['customer_ref'];
            if (!empty($customer['customer_ref_type'])) {
                $data['customer_ref_type'] = (string) $customer['customer_ref_type'];
            }
        }
        if ($items !== []) {
            $data['items'] = $items;
        }

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
