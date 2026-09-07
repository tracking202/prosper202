<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Controller;
use Api\V3\Exception\ConflictException;

/**
 * Registry of advertised App Store apps for SKAN reporting.
 *
 * Registering an app claims its postbacks: the public receiver stamps each
 * incoming postback with the registering user's id, and creation (or a later
 * update) retroactively claims any postbacks that arrived before the app was
 * registered (rows with user_id = 0). An App Store id can be registered by
 * exactly one user — a global UNIQUE constraint, checked here for a readable
 * error — so postback ownership is never ambiguous.
 */
class SkanAppsController extends Controller
{
    protected function tableName(): string
    {
        return '202_skan_apps';
    }

    protected function primaryKey(): string
    {
        return 'skan_app_id';
    }

    protected function fields(): array
    {
        return [
            'app_id'       => ['type' => 'i', 'required' => true],
            'app_name'     => ['type' => 's', 'required' => true, 'max_length' => 255],
            'notes'        => ['type' => 's', 'max_length' => 500],
            // The capability value an iOS build presents to GET /skan/schema
            // to fetch its conversion-value mapping at runtime. Served to the
            // owner, never client-writable; rotate with rotateSchemaToken().
            'schema_token' => ['type' => 's', 'readonly' => true],
        ];
    }

    #[\Override]
    public function create(array $payload): array
    {
        try {
            return parent::create($payload);
        } catch (\mysqli_sql_exception $e) {
            // Two concurrent registrations can both pass the beforeCreate
            // pre-check; the UNIQUE key decides, and the loser deserves the
            // same readable answer as the pre-checked case.
            if ((int)$e->getCode() === 1062) {
                throw new ConflictException('This App Store id is already registered.');
            }
            throw $e;
        }
    }

    #[\Override]
    protected function beforeCreate(array $payload): array
    {
        $this->assertAppIdUnregistered((int)($payload['app_id'] ?? 0));
        return [
            'schema_token' => ['type' => 's', 'value' => self::newSchemaToken()],
            'created_at' => ['type' => 'i', 'value' => time()],
            'updated_at' => ['type' => 'i', 'value' => time()],
        ];
    }

    /**
     * Replace the app's schema token. The old token stops working with this
     * write — the remedy for a token leaked out of a shipped binary — and the
     * response carries the replacement for the next build's configuration.
     */
    public function rotateSchemaToken(int $id): array
    {
        $this->get($id); // ownership + existence; throws NotFoundException otherwise

        $token = self::newSchemaToken();
        $sql = 'UPDATE 202_skan_apps SET schema_token = ?, updated_at = ? WHERE skan_app_id = ? AND user_id = ?';
        $stmt = $this->prepare($sql);
        $now = time();
        $this->bind($stmt, 'siii', $token, $now, $id, $this->userId);
        $this->execute($stmt, 'Token rotation failed');
        $stmt->close();

        return $this->get($id);
    }

    private static function newSchemaToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    #[\Override]
    public function deletePreview(int|string $id): array
    {
        // Previews are embedded in staged changes, which readers other than
        // the owner can see; the schema token is a capability value and
        // stays out of them. The owner reads it via GET /skan/apps/{id}.
        $preview = parent::deletePreview($id);
        if (isset($preview['data']['record']) && is_array($preview['data']['record'])) {
            unset($preview['data']['record']['schema_token']);
        }
        return $preview;
    }

    #[\Override]
    protected function afterCreate(int $insertId, array $payload): void
    {
        // Best effort: a failed claim must not fail the registration (the
        // row exists either way). Updating the app re-runs the claim, so a
        // logged failure here is recoverable without support surgery.
        try {
            $this->claimUnassignedPostbacks((int)$payload['app_id']);
        } catch (\Throwable $e) {
            error_log('p202 skan: claiming postbacks for app ' . (int)$payload['app_id'] . ' failed: ' . $e->getMessage());
        }
    }

    #[\Override]
    protected function beforeUpdate(int|string $id, array $payload): array
    {
        if (array_key_exists('app_id', $payload)) {
            $this->assertAppIdUnregistered((int)$payload['app_id'], excludeId: (int)$id);
        }
        return [
            'updated_at' => ['type' => 'i', 'value' => time()],
        ];
    }

    #[\Override]
    public function update(int|string $id, array $payload): array
    {
        try {
            $updated = parent::update($id, $payload);
        } catch (\mysqli_sql_exception $e) {
            if ((int)$e->getCode() === 1062) {
                throw new ConflictException('This App Store id is already registered.');
            }
            throw $e;
        }
        // Re-run the claim on every update so unclaimed history (or a claim
        // that failed at create time) can be picked up by touching the app.
        try {
            $this->claimUnassignedPostbacks((int)$updated['data']['app_id']);
        } catch (\Throwable $e) {
            error_log('p202 skan: claiming postbacks for app ' . (int)$updated['data']['app_id'] . ' failed: ' . $e->getMessage());
        }
        return $updated;
    }

    /**
     * The registration is unique across ALL users on purpose: the receiver
     * resolves postback ownership by app id alone, so two owners would make
     * attribution ambiguous. Only rows claimed while registered stay with
     * their user; deleting a registration never reassigns history.
     */
    private function assertAppIdUnregistered(int $appId, ?int $excludeId = null): void
    {
        $sql = 'SELECT skan_app_id FROM 202_skan_apps WHERE app_id = ? LIMIT 1';
        $stmt = $this->prepare($sql);
        $this->bind($stmt, 'i', $appId);
        $this->execute($stmt, 'App lookup failed');
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new \Api\V3\Exception\DatabaseException('App lookup failed');
        }
        $row = $result->fetch_assoc();
        $stmt->close();

        if (is_array($row) && ($excludeId === null || (int)$row['skan_app_id'] !== $excludeId)) {
            throw new ConflictException('This App Store id is already registered.');
        }
    }

    private function claimUnassignedPostbacks(int $appId): void
    {
        $sql = 'UPDATE 202_skan_postbacks SET user_id = ? WHERE app_id = ? AND user_id = 0';
        $stmt = $this->prepare($sql);
        $this->bind($stmt, 'ii', $this->userId, $appId);
        $this->execute($stmt, 'Postback claim failed');
        $stmt->close();
    }
}
