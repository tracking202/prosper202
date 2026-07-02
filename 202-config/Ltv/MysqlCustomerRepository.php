<?php

declare(strict_types=1);

namespace Prosper202\Ltv;

use Prosper202\Database\Connection;
use RuntimeException;

/**
 * Ingest-side customer identity + revenue ledger writes.
 *
 * Every method here is designed to run INSIDE an already-open transaction on
 * the shared Connection (MysqlConversionRepository::record() opens it), so the
 * customer upsert, ledger event, line items and rollup bump commit or roll
 * back together with the conversion row.
 *
 * Lock ordering contract (all ingest paths): click row (FOR UPDATE) →
 * customer → ledger → line items. Callers retry the whole transaction once on
 * deadlock (MySQL 1213/1205).
 *
 * The 202_revenue_events ledger is the source of truth for money; the rollup
 * columns on 202_customers are a derived cache bumped here for freshness and
 * reconciled from the ledger by the ltv_maintenance cronjob.
 */
final class MysqlCustomerRepository
{
    /** Alias types accepted from ingest. 'custom' is the default for opaque refs. */
    public const ALIAS_TYPES = ['email_md5', 'email_sha256', 'esp_id', 'merchant_id', 'subid', 'custom'];

    /** Event types that count as an order in the rollup cache. */
    private const ORDER_EVENT_TYPES = ['purchase', 'renewal', 'one_time'];

    public function __construct(private Connection $conn)
    {
    }

    /**
     * Resolve the customer for a conversion, following the documented
     * precedence: explicit ref → cached click link → per-user c-param
     * fallback. Returns null when no identity signal exists (the conversion
     * records unlinked, exactly as before the LTV feature).
     *
     * @param array<string, mixed> $crm CRM fields applied on INSERT only.
     */
    public function resolveForConversion(
        int $userId,
        int $clickId,
        ?string $customerRef,
        ?string $customerRefType,
        array $crm,
        int $now
    ): ?int {
        // 1. Explicit ref supplied on the pixel/postback/API call.
        if ($customerRef !== null && $customerRef !== '') {
            $type = $this->normalizeAliasType($customerRefType);
            return $this->resolveOrCreateByAlias($userId, $type, $customerRef, $crm, $clickId, $now);
        }

        // 2. Cached customer on the click (a prior conversion on this subid
        //    already resolved it — "linked once, never again").
        $cached = $this->customerIdForClick($clickId);
        if ($cached !== null) {
            return $this->followMergePointer($cached);
        }

        // 3. Per-user c-param fallback: the account has designated one of the
        //    c1-c4 tracking params as carrying the customer reference.
        $cparam = $this->customerCParamPref($userId);
        if ($cparam >= 1 && $cparam <= 4) {
            $ref = $this->clickCParamValue($clickId, $cparam);
            if ($ref !== null && $ref !== '') {
                return $this->resolveOrCreateByAlias($userId, 'custom', $ref, $crm, $clickId, $now);
            }
        }

        return null;
    }

    /**
     * Find or create the customer owning (user, alias_type, alias_value).
     *
     * Race-safe: the UNIQUE (user_id, alias_type, alias_hash) key arbitrates
     * concurrent first-time ingests. The loser of the race deletes its orphan
     * customer row and adopts the winner's, so both writers converge on one
     * customer.
     *
     * @param array<string, mixed> $crm CRM fields applied on INSERT only.
     */
    public function resolveOrCreateByAlias(
        int $userId,
        string $aliasType,
        string $aliasValue,
        array $crm,
        ?int $firstClickId,
        int $now
    ): int {
        $aliasValue = trim($aliasValue);
        if ($aliasValue === '') {
            throw new RuntimeException('Customer reference must not be empty');
        }
        if (strlen($aliasValue) > 255) {
            throw new RuntimeException('Customer reference exceeds 255 characters');
        }
        $aliasHash = hash('sha256', $aliasValue, true);

        $existing = $this->aliasCustomerId($userId, $aliasType, $aliasHash);
        if ($existing !== null) {
            return $this->followMergePointer($existing);
        }

        $newCustomerId = $this->insertCustomer($userId, $aliasValue, $crm, $firstClickId, $now);

        // Claim the alias. The no-op ON DUPLICATE KEY UPDATE reports
        // affected_rows = 1 on insert (we won) and 0 when the row already
        // exists (a concurrent transaction won after our SELECT above).
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_customer_aliases (user_id, customer_id, alias_type, alias_value, alias_hash, created_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE customer_id = customer_id'
        );
        $this->conn->bind($stmt, 'iisssi', [$userId, $newCustomerId, $aliasType, $aliasValue, $aliasHash, $now]);
        $affected = $this->conn->executeUpdate($stmt);

        if ($affected === 1) {
            return $newCustomerId;
        }

        // Lost the race: adopt the winner's customer and drop our orphan row.
        $winner = $this->aliasCustomerId($userId, $aliasType, $aliasHash);
        if ($winner === null) {
            throw new RuntimeException('Customer alias claim failed and winner not found');
        }
        $del = $this->conn->prepareWrite('DELETE FROM 202_customers WHERE customer_id = ? AND user_id = ?');
        $this->conn->bind($del, 'ii', [$newCustomerId, $userId]);
        $this->conn->executeUpdate($del);

        return $this->followMergePointer($winner);
    }

    /**
     * Register an additional alias for an existing customer (e.g. mapping an
     * ESP email hash onto a customer first seen via merchant id). Returns the
     * customer that ends up owning the alias — if the alias already belongs
     * to a different customer, that binding wins (first-writer-wins; no
     * silent re-pointing).
     */
    public function addAlias(int $userId, int $customerId, string $aliasType, string $aliasValue, int $now): int
    {
        $aliasValue = trim($aliasValue);
        if ($aliasValue === '') {
            throw new RuntimeException('Alias value must not be empty');
        }
        $aliasType = $this->normalizeAliasType($aliasType);
        $aliasHash = hash('sha256', $aliasValue, true);

        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_customer_aliases (user_id, customer_id, alias_type, alias_value, alias_hash, created_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE customer_id = customer_id'
        );
        $this->conn->bind($stmt, 'iisssi', [$userId, $customerId, $aliasType, $aliasValue, $aliasHash, $now]);
        $this->conn->executeUpdate($stmt);

        $owner = $this->aliasCustomerId($userId, $aliasType, $aliasHash);
        return $owner !== null ? $this->followMergePointer($owner) : $customerId;
    }

    /**
     * Remove one alias from a customer. Scoped to (user, customer) so a
     * stale/foreign alias_id cannot unlink someone else's identity. The
     * customer record itself is untouched — only the resolution mapping goes.
     */
    public function deleteAlias(int $userId, int $customerId, int $aliasId): void
    {
        $stmt = $this->conn->prepareWrite(
            'DELETE FROM 202_customer_aliases
             WHERE alias_id = ? AND customer_id = ? AND user_id = ?'
        );
        $this->conn->bind($stmt, 'iii', [$aliasId, $customerId, $userId]);
        if ($this->conn->executeUpdate($stmt) === 0) {
            throw new RuntimeException('Alias not found on this customer');
        }
    }

    /**
     * Insert a revenue ledger event. Returns [event_id, inserted]; on an
     * idempotent replay (same conv_id, or same user+idempotency_key) the
     * existing event's id is returned with inserted = false and NOTHING else
     * may be bumped by the caller.
     *
     * @param array{
     *   event_type: string, amount: float, currency: string, occurred_at: int,
     *   source: string, conv_id?: int|null, subscription_id?: int|null,
     *   click_id?: int|null, external_ref?: string|null,
     *   transaction_id?: string|null, idempotency_key?: string|null
     * } $event
     * @return array{eventId: int, inserted: bool}
     */
    public function insertRevenueEvent(int $userId, int $customerId, array $event, int $now): array
    {
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_revenue_events
                (user_id, customer_id, event_type, amount, currency, occurred_at, source,
                 conv_id, subscription_id, click_id, external_ref, transaction_id, idempotency_key, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE event_id = LAST_INSERT_ID(event_id)'
        );
        $this->conn->bind($stmt, 'iisdsisiiisssi', [
            $userId,
            $customerId,
            (string) $event['event_type'],
            (float) $event['amount'],
            (string) $event['currency'],
            (int) $event['occurred_at'],
            (string) $event['source'],
            $event['conv_id'] ?? null,
            $event['subscription_id'] ?? null,
            $event['click_id'] ?? null,
            $event['external_ref'] ?? null,
            $event['transaction_id'] ?? null,
            $event['idempotency_key'] ?? null,
            $now,
        ]);

        // Need both the id and the inserted/duplicate distinction, so run the
        // checked execute and read affected_rows + insert_id before closing.
        // affected_rows: 1 = inserted, 0/2 = duplicate hit the no-op update.
        // Property reads are guarded like Connection's own accessors: PHP 8.4
        // internal readonly properties make subclass test fakes throw Error on
        // access (an inserted row is the default assumption there).
        $this->conn->execute($stmt);
        try {
            $affected = (int) $stmt->affected_rows;
        } catch (\Error) {
            $affected = 1;
        }
        try {
            $eventId = (int) $stmt->insert_id;
        } catch (\Error) {
            $eventId = 0;
        }
        $stmt->close();
        if ($eventId === 0) {
            try {
                $eventId = (int) $this->conn->writeConnection()->insert_id;
            } catch (\Error) {
                $eventId = 0;
            }
        }

        return ['eventId' => $eventId, 'inserted' => $affected === 1];
    }

    /**
     * Bump the cached rollups on 202_customers for a newly inserted ledger
     * event. Callers MUST skip this for idempotent replays (inserted=false).
     * GREATEST(0, ...) guards keep transient drift from going negative; the
     * reconcile cron corrects the cache from the ledger either way.
     */
    public function applyEventToRollups(int $userId, int $customerId, string $eventType, float $amount, int $occurredAt, int $now): void
    {
        $orderDelta = in_array($eventType, self::ORDER_EVENT_TYPES, true) ? 1 : 0;
        $refundDelta = ($eventType === 'refund' || $eventType === 'chargeback') ? abs($amount) : 0.0;

        $this->adjustRollups($userId, $customerId, $orderDelta, $amount, $refundDelta, $occurredAt, $now);
    }

    /**
     * Apply explicit rollup deltas (used for compensating events, e.g. a
     * conversion soft-delete voiding its purchase: orderDelta -1, negative
     * amountDelta).
     */
    public function adjustRollups(int $userId, int $customerId, int $orderDelta, float $amountDelta, float $refundDelta, int $occurredAt, int $now): void
    {
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_customers SET
                order_count = GREATEST(0, CAST(order_count AS SIGNED) + ?),
                total_revenue = total_revenue + ?,
                refunded_amount = GREATEST(0, refunded_amount + ?),
                last_activity_time = GREATEST(last_activity_time, ?),
                updated_at = ?
             WHERE customer_id = ? AND user_id = ?'
        );
        $this->conn->bind($stmt, 'iddiiii', [
            $orderDelta,
            $amountDelta,
            $refundDelta,
            $occurredAt,
            $now,
            $customerId,
            $userId,
        ]);
        $this->conn->executeUpdate($stmt);
    }

    /**
     * Cache the resolved customer on the click's tracking row so later
     * conversions on the same subid resolve without any identity signal.
     *
     * Upsert (legacy clicks may have no 202_clicks_tracking row; c-columns are
     * NOT NULL so the insert supplies 0). First-writer-wins: an existing,
     * different customer_id is kept, never silently rebound.
     */
    public function stampClickCustomer(int $clickId, int $customerId): void
    {
        if ($clickId <= 0) {
            return;
        }
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_clicks_tracking (click_id, c1_id, c2_id, c3_id, c4_id, customer_id)
             VALUES (?, 0, 0, 0, 0, ?)
             ON DUPLICATE KEY UPDATE customer_id = IF(customer_id IS NULL, VALUES(customer_id), customer_id)'
        );
        $this->conn->bind($stmt, 'ii', [$clickId, $customerId]);
        $this->conn->executeUpdate($stmt);
    }

    /**
     * Upsert a catalog product and return its product_id. At least one of
     * external_product_id / sku is required; when only a sku is supplied it
     * doubles as the external id so the unique key still applies.
     *
     * @param array{external_product_id?: string, sku?: string, name?: string, price?: float|null} $product
     */
    public function upsertProduct(int $userId, array $product, string $currency, int $now): int
    {
        $externalId = trim((string) ($product['external_product_id'] ?? ''));
        $sku = trim((string) ($product['sku'] ?? ''));
        if ($externalId === '' && $sku === '') {
            throw new RuntimeException('Product requires an external_product_id or sku');
        }
        if ($externalId === '') {
            $externalId = 'sku:' . $sku;
        }

        $name = isset($product['name']) ? trim((string) $product['name']) : null;
        $price = isset($product['price']) && $product['price'] !== null ? (float) $product['price'] : null;

        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_products (user_id, external_product_id, sku, name, price, currency, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                product_id = LAST_INSERT_ID(product_id),
                sku = COALESCE(VALUES(sku), sku),
                name = COALESCE(VALUES(name), name),
                price = COALESCE(VALUES(price), price),
                updated_at = VALUES(updated_at)'
        );
        $this->conn->bind($stmt, 'isssdsii', [
            $userId,
            $externalId,
            $sku !== '' ? $sku : null,
            $name !== '' ? $name : null,
            $price,
            $currency,
            $now,
            $now,
        ]);
        $productId = $this->conn->executeInsert($stmt);
        if ($productId <= 0) {
            throw new RuntimeException('Product upsert did not yield a product_id');
        }

        return $productId;
    }

    /**
     * Attach product line items to a ledger event. Malformed items throw —
     * a rejected order must be visible, never silently truncated.
     *
     * @param list<array<string, mixed>> $items
     */
    public function insertLineItems(int $userId, int $eventId, array $items, string $currency, int $now): void
    {
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                throw new RuntimeException("Line item #{$index} is not an object");
            }
            $quantity = isset($item['quantity']) ? (float) $item['quantity'] : 1.0;
            if ($quantity <= 0) {
                throw new RuntimeException("Line item #{$index} has a non-positive quantity");
            }
            $unitPrice = isset($item['unit_price']) && $item['unit_price'] !== null ? (float) $item['unit_price'] : null;
            $amount = isset($item['amount']) && $item['amount'] !== null
                ? (float) $item['amount']
                : ($unitPrice !== null ? $unitPrice * $quantity : 0.0);

            $productId = $this->upsertProduct($userId, $item, $currency, $now);
            $sku = trim((string) ($item['sku'] ?? ''));
            $name = trim((string) ($item['name'] ?? ''));

            $stmt = $this->conn->prepareWrite(
                'INSERT INTO 202_revenue_line_items
                    (user_id, event_id, product_id, sku, product_name, quantity, unit_price, amount, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $this->conn->bind($stmt, 'iiissdddi', [
                $userId,
                $eventId,
                $productId,
                $sku !== '' ? $sku : null,
                $name !== '' ? $name : null,
                $quantity,
                $unitPrice,
                $amount,
                $now,
            ]);
            $this->conn->execute($stmt);
            $stmt->close();
        }
    }

    /**
     * The account currency all conversion-sourced ledger events are recorded
     * in (202_users_pref.user_account_currency, USD when unset).
     */
    public function accountCurrency(int $userId): string
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT user_account_currency FROM 202_users_pref WHERE user_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'i', [$userId]);
        $row = $this->conn->fetchOne($stmt);
        $currency = strtoupper(trim((string) ($row['user_account_currency'] ?? '')));

        return preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : 'USD';
    }

    /**
     * True when the customer exists and belongs to the given account. Used to
     * reject cross-tenant explicit customer_id values at ingest.
     */
    public function customerBelongsToUser(int $customerId, int $userId): bool
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT customer_id FROM 202_customers WHERE customer_id = ? AND user_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'ii', [$customerId, $userId]);

        return $this->conn->fetchOne($stmt) !== null;
    }

    /**
     * Resolve a customer id, following the merge pointer to the terminal
     * record (bounded, defensively, at 5 hops — merges always point at the
     * terminal customer so one hop is the norm).
     */
    public function followMergePointer(int $customerId): int
    {
        for ($hop = 0; $hop < 5; $hop++) {
            $stmt = $this->conn->prepareWrite(
                'SELECT merged_into_customer_id FROM 202_customers WHERE customer_id = ? LIMIT 1'
            );
            $this->conn->bind($stmt, 'i', [$customerId]);
            $row = $this->conn->fetchOne($stmt);
            if ($row === null || $row['merged_into_customer_id'] === null) {
                return $customerId;
            }
            $customerId = (int) $row['merged_into_customer_id'];
        }

        return $customerId;
    }

    private function customerIdForClick(int $clickId): ?int
    {
        if ($clickId <= 0) {
            return null;
        }
        $stmt = $this->conn->prepareWrite(
            'SELECT customer_id FROM 202_clicks_tracking WHERE click_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'i', [$clickId]);
        $row = $this->conn->fetchOne($stmt);

        $customerId = $row !== null ? (int) ($row['customer_id'] ?? 0) : 0;
        return $customerId > 0 ? $customerId : null;
    }

    private function customerCParamPref(int $userId): int
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT user_ltv_customer_cparam FROM 202_users_pref WHERE user_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'i', [$userId]);
        $row = $this->conn->fetchOne($stmt);

        return $row !== null ? (int) ($row['user_ltv_customer_cparam'] ?? 0) : 0;
    }

    private function clickCParamValue(int $clickId, int $cparam): ?string
    {
        if ($clickId <= 0) {
            return null;
        }
        // $cparam is validated to 1-4 by the caller; the identifiers cannot be
        // bound as parameters.
        $column = 'c' . $cparam;
        $stmt = $this->conn->prepareWrite(
            "SELECT t.{$column} AS ref
             FROM 202_clicks_tracking ct
             JOIN 202_tracking_{$column} t ON ct.{$column}_id = t.{$column}_id
             WHERE ct.click_id = ? LIMIT 1"
        );
        $this->conn->bind($stmt, 'i', [$clickId]);
        $row = $this->conn->fetchOne($stmt);

        $ref = $row !== null ? trim((string) ($row['ref'] ?? '')) : '';
        return $ref !== '' ? $ref : null;
    }

    private function aliasCustomerId(int $userId, string $aliasType, string $aliasHash): ?int
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT customer_id FROM 202_customer_aliases
             WHERE user_id = ? AND alias_type = ? AND alias_hash = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'iss', [$userId, $aliasType, $aliasHash]);
        $row = $this->conn->fetchOne($stmt);

        return $row !== null ? (int) $row['customer_id'] : null;
    }

    /**
     * @param array<string, mixed> $crm
     */
    private function insertCustomer(int $userId, string $primaryRef, array $crm, ?int $firstClickId, int $now): int
    {
        $email = isset($crm['email']) ? trim((string) $crm['email']) : '';
        $emailHash = $email !== '' ? hash('sha256', strtolower($email), true) : null;

        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_customers
                (user_id, primary_ref, first_name, last_name, email, email_hash, phone, company,
                 address_line1, address_line2, city, region, postal_code, country,
                 first_seen_time, last_activity_time, first_click_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $str = static function (string $key) use ($crm): ?string {
            $value = isset($crm[$key]) ? trim((string) $crm[$key]) : '';
            return $value !== '' ? $value : null;
        };

        $this->conn->bind($stmt, 'isssssssssssssiiiii', [
            $userId,
            $primaryRef,
            $str('first_name'),
            $str('last_name'),
            $email !== '' ? $email : null,
            $emailHash,
            $str('phone'),
            $str('company'),
            $str('address_line1'),
            $str('address_line2'),
            $str('city'),
            $str('region'),
            $str('postal_code'),
            $str('country'),
            $now,
            $now,
            $firstClickId !== null && $firstClickId > 0 ? $firstClickId : null,
            $now,
            $now,
        ]);

        $customerId = $this->conn->executeInsert($stmt);
        if ($customerId <= 0) {
            throw new RuntimeException('Customer insert did not yield a customer_id');
        }

        return $customerId;
    }

    private function normalizeAliasType(?string $type): string
    {
        $type = strtolower(trim((string) $type));
        if ($type === '') {
            return 'custom';
        }
        if (!in_array($type, self::ALIAS_TYPES, true)) {
            throw new RuntimeException(
                'Unknown customer_ref_type "' . $type . '"; expected one of: ' . implode(', ', self::ALIAS_TYPES)
            );
        }

        return $type;
    }
}
