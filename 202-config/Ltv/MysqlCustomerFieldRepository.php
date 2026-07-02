<?php

declare(strict_types=1);

namespace Prosper202\Ltv;

use Prosper202\Database\Connection;
use RuntimeException;

/**
 * Custom field definitions for customers (202_customer_fields) and the typed
 * value writes (202_customer_field_values). Values are stored in the column
 * matching the field's declared type so number/date filters stay index-backed.
 */
final class MysqlCustomerFieldRepository
{
    public const FIELD_TYPES = ['text', 'number', 'date', 'boolean', 'select', 'email', 'url'];

    public function __construct(private Connection $conn)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $userId): array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT field_id, field_key, label, field_type, options, is_required, sort_order, created_at, updated_at
             FROM 202_customer_fields WHERE user_id = ? ORDER BY sort_order ASC, field_id ASC'
        );
        $this->conn->bind($stmt, 'i', [$userId]);

        return $this->conn->fetchAll($stmt);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByKey(int $userId, string $fieldKey): ?array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT field_id, field_key, label, field_type, options, is_required, sort_order
             FROM 202_customer_fields WHERE user_id = ? AND field_key = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'is', [$userId, $fieldKey]);

        return $this->conn->fetchOne($stmt);
    }

    /**
     * Create a field definition. field_key is normalized to [a-z0-9_].
     *
     * @param array<string, mixed> $payload
     */
    public function create(int $userId, array $payload): int
    {
        $key = strtolower(trim((string) ($payload['field_key'] ?? '')));
        if ($key === '' || preg_match('/^[a-z0-9_]{1,64}$/', $key) !== 1) {
            throw new RuntimeException('field_key must be 1-64 chars of a-z, 0-9 and underscore');
        }
        $label = trim((string) ($payload['label'] ?? $key));
        $type = strtolower(trim((string) ($payload['field_type'] ?? 'text')));
        if (!in_array($type, self::FIELD_TYPES, true)) {
            throw new RuntimeException('field_type must be one of: ' . implode(', ', self::FIELD_TYPES));
        }

        $options = null;
        if ($type === 'select') {
            $optionList = $payload['options'] ?? null;
            if (!is_array($optionList) || $optionList === []) {
                throw new RuntimeException('select fields require a non-empty options array');
            }
            $options = json_encode(array_values(array_map(strval(...), $optionList)));
            if ($options === false) {
                throw new RuntimeException('Failed to encode field options');
            }
        }

        if ($this->findByKey($userId, $key) !== null) {
            throw new RuntimeException('A field with key "' . $key . '" already exists');
        }

        $now = time();
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_customer_fields
                (user_id, field_key, label, field_type, options, is_required, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $this->conn->bind($stmt, 'issssiiii', [
            $userId,
            $key,
            $label,
            $type,
            $options,
            !empty($payload['is_required']) ? 1 : 0,
            max(0, (int) ($payload['sort_order'] ?? 0)),
            $now,
            $now,
        ]);

        return $this->conn->executeInsert($stmt);
    }

    /**
     * Update label / options / is_required / sort_order. field_key and
     * field_type are immutable (changing the type would orphan typed values).
     *
     * @param array<string, mixed> $payload
     */
    public function update(int $userId, int $fieldId, array $payload): void
    {
        $field = $this->findById($userId, $fieldId);
        if ($field === null) {
            throw new RuntimeException('Field not found');
        }

        $sets = [];
        $types = '';
        $binds = [];

        if (array_key_exists('label', $payload)) {
            $sets[] = 'label = ?';
            $types .= 's';
            $binds[] = trim((string) $payload['label']);
        }
        if (array_key_exists('options', $payload)) {
            if ((string) $field['field_type'] !== 'select') {
                throw new RuntimeException('options apply only to select fields');
            }
            if (!is_array($payload['options']) || $payload['options'] === []) {
                throw new RuntimeException('select fields require a non-empty options array');
            }
            $encoded = json_encode(array_values(array_map(strval(...), $payload['options'])));
            if ($encoded === false) {
                throw new RuntimeException('Failed to encode field options');
            }
            $sets[] = 'options = ?';
            $types .= 's';
            $binds[] = $encoded;
        }
        if (array_key_exists('is_required', $payload)) {
            $sets[] = 'is_required = ?';
            $types .= 'i';
            $binds[] = !empty($payload['is_required']) ? 1 : 0;
        }
        if (array_key_exists('sort_order', $payload)) {
            $sets[] = 'sort_order = ?';
            $types .= 'i';
            $binds[] = max(0, (int) $payload['sort_order']);
        }
        if ($sets === []) {
            throw new RuntimeException('No updatable properties supplied');
        }

        $sets[] = 'updated_at = ?';
        $types .= 'i';
        $binds[] = time();

        $types .= 'ii';
        $binds[] = $fieldId;
        $binds[] = $userId;

        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_customer_fields SET ' . implode(', ', $sets) . ' WHERE field_id = ? AND user_id = ?'
        );
        $this->conn->bind($stmt, $types, $binds);
        $this->conn->executeUpdate($stmt);
    }

    /**
     * Delete a field definition and all its stored values (transactional).
     */
    public function delete(int $userId, int $fieldId): void
    {
        if ($this->findById($userId, $fieldId) === null) {
            throw new RuntimeException('Field not found');
        }

        $this->conn->transaction(function () use ($userId, $fieldId): void {
            $stmt = $this->conn->prepareWrite(
                'DELETE FROM 202_customer_field_values WHERE field_id = ? AND user_id = ?'
            );
            $this->conn->bind($stmt, 'ii', [$fieldId, $userId]);
            $this->conn->executeUpdate($stmt);

            $stmt = $this->conn->prepareWrite(
                'DELETE FROM 202_customer_fields WHERE field_id = ? AND user_id = ?'
            );
            $this->conn->bind($stmt, 'ii', [$fieldId, $userId]);
            $this->conn->executeUpdate($stmt);
        });
    }

    /**
     * Set one customer's value for a field, validated + stored per the
     * field's declared type. A null value deletes the stored value.
     *
     * @param array<string, mixed> $field a row from list()/findByKey()
     */
    public function setValue(int $userId, int $customerId, array $field, mixed $value): void
    {
        $fieldId = (int) $field['field_id'];

        if ($value === null || (is_string($value) && trim($value) === '')) {
            $stmt = $this->conn->prepareWrite(
                'DELETE FROM 202_customer_field_values WHERE customer_id = ? AND field_id = ? AND user_id = ?'
            );
            $this->conn->bind($stmt, 'iii', [$customerId, $fieldId, $userId]);
            $this->conn->executeUpdate($stmt);
            return;
        }

        [$text, $number, $date] = $this->coerce((string) $field['field_type'], (string) $field['field_key'], $field['options'] ?? null, $value);

        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_customer_field_values (user_id, customer_id, field_id, value_text, value_number, value_date)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                value_text = VALUES(value_text),
                value_number = VALUES(value_number),
                value_date = VALUES(value_date)'
        );
        $this->conn->bind($stmt, 'iiisdi', [$userId, $customerId, $fieldId, $text, $number, $date]);
        $this->conn->executeUpdate($stmt);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findById(int $userId, int $fieldId): ?array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT field_id, field_key, field_type, options FROM 202_customer_fields
             WHERE field_id = ? AND user_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'ii', [$fieldId, $userId]);

        return $this->conn->fetchOne($stmt);
    }

    /**
     * Validate + type a raw value into (value_text, value_number, value_date).
     * Rejects malformed input explicitly — never silently coerces garbage.
     *
     * @return array{0: ?string, 1: ?float, 2: ?int}
     */
    private function coerce(string $fieldType, string $fieldKey, mixed $optionsJson, mixed $value): array
    {
        switch ($fieldType) {
            case 'number':
                if (!is_numeric($value)) {
                    throw new RuntimeException("Field {$fieldKey} expects a number");
                }
                return [null, (float) $value, null];

            case 'boolean':
                if (is_bool($value)) {
                    return [null, $value ? 1.0 : 0.0, null];
                }
                $normalized = strtolower(trim((string) $value));
                if (!in_array($normalized, ['0', '1', 'true', 'false', 'yes', 'no'], true)) {
                    throw new RuntimeException("Field {$fieldKey} expects a boolean");
                }
                return [null, in_array($normalized, ['1', 'true', 'yes'], true) ? 1.0 : 0.0, null];

            case 'date':
                if (is_numeric($value)) {
                    return [null, null, (int) $value];
                }
                $parsed = strtotime(trim((string) $value));
                if ($parsed === false) {
                    throw new RuntimeException("Field {$fieldKey} expects a date (unix timestamp or parseable string)");
                }
                return [null, null, $parsed];

            case 'select':
                $text = trim((string) $value);
                $options = is_string($optionsJson) ? json_decode($optionsJson, true) : $optionsJson;
                if (!is_array($options) || !in_array($text, array_map(strval(...), $options), true)) {
                    throw new RuntimeException("Field {$fieldKey} value must be one of its configured options");
                }
                return [$text, null, null];

            case 'email':
                $text = trim((string) $value);
                if (filter_var($text, FILTER_VALIDATE_EMAIL) === false) {
                    throw new RuntimeException("Field {$fieldKey} expects a valid email address");
                }
                return [$text, null, null];

            case 'url':
                $text = trim((string) $value);
                if (filter_var($text, FILTER_VALIDATE_URL) === false) {
                    throw new RuntimeException("Field {$fieldKey} expects a valid URL");
                }
                return [$text, null, null];

            case 'text':
            default:
                $text = trim((string) $value);
                if (strlen($text) > 1000) {
                    throw new RuntimeException("Field {$fieldKey} value exceeds 1000 characters");
                }
                return [$text, null, null];
        }
    }
}
