<?php

declare(strict_types=1);

if (!function_exists('p202RespondJsonError')) {
    function p202RespondJsonError(int $code, string $message): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['error' => true, 'code' => $code, 'msg' => $message]);
        die();
    }
}

const P202_POSTBACK_USER_AGENT = \Prosper202\Conversion\TrafficSourcePixels::POSTBACK_USER_AGENT;

if (!function_exists('p202ApplyConversionClickSide')) {
    /**
     * The click-side part of a conversion that is not its value: a CPA
     * campaign's cost moves onto the click (click_cpc = the tracker's CPA),
     * and a converting click is never left filtered.
     *
     * This used to set click_lead and click_payout as well. Those are now
     * derived from the click's ledger rows by MysqlConversionLedger::recompute(),
     * which runs in the same transaction right after this; writing them here
     * too would let the cache disagree with its rows (ClickValueWritersTest).
     *
     * @return bool Whether the 202_clicks update ran. The 202_clicks_spy copy
     *         is best-effort (its row may already have aged out), so its
     *         failure is logged, not returned.
     */
    function p202ApplyConversionClickSide(mysqli $db, int $clickId, string $clickCpa): bool
    {
        $cpa = trim($clickCpa);
        $setCost = $cpa !== '' && is_numeric($cpa);
        $conn = new \Prosper202\Database\Connection($db);

        $ok = true;
        foreach (['202_clicks', '202_clicks_spy'] as $table) {
            $sql = $table === '202_clicks'
                ? ($setCost
                    ? 'UPDATE 202_clicks SET click_cpc = ?, click_filtered = 0 WHERE click_id = ?'
                    : 'UPDATE 202_clicks SET click_filtered = 0 WHERE click_id = ?')
                : ($setCost
                    ? 'UPDATE 202_clicks_spy SET click_cpc = ?, click_filtered = 0 WHERE click_id = ?'
                    : 'UPDATE 202_clicks_spy SET click_filtered = 0 WHERE click_id = ?');
            try {
                $stmt = $conn->prepareWrite($sql);
                if ($setCost) {
                    $conn->bind($stmt, 'si', [$cpa, $clickId]);
                } else {
                    $conn->bind($stmt, 'i', [$clickId]);
                }
                $conn->executeUpdate($stmt);
            } catch (\Prosper202\Database\Exceptions\QueryException $e) {
                error_log('p202ApplyConversionClickSide: the ' . $table . ' update failed: ' . $e->getMessage());
                if ($table === '202_clicks') {
                    $ok = false;
                }
            }
        }

        return $ok;
    }
}

if (!function_exists('p202ExtractReversal')) {
    /**
     * Whether a postback reverses an earlier conversion, and the network's
     * reference for the reversal.
     *
     * `status=reversed` marks one; the transaction id names the conversion it
     * reverses. A negative `amount` with a transaction id already on file is
     * a reversal too, decided by the writer, which is the one place that can
     * see whether the id is on file. `reversal_id` is the network's own id
     * for the reversal; without one, a sale can be reversed once.
     *
     * @param array<string,mixed> $source Typically $_GET.
     * @return array{reversal: bool, reversal_ref: string}
     */
    function p202ExtractReversal(array $source): array
    {
        $status = isset($source['status']) && is_scalar($source['status']) ? strtolower(trim((string) $source['status'])) : '';
        $ref = isset($source['reversal_id']) && is_scalar($source['reversal_id']) ? trim((string) $source['reversal_id']) : '';

        return ['reversal' => $status === 'reversed', 'reversal_ref' => $ref];
    }
}

if (!function_exists('p202FireTrafficSourcePixels')) {
    /**
     * Tell the click's traffic source about a conversion: the one sender for
     * every 202_ppc_account_pixels pixel, used by gpb.php and upx.php and by
     * the goal notifier (Prosper202\Goals\TrafficSourceNotifier). The rules
     * live in Prosper202\Conversion\TrafficSourcePixels::fire(), which this
     * delegates to so the paths that never load this file send the same.
     *
     * @param array<string, scalar> $tokens replaceTokens() tokens; the caller
     *        fills `transactionid` with the conversion's transaction id, or
     *        its dedupe key when the network sent none
     * @param (callable(string): bool)|null $fetch Performs a type-4 GET and
     *        says whether it succeeded; injected by tests.
     * @return array{markup: string, types: list<int>, server_calls: int, server_failures: int, browser_skipped: int, server_skipped: int}
     */
    function p202FireTrafficSourcePixels(mysqli $db, int $ppcAccountId, array $tokens, ?callable $fetch = null): array
    {
        return \Prosper202\Conversion\TrafficSourcePixels::fire(new \Prosper202\Database\Connection($db), $ppcAccountId, $tokens, $fetch);
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
     * (alias: customer_ref) with an optional `cust_type`. `customer_id` is
     * deliberately not accepted — see the note in the implementation.
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
        // Deliberately NOT 'customer_id': on public pixels that name would be
        // ambiguous with the authenticated API's internal numeric id (which
        // carries tenant-ownership checks). External references only here.
        foreach (['cust', 'customer_ref'] as $key) {
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
            $quantity = (float) $source['qty'];
            // A non-positive quantity is malformed and would make
            // insertLineItems() throw INSIDE the conversion transaction, so
            // the static endpoints would lose the CORE conversion just because
            // optional product metadata was bad. Drop the line item instead —
            // the conversion still records. Coercing qty to 1 would invent data
            // the caller never sent (CLAUDE.md #4), so we discard the item.
            if ($quantity <= 0) {
                return [];
            }
            $item['quantity'] = $quantity;
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

            // The beacon request hits the tracking domain, so the request
            // cookies are the tracker's own: the prior click's subid.
            $cookieClickId = isset($_COOKIE['tracking202subid']) && is_numeric($_COOKIE['tracking202subid'])
                ? (int) $_COOKIE['tracking202subid']
                : 0;

            // Engagement (ABM): whenever the visitor resolves to a known
            // customer — through any explicit signal — stamp this pageview's
            // click so browsing behavior becomes per-customer queryable, and
            // touch the customer's activity recency. Stamping is independent
            // of whether a new token gets minted below.
            $engagementCustomerId = $repo->resolveVisitorCustomer($userId, $get, $cookieClickId, true);
            if ($engagementCustomerId !== null && $clickId > 0) {
                $customers = new \Prosper202\Ltv\MysqlCustomerRepository($conn);
                $customers->stampClickCustomer($clickId, $engagementCustomerId);
                $touch = $conn->prepareWrite(
                    'UPDATE 202_customers SET last_activity_time = GREATEST(last_activity_time, ?), updated_at = ?
                     WHERE customer_id = ? AND user_id = ?'
                );
                $now = time();
                $conn->bind($touch, 'iiii', [$now, $now, $engagementCustomerId, $userId]);
                $conn->executeUpdate($touch);
            }

            // An empty allowlist only turns PERSONALIZATION off — the ABM
            // stamping above must still run, so this gate sits between them.
            if ($repo->allowedFields($userId) === []) {
                return '';
            }

            // The LP reports its token cookie via the beacon (the cookie
            // lives on the LP domain, invisible to this request). While the
            // page holds a USABLE token, only a fresh EXPLICIT identity
            // signal justifies re-minting — repeat pageviews within a visit
            // reuse one token. A dead token (expired before first use, or
            // past its replay window) must NOT suppress reminting, or a
            // cookie-recognized visitor loses personalization until the
            // 30-day LP cookie drains.
            //
            // Wire values (the beacon URL is a GET, so it must never carry
            // the bearer token itself — server/proxy/CDN logs capture query
            // strings): current snippets send 'h:' + sha256(token) from the
            // companion hash cookie; '1' is the legacy bare presence flag
            // (unknown state — treated as usable, the conservative
            // pre-validation behavior); anything else is a raw token from a
            // cached pre-digest snippet, still validated directly. ':' is
            // outside the token's base64url charset, so the digest form can
            // never be mistaken for a raw token.
            $pageToken = isset($get['p13n_have']) ? trim((string) $get['p13n_have']) : '';
            $pageHasToken = $pageToken !== '' && $pageToken !== '0';
            if ($pageHasToken && $pageToken !== '1') {
                $pageHasToken = str_starts_with($pageToken, 'h:')
                    ? $repo->tokenHashIsUsable(substr($pageToken, 2), time())
                    : $repo->tokenIsUsable($pageToken, time());
            }

            $customerId = $pageHasToken
                ? $repo->resolveVisitorCustomer($userId, $get, $cookieClickId, false)
                : $engagementCustomerId;
            if ($customerId === null) {
                return '';
            }

            $token = $repo->mint($userId, $customerId, $clickId, time());

            // Two 30-day LP-domain cookies: the bearer token itself (read by
            // the LP's POST-only p13n/redeem and event calls) and its 'h:'
            // sha256 digest, which is what the beacon reports back in the
            // record.php GET URL — the bearer must never appear in a query
            // string that request logs capture. Token is base64url, digest
            // is hex, so json_encode yields clean JS string literals.
            return 'createCookie(\'tracking202p13n\',' . json_encode($token) . ',30);'
                . 'createCookie(\'tracking202p13nh\',' . json_encode('h:' . hash('sha256', $token)) . ',30);';
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
     * on its dedupe key (idempotent replay), and the ledger row, the click-side
     * update (CPA cost, filtered flag) and the recompute of the click's value
     * from its rows commit or roll back together. The dirty-hour cache write
     * happens after commit, only for a newly recorded conversion.
     *
     * @param array<string,int|float|string|bool> $log Conversion_logs column values.
     *        Required keys: click_id, campaign_id, user_id, click_time, conv_time,
     *        time_difference, ip, pixel_type, user_agent. Optional: source (a
     *        ConversionSource value; defaults from pixel_type), reversal and
     *        reversal_ref (see p202ExtractReversal), once_per_click.
     *        click_payout is read only when $usePixelPayout: without an
     *        explicit amount the writer applies the campaign's default.
     * @param array{customer_ref?: string, customer_ref_type?: string} $customer
     *        LTV customer identity (see p202ExtractCustomer); [] = unlinked.
     * @param list<array<string,mixed>> $items Product line items for the
     *        revenue ledger event (see p202ExtractItems); [] = none.
     * @return array{conv_id:int, duplicate:bool, transaction_id:string, dedupe_key:string, payout:string, reverses_conv_id:int}
     *         conv_id is 0 when the source click no longer exists (no orphan
     *         row is written) or an id-less hit found the click already
     *         converted (duplicate). dedupe_key is the row's ledger key, which
     *         the traffic-source pixel sends in place of a transaction id the
     *         network never gave. reverses_conv_id is the sale a reversal row
     *         nets against, 0 for every other row: a reversal is not a new
     *         conversion, so the caller must not announce it as one.
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
            'click_time'      => (int) ($log['click_time'] ?? 0),
            'conv_time'       => (int) ($log['conv_time'] ?? time()),
            'time_difference' => (string) ($log['time_difference'] ?? ''),
            'ip'              => (string) ($log['ip'] ?? ''),
            'pixel_type'      => (int) ($log['pixel_type'] ?? 0),
            'user_agent'      => (string) ($log['user_agent'] ?? ''),
        ];
        if ($usePixelPayout) {
            // An explicit amount. Without one the writer applies the campaign's
            // default — the click's current value in replace mode, the
            // campaign payout in accumulate mode — rather than trusting a
            // cached click_payout that may be a running total.
            $data['payout'] = $clickPayout !== '' ? $clickPayout : (string) ($log['click_payout'] ?? '0');
        }
        $data['source'] = isset($log['source']) && $log['source'] !== ''
            ? (string) $log['source']
            : match ((int) ($log['pixel_type'] ?? 0)) {
                1 => \Prosper202\Conversion\Ledger\ConversionSource::PIXEL->value,
                2 => \Prosper202\Conversion\Ledger\ConversionSource::POSTBACK->value,
                3 => \Prosper202\Conversion\Ledger\ConversionSource::UNIVERSAL_PIXEL->value,
                default => \Prosper202\Conversion\Ledger\ConversionSource::API->value,
            };
        if (!empty($log['reversal'])) {
            $data['reversal'] = true;
        }
        if (isset($log['reversal_ref']) && (string) $log['reversal_ref'] !== '') {
            $data['reversal_ref'] = (string) $log['reversal_ref'];
        }
        if (isset($log['event_name']) && (string) $log['event_name'] !== '') {
            // The `event=` of a hit on a campaign without goals: kept on the
            // row for the breakdown, and nothing else (p202RecordWebEvent).
            $data['event_name'] = (string) $log['event_name'];
        }
        if (!empty($log['once_per_click'])) {
            // The one-conversion-per-click rule for id-less hits, enforced by
            // the writer under its click lock (see MysqlConversionRepository::record).
            $data['once_per_click'] = true;
        }

        // LTV: customer identity + product line items ride the same
        // transactional write (customer upsert, ledger event, line items and
        // rollup bump commit together with the conversion).
        if (!empty($customer['customer_id'])) {
            $data['customer_id'] = (int) $customer['customer_id'];
        }
        if (!empty($customer['customer_ref'])) {
            $data['customer_ref'] = (string) $customer['customer_ref'];
            if (!empty($customer['customer_ref_type'])) {
                $data['customer_ref_type'] = (string) $customer['customer_ref_type'];
            }
        }
        if (!empty($customer['customer_crm']) && is_array($customer['customer_crm'])) {
            // CRM fields (name/email/company/...) are applied on customer
            // CREATE by the writer; dropping them here would silently lose
            // caller-supplied identity data.
            $data['customer_crm'] = $customer['customer_crm'];
        }
        if ($items !== []) {
            $data['items'] = $items;
        }

        $result = $repo->record(
            (int) ($log['user_id'] ?? 0),
            $data,
            function (int $lockedClickId, float $payout) use ($db, $clickCpa): void {
                if (!p202ApplyConversionClickSide($db, $lockedClickId, $clickCpa)) {
                    throw new \RuntimeException('p202RecordConversion: click update failed for click ' . $lockedClickId);
                }
            }
        );

        // The report row (202_dataengine) is re-rolled by the repository's
        // record() after its commit, for this path and every other writer
        // alike, as the conversion.recorded bridge event is.

        return [
            'conv_id' => $result['convId'],
            'duplicate' => $result['duplicate'],
            'transaction_id' => trim($transactionId),
            'dedupe_key' => (string) ($result['dedupeKey'] ?? ''),
            'payout' => isset($result['payout']) ? (string) $result['payout'] : '',
            'reverses_conv_id' => (int) ($result['reversesConvId'] ?? 0),
        ];
    }
}

if (!function_exists('p202ParseClickId')) {
    /**
     * A click id from untrusted input (a cookie, a query parameter, a
     * network's tracking code), or null when the value is not exactly one.
     *
     * Digits only, no sign, no leading zero, within bigint. `is_numeric()`
     * followed by an int cast accepted "123.9", "1e3" and " 42" and silently
     * turned each into a DIFFERENT click, so a corrupted cookie could credit a
     * conversion to a real click nobody meant (the same shape as CLAUDE.md
     * error pattern #18). The round trip pins the value: what is returned
     * prints back as exactly what was given.
     */
    function p202ParseClickId(mixed $value): ?int
    {
        return \Prosper202\Click\ClickId::parse($value);
    }
}

if (!function_exists('p202LinkConversionIdentity')) {
    /**
     * Link the converting click to the signed customer id the conversion
     * request carries (cust + cust_sig, plan §6.2): the join that makes a
     * purchase on a phone and an earlier click on a laptop one journey.
     *
     * Runs after the conversion is recorded, in its own transaction, and
     * never fails the request: identity is an enrichment, and a sender that
     * got a 500 here would retry a conversion that was already stored. An
     * unsigned or wrongly signed id links nothing (ClickIdentity logs only
     * real failures). Returns the click's visitor key, or null.
     *
     * @param array<string, mixed> $get
     */
    function p202LinkConversionIdentity(mysqli $db, int $clickId, array $get): ?int
    {
        $identity = \Prosper202\Identity\ClickIdentity::customerOnly($get);
        if ($identity->isEmpty() || $clickId <= 0) {
            return null;
        }

        return $identity->attachToStoredClick(\Prosper202\Repository\LookupRepositoryFactory::connection($db), $clickId);
    }
}

if (!function_exists('p202ClientIp')) {
    /**
     * The client address to store on a conversion row: one valid IP, or ''.
     *
     * X-Forwarded-For is a comma-separated chain behind more than one proxy,
     * and with IPv6 hops it runs well past the 45 characters
     * 202_conversion_logs.ip holds. Passed through as it was, the INSERT
     * failed under strict sql_mode and rolled the conversion back — a 500
     * from pb.php on every retry, silence from px.php. The leftmost hop is
     * the client the first proxy saw; it is taken only when it parses as an
     * address, otherwise REMOTE_ADDR is, otherwise nothing. The header is
     * attacker-supplied, so the value is for display only and never a
     * security decision (CLAUDE.md error pattern #16).
     *
     * @param array<string,mixed> $server $_SERVER, or a stand-in in tests.
     */
    function p202ClientIp(array $server): string
    {
        $forwarded = (string) ($server['HTTP_X_FORWARDED_FOR'] ?? '');
        $first = trim(explode(',', $forwarded, 2)[0]);
        if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP) !== false) {
            return $first;
        }
        $remote = trim((string) ($server['REMOTE_ADDR'] ?? ''));
        if ($remote !== '' && filter_var($remote, FILTER_VALIDATE_IP) !== false) {
            return $remote;
        }
        return '';
    }
}

if (!function_exists('p202ClickIdFromRequest')) {
    /**
     * Which click a pixel request names, from the places gpx.php and upx.php
     * look, in their order: the subid (or sid) parameter, the campaign's own
     * cookie (tracking202subid_a_<cid>), the general cookie.
     *
     * Each value is untrusted and must be an exact positive integer
     * (p202ParseClickId): "123.9" is not click 123. An empty value is absent
     * and the next place is tried, which is what an unfilled [[subid]] in a
     * template has always done. A value that is PRESENT and not a click id
     * is refused outright: the caller must not fall back to the IP lookup,
     * which behind a NAT would credit whichever click last came from that
     * address to a request whose own identity was garbage (the rule px.php
     * already applies, CLAUDE.md error pattern #5).
     *
     * @param array<string, mixed> $get
     * @param array<string, mixed> $cookies
     * @return array{click_id: int|null, malformed: string|null}
     *         click_id null with malformed null: nothing named a click, the
     *         IP fallback may run. malformed set: the name of the value that
     *         was present and unreadable; record nothing.
     */
    function p202ClickIdFromRequest(array $get, array $cookies, int $campaignId): array
    {
        $places = [
            ['subid', $get['subid'] ?? null],
            ['sid', $get['sid'] ?? null],
        ];
        if ($campaignId > 0) {
            $places[] = ['tracking202subid_a_' . $campaignId, $cookies['tracking202subid_a_' . $campaignId] ?? null];
        }
        $places[] = ['tracking202subid', $cookies['tracking202subid'] ?? null];

        foreach ($places as [$name, $value]) {
            if ($value === null || $value === '') {
                continue;
            }
            $clickId = p202ParseClickId(is_string($value) ? $value : (is_int($value) ? $value : null));
            if ($clickId === null) {
                return ['click_id' => null, 'malformed' => $name];
            }

            return ['click_id' => $clickId, 'malformed' => null];
        }

        return ['click_id' => null, 'malformed' => null];
    }
}

if (!function_exists('p202LegacyConversionGate')) {
    /**
     * Whether a hit on a legacy endpoint (px.php, pb.php, cb202.php) records a
     * conversion row. Returns null to record, or the reason not to.
     *
     * Without a transaction id a retry cannot be told apart from a repeat
     * purchase, so a click that is already a lead records nothing more — the
     * same one-conversion-per-click gate gpx.php applies (gpx.php:94). A
     * transaction id lifts the gate: a new id is a repeat purchase, and a
     * replayed one is de-duplicated by the writer.
     */
    function p202LegacyConversionGate(bool $clickLead, string $transactionId): ?string
    {
        if ($clickLead && trim($transactionId) === '') {
            return 'already_lead';
        }
        return null;
    }
}

if (!function_exists('p202TimeDifference')) {
    /**
     * The click-to-conversion gap in the wording the legacy endpoints store
     * in 202_conversion_logs.time_difference ("N days, H hours, M min and S
     * sec"); the V3 API stores the gap in seconds instead.
     */
    function p202TimeDifference(int $clickTime, int $convTime): string
    {
        $from = new \DateTime('@' . max(0, $clickTime));
        $to = new \DateTime('@' . max(0, $convTime));
        $diff = $from->diff($to);
        return $diff->d . ' days, ' . $diff->h . ' hours, ' . $diff->i . ' min and ' . $diff->s . ' sec';
    }
}

if (!function_exists('p202RecordLegacyConversion')) {
    /**
     * Record a conversion for one of the legacy endpoints — the per-campaign
     * pixel (px.php), the per-campaign postback (pb.php) and the ClickBank
     * INS receiver (cb202.php).
     *
     * Until now these three only flagged the click (click_lead, cpa, payout)
     * and wrote no 202_conversion_logs row, so a payout they produced could
     * not be listed, broken down, attributed or reversed. They now go through
     * the same writer as gpx/gpb/upx and the V3 API: the click is locked, the
     * row and the click flag commit together, and a transaction id
     * de-duplicates a replay.
     *
     * The click is loaded here, once, with everything the writer needs, and
     * the two ownership checks a caller may ask for are applied before any
     * write: `user_id` (px: the click must belong to the campaign's owner)
     * and `campaign_id` (pb: the click must belong to the campaign the
     * postback names). Both were implicit or absent before.
     *
     * @param array{
     *     user_id?: int, campaign_id?: int, transaction_id?: string,
     *     use_pixel_payout?: bool, payout?: string, ip?: string, user_agent?: string,
     *     source?: string, reversal?: bool, reversal_ref?: string, event_name?: string|null
     * } $opts source is the ledger source the row is recorded under
     *     (legacy_pixel for px and pb, clickbank for cb202); event_name is
     *     a campaign-without-goals hit's `event=`, kept on the row.
     * @return array{recorded: bool, duplicate: bool, conv_id: int, reason: string}
     *         reason is '' when recorded; otherwise one of unknown_click,
     *         foreign_click, campaign_mismatch, already_lead, duplicate.
     */
    function p202RecordLegacyConversion(mysqli $db, int $clickId, int $pixelType, array $opts = []): array
    {
        if ($clickId <= 0) {
            throw new \InvalidArgumentException('p202RecordLegacyConversion: click_id must be a positive integer');
        }

        // The checked wrapper: prepare, bind, execute and get_result are each
        // verified inside Connection (CLAUDE.md #1), and a failure throws
        // rather than reading as "no such click".
        $conn = new \Prosper202\Database\Connection($db);
        $stmt = $conn->prepareRead(
            'SELECT c.user_id, c.aff_campaign_id, c.click_lead, c.click_time, c.click_payout, t.click_cpa
             FROM 202_clicks AS c
             LEFT JOIN 202_cpa_trackers AS cp ON cp.click_id = c.click_id
             LEFT JOIN 202_trackers AS t ON t.tracker_id_public = cp.tracker_id_public
             WHERE c.click_id = ?
             LIMIT 1'
        );
        $conn->bind($stmt, 'i', [$clickId]);
        $click = $conn->fetchOne($stmt);

        $none = ['recorded' => false, 'duplicate' => false, 'conv_id' => 0];
        if (!is_array($click)) {
            return $none + ['reason' => 'unknown_click'];
        }
        if (isset($opts['user_id']) && (int) $click['user_id'] !== (int) $opts['user_id']) {
            return $none + ['reason' => 'foreign_click'];
        }
        if (isset($opts['campaign_id']) && (int) $click['aff_campaign_id'] !== (int) $opts['campaign_id']) {
            return $none + ['reason' => 'campaign_mismatch'];
        }

        // Fast path only: the same rule is enforced again by the writer under
        // its click lock (once_per_click below), which is what makes two
        // concurrent id-less requests record one conversion, not two.
        $transactionId = trim((string) ($opts['transaction_id'] ?? ''));
        $blocked = !empty($opts['reversal'])
            ? null
            : p202LegacyConversionGate((int) $click['click_lead'] === 1, $transactionId);
        if ($blocked !== null) {
            return $none + ['reason' => $blocked];
        }

        $usePixelPayout = (bool) ($opts['use_pixel_payout'] ?? false);
        $payout = $usePixelPayout ? (string) ($opts['payout'] ?? '0') : (string) $click['click_payout'];
        $convTime = time();
        $clickTime = (int) $click['click_time'];

        $result = p202RecordConversion(
            $db,
            [
                'click_id'        => $clickId,
                'campaign_id'     => (int) $click['aff_campaign_id'],
                'user_id'         => (int) $click['user_id'],
                'click_time'      => $clickTime,
                'conv_time'       => $convTime,
                'time_difference' => p202TimeDifference($clickTime, $convTime),
                'ip'              => (string) ($opts['ip'] ?? ''),
                'pixel_type'      => $pixelType,
                'user_agent'      => (string) ($opts['user_agent'] ?? ''),
                'click_payout'    => $payout,
                'once_per_click'  => $transactionId === '' && empty($opts['reversal']),
                'source'          => (string) ($opts['source'] ?? \Prosper202\Conversion\Ledger\ConversionSource::LEGACY_PIXEL->value),
                'reversal'        => !empty($opts['reversal']),
                'reversal_ref'    => (string) ($opts['reversal_ref'] ?? ''),
                'event_name'      => (string) ($opts['event_name'] ?? ''),
            ],
            (string) ($click['click_cpa'] ?? ''),
            $usePixelPayout,
            $usePixelPayout ? $payout : '',
            $transactionId
        );

        // conv_id 0 with duplicate = the writer's under-lock gate: another
        // id-less request converted this click first. conv_id 0 without it =
        // the click vanished between the lookup and the lock.
        $recorded = $result['conv_id'] > 0 && !$result['duplicate'];

        if ($recorded) {
            $reason = '';
        } elseif ($result['duplicate']) {
            $reason = $result['conv_id'] > 0 ? 'duplicate' : 'already_lead';
        } else {
            $reason = 'unknown_click';
        }

        return [
            'recorded'  => $recorded,
            'duplicate' => $result['duplicate'],
            'conv_id'   => $result['conv_id'],
            'reason'    => $reason,
        ];
    }
}

if (!function_exists('p202RecordWebEvent')) {
    /**
     * A pixel or postback that carries `event=` (plan §2.2): the event is
     * stored on the click and evaluated by the click's goals through
     * GoalEngine::ingest(), which records whatever the goals reach through
     * the conversion ledger and tells the traffic source about each payable
     * goal the campaign notifies for.
     *
     * Returns null when the request carries no event — the endpoint then
     * does exactly what it did before events existed. Otherwise one of:
     *
     *   status no_goals   the click's campaign evaluates no goals: the
     *                     endpoint records its plain conversion as it always
     *                     has, with `event_name` (the event's name when it is
     *                     a valid one, else null) kept on the row. Goals are
     *                     opt-in per campaign, so an existing postback that
     *                     happens to carry `event=` changes nothing.
     *   status recorded   the event was stored (or was a duplicate): `result`
     *                     is the engine's answer, `markup` the browser pixels
     *                     to echo on a browser path.
     *   status invalid    the event was malformed (`errors` by parameter);
     *                     nothing was written. HTTP 422.
     *   status refused    the engine refused it (`code` 409 for an event id
     *                     reused with other content, 422 for a subject at its
     *                     event cap or an event that is also a reversal).
     *   status not_found  no such click, or not the click the endpoint's
     *                     scope allows (`reason`). HTTP 404.
     *
     * A database failure propagates: the caller answers 500 so a sender that
     * retries failures retries, and the event id makes the retry safe.
     *
     * @param array<string, mixed> $get the request's query
     * @param array{user_id?: int, campaign_id?: int, browser?: bool, trusted?: bool} $opts
     *        user_id / campaign_id: the click must belong to that owner /
     *        campaign (px / pb scopes). browser: a browser renders the
     *        response, so image, iframe and script pixels are returned for
     *        it to load. trusted: whether `amount` may set a paid value, as it
     *        does for this endpoint's plain conversions (default true).
     * @return array<string, mixed>|null
     */
    function p202RecordWebEvent(mysqli $db, int $clickId, array $get, array $opts = []): ?array
    {
        if (!\Prosper202\Goals\WebEvents::requested($get)) {
            return null;
        }
        $conn = new \Prosper202\Database\Connection($db);
        $events = new \Prosper202\Goals\WebEvents($conn);
        $click = $events->click($clickId);
        if ($click === null) {
            return ['status' => 'not_found', 'reason' => 'unknown_click'];
        }
        if (isset($opts['user_id']) && $click['user_id'] !== (int) $opts['user_id']) {
            return ['status' => 'not_found', 'reason' => 'foreign_click'];
        }
        if (isset($opts['campaign_id']) && $click['campaign_id'] !== (int) $opts['campaign_id']) {
            return ['status' => 'not_found', 'reason' => 'campaign_mismatch'];
        }
        $now = time();
        if (!$events->campaignEvaluatesGoals($click['user_id'], $click['campaign_id'], $now)) {
            return ['status' => 'no_goals', 'event_name' => \Prosper202\Goals\WebEvents::nameFrom($get)];
        }
        if (p202ExtractReversal($get)['reversal']) {
            return ['status' => 'refused', 'code' => 422, 'message' => 'An event cannot also be a reversal: send status=reversed '
                . 'with the transaction id of the conversion it reverses, and no event.'];
        }

        try {
            $event = \Prosper202\Goals\WebEvents::fromQuery($get, p202ExtractTransactionId($get), $now, (bool) ($opts['trusted'] ?? true));
        } catch (\Prosper202\Goals\InvalidGoalDefinition $e) {
            return ['status' => 'invalid', 'errors' => $e->errors(), 'message' => $e->getMessage()];
        }

        $notifier = new \Prosper202\Goals\TrafficSourceNotifier($conn, (bool) ($opts['browser'] ?? false));
        $engine = new \Prosper202\Goals\GoalEngine($conn, null, null, null, $notifier);
        try {
            $result = $engine->ingest($click['user_id'], $engine->clickSubject($click['user_id'], $clickId), [$event]);
        } catch (\Prosper202\Goals\GoalEngineException $e) {
            if ($e->reason === \Prosper202\Goals\GoalEngineException::EVENT_CONFLICT) {
                return ['status' => 'refused', 'code' => 409, 'message' => $e->getMessage()];
            }
            if ($e->reason === \Prosper202\Goals\GoalEngineException::EVENT_CAP) {
                return ['status' => 'refused', 'code' => 422, 'message' => $e->getMessage()];
            }
            if ($e->reason === \Prosper202\Goals\GoalEngineException::NOT_FOUND) {
                return ['status' => 'not_found', 'reason' => 'unknown_click'];
            }
            throw $e;
        }
        if ($result['accepted'] !== []) {
            p202LinkConversionIdentity($db, $clickId, $get);
        }

        return ['status' => 'recorded', 'event_id' => $event->eventId, 'result' => $result, 'markup' => $notifier->markup()];
    }
}

if (!function_exists('p202RespondWebEvent')) {
    /**
     * Answer a server-to-server event hit (gpb, pb, upx) and stop: 200 with
     * what happened, or the refusal's status with its reason. A browser path
     * that returned an image before it knew (gpx, px) logs instead.
     *
     * @param array<string, mixed> $outcome p202RecordWebEvent()'s answer
     */
    function p202RespondWebEvent(array $outcome, string $endpoint, bool $echoMarkup = false): void
    {
        switch ($outcome['status']) {
            case 'invalid':
                http_response_code(422);
                header('Content-Type: application/json');
                echo json_encode(['error' => true, 'code' => 422, 'msg' => (string) $outcome['message'], 'field_errors' => $outcome['errors']]);
                die();
            case 'refused':
                p202RespondJsonError((int) $outcome['code'], (string) $outcome['message']);
                return;
            case 'not_found':
                p202RespondJsonError(404, 'Unknown subid' . ($outcome['reason'] === 'campaign_mismatch' ? ' for this campaign' : ''));
                return;
            case 'recorded':
                $result = $outcome['result'];
                if ($echoMarkup && $outcome['markup'] !== '') {
                    echo $outcome['markup'];
                    die();
                }
                header('Content-Type: application/json');
                echo json_encode([
                    'error' => false,
                    'code' => 200,
                    'msg' => $result['accepted'] !== [] ? 'Event recorded' : 'Event already recorded',
                    'event_id' => $outcome['event_id'],
                    'duplicate' => $result['accepted'] === [],
                    'outcomes' => array_map(static fn (array $o): array => [
                        'goal_id' => $o['goal_id'], 'n' => $o['n'], 'payable' => $o['payable'], 'amount' => $o['amount'], 'conversion_id' => $o['conversion_id'],
                    ], $result['outcomes']),
                    'notifications' => $result['notifications'],
                ]);
                die();
        }
        error_log($endpoint . ': unexpected web event status ' . (string) $outcome['status']);
    }
}
