<?php

declare(strict_types=1);

namespace Prosper202\Conversion;

use Prosper202\Bridge\EventBridge;
use Prosper202\Conversion\Ledger\Amount;
use Prosper202\Conversion\Ledger\ConversionSource;
use Prosper202\Conversion\Ledger\DedupeKey;
use Prosper202\Conversion\Ledger\LedgerIntegrityException;
use Prosper202\Conversion\Ledger\MysqlConversionLedger;
use Prosper202\Conversion\Ledger\PayoutMode;
use Prosper202\Conversion\Ledger\ReversalException;
use Prosper202\Conversion\Ledger\SupersededReason;
use Prosper202\Database\Connection;
use Prosper202\DataEngine\ClickRollupSql;
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
        $data['source'] ??= ConversionSource::API->value;
        $result = $this->record($userId, $data);

        if (!$result['clickFound']) {
            throw new ClickNotFoundException('Click not found or not owned by user');
        }

        return $result['convId'];
    }

    /**
     * Single owner of the transactional conversion write used by every ingestion
     * path (the V3 API, the static pixel and postback endpoints, both uploads).
     *
     * In one transaction it: locks the source click (SELECT ... FOR UPDATE) so
     * concurrent and retried requests serialise; carries a pre-ledger click's
     * value into the ledger (MysqlConversionLedger::ensureManaged); decides the
     * row's dedupe key and answers a replay as a duplicate; inserts the ledger
     * row; runs the caller's click-side update (CPA cost, the filtered flag —
     * never the lead or the payout); recomputes the click's value from its
     * rows; and queues the conversion for MTA in the same transaction.
     *
     * @param array<string, mixed> $data Requires click_id. Optional:
     *        payout (an explicit amount; when absent the amount is the
     *        campaign's default — the click's current value in replace mode,
     *        the campaign payout in accumulate mode, because an accumulating
     *        click's cached value is a running total),
     *        transaction_id (the network's id; kept on the row for
     *        reconciliation and deduplicated as tx:<id>),
     *        dedupe_key (a source-built key from DedupeKey — uploads and goals
     *        pass their own; when absent the key is derived from the
     *        transaction id and the payout mode),
     *        source (a ConversionSource value; default api), source_ref,
     *        event_name, payable (default true),
     *        reversal (true = this reverses the earlier row carrying the same
     *        transaction_id; a negative explicit payout with a transaction id
     *        on file is a reversal too), reversal_ref (the network's reversal
     *        id, default "1"),
     *        once_per_click (true = record only while the click is not yet a
     *        lead; checked under the click lock),
     *        skip_ltv (do not write a revenue event), skip_bridge (do not emit
     *        conversion.recorded) — both for rows that are not new sales, such
     *        as upload lines,
     *        conv_time, campaign_id, click_time, and the legacy columns
     *        time_difference, ip, pixel_type, user_agent.
     *        LTV keys (all optional): customer_id, customer_ref +
     *        customer_ref_type, customer_crm, items.
     * @param (callable(int $clickId, float $payout): void)|null $clickSideUpdate
     *        Runs inside the transaction after the insert. It must not write
     *        click_lead or click_payout (ClickValueWritersTest enforces it):
     *        the recompute that follows owns both.
     * @return array{convId: int, duplicate: bool, clickFound: bool, customerId: int|null, dedupeKey?: string, reversesConvId?: int|null, campaignId?: int, clickTime?: int, payout?: float}
     * @throws ReversalException when a reversal names no row, or a different reversal of that row is on file
     */
    public function record(int $userId, array $data, ?callable $clickSideUpdate = null): array
    {
        $prepared = $this->prepareRecord($data);
        $work = fn (): array => $this->recordLocked($userId, $prepared, $clickSideUpdate);

        // Unique-key upserts on 202_customer_aliases under concurrency make a
        // deadlock reachable, not theoretical. The transaction callback is a
        // pure function of its arguments, so one full retry is safe.
        try {
            $result = $this->conn->transaction($work);
        } catch (Throwable $e) {
            if (!self::isRetryableLockError($e)) {
                throw $e;
            }
            $result = $this->conn->transaction($work);
        }

        $this->afterRecordCommit($userId, $prepared, $result);

        return $result;
    }

    /**
     * record()'s write, inside a transaction the CALLER holds — for a writer
     * that must record several ledger rows atomically with its own rows (the
     * goals engine: an event, its outcomes and their conversions commit or
     * roll back together). Same rules, same row, same recompute and outbox;
     * the only differences are that it opens no transaction (mysqli's
     * begin_transaction() inside an open one would commit the caller's work,
     * CLAUDE.md #13) and does not retry on a deadlock (the caller retries its
     * whole transaction). After the caller commits it must hand the result
     * to afterRecordCommit(), which refreshes the report row and emits the
     * post-commit bridge event exactly as record() does.
     *
     * Lock order: the caller may hold its own locks, but must take them before
     * this call locks the click and never lock anything after it that another
     * record() path locks before the click.
     *
     * @param array<string, mixed> $data as record()
     * @return array<string, mixed> as record(), plus '_prepared' for afterRecordCommit()
     * @throws ReversalException
     */
    public function recordInTransaction(int $userId, array $data): array
    {
        $prepared = $this->prepareRecord($data);
        $result = $this->recordLocked($userId, $prepared, null);
        $result['_prepared'] = $prepared;

        return $result;
    }

    /**
     * The post-commit half of a recordInTransaction() call: run it once the
     * caller's transaction has committed, never before (the report row and
     * the bridge event describe committed state).
     *
     * @param array<string, mixed> $result what recordInTransaction() returned
     */
    public function afterRecordInTransaction(int $userId, array $result): void
    {
        $prepared = $result['_prepared'] ?? null;
        if (!is_array($prepared)) {
            throw new RuntimeException('afterRecordInTransaction() needs the result recordInTransaction() returned');
        }
        $this->afterRecordCommit($userId, $prepared, $result);
    }

    /**
     * Normalise and validate what a caller hands record(): everything that
     * can be refused is refused here, before any lock is taken.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function prepareRecord(array $data): array
    {
        $clickId = (int) ($data['click_id'] ?? 0);
        if ($clickId <= 0) {
            throw new RuntimeException('click_id is required');
        }

        // Trim centrally so a blank/whitespace-only id is treated as absent
        // (stored NULL) across every ingestion path.
        $rawTransactionId = trim((string) ($data['transaction_id'] ?? ''));
        $transactionId = $rawTransactionId !== '' ? $rawTransactionId : null;
        $convTime = (int) ($data['conv_time'] ?? time());
        $explicitPayout = null;
        if (array_key_exists('payout', $data) && $data['payout'] !== null && $data['payout'] !== '') {
            $rawPayout = $data['payout'];
            if (!is_int($rawPayout) && !is_float($rawPayout) && !is_string($rawPayout)) {
                throw new RuntimeException('payout must be a number');
            }
            // Throws on anything that is not a decimal number: an amount that
            // cannot be read must never be recorded as 0.
            $explicitPayout = Amount::toUnits($rawPayout);
        }
        // The ip column is varchar(45): exactly one address fits, a forwarding
        // chain does not, and an over-long value fails the INSERT under strict
        // sql_mode and rolls the conversion back. Whatever a caller hands in,
        // the row gets one valid address or nothing (p202ClientIp() picks the
        // address at the endpoints; this is the writer's own floor).
        $ip = trim((string) ($data['ip'] ?? ''));
        $data['ip'] = $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';

        $source = ConversionSource::tryFrom((string) ($data['source'] ?? ConversionSource::API->value));
        if ($source === null) {
            throw new RuntimeException('source "' . (string) $data['source'] . '" is not a ledger source');
        }
        $payable = !array_key_exists('payable', $data) || (bool) $data['payable'];
        $wantsReversal = !empty($data['reversal']);

        return [
            'clickId' => $clickId,
            'transactionId' => $transactionId,
            'convTime' => $convTime,
            'explicitPayout' => $explicitPayout,
            'data' => $data,
            'source' => $source,
            'payable' => $payable,
            'wantsReversal' => $wantsReversal,
        ];
    }

    /**
     * The transactional body of record(): runs inside a transaction someone
     * else opened.
     *
     * @param array<string, mixed> $prepared from prepareRecord()
     * @return array<string, mixed>
     */
    private function recordLocked(int $userId, array $prepared, ?callable $clickSideUpdate): array
    {
        $clickId = (int) $prepared['clickId'];
        $transactionId = $prepared['transactionId'];
        $convTime = (int) $prepared['convTime'];
        $explicitPayout = $prepared['explicitPayout'];
        /** @var array<string, mixed> $data */
        $data = $prepared['data'];
        /** @var ConversionSource $source */
        $source = $prepared['source'];
        $payable = (bool) $prepared['payable'];
        $wantsReversal = (bool) $prepared['wantsReversal'];
        $ledger = new MysqlConversionLedger($this->conn);

        // Lock the source click so concurrent/retried requests serialise here.
        $clickStmt = $this->conn->prepareWrite(
            'SELECT click_id, aff_campaign_id, click_payout, click_time, click_lead FROM 202_clicks WHERE click_id = ? AND user_id = ? LIMIT 1 FOR UPDATE'
        );
        $this->conn->bind($clickStmt, 'ii', [$clickId, $userId]);
        $click = $this->conn->fetchOne($clickStmt);

        if ($click === null) {
            return ['convId' => 0, 'duplicate' => false, 'clickFound' => false, 'customerId' => null];
        }

        $clickCampaignId = (int) $click['aff_campaign_id'];
        $terms = $ledger->campaignTerms($clickCampaignId);

        // A reversal names the row it reverses by that row's transaction
        // id. It is its own row with its own key (never tx:<id>, which the
        // original holds), so a replay of the same reversal is a
        // duplicate and a second, different reversal of one sale is
        // refused rather than netting the sale twice.
        $reverses = null;
        if ($transactionId !== null && ($wantsReversal || ($explicitPayout !== null && $explicitPayout < 0))) {
            $targetStmt = $this->conn->prepareWrite(
                'SELECT conv_id, click_payout, deleted FROM 202_conversion_logs
                 WHERE click_id = ? AND transaction_id = ? AND reverses_conv_id IS NULL
                 ORDER BY conv_id LIMIT 1'
            );
            $this->conn->bind($targetStmt, 'is', [$clickId, $transactionId]);
            $target = $this->conn->fetchOne($targetStmt);
            if ($target !== null && (int) $target['deleted'] === 0) {
                $reverses = $target;
            } elseif ($wantsReversal) {
                throw new ReversalException(
                    'There is no conversion with transaction id "' . $transactionId . '" on click ' . $clickId
                    . ' to reverse' . ($target !== null ? ' (it was deleted)' : '') . '.',
                    ReversalException::NO_TARGET
                );
            }
        } elseif ($wantsReversal) {
            throw new ReversalException(
                'A reversal needs the transaction id of the conversion it reverses.',
                ReversalException::NO_TARGET
            );
        }

        if ($reverses !== null) {
            $reversalRef = trim((string) ($data['reversal_ref'] ?? ''));
            $dedupeKey = DedupeKey::reversal((int) $reverses['conv_id'], $reversalRef !== '' ? $reversalRef : '1');
        } elseif (isset($data['dedupe_key']) && $data['dedupe_key'] !== '') {
            $dedupeKey = (string) $data['dedupe_key'];
        } elseif ($transactionId !== null) {
            $dedupeKey = DedupeKey::transaction($transactionId);
        } elseif ($terms['mode'] === PayoutMode::ACCUMULATE && $payable) {
            // With no id of its own an accumulating row would double the
            // money on every retry, so it is the campaign's one plain
            // conversion: once per click.
            $dedupeKey = DedupeKey::plainConversion();
        } else {
            $dedupeKey = DedupeKey::rowPlaceholder();
        }
        if (strlen($dedupeKey) > DedupeKey::MAX_LENGTH) {
            throw new RuntimeException('dedupe key is longer than ' . DedupeKey::MAX_LENGTH . ' bytes');
        }

        // Idempotency: a key already on this click is a replay. The lookup
        // ignores `deleted` so it matches UNIQUE (click_id, dedupe_key)
        // and never collides on insert.
        $dupStmt = $this->conn->prepareWrite(
            'SELECT conv_id, customer_id FROM 202_conversion_logs WHERE click_id = ? AND dedupe_key = ? LIMIT 1'
        );
        $this->conn->bind($dupStmt, 'is', [$clickId, $dedupeKey]);
        $dup = $this->conn->fetchOne($dupStmt);
        if ($dup !== null) {
            return [
                'convId' => (int) $dup['conv_id'],
                'duplicate' => true,
                'clickFound' => true,
                'customerId' => $dup['customer_id'] !== null ? (int) $dup['customer_id'] : null,
                'dedupeKey' => $dedupeKey,
            ];
        }

        if ($reverses !== null) {
            $otherStmt = $this->conn->prepareWrite(
                'SELECT conv_id, dedupe_key FROM 202_conversion_logs
                 WHERE click_id = ? AND reverses_conv_id = ? AND deleted = 0 ORDER BY conv_id LIMIT 1'
            );
            $this->conn->bind($otherStmt, 'ii', [$clickId, (int) $reverses['conv_id']]);
            $other = $this->conn->fetchOne($otherStmt);
            if ($other !== null) {
                throw new ReversalException(
                    'Conversion ' . (int) $reverses['conv_id'] . ' (transaction id "' . $transactionId
                    . '") was already reversed by conversion ' . (int) $other['conv_id']
                    . ' (' . (string) $other['dedupe_key'] . '); a sale can be reversed once.',
                    ReversalException::CONFLICT
                );
            }
        }

        // One conversion per click for id-less pixels and postbacks. This
        // is the authoritative check: it reads click_lead from the row
        // locked FOR UPDATE above, so a second id-less request waits on
        // the first's commit and then sees click_lead = 1.
        if (!empty($data['once_per_click']) && (int) ($click['click_lead'] ?? 0) === 1) {
            return ['convId' => 0, 'duplicate' => true, 'clickFound' => true, 'customerId' => null];
        }

        // A click converted before the ledger holds its value only in its
        // cache; carry it into the ledger before this row changes it.
        $ledger->ensureManaged($click, $userId);

        if ($reverses !== null) {
            $amountUnits = -abs($explicitPayout ?? Amount::toUnits((string) $reverses['click_payout']));
        } elseif ($explicitPayout !== null) {
            $amountUnits = $explicitPayout;
        } elseif ($terms['mode'] === PayoutMode::ACCUMULATE) {
            $amountUnits = Amount::toUnits($terms['default_payout']);
        } else {
            $amountUnits = Amount::toUnits((string) ($click['click_payout'] ?? '0'));
        }
        $payout = (float) Amount::fromUnits($amountUnits);
        $campaignId = isset($data['campaign_id']) ? (int) $data['campaign_id'] : $clickCampaignId;
        $clickTime = isset($data['click_time']) ? (int) $data['click_time'] : (int) ($click['click_time'] ?? 0);

        // LTV: resolve the customer AFTER the click lock and dedup guard
        // (lock ordering: click → customer → ledger → line items) so replays
        // never double-count. A conversion with no identity signal records
        // unlinked, exactly as before the LTV feature.
        $customerId = empty($data['skip_ltv']) ? $this->resolveCustomer($userId, $clickId, $data, $convTime) : null;

        $columns = ['click_id', 'transaction_id', 'campaign_id', 'click_payout', 'user_id', 'click_time', 'conv_time'];
        $types = 'isisiii';
        $values = [$clickId, $transactionId, $campaignId, Amount::fromUnits($amountUnits), $userId, $clickTime, $convTime];

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
        // using the caller's value when supplied and a sensible default otherwise.
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

        // Provenance: what produced this row and what it is linked to.
        $sourceRef = isset($data['source_ref']) && $data['source_ref'] !== '' ? (string) $data['source_ref'] : null;
        if ($reverses !== null) {
            $sourceRef = 'conv:' . (int) $reverses['conv_id'];
        }
        $eventName = isset($data['event_name']) && $data['event_name'] !== '' ? (string) $data['event_name'] : null;
        array_push($columns, 'source', 'source_ref', 'event_name', 'payable', 'reverses_conv_id', 'dedupe_key');
        $types .= 'sssiis';
        array_push(
            $values,
            $source->value,
            $sourceRef,
            $eventName,
            $payable ? 1 : 0,
            $reverses !== null ? (int) $reverses['conv_id'] : null,
            $dedupeKey
        );

        $placeholders = rtrim(str_repeat('?, ', count($values)), ', ');
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_conversion_logs (' . implode(', ', $columns) . ', deleted) VALUES (' . $placeholders . ', 0)'
        );
        $this->conn->bind($stmt, $types, $values);
        $convId = $this->conn->executeInsert($stmt);
        if ($convId <= 0) {
            throw new RuntimeException('conversion insert for click ' . $clickId . ' returned no id');
        }

        if (str_starts_with($dedupeKey, 'row-pending:')) {
            $dedupeKey = DedupeKey::row($convId);
            $keyStmt = $this->conn->prepareWrite(
                'UPDATE 202_conversion_logs SET dedupe_key = ? WHERE conv_id = ?'
            );
            $this->conn->bind($keyStmt, 'si', [$dedupeKey, $convId]);
            if ($this->conn->executeUpdate($keyStmt) !== 1) {
                throw new RuntimeException('conversion ' . $convId . ': its dedupe key was not written');
            }
        }

        // LTV: append the purchase to the revenue ledger (source of truth),
        // bump the customer's cached rollups, attach product line items, and
        // cache the customer on the click. All inside this transaction.
        if ($customerId !== null) {
            $currency = $this->customers->accountCurrency($userId);
            // A negative payout is a correction. Ledger it as an
            // adjustment: recording it as a "purchase" would bump
            // order_count while draining revenue, corrupting LTV/AOV.
            $eventType = $payout < 0 ? 'adjustment' : 'purchase';
            $ledgerEvent = $this->customers->insertRevenueEvent($userId, $customerId, [
                'event_type' => $eventType,
                'amount' => $payout,
                'currency' => $currency,
                'occurred_at' => $convTime,
                'source' => 'conversion',
                'conv_id' => $convId,
                'click_id' => $clickId,
                'transaction_id' => $transactionId,
            ], $convTime);

            if ($ledgerEvent['inserted']) {
                $this->customers->applyEventToRollups($userId, $customerId, $eventType, $payout, $convTime, $convTime);
                $items = $data['items'] ?? [];
                if (is_array($items) && $items !== []) {
                    $this->customers->insertLineItems($userId, $ledgerEvent['eventId'], $items, $currency, $convTime, $payout);
                }
            }

            $this->customers->stampClickCustomer($clickId, $customerId);
        }

        if ($clickSideUpdate !== null) {
            $clickSideUpdate($clickId, $payout);
        }

        $ledger->recompute($clickId, $clickCampaignId);
        $ledger->enqueue(array_values(array_filter([$convId, $reverses !== null ? (int) $reverses['conv_id'] : null])), 'recorded');

        return [
            'convId' => $convId, 'duplicate' => false, 'clickFound' => true, 'customerId' => $customerId,
            'dedupeKey' => $dedupeKey,
            'reversesConvId' => $reverses !== null ? (int) $reverses['conv_id'] : null,
            // threaded out for the post-commit bridge emit (closure locals)
            'campaignId' => $campaignId, 'clickTime' => $clickTime, 'payout' => $payout,
        ];
    }

    /**
     * What follows a committed record(): the report row and the bridge event.
     *
     * @param array<string, mixed> $prepared
     * @param array<string, mixed> $result
     */
    private function afterRecordCommit(int $userId, array $prepared, array $result): void
    {
        $clickId = (int) $prepared['clickId'];
        $transactionId = $prepared['transactionId'];
        $convTime = (int) $prepared['convTime'];
        /** @var array<string, mixed> $data */
        $data = $prepared['data'];

        if ($result['clickFound'] && !$result['duplicate']) {
            $this->refreshReportRollup($clickId);
        }

        // Landing Page Optimizer bridge: post-commit, NEW conversions only (duplicates and
        // missing clicks never emit). EventBridge swallows its own failures, so a
        // bridge hiccup never breaks recording.
        if ($result['clickFound'] && !$result['duplicate'] && empty($data['skip_bridge'])) {
            EventBridge::emit($this->conn, $userId, 'conversion.recorded', [
                'idempotency_key' => $clickId . ':' . ($transactionId !== null
                    ? $transactionId
                    // blank txids are stored as distinct rows: key by
                    // conversion row id so consumers never collapse them
                    : 'conv:' . $result['convId']),
                'click_id'        => $clickId,
                'transaction_id'  => $transactionId !== null ? $transactionId : '',
                'conv_id'         => $result['convId'],
                'campaign_id'     => (int) ($result['campaignId'] ?? 0),
                'payout'          => (float) ($result['payout'] ?? 0),
                'conv_time'       => $convTime,
                'click_time'      => (int) ($result['clickTime'] ?? 0),
                'pixel_type'      => (int) ($data['pixel_type'] ?? 0),
                'ip'              => (string) ($data['ip'] ?? ''),
            ]);
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
     * True for MySQL deadlock (1213) / lock wait timeout (1205). The
     * detection logic lives once on Connection so every ingest path applies
     * the same rules.
     */
    private static function isRetryableLockError(Throwable $e): bool
    {
        return Connection::isRetryableLockError($e);
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
        $work = fn (): ?int => $this->softDeleteLocked($id, $userId);

        try {
            $touched = $this->conn->transaction($work);
        } catch (Throwable $e) {
            if (!self::isRetryableLockError($e)) {
                throw $e;
            }
            $touched = $this->conn->transaction($work);
        }
        if ($touched !== null) {
            $this->refreshReportRollup($touched);
        }
    }

    /**
     * softDelete() inside a transaction the caller holds (see
     * recordInTransaction() for why and for the lock order). Returns the
     * click whose value changed, or null when nothing was deleted; once the
     * caller has committed, it hands that to refreshClickReport().
     */
    public function softDeleteInTransaction(int $id, int $userId): ?int
    {
        return $this->softDeleteLocked($id, $userId);
    }

    /**
     * The goals engine retires a goal row with nothing in its place (a
     * re-evaluation under which the outcome is no longer reached): the row
     * is soft-deleted exactly as softDelete() does — click recomputed, MTA
     * queued, its revenue event voided — and, in the same statement, marked
     * with the engine's reason. The mark is the row's provenance: it is what
     * lets reviveGoalRowInTransaction() tell a deletion the engine made (and
     * may undo) from one an operator made (which it must not). A row that is
     * already deleted is left exactly as it is, unmarked, and null returned.
     *
     * Inside a transaction the caller holds (see recordInTransaction()).
     * Returns the click whose value changed, or null.
     */
    public function retireGoalRowInTransaction(int $convId, int $userId, SupersededReason $reason): ?int
    {
        if ($reason !== SupersededReason::REPLAY && $reason !== SupersededReason::REEVALUATION) {
            throw new LedgerIntegrityException('a goal row is retired by a replay or a re-evaluation, not by "' . $reason->value . '"');
        }
        $this->assertGoalRow($convId, $userId);

        return $this->softDeleteLocked($convId, $userId, $reason);
    }

    /**
     * Undo the engine's retirement of a goal row: a reconciliation re-derived
     * exactly the outcome this row records (same goal, version, n and event),
     * so the row counts again as it did before the engine retired it.
     *
     * The row is restored from whichever retirement happened:
     * - superseded by the engine (reason replay/reevaluation, not deleted):
     *   the mark is lifted;
     * - deleted by the engine (deleted, reason replay/reevaluation, no
     *   superseding row — retireGoalRowInTransaction()): it is undeleted and
     *   the mark lifted, and when its deletion voided a revenue event the
     *   amount is posted to the customer again (see reinstateRevenueEvent());
     * - deleted by anyone else (no engine mark): left deleted — the engine
     *   restores what it retired, never what an operator removed;
     * - already counting as the ledger decides (no fixed mark): unchanged.
     * Any other state (a pre_ledger mark on a goal row) is refused.
     *
     * The caller names the click and dedupe key the outcome says its row
     * has; a row that is not that goal row (a broken link) is refused
     * rather than revived, so a revival can never pay a click for an
     * outcome that row does not record. The UNIQUE (click_id, dedupe_key)
     * key is what guarantees there is no second row for the outcome to
     * conflict with; the key check is what ties this row to it.
     *
     * Inside a transaction the caller holds; lock order click, then
     * conversion, as on every path here. Returns the click whose value may
     * have changed (for refreshClickReport() after commit), or null when
     * nothing was written.
     *
     * @throws LedgerIntegrityException
     */
    public function reviveGoalRowInTransaction(int $convId, int $userId, int $clickId, string $dedupeKey): ?int
    {
        $found = $this->assertGoalRow($convId, $userId);
        if ((int) $found['click_id'] !== $clickId || (string) $found['dedupe_key'] !== $dedupeKey) {
            throw new LedgerIntegrityException(
                'conversion ' . $convId . ' is click ' . (int) $found['click_id'] . '\'s row "' . (string) $found['dedupe_key']
                . '", not click ' . $clickId . '\'s "' . $dedupeKey . '"; the outcome that names it does not record it, so it is not revived'
            );
        }

        $clickStmt = $this->conn->prepareWrite(
            'SELECT click_id, aff_campaign_id FROM 202_clicks WHERE click_id = ? AND user_id = ? LIMIT 1 FOR UPDATE'
        );
        $this->conn->bind($clickStmt, 'ii', [$clickId, $userId]);
        $click = $this->conn->fetchOne($clickStmt);
        if ($click === null) {
            throw new LedgerIntegrityException('conversion ' . $convId . ' names click ' . $clickId . ', which does not exist');
        }

        $convStmt = $this->conn->prepareWrite(
            'SELECT conv_id, customer_id, deleted, superseded_reason, superseded_by FROM 202_conversion_logs
             WHERE conv_id = ? AND user_id = ? LIMIT 1 FOR UPDATE'
        );
        $this->conn->bind($convStmt, 'ii', [$convId, $userId]);
        $conv = $this->conn->fetchOne($convStmt);
        if ($conv === null) {
            throw new LedgerIntegrityException('conversion ' . $convId . ' disappeared under its click lock');
        }

        $reason = $conv['superseded_reason'] !== null && $conv['superseded_reason'] !== ''
            ? SupersededReason::tryFrom((string) $conv['superseded_reason']) : null;
        if ($conv['superseded_reason'] !== null && $conv['superseded_reason'] !== '' && $reason === null) {
            throw new LedgerIntegrityException('conversion ' . $convId . ' has superseded_reason "' . (string) $conv['superseded_reason'] . '", which is not a known reason');
        }
        $engineMark = $reason === SupersededReason::REPLAY || $reason === SupersededReason::REEVALUATION;
        $deleted = (int) $conv['deleted'] === 1;

        if ($deleted) {
            if (!$engineMark || $conv['superseded_by'] !== null) {
                return null; // deleted by someone other than the engine: it stays deleted
            }
            $stmt = $this->conn->prepareWrite(
                "UPDATE 202_conversion_logs SET deleted = 0, superseded_by = NULL, superseded_reason = NULL
                 WHERE conv_id = ? AND deleted = 1 AND superseded_reason IN ('replay', 'reevaluation') AND superseded_by IS NULL"
            );
            $this->conn->bind($stmt, 'i', [$convId]);
            if ($this->conn->executeUpdate($stmt) !== 1) {
                throw new LedgerIntegrityException('conversion ' . $convId . ' was not undeleted');
            }
            $this->reinstateRevenueEvent($convId, $conv['customer_id'] !== null ? (int) $conv['customer_id'] : 0, $userId, $clickId);
        } elseif ($engineMark) {
            $stmt = $this->conn->prepareWrite(
                "UPDATE 202_conversion_logs SET superseded_by = NULL, superseded_reason = NULL
                 WHERE conv_id = ? AND deleted = 0 AND superseded_reason IN ('replay', 'reevaluation')"
            );
            $this->conn->bind($stmt, 'i', [$convId]);
            if ($this->conn->executeUpdate($stmt) !== 1) {
                throw new LedgerIntegrityException('conversion ' . $convId . '\'s supersession was not lifted');
            }
        } elseif ($reason !== null && !$reason->isDerived()) {
            throw new LedgerIntegrityException('goal conversion ' . $convId . ' is superseded as "' . $reason->value . '", which no goal row can be; it is not revived');
        } else {
            return null; // counting as the ledger decides: nothing was retired
        }

        $ledger = new MysqlConversionLedger($this->conn);
        $ledger->recompute($clickId, (int) $click['aff_campaign_id']);
        $ledger->enqueue([$convId], 'counted_state');

        return $clickId;
    }

    /**
     * A goal row of this account, or a refusal naming what it is instead.
     * The built-in install goal's row (source app_install) is a goal row
     * too: the engine writes it for that goal's outcome, and a withdrawn
     * install credit retires it and a restored one revives it
     * (GoalEngine::recreditInstallInTransaction()).
     *
     * @return array{click_id: int|string, dedupe_key: string}
     */
    private function assertGoalRow(int $convId, int $userId): array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT click_id, source, dedupe_key FROM 202_conversion_logs WHERE conv_id = ? AND user_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'ii', [$convId, $userId]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            throw new LedgerIntegrityException('conversion ' . $convId . ' does not exist');
        }
        if ((string) $row['source'] !== ConversionSource::GOAL->value && (string) $row['source'] !== ConversionSource::APP_INSTALL->value) {
            throw new LedgerIntegrityException('conversion ' . $convId . ' is a ' . (string) $row['source'] . ' row, not a goal row');
        }

        return ['click_id' => $row['click_id'], 'dedupe_key' => (string) $row['dedupe_key']];
    }

    /**
     * Post-commit: refresh the report row of a click whose value a caller's
     * committed transaction changed (softDeleteInTransaction(), or a goal
     * row superseded through MysqlConversionLedger).
     */
    public function refreshClickReport(int $clickId): void
    {
        $this->refreshReportRollup($clickId);
    }

    /**
     * @param SupersededReason|null $engineMark the goals engine's reason, written with the deletion
     *        (retireGoalRowInTransaction()); null for every other delete
     */
    private function softDeleteLocked(int $id, int $userId, ?SupersededReason $engineMark = null): ?int
    {
        $ledger = new MysqlConversionLedger($this->conn);

        // Lock order is click, then conversion, on every path that writes
        // both (record() locks the click first), so find the click before
        // taking any lock.
        $findStmt = $this->conn->prepareWrite(
            'SELECT click_id FROM 202_conversion_logs WHERE conv_id = ? AND user_id = ? LIMIT 1'
        );
        $this->conn->bind($findStmt, 'ii', [$id, $userId]);
        $found = $this->conn->fetchOne($findStmt);
        if ($found === null) {
            return null;
        }
        $clickId = (int) $found['click_id'];

        $clickStmt = $this->conn->prepareWrite(
            'SELECT click_id, aff_campaign_id, click_payout, click_time, click_lead FROM 202_clicks
             WHERE click_id = ? LIMIT 1 FOR UPDATE'
        );
        $this->conn->bind($clickStmt, 'i', [$clickId]);
        $click = $this->conn->fetchOne($clickStmt);

        // Lock the conversion row so concurrent deletes serialize here.
        $convStmt = $this->conn->prepareWrite(
            'SELECT conv_id, customer_id, deleted, reverses_conv_id FROM 202_conversion_logs
             WHERE conv_id = ? AND user_id = ? LIMIT 1 FOR UPDATE'
        );
        $this->conn->bind($convStmt, 'ii', [$id, $userId]);
        $conv = $this->conn->fetchOne($convStmt);
        if ($conv === null || (int) $conv['deleted'] === 1) {
            // Missing or already deleted: nothing to do (matches the
            // historical silent-no-op semantics of this method).
            return null;
        }

        if ($click !== null) {
            // A pre-ledger click keeps the value only its cache knows;
            // carry it in first so deleting an old row cannot zero it.
            $ledger->ensureManaged($click, $userId);
        }

        if ($engineMark === null) {
            $stmt = $this->conn->prepareWrite(
                'UPDATE 202_conversion_logs SET deleted = 1 WHERE conv_id = ? AND user_id = ?'
            );
            $this->conn->bind($stmt, 'ii', [$id, $userId]);
            $this->conn->executeUpdate($stmt);
        } else {
            // The mark is the provenance a revival reads; a deletion that
            // did not carry it would be one the engine can never undo.
            $stmt = $this->conn->prepareWrite(
                'UPDATE 202_conversion_logs SET deleted = 1, superseded_by = NULL, superseded_reason = ? WHERE conv_id = ? AND user_id = ? AND deleted = 0'
            );
            $this->conn->bind($stmt, 'sii', [$engineMark->value, $id, $userId]);
            if ($this->conn->executeUpdate($stmt) !== 1) {
                throw new LedgerIntegrityException('goal conversion ' . $id . ' was not retired');
            }
        }

        // What counts changes for this row, for the reversals that name
        // it (they stop netting), and for the row it reverses (it nets
        // no more).
        $affected = [$id];
        if ($conv['reverses_conv_id'] !== null) {
            $affected[] = (int) $conv['reverses_conv_id'];
        }
        $revStmt = $this->conn->prepareWrite(
            'SELECT conv_id FROM 202_conversion_logs WHERE click_id = ? AND reverses_conv_id = ?'
        );
        $this->conn->bind($revStmt, 'ii', [$clickId, $id]);
        foreach ($this->conn->fetchAll($revStmt) as $rev) {
            $affected[] = (int) $rev['conv_id'];
        }

        if ($click !== null) {
            $ledger->recompute($clickId, (int) $click['aff_campaign_id']);
        }
        $ledger->enqueue($affected, 'counted_state');

        $this->voidRevenueEvent($id, $conv['customer_id'] !== null ? (int) $conv['customer_id'] : 0, $userId);

        return $clickId;
    }

    /**
     * Refresh the click's row in 202_dataengine, the table every report
     * reads, after a committed change to its value. The ledger updates
     * 202_clicks inside the transaction; without this the reports kept the
     * old figures until something else happened to re-roll the click, and a
     * conversion recorded through the API, the revenue upload or the subid
     * pages never reached them at all (only the pixel helpers re-rolled).
     *
     * Post-commit and best-effort: the write has landed, so a failure here
     * must not read as a failed write (CLAUDE.md #13). It is logged with the
     * click id, and the next change to the click re-rolls it.
     */
    private function refreshReportRollup(int $clickId): void
    {
        try {
            $stmt = $this->conn->prepareWrite(ClickRollupSql::insertSelect(
                '202_dataengine',
                '2c.click_id=' . $clickId,
                updateLandingPageId: true
            ));
            $this->conn->executeUpdate($stmt);
        } catch (Throwable $e) {
            error_log('conversion ledger: click ' . $clickId . ' changed but its report row was not refreshed: ' . $e->getMessage());
        }
    }

    /**
     * Void a deleted conversion's revenue event: a compensating 'adjustment'
     * with a deterministic idempotency key ('void:conv:{id}'), so repeated
     * deletes compensate exactly once and the ledger stays append-only. Runs
     * inside the caller's transaction.
     */
    private function voidRevenueEvent(int $id, int $customerId, int $userId): void
    {
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
        // Only an order-type event may subtract an order — a voided
        // negative-payout conversion was ledgered as an adjustment and
        // never bumped order_count. The external_ref PREFIX encodes this
        // for the reconcile jobs ('void:' counts -1 order, 'void-nc:'
        // does not); the idempotency key stays identical either way so a
        // repeated delete can never double-void. A goal row the engine
        // revived was posted again (reinstateRevenueEvent()), so its next
        // deletion voids that posting: the key carries the generation
        // (void:conv:<id> first, then void:conv:<id>:<n> after the n-th
        // reinstatement), and within a generation a repeat is still one void.
        $voidedAnOrder = in_array((string) $event['event_type'], MysqlCustomerRepository::ORDER_EVENT_TYPES, true);
        $suffix = self::revenueGenerationSuffix($this->revenueGeneration($id, $userId));
        $void = $this->customers->insertRevenueEvent($userId, $customerId, [
            'event_type' => 'adjustment',
            'amount' => -(float) $event['amount'],
            'currency' => (string) $event['currency'],
            'occurred_at' => $now,
            'source' => 'conversion',
            'external_ref' => ($voidedAnOrder ? 'void:conv:' : 'void-nc:conv:') . $id . $suffix,
            'idempotency_key' => 'void:conv:' . $id . $suffix,
        ], $now);

        if ($void['inserted']) {
            $this->customers->adjustRollups($userId, $customerId, $voidedAnOrder ? -1 : 0, -(float) $event['amount'], 0.0, $now, $now);

            // Mirror the sale's line items negated (amount AND quantity)
            // onto the void event so product revenue/unit reports net the
            // deleted conversion out, exactly like the customer totals do.
            $itemsStmt = $this->conn->prepareWrite(
                'INSERT INTO 202_revenue_line_items
                    (user_id, event_id, product_id, sku, product_name, quantity, unit_price, amount, created_at)
                 SELECT user_id, ?, product_id, sku, product_name, -quantity, unit_price, -amount, ?
                 FROM 202_revenue_line_items WHERE event_id = ?'
            );
            $this->conn->bind($itemsStmt, 'iii', [$void['eventId'], $now, (int) $event['event_id']]);
            $this->conn->execute($itemsStmt);
            $itemsStmt->close();
        }
    }

    /**
     * The revived half of voidRevenueEvent(): a goal row the engine had
     * deleted counts again, so the revenue its deletion voided is posted to
     * the customer again — a new event of the original's type and amount
     * (an order again when it was one, so the reconcile jobs' order count
     * and the cache agree), keyed reinstate:conv:<id>:<n>. The ledger stays
     * append-only: neither the purchase nor its void is touched. Nothing is
     * posted when the deletion voided nothing (an unlinked row, or none on
     * file for this generation). Runs inside the caller's transaction.
     */
    private function reinstateRevenueEvent(int $id, int $customerId, int $userId, int $clickId): void
    {
        if ($customerId <= 0) {
            return;
        }
        $eventStmt = $this->conn->prepareWrite(
            'SELECT event_id, amount, currency, event_type, transaction_id FROM 202_revenue_events WHERE conv_id = ? LIMIT 1'
        );
        $this->conn->bind($eventStmt, 'i', [$id]);
        $event = $this->conn->fetchOne($eventStmt);
        if ($event === null) {
            return;
        }
        $generation = $this->revenueGeneration($id, $userId);
        $voidStmt = $this->conn->prepareWrite(
            'SELECT 1 FROM 202_revenue_events WHERE user_id = ? AND idempotency_key = ? LIMIT 1'
        );
        $this->conn->bind($voidStmt, 'is', [$userId, 'void:conv:' . $id . self::revenueGenerationSuffix($generation)]);
        if ($this->conn->fetchOne($voidStmt) === null) {
            return; // the deletion voided nothing, so there is nothing to post again
        }

        $now = time();
        $key = 'reinstate:conv:' . $id . ':' . ($generation + 1);
        $eventType = (string) $event['event_type'];
        $amount = (float) $event['amount'];
        $posted = $this->customers->insertRevenueEvent($userId, $customerId, [
            'event_type' => $eventType,
            'amount' => $amount,
            'currency' => (string) $event['currency'],
            'occurred_at' => $now,
            'source' => 'conversion',
            'click_id' => $clickId,
            'external_ref' => $key,
            'transaction_id' => $event['transaction_id'] !== null ? (string) $event['transaction_id'] : null,
            'idempotency_key' => $key,
        ], $now);
        if (!$posted['inserted']) {
            throw new LedgerIntegrityException('conversion ' . $id . ': its revenue was already reinstated as ' . $key . ' without a void since');
        }
        $this->customers->applyEventToRollups($userId, $customerId, $eventType, $amount, $now, $now);

        // The sale's line items again, as the void mirrored them negated.
        $itemsStmt = $this->conn->prepareWrite(
            'INSERT INTO 202_revenue_line_items
                (user_id, event_id, product_id, sku, product_name, quantity, unit_price, amount, created_at)
             SELECT user_id, ?, product_id, sku, product_name, quantity, unit_price, amount, ?
             FROM 202_revenue_line_items WHERE event_id = ?'
        );
        $this->conn->bind($itemsStmt, 'iii', [$posted['eventId'], $now, (int) $event['event_id']]);
        $this->conn->execute($itemsStmt);
        $itemsStmt->close();
    }

    /** How many times a conversion's revenue was reinstated (reinstateRevenueEvent()). */
    private function revenueGeneration(int $id, int $userId): int
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT COUNT(*) AS n FROM 202_revenue_events WHERE user_id = ? AND idempotency_key LIKE ?'
        );
        $this->conn->bind($stmt, 'is', [$userId, 'reinstate:conv:' . $id . ':%']);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            throw new RuntimeException('conversion ' . $id . ': its revenue generation could not be read');
        }

        return (int) $row['n'];
    }

    private static function revenueGenerationSuffix(int $generation): string
    {
        return $generation === 0 ? '' : ':' . $generation;
    }

    /**
     * Clear the conversions of the given clicks: the "delete subids" and
     * "clear subids" pages.
     *
     * Those pages used to set click_lead = 0 and leave every conversion row
     * in place, so the rows and the click disagreed from then on. Now each
     * click is cleared the way the ledger clears anything: under the click's
     * lock, a pre-ledger value is first carried in as its baseline row, every
     * live row is soft-deleted (and its revenue event voided, as softDelete()
     * does), and the click's value is recomputed from what is left — nothing,
     * so it is no longer a lead. One transaction per click, so a failure
     * part-way through a large clear leaves every click either cleared or
     * untouched, never half of one.
     *
     * @param list<int> $clickIds
     * @return int How many of the clicks belonged to the account and were cleared.
     */
    public function clearClicks(int $userId, array $clickIds): int
    {
        $ledger = new MysqlConversionLedger($this->conn);
        $cleared = 0;

        foreach (array_unique($clickIds) as $clickId) {
            $clickId = (int) $clickId;
            if ($clickId <= 0) {
                continue;
            }
            $work = function () use ($clickId, $userId, $ledger): bool {
                $clickStmt = $this->conn->prepareWrite(
                    'SELECT click_id, aff_campaign_id, click_payout, click_time, click_lead FROM 202_clicks
                     WHERE click_id = ? AND user_id = ? LIMIT 1 FOR UPDATE'
                );
                $this->conn->bind($clickStmt, 'ii', [$clickId, $userId]);
                $click = $this->conn->fetchOne($clickStmt);
                if ($click === null) {
                    return false;
                }

                $ledger->ensureManaged($click, $userId);

                $rowsStmt = $this->conn->prepareWrite(
                    'SELECT conv_id, customer_id FROM 202_conversion_logs
                     WHERE click_id = ? AND user_id = ? AND deleted = 0 FOR UPDATE'
                );
                $this->conn->bind($rowsStmt, 'ii', [$clickId, $userId]);
                $rows = $this->conn->fetchAll($rowsStmt);

                $deleted = [];
                foreach ($rows as $row) {
                    $convId = (int) $row['conv_id'];
                    $del = $this->conn->prepareWrite('UPDATE 202_conversion_logs SET deleted = 1 WHERE conv_id = ?');
                    $this->conn->bind($del, 'i', [$convId]);
                    $this->conn->executeUpdate($del);
                    $this->voidRevenueEvent($convId, $row['customer_id'] !== null ? (int) $row['customer_id'] : 0, $userId);
                    $deleted[] = $convId;
                }

                $ledger->recompute($clickId, (int) $click['aff_campaign_id']);
                if ($deleted !== []) {
                    $ledger->enqueue($deleted, 'counted_state');
                }

                return true;
            };

            try {
                $ok = $this->conn->transaction($work);
            } catch (Throwable $e) {
                if (!self::isRetryableLockError($e)) {
                    throw $e;
                }
                $ok = $this->conn->transaction($work);
            }
            if ($ok) {
                $cleared++;
                $this->refreshReportRollup($clickId);
            }
        }

        return $cleared;
    }
}
