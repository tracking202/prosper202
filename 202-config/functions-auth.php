<?php

declare(strict_types=1);
//error_reporting(E_ALL);
class AUTH
{

	const LOGOUT_DAYS = 14;
	static function logged_in()
	{
		if (!isset($_SESSION['session_time'])) {
			return false;
		}

		$session_time_passed = time() - $_SESSION['session_time'];
		if (
			isset($_SESSION['user_name']) && isset($_SESSION['user_id']) &&
			(isset($_SESSION['session_fingerprint']) && $_SESSION['session_fingerprint'] == md5('session_fingerprint' . $_SERVER['HTTP_USER_AGENT'] . session_id())) &&
			($session_time_passed < 50000)
		) {

			$_SESSION['session_time'] = time();
			return true;
		} else {
			return false;
		}
	}
	static function require_user($auth_type = '')
	{
		if (AUTH::logged_in() == false) {
			AUTH::remember_me_on_logged_out();
		}

		if (AUTH::logged_in() == false) {
			if ($auth_type == "toolbar")
				$_SESSION['toolbar'] = 'true';

			die(include_once(realpath(__DIR__ . '/../') . '/202-access-denied.php')); //go up one level
		}
		AUTH::set_timezone($_SESSION['user_timezone']);
		AUTH::require_valid_api_key();
	}
	static function require_valid_api_key()
	{
		// First try the join query
		$user_sql = "SELECT user_pref_ad_settings, p202_customer_api_key from 202_users_pref left join 202_users ON (202_users_pref.user_id = 202_users.user_id) WHERE 202_users_pref.user_id='1'";
		$user_result = _mysqli_query($user_sql);
		
		if ($user_result && $user_result->num_rows > 0) {
			$user_row = $user_result->fetch_assoc();
			$user_api_key = $user_row['p202_customer_api_key'];
		} else {
			// Fallback: If no user_pref record exists, check 202_users directly
			$user_sql = "SELECT p202_customer_api_key FROM 202_users WHERE user_id='1'";
			$user_result = _mysqli_query($user_sql);
			if ($user_result && $user_result->num_rows > 0) {
				$user_row = $user_result->fetch_assoc();
				$user_api_key = $user_row['p202_customer_api_key'];
				
				// Create missing user_pref record
				$insert_sql = "INSERT IGNORE INTO 202_users_pref (user_id) VALUES ('1')";
				_mysqli_query($insert_sql);
			} else {
				$user_api_key = '';
			}
		}

		if (AUTH::is_valid_api_key($user_api_key) == false || $user_api_key == '') {
			header('location: ' . get_absolute_url() . 'api-key-required.php');
			die();
		}
	}

	//this checks if this api key is valid
	static function is_valid_api_key($user_api_key)
	{

		//only check once per session speed up ui 	    
		if (isset($_SESSION['valid_key']) && $_SESSION['valid_key'] == true) {
			return true;
		}

		$post = array();
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
		// Set timeout to prevent hanging
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		// Execute
		$result = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curl_error = curl_error($ch);
		$curl_errno = curl_errno($ch);
		
		// close connection
		curl_close($ch);

		// If there's a network error or timeout, assume the key is valid
		// This prevents blocking access when the validation server is down
		if ($curl_errno > 0 || $http_code == 0) {
			// Network error - allow access but don't cache the validation
			return true;
		}
		
		// If we got a response, validate it
		$api_validate = json_decode($result, true);
		if (isset($api_validate['msg']) && $api_validate['msg'] == "Key valid") {
			$database = DB::getInstance();
			$db = $database->getConnection();
			$mysql = array(
				'user_api' => $db->real_escape_string($user_api_key),
				'user_name' => $db->real_escape_string((string)($_SESSION['user_name'] ?? '')),
				'user_pass' => $db->real_escape_string((string)(isset($_SESSION['user_pass']) ? $_SESSION['user_pass'] : ''))
			);			//update the api key if user_name and user_pass are available
			if (!empty($mysql['user_name']) && !empty($mysql['user_pass'])) {
				$user_sql = "	UPDATE 	202_users
							SET		p202_customer_api_key='" . $mysql['user_api'] . "'
							WHERE 	user_name='" . $mysql['user_name'] . "'
							AND     		user_pass='" . $mysql['user_pass'] . "'";
				$user_result = _mysqli_query($user_sql);
			}
			$_SESSION['valid_key'] = true;
			return true;
		} else {
			return false;
		}
	}

	static function set_timezone($user_timezone)
	{

		if (isset($_SESSION['user_timezone'])) {
			$user_timezone = $_SESSION['user_timezone'];
		}

		date_default_timezone_set($user_timezone);
	}

	static function remember_me_on_logged_out()
	{
		if (isset($_COOKIE['remember_me']) && AUTH::logged_in() == false) {
			list($user_id, $auth_key, $hash) = explode('-', $_COOKIE['remember_me']);

			if (!empty($user_id) && !empty($auth_key) && !empty($hash)) {
				if ($hash !== hash_hmac('sha256', $user_id . '-' . $auth_key, self::get_user_secret_key($user_id))) {
					return false;
				}

				$database = DB::getInstance();
				$db = $database->getConnection();

				$mysql = array(
					'user_id' => $db->real_escape_string($user_id),
					'auth_key' => $db->real_escape_string($auth_key)
				);
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

					$_SESSION['session_fingerprint'] = md5('session_fingerprint' . $_SERVER['HTTP_USER_AGENT'] . session_id());
					$_SESSION['session_time'] = time();
					$_SESSION['user_name'] = $user_row['user_name'];
					$_SESSION['user_id'] = 1;
					$_SESSION['user_own_id'] = $user_row['user_id'];
					$_SESSION['user_api_key'] = $user_row['user_api_key'];
					$_SESSION['user_cirrus_link'] = $user_row['user_api_key'];
					$_SESSION['user_stats202_app_key'] = $user_row['user_stats202_app_key'];
					$_SESSION['user_timezone'] = $user_row['user_timezone'];

					return true;
				}
			}
		}

		return false;
	}

	static function generate_random_string($length)
	{
		$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$charactersLength = strlen($characters);
		$randomString = '';
		for ($i = 0; $i < $length; $i++) {
			$randomString .= $characters[self::dev_urand(0, $charactersLength - 1)];
		}
		return $randomString;
	}

	static function get_user_secret_key($user_id)
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
	static function remember_me_on_auth()
	{
		$auth_key = self::generate_random_string(48);

		$database = DB::getInstance();
		$db = $database->getConnection();

		// Clean up expired auth keys
		$cleanup_sql = 'DELETE FROM 202_auth_keys WHERE expires < UNIX_TIMESTAMP()';
		_mysqli_query($cleanup_sql);

		$mysql = array(
			'user_id' => $db->real_escape_string((string)$_SESSION['user_own_id']),
			'auth_key' => $db->real_escape_string($auth_key)
		);
		$expires_time = time() + (self::LOGOUT_DAYS * 24 * 60 * 60);

		$sql = 'INSERT INTO
					202_auth_keys
				SET
					auth_key = "' . $mysql['auth_key'] . '",
					user_id = "' . $mysql['user_id'] . '",
					expires = "' . $expires_time . '"
				';
		_mysqli_query($sql);

		$hash = hash_hmac('sha256', $_SESSION['user_own_id'] . '-' . $auth_key, self::get_user_secret_key($_SESSION['user_own_id']));

		$expire = strtotime('+' . self::LOGOUT_DAYS . ' days');
		setcookie(
			'remember_me',
			$_SESSION['user_own_id'] . '-' . $auth_key . '-' . $hash,
			$expire,
			'/',
			$_SERVER['HTTP_HOST'],
			false,
			true
		);
	}

	static function delete_old_auth_hash()
	{
		if (isset($_COOKIE['auth_hash'])) {
			if (!empty($user_id) && !empty($auth_key)) {
				$sql = '
						DELETE FROM
							202_auth_keys
						WHERE
							expires < UNIX_TIMESTAMP()
					';

				_mysqli_query($sql);
			}
		}
	}

	static function dev_urand($min = 0, $max = 0x7FFFFFFF)
	{
		// Use random_int for cryptographically secure random numbers in PHP 7+
		return random_int($min, $max);
	}
}
