<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -18) . '/202-config/connect.php');

AUTH::require_user();

if (!$userObj->hasPermission("access_to_setup_section")) {
	header('location: ' . get_absolute_url() . 'tracking202/');
	die();
}

$slack = false;
$slack_pixel_added_message = false;
$error = [];
$html = [];
$selected = [];
$add_success = '';
$delete_success = '';
$network_editing = false;
$editing = false;
$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
$mysql['user_own_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
$user_sql = "SELECT 2u.user_name as username, 2u.install_hash, 2up.user_slack_incoming_webhook AS url FROM 202_users AS 2u INNER JOIN 202_users_pref AS 2up ON (2up.user_id = 1) WHERE 2u.user_id = '" . $mysql['user_own_id'] . "'";
$user_results = $db->query($user_sql);
$user_row = $user_results->fetch_assoc();

if (!empty($user_row['url']))
	$slack = new Slack($user_row['url']);

if (!empty($_GET['edit_ppc_account_id'])) {
	$editing = true;
} elseif (!empty($_GET['edit_ppc_network_id'])) {
	$network_editing = true;
	$mysql['ppc_network_id'] = $db->real_escape_string((string)$_GET['edit_ppc_network_id']);
}
$pixel_array = [];
$pixel_array[] = ['pixel_type_id' => '', 'pixel_code' => '', 'pixel_id' => ''];
$pixel_types = [];

$ppc_pixel_type_sql = "SELECT * FROM `202_pixel_types`";
$ppc_pixel_type_result = _mysqli_query($ppc_pixel_type_sql);

while ($ppc_pixel_type_row = $ppc_pixel_type_result->fetch_assoc()) {
	$pixel_types[] = ['pixel_type' => htmlentities((string)($ppc_pixel_type_row['pixel_type'] ?? ''), ENT_QUOTES, 'UTF-8'), 'pixel_type_id' => htmlentities((string)($ppc_pixel_type_row['pixel_type_id'] ?? ''), ENT_QUOTES, 'UTF-8')];
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	if (isset($_POST['ppc_network_name'])) {
		$ppc_network_name = trim((string) $_POST['ppc_network_name']);
		if (empty($ppc_network_name)) {
			$error['ppc_network_name'] = 'Type in the name the traffic source.';
		}

		if (empty($error)) {
			$mysql['ppc_network_id'] = isset($_POST['ppc_network_id']) ? $db->real_escape_string((string)$_POST['ppc_network_id']) : '';
			$mysql['ppc_network_name'] = $db->real_escape_string((string)$_POST['ppc_network_name']);
			$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
			$mysql['ppc_network_time'] = time();

			if ($network_editing == true) {
				$ppc_network_sql  = " UPDATE 202_ppc_networks SET";
			} else {
				$ppc_network_sql = "INSERT INTO `202_ppc_networks` SET";
			}
			$ppc_network_sql .= " `user_id`='" . $mysql['user_id'] . "',
								  `ppc_network_name`='" . $mysql['ppc_network_name'] . "',
								  `ppc_network_time`='" . $mysql['ppc_network_time'] . "'";
			if ($network_editing == true) {
				$ppc_network_sql  .= "WHERE ppc_network_id='" . $mysql['ppc_network_id'] . "'";
			}
			$ppc_network_result = _mysqli_query($ppc_network_sql); //($ppc_network_sql);
			$add_success = true;
			if ($network_editing == true) {
				if ($slack)
					$slack->push('traffic_source_name_changed', ['old_name' => $_GET['edit_ppc_network_name'], 'new_name' => $_POST['ppc_network_name'], 'user' => $user_row['username']]);
				//if editing true, refresh back with the edit get variable GONE GONE!
				header('location: ' . get_absolute_url() . 'tracking202/setup/ppc_accounts.php');
			} else {
				if ($slack)
					$slack->push('traffic_source_created', ['name' => $_POST['ppc_network_name'], 'user' => $user_row['username']]);
			}

			tagUserByNetwork($user_row['install_hash'], 'traffic-sources', $_POST['ppc_network_name']);
		}
	}

	if (isset($_POST['ppc_network_id']) && ($network_editing == false)) {

		$pixel_ids = [];

		$ppc_account_name = trim((string) $_POST['ppc_account_name']);
		$do_edit_ppc_account = trim(filter_input(INPUT_POST, 'do_edit_ppc_account', FILTER_SANITIZE_NUMBER_INT));
		if ($ppc_account_name == '' && $do_edit_ppc_account == '1') {
			$error['ppc_account_name'] = 'What is the username for this account?';
		}

		$ppc_network_id = trim((string) $_POST['ppc_network_id']);
		if ($ppc_network_id == '') {
			$error['ppc_network_id'] = 'What traffic source is this account attached to?';
		}

		if (empty($error)) {
			//check to see if this user is the owner of the ppc network hes trying to add an account to
			$mysql['ppc_network_id'] = $db->real_escape_string((string)($_POST['ppc_network_id'] ?? ''));
			$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);

			$ppc_network_sql = "SELECT * FROM `202_ppc_networks` WHERE `user_id`='" . $mysql['user_id'] . "' AND `ppc_network_id`='" . $mysql['ppc_network_id'] . "'";
			$ppc_network_result = _mysqli_query($ppc_network_sql); //($ppc_network_sql);
			if ($ppc_network_result->num_rows == 0) {
				$error['wrong_user'] = 'You are not authorized to add an account to another user\'s traffic source';
			}
		}
		if (empty($error)) {
			//check to see if this user is the owner of the ppc network hes trying to edit
			$mysql['ppc_network_id'] = $db->real_escape_string((string)($_POST['ppc_network_id'] ?? ''));
			$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);

			$ppc_network_sql = "SELECT * FROM `202_ppc_networks` WHERE `user_id`='" . $mysql['user_id'] . "' AND `ppc_network_id`='" . $mysql['ppc_network_id'] . "'";
			$ppc_network_result = _mysqli_query($ppc_network_sql); //($ppc_network_sql);
			if ($ppc_network_result->num_rows == 0) {
				$error['wrong_user'] = 'You are not authorized to add an account to another user\'s traffic source' . $ppc_network_sql;
			}
		}
		if (empty($error)) {
			//if editing, check to make sure the own the ppc account they are editing
			if ($editing == true) {
				$mysql['ppc_account_id'] = $db->real_escape_string((string)$_GET['edit_ppc_account_id']);
				$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
				$ppc_account_sql = "SELECT * FROM `202_ppc_accounts` LEFT JOIN 202_ppc_account_pixels USING (ppc_account_id) LEFT JOIN 202_pixel_types USING (pixel_type_id) WHERE `user_id`='" . $mysql['user_id'] . "' AND `ppc_account_id`='" . $mysql['ppc_account_id'] . "'";
				$ppc_account_result = _mysqli_query($ppc_account_sql); //($ppc_account_sql);
				if ($ppc_account_result->num_rows == 0) {
					$error['wrong_user'] .= 'You are not authorized to modify another user\'s traffic source account';
				}

				$ppc_old_account_row = $ppc_account_result->fetch_assoc();
			}
		}

		if (empty($error)) {

			$ppc_network_row = $ppc_network_result->fetch_assoc();
			$mysql['ppc_network_id'] = $db->real_escape_string((string)($_POST['ppc_network_id'] ?? ''));
			$mysql['ppc_account_name'] = $db->real_escape_string((string)$_POST['ppc_account_name']);
			$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
			$mysql['ppc_account_time'] = time();

			if ($editing == true) {
				$ppc_account_sql  = " UPDATE 202_ppc_accounts SET";
			} else {
				$ppc_account_sql  = " INSERT INTO 202_ppc_accounts SET";
			}

			$ppc_account_sql .= " ppc_account_name='" . $mysql['ppc_account_name'] . "',
								  ppc_network_id='" . $mysql['ppc_network_id'] . "',
								  user_id='" . $mysql['user_id'] . "',
								  ppc_account_time='" . $mysql['ppc_account_time'] . "'";

			if ($editing == true) {
				$ppc_account_sql  .= "WHERE ppc_account_id='" . $mysql['ppc_account_id'] . "'";
			}

			$ppc_account_result = _mysqli_query($ppc_account_sql); //($ppc_account_sql);
			$add_success = true;
			$the_ppc_account_id = $db->insert_id != 0 ? $db->insert_id : $mysql['ppc_account_id'];

			foreach ($_POST['pixel_type_id'] as $key => $value) {
				$mysql['pixel_type_id'] = $db->real_escape_string($value);
				$mysql['pixel_id'] = $db->real_escape_string($_POST['pixel_id'][$key]);

				$pixel_type_sql = "SELECT * FROM `202_pixel_types` WHERE pixel_type_id = '" . $mysql['pixel_type_id'] . "'";
				$pixel_type_result = _mysqli_query($pixel_type_sql);
				$pixel_type_row = $pixel_type_result->fetch_assoc();

				$pixelCode = trim((string) $_POST['pixel_code'][$key]);
				$mysql['pixel_code'] = $db->real_escape_string($pixelCode);

				if ($mysql['pixel_code'] != "" && $mysql['pixel_type_id'] != "") {

					if ($mysql['pixel_id'] != "") {
						$pixel_sql = "UPDATE 202_ppc_account_pixels SET pixel_code='" . $mysql['pixel_code'] . "', pixel_type_id=" . $mysql['pixel_type_id'] . " WHERE pixel_id=" . $mysql['pixel_id'] . "";

						if ($slack) {
							if ($ppc_old_account_row['pixel_type_id'] != $value) {
								$slack->push('traffic_source_account_pixel_type_changed', ['network_name' => $ppc_network_row['ppc_network_name'], 'account_name' => $ppc_old_account_row['ppc_account_name'], 'old_pixel_type' => $ppc_old_account_row['pixel_type'], 'new_pixel_type' => $pixel_type_row['pixel_type'], 'user' => $user_row['username']]);
							}

							if ($ppc_old_account_row['pixel_code'] != $_POST['pixel_code'][$key]) {
								$slack->push('traffic_source_account_pixel_code_changed', ['network_name' => $ppc_network_row['ppc_network_name'], 'account_name' => $ppc_old_account_row['ppc_account_name'], 'user' => $user_row['username']]);
							}
						}
						$db->query($pixel_sql);
						$pixel_ids[] = $mysql['pixel_id'];
					} else {
						$pixel_sql = "INSERT INTO 202_ppc_account_pixels (ppc_account_id, pixel_code,pixel_type_id)
								VALUES(" . $the_ppc_account_id . ",'"
							. $mysql['pixel_code'] . "',"
							. $mysql['pixel_type_id'] . ")";

						$slack_pixel_added_message_vars = ['type' => $pixel_type_row['pixel_type'], 'network_name' => $ppc_network_row['ppc_network_name'], 'account_name' => $_POST['ppc_account_name'], 'user' => $user_row['username']];
						$slack_pixel_added_message = true;

						$db->query($pixel_sql);
						$pixel_ids[] = $db->insert_id;
					}

					$sql = "DELETE FROM 202_ppc_account_pixels WHERE pixel_id NOT IN (" . implode(",", $pixel_ids) . ") AND ppc_account_id=" . $the_ppc_account_id;
					//_mysqli_query($sql);
				}

				if ($editing == true) {
					if ($slack) {
						if ($ppc_old_account_row['ppc_account_name'] != $_POST['ppc_account_name']) {
							$slack->push('traffic_source_account_name_changed', ['network_name' => $ppc_network_row['ppc_network_name'], 'old_account_name' => $ppc_old_account_row['ppc_account_name'], 'new_account_name' => $_POST['ppc_account_name'], 'user' => $user_row['username']]);
						}
					}
					//if editing true, refresh back with the edit get variable GONE GONE!
					//_mysqli_query($sql);

				} else {
					if ($slack) {
						$slack->push('traffic_source_account_created', ['account_name' => $_POST['ppc_account_name'], 'network_name' => $ppc_network_row['ppc_network_name'], 'user' => $user_row['username']]);

						if ($slack_pixel_added_message) {
							$slack->push('traffic_source_account_pixel_added', $slack_pixel_added_message_vars);
						}
					}
				}
			}
			if (isset($sql) && !empty($sql)) {
				_mysqli_query($sql);
			}
			header('location: ' . get_absolute_url() . 'tracking202/setup/ppc_accounts.php');
		}
	}
}

if (isset($_GET['delete_ppc_network_id'])) {

	if ($userObj->hasPermission("remove_traffic_source")) {
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$mysql['ppc_network_id'] = $db->real_escape_string((string)$_GET['delete_ppc_network_id']);
		$mysql['ppc_network_time'] = time();

		$delete_sql = " UPDATE  `202_ppc_networks`
						SET     `ppc_network_deleted`='1',
								`ppc_network_time`='" . $mysql['ppc_network_time'] . "'
						WHERE   `user_id`='" . $mysql['user_id'] . "'
						AND     `ppc_network_id`='" . $mysql['ppc_network_id'] . "'";
		if ($delete_result = _mysqli_query($delete_sql)) { //($delete_result)) {
			$delete_success = true;
			if ($slack)
				$slack->push('traffic_source_deleted', ['name' => $_GET['delete_ppc_network_name'], 'user' => $user_row['username']]);
		}
	} else {
		header('location: ' . get_absolute_url() . 'tracking202/setup/ppc_accounts.php');
	}
}

if (isset($_GET['delete_ppc_account_id'])) {

	if ($userObj->hasPermission("remove_traffic_source_account")) {
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$mysql['ppc_account_id'] = $db->real_escape_string((string)$_GET['delete_ppc_account_id']);
		$mysql['ppc_account_time'] = time();

		$delete_sql = " UPDATE  `202_ppc_accounts`
						SET     `ppc_account_deleted`='1',
								`ppc_account_time`='" . $mysql['ppc_account_time'] . "'
						WHERE   `user_id`='" . $mysql['user_id'] . "'
						AND     `ppc_account_id`='" . $mysql['ppc_account_id'] . "'";
		if ($delete_result = _mysqli_query($delete_sql)) {
			$delete_success = true;
			if ($slack)
				$slack->push('traffic_source_account_deleted', ['account_name' => $_GET['delete_ppc_account_name'], 'user' => $user_row['username']]);
		}
	} else {
		header('location: ' . get_absolute_url() . 'tracking202/setup/ppc_accounts.php');
	}
}

if (!empty($_GET['edit_ppc_network_id'])) {

	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
	$mysql['ppc_network_id'] = $db->real_escape_string((string)$_GET['edit_ppc_network_id']);

	$ppc_network_sql = "SELECT  *
						 FROM   `202_ppc_networks`
						 WHERE  `ppc_network_id`='" . $mysql['ppc_network_id'] . "'
						 AND    `user_id`='" . $mysql['user_id'] . "'";
	$ppc_network_result = _mysqli_query($ppc_network_sql);
	$ppc_network_row = $ppc_network_result->fetch_assoc();

	$html['ppc_network_name'] = htmlentities((string)($ppc_network_row['ppc_network_name'] ?? ''), ENT_QUOTES, 'UTF-8');
	$autocomplete_ppc_network_name =  $html['ppc_network_name'];
}

if (!empty($_GET['edit_ppc_account_id'])) {

	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
	$mysql['ppc_account_id'] = $db->real_escape_string((string)$_GET['edit_ppc_account_id']);

	$ppc_account_sql = "SELECT  *
						 FROM   `202_ppc_accounts`
						 WHERE  `ppc_account_id`='" . $mysql['ppc_account_id'] . "'
						 AND    `user_id`='" . $mysql['user_id'] . "'";
	$ppc_account_result = _mysqli_query($ppc_account_sql); //($ppc_account_sql);
	$ppc_account_row = $ppc_account_result->fetch_assoc();

	$selected['ppc_network_id'] = $ppc_account_row['ppc_network_id'];
	$html['ppc_account_name'] = htmlentities((string)($ppc_account_row['ppc_account_name'] ?? ''), ENT_QUOTES, 'UTF-8');


	$selected['ppc_network_id'] = $ppc_account_row['ppc_network_id'];
	$ppc_account_pixel_sql = "SELECT  *
						 FROM   `202_ppc_account_pixels`
						 WHERE  `ppc_account_id`=" . $mysql['ppc_account_id'] . "";
	//echo $ppc_account_pixel_sql;
	$ppc_account_pixel_result = _mysqli_query($ppc_account_pixel_sql); //($ppc_account_sql);

	$pixel_array = [];

	if ($ppc_account_pixel_result->num_rows > 0) {
		while ($ppc_account_pixel_row = $ppc_account_pixel_result->fetch_assoc()) {
			if ($ppc_account_pixel_row['pixel_type_id'] == 5) {
				$selected['pixel_code'] = stripslashes((string) $ppc_account_pixel_row['pixel_code']);
				$selected['pixel_code'] = htmlentities($selected['pixel_code']);
			} else {
				$selected['pixel_code'] = $ppc_account_pixel_row['pixel_code'];
			}

			$pixel_array[] = ['pixel_type_id' => $ppc_account_pixel_row['pixel_type_id'], 'pixel_code' => $selected['pixel_code'], 'pixel_id' => $ppc_account_pixel_row['pixel_id']];
		}
	}
}

if (!empty($error)) {
	//if someone happend take the post stuff and add it
	$selected['ppc_network_id'] = $_POST['ppc_network_id'] ?? '';
	$html['ppc_account_name'] = htmlentities((string)($_POST['ppc_account_name'] ?? ''), ENT_QUOTES, 'UTF-8');
}


template_top('Traffic Sources'); ?>



<!-- Page Header - Design System -->
<div class="row" style="margin-bottom: 28px;">
	<div class="col-xs-12">
		<div class="setup-page-header">
			<div class="setup-page-header__icon">
				<span class="glyphicon glyphicon-globe"></span>
			</div>
			<div class="setup-page-header__text">
				<h1 class="setup-page-header__title">Traffic Sources</h1>
				<p class="setup-page-header__subtitle">Add traffic sources (PPC, Display, Social, Email) and configure tracking accounts</p>
			</div>
		</div>
	</div>
</div>

<?php if (!empty($error)) { ?>
<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<div class="alert alert-danger">
			<i class="fa fa-exclamation-circle"></i> There were errors with your submission. <?php echo $error['token'] ?? ''; ?>
		</div>
	</div>
</div>
<?php } ?>

<?php if ($add_success == true) { ?>
<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<div class="alert alert-success">
			<i class="fa fa-check-circle"></i> Your submission was successful. Your changes have been saved.
		</div>
	</div>
</div>
<?php } ?>

<?php if ($delete_success == true) { ?>
<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<div class="alert alert-success">
			<i class="fa fa-check-circle"></i> Your deletion was successful. You have successfully removed an account.
		</div>
	</div>
</div>
<?php } ?>

<div class="row form_seperator" style="margin-bottom:15px;">
	<div class="col-xs-12"></div>
</div>

<div class="row">
	<div class="col-md-6">
		<div class="row">
			<div class="col-xs-12">
				<small><strong>Add Traffic Source</strong></small><br />
				<span class="infotext">What Traffic Sources do you use? Some examples include, Facebook Ads, Twitter Ads, BingAds, & Google Adwords.</span>

				<form method="post" action="<?php echo $_SERVER['REDIRECT_URL'] ?? ''; ?>" class="form-inline" role="form" style="margin:15px 0px;">
					<div class="form-group <?php if (isset($error['ppc_network_name'])) echo "has-error"; ?>"
						<label class="sr-only" for="ppc_network_name">Traffic source</label>
						<input type="text" class="form-control input-sm" id="ppc_network_name" name="ppc_network_name" placeholder="Traffic source" value="<?php echo $html['ppc_network_name'] ?? ''; ?>">
					</div>
					<button type="submit" class="btn btn-xs btn-p202"><?php if ($network_editing == true) {
																			echo 'Edit';
																		} else {
																			echo 'Add';
																		} ?></button>
					<?php if ($network_editing == true) { ?>
						<input type="hidden" name="ppc_network_id" value="<?php echo filter_input(INPUT_GET, 'edit_ppc_network_id', FILTER_SANITIZE_NUMBER_INT); ?>">
						<button type="submit" class="btn btn-xs btn-danger" onclick="window.location='<?php echo get_absolute_url(); ?>tracking202/setup/ppc_accounts.php'; return false;">Cancel</button>
					<?php } ?>
				</form>

			</div>

			<div class="col-xs-12" style="margin-top: 15px;">
				<small><strong>Add Traffic Source Accounts and Pixels</strong></small><br />
				<span class="infotext">What accounts to do you have with each Traffic Source? For instance, if you have two Facebook accounts, you can add them both here. This way you can track how individual accounts on each source are doing.</span>

				<form style="margin:15px 0px;" method="post" action="<?php if (isset($delete_success) && $delete_success == true) {
																			echo $_SERVER['REDIRECT_URL'] ?? '';
																		} ?>" class="form-horizontal" role="form">
					<div class="form-group <?php if (isset($error['ppc_network_id'])) echo "has-error"; ?>" style="margin-bottom: 0px;">
						<label for="ppc_network_id" class="col-xs-4 control-label" style="text-align: left;">Traffic Source:</label>
						<div class="col-xs-5">
							<select class="form-control input-sm" name="ppc_network_id" id="ppc_network_id">
								<option value="">---</option>
								<?php $mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
								$ppc_network_sql = "SELECT * FROM `202_ppc_networks` WHERE `user_id`='" . $mysql['user_id'] . "' AND `ppc_network_deleted`='0' ORDER BY `ppc_network_name` ASC";
								$ppc_network_result = _mysqli_query($ppc_network_sql); //($ppc_network_sql);
								while ($ppc_network_row = $ppc_network_result->fetch_array(MYSQLI_ASSOC)) {

									$html['ppc_network_name'] = htmlentities((string)($ppc_network_row['ppc_network_name'] ?? ''), ENT_QUOTES, 'UTF-8');
									$html['ppc_network_id'] = htmlentities((string)($ppc_network_row['ppc_network_id'] ?? ''), ENT_QUOTES, 'UTF-8');


									if (isset($selected['ppc_network_id']) && $selected['ppc_network_id'] == $ppc_network_row['ppc_network_id']) {
										printf('<option selected="selected" value="%s">%s</option>', $html['ppc_network_id'], $html['ppc_network_name']);
									} else {
										printf('<option value="%s">%s</option>', $html['ppc_network_id'], $html['ppc_network_name']);
									}
								} ?>
							</select>
							<input type="hidden" name="do_edit_ppc_account" value="1">
						</div>
					</div>

					<div class="form-group <?php if (isset($error['ppc_account_name'])) echo "has-error"; ?>" style="margin-bottom: 0px;">
						<label for="ppc_account_name" class="col-xs-4 control-label" style="text-align: left;">Account Username:</label>
						<div class="col-xs-5">
							<input type="ppc_account_name" class="form-control input-sm" id="ppc_account_name" name="ppc_account_name" value="<?php echo $html['ppc_account_name'] ?? ''; ?>">
						</div>
					</div>

					<div class="pixel-container">
						<?php for ($i = 0; $i < count($pixel_array); $i++) { ?>
							<div class="pixel">
								<div class="form-group" style="margin-bottom: 0px;">
									<label for="pixel_type_id[]" class="col-xs-4 control-label" style="text-align: left;">Pixel Type:</label> <span class="fui-info-circle" style="font-size: 12px;" data-toggle="tooltip" title="" data-original-title="Optional: Select the type of pixel this traffic source uses"></span>
									<div class="col-xs-5">
										<select class="form-control input-sm" name="pixel_type_id[]" id="pixel_type_id[]">
											<option value="">---</option>
											<?php
											foreach ($pixel_types as $pixel_type) {
												if ($pixel_array[$i]['pixel_type_id'] == $pixel_type['pixel_type_id']) {
													printf('<option selected="selected" value="%s">%s</option>', $pixel_type['pixel_type_id'], $pixel_type['pixel_type']);
												} else {
													printf('<option value="%s">%s</option>', $pixel_type['pixel_type_id'], $pixel_type['pixel_type']);
												}
											}
											?>
										</select>
										<input type="hidden" name="do_edit_ppc_account" value="1">
										<?php if ($i > 0) { ?>
											<span class="fui-cross" id="remove_pixel" style="position:absolute; font-size:12px; cursor:pointer; margin:0px; top: 11px; left: -5px;"></span>
										<?php } ?>
									</div>
								</div>

								<div class="form-group">
									<label for="pixel_code" class="col-xs-4 control-label" style="text-align: left;">Pixel Code:</label> <span class="fui-info-circle" style="font-size: 12px;" data-toggle="tooltip" title="" data-original-title="Optional: If you selected a Pixel Type above then enter the code for the pixel here. For all pixel types, except for Raw, simply type in the url value of the src"></span>
									<div class="col-xs-5">
										<textarea class="form-control" name="pixel_code[]" id="pixel_code[]" rows="3"><?php echo $pixel_array[$i]['pixel_code']; ?></textarea>
										<input type="hidden" name="pixel_id[]" value="<?php echo $pixel_array[$i]['pixel_id']; ?>">
									</div>
								</div>
							</div>
						<?php } ?>
					</div>

					<div class="form-group" style="margin-top:7px;">
						<div class="col-xs-5 col-xs-offset-4">
							<button class="btn btn-xs btn-default btn-block" id="add_more_pixels" type="button" data-loading-text="Loading...">Add More Pixels</button>

							<?php if ($editing == true) { ?>
								<div class="row" style="margin-top: 10px;">
									<div class="col-xs-6">
										<button class="btn btn-sm btn-p202 btn-block" type="submit">Edit</button>
									</div>
									<div class="col-xs-6">
										<button type="submit" class="btn btn-sm btn-danger btn-block" onclick="window.location='<?php echo get_absolute_url(); ?>tracking202/setup/ppc_accounts.php'; return false;">Cancel</button>
									</div>
								</div>
							<?php } else { ?>
								<button class="btn btn-sm btn-p202 btn-block" type="submit">Add</button>
							<?php } ?>
						</div>
					</div>

				</form>

			</div>
		</div>
	</div>
		<div class="col-md-6">
			<div class="panel panel-default setup-side-panel">
			<?php
			$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
			$ppc_network_sql = "SELECT * FROM `202_ppc_networks` WHERE `user_id`='" . $mysql['user_id'] . "' AND `ppc_network_deleted`='0' ORDER BY `ppc_network_name` ASC";
			$ppc_network_result = _mysqli_query($ppc_network_sql);
			$traffic_source_total = (int)$ppc_network_result->num_rows;
			?>
			<div class="panel-heading traffic-sources-heading">
				<span>My Traffic Sources</span>
				<span class="traffic-sources-heading__meta"><?php echo $traffic_source_total; ?> source<?php echo ($traffic_source_total === 1) ? '' : 's'; ?></span>
			</div>
			<div class="panel-body">
				<div id="trafficSourceList" class="traffic-source-list">
					<div class="traffic-source-toolbar">
						<div class="traffic-source-search-wrap">
							<span class="fa fa-search traffic-source-search-icon" aria-hidden="true"></span>
							<input class="form-control input-sm search fuzzy-search traffic-source-search" placeholder="Filter sources or accounts">
						</div>
					</div>
					<ul class="list source-list">
						<?php if ($traffic_source_total === 0) { ?>
							<li class="empty-state">No traffic sources added yet. Add your first source on the left.</li>
						<?php } ?>
						<?php
						while ($ppc_network_row = $ppc_network_result->fetch_array(MYSQLI_ASSOC)) {
							$html['ppc_network_name'] = htmlentities((string)($ppc_network_row['ppc_network_name'] ?? ''), ENT_QUOTES, 'UTF-8');
							$url['ppc_network_id'] = urlencode((string)$ppc_network_row['ppc_network_id']);
							$url['ppc_network_name'] = urlencode((string)($ppc_network_row['ppc_network_name'] ?? ''));

							$mysql['ppc_network_id'] = $db->real_escape_string((string)$ppc_network_row['ppc_network_id']);
							$ppc_account_sql = "SELECT * FROM `202_ppc_accounts` WHERE `ppc_network_id`='" . $mysql['ppc_network_id'] . "' AND `ppc_account_deleted`='0' ORDER BY `ppc_account_name` ASC";
							$ppc_account_result = _mysqli_query($ppc_account_sql);
							$account_count = (int)$ppc_account_result->num_rows;
							$account_label = ($account_count === 1) ? 'account' : 'accounts';
						?>
							<li class="source-item">
								<div class="source-header">
									<div class="source-meta">
										<span class="filter_source_name source-name"><?php echo $html['ppc_network_name']; ?></span>
										<span class="source-account-count"><?php echo $account_count . ' ' . $account_label; ?></span>
									</div>
									<div class="source-actions">
										<a href="?edit_ppc_network_id=<?php echo $url['ppc_network_id']; ?>&edit_ppc_network_name=<?php echo $url['ppc_network_name']; ?>" class="list-action">edit</a>
										<?php if ($userObj->hasPermission("remove_traffic_source")) { ?>
											<a href="#" class="custom variables list-action" data-id="<?php echo $url['ppc_network_id']; ?>">variables</a>
											<a href="?delete_ppc_network_id=<?php echo $url['ppc_network_id']; ?>&delete_ppc_network_name=<?php echo $url['ppc_network_name']; ?>" class="list-action list-action-danger" onclick="return confirmSubmit('Are You Sure You Want To Delete This Traffic Source?');">remove</a>
										<?php } ?>
									</div>
								</div>

								<ul class="account-list">
									<?php if ($account_count === 0) { ?>
										<li class="account-item-empty">No accounts added yet</li>
									<?php } ?>
									<?php while ($ppc_account_row = $ppc_account_result->fetch_array(MYSQLI_ASSOC)) {
										$html['ppc_account_name'] = htmlentities((string)($ppc_account_row['ppc_account_name'] ?? ''), ENT_QUOTES, 'UTF-8');
										$url['ppc_account_id'] = urlencode((string)$ppc_account_row['ppc_account_id']);
										$url['ppc_account_name'] = urlencode((string)($ppc_account_row['ppc_account_name'] ?? ''));
									?>
										<li class="account-item">
											<span class="filter_account_name account-name"><?php echo $html['ppc_account_name']; ?></span>
											<div class="account-actions">
												<a href="?edit_ppc_account_id=<?php echo $url['ppc_account_id']; ?>" class="list-action">edit</a>
												<?php if ($userObj->hasPermission("remove_traffic_source_account")) { ?>
													<a href="?delete_ppc_account_id=<?php echo $url['ppc_account_id']; ?>&delete_ppc_account_name=<?php echo $url['ppc_account_name']; ?>" class="list-action list-action-danger" onclick="return confirmSubmit('Are You Sure You Want To Delete This Account?');">remove</a>
												<?php } ?>
											</div>
										</li>
									<?php } ?>
								</ul>
							</li>
						<?php } ?>
					</ul>
				</div>
			</div>
		</div>
	</div>
</div>

<div class="modal fade" id="variablesModel" tabindex="-1" role="dialog" aria-labelledby="variablesModelLabel" aria-hidden="true">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close fui-cross" data-dismiss="modal" aria-hidden="true"></button>
				<h4 class="modal-title" id="myModalLabel">Add Custom Variables</h4>
				<div class="alert alert-danger small variables_validate_alert" role="alert">ERROR! Make Sure all field are filled out!</div>
			</div>
			<div class="modal-body">
				<div class="row">
					<div class="col-xs-12"></div>
					<div class="row">
						<div class="col-xs-4"><small>Name <i class="fa fa-question-circle variables-info-pop" data-content="Variable name in report" data-placement="top" data-toggle="popover" data-container="body"></i></small></div>
						<div class="col-xs-4"><small>Parameter <i class="fa fa-question-circle variables-info-pop" data-content="Parameter in url. Example: p202.com?parameter=[[placeholder]]" data-placement="top" data-toggle="popover" data-container="body"></i></small></div>
						<div class="col-xs-4"><small>Placeholder <i class="fa fa-question-circle variables-info-pop" data-content="Placeholder in url. Example: p202.com?parameter=[[placeholder]]" data-placement="top" data-toggle="popover" data-container="body"></i></small></div>
					</div>
					<div class="row form_seperator" style="margin-bottom: 5px;margin-top: 5px;margin-right: 0px;">
						<div class="col-xs-12"></div>
					</div>
					<div class="row">
						<form method="post" id="custom-variables-form" class="form-inline" role="form">
							<input type="hidden" id="ppc_network_id" name="ppc_network_id" value="">
							<div class="col-xs-12" id="variable-group">
								<div class="row var-field-group" style="margin-bottom: 10px;" data-var-id="">
									<div class="col-xs-4">
										<div class="form-group">
											<label for="name" class="sr-only">Name</label>
											<input type="text" class="form-control input-sm" name="name">
										</div>
									</div>
									<div class="col-xs-4">
										<div class="form-group">
											<label for="parameter" class="sr-only">Parameter</label>
											<input type="text" class="form-control input-sm" name="parameter">
										</div>
									</div>
									<div class="col-xs-4">
										<div class="form-group">
											<label for="placeholder" class="sr-only">Placeholder</label>
											<input type="text" class="form-control input-sm" name="placeholder">
										</div>
									</div>
								</div>
							</div>
							<div class="col-xs-12 text-right"><small style="margin-right: 13px;"><a href="#" id="add_more_variables"><i class="fa fa-plus"></i> add more</a></small></div>
						</form>
					</div>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
				<button type="button" id="add_variables_form_submit" data-loading-text="Loading..." autocomplete="off" class="btn btn-primary">Add variables</button>
			</div>
		</div>
	</div>
</div>
</div>
<script type="text/javascript">
	$(document).ready(function() {
		// Simple input - no tokenfield needed for single value
		$(".variables-info-pop").popover({
			trigger: "hover"
		});

		var trafficSourceOptions = {
			valueNames: ['filter_source_name', 'filter_account_name'],
			plugins: [
				ListFuzzySearch()
			]
		};

		var trafficSourceList = new List('trafficSourceList', trafficSourceOptions);
	});
</script>

<link rel="stylesheet" href="<?php echo get_absolute_url();?>202-css/design-system.css">

<style>
/* ===========================================
   TRAFFIC SOURCES - Enhanced Design System
   =========================================== */

/* Page Header Styles */
.setup-page-header {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 24px;
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    border-radius: 12px;
    color: #fff;
    box-shadow: 0 4px 15px rgba(0, 123, 255, 0.2);
}

.setup-page-header__icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 56px;
    height: 56px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    flex-shrink: 0;
}

.setup-page-header__icon .glyphicon {
    font-size: 24px;
    color: #fff;
}

.setup-page-header__text {
    flex: 1;
}

.setup-page-header__title {
    margin: 0 0 4px 0;
    font-size: 24px;
    font-weight: 600;
    color: #fff;
}

.setup-page-header__subtitle {
    margin: 0;
    font-size: 14px;
    color: rgba(255, 255, 255, 0.9);
    font-weight: 400;
}

/* Panel Styles - Design System */
.setup-panel,
.panel-default {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    overflow: hidden;
    background: #fff;
}

.panel-heading {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-bottom: 1px solid #e2e8f0;
    padding: 16px 20px;
    font-weight: 600;
    color: #1e293b;
}

.panel-body {
    padding: 20px;
}

/* Form Group Styles */
.setup-form-group {
    margin-bottom: 16px;
}

.setup-form-group:last-child {
    margin-bottom: 0;
}

.form-group label {
    display: block;
    margin-bottom: 6px;
    font-weight: 500;
    color: #374151;
    font-size: 14px;
}

.form-group input[type="text"],
.form-group input[type="email"],
.form-group input[type="number"],
.form-group textarea,
.form-group select {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 14px;
    transition: all 0.2s ease;
}

.form-group input:focus,
.form-group textarea:focus,
.form-group select:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
}

/* Button Styles */
.setup-btn,
.btn {
    display: inline-block;
    padding: 8px 16px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    border: none;
}

.setup-btn-primary,
.btn-p202 {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    color: #fff;
    border: 1px solid #0056b3;
}

.setup-btn-primary:hover,
.btn-p202:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3);
}

.setup-btn-secondary {
    background: #f3f4f6;
    color: #374151;
    border: 1px solid #d1d5db;
}

.setup-btn-secondary:hover {
    background: #e5e7eb;
    border-color: #9ca3af;
}

.setup-btn-danger,
.btn-danger {
    background: #ef4444;
    color: #fff;
    border: 1px solid #dc2626;
}

.setup-btn-danger:hover,
.btn-danger:hover {
    background: #dc2626;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
}

/* Alert Styles */
.setup-alert,
.alert {
    padding: 12px 16px;
    border-radius: 8px;
    border: 1px solid;
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
}

.setup-alert-success,
.alert-success {
    background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
    border-color: #86efac;
    color: #166534;
}

.setup-alert-danger,
.alert-danger {
    background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
    border-color: #fca5a5;
    color: #991b1b;
}

.setup-alert-warning {
    background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
    border-color: #fcd34d;
    color: #92400e;
}

.setup-alert-info {
    background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
    border-color: #7dd3fc;
    color: #0c4a6e;
}

.setup-alert i,
.alert i {
    flex-shrink: 0;
}

/* Info Text */
.infotext {
    display: block;
    font-size: 13px;
    color: #6b7280;
    margin: 8px 0 12px 0;
    line-height: 1.4;
}

/* Pixel Container */
.pixel-container {
    margin: 16px 0;
    padding: 16px;
    background: #f9fafb;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
}

.pixel {
    margin-bottom: 16px;
    padding-bottom: 16px;
    border-bottom: 1px solid #e5e7eb;
}

.pixel:last-child {
    margin-bottom: 0;
    padding-bottom: 0;
    border-bottom: none;
}

#remove_pixel {
    cursor: pointer;
    color: #ef4444;
    transition: color 0.2s ease;
}

#remove_pixel:hover {
    color: #dc2626;
}

/* Form Separator */
.form_seperator {
    border-bottom: 1px solid #e5e7eb;
}

/* Setup List */
.setup-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.traffic-sources-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.traffic-sources-heading__meta {
    font-size: 12px;
    font-weight: 500;
    color: #475569;
    background: #fff;
    border: 1px solid #cbd5e1;
    border-radius: 999px;
    padding: 2px 10px;
}

.traffic-source-toolbar {
    margin-bottom: 14px;
}

.traffic-source-search-wrap {
    position: relative;
}

.traffic-source-search-icon {
    position: absolute;
    top: 50%;
    left: 12px;
    transform: translateY(-50%);
    color: #94a3b8;
    pointer-events: none;
}

.traffic-source-search {
    background: #f8fafc;
    border-color: #cbd5e1;
    padding-left: 34px !important;
    min-height: 36px;
}

.traffic-source-search:focus {
    background: #fff;
}

#trafficSourceList .source-list {
    list-style: none;
    padding: 0 4px 0 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-height: 560px;
    overflow-y: auto;
}

#trafficSourceList .source-item {
    border: 1px solid #dbe3ec;
    border-radius: 10px;
    background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
    padding: 12px;
    transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
}

#trafficSourceList .source-item:hover {
    border-color: #9ec5fe;
    box-shadow: 0 6px 18px rgba(15, 23, 42, 0.08);
    transform: translateY(-1px);
}

#trafficSourceList .source-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
}

#trafficSourceList .source-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
    flex-wrap: wrap;
}

#trafficSourceList .source-name {
    font-size: 14px;
    font-weight: 600;
    color: #1e293b;
}

#trafficSourceList .source-account-count {
    font-size: 11px;
    font-weight: 600;
    color: #1e40af;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 999px;
    padding: 2px 8px;
}

#trafficSourceList .source-actions,
#trafficSourceList .account-actions {
    display: inline-flex;
    align-items: center;
    justify-content: flex-end;
    gap: 6px;
    flex-wrap: wrap;
}

#trafficSourceList .account-list {
    list-style: none;
    margin: 10px 0 0 0;
    padding: 10px 0 0 12px;
    border-top: 1px dashed #dbe3ec;
}

#trafficSourceList .account-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 7px 10px;
    margin-bottom: 6px;
    border-left: 2px solid #dbe3ec;
    border-radius: 0 8px 8px 0;
    background: #f8fafc;
    transition: background 0.2s ease, border-color 0.2s ease;
}

#trafficSourceList .account-item:last-child {
    margin-bottom: 0;
}

#trafficSourceList .account-item:hover {
    background: #eff6ff;
    border-left-color: #60a5fa;
}

#trafficSourceList .account-name {
    font-size: 13px;
    font-weight: 500;
    color: #334155;
}

#trafficSourceList .account-item-empty {
    font-size: 12px;
    color: #64748b;
    padding: 6px 0 2px;
    font-style: italic;
}

/* List Action Links */
.list-action {
    color: #2563eb;
    text-decoration: none;
    font-weight: 600;
    font-size: 12px;
    padding: 4px 9px;
    border-radius: 6px;
    border: 1px solid transparent;
    transition: all 0.2s ease;
    white-space: nowrap;
}

.list-action:hover {
    background-color: #eff6ff;
    border-color: #bfdbfe;
    color: #1d4ed8;
    text-decoration: none;
}

.list-action-danger {
    color: #dc2626;
}

.list-action-danger:hover {
    background-color: #fef2f2;
    color: #991b1b;
}

.setup-list-empty,
#trafficSourceList .empty-state {
    text-align: center;
    padding: 24px 16px;
    color: #64748b;
    border: 1px dashed #cbd5e1;
    border-radius: 10px;
    background: #f8fafc;
}

/* Input and Select Improvements */
.form-control {
    border: 1px solid #d1d5db;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 14px;
    transition: all 0.2s ease;
    box-shadow: none !important;
    -webkit-box-shadow: none !important;
    -webkit-appearance: none;
    background-color: #fff;
}

.form-control:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1) !important;
}

.form-control.input-sm {
    padding: 8px 12px;
    font-size: 13px;
}

/* Tokenfield override - clean minimal styling */
.tokenfield,
.tokenfield.form-control {
    padding: 6px 12px !important;
    height: auto !important;
    min-height: 38px !important;
    box-shadow: none !important;
    -webkit-box-shadow: none !important;
    border: 1px solid #d1d5db !important;
    border-radius: 8px !important;
    background-color: #fff !important;
    display: flex !important;
    align-items: center !important;
    flex-wrap: wrap !important;
    gap: 4px !important;
}
.tokenfield .token {
    background: transparent !important;
    border: none !important;
    border-radius: 0 !important;
    padding: 0 !important;
    margin: 0 4px 0 0 !important;
    height: auto !important;
    color: #374151 !important;
    font-size: 14px !important;
}
.tokenfield .token .token-label {
    padding: 0 !important;
}
.tokenfield .token .close {
    display: none !important;
}
.tokenfield .token-input,
.tokenfield.form-control .token-input {
    box-shadow: none !important;
    -webkit-box-shadow: none !important;
    border: none !important;
    outline: none !important;
    background: transparent !important;
    height: 24px !important;
    margin: 0 !important;
    padding: 0 !important;
    flex: 1 !important;
    min-width: 60px !important;
}
.tokenfield.focus,
.tokenfield.form-control.focus {
    border-color: #007bff !important;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1) !important;
    -webkit-box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1) !important;
}

/* Search Input */
.search {
    border: 1px solid #d1d5db;
    box-shadow: none !important;
}

.search:focus {
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1) !important;
}

/* Modal Improvements */
.modal-header {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-bottom: 1px solid #e2e8f0;
}

.modal-title {
    color: #1e293b;
    font-weight: 600;
}

.variables_validate_alert {
    margin-top: 12px;
}

/* Responsive Design */
@media (max-width: 768px) {
    .setup-page-header {
        flex-direction: column;
        text-align: center;
        padding: 20px 16px;
    }

    .setup-page-header__icon {
        width: 48px;
        height: 48px;
    }

    .setup-page-header__icon .glyphicon {
        font-size: 20px;
    }

    .setup-page-header__title {
        font-size: 20px;
    }

    .setup-page-header__subtitle {
        font-size: 13px;
    }

    .form-group label {
        margin-bottom: 4px;
    }

    .setup-btn,
    .btn {
        width: 100%;
        margin-bottom: 8px;
    }

    .pixel-container {
        padding: 12px;
    }

    .pixel {
        margin-bottom: 12px;
        padding-bottom: 12px;
    }

    .traffic-sources-heading {
        flex-direction: column;
        align-items: flex-start;
    }

    #trafficSourceList .source-header {
        flex-direction: column;
        align-items: flex-start;
    }

    #trafficSourceList .source-actions,
    #trafficSourceList .account-actions {
        justify-content: flex-start;
    }

    #trafficSourceList .account-item {
        flex-direction: column;
        align-items: flex-start;
    }
}

@media (max-width: 480px) {
    .setup-page-header {
        padding: 16px 12px;
    }

    .setup-page-header__icon {
        width: 40px;
        height: 40px;
    }

    .setup-page-header__icon .glyphicon {
        font-size: 18px;
    }

    .setup-page-header__title {
        font-size: 18px;
    }

    .setup-page-header__subtitle {
        font-size: 12px;
    }

    #trafficSourceList .source-item {
        padding: 10px;
    }

    #trafficSourceList .account-list {
        padding-left: 8px;
    }
}
</style>

<?php template_bottom(); ?>
