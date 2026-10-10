<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Api\V3\Exception\WriteCommittedException;
use Api\V3\Support\StatementHelpers;
use Prosper202\Click\TrackingLinkVariables;

/**
 * A traffic source's custom variables (202_ppc_network_variables): the extra
 * parameters its tracking links carry, each with the placeholder the
 * traffic source fills in (`parameter=placeholder`). Setup › Traffic Sources
 * edits them in its variables dialog (tracking202/ajax/custom_variables.php);
 * the tracking-link builder reads the live ones
 * (TrackersController::customVariables(), Prosper202\Click\TrackingLinkVariables).
 *
 *   GET    /ppc-networks/{id}/variables
 *   POST   /ppc-networks/{id}/variables            {name, parameter, placeholder}
 *   PUT    /ppc-networks/{id}/variables/{variableId} any of the three
 *   DELETE /ppc-networks/{id}/variables/{variableId} (?dry_run=1 previews)
 *
 * The dialog's rules: the traffic source must be the caller's and not
 * removed (the same check the dialog's endpoint makes), a variable is only
 * ever read or changed within its own source, every field is required and
 * not blank, and a removed variable is retired (deleted = 1), not erased,
 * as the dialog retires the rows it no longer lists. Beyond the dialog,
 * what would break every link of the source is refused, because the link
 * builder writes the parameter and placeholder into the link as given: a
 * value over the column's 255 characters, spaces, &, #, ? or control
 * characters, an = in the parameter, and the parameter t202id, the link's
 * own id.
 */
final class PpcNetworkVariablesController
{
    use StatementHelpers;

    private const FIELDS = ['name', 'parameter', 'placeholder'];

    /** What a column holds (varchar(255)), in characters. */
    private const MAX_LENGTH = 255;

    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
    }

    /** @return array{data: list<array<string, mixed>>} */
    public function list(int $networkId): array
    {
        $this->network($networkId);
        $stmt = $this->prepare(
            'SELECT ppc_variable_id, ppc_network_id, name, parameter, placeholder
             FROM 202_ppc_network_variables
             WHERE ppc_network_id = ? AND deleted = 0
             ORDER BY ppc_variable_id ASC'
        );
        $this->bind($stmt, 'i', $networkId);
        $this->execute($stmt, 'Variable list failed');
        $result = $stmt->get_result();
        if ($result === false) {
            // Read as an empty list, a source with variables would look
            // like one with none, and its links would seem complete.
            $stmt->close();
            throw new DatabaseException('Variable list failed');
        }
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = self::shape($row);
        }
        $stmt->close();

        return ['data' => $rows];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{data: array<string, mixed>}
     */
    public function create(int $networkId, array $payload): array
    {
        \Api\V3\Support\PayloadKeys::refuseUnknown($payload, self::FIELDS, 'a variable');
        $this->network($networkId);
        $values = self::validate($payload, true);

        $stmt = $this->prepare('INSERT INTO 202_ppc_network_variables (ppc_network_id, name, parameter, placeholder, deleted) VALUES (?, ?, ?, ?, 0)');
        $this->bind($stmt, 'isss', $networkId, $values['name'], $values['parameter'], $values['placeholder']);
        $this->execute($stmt, 'Failed to create variable');
        $variableId = (int) $stmt->insert_id;
        $stmt->close();

        // Committed: the variable exists and the source's links carry it.
        try {
            return ['data' => $this->variable($networkId, $variableId)];
        } catch (\Throwable $e) {
            throw new WriteCommittedException('traffic source variable', $e);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{data: array<string, mixed>}
     */
    public function update(int $networkId, int $variableId, array $payload): array
    {
        \Api\V3\Support\PayloadKeys::refuseUnknown($payload, self::FIELDS, 'a variable');
        $this->network($networkId);
        $this->variable($networkId, $variableId);
        $values = self::validate($payload, false);
        if ($values === []) {
            throw new ValidationException('No fields to update', ['name' => 'Send name, parameter or placeholder']);
        }

        $sets = [];
        $binds = [];
        foreach ($values as $field => $value) {
            $sets[] = $field . ' = ?';
            $binds[] = $value;
        }
        $binds[] = $variableId;
        $binds[] = $networkId;
        $stmt = $this->prepare('UPDATE 202_ppc_network_variables SET ' . implode(', ', $sets) . ' WHERE ppc_variable_id = ? AND ppc_network_id = ? AND deleted = 0');
        $this->bind($stmt, str_repeat('s', count($values)) . 'ii', ...$binds);
        $this->execute($stmt, 'Failed to update variable');
        $stmt->close();

        try {
            return ['data' => $this->variable($networkId, $variableId)];
        } catch (\Throwable $e) {
            throw new WriteCommittedException('traffic source variable', $e);
        }
    }

    /** @return array{data: array<string, mixed>} */
    public function deletePreview(int $networkId, int $variableId): array
    {
        $this->network($networkId);

        return ['data' => [
            'dry_run' => true,
            'action' => 'delete',
            'resource' => 'ppc-network-variables',
            'mode' => 'soft',
            'record' => $this->variable($networkId, $variableId),
            'cascade' => [],
        ]];
    }

    /**
     * Retire the variable, as the dialog retires a row it no longer lists:
     * new links stop carrying it, and clicks already recorded with it keep
     * their values.
     */
    public function delete(int $networkId, int $variableId): void
    {
        $this->network($networkId);
        $this->variable($networkId, $variableId);
        $stmt = $this->prepare('UPDATE 202_ppc_network_variables SET deleted = 1 WHERE ppc_variable_id = ? AND ppc_network_id = ? AND deleted = 0');
        $this->bind($stmt, 'ii', $variableId, $networkId);
        $this->execute($stmt, 'Failed to delete variable');
        $stmt->close();
    }

    /**
     * The fields as sent, checked before anything is written. Unknown
     * fields are refused by name: `placholder` must not be read as "leave
     * the placeholder as it is".
     *
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private static function validate(array $payload, bool $create): array
    {
        // Unknown keys were refused by the handler (PayloadKeys).
        $errors = [];
        $values = [];
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $payload)) {
                if ($create) {
                    $errors[$field] = 'Required';
                }
                continue;
            }
            $problem = self::problem($field, $payload[$field]);
            if ($problem !== null) {
                $errors[$field] = $problem;
                continue;
            }
            $values[$field] = $field === 'name' ? trim((string) $payload[$field]) : (string) $payload[$field];
        }
        if ($errors !== []) {
            throw new ValidationException('Invalid variable', $errors);
        }

        return $values;
    }

    /** Why a field's value cannot be stored, or null. */
    private static function problem(string $field, mixed $value): ?string
    {
        if (!is_string($value)) {
            return 'Must be text';
        }
        if (trim($value) === '') {
            // The dialog's own rule: every field of a variable is filled in.
            return 'Must not be blank';
        }
        if (mb_strlen($value) > self::MAX_LENGTH) {
            return 'At most ' . self::MAX_LENGTH . ' characters';
        }
        if ($field === 'name') {
            return null;
        }
        $problem = TrackingLinkVariables::problem($value);
        if ($problem !== null) {
            return $problem;
        }
        if ($field === 'parameter') {
            if (str_contains($value, '=')) {
                return 'Must not contain =: the link is written parameter=placeholder';
            }
            if (strtolower($value) === 't202id') {
                return "t202id is the link's own id; a second one would replace it and the click would not be tracked";
            }
        }

        return null;
    }

    /** The caller's live traffic source, or 404. */
    private function network(int $networkId): void
    {
        $stmt = $this->prepare('SELECT ppc_network_id FROM 202_ppc_networks WHERE ppc_network_id = ? AND user_id = ? AND ppc_network_deleted = 0 LIMIT 1');
        $this->bind($stmt, 'ii', $networkId, $this->userId);
        if ($this->fetchOne($stmt, 'Traffic source lookup failed') === null) {
            throw new NotFoundException("Traffic source $networkId not found");
        }
    }

    /**
     * A live variable of this source, or 404.
     *
     * @return array<string, mixed>
     */
    private function variable(int $networkId, int $variableId): array
    {
        $stmt = $this->prepare(
            'SELECT ppc_variable_id, ppc_network_id, name, parameter, placeholder
             FROM 202_ppc_network_variables
             WHERE ppc_variable_id = ? AND ppc_network_id = ? AND deleted = 0
             LIMIT 1'
        );
        $this->bind($stmt, 'ii', $variableId, $networkId);
        $row = $this->fetchOne($stmt, 'Variable lookup failed');
        if ($row === null) {
            throw new NotFoundException("Variable $variableId not found on traffic source $networkId");
        }

        return self::shape($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        return [
            'ppc_variable_id' => (int) $row['ppc_variable_id'],
            'ppc_network_id' => (int) $row['ppc_network_id'],
            'name' => (string) $row['name'],
            'parameter' => (string) $row['parameter'],
            'placeholder' => (string) $row['placeholder'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function fetchOne(\mysqli_stmt $stmt, string $message): ?array
    {
        $this->execute($stmt, $message);
        $result = $stmt->get_result();
        if ($result === false) {
            // "No row" would answer 404 for a database failure.
            $stmt->close();
            throw new DatabaseException($message);
        }
        $row = $result->fetch_assoc();
        $stmt->close();

        return $row ?? null;
    }
}
