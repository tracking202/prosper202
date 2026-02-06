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

$slack = false;
$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
$mysql['user_own_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
$user_sql = "SELECT 2u.user_name as username, 2up.user_slack_incoming_webhook AS url, 2up.maxmind_isp FROM 202_users AS 2u INNER JOIN 202_users_pref AS 2up ON (2up.user_id = 1) WHERE 2u.user_id = '".$mysql['user_own_id']."'";
$user_results = $db->query($user_sql);
$user_row = $user_results->fetch_assoc();

if (!empty($user_row['url']))
	$slack = new Slack($user_row['url']);

if (!empty($_POST['edit_rotator'])) {
	$editing = true;
}

if (!empty($_GET['rules_added'])) {
	$add_success = true;
}

if ($_SERVER['REQUEST_METHOD'] == "POST") {

	$rotator_name = trim((string) $_POST['rotator_name']);
	if (empty($rotator_name)) {
		$error['rotator_name'] = '<div class="error">Type in the name of your rotator!</div>';
	}

	if (!$error) {
		$mysql['rotator_name'] = $db->real_escape_string($_POST['rotator_name']);
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);

		if ($editing == true) {
			$sql = "UPDATE `202_aff_networks` SET";
		} else {
			$sql = "INSERT INTO 202_rotators SET name='" . $mysql['rotator_name'] . "', user_id='" . $mysql['user_id'] . "'";
		}

		$result = $db->query($sql);
		$rotator_id = $db->insert_id;

		$sql = "UPDATE 202_rotators SET public_id='" . random_int(1, 9) . $rotator_id . random_int(1, 9) . "' WHERE id='" . $rotator_id . "'";
		$result = $db->query($sql);
		$add_success = true;

		if ($slack)
			$slack->push('rotator_created', ['name' => $_POST['rotator_name'], 'user' => $user_row['username']]);
	}
}

if (isset($_GET['delete_rotator_id'])) {

	if ($userObj->hasPermission("remove_rotator")) {
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$mysql['rotator_id'] = $db->real_escape_string((string)$_GET['delete_rotator_id']);

		$delete_sql = "DELETE FROM 202_rotators WHERE id='" . $mysql['rotator_id'] . "' AND user_id='" . $mysql['user_id'] . "'";

		if (_mysqli_query($delete_sql)) {
			$rule_sql = "DELETE FROM 202_rotator_rules WHERE rotator_id='" . $mysql['rotator_id'] . "'";

			if (_mysqli_query($rule_sql)) {
				$criteria_sql = "DELETE FROM 202_rotator_rules_criteria WHERE rotator_id='" . $mysql['rotator_id'] . "'";
				if (_mysqli_query($criteria_sql)) {
					$delete_success = true;
					if ($slack)
						$slack->push('rotator_deleted', ['name' => $_GET['delete_rotator_name'], 'user' => $user_row['username']]);
				}
			}
		}
	} else {
		header('location: ' . get_absolute_url() . 'tracking202/setup/rotator.php');
	}
}


template_top('Smart Redirector'); ?>
<link rel="stylesheet" href="<?php echo get_absolute_url();?>202-css/design-system.css">

<!-- Page Header - Design System -->
<div class="row" style="margin-bottom: 28px;">
	<div class="col-xs-12">
		<div class="setup-page-header">
			<div class="setup-page-header__icon">
				<span class="glyphicon glyphicon-refresh"></span>
			</div>
			<div class="setup-page-header__text">
				<h1 class="setup-page-header__title">Redirector</h1>
				<p class="setup-page-header__subtitle">Create intelligent routing rules based on country, device, browser, or custom criteria</p>
			</div>
		</div>
	</div>
</div>

<div class="row" style="margin-bottom: 15px; display: none;" id="form_erors">
	<div class="col-xs-12">
		<div class="alert alert-danger">
			<i class="fa fa-exclamation-circle"></i> Hey! Make sure all fields are filled.
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
			<i class="fa fa-check-circle"></i> Your deletion was successful. You have successfully removed a redirector.
		</div>
	</div>
</div>
<?php } ?>

<div class="row form_seperator" style="margin-bottom:15px;">
	<div class="col-xs-12"></div>
</div>

<div class="row">
	<div class="col-md-6">
		<small><strong>Add New Smart Redirector</strong></small><br />
		<span class="infotext">Give a name for your redirector.</span>

		<form method="post" action="" class="form-inline" role="form" style="margin:15px 0px;">
			<div class="form-group">
				<label class="sr-only" for="rotator_name">Smart Redirector</label>
				<input type="text" class="form-control input-sm" id="rotator_name" name="rotator_name" placeholder="Redirector name">
			</div>
			<button type="submit" class="btn btn-xs btn-p202" id="addRotator">Add</button>
		</form>
	</div>

	<div class="col-md-6">
		<div class="panel panel-default">
			<div class="panel-heading">My Smart Redirectors</div>

			<div class="panel-body">

				<div id="filterRotators">
					<input class="form-control input-sm search" style="margin-bottom: 10px; height: 30px;" placeholder="Filter">
					<ul class="list">
						<?php
						$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
						$sql = "SELECT * FROM `202_rotators` WHERE `user_id`='" . $mysql['user_id'] . "' ORDER BY `name` ASC";
						$result = $db->query($sql) or record_mysql_error($sql);
						if ($result->num_rows == 0) {
						?><li class="empty-state">No redirectors added yet</li><?php
																	}

																	while ($row = $result->fetch_array(MYSQLI_ASSOC)) {
																		$html['name'] = htmlentities((string) $row['name'], ENT_QUOTES, 'UTF-8');
																		$html['id'] = htmlentities((string) $row['id'], ENT_QUOTES, 'UTF-8');

																		if ($userObj->hasPermission("remove_rotator")) {
																			printf('<li><span class="filter_rotator_name">%s</span> <a href="?delete_rotator_id=%s&delete_rotator_name=%s" class="list-action list-action-danger" onclick="return confirmSubmit(\'Are you sure?\');">remove</a></li>', $html['name'], $html['id'], $html['name']);
																		} else {
																			printf('<li><span class="filter_rotator_name">%s</span></li>', $html['name']);
																		}

																		$rule_sql = "SELECT * FROM `202_rotator_rules` WHERE `rotator_id`='" . $row['id'] . "' ORDER BY `id` ASC";
																		$rule_result = $db->query($rule_sql) or record_mysql_error($rule_sql);
																		if ($rule_result->num_rows == 0) {
																		?><ul>
									<li>You have not added any rules.</li>
								</ul><?php
																		} else {
																			echo "<ul>";
																			while ($rule_row = $rule_result->fetch_array()) {
																				$criteria_sql = "SELECT * FROM `202_rotator_rules_criteria` WHERE `rule_id`='" . $rule_row['id'] . "' ORDER BY `id` ASC";
																				$criteria_result = $db->query($criteria_sql) or record_mysql_error($criteria_sql);
																				if ($criteria_result->num_rows > 0) {
																					$criteria = "You have " . $criteria_result->num_rows . " criteria added";
																				} else {
																					$criteria = "No criteria added";
																				}

										?>
									<li><span class="filter_rule_name"><?php echo $rule_row['rule_name']; ?></span><span class="rule-criteria"><?php echo $criteria; ?></span> <a href="" id="rule_details" data-id="<?php echo $rule_row['id']; ?>" data-toggle="modal" data-target="#rule_values_modal" class="list-action">Details</a></li>
								<?php }
																			echo "</ul>";
								?>

						<?php }
																	}
						?>
					</ul>
				</div>
			</div>
		</div>
	</div>
</div>

<div class="row form_seperator" style="margin-bottom:15px;">
	<div class="col-xs-12"></div>
</div>

<div class="row">
	<div class="col-xs-12">
		<small><strong>Add Rule to Your Smart Redirector</strong></small><br />
		<span class="infotext">Select redirector, to add new rule. You can add as many rules as you want, for each redirector.</span>
	</div>
</div>

<form class="form-inline" onsubmit="return false;" role="form" id="rule_form" method="post" action="">
	<div class="row" style="margin-top:15px;">
		<div class="col-xs-4">
			<div class="form-group">
				<img id="rules_loading" class="loading" src="/202-img/loader-small.gif" style="display:none;right: -20px;top: 10px;" />
				<label for="rotator_id" style="margin-right:5px;">Select redirector: </label>
				<select class="form-control input-sm" name="rotator_id" style="min-width: 130px;">
					<option value="0">--</option>
					<?php $mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
					$rotator_sql = "SELECT * FROM `202_rotators` WHERE `user_id`='" . $mysql['user_id'] . "' ORDER BY `name` ASC";
					$rotator_result = _mysqli_query($rotator_sql);
					while ($rotator_row = $rotator_result->fetch_array(MYSQLI_ASSOC)) {

						$html['rotator_name'] = htmlentities((string)($rotator_row['name'] ?? ''), ENT_QUOTES, 'UTF-8');
						$html['rotator_id'] = htmlentities((string)($rotator_row['id'] ?? ''), ENT_QUOTES, 'UTF-8');

						printf('<option value="%s">%s</option>', $html['rotator_id'], $html['rotator_name']);
					} ?>
				</select>
			</div>
		</div>

		<div id="defaults_container" style="opacity:0.5">
			<div class="col-xs-4">
				<label for="default_type" class="col-xs-5 control-label">Defaults to: </label>
				<select class="form-control input-sm" name="default_type" id="default_type_select" disabled>
					<option value="">Campaign</option>
					<option value="">Landing Page</option>
					<option value="">Url</option>
					<!--<option value="">Auto Monetizer</option>-->
				</select>
			</div>

			<div class="col-xs-4" id="default_campaign_select">
				<select class="form-control input-sm" name="default_campaign" style="width: 100%;" disabled>
					<option value="">--</option>
				</select>
			</div>
			<div class="col-xs-8" id="default_url_input" style="display:none">
				<div class="input-group input-group-sm">
					<span class="input-group-addon"><i class="fa fa-globe"></i></span>
					<input name="default_url" class="form-control" type="text" placeholder="http://" disabled>
				</div>
			</div>
		</div>

	</div>

	<div class="row" id="rotator_rules_container" style="opacity:0.5">
		<div class="col-xs-12" style="margin-top:15px;">
			<div class="col-xs-12 rules">
				<div class="row">
					<div class="col-xs-12">
						<div class="form-group">
							<label for="rule_name">Rule name: </label>
							<input class="form-control input-sm" name="rule_name" placeholder="Type in rule name" disabled />
						</div>
						<div class="form-group" style="float:right; margin-right: 25px;">
							<label class="checkbox" for="inactive" style="margin-bottom: 12px;padding-left: 32px;">
								<input type="checkbox" id="inactive" name="inactive" data-toggle="checkbox">
								Inactive
							</label>
						</div>
						<div class="form-group" style="float:right; margin-right: 25px;">
							<label class="checkbox" for="splittest" style="margin-bottom: 12px;padding-left: 32px;">
								<input type="checkbox" id="splittest" name="splittest" data-toggle="checkbox">Split test</label>
						</div>
					</div>
				</div>

				<div class="row form_seperator" style="margin-top:10px; margin-bottom:10px;">
					<div class="col-xs-12" style="width: 97.5%;"></div>
				</div>

				<div class="row">
					<div class="col-xs-10" id="criteria_container">
						<div class="criteria" id="criteria">
							<div class="form-group">
								<label for="rule_type">If</label>
								<select class="form-control input-sm" name="rule_type" style="margin: 0px 5px;" disabled>
									<option value="country">Country</option>
									<option value="region">State/Region</option>
									<option value="city">Cities</option>
									<option value="isp" <?php if (!isset($user_row['maxmind_isp']) || $user_row['maxmind_isp'] != '1') echo "disabled"; ?>>ISP/Carrier</option>
									<option value="ip">IP Address</option>
									<option value="browser">Browser Name</option>
									<option value="platform">OS</option>
									<option value="device">Device Type</option>
								</select>
							</div>

							<div class="form-group">
								<label for="rule_statement"><i class="fa fa-angle-double-right"></i></label>
								<select class="form-control input-sm" name="rule_statement" style="margin: 0px 5px;" disabled>
									<option value="is">IS</option>
									<option value="is_not">IS NOT</option>
								</select>
							</div>

							<div class="form-group">
								<label for="rule_value">equal to:</label>
								<input id="tag" class="value_select" name="value" placeholder="Type in country and hit Enter" disabled />
							</div>
						</div>
					</div>

					<div class="col-xs-2" style="margin-left: -18px; margin-top: 10px;">
						<div class="form-group">
							<img id="addmore_criteria_loading" class="loading" src="<?php echo get_absolute_url(); ?>202-img/loader-small.gif" style="display:none; position: absolute; top: 4px; left: -20px;">
							<button id="add_more_criteria" class="btn btn-xs btn-default" disabled><span class="fui-plus"></span> Add more criteria</button>
						</div>
					</div>
				</div>

				<div class="row form_seperator" style="margin-top:10px; margin-bottom:10px;">
					<div class="col-xs-12" style="width: 97.5%;"></div>
				</div>

				<div class="row">
					<div class="col-xs-4">
						<label for="redirect_type" style="margin-left: -15px;" class="col-xs-5 control-label">Redirects to: </label>
						<select class="form-control input-sm" name="redirect_type" id="redirect_type_select" disabled>
							<option value="">Campaign</option>
							<option value="">Landing Page</option>
							<option value="">Url</option>
							<!--<option value="">Auto Monetizer</option>-->
						</select>
					</div>

					<div class="col-xs-4" id="redirect_campaign_select" style="margin-left: -3%">
						<select class="form-control input-sm" name="redirect_campaign" style="width: 100%;" disabled>
							<option value="">--</option>
						</select>
					</div>
					<div class="col-xs-8" id="redirect_url_input" style="display:none; width: 64.5%;">
						<div class="input-group input-group-sm">
							<span class="input-group-addon"><i class="fa fa-globe"></i></span>
							<input name="redirect_url" class="form-control" type="text" placeholder="http://" disabled>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
	<div class="row">
		<div class="col-xs-7" style="margin-top:15px;">
			<span class="infotext">*If you want to split-test all visitors, select at least one criteria and type: <i><b>ALL</b></i> as value and hit <i><b>Enter</b></i>.</span>
		</div>
		<div class="col-xs-5 text-right" style="margin-top:15px;">
			<img id="addmore_loading" class="loading" src="<?php echo get_absolute_url(); ?>202-img/loader-small.gif" style="display: none; position: static;">
			<button id="add_more_rules" class="btn btn-xs btn-default" disabled><span class="fui-plus"></span> Add more rules</button>
			<button class="btn btn-xs btn-p202" id="post_rules" disabled>Save rules</button>
		</div>
	</div>
</form>


<script type="text/javascript">
	$(document).ready(function() {
		rotator_tags_autocomplete('tag', 'country');
		var rotatorOptions = {
			valueNames: ['filter_adv_lp_name'],
			plugins: [
				ListFuzzySearch()
			]
		};

		var filterRotators = new List('filterRotators', rotatorOptions);
	});
</script>

<div id="rule_values_modal" class="modal fade" role="dialog" aria-hidden="true">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
				<h4 class="modal-title">Rule details</h4>
			</div>
			<div class="modal-body">
			</div>
			<div class="modal-footer">
				<button class="btn btn-wide btn-default" data-dismiss="modal">Close</button>
			</div>
		</div>
	</div>
</div>
<style>
/* ===========================================
   SMART REDIRECTOR - Modern Design System
   =========================================== */

/* Page Header - Blue Gradient */
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
    font-size: 24px;
    color: #fff;
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
    line-height: 1.4;
}

/* Panel Styles - Modern Design */
.panel-default {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    overflow: hidden;
    background: #fff;
    transition: box-shadow 0.2s ease;
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
    font-size: 15px;
}

.panel-body {
    padding: 20px;
}

/* Form Elements - Modern Style */
.form-control {
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 8px 12px;
    font-size: 14px;
    transition: all 0.2s ease;
}

.form-control:focus {
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
    outline: none;
}

.input-sm {
    padding: 6px 10px;
    font-size: 13px;
    height: 32px;
}

/* Button Styles */
.btn {
    border-radius: 8px;
    font-weight: 500;
    transition: all 0.2s ease;
    border: none;
    padding: 8px 16px;
    font-size: 14px;
}

.btn-p202 {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    color: #fff;
    box-shadow: 0 2px 8px rgba(0, 123, 255, 0.3);
}

.btn-p202:hover {
    background: linear-gradient(135deg, #0056b3 0%, #003d82 100%);
    box-shadow: 0 4px 12px rgba(0, 123, 255, 0.4);
    color: #fff;
    text-decoration: none;
}

.btn-default {
    background: #f1f5f9;
    color: #334155;
    border: 1px solid #cbd5e1;
}

.btn-default:hover {
    background: #e2e8f0;
    color: #1e293b;
}

.btn-xs {
    padding: 6px 12px;
    font-size: 12px;
    height: auto;
}

/* Alert Styles - Success & Danger */
.alert {
    border-radius: 8px;
    padding: 12px 16px;
    border: 1px solid;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 14px;
    margin-bottom: 0;
}

.alert-success {
    background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
    border-color: #86efac;
    color: #166534;
}

.alert-success .fa {
    color: #22c55e;
    font-size: 16px;
}

.alert-danger {
    background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
    border-color: #fca5a5;
    color: #991b1b;
}

.alert-danger .fa {
    color: #ef4444;
    font-size: 16px;
}

/* Section Separator */
.form_seperator {
    border-bottom: 1px solid #e2e8f0;
}

/* List Styling */
.list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.list > li {
    padding: 8px 12px;
    border-bottom: 1px solid #f1f5f9;
    font-size: 13px;
    color: #475569;
}

.list > li:last-child {
    border-bottom: none;
}

.list > li:hover {
    background: #f8fafc;
}

.list > li a {
    color: #007bff;
    text-decoration: none;
    font-weight: 500;
}

.list > li a:hover {
    text-decoration: underline;
}

/* Info Text */
.infotext {
    font-size: 13px;
    color: #64748b;
    display: block;
    margin-bottom: 12px;
}

/* Checkbox Styling */
.checkbox {
    display: flex;
    align-items: center;
    font-size: 14px;
    cursor: pointer;
}

.checkbox input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
    margin-right: 6px;
    accent-color: #007bff;
}

/* Modal Styles */
.modal-header {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-bottom: 1px solid #e2e8f0;
    padding: 16px 20px;
}

.modal-title {
    font-size: 16px;
    font-weight: 600;
    color: #1e293b;
    margin: 0;
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    padding: 12px 16px;
}

/* Form Group Styling */
.form-group {
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.form-group label {
    font-size: 14px;
    color: #334155;
    font-weight: 500;
    margin: 0;
    white-space: nowrap;
}

.form-group select,
.form-group input {
    flex: 1;
    max-width: 200px;
}

/* List Item Name Styling */
.filter_rotator_name,
.filter_rule_name {
    font-weight: 500;
    color: #1e293b;
    flex: 1;
    min-width: 100px;
}

.rule-criteria {
    font-size: 12px;
    color: #64748b;
    font-weight: 400;
}

/* List Action Links */
.list-action {
    color: #007bff;
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    padding: 4px 8px;
    border-radius: 4px;
    transition: all 0.2s ease;
    white-space: nowrap;
}

.list-action:hover {
    background: #f0f7ff;
    color: #0056b3;
    text-decoration: none;
}

.list-action-danger {
    color: #dc2626;
}

.list-action-danger:hover {
    background: #fef2f2;
    color: #b91c1c;
}

.empty-state {
    text-align: center;
    padding: 24px 16px;
    color: #9ca3af;
    border: 1px dashed #e5e7eb;
    border-radius: 8px;
    font-size: 14px;
}

.list > li {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
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
        font-size: 20px;
    }

    .setup-page-header__title {
        font-size: 20px;
    }

    .setup-page-header__subtitle {
        font-size: 13px;
    }

    .alert {
        flex-direction: column;
        align-items: flex-start;
        padding: 16px;
    }

    .form-group {
        flex-direction: column;
        align-items: flex-start;
    }

    .form-group select,
    .form-group input {
        width: 100%;
        max-width: none;
    }

    .list > li {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }

    .list-action {
        align-self: flex-start;
    }
}
</style>

<?php template_bottom();
