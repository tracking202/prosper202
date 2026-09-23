<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Attribution\SignatureState;
use Api\V3\Controller;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\ValidationException;
use Api\V3\Exception\WriteCommittedException;

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
 * their own build, and off again before trusting the numbers. Deleting the
 * registration reverts it too — with nothing left to vouch for those rows,
 * they go back to untrusted rather than staying counted forever.
 */
class AttributionAppsController extends Controller
{
    public const PLATFORM_IOS = 'ios';

    /**
     * Recognised platforms: true when this install can receive their
     * postbacks, otherwise the sentence explaining why not.
     */
    private const PLATFORMS = [
        self::PLATFORM_IOS => true,
        'android' => 'Android apps are not supported yet: this install receives Apple\'s SKAdNetwork '
            . 'and AdAttributionKit postbacks, and there is no Android receiver to point an app at.',
    ];

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
            // The store the app is distributed through. Only Apple's
            // frameworks have a receiver here, so 'ios' is the only accepted
            // value and the only default; the column exists so an Android
            // receiver can be added without a migration, and so the UI can
            // show the platform it derived from a store link.
            'platform'     => ['type' => 's', 'max_length' => 16, 'default' => self::PLATFORM_IOS],
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
    public function create(array $payload): array
    {
        // The RAW value, before Controller::create() hands the payload to
        // validatePayload() and the 'i' field definition casts it: by the
        // time beforeCreate() runs, 1.5 is 1, '525463029.9' is 525463029 and
        // a 20-digit string is PHP_INT_MAX. A guard placed there registers a
        // different app from the one the caller named and answers 201 (error
        // patterns #4 and #12). Absent app_id is left to validatePayload's
        // required check so the caller still gets every missing field at once.
        if (array_key_exists('app_id', $payload)) {
            self::assertUsableAppId($payload['app_id']);
        }
        self::assertSupportedPlatform($payload);
        if (array_key_exists('platform', $payload) && $payload['platform'] !== null) {
            $payload['platform'] = self::normalizePlatform($payload['platform']);
        }
        return parent::create($payload);
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

        // The old token is dead from here: only reading the new one back can
        // still fail, and this route is stageable. Reported as a plain
        // failure, StagedChangesController::apply() would return the change
        // to `staged` on the premise that nothing was written, and the
        // re-apply would mint a THIRD token — leaving the approver holding
        // one that was never in effect (error pattern #13).
        try {
            return $this->get($id);
        } catch (\Throwable $e) {
            throw new WriteCommittedException('attribution app schema token', $e);
        }
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
     *
     * Called on the RAW payload value by create()/update() — see the note
     * there — and again on the cast one from the beforeCreate/beforeUpdate
     * hooks, which is where the 0-and-negative check still earns its keep.
     */
    private static function assertUsableAppId(mixed $appId): void
    {
        if (!self::isUsableAppId($appId)) {
            throw new ValidationException('Invalid app_id', [
                'app_id' => 'Must be a positive App Store id (the number in the app\'s App Store URL)',
            ]);
        }
    }

    /**
     * Refuse a platform this install cannot receive postbacks for, and say
     * why rather than listing the one value that works.
     *
     * Reads the RAW payload for the reason assertUsableAppId() does: by the
     * time a hook sees the value, validatePayload() has already cast it, and
     * a field-level 'allowed' list would answer "must be one of: ios" — true,
     * but it does not tell an Android user that the answer is "not yet"
     * rather than "you typed it wrong". The sentence comes from the platform's
     * own entry in PLATFORMS, so adding a store here means writing the
     * sentence for it; a generic branch would have answered every future
     * unsupported store with the one about Android.
     *
     * @param array<string, mixed> $payload
     */
    public static function assertSupportedPlatform(array $payload): void
    {
        if (!array_key_exists('platform', $payload) || $payload['platform'] === null) {
            return;
        }
        $value = self::normalizePlatform($payload['platform']);
        $known = self::PLATFORMS[$value] ?? null;
        if ($known === true) {
            return;
        }
        throw new ValidationException('Validation failed', [
            'platform' => is_string($known)
                ? $known
                : 'Must be one of: ' . implode(', ', self::supportedPlatforms()),
        ]);
    }

    /**
     * The spelling a platform is stored as.
     *
     * assertSupportedPlatform() compares case-insensitively, so 'iOS' passes;
     * without this the row would then hold 'iOS' while every query that
     * filters the column is written against 'ios'. Normalising at the two
     * write paths keeps the stored value canonical, which is the only thing
     * a later WHERE can rely on.
     */
    private static function normalizePlatform(mixed $platform): string
    {
        return is_scalar($platform) ? strtolower(trim((string)$platform)) : '';
    }

    /** @return list<string> */
    private static function supportedPlatforms(): array
    {
        return array_keys(array_filter(self::PLATFORMS, static fn ($supported) => $supported === true));
    }

    /**
     * An App Store id is a whole number, so only two spellings are accepted:
     * a PHP int (a JSON body's number) and a string of digits (the Go CLI
     * sends it that way, as does a form-encoded body). Everything else —
     * 1.5, '1e2', '525463029.9', ' 1', true, an array — is a value the int
     * cast would silently rewrite into some *other* app's id, which then
     * takes the global UNIQUE slot. A digit string PHP cannot represent
     * saturates to PHP_INT_MAX under the same cast, so the round-trip below
     * refuses it too rather than registering app 9223372036854775807.
     */
    private static function isUsableAppId(mixed $appId): bool
    {
        if (is_int($appId)) {
            return $appId >= 1;
        }
        if (!is_string($appId) || preg_match('/^\d+$/D', $appId) !== 1) {
            return false;
        }
        $digits = ltrim($appId, '0');
        return $digits !== '' && $digits === (string)(int)$digits;
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
            $this->applyRegistrationPolicy(
                (int)$payload['app_id'],
                accept: (int)($payload['accept_development_postbacks'] ?? 0) === 1,
                claimHistory: true
            );
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
        // Raw, for the same reason create() checks it raw: beforeUpdate()
        // only ever sees the already-cast int.
        if (array_key_exists('app_id', $payload)) {
            self::assertUsableAppId($payload['app_id']);
        }
        self::assertSupportedPlatform($payload);
        if (array_key_exists('platform', $payload) && $payload['platform'] !== null) {
            $payload['platform'] = self::normalizePlatform($payload['platform']);
        }
        $updated = parent::update($id, $payload);
        // Re-run the claim on every update so unclaimed history (or a claim
        // that failed at create time) can be picked up by touching the app,
        // and re-apply the development trust policy so toggling the opt-in
        // changes what the report counts from now on AND for the rows
        // already stored — the flag is a live policy, not a receipt-time
        // snapshot.
        try {
            $this->applyRegistrationPolicy(
                (int)$updated['data']['app_id'],
                accept: (int)($updated['data']['accept_development_postbacks'] ?? 0) === 1,
                claimHistory: true
            );
        } catch (\Throwable $e) {
            error_log('p202 attribution: claiming postbacks for app ' . (int)$updated['data']['app_id'] . ' failed: ' . $e->getMessage());
        }
        return $updated;
    }

    #[\Override]
    protected function beforeDelete(int|string $id): void
    {
        // Deleting the registration is the third way the development-trust
        // opt-in is toggled, and the only one that leaves nothing behind to
        // toggle it back: without this the rows the opt-in marked trusted
        // keep signature_valid = 1 forever and the default verified-only
        // report goes on counting them. Runs BEFORE the row goes away, so a
        // failure aborts the delete rather than stranding trusted rows with
        // no registration — the fail-closed direction for a trust value.
        $row = $this->get($id); // ownership + existence; throws NotFoundException otherwise
        $appId = (int)($row['data']['app_id'] ?? 0);
        if ($appId > 0) {
            $this->applyRegistrationPolicy($appId, accept: false, claimHistory: false);
        }
    }

    /**
     * The one place the registration's policy is applied to postbacks
     * already stored. create(), update() and delete() all pass through here:
     * the claim-then-sync pair was duplicated between afterCreate() and
     * update(), and the delete path was written without either.
     *
     * $claimHistory is false for delete — a registration going away must not
     * hand the departing owner rows nobody had claimed — but the trust sync
     * runs on every path, which is the point.
     */
    private function applyRegistrationPolicy(int $appId, bool $accept, bool $claimHistory): void
    {
        if ($claimHistory) {
            $this->claimUnassignedPostbacks($appId);
        }
        $this->syncDevelopmentTrust($appId, $accept);
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
        $development = SignatureState::DEVELOPMENT->value;
        $stmt = $accept
            ? $this->prepare("UPDATE 202_attribution_postbacks SET signature_valid = 1 WHERE app_id = ? AND user_id = ? AND signature_state = '$development'")
            : $this->prepare("UPDATE 202_attribution_postbacks SET signature_valid = NULL WHERE app_id = ? AND user_id = ? AND signature_state = '$development'");
        $this->bind($stmt, 'ii', $appId, $this->userId);
        $this->execute($stmt, 'Development trust sync failed');
        $stmt->close();
    }
}
