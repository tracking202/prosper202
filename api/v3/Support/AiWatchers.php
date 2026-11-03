<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Api\V3\Auth;
use Prosper202\Database\Connection;

/**
 * The campaign watchers Tracking202's MCP server runs with an AI connection's
 * key, as that server reports them: listed on Account › API keys, stopped one
 * at a time there, and all stopped before the key is deleted.
 *
 * The server knows a key's watchers by its watcher token
 * (AiConnect::watcherToken()), never the key itself; the token goes only in
 * the Authorization header, never in a URL or a log line. A page waits at
 * most TIMEOUT seconds for an answer, and once a call could not reach the
 * server this instance asks it nothing more, so a page listing several keys
 * waits for one timeout, not one per key. A server that predates watchers
 * answers 404 for these paths: no watchers.
 */
final class AiWatchers
{
    public const CONNECT_TIMEOUT = 3;
    public const TIMEOUT = 3;
    private const MAX_BYTES = 1048576;
    public const ID = '/^wch_[0-9a-f]{24}$/D';

    private string $origin;
    private bool $unreachable = false;

    /** @param ?string $origin the server's origin; null = AiConnect::relayOrigin() */
    public function __construct(?string $origin = null)
    {
        $this->origin = $origin ?? AiConnect::relayOrigin();
    }

    /**
     * The watchers of the key whose token this is, newest first, or null
     * when they could not be read.
     *
     * @return list<array{id: string, campaignId: int, breakdown: string, window: string, rule: array<string, mixed>,
     *     createdAt: ?int, endsAt: ?int, status: string, statusReason: string, client: string, alerts: int,
     *     lastAlert: ?string}>|null
     */
    public function list(string $token): ?array
    {
        [$status, $body] = $this->call('GET', '/api/watchers', $token, null);
        if ($status === 404) {
            return [];
        }
        if ($status !== 200) {
            return null;
        }
        $data = is_array($body) ? ($body['data'] ?? null) : null;
        if (!is_array($data) || !array_is_list($data)) {
            error_log('p202: AI watchers: the list answer carried no data list');
            return null;
        }
        $watchers = [];
        foreach ($data as $entry) {
            $watcher = is_array($entry) ? self::watcher($entry) : null;
            if ($watcher === null) {
                // One unreadable entry makes the list untrustworthy as a whole:
                // a watcher left out would read as one that is not running.
                error_log('p202: AI watchers: the list answer carried an unreadable watcher');
                return null;
            }
            $watchers[] = $watcher;
        }
        return $watchers;
    }

    /**
     * Stops one watcher of the token's key: 'stopped', 'not_found' (not this key's, or
     * no such watcher) or 'failed' (no answer that says).
     */
    public function stop(string $token, string $watcherId): string
    {
        if (preg_match(self::ID, $watcherId) !== 1) {
            return 'not_found';
        }
        [$status, $body] = $this->call('POST', '/api/watchers/stop', $token, ['watcherId' => $watcherId]);
        if ($status === 404) {
            return 'not_found';
        }
        return $status === 200 && self::stoppedList($body) !== null ? 'stopped' : 'failed';
    }

    /** Stops every watcher of the token's key; true when the server confirmed it (404: it runs none). */
    public function stopAll(string $token): bool
    {
        [$status, $body] = $this->call('POST', '/api/watchers/stop', $token, ['all' => true]);
        return $status === 404 || ($status === 200 && self::stoppedList($body) !== null);
    }

    /**
     * Stops every watcher of each token's key; true when the server
     * confirmed it for all of them (and for none given).
     *
     * @param list<string> $tokens
     */
    public function stopAllOf(array $tokens): bool
    {
        $confirmed = true;
        foreach ($tokens as $token) {
            $confirmed = $this->stopAll($token) && $confirmed;
        }
        return $confirmed;
    }

    /**
     * The watcher tokens of the user's AI-connection keys
     * (AiConnect::isConnectionLabel()), all of them or those $apiKey names:
     * as the account page and the API delete it (the column's collation, case
     * folded) or, with $exact, byte for byte as POST /connect/revoke does. An
     * install without the label column has no key it can tell is a
     * connection's. Computed from the stored key for now; an install that
     * stores keys hashed reads a token stored at the key's creation instead.
     *
     * @return list<string>
     * @throws \Throwable when the keys could not be read
     */
    public static function connectionTokens(
        \mysqli $db,
        int $userId,
        ?string $apiKey = null,
        bool $exact = false
    ): array {
        if (!Auth::apiKeyLabelColumnExists($db)) {
            return [];
        }
        $conn = new Connection($db);
        $sql = 'SELECT api_key, label FROM 202_api_keys WHERE user_id = ?';
        if ($apiKey === null) {
            $stmt = $conn->prepareRead($sql);
            $conn->bind($stmt, 'i', [$userId]);
        } else {
            $stmt = $conn->prepareRead($sql . ($exact ? ' AND api_key COLLATE utf8mb4_bin = ?' : ' AND api_key = ?'));
            $conn->bind($stmt, 'is', [$userId, $apiKey]);
        }
        $tokens = [];
        foreach ($conn->fetchAll($stmt) as $row) {
            if (AiConnect::isConnectionLabel(isset($row['label']) ? (string) $row['label'] : null)) {
                $tokens[] = AiConnect::watcherToken((string) $row['api_key']);
            }
        }
        return $tokens;
    }

    /**
     * Before a delete of the user's keys ($apiKey, or all of them): stops the
     * watchers of each AI-connection key among them. Best effort; the delete
     * goes ahead whatever this answers, since a deleted key reads nothing.
     * True when there was nothing to stop or the server confirmed it.
     */
    public function stopBeforeDelete(\mysqli $db, int $userId, ?string $apiKey = null, bool $exact = false): bool
    {
        try {
            $tokens = self::connectionTokens($db, $userId, $apiKey, $exact);
        } catch (\Throwable $e) {
            error_log('p202: AI watchers: the keys to be deleted could not be read: ' . $e->getMessage());
            return false;
        }
        return $this->stopAllOf($tokens);
    }

    /** @return list<string>|null */
    private static function stoppedList(mixed $body): ?array
    {
        $stopped = is_array($body) ? ($body['stopped'] ?? null) : null;
        if (!is_array($stopped) || !array_is_list($stopped)) {
            return null;
        }
        foreach ($stopped as $id) {
            if (!is_string($id)) {
                return null;
            }
        }
        return $stopped;
    }

    /**
     * One watcher of the list, or null when it lacks what the page needs to
     * show and stop it.
     *
     * @param array<mixed> $entry
     * @return array{id: string, campaignId: int, breakdown: string, window: string, rule: array<string, mixed>,
     *     createdAt: ?int, endsAt: ?int, status: string, statusReason: string, client: string, alerts: int,
     *     lastAlert: ?string}|null
     */
    private static function watcher(array $entry): ?array
    {
        $id = $entry['id'] ?? null;
        $campaignId = $entry['campaignId'] ?? null;
        $status = $entry['status'] ?? null;
        if (!is_string($id) || preg_match(self::ID, $id) !== 1 || !is_int($campaignId) || !is_string($status)) {
            return null;
        }
        $string = static fn (string $k): string => is_string($entry[$k] ?? null) ? $entry[$k] : '';
        $ms = static fn (string $k): ?int => is_int($entry[$k] ?? null) || is_float($entry[$k] ?? null)
            ? (int) $entry[$k] : null;
        $rule = is_array($entry['rule'] ?? null) ? $entry['rule'] : [];
        /** @var array<string, mixed> $rule */
        return [
            'id' => $id,
            'campaignId' => $campaignId,
            'breakdown' => $string('breakdown'),
            'window' => $string('window'),
            'rule' => $rule,
            'createdAt' => $ms('createdAt'),
            'endsAt' => $ms('endsAt'),
            'status' => $status,
            'statusReason' => $string('statusReason'),
            'client' => $string('client'),
            'alerts' => is_int($entry['alerts'] ?? null) ? $entry['alerts'] : 0,
            'lastAlert' => is_string($entry['lastAlert'] ?? null) ? $entry['lastAlert'] : null,
        ];
    }

    /**
     * One request to the server: [status, decoded JSON body or null]. Status
     * 0 when there was no answer; from then on this instance makes no call.
     *
     * @param array<string, mixed>|null $json
     * @return array{0: int, 1: mixed}
     */
    private function call(string $method, string $path, string $token, ?array $json): array
    {
        if ($this->unreachable) {
            return [0, null];
        }
        $url = $this->origin . $path;
        $scheme = (string) parse_url($url, PHP_URL_SCHEME);
        $host = (string) parse_url($url, PHP_URL_HOST);
        $loopback = in_array($host, ['127.0.0.1', 'localhost', '[::1]'], true);
        if ($scheme !== 'https' && !($scheme === 'http' && $loopback)) {
            // The key would cross the network in clear text.
            error_log('p202: AI watchers: the MCP server origin is not https (http only to loopback); not called');
            $this->unreachable = true;
            return [0, null];
        }
        if (!function_exists('curl_init')) {
            $this->unreachable = true;
            return [0, null];
        }
        $ch = curl_init();
        if ($ch === false) {
            $this->unreachable = true;
            return [0, null];
        }
        $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => $scheme === 'https' ? CURLPROTO_HTTPS : CURLPROTO_HTTP,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Prosper202/' . AiConnect::version(),
        ];
        if ($json !== null) {
            $encoded = json_encode($json);
            if ($encoded === false) {
                return [0, null];
            }
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = $encoded;
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        $raw = '';
        $options[CURLOPT_RETURNTRANSFER] = false;
        $options[CURLOPT_WRITEFUNCTION] = static function ($handle, string $chunk) use (&$raw): int {
            if (strlen($raw) + strlen($chunk) > self::MAX_BYTES) {
                return 0; // longer than any list of watchers: abort
            }
            $raw .= $chunk;
            return strlen($chunk);
        };
        if (!curl_setopt_array($ch, $options)) {
            error_log('p202: AI watchers: curl refused an option: ' . curl_error($ch));
            $this->unreachable = true;
            return [0, null];
        }
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        if ($ok === false || $status === 0) {
            error_log('p202: AI watchers: ' . $method . ' ' . $path . ' had no answer: ' . $error);
            $this->unreachable = true;
            return [0, null];
        }
        if ($status !== 200 && $status !== 404) {
            error_log('p202: AI watchers: ' . $method . ' ' . $path . ' answered HTTP ' . $status);
        }
        $decoded = json_decode($raw, true);
        if ($status === 200 && !is_array($decoded)) {
            error_log('p202: AI watchers: ' . $method . ' ' . $path . ' answered 200 without JSON');
            return [502, null];
        }
        return [$status, $decoded];
    }
}
