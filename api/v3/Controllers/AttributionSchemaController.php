<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\DatabaseException;
use Api\V3\Support\MysqliStatements;

/**
 * Serves the SKAN conversion-value schema to the advertised app at runtime.
 *
 * The point of this endpoint is release decoupling: the app ships once with
 * the P202Attribution helper (or its own fetch of this document) and a per-app
 * schema token; from then on, changing what conversion values mean is an
 * edit to /attribution/conversion-values — no App Store resubmission. The same
 * rule rows drive this ENCODE document and the report's DECODE step, so the
 * two directions cannot drift.
 *
 * Trust model: the endpoint is pre-auth (an app binary cannot hold an API
 * key), gated by the app's schema_token — an unguessable capability value
 * minted at registration and rotatable via POST
 * /attribution/apps/{id}/schema-token/rotate. The document deliberately carries
 * only what encoding needs: event names and their values. Revenue amounts
 * stay server-side.
 */
final class AttributionSchemaController
{
    use MysqliStatements;

    /** Order for picking one coarse value when several map to one event. */
    private const COARSE_RANK = ['low' => 1, 'medium' => 2, 'high' => 3];

    public function __construct(private readonly \mysqli $db)
    {
    }

    /**
     * Resolve a schema token to its app's encode schema.
     *
     * @return array{status: int, body: array<string, mixed>|null, etag: string|null}
     */
    public function publicSchema(?string $token, ?string $ifNoneMatch): array
    {
        $token = trim((string)$token);
        if ($token === '') {
            return self::badRequest('Provide the app\'s schema token in the X-P202-Schema-Token header');
        }
        // Minted tokens are exactly bin2hex(random_bytes(32)); anything else
        // is a paste of the wrong value (an API key, a truncated copy) and is
        // told so instead of getting a misleading "unknown token" 404.
        if (strlen($token) !== 64 || !ctype_xdigit($token)) {
            return self::badRequest('Schema tokens are 64 hexadecimal characters; use the value from POST /attribution/apps or GET /attribution/apps/{id}');
        }

        // Deliberately an indexed equality lookup rather than a constant-time
        // compare: the token is 256 random bits, so guessing is infeasible
        // regardless of timing, and the comparison happens inside the
        // database's index walk, which exposes no per-byte timing to the
        // client the way a naive string compare in PHP could.
        $stmt = $this->prepare('SELECT attribution_app_id, user_id, app_id FROM 202_attribution_apps WHERE schema_token = ? LIMIT 1');
        $this->bind($stmt, 's', $token);
        $this->execute($stmt, 'Schema lookup failed');
        $result = $this->result($stmt);
        $app = $result->fetch_assoc();
        $stmt->close();

        if (!is_array($app)) {
            return [
                'status' => 404,
                'body' => ['error' => true, 'message' => 'Unknown schema token', 'status' => 404],
                'etag' => null,
            ];
        }

        $events = $this->buildEvents((int)$app['user_id'], (int)$app['app_id']);

        // The version hash covers everything that changes encoding behaviour
        // and nothing that does not, so devices polling with If-None-Match
        // get 304s until a rule actually changes.
        $canonical = json_encode(['app_id' => (int)$app['app_id'], 'events' => $events]);
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
                'data' => [
                    'app_id' => (int)$app['app_id'],
                    'schema_version' => trim($etag, '"'),
                    // Always an object: event names can be numeric strings,
                    // and a PHP array of 0-based numeric keys would
                    // JSON-encode as a LIST, dropping the names — the iOS
                    // helper's [String: EventMapping] decoder then rejects
                    // the whole document on every installed device.
                    'events' => (object)$events,
                    'generated_at' => time(),
                ],
            ],
            'etag' => $etag,
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
     * owner's rules for this app plus the app_id = 0 account-wide defaults.
     *
     * Resolution, per event and per kind (fine and coarse independently):
     * app-specific rules beat defaults; when several values in the winning
     * scope decode to the same event (legitimate on the decode side), the
     * HIGHEST value is served — SKAN convention reads higher as more
     * valuable, and the choice must be deterministic.
     *
     * @return array<string, array{fine_value: int|null, coarse_value: string|null}>
     */
    private function buildEvents(int $userId, int $appId): array
    {
        $stmt = $this->prepare(
            'SELECT app_id, fine_value, coarse_value, event_name FROM 202_attribution_conversion_values WHERE user_id = ? AND (app_id = ? OR app_id = 0)'
        );
        $this->bind($stmt, 'ii', $userId, $appId);
        $this->execute($stmt, 'Rules query failed');
        $result = $this->result($stmt);

        // candidates[event][kind][scope] = best value seen for that scope,
        // where scope is 'app' or 'default'.
        $candidates = [];
        while ($row = $result->fetch_assoc()) {
            $eventName = (string)$row['event_name'];
            $scope = ((int)$row['app_id'] === 0) ? 'default' : 'app';
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
