<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Apps\Android\Integrity\IntegrityCredentialStore;
use Api\V3\Apps\Android\Integrity\IntegrityMode;
use Api\V3\Apps\Android\Integrity\UnverifiableInstalls;
use Api\V3\Apps\Android\InstallIntake;
use Api\V3\Apps\Android\OrphanedPendingClicks;
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
 * registration withdraws it. The same flag governs an Android app's test
 * installs, and is applied to the stored ones the same way: each whose
 * trust changes has its outcomes moved onto or off its click
 * (InstallIntake::rejudgeTestInstalls()).
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
            // Android: how many days after its click an install may begin
            // and still be attributed to it (1-365, default 7).
            'attribution_window_days' => ['type' => 'i'],
            // Android: 1 = an event's reported revenue may be paid by a goal
            // valued from_property; 0 (default) = stored and reported, not
            // credited, because the app token is public.
            'trust_client_revenue' => ['type' => 'i', 'allowed' => [0, 1]],
            // Android: Play Integrity off (default), observe or require
            // (IntegrityMode). Anything but off needs the service-account
            // credential first (PUT /apps/{id}/integrity-credential) and a
            // Cloud project number (assertIntegrityUsable()).
            'integrity_mode' => ['type' => 's', 'allowed' => IntegrityMode::values()],
            // Android: the Google Cloud project number the SDK requests
            // standard integrity tokens with; published in the schema
            // document so the build need not hard-code it. Replaced, never
            // cleared (an explicit null is refused).
            'integrity_cloud_project_number' => ['type' => 'i'],
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
        self::assertAndroidPolicy($payload, $identity->platform);
        if (isset($payload['integrity_mode']) && $payload['integrity_mode'] !== IntegrityMode::OFF->value) {
            throw new ValidationException('Play Integrity needs a credential first', [
                'integrity_mode' => 'can only be off when the app is registered: set the service account with '
                    . 'PUT /apps/{id}/integrity-credential, then switch the mode, with integrity_cloud_project_number, in PUT /apps/{id}',
            ]);
        }
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

    /**
     * The Android policy fields, read from the RAW payload before the base
     * controller casts them (CLAUDE.md #18): a whole number of days 1-365
     * (a JSON integer or its canonical digits — the CLI sends strings) and a
     * 0/1 flag. `1.5`, `"7.0"`, `1e2` or `0` are refused, never rounded.
     * Both belong to Android registrations only: an iOS app's attribution
     * comes from Apple's postbacks, which neither setting governs.
     *
     * @param array<string, mixed> $payload
     */
    private static function assertAndroidPolicy(array $payload, string $platform): void
    {
        $errors = [];
        if (array_key_exists('integrity_mode', $payload) && $payload['integrity_mode'] !== null) {
            $mode = $payload['integrity_mode'];
            if ($platform !== AppIdentity::ANDROID) {
                $errors['integrity_mode'] = 'applies to Android registrations only (Play Integrity)';
            } elseif (!is_string($mode) || IntegrityMode::tryFrom($mode) === null) {
                $errors['integrity_mode'] = 'must be one of: ' . implode(', ', IntegrityMode::values());
            }
        }
        if (array_key_exists('integrity_cloud_project_number', $payload) && $payload['integrity_cloud_project_number'] !== null) {
            $raw = $payload['integrity_cloud_project_number'];
            $text = is_int($raw) ? (string)$raw : (is_string($raw) ? $raw : null);
            if ($platform !== AppIdentity::ANDROID) {
                $errors['integrity_cloud_project_number'] = 'applies to Android registrations only (Play Integrity)';
            } elseif ($text === null || preg_match('/^[1-9][0-9]{0,17}$/D', $text) !== 1) {
                $errors['integrity_cloud_project_number'] = 'must be the Google Cloud project NUMBER (digits, from the project\'s dashboard), not its id';
            }
        }
        foreach (['attribution_window_days' => [1, AppPolicy::MAX_WINDOW_DAYS], 'trust_client_revenue' => [0, 1]] as $field => [$min, $max]) {
            if (!array_key_exists($field, $payload) || $payload[$field] === null) {
                continue;
            }
            if ($platform !== AppIdentity::ANDROID) {
                $errors[$field] = 'applies to Android registrations only';
                continue;
            }
            $raw = $payload[$field];
            $text = is_int($raw) ? (string)$raw : (is_string($raw) ? $raw : null);
            if ($text === null || preg_match('/^(0|[1-9][0-9]{0,2})$/D', $text) !== 1 || (int)$text < $min || (int)$text > $max) {
                $errors[$field] = 'must be a whole number from ' . $min . ' to ' . $max;
            }
        }
        if ($errors !== []) {
            throw new ValidationException('Invalid app policy', $errors);
        }
    }

    #[\Override]
    protected function afterCreate(int $insertId, array $payload): void
    {
        if ((string)$payload['platform'] === AppIdentity::ANDROID) {
            // The built-in install goal exists from the start, so a campaign
            // can attach it (with a payout) before the first install. Best
            // effort: the intake creates it on first use as well.
            try {
                (new \Prosper202\Goals\MysqlGoalRepository(new \Prosper202\Database\Connection($this->db)))
                    ->ensureBuiltinInstallGoal($this->userId, $insertId, time());
            } catch (\Throwable $e) {
                error_log('p202 apps: creating the install goal of registration ' . $insertId . ' failed: ' . $e->getMessage());
            }
        }
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

        $policyFields = ['attribution_window_days', 'trust_client_revenue', 'integrity_mode', 'integrity_cloud_project_number'];
        $namesIntegrity = array_key_exists('integrity_mode', $payload) || array_key_exists('integrity_cloud_project_number', $payload);
        if (array_intersect(array_keys($payload), $policyFields) !== [] && !$namesIntegrity) {
            self::assertAndroidPolicy($payload, (string)((array)$this->get($id)['data'])['platform']);
        }
        if ($namesIntegrity) {
            // Only a write that names Play Integrity is held to it: an
            // unrelated field is never refused over the stored setting. The
            // check and the write commit under the registration's row lock,
            // which DELETE /apps/{id}/integrity-credential takes for its own
            // check and delete: checked apart from the write, a credential
            // cleared in between leaves observe or require committed with
            // nothing to decode (AppIntegrityController::clearCredential()).
            $committed = null;
            $updated = $this->transaction(function () use ($id, $payload, &$committed): ?array {
                $current = AppIntegrityController::lockRegistration($this->db, $this->userId, (int)$id);
                self::assertAndroidPolicy($payload, (string)$current['platform']);
                $this->assertIntegrityUsable((int)$id, $payload, $current);
                try {
                    return parent::update($id, $payload);
                } catch (WriteCommittedException $e) {
                    // The write stands: let the transaction commit it, and
                    // say so after (CLAUDE.md #13).
                    $committed = $e;

                    return null;
                }
            });
            if ($committed !== null) {
                throw $committed;
            }
            if ($updated === null) {
                throw new \LogicException('the locked registration update returned nothing');
            }
        } else {
            $updated = parent::update($id, $payload);
        }
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

    /**
     * `observe` and `require` need both halves of Play Integrity: the service
     * account the worker decodes with, and the Cloud project number the
     * schema document gives the SDK to request tokens for. Without the first
     * every verdict is an error; without the second the SDK is told to
     * request a token and cannot, so every install arrives without one — and
     * under require, every attributable install is integrity_unverified.
     *
     * Read from the RAW payload (CLAUDE.md #18: the base controller skips a
     * null and casts the rest before any hook sees them) against the stored
     * row, so the rule holds for the state the write leaves, whichever of the
     * two fields it names:
     *
     *  - a mode other than off needs a stored credential and a project number,
     *    sent in this request or already stored;
     *  - the project number is replaced, never cleared: an explicit null is
     *    refused by name in every mode rather than dropped in silence
     *    (CLAUDE.md #4). While off the number is unused, so there is nothing
     *    to clear it for; under observe/require clearing it would be the
     *    broken state above.
     *
     * Create needs none of this: a registration can only be created `off`.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $current the stored registration
     */
    private function assertIntegrityUsable(int $id, array $payload, array $current): void
    {
        if (array_key_exists('integrity_cloud_project_number', $payload) && $payload['integrity_cloud_project_number'] === null) {
            throw new ValidationException('The Cloud project number cannot be cleared', [
                'integrity_cloud_project_number' => 'cannot be cleared (null): send another project number to replace it. '
                    . 'To stop Play Integrity, set integrity_mode to off; the SDK then requests no token and the number is unused.',
            ]);
        }
        $mode = array_key_exists('integrity_mode', $payload) && $payload['integrity_mode'] !== null
            ? IntegrityMode::tryFrom((string)$payload['integrity_mode'])
            : IntegrityMode::fromStored($current['integrity_mode'] ?? null);
        if ($mode === null || !$mode->decodes()) {
            return; // off, or a mode assertAndroidPolicy() already refused
        }
        $errors = [];
        if (!(new IntegrityCredentialStore(new \Prosper202\Database\Connection($this->db)))->existsLocked($this->userId, $id)) {
            // Refused rather than accepted into a state where every install
            // waits for a verdict nothing can decode (observe would record
            // only errors; require would pay nothing).
            $errors['integrity_mode'] = 'needs the app\'s Google service account: PUT /apps/' . $id . '/integrity-credential first';
        }
        $project = array_key_exists('integrity_cloud_project_number', $payload)
            ? $payload['integrity_cloud_project_number']
            : ($current['integrity_cloud_project_number'] ?? null);
        if ($project === null || $project === '') {
            $errors['integrity_cloud_project_number'] = 'is required for integrity_mode ' . $mode->value . ': the SDK requests tokens for this '
                . 'Google Cloud project, so without its Cloud project number (digits, on the project\'s dashboard) no install can carry one. '
                . 'Send it with the mode: {"integrity_mode": "' . $mode->value . '", "integrity_cloud_project_number": "123456789012"}';
        }
        if ($errors !== []) {
            throw new ValidationException(
                isset($errors['integrity_mode']) ? 'Play Integrity needs a credential first' : 'Play Integrity needs the Cloud project number',
                $errors
            );
        }
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
        $this->transaction(function () use ($id): void {
            parent::delete($id);
        });
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

        // The installs still waiting for a Play Integrity verdict can never
        // get one once the credential below is gone, and the worker reads an
        // install only through its registration: settled here, in the same
        // transaction, to `error` / integrity_unverified — terminal, never
        // paid — rather than left queued forever (UnverifiableInstalls).
        (new UnverifiableInstalls(new \Prosper202\Database\Connection($this->db)))
            ->settleForDeletedRegistration($this->userId, $registrationId, time());

        // Installs still waiting for their click can never settle once the
        // registration is gone: the settler reads an install only through
        // its registration, retention never prunes a pending install, and no
        // app token reaches it. Settled here, in the same transaction, to the
        // state the pending-click deadline leaves them in — bad_token, never
        // paid (OrphanedPendingClicks).
        (new OrphanedPendingClicks(new \Prosper202\Database\Connection($this->db)))
            ->settleForDeletedRegistration($this->userId, $registrationId, time());

        // The Play Integrity credential is the operator's secret for this
        // app; nothing may sign with it once the registration is gone. It is
        // deleted under the registration's row lock, the lock a Play
        // Integrity mode write and the credential routes take
        // (AppIntegrityController::lockRegistration()), so none of them can
        // land between this DELETE and the commit and leave a key stored for
        // an app that no longer exists. Taken after the install writes
        // above, in the order the settlers take theirs (the install, then
        // its registration), so the two never wait on each other in a cycle.
        AppIntegrityController::lockRegistration($this->db, $this->userId, $registrationId);
        $stmt = $this->prepare('DELETE FROM 202_app_integrity_credentials WHERE registration_id = ? AND user_id = ?');
        $this->bind($stmt, 'ii', $registrationId, $this->userId);
        $this->execute($stmt, 'Integrity credential delete failed');
        $stmt->close();

        // Encodings exist only for their registration; left behind they
        // would decode nothing and hold UNIQUE slots nobody can see. What
        // they meant goes to the history first, as every removed encoding's
        // does (SkanEncodingHistory).
        (new \Api\V3\Apps\Apple\SkanEncodingHistory($this->db))->retireRegistration($this->userId, $registrationId, time());
        $stmt = $this->prepare('DELETE FROM 202_app_skan_encodings WHERE registration_id = ? AND user_id = ?');
        $this->bind($stmt, 'ii', $registrationId, $this->userId);
        $this->execute($stmt, 'Encoding delete failed');
        $stmt->close();

        // Campaigns linked to the app are unlinked. Left naming a
        // registration that no longer exists, a campaign would read as
        // linked to *another* app once the same app is registered again (a
        // new id): its clicks' install tokens refused, their installs
        // foreign_click. Unlinked, the campaign is what it was before it was
        // linked, and the operator links it to the new registration.
        $stmt = $this->prepare('UPDATE 202_aff_campaigns SET app_registration_id = NULL WHERE app_registration_id = ? AND user_id = ?');
        $this->bind($stmt, 'ii', $registrationId, $this->userId);
        $this->execute($stmt, 'Campaign unlink failed');
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
            ['resource' => 'app-integrity-credential', 'action' => 'delete (the Play Integrity service account)', 'where' => 'registration_id = ' . (int)$id],
            ['resource' => 'app-postbacks', 'action' => 'unlink (registration_id set to NULL; owner kept; test-signal trust withdrawn)', 'where' => 'registration_id = ' . (int)$id],
            ['resource' => 'campaigns', 'action' => 'unlink (app_registration_id set to NULL)', 'where' => 'app_registration_id = ' . (int)$id],
            ['resource' => 'goals', 'action' => 'archive (versions, outcomes and conversions kept)', 'where' => 'scope = registration, scope_id = ' . (int)$id],
            ['resource' => 'app-installs', 'action' => 'kept (history; their conversions stay on the ledger), no longer reachable by any app token', 'where' => 'registration_id = ' . (int)$id],
            ['resource' => 'app-installs', 'action' => 'settle the Play Integrity queue: integrity_state pending → error, match_state pending_integrity → integrity_unverified (never paid)', 'where' => 'registration_id = ' . (int)$id . ', still waiting for a verdict'],
            ['resource' => 'app-installs', 'action' => 'settle the pending clicks: match_state pending_click → bad_token (never paid)', 'where' => 'registration_id = ' . (int)$id . ', still waiting for their click'],
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
        if ($platform === AppIdentity::ANDROID) {
            // Android installs are claimed at receipt (the token names the
            // registration), so there is no history to claim; the policy is
            // re-applied to the test installs already stored, and each one
            // whose trust changes moves its outcomes onto or off its click.
            (new InstallIntake($this->db))->rejudgeTestInstalls($this->userId, $registrationId);
            return;
        }
        if ($platform !== AppIdentity::IOS) {
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
