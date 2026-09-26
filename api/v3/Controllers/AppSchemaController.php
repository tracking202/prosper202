<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Apps\AppIdentity;
use Api\V3\Apps\AppRegistration;
use Api\V3\Apps\AppRegistry;
use Api\V3\Apps\AppToken;
use Api\V3\Exception\DatabaseException;
use Api\V3\Support\MysqliStatements;

/**
 * GET /apps/schema: the document an app build fetches at runtime, selected
 * by its app token (plan §4.3).
 *
 * The point is release decoupling: the app ships once with the SDK and its
 * app token; from then on, changing what it should report is an edit on the
 * server — no store resubmission. The document is platform-shaped:
 *
 *  - iOS gets the SKAN encode map — event name -> {fine_value,
 *    coarse_value} — built from the same encoding rows the report decodes
 *    through (/apps/skan-encodings), so the two directions cannot drift;
 *  - Android gets its registration's identity, the integrity mode (off
 *    until PR 6) and the SDK settings: where installs and events go and how
 *    many events one request may carry.
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

        if (isset($document['events'])) {
            // Always an object: event names can be numeric strings, and a PHP
            // array of 0-based numeric keys would JSON-encode as a LIST,
            // dropping the names — the iOS helper's [String: EventMapping]
            // decoder then rejects the whole document on every device.
            $document['events'] = (object)$document['events'];
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
            'events' => $this->buildEvents($registration->userId, $registration->registrationId),
        ];
    }

    /** @return array<string, mixed> */
    private function androidDocument(AppRegistration $registration): array
    {
        // Android's goals are evaluated on the server, so the SDK reports
        // every event and needs no goal view; what it needs is how to talk
        // to the intake. Play Integrity is PR 6: until then it is off for
        // every registration, and the SDK requests no token.
        return [
            'platform' => AppIdentity::ANDROID,
            'app_key' => $registration->identity->appKey,
            'integrity_mode' => 'off',
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
     * The encode map: event name -> {fine_value, coarse_value}, from the
     * owner's encodings for this registration plus the registration_id = 0
     * account-wide ones. An encoding names a goal (plan §4.5); until the
     * on-device evaluator ships the goal is a plain event goal
     * (AppSkanEncodingsController refuses anything else), so the document
     * keeps its shape and keys each value by the goal's trigger event — the
     * name the SDK's logEvent() is called with. An encoding whose goal is
     * archived or no longer plain is left out and logged: the SDK could not
     * act on it, and serving it would set a value for the wrong event.
     *
     * Resolution, per event and per kind (fine and coarse independently):
     * app-specific rules beat defaults; when several values in the winning
     * scope decode to the same event (legitimate on the decode side), the
     * HIGHEST value is served — SKAN convention reads higher as more
     * valuable, and the choice must be deterministic.
     *
     * @return array<string, array{fine_value: int|null, coarse_value: string|null}>
     */
    private function buildEvents(int $userId, int $registrationId): array
    {
        $stmt = $this->prepare(
            'SELECT e.encoding_id, e.registration_id, e.fine_value, e.coarse_value, e.goal_id, g.archived_at, v.definition
             FROM 202_app_skan_encodings e
             LEFT JOIN 202_goals g ON g.goal_id = e.goal_id AND g.user_id = e.user_id
             LEFT JOIN 202_goal_versions v ON v.goal_id = g.goal_id AND v.version = g.current_version
             WHERE e.user_id = ? AND (e.registration_id = ? OR e.registration_id = 0)'
        );
        $this->bind($stmt, 'ii', $userId, $registrationId);
        $this->execute($stmt, 'Encodings query failed');
        $result = $this->result($stmt);

        // candidates[event][kind][scope] = best value seen for that scope,
        // where scope is 'app' or 'default'.
        $candidates = [];
        while ($row = $result->fetch_assoc()) {
            $eventName = self::plainEventOf($row);
            if ($eventName === null) {
                continue;
            }
            $scope = ((int)$row['registration_id'] === 0) ? 'default' : 'app';
            if ($row['fine_value'] !== null) {
                $fine = (int)$row['fine_value'];
                $current = $candidates[$eventName]['fine'][$scope] ?? null;
                if ($current === null || $fine > $current) {
                    $candidates[$eventName]['fine'][$scope] = $fine;
                }
            } elseif ($row['coarse_value'] !== null) {
                $coarse = (string)$row['coarse_value'];
                $current = $candidates[$eventName]['coarse'][$scope] ?? null;
                if (
                    $current === null
                    || (self::COARSE_RANK[$coarse] ?? 0) > (self::COARSE_RANK[$current] ?? 0)
                ) {
                    $candidates[$eventName]['coarse'][$scope] = $coarse;
                }
            }
        }
        $stmt->close();

        $events = [];
        foreach ($candidates as $eventName => $kinds) {
            $events[$eventName] = [
                'fine_value' => $kinds['fine']['app'] ?? $kinds['fine']['default'] ?? null,
                'coarse_value' => $kinds['coarse']['app'] ?? $kinds['coarse']['default'] ?? null,
            ];
        }
        ksort($events);
        return $events;
    }

    /**
     * The trigger event of an encoding's goal, or null (logged) when the
     * goal is gone, archived, or not a plain event goal.
     *
     * @param array<string, mixed> $row
     */
    private static function plainEventOf(array $row): ?string
    {
        $why = null;
        $event = null;
        if ($row['definition'] === null) {
            $why = 'its goal ' . (int)$row['goal_id'] . ' does not exist';
        } elseif ($row['archived_at'] !== null) {
            $why = 'its goal ' . (int)$row['goal_id'] . ' is archived';
        } else {
            try {
                $definition = \Prosper202\Goals\GoalDefinition::fromJson((string)$row['definition'], (int)$row['goal_id']);
                if ($definition->isPlainEvent()) {
                    $event = $definition->triggerEvent;
                } else {
                    $why = 'its goal ' . (int)$row['goal_id'] . ' is not a plain event goal';
                }
            } catch (\Prosper202\Goals\InvalidGoalDefinition $e) {
                $why = 'its goal ' . (int)$row['goal_id'] . ' has an invalid definition: ' . $e->getMessage();
            }
        }
        if ($why !== null) {
            error_log('p202 app schema: SKAN encoding ' . (int)$row['encoding_id'] . ' is not served: ' . $why);
        }

        return $event;
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
