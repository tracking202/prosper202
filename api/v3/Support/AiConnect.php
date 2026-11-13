<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Api\V3\Auth;
use Api\V3\AuthException;
use Api\V3\Controllers\CapabilitiesController;
use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;

/**
 * Connecting an AI assistant through Tracking202's MCP server.
 *
 * The person approves on this install, signed in as themselves
 * (202-account/connect-ai.php). The approval is a one-time code sent to the
 * MCP server's fixed address, never to an address the request names, and
 * the MCP server exchanges it here (POST /api/v3/connect/token) for a new
 * API key of that user, with the access the person chose. Holding the code
 * is not enough: the exchange needs the verifier whose S256 challenge the
 * MCP server sent with the person (RFC 7636), so a code read from a log or
 * a browser history buys nothing.
 */
final class AiConnect
{
    public const RELAY_ORIGIN = 'https://mcp.tracking202.com';

    /** Seconds between the approval and the exchange. */
    public const CODE_TTL = 300;

    /** The access a person can give, in the order the approval page lists them. */
    public const ACCESSES = ['read', 'propose', 'write'];

    /**
     * The API areas the hosted MCP server's tools read, and of those the ones
     * they change: all an AI key reaches, whatever the approving user's role.
     * Never `users` (its keys and identity key), `system` or `sync`.
     * TestHostedMCPToolsStayInsideTheAIKeyAreas (go-cli) holds every hosted
     * tool's requests to these lists, and the lists to what the tools use.
     */
    public const READ_AREAS = [
        'reports', 'attribution', 'campaigns', 'trackers', 'landing-pages', 'ppc-networks', 'ppc-accounts',
        'aff-networks', 'text-ads', 'rotators', 'clicks', 'conversions', 'staged-changes', 'ad-connections',
    ];
    public const WRITE_AREAS = [
        'campaigns', 'trackers', 'landing-pages', 'ppc-networks', 'ppc-accounts', 'aff-networks', 'text-ads', 'rotators',
        // ChatGPT Ads campaigns: pause, activate, template, event settings. The connection's key is never
        // reachable with an area scope (AdConnectionKeyAccess).
        'ad-connections',
    ];

    /**
     * Preselected on the approval page. Read only, until proposals can be
     * applied from a web page: today only `p202 change apply` and the API
     * can, so "propose" would strand a person without the CLI.
     */
    public const DEFAULT_ACCESS = 'read';

    private const B64URL_43 = '/^[A-Za-z0-9_-]{43}$/D';

    /**
     * The MCP server this install sends approvals to. P202_MCP_RELAY_ORIGIN
     * in 202-config.php replaces it (a test install pointing at a local
     * relay); a value that is not a bare http(s) origin is ignored.
     */
    public static function relayOrigin(): string
    {
        if (defined('P202_MCP_RELAY_ORIGIN')) {
            $origin = (string) constant('P202_MCP_RELAY_ORIGIN');
            if (preg_match('#^https?://[a-z0-9.-]+(:[0-9]{1,5})?$#D', $origin) === 1) {
                return $origin;
            }
            error_log('p202: P202_MCP_RELAY_ORIGIN is not an origin (scheme://host[:port]); using '
                . self::RELAY_ORIGIN);
        }
        return self::RELAY_ORIGIN;
    }

    /** This install's version: the API does not load version.php itself. */
    public static function version(): string
    {
        if (!defined('PROSPER202_VERSION')) {
            require_once dirname(__DIR__, 3) . '/202-config/version.php';
        }
        return (string) PROSPER202_VERSION;
    }

    public static function redirectUri(): string
    {
        return self::relayOrigin() . '/callback';
    }

    /**
     * The approval request's parameters, checked; or the sentence saying
     * which one is wrong. The same check runs on the page's GET and on its
     * POST, which carries them back in hidden fields.
     *
     * install is the address the person gave the MCP server; the page holds
     * it to its own (sameInstall()), so another install cannot bounce a
     * person here with its own approval link. The MCP server sets
     * client_domain only for an app it verified (a client ID metadata
     * document on that domain), but nothing signs the link, so anyone can
     * write one naming any app: the page shows both names as the link's
     * claim. redirect_host is where the MCP server hands the connection.
     *
     * @param array<string, mixed> $params
     * @return array{state: string, code_challenge: string, client_name: string, client_domain: string,
     *     redirect_host: string, install: string}|string
     */
    public static function readRequest(array $params): array|string
    {
        $value = static fn (string $k): string => isset($params[$k]) && is_string($params[$k]) ? $params[$k] : '';
        $again = ' Start connecting again from your AI app.';
        if ($value('redirect_uri') !== self::redirectUri()) {
            return 'This approval link does not come from Tracking202\'s MCP server ('
                . self::relayOrigin() . '), so it cannot be used.';
        }
        $parts = explode('.', $value('state'));
        if (
            count($parts) !== 2
            || preg_match(self::B64URL_43, $parts[0]) !== 1
            || preg_match(self::B64URL_43, $parts[1]) !== 1
        ) {
            return 'The approval link is incomplete (state).' . $again;
        }
        if ($value('code_challenge_method') !== 'S256' || preg_match(self::B64URL_43, $value('code_challenge')) !== 1) {
            return 'The approval link is incomplete (code_challenge).' . $again;
        }
        $client = trim($value('client_name'));
        if ($client === '' || mb_strlen($client) > 100 || preg_match('/[\x00-\x1f\x7f]/', $client) === 1) {
            return 'The approval link does not name the AI app (client_name).' . $again;
        }
        $hostname = '/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/D';
        $domain = $value('client_domain');
        if ($domain !== '' && preg_match($hostname, $domain) !== 1) {
            return 'The approval link is incomplete (client_domain).' . $again;
        }
        // A host name, [::1], or (for an app's own URI scheme) the whole URI;
        // shown escaped, so any printable text up to 255 characters will do.
        $redirectHost = $value('redirect_host');
        if (preg_match('/^[\x21-\x7e]{1,255}$/D', $redirectHost) !== 1) {
            return 'The approval link does not say where the connection goes (redirect_host).' . $again;
        }
        $install = $value('install');
        if (preg_match('#^https?://[A-Za-z0-9.-]+(:[0-9]{1,5})?/([A-Za-z0-9._~-]+/)*$#D', $install) !== 1) {
            return 'The approval link does not name this install (install).' . $again;
        }
        return [
            'state' => $value('state'),
            'code_challenge' => $value('code_challenge'),
            'client_name' => $client,
            'client_domain' => $domain,
            'redirect_host' => $redirectHost,
            'install' => $install,
        ];
    }

    /**
     * Whether two install addresses name the same install: the same host and
     * port (case folded) and the same path. The scheme is not compared: an
     * install behind a TLS proxy that does not say so sees itself as http.
     */
    public static function sameInstall(string $a, string $b): bool
    {
        $key = static function (string $url): ?string {
            $p = parse_url($url);
            if (!is_array($p) || !isset($p['host'])) {
                return null;
            }
            $port = isset($p['port']) && !in_array($p['port'], [80, 443], true) ? ':' . $p['port'] : '';
            $path = rtrim($p['path'] ?? '/', '/') . '/';
            return strtolower(rtrim($p['host'], '.')) . $port . $path;
        };
        $ka = $key($a);
        return $ka !== null && $ka === $key($b);
    }

    /**
     * The scope a chosen access mints, or null for an access that is not one
     * of the three: `<area>:read` for each area the tools read, and for the
     * areas they change `<area>:stage` beside it (propose) or `<area>:write`
     * in its place (write).
     */
    public static function scopeFor(string $access): ?string
    {
        if (!in_array($access, self::ACCESSES, true)) {
            return null;
        }
        $tokens = [];
        foreach (self::READ_AREAS as $area) {
            $changes = in_array($area, self::WRITE_AREAS, true);
            $tokens[] = $area . ($changes && $access === 'write' ? ':write' : ':read');
            if ($changes && $access === 'propose') {
                $tokens[] = $area . ':stage';
            }
        }
        return implode(',', $tokens);
    }

    /** Each access as the approval page names it. */
    public const ACCESS_LABELS = [
        'read' => 'Read only',
        'propose' => 'Read, and propose changes',
        'write' => 'Read and change',
    ];

    /**
     * The least access whose key passes a check for $scope, or null when no
     * connection's key can (users, system, sync, or an area the tools do not
     * change). A connection keeps the scope it was minted with, so one
     * approved before an area joined these lists is refused there until it
     * is approved again.
     */
    public static function accessCovering(string $scope): ?string
    {
        foreach (self::ACCESSES as $access) {
            if (Auth::scopesSatisfy(explode(',', (string) self::scopeFor($access)), $scope)) {
                return $access;
            }
        }
        return null;
    }

    /**
     * The access to choose when approving a connection again so that its new
     * key passes a check for $scope and keeps what its key ($keyScopes) may
     * do now: accessCovering(), or the key's own access when that is more.
     * Null when no connection's key can pass.
     *
     * @param string[] $keyScopes
     */
    public static function accessToApprove(string $scope, array $keyScopes): ?string
    {
        $covering = self::accessCovering($scope);
        if ($covering === null) {
            return null;
        }
        $held = 'read';
        foreach ($keyScopes as $token) {
            if ($token === 'write' || str_ends_with($token, ':write')) {
                $held = 'write';
                break;
            }
            if ($token === 'stage' || str_ends_with($token, ':stage')) {
                $held = 'propose';
            }
        }
        $rank = array_flip(self::ACCESSES);
        return $rank[$held] > $rank[$covering] ? $held : $covering;
    }

    /** The access a stored scope was minted for, or null when it is none of the three. */
    public static function accessFor(string $scope): ?string
    {
        foreach (self::ACCESSES as $access) {
            if (self::scopeFor($access) === $scope) {
                return $access;
            }
        }
        return null;
    }

    /**
     * The query flag a connect route cannot honour, or null. Neither
     * /connect/token nor /connect/revoke previews or proposes, so a request
     * asking for either is refused rather than performed (CLAUDE.md #14).
     *
     * @param array<string, mixed> $queryParams
     */
    public static function withheldFlag(array $queryParams): ?string
    {
        foreach (['dry_run', 'staged'] as $flag) {
            if (array_key_exists($flag, $queryParams)) {
                return $flag;
            }
        }
        return null;
    }

    /**
     * The licence key as the MCP server sends it to the licence service:
     * SHA-256 of the key trimmed and lowercased, because the service's lookup
     * by key ignores case and surrounding spaces and a lookup by hash cannot.
     */
    public static function licenceKeyHash(string $key): string
    {
        return hash('sha256', strtolower(trim($key)));
    }

    /** How every connection's label begins; nothing else writes a key's label. */
    public const LABEL_PREFIX = 'AI: ';

    /** The key's label in Account › API keys. */
    public static function label(string $clientName): string
    {
        $host = (string) parse_url(self::relayOrigin(), PHP_URL_HOST);
        return mb_substr(self::LABEL_PREFIX . $clientName . ' via ' . $host, 0, 100);
    }

    /** Whether a key's stored label is one label() wrote: the key is a connection's. */
    public static function isConnectionLabel(?string $label): bool
    {
        return $label !== null && str_starts_with($label, self::LABEL_PREFIX);
    }

    /**
     * The client's kind for analytics: a fixed category, never the name the
     * approval link carried, which anyone can set to anything.
     */
    public static function analyticsClientKind(string $clientName): string
    {
        return match (strtolower(trim($clientName))) {
            'claude' => 'claude',
            'chatgpt' => 'chatgpt',
            'cursor' => 'cursor',
            'gemini' => 'gemini',
            default => 'other',
        };
    }

    /**
     * What the MCP server knows a connection's watchers by: HMAC-SHA256 of
     * WATCHER_TOKEN_INFO keyed with the key, hex. One-way from the key, so an
     * install that stores keys hashed can keep it from the key's creation.
     */
    public static function watcherToken(string $apiKey): string
    {
        return hash_hmac('sha256', self::WATCHER_TOKEN_INFO, $apiKey);
    }

    public const WATCHER_TOKEN_INFO = 'p202-mcp watchers v1';

    /** Records an approval and returns its one-time code. */
    public static function approve(
        ServerStateStore $store,
        int $userId,
        string $scope,
        string $challenge,
        string $clientName
    ): string {
        $code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $store->issueConnectCode(hash('sha256', $code), [
            'user_id' => $userId,
            'scope' => $scope,
            'code_challenge' => $challenge,
            'client_name' => $clientName,
        ], self::CODE_TTL);
        return $code;
    }

    /** Failed exchanges counted per source address, and the window they are counted in. */
    public const FAILURE_BUDGET = 30;
    public const FAILURE_WINDOW = 60;

    /**
     * POST /connect/token under its failure budget. Only failures are
     * counted: the MCP server's exchanges can share an address with anyone
     * else's (a proxy, Cloudflare's Worker egress), so a good exchange is
     * never refused. A source past its budget is refused before the work
     * unless it presents a live approval, so junk spends nothing; an exchange
     * that runs answers as itself, failure or not (a failed live code says
     * to start again, not to wait), and its failure is counted. Check and
     * count are separate steps, so concurrent requests can pass the check
     * together: the budget bounds the steady rate, not a burst. A limiter
     * that cannot answer is logged and serves, as the other public intakes'
     * limiters do: the code and its verifier are the guard, not the count.
     *
     * @param mixed $presentedCode the request's `code`, read only to ask
     *        whether it is a live approval; the exchange reads the body
     * @param callable(): array{0: int, 1: array<string, mixed>} $exchange
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function tokenWithFailureBudget(
        ServerStateStore $store,
        mixed $presentedCode,
        callable $exchange,
        ?string $peerIp = null
    ): array {
        $limited = static fn (int $after): array => [429, ['rate_limited' => true, 'retry_after_seconds' => $after]];
        $bucket = 'connect-token-failed';
        $over = $store->softIpRateLimitExceeded($bucket, self::FAILURE_BUDGET, self::FAILURE_WINDOW, $peerIp);
        if ($over !== null) {
            $live = false;
            try {
                $live = is_string($presentedCode) && $store->hasConnectCode(hash('sha256', $presentedCode));
            } catch (\Throwable $e) {
                // An approval that cannot be read cannot be exchanged either
                // (consumeConnectCode reads strictly), so this is no live one.
                error_log('p202: AI connect: approvals unreadable for the budget check: ' . $e->getMessage());
            }
            if (!$live) {
                return $limited($over);
            }
        }
        try {
            [$status, $body] = $exchange();
        } catch (\Throwable $e) {
            // A refusal thrown (an unknown key in the body, a database
            // error) is a failure too.
            $store->softIpRateLimit($bucket, self::FAILURE_BUDGET, self::FAILURE_WINDOW, $peerIp);
            throw $e;
        }
        if ($status >= 400) {
            $store->softIpRateLimit($bucket, self::FAILURE_BUDGET, self::FAILURE_WINDOW, $peerIp);
        }
        return [$status, $body];
    }

    /**
     * POST /connect/token, after ConnectController refused unknown keys.
     * Returns [status, body]. The licence is decided here, before any key
     * exists, as the install's CLI gate decides it (#201): an active free
     * ClickServer licence, no paid subscription. The answer never carries
     * the ClickServer key: only its hash (licenceKeyHash()), when the key
     * could be read, which is good for nothing else.
     *
     * @param array<string, mixed> $payload
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function exchange(\mysqli $db, ServerStateStore $store, array $payload): array
    {
        $refuse = static fn (int $status, string $code, string $message): array
            => [$status, ['error' => true, 'code' => $code, 'message' => $message]];
        $again = ' Start connecting again.';

        $code = $payload['code'] ?? null;
        $verifier = $payload['code_verifier'] ?? null;
        if (!is_string($code) || preg_match(self::B64URL_43, $code) !== 1) {
            return $refuse(400, 'invalid_request', 'code must be the code this install sent with the approval.');
        }
        if (!is_string($verifier) || preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier) !== 1) {
            return $refuse(400, 'invalid_request', 'code_verifier must be 43 to 128 characters of A-Z, a-z, 0-9 '
                . 'and -._~ (RFC 7636).');
        }
        // Asked before the code is used up, so a database that cannot answer
        // refuses with the code still good. label came with 1.9.78, whose
        // files can land before its upgrade runs.
        try {
            $withLabel = Auth::apiKeyLabelColumnExists($db);
            // Probed now too, so the key's insert (ApiKeyStore) asks nothing new.
            Auth::apiKeyHashColumnExists($db);
            Auth::apiKeyScopeColumnExists($db);
        } catch (AuthException $e) {
            return $refuse(500, 'server_error', 'The install could not read its API key table.' . $again);
        }
        // Consumed whatever happens next: a code works once.
        $grant = $store->consumeConnectCode(hash('sha256', $code));
        if ($grant === null) {
            return $refuse(400, 'invalid_grant', 'This approval was used already, expired, or was never given.'
                . $again);
        }
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if (!hash_equals((string) $grant['code_challenge'], $challenge)) {
            return $refuse(400, 'invalid_grant', 'The verifier does not match the approval.' . $again);
        }
        $userId = (int) $grant['user_id'];
        $scope = (string) $grant['scope'];
        $access = self::accessFor($scope);
        if ($access === null) {
            return $refuse(500, 'server_error', 'The approval was stored without a known access.' . $again);
        }

        $conn = new Connection($db);
        try {
            $userName = self::activeUserName($conn, $userId);
        } catch (QueryException $e) {
            error_log('p202: AI connect: ' . $e->getMessage());
            return $refuse(500, 'server_error', 'The install could not read the user who approved.' . $again);
        }
        if ($userName === null) {
            return $refuse(400, 'invalid_grant', 'The user who approved can no longer sign in to this install.');
        }
        // The CLI gate's own verdict for this user: an active free ClickServer
        // licence (a valid key, no paid subscription), the grace period
        // included, and no key exists until it has answered.
        $licenceGate = new CapabilitiesController($db, $userId);
        if (!$licenceGate->cliAccess()) {
            return $refuse(402, 'licence', $licenceGate->cliRefusal(
                CapabilitiesController::notLicensedMessage('Connecting an AI assistant is free')
            ));
        }

        // An install with no key was refused above. A key that cannot be read
        // is the install's own error, which cliAccess() lets through; the
        // answer then carries no hash, which the hosted MCP server no longer
        // requires (it takes one when sent).
        $licenceKey = CapabilitiesController::licenceKeyFor($db, $userId);

        $label = self::label((string) $grant['client_name']);
        $apiKey = bin2hex(random_bytes(32));
        try {
            // Stored hashed, with the watcher token its label calls for.
            \Prosper202\User\ApiKeyStore::insert($db, $userId, $apiKey, $scope, $withLabel ? $label : null, time());
        } catch (QueryException $e) {
            error_log('p202: AI connect: ' . $e->getMessage());
            return $refuse(500, 'server_error', 'The key could not be created; nothing was connected.' . $again);
        }

        // Analytics milestone (consent-gated): the app's kind and the access granted.
        require_once dirname(__DIR__, 3) . '/202-config/Messaging/Analytics.class.php';
        \Analytics::eventFor($db, $userId, 'ai_connector_connected', [
            'client' => self::analyticsClientKind((string) $grant['client_name']),
            'access' => (string) $access,
        ], 'analytics');

        return [200, ['data' => [
            'api_key' => $apiKey,
            'scope' => $scope,
            'access' => $access,
            'user' => ['user_id' => $userId, 'user_name' => $userName],
            'version' => self::version(),
            'licensed' => true,
        ] + ($licenceKey === null || $licenceKey === ''
            ? []
            : ['licence_key_sha256' => self::licenceKeyHash($licenceKey)])]];
    }

    /**
     * POST /connect/revoke: the key the request bears removes itself, and
     * nothing else. The caller has already been authenticated with it.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function revoke(\mysqli $db, string $apiKey): array
    {
        $conn = new Connection($db);
        try {
            // Compared byte for byte, as every key lookup is (ApiKeyStore).
            [$keyMatch, $keyTypes, $keyValues] = \Prosper202\User\ApiKeyStore::matchKey($db, $apiKey);
            $stmt = $conn->prepareWrite('DELETE FROM 202_api_keys WHERE ' . $keyMatch);
            $conn->bind($stmt, $keyTypes, $keyValues);
            $removed = $conn->executeUpdate($stmt);
        } catch (QueryException | AuthException $e) {
            error_log('p202: AI connect: revoke: ' . $e->getMessage());
            $message = 'The key could not be removed; remove it in My Account › Personal Settings › API keys.';
            return [500, ['error' => true, 'message' => $message]];
        }
        if ($removed < 1) {
            return [404, ['error' => true, 'message' => 'No key with exactly these characters was found; '
                . 'remove it in My Account › Personal Settings › API keys.']];
        }
        return [200, ['data' => ['revoked' => true]]];
    }

    /**
     * The name of an active, undeleted user, or null when there is none.
     *
     * @throws QueryException when the user could not be read
     */
    private static function activeUserName(Connection $conn, int $userId): ?string
    {
        $stmt = $conn->prepareRead(
            'SELECT user_name FROM 202_users WHERE user_id = ? AND user_active = 1 AND user_deleted = 0 LIMIT 1'
        );
        $conn->bind($stmt, 'i', [$userId]);
        $row = $conn->fetchOne($stmt);
        return $row === null ? null : (string) $row['user_name'];
    }
}
