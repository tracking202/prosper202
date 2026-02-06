<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -18) . '/202-config/connect.php');

AUTH::require_user();

if (!$userObj->hasPermission("access_to_setup_section")) {
	header('location: ' . get_absolute_url() . 'tracking202/');
	die();
}

$slack = false;
$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
$mysql['user_own_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
$user_sql = "SELECT 2u.user_name as username, 2u.install_hash, 2up.user_slack_incoming_webhook AS url FROM 202_users AS 2u INNER JOIN 202_users_pref AS 2up ON (2up.user_id = 1) WHERE 2u.user_id = '" . $mysql['user_own_id'] . "'";
$user_results = $db->query($user_sql);
$user_row = $user_results->fetch_assoc();

if (!empty($user_row['url']))
	$slack = new Slack($user_row['url']);

// Initialize variables
$error = [];
$html = [];
$add_success = '';
$delete_success = '';
$editing = false;
$network_editing = false;

if (!empty($_GET['edit_aff_network_id'])) {
	$editing = true;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	$aff_network_name = trim((string) $_POST['aff_network_name']);
	if (empty($aff_network_name)) {
		$error['aff_network_name'] = '<div class="error">Type in the name of your campaign\'s category.</div>';
	}

	//if editing, check to make sure the own the network they are editing
	if ($editing == true) {
		$mysql['aff_network_id'] = $db->real_escape_string((string)$_GET['edit_aff_network_id']);
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$aff_network_sql = "SELECT * FROM `202_aff_networks` WHERE `user_id`='" . $mysql['user_id'] . "' AND `aff_network_id`='" . $mysql['aff_network_id'] . "'";
		$aff_network_result = $db->query($aff_network_sql) or record_mysql_error($aff_network_sql);
		if ($aff_network_result->num_rows == 0) {
			$error['wrong_user'] = '<div class="error">You are not authorized to edit another users network</div>';
		} else {
			$aff_network_row = $aff_network_result->fetch_assoc();
		}
	}

	if (! $error) {

		$mysql['aff_network_name'] = $db->real_escape_string((string)$_POST['aff_network_name']);
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$mysql['aff_network_time'] = time();

		if ($editing == true) {
			$aff_network_sql = "UPDATE `202_aff_networks` SET";
		} else {
			$aff_network_sql = "INSERT INTO `202_aff_networks` SET";
		}

		$aff_network_sql .= "`user_id`='" . $mysql['user_id'] . "',
										`aff_network_name`='" . $mysql['aff_network_name'] . "',
										`aff_network_time`='" . $mysql['aff_network_time'] . "'";
		if ($editing == true) {
			$aff_network_sql .= "WHERE `aff_network_id`='" . $mysql['aff_network_id'] . "'";
		}
		$aff_network_result = $db->query($aff_network_sql) or record_mysql_error($aff_network_sql);

		$add_success = true;

		if ($slack) {
			if ($editing == true) {
				$slack->push('campaign_category_name_changed', ['old_name' => $aff_network_row['aff_network_name'], 'new_name' => $_POST['aff_network_name'], 'user' => $user_row['username']]);
			} else {
				$slack->push('campaign_category_created', ['name' => $_POST['aff_network_name'], 'user' => $user_row['username']]);
			}
		}
	}

	tagUserByNetwork($user_row['install_hash'], 'affiliate-networks', $_POST['aff_network_name']);
}


if (!empty($_GET['edit_aff_network_id'])) {

	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
	$mysql['aff_network_id'] = $db->real_escape_string((string)$_GET['edit_aff_network_id']);

	$aff_network_sql = "SELECT 	* 
						 FROM   	`202_aff_networks`
						 WHERE  	`aff_network_id`='" . $mysql['aff_network_id'] . "'
						 AND    		`user_id`='" . $mysql['user_id'] . "'";
	$aff_network_result = $db->query($aff_network_sql) or record_mysql_error($aff_network_sql);
	$aff_network_row = $aff_network_result->fetch_assoc();

	$html = array_map('htmlentities', $aff_network_row);
	$html['aff_network_id'] = htmlentities((string)($_GET['edit_aff_network_id'] ?? ''), ENT_QUOTES, 'UTF-8');
	$autocomplete_aff_network_name =  $html['aff_network_name'];
}

//this will override the edit, if posting and edit fail
if (($_SERVER['REQUEST_METHOD'] == 'POST') and ($add_success != true)) {

	$selected['aff_network_id'] = $_POST['aff_network_id'] ?? '';
	$html = array_map('htmlentities', $_POST);
}

if (isset($_GET['delete_aff_network_id'])) {

	if ($userObj->hasPermission("remove_campaign_category")) {
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$mysql['aff_network_id'] = $db->real_escape_string((string)$_GET['delete_aff_network_id']);
		$mysql['aff_network_time'] = time();

		$delete_sql = " UPDATE  `202_aff_networks`
						SET     `aff_network_deleted`='1',
								`aff_network_time`='" . $mysql['aff_network_time'] . "'
						WHERE   `user_id`='" . $mysql['user_id'] . "'
						AND     `aff_network_id`='" . $mysql['aff_network_id'] . "'";
		if ($delete_result = $db->query($delete_sql) or record_mysql_error($delete_result)) {
			$delete_success = true;

			if ($slack)
				$slack->push('campaign_category_deleted', ['name' => $_GET['delete_aff_network_name'], 'user' => $user_row['username']]);
		}
	} else {
		header('location: ' . get_absolute_url() . 'tracking202/setup/aff_networks.php');
	}
}

template_top('Campaign Category Setup');
?>
<link rel="stylesheet" href="<?php echo get_absolute_url();?>202-css/design-system.css">

<!-- Page Header - Design System -->
<div class="row" style="margin-bottom: 28px;">
	<div class="col-xs-12">
		<div class="setup-page-header">
			<div class="setup-page-header__icon">
				<span class="glyphicon glyphicon-th-large"></span>
			</div>
			<div class="setup-page-header__text">
				<h1 class="setup-page-header__title">Campaign Categories</h1>
				<p class="setup-page-header__subtitle">Organize your campaigns by category - create groups like affiliate networks, niches, or campaign types</p>
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
			<i class="fa fa-check-circle"></i>
			<?php if ($editing == true) { ?>
				Your submission was successful. You have successfully edited the category.
			<?php } else { ?>
				Your submission was successful. You have successfully added a category to your account.
			<?php } ?>
		</div>
	</div>
</div>
<?php } ?>

<?php if ($delete_success == true) { ?>
<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<div class="alert alert-success">
			<i class="fa fa-check-circle"></i> You have successfully deleted a category from your account.
		</div>
	</div>
</div>
<?php } ?>

<div class="row form_seperator" style="margin-bottom:15px;">
	<div class="col-xs-12"></div>
</div>

<div class="row">
	<div class="col-md-6 col-sm-12">
		<div class="panel panel-default">
			<div class="panel-heading">Add Campaign Category</div>
			<div class="panel-body">
				<span class="infotext">What Campaign Categories do you want to use? Some examples include Commission Junction or Mobile etc.</span>

				<form method="post" action="<?php echo $_SERVER['REDIRECT_URL'] ?? ''; ?>" class="setup-form" role="form">
					<div class="form-group <?php if (isset($error['aff_network_name'])) echo "has-error"; ?>">
						<label for="aff_network_name">Category Name</label>
						<input type="text" class="form-control" id="aff_network_name" name="aff_network_name" placeholder="Enter category name..." value="<?php echo $html['aff_network_name'] ?? ''; ?>">
					</div>
					<div class="setup-form-actions">
						<button type="submit" class="btn btn-p202" <?php if ($network_editing != true) { echo 'id="addCategory"'; } ?>><?php echo ($network_editing == true) ? 'Save Changes' : 'Add Category'; ?></button>
						<?php if ($editing == true) { ?>
							<a href="<?php echo get_absolute_url(); ?>tracking202/setup/aff_networks.php" class="btn btn-secondary">Cancel</a>
						<?php } ?>
					</div>
				</form>
			</div>
		</div>
	</div>
	<div class="col-md-6 col-sm-12">
		<div class="panel panel-default">
			<div class="panel-heading">My Campaign Categories</div>
			<div class="panel-body">
				<div id="networkList">
					<input class="form-control fuzzy-search" placeholder="Filter categories...">
					<ul class="list">
						<?php
						$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
						$aff_network_sql = "SELECT * FROM `202_aff_networks` WHERE `user_id`='" . $mysql['user_id'] . "' AND `aff_network_deleted`='0' ORDER BY `aff_network_name` ASC";

						$aff_network_result = $db->query($aff_network_sql) or record_mysql_error($aff_network_sql);
						if ($aff_network_result->num_rows == 0) {
						?>
						<li class="empty-state">No categories added yet</li>
						<?php
						}

						while ($aff_network_row = $aff_network_result->fetch_array(MYSQLI_ASSOC)) {
							$html['aff_network_name'] = htmlentities((string)($aff_network_row['aff_network_name'] ?? ''), ENT_QUOTES, 'UTF-8');
							$html['aff_network_id'] = htmlentities((string)($aff_network_row['aff_network_id'] ?? ''), ENT_QUOTES, 'UTF-8');
							$html['network_logo'] = '';

							if (!empty($aff_network_row['dni_network_id']))
								$html['network_logo'] = '<img src="/202-img/favicon.gif" width=16>&nbsp;&nbsp;';

							if ($userObj->hasPermission("remove_campaign_category")) {
								printf('<li>%s<span class="filter_network_name">%s</span> <a href="?edit_aff_network_id=%s" class="list-action">edit</a> <a href="?delete_aff_network_id=%s&delete_aff_network_name=%s" class="list-action list-action-danger" onclick="return confirmSubmit(\'Are You Sure You Want To Delete This Campaign Category?\');">remove</a></li>',
									$html['network_logo'],
									$html['aff_network_name'],
									$html['aff_network_id'],
									$html['aff_network_id'],
									$html['aff_network_name']);
							} else {
								printf('<li>%s<span class="filter_network_name">%s</span> <a href="?edit_aff_network_id=%s" class="list-action">edit</a></li>',
									$html['network_logo'],
									$html['aff_network_name'],
									$html['aff_network_id']);
							}
						}
						?>
					</ul>
				</div>
			</div>
		</div>
	</div>
</div>
<script type="text/javascript">
	$(document).ready(function() {
		autocomplete_names('aff_network_name', 'affiliate-networks');
		<?php if (!empty($_GET['edit_aff_network_id'])) { ?>
			$("#aff_network_name").tokenfield("setTokens", <?php print_r(json_encode(['value' => $autocomplete_aff_network_name, 'label' => $autocomplete_aff_network_name])) ?>);
		<?php } ?>

		var networkOptions = {
			valueNames: ['filter_network_name'],
			plugins: [
				ListFuzzySearch()
			]
		};

		var networkList = new List('networkList', networkOptions);
	});
</script>

<style>
/* ===========================================
   CAMPAIGN CATEGORIES - Modern Design System
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

/* Form Styling */
.form-inline {
    display: flex;
    gap: 8px;
    align-items: flex-end;
    flex-wrap: wrap;
}

.form-group {
    flex: 1;
    min-width: 280px;
    margin-bottom: 0;
}

.form-group.has-error .form-control {
    border-color: #f87171;
    background-color: #fee2e2;
}

.form-control {
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 14px;
    transition: all 0.3s ease;
    background-color: #fff;
}

.form-control:focus {
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
    outline: none;
}

.form-control::placeholder {
    color: #94a3b8;
}

.input-sm {
    padding: 8px 10px;
    font-size: 13px;
    height: 34px;
}

/* Button Styling */
.btn {
    border: none;
    border-radius: 8px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 10px 16px;
    font-size: 14px;
}

.btn-xs {
    padding: 6px 12px;
    font-size: 12px;
    height: 32px;
}

.btn-p202 {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    color: #fff;
    border: 1px solid #0056b3;
}

.btn-p202:hover {
    background: linear-gradient(135deg, #0056b3 0%, #004085 100%);
    box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3);
    transform: translateY(-2px);
}

.btn-p202:active {
    transform: translateY(0);
    box-shadow: 0 2px 6px rgba(0, 123, 255, 0.2);
}

.btn-danger {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: #fff;
    border: 1px solid #dc2626;
}

.btn-danger:hover {
    background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
    transform: translateY(-2px);
}

.btn-danger:active {
    transform: translateY(0);
    box-shadow: 0 2px 6px rgba(239, 68, 68, 0.2);
}

/* Panel Styling */
.panel {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    background-color: #fff;
    margin-bottom: 20px;
}

.panel-default {
    border-color: #e2e8f0;
}

.panel-heading {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-bottom: 1px solid #e2e8f0;
    padding: 16px 20px;
    font-weight: 600;
    font-size: 15px;
    color: #1e293b;
}

.panel-body {
    padding: 20px;
}

/* Setup Form */
.setup-form {
    margin-top: 16px;
}

.setup-form .form-group {
    margin-bottom: 16px;
}

.setup-form .form-group label {
    display: block;
    font-weight: 600;
    font-size: 13px;
    color: #374151;
    margin-bottom: 6px;
}

.setup-form-actions {
    display: flex;
    gap: 10px;
    margin-top: 20px;
}

/* Network List Styling */
#networkList ul.list {
    list-style: none;
    padding: 0;
    margin: 12px 0 0 0;
}

#networkList ul.list li {
    padding: 12px 14px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    margin-bottom: 8px;
    font-size: 14px;
    color: #374151;
    background: #fff;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}

#networkList ul.list li:hover {
    border-color: #c7d2fe;
    background: #f8fafc;
}

#networkList ul.list li:last-child {
    margin-bottom: 0;
}

#networkList .filter_network_name {
    font-weight: 500;
    flex: 1;
    min-width: 120px;
}

#networkList .list-action {
    color: #007bff;
    text-decoration: none;
    font-weight: 500;
    font-size: 13px;
    padding: 4px 10px;
    border-radius: 4px;
    transition: all 0.2s ease;
    margin-left: 4px;
}

#networkList .list-action:hover {
    background-color: #eff6ff;
    color: #0056b3;
}

#networkList .list-action-danger {
    color: #dc2626;
}

#networkList .list-action-danger:hover {
    background-color: #fef2f2;
    color: #991b1b;
}

#networkList .empty-state {
    text-align: center;
    padding: 24px;
    color: #9ca3af;
    border-style: dashed;
}

/* Alert Styles */
.alert {
    border-radius: 8px;
    padding: 12px 16px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 14px;
    margin-bottom: 20px;
}

.alert-success {
    background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
    border: 1px solid #86efac;
    color: #166534;
}

.alert-danger {
    background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
    border: 1px solid #fca5a5;
    color: #991b1b;
}

.alert .fa {
    font-size: 16px;
    flex-shrink: 0;
}

/* Form Separator */
.form_seperator {
    border-bottom: 2px solid #e2e8f0;
    margin: 20px 0 !important;
}

/* Info Text */
.infotext {
    display: block;
    color: #64748b;
    font-size: 13px;
    margin-bottom: 12px;
    line-height: 1.5;
}

small strong {
    color: #1e293b;
    font-size: 14px;
}

/* Secondary Button */
.btn-secondary {
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #e2e8f0;
}

.btn-secondary:hover {
    background: #e2e8f0;
    color: #1e293b;
}

/* Fuzzy Search Input */
.fuzzy-search {
    border: 1px solid #e5e7eb !important;
    border-radius: 8px !important;
    padding: 10px 14px !important;
    font-size: 14px;
    width: 100%;
}

.fuzzy-search:focus {
    border-color: #007bff !important;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1) !important;
    outline: none;
}

/* Responsive Design */
@media (max-width: 992px) {
    .form-inline {
        flex-direction: column;
        align-items: stretch;
    }

    .form-group {
        min-width: 100%;
    }

    .btn {
        width: 100%;
    }
}

@media (max-width: 768px) {
    .setup-page-header {
        flex-direction: column;
        text-align: center;
        padding: 20px 16px;
        gap: 12px;
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

    .form-inline {
        gap: 10px;
    }

    .btn-xs {
        font-size: 11px;
        padding: 5px 10px;
        height: 30px;
    }

    .panel-body .list li {
        flex-direction: column;
        align-items: flex-start;
    }

    .panel-body .list li a {
        margin-left: 0;
        margin-top: 8px;
    }
}
</style>

<?php template_bottom(); ?>