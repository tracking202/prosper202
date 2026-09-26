<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Apps\AppIdentity;
use Api\V3\Apps\AppPolicy;
use Api\V3\Apps\AppToken;
use Api\V3\Apps\Apple\SignatureState;
use Api\V3\Controller;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\ValidationException;
use Api\V3\Exception\WriteCommittedException;

/**
 * The app registry: one registration per app, for both platforms (plan
 * §4.2). An app is (platform, app_key) — the App Store id for iOS, the
 * package name for Android — and AppIdentity is the only thing that decides
 * whether a value names one. A registration is created from `app_key` (with
 * `platform`, or inferred from the key's shape) or from a `store_link`.
 *
 * (platform, app_key) is UNIQUE across ALL users on purpose: signals
 * resolve their owner by the app they name, so two owners would make
 * attribution ambiguous. Both are fixed once registered — `registration_id`
 * is the key every other table links through, and letting it silently start
 * naming a different app would re-point everything that links to it.
 *
 * Registering an iOS app claims its postbacks: rows that arrived before the
 * registration (user_id = 0), and the owner's own rows a deleted
 * registration of the same app left unlinked. The registration's policy —
 * `accept_test_signals`, whether development-signed postbacks count as
 * trusted — is applied to the rows already stored on every write, because it
 * is a live policy rather than a receipt-time snapshot; deleting the
 * registration withdraws it.
 */
class AppRegistrationsController extends Controller
{
    protected function tableName(): string
    {
        return '202_app_registrations';
    }

    protected function primaryKey(): string
    {
        return 'registration_id';
    }

    protected function fields(): array
    {
        return [
            // Set only by create(), from AppIdentity; see update() for why
            // they cannot change afterwards.
            'platform'     => ['type' => 's', 'required' => true, 'max_length' => 16],
            'app_key'      => ['type' => 's', 'required' => true, 'max_length' => AppIdentity::MAX_KEY_LENGTH],
            'app_name'     => ['type' => 's', 'required' => true, 'max_length' => 255],
            'notes'        => ['type' => 's', 'max_length' => 500],
            // 1 = test signals (AdAttributionKit development-key postbacks;
            // Android test installs) count as trusted; 0 = they store flagged
            // and are pruned like any other unvouched row.
            'accept_test_signals' => ['type' => 'i', 'allowed' => [0, 1]],
            // What an app build presents in X-P202-App-Token to the pre-auth
            // routes. Served to the owner, never client-writable; rotate with
            // rotateAppToken().
            'app_token'    => ['type' => 's', 'readonly' => true],
        ];
    }

    #[\Override]
    protected function duplicateKeyConflictMessage(): ?string
    {
        // Two concurrent registrations can both pass the beforeCreate
        // pre-check; the UNIQUE key decides, and the loser deserves the same
        // readable answer. (app_token is UNIQUE too, but 256 random bits
        // cannot realistically collide.)
        return 'This app is already registered.';
    }

    #[\Override]
    public function create(array $payload): array
    {
        // The RAW payload, before Controller::create() hands it to
        // validatePayload(): nothing may cast the key before AppIdentity has
        // read it (CLAUDE.md #18). store_link is not a column; it is read
        // for the app it names and replaced by that app's platform and key.
        $identity = AppIdentity::fromPayload($payload);
        unset($payload['store_link']);
        $payload['platform'] = $identity->platform;
        $payload['app_key'] = $identity->appKey;
        return parent::create($payload);
    }

    #[\Override]
    protected function beforeCreate(array $payload): array
    {
        $this->assertUnregistered((string)$payload['platform'], (string)$payload['app_key']);
        $now = time();
        return [
            'app_token' => ['type' => 's', 'value' => AppToken::mint()],
            'created_at' => ['type' => 'i', 'value' => $now],
            'updated_at' => ['type' => 'i', 'value' => $now],
        ];
    }

    #[\Override]
    protected function afterCreate(int $insertId, array $payload): void
    {
        // Best effort: a failed claim must not fail the registration (the
        // row exists either way). Updating the registration re-runs the
        // claim, so a logged failure here is recoverable without surgery.
        try {
            $this->applyRegistrationPolicy(
                $insertId,
                (string)$payload['platform'],
                (string)$payload['app_key'],
                AppPolicy::fromRow($payload + ['accept_test_signals' => 0]),
                claimHistory: true
            );
        } catch (\Throwable $e) {
            error_log('p202 apps: claiming signals for registration ' . $insertId . ' failed: ' . $e->getMessage());
        }
    }

    /**
     * Replace the app token. The old token stops working with this write —
     * the remedy for a token lifted out of a shipped binary — and the
     * response carries the replacement for the next build's configuration.
     */
    public function rotateAppToken(int $id): array
    {
        $this->get($id); // ownership + existence; throws NotFoundException otherwise

        $token = AppToken::mint();
        $stmt = $this->prepare('UPDATE 202_app_registrations SET app_token = ?, updated_at = ? WHERE registration_id = ? AND user_id = ?');
        $now = time();
        $this->bind($stmt, 'siii', $token, $now, $id, $this->userId);
        $this->execute($stmt, 'Token rotation failed');
        $stmt->close();

        // The old token is dead from here: only reading the new one back can
        // still fail, and this route is stageable. Reported as a plain
        // failure, StagedChangesController::apply() would return the change
        // to `staged` on the premise that nothing was written, and the
        // re-apply would mint a THIRD token (CLAUDE.md #13).
        try {
            return $this->get($id);
        } catch (\Throwable $e) {
            throw new WriteCommittedException('app registration token', $e);
        }
    }

    #[\Override]
    public function update(int|string $id, array $payload): array
    {
        // The app a registration names is fixed. Sending the same app again
        // (an idempotent PUT of the whole record) is accepted; naming a
        // different one is refused by name rather than ignored, because a
        // caller who sent it believes it changed (CLAUDE.md #4).
        $identityFields = array_filter(
            array_intersect_key($payload, array_flip(['platform', 'app_key', 'store_link'])),
            static fn (mixed $value): bool => $value !== null
        );
        if ($identityFields !== []) {
            $current = (array)$this->get($id)['data'];
            $currentPlatform = (string)$current['platform'];
            $currentKey = (string)$current['app_key'];
            if (!isset($identityFields['app_key']) && !isset($identityFields['store_link'])) {
                // The platform alone: it must be the one registered.
                $field = 'platform';
                $same = AppIdentity::normalizePlatform($identityFields['platform']) === $currentPlatform;
            } else {
                $field = isset($identityFields['store_link']) ? 'store_link' : 'app_key';
                if (isset($identityFields['app_key']) && !isset($identityFields['platform'])) {
                    // A bare key is read on the registration's own platform.
                    $identityFields['platform'] = $currentPlatform;
                }
                $sent = AppIdentity::fromPayload($identityFields);
                $same = $sent->platform === $currentPlatform && $sent->appKey === $currentKey;
            }
            if (!$same) {
                throw new ValidationException('A registration\'s app cannot change', [
                    $field => 'This registration is for ' . $currentPlatform . ' app ' . $currentKey
                        . '. Register the other app separately, and delete this registration if it is no longer wanted.',
                ]);
            }
            unset($payload['platform'], $payload['app_key'], $payload['store_link']);
        }

        $updated = parent::update($id, $payload);
        // Re-run the claim on every update so unclaimed history (or a claim
        // that failed at create time) is picked up by touching the
        // registration, and re-apply the test-signal policy so toggling it
        // changes what the report counts from now on AND for the rows already
        // stored.
        $row = (array)$updated['data'];
        try {
            $this->applyRegistrationPolicy(
                (int)$row['registration_id'],
                (string)$row['platform'],
                (string)$row['app_key'],
                AppPolicy::fromRow($row),
                claimHistory: true
            );
        } catch (\Throwable $e) {
            error_log('p202 apps: claiming signals for registration ' . (int)$row['registration_id'] . ' failed: ' . $e->getMessage());
        }
        return $updated;
    }

    #[\Override]
    protected function beforeUpdate(int|string $id, array $payload): array
    {
        return [
            'updated_at' => ['type' => 'i', 'value' => time()],
        ];
    }

    #[\Override]
    public function delete(int|string $id): void
    {
        // The policy withdrawal, the unlinking and the row delete commit
        // together: a registration gone with its trusted development rows
        // still trusted would leave nothing to withdraw them from.
        //
        // The change record is written after the commit, not inside it. A
        // recordChange() failure inside would roll the cascade back and still
        // surface as WriteCommittedException ("landed, never retry"), so a
        // staged apply would be marked interrupted for a delete that never
        // happened (CLAUDE.md #13).
        $deleted = $this->transaction(fn (): array => $this->deleteRecord($id));
        $this->recordDeleted($deleted);
    }

    #[\Override]
    protected function beforeDelete(int|string $id): void
    {
        $row = (array)$this->get($id)['data']; // ownership + existence
        $registrationId = (int)$row['registration_id'];

        // Withdraw the test-signal policy from the rows it marked trusted —
        // with nothing left to vouch for them they go back to unvouched —
        // then unlink them. They keep their owner (they were claimed while
        // registered); registering the app again re-links them.
        $this->syncTestSignalTrust($registrationId, AppPolicy::untrusting());
        $stmt = $this->prepare('UPDATE 202_app_postbacks SET registration_id = NULL WHERE registration_id = ? AND user_id = ?');
        $this->bind($stmt, 'ii', $registrationId, $this->userId);
        $this->execute($stmt, 'Postback unlink failed');
        $stmt->close();

        // Encodings exist only for their registration; left behind they
        // would decode nothing and hold UNIQUE slots nobody can see.
        $stmt = $this->prepare('DELETE FROM 202_app_skan_encodings WHERE registration_id = ? AND user_id = ?');
        $this->bind($stmt, 'ii', $registrationId, $this->userId);
        $this->execute($stmt, 'Encoding delete failed');
        $stmt->close();

        // The app's goals are archived, not deleted: their versions and the
        // outcomes and conversions they produced are history. Archived, they
        // evaluate nothing received from now on, and a registration id is
        // never reused, so registering the app again starts a new set.
        $now = time();
        $stmt = $this->prepare(
            "UPDATE 202_goals SET archived_at = ?, updated_at = ? WHERE scope = 'registration' AND scope_id = ? AND user_id = ? AND archived_at IS NULL"
        );
        $this->bind($stmt, 'iiii', $now, $now, $registrationId, $this->userId);
        $this->execute($stmt, 'Goal archive failed');
        $stmt->close();
    }

    #[\Override]
    public function deletePreview(int|string $id): array
    {
        // Previews are embedded in staged changes, which readers other than
        // the owner can see; the token stays out of them even though it is an
        // identifier rather than a secret. The owner reads it via
        // GET /apps/{id}.
        $preview = parent::deletePreview($id);
        if (isset($preview['data']['record']) && is_array($preview['data']['record'])) {
            unset($preview['data']['record']['app_token']);
        }
        $preview['data']['cascade'] = [
            ['resource' => 'app-skan-encodings', 'action' => 'delete', 'where' => 'registration_id = ' . (int)$id],
            ['resource' => 'app-postbacks', 'action' => 'unlink (registration_id set to NULL; owner kept; test-signal trust withdrawn)', 'where' => 'registration_id = ' . (int)$id],
            ['resource' => 'goals', 'action' => 'archive (versions, outcomes and conversions kept)', 'where' => 'scope = registration, scope_id = ' . (int)$id],
        ];
        return $preview;
    }

    /**
     * The one place the registration's policy is applied to signals already
     * stored. Create, update and delete all pass through here or through
     * syncTestSignalTrust().
     */
    private function applyRegistrationPolicy(
        int $registrationId,
        string $platform,
        string $appKey,
        AppPolicy $policy,
        bool $claimHistory
    ): void {
        if ($platform !== AppIdentity::IOS) {
            // Only the Apple source stores signals today; the Android intake
            // adds its own claim here.
            return;
        }
        if ($claimHistory) {
            $this->claimPostbacks($registrationId, AppIdentity::fromKey($platform, $appKey)->appleAppId());
        }
        $this->syncTestSignalTrust($registrationId, $policy);
    }

    /**
     * Link this registration's postbacks: the ones nobody has claimed, and
     * the owner's own ones a deleted registration of the same app left
     * unlinked. Another user's rows are never taken.
     */
    private function claimPostbacks(int $registrationId, int $appStoreId): void
    {
        $stmt = $this->prepare(
            'UPDATE 202_app_postbacks SET user_id = ?, registration_id = ? '
            . 'WHERE app_id = ? AND registration_id IS NULL AND (user_id = 0 OR user_id = ?)'
        );
        $this->bind($stmt, 'iiii', $this->userId, $registrationId, $appStoreId, $this->userId);
        $this->execute($stmt, 'Postback claim failed');
        $stmt->close();
    }

    /**
     * Apply the policy to the registration's test signals: development-signed
     * postbacks become trusted when it accepts them and unvouched (NULL) when
     * it does not. Rows in any other signature state are never touched — the
     * policy is about test signals only — and the value written is the
     * Verdict's own answer, so the stored bit and the receiver's cannot
     * differ.
     */
    private function syncTestSignalTrust(int $registrationId, AppPolicy $policy): void
    {
        $state = SignatureState::DEVELOPMENT;
        $bit = $state->trustBit($policy);
        $stmt = $bit === null
            ? $this->prepare('UPDATE 202_app_postbacks SET trusted = NULL WHERE registration_id = ? AND user_id = ? AND signature_state = ?')
            : $this->prepare('UPDATE 202_app_postbacks SET trusted = ' . (int)$bit . ' WHERE registration_id = ? AND user_id = ? AND signature_state = ?');
        $state = $state->value;
        $this->bind($stmt, 'iis', $registrationId, $this->userId, $state);
        $this->execute($stmt, 'Test-signal trust sync failed');
        $stmt->close();
    }

    /**
     * Refused before the INSERT with a readable 409. The UNIQUE key still
     * decides a race (duplicateKeyConflictMessage()).
     */
    private function assertUnregistered(string $platform, string $appKey): void
    {
        $stmt = $this->prepare('SELECT registration_id FROM 202_app_registrations WHERE platform = ? AND app_key = ? LIMIT 1');
        $this->bind($stmt, 'ss', $platform, $appKey);
        $this->execute($stmt, 'Registration lookup failed');
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new DatabaseException('Registration lookup failed');
        }
        $row = $result->fetch_assoc();
        $stmt->close();

        if (is_array($row)) {
            throw new ConflictException('This app is already registered.');
        }
    }
}
