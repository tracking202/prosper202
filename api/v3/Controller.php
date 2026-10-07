<?php

declare(strict_types=1);

namespace Api\V3;

use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Api\V3\Exception\NothingToUpdateException;
use Api\V3\Exception\WriteCommittedException;
use Api\V3\Support\PayloadKeys;
use Api\V3\Support\ServerStateStore;
use Api\V3\Support\StatementHelpers;

/**
 * Base CRUD controller with lifecycle hooks, input validation, and DI.
 *
 * Subclasses declare their schema via tableName(), primaryKey(), fields().
 * Override lifecycle hooks (beforeCreate, afterCreate, etc.) to inject
 * custom behaviour without copy-pasting the entire CRUD method.
 */
abstract class Controller
{
    use StatementHelpers;

    abstract protected function tableName(): string;
    abstract protected function primaryKey(): string;
    abstract protected function fields(): array;

    /** @var string[]|null  Computed once per instance. */
    private ?array $cachedSelectColumns = null;
    private ?array $cachedFields = null;
    private ?ServerStateStore $stateStore = null;

    /**
     * Dictionary tables whose rows feed the Landing Page Optimizer
     * dimension snapshot (DimensionSync::buildSnapshot, plus 202_aff_networks
     * for parity with the setup-page hooks). API mutations of these must
     * flag the user's snapshot dirty exactly like the legacy setup pages do.
     */
    private const LPO_SYNCED_DICTIONARIES = [
        '202_aff_campaigns',
        '202_aff_networks',
        '202_ppc_networks',
        '202_ppc_accounts',
        '202_landing_pages',
    ];

    public function __construct(protected \mysqli $db, protected int $userId)
    {
    }

    // ─── Schema helpers ──────────────────────────────────────────────

    protected function userIdColumn(): ?string
    {
        return 'user_id';
    }

    protected function deletedColumn(): ?string
    {
        return null;
    }

    protected function listOrderBy(): string
    {
        return $this->primaryKey() . ' DESC';
    }

    /**
     * Single source of truth for the bulk-upsert row cap; /capabilities
     * advertises this value and must never drift from what is enforced.
     */
    public static function configuredMaxBulkRows(): int
    {
        $raw = getenv('P202_MAX_BULK_ROWS');
        if (is_string($raw) && trim($raw) !== '') {
            $parsed = (int)$raw;
            if ($parsed > 0) {
                return min(5000, $parsed);
            }
        }
        return 500;
    }

    protected function maxBulkRows(): int
    {
        return self::configuredMaxBulkRows();
    }

    protected function selectColumns(): array
    {
        if ($this->cachedSelectColumns !== null) {
            return $this->cachedSelectColumns;
        }
        $columns = [$this->primaryKey()];
        foreach ($this->resolveFields() as $col => $def) {
            $columns[] = $col;
        }
        if ($this->userIdColumn()) {
            $columns[] = $this->userIdColumn();
        }
        $this->cachedSelectColumns = array_values(array_unique($columns));
        return $this->cachedSelectColumns;
    }

    protected function resolveFields(): array
    {
        if ($this->cachedFields === null) {
            $this->cachedFields = $this->fields();
        }
        return $this->cachedFields;
    }

    // ─── Input Validation ────────────────────────────────────────────

    /**
     * Column ranges for fields()' 'range' (the integer types the schema
     * uses). A BIGINT UNSIGNED holds more than a PHP int, and mysqli binds
     * 'i' as a signed 64-bit integer, so its top is PHP_INT_MAX here.
     * ControllerFieldsMatchSchemaTest holds every declared range, nullable
     * flag and max_length to the installed schema.
     */
    protected const TINYINT = [-128, 127];
    protected const TINYINT_UNSIGNED = [0, 255];
    protected const SMALLINT_UNSIGNED = [0, 65535];
    protected const MEDIUMINT = [-8388608, 8388607];
    protected const MEDIUMINT_UNSIGNED = [0, 16777215];
    protected const INT = [-2147483648, 2147483647];
    protected const INT_UNSIGNED = [0, 4294967295];
    protected const BIGINT_UNSIGNED = [0, PHP_INT_MAX];

    /**
     * What the field-error messages call this resource ("campaigns").
     */
    protected function resourceLabel(): string
    {
        return $this->changeEntityName() ?? $this->tableName();
    }

    /**
     * Keys this controller's create()/update() read themselves and remove
     * before the body reaches the base (a campaign's links, an app's
     * store_link). The base never sees them; they are listed so that a
     * refused key's message names them among what is accepted.
     *
     * @return list<string>
     */
    protected function handledKeys(): array
    {
        return [];
    }

    /**
     * The keys a GET of this resource answers that no write sets: the
     * primary key, the owner, the fields() marked readonly, and the version
     * metadata withVersionMetadata() adds.
     *
     * @return list<string>
     */
    protected function readOnlyKeys(): array
    {
        $keys = [$this->primaryKey()];
        if ($this->userIdColumn() !== null) {
            $keys[] = $this->userIdColumn();
        }
        foreach ($this->resolveFields() as $col => $def) {
            if ($def['readonly'] ?? false) {
                $keys[] = $col;
            }
        }
        $keys[] = 'version';
        $keys[] = 'etag';

        return array_values(array_unique($keys));
    }

    /**
     * Validate a request body against fields() and return the values to
     * write, each as its column's type.
     *
     * Nothing is passed over in silence (CLAUDE.md #4). This used to skip any
     * key it did not write and any null, and to cast whatever is_numeric()
     * let through, so a typo'd field, a field the controller does not write
     * and an attempt to clear a field all answered success having done less
     * than asked, and "1.5", "1e3" or a 20-digit string were stored as 1,
     * 1000 and PHP_INT_MAX. Now each of these is a 422 naming the field:
     *
     *  - a key that is not a field: refused, with the writable fields listed;
     *  - a read-only key (readOnlyKeys()): accepted only on an update, and
     *    only with the value the record holds ($current), so a body read with
     *    GET can be sent back; `version`/`etag` that differ are the 409 an
     *    If-Match would give, since the body was read from an older record;
     *  - null: written as NULL where the field is 'nullable' (the column can
     *    hold it: a clear), refused otherwise;
     *  - 'i': a JSON integer, an integral JSON number, or a string of digits
     *    with an optional sign, within the field's 'range';
     *  - 'd': a finite number (a JSON number or a numeric string) within the
     *    field's 'range';
     *  - 's': a string, or a JSON integer as its digits; never a bool, a
     *    fraction, a list or an object.
     *
     * @param array<array-key, mixed> $payload the request body as decoded
     * @param array<string, mixed>|null $current the record an update changes
     *     (as get() answers it); null for a create
     * @return array<string, mixed> the writable fields the body sets
     * @throws ValidationException
     * @throws ConflictException when the body's version is not the record's
     */
    protected function validatePayload(array $payload, bool $requireRequired = false, ?array $current = null): array
    {
        $fields = $this->resolveFields();
        $errors = [];
        $clean = [];

        if ($current !== null) {
            $this->assertBodyVersionCurrent($payload, $current);
        }

        if ($requireRequired) {
            foreach ($fields as $col => $def) {
                if (($def['required'] ?? false) && !array_key_exists($col, $payload)) {
                    $errors[$col] = "Field '$col' is required";
                }
            }
        }

        $readOnly = $this->readOnlyKeys();
        $writable = array_values(array_diff(array_keys($fields), $readOnly));
        $errors += PayloadKeys::changedReadOnly($payload, $readOnly, $current);
        $errors += PayloadKeys::unknown(
            array_diff_key($payload, array_flip($readOnly)),
            $writable,
            $this->resourceLabel(),
            [],
            $this->handledKeys()
        );

        foreach ($payload as $col => $value) {
            $col = (string) $col;
            $def = $fields[$col] ?? null;
            if ($def === null || in_array($col, $readOnly, true)) {
                continue; // refused above, or a read-only value that matches
            }

            if ($value === null) {
                if ($def['nullable'] ?? false) {
                    $clean[$col] = null;
                } else {
                    $errors[$col] = "Field '$col' cannot be null: send a value, or omit it to "
                        . ($current === null ? 'take its default' : 'leave it as it is');
                }
                continue;
            }

            // A field with a range names it in every refusal, not only for
            // a value that parsed: a 20-digit string is past PHP's int range
            // before it is past the column's, and "must be a whole number"
            // left the caller to guess which numbers would do.
            $range = $def['range'] ?? null;
            $inRange = static fn (int|float $n): bool => $range === null || ($n >= $range[0] && $n <= $range[1]);
            $rangeText = $range === null ? '' : " from {$range[0]} to {$range[1]}";
            switch ($def['type']) {
                case 'i':
                    $int = self::wholeNumber($value);
                    if ($int === null || !$inRange($int)) {
                        $errors[$col] = "Field '$col' must be a whole number" . $rangeText;
                    } else {
                        $clean[$col] = $int;
                    }
                    break;
                case 'd':
                    $numeric = is_int($value) || is_float($value) || (is_string($value) && is_numeric($value));
                    $number = $numeric ? (float) $value : null;
                    if ($number === null || !is_finite($number) || !$inRange($number)) {
                        $errors[$col] = "Field '$col' must be "
                            . ($range === null ? 'a finite number' : 'a number' . $rangeText);
                    } else {
                        $clean[$col] = $number;
                    }
                    break;
                case 's':
                    if (!is_string($value) && !is_int($value)) {
                        $errors[$col] = "Field '$col' must be a string";
                        break;
                    }
                    $clean[$col] = (string) $value;
                    if (isset($def['max_length']) && mb_strlen($clean[$col]) > $def['max_length']) {
                        $errors[$col] = "Field '$col' exceeds max length of {$def['max_length']}";
                        unset($clean[$col]);
                    }
                    break;
                default:
                    $clean[$col] = $value;
            }

            if (
                isset($def['allowed'])
                && array_key_exists($col, $clean)
                && !in_array($clean[$col], $def['allowed'], true)
            ) {
                $errors[$col] = "Field '$col' must be one of: " . implode(', ', $def['allowed']);
            }
        }

        if ($errors) {
            throw new ValidationException('Validation failed', $errors);
        }

        return $clean;
    }

    /**
     * A whole number as the value states it, or null: a PHP int; a float
     * that is integral and inside PHP's int range (JSON `5.0`, `1e2`); a
     * string of digits with an optional sign that fits a PHP int. "1.5",
     * "1e3", " 7", true and a 20-digit string are none of these.
     */
    private static function wholeNumber(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            // 2^63 itself is not an int; every float below it in magnitude
            // that is integral converts exactly.
            if (!is_finite($value) || floor($value) !== $value || $value >= 9.2233720368547758E18 || $value < -9.2233720368547758E18) {
                return null;
            }

            return (int) $value;
        }
        if (!is_string($value) || preg_match('/^([+-]?)0*([0-9]+)$/D', $value, $m) !== 1) {
            return null;
        }
        $limit = $m[1] === '-' ? '9223372036854775808' : '9223372036854775807';
        if (strlen($m[2]) > strlen($limit) || (strlen($m[2]) === strlen($limit) && strcmp($m[2], $limit) > 0)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * A body's `version` (or `etag`) says which record it was read from. A
     * body read from an older one is the conflict an If-Match header would
     * report: written back whole, it would undo the change made since.
     *
     * @param array<array-key, mixed> $payload
     * @param array<string, mixed> $current
     * @throws ConflictException
     */
    private function assertBodyVersionCurrent(array $payload, array $current): void
    {
        $currentVersion = $this->computeVersionHash($current);
        foreach (['version' => $currentVersion, 'etag' => '"' . $currentVersion . '"'] as $key => $expected) {
            if (!array_key_exists($key, $payload) || $payload[$key] === $expected) {
                continue;
            }
            $this->stateStore()->incrementMetric('conflicts', 1);
            throw new ConflictException(
                'Version mismatch',
                [
                    'expected_version' => is_scalar($payload[$key]) ? trim((string) $payload[$key], '" ') : null,
                    'current_version' => $currentVersion,
                    'diff_hint' => "The body's $key is from an older read of this record. Re-fetch it (GET) and "
                        . "send your changes on that, or omit $key and send only the fields to change.",
                ]
            );
        }
    }

    // ─── Linked Records ──────────────────────────────────────────────

    /**
     * The records a Setup field links to: field => [table, id column,
     * deleted column, what it is, where its ids are listed]. The tracker
     * page's own list (generate_tracking_link.php), and the checks of the
     * other Setup pages ("not authorized to add a campaign to another
     * user's network").
     */
    private const LINKED_RECORDS = [
        'aff_network_id'  => ['202_aff_networks', 'aff_network_id', 'aff_network_deleted', 'category', 'GET /aff-networks'],
        'aff_campaign_id' => ['202_aff_campaigns', 'aff_campaign_id', 'aff_campaign_deleted', 'campaign', 'GET /campaigns'],
        'landing_page_id' => ['202_landing_pages', 'landing_page_id', 'landing_page_deleted', 'landing page', 'GET /landing-pages'],
        'text_ad_id'      => ['202_text_ads', 'text_ad_id', 'text_ad_deleted', 'text ad', 'GET /text-ads'],
        'ppc_network_id'  => ['202_ppc_networks', 'ppc_network_id', 'ppc_network_deleted', 'traffic source', 'GET /ppc-networks'],
        'ppc_account_id'  => ['202_ppc_accounts', 'ppc_account_id', 'ppc_account_deleted', 'traffic source account', 'GET /ppc-accounts'],
        'rotator_id'      => ['202_rotators', 'id', null, 'redirector', 'GET /rotators'],
    ];

    /**
     * A linked id names one of the caller's own live records, as the Setup
     * pages require. The API took every one as sent, so a key could file its
     * campaign under another account's category or build a tracker on
     * another account's campaign, account, landing page, ad or redirector.
     *
     * Only fields the payload sets are read. On an update, a value the
     * record already holds is not read either: re-sending what a record has
     * (a full PUT, a sync) must not fail because its campaign was removed
     * since. 0 is "none" for a link the record may go without, and refused
     * for one it requires.
     *
     * @param array<string, mixed>      $clean   validatePayload()'s output
     * @param array<string, mixed>|null $current the row an update changes
     * @throws ValidationException naming each field
     */
    protected function assertLinksOwned(array $clean, ?array $current = null): void
    {
        $fields = $this->resolveFields();
        $errors = [];
        foreach (self::LINKED_RECORDS as $field => [$table, $column, $deletedColumn, $what, $listedBy]) {
            if (!array_key_exists($field, $clean) || !isset($fields[$field]) || $field === $this->primaryKey()) {
                continue;
            }
            $id = (int)$clean[$field];
            if ($current !== null && array_key_exists($field, $current) && (int)$current[$field] === $id) {
                continue;
            }
            if ($id === 0 && !($fields[$field]['required'] ?? false)) {
                continue;
            }
            if ($id <= 0) {
                $errors[$field] = "Field '$field' must be the id of a $what of yours ($listedBy lists them)";
                continue;
            }
            if (!$this->ownsLiveRecord($table, $column, $deletedColumn, $id)) {
                $errors[$field] = "$what $id is not one of yours, or it was removed ($listedBy lists them)";
            }
        }
        if ($errors) {
            throw new ValidationException('Validation failed', $errors);
        }
    }

    /**
     * Whether $id is a live row of this user's. Table and column names come
     * from LINKED_RECORDS, never from the request. A failed lookup throws:
     * "not yours" would refuse a valid id for a database failure.
     */
    private function ownsLiveRecord(string $table, string $column, ?string $deletedColumn, int $id): bool
    {
        $sql = "SELECT 1 FROM $table WHERE $column = ? AND user_id = ?"
            . ($deletedColumn !== null ? " AND COALESCE($deletedColumn, 0) = 0" : '') . ' LIMIT 1';
        $stmt = $this->prepare($sql);
        $this->bind($stmt, 'ii', $id, $this->userId);
        $this->execute($stmt, 'Ownership lookup failed');
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new DatabaseException('Ownership lookup failed');
        }
        $found = $result->fetch_row() !== null;
        $stmt->close();

        return $found;
    }

    // ─── Lifecycle Hooks ─────────────────────────────────────────────

    /**
     * Called before INSERT.  Return extra columns to include in the INSERT.
     * @return array<string, array{type: string, value: mixed}>
     */
    protected function beforeCreate(array $payload): array
    {
        return [];
    }

    protected function afterCreate(int $insertId, array $payload): void
    {
    }

    /**
     * Build the WHERE condition for one list filter.
     * Returns [sql with one placeholder, bind value, bind type].
     * Override to customize matching for specific fields (default: equality).
     * @return array{string, mixed, string}
     */
    protected function filterCondition(string $field, array $fieldDef, mixed $value): array
    {
        return ["$field = ?", $value, $fieldDef['type']];
    }

    /**
     * The list's `filter[<field>]=<value>` pairs, each a field this
     * controller declares with a value its type can hold.
     *
     * A filter it could not apply used to be skipped, so the list came
     * back unfiltered with a 200: `filter[aff_campaing_id]=3` (a typo),
     * `filter[aff_campaign_id_public]=…` (a read-only field), or
     * `filter[aff_campaign_id]=abc` (bound as 0) each answered with every
     * row, or none, as though it had been honoured. A narrowing that is
     * silently dropped widens the answer (error pattern #4), so each of
     * those is a 422 naming what the list can be filtered by. Read-only
     * fields are filterable: a filter is a read.
     *
     * @param array<string, mixed> $params
     * @param array<string, array<string, mixed>> $fields
     * @return array<string, mixed>
     */
    private function listFilters(array $params, array $fields): array
    {
        if (!array_key_exists('filter', $params)) {
            return [];
        }
        $filters = $params['filter'];
        $filterable = 'Filter by field: ' . implode(', ', array_keys($fields));
        if (!is_array($filters)) {
            throw new ValidationException('Invalid filter', ['filter' => 'Use filter[<field>]=<value>. ' . $filterable]);
        }
        $errors = [];
        foreach ($filters as $field => $value) {
            $key = 'filter[' . $field . ']';
            if (!isset($fields[$field])) {
                $errors[$key] = 'Not a field of this list. ' . $filterable;
                continue;
            }
            $type = $fields[$field]['type'] ?? 's';
            if (!is_scalar($value)) {
                $errors[$key] = 'Must be a single value';
            } elseif ($type === 'i' && preg_match('/^-?[0-9]+$/D', (string) $value) !== 1) {
                $errors[$key] = 'Must be a whole number';
            } elseif ($type === 'd' && !is_numeric($value)) {
                $errors[$key] = 'Must be a number';
            }
        }
        if ($errors !== []) {
            throw new ValidationException('Invalid filter', $errors);
        }

        return $filters;
    }

    /**
     * Give a just-inserted row the public id the setup pages give theirs:
     * a random digit, the row id, a random digit (landing_pages.php,
     * aff_campaigns.php). Embedding the id makes it unique by construction,
     * which a random number is not: the redirects resolve a public id
     * across every account. Run from afterCreate(), where a failure is
     * reported as a committed write rather than a failed create.
     */
    protected function assignPublicId(string $column, int $insertId, int $max = 4294967295): int
    {
        // The columns are INT UNSIGNED and the ids MEDIUMINT: past ten
        // million rows a leading 5-9 no longer fits, and strict mode refuses
        // the write (the setup pages, running non-strict, are clamped to the
        // maximum — a shared id). So the leading digit is drawn from those
        // that fit.
        $lead = 9;
        while ($lead > 1 && (int) ($lead . $insertId . '9') > $max) {
            $lead--;
        }
        $publicId = (int) (random_int(1, $lead) . $insertId . random_int(1, 9));
        if ($publicId > $max) {
            throw new \Api\V3\Exception\DatabaseException("No public id fits $column for row $insertId");
        }
        $sql = sprintf('UPDATE %s SET %s = ? WHERE %s = ?', $this->tableName(), $column, $this->primaryKey());
        $stmt = $this->prepare($sql);
        $this->bind($stmt, 'ii', $publicId, $insertId);
        $this->execute($stmt, 'Public id assignment failed');
        $stmt->close();

        return $publicId;
    }

    /**
     * Called before UPDATE.  Return extra columns to include in the UPDATE SET.
     * @return array<string, array{type: string, value: mixed}>
     */
    protected function beforeUpdate(int|string $id, array $payload): array
    {
        return [];
    }

    protected function beforeDelete(int|string $id): void
    {
    }

    /**
     * Message for a duplicate-key (MySQL 1062) failure on this controller's
     * INSERT/UPDATE, or null (the default) to let the exception propagate.
     *
     * Returning a message turns the race loser's raw SQL error into the same
     * 409 a beforeCreate uniqueness pre-check produces: two concurrent
     * writes can both pass the pre-check, and only the UNIQUE key decides.
     * The message should name the colliding thing the way the pre-check
     * does, so the caller cannot tell which path rejected them.
     */
    protected function duplicateKeyConflictMessage(): ?string
    {
        return null;
    }

    /**
     * @throws ConflictException when the failure is a duplicate key and the
     *                           controller declares a conflict message
     */
    private function rethrowDuplicateKey(\mysqli_sql_exception $e, \mysqli_stmt $stmt): never
    {
        // Under mysqli's ERROR|STRICT reporting the throw comes from inside
        // $stmt->execute(), so execute()'s own close() never ran: this is
        // the only place that can release the statement before the
        // exception leaves the request. Without it every 409 from a racing
        // create leaks a prepared statement against max_prepared_stmt_count.
        $stmt->close();
        $message = $this->duplicateKeyConflictMessage();
        if ($message !== null && (int)$e->getCode() === 1062) {
            throw new ConflictException($message);
        }
        throw $e;
    }

    // ─── CRUD Operations ─────────────────────────────────────────────

    public function list(array $params): array
    {
        $limit = max(1, min(500, (int)($params['limit'] ?? 50)));
        $offset = max(0, (int)($params['offset'] ?? 0));
        // Absent and '' are no cursor; anything else is decoded or refused.
        // This was !empty(), and empty('0') is true, so cursor=0 was no
        // cursor and answered page one, where every other malformed cursor
        // is a 422 — a pager handed it would restart instead of stopping.
        $cursor = $params['cursor'] ?? '';
        if ($cursor !== '') {
            $offset = $this->decodeOffsetCursor(is_string($cursor) ? $cursor : '');
        }
        $cursorTtl = max(60, min(86400, (int)($params['cursor_ttl'] ?? 3600)));
        $selectExpr = implode(', ', $this->selectColumns());

        $where = [];
        $binds = [];
        $types = '';

        if ($this->userIdColumn()) {
            $where[] = $this->userIdColumn() . ' = ?';
            $binds[] = $this->userId;
            $types .= 'i';
        }

        if ($this->deletedColumn()) {
            $where[] = $this->deletedColumn() . ' = 0';
        }

        // The primary key filters too: `filter[aff_campaign_id]=3` on
        // campaigns is a lookup by id.
        $fields = [$this->primaryKey() => ['type' => 'i']] + $this->resolveFields();
        foreach ($this->listFilters($params, $fields) as $field => $value) {
            [$condition, $bindValue, $bindType] = $this->filterCondition($field, $fields[$field], $value);
            $where[] = $condition;
            $binds[] = $bindValue;
            $types .= $bindType;
        }

        if (isset($params['updated_since']) && $params['updated_since'] !== '') {
            $updatedColumn = $this->detectTimestampColumn(['updated_at', 'updated_time', 'last_modified', 'modified_at']);
            if ($updatedColumn !== null) {
                $where[] = "$updatedColumn >= ?";
                $binds[] = (int)$params['updated_since'];
                $types .= 'i';
            }
        }

        if (isset($params['deleted_since']) && $params['deleted_since'] !== '' && $this->deletedColumn() !== null) {
            $deletedColumn = $this->detectTimestampColumn(['deleted_at', 'deleted_time', 'removed_at']);
            if ($deletedColumn !== null) {
                $where[] = "$deletedColumn >= ?";
                $binds[] = (int)$params['deleted_since'];
                $types .= 'i';
            }
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $orderBy = $this->listOrderBy();

        $countSql = "SELECT COUNT(*) as total FROM {$this->tableName()} $whereClause";
        $total = 0;
        if ($types) {
            $stmt = $this->prepare($countSql);
            $this->bind($stmt, $types, ...$binds);
            $this->execute($stmt, 'Count query failed');
            $total = (int)$this->resultOf($stmt, 'Count query failed')->fetch_assoc()['total'];
            $stmt->close();
        } else {
            $result = $this->db->query($countSql);
            if (!$result) {
                throw new DatabaseException('Count query failed');
            }
            $total = (int)$result->fetch_assoc()['total'];
        }

        $sql = "SELECT $selectExpr FROM {$this->tableName()} $whereClause ORDER BY $orderBy LIMIT ? OFFSET ?";
        $binds[] = $limit;
        $types .= 'i';
        $binds[] = $offset;
        $types .= 'i';

        $stmt = $this->prepare($sql);
        $this->bind($stmt, $types, ...$binds);
        $this->execute($stmt, 'List query failed');
        $result = $this->resultOf($stmt, 'List query failed');

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $this->withVersionMetadata($row);
        }
        $stmt->close();

        $nextCursor = null;
        $cursorExpiresAt = null;
        if (($offset + $limit) < $total) {
            $cursorExpiresAt = time() + $cursorTtl;
            $nextCursor = $this->encodeOffsetCursor($offset + $limit, $cursorExpiresAt);
        }

        return [
            'data' => $rows,
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'cursor' => $nextCursor,
                'cursor_expires_at' => $cursorExpiresAt,
            ],
        ];
    }

    public function get(int|string $id): array
    {
        $selectExpr = implode(', ', $this->selectColumns());
        $where = [$this->primaryKey() . ' = ?'];
        $binds = [$id];
        $types = is_int($id) ? 'i' : 's';

        if ($this->userIdColumn()) {
            $where[] = $this->userIdColumn() . ' = ?';
            $binds[] = $this->userId;
            $types .= 'i';
        }

        if ($this->deletedColumn()) {
            $where[] = $this->deletedColumn() . ' = 0';
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);
        $sql = "SELECT $selectExpr FROM {$this->tableName()} $whereClause LIMIT 1";
        $stmt = $this->prepare($sql);
        $this->bind($stmt, $types, ...$binds);
        $this->execute($stmt, 'Query failed');
        // A failed get_result() is a database error, not "no such record":
        // read as one, an update or a delete would answer 404 for a row that
        // exists, and bulk-upsert would create a second copy of it.
        $row = $this->resultOf($stmt, 'Query failed')->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new NotFoundException();
        }

        $row = $this->withVersionMetadata($row);
        if (!headers_sent()) {
            header('ETag: ' . $row['etag']);
        }

        return ['data' => $row];
    }

    public function create(array $payload): array
    {
        $clean = $this->validatePayload($payload, requireRequired: true);
        $this->assertLinksOwned($clean);
        $extras = $this->beforeCreate($clean);

        $columns = [];
        $placeholders = [];
        $binds = [];
        $types = '';
        $fields = $this->resolveFields();

        foreach ($fields as $col => $def) {
            if ($def['readonly'] ?? false) {
                continue;
            }
            if (array_key_exists($col, $extras)) {
                continue;
            }
            if (array_key_exists($col, $clean)) {
                $columns[] = $col;
                $placeholders[] = '?';
                $binds[] = $clean[$col];
                $types .= $def['type'];
            } elseif (array_key_exists('default', $def)) {
                // NOT-NULL columns the client omitted get their declared default so the
                // INSERT does not fail under MySQL strict mode (the V3 API connection
                // does not disable strict mode the way the connect.php UI path does).
                $columns[] = $col;
                $placeholders[] = '?';
                $binds[] = $def['default'];
                $types .= $def['type'];
            }
        }

        foreach ($extras as $col => $info) {
            $columns[] = $col;
            $placeholders[] = '?';
            $binds[] = $info['value'];
            $types .= $info['type'];
        }

        if ($this->userIdColumn()) {
            $columns[] = $this->userIdColumn();
            $placeholders[] = '?';
            $binds[] = $this->userId;
            $types .= 'i';
        }

        if (empty($columns)) {
            throw new ValidationException('No valid fields provided');
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->tableName(),
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->prepare($sql);
        $this->bind($stmt, $types, ...$binds);

        try {
            $this->execute($stmt, 'Insert failed');
        } catch (\mysqli_sql_exception $e) {
            $this->rethrowDuplicateKey($e, $stmt);
        }

        $insertId = $stmt->insert_id;
        $stmt->close();

        // The row exists from here on. A failure past this point is not a
        // failed create, and must not be reported as one: the caller would
        // retry and make a second row. See WriteCommittedException.
        try {
            $this->afterCreate($insertId, $clean);
            $created = $this->get($insertId);
            $this->recordChange('create', (array)$created['data']);
        } catch (\Throwable $e) {
            throw new WriteCommittedException($this->changeEntityName() ?? 'record', $e);
        }

        return $created;
    }

    public function update(int|string $id, array $payload): array
    {
        $current = $this->get($id);
        $currentData = (array)$current['data'];
        $this->assertIfMatchSatisfied($currentData);
        $clean = $this->validatePayload($payload, current: $currentData);
        $this->assertLinksOwned($clean, $currentData);
        $extras = $this->beforeUpdate($id, $clean);

        $sets = [];
        $binds = [];
        $types = '';
        $fields = $this->resolveFields();

        foreach ($fields as $col => $def) {
            if ($def['readonly'] ?? false) {
                continue;
            }
            if (array_key_exists($col, $clean)) {
                $sets[] = "$col = ?";
                $binds[] = $clean[$col];
                $types .= $def['type'];
            }
        }

        // Reject before merging extras: a payload with no writable fields must
        // fail validation rather than silently bump hook columns like updated_at.
        if (empty($sets)) {
            throw new NothingToUpdateException('No valid fields to update');
        }

        foreach ($extras as $col => $info) {
            $sets[] = "$col = ?";
            $binds[] = $info['value'];
            $types .= $info['type'];
        }

        $binds[] = $id;
        $types .= is_int($id) ? 'i' : 's';

        $where = [$this->primaryKey() . ' = ?'];
        if ($this->userIdColumn()) {
            $where[] = $this->userIdColumn() . ' = ?';
            $binds[] = $this->userId;
            $types .= 'i';
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->tableName(),
            implode(', ', $sets),
            implode(' AND ', $where)
        );

        $stmt = $this->prepare($sql);
        $this->bind($stmt, $types, ...$binds);

        try {
            $this->execute($stmt, 'Update failed');
        } catch (\mysqli_sql_exception $e) {
            $this->rethrowDuplicateKey($e, $stmt);
        }
        $stmt->close();

        // As in create(): the write has landed, so a later failure must not
        // read as "the update did not happen".
        try {
            $updated = $this->get($id);
            $this->recordChange('update', (array)$updated['data']);
        } catch (\Throwable $e) {
            throw new WriteCommittedException($this->changeEntityName() ?? 'record', $e);
        }
        return $updated;
    }

    public function delete(int|string $id): void
    {
        $this->recordDeleted($this->deleteRecord($id));
    }

    /**
     * The delete itself: beforeDelete() and the row write, without the
     * change record. Returns the row as it was.
     *
     * Split from recordDeleted() so a controller that runs the delete inside
     * a transaction can record the change after the commit: recordChange()
     * inside the transaction would have its failure roll the delete back and
     * still be reported as WriteCommittedException — "the write landed,
     * never retry" — about a write that did not land (CLAUDE.md #13).
     *
     * @return array<string, mixed>
     */
    protected function deleteRecord(int|string $id): array
    {
        $existing = $this->get($id);
        $this->beforeDelete($id);

        $binds = [$id];
        $types = is_int($id) ? 'i' : 's';
        $where = [$this->primaryKey() . ' = ?'];

        if ($this->userIdColumn()) {
            $where[] = $this->userIdColumn() . ' = ?';
            $binds[] = $this->userId;
            $types .= 'i';
        }

        if ($this->deletedColumn()) {
            $sql = sprintf(
                'UPDATE %s SET %s = 1 WHERE %s',
                $this->tableName(),
                $this->deletedColumn(),
                implode(' AND ', $where)
            );
        } else {
            $sql = sprintf(
                'DELETE FROM %s WHERE %s',
                $this->tableName(),
                implode(' AND ', $where)
            );
        }

        $stmt = $this->prepare($sql);
        $this->bind($stmt, $types, ...$binds);

        $this->execute($stmt, 'Delete failed');
        $stmt->close();

        return (array)$existing['data'];
    }

    /**
     * Record a delete that has landed. Call it only once the delete is
     * committed: a failure here is reported as WriteCommittedException, which
     * tells every retry seam the row is already gone.
     *
     * @param array<string, mixed> $deleted The row deleteRecord() returned.
     */
    protected function recordDeleted(array $deleted): void
    {
        try {
            $this->recordChange('delete', $deleted);
        } catch (\Throwable $e) {
            throw new WriteCommittedException($this->changeEntityName() ?? 'record', $e);
        }
    }

    /**
     * Read-only preview of delete(): the record that would be removed,
     * without removing it. Backs `?dry_run=1` on DELETE routes. Controllers
     * whose deletes cascade override this to report the cascade too.
     */
    public function deletePreview(int|string $id): array
    {
        $existing = $this->get($id);
        return ['data' => [
            'dry_run' => true,
            'action' => 'delete',
            'resource' => $this->changeEntityName() ?? $this->tableName(),
            'mode' => $this->deletedColumn() !== null ? 'soft' : 'hard',
            'record' => $existing['data'],
            'cascade' => [],
        ]];
    }

    public function bulkUpsert(array $payload): array
    {
        $idempotencyKey = trim((string)(\Api\V3\RequestContext::header('idempotency-key') ?? ''));
        if ($idempotencyKey === '') {
            throw new ValidationException('Idempotency-Key header is required', ['idempotency_key' => 'Missing Idempotency-Key header']);
        }

        // The body is a list of rows, or {"rows": [...]}: an object with any
        // other key is not a bulk body (a typo'd `rows` used to be read as a
        // batch of one row per key).
        if (!array_is_list($payload)) {
            PayloadKeys::refuseUnknown($payload, ['rows'], 'a bulk-upsert body ({"rows": [...]}, or the list of rows itself)');
        }
        $rows = $payload['rows'] ?? $payload;
        if (!is_array($rows)) {
            throw new ValidationException('rows must be an array', ['rows' => 'Expected array']);
        }
        $maxRows = $this->maxBulkRows();
        if (count($rows) > $maxRows) {
            throw new ValidationException('rows exceeds max size', ['rows' => "Maximum {$maxRows} rows per request"]);
        }

        // The target table and the rows are a fingerprint recorded beside
        // the response, not part of the scope: with them in the scope a key
        // reused for a changed batch (or a different table) reads a
        // different file, finds nothing, and re-applies the whole batch —
        // the duplicate the key was sent to prevent.
        $requestHash = ServerStateStore::idempotencyFingerprint('bulk-upsert:' . $this->tableName(), ['rows' => $rows]);
        $scope = ServerStateStore::idempotencyScopeForUser($this->userId);
        $lookup = $this->stateStore()->lookupIdempotent($scope, $idempotencyKey, $requestHash);
        if ($lookup['state'] === 'mismatch') {
            throw new ValidationException(
                'This Idempotency-Key was already used for a different request. Resend the original '
                . 'rows to this same endpoint to replay the recorded response, or send a new '
                . 'Idempotency-Key for a different batch.',
                ['idempotency_key' => 'Already used for a different request']
            );
        }
        if ($lookup['state'] === 'replay' && is_array($lookup['response'])) {
            $existing = $lookup['response'];
            $existing['idempotent_replay'] = true;
            return $existing;
        }

        $results = [];
        $summary = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'error' => 0];
        $chunkSize = max(1, min(100, $maxRows));
        foreach (array_chunk($rows, $chunkSize, true) as $chunk) {
            $this->transaction(function () use (&$chunk, &$summary, &$results): void {
                foreach ($chunk as $index => $row) {
                    if (!is_array($row)) {
                        $summary['error']++;
                        $results[] = ['index' => $index, 'status' => 'error', 'message' => 'Row must be an object'];
                        continue;
                    }
                    if ($row === []) {
                        $summary['skipped']++;
                        $results[] = ['index' => $index, 'status' => 'skipped', 'message' => 'Row is empty'];
                        continue;
                    }

                    try {
                        $primaryKey = $this->primaryKey();
                        $named = static fn (string $key): bool => isset($row[$key]) && $row[$key] !== '';
                        if ($named($primaryKey) && $named('id') && !PayloadKeys::same($row[$primaryKey], $row['id'])) {
                            $summary['error']++;
                            $results[] = ['index' => $index, 'status' => 'error', 'message' => "$primaryKey and id name different records: send one"];
                            continue;
                        }
                        $id = $named($primaryKey) ? $row[$primaryKey] : ($named('id') ? $row['id'] : null);
                        // `id` is this endpoint's lookup key, not a field of
                        // the record: it is read here and goes no further. An
                        // empty primary key is "no id", as it always was.
                        $fields = $row;
                        unset($fields['id']);
                        if (!$named($primaryKey)) {
                            unset($fields[$primaryKey]);
                        }
                        if ($id !== null) {
                            // Strictly validate the ID instead of passing it through
                            // as a string: binding "12abc" as 's' against an integer
                            // PK would let MySQL coerce it to 12 and silently
                            // overwrite the wrong row.
                            if (is_int($id)) {
                                // Already an integer.
                            } elseif (is_string($id) && ctype_digit(trim($id))) {
                                $id = (int)trim($id);
                            } elseif (is_float($id) && $id === (float)(int)$id) {
                                $id = (int)$id;
                            } else {
                                $summary['error']++;
                                $results[] = ['index' => $index, 'status' => 'error', 'message' => 'Invalid primary key value'];
                                continue;
                            }
                            if ($named($primaryKey)) {
                                // The key as it was read (" 12 " is row 12),
                                // so update() compares the record's own id.
                                $fields[$primaryKey] = $id;
                            }
                            $exists = true;
                            try {
                                $this->get($id);
                            } catch (NotFoundException) {
                                $exists = false;
                            }
                            if ($exists) {
                                // The row goes through update() whole, so a
                                // field it does not write is refused here as
                                // on a PUT; a row with nothing to change (only
                                // its id, or read-only values the record
                                // holds) is skipped.
                                try {
                                    $updated = $this->update($id, $fields);
                                } catch (NothingToUpdateException) {
                                    $summary['skipped']++;
                                    $results[] = ['index' => $index, 'status' => 'skipped', 'message' => 'No mutable fields provided'];
                                    continue;
                                }
                                $summary['updated']++;
                                $results[] = ['index' => $index, 'status' => 'updated', 'data' => $updated['data']];
                                continue;
                            }
                            // An id that names none of this account's records
                            // is the lookup key of a create, not a field of it:
                            // the row is created with an id of its own, which
                            // the result carries.
                            unset($fields[$primaryKey]);
                        }

                        $created = $this->create($fields);
                        $summary['created']++;
                        $results[] = ['index' => $index, 'status' => 'created', 'data' => $created['data']];
                    } catch (\Throwable $e) {
                        $summary['error']++;
                        $failed = ['index' => $index, 'status' => 'error', 'message' => $e->getMessage()];
                        // "Validation failed" alone does not say which field.
                        if ($e instanceof ValidationException && $e->getFieldErrors() !== []) {
                            $failed['field_errors'] = $e->getFieldErrors();
                        }
                        $results[] = $failed;
                    }
                }
            });
        }

        $response = [
            'data' => $results,
            'summary' => $summary,
            'idempotent_replay' => false,
        ];
        $this->stateStore()->incrementMetric('bulk_upsert_created', (int)$summary['created']);
        $this->stateStore()->incrementMetric('bulk_upsert_updated', (int)$summary['updated']);
        $this->stateStore()->incrementMetric('bulk_upsert_skipped', (int)$summary['skipped']);
        $this->stateStore()->incrementMetric('bulk_upsert_errors', (int)$summary['error']);
        $this->stateStore()->putIdempotent($scope, $idempotencyKey, $response, $requestHash);

        return $response;
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    protected function stateStore(): ServerStateStore
    {
        if ($this->stateStore === null) {
            $this->stateStore = new ServerStateStore();
        }
        return $this->stateStore;
    }

    protected function withVersionMetadata(array $row): array
    {
        $version = $this->computeVersionHash($row);
        $row['version'] = $version;
        $row['etag'] = '"' . $version . '"';
        return $row;
    }

    protected function computeVersionHash(array $row): string
    {
        unset($row['version'], $row['etag']);
        ksort($row);
        $json = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return sha1((string)microtime(true));
        }
        return sha1($json);
    }

    protected function assertIfMatchSatisfied(array $currentRow): void
    {
        $ifMatch = trim((string)(\Api\V3\RequestContext::header('if-match') ?? ''));
        if ($ifMatch === '' || $ifMatch === '*') {
            return;
        }

        $expected = trim($ifMatch);
        if (str_starts_with($expected, 'W/')) {
            $expected = substr($expected, 2);
        }
        $expected = trim($expected, '" ');

        $currentVersion = $this->computeVersionHash($currentRow);
        if ($expected !== $currentVersion) {
            $this->stateStore()->incrementMetric('conflicts', 1);
            throw new ConflictException(
                'Version mismatch',
                [
                    'expected_version' => $expected,
                    'current_version' => $currentVersion,
                    'diff_hint' => 'Re-fetch resource and retry update with latest ETag.',
                ]
            );
        }
    }

    protected function encodeOffsetCursor(int $offset, int $expiresAt): string
    {
        $json = json_encode(['offset' => $offset, 'expires_at' => $expiresAt], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new ValidationException('Failed to encode cursor');
        }
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    protected function decodeOffsetCursor(string $cursor): int
    {
        $payload = strtr($cursor, '-_', '+/');
        $padLen = strlen($payload) % 4;
        if ($padLen !== 0) {
            $payload .= str_repeat('=', 4 - $padLen);
        }

        $raw = base64_decode($payload, true);
        if ($raw === false) {
            throw new ValidationException('Invalid cursor', ['cursor' => 'Malformed cursor']);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new ValidationException('Invalid cursor', ['cursor' => 'Malformed cursor']);
        }
        if (!empty($decoded['expires_at']) && (int)$decoded['expires_at'] < time()) {
            throw new ValidationException('Cursor expired', ['cursor' => 'Cursor has expired']);
        }

        return max(0, (int)($decoded['offset'] ?? 0));
    }

    protected function detectTimestampColumn(array $candidates): ?string
    {
        foreach ($candidates as $column) {
            if ($this->hasColumn($column)) {
                return $column;
            }
        }
        return null;
    }

    /**
     * Whether this resource's table has $column. A probe that fails does not
     * know, and says so by throwing: answered "no column", a list's
     * updated_since/deleted_since filter was dropped and every row answered
     * as though filtered (CLAUDE.md #11: a predicate must not answer when it
     * does not know).
     */
    protected function hasColumn(string $column): bool
    {
        $sql = sprintf('SHOW COLUMNS FROM %s LIKE ?', $this->tableName());
        $stmt = $this->prepare($sql);
        $this->bind($stmt, 's', $column);
        $this->execute($stmt, 'Column probe failed');
        $row = $this->resultOf($stmt, 'Column probe failed')->fetch_assoc();
        $stmt->close();
        return (bool)$row;
    }

    protected function recordChange(string $operation, array $record): void
    {
        // Landing Page Optimizer (segments-v2 G10): create/update/delete of
        // a synced dictionary through the v3 API changes the dimension
        // snapshot exactly like the setup-page save/delete paths, so flag
        // the user's snapshot dirty for the hourly push. This is the shared
        // post-mutation choke point (create(), update(), delete(), and
        // bulkUpsert() via create/update all land here). markDirty is
        // DB-only and never fatal, so API responses cannot break on pairing
        // problems or pre-upgrade schemas.
        if (in_array($this->tableName(), self::LPO_SYNCED_DICTIONARIES, true)) {
            \Prosper202\Lpo\DimensionSync::markDirty($this->db, $this->userId);
        }

        $entity = $this->changeEntityName();
        if ($entity === null) {
            return;
        }
        $this->stateStore()->recordChange($entity, $operation, $record, $this->userId);
    }

    protected function changeEntityName(): ?string
    {
        $map = [
            '202_aff_networks' => 'aff-networks',
            '202_ppc_networks' => 'ppc-networks',
            '202_ppc_accounts' => 'ppc-accounts',
            '202_aff_campaigns' => 'campaigns',
            '202_landing_pages' => 'landing-pages',
            '202_text_ads' => 'text-ads',
            '202_trackers' => 'trackers',
        ];
        return $map[$this->tableName()] ?? null;
    }

}
