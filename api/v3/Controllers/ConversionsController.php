<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\WriteCommittedException;
use Api\V3\Exception\ValidationException;

class ConversionsController
{
    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
    }

    /**
     * The columns a conversion is served with: the row, its campaign's name,
     * and its provenance in the ledger (what produced it, what that is,
     * whether it is paid, and what replaced or reverses it). Whether a row
     * counts toward its click is a property of the click's rows together,
     * so it is answered by GET /clicks/{id}/conversions, not here.
     */
    private const COLUMNS = 'cl.conv_id, cl.click_id, cl.transaction_id, cl.campaign_id,
                cl.click_payout, cl.user_id, cl.click_time, cl.conv_time, cl.deleted,
                cl.source, cl.source_ref, cl.event_name, cl.payable, cl.reverses_conv_id,
                cl.superseded_by, cl.superseded_reason,
                ac.aff_campaign_name';

    public function list(array $params): array
    {
        $limit = max(1, min(500, (int)($params['limit'] ?? 50)));
        $offset = max(0, (int)($params['offset'] ?? 0));

        $where = ['cl.user_id = ?'];
        $binds = [$this->userId];
        $types = 'i';

        if (!empty($params['campaign_id'])) {
            $where[] = 'cl.campaign_id = ?';
            $binds[] = (int)$params['campaign_id'];
            $types .= 'i';
        }
        if (!empty($params['time_from'])) {
            $where[] = 'cl.conv_time >= ?';
            $binds[] = (int)$params['time_from'];
            $types .= 'i';
        }
        if (!empty($params['time_to'])) {
            $where[] = 'cl.conv_time <= ?';
            $binds[] = (int)$params['time_to'];
            $types .= 'i';
        }

        // The ledger filters. A value that cannot be read is refused, never
        // dropped: an ignored filter answers with every conversion of the
        // account, which reads as "these are the ones you asked for"
        // (CLAUDE.md #4).
        $errors = [];
        if (array_key_exists('click_id', $params)) {
            $clickId = self::positiveId($params['click_id']);
            if ($clickId === null) {
                $errors['click_id'] = 'Must be a positive integer click id (see `p202 click list`)';
            } else {
                $where[] = 'cl.click_id = ?';
                $binds[] = $clickId;
                $types .= 'i';
            }
        }
        if (array_key_exists('source', $params)) {
            $source = is_string($params['source']) ? \Prosper202\Conversion\Ledger\ConversionSource::tryFrom($params['source']) : null;
            if ($source === null) {
                $errors['source'] = 'Must be one of: ' . implode(', ', array_map(
                    static fn (\Prosper202\Conversion\Ledger\ConversionSource $s): string => $s->value,
                    \Prosper202\Conversion\Ledger\ConversionSource::cases()
                ));
            } else {
                $where[] = 'cl.source = ?';
                $binds[] = $source->value;
                $types .= 's';
            }
        }
        if (array_key_exists('goal', $params)) {
            $goalId = self::positiveId($params['goal']);
            if ($goalId === null) {
                $errors['goal'] = 'Must be a positive integer goal id (see `p202 goal list`)';
            } else {
                // Every version of the goal. The prefix ends in its colon, so
                // goal 1 never matches goal 12's rows.
                $where[] = "cl.source = 'goal' AND cl.source_ref LIKE ?";
                $binds[] = 'goal:' . $goalId . ':%';
                $types .= 's';
            }
        }
        if ($errors !== []) {
            throw new ValidationException('Invalid conversion filter: ' . implode(', ', array_keys($errors)), $errors);
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where) . ' AND cl.deleted = 0';

        $countSql = "SELECT COUNT(*) as total FROM 202_conversion_logs cl $whereClause";
        $stmt = $this->prepare($countSql);
        $this->bind($stmt, $types, ...$binds);
        $this->execute($stmt, 'Count query failed');
        $total = (int)$this->result($stmt)->fetch_assoc()['total'];
        $stmt->close();

        $sql = 'SELECT ' . self::COLUMNS . "
            FROM 202_conversion_logs cl
            LEFT JOIN 202_aff_campaigns ac ON cl.campaign_id = ac.aff_campaign_id
            $whereClause
            ORDER BY cl.conv_time DESC, cl.conv_id DESC LIMIT ? OFFSET ?";

        $binds[] = $limit;
        $types .= 'i';
        $binds[] = $offset;
        $types .= 'i';

        $stmt = $this->prepare($sql);
        $this->bind($stmt, $types, ...$binds);
        $this->execute($stmt, 'List query failed');
        $result = $this->result($stmt);

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = self::present($row);
        }
        $stmt->close();

        return [
            'data' => $rows,
            'pagination' => ['total' => $total, 'limit' => $limit, 'offset' => $offset],
        ];
    }

    public function get(int $id): array
    {
        $sql = 'SELECT ' . self::COLUMNS . '
            FROM 202_conversion_logs cl
            LEFT JOIN 202_aff_campaigns ac ON cl.campaign_id = ac.aff_campaign_id
            WHERE cl.conv_id = ? AND cl.user_id = ? AND cl.deleted = 0 LIMIT 1';

        $stmt = $this->prepare($sql);
        $this->bind($stmt, 'ii', $id, $this->userId);
        $this->execute($stmt, 'Query failed');
        $row = $this->result($stmt)->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new NotFoundException('Conversion not found');
        }
        return ['data' => self::present($row)];
    }

    /**
     * A stored row as served: `payable` as a boolean, and a goal row's goal
     * and version beside its raw source_ref, so a caller can filter and
     * join without parsing the reference.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        $row['payable'] = (int) $row['payable'] === 1;
        $ref = \Prosper202\Conversion\Ledger\SourceRef::parse(isset($row['source_ref']) ? (string) $row['source_ref'] : null);
        $isGoal = $ref !== null && $ref['kind'] === \Prosper202\Conversion\Ledger\SourceRef::GOAL;
        $row['goal_id'] = $isGoal ? $ref['id'] : null;
        $row['goal_version'] = $isGoal ? $ref['version'] : null;

        return $row;
    }

    /** A positive integer id from a query value, or null when it is not one. */
    private static function positiveId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,18}$/D', $value) !== 1) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($id) && $id > 0 ? $id : null;
    }

    public function create(array $payload): array
    {
        $clickId = (int)($payload['click_id'] ?? 0);
        if ($clickId <= 0) {
            throw new ValidationException('click_id is required', ['click_id' => 'Must be a positive integer']);
        }

        $data = [
            'click_id' => $clickId,
            'transaction_id' => (string)($payload['transaction_id'] ?? ''),
            'conv_time' => (int)($payload['conv_time'] ?? time()),
            // Provenance: written through the API, by this key (a digest of
            // it; the breakdown names the key from it).
            'source' => \Prosper202\Conversion\Ledger\ConversionSource::API->value,
        ];
        $keyRef = \Api\V3\RequestContext::apiKeyRef();
        if ($keyRef !== '') {
            $data['source_ref'] = $keyRef;
        }
        if (array_key_exists('payout', $payload)) {
            // An amount that is not a number is refused, never cast to 0:
            // the ledger records exactly what it is given (CLAUDE.md #4).
            $payout = $payload['payout'];
            if (!is_int($payout) && !is_float($payout) && !(is_string($payout) && preg_match('/^-?\d+(\.\d+)?$/D', trim($payout)) === 1)) {
                throw new ValidationException('payout must be a number', ['payout' => 'Must be a decimal number, e.g. 12.50']);
            }
            $data['payout'] = is_string($payout) ? trim($payout) : $payout;
        }

        // A reversal nets an earlier conversion on the same click, named by
        // its transaction_id: status "reversed", optionally with the
        // network's reversal_id. A sale can be reversed once.
        if (array_key_exists('status', $payload)) {
            if ($payload['status'] !== 'reversed') {
                throw new ValidationException('status must be "reversed" when given', ['status' => 'The only status a conversion can be created with is "reversed"']);
            }
            $data['reversal'] = true;
            if (isset($payload['reversal_id'])) {
                if (!is_scalar($payload['reversal_id']) || trim((string) $payload['reversal_id']) === '') {
                    throw new ValidationException('reversal_id must be a non-empty string', ['reversal_id' => 'The network\'s id for this reversal']);
                }
                $data['reversal_ref'] = trim((string) $payload['reversal_id']);
            }
        }

        // LTV: optional customer identity + product line items. An invalid
        // customer_ref_type or malformed items array is rejected by the
        // repository with an explicit error — never silently dropped.
        if (!empty($payload['customer_id'])) {
            $data['customer_id'] = (int)$payload['customer_id'];
        }
        if (!empty($payload['customer_ref'])) {
            $data['customer_ref'] = (string)$payload['customer_ref'];
            if (!empty($payload['customer_ref_type'])) {
                $data['customer_ref_type'] = (string)$payload['customer_ref_type'];
            }
        }
        if (isset($payload['customer_crm'])) {
            if (!is_array($payload['customer_crm'])) {
                throw new ValidationException('customer_crm must be an object', ['customer_crm' => 'Must be an object of CRM fields']);
            }
            $data['customer_crm'] = $payload['customer_crm'];
        }
        if (isset($payload['items'])) {
            if (!is_array($payload['items'])) {
                throw new ValidationException('items must be an array', ['items' => 'Must be an array of line items']);
            }
            $data['items'] = $payload['items'];
        }

        // Delegate to the single canonical conversion writer so the V3 API and the
        // legacy postback/pixel endpoints share one transactional, idempotent path
        // (locks the click, de-dupes on its ledger key, inserts the row and
        // recomputes the click's value from its rows).
        $repo = new \Prosper202\Conversion\MysqlConversionRepository(
            new \Prosper202\Database\Connection($this->db)
        );

        try {
            $convId = $repo->create($this->userId, $data);
        } catch (\Prosper202\Conversion\ClickNotFoundException $e) {
            throw new NotFoundException('Click not found or not owned by user');
        } catch (\Prosper202\Conversion\Ledger\ReversalException $e) {
            if ($e->kind === \Prosper202\Conversion\Ledger\ReversalException::NO_TARGET) {
                throw new NotFoundException($e->getMessage());
            }
            throw new ValidationException($e->getMessage(), ['transaction_id' => $e->getMessage()]);
        } catch (\Prosper202\Database\Exceptions\QueryException | \mysqli_sql_exception $e) {
            // A real database failure is a 500 even though QueryException
            // extends RuntimeException — under MYSQLI_REPORT_STRICT a failed
            // query surfaces as Connection's QueryException, not
            // mysqli_sql_exception. Only repository validation maps to 422 below.
            throw new DatabaseException('Failed to create conversion: ' . $e->getMessage(), $e);
        } catch (\RuntimeException $e) {
            // Validation-shaped repository errors (unknown customer_ref_type,
            // malformed line items, foreign customer_id) are client-
            // correctable — 422/404 like the sibling /ltv endpoints, not 500.
            if (str_contains($e->getMessage(), 'not found for this account')) {
                throw new NotFoundException($e->getMessage());
            }
            throw new ValidationException($e->getMessage());
        } catch (\Throwable $e) {
            // Preserve the underlying failure for server-side logs via getPrevious().
            throw new DatabaseException('Failed to create conversion: ' . $e->getMessage(), $e);
        }

        // A customer_ref on an authenticated request is the operator's own
        // statement, so it links the click into the identity graph without
        // the cust_sig a public pixel needs (plan §6.2). Best-effort and
        // after the commit: ClickIdentity logs a failure and never throws.
        if (isset($data['customer_ref']) && empty($data['reversal'])) {
            \Prosper202\Identity\ClickIdentity::trustedCustomer(
                $data['customer_ref'],
                $data['customer_ref_type'] ?? null
            )->attachToStoredClick(new \Prosper202\Database\Connection($this->db), $clickId);
        }

        // The repository's transaction has committed, so the conversion
        // exists. Reading it back is the only step left, and its failure must
        // not read as a failed create.
        try {
            return $this->get($convId);
        } catch (\Throwable $e) {
            throw new WriteCommittedException('conversion', $e);
        }
    }

    /**
     * Read-only preview of delete() for `?dry_run=1`.
     */
    public function deletePreview(int $id): array
    {
        $existing = $this->get($id);
        return ['data' => [
            'dry_run' => true,
            'action' => 'delete',
            'resource' => 'conversions',
            'mode' => 'soft',
            'record' => $existing['data'],
            'cascade' => [],
            'note' => 'Soft-deletes the conversion, voids its revenue ledger event, and corrects the customer LTV rollups in one transaction.',
        ]];
    }

    public function delete(int $id): void
    {
        $this->get($id);

        // Delegate to the canonical repository so the soft-delete also voids
        // the conversion's revenue ledger event (compensating adjustment) and
        // corrects the customer's LTV rollups in the same transaction.
        $repo = new \Prosper202\Conversion\MysqlConversionRepository(
            new \Prosper202\Database\Connection($this->db)
        );
        try {
            $repo->softDelete($id, $this->userId);
        } catch (\Throwable $e) {
            throw new DatabaseException('Delete failed: ' . $e->getMessage(), $e);
        }
    }

    private function result(\mysqli_stmt $stmt): \mysqli_result
    {
        $result = $stmt->get_result();
        if (!$result instanceof \mysqli_result) {
            $stmt->close();
            throw new DatabaseException('Reading the result failed');
        }

        return $result;
    }

    private function prepare(string $sql): \mysqli_stmt
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new DatabaseException('Prepare failed');
        }
        return $stmt;
    }

    private function bind(\mysqli_stmt $stmt, string $types, mixed ...$values): void
    {
        // @phpstan-ignore-next-line prosper202.directStmtCall — this IS the centralized ref-safe bind wrapper (no Connection instance; routing through $this->conn would self-recurse)
        if (!$stmt->bind_param($types, ...$values)) {
            $stmt->close();
            throw new DatabaseException('Bind failed');
        }
    }

    private function execute(\mysqli_stmt $stmt, string $message): void
    {
        // @phpstan-ignore-next-line prosper202.directStmtCall — this IS the centralized checked-execute wrapper (no Connection instance; routing through $this->conn would self-recurse)
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException($message);
        }
    }
}
