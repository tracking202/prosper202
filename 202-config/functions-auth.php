<?php
//error_reporting(E_ALL);
class AUTH
{

	const LOGOUT_DAYS = 14;

	static function logged_in()
	{

		$session_time_passed = isset($_SESSION['session_time']) ? time() - $_SESSION['session_time'] : PHP_INT_MAX;
		if (isset($_SESSION['user_name']) and isset($_SESSION['user_id']) and isset($_SESSION['session_fingerprint']) and ($_SESSION['session_fingerprint'] == md5('session_fingerprint' . $_SERVER['HTTP_USER_AGENT'] . session_id())) and ($session_time_passed < 50000)) {

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
		$user_sql = "SELECT user_pref_ad_settings, p202_customer_api_key from 202_users_pref left join 202_users ON (202_users_pref.user_id = 202_users.user_id) WHERE 202_users_pref.user_id='1'";
		$user_result = _mysqli_query($user_sql);
		if ($user_result) {
			$user_row = $user_result->fetch_assoc();
			$user_api_key = $user_row['p202_customer_api_key'];
		} else {
			$user_api_key = '';
		}

		if (self::is_valid_api_key($user_api_key) == false || $user_api_key == '') {
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
			$user_result = _mysqli_query($user_sql);
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
                 	    expires < UNIX_TIMESTAMP()
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

		$mysql = array(
			'user_id' => $db->real_escape_string($_SESSION['user_own_id']),
			'auth_key' => $db->real_escape_string($auth_key)
		);

		$sql = 'INSERT INTO
					202_auth_keys
				SET
					auth_key = "' . $mysql['auth_key'] . '",
					user_id = "' . $mysql['user_id'] . '",
					expires = "' . time() . '"
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
		if (function_exists('random_bytes')) {
			$diff = $max - $min;
			if ($diff < 0 || $diff > 0x7FFFFFFF) {
				throw new RuntimeException("Bad range");
			}
			$bytes = random_bytes(4);
			if ($bytes === false || strlen($bytes) != 4) {
				throw new RuntimeException("Unable to get 4 bytes");
			}
			$ary = unpack("Nint", $bytes);
			$val = $ary['int'] & 0x7FFFFFFF;   // 32-bit safe
			$fp = (float) $val / 2147483647.0; // convert to [0,1]
			return round($fp * $diff) + $min;
		}

		// fallback to less secure nt_rand in case user doesn't have mcrypt extension
		return mt_rand($min = 0, $max = 0x7FFFFFFF);
	}
}
