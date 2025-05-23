<?php

declare(strict_types=1);
include_once(substr(dirname(__FILE__), 0, -18) . '/202-config/connect.php');

AUTH::require_user();

if (!$userObj->hasPermission("access_to_setup_section")) {
	header('location: ' . get_absolute_url() . 'tracking202/');
	die();
}

$slack = false;
$slack_pixel_added_message = false;
$error = array();
$html = array();
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
$pixel_array = array();
$pixel_array[] = array('pixel_type_id' => '', 'pixel_code' => '', 'pixel_id' => '');
$pixel_types = array();

$ppc_pixel_type_sql = "SELECT * FROM `202_pixel_types`";
$ppc_pixel_type_result = _mysqli_query($ppc_pixel_type_sql);

while ($ppc_pixel_type_row = $ppc_pixel_type_result->fetch_assoc()) {
	$pixel_types[] = array('pixel_type' => htmlentities((string)($ppc_pixel_type_row['pixel_type'] ?? ''), ENT_QUOTES, 'UTF-8'), 'pixel_type_id' => htmlentities((string)($ppc_pixel_type_row['pixel_type_id'] ?? ''), ENT_QUOTES, 'UTF-8'));
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	if (isset($_POST['ppc_network_name'])) {
		$ppc_network_name = trim($_POST['ppc_network_name']);
		if (empty($ppc_network_name)) {
			$error['ppc_network_name'] = 'Type in the name the traffic source.';
		}

		if (!$error) {
			$mysql['ppc_network_id'] = $db->real_escape_string((string)$_POST['ppc_network_id']);
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
					$slack->push('traffic_source_name_changed', array('old_name' => $_GET['edit_ppc_network_name'], 'new_name' => $_POST['ppc_network_name'], 'user' => $user_row['username']));
				//if editing true, refresh back with the edit get variable GONE GONE!
				header('location: ' . get_absolute_url() . 'tracking202/setup/ppc_accounts.php');
			} else {
				if ($slack)
					$slack->push('traffic_source_created', array('name' => $_POST['ppc_network_name'], 'user' => $user_row['username']));
			}

			tagUserByNetwork($user_row['install_hash'], 'traffic-sources', $_POST['ppc_network_name']);
		}
	}

	if (isset($_POST['ppc_network_id']) && ($network_editing == false)) {

		$pixel_ids = array();

		$ppc_account_name = trim($_POST['ppc_account_name']);
		$do_edit_ppc_account = trim(filter_input(INPUT_POST, 'do_edit_ppc_account', FILTER_SANITIZE_NUMBER_INT));
		if ($ppc_account_name == '' && $do_edit_ppc_account == '1') {
			$error['ppc_account_name'] = 'What is the username for this account?';
		}

		$ppc_network_id = trim($_POST['ppc_network_id']);
		if ($ppc_network_id == '') {
			$error['ppc_network_id'] = 'What traffic source is this account attached to?';
		}

		if (!$error) {
			//check to see if this user is the owner of the ppc network hes trying to add an account to
			$mysql['ppc_network_id'] = $db->real_escape_string((string)$_POST['ppc_network_id']);
			$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);

			$ppc_network_sql = "SELECT * FROM `202_ppc_networks` WHERE `user_id`='" . $mysql['user_id'] . "' AND `ppc_network_id`='" . $mysql['ppc_network_id'] . "'";
			$ppc_network_result = _mysqli_query($ppc_network_sql); //($ppc_network_sql);
			if ($ppc_network_result->num_rows == 0) {
				$error['wrong_user'] = 'You are not authorized to add an account to another user\'s traffic source';
			}
		}
		if (!$error) {
			//check to see if this user is the owner of the ppc network hes trying to edit
			$mysql['ppc_network_id'] = $db->real_escape_string((string)$_POST['ppc_network_id']);
			$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);

			$ppc_network_sql = "SELECT * FROM `202_ppc_networks` WHERE `user_id`='" . $mysql['user_id'] . "' AND `ppc_network_id`='" . $mysql['ppc_network_id'] . "'";
			$ppc_network_result = _mysqli_query($ppc_network_sql); //($ppc_network_sql);
			if ($ppc_network_result->num_rows == 0) {
				$error['wrong_user'] = 'You are not authorized to add an account to another user\'s traffic source' . $ppc_network_sql;
			}
		}
		if (!$error) {
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

		if (!$error) {

			$ppc_network_row = $ppc_network_result->fetch_assoc();
			$mysql['ppc_network_id'] = $db->real_escape_string((string)$_POST['ppc_network_id']);
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

				$pixelCode = trim($_POST['pixel_code'][$key]);
				$mysql['pixel_code'] = $db->real_escape_string($pixelCode);

				if ($mysql['pixel_code'] != "" && $mysql['pixel_type_id'] != "") {

					if ($mysql['pixel_id'] != "") {
						$pixel_sql = "UPDATE 202_ppc_account_pixels SET pixel_code='" . $mysql['pixel_code'] . "', pixel_type_id=" . $mysql['pixel_type_id'] . " WHERE pixel_id=" . $mysql['pixel_id'] . "";

						if ($slack) {
							if ($ppc_old_account_row['pixel_type_id'] != $value) {
								$slack->push('traffic_source_account_pixel_type_changed', array('network_name' => $ppc_network_row['ppc_network_name'], 'account_name' => $ppc_old_account_row['ppc_account_name'], 'old_pixel_type' => $ppc_old_account_row['pixel_type'], 'new_pixel_type' => $pixel_type_row['pixel_type'], 'user' => $user_row['username']));
							}

							if ($ppc_old_account_row['pixel_code'] != $_POST['pixel_code'][$key]) {
								$slack->push('traffic_source_account_pixel_code_changed', array('network_name' => $ppc_network_row['ppc_network_name'], 'account_name' => $ppc_old_account_row['ppc_account_name'], 'user' => $user_row['username']));
							}
						}
						$db->query($pixel_sql);
						$pixel_ids[] = $mysql['pixel_id'];
					} else {
						$pixel_sql = "INSERT INTO 202_ppc_account_pixels (ppc_account_id, pixel_code,pixel_type_id)
								VALUES(" . $the_ppc_account_id . ",'"
							. $mysql['pixel_code'] . "',"
							. $mysql['pixel_type_id'] . ")";

						$slack_pixel_added_message_vars = array('type' => $pixel_type_row['pixel_type'], 'network_name' => $ppc_network_row['ppc_network_name'], 'account_name' => $_POST['ppc_account_name'], 'user' => $user_row['username']);
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
							$slack->push('traffic_source_account_name_changed', array('network_name' => $ppc_network_row['ppc_network_name'], 'old_account_name' => $ppc_old_account_row['ppc_account_name'], 'new_account_name' => $_POST['ppc_account_name'], 'user' => $user_row['username']));
						}
					}
					//if editing true, refresh back with the edit get variable GONE GONE!
					//_mysqli_query($sql);

				} else {
					if ($slack) {
						$slack->push('traffic_source_account_created', array('account_name' => $_POST['ppc_account_name'], 'network_name' => $ppc_network_row['ppc_network_name'], 'user' => $user_row['username']));

						if ($slack_pixel_added_message) {
							$slack->push('traffic_source_account_pixel_added', $slack_pixel_added_message_vars);
						}
					}
				}
			}
			_mysqli_query($sql);
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
				$slack->push('traffic_source_deleted', array('name' => $_GET['delete_ppc_network_name'], 'user' => $user_row['username']));
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
				$slack->push('traffic_source_account_deleted', array('account_name' => $_GET['delete_ppc_account_name'], 'user' => $user_row['username']));
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

	$pixel_array = array();

	if ($ppc_account_pixel_result->num_rows > 0) {
		while ($ppc_account_pixel_row = $ppc_account_pixel_result->fetch_assoc()) {
			if ($ppc_account_pixel_row['pixel_type_id'] == 5) {
				$selected['pixel_code'] = stripslashes($ppc_account_pixel_row['pixel_code']);
				$selected['pixel_code'] = htmlentities($selected['pixel_code']);
			} else {
				$selected['pixel_code'] = $ppc_account_pixel_row['pixel_code'];
			}

			$pixel_array[] = array('pixel_type_id' => $ppc_account_pixel_row['pixel_type_id'], 'pixel_code' => $selected['pixel_code'], 'pixel_id' => $ppc_account_pixel_row['pixel_id']);
		}
	}
}

if ($error) {
	//if someone happend take the post stuff and add it
	$selected['ppc_network_id'] = $_POST['ppc_network_id'];
	$html['ppc_account_name'] = htmlentities((string)($_POST['ppc_account_name'] ?? ''), ENT_QUOTES, 'UTF-8');
}


template_top('Traffic Sources', NULL, NULL, NULL); ?>

<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<div class="row">
			<div class="col-xs-5">
				<h6>Traffic Source Account Setup</h6>
			</div>
			<div class="col-xs-7">
				<div class="<?php if ($error) echo "error";
							else echo "success"; ?> pull-right" style="margin-top: 20px;">
					<small>
						<?php if ($error) { ?>
							<span class="fui-alert"></span> There were errors with your submission. <?php echo isset($error['token']) ? $error['token'] : ''; ?>
						<?php } ?>
						<?php if ($add_success == true) { ?>
							<span class="fui-check-inverted"></span> Your submission was successful. Your changes have been saved.
						<?php } ?>
						<?php if ($delete_success == true) { ?>
							<span class="fui-check-inverted"></span> Your deletion was successful. You have successfully removed an account.
						<?php } ?>
					</small>
				</div>
			</div>
		</div>
	</div>
	<div class="col-xs-12">
		<small>Add the Traffic Source (PPC, Display, Social, Email etc) you use, and usernames for each account you have.</small>
	</div>
</div>

<div class="row form_seperator" style="margin-bottom:15px;">
	<div class="col-xs-12"></div>
</div>

<div class="row">
	<div class="col-xs-7">
		<div class="row">
			<div class="col-xs-12">
				<small><strong>Add Traffic Source</strong></small><br />
				<span class="infotext">What Traffic Sources do you use? Some examples include, Facebook Ads, Twitter Ads, BingAds, & Google Adwords.</span>

				<form method="post" action="<?php echo $_SERVER['REDIRECT_URL'] ?? ''; ?>" class="form-inline" role="form" style="margin:15px 0px;">
					<div class="form-group <?php if (isset($error['ppc_network_name'])) echo "has-error"; ?>"
						<label class="sr-only" for="ppc_network_name">Traffic source</label>
						<input type="text" class="form-control input-sm" id="ppc_network_name" name="ppc_network_name" placeholder="Traffic source" value="<?php echo isset($html['ppc_network_name']) ? $html['ppc_network_name'] : ''; ?>">
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


									if ($selected['ppc_network_id'] == $ppc_network_row['ppc_network_id']) {
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
							<input type="ppc_account_name" class="form-control input-sm" id="ppc_account_name" name="ppc_account_name" value="<?php echo isset($html['ppc_account_name']) ? $html['ppc_account_name'] : ''; ?>">
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
	<div class="col-xs-4 col-xs-offset-1">
		<div class="panel panel-default">
			<div class="panel-heading">My Traffic Sources</div>
			<div class="panel-body">
				<div id="trafficSourceList">
					<input class="form-control input-sm search" style="margin-bottom: 10px; height: 30px;" placeholder="Filter">
					<ul class="list">
						<?php $mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
						$ppc_network_sql = "SELECT * FROM `202_ppc_networks` WHERE `user_id`='" . $mysql['user_id'] . "' AND `ppc_network_deleted`='0' ORDER BY `ppc_network_name` ASC";
						$ppc_network_result = _mysqli_query($ppc_network_sql); //($ppc_network_sql);
						if ($ppc_network_result->num_rows == 0) {
						?>
							<li>You have not added any networks.</li>
						<?php
						}

						while ($ppc_network_row = $ppc_network_result->fetch_array(MYSQLI_ASSOC)) {

							//print out the PPC networks
							$html['ppc_network_name'] = htmlentities((string)($ppc_network_row['ppc_network_name'] ?? ''), ENT_QUOTES, 'UTF-8');
							$url['ppc_network_id'] = urlencode($ppc_network_row['ppc_network_id']);

							if ($userObj->hasPermission("remove_traffic_source")) {
								printf('<li><span class="filter_source_name">%s</span> - <a href="?edit_ppc_network_id=%s&edit_ppc_network_name=%s">edit</a> - <a href="#" class="custom variables" data-id="%s">variables</a> - <a href="?delete_ppc_network_id=%s&delete_ppc_network_name=%s" onclick="return confirmSubmit(\'Are You Sure You Want To Delete This Traffic Source?\');">remove</a></li>', $html['ppc_network_name'], $url['ppc_network_id'], $html['ppc_network_name'], $url['ppc_network_id'], $url['ppc_network_id'], $html['ppc_network_name']);
							} else {
								printf('<li><span class="filter_source_name">%s</span> - <a href="?edit_ppc_network_id=%s&edit_ppc_network_name=%s">edit</a></li>', $html['ppc_network_name'], $url['ppc_network_id'], $html['ppc_network_name']);
							}

						?>
							<ul style="margin-top: 0px;">
								<?php

								//print out the individual accounts per each PPC network
								$mysql['ppc_network_id'] = $db->real_escape_string($ppc_network_row['ppc_network_id']);
								$ppc_account_sql = "SELECT * FROM `202_ppc_accounts` WHERE `ppc_network_id`='" . $mysql['ppc_network_id'] . "' AND `ppc_account_deleted`='0' ORDER BY `ppc_account_name` ASC";
								$ppc_account_result = _mysqli_query($ppc_account_sql); //($ppc_account_sql);

								while ($ppc_account_row = $ppc_account_result->fetch_array(MYSQLI_ASSOC)) {

									$html['ppc_account_name'] = htmlentities((string)($ppc_account_row['ppc_account_name'] ?? ''), ENT_QUOTES, 'UTF-8');
									$url['ppc_account_id'] = urlencode($ppc_account_row['ppc_account_id']);

									if ($userObj->hasPermission("remove_traffic_source_account")) {
										printf('<li>%s - <a href="?edit_ppc_account_id=%s">edit</a> - <a href="?delete_ppc_account_id=%s&delete_ppc_account_name=%s" onclick="return confirmSubmit(\'Are You Sure You Want To Delete This Account?\');">remove</a></li>', $html['ppc_account_name'], $url['ppc_account_id'], $url['ppc_account_id'], $html['ppc_account_name']);
									} else {
										printf('<li>%s - <a href="?edit_ppc_account_id=%s">edit</a></li>', $html['ppc_account_name'], $url['ppc_account_id']);
									}
								}

								?>
							</ul>
						<?php

						} ?>
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
		autocomplete_names('ppc_network_name', 'traffic-sources');
		<?php if (!empty($_GET['edit_ppc_network_id'])) { ?>
			$("#ppc_network_name").tokenfield("setTokens", <?php print_r(json_encode(array('value' => $autocomplete_ppc_network_name, 'label' => $autocomplete_ppc_network_name))) ?>);
		<?php } ?>
		$(".variables-info-pop").popover({
			trigger: "hover"
		});

		var trafficSourceOptions = {
			valueNames: ['filter_source_name'],
			plugins: [
				ListFuzzySearch()
			]
		};

		var trafficSourceList = new List('trafficSourceList', trafficSourceOptions);
	});
</script>
<?php template_bottom(); ?>