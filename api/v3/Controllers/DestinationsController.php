<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\PayloadKeys;
use Prosper202\Database\Connection;
use Prosper202\Destinations\DestinationInputException;
use Prosper202\Destinations\DestinationQueue;
use Prosper202\Destinations\DestinationStore;

/**
 * /destinations: the ad platforms the account sends conversions to
 * (Prosper202\Destinations), a test fire, and each one's delivery log.
 * Credentials are taken and never served: a read says whether they are set.
 */
final class DestinationsController
{
    private readonly Connection $conn;
    private readonly DestinationStore $store;

    public function __construct(\mysqli $db, private readonly int $userId)
    {
        $this->conn = new Connection($db);
        $this->store = new DestinationStore($this->conn);
    }

    public function list(): array
    {
        return ['data' => $this->store->list($this->userId)];
    }

    public function get(int $id): array
    {
        return ['data' => $this->store->get($this->userId, $id) ?? throw self::notFound($id)];
    }

    /** @param array<string, mixed> $payload */
    public function create(array $payload): array
    {
        PayloadKeys::refuseUnknown($payload, ['type', 'name', 'settings', 'credentials'], 'a destination');
        $type = $this->refusing(fn () => $this->store->type(is_string($payload['type'] ?? null) ? $payload['type'] : ''));
        PayloadKeys::refuse([
            ...PayloadKeys::objectErrors($payload, 'settings', [...DestinationStore::COMMON_SETTINGS, ...$type->settingKeys()], 'destination settings'),
            ...PayloadKeys::objectErrors($payload, 'credentials', $type->credentialKeys(), 'destination credentials'),
        ]);
        if (!is_array($payload['settings'] ?? null) || !is_array($payload['credentials'] ?? null)) {
            throw new ValidationException('Validation failed', [is_array($payload['settings'] ?? null) ? 'credentials' : 'settings' => 'Required, an object.']);
        }

        return ['data' => $this->refusing(fn (): array => $this->store->create(
            $this->userId,
            (string) $payload['type'],
            is_string($payload['name'] ?? null) ? $payload['name'] : '',
            $payload['settings'],
            $payload['credentials'],
            time()
        ))];
    }

    /** @param array<string, mixed> $payload */
    public function update(int $id, array $payload): array
    {
        PayloadKeys::refuseUnknown($payload, ['name', 'enabled', 'settings', 'credentials'], 'a destination update');
        if ($payload === []) {
            throw new ValidationException('Nothing to update', ['body' => 'Send at least one of name, enabled, settings, credentials.']);
        }
        $current = $this->store->get($this->userId, $id) ?? throw self::notFound($id);
        $type = $this->refusing(fn () => $this->store->type((string) $current['type']));
        PayloadKeys::refuse([
            ...PayloadKeys::objectErrors($payload, 'settings', [...DestinationStore::COMMON_SETTINGS, ...$type->settingKeys()], 'destination settings'),
            ...PayloadKeys::objectErrors($payload, 'credentials', $type->credentialKeys(), 'destination credentials'),
        ]);

        return ['data' => $this->refusing(fn (): ?array => $this->store->update($this->userId, $id, $payload, time())) ?? throw self::notFound($id)];
    }

    public function delete(int $id): void
    {
        if (!$this->store->delete($this->userId, $id, time())) {
            throw self::notFound($id);
        }
    }

    public function deletePreview(int $id): array
    {
        $destination = $this->store->get($this->userId, $id) ?? throw self::notFound($id);
        $pending = $this->rows("SELECT COUNT(*) AS n FROM 202_destination_deliveries WHERE user_id = ? AND destination_id = ? AND status = 'pending'", 'ii', [$this->userId, $id]);

        return ['data' => [
            'dry_run' => true,
            'action' => 'delete',
            'resource' => 'destinations',
            'mode' => 'soft',
            'record' => $destination,
            'cascade' => [['resource' => 'pending-deliveries-cancelled', 'count' => (int) ($pending[0]['n'] ?? 0)]],
        ]];
    }

    public function test(int $id): array
    {
        return ['data' => (new DestinationQueue($this->conn, $this->store))->testFire($this->userId, $id, time()) ?? throw self::notFound($id)];
    }

    /** @param array<string, mixed> $params */
    public function deliveries(int $id, array $params): array
    {
        if ($this->store->get($this->userId, $id) === null) {
            throw self::notFound($id);
        }
        $unknown = array_diff(array_keys($params), ['status', 'kind', 'conv_id', 'time_from', 'time_to', 'limit', 'offset']);
        if ($unknown !== []) {
            throw new ValidationException('Unknown parameter', [(string) reset($unknown) => 'Not a parameter of the delivery log. Valid: status, kind, conv_id, time_from, time_to, limit, offset']);
        }
        $limit = AppPostbacksController::boundedInt($params, 'limit', 50, 1, 500);
        $offset = AppPostbacksController::boundedInt($params, 'offset', 0, 0, PHP_INT_MAX);
        $where = ['user_id = ?', 'destination_id = ?'];
        $types = 'ii';
        $binds = [$this->userId, $id];
        foreach (['kind' => [DestinationQueue::KIND_CONVERSION, DestinationQueue::KIND_TEST]] as $param => $allowed) {
            if (isset($params[$param]) && $params[$param] !== '') {
                if (!in_array($params[$param], $allowed, true)) {
                    throw new ValidationException('Invalid filter value', [$param => 'Must be one of: ' . implode(', ', $allowed)]);
                }
                $where[] = $param . ' = ?';
                $types .= 's';
                $binds[] = $params[$param];
            }
        }
        foreach (['conv_id' => 'conv_id = ?', 'time_from' => 'created_at >= ?', 'time_to' => 'created_at <= ?'] as $param => $condition) {
            if (isset($params[$param]) && $params[$param] !== '') {
                $where[] = $condition;
                $types .= 'i';
                $binds[] = AppPostbacksController::strictInt($params[$param], $param, 'Invalid filter value', 'Must be an integer');
            }
        }
        $summary = array_fill_keys(DestinationQueue::STATUSES, 0);
        foreach ($this->rows('SELECT status, COUNT(*) AS n FROM 202_destination_deliveries WHERE ' . implode(' AND ', $where) . ' GROUP BY status', $types, $binds) as $row) {
            $summary[(string) $row['status']] = (int) $row['n'];
        }
        if (isset($params['status']) && $params['status'] !== '') {
            if (!in_array($params['status'], DestinationQueue::STATUSES, true)) {
                throw new ValidationException('Invalid filter value', ['status' => 'Must be one of: ' . implode(', ', DestinationQueue::STATUSES)]);
            }
            $where[] = 'status = ?';
            $types .= 's';
            $binds[] = $params['status'];
        }
        $sql = ' FROM 202_destination_deliveries WHERE ' . implode(' AND ', $where);
        $total = (int) ($this->rows('SELECT COUNT(*) AS n' . $sql, $types, $binds)[0]['n'] ?? 0);
        $data = [];
        foreach (
            $this->rows('SELECT delivery_id, conv_id, kind, status, attempts, next_attempt_at, last_status_code, last_error, summary, created_at, sent_at'
            . $sql . ' ORDER BY delivery_id DESC LIMIT ? OFFSET ?', $types . 'ii', [...$binds, $limit, $offset]) as $row
        ) {
            foreach (['delivery_id', 'conv_id', 'attempts', 'next_attempt_at', 'last_status_code', 'created_at', 'sent_at'] as $int) {
                $row[$int] = $row[$int] === null ? null : (int) $row[$int];
            }
            $row['summary'] = $row['summary'] === null ? null : json_decode((string) $row['summary'], true);
            $data[] = $row;
        }

        return ['data' => $data, 'pagination' => ['total' => $total, 'limit' => $limit, 'offset' => $offset], 'meta' => ['summary' => $summary]];
    }

    /**
     * @template T
     * @param callable(): T $write
     * @return T
     */
    private function refusing(callable $write): mixed
    {
        try {
            return $write();
        } catch (DestinationInputException $e) {
            throw new ValidationException($e->getMessage(), [$e->field => $e->getMessage()], $e);
        }
    }

    /**
     * @param list<mixed> $binds
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, string $types, array $binds): array
    {
        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, $types, $binds);

        return $this->conn->fetchAll($stmt);
    }

    private static function notFound(int $id): NotFoundException
    {
        return new NotFoundException('Destination ' . $id . ' not found; p202 destination list shows yours.');
    }
}
