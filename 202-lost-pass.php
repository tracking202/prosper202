<?php

declare(strict_types=1);
include_once(__DIR__ . '/202-config/connect.php');

$error = [];
$html = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	$mysql['user_name'] = $db->real_escape_string((string)$_POST['user_name']);
	$mysql['user_email'] = $db->real_escape_string((string)$_POST['user_email']);

	$user_sql = "SELECT user_id FROM 202_users WHERE user_name='" . $mysql['user_name'] . "' AND user_email='" . $mysql['user_email'] . "'";
	$user_result = _mysqli_query($user_sql, $db);
	$user_row = $user_result->fetch_assoc();

	if (!$user_row) {
		$error['user'] = 'Invalid username /email combination.';
	}

	//i there isn't any error, give this user, a new password, and email it to them!
	if (!$error) {

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
		$update_result = _mysqli_query($update_sql, $db);


		//now email the user the script to reset their email
		//normalize recipient: strip CR/LF and require a valid address before use in headers/mail()
		$to = str_replace(["\r", "\n"], '', (string) $_POST['user_email']);
		if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
			$to = '';
		}
		$server_name = str_replace(["\r", "\n"], '', (string) ($_SERVER['SERVER_NAME'] ?? ''));
		$subject = "[Propser202 on " . $server_name . "] Password Reset";

		$message = "
<p>Someone has asked to reset the password for the following site and username.</p>

<p><a href=\"http://" . $server_name . "\">http://" . $server_name . "</a></p>

<p>Username: " . htmlentities((string) $_POST['user_name'], ENT_QUOTES, 'UTF-8') . "</p>

<p>To reset your password visit the following address, otherwise just ignore this email and nothing will happen.</p>

<p><a href=\"http://" . $server_name . "/202-pass-reset.php?key=$user_pass_key\">http://" . $server_name . get_absolute_url() . "202-pass-reset.php?key=$user_pass_key</a></p>";

		$from = "propser202@" . $server_name;

		$header = "From: Propser202<" . $from . "> \r\n";
		$header .= "Reply-To: " . $from . " \r\n";
		$header .=  "To: " . $to . " \r\n";
		$header .= "Content-Type: text/html; charset=\"iso-8859-1\" \r\n";
		$header .= "Content-Transfer-Encoding: 8bit \r\n";
		$header .= "MIME-Version: 1.0 \r\n";

		if ($to !== '') {
			mail((string) $to, $subject, $message, $header);
		}

		$success = true;
	}




	$html['user_name'] = htmlentities((string)($_POST['user_name'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['user_email'] = htmlentities((string)($_POST['user_email'] ?? ''), ENT_QUOTES, 'UTF-8');
} ?>



<?php info_top(); ?>

<?php if ($success == true) { ?>

	<center><small>An email has been sent with a link where you can change your password.</small></center>

<?php } else { ?>
	<div class="row">
		<div class="main col-xs-4">
			<center><img src="202-img/prosper202.png"></center>
			<center><span class="infotext">Please enter your username and e-mail address.<br />You will receive a new password via e-mail to <a href="<?php echo get_absolute_url(); ?>202-login.php">login</a> with.</span></center>
			<form class="form-signin form-horizontal" role="form" method="post" action="">
				<div class="form-group <?php if (isset($error['user'])) echo "has-error"; ?>">
					<?php if (isset($error['user'])) { ?>
						<div class="tooltip right in login_tooltip">
							<div class="tooltip-arrow"></div>
							<div class="tooltip-inner"><?php echo $error['user']; ?></div>
						</div>
					<?php } ?>
					<input type="text" class="form-control first" name="user_name" placeholder="Username">
					<input type="text" class="form-control last" name="user_email" placeholder="Email">
					<p></p>
					<button class="btn btn-lg btn-p202 btn-block" type="submit">Get New Password <span class="fui-arrow-right pull-right"></span></button>
				</div>
			</form>
		</div>
	</div>
<?php } ?>
<?php info_bottom(); ?>