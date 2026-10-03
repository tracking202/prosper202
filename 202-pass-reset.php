<?php

declare(strict_types=1);
include_once(__DIR__ . '/202-config/connect.php');

$error = [];
$html = [];
$success = false;

//take password retireveal and see if it is legitimate
$submitted_key = (string)($_GET['key'] ?? '');
$mysql['user_pass_key'] = $db->real_escape_string($submitted_key);

// Never look up on a blank key. Reset keys are cleared to NULL after use, but
// guarding here keeps an empty "?key=" from ever matching a row by accident.
if ($submitted_key === '') {
	$user_row = null;
} else {
	$user_sql = "SELECT * FROM 202_users WHERE user_pass_key='" . $mysql['user_pass_key'] . "'";
	$user_result = _mysqli_query($db, $user_sql);
	$user_row = ($user_result instanceof mysqli_result) ? $user_result->fetch_assoc() : null;
}

if (!$user_row) {
	$error['user_pass_key'] = '<div class="error">No key was found like that</div>';
}

if (!$error) {

	//how many days ago was this code activated, this code will only work if the activation reset code is at least current within the last 3 days
	$date_today = time();
	$days = (($date_today - $user_row['user_pass_time']) / 86400);

	if ($days > 3) {
		$error['user_pass_key'] = ($error['user_pass_key'] ?? '') . 'Sorry, this key has expired, they expire in three (3) days.';
	}
}


//if the key is legit, make sure their new posted password is legit
if (!$error and ($_SERVER['REQUEST_METHOD'] == "POST")) {

	//check tokens (CSRF): the form embeds the session token; reject forged posts
	if (!AUTH::check_csrf_token()) {
		$error['user_pass'] = '<div class="error">Your session has expired. Please reload the page and try again.</div>';
	}

	if (!$error && ($_POST['user_pass'] ?? '') == '') {
		$error['user_pass'] = '<div class="error">You must type in your desired password</div>';
	}
	if (!$error && ($_POST['verify_user_pass'] ?? '') == '') {
		$error['user_pass'] = ($error['user_pass'] ?? '') . '<div class="error">You must type verify your password</div>';
	}
	// Cap at 72 bytes (bcrypt's effective input length under PASSWORD_DEFAULT).
	if (!$error && ((strlen((string) ($_POST['user_pass'] ?? '')) < 8) or (strlen((string) ($_POST['user_pass'] ?? '')) > 72))) {
		$error['user_pass'] = ($error['user_pass'] ?? '') . '<div class="error">Passwords must be 8 to 72 characters long</div>';
	}
	if (!$error && (($_POST['user_pass'] ?? '') != ($_POST['verify_user_pass'] ?? ''))) {
		$error['user_pass'] = ($error['user_pass'] ?? '') . '<div class="error">Your passwords did not match, please try again</div>';
	}

	if (!$error) {

		$hasher = function_exists('hash_user_pass') ? 'hash_user_pass' : 'salt_user_pass';
		$user_pass = $hasher($_POST['user_pass']);
		$mysql['user_pass'] = $db->real_escape_string($user_pass);

		$mysql['user_id'] = $db->real_escape_string($user_row['user_id']);

		// Clear the reset key as well as the timestamp so the link is single-use
		// and cannot be replayed even before the 3-day window elapses.
		$user_sql = "UPDATE 	202_users
						  SET		user_pass='" . $mysql['user_pass'] . "',
									user_pass_key=NULL,
									user_pass_time='0'
						  WHERE	user_id='" . $mysql['user_id'] . "'";
		$user_result = _mysqli_query($db, $user_sql);

		if ($user_result === false) {
			$error['user_pass'] = '<div class="error">Could not save your new password, please try again.</div>';
		} else {
			$success = true;
		}
	}
}

$html['user_name'] = htmlentities((string)($user_row['user_name'] ?? ''), ENT_QUOTES, 'UTF-8');



//if password was changed successfully
if ($success == true) {

	_die("<h6>Password changed</h6><small>Your password has been reset. You can now <a href=\"" . get_absolute_url() . "202-login.php\">sign in</a> with your new password.</small>");
}

if (!empty($error['user_pass_key'])) {

	_die("<h6>This reset link does not work</h6><small>" . htmlspecialchars(p202_standalone_error_text($error['user_pass_key']), ENT_QUOTES, 'UTF-8') . " Please use the <a href=\"" . get_absolute_url() . "202-lost-pass.php\">password retrieval tool</a> to get a new password reset link.</small>");
}

//else if none of the above, show the code to reset! 
?>

<?php info_top(['title' => 'Choose a new password - Prosper202 ClickServer']);
echo p202_standalone_card('Choose a new password', 'Type it twice. It must be 8 to 72 characters.'); ?>
	<?php if (!empty($error['user_pass'])) { ?>
		<div class="alert alert-danger p202-flash" role="alert"><i class="bi bi-x-circle"></i><div class="p202-flash__body"><?php echo htmlspecialchars(p202_standalone_error_text($error['user_pass']), ENT_QUOTES, 'UTF-8'); ?></div></div>
	<?php } ?>
	<form method="post" action="" id="pass-reset-form">
		<input type="hidden" name="token" value="<?php echo htmlspecialchars((string) ($_SESSION['token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
		<div class="mb-3">
			<label class="form-label" for="user_name">Username</label>
			<input type="text" class="form-control" id="user_name" value="<?php echo $html['user_name']; ?>" autocomplete="username" readonly>
		</div>
		<div class="mb-3">
			<label class="form-label" for="user_pass">New password</label>
			<input type="password" class="form-control<?php echo !empty($error['user_pass']) ? ' is-invalid' : ''; ?>" id="user_pass" name="user_pass" autocomplete="new-password" minlength="8" maxlength="72" required autofocus>
		</div>
		<div class="mb-3">
			<label class="form-label" for="verify_user_pass">Type it again</label>
			<input type="password" class="form-control" id="verify_user_pass" name="verify_user_pass" autocomplete="new-password" minlength="8" maxlength="72" required>
		</div>
		<button class="btn btn-primary w-100" type="submit">Reset password</button>
	</form>
<?php echo p202_standalone_card_end();
info_bottom();
