<?php

declare(strict_types=1);

namespace Prosper202\Conversion;

use Prosper202\Database\Connection;
use Prosper202\Ltv\MysqlCustomerRepository;
use RuntimeException;
use Throwable;

final class MysqlConversionRepository implements ConversionRepositoryInterface
{
    private MysqlCustomerRepository $customers;

    public function __construct(private Connection $conn)
    {
        $this->customers = new MysqlCustomerRepository($conn);
    }

    public function list(int $userId, array $filters, int $offset, int $limit): array
    {
        $where = ['cl.user_id = ?', 'cl.deleted = 0'];
        $binds = [$userId];
        $types = 'i';

        if (!empty($filters['campaign_id'])) {
            $where[] = 'cl.campaign_id = ?';
            $binds[] = (int) $filters['campaign_id'];
            $types .= 'i';
        }
        if (!empty($filters['time_from'])) {
            $where[] = 'cl.conv_time >= ?';
            $binds[] = (int) $filters['time_from'];
            $types .= 'i';
        }
        if (!empty($filters['time_to'])) {
            $where[] = 'cl.conv_time <= ?';
            $binds[] = (int) $filters['time_to'];
            $types .= 'i';
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $countStmt = $this->conn->prepareRead("SELECT COUNT(*) AS total FROM 202_conversion_logs cl $whereClause");
        $this->conn->bind($countStmt, $types, $binds);
        $total = (int) ($this->conn->fetchOne($countStmt)['total'] ?? 0);

        $sql = "SELECT cl.conv_id, cl.click_id, cl.transaction_id, cl.campaign_id,
                cl.click_payout, cl.user_id, cl.click_time, cl.conv_time, cl.deleted,
                ac.aff_campaign_name
            FROM 202_conversion_logs cl
            LEFT JOIN 202_aff_campaigns ac ON cl.campaign_id = ac.aff_campaign_id
            $whereClause
            ORDER BY cl.conv_time DESC LIMIT ? OFFSET ?";

        $binds[] = $limit;
        $types .= 'i';
        $binds[] = $offset;
        $types .= 'i';

        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, $types, $binds);

        return ['rows' => $this->conn->fetchAll($stmt), 'total' => $total];
    }

    public function findById(int $id, int $userId): ?array
    {
        $sql = "SELECT cl.conv_id, cl.click_id, cl.transaction_id, cl.campaign_id,
                cl.click_payout, cl.user_id, cl.click_time, cl.conv_time, cl.deleted,
                ac.aff_campaign_name
            FROM 202_conversion_logs cl
            LEFT JOIN 202_aff_campaigns ac ON cl.campaign_id = ac.aff_campaign_id
            WHERE cl.conv_id = ? AND cl.user_id = ? AND cl.deleted = 0 LIMIT 1";

        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, 'ii', [$id, $userId]);

        return $this->conn->fetchOne($stmt);
    }

    public function create(int $userId, array $data): int
    {
        $result = $this->record($userId, $data, function (int $clickId, float $payout) use ($userId): void {
            $this->applyStandardClickUpdate($clickId, $payout, $userId);
        });

        if (!$result['clickFound']) {
            throw new ClickNotFoundException('Click not found or not owned by user');
        }

        return $result['convId'];
    }

    /**
     * Single owner of the transactional conversion write used by every ingestion
     * path (V3 API and the legacy static postback/pixel endpoints).
     *
     * In one transaction it: locks the source click (SELECT ... FOR UPDATE) so
     * concurrent/retried postbacks serialise; de-duplicates on transaction_id
     * (matching the UNIQUE (click_id, transaction_id) key, which ignores
     * `deleted`); inserts the conversion_logs row; and runs an optional
     * caller-supplied click-side update inside the same transaction so the click
     * flag and the audit row commit or roll back together.
     *
     * @param array<string, mixed> $data Requires click_id. Optional:
     *        transaction_id, payout, conv_time, campaign_id, click_time, and the
     *        legacy columns time_difference, ip, pixel_type, user_agent (only
     *        written when present, so the V3 insert keeps its historical shape).
     *        LTV keys (all optional): customer_id (known internal id),
     *        customer_ref + customer_ref_type (external identity — resolved or
     *        created via 202_customer_aliases), customer_crm (CRM fields applied
     *        when the customer is first created), items (product line items for
     *        the revenue ledger event).
     * @param (callable(int $clickId, float $payout): void)|null $clickSideUpdate
     * @return array{convId: int, duplicate: bool, clickFound: bool, customerId: int|null}
     */
    public function record(int $userId, array $data, ?callable $clickSideUpdate = null): array
    {
        $clickId = (int) ($data['click_id'] ?? 0);
        if ($clickId <= 0) {
            throw new RuntimeException('click_id is required');
        }

        // Trim centrally so a blank/whitespace-only id is treated as absent
        // (stored NULL, no dedup) across every ingestion path.
        $rawTransactionId = trim((string) ($data['transaction_id'] ?? ''));
        $transactionId = $rawTransactionId !== '' ? $rawTransactionId : null;
        $convTime = (int) ($data['conv_time'] ?? time());
        $payoutOverride = isset($data['payout']) ? (float) $data['payout'] : null;

        $work = function () use ($userId, $clickId, $transactionId, $convTime, $payoutOverride, $data, $clickSideUpdate): array {
            // Lock the source click so concurrent/retried postbacks serialise here.
            $clickStmt = $this->conn->prepareWrite(
                'SELECT click_id, aff_campaign_id, click_payout, click_time FROM 202_clicks WHERE click_id = ? AND user_id = ? LIMIT 1 FOR UPDATE'
            );
            $this->conn->bind($clickStmt, 'ii', [$clickId, $userId]);
            $click = $this->conn->fetchOne($clickStmt);

            if ($click === null) {
                return ['convId' => 0, 'duplicate' => false, 'clickFound' => false, 'customerId' => null];
            }

            // Idempotency: a transaction_id already recorded for this click is a
            // replay/retry. The lookup ignores `deleted` so it matches the UNIQUE
            // (click_id, transaction_id) key and never collides on insert.
            if ($transactionId !== null) {
                $dupStmt = $this->conn->prepareWrite(
                    'SELECT conv_id, customer_id FROM 202_conversion_logs WHERE click_id = ? AND transaction_id = ? LIMIT 1'
                );
                $this->conn->bind($dupStmt, 'is', [$clickId, $transactionId]);
                $dup = $this->conn->fetchOne($dupStmt);
                if ($dup !== null) {
                    return [
                        'convId' => (int) $dup['conv_id'],
                        'duplicate' => true,
                        'clickFound' => true,
                        'customerId' => $dup['customer_id'] !== null ? (int) $dup['customer_id'] : null,
                    ];
                }
            }

            $payout = $payoutOverride ?? (float) ($click['click_payout'] ?? 0);
            $campaignId = isset($data['campaign_id']) ? (int) $data['campaign_id'] : (int) $click['aff_campaign_id'];
            $clickTime = isset($data['click_time']) ? (int) $data['click_time'] : (int) ($click['click_time'] ?? 0);

            // LTV: resolve the customer AFTER the click lock and dedup guard
            // (lock ordering: click → customer → ledger → line items) so replays
            // never double-count. A conversion with no identity signal records
            // unlinked, exactly as before the LTV feature.
            $customerId = $this->resolveCustomer($userId, $clickId, $data, $convTime);

            // Base column set is exactly the historical V3 insert; the NOT-NULL
            // legacy columns are always appended below (with defaults when absent).
            $columns = ['click_id', 'transaction_id', 'campaign_id', 'click_payout', 'user_id', 'click_time', 'conv_time'];
            $types = 'isidiii';
            $values = [$clickId, $transactionId, $campaignId, $payout, $userId, $clickTime, $convTime];

            if ($customerId !== null) {
                $columns[] = 'customer_id';
                $types .= 'i';
                $values[] = $customerId;
            }

            // These columns are NOT NULL with no DB default. Callers that have the
            // context (the legacy pixel/postback paths) pass them in $data; callers
            // that don't (the V3 API) would otherwise omit them entirely, and the
            // INSERT then fails under STRICT sql_mode with "Field doesn't have a
            // default value" — silently dropping the conversion. Always include them,
            // using the caller's value when supplied and a sensible default otherwise,
            // so every ingestion path writes a valid row. time_difference is the
            // click->conversion gap in seconds.
            $legacyDefaults = [
                'time_difference' => (string) max(0, $convTime - $clickTime),
                'ip' => '',
                'pixel_type' => 0,
                'user_agent' => '',
            ];
            foreach (['time_difference' => 's', 'ip' => 's', 'pixel_type' => 'i', 'user_agent' => 's'] as $col => $type) {
                $value = array_key_exists($col, $data) ? $data[$col] : $legacyDefaults[$col];
                $columns[] = $col;
                $types .= $type;
                $values[] = $type === 'i' ? (int) $value : (string) $value;
            }

            $placeholders = rtrim(str_repeat('?, ', count($values)), ', ');
            $stmt = $this->conn->prepareWrite(
                'INSERT INTO 202_conversion_logs (' . implode(', ', $columns) . ', deleted) VALUES (' . $placeholders . ', 0)'
            );
            $this->conn->bind($stmt, $types, $values);
            $convId = $this->conn->executeInsert($stmt);

            // LTV: append the purchase to the revenue ledger (source of truth),
            // bump the customer's cached rollups, attach product line items, and
            // cache the customer on the click so later conversions on the same
            // subid resolve without an identity signal. All inside this
            // transaction: the conversion and its ledger event commit together.
            if ($customerId !== null) {
                $currency = $this->customers->accountCurrency($userId);
                $ledger = $this->customers->insertRevenueEvent($userId, $customerId, [
                    'event_type' => 'purchase',
                    'amount' => $payout,
                    'currency' => $currency,
                    'occurred_at' => $convTime,
                    'source' => 'conversion',
                    'conv_id' => $convId,
                    'click_id' => $clickId,
                    'transaction_id' => $transactionId,
                ], $convTime);

                if ($ledger['inserted']) {
                    $this->customers->applyEventToRollups($userId, $customerId, 'purchase', $payout, $convTime, $convTime);
                    $items = $data['items'] ?? [];
                    if (is_array($items) && $items !== []) {
                        $this->customers->insertLineItems($userId, $ledger['eventId'], $items, $currency, $convTime);
                    }
                }

                $this->customers->stampClickCustomer($clickId, $customerId);
            }

            if ($clickSideUpdate !== null) {
                $clickSideUpdate($clickId, $payout);
            }

            return ['convId' => $convId, 'duplicate' => false, 'clickFound' => true, 'customerId' => $customerId];
        };

        // Unique-key upserts on 202_customer_aliases under concurrency make a
        // deadlock reachable, not theoretical. The transaction callback is a
        // pure function of its arguments, so one full retry is safe.
        try {
            return $this->conn->transaction($work);
        } catch (Throwable $e) {
            if (!self::isRetryableLockError($e)) {
                throw $e;
            }
            return $this->conn->transaction($work);
        }
    }

    /**
     * Resolve the customer for this conversion from the ingest payload:
     * explicit internal id → external ref (alias upsert) → cached click link →
     * per-user c-param fallback. Returns null when no identity signal exists.
     *
     * @param array<string, mixed> $data
     */
    private function resolveCustomer(int $userId, int $clickId, array $data, int $now): ?int
    {
        $explicitId = isset($data['customer_id']) ? (int) $data['customer_id'] : 0;
        if ($explicitId > 0) {
            // Ownership check: an explicit id must belong to this account, or a
            // caller could attach revenue to another tenant's customer. Reject
            // loudly rather than silently recording unlinked.
            $owned = $this->customers->customerBelongsToUser($explicitId, $userId);
            if (!$owned) {
                throw new RuntimeException('customer_id ' . $explicitId . ' not found for this account');
            }
            return $this->customers->followMergePointer($explicitId);
        }

        $ref = isset($data['customer_ref']) ? trim((string) $data['customer_ref']) : '';
        $refType = isset($data['customer_ref_type']) ? (string) $data['customer_ref_type'] : null;
        $crm = isset($data['customer_crm']) && is_array($data['customer_crm']) ? $data['customer_crm'] : [];

        return $this->customers->resolveForConversion(
            $userId,
            $clickId,
            $ref !== '' ? $ref : null,
            $refType,
            $crm,
            $now
        );
    }

    /**
     * True for MySQL deadlock (1213) / lock wait timeout (1205), whether
     * surfaced as a mysqli_sql_exception or wrapped in the Connection's
     * RuntimeException message.
     */
    private static function isRetryableLockError(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof \mysqli_sql_exception) {
                $code = (int) $current->getCode();
                if ($code === 1213 || $code === 1205) {
                    return true;
                }
            }
            $message = $current->getMessage();
            if (str_contains($message, 'Deadlock found') || str_contains($message, 'Lock wait timeout')) {
                return true;
            }
        }

        return false;
    }

    private function applyStandardClickUpdate(int $clickId, float $payout, int $userId): void
    {
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_clicks SET click_lead = 1, click_payout = ? WHERE click_id = ? AND user_id = ?'
        );
        $this->conn->bind($stmt, 'dii', [$payout, $clickId, $userId]);
        // executeUpdate() runs the checked execute and closes the statement.
        $this->conn->executeUpdate($stmt);
    }

    /**
     * Soft-delete a conversion AND void its revenue ledger event, in one
     * transaction, so LTV totals and the conversion report cannot diverge.
     *
     * The void is a compensating 'adjustment' event (negative amount) with a
     * deterministic idempotency_key ('void:conv:{id}'), so repeated deletes of
     * the same conversion compensate exactly once. The ledger stays
     * append-only: the original purchase event is never mutated.
     */
    public function softDelete(int $id, int $userId): void
    {
        $work = function () use ($id, $userId): void {
            // Lock the conversion row so concurrent deletes serialize here.
            $convStmt = $this->conn->prepareWrite(
                'SELECT conv_id, customer_id, deleted FROM 202_conversion_logs
                 WHERE conv_id = ? AND user_id = ? LIMIT 1 FOR UPDATE'
            );
            $this->conn->bind($convStmt, 'ii', [$id, $userId]);
            $conv = $this->conn->fetchOne($convStmt);
            if ($conv === null || (int) $conv['deleted'] === 1) {
                // Missing or already deleted: nothing to do (matches the
                // historical silent-no-op semantics of this method).
                return;
            }

            $stmt = $this->conn->prepareWrite(
                'UPDATE 202_conversion_logs SET deleted = 1 WHERE conv_id = ? AND user_id = ?'
            );
            $this->conn->bind($stmt, 'ii', [$id, $userId]);
            $this->conn->executeUpdate($stmt);

            $customerId = $conv['customer_id'] !== null ? (int) $conv['customer_id'] : 0;
            if ($customerId <= 0) {
                return; // unlinked conversion — no ledger event to void
            }

            $eventStmt = $this->conn->prepareWrite(
                'SELECT event_id, amount, currency, event_type, occurred_at
                 FROM 202_revenue_events WHERE conv_id = ? LIMIT 1'
            );
            $this->conn->bind($eventStmt, 'i', [$id]);
            $event = $this->conn->fetchOne($eventStmt);
            if ($event === null) {
                return; // conversion predates the ledger — nothing to void
            }

            $now = time();
            $void = $this->customers->insertRevenueEvent($userId, $customerId, [
                'event_type' => 'adjustment',
                'amount' => -(float) $event['amount'],
                'currency' => (string) $event['currency'],
                'occurred_at' => $now,
                'source' => 'conversion',
                'external_ref' => 'void:conv:' . $id,
                'idempotency_key' => 'void:conv:' . $id,
            ], $now);

            if ($void['inserted']) {
                // Reverse the purchase: one fewer order, revenue back out.
                $this->customers->adjustRollups($userId, $customerId, -1, -(float) $event['amount'], 0.0, $now, $now);
            }
        };

        try {
            $this->conn->transaction($work);
        } catch (Throwable $e) {
            if (!self::isRetryableLockError($e)) {
                throw $e;
            }
            $this->conn->transaction($work);
        }
    }
}
