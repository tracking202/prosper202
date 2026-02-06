<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -18) . '/202-config/connect.php');

AUTH::require_user();

if (!$userObj->hasPermission("access_to_setup_section")) {
	header('location: ' . get_absolute_url() . 'tracking202/');
	die();
}

// Initialize variables to prevent undefined variable warnings
$error = [];
$html = [];
$mysql = [];
$selected = [];
$add_success = false;
$delete_success = false;
$editing = false;
$copying = false;
$append = '';
$aff_campaign_row = [];
$aff_network_row = [];
$landing_page_row = [];
$url = [];


$slack = false;
$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
$mysql['user_own_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
$user_sql = "SELECT 2u.user_name as username, 2up.user_slack_incoming_webhook AS url FROM 202_users AS 2u INNER JOIN 202_users_pref AS 2up ON (2up.user_id = 1) WHERE 2u.user_id = '" . $mysql['user_own_id'] . "'";
$user_results = $db->query($user_sql);
$user_row = $user_results->fetch_assoc();

if (!empty($user_row['url']))
	$slack = new Slack($user_row['url']);

if (!empty($_GET['edit_landing_page_id'])) {
	$editing = true;
}

if (!empty($_GET['copy_landing_page_id'])) {
	$copying = true;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	if ((!isset($_POST['landing_page_type'])) || (($_POST['landing_page_type'] != '0') and ($_POST['landing_page_type'] != '1'))) {
		$error['landing_page_type'] = '<div class="error">What type of landing page is this?</div>';
	}

	//if this is a simple landing page
	if (isset($_POST['landing_page_type']) && $_POST['landing_page_type'] == '0') {
		$aff_campaign_id = isset($_POST['aff_campaign_id']) ? trim($_POST['aff_campaign_id']) : '';
		if (empty($aff_campaign_id)) {
			$error['aff_campaign_id'] = '<div class="error">What campaign is this landing page for?</div>';
		}
	}

	$landing_page_nickname = isset($_POST['landing_page_nickname']) ? trim((string) $_POST['landing_page_nickname']) : '';
	if (empty($landing_page_nickname)) {
		$error['landing_page_nickname'] = '<div class="error">Give this landing page a nickname</div>';
	}

	$landing_page_url = isset($_POST['landing_page_url']) ? trim((string) $_POST['landing_page_url']) : '';
	if (empty($landing_page_url)) {
		$error['landing_page_url'] = '<div class="error">What is the URL of your landing page?</div>';
	}

	if (isset($_POST['landing_page_url']) && !empty($_POST['landing_page_url']) && (!str_starts_with((string) $_POST['landing_page_url'], 'http://')) and (!str_starts_with((string) $_POST['landing_page_url'], 'https://'))) {
		if (!isset($error['landing_page_url'])) {
			$error['landing_page_url'] = '';
		}
		$error['landing_page_url'] .= '<div class="error">Your Landing Page URL must start with http:// or https://</div>';
	}

	//if this is a simple landing page
	if (isset($_POST['landing_page_type']) && $_POST['landing_page_type'] == '0') {
		//check to see if they are the owners of this affiliate network
		$mysql['aff_campaign_id'] = $db->real_escape_string((string)($_POST['aff_campaign_id'] ?? ''));
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$aff_campaign_sql = "SELECT * FROM `202_aff_campaigns` WHERE `user_id`='" . $mysql['user_id'] . "' AND `aff_campaign_id`='" . $mysql['aff_campaign_id'] . "'";
		$aff_campaign_result = $db->query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql);
		if ($aff_campaign_result->num_rows == 0) {
			$error['wrong_user'] = '<div class="error">You are not authorized to add a landing page to another users campaign</div>';
		} else {
			$aff_campaign_row = $aff_campaign_result->fetch_assoc();
		}
	}

	//if editing, check to make sure the own the campaign they are editing
	if ($editing == true) {
		$mysql['landing_page_id'] = $db->real_escape_string((string)($_POST['landing_page_id'] ?? ''));
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$landing_page_sql = "SELECT * FROM 202_landing_pages LEFT JOIN 202_aff_campaigns USING (aff_campaign_id) WHERE 202_landing_pages.user_id='" . $mysql['user_id'] . "' AND landing_page_id='" . $mysql['landing_page_id'] . "'";
		$landing_page_result = $db->query($landing_page_sql) or record_mysql_error($landing_page_sql);
		if ($landing_page_result->num_rows == 0) {
			if (!isset($error['wrong_user'])) {
				$error['wrong_user'] = '';
			}
			$error['wrong_user'] .= '<div class="error">You are not authorized to modify another users campaign</div>';
		} else {
			$landing_page_row = $landing_page_result->fetch_assoc();
		}
	}

	if (!$error) {
		$mysql['landing_page_id'] = $db->real_escape_string((string)($_POST['landing_page_id'] ?? ''));
		$mysql['aff_campaign_id'] = $db->real_escape_string((string)($_POST['aff_campaign_id'] ?? ''));
		$mysql['landing_page_nickname'] = $db->real_escape_string((string)($_POST['landing_page_nickname'] ?? ''));
		$mysql['landing_page_url'] = $db->real_escape_string((string)($_POST['landing_page_url'] ?? ''));
		$mysql['leave_behind_page_url'] = $db->real_escape_string((string)($_POST['leave_behind_page_url'] ?? ''));
		$mysql['landing_page_type'] = $db->real_escape_string((string)($_POST['landing_page_type'] ?? ''));
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$mysql['landing_page_time'] = time();

		if ($editing == true) {
			$landing_page_sql  = "UPDATE `202_landing_pages` SET";
		} else {
			$landing_page_sql  = "INSERT INTO `202_landing_pages` SET";
		}
		$landing_page_sql .= "`aff_campaign_id`='" . $mysql['aff_campaign_id'] . "',
			                                                  `landing_page_nickname`='" . $mysql['landing_page_nickname'] . "',
			                                                  `landing_page_url`='" . $mysql['landing_page_url'] . "'";
		if (isset($_SESSION['user_mods_lb']) && $_SESSION['user_mods_lb'] == '1') {
			$landing_page_sql .=  ", `leave_behind_page_url`='" . $mysql['leave_behind_page_url'] . "' ";
		}
		$landing_page_sql .=  " ,
											  `landing_page_type`='" . $mysql['landing_page_type'] . "',
											  `user_id`='" . $mysql['user_id'] . "',
											  `landing_page_time`='" . $mysql['landing_page_time'] . "' ";

		if ($editing == true) {
			$landing_page_sql  .= "WHERE `landing_page_id`='" . $mysql['landing_page_id'] . "'";
		}
		//die($landing_page_sql);
		$landing_page_result = $db->query($landing_page_sql) or record_mysql_error($landing_page_sql);
		$add_success = true;

		if ($editing == true) {
			if ($slack) {
				$lp_type = 'advanced'; // Default value
				if (isset($_POST['landing_page_type']) && $_POST['landing_page_type'] == '0') {
					if (isset($landing_page_row['aff_campaign_id'], $_POST['aff_campaign_id']) && $landing_page_row['aff_campaign_id'] != $_POST['aff_campaign_id']) {
						$slack->push('simple_landing_page_campaign_changed', ['name' => $_POST['landing_page_nickname'] ?? '', 'old_campaign' => $landing_page_row['aff_campaign_name'] ?? '', 'new_campaign' => $aff_campaign_row['aff_campaign_name'] ?? '', 'user' => $user_row['username'] ?? '']);
					}

					$lp_type = 'simple';
				} else if (isset($_POST['landing_page_type']) && $_POST['landing_page_type'] == '1') {
					$lp_type = 'advanced';
				}

				if (isset($landing_page_row['landing_page_nickname'], $_POST['landing_page_nickname']) && $landing_page_row['landing_page_nickname'] != $_POST['landing_page_nickname']) {
					$slack->push($lp_type . '_landing_page_name_changed', ['name' => $_POST['landing_page_nickname'] ?? '', 'old_name' => $landing_page_row['landing_page_nickname'] ?? '', 'new_name' => $_POST['landing_page_nickname'] ?? '', 'user' => $user_row['username'] ?? '']);
				}

				if (isset($landing_page_row['landing_page_url'], $_POST['landing_page_url']) && $landing_page_row['landing_page_url'] != $_POST['landing_page_url']) {
					$slack->push($lp_type . '_landing_page_url_changed', ['name' => $_POST['landing_page_nickname'] ?? '', 'old_url' => $landing_page_row['landing_page_url'] ?? '', 'new_url' => $_POST['landing_page_url'] ?? '', 'user' => $user_row['username'] ?? '']);
				}
			}
			header('location: ' . get_absolute_url() . 'tracking202/setup/landing_pages.php');
		} else {
			if ($slack) {
				if (isset($_POST['landing_page_type']) && $_POST['landing_page_type'] == '0') {
					$slack->push('simple_landing_page_created', ['name' => $_POST['landing_page_nickname'] ?? '', 'user' => $user_row['username'] ?? '']);
				} else if (isset($_POST['landing_page_type']) && $_POST['landing_page_type'] == '1') {
					$slack->push('advanced_landing_page_created', ['name' => $_POST['landing_page_nickname'] ?? '', 'user' => $user_row['username'] ?? '']);
				}
			}
		}

		if ($editing != true) {
			//if this landing page is brand new, add on a landing_page_id_public
			$landing_page_row['landing_page_id'] = $db->insert_id;
			$landing_page_id_public = random_int(1, 9) . $landing_page_row['landing_page_id'] . random_int(1, 9);
			$mysql['landing_page_id_public'] = $db->real_escape_string((string)$landing_page_id_public);
			$mysql['landing_page_id'] = $db->real_escape_string((string)$landing_page_row['landing_page_id']);

			$landing_page_sql = "	UPDATE       `202_landing_pages`
								 	SET          	 `landing_page_id_public`='" . $mysql['landing_page_id_public'] . "'
								 	WHERE        `landing_page_id`='" . $mysql['landing_page_id'] . "'";
			$landing_page_result = $db->query($landing_page_sql) or record_mysql_error($landing_page_sql);
		}
	}
}

if (isset($_GET['delete_landing_page_id'])) {

	if ($userObj->hasPermission("remove_landing_page")) {
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$mysql['landing_page_id'] = $db->real_escape_string((string)$_GET['delete_landing_page_id']);
		$mysql['landing_page_time'] = time();
		$delete_sql = " UPDATE  `202_landing_pages`
						SET     `landing_page_deleted`='1',
								`landing_page_time`='" . $mysql['landing_page_time'] . "'
						WHERE   `user_id`='" . $mysql['user_id'] . "'
						AND     `landing_page_id`='" . $mysql['landing_page_id'] . "'";

		if ($delete_result = $db->query($delete_sql) or record_mysql_error($delete_result)) {
			$delete_success = true;
			if ($slack) {
				if (isset($_GET['delete_landing_page_type']) && $_GET['delete_landing_page_type'] == '0') {
					$slack->push('simple_landing_page_deleted', ['name' => $_GET['delete_landing_page_name'] ?? '', 'user' => $user_row['username'] ?? '']);
				} else if (isset($_GET['delete_landing_page_type']) && $_GET['delete_landing_page_type'] == '1') {
					$slack->push('advanced_landing_page_deleted', ['name' => $_GET['delete_landing_page_name'] ?? '', 'user' => $user_row['username'] ?? '']);
				}
			}
		}
	} else {
		header('location: ' . get_absolute_url() . 'tracking202/setup/landing_pages.php');
	}
}

if ((isset($_GET['edit_landing_page_id']) || isset($_GET['copy_landing_page_id'])) and ($_SERVER['REQUEST_METHOD'] != 'POST')) {

	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);

	if (!empty($_GET['edit_landing_page_id'])) {
		$mysql['landing_page_id'] = $db->real_escape_string((string)$_GET['edit_landing_page_id']);
		$append = "";
	} else if (!empty($_GET['copy_landing_page_id'])) {
		$mysql['landing_page_id'] = $db->real_escape_string((string)$_GET['copy_landing_page_id']);
		$append = " (Copy)";
	}


	$landing_page_sql = "SELECT * 
                         FROM   `202_landing_pages`
                         WHERE  `landing_page_id`='" . $mysql['landing_page_id'] . "'
						 AND    `user_id`='" . $mysql['user_id'] . "'";
	$landing_page_result = $db->query($landing_page_sql) or record_mysql_error($landing_page_sql);
	$landing_page_row = $landing_page_result->fetch_assoc();

	$mysql['aff_campaign_id'] = $db->real_escape_string($landing_page_row['aff_campaign_id']);
	$html['aff_campaign_id'] = htmlentities((string)($landing_page_row['aff_campaign_id'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['landing_page_id'] = htmlentities((string)($_GET['edit_landing_page_id'] ?? ''), ENT_QUOTES, 'UTF-8');
	$selected['pixel_id'] = htmlentities((string)($landing_page_row['landing_page_id'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['landing_page_type'] = htmlentities((string)($landing_page_row['landing_page_type'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['landing_page_nickname'] = htmlentities((string)($landing_page_row['landing_page_nickname'] ?? ''), ENT_QUOTES, 'UTF-8') . $append;
	$html['landing_page_url'] = htmlentities((string)($landing_page_row['landing_page_url'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['leave_behind_page_url'] = htmlentities((string)($landing_page_row['leave_behind_page_url'] ?? ''), ENT_QUOTES, 'UTF-8');
} elseif (($_SERVER['REQUEST_METHOD'] == 'POST') and ($add_success != true)) {

	$mysql['aff_campaign_id'] = $db->real_escape_string((string)($_POST['aff_campaign_id'] ?? ''));
	$html['aff_network_id'] = htmlentities((string)($_POST['aff_network_id'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['aff_campaign_id'] = htmlentities((string)($_POST['aff_campaign_id'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['landing_page_type'] = htmlentities((string)($_POST['landing_page_type'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['landing_page_id'] = htmlentities((string)($_POST['landing_page_id'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['landing_page_nickname'] = htmlentities((string)($_POST['landing_page_nickname'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['landing_page_url'] = htmlentities((string)($_POST['landing_page_url'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['leave_behind_page_url'] = htmlentities((string)($_POST['leave_behind_page_url'] ?? ''), ENT_QUOTES, 'UTF-8');
}

if ((($editing == true) or ($add_success != true)) and (isset($mysql['aff_campaign_id']) && $mysql['aff_campaign_id'])) {
	//now grab the affiliate network id, per that aff campaign id
	$aff_campaign_sql = "SELECT * FROM `202_aff_campaigns` WHERE `aff_campaign_id`='" . $mysql['aff_campaign_id'] . "'";
	$aff_campaign_result = $db->query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql);
	$aff_campaign_row = $aff_campaign_result->fetch_assoc();

	$mysql['aff_network_id'] = $db->real_escape_string($aff_campaign_row['aff_network_id'] ?? '');
	$aff_network_sql = "SELECT * FROM `202_aff_networks` WHERE `aff_network_id`='" . $mysql['aff_network_id'] . "'";
	$aff_network_result = $db->query($aff_network_sql) or record_mysql_error($aff_network_sql);
	$aff_network_row = $aff_network_result->fetch_assoc();

	$html['aff_network_id'] = htmlentities((string)($aff_network_row['aff_network_id'] ?? ''), ENT_QUOTES, 'UTF-8');
}

template_top('Landing Page Setup');  ?>
<link rel="stylesheet" href="<?php echo get_absolute_url();?>202-css/design-system.css">

<!-- Page Header - Design System -->
<div class="row" style="margin-bottom: 28px;">
	<div class="col-xs-12">
		<div class="setup-page-header">
			<div class="setup-page-header__icon">
				<span class="glyphicon glyphicon-file"></span>
			</div>
			<div class="setup-page-header__text">
				<h1 class="setup-page-header__title">Landing Pages</h1>
				<p class="setup-page-header__subtitle">Configure your landing pages for simple (single offer) or advanced (multiple offers) setups</p>
			</div>
		</div>
	</div>
</div>

<?php if ($error) { ?>
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
			<i class="fa fa-check-circle"></i> Your deletion was successful. You have successfully removed a landing page.
		</div>
	</div>
</div>
<?php } ?>

<div class="row form_seperator" style="margin-bottom:15px;">
	<div class="col-xs-12"></div>
</div>

<div class="row">
	<div class="col-md-6">
		<small><strong>Add A Landing Page (optional)</strong></small><br />
		<span class="infotext">Here you can add different landing pages you might use with your marketing.</span>

		<form method="post" action="<?php if ($delete_success == true) {
										echo $_SERVER['REDIRECT_URL'] ?? '';
									} ?>" class="form-horizontal" role="form" style="margin:15px 0px;">
			<input name="landing_page_id" type="hidden" value="<?php echo $html['landing_page_id'] ?? ''; ?>" />
			<div class="form-group" style="margin-bottom: 0px;" id="radio-select">
				<label class="col-xs-4 control-label" style="text-align: left;" id="width-tooltip">Landing Page Type <span class="fui-info-circle" data-toggle="tooltip" title="A Simple Landing Page is a landing page that only has one offer associated with it. Where as an Advanced Landing Page is a landing page that can run several offers on it. An example would be a retail landing page where you have outgoing links to several different products."></span></label>

				<div class="col-xs-8" style="margin-top: 10px;">
					<label class="radio">
						<input type="radio" name="landing_page_type" id="landing_page_type1" value="0" data-toggle="radio" <?php if ((isset($html['landing_page_type']) && $html['landing_page_type'] == '0') || !isset($html['landing_page_type']) || (isset($html['landing_page_type']) && !$html['landing_page_type'])) {
																																echo 'checked';
																															} ?>>
						Simple (One Offer on the page)
					</label>
					<label class="radio">
						<input type="radio" name="landing_page_type" id="landing_page_type2" value="1" data-toggle="radio" <?php if (isset($html['landing_page_type']) && $html['landing_page_type'] == '1') {
																																echo 'checked';
																															} ?>>
						Advanced (Mutiple Offers on the page)
					</label>
				</div>
			</div>

			<div id="aff-campaign-div" <?php if (isset($html['landing_page_type']) && $html['landing_page_type'] == '1') {
											echo 'style="display:none;"';
										} ?>>
				<div class="form-group <?php if (isset($error['aff_campaign_id'])) echo "has-error"; ?>" style="margin-bottom: 0px;">
					<label for="aff_network_id" class="col-xs-4 control-label" style="text-align: left;">Category:</label>
					<div class="col-xs-6" style="margin-top: 10px;">
						<img id="aff_network_id_div_loading" class="loading" src="<?php echo get_absolute_url(); ?>202-img/loader-small.gif" />
						<div id="aff_network_id_div"></div>
					</div>
				</div>

				<div id="aff-campaign-group" class="form-group <?php if (isset($error['aff_campaign_id'])) echo "has-error"; ?>" style="margin-bottom: 0px;">
					<label for="aff_campaign_id" class="col-xs-4 control-label" style="text-align: left;">Campaign:</label>
					<div class="col-xs-6" style="margin-top: 10px;">
						<img id="aff_campaign_id_div_loading" class="loading" src="<?php echo get_absolute_url(); ?>202-img/loader-small.gif" style="display: none;" />
						<div id="aff_campaign_id_div">
							<select class="form-control input-sm" id="aff_campaign_id" disabled="">
								<option>--</option>
							</select>
						</div>
					</div>
				</div>
			</div>

			<div class="form-group <?php if (isset($error['landing_page_nickname'])) echo "has-error"; ?>" style="margin-bottom: 0px;">
				<label for="landing_page_nickname" class="col-xs-4 control-label" style="text-align: left;">LP Nickname:</label>
				<div class="col-xs-6" style="margin-top: 10px;">
					<input type="text" class="form-control input-sm" id="landing_page_nickname" name="landing_page_nickname" value="<?php echo $html['landing_page_nickname'] ?? ''; ?>">
				</div>
			</div>

			<div class="form-group <?php if (isset($error['landing_page_url'])) echo "has-error"; ?>" style="margin-bottom: 10px;">
				<label for="landing_page_url" class="col-xs-4 control-label" style="text-align: left;">Landing Page URL:</label>
				<div class="col-xs-6" style="margin-top: 10px;">
					<textarea class="form-control input-sm" rows="3" id="landing_page_url" name="landing_page_url" placeholder="http://"><?php echo $html['landing_page_url'] ?? ''; ?></textarea>
				</div>
			</div>
			<div class="form-group" style="margin-bottom: 10px;">
				<div class="col-xs-6 col-xs-offset-4" id="placeholderslp">
					<span class="help-block" style="font-size: 12px;">The following tracking placeholders can be used:<br /></span>
					<input style="margin-left: 1px;" type="button" class="btn btn-xs btn-primary" value="[[subid]]" />
					<input type="button" class="btn btn-xs btn-primary" value="[[c1]]" />
					<input type="button" class="btn btn-xs btn-primary" value="[[c2]]" />
					<input type="button" class="btn btn-xs btn-primary" value="[[c3]]" />
					<input type="button" class="btn btn-xs btn-primary" value="[[c4]]" /><br /><br />
					<input type="button" class="btn btn-xs btn-primary" value="[[random]]" />
					<input type="button" class="btn btn-xs btn-primary" value="[[referer]]" />
					<input type="button" class="btn btn-xs btn-primary" value="[[gclid]]" /><br /><br />
					<input type="button" class="btn btn-xs btn-primary" value="[[utm_source]]" />
					<input type="button" class="btn btn-xs btn-primary" value="[[utm_medium]]" /><br /><br />
					<input type="button" class="btn btn-xs btn-primary" value="[[utm_campaign]]" />
					<input type="button" class="btn btn-xs btn-primary" value="[[utm_term]]" /><br /><br />
					<input type="button" class="btn btn-xs btn-primary" value="[[utm_content]]" />
					<input type="button" class="btn btn-xs btn-primary" value="[[payout]]" />
					<input type="button" class="btn btn-xs btn-primary" value="[[cpc]]" /><br /><br />
					<input type="button" class="btn btn-xs btn-primary" value="[[cpc2]]" />
					<input type="button" class="btn btn-xs btn-primary" value="[[timestamp]]" />

				</div>
			</div>
			<?php if (isset($_SESSION['user_mods_lb']) && $_SESSION['user_mods_lb'] == 1) { ?> <div id="leave_behind_div" class="form-group <?php if ($error['landing_page_url']) echo "has-error"; ?>" style="margin-bottom: 10px;">
					<label for="leave_behind_page_url" class="col-xs-4 control-label" style="text-align: left;">Leave Behind URL (Optional): <span class="fui-info-circle" data-toggle="tooltip" title="A Leave Behind is a page that is loaded in the background only after someone clicks one of your links on your landing pages. Use this to generate extra revenue from your campaigns"></span></label>
					<div class="col-xs-6" style="margin-top: 10px;">
						<textarea class="form-control input-sm" rows="3" id="leave_behind_page_url" name="leave_behind_page_url" placeholder="http://"><?php echo $html['leave_behind_page_url']; ?></textarea>
					</div>
				</div>
			<?php } ?>
			<div class="form-group">
				<div class="col-xs-6 col-xs-offset-4">
					<?php if ($editing == true) { ?>
						<div class="row">
							<div class="col-xs-6">
								<button class="btn btn-sm btn-p202 btn-block" type="submit">Edit</button>
							</div>
							<div class="col-xs-6">
								<input type="hidden" name="pixel_id" value="<?php echo $selected['pixel_id'] ?? ''; ?>">
								<button type="submit" class="btn btn-sm btn-danger btn-block" onclick="window.location='<?php echo get_absolute_url(); ?>tracking202/setup/landing_pages.php'; return false;">Cancel</button>
							</div>
						</div>
					<?php } else { ?>
						<button class="btn btn-sm btn-p202 btn-block" type="submit" id="addedLp">Add</button>
					<?php } ?>
				</div>
			</div>

		</form>
	</div>

	<div class="col-md-6">
		<div class="panel panel-default">
			<div class="panel-heading">My Advanced Landing Pages</div>
			<div class="panel-body">
				<div id="advLps">
					<input class="form-control input-sm search" style="margin-bottom: 10px; height: 30px;" placeholder="Filter">
					<ul class="setup-list">
						<?php $mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);

						$landing_page_sql = "SELECT * FROM `202_landing_pages` WHERE `user_id`='" . $mysql['user_id'] . "' AND landing_page_type='1' AND landing_page_deleted='0'";

						$landing_page_result = $db->query($landing_page_sql) or record_mysql_error($landing_page_sql);

						if ($landing_page_result->num_rows == 0) {
						?><li>You have no advanced landing page.</li><?php
																	}

																	while ($landing_page_row = $landing_page_result->fetch_array(MYSQLI_ASSOC)) {
																		$html['landing_page_nickname'] = htmlentities((string)($landing_page_row['landing_page_nickname'] ?? ''), ENT_QUOTES, 'UTF-8');
																		$html['landing_page_id'] = htmlentities((string)($landing_page_row['landing_page_id'] ?? ''), ENT_QUOTES, 'UTF-8');

																		if ($userObj->hasPermission("remove_landing_page")) {
																			printf('<li><span class="filter_adv_lp_name">%s</span> <a href="?edit_landing_page_id=%s" class="list-action">edit</a> <a href="?copy_landing_page_id=%s" class="list-action">copy</a> <a href="?delete_landing_page_id=%s&delete_landing_page_name=%s&delete_landing_page_type=1" class="list-action list-action-danger" onclick="return confirmAlert(\'Are You Sure You Want To Delete This Landing Page?\');">remove</a></li>', $html['landing_page_nickname'], $html['landing_page_id'], $html['landing_page_id'], $html['landing_page_id'], $html['landing_page_nickname']);
																		} else {
																			printf('<li><span class="filter_adv_lp_name">%s</span> <a href="?edit_landing_page_id=%s" class="list-action">edit</a></li>', $html['landing_page_nickname'], $html['landing_page_id']);
																		}
																	} ?>
					</ul>
				</div>
			</div>
		</div>
		<div class="panel panel-default">
			<div class="panel-heading">My Simple Landing Pages</div>
			<div class="panel-body">
				<ul class="setup-list">
					<?php $mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
					$aff_network_sql = "SELECT * FROM `202_aff_networks` WHERE `user_id`='" . $mysql['user_id'] . "' AND `aff_network_deleted`='0' ORDER BY `aff_network_name` ASC";
					$aff_network_result = $db->query($aff_network_sql) or record_mysql_error($aff_network_sql);
					if ($aff_network_result->num_rows == 0) {
					?><li>You have no simple landing page.</li><?php
																	}

																	while ($aff_network_row = $aff_network_result->fetch_array(MYSQLI_ASSOC)) {
																		$html['aff_network_name'] = htmlentities((string)($aff_network_row['aff_network_name'] ?? ''), ENT_QUOTES, 'UTF-8');
																		$url['aff_network_id'] = urlencode((string) $aff_network_row['aff_network_id']);

																		printf('<li>%s</li>', $html['aff_network_name']);

																		?><ul class="setup-list"><?php

																		//print out the individual accounts per each PPC network
																		$mysql['aff_network_id'] = $db->real_escape_string($aff_network_row['aff_network_id']);
																		$aff_campaign_sql = "SELECT * FROM `202_aff_campaigns` WHERE `aff_network_id`='" . $mysql['aff_network_id'] . "' AND `aff_campaign_deleted`='0' ORDER BY `aff_campaign_name` ASC";
																		$aff_campaign_result = $db->query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql);

																		while ($aff_campaign_row = $aff_campaign_result->fetch_array(MYSQLI_ASSOC)) {

																			$html['aff_campaign_name'] = htmlentities((string)($aff_campaign_row['aff_campaign_name'] ?? ''), ENT_QUOTES, 'UTF-8');
																			$html['aff_campaign_payout'] = htmlentities((string)($aff_campaign_row['aff_campaign_payout'] ?? ''), ENT_QUOTES, 'UTF-8');

																			printf('<li>%s &middot; &#36;%s</li>', $html['aff_campaign_name'], $html['aff_campaign_payout']);

									?><ul class="setup-list" style="margin-top: 0px;"><?php

																			$mysql['aff_campaign_id'] = $db->real_escape_string($aff_campaign_row['aff_campaign_id']);
																			$landing_page_sql = "SELECT * FROM `202_landing_pages` WHERE `aff_campaign_id`='" . $mysql['aff_campaign_id'] . "' AND `landing_page_deleted`='0' AND landing_page_type='0'";
																			$landing_page_result = $db->query($landing_page_sql) or record_mysql_error($landing_page_sql);

																			while ($landing_page_row = $landing_page_result->fetch_array(MYSQLI_ASSOC)) {

																				$html['landing_page_nickname'] = htmlentities((string)($landing_page_row['landing_page_nickname'] ?? ''), ENT_QUOTES, 'UTF-8');
																				$html['landing_page_id'] = htmlentities((string)($landing_page_row['landing_page_id'] ?? ''), ENT_QUOTES, 'UTF-8');

																				if ($userObj->hasPermission("remove_landing_page")) {
																					printf('<li><span class="filter_simple_lp_name">%s</span> <a href="?edit_landing_page_id=%s" class="list-action">edit</a> <a href="?copy_landing_page_id=%s" class="list-action">copy</a> <a href="?delete_landing_page_id=%s&delete_landing_page_name=%s&delete_landing_page_type=0" class="list-action list-action-danger" onclick="return confirmAlert(\'Are You Sure You Want To Delete This Landing Page?\');">remove</a></li>', $html['landing_page_nickname'], $html['landing_page_id'], $html['landing_page_id'], $html['landing_page_id'], $html['landing_page_nickname']);
																				} else {
																					printf('<li><span class="filter_simple_lp_name">%s</span> <a href="?edit_landing_page_id=%s" class="list-action">edit</a></li>', $html['landing_page_nickname'], $html['landing_page_id']);
																				}
																			}

																	?></ul><?php
																		}

											?></ul><?php

																	}
									?>
				</ul>
			</div>
		</div>
	</div>

</div>
<!-- open up the ajax aff network -->
<script type="text/javascript">
	$(document).ready(function() {

		load_aff_network_id('<?php echo $html['aff_network_id'] ?? ''; ?>');
		<?php if (isset($html['aff_network_id']) && $html['aff_network_id'] != '') { ?>
			load_aff_campaign_id('<?php echo $html['aff_network_id']; ?>', '<?php echo $html['aff_campaign_id'] ?? ''; ?>');
		<?php } ?>

		var advLpOptions = {
			valueNames: ['filter_adv_lp_name'],
			plugins: [
				ListFuzzySearch()
			]
		};

		var advLps = new List('advLps', advLpOptions);
	});
</script>
<script type="text/javascript" src="<?php echo get_absolute_url(); ?>202-js/jquery.caret.js"></script>

<style>
/* ===========================================
   LANDING PAGES - Modern Design System Styles
   =========================================== */

/* Header Section */
.setup-page-header {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 24px;
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    border-radius: 12px;
    color: #fff;
    box-shadow: 0 4px 15px rgba(0, 123, 255, 0.2);
    margin-bottom: 28px;
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
    transition: background 0.3s ease;
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

/* Setup Panel Styles */
.setup-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    overflow: hidden;
    transition: box-shadow 0.3s ease, border-color 0.3s ease;
}

.setup-panel:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    border-color: #cbd5e1;
}

/* Panel Defaults */
.panel-default {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    overflow: hidden;
    transition: box-shadow 0.3s ease;
}

.panel-default:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.panel-heading {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-bottom: 1px solid #e2e8f0;
    padding: 16px 20px;
    font-weight: 600;
    color: #1e293b;
    font-size: 14px;
    letter-spacing: 0.5px;
}

.panel-body {
    padding: 20px;
    background: #fff;
}

/* Form Group Styles */
.setup-form-group {
    margin-bottom: 18px;
    padding-bottom: 18px;
    border-bottom: 1px solid #f1f5f9;
}

.setup-form-group:last-child {
    margin-bottom: 0;
    padding-bottom: 0;
    border-bottom: none;
}

.setup-form-group label {
    font-weight: 500;
    color: #334155;
    margin-bottom: 8px;
    display: block;
    font-size: 13px;
    letter-spacing: 0.3px;
}

.setup-form-group input,
.setup-form-group textarea,
.setup-form-group select {
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 8px 12px;
    font-size: 13px;
    transition: border-color 0.3s ease, box-shadow 0.3s ease;
}

.setup-form-group input:focus,
.setup-form-group textarea:focus,
.setup-form-group select:focus {
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
    outline: none;
}

/* Button Styles */
.setup-btn {
    display: inline-block;
    padding: 10px 20px;
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    color: #fff;
    border: none;
    border-radius: 8px;
    font-weight: 500;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 123, 255, 0.15);
}

.setup-btn:hover {
    box-shadow: 0 4px 12px rgba(0, 123, 255, 0.25);
    transform: translateY(-1px);
}

.setup-btn:active {
    transform: translateY(0);
}

.setup-btn-secondary {
    background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
    box-shadow: 0 2px 8px rgba(107, 114, 128, 0.15);
}

.setup-btn-secondary:hover {
    box-shadow: 0 4px 12px rgba(107, 114, 128, 0.25);
}

.setup-btn-danger {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.15);
}

.setup-btn-danger:hover {
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.25);
}

/* Alert Styles */
.setup-alert {
    padding: 14px 16px;
    border-radius: 8px;
    border-left: 4px solid;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 16px;
}

.setup-alert-success {
    background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
    border-color: #86efac;
    color: #166534;
}

.setup-alert-error {
    background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
    border-color: #fca5a5;
    color: #991b1b;
}

.setup-alert-info {
    background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
    border-color: #7dd3fc;
    color: #0c4a6e;
}

.setup-alert i {
    flex-shrink: 0;
    margin-top: 1px;
}

/* List Styles */
.setup-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.setup-list li {
    padding: 12px 0;
    border-bottom: 1px solid #f1f5f9;
    font-size: 13px;
    color: #334155;
    transition: color 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.setup-list li:last-child {
    border-bottom: none;
}

.setup-list li:hover {
    color: #007bff;
}

.setup-list li > span {
    word-break: break-word;
}

/* Name spans - take up available space */
.filter_adv_lp_name,
.filter_simple_lp_name {
    font-weight: 500;
    flex: 1;
    min-width: 100px;
}

.setup-list .list-action {
    color: #007bff;
    text-decoration: none;
    font-weight: 500;
    transition: color 0.3s ease;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    display: inline-block;
    flex-shrink: 0;
}

.setup-list .list-action:hover {
    color: #0056b3;
    text-decoration: none;
    background: rgba(0, 123, 255, 0.1);
}

.setup-list .list-action-danger {
    color: #ef4444;
}

.setup-list .list-action-danger:hover {
    color: #dc2626;
    background: rgba(239, 68, 68, 0.1);
}

.empty-state {
    text-align: center;
    padding: 24px 16px;
    color: #9ca3af;
    border: 1px dashed #e5e7eb;
    border-radius: 8px;
    font-size: 14px;
}

/* Form Separator */
.form_seperator {
    border-bottom: 2px solid #e2e8f0;
    margin-bottom: 24px;
}

/* Radio and Checkbox Styles */
.radio,
.checkbox {
    margin-top: 8px;
    margin-bottom: 8px;
}

.radio label,
.checkbox label {
    padding-left: 24px;
    cursor: pointer;
    font-weight: 400;
    color: #334155;
}

.radio input,
.checkbox input {
    margin-left: -24px;
    margin-right: 8px;
    cursor: pointer;
}

/* Info Text */
.infotext {
    font-size: 12px;
    color: #64748b;
    font-weight: 400;
    line-height: 1.5;
}

/* Help Block */
.help-block {
    font-size: 12px;
    color: #64748b;
    margin-top: 6px;
    display: block;
}

/* Responsive Design */
@media (max-width: 768px) {
    .setup-page-header {
        flex-direction: column;
        text-align: center;
        padding: 20px 16px;
        margin-bottom: 20px;
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

    .setup-form-group {
        margin-bottom: 16px;
    }

    .setup-btn {
        width: 100%;
        margin-bottom: 8px;
    }
}
</style>

<?php template_bottom();
