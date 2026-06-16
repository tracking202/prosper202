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

    private const string LOGIN_SELECT = 'SELECT u.user_id, u.user_name, u.user_pass, u.user_api_key, u.user_stats202_app_key, u.user_timezone, u.user_mods_lb, u.install_hash, u.p202_customer_api_key, up.user_id AS pref_user_id FROM 202_users u LEFT JOIN 202_users_pref up ON up.user_id = u.user_id WHERE u.user_name = ? AND u.user_deleted != 1 AND u.user_active = 1 LIMIT 1';
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
        $result = $stmt->get_result();
        $user_row = $result ? $result->fetch_assoc() : null;
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
        $result = $stmt->get_result();
        $ownerRow = $result ? $result->fetch_assoc() : null;
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

    public static function require_user($auth_type = '')
    {
        $loggedIn = AUTH::logged_in();
        if ($loggedIn == false) {
            AUTH::remember_me_on_logged_out();
            $loggedIn = AUTH::logged_in();
        }

        if ($loggedIn == false) {
            if ($auth_type == "toolbar") {
                self::writeSessionValue('toolbar', 'true');
            }

            die(include_once(realpath(__DIR__ . '/../') . '/202-access-denied.php'));
//go up one level
        }
        AUTH::set_timezone($_SESSION['user_timezone']);
        AUTH::require_valid_api_key();
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
            $user_sql = "	UPDATE 	202_users
							SET		p202_customer_api_key='" . $user_api_key . "'
							WHERE 	user_id='" . $_SESSION['user_id'] . "'";
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


    public static function set_timezone($user_timezone)
    {
        if (isset($_SESSION['user_timezone'])) {
            $user_timezone = $_SESSION['user_timezone'];
        }

        date_default_timezone_set($user_timezone);
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
                $user_row = $user_result->fetch_assoc();
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
        $user_row = $user_result->fetch_assoc();
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
        $secure = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        setcookie('remember_me', $_SESSION['user_own_id'] . '-' . $auth_key . '-' . $hash, [
            'expires' => $expire,
            'path' => '/',
            'domain' => self::cookie_domain(),
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function cookie_domain(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        // Strip port number if present (e.g. "example.com:8080" → "example.com")
        $domain = strtolower((string) preg_replace('/:\d+$/', '', (string) $host));
        // Don't set a cookie domain for localhost or IP addresses — browsers reject it
        if ($domain === 'localhost' || filter_var($domain, FILTER_VALIDATE_IP)) {
            return '';
        }
        return $domain;
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
     * Constant-time validation of the anti-CSRF token. Forms embed
     * $_SESSION['token'] (seeded in connect.php) as a hidden field; a cross-site
     * attacker cannot read it, so a forged POST fails this check.
     */
    public static function check_csrf_token(): bool
    {
        return hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_POST['token'] ?? ''));
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
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
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

    private static function execute(\mysqli_stmt $stmt, string $message): void
    {
        // @phpstan-ignore-next-line -- AUTH::execute() is this class's own checked-execution wrapper (no Database\Connection instance is in scope; static context). Return value is checked and throws on failure.
        if (!$stmt->execute()) {
            throw new \RuntimeException($message);
        }
    }
}
