<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -11) . '/202-config/connect.php');

$error = [];
$html = [];

if (AUTH::logged_in()) {
	header('location: ' . get_absolute_url() . '202-Mobile/mini-stats');
}
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	$username_raw = (string)($_POST['user_name'] ?? '');
	$password = (string)($_POST['user_pass'] ?? '');
	$username = trim($username_raw);

	if ($username === '' || $password === '') {
		$error['user'] = '<div class="error">Enter both a username and password.</div>';
	}

	$login_result = null;
	if (empty($error)) {
		try {
			$login_result = AUTH::authenticate($username, $password, $db);
		} catch (RuntimeException) {
			$error['user'] = '<div class="error">Unable to sign you in right now. Please try again later.</div>';
		}
	}

	$user_row = $login_result['user'] ?? null;
	if (empty($error) && ($login_result['success'] ?? false) === false) {
		$error['user'] = '<div class="error">Your username or password is incorrect.</div>';
	}

	$login_success = empty($error) ? 1 : 0;
	$log_stmt = $db->prepare('INSERT INTO 202_users_log (user_name, user_pass, ip_address, login_time, login_success, login_error, login_server, login_session) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
	if ($log_stmt) {
		$login_error_serialized = serialize($error);
		$login_server_serialized = serialize($_SERVER);
		$login_session_serialized = serialize($_SESSION);
		$redacted_password = '[filtered]';
		$ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
		$login_time = time();
		$log_stmt->bind_param(
			'sssiisss',
			$username,
			$redacted_password,
			$ip_address,
			$login_time,
			$login_success,
			$login_error_serialized,
			$login_server_serialized,
			$login_session_serialized
		);
		$log_stmt->execute();
		$log_stmt->close();
	} else {
		prosper_log('login', 'Unable to prepare mobile login log statement: ' . $db->error);
	}

	if (empty($error) && $user_row) {
		AUTH::delete_old_auth_hash();
		$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '0.0.0.0';
		$ip_id = (int) INDEXES::get_ip_id($ip);
		$update_stmt = $db->prepare('UPDATE 202_users SET user_last_login_ip_id = ? WHERE user_id = ?');
		if ($update_stmt) {
			$user_id = (int) $user_row['user_id'];
			$update_stmt->bind_param('ii', $ip_id, $user_id);
			$update_stmt->execute();
			$update_stmt->close();
		}

		AUTH::begin_user_session($user_row);
		$_SESSION['toolbar'] = 'true';

		header('location: ' . get_absolute_url() . '202-Mobile/mini-stats');
		exit;
	}

	$html['user_name'] = htmlentities($username, ENT_QUOTES, 'UTF-8');
}
?>


<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">

<head>

	<title>Prosper202</title>
	<meta name="description" content="description" />
	<meta name="keywords" content="keywords" />
	<meta name="copyright" content="202, Inc" />
	<meta name="author" content="202, Inc" />
	<meta name="MSSmartTagsPreventParsing" content="TRUE" />
	<meta http-equiv="imagetoolbar" content="no" />
	<meta name="viewport" content="width=device-width ,  user-scalable=no">


	<link href="<?php echo get_absolute_url(); ?>202-css/toolbar.css" rel="stylesheet" type="text/css" />
</head>

<body>


	<div class="center">

		<table cellspacing="0" cellpadding="5">
			<tr>
				<td colspan="2" style="text-align: center;"><a href="https://my.tracking202.com" target="_blank"><img src="<?php echo get_absolute_url(); ?>202-img/prosper202.png" /></a><br /></td>
			</tr>
			<tr>
				<td>
					<form method="post" action="">
						<input type="hidden" name="token" value="<?php echo $_SESSION['token'] ?? ''; ?>" />
						<table cellspacing="0" cellpadding="5" style="margin: 0px auto;">
							<?php if (isset($error['token'])) {
								printf('<tr><td colspan="2">%s</td></tr>', $error['token']);
							} ?>
							<tr>
								<td>Username:</td>
							</tr>
							<tr>

								<td><input id="user_name" type="text" name="user_name" value="<?php echo $html['user_name'] ?? ''; ?>" autocomplete="username" /></td>
							</tr>
							<?php if (isset($error['user'])) {
								printf('<tr><td colspan="2">%s</td></tr>', $error['user']);
							} ?>

							<tr>
								<td>Password:</td>
							</tr>
							<tr>

								<td>
									<input id="user_pass" type="password" name="user_pass" autocomplete="current-password" />
									<!-- <span id="forgot_pass"><br>(<a href="<?php echo get_absolute_url(); ?>202-lost-pass.php">I forgot my password/username</a>)</a> -->
								</td>
							</tr>
							<tr>

								<td align="center"><input id="submit" type="submit" value="Sign In" /></td>
							</tr>
						</table>
					</form>
				</td>
			</tr>
		</table>
	</div>

</body>

</html>
