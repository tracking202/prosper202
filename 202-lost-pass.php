<?php

declare(strict_types=1);
include_once(__DIR__ . '/202-config/connect.php');

$error = [];
$html = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !AUTH::check_csrf_token()) {
	$error['user'] = 'Your session has expired. Please reload the page and try again.';
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$error) {

	$mysql['user_name'] = $db->real_escape_string((string)$_POST['user_name']);
	$mysql['user_email'] = $db->real_escape_string((string)$_POST['user_email']);

	$user_sql = "SELECT user_id FROM 202_users WHERE user_name='" . $mysql['user_name'] . "' AND user_email='" . $mysql['user_email'] . "'";
	$user_result = _mysqli_query($db, $user_sql);
	$user_row = ($user_result instanceof mysqli_result) ? $user_result->fetch_assoc() : null;

	// Always report success regardless of whether the account exists. Revealing
	// "invalid username/email combination" lets an attacker enumerate which
	// usernames and emails are registered. Only actually issue a reset when the
	// account matches.
	$success = true;

	if ($user_row) {

		$mysql['user_id'] = $db->real_escape_string((string) $user_row['user_id']);

		//generate random key (CSPRNG; expiry tracked separately via user_pass_time)
		$user_pass_key = bin2hex(random_bytes(32));
		$mysql['user_pass_key'] = $db->real_escape_string($user_pass_key);

		//set the user pass time
		$mysql['user_pass_time'] = time();

		//insert this verification key into the database, and the timestamp of inserting it
		$update_sql = "	UPDATE 	202_users
							SET 		user_pass_key='" . $mysql['user_pass_key'] . "',
										user_pass_time='" . $mysql['user_pass_time'] . "'
							WHERE		user_id='" . $mysql['user_id'] . "'";
		$update_result = _mysqli_query($db, $update_sql);

		// If the key never persisted, do not mail a dead reset link. Log it
		// server-side but keep the generic success message (below) so the
		// response is identical whether or not the account exists.
		if ($update_result === false) {
			prosper_log('lost-pass', 'Failed to store reset key for user_id ' . $mysql['user_id'] . ': ' . $db->error);
		} else {
		//now email the user the script to reset their email
		//normalize recipient: strip CR/LF and require a valid address before use in headers/mail()
		$to = str_replace(["\r", "\n"], '', (string) $_POST['user_email']);
		if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
			$to = '';
		}
		$server_name = str_replace(["\r", "\n"], '', (string) ($_SERVER['SERVER_NAME'] ?? ''));
		// Match the scheme the request came in on so the reset link isn't downgraded to http.
		$scheme = getSecureStatus() ? 'https' : 'http';
		// get_absolute_url() is '' on root installs, so ensure a leading slash or
		// the link becomes "https://example.com202-pass-reset.php" (unusable).
		$base_path = get_absolute_url();
		if ($base_path === '' || $base_path[0] !== '/') {
			$base_path = '/' . $base_path;
		}
		$reset_url = $scheme . '://' . $server_name . $base_path . '202-pass-reset.php?key=' . $user_pass_key;
		$subject = "[Prosper202 on " . $server_name . "] Password Reset";

		$message = "
<p>Someone has asked to reset the password for the following site and username.</p>

<p><a href=\"" . $scheme . "://" . $server_name . "\">" . $scheme . "://" . $server_name . "</a></p>

<p>Username: " . htmlentities((string) $_POST['user_name'], ENT_QUOTES, 'UTF-8') . "</p>

<p>To reset your password visit the following address, otherwise just ignore this email and nothing will happen.</p>

<p><a href=\"" . $reset_url . "\">" . $reset_url . "</a></p>";

		$from = "prosper202@" . $server_name;

		$header = "From: Prosper202<" . $from . "> \r\n";
		$header .= "Reply-To: " . $from . " \r\n";
		$header .=  "To: " . $to . " \r\n";
		$header .= "Content-Type: text/html; charset=\"iso-8859-1\" \r\n";
		$header .= "Content-Transfer-Encoding: 8bit \r\n";
		$header .= "MIME-Version: 1.0 \r\n";

		if ($to !== '') {
			mail((string) $to, $subject, $message, $header);
		}
		}
	}




	$html['user_name'] = htmlentities((string)($_POST['user_name'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['user_email'] = htmlentities((string)($_POST['user_email'] ?? ''), ENT_QUOTES, 'UTF-8');
} ?>



<?php info_top(['title' => 'Reset your password - Prosper202 ClickServer']);

if ($success == true) {
	echo p202_standalone_card('Check your email', 'If that username and email match an account, a link to choose a new password is on its way. It works for three days.'); ?>
	<p class="mb-0"><a class="btn btn-secondary w-100" href="<?php echo htmlspecialchars(get_absolute_url(), ENT_QUOTES, 'UTF-8'); ?>202-login.php">Back to sign in</a></p>
<?php echo p202_standalone_card_end();
} else {
	echo p202_standalone_card('Reset your password', 'Enter your username and the email address on your account. We will email you a link to choose a new password.'); ?>
	<?php if (isset($error['user'])) { ?>
		<div class="alert alert-danger p202-flash" role="alert"><i class="bi bi-x-circle"></i><div class="p202-flash__body"><?php echo htmlspecialchars(trim((string) $error['user']), ENT_QUOTES, 'UTF-8'); ?></div></div>
	<?php } ?>
	<form method="post" action="" id="lost-pass-form">
		<input type="hidden" name="token" value="<?php echo htmlspecialchars((string) ($_SESSION['token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
		<div class="mb-3">
			<label class="form-label" for="user_name">Username</label>
			<input type="text" class="form-control" id="user_name" name="user_name" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
		</div>
		<div class="mb-3">
			<label class="form-label" for="user_email">Email</label>
			<input type="email" class="form-control" id="user_email" name="user_email" autocomplete="email" required>
		</div>
		<button class="btn btn-primary w-100" type="submit">Email me a reset link</button>
	</form>
	<p class="mt-3 mb-0 text-center small"><a href="<?php echo htmlspecialchars(get_absolute_url(), ENT_QUOTES, 'UTF-8'); ?>202-login.php">Back to sign in</a></p>
<?php echo p202_standalone_card_end();
}
info_bottom();
