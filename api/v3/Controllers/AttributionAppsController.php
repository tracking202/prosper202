<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Controller;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\ValidationException;

/**
 * Registry of advertised App Store apps for attribution reporting.
 *
 * Registering an app claims its postbacks: the public receivers stamp each
 * incoming postback with the registering user's id, and creation (or a later
 * update) retroactively claims any postbacks that arrived before the app was
 * registered (rows with user_id = 0). An App Store id can be registered by
 * exactly one user — a global UNIQUE constraint, checked here for a readable
 * error — so postback ownership is never ambiguous.
 *
 * The registration also carries the app's trust policy for development-
 * signed postbacks (AdAttributionKit's development keys, which any phone in
 * Developer Mode can sign with, naming any App Store id). Off by default:
 * such rows store flagged `development` and count nowhere; an owner turns
 * accept_development_postbacks on for an app while integration-testing
 * their own build, and off again before trusting the numbers.
 */
class AttributionAppsController extends Controller
{
    protected function tableName(): string
    {
        return '202_attribution_apps';
    }

    protected function primaryKey(): string
    {
        return 'attribution_app_id';
    }

    protected function fields(): array
    {
        return [
            'app_id'       => ['type' => 'i', 'required' => true],
            'app_name'     => ['type' => 's', 'required' => true, 'max_length' => 255],
            'notes'        => ['type' => 's', 'max_length' => 500],
            // 1 = development-signed postbacks for this app store as trusted
            // (signature_valid = 1) and count in the report; 0 = they store
            // flagged and are pruned like any other unverified row.
            'accept_development_postbacks' => ['type' => 'i', 'allowed' => [0, 1]],
            // The capability value an iOS build presents to GET /attribution/schema
            // to fetch its conversion-value mapping at runtime. Served to the
            // owner, never client-writable; rotate with rotateSchemaToken().
            'schema_token' => ['type' => 's', 'readonly' => true],
        ];
    }

    #[\Override]
    protected function duplicateKeyConflictMessage(): ?string
    {
        // Two concurrent registrations can both pass the beforeCreate
        // pre-check; the UNIQUE key decides, and the loser deserves the same
        // readable answer as the pre-checked case. (schema_token is UNIQUE
        // too, but 32 random bytes cannot realistically collide — app_id is
        // the only key a client can actually hit.)
        return 'This App Store id is already registered.';
    }

    #[\Override]
    protected function beforeCreate(array $payload): array
    {
        self::assertUsableAppId($payload['app_id'] ?? null);
        $this->assertAppIdUnregistered((int)$payload['app_id']);
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
        $sql = 'UPDATE 202_attribution_apps SET schema_token = ?, updated_at = ? WHERE attribution_app_id = ? AND user_id = ?';
        $stmt = $this->prepare($sql);
        $now = time();
        $this->bind($stmt, 'siii', $token, $now, $id, $this->userId);
        $this->execute($stmt, 'Token rotation failed');
        $stmt->close();

        return $this->get($id);
    }

    /**
     * An App Store id the rest of the feature can actually use.
     *
     * The column is bigint UNSIGNED, so a negative value reaches strict-mode
     * MySQL as error 1264 and surfaces as a 500 for what is plainly bad
     * input. 0 is refused separately: it is the reserved "account-wide
     * default" scope in 202_attribution_conversion_values, so an app registered as
     * 0 would share one scope with the defaults and the schema endpoint
     * could not tell an app rule from a fallback.
     */
    private static function assertUsableAppId(mixed $appId): void
    {
        $value = is_numeric($appId) ? (int)$appId : -1;
        if ($value < 1) {
            throw new ValidationException('Invalid app_id', [
                'app_id' => 'Must be a positive App Store id (the number in the app\'s App Store URL)',
            ]);
        }
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
        // stays out of them. The owner reads it via GET /attribution/apps/{id}.
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
            $this->syncDevelopmentTrust((int)$payload['app_id'], (int)($payload['accept_development_postbacks'] ?? 0) === 1);
        } catch (\Throwable $e) {
            error_log('p202 attribution: claiming postbacks for app ' . (int)$payload['app_id'] . ' failed: ' . $e->getMessage());
        }
    }

    #[\Override]
    protected function beforeUpdate(int|string $id, array $payload): array
    {
        if (array_key_exists('app_id', $payload)) {
            self::assertUsableAppId($payload['app_id']);
            $this->assertAppIdUnregistered((int)$payload['app_id'], excludeId: (int)$id);
        }
        return [
            'updated_at' => ['type' => 'i', 'value' => time()],
        ];
    }

    #[\Override]
    public function update(int|string $id, array $payload): array
    {
        $updated = parent::update($id, $payload);
        // Re-run the claim on every update so unclaimed history (or a claim
        // that failed at create time) can be picked up by touching the app,
        // and re-apply the development trust policy so toggling the opt-in
        // changes what the report counts from now on AND for the rows
        // already stored — the flag is a live policy, not a receipt-time
        // snapshot.
        try {
            $this->claimUnassignedPostbacks((int)$updated['data']['app_id']);
            $this->syncDevelopmentTrust(
                (int)$updated['data']['app_id'],
                (int)($updated['data']['accept_development_postbacks'] ?? 0) === 1
            );
        } catch (\Throwable $e) {
            error_log('p202 attribution: claiming postbacks for app ' . (int)$updated['data']['app_id'] . ' failed: ' . $e->getMessage());
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
        $sql = 'SELECT attribution_app_id FROM 202_attribution_apps WHERE app_id = ? LIMIT 1';
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

        if (is_array($row) && ($excludeId === null || (int)$row['attribution_app_id'] !== $excludeId)) {
            throw new ConflictException('This App Store id is already registered.');
        }
    }

    private function claimUnassignedPostbacks(int $appId): void
    {
        $sql = 'UPDATE 202_attribution_postbacks SET user_id = ? WHERE app_id = ? AND user_id = 0';
        $stmt = $this->prepare($sql);
        $this->bind($stmt, 'ii', $this->userId, $appId);
        $this->execute($stmt, 'Postback claim failed');
        $stmt->close();
    }

    /**
     * Apply the app's accept_development_postbacks policy to the stored
     * rows: development-signed rows of this app become trusted
     * (signature_valid = 1) when the opt-in is on and untrusted (NULL,
     * the "nobody vouched for it" class) when it is off. Only the owner's
     * rows: history a previous owner claimed keeps that owner's decision.
     * Rows in any other signature state are never touched — the opt-in is
     * about development keys only.
     */
    private function syncDevelopmentTrust(int $appId, bool $accept): void
    {
        $stmt = $accept
            ? $this->prepare("UPDATE 202_attribution_postbacks SET signature_valid = 1 WHERE app_id = ? AND user_id = ? AND signature_state = 'development'")
            : $this->prepare("UPDATE 202_attribution_postbacks SET signature_valid = NULL WHERE app_id = ? AND user_id = ? AND signature_state = 'development'");
        $this->bind($stmt, 'ii', $appId, $this->userId);
        $this->execute($stmt, 'Development trust sync failed');
        $stmt->close();
    }
}
