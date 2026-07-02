<?php

declare(strict_types=1);

namespace Prosper202\Ltv;

use Prosper202\Database\Connection;
use RuntimeException;

/**
 * API-side customer CRUD: detail reads, CRM upserts, aliasing, merge and
 * erasure. Ingest-side identity resolution lives in MysqlCustomerRepository;
 * this class serves the /ltv/customers endpoints.
 */
final class MysqlCustomerCrmRepository
{
    private const CRM_COLUMNS = [
        'first_name', 'last_name', 'phone', 'company',
        'address_line1', 'address_line2', 'city', 'region', 'postal_code', 'country',
    ];

    public function __construct(
        private Connection $conn,
        private MysqlCustomerRepository $customers,
        private MysqlCustomerFieldRepository $fields
    ) {
    }

    /**
     * Full customer detail: record + aliases + custom-field values +
     * subscriptions + recent ledger events (with line items).
     *
     * @param int $eventLimit How many recent revenue events to include.
     * @return array<string, mixed>|null
     */
    public function get(int $userId, int $customerId, int $eventLimit = 20): ?array
    {
        $eventLimit = max(1, min(200, $eventLimit));
        $stmt = $this->conn->prepareRead(
            'SELECT customer_id, merged_into_customer_id, primary_ref, first_name, last_name, email,
                    phone, company, address_line1, address_line2, city, region, postal_code, country,
                    first_seen_time, last_activity_time, first_click_id,
                    order_count, total_revenue, refunded_amount, active_subscription_count, mrr,
                    status, created_at, updated_at
             FROM 202_customers WHERE customer_id = ? AND user_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'ii', [$customerId, $userId]);
        $customer = $this->conn->fetchOne($stmt);
        if ($customer === null) {
            return null;
        }

        $stmt = $this->conn->prepareRead(
            'SELECT alias_type, alias_value, created_at FROM 202_customer_aliases
             WHERE customer_id = ? AND user_id = ? ORDER BY alias_id ASC'
        );
        $this->conn->bind($stmt, 'ii', [$customerId, $userId]);
        $customer['aliases'] = $this->conn->fetchAll($stmt);

        $stmt = $this->conn->prepareRead(
            'SELECT f.field_key, f.field_type, v.value_text, v.value_number, v.value_date
             FROM 202_customer_field_values v
             JOIN 202_customer_fields f ON f.field_id = v.field_id
             WHERE v.customer_id = ? AND v.user_id = ?
             ORDER BY f.sort_order ASC, f.field_id ASC'
        );
        $this->conn->bind($stmt, 'ii', [$customerId, $userId]);
        $customFields = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $customFields[(string) $row['field_key']] = match ((string) $row['field_type']) {
                'number' => $row['value_number'] !== null ? (float) $row['value_number'] : null,
                'boolean' => $row['value_number'] !== null ? ((float) $row['value_number'] > 0) : null,
                'date' => $row['value_date'] !== null ? (int) $row['value_date'] : null,
                default => $row['value_text'],
            };
        }
        $customer['custom_fields'] = $customFields;

        $stmt = $this->conn->prepareRead(
            'SELECT subscription_id, external_sub_id, plan_name, status, amount, currency,
                    billing_interval, billing_interval_count, mrr,
                    started_at, current_period_start, current_period_end, canceled_at
             FROM 202_subscriptions
             WHERE customer_id = ? AND user_id = ?
             ORDER BY subscription_id DESC'
        );
        $this->conn->bind($stmt, 'ii', [$customerId, $userId]);
        $customer['subscriptions'] = $this->conn->fetchAll($stmt);

        $stmt = $this->conn->prepareRead(
            'SELECT event_id, event_type, amount, currency, occurred_at, source,
                    conv_id, subscription_id, click_id, external_ref, transaction_id
             FROM 202_revenue_events
             WHERE customer_id = ? AND user_id = ?
             ORDER BY occurred_at DESC, event_id DESC LIMIT ?'
        );
        $this->conn->bind($stmt, 'iii', [$customerId, $userId, $eventLimit]);
        $events = $this->conn->fetchAll($stmt);

        if ($events !== []) {
            $eventIds = array_map(static fn (array $e): int => (int) $e['event_id'], $events);
            $placeholders = implode(', ', array_fill(0, count($eventIds), '?'));
            $stmt = $this->conn->prepareRead(
                "SELECT event_id, product_id, sku, product_name, quantity, unit_price, amount
                 FROM 202_revenue_line_items WHERE event_id IN ({$placeholders})"
            );
            $this->conn->bind($stmt, str_repeat('i', count($eventIds)), $eventIds);
            $itemsByEvent = [];
            foreach ($this->conn->fetchAll($stmt) as $item) {
                $itemsByEvent[(int) $item['event_id']][] = $item;
            }
            foreach ($events as &$event) {
                $event['items'] = $itemsByEvent[(int) $event['event_id']] ?? [];
            }
            unset($event);
        }
        $customer['recent_events'] = $events;

        return $customer;
    }

    /**
     * Upsert a customer from the API: resolve/create identity, then apply CRM
     * fields, email, extra aliases and custom-field values. Returns the
     * terminal customer_id.
     *
     * @param array<string, mixed> $payload customer_id OR customer_ref
     *        (+customer_ref_type); optional CRM columns, email, aliases
     *        (list of {type, value}), custom_fields (map key => value).
     */
    public function upsert(int $userId, array $payload): int
    {
        $now = time();
        $customerId = $this->resolveTarget($userId, $payload, $now);

        $this->applyCrmFields($userId, $customerId, $payload, $now);

        if (isset($payload['aliases'])) {
            if (!is_array($payload['aliases'])) {
                throw new RuntimeException('aliases must be an array of {type, value}');
            }
            foreach ($payload['aliases'] as $alias) {
                if (!is_array($alias) || trim((string) ($alias['value'] ?? '')) === '') {
                    throw new RuntimeException('Each alias requires a non-empty value');
                }
                $owner = $this->conn->transaction(
                    fn (): int => $this->customers->addAlias(
                        $userId,
                        $customerId,
                        (string) ($alias['type'] ?? 'custom'),
                        (string) $alias['value'],
                        $now
                    )
                );
                if ($owner !== $customerId) {
                    // First-writer-wins: surfacing beats silent re-pointing.
                    throw new RuntimeException(
                        'Alias "' . $alias['value'] . '" already belongs to customer ' . $owner
                        . '; use POST /ltv/customers/' . $customerId . '/merge to combine records'
                    );
                }
            }
        }

        $this->applyCustomFields($userId, $customerId, $payload);

        return $customerId;
    }

    /**
     * Merge source into target: repoint aliases, ledger events, conversions,
     * click links, subscriptions and custom-field values; set the merge
     * pointer; reconcile the target's rollups from the ledger. One
     * transaction.
     */
    public function merge(int $userId, int $sourceId, int $targetId): void
    {
        if ($sourceId === $targetId) {
            throw new RuntimeException('Cannot merge a customer into itself');
        }
        if (!$this->customers->customerBelongsToUser($sourceId, $userId)
            || !$this->customers->customerBelongsToUser($targetId, $userId)) {
            throw new RuntimeException('Both customers must exist and belong to this account');
        }
        $terminalTarget = $this->customers->followMergePointer($targetId);
        if ($terminalTarget === $sourceId) {
            throw new RuntimeException('Target already merges into source; merge the other way around');
        }

        $now = time();
        $this->conn->transaction(function () use ($userId, $sourceId, $terminalTarget, $now): void {
            // Custom-field values first: target's existing value wins on
            // conflict (UNIQUE customer_id+field_id), source leftovers dropped.
            $stmt = $this->conn->prepareWrite(
                'UPDATE IGNORE 202_customer_field_values SET customer_id = ? WHERE customer_id = ? AND user_id = ?'
            );
            $this->conn->bind($stmt, 'iii', [$terminalTarget, $sourceId, $userId]);
            $this->conn->executeUpdate($stmt);
            $stmt = $this->conn->prepareWrite(
                'DELETE FROM 202_customer_field_values WHERE customer_id = ? AND user_id = ?'
            );
            $this->conn->bind($stmt, 'ii', [$sourceId, $userId]);
            $this->conn->executeUpdate($stmt);

            // Aliases are UNIQUE per (user, type, hash) across customers, so a
            // plain repoint cannot collide.
            foreach ([
                ['202_customer_aliases', 'customer_id', true],
                ['202_revenue_events', 'customer_id', true],
                ['202_conversion_logs', 'customer_id', true],
                ['202_subscriptions', 'customer_id', true],
                ['202_clicks_tracking', 'customer_id', false],
            ] as [$table, $column, $hasUserId]) {
                $sql = "UPDATE {$table} SET {$column} = ? WHERE {$column} = ?" . ($hasUserId ? ' AND user_id = ?' : '');
                $stmt = $this->conn->prepareWrite($sql);
                if ($hasUserId) {
                    $this->conn->bind($stmt, 'iii', [$terminalTarget, $sourceId, $userId]);
                } else {
                    $this->conn->bind($stmt, 'ii', [$terminalTarget, $sourceId]);
                }
                $this->conn->executeUpdate($stmt);
            }

            // Mark the source as merged (terminal pointer) and zero its cache.
            $stmt = $this->conn->prepareWrite(
                "UPDATE 202_customers
                 SET merged_into_customer_id = ?, status = 'merged',
                     order_count = 0, total_revenue = 0, refunded_amount = 0,
                     active_subscription_count = 0, mrr = 0, updated_at = ?
                 WHERE customer_id = ? AND user_id = ?"
            );
            $this->conn->bind($stmt, 'iiii', [$terminalTarget, $now, $sourceId, $userId]);
            $this->conn->executeUpdate($stmt);

            // Re-point any customers that previously merged into the source so
            // the pointer stays terminal (depth 1).
            $stmt = $this->conn->prepareWrite(
                'UPDATE 202_customers SET merged_into_customer_id = ?, updated_at = ?
                 WHERE merged_into_customer_id = ? AND user_id = ?'
            );
            $this->conn->bind($stmt, 'iiii', [$terminalTarget, $now, $sourceId, $userId]);
            $this->conn->executeUpdate($stmt);

            $this->reconcileCustomer($userId, $terminalTarget, $now);
        });
    }

    /**
     * Erasure (GDPR-shaped): remove CRM fields, aliases and custom-field
     * values; anonymize the record. Ledger amounts are kept for revenue
     * integrity — they no longer reference any personal data.
     */
    public function erase(int $userId, int $customerId): void
    {
        if (!$this->customers->customerBelongsToUser($customerId, $userId)) {
            throw new RuntimeException('Customer not found for this account');
        }

        $now = time();
        $this->conn->transaction(function () use ($userId, $customerId, $now): void {
            $stmt = $this->conn->prepareWrite(
                'DELETE FROM 202_customer_aliases WHERE customer_id = ? AND user_id = ?'
            );
            $this->conn->bind($stmt, 'ii', [$customerId, $userId]);
            $this->conn->executeUpdate($stmt);

            // Personalization tokens hold sealed PII snapshots — erasure must
            // reach them too.
            $stmt = $this->conn->prepareWrite(
                'DELETE FROM 202_personalization_tokens WHERE customer_id = ? AND user_id = ?'
            );
            $this->conn->bind($stmt, 'ii', [$customerId, $userId]);
            $this->conn->executeUpdate($stmt);

            $stmt = $this->conn->prepareWrite(
                'DELETE FROM 202_customer_field_values WHERE customer_id = ? AND user_id = ?'
            );
            $this->conn->bind($stmt, 'ii', [$customerId, $userId]);
            $this->conn->executeUpdate($stmt);

            $stmt = $this->conn->prepareWrite(
                "UPDATE 202_customers
                 SET primary_ref = CONCAT('erased:', customer_id),
                     first_name = NULL, last_name = NULL, email = NULL, email_hash = NULL,
                     phone = NULL, company = NULL, address_line1 = NULL, address_line2 = NULL,
                     city = NULL, region = NULL, postal_code = NULL, country = NULL,
                     status = 'anonymized', updated_at = ?
                 WHERE customer_id = ? AND user_id = ?"
            );
            $this->conn->bind($stmt, 'iii', [$now, $customerId, $userId]);
            $this->conn->executeUpdate($stmt);
        });
    }

    /**
     * Recompute one customer's cached rollups from the ledger and
     * subscriptions (same definitions as the ltv_maintenance sweep).
     */
    public function reconcileCustomer(int $userId, int $customerId, int $now): void
    {
        $stmt = $this->conn->prepareWrite(
            "UPDATE 202_customers c
             LEFT JOIN (
                SELECT customer_id,
                    SUM(amount) AS revenue,
                    SUM(CASE WHEN event_type IN ('refund','chargeback') THEN -amount ELSE 0 END) AS refunded,
                    SUM(CASE WHEN event_type IN ('purchase','renewal','one_time') THEN 1
                             WHEN event_type = 'adjustment' AND external_ref LIKE 'void:%' THEN -1
                             ELSE 0 END) AS orders
                FROM 202_revenue_events WHERE customer_id = ? GROUP BY customer_id
             ) e ON e.customer_id = c.customer_id
             LEFT JOIN (
                SELECT customer_id,
                    SUM(CASE WHEN status = 'active' THEN mrr ELSE 0 END) AS mrr,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_subs
                FROM 202_subscriptions WHERE customer_id = ? GROUP BY customer_id
             ) s ON s.customer_id = c.customer_id
             SET c.order_count = GREATEST(0, COALESCE(e.orders, 0)),
                 c.total_revenue = COALESCE(e.revenue, 0),
                 c.refunded_amount = GREATEST(0, COALESCE(e.refunded, 0)),
                 c.mrr = COALESCE(s.mrr, 0),
                 c.active_subscription_count = COALESCE(s.active_subs, 0),
                 c.updated_at = ?
             WHERE c.customer_id = ? AND c.user_id = ?"
        );
        $this->conn->bind($stmt, 'iiiii', [$customerId, $customerId, $now, $customerId, $userId]);
        $this->conn->executeUpdate($stmt);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveTarget(int $userId, array $payload, int $now): int
    {
        $explicitId = isset($payload['customer_id']) ? (int) $payload['customer_id'] : 0;
        if ($explicitId > 0) {
            if (!$this->customers->customerBelongsToUser($explicitId, $userId)) {
                throw new RuntimeException('customer_id ' . $explicitId . ' not found for this account');
            }
            return $this->customers->followMergePointer($explicitId);
        }

        $ref = trim((string) ($payload['customer_ref'] ?? ''));
        if ($ref === '') {
            throw new RuntimeException('customer_id or customer_ref is required');
        }
        $refType = isset($payload['customer_ref_type']) ? (string) $payload['customer_ref_type'] : 'custom';
        $crm = [];
        foreach (array_merge(self::CRM_COLUMNS, ['email']) as $column) {
            if (isset($payload[$column])) {
                $crm[$column] = $payload[$column];
            }
        }

        return $this->conn->transaction(
            fn (): int => $this->customers->resolveOrCreateByAlias($userId, $refType, $ref, $crm, null, $now)
        );
    }

    /**
     * Apply any CRM columns present in the payload (explicit-null clears a
     * column; absent keys are untouched).
     *
     * @param array<string, mixed> $payload
     */
    private function applyCrmFields(int $userId, int $customerId, array $payload, int $now): void
    {
        $sets = [];
        $types = '';
        $binds = [];

        foreach (self::CRM_COLUMNS as $column) {
            if (!array_key_exists($column, $payload)) {
                continue;
            }
            $value = $payload[$column];
            $sets[] = "{$column} = ?";
            $types .= 's';
            $binds[] = $value !== null && trim((string) $value) !== '' ? trim((string) $value) : null;
        }

        if (array_key_exists('email', $payload)) {
            $email = $payload['email'] !== null ? trim((string) $payload['email']) : '';
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException('email is not a valid address');
            }
            $sets[] = 'email = ?';
            $types .= 's';
            $binds[] = $email !== '' ? $email : null;
            $sets[] = 'email_hash = ?';
            $types .= 's';
            $binds[] = $email !== '' ? hash('sha256', strtolower($email), true) : null;
        }

        if ($sets === []) {
            return;
        }

        $sets[] = 'updated_at = ?';
        $types .= 'i';
        $binds[] = $now;
        $types .= 'ii';
        $binds[] = $customerId;
        $binds[] = $userId;

        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_customers SET ' . implode(', ', $sets) . ' WHERE customer_id = ? AND user_id = ?'
        );
        $this->conn->bind($stmt, $types, $binds);
        $this->conn->executeUpdate($stmt);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyCustomFields(int $userId, int $customerId, array $payload): void
    {
        if (!isset($payload['custom_fields'])) {
            return;
        }
        if (!is_array($payload['custom_fields'])) {
            throw new RuntimeException('custom_fields must be an object of field_key => value');
        }

        foreach ($payload['custom_fields'] as $key => $value) {
            $field = $this->fields->findByKey($userId, (string) $key);
            if ($field === null) {
                throw new RuntimeException(
                    'Unknown custom field "' . $key . '"; define it first via POST /ltv/fields'
                );
            }
            $this->fields->setValue($userId, $customerId, $field, $value);
        }
    }
}
