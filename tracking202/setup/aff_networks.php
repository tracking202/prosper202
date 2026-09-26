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
	// validate session token before any state change
	if (!hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_POST['token'] ?? ''))) {
		$error['token'] = '<div class="error">Invalid token, please reload the page and try again.</div>';
	}

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

		// Landing Page Optimizer (segments-v2 G10): flag this user's dimension
		// snapshot dirty; the hourly cron pushes it. DB-only — no HTTP here.
		\Prosper202\Lpo\DimensionSync::markDirty($db, (int) ($_SESSION['user_id'] ?? 0));

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
	if (!is_array($aff_network_row)) {
		// Not this user's category, or gone: show the add form rather than
		// a fatal from array_map(null).
		$editing = false;
		$aff_network_row = [];
	}

	$html = array_map(fn($value) => htmlentities((string) ($value ?? ''), ENT_QUOTES, 'UTF-8'), $aff_network_row);
	$html['aff_network_id'] = htmlentities((string)($_GET['edit_aff_network_id'] ?? ''), ENT_QUOTES, 'UTF-8');
}

//this will override the edit, if posting and edit fail
if (($_SERVER['REQUEST_METHOD'] == 'POST') and ($add_success != true)) {

	$selected['aff_network_id'] = $_POST['aff_network_id'] ?? '';
	$html = array_map(htmlentities(...), $_POST);
}

if (isset($_GET['delete_aff_network_id'])) {

	// validate session token before any state change
	if (!hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_GET['token'] ?? ''))) {
		header('location: ' . get_absolute_url() . 'tracking202/setup/aff_networks.php');
		die();
	}

	if ($userObj->hasPermission("remove_campaign_category")) {
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$mysql['aff_network_id'] = $db->real_escape_string((string)$_GET['delete_aff_network_id']);
		$mysql['aff_network_time'] = time();

		$delete_sql = " UPDATE  `202_aff_networks`
						SET     `aff_network_deleted`='1',
								`aff_network_time`='" . $mysql['aff_network_time'] . "'
						WHERE   `user_id`='" . $mysql['user_id'] . "'
						AND     `aff_network_id`='" . $mysql['aff_network_id'] . "'";
		if ($delete_result = $db->query($delete_sql) or record_mysql_error($db, $delete_sql)) {
			$delete_success = true;

			// Landing Page Optimizer (segments-v2 G10): mirror the save-path
			// hook above — every dictionary mutation on this page flags the
			// snapshot dirty for the hourly cron. DB-only — no HTTP here.
			\Prosper202\Lpo\DimensionSync::markDirty($db, (int) ($_SESSION['user_id'] ?? 0));

			if ($slack)
				$slack->push('campaign_category_deleted', ['name' => $_GET['delete_aff_network_name'], 'user' => $user_row['username']]);
		}
	} else {
		header('location: ' . get_absolute_url() . 'tracking202/setup/aff_networks.php');
	}
}

// Post-redirect-get: a saved or removed category answers with a redirect, so
// a reload cannot submit the form (or the remove link) a second time.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $add_success == true) {
	header('location: ' . get_absolute_url() . 'tracking202/setup/aff_networks.php?' . ($editing ? 'saved=1' : 'added=1'));
	exit;
}
if ($delete_success == true) {
	header('location: ' . get_absolute_url() . 'tracking202/setup/aff_networks.php?deleted=1');
	exit;
}

require_once __DIR__ . '/_includes/setup_ui.php';

$base = get_absolute_url();
$self = $base . 'tracking202/setup/aff_networks.php';
$canRemove = $userObj->hasPermission("remove_campaign_category");
$categories = p202_setup_rows($db, "SELECT * FROM `202_aff_networks` WHERE `user_id`='" . $db->real_escape_string((string)$_SESSION['user_id']) . "' AND `aff_network_deleted`='0' ORDER BY `aff_network_name` ASC");
// What the form shows: the row being edited, or what was just refused.
$formName = $_SERVER['REQUEST_METHOD'] == 'POST'
	? (string) ($_POST['aff_network_name'] ?? '')
	: (string) ($aff_network_row['aff_network_name'] ?? '');
$editId = $editing ? (int) ($_GET['edit_aff_network_id'] ?? 0) : 0;

template_top('Campaign Category Setup', ['ui' => 'v2']);
?>

<div class="p202-page-header p202-page-header--accent">
	<div class="p202-page-header__icon"><i class="bi bi-grid"></i></div>
	<div class="p202-page-header__text">
		<h1 class="p202-page-header__title">Campaign Categories</h1>
		<p class="p202-page-header__desc">Group your campaigns: by affiliate network, by niche, or however you report on them.</p>
	</div>
</div>

<?php
echo p202_setup_query_flashes([
	'added' => 'Category added. Add its campaigns next.',
	'saved' => 'Category renamed.',
	'deleted' => 'Category removed. Its campaigns keep their history.',
], $_GET);
if ($error) {
	echo p202_setup_error_flashes($error, ['aff_network_name']);
}
?>

<div class="row g-4">
	<div class="col-12 col-lg-6">
		<section class="p202-panel" id="category-form">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title"><?php echo $editing ? 'Rename category' : 'Add a category'; ?></h2>
				<p class="p202-panel__sub"><?php echo $editing ? 'Campaigns in it keep their place.' : 'For example the network you promote, or a niche like Mobile.'; ?></p>
			</div>
			<div class="p202-panel__body">
				<form method="post" action="<?php echo p202_setup_e($self . ($editing ? '?edit_aff_network_id=' . $editId : '')); ?>">
					<?php echo p202_setup_token_field((string) ($_SESSION['token'] ?? '')); ?>
					<div class="mb-3">
						<label class="form-label" for="aff_network_name">Category name</label>
						<input type="text" class="form-control<?php echo p202_setup_invalid($error, 'aff_network_name'); ?>" id="aff_network_name" name="aff_network_name" value="<?php echo p202_setup_e($formName); ?>" maxlength="255" required<?php echo $categories === [] || $editing ? ' autofocus' : ''; ?>>
						<?php echo p202_setup_feedback($error, 'aff_network_name'); ?>
					</div>
					<div class="p202-form-actions">
						<?php if ($editing) { ?>
							<a class="btn btn-link" href="<?php echo p202_setup_e($self); ?>">Cancel</a>
							<button type="submit" class="btn btn-primary">Save changes</button>
						<?php } else { ?>
							<button type="submit" class="btn btn-primary" id="addCategory">Add category</button>
						<?php } ?>
					</div>
				</form>
			</div>
		</section>
	</div>

	<div class="col-12 col-lg-6">
		<section class="p202-panel">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title">Your categories</h2>
				<span class="p202-pill p202-pill--accent"><?php echo count($categories) . ' ' . (count($categories) === 1 ? 'category' : 'categories'); ?></span>
				<?php if (count($categories) > 5) { ?>
					<div class="p202-panel__aside"><?php echo p202_setup_list_filter('category-list', 'Filter categories…'); ?></div>
				<?php } ?>
			</div>
			<div class="p202-panel__body">
				<?php if ($categories === []) { ?>
					<div class="p202-empty">
						<i class="bi bi-grid p202-empty__icon"></i>
						<strong class="p202-empty__title">No categories yet</strong>
						<div>A category holds campaigns. Name your first one, then add its campaigns.</div>
						<div class="p202-empty__action"><a class="btn btn-secondary btn-sm" href="#aff_network_name">Name your first category</a></div>
					</div>
				<?php } else { ?>
					<ul class="p202-list" id="category-list">
						<?php foreach ($categories as $category) {
							$id = (int) $category['aff_network_id'];
							$name = (string) $category['aff_network_name']; ?>
							<li class="p202-list__item<?php echo $editId === $id ? ' is-active' : ''; ?>" data-p202-filter-text="<?php echo p202_setup_e($name); ?>">
								<span class="p202-list__name"><?php echo p202_setup_e($name); ?></span>
								<?php if (!empty($category['dni_network_id'])) { ?>
									<span class="p202-pill">network integration</span>
								<?php } ?>
								<span class="p202-list__actions">
									<a class="p202-list__action" href="<?php echo p202_setup_e($self . '?edit_aff_network_id=' . $id); ?>">edit</a>
									<?php if ($canRemove) {
										echo p202_setup_remove_form($self, [
											'delete_aff_network_id' => $id,
											'delete_aff_network_name' => $name,
											'token' => (string) ($_SESSION['token'] ?? ''),
										], 'Remove the category "' . $name . '"? Its campaigns keep their clicks and history.');
									} ?>
								</span>
							</li>
						<?php } ?>
					</ul>
				<?php } ?>
			</div>
		</section>
	</div>
</div>

<?php echo p202_setup_script_tag($base); ?>
<?php template_bottom(); ?>
