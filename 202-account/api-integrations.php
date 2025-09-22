<?php

/**
 * API Integrations Management - Refactored for improved readability
 */

declare(strict_types=1);
include_once(str_repeat("../", 1) . '202-config/connect.php');
include_once(str_repeat("../", 1) . '202-config/clickserver_api_management.php');

AUTH::require_user();

// Initialize variables to prevent undefined variable warnings
$error = [];
$html = [];
$mysql = [];
$selected = [];
$add_success = false;
$delete_success = false;

// Initialize change status variables
$change_cb_key = false;
$change_jvzoo_secret_key = false;
$change_zaxaa_api_signature = false;
$change_user_slack_incoming_webhook = false;
$change_ipqs_api_key = false;

/**
 * Helper Functions for API Integration Management
 */

/**
 * Validate required field and add error if empty
 */
function validateRequired($field_value, $error_key, $error_message, &$error)
{
	if (empty($field_value)) {
		$error[$error_key] = ($error[$error_key] ?? '') . $error_message;
		return false;
	}
	return true;
}

/**
 * Update user preference in database
 */
function updateUserPreference($field_name, $value, $user_id, $db)
{
	$escaped_value = $db->real_escape_string((string)$value);
	$escaped_user_id = $db->real_escape_string((string)$user_id);

	$sql = "UPDATE `202_users_pref` SET `{$field_name}` = '{$escaped_value}' WHERE `user_id` = '{$escaped_user_id}'";
	return $db->query($sql);
}

/**
 * Send Slack notification if configured and value changed
 */
function sendSlackNotification($slack, $event_name, $username, $old_value, $new_value)
{
	if ($slack && $old_value !== $new_value) {
		$slack->push($event_name, ['user' => $username]);
	}
}

/**
 * Process API key update with validation and notification
 */
function processApiKeyUpdate($config, &$error, &$change_flag, $user_row, $slack, $username, $db)
{
	$post_key = $config['post_key'];
	$field_name = $config['field_name'];
	$error_key = $config['error_key'];
	$error_message = $config['error_message'];
	$slack_event = $config['slack_event'];
	$user_id = $_SESSION['user_id'];

	if (!validateRequired($_POST[$post_key], $error_key, $error_message, $error)) {
		return false;
	}

	if (!$error) {
		$new_value = $_POST[$post_key];
		$old_value = $user_row[$field_name] ?? '';

		if ($new_value !== $old_value) {
			updateUserPreference($field_name, $new_value, $user_id, $db);

			// Special handling for cb_key verification reset
			if ($field_name === 'cb_key') {
				updateUserPreference('cb_verified', '0', $user_id, $db);
			}
		}

		$change_flag = true;
		sendSlackNotification($slack, $slack_event, $username, $old_value, $new_value);
		return true;
	}

	return false;
}

/**
 * Display success message
 */
function showSuccessMessage($condition, $message)
{
	if ($condition) {
		echo '<div class="success" style="text-align:right"><small><span class="fui-check-inverted"></span> ' . htmlspecialchars((string) $message) . '</small></div>';
	}
}

/**
 * Display error message
 */
function showErrorMessage($errors, $key)
{
	if (isset($errors[$key]) && $errors[$key]) {
		echo '<div class="error" style="text-align:right"><small><span class="fui-alert"></span> ' . htmlspecialchars((string) $errors[$key]) . '</small></div>';
	}
}

$strProtocol = stripos((string) $_SERVER['SERVER_PROTOCOL'], 'https') === true ? 'https://' : 'http://';
$mysql['add_dni'] = $db->real_escape_string((string)($_GET['add_dni_network'] ?? ''));
$slack = false;
$mysql['user_own_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
$user_sql = "SELECT 2u.user_name as username, 2up.user_slack_incoming_webhook AS url, 2u.install_hash, 2u.p202_customer_api_key FROM 202_users AS 2u INNER JOIN 202_users_pref AS 2up ON (2up.user_id = 1) WHERE 2u.user_id = '" . $mysql['user_own_id'] . "'";
$user_results = $db->query($user_sql);
$user_row = $user_results->fetch_assoc();
$username = $user_row['username'];
$editing_dni_network = false;
$dniNetworks = getAllDniNetworks($user_row['install_hash']);
$dniProcesing = ['host' => getDNIHost(), 'install_hash' => $user_row['install_hash'], 'networks' => []];

if (!empty($user_row['url']))
	$slack = new Slack($user_row['url']);

if (isset($_GET['cb_status']) && $_GET['cb_status'] == 1) {
	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
	$user_sql = "SELECT cb_verified
             FROM 202_users_pref
             WHERE user_id='" . $mysql['user_id'] . "'";
	$user_results = $db->query($user_sql);
	$user_row = $user_results->fetch_assoc();
	if ($user_row['cb_verified']) {
		echo '<span class="label label-primary">Verified</span>';
	} else {
		echo '<span class="label label-important">Unverified</span>';
	}
	die();
}

//get all of the user data
$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
$user_sql = "	SELECT 	*
				 FROM   	`202_users` 
				 LEFT JOIN	`202_users_pref` USING (user_id)
				 WHERE  	`202_users`.`user_id`='" . $mysql['user_id'] . "'";
$user_result = $db->query($user_sql);
$user_row = $user_result->fetch_assoc();
$html = array_map('htmlentities', $user_row);

$cb_verified = $user_row['cb_verified'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	// ClickBank Key Update
	if (isset($_POST['change_cb_key']) && $_POST['change_cb_key'] == '1') {
		$config = [
			'post_key' => 'cb_key',
			'field_name' => 'cb_key',
			'error_key' => 'cb_key',
			'error_message' => 'Clickbank Secret Key can\'t be empty!',
			'slack_event' => 'cb_key_updated'
		];
		processApiKeyUpdate($config, $error, $change_cb_key, $user_row, $slack, $username, $db);
	}

	// Slack Webhook Update
	if (isset($_POST['change_user_slack_incoming_webhook']) && $_POST['change_user_slack_incoming_webhook'] == '1') {
		$config = [
			'post_key' => 'user_slack_incoming_webhook',
			'field_name' => 'user_slack_incoming_webhook',
			'error_key' => 'user_slack_incoming_webhook',
			'error_message' => 'Slack Incoming Webhook URL can\'t be empty!',
			'slack_event' => 'user_slack_incoming_webhook_updated'
		];
		processApiKeyUpdate($config, $error, $change_user_slack_incoming_webhook, $user_row, $slack, $username, $db);
	}

	// Zaxaa API Signature Update
	if (isset($_POST['change_zaxaa_api_signature']) && $_POST['change_zaxaa_api_signature'] == '1') {
		$config = [
			'post_key' => 'zaxaa_api_signature',
			'field_name' => 'zaxaa_api_signature',
			'error_key' => 'zaxaa_api_signature_error',
			'error_message' => 'Zaxaa API signature can\'t be empty!',
			'slack_event' => 'zaxaa_api_signature_updated'
		];
		processApiKeyUpdate($config, $error, $change_zaxaa_api_signature, $user_row, $slack, $username, $db);
	}

	// JVZoo Secret Key Update
	if (isset($_POST['change_jvzoo_secret_key']) && $_POST['change_jvzoo_secret_key'] == '1') {
		$config = [
			'post_key' => 'jvzoo_ipn_secret_key',
			'field_name' => 'jvzoo_ipn_secret_key',
			'error_key' => 'jvzoo_secret_key_error',
			'error_message' => 'JVZoo secret key can\'t be empty!',
			'slack_event' => 'jvzoo_secret_key_updated'
		];
		processApiKeyUpdate($config, $error, $change_jvzoo_secret_key, $user_row, $slack, $username, $db);
	}

	// IPQualityScore API Key Update
	if (isset($_POST['change_ipqs_api_key']) && $_POST['change_ipqs_api_key'] == '1') {
		$config = [
			'post_key' => 'ipqs_api_key',
			'field_name' => 'ipqs_api_key',
			'error_key' => 'ipqs_api_key_error',
			'error_message' => 'The IPQualityScore API Key can\'t be empty!',
			'slack_event' => 'ipqs_api_key_updated'
		];
		processApiKeyUpdate($config, $error, $change_ipqs_api_key, $user_row, $slack, $username, $db);
	}

	if (isset($_POST['dni_network'])) {
		if (array_search('', $_POST) !== false) {
			$error['dni_network'] = 'Make sure all fields are selected and filled out!';
		} else {
			$mysql['dniNetworkId'] = $db->real_escape_string((string)$_POST['dni_network']);
			$mysql['dniNetworkType'] = $db->real_escape_string((string)$_POST['dni_network_type']);
			$dniNetworkName = explode(" (", (string) $_POST['dni_network_name'], 2);
			$mysql['dniNetworkName'] = $db->real_escape_string($dniNetworkName[0]);
			$mysql['dniAffiliateId'] = $db->real_escape_string((string)$_POST['dni_network_affiliate_id']);
			$mysql['dniApikey'] = $db->real_escape_string((string)$_POST['dni_network_api_key']);
			$dniAuth = authDniNetworks($user_row['install_hash'], $_POST['dni_network'], $_POST['dni_network_api_key'], $_POST['dni_network_affiliate_id']);

			if ($dniAuth['auth'] == false) {
				$error['dni_network_auth'] = 'Can\'t authenticate with provided credentials. Try again!';
			} else {
				if (!isset($_POST['editing_dni_network'])) {
					$dniShortDescription = '';
					$dniFavIcon = '';
					foreach ($dniNetworks as $dniNetwork) {
						if ($dniNetwork['networkId'] == $_POST['dni_network']) {
							$dniShortDescription = $dniNetwork['shortDescription'];
							$dniFavIcon = $dniNetwork['favIconUrl'];
						}
					}

					$mysql['dniShortDescription'] = $db->real_escape_string($dniShortDescription);
					$mysql['dniFavIcon'] = $db->real_escape_string($dniFavIcon);
					$mysql['dniFavIcon'] = $db->real_escape_string($dniFavIcon);

					$dniProcessed = $db->real_escape_string($dniAuth['processed']);

					$sql = "INSERT INTO 202_dni_networks SET user_id = '" . $mysql['user_id'] . "', networkId = '" . $mysql['dniNetworkId'] . "', name = '" . $mysql['dniNetworkName'] . "', type = '" . $mysql['dniNetworkType'] . "', apiKey = '" . $mysql['dniApikey'] . "', time = '" . time() . "', processed = '" . $dniProcessed . "', shortDescription = '" . $mysql['dniShortDescription'] . "', favIcon = '" . $mysql['dniFavIcon'] . "'";

					if ($_POST['dni_network_type'] == 'Cake') {
						$sql .= ", affiliateId = '" . $mysql['dniAffiliateId'] . "'";
					}

					if ($db->query($sql)) {
						$success['dni_network_added'] = $mysql['dniNetworkName'] . " network configured. API processing can take up to 5 minutes.";
						$sql = "INSERT INTO 202_aff_networks SET dni_network_id = '" . $db->insert_id . "', user_id = '" . $mysql['user_id'] . "', aff_network_name = '" . $mysql['dniNetworkName'] . " (DNI)" . "', aff_network_time = '" . time() . "'";
						$db->query($sql);
					}
				} else if (isset($_POST['editing_dni_network_id']) && !empty($_POST['editing_dni_network_id'])) {
					$mysql['editing_dni_network_id'] = $db->real_escape_string((string)$_POST['editing_dni_network_id']);
					$sql = "UPDATE 202_dni_networks SET networkId = '" . $mysql['dniNetworkId'] . "', name = '" . $mysql['dniNetworkName'] . "', type = '" . $mysql['dniNetworkType'] . "', apiKey = '" . $mysql['dniApikey'] . "', time = '" . time() . "'";

					if ($_POST['dni_network_type'] == 'Cake') {
						$sql .= ", affiliateId = '" . $mysql['dniAffiliateId'] . "'";
					}

					$sql .= " WHERE id = '" . $mysql['editing_dni_network_id'] . "'";

					if ($db->query($sql)) {
						$sql = "UPDATE 202_aff_networks SET aff_network_name = '" . $mysql['dniNetworkName'] . " (DNI)" . "', aff_network_time = '" . time() . "' WHERE dni_network_id = '" . $mysql['editing_dni_network_id'] . "'";
						$db->query($sql);
						header('Location: ' . get_absolute_url() . '202-account/api-integrations.php?dni_network_updated=1');
						die();
					}
				}

				tagUserByNetwork($user_row['install_hash'], 'affiliate-networks', $dniNetworkName[0]);
			}
		}
	}

	$html = array_merge($html, array_map('htmlentities', $_POST));
}


if (isset($_GET['delete_dni_network']) && !empty($_GET['delete_dni_network'])) {
	$mysql['deleteDniNetworkId'] = $db->real_escape_string((string)$_GET['delete_dni_network']);
	$db->query("DELETE FROM 202_dni_networks WHERE id = '" . $mysql['deleteDniNetworkId'] . "' AND user_id = '" . $mysql['user_id'] . "'");
	$sql = "UPDATE 202_aff_networks SET aff_network_deleted = '1', aff_network_time = '" . time() . "' WHERE dni_network_id = '" . $mysql['deleteDniNetworkId'] . "'";
	$db->query($sql);
	header('Location: ' . get_absolute_url() . '202-account/api-integrations.php');
	die();
}

if (isset($_GET['edit_dni_network']) && !empty($_GET['edit_dni_network'])) {
	$mysql['editDniNetworkId'] = $db->real_escape_string((string)$_GET['edit_dni_network']);
	$sql_edit_dni = "SELECT * FROM 202_dni_networks WHERE id = '" . $mysql['editDniNetworkId'] . "' AND user_id = '" . $mysql['user_id'] . "'";
	$edit_dni_result = $db->query($sql_edit_dni);
	if ($edit_dni_result->num_rows > 0) {
		$edit_dni_row = $edit_dni_result->fetch_assoc();
		$editing_dni_network = true;
	}
}

$dni_sql = "SELECT * FROM 202_dni_networks WHERE user_id = '1'";
$dni_result = $db->query($dni_sql);

template_top('API Integrations');

?>

<div class="row account">
	<div class="col-xs-12">
		<div class="row">
			<div class="col-xs-6">
				<h6><span><img src="<?php echo get_absolute_url(); ?>202-img/icons/integrations/dni.jpg"></span> Direct Network Integration <?php showHelp("dni"); ?></h6>
			</div>
			<div class="col-xs-6">
				<?php if (isset($error['dni_network']) && $error['dni_network']) { ?>
					<div class="error" style="text-align:right"><small><span class="fui-alert"></span> <?php echo $error['dni_network']; ?></small></div>
				<?php } ?>
				<?php if (isset($error['dni_network_auth']) && $error['dni_network_auth']) { ?>
					<div class="error" style="text-align:right"><small><span class="fui-alert"></span> <?php echo $error['dni_network_auth']; ?></small></div>
				<?php } ?>
				<?php if (isset($success) && isset($success['dni_network_added']) && $success['dni_network_added']) { ?>
					<div class="success" style="text-align:right"><small><span class="fui-check-inverted"></span> <?php echo $success['dni_network_added']; ?></small></div>
				<?php } ?>
				<?php if (isset($_GET['dni_network_updated'])) { ?>
					<div class="success" style="text-align:right"><small><span class="fui-check-inverted"></span> DNI Network updated successfully. API processing can take up to 5 minutes.</small></div>
				<?php } ?>
			</div>
		</div>
	</div>
	<div class="col-xs-4">
		<div class="row">
			<div class="col-xs-12">
				<div class="panel panel-default account_left">
					<div class="panel-body">
						If you wish to search, apply and setup offers directly from your Prosper202 dashboard, use Direct Network Integration to link Prosper202 with our offers API. This will allow you to search, apply and setup offers from various networks without leaving your Prosper202 dashboard.
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="col-xs-8">
		<div class="row">
			<div class="col-xs-12">
				<table class="table table-bordered table-hover" id="stats-table">
					<thead>
						<tr style="background-color: #f2fbfa;">
							<th>Network</th>
							<th>API Key</th>
							<th>ID</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php if ($dni_result->num_rows > 0) {
							while ($dni_row = $dni_result->fetch_assoc()) {
								if ($dni_row['processed'] == false) {
									$dniProcesing['networks'][] = ['id' => $dni_row['id'], 'networkId' => $dni_row['networkId'], 'api_key' => $dni_row['apiKey'], 'type' => $dni_row['type']];
								}
						?>
								<tr>
									<td> <img src="<?php echo $dni_row['favIcon']; ?>" width=16>&nbsp;&nbsp;<?php echo $dni_row['name'] . " (" . $dni_row['type'] . ")"; ?><span class="fui-info-circle" style="font-size: 12px; margin: -25px 0px 0px 5px;" data-toggle="tooltip" title="" data-original-title="<?php echo $dni_row['shortDescription']; ?>"></span><br>
										<?php if ($dni_row['processed'] == false) { ?>
											<div id="network-<?php echo $dni_row['id']; ?>">
												<span style='font-size:10px'>processing... <img src="<?php echo get_absolute_url(); ?>202-img/loader-small.gif"></span>
												<div class="progress" style="margin: 0px 5px;">
													<div id="<?php echo $dni_row['id']; ?>" class="progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" style="width: 0%; color:#34495E">
														0.00%
													</div>
												</div>
												<div>
												<?php } ?>
									</td>
									<td><?php echo substr((string) $dni_row['apiKey'], 0, 12) . "... "; ?><a href="#" class="link showFullDniApikey" data-long="<?php echo $dni_row['apiKey']; ?>" data-short="<?php echo substr((string) $dni_row['apiKey'], 0, 12); ?>">show</a></td>
									<td><?php echo $dni_row['affiliateId']; ?></td>
									<td><a href="<?php echo get_absolute_url(); ?>202-account/api-integrations.php?edit_dni_network=<?php echo $dni_row['id']; ?>"><i class="glyphicon glyphicon-pencil"></i></a> <a href="<?php echo get_absolute_url(); ?>202-account/api-integrations.php?delete_dni_network=<?php echo $dni_row['id']; ?>" onClick="return confirm('Delete This DNI Network?')"><i class="glyphicon glyphicon-trash"></i></a></td>
								</tr>
						<?php }
						} ?>
					</tbody>
				</table>
			</div>
			<div class="col-xs-12">
				<form class="form-horizontal" role="form" method="post" action="">
					<input type="hidden" name="dni_network_type" id="dni_network_type" value="<?php echo $edit_dni_row['type'] ?? ''; ?>">
					<input type="hidden" name="dni_network_name" id="dni_network_name" value="<?php echo $edit_dni_row['name'] ?? ''; ?>">
					<?php if (isset($editing_dni_network) && $editing_dni_network) { ?>
						<input type="hidden" name="editing_dni_network" value="1">
						<input type="hidden" name="editing_dni_network_id" value="<?php echo $edit_dni_row['id'] ?? ''; ?>">
					<?php } ?>
					<div class="col-xs-3" style="padding: 0px; padding-right: 5px;">
						<label class="sr-only" for="dni_network">Select Network</label>
						<select name="dni_network" class="form-control input-sm">
							<option value="">Select network</option>
							<?php foreach ($dniNetworks as $dninetwork) { ?>
								<option value="<?php echo $dninetwork['networkId']; ?>" data-type="<?php echo $dninetwork['networkType']; ?>" <?php if (isset($edit_dni_row['networkId']) && $edit_dni_row['networkId'] == $dninetwork['networkId'] || isset($mysql['add_dni']) && $mysql['add_dni'] == $dninetwork['networkId']) echo 'selected'; ?>><?php echo $dninetwork['name']; ?> (<?php echo $dninetwork['networkType']; ?>)</option>
							<?php } ?>
						</select>

					</div>
					<div class="<?php if (isset($editing_dni_network) && $editing_dni_network) {
									if (isset($edit_dni_row['type']) && $edit_dni_row['type'] == 'HasOffers') echo 'col-xs-7';
									else echo 'col-xs-5';
								} else {
									echo 'col-xs-7';
								} ?>" id="dni_api_key_input_group" style="padding: 0px; padding-right: 5px;">
						<label class="sr-only" for="dni_network_api_key">Add API key</label>
						<input type="text" name="dni_network_api_key" class="form-control input-sm" placeholder="API Key" value="<?php echo $edit_dni_row['apiKey'] ?? ''; ?>">
						<p>
						<div id="dniInfo"></div>
					</div>
					<div class="col-xs-2" id="dni_affiliate_id_input_group" style="<?php if (isset($editing_dni_network) && $editing_dni_network) {
																						if (isset($edit_dni_row['type']) && $edit_dni_row['type'] == 'HasOffers') echo 'display:none;';
																					} else {
																						echo 'display:none;';
																					} ?> padding: 0px; padding-right: 5px;">
						<label class="sr-only" for="dni_network_affiliate_id">Add Affiliate ID</label>
						<input type="text" name="dni_network_affiliate_id" id="dni_network_affiliate_id" class="form-control input-sm" placeholder="Affiliate ID" value="<?php if (isset($editing_dni_network) && $editing_dni_network) {
																																												if (isset($edit_dni_row['type']) && $edit_dni_row['type'] == 'HasOffers') echo 'null';
																																											} else {
																																												echo $edit_dni_row['affiliateId'] ?? '';
																																											} ?>">
					</div>
					<div class="col-xs-2" style="padding: 0px;">
						<button class="btn btn-xs btn-p202 btn-block" type="submit" style="margin-top: 5px;"><?php if (isset($editing_dni_network) && $editing_dni_network) echo 'Edit';
																												else echo 'Add'; ?></button>
					</div>
				</form>
			</div>
		</div>
	</div>
</div>

<div class="row account">
	<div class="col-xs-12">
		<div class="row">
			<div class="col-xs-4">
				<h6><span><img src="<?php echo get_absolute_url(); ?>202-img/icons/integrations/ipqs.png"></span> IPQualityScore Integration <?php showHelp("jvzoo"); ?></h6>
			</div>
			<div class="col-xs-8">
				<?php showSuccessMessage($change_ipqs_api_key, 'Your IPQualityScore API key was changed successfully.'); ?>
				<?php showErrorMessage($error, 'ipqs_api_key_error'); ?>
			</div>
		</div>
	</div>
	<div class="col-xs-4">
		<div class="row">
			<div class="col-xs-12">
				<div class="panel panel-default account_left">
					<div class="panel-body">
						If you wish to detect and redirect Fraud in real-time using IPQualityScore, enter your IPQualityScore API Key! <a href='https://202.redirexit.com/tracking202/redirect/dl.php?t202id=12608&t202kw=' target='_blank' rel='noopener'>Click here for your free api key.</a>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="col-xs-8">
		<strong><small>Your IPQualityScore API Key Is:</small></strong><br />
		<div class="row">

			<form class="form-horizontal" role="form" method="post" action="">

				<div class="col-xs-9">

					<input type="hidden" name="change_ipqs_api_key" value="1" />
					<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" />
					<div class="form-group" style="margin-top: 20px;">
						<label for="ipqs_api_key" class="col-xs-12 control-label" style="text-align:left">IPQS API Key:</label>
						<div class="col-xs-12">
							<input type="text" class="form-control input-sm" id="ipqs_api_key" name="ipqs_api_key" value="<?php echo $html['ipqs_api_key']; ?>">
						</div>
						<div class="col-xs-6">
							<br>
							<button class="btn btn-xs btn-p202 btn-block" type="submit">Update IPQS API Key</button>
						</div>
					</div>
				</div>

			</form>
		</div>
	</div>
</div>

<div class="row account">
	<div class="col-xs-12">
		<div class="row">
			<div class="col-xs-6">
				<h6><span><img src="<?php echo get_absolute_url(); ?>202-img/icons/integrations/clickbank.png"></span> ClickBank Sales Notification <?php showHelp("clickbank"); ?></h6>
			</div>
			<div class="col-xs-6">
				<?php showSuccessMessage($change_cb_key, 'Your Clickbank secret key was changed successfully.'); ?>
				<?php showErrorMessage($error, 'cb_key'); ?>
			</div>
		</div>
	</div>
	<div class="col-xs-4">
		<div class="row">
			<div class="col-xs-12">
				<div class="panel panel-default account_left">
					<div class="panel-body">
						If you wish to use Clickbank Sales Notification Service, to update conversions, enter your Secret Key!
					</div>
				</div>
			</div>
			<!--<div class="col-xs-12">
				<div class="panel panel-default account_left">
					<div class="panel-body">
					   <iframe width="100%" height="auto" src="//www.youtube.com/embed/M6zo3XuExL0" frameborder="0" allowfullscreen></iframe>
					</div>
				</div>			
			</div> -->
		</div>
	</div>

	<div class="col-xs-8">
		<?php
		if (extension_loaded('mcrypt') || function_exists("openssl_decrypt")) {
		?>
			<strong><small>Your Clickbank Notification URL is:</small></strong><br />
			<div class="row">

				<form class="form-horizontal" role="form" method="post" action="">

					<div class="col-xs-9">

						<small>
							<span id="cb_verified">
								<?php if (!$cb_verified) { ?>
									<span class="label label-important">Unverified</span>
								<?php } else { ?>
									<span class="label label-primary">Verified</span>
								<?php } ?>
							</span> -
							<em><?php echo $strProtocol . '' . getTrackingDomain() . get_absolute_url() . 'tracking202/static/cb202.php'; ?></em>
						</small>

						<input type="hidden" name="change_cb_key" value="1" />
						<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" />
						<div class="form-group" style="margin-top: 20px;">
							<label for="cb_key" class="col-xs-5 control-label" style="text-align:left">Clickbank Secret Key:</label>
							<div class="col-xs-7">
								<input type="text" class="form-control input-sm" id="cb_key" name="cb_key" value="<?php echo $html['cb_key']; ?>">
							</div>
						</div>
					</div>

					<div class="col-xs-3">
						<a id="cb_status" class="btn btn-xs btn-warning btn-block">Check status</a>
						<br />
						<div class="form-group">
							<div class="col-xs-12">
								<button class="btn btn-xs btn-p202 btn-block" type="submit">Update Secret Key</button>
							</div>
						</div>
					</div>

				</form>
			</div>
		<?php
		} else {
		?>
			<div class="row">


				<div class="col-xs-12">

					<small>
						<span id="cb_verified">
							<span class="label label-important">Mcrypt Extension Missing </span>
						</span> The mcrypt extension is needed for the ClickBank Sales Notification integration to work. However, it has not been installed. Please install it, or ask your hosting provider for assistance.
					</small>

				</div>


			</div>
		<?php
		}
		?>

	</div>
</div>

<div class="row account">
	<div class="col-xs-12">
		<div class="row">
			<div class="col-xs-4">
				<h6><span><img src="<?php echo get_absolute_url(); ?>202-img/icons/integrations/jvzoo.png"></span> JVZoo JVZIPN <?php showHelp("jvzoo"); ?></h6>
			</div>
			<div class="col-xs-8">
				<?php showSuccessMessage($change_jvzoo_secret_key, 'Your JVZoo secret key was changed successfully.'); ?>
				<?php showErrorMessage($error, 'jvzoo_secret_key_error'); ?>
			</div>
		</div>
	</div>
	<div class="col-xs-4">
		<div class="row">
			<div class="col-xs-12">
				<div class="panel panel-default account_left">
					<div class="panel-body">
						If you wish to use JVZoo Instant Payment Notification (JVZIPN) to update conversions, enter your JVZIPN Secret Key!
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="col-xs-8">
		<strong><small>Your JVZIPN URL For JVZoo Is:</small></strong><br />
		<div class="row">

			<form class="form-horizontal" role="form" method="post" action="">

				<div class="col-xs-9">

					<small>
						<em><?php echo $strProtocol . '' . getTrackingDomain() . get_absolute_url() . 'tracking202/static/jvzoo.php'; ?></em>
					</small>

					<input type="hidden" name="change_jvzoo_secret_key" value="1" />
					<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" />
					<div class="form-group" style="margin-top: 20px;">
						<label for="jvzoo_ipn_secret_key" class="col-xs-12 control-label" style="text-align:left">JVZoo Secret Key:</label>
						<div class="col-xs-12">
							<input type="text" class="form-control input-sm" id="jvzoo_ipn_secret_key" name="jvzoo_ipn_secret_key" value="<?php echo $html['jvzoo_ipn_secret_key']; ?>">
						</div>
						<div class="col-xs-6">
							<br>
							<button class="btn btn-xs btn-p202 btn-block" type="submit">Update JVZoo Secret Key</button>
						</div>
					</div>
				</div>

			</form>
		</div>
	</div>
</div>

<div class="row account">
	<div class="col-xs-12">
		<div class="row">
			<div class="col-xs-4">
				<h6><span><img src="<?php echo get_absolute_url(); ?>202-img/icons/integrations/zaxaa.png"></span> ZPN Payment Notification (ZPN) <?php showHelp("zaxaa"); ?></h6>
			</div>
			<div class="col-xs-8">
				<?php showSuccessMessage($change_zaxaa_api_signature, 'Your Zaxaa API signature was changed successfully.'); ?>
				<?php showErrorMessage($error, 'zaxaa_api_signature_error'); ?>
			</div>
		</div>
	</div>
	<div class="col-xs-4">
		<div class="row">
			<div class="col-xs-12">
				<div class="panel panel-default account_left">
					<div class="panel-body">
						If you wish to use Zaxaa Payment Notification (ZPN) to update conversions, enter your API Signature!
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="col-xs-8">
		<strong><small>Your ZPN URL For Zaxaa Is:</small></strong><br />
		<div class="row">

			<form class="form-horizontal" role="form" method="post" action="">

				<div class="col-xs-9">

					<small>
						<em><?php echo $strProtocol . '' . getTrackingDomain() . get_absolute_url() . 'tracking202/static/zpn.php'; ?></em>
					</small>

					<input type="hidden" name="change_zaxaa_api_signature" value="1" />
					<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" />
					<div class="form-group" style="margin-top: 20px;">
						<label for="zaxaa_api_signature" class="col-xs-12 control-label" style="text-align:left">Zaxaa API signature:</label>
						<div class="col-xs-12">
							<input type="text" class="form-control input-sm" id="zaxaa_api_signature" name="zaxaa_api_signature" value="<?php echo $html['zaxaa_api_signature']; ?>">
						</div>
						<div class="col-xs-6">
							<br>
							<button class="btn btn-xs btn-p202 btn-block" type="submit">Update Zaxaa API Signature</button>
						</div>
					</div>
				</div>

			</form>
		</div>
	</div>
</div>

<div class="row account">
	<div class="col-xs-12">
		<div class="row">
			<div class="col-xs-4">
				<h6><span><img src="<?php echo get_absolute_url(); ?>202-img/icons/integrations/slack.png"></span> Slack Integration <?php showHelp("slack"); ?></h6>
			</div>
			<div class="col-xs-8">
				<?php showSuccessMessage($change_user_slack_incoming_webhook, 'Your Slack Incoming Webhook URL was changed successfully.'); ?>
				<?php showErrorMessage($error, 'user_slack_incoming_webhook'); ?>
			</div>
		</div>
	</div>
	<div class="col-xs-4">
		<div class="row">
			<div class="col-xs-12">
				<div class="panel panel-default account_left">
					<div class="panel-body">
						If you wish to send notifications into Slack enter your <strong>Slack</strong> incoming webhook url. To receive notifications from Slack, use the <strong>Prosper202</strong> Incoming Webhook URL.
					</div>
				</div>
			</div>
			<!--	<div class="col-xs-12">
				<div class="panel panel-default account_left">
					<div class="panel-body">
					    <iframe width="100%" height="auto" src="//www.youtube.com/embed/M6zo3XuExL0" frameborder="0" allowfullscreen></iframe>
					</div>
				</div>			
			</div> -->
		</div>
	</div>

	<div class="col-xs-8">
		<strong><small>Prosper202 Incoming Webhook URL Is:</small></strong><br />
		<div class="row">

			<form class="form-horizontal" role="form" method="post" action="">

				<div class="col-xs-9">

					<small>
						<em><?php echo $strProtocol . '' . getTrackingDomain() . get_absolute_url() . 'tracking202/static/slack.php'; ?></em>
					</small>

					<input type="hidden" name="change_user_slack_incoming_webhook" value="1" />
					<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" />
					<div class="form-group" style="margin-top: 20px;">
						<label for="user_slack_incoming_webhook" class="col-xs-12 control-label" style="text-align:left">Slack Incoming Webhook URL:</label>
						<div class="col-xs-12">
							<input type="text" class="form-control input-sm" id="user_slack_incoming_webhook" name="user_slack_incoming_webhook" value="<?php echo $html['user_slack_incoming_webhook']; ?>">
						</div>
						<div class="col-xs-6">
							<br>
							<button class="btn btn-xs btn-p202 btn-block" type="submit">Update Webhook Url</button>
						</div>
					</div>
				</div>

			</form>
		</div>
	</div>
</div>

<div class="row account">
	<div class="col-xs-12">
		<div class="row">
			<div class="col-xs-4">
				<h6><span><img src="<?php echo get_absolute_url(); ?>202-img/icons/integrations/paykickstart.png"></span> PayKickstart Affiliate IPN <?php showHelp("paykickstart"); ?></h6>
			</div>
			<div class="col-xs-8">

			</div>
		</div>
	</div>
	<div class="col-xs-4">
		<div class="row">
			<div class="col-xs-12">
				<div class="panel panel-default account_left">
					<div class="panel-body">
						If you wish to use PayKickstart Affiliate Instant Payment Notification (IPN) to update conversions, use the following url as your IPN url.
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="col-xs-8">
		<strong><small>Your URL For PayKickstart Affiliate IPN is:</small></strong><br />
		<div class="row">

			<form class="form-horizontal" role="form" method="post" action="">

				<div class="col-xs-9">

					<small>
						<em><?php echo $strProtocol . '' . getTrackingDomain() . get_absolute_url() . 'tracking202/static/paykickstart.php'; ?></em>
					</small>

				</div>

			</form>
		</div>
	</div>
</div>

<?php if (count($dniProcesing['networks']) > 0) { ?>
	<script type="text/javascript">
		$(document).ready(function() {
			var DNIdata = JSON.stringify(<?php echo json_encode($dniProcesing, JSON_NUMERIC_CHECK); ?>);
			getDNIProgress(DNIdata);

			window.setInterval(function() {
				getDNIProgress(DNIdata);
			}, 3000);

			function getDNIProgress(DNIdata) {
				$.post("<?php echo get_absolute_url(); ?>202-account/ajax/dni.php?getProgress=true", DNIdata).done(function(response) {
					var json = $.parseJSON(response);
					$.each(json.data, function(index, item) {
						if (item.progress == '100') {
							$.post("<?php echo get_absolute_url(); ?>202-account/ajax/dni.php?updateStatus=true&dni=" + item.id, function(data1) {
								$("#network-" + item.id).remove();
							});
						}
						$("#" + item.id).css('width', item.progress + '%').attr('aria-valuenow', item.progress).text(item.progress + '%');
					});
				});
			}
			$('select[name=dni_network]').trigger("change");
		});
	</script>
<?php } else { ?>
	<script type="text/javascript">
		$(document).ready(function() {
			//manually trigger the change function
			$('select[name=dni_network]').trigger("change");
		});
	</script>
<?php } ?>
<script>
	dniNetworks = <?php echo json_encode(getAllDniNetworks($user_row['install_hash'])); ?>;

	function dni() {
		var selectedNetwork = $('select[name=dni_network] option:selected').val()
		var dniNetwork = $(dniNetworks).filter(function(i, n) {
			return n.networkId === selectedNetwork
		});
		var dniInfo = '<small> <img src="' + dniNetwork[0].favIconUrl + '" width="16"> <strong>' + dniNetwork[0].name + '</strong><br><br>' + dniNetwork[0].shortDescription + ' <br><br><a href="' + dniNetwork[0].websiteURL + '" target="_blank" class="btn btn-xs btn-info btn-block">Get An Account with ' + dniNetwork[0].name + '</a></small>'
		$("#dniInfo").html(dniInfo);
	}
</script>
<?php template_bottom();
