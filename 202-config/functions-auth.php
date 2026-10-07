<?php

declare(strict_types=1);

// Loaded directly (not via autoload) because some legacy entry points include
// this file before the Composer autoloader is registered.
require_once __DIR__ . '/License/ClickServerKeyValidator.php';
require_once __DIR__ . '/License/ShellAccessCache.php';

/**
 * Password helper functions live here to avoid bootstrap order issues.
 * They support both legacy salted MD5 hashes and modern password_hash()-based hashes.
 */
if (!function_exists('hash_user_pass')) {
    function hash_user_pass(string $password): string
    {
        // @phpstan-ignore-next-line centralized password hashing helper
        return password_hash($password, PASSWORD_DEFAULT);
    }
}

if (!function_exists('verify_user_pass')) {
    /**
     * @return array{valid: bool, needsRehash: bool}
     */
    function verify_user_pass(string $password, string $storedHash): array
    {
        $storedHash = trim($storedHash);
        if ($storedHash === '') {
            return ['valid' => false, 'needsRehash' => false];
        }

        $hashInfo = password_get_info($storedHash);
        if (($hashInfo['algo'] ?? 0) !== 0) {
            $valid = password_verify($password, $storedHash);
            return [
                'valid' => $valid,
                'needsRehash' => $valid && password_needs_rehash($storedHash, PASSWORD_DEFAULT),
            ];
        }

        $legacyHash = function_exists('salt_user_pass') ? salt_user_pass($password) : md5($password);
        if (hash_equals((string) $legacyHash, $storedHash)) {
            return ['valid' => true, 'needsRehash' => true];
        }

        if (hash_equals(md5($password), $storedHash)) {
            return ['valid' => true, 'needsRehash' => true];
        }

        return ['valid' => false, 'needsRehash' => false];
    }
}

//error_reporting(E_ALL);
class AUTH
{
    public const LOGOUT_DAYS = 14;

    // Brute-force throttle: once failed attempts within RATE_LIMIT_WINDOW seconds
    // exceed these counts, further attempts are blocked until older failures age
    // out of the window. The per-account limit protects a targeted user; the
    // higher per-IP limit is a backstop that still tolerates shared NAT/proxy IPs.
    public const RATE_LIMIT_WINDOW = 900;       // 15 minutes
    public const RATE_LIMIT_MAX_PER_USER = 10;
    public const RATE_LIMIT_MAX_PER_IP = 50;

    // Step-up protection: wrong current-password attempts allowed within one
    // session before the change-password flow tears the session down. Guards
    // against someone holding a session they shouldn't (shared machine, ridden
    // cookie) guessing the password toward a full account takeover.
    public const MAX_PASSWORD_REAUTH_FAILS = 5;

    private const string LOGIN_SELECT = 'SELECT u.user_id, u.user_name, u.user_pass, u.user_api_key, u.user_stats202_app_key, u.user_timezone, u.user_mods_lb, u.install_hash, u.p202_customer_api_key, up.user_id AS pref_user_id, up.user_slack_incoming_webhook FROM 202_users u LEFT JOIN 202_users_pref up ON up.user_id = u.user_id WHERE u.user_name = ? AND u.user_deleted != 1 AND u.user_active = 1 LIMIT 1';
    private static bool $passwordColumnChecked = false;
    private static bool $sessionHeartbeatRefreshed = false;

    private static function updateSession(array $values, bool $regenerateId = false): void
    {
        $writer = static function () use ($values, $regenerateId): void {
            if ($regenerateId && session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }

            foreach ($values as $key => $value) {
                $_SESSION[$key] = $value;
            }
        };

        if (function_exists('withWritableSession')) {
            withWritableSession($writer);
            return;
        }

        $writer();
    }

    private static function writeSessionValue(string $key, $value): void
    {
        self::updateSession([$key => $value]);
    }

    public static function logged_in()
    {
        $session_time_passed = isset($_SESSION['session_time']) ? time() - $_SESSION['session_time'] : PHP_INT_MAX;
        if (isset($_SESSION['user_name']) and isset($_SESSION['user_id']) and isset($_SESSION['session_fingerprint']) and hash_equals(self::session_fingerprint(), (string) $_SESSION['session_fingerprint']) and ($session_time_passed < 50000)) {
            if (!self::$sessionHeartbeatRefreshed) {
                self::writeSessionValue('session_time', time());
                self::$sessionHeartbeatRefreshed = true;
            }
            return true;
        } else {
            return false;
        }
    }

    public static function authenticate(string $username, string $password, \mysqli $db): array
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            return ['success' => false, 'error' => 'missing_credentials'];
        }
        //die('starting authentication...');
        $stmt = $db->prepare(self::LOGIN_SELECT);
       // die('prepared statement...');
        if (!$stmt) {
            throw new \RuntimeException('Unable to prepare login query: ' . $db->error);
        }
       // die('prepared statement...');
        self::bind($stmt, 's', $username);
        self::execute($stmt, 'Unable to execute login query');
        $user_row = self::resultOf($stmt, 'Unable to read the login query')->fetch_assoc();
        $stmt->close();
       // die('done fetching user row...');
        if (!$user_row) {
            return ['success' => false, 'error' => 'invalid_credentials', 'user' => null];
        }

        $verification = verify_user_pass($password, (string) ($user_row['user_pass'] ?? ''));
        if ($verification['valid'] === false) {
            return ['success' => false, 'error' => 'invalid_credentials', 'user' => $user_row];
        }

        if ($verification['needsRehash'] === true) {
           // die('upgrading password hash...');
            self::upgrade_user_password($db, (int) $user_row['user_id'], $password);
        }
      //  die('authentication successful...');
        return [
            'success' => true,
            'user' => $user_row,
            'error' => null,
        ];
    }

    private static function upgrade_user_password(\mysqli $db, int $user_id, string $password): void
    {
        self::ensure_password_column_capacity($db);
        $new_hash = hash_user_pass($password);
        $stmt = $db->prepare('UPDATE 202_users SET user_pass = ? WHERE user_id = ?');
        if (!$stmt) {
            throw new \RuntimeException('Unable to prepare password upgrade query: ' . $db->error);
        }
        self::bind($stmt, 'si', $new_hash, $user_id);
        self::execute($stmt, 'Unable to execute password upgrade query');
        $stmt->close();
    }

    private static function ensure_password_column_capacity(\mysqli $db): void
    {
        if (self::$passwordColumnChecked) {
            return;
        }

        $result = $db->query("SHOW COLUMNS FROM 202_users LIKE 'user_pass'");
        if ($result) {
            $column = $result->fetch_assoc();
            $result->close();
            if ($column && isset($column['Type'])) {
                $type = strtolower((string) $column['Type']);
                if (preg_match('/\\((\\d+)\\)/', $type, $matches)) {
                    $length = (int) $matches[1];
                    if ($length < 60) {
                        $alter = $db->query('ALTER TABLE 202_users MODIFY user_pass VARCHAR(255) NOT NULL');
                        if ($alter === false && function_exists('prosper_log')) {
                            prosper_log('login', 'Failed to expand user_pass column: ' . $db->error);
                        }
                    }
                }
            }
        }

        self::$passwordColumnChecked = true;
    }

    private static function determineAccountOwnerId(array $user_row): int
    {
        $userId = (int) ($user_row['user_id'] ?? 0);
        $installHash = trim((string) ($user_row['install_hash'] ?? ''));
        $existingKey = trim((string) ($user_row['p202_customer_api_key'] ?? ''));

        if ($existingKey !== '' || $installHash === '') {
            return $userId;
        }

        $database = DB::getInstance();
        $db = $database->getConnection();
        $stmt = $db->prepare('SELECT user_id FROM 202_users WHERE install_hash = ? AND user_deleted != 1 AND user_active = 1 AND p202_customer_api_key IS NOT NULL AND p202_customer_api_key != "" ORDER BY user_id ASC LIMIT 1');
        if (!$stmt) {
            return $userId;
        }

        self::bind($stmt, 's', $installHash);
        self::execute($stmt, 'Unable to execute API owner lookup query');
        $ownerRow = self::resultOf($stmt, 'Unable to read the API owner lookup')->fetch_assoc();
        $stmt->close();

        if ($ownerRow && isset($ownerRow['user_id'])) {
            return (int) $ownerRow['user_id'];
        }

        return $userId;
    }

    private static function lookupApiKeyForUser(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }

        $user_sql = "SELECT user_pref_ad_settings, p202_customer_api_key FROM 202_users_pref LEFT JOIN 202_users ON (202_users_pref.user_id = 202_users.user_id) WHERE 202_users_pref.user_id='" . $userId . "'";
        $user_result = _mysqli_query($user_sql);
        if ($user_result) {
            $user_row = $user_result->fetch_assoc();
            return trim((string) ($user_row['p202_customer_api_key'] ?? ''));
        }

        return '';
    }

    public static function begin_user_session(array $user_row): void
    {
        $writer = static function () use ($user_row): void {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }

            $_SESSION['session_fingerprint'] = self::session_fingerprint();
            $_SESSION['session_time'] = time();
            $_SESSION['user_name'] = $user_row['user_name'];
            $_SESSION['user_id'] = (int) $user_row['user_id'];
            $_SESSION['user_own_id'] = (int) $user_row['user_id'];
            $_SESSION['user_api_key'] = $user_row['user_api_key'] ?? null;
            $_SESSION['user_stats202_app_key'] = $user_row['user_stats202_app_key'] ?? null;
            $_SESSION['user_timezone'] = $user_row['user_timezone'] ?? 'UTC';
            $_SESSION['user_mods_lb'] = $user_row['user_mods_lb'] ?? 0;
            $_SESSION['account_owner_id'] = self::determineAccountOwnerId($user_row);
        };

        if (function_exists('withWritableSession')) {
            withWritableSession($writer);
        } else {
            $writer();
        }

        self::$sessionHeartbeatRefreshed = true;
    }

    /**
     * True if the request has an authenticated user, restoring a session from a
     * valid remember-me cookie if needed. This is the single source of truth for
     * "is this request logged in" so callers (require_user, the messaging AJAX
     * gate) don't each re-implement the login + remember-me sequence.
     */
    public static function logged_in_with_remember(): bool
    {
        if (AUTH::logged_in()) {
            return true;
        }
        AUTH::remember_me_on_logged_out();
        return AUTH::logged_in();
    }

    public static function require_user($auth_type = '', bool $requireLicense = true)
    {
        if (AUTH::logged_in_with_remember() == false) {
            if ($auth_type == "toolbar") {
                self::writeSessionValue('toolbar', 'true');
            }

            die(include_once(realpath(__DIR__ . '/../') . '/202-access-denied.php'));
//go up one level
        }
        AUTH::set_timezone($_SESSION['user_timezone']);
        if ($requireLicense) {
            AUTH::require_valid_api_key();
        }
    }

    /**
     * Refuse the request unless the signed-in user's role has every one of
     * $permissions: 403 naming the first one missing. For the endpoints a
     * page posts to: the page gates its form (access_to_setup_section, a
     * remove_* button), and an endpoint that asks for nothing answers any
     * signed-in role that posts to it directly (CLAUDE.md #5).
     * SetupAjaxRequiresPermissionTest holds the Setup pages' endpoints to it.
     */
    public static function require_permissions(string ...$permissions): void
    {
        global $userObj;
        foreach ($permissions as $permission) {
            if (!$userObj instanceof \User || !$userObj->hasPermission($permission)) {
                http_response_code(403);
                die("This account's role does not have the '" . htmlspecialchars($permission, ENT_QUOTES, 'UTF-8') . "' permission.");
            }
        }
    }

    public static function require_valid_api_key()
    {
        $candidateIds = array_unique(array_filter([
            (int) ($_SESSION['account_owner_id'] ?? 0),
            (int) ($_SESSION['user_id'] ?? 0),
            (int) ($_SESSION['user_own_id'] ?? 0),
        ]));

        $user_api_key = '';
        foreach ($candidateIds as $candidateId) {
            $user_api_key = self::lookupApiKeyForUser($candidateId);
            if ($user_api_key !== '') {
                break;
            }
        }

        if (self::is_valid_api_key($user_api_key) == false || $user_api_key == '') {
            header('location: ' . get_absolute_url() . 'api-key-required.php');
            die();
        }
    }


    //this checks if this api key is valid
    public static function is_valid_api_key($user_api_key)
    {

        //only check once per session speed up ui
        if (isset($_SESSION['valid_key']) && $_SESSION['valid_key'] == true) {
            return true;
        }

        $keyIsValid = \Prosper202\License\ClickServerKeyValidator::validate($user_api_key);

        // On network failure, don't punish the user — assume valid until next check
        if ($keyIsValid === null) {
            return true;
        }

        if ($keyIsValid) {
        //update the api key
            global $db;
            $escaped_api_key = $db->real_escape_string((string) $user_api_key);
            $user_sql = "	UPDATE 	202_users
							SET		p202_customer_api_key='" . $escaped_api_key . "'
							WHERE 	user_id='" . (int) $_SESSION['user_id'] . "'";
            _mysqli_query($user_sql);
            self::writeSessionValue('valid_key', true);
            // Warm the CLI shell license cache so p202 shell works without its own round-trip.
            \Prosper202\License\ShellAccessCache::write($user_api_key, true);
            return true;
        } else {
            // Deny the CLI shell immediately when the key is no longer valid.
            \Prosper202\License\ShellAccessCache::write($user_api_key, false);
            return false;
        }
    }


    /** @var array<int, string> the account zone read this request, by user id */
    private static array $accountTimezones = [];

    /**
     * Set the request's zone: the signed-in user's, read from their account
     * (202_users.user_timezone), else $user_timezone.
     *
     * The session keeps the zone it had at sign-in, and every report page
     * counts its days from this: when the account's zone changed elsewhere
     * (Personal Settings in another session, `p202 user update`, a
     * colleague's User Management), "today" on every page stayed the old
     * zone's today until the user signed in again, while GET /reports/*
     * (api/v3 AccountTimezone) used the new one. The account's row is read
     * once a request and the session's copy refreshed with it; a zone that
     * is empty or that PHP does not know is UTC, as the API reads it. A read
     * that fails throws (accountTimezone()): kept, the session's zone was a
     * guess every report page counted its days in with nothing to say so.
     */
    public static function set_timezone($user_timezone)
    {
        if (isset($_SESSION['user_timezone'])) {
            $user_timezone = $_SESSION['user_timezone'];
            $userId = (int) ($_SESSION['user_id'] ?? 0);
            $accountZone = $userId > 0 ? self::accountTimezone($userId) : null;
            if ($accountZone !== null) {
                $user_timezone = $accountZone;
                $_SESSION['user_timezone'] = $accountZone;
            }
        }

        date_default_timezone_set($user_timezone);
    }

    /**
     * The account's zone: UTC when it is unset or not a zone PHP knows, null
     * when there is no account row (the session outlived its user, and keeps
     * its own zone) or no database in this process at all.
     *
     * A read that fails throws, naming the account. It answered null, which
     * set_timezone() reads as "keep the session's zone", for a failed
     * prepare, execute or get_result() and for any exception at all: one
     * transient database error made a report page count "today" in the zone
     * captured at sign-in, which may no longer be the account's, with
     * nothing in a log (CLAUDE.md #1, #11). The API's AccountTimezone
     * refuses the same failure; a page whose days cannot be placed now
     * fails the same way.
     */
    private static function accountTimezone(int $userId): ?string
    {
        if (isset(self::$accountTimezones[$userId])) {
            return self::$accountTimezones[$userId];
        }
        if (!class_exists('DB', false)) {
            return null;
        }
        $failed = 'Unable to read the time zone of account ' . $userId;
        $db = DB::getInstance()->getConnection();
        if (!$db instanceof \mysqli) {
            throw new \RuntimeException($failed . ': no database connection');
        }
        try {
            $stmt = $db->prepare('SELECT user_timezone FROM 202_users WHERE user_id = ? LIMIT 1');
            if ($stmt === false) {
                throw new \RuntimeException($failed . ': ' . $db->error);
            }
            self::bind($stmt, 'i', $userId);
            self::execute($stmt, $failed);
            $row = self::resultOf($stmt, $failed)->fetch_assoc();
            $stmt->close();
        } catch (\mysqli_sql_exception $e) {
            // Strict reporting throws MySQL's sentence; name whose read it was.
            throw new \RuntimeException($failed . ': ' . $e->getMessage(), 0, $e);
        }
        if ($row === null) {
            return null;
        }
        $zone = trim((string) ($row['user_timezone'] ?? ''));
        if ($zone === '' || !in_array($zone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            $zone = 'UTC';
        }

        return self::$accountTimezones[$userId] = $zone;
    }

    public static function remember_me_on_logged_out()
    {
        if (isset($_COOKIE['remember_me']) && AUTH::logged_in() == false) {
            $parts = explode('-', (string) $_COOKIE['remember_me']);
            if (count($parts) !== 3) {
                return false;
            }
            [$user_id, $auth_key, $hash] = $parts;
            if (!empty($user_id) && !empty($auth_key) && !empty($hash)) {
                $expected = hash_hmac('sha256', $user_id . '-' . $auth_key, (string) self::get_user_secret_key($user_id));
                if (!hash_equals($expected, (string) $hash)) {
                    return false;
                }

                $database = DB::getInstance();
                $db = $database->getConnection();
                $mysql = [
                    'user_id' => $db->real_escape_string($user_id),
                    'auth_key' => $db->real_escape_string($auth_key)
                ];
                $sql = '
					SELECT
						*
                  	FROM
                  		202_auth_keys 2a, 202_users 2u
                 	WHERE
                 	    2a.expires > UNIX_TIMESTAMP()
                 	AND
                 		2a.user_id = "' . $mysql['user_id'] . '"
                  	AND
                  		2a.auth_key = "' . $mysql['auth_key'] . '"
                  	AND
                  	    2u.user_id = 2a.user_id
                    AND
                        2u.user_deleted != 1
					AND
						2u.user_active = 1
                	LIMIT 1';
                $user_result = _mysqli_query($sql);
                // _mysqli_query() returns false on a query failure; guard before
                // fetch_assoc() so a transient DB error during remember-me restore
                // doesn't fatal on false->fetch_assoc().
                $user_row = ($user_result instanceof mysqli_result) ? $user_result->fetch_assoc() : null;
                if ($user_row) {
                    self::begin_user_session($user_row);
                    self::writeSessionValue('user_cirrus_link', $user_row['user_api_key'] ?? null);
                    return true;
                }
            }
        }

        return false;
    }

    public static function generate_random_string($length)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $index = (int) self::dev_urand(0, $charactersLength - 1);
            $randomString .= $characters[$index];
        }
        return $randomString;
    }

    public static function get_user_secret_key($user_id)
    {
        $database = DB::getInstance();
        $db = $database->getConnection();
        $mysql['user_id'] = $db->escape_string((string) $user_id);
        $sql = '
			SELECT
				secret_key
			FROM
				202_users
			WHERE
				user_id = "' . $mysql['user_id'] . '"
		';
        $user_result = _mysqli_query($sql);
        // Guard against a false return from a failed query before fetch_assoc().
        $user_row = ($user_result instanceof mysqli_result) ? $user_result->fetch_assoc() : null;
        if (empty($user_row['secret_key'])) {
            $mysql['secret_key'] = self::generate_random_string(48);
            $sql = '
				UPDATE
					202_users
				SET
					secret_key = "' . $mysql['secret_key'] . '"
				WHERE
					user_id = "' . $mysql['user_id'] . '"
			';
            _mysqli_query($sql);
            return $mysql['secret_key'];
        } else {
            return $user_row['secret_key'];
        }
    }

    public static function remember_me_on_auth()
    {
        $auth_key = self::generate_random_string(48);
        $database = DB::getInstance();
        $db = $database->getConnection();
// Clean up expired auth keys
        $cleanup_sql = 'DELETE FROM 202_auth_keys WHERE expires < UNIX_TIMESTAMP()';
        _mysqli_query($cleanup_sql);
        $mysql = [
            'user_id' => $db->real_escape_string((string)$_SESSION['user_own_id']),
            'auth_key' => $db->real_escape_string($auth_key)
        ];
        $sql = 'INSERT INTO
					202_auth_keys
				SET
					auth_key = "' . $mysql['auth_key'] . '",
					user_id = "' . $mysql['user_id'] . '",
					expires = "' . (time() + (self::LOGOUT_DAYS * 24 * 60 * 60)) . '"
				';
        _mysqli_query($sql);
        $hash = hash_hmac('sha256', $_SESSION['user_own_id'] . '-' . $auth_key, (string) self::get_user_secret_key($_SESSION['user_own_id']));
        $expire = strtotime('+' . self::LOGOUT_DAYS . ' days');
        // Use the canonical HTTPS check so the cookie's Secure flag isn't dropped
        // behind proxies that signal TLS via X-Forwarded-SSL/Port or REQUEST_SCHEME.
        $secure = function_exists('getSecureStatus') ? getSecureStatus() : (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off');
        setcookie('remember_me', $_SESSION['user_own_id'] . '-' . $auth_key . '-' . $hash, [
            'expires' => $expire,
            'path' => '/',
            'domain' => self::cookie_domain(),
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * The remember_me cookie's Domain: the one rule every cookie with a
     * Domain follows (CookieDomain — the request host without its port, none
     * for an IP literal, localhost or anything that is not a host name). The
     * copy that lived here stripped a port with /:\d+$/, so `[::1]:8080`
     * became the Domain `[::1]`.
     */
    public static function cookie_domain(): string
    {
        return \Prosper202\Http\CookieDomain::fromServer($_SERVER);
    }

    public static function delete_old_auth_hash()
    {
        $sql = 'DELETE FROM 202_auth_keys WHERE expires < UNIX_TIMESTAMP()';
        _mysqli_query($sql);
    }

    /**
     * Build a sanitized, serialized snapshot of the request for the login audit
     * log. The previous code stored serialize($_SERVER) and serialize($_SESSION)
     * verbatim, which persisted live secrets at rest on every login attempt —
     * the request's Cookie header (containing the PHPSESSID and remember_me
     * token), Authorization header, and the whole session (API keys, CSRF
     * token). None of that is ever displayed; only a few forensic fields are.
     * Keep just those safe fields and drop everything sensitive.
     */
    public static function login_audit_snapshot(): string
    {
        $server = $_SERVER ?? [];
        $safe = [];
        foreach (['REQUEST_METHOD', 'REQUEST_URI', 'SERVER_NAME', 'HTTP_HOST', 'HTTP_USER_AGENT', 'HTTP_REFERER', 'REMOTE_ADDR'] as $key) {
            if (isset($server[$key]) && is_scalar($server[$key])) {
                $safe[$key] = (string) $server[$key];
            }
        }

        return serialize($safe);
    }

    /**
     * Derive the session fingerprint. Binds the session to the client's
     * User-Agent on top of the session id (HMAC keyed on the id, so it still
     * rotates with session_regenerate_id()). The previous value hashed only the
     * session id, which an attacker who stole the cookie already possessed — so
     * it provided no protection. Binding to the User-Agent means a leaked session
     * id alone (e.g. from a log) no longer validates unless the UA is replayed.
     */
    public static function session_fingerprint(): string
    {
        $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        return hash_hmac('sha256', 'session_fingerprint|' . $userAgent, (string) session_id());
    }

    /**
     * The visitor's address, validated (VisitorIp: the forwarding headers
     * the click path reads, the leftmost hop, REMOTE_ADDR when that is not an
     * address), or '0.0.0.0' when the request carries none — so the throttle
     * key and the audit log only ever see a real IP that fits their column.
     * It is the address the client claims; see VisitorIp for what that may
     * and may not decide.
     */
    public static function client_ip(): string
    {
        $ip = \Prosper202\Http\VisitorIp::fromServer($_SERVER);

        return $ip !== '' ? $ip : '0.0.0.0';
    }

    /**
     * Constant-time validation of the anti-CSRF token. Forms embed
     * $_SESSION['token'] (seeded in connect.php) as a hidden field; a cross-site
     * attacker cannot read it, so a forged POST fails this check.
     */
    public static function check_csrf_token(): bool
    {
        return self::csrf_token_matches($_POST['token'] ?? null);
    }

    /**
     * Whether $submitted is this session's anti-CSRF token: the app's one
     * comparison, for a token that arrives other than as $_POST['token'] (a
     * Setup delete link's `?token=`, a form whose field is named otherwise).
     *
     * Fails closed. hash_equals('', '') is true, so the inline copies this
     * replaced — `hash_equals((string) ($_SESSION['token'] ?? ''), (string)
     * ($_POST['token'] ?? ''))` — let a token-less request through whenever
     * the session held an empty token: connect.php then reseeded only a
     * token that was not set, not one that was set to ''. Measured live: with
     * the stored token blanked, a Setup add, a Setup delete link,
     * set_user_prefs, charts and the redirector's rule save all wrote on a
     * request that carried no token. A token that is not usable on either
     * side (csrf_token_usable()) — never seeded, blanked, an array posted as
     * token[] — matches nothing.
     */
    public static function csrf_token_matches(mixed $submitted): bool
    {
        $sessionToken = $_SESSION['token'] ?? null;
        if (!self::csrf_token_usable($sessionToken) || !self::csrf_token_usable($submitted)) {
            return false;
        }
        return hash_equals($sessionToken, $submitted);
    }

    /**
     * Whether a value can be an anti-CSRF token: a non-empty string. The one
     * test of it: csrf_token_matches() refuses a token that fails it on
     * either side, and connect.php seeds a new session token in place of one
     * that fails it, so what the guard refuses and what the seed replaces
     * cannot drift apart (a session the guard refuses and the seed keeps is
     * refused on every form until sign-out).
     *
     * @phpstan-assert-if-true non-empty-string $token
     */
    public static function csrf_token_usable(mixed $token): bool
    {
        return is_string($token) && $token !== '';
    }

    /**
     * Brute-force throttle. Returns true when recent failed login attempts for
     * this IP or username exceed the configured thresholds within the rolling
     * window, in which case the caller should reject the attempt without
     * checking credentials.
     */
    public static function is_rate_limited(\mysqli $db, string $username, string $ip): bool
    {
        $since = time() - self::RATE_LIMIT_WINDOW;

        $ip = trim($ip);
        if ($ip !== '' && self::count_recent_failures($db, 'ip_address', $ip, $since) >= self::RATE_LIMIT_MAX_PER_IP) {
            return true;
        }

        $username = trim($username);
        if ($username !== '' && self::count_recent_failures($db, 'user_name', $username, $since) >= self::RATE_LIMIT_MAX_PER_USER) {
            return true;
        }

        return false;
    }

    private static function count_recent_failures(\mysqli $db, string $column, string $value, int $since): int
    {
        // $column is a fixed internal literal ('ip_address' | 'user_name'), never
        // request input, so it is safe to interpolate; $value/$since are bound.
        $stmt = $db->prepare(
            'SELECT COUNT(*) AS failures FROM 202_users_log '
            . 'WHERE login_success = 0 AND ' . $column . ' = ? AND login_time >= ?'
        );
        if (!$stmt) {
            throw new \RuntimeException('Unable to prepare login throttle query: ' . $db->error);
        }
        self::bind($stmt, 'si', $value, $since);
        self::execute($stmt, 'Unable to execute login throttle query');
        // A failed read is not "no failures": it throws, and the login page
        // logs it ("Rate limit check failed") before going on, as it does for
        // a query that fails to prepare or run.
        $row = self::resultOf($stmt, 'Unable to read the login throttle query')->fetch_assoc();
        $stmt->close();

        return $row ? (int) $row['failures'] : 0;
    }

    public static function dev_urand($min = 0, $max = 0x7FFFFFFF)
    {
        if (function_exists('random_bytes')) {
            $diff = $max - $min;
            if ($diff < 0 || $diff > 0x7FFFFFFF) {
                throw new \RuntimeException("Bad range");
            }
            $bytes = random_bytes(4);
            if (strlen($bytes) != 4) {
                throw new \RuntimeException("Unable to get 4 bytes");
            }
            $ary = unpack("Nint", $bytes);
            $val = $ary['int'] & 0x7FFFFFFF;
// 32-bit safe
            $fp = (float) $val / 2147483647.0;
// convert to [0,1]
            return (int) round($fp * $diff) + $min;
        }

        // fallback to less secure mt_rand in case user doesn't have random_bytes
        return (int) mt_rand($min, $max);
    }

    private static function bind(\mysqli_stmt $stmt, string $types, mixed ...$values): void
    {
        // @phpstan-ignore-next-line -- AUTH::bind() is this class's own checked binding wrapper (no Database\Connection instance is in scope; static context). Return value is checked and throws on failure.
        if (!$stmt->bind_param($types, ...$values)) {
            throw new \RuntimeException('Unable to bind statement parameters');
        }
    }

    /**
     * The statement's result set. A false get_result() is a failed read, and
     * read as an empty result it was "no such user" at sign-in, "no failed
     * attempts" to the login throttle, and "no owner" to the API key lookup.
     */
    private static function resultOf(\mysqli_stmt $stmt, string $message): \mysqli_result
    {
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new \RuntimeException($message);
        }

        return $result;
    }

    private static function execute(\mysqli_stmt $stmt, string $message): void
    {
        // @phpstan-ignore-next-line -- AUTH::execute() is this class's own checked-execution wrapper (no Database\Connection instance is in scope; static context). Return value is checked and throws on failure.
        if (!$stmt->execute()) {
            throw new \RuntimeException($message);
        }
    }
}
