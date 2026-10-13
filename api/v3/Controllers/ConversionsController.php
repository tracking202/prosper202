<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\ConflictException;
use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\WriteCommittedException;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\AccountTimezone;
use Api\V3\Support\LtvBody;
use Api\V3\Support\PayloadKeys;
use Api\V3\Support\StatementHelpers;
use Api\V3\Support\TimeBound;
use Api\V3\Support\QueryInt;
use Prosper202\Conversion\Ledger\Amount;
use Prosper202\Conversion\Ledger\DedupeKey;
use Prosper202\Database\Tables\ConversionTables;

class ConversionsController
{
    use StatementHelpers;
    use AccountTimezone;

    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
    }

    /**
     * The campaign a name is read from: the conversion's, only when it is
     * the conversion's own account's. A conversion takes its click's
     * campaign id, and nothing stopped a tracker naming another account's
     * campaign before the API checked linked ids (229df10); such a row is
     * served with no campaign name, as GET /clicks serves its click.
     */
    private const CAMPAIGN_JOIN = 'LEFT JOIN 202_aff_campaigns ac ON cl.campaign_id = ac.aff_campaign_id AND ac.user_id = cl.user_id';

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
        $limit = QueryInt::param($params, 'limit', 50, 1, 500, 'rows per page');
        $offset = QueryInt::param($params, 'offset', 0, 0, PHP_INT_MAX, 'rows to skip');

        $where = ['cl.user_id = ?'];
        $binds = [$this->userId];
        $types = 'i';

        // Read as its siblings below read theirs. It was !empty(), and
        // empty('0') is true, so campaign_id=0 (`--aff-campaign-id 0`) was no
        // filter and answered with every campaign's conversions; a value that
        // was not a number was cast to 0 and answered with none.
        if (array_key_exists('campaign_id', $params)) {
            $campaignId = self::positiveId($params['campaign_id']);
            if ($campaignId === null) {
                throw new ValidationException('Invalid conversion filter: campaign_id', ['campaign_id' => 'Must be a positive integer campaign id (see `p202 campaign list`)']);
            }
            $where[] = 'cl.campaign_id = ?';
            $binds[] = $campaignId;
            $types .= 'i';
        }
        [$from, $to] = TimeBound::window($params, fn (): string => $this->accountTimezone());
        if ($from !== null) {
            $where[] = 'cl.conv_time >= ?';
            $binds[] = $from;
            $types .= 'i';
        }
        if ($to !== null) {
            $where[] = 'cl.conv_time <= ?';
            $binds[] = $to;
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

        $sql = 'SELECT ' . self::COLUMNS . '
            FROM 202_conversion_logs cl
            ' . self::CAMPAIGN_JOIN . "
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
            ' . self::CAMPAIGN_JOIN . '
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
        // DECIMAL, which mysqli hands back as a string ("1.50000").
        if (isset($row['click_payout']) && is_string($row['click_payout']) && is_numeric($row['click_payout'])) {
            $row['click_payout'] = (float) $row['click_payout'];
        }
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

    /**
     * transaction_id and reversal_id as the ledger keys them: a string, or a
     * JSON integer read as its digits (the rule LtvBody reads /ltv/revenue's
     * references by), at most DedupeKey::MAX_TEXT bytes once trimmed, as
     * the key is built from the trimmed id. Absent or null is none.
     *
     * A number past PHP_INT_MAX is decoded as a float, which cannot tell
     * 12345678901234567891 from 12345678901234567892 (both are
     * 12345678901234567168), so it is refused, not read: send such an id as
     * a string. Past 255 bytes, DedupeKey threw from inside the write and the
     * request answered 500 naming nothing.
     *
     * @param array<array-key, mixed> $payload
     * @return array<string, string>
     */
    private static function referenceErrors(array $payload): array
    {
        $errors = [];
        $about = [
            'transaction_id' => 'the network\'s id for the sale',
            'reversal_id' => 'the network\'s id for the reversal',
        ];
        foreach ($about as $key => $what) {
            if (!array_key_exists($key, $payload) || $payload[$key] === null) {
                continue;
            }
            $value = $payload[$key];
            if (!is_string($value) && !is_int($value)) {
                $errors[$key] = 'must be a string, or a whole number up to ' . PHP_INT_MAX . ': ' . $what
                    . ' (send a longer number as a string)';
                continue;
            }
            $bytes = strlen(trim((string) $value));
            if ($bytes > DedupeKey::MAX_TEXT) {
                $errors[$key] = 'must be at most ' . DedupeKey::MAX_TEXT . ' bytes: ' . $what . ' (got ' . $bytes . ')';
            }
        }

        return $errors;
    }

    /**
     * The largest amount, in Amount's units, 202_conversion_logs.click_payout
     * holds, read from the table's definition rather than restated:
     * decimal(P,S) holds P nines' worth of units of 10^-S, and S has to be
     * Amount's scale for those to be Amount's units.
     */
    private static function payoutColumnMaxUnits(): int
    {
        $ddl = ConversionTables::conversionLogs()->createStatement;
        $found = preg_match('/`click_payout`\s+decimal\((\d+),(\d+)\)/i', $ddl, $m) === 1;
        if (!$found || (int) $m[2] !== Amount::SCALE || (int) $m[1] > 18) {
            throw new \LogicException(
                '202_conversion_logs.click_payout is not a decimal(P,' . Amount::SCALE . ') column'
            );
        }

        return (int) str_repeat('9', (int) $m[1]);
    }

    public function create(array $payload): array
    {
        // Every key below is read; anything else was dropped with a 201 — a
        // misspelled `transaction_id` recorded the sale without the id that
        // dedupes its retries (CLAUDE.md #4).
        PayloadKeys::refuseUnknown($payload, [
            'click_id', 'transaction_id', 'conv_time', 'payout', 'status', 'reversal_id',
            'customer_id', 'customer_ref', 'customer_ref_type', 'customer_crm', 'items',
        ], 'a conversion');
        // And the objects inside it, as strictly: the repository read the
        // keys of a line item and of customer_crm it knew and cast them, so
        // `unit_pirce` stored no price, a unit_price of "abc" stored 0 and
        // `frist_name` no name, each answered 201 (CLAUDE.md #4). And the
        // references a sale is keyed and identified by, as /ltv/revenue reads
        // its own: (string) made the JSON integers 12345678901234567891 and
        // 12345678901234567892 (floats past PHP_INT_MAX) both
        // "1.2345678901235E+19", so the second sale was answered duplicate
        // and dropped, an object "Array" and true "1" (measured); is_scalar()
        // let a customer_ref through the same way.
        PayloadKeys::refuse(
            PayloadKeys::objectErrors($payload, 'customer_crm', LtvBody::crmKeys(), 'customer_crm', LtvBody::crm(...))
            + PayloadKeys::listErrors($payload, 'items', LtvBody::LINE_ITEM_KEYS, 'a line item', LtvBody::lineItem(...))
            + LtvBody::identity($payload)
            + self::referenceErrors($payload)
        );
        // Read strictly: (int) made "12abc" click 12 and "abc" no click, and
        // a conv_time of "2026-10-07" the 2026th second of 1970.
        $clickId = QueryInt::required($payload, 'click_id', 1, PHP_INT_MAX, 'the click the conversion is recorded on');

        $data = [
            'click_id' => $clickId,
            // A string or an integer by now (referenceErrors()).
            'transaction_id' => (string)($payload['transaction_id'] ?? ''),
            'conv_time' => QueryInt::param($payload, 'conv_time', time(), 0, 2147483647, 'a unix time; leave it out for now'),
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
            // And one a conversion row can hold, as stored (rounded to five
            // places): Amount refused 14 digits or more and overflowed on a
            // JSON integer from 10^14, each a 500 naming nothing, and 1234567
            // reached the INSERT, a strict-mode 500, or under an empty
            // sql_mode stored as 999999.99999 and answered 201 (measured).
            try {
                $units = Amount::toUnits(is_int($payout) ? (string) $payout : $payout);
            } catch (\InvalidArgumentException) {
                $units = null;
            }
            $max = self::payoutColumnMaxUnits();
            if ($units === null || abs($units) > $max) {
                throw new ValidationException('payout is out of range', ['payout' => 'Must be from -'
                    . Amount::fromUnits($max) . ' to ' . Amount::fromUnits($max) . ', what one conversion holds']);
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
        } elseif (isset($payload['reversal_id'])) {
            // A reversal_id without the status was dropped, and the body
            // recorded a new sale: money added where the caller meant to
            // take it back, answered 201.
            throw new ValidationException('reversal_id is read only with status "reversed"', [
                'reversal_id' => 'Send status "reversed" with it to reverse the sale named by transaction_id; a new sale takes no reversal_id',
            ]);
        }

        // LTV: optional customer identity + product line items, whose shape
        // was checked above, with customer_ref and customer_ref_type
        // (LtvBody::identity()); the repository keeps its own refusal of a
        // type it does not know — never silently dropped.
        // Given means present and not null. These were !empty(), and
        // empty('0') is true: customer_ref "0" (a real id where a system
        // counts from 0) was dropped and the revenue landed on whatever the
        // click resolved to, and customer_id 0 or "x" named no one, silently.
        if (isset($payload['customer_id'])) {
            $customerId = self::positiveId($payload['customer_id']);
            if ($customerId === null) {
                throw new ValidationException('customer_id must be a positive integer', ['customer_id' => 'An LTV customer id (see `p202 ltv customers`)']);
            }
            $data['customer_id'] = $customerId;
        }
        if (isset($payload['customer_ref'])) {
            if (!is_scalar($payload['customer_ref']) || trim((string) $payload['customer_ref']) === '') {
                throw new ValidationException('customer_ref must be a non-empty string', ['customer_ref' => 'Your id for the customer']);
            }
            $data['customer_ref'] = (string)$payload['customer_ref'];
            if (isset($payload['customer_ref_type'])) {
                if (!is_string($payload['customer_ref_type'])) {
                    throw new ValidationException('customer_ref_type must be a string', ['customer_ref_type' => 'email_md5, email_sha256, esp_id, merchant_id, subid or custom']);
                }
                // The repository refuses a type it does not know and reads ''
                // as its default, custom (normalizeAliasType()).
                $data['customer_ref_type'] = $payload['customer_ref_type'];
            }
        }
        if (isset($payload['customer_crm'])) {
            $data['customer_crm'] = $payload['customer_crm'];
        }
        if (isset($payload['items'])) {
            $data['items'] = $payload['items'];
        }
        // Line items and CRM fields belong to a customer. When none resolves
        // (none named, the click linked to none, the account's c-param naming
        // none) the conversion was recorded without them and answered 201;
        // the repository refuses instead, before the row is written.
        $data['ltv_requires_customer'] = true;

        // Delegate to the single canonical conversion writer so the V3 API and the
        // legacy postback/pixel endpoints share one transactional, idempotent path
        // (locks the click, de-dupes on its ledger key, inserts the row and
        // recomputes the click's value from its rows).
        $repo = new \Prosper202\Conversion\MysqlConversionRepository(
            new \Prosper202\Database\Connection($this->db)
        );

        try {
            $recorded = $repo->record($this->userId, $data);
        } catch (\Prosper202\Conversion\Ledger\ReversalException $e) {
            if ($e->kind === \Prosper202\Conversion\Ledger\ReversalException::NO_TARGET) {
                throw new NotFoundException($e->getMessage());
            }
            throw new ValidationException($e->getMessage(), ['transaction_id' => $e->getMessage()]);
        } catch (\Prosper202\Conversion\LtvDataWithoutCustomer $e) {
            throw new ValidationException($e->getMessage(), $e->fieldErrors());
        } catch (\Prosper202\Ltv\LtvInputException $e) {
            // The LTV repositories' refusals name their field (an unknown
            // customer_ref_type, a line item's quantity), as on /ltv/*.
            throw new ValidationException($e->getMessage(), $e->fieldErrors(), $e);
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
        if (!$recorded['clickFound']) {
            throw new NotFoundException('Click not found or not owned by user');
        }
        $convId = (int) $recorded['convId'];
        $duplicate = (bool) $recorded['duplicate'];
        // The repository wrote nothing for a duplicate. One of a deleted row
        // is refused: that row keeps its ledger key, so the conversion is
        // not recorded again, and answering with the row would claim it
        // counts. A 409 is not a committed write, so it never spends the
        // request's Idempotency-Key.
        if ($duplicate && !empty($recorded['deleted'])) {
            throw new ConflictException(
                self::deletedDuplicateMessage($convId, $clickId, (string) ($recorded['dedupeKey'] ?? '')),
                ['conv_id' => $convId, 'click_id' => $clickId, 'deleted' => true]
            );
        }
        // A duplicate is this sale again only when what it states is what
        // the recorded conversion holds. The ledger key (the transaction id)
        // located the row and nothing compared the request to it, so the
        // same transaction id with another payout or customer answered 201
        // `duplicate: true` and the change was dropped (CLAUDE.md #15).
        if ($duplicate && $convId > 0) {
            $this->refuseADifferentSale($convId, $clickId, $payload, $data, (string) ($recorded['dedupeKey'] ?? ''));
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
        // not read as a failed create. A duplicate committed nothing, so its
        // failed read is an ordinary failure a retry can repeat safely.
        try {
            $response = $this->get($convId);
        } catch (\Throwable $e) {
            if (!$duplicate) {
                throw new WriteCommittedException('conversion', $e);
            }
            throw new DatabaseException('Conversion ' . $convId . ' already records this conversion but could not be read back', $e);
        }
        // Additive and only when true, like idempotent_replay: `data` stays
        // the conversion as GET /conversions/{id} serves it.
        if ($duplicate) {
            $response['duplicate'] = true;
        }

        return $response;
    }

    /**
     * Refuse a request that matched a recorded conversion's ledger key but
     * states a different sale, naming what differs; nothing was written.
     *
     * Compared when the request states it (each is stored as sent): the
     * payout (negated for a reversal, as the writer negates it), conv_time,
     * the customer it names (Prosper202\Ltv\RevenueReplay, merges followed)
     * and its line items, against the conversion's revenue event. Left out,
     * the payout and the time mean the campaign's or the click's payout and
     * now, which a retry cannot be held to; customer_crm only describes a
     * customer the write creates. The postback and pixel paths keep the
     * first statement of a sale, as networks resend theirs; this is the
     * API's answer to its own caller.
     *
     * @param array<string, mixed> $payload the body as sent
     * @param array<string, mixed> $data what record() was given
     */
    private function refuseADifferentSale(
        int $convId,
        int $clickId,
        array $payload,
        array $data,
        string $dedupeKey
    ): void {
        $statesConvTime = array_key_exists('conv_time', $payload) && $payload['conv_time'] !== null;
        $statesAnything = array_key_exists('payout', $data) || $statesConvTime || isset($data['customer_id'])
            || isset($data['customer_ref']) || isset($data['items']);
        if (!$statesAnything) {
            return; // it states nothing a recorded sale could differ in
        }
        $conn = new \Prosper202\Database\Connection($this->db);
        $stmt = $conn->prepareWrite(
            'SELECT click_payout, conv_time, customer_id, reverses_conv_id FROM 202_conversion_logs
             WHERE conv_id = ? AND user_id = ? LIMIT 1'
        );
        $conn->bind($stmt, 'ii', [$convId, $this->userId]);
        $row = $conn->fetchOne($stmt);
        if ($row === null) {
            throw new DatabaseException(
                'Conversion ' . $convId . ' matched this request but could not be read to compare it'
            );
        }

        $differences = [];
        if (array_key_exists('payout', $data)) {
            $sent = \Prosper202\Conversion\Ledger\Amount::toUnits($data['payout']);
            if ($row['reverses_conv_id'] !== null) {
                $sent = -abs($sent);
            }
            $kept = \Prosper202\Conversion\Ledger\Amount::toUnits((string) $row['click_payout']);
            if ($sent !== $kept) {
                $differences['payout'] = 'recorded ' . \Prosper202\Conversion\Ledger\Amount::fromUnits($kept)
                    . ', sent ' . \Prosper202\Conversion\Ledger\Amount::fromUnits($sent);
            }
        }
        if ($statesConvTime && (int) $data['conv_time'] !== (int) $row['conv_time']) {
            $differences['conv_time'] = 'recorded ' . (int) $row['conv_time'] . ', sent ' . (int) $data['conv_time'];
        }

        $replay = new \Prosper202\Ltv\RevenueReplay(new \Prosper202\Ltv\MysqlCustomerRepository($conn));
        if (isset($data['customer_id']) || isset($data['customer_ref'])) {
            $customer = isset($data['customer_id'])
                ? ['id' => (int) $data['customer_id']]
                : ['ref' => (string) $data['customer_ref'], 'type' => $data['customer_ref_type'] ?? null];
            $recorded = $row['customer_id'] !== null ? (int) $row['customer_id'] : null;
            try {
                $difference = $replay->customerDifference($this->userId, $recorded, $customer);
            } catch (\Prosper202\Ltv\LtvInputException $e) {
                // A customer_ref_type off the list or a malformed digest: a
                // new conversion refuses it in the repository, which a
                // duplicate never reaches.
                throw new ValidationException($e->getMessage(), $e->fieldErrors(), $e);
            }
            if ($difference !== null) {
                $differences['customer'] = $difference;
            }
        }
        if (isset($data['items']) && is_array($data['items'])) {
            $eventStmt = $conn->prepareWrite(
                'SELECT event_id, amount FROM 202_revenue_events WHERE conv_id = ? AND user_id = ? LIMIT 1'
            );
            $conn->bind($eventStmt, 'ii', [$convId, $this->userId]);
            $event = $conn->fetchOne($eventStmt);
            $difference = $replay->itemsDifference(
                $this->userId,
                $event !== null ? (int) $event['event_id'] : null,
                array_values($data['items']),
                $event !== null ? (float) $event['amount'] : (float) $row['click_payout']
            );
            if ($difference !== null) {
                $differences['items'] = $difference;
            }
        }
        if ($differences === []) {
            return;
        }

        if (str_starts_with($dedupeKey, 'tx:')) {
            $sale = 'The sale with transaction_id "' . substr($dedupeKey, 3) . '" on click ' . $clickId;
        } elseif (str_starts_with($dedupeKey, 'rev:')) {
            $sale = 'This reversal on click ' . $clickId;
        } else {
            $sale = 'Click ' . $clickId . '\'s conversion without a transaction_id';
        }
        $what = implode('; ', array_map(
            static fn (string $field, string $difference): string => $field . ' ' . $difference,
            array_keys($differences),
            $differences
        ));
        throw new ValidationException(
            $sale . ' is already recorded as conversion ' . $convId . ', and this request states a'
                . ' different sale (' . $what . '). Nothing was recorded: send it as recorded to get conversion '
                . $convId . ' back, send a different sale with its own transaction_id, or take this one back with'
                . ' status "reversed".',
            ['transaction_id' => 'Already recorded as conversion ' . $convId . ' with a different '
                . implode(', ', array_keys($differences)) . '; a different sale needs its own transaction_id']
        );
    }

    /**
     * Why a conversion matching a deleted row is not recorded, in the terms
     * of the ledger key the two share (DedupeKey: the API writes tx:<id>,
     * the one plain conversion, or a reversal's rev:<sale>:<ref>).
     */
    private static function deletedDuplicateMessage(int $convId, int $clickId, string $dedupeKey): string
    {
        if (str_starts_with($dedupeKey, 'tx:')) {
            $what = 'Conversion ' . $convId . ' on click ' . $clickId . ' had transaction id "' . substr($dedupeKey, 3) . '"';
            $next = 'If this is a different sale, send it with its own transaction_id.';
        } elseif ($dedupeKey === \Prosper202\Conversion\Ledger\DedupeKey::plainConversion()) {
            $what = 'Conversion ' . $convId . ' was click ' . $clickId . '\'s one conversion without a transaction id (its campaign accumulates)';
            $next = 'To record a sale on this click, send it with its transaction_id.';
        } elseif (str_starts_with($dedupeKey, 'rev:')) {
            $what = 'Conversion ' . $convId . ' was this reversal on click ' . $clickId;
            $next = 'A different reversal of the sale needs its own reversal_id.';
        } else {
            $what = 'Conversion ' . $convId . ' on click ' . $clickId . ' matched this request';
            $next = 'Send a conversion that does not repeat it.';
        }

        return $what . ' and was deleted. A deleted conversion keeps its place in the click\'s ledger, so it is not '
            . 'recorded again (a network\'s repeat of it is ignored the same way); nothing was written. ' . $next
            . ' GET /clicks/' . $clickId . '/conversions shows the deleted row.';
    }

    /**
     * Read-only preview of delete() for `?dry_run=1`.
     */
    public function deletePreview(int $id): array
    {
        $existing = $this->get($id);
        $repo = new \Prosper202\Conversion\MysqlConversionRepository(
            new \Prosper202\Database\Connection($this->db)
        );
        // What delete() will do to the reversals naming this conversion
        // (MysqlConversionRepository::softDeleteLocked()): they stay, stop
        // netting, and their revenue events are voided with this one's.
        $reversalIds = array_column($repo->liveReversalsOf($id, $this->userId), 'conv_id');
        $cascade = [];
        $note = 'Soft-deletes the conversion, voids its revenue ledger event, and corrects the customer LTV rollups'
            . ' in one transaction.';
        if ($reversalIds !== []) {
            $cascade[] = [
                'resource' => 'conversions',
                'count' => count($reversalIds),
                'ids' => $reversalIds,
                'effect' => 'reversal_stops_netting',
            ];
            $one = count($reversalIds) === 1;
            $note .= ' ' . ($one ? 'Conversion ' : 'Conversions ') . implode(', ', $reversalIds)
                . ($one ? ' reverses it: it stays but stops' : ' reverse it: they stay but stop')
                . ' counting (a reversal nets only while its sale counts), and '
                . ($one ? 'its revenue event is' : 'their revenue events are') . ' voided in the same transaction.';
        }

        // The traffic-source postbacks queued for it (NotificationOutbox::
        // onReplaced(), which delete() runs): cancelled where none was
        // attempted, retracted where one may have gone out.
        $stmt = $this->prepare(
            "SELECT COALESCE(SUM(status = 'pending' AND attempts = 0), 0) AS cancel,
                    COALESCE(SUM(status <> 'cancelled' AND NOT (status = 'pending' AND attempts = 0)), 0) AS retract
             FROM 202_notification_pending WHERE conv_id = ? AND user_id = ? AND kind = 'reached'"
        );
        $this->bind($stmt, 'ii', $id, $this->userId);
        $this->execute($stmt, 'Reading the queued postbacks failed');
        $postbacks = $this->result($stmt)->fetch_assoc();
        $stmt->close();
        $cancel = (int) ($postbacks['cancel'] ?? 0);
        $retract = (int) ($postbacks['retract'] ?? 0);
        if ($cancel + $retract > 0) {
            $cascade[] = ['resource' => 'notifications', 'count' => $cancel, 'effect' => 'postback_cancelled'];
            $cascade[] = ['resource' => 'notifications', 'count' => $retract, 'effect' => 'postback_retracted'];
            $note .= ' Its traffic-source postbacks: ' . $cancel . ' not yet sent '
                . ($cancel === 1 ? 'is' : 'are') . ' cancelled, and '
                . $retract . ' that may have gone out ' . ($retract === 1 ? 'gets a retraction' : 'get retractions')
                . ' (sent to the correction URL where one is set).';
        }

        return ['data' => [
            'dry_run' => true,
            'action' => 'delete',
            'resource' => 'conversions',
            'mode' => 'soft',
            'record' => $existing['data'],
            'cascade' => $cascade,
            'note' => $note,
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
}
