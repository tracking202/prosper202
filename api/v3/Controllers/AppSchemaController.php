<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Apps\AppIdentity;
use Api\V3\Apps\AppRegistration;
use Api\V3\Apps\AppRegistry;
use Api\V3\Apps\AppToken;
use Api\V3\Exception\DatabaseException;
use Api\V3\Support\MysqliStatements;
use Prosper202\Database\Connection;
use Prosper202\Goals\MysqlGoalRepository;

/**
 * GET /apps/schema: the document an app build fetches at runtime, selected
 * by its app token (plan §4.3).
 *
 * The point is release decoupling: the app ships once with the SDK and its
 * app token; from then on, changing what it should report is an edit on the
 * server — no store resubmission. The document is platform-shaped:
 *
 *  - iOS gets the goals its SKAN encodings name — an evaluation-only view,
 *    every prerequisite included and every value stripped — and the
 *    encodings themselves (goal -> {fine_value, coarse_value}), built from
 *    the same rows the report decodes through (/apps/skan-encodings), so the
 *    two directions cannot drift. The SDK evaluates the goals on the device
 *    (plan §5.5): Apple's postback carries only the value, so nothing else
 *    could decide which one to set;
 *  - Android gets its registration's identity, its Play Integrity mode and
 *    what the SDK needs to request a token bound to the install, and the SDK
 *    settings: where installs and events go and how many events one request
 *    may carry.
 *
 * Revenue is withheld from both: the document carries what a device needs
 * to act, never what the operator is paid.
 *
 * Trust model: the route is pre-auth (an app binary cannot hold an API key)
 * and selects the registration by the X-P202-App-Token header. The token is
 * an identifier, not a secret (AppToken); it is rotatable via
 * POST /apps/{id}/app-token/rotate.
 */
final class AppSchemaController
{
    use MysqliStatements;

    /** Order for picking one coarse value when several map to one event. */
    private const COARSE_RANK = ['low' => 1, 'medium' => 2, 'high' => 3];

    public function __construct(private readonly \mysqli $db)
    {
    }

    /**
     * Resolve an app token to its registration's document.
     *
     * @return array{status: int, body: array<string, mixed>|null, etag: string|null}
     */
    public function publicSchema(?string $token, ?string $ifNoneMatch): array
    {
        $token = trim((string)$token);
        if ($token === '') {
            return self::badRequest('Provide the app\'s token in the ' . AppToken::HEADER . ' header');
        }
        if (!AppToken::isWellFormed($token)) {
            return self::badRequest('App tokens are 64 hexadecimal characters; use the app_token from POST /apps or GET /apps/{id}');
        }

        $registration = (new AppRegistry($this->db))->byToken($token);
        if ($registration === null) {
            return [
                'status' => 404,
                'body' => ['error' => true, 'message' => 'Unknown app token', 'status' => 404],
                'etag' => null,
            ];
        }

        $document = $registration->identity->platform === AppIdentity::IOS
            ? $this->iosDocument($registration)
            : $this->androidDocument($registration);

        // The version hash covers everything that changes the device's
        // behaviour and nothing that does not, so devices polling with
        // If-None-Match get 304s until something actually changes.
        $canonical = json_encode($document);
        if ($canonical === false) {
            throw new DatabaseException('Schema encoding failed');
        }
        $etag = '"' . sha1($canonical) . '"';

        if ($ifNoneMatch !== null && self::ifNoneMatchSatisfied($ifNoneMatch, $etag)) {
            return ['status' => 304, 'body' => null, 'etag' => $etag];
        }

        return [
            'status' => 200,
            'body' => [
                'data' => $document + [
                    'schema_version' => trim($etag, '"'),
                    'generated_at' => time(),
                ],
            ],
            'etag' => $etag,
        ];
    }

    /** @return array<string, mixed> */
    private function iosDocument(AppRegistration $registration): array
    {
        return [
            'platform' => AppIdentity::IOS,
            'app_key' => $registration->identity->appKey,
            // The number the iOS helper has always read the app by.
            'app_id' => $registration->identity->appleAppId(),
        ] + $this->buildGoals($registration->userId, $registration->registrationId);
    }

    /** @return array<string, mixed> */
    private function androidDocument(AppRegistration $registration): array
    {
        // Android's goals are evaluated on the server, so the SDK reports
        // every event and needs no goal view; what it needs is how to talk
        // to the intake, and whether to request a Play Integrity token: under
        // observe or require it requests a standard token for the cloud
        // project named here, with requestHash = the install's fingerprint
        // (IntegrityBinding), and sends it as integrity_token.
        $mode = $registration->policy->integrityMode;
        // A token cannot be requested without the project number, so the
        // document never tells the SDK to try. The registry refuses
        // observe/require without one; this covers a stored mode read as
        // require because it was unreadable (IntegrityMode::fromStored()),
        // where the install then arrives tokenless and is judged `missing`.
        $project = $registration->integrityCloudProjectNumber;

        return [
            'platform' => AppIdentity::ANDROID,
            'app_key' => $registration->identity->appKey,
            'integrity_mode' => $mode->value,
            'integrity' => [
                'request_token' => $mode->decodes() && $project !== null,
                'token_type' => 'standard',
                'cloud_project_number' => $project,
                'request_hash' => 'sha256_hex_of_canonical_install_body',
            ],
            'sdk' => [
                'installs_path' => '/api/v3/apps/installs',
                'events_path' => '/api/v3/apps/installs/{install_uuid}/events',
                'max_events_per_request' => \Api\V3\Apps\Android\InstallEventsIntake::MAX_EVENTS,
            ],
        ];
    }

    /**
     * Whether an If-None-Match header matches the document's ETag, using the
     * WEAK comparison function RFC 7232 §3.2 requires for this header.
     *
     * Exact equality against the strong tag was wrong in three ways at once:
     * the header may carry a comma-separated list, the value `*`, and weak
     * validators (`W/"..."`). The nginx gzip filter rewrites a strong ETag to
     * its `W/` form, so every device behind a compressing proxy failed the
     * comparison and re-downloaded the whole schema on every poll — the
     * opposite of what this endpoint's ETag and max-age advertise. Only a
     * client echoing the exact quoted string ever got a 304.
     *
     * Normalisation matches Controller::assertIfMatchSatisfied(): strip a
     * leading `W/`, then the surrounding quotes and spaces. `*` matches
     * because the caller only reaches here with a resolved app row. A tag a
     * proxy has *rewritten* rather than weakened (mod_deflate's
     * `-gzip` suffix) is a different opaque tag and correctly does not match.
     *
     * Splitting on commas is safe for this endpoint: the tags it mints are
     * sha1 hex, so no comma can appear inside one.
     */
    private static function ifNoneMatchSatisfied(string $header, string $etag): bool
    {
        $expected = trim($etag, '" ');
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            if ($candidate === '*') {
                return true;
            }
            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }
            if (trim($candidate, '" ') === $expected) {
                return true;
            }
        }
        return false;
    }

    /**
     * The encodings a device applies, and the goals it evaluates to apply
     * them.
     *
     * `encodings`: one entry per goal an encoding names, from the owner's
     * encodings for this registration plus the registration_id = 0
     * account-wide ones. Per kind (fine, coarse), the registration's own
     * encodings of a goal beat the account-wide ones; when several values in
     * the winning scope name the same goal (legitimate on the decode side:
     * tiered revenue), the HIGHEST is served — SKAN reads higher as more
     * valuable, and the choice must be deterministic. An account-wide value
     * the registration has given its own meaning is not served at all: the
     * report decodes that value through the registration's encoding, so a
     * device setting it for the account-wide goal would be read as another
     * goal. An encoding whose goal is gone or archived is left out and
     * logged.
     *
     * `goals`: each encoded goal with every prerequisite its versions name
     * (MysqlGoalRepository::specsWithPrerequisites(), the server's own
     * evaluation set), every version with its effective_at, and each
     * definition with its `value` removed — the document says what a device
     * should do, never what an outcome is worth. A definition that no longer
     * parses is served as stored; the device disables it
     * (invalid_definition), as the server's evaluator does.
     *
     * @return array{goals: list<array<string, mixed>>, encodings: list<array{goal_id: int, fine_value: int|null, coarse_value: string|null}>}
     */
    private function buildGoals(int $userId, int $registrationId): array
    {
        $stmt = $this->prepare(
            'SELECT e.encoding_id, e.registration_id, e.fine_value, e.coarse_value, e.goal_id, g.archived_at, g.goal_id AS found
             FROM 202_app_skan_encodings e
             LEFT JOIN 202_goals g ON g.goal_id = e.goal_id AND g.user_id = e.user_id
             WHERE e.user_id = ? AND (e.registration_id = ? OR e.registration_id = 0)'
        );
        $this->bind($stmt, 'ii', $userId, $registrationId);
        $this->execute($stmt, 'Encodings query failed');
        $result = $this->result($stmt);

        $rows = [];
        $claimed = []; // "fine|<n>" / "coarse|<word>" the registration gave its own meaning
        while ($row = $result->fetch_assoc()) {
            if ($row['found'] === null || $row['archived_at'] !== null) {
                error_log('p202 app schema: SKAN encoding ' . (int)$row['encoding_id'] . ' is not served: its goal '
                    . (int)$row['goal_id'] . ($row['found'] === null ? ' does not exist' : ' is archived'));
                continue;
            }
            $kind = $row['fine_value'] !== null ? 'fine' : ($row['coarse_value'] !== null ? 'coarse' : null);
            if ($kind === null) {
                continue;
            }
            $value = $kind === 'fine' ? (int)$row['fine_value'] : (string)$row['coarse_value'];
            $scope = (int)$row['registration_id'] === 0 ? 'default' : 'app';
            if ($scope === 'app') {
                $claimed[$kind . '|' . $value] = true;
            }
            $rows[] = ['goal_id' => (int)$row['goal_id'], 'kind' => $kind, 'value' => $value, 'scope' => $scope];
        }
        $stmt->close();

        // best[goal][kind][scope] = the highest value in that scope.
        $best = [];
        foreach ($rows as $r) {
            if ($r['scope'] === 'default' && isset($claimed[$r['kind'] . '|' . $r['value']])) {
                continue;
            }
            $current = $best[$r['goal_id']][$r['kind']][$r['scope']] ?? null;
            if ($current === null || self::rank($r['kind'], $r['value']) > self::rank($r['kind'], $current)) {
                $best[$r['goal_id']][$r['kind']][$r['scope']] = $r['value'];
            }
        }
        ksort($best);

        $encodings = [];
        foreach ($best as $goalId => $kinds) {
            $fine = $kinds['fine']['app'] ?? $kinds['fine']['default'] ?? null;
            $coarse = $kinds['coarse']['app'] ?? $kinds['coarse']['default'] ?? null;
            $encodings[] = [
                'goal_id' => (int)$goalId,
                'fine_value' => $fine === null ? null : (int)$fine,
                'coarse_value' => $coarse === null ? null : (string)$coarse,
            ];
        }

        $goals = [];
        if ($best !== []) {
            $repository = new MysqlGoalRepository(new Connection($this->db));
            $starts = array_fill_keys(array_keys($best), 0);
            foreach ($repository->specsWithPrerequisites($userId, $starts, []) as $spec) {
                $goals[] = [
                    'goal_id' => $spec->goalId,
                    'starts_at' => $spec->startsAt,
                    'ends_at' => $spec->endsAt,
                    'versions' => array_map(static fn (array $v): array => [
                        'version' => (int)$v['version'],
                        'effective_at' => (int)$v['effective_at'],
                        'definition' => self::evaluationOnly($v['definition']),
                    ], $spec->versions),
                ];
            }
        }

        return ['goals' => $goals, 'encodings' => $encodings];
    }

    /** A value's order for the highest-wins tie-break. */
    private static function rank(string $kind, int|string $value): int
    {
        return $kind === 'fine' ? (int)$value : (self::COARSE_RANK[(string)$value] ?? 0);
    }

    /**
     * A stored definition without its value (plan §4.3: the document never
     * says what an outcome is worth). Anything that is not a decoded object
     * is passed through for the device to disable.
     */
    private static function evaluationOnly(mixed $definition): mixed
    {
        if (!is_array($definition)) {
            return $definition;
        }
        unset($definition['value']);

        // An object even when every key is gone, and nested objects stay
        // objects: json_encode() would turn an empty PHP array into [].
        return self::objects($definition);
    }

    /**
     * Re-mark associative arrays as objects for json_encode(), leaving
     * lists as lists.
     */
    private static function objects(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $isList = array_is_list($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::objects($v);
        }

        return $isList && $out !== [] ? $out : ($isList ? [] : (object)$out);
    }

    /** @return array{status: int, body: array<string, mixed>, etag: null} */
    private static function badRequest(string $message): array
    {
        return [
            'status' => 400,
            'body' => ['error' => true, 'message' => $message, 'status' => 400],
            'etag' => null,
        ];
    }
}
