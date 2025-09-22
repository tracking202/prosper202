<?php

declare(strict_types=1);

//error_reporting(E_ALL);
class AUTH
{
    public const LOGOUT_DAYS = 14;
    private const string LOGIN_SELECT = 'SELECT u.user_id, u.user_name, u.user_pass, u.user_api_key, u.user_stats202_app_key, u.user_timezone, u.user_mods_lb, u.install_hash, u.p202_customer_api_key, up.user_id AS pref_user_id FROM 202_users u LEFT JOIN 202_users_pref up ON up.user_id = u.user_id WHERE u.user_name = ? AND u.user_deleted != 1 AND u.user_active = 1 LIMIT 1';
    private static bool $passwordColumnChecked = false;

    public static function logged_in()
    {
        $session_time_passed = isset($_SESSION['session_time']) ? time() - $_SESSION['session_time'] : PHP_INT_MAX;
        if (isset($_SESSION['user_name']) and isset($_SESSION['user_id']) and isset($_SESSION['session_fingerprint']) and ($_SESSION['session_fingerprint'] == md5('session_fingerprint' . $_SERVER['HTTP_USER_AGENT'] . session_id())) and ($session_time_passed < 50000)) {
            $_SESSION['session_time'] = time();
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
        $stmt->bind_param('s', $username);
        $stmt->execute();
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
        $stmt->bind_param('si', $new_hash, $user_id);
        $stmt->execute();
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
                    $length = (int) ($matches[1] ?? 0);
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

        $stmt->bind_param('s', $installHash);
        $stmt->execute();
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
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION['session_fingerprint'] = md5('session_fingerprint' . ($_SERVER['HTTP_USER_AGENT'] ?? '') . session_id());
        $_SESSION['session_time'] = time();
        $_SESSION['user_name'] = $user_row['user_name'];
        $_SESSION['user_id'] = (int) $user_row['user_id'];
        $_SESSION['user_own_id'] = (int) $user_row['user_id'];
        $_SESSION['user_api_key'] = $user_row['user_api_key'] ?? null;
        $_SESSION['user_stats202_app_key'] = $user_row['user_stats202_app_key'] ?? null;
        $_SESSION['user_timezone'] = $user_row['user_timezone'] ?? 'UTC';
        $_SESSION['user_mods_lb'] = $user_row['user_mods_lb'] ?? 0;
        $_SESSION['account_owner_id'] = self::determineAccountOwnerId($user_row);
    }

    public static function require_user($auth_type = '')
    {
        if (AUTH::logged_in() == false) {
            AUTH::remember_me_on_logged_out();
        }

        if (AUTH::logged_in() == false) {
            if ($auth_type == "toolbar") {
                $_SESSION['toolbar'] = 'true';
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

        $post = [];
        $post['key'] = $user_api_key;
        $fields = http_build_query($post);
// Initiate curl
        $ch = curl_init();
// Set the url
        curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/validate-customers-key');
// Disable SSL verification
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
// Will return the response, if false it print the response
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
// Set to post
        curl_setopt($ch, CURLOPT_POST, 1);
// Set post fields
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
// Execute
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
        // echo 'error:' . curl_error($c);
        }
        // close connection
        curl_close($ch);
        $api_validate = json_decode($result, true);
        if ($api_validate['msg'] == "Key valid") {
        //update the api key
            $user_sql = "	UPDATE 	202_users
						SET		p202_customer_api_key='" . $user_api_key . "'
						WHERE 	user_id='" . $_SESSION['user_id'] . "'";
            _mysqli_query($user_sql);
            $_SESSION['valid_key'] = true;
            return true;
        } else {
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
            [$user_id, $auth_key, $hash] = explode('-', (string) $_COOKIE['remember_me']);
            if (!empty($user_id) && !empty($auth_key) && !empty($hash)) {
                if ($hash !== hash_hmac('sha256', $user_id . '-' . $auth_key, (string) self::get_user_secret_key($user_id))) {
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
                    $_SESSION['user_cirrus_link'] = $user_row['user_api_key'] ?? null;
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
            $randomString .= $characters[self::dev_urand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    public static function get_user_secret_key($user_id)
    {
        $database = DB::getInstance();
        $db = $database->getConnection();
        $mysql['user_id'] = $db->escape_string($user_id);
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
            'domain' => (string) ($_SERVER['HTTP_HOST'] ?? ''),
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function delete_old_auth_hash()
    {
        $sql = 'DELETE FROM 202_auth_keys WHERE expires < UNIX_TIMESTAMP()';
        _mysqli_query($sql);
    }

    public static function dev_urand($min = 0, $max = 0x7FFFFFFF)
    {
        if (function_exists('random_bytes')) {
            $diff = $max - $min;
            if ($diff < 0 || $diff > 0x7FFFFFFF) {
                throw new \RuntimeException("Bad range");
            }
            $bytes = random_bytes(4);
            if ($bytes === false || strlen($bytes) != 4) {
                throw new \RuntimeException("Unable to get 4 bytes");
            }
            $ary = unpack("Nint", $bytes);
            $val = $ary['int'] & 0x7FFFFFFF;
// 32-bit safe
            $fp = (float) $val / 2147483647.0;
// convert to [0,1]
            return round($fp * $diff) + $min;
        }

        // fallback to less secure mt_rand in case user doesn't have random_bytes
        return mt_rand($min, $max);
    }
}
