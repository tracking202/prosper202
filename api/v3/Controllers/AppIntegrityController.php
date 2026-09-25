<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Apps\AppIdentity;
use Api\V3\Apps\Android\Integrity\IntegrityCredentialStore;
use Api\V3\Apps\Android\Integrity\IntegrityMode;
use Api\V3\Apps\Android\Integrity\IntegrityPolicy;
use Api\V3\Apps\Android\Integrity\IntegrityState;
use Api\V3\Apps\Android\Integrity\IntegrityVerifier;
use Api\V3\Apps\Android\Integrity\ServiceAccountCredential;
use Api\V3\Apps\Android\MatchState;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Api\V3\Exception\WriteCommittedException;
use Api\V3\HttpException;
use Api\V3\Support\MysqliStatements;
use Prosper202\Database\Connection;

/**
 * Play Integrity for one Android registration (plan §5.6, §5.11):
 *
 *  - GET    /apps/{id}/integrity             the mode, the credential (never
 *    its key), where the registration's installs stand, the decodes Google
 *    answered today against its default quota, and the policy's constants;
 *  - PUT    /apps/{id}/integrity-credential  set or rotate the service
 *    account, `{"credential": <the key file's JSON object>}`;
 *  - DELETE /apps/{id}/integrity-credential  clear it — refused while the
 *    mode is not `off`, so a registration can never be left requiring a
 *    verdict nothing can decode.
 *
 * The mode itself is a registration field (`integrity_mode`, PUT /apps/{id}).
 *
 * The credential routes are not stageable: a staged change is stored and
 * shown to reviewers, and the body here is a private key (the staging guard
 * refuses a `credential` key by name as well). The key is written encrypted
 * (IntegrityCredentialStore) and no response, log line or error message
 * carries it.
 */
final class AppIntegrityController
{
    use MysqliStatements;

    public const DAILY_QUOTA = 10000;

    private IntegrityCredentialStore $credentials;

    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
        $this->credentials = new IntegrityCredentialStore(new Connection($db));
    }

    public function status(int $registrationId): array
    {
        $registration = $this->androidRegistration($registrationId);

        $byState = array_fill_keys(IntegrityState::values(), 0);
        $stmt = $this->prepare('SELECT integrity_state, COUNT(*) AS n FROM 202_app_installs WHERE user_id = ? AND registration_id = ? GROUP BY integrity_state');
        $this->bind($stmt, 'ii', $this->userId, $registrationId);
        $this->execute($stmt, 'Integrity summary failed');
        $result = $this->result($stmt);
        while ($row = $result->fetch_assoc()) {
            $byState[(string) $row['integrity_state']] = (int) $row['n'];
        }
        $stmt->close();

        $held = [MatchState::PENDING_INTEGRITY->value => 0, MatchState::INTEGRITY_FAILED->value => 0, MatchState::INTEGRITY_UNVERIFIED->value => 0];
        $stmt = $this->prepare(
            "SELECT match_state, COUNT(*) AS n FROM 202_app_installs WHERE user_id = ? AND registration_id = ?
               AND match_state IN ('pending_integrity', 'integrity_failed', 'integrity_unverified') GROUP BY match_state"
        );
        $this->bind($stmt, 'ii', $this->userId, $registrationId);
        $this->execute($stmt, 'Integrity summary failed');
        $result = $this->result($stmt);
        while ($row = $result->fetch_assoc()) {
            $held[(string) $row['match_state']] = (int) $row['n'];
        }
        $stmt->close();

        // Google's default quota is per app per day (Pacific time); counted
        // here from UTC midnight, which is what this server can say without
        // a time-zone table, and labelled so.
        $dayStart = intdiv(time(), 86400) * 86400;
        $stmt = $this->prepare('SELECT COUNT(*) AS n FROM 202_app_installs WHERE user_id = ? AND registration_id = ? AND integrity_checked_at >= ?');
        $this->bind($stmt, 'iii', $this->userId, $registrationId, $dayStart);
        $this->execute($stmt, 'Integrity usage failed');
        $used = $this->result($stmt)->fetch_assoc();
        $stmt->close();
        if (!is_array($used) || !isset($used['n'])) {
            throw new HttpException('Integrity usage returned no row', 500);
        }

        return ['data' => [
            'registration_id' => $registrationId,
            'app_key' => $registration['app_key'],
            'integrity_mode' => IntegrityMode::fromStored($registration['integrity_mode'])->value,
            'integrity_cloud_project_number' => $registration['integrity_cloud_project_number'] === null ? null : (string) $registration['integrity_cloud_project_number'],
            'credential' => $this->credentials->summary($this->userId, $registrationId),
            'installs' => [
                'by_integrity_state' => $byState,
                'by_match_state' => $held,
            ],
            'usage' => [
                'decoded_since_utc_midnight' => (int) $used['n'],
                'default_daily_quota' => self::DAILY_QUOTA,
            ],
            'policy' => [
                'request_hash' => 'sha256 hex of the canonical install body (integrity_token excluded)',
                'max_token_age_seconds' => IntegrityPolicy::MAX_TOKEN_AGE,
                'clock_skew_seconds' => IntegrityPolicy::CLOCK_SKEW,
                'app_recognition' => 'PLAY_RECOGNIZED',
                'device_integrity' => IntegrityPolicy::DEVICE_LABELS,
                'licensing' => 'not UNLICENSED',
                'retry_deadline_seconds' => IntegrityVerifier::DEADLINE,
            ],
        ]];
    }

    /** @param array<string, mixed> $payload */
    public function setCredential(int $registrationId, array $payload): array
    {
        $this->androidRegistration($registrationId);
        $unknown = array_diff(array_map('strval', array_keys($payload)), ['credential']);
        if ($unknown !== [] || !array_key_exists('credential', $payload)) {
            throw new ValidationException('Send the service account as {"credential": <key file JSON>}', array_fill_keys(
                $unknown !== [] ? array_values($unknown) : ['credential'],
                $unknown !== [] ? 'is not a field here (send only "credential")' : 'is required: the service-account key file\'s JSON object'
            ));
        }
        $credential = ServiceAccountCredential::fromKeyFile($payload['credential']);
        $this->credentials->set($this->userId, $registrationId, $credential, time());

        // Stored: a failure reading it back must not read as "not set", or a
        // retry would look like the first write (CLAUDE.md #13).
        try {
            return $this->status($registrationId);
        } catch (\Throwable $e) {
            throw new WriteCommittedException('Play Integrity credential', $e);
        }
    }

    public function clearCredential(int $registrationId): array
    {
        $registration = $this->androidRegistration($registrationId);
        $mode = IntegrityMode::fromStored($registration['integrity_mode']);
        if ($mode !== IntegrityMode::OFF) {
            throw new ConflictException('Play Integrity is ' . $mode->value . ' for this app; set integrity_mode to off (PUT /apps/'
                . $registrationId . ') before clearing its credential, or PUT a new credential to rotate it.');
        }
        $removed = $this->credentials->clear($this->userId, $registrationId);

        return ['data' => [
            'registration_id' => $registrationId,
            'credential' => null,
            'cleared' => $removed,
            'message' => $removed ? 'The Play Integrity credential was deleted.' : 'This app had no Play Integrity credential.',
        ]];
    }

    /** @return array{app_key: string, integrity_mode: mixed, integrity_cloud_project_number: mixed} */
    private function androidRegistration(int $registrationId): array
    {
        $stmt = $this->prepare('SELECT platform, app_key, integrity_mode, integrity_cloud_project_number FROM 202_app_registrations WHERE registration_id = ? AND user_id = ? LIMIT 1');
        $this->bind($stmt, 'ii', $registrationId, $this->userId);
        $this->execute($stmt, 'Registration lookup failed');
        $row = $this->result($stmt)->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) {
            throw new NotFoundException('App registration ' . $registrationId . ' not found');
        }
        if ((string) $row['platform'] !== AppIdentity::ANDROID) {
            throw new ValidationException('Play Integrity is Android\'s', [
                'id' => 'registration ' . $registrationId . ' is an iOS app; Play Integrity verifies Android installs',
            ]);
        }

        return ['app_key' => (string) $row['app_key'], 'integrity_mode' => $row['integrity_mode'], 'integrity_cloud_project_number' => $row['integrity_cloud_project_number']];
    }
}
