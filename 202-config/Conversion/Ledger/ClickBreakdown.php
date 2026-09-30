<?php

declare(strict_types=1);

namespace Prosper202\Conversion\Ledger;

use Prosper202\Database\Connection;

/**
 * A click's value, explained row by row: the read behind
 * `GET /api/v3/clicks/{id}/conversions`, `p202 click conversions` and the
 * breakdown the Visitors and Spy pages open.
 *
 * Every conversion row of the click is listed — counted, unpaid, superseded,
 * deleted and reversals alike — with its amount, what produced it (source)
 * and what that is (source_ref resolved to a goal and version, an upload, a
 * reversed conversion or an API key), its transaction id and time, and
 * whether it counts toward the click's value with the reason when it does
 * not. Which rows count is ClickValueCalculator's answer (LedgerExplainer),
 * and the click's cached value is compared with it, so a breakdown that
 * does not add up to the figure the reports show says so instead of
 * looking right.
 *
 * Read-only: it takes no lock and writes nothing.
 */
final class ClickBreakdown
{
    public function __construct(private Connection $conn)
    {
    }

    /**
     * @param int|null $ownerUserId the account the click must belong to; null
     *        for a session that may see every account's clicks (the report
     *        pages' non-publisher sessions)
     * @return array{click: array<string, mixed>, rows: list<array<string, mixed>>}|null
     *         null when there is no such click for that owner
     */
    public function forClick(int $clickId, ?int $ownerUserId): ?array
    {
        if ($clickId <= 0) {
            return null;
        }
        $sql = 'SELECT c.click_id, c.user_id, c.aff_campaign_id, c.click_lead, c.click_payout, c.click_time,
                       ac.aff_campaign_name
                FROM 202_clicks c
                LEFT JOIN 202_aff_campaigns ac ON ac.aff_campaign_id = c.aff_campaign_id
                WHERE c.click_id = ?' . ($ownerUserId !== null ? ' AND c.user_id = ?' : ' AND c.user_id <> 0') . ' LIMIT 1';
        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, $ownerUserId !== null ? 'ii' : 'i', $ownerUserId !== null ? [$clickId, $ownerUserId] : [$clickId]);
        $click = $this->conn->fetchOne($stmt);
        if ($click === null) {
            return null;
        }
        $userId = (int) $click['user_id'];

        $terms = (new MysqlConversionLedger($this->conn))->campaignTerms((int) $click['aff_campaign_id']);

        $stmt = $this->conn->prepareRead(
            'SELECT conv_id, click_id, click_payout, payable, deleted, reverses_conv_id, source, source_ref,
                    superseded_reason, superseded_by, transaction_id, event_name, conv_time
             FROM 202_conversion_logs WHERE click_id = ? ORDER BY conv_id'
        );
        $this->conn->bind($stmt, 'i', [$clickId]);
        $stored = $this->conn->fetchAll($stmt);

        $ledgerRows = [];
        // A click is ledger-managed once any row of it is not a pre-ledger
        // row, whatever else that row's state is.
        $managed = false;
        foreach ($stored as $r) {
            $row = MysqlConversionLedger::rowFrom($r);
            $ledgerRows[] = $row;
            if ($row->supersededReason !== SupersededReason::PRE_LEDGER) {
                $managed = true;
            }
        }
        $explained = LedgerExplainer::explain($ledgerRows, $terms['mode']);
        $refs = $this->resolveRefs($stored, $userId);

        $rows = [];
        $counted = 0;
        foreach ($stored as $r) {
            $convId = (int) $r['conv_id'];
            $verdict = $explained['rows'][$convId];
            $supersededReason = $verdict['superseded_reason'];
            if ($verdict['counted']) {
                $counted++;
            }
            $explanation = null;
            if ($verdict['reason'] === NotCountedReason::SUPERSEDED && $supersededReason !== null) {
                $explanation = $supersededReason->explanation();
            } elseif ($verdict['reason'] !== null) {
                $explanation = $verdict['reason']->explanation();
            }
            $source = ConversionSource::from((string) $r['source']);
            $rows[] = [
                'conv_id' => $convId,
                'click_id' => (int) $r['click_id'],
                'amount' => Amount::fromUnits(Amount::toUnits((string) $r['click_payout'])),
                'payable' => (int) $r['payable'] === 1,
                'deleted' => (int) $r['deleted'] === 1,
                'counted' => $verdict['counted'],
                'not_counted_reason' => $verdict['reason']?->value,
                'superseded_reason' => $supersededReason?->value,
                'superseded_by' => $verdict['superseded_by'],
                'explanation' => $explanation,
                'source' => $source->value,
                'source_label' => $source->label(),
                'source_ref' => $r['source_ref'] !== null && $r['source_ref'] !== '' ? (string) $r['source_ref'] : null,
                'linked_to' => $refs[$convId] ?? null,
                'event_name' => $r['event_name'] !== null && $r['event_name'] !== '' ? (string) $r['event_name'] : null,
                'transaction_id' => $r['transaction_id'] !== null && $r['transaction_id'] !== '' ? (string) $r['transaction_id'] : null,
                'reverses_conv_id' => $r['reverses_conv_id'] !== null ? (int) $r['reverses_conv_id'] : null,
                'conv_time' => (int) $r['conv_time'],
            ];
        }

        $value = $explained['value'];
        $cachedLead = (int) $click['click_lead'] === 1;
        $cachedUnits = Amount::toUnits((string) $click['click_payout']);
        // A click converted before the ledger holds its value in its cache
        // until its next conversion carries it in as a legacy_baseline row
        // (MysqlConversionLedger::ensureManaged); until then no row of it
        // counts, and that is the expected state, not a mismatch.
        $preLedger = !$managed && $cachedLead;
        if ($preLedger) {
            $matches = true;
        } else {
            $matches = $cachedLead === $value->lead
                && (!$value->lead || $cachedUnits === (int) $value->valueUnits);
        }

        return [
            'click' => [
                'click_id' => (int) $click['click_id'],
                'campaign_id' => (int) $click['aff_campaign_id'],
                'campaign_name' => $click['aff_campaign_name'] !== null ? (string) $click['aff_campaign_name'] : null,
                'payout_mode' => $terms['mode']->value,
                'lead' => $cachedLead,
                'click_payout' => Amount::fromUnits($cachedUnits),
                'ledger_state' => $preLedger ? 'pre_ledger' : 'ledger',
                'ledger_value' => $value->lead ? Amount::fromUnits((int) $value->valueUnits) : null,
                'matches_click' => $matches,
                'rows' => count($rows),
                'counted_rows' => $counted,
            ],
            'rows' => $rows,
        ];
    }

    /**
     * Resolve every row's source_ref into what it names, reading each kind
     * once for the whole click and only within the click's own account.
     *
     * @param list<array<string, mixed>> $stored
     * @return array<int, array<string, mixed>> keyed by conv_id
     */
    private function resolveRefs(array $stored, int $userId): array
    {
        $parsed = [];
        $goalIds = [];
        $batchIds = [];
        $wantKeys = false;
        foreach ($stored as $r) {
            $ref = SourceRef::parse($r['source_ref'] !== null ? (string) $r['source_ref'] : null);
            if ($ref === null) {
                continue;
            }
            $parsed[(int) $r['conv_id']] = $ref;
            if ($ref['kind'] === SourceRef::GOAL) {
                $goalIds[(int) $ref['id']] = true;
            } elseif ($ref['kind'] === SourceRef::UPLOAD_BATCH) {
                $batchIds[(int) $ref['id']] = true;
            } elseif ($ref['kind'] === SourceRef::API_KEY) {
                $wantKeys = true;
            }
        }

        $goals = $goalIds === [] ? [] : $this->byId(
            'SELECT goal_id AS id, name, archived_at FROM 202_goals WHERE user_id = ? AND goal_id IN (%s)',
            $userId,
            array_keys($goalIds)
        );
        $batches = $batchIds === [] ? [] : $this->byId(
            'SELECT batch_id AS id, file_name, uploaded_at FROM 202_conversion_uploads WHERE user_id = ? AND batch_id IN (%s)',
            $userId,
            array_keys($batchIds)
        );
        $keys = [];
        if ($wantKeys) {
            $stmt = $this->conn->prepareRead('SELECT api_key, created_at FROM 202_api_keys WHERE user_id = ?');
            $this->conn->bind($stmt, 'i', [$userId]);
            foreach ($this->conn->fetchAll($stmt) as $k) {
                $keys[SourceRef::keyDigest((string) $k['api_key'])] = (int) $k['created_at'];
            }
        }

        $out = [];
        foreach ($parsed as $convId => $ref) {
            switch ($ref['kind']) {
                case SourceRef::GOAL:
                    $goal = $goals[(int) $ref['id']] ?? null;
                    $name = $goal !== null ? (string) $goal['name'] : null;
                    $out[$convId] = [
                        'type' => SourceRef::GOAL,
                        'goal_id' => (int) $ref['id'],
                        'goal_version' => (int) $ref['version'],
                        'name' => $name,
                        'archived' => $goal !== null && $goal['archived_at'] !== null,
                        'label' => $name !== null
                            ? 'Goal "' . $name . '" v' . (int) $ref['version']
                            : 'Goal ' . (int) $ref['id'] . ' v' . (int) $ref['version'] . ' (not found)',
                    ];
                    break;
                case SourceRef::UPLOAD_BATCH:
                    $batch = $batches[(int) $ref['id']] ?? null;
                    $out[$convId] = [
                        'type' => SourceRef::UPLOAD_BATCH,
                        'batch_id' => (int) $ref['id'],
                        'file_name' => $batch !== null ? (string) $batch['file_name'] : null,
                        'uploaded_at' => $batch !== null ? (int) $batch['uploaded_at'] : null,
                        'label' => $batch !== null
                            ? 'Upload ' . (int) $ref['id'] . ' (' . (string) $batch['file_name'] . ')'
                            : 'Upload ' . (int) $ref['id'] . ' (not found)',
                    ];
                    break;
                case SourceRef::CONVERSION:
                    $out[$convId] = [
                        'type' => SourceRef::CONVERSION,
                        'conv_id' => (int) $ref['id'],
                        'label' => 'Reverses conversion ' . (int) $ref['id'],
                    ];
                    break;
                case SourceRef::API_KEY:
                    $created = $keys[(string) $ref['digest']] ?? null;
                    $out[$convId] = [
                        'type' => SourceRef::API_KEY,
                        'key_digest' => (string) $ref['digest'],
                        'key_created_at' => $created,
                        'revoked' => $created === null,
                        'label' => $created !== null
                            ? 'API key created ' . gmdate('Y-m-d', $created)
                            : 'An API key since revoked',
                    ];
                    break;
                default:
                    $out[$convId] = ['type' => SourceRef::UNKNOWN, 'label' => (string) $ref['raw']];
            }
        }

        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function byId(string $sqlWithIn, int $userId, array $ids): array
    {
        $stmt = $this->conn->prepareRead(sprintf($sqlWithIn, implode(', ', array_fill(0, count($ids), '?'))));
        $this->conn->bind($stmt, 'i' . str_repeat('i', count($ids)), array_merge([$userId], $ids));
        $out = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $out[(int) $row['id']] = $row;
        }

        return $out;
    }
}
