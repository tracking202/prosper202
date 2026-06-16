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
	$user_result = _mysqli_query($user_sql, $db);
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
	if (!$error && ((strlen((string) ($_POST['user_pass'] ?? '')) < 8) or (strlen((string) ($_POST['user_pass'] ?? '')) > 128))) {
		$error['user_pass'] = ($error['user_pass'] ?? '') . '<div class="error">Passwords must be 8 to 128 characters long</div>';
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
		$user_result = _mysqli_query($user_sql, $db);

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

	_die("<center><small>Congratulations, your password has been reset.<br/>You can now <a href=\"" . get_absolute_url() . "202-login.php\">login</a> with your new password.</small></center>");
}

if (!empty($error['user_pass_key'])) {

	_die("<center><small>" . $error['user_pass_key'] . "<br/>Please use the <a href=\"" . get_absolute_url() . "202-lost-pass.php\">password retrieval tool</a> to get a new password reset key.</small></center>");
}

//else if none of the above, show the code to reset! 
?>

<?php info_top(); ?>
<div class="row">
	<div class="main col-xs-4">
		<center><img src="202-img/prosper202.png"></center>
		<center><span class="infotext">Please create a new password and verify it to proceed.</span></center>
		<form class="form-signin form-horizontal" role="form" method="post" action="">
			<input type="hidden" name="token" value="<?php echo htmlspecialchars((string) ($_SESSION['token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
			<div class="form-group">
				<input type="text" class="form-control first" id="user_name" name="user_name" value="<?php echo $html['user_name']; ?>" disabled="disabled">
			</div>
			<div class="form-group <?php if (!empty($error['user_pass'])) echo "has-error"; ?>">
				<?php if (!empty($error['user_pass'])) { ?>
					<div class="tooltip right in login_tooltip">
						<div class="tooltip-arrow"></div>
						<div class="tooltip-inner"><?php echo $error['user_pass']; ?></div>
					</div>
				<?php } ?>
				<input type="password" class="form-control middle" name="user_pass" placeholder="New Password">
				<input type="password" class="form-control last" name="verify_user_pass" placeholder="Verify Password">
				<p></p>
				<button class="btn btn-lg btn-p202 btn-block" type="submit">Reset Password <span class="fui-arrow-right pull-right"></span></button>
			</div>
		</form>
	</div>
</div>
<?php info_bottom(); ?>
