<?php

declare(strict_types=1);

// Loaded directly (not via autoload) because some legacy entry points include
// this file before the Composer autoloader is registered.
require_once __DIR__ . '/License/ClickServerKeyValidator.php';
require_once __DIR__ . '/License/ShellAccessCache.php';
require_once __DIR__ . '/License/CliAccessCache.php';

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

    private const string LOGIN_SELECT = 'SELECT u.user_id, u.user_name, u.user_pass, u.user_api_key, u.user_stats202_app_key, u.user_timezone, u.user_mods_lb, u.install_hash, u.p202_customer_api_key, u.user_session_generation, up.user_id AS pref_user_id, up.user_slack_incoming_webhook FROM 202_users u LEFT JOIN 202_users_pref up ON up.user_id = u.user_id WHERE u.user_name = ? AND u.user_deleted = 0 AND u.user_active = 1 LIMIT 1';
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

    /** Whether this request has tried its remember-me cookie (remember_me_on_logged_out()). */
    private static bool $rememberMeTried = false;

    /**
     * The session's own claim to be signed in: a user, this browser's
     * fingerprint and a request in the last 50000 seconds. Whether that user
     * may still act is logged_in()'s question, which needs the user's row.
     */
    private static function sessionClaimsSignIn(): bool
    {
        $passed = isset($_SESSION['session_time']) ? time() - (int) $_SESSION['session_time'] : PHP_INT_MAX;

        return isset($_SESSION['user_name'], $_SESSION['user_id'], $_SESSION['session_fingerprint'])
            && hash_equals(self::session_fingerprint(), (string) $_SESSION['session_fingerprint'])
            && $passed < 50000;
    }

    /**
     * Whether this request is signed in: the session's own fields (user,
     * fingerprint, under 50000 seconds since its last request), and the
     * user's row, read this request, active and not deleted
     * (sessionUserStands()). The row is read because nothing else ends a
     * session: its time is refreshed on every request, so a user switched off
     * or removed kept every page for as long as they kept using the browser.
     */
    public static function logged_in()
    {
        if (self::sessionClaimsSignIn()) {
            // connect.php's User, when it read this session's user.
            $read = $GLOBALS['userObj'] ?? null;
            if (!self::sessionUserStands($read instanceof \User ? $read : null)) {
                return false;
            }
            if (!self::$sessionHeartbeatRefreshed) {
                self::writeSessionValue('session_time', time());
                self::$sessionHeartbeatRefreshed = true;
            }
            return true;
        } else {
            return false;
        }
    }

    /**
     * connect.php, once it has read the session's user (new User()): a
     * session whose user may no longer act, or which began before the user's
     * password changed (sessionUserStands()), is ended there, before the page
     * reads anything from it, so no page shows or acts on it whether or not
     * it asks for a sign-in. Nothing is read here: where connect.php read no
     * row (the install, key and upgrade pages, which sign nobody in, or a
     * database with no 202_users yet), logged_in() reads it for itself.
     */
    public static function endSessionIfItsUserCannotAct(?\User $user): void
    {
        if (!isset($_SESSION['user_id']) && !isset($_SESSION['user_own_id'])) {
            return;
        }
        $userId = self::sessionUserId();
        if ($userId !== null && $user?->standingOf($userId) === null) {
            return;
        }
        self::sessionUserStands($user);
    }

    /**
     * Whether the session's user may act (UserStanding), and the session began
     * at the user's current generation (SessionGeneration), from the row $read
     * (connect.php's User) holds for this request or, when it holds none for
     * this user, from the database now; never from an answer kept from an
     * earlier request, which would outlive the switch-off it is there to see.
     * A session whose user may not act is ended, and so is one from before a
     * new password, a switch-off or a removal. A read that fails throws: it is
     * neither "may act" nor "signed out".
     */
    private static function sessionUserStands(?\User $read): bool
    {
        $userId = self::sessionUserId();
        if ($userId === null) {
            self::endSession(null);
            return false;
        }
        $standing = $read?->standingOf($userId);
        $generation = $read?->generationOf($userId);
        if ($standing === null) {
            [$standing, $generation] = \Prosper202\User\UserStanding::readWithGeneration(
                DB::getInstance()->getConnection(),
                $userId
            );
        }
        if (!$standing->canAct()) {
            self::endSession($userId);
            return false;
        }
        // The change that counted the generation up revoked the user's keys
        // in its own transaction; keys made since are a later sign-in's.
        if ($generation !== null && self::sessionGeneration() !== $generation) {
            self::endSession(null);
            return false;
        }

        return true;
    }

    /**
     * The generation this session began at; 0 for a session from before
     * 1.9.79, which holds none (every user is at 0 until a change), and -1,
     * which no row holds, for a value no sign-in wrote.
     */
    private static function sessionGeneration(): int
    {
        $held = $_SESSION['user_session_generation'] ?? 0;

        return is_int($held) && $held >= 0 ? $held : -1;
    }

    /**
     * This browser changed its own password (Personal settings): it keeps its
     * session by taking the generation the change counted up to, which every
     * other session of the user does not hold. After the commit, in the same
     * request.
     */
    public static function keepSessionAfterPasswordChange(int $generation): void
    {
        $writer = static function () use ($generation): void {
            $_SESSION['user_session_generation'] = $generation;
        };
        if (function_exists('withWritableSession')) {
            withWritableSession($writer);
        } else {
            $writer();
        }
    }

    /**
     * The user the session is signed in as. begin_user_session() sets
     * user_id and user_own_id to the same id; a session where either is
     * missing, is not a positive whole number, or they differ is not one it
     * made, and names nobody.
     */
    private static function sessionUserId(): ?int
    {
        $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
        $ownId = filter_var($_SESSION['user_own_id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($userId) || $userId <= 0 || $ownId !== $userId) {
            return null;
        }

        return $userId;
    }

    /**
     * End this request's session, for a user who may no longer act, as the
     * sign-out does and more: the session's data and id are replaced by a
     * signed-out visitor's (a new id, and a new anti-CSRF token so a sign-in
     * form drawn by this request can be posted), the remember-me cookie is
     * cleared, and every remember-me key the user holds is revoked, so a
     * browser kept signed in does not sign back in when the user is switched
     * on again. $revokeFor is null for a session that names nobody.
     */
    private static function endSession(?int $revokeFor): void
    {
        $writer = static function (): void {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
                session_regenerate_id(true);
            }
            $_SESSION['token'] = self::mintCsrfToken();
        };
        if (function_exists('withWritableSession')) {
            withWritableSession($writer);
        } else {
            $writer();
        }

        self::forgetRememberMeCookie();

        if ($revokeFor !== null) {
            \Prosper202\User\RememberMeKeys::revokeAll(
                new \Prosper202\Database\Connection(DB::getInstance()->getConnection()),
                $revokeFor
            );
        }
    }

    /**
     * Sign this browser out (202-account/signout.php, and Personal settings
     * after too many wrong current passwords): the session destroyed, the
     * remember-me cookie cleared, and the stored key that cookie names
     * deleted, so a copy of the cookie signs nobody in. The sign-out kept the
     * key, and a copied cookie went on signing in for up to 14 days.
     *
     * Only this browser's key: the user's other remembered browsers are other
     * sign-ins, which this one did not end. Switching the user off ends them
     * all.
     *
     * The session and the cookie end first, whatever the delete does: a
     * delete that fails then throws, and the page answers with the database
     * error page rather than a sign-out that reads as complete.
     */
    public static function signOut(): void
    {
        $named = \Prosper202\User\RememberMeKeys::namedBy($_COOKIE['remember_me'] ?? null);
        $writer = static function (): void {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
        };
        if (function_exists('withWritableSession')) {
            withWritableSession($writer);
        } else {
            $writer();
        }
        self::forgetRememberMeCookie();

        if ($named !== null) {
            \Prosper202\User\RememberMeKeys::revokeOne(
                new \Prosper202\Database\Connection(DB::getInstance()->getConnection()),
                $named[0],
                $named[1]
            );
        }
    }

    /**
     * Clear this browser's remember_me cookie, for the rest of the request
     * too: at a sign-out, and once its key is deleted (a password change).
     */
    public static function forgetRememberMeCookie(): void
    {
        if (!isset($_COOKIE['remember_me'])) {
            return;
        }
        unset($_COOKIE['remember_me']);
        if (!headers_sent()) {
            setcookie('remember_me', '', [
                'expires' => 1,
                'path' => '/',
                'domain' => self::cookie_domain(),
                'secure' => function_exists('getSecureStatus')
                    ? getSecureStatus()
                    : (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off'),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    /** A new session's anti-CSRF token: 128 bits from the CSPRNG. */
    public static function mintCsrfToken(): string
    {
        return bin2hex(random_bytes(16));
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
            try {
                self::upgrade_user_password($db, (int) $user_row['user_id'], $password);
            } catch (\RuntimeException $e) {
                // The password was right: not a failure for the throttle.
                throw new \Prosper202\User\SignInNotCompleted(
                    'Storing the rehashed password failed: ' . $e->getMessage(),
                    0,
                    $e
                );
            }
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

        // A width that cannot be read, or widened, is not wide enough: the
        // pages' empty sql_mode cuts a longer hash without a word, and the
        // password then matches nothing. Thrown, authenticate() refuses the
        // sign-in (SignInNotCompleted).
        $sql = "SHOW COLUMNS FROM 202_users LIKE 'user_pass'";
        $result = $db->query($sql);
        if ($result === false) {
            throw self::queryFailed($db, 'Reading the width of 202_users.user_pass failed');
        }
        $column = p202FetchRow($result, $sql);
        $result->close();
        if ($column && isset($column['Type'])) {
            $type = strtolower((string) $column['Type']);
            if (preg_match('/\\((\\d+)\\)/', $type, $matches)) {
                $length = (int) $matches[1];
                if ($length < 60) {
                    if ($db->query('ALTER TABLE 202_users MODIFY user_pass VARCHAR(255) NOT NULL') === false) {
                        throw self::queryFailed($db, 'Widening 202_users.user_pass failed');
                    }
                }
            }
        }

        self::$passwordColumnChecked = true;
    }

    private static function queryFailed(\mysqli $db, string $what): \Prosper202\Database\Exceptions\QueryException
    {
        return new \Prosper202\Database\Exceptions\QueryException(
            $what . ': ' . p202FailedQueryMessage($db->errno, $db->error) . ' [errno ' . $db->errno . ']'
        );
    }

    /**
     * The account whose Prosper202 license key this user's session is held
     * to (require_valid_api_key()): the user's own when it has a key or no
     * install, else the install's first active user that has one.
     *
     * A lookup that fails throws, naming the user. A failed prepare answered
     * the user's own id -- the same value as "no user of this install has a
     * key" -- and begin_user_session() keeps the answer for the whole
     * session: one transient database error at sign-in held a sub-user's
     * every page to its own, empty key, and every page sent the session to
     * api-key-required.php for a license key the install already has
     * (CLAUDE.md #11: a lookup that cannot answer must not answer).
     */
    private static function determineAccountOwnerId(array $user_row): int
    {
        $userId = (int) ($user_row['user_id'] ?? 0);
        $installHash = trim((string) ($user_row['install_hash'] ?? ''));
        $existingKey = trim((string) ($user_row['p202_customer_api_key'] ?? ''));

        if ($existingKey !== '' || $installHash === '') {
            return $userId;
        }

        $failed = 'Unable to read whose license key user ' . $userId . "'s session is held to";
        $database = DB::getInstance();
        $db = $database->getConnection();
        try {
            $stmt = $db->prepare('SELECT user_id FROM 202_users WHERE install_hash = ? AND user_deleted != 1 AND user_active = 1 AND p202_customer_api_key IS NOT NULL AND p202_customer_api_key != "" ORDER BY user_id ASC LIMIT 1');
            if ($stmt === false) {
                throw new \RuntimeException($failed . ': ' . $db->error);
            }

            self::bind($stmt, 's', $installHash);
            self::execute($stmt, $failed);
            $ownerRow = self::resultOf($stmt, $failed)->fetch_assoc();
            $stmt->close();
        } catch (\mysqli_sql_exception $e) {
            // Strict reporting throws MySQL's sentence; name whose read it was.
            throw new \RuntimeException($failed . ': ' . $e->getMessage(), 0, $e);
        }

        if ($ownerRow && isset($ownerRow['user_id'])) {
            return (int) $ownerRow['user_id'];
        }

        return $userId;
    }

    /**
     * A user's Prosper202 license key, '' when it has none. A read that
     * fails throws: '' sent the session to api-key-required.php as though
     * the install had no key (CLAUDE.md #11).
     */
    private static function lookupApiKeyForUser(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }

        $failed = 'Unable to read the license key of user ' . $userId;
        $user_sql = "SELECT user_pref_ad_settings, p202_customer_api_key FROM 202_users_pref LEFT JOIN 202_users ON (202_users_pref.user_id = 202_users.user_id) WHERE 202_users_pref.user_id='" . $userId . "'";
        try {
            $user_result = _mysqli_query($user_sql);
        } catch (\mysqli_sql_exception $e) {
            throw new \RuntimeException($failed . ': ' . $e->getMessage(), 0, $e);
        }
        if (!$user_result instanceof \mysqli_result) {
            throw new \RuntimeException($failed);
        }
        $user_row = $user_result->fetch_assoc();

        return trim((string) ($user_row['p202_customer_api_key'] ?? ''));
    }

    public static function begin_user_session(array $user_row): void
    {
        // Read before the session is written: a lookup that throws leaves
        // the request signed out, rather than signed in without an owner.
        $accountOwnerId = self::determineAccountOwnerId($user_row);
        $writer = static function () use ($user_row, $accountOwnerId): void {
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
            $_SESSION['account_owner_id'] = $accountOwnerId;
            // The generation the user is at; a later change counts it up, and
            // connect.php ends a session still holding this one.
            $_SESSION['user_session_generation'] = (int) ($user_row['user_session_generation'] ?? 0);
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
            // A page's script (jQuery sends X-Requested-With; a browser marks
            // fetch() Sec-Fetch-Dest: empty) treats a failed status as a
            // failure; the sign-in page a redirect led to read as the answer,
            // and was drawn into the panel that asked.
            $byScript = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
                || strtolower((string) ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '')) === 'empty';
            if ($byScript) {
                http_response_code(401);
                header('Content-Type: text/plain; charset=utf-8');
                header('Cache-Control: no-store');
                die('You are signed out. Sign in again, then reload the page.');
            }
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
            // The page says which: a key refused, or one that could not be checked.
            $unconfirmed = self::$licenceUnconfirmed && $user_api_key !== '';
            $page = 'api-key-required.php' . ($unconfirmed ? '?licence=unconfirmed' : '');
            header('location: ' . get_absolute_url() . $page);
            die();
        }
    }

    /** Whether the last is_valid_api_key() refused only because ClickServer gave no answer. */
    private static bool $licenceUnconfirmed = false;

    //this checks if this api key is valid
    public static function is_valid_api_key($user_api_key)
    {
        self::$licenceUnconfirmed = false;

        //only check once per session speed up ui
        if (isset($_SESSION['valid_key']) && $_SESSION['valid_key'] == true) {
            return true;
        }

        $licence = static::askLicenceService((string) $user_api_key);
        // The caches are keyed by the key as the API reads it from 202_users,
        // trimmed; a caller handing in a padded key must land on the same entry.
        $cacheKey = trim((string) $user_api_key);

        // No answer: licensed only on a valid answer within the grace period.
        if ($licence === null) {
            if (\Prosper202\License\ShellAccessCache::validWithinGrace($cacheKey)) {
                return true;
            }
            self::$licenceUnconfirmed = true;
            return false;
        }
        $keyIsValid = $licence['valid'];
        // The same answer carries the CLI licence; record it so a verdict the
        // web app has already heard is not asked for again by the Go CLI gate,
        // and a key found invalid here stops a cached CLI allow at once.
        if ($licence['paid'] !== null) {
            \Prosper202\License\CliAccessCache::write($cacheKey, $licence['paid']);
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
            \Prosper202\License\ShellAccessCache::write($cacheKey, true);
            return true;
        } else {
            // Deny the CLI shell immediately when the key is no longer valid.
            \Prosper202\License\ShellAccessCache::write($cacheKey, false);
            return false;
        }
    }

    /**
     * ClickServer's answer for a key: null when none came. A seam the
     * licence gate's tests answer through.
     *
     * @return array{valid: bool, paid: ?bool}|null
     */
    protected static function askLicenceService(string $key): ?array
    {
        return \Prosper202\License\ClickServerKeyValidator::check($key);
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
     * is empty or that is not a zone is UTC, as the API reads it. A read
     * that fails throws (accountTimezone()): kept, the session's zone was a
     * guess every report page counted its days in with nothing to say so.
     *
     * Whatever is set is held to the one rule (Prosper202\Report\AccountZone):
     * the redirects and pixels pass the zone of a row they read, and an
     * offset such as +05:30 there was refused by date_default_timezone_set()
     * with a notice, leaving the server's own zone in force.
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

        $zone = \Prosper202\Report\AccountZone::normalize(is_string($user_timezone) ? $user_timezone : null);
        date_default_timezone_set($zone);
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
        // The one rule, the API's too: a zone PHP lists, by that exact name;
        // an offset or anything else is UTC.
        $stored = $row['user_timezone'] ?? null;
        $zone = \Prosper202\Report\AccountZone::normalize(is_string($stored) ? $stored : null);

        return self::$accountTimezones[$userId] = $zone;
    }

    /**
     * Sign this request in by its remember-me cookie, once a request at most.
     * connect.php tries it before it reads the session's user, so the User
     * every page uses (and the check of whether it may act) is the signed-in
     * user's from the first request: tried later, by the page, the User had
     * been read for nobody, and that request ran with no roles. A later call
     * (the sign-in page, logged_in_with_remember()) reads nothing again and
     * answers false. A session that already claims a sign-in is not replaced:
     * connect.php reads its user and ends it if that user may not act.
     */
    public static function remember_me_on_logged_out()
    {
        if (!self::$rememberMeTried && isset($_COOKIE['remember_me']) && !self::sessionClaimsSignIn()) {
            self::$rememberMeTried = true;
            $parts = explode('-', (string) $_COOKIE['remember_me']);
            if (count($parts) !== 3) {
                return false;
            }
            [$user_id, $auth_key, $hash] = $parts;
            if (!empty($user_id) && !empty($auth_key) && !empty($hash)) {
                try {
                    $secret = self::get_user_secret_key($user_id, false);
                } catch (\RuntimeException $e) {
                    // Not verified, so not signed in; the cookie is kept for
                    // a request that can read the key.
                    error_log('remember-me: ' . $e->getMessage());
                    return false;
                }
                // No such user, or none with a key: anyone can send that
                // cookie, so it is refused without a log line per request.
                if ($secret === null) {
                    return false;
                }
                $expected = hash_hmac('sha256', $user_id . '-' . $auth_key, $secret);
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
                        2u.user_deleted = 0
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

    /**
     * The account's remember-me key, minted and stored when it has none; null
     * for no account. With $mintIfNone false (the cookie check, which a
     * stranger can call) an account with no key answers null as well: a
     * cookie is signed only with a key already stored. A read or a store that
     * fails throws.
     */
    public static function get_user_secret_key($user_id, bool $mintIfNone = true): ?string
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
        // A failed read is not "no key": minting one then replaced the
        // stored key and signed every remember-me cookie of the account out.
        if (!$user_result instanceof mysqli_result) {
            throw new \RuntimeException('Reading the remember-me key of user ' . (int) $user_id . ' failed (logged by _mysqli_query())');
        }
        $user_row = $user_result->fetch_assoc();
        // No account: a key minted for it would be stored nowhere.
        if ($user_row === null) {
            return null;
        }
        if (!is_array($user_row)) {
            throw new \RuntimeException(
                'Reading the remember-me key of user ' . (int) $user_id . ' failed: its row could not be fetched'
            );
        }
        if (empty($user_row['secret_key'])) {
            if (!$mintIfNone) {
                return null;
            }
            $mysql['secret_key'] = self::generate_random_string(48);
            $sql = '
				UPDATE
					202_users
				SET
					secret_key = "' . $mysql['secret_key'] . '"
				WHERE
					user_id = "' . $mysql['user_id'] . '"
			';
            // A key that was not stored signs a cookie nothing can verify.
            if (_mysqli_query($sql) === false) {
                throw new \RuntimeException('Storing the remember-me key of user ' . (int) $user_id . ' failed (logged by _mysqli_query())');
            }
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
        self::delete_old_auth_hash();
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
        // The sign-in stands without it; a cookie whose key was not stored
        // (or cannot be signed) would be refused on every later visit.
        if (_mysqli_query($sql) === false) {
            return;
        }
        try {
            $secret = self::get_user_secret_key($_SESSION['user_own_id']);
        } catch (\RuntimeException $e) {
            error_log('remember-me: ' . $e->getMessage());
            return;
        }
        if ($secret === null) {
            error_log('remember-me: the signed-in user ' . (int) $_SESSION['user_own_id'] . ' has no account row');
            return;
        }
        $hash = hash_hmac('sha256', $_SESSION['user_own_id'] . '-' . $auth_key, $secret);
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
     * checking credentials. Only SignInOutcome::Failed counts: a right
     * password whose sign-in could not be completed is not a guess.
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
            . 'WHERE ' . \Prosper202\User\SignInOutcome::Failed->sqlCondition()
            . ' AND ' . $column . ' = ? AND login_time >= ?'
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
