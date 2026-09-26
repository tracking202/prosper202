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

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !$editing && !$copying && !empty($_GET['aff_campaign_id'])) {
	$requestedCampaignId = filter_input(INPUT_GET, 'aff_campaign_id', FILTER_VALIDATE_INT);
	if ($requestedCampaignId) {
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$mysql['aff_campaign_id'] = $db->real_escape_string((string)$requestedCampaignId);
		$campaignSql = "SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_id='" . $mysql['aff_campaign_id'] . "' AND user_id='" . $mysql['user_id'] . "' AND aff_campaign_deleted='0'";
		$campaignResult = $db->query($campaignSql) or record_mysql_error($campaignSql);
		if ($campaignResult->num_rows > 0) {
			$html['aff_campaign_id'] = htmlentities((string)$requestedCampaignId, ENT_QUOTES, 'UTF-8');
		} else {
			unset($mysql['aff_campaign_id']);
		}
	}
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	// Require a valid session token for this state-changing request.
	if (!hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_POST['token'] ?? ''))) {
		$error['token'] = '<div class="error">Invalid or expired form token. Please reload the page and try again.</div>';
	}

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

		// Landing Page Optimizer (segments-v2 G10): flag this user's dimension
		// snapshot dirty; the hourly cron pushes it. DB-only — no HTTP here.
		// (After the public-id block above: markDirty's queries reset insert_id.)
		\Prosper202\Lpo\DimensionSync::markDirty($db, (int) ($_SESSION['user_id'] ?? 0));
	}
}

if (isset($_GET['delete_landing_page_id'])) {

	// Require a valid session token for this state-changing request.
	if (!hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_GET['token'] ?? ''))) {
		header('location: ' . get_absolute_url() . 'tracking202/setup/landing_pages.php');
		die();
	}

	if ($userObj->hasPermission("remove_landing_page")) {
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$mysql['landing_page_id'] = $db->real_escape_string((string)$_GET['delete_landing_page_id']);
		$mysql['landing_page_time'] = time();
		$delete_sql = " UPDATE  `202_landing_pages`
						SET     `landing_page_deleted`='1',
								`landing_page_time`='" . $mysql['landing_page_time'] . "'
						WHERE   `user_id`='" . $mysql['user_id'] . "'
						AND     `landing_page_id`='" . $mysql['landing_page_id'] . "'";

		if ($delete_result = $db->query($delete_sql)) {
			$delete_success = true;

			// Landing Page Optimizer (segments-v2 G10): deletes change the
			// synced snapshot too (buildSnapshot omits deleted rows) — flag
			// dirty so the hourly cron re-pushes. DB-only — no HTTP here.
			\Prosper202\Lpo\DimensionSync::markDirty($db, (int) ($_SESSION['user_id'] ?? 0));

			if ($slack) {
				if (isset($_GET['delete_landing_page_type']) && $_GET['delete_landing_page_type'] == '0') {
					$slack->push('simple_landing_page_deleted', ['name' => $_GET['delete_landing_page_name'] ?? '', 'user' => $user_row['username'] ?? '']);
				} else if (isset($_GET['delete_landing_page_type']) && $_GET['delete_landing_page_type'] == '1') {
					$slack->push('advanced_landing_page_deleted', ['name' => $_GET['delete_landing_page_name'] ?? '', 'user' => $user_row['username'] ?? '']);
				}
			}
		} else {
			record_mysql_error($delete_sql);
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
	$landing_page_row = $landing_page_result->fetch_assoc() ?? [];

	$mysql['aff_campaign_id'] = $db->real_escape_string((string) ($landing_page_row['aff_campaign_id'] ?? ''));
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

// Post-redirect-get: a saved or removed landing page answers with a redirect,
// so a reload cannot submit the form (or the remove link) a second time.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $add_success == true) {
	header('location: ' . get_absolute_url() . 'tracking202/setup/landing_pages.php?' . ($editing ? 'saved=1' : 'added=1'));
	exit;
}
if ($delete_success == true) {
	header('location: ' . get_absolute_url() . 'tracking202/setup/landing_pages.php?deleted=1');
	exit;
}

require_once __DIR__ . '/_includes/setup_ui.php';

$base = get_absolute_url();
$self = $base . 'tracking202/setup/landing_pages.php';
$token = (string) ($_SESSION['token'] ?? '');
$uid = $db->real_escape_string((string) $_SESSION['user_id']);
$canRemove = $userObj->hasPermission("remove_landing_page");
$leaveBehind = isset($_SESSION['user_mods_lb']) && $_SESSION['user_mods_lb'] == '1';
$campaignOptions = p202_setup_campaign_options($db, (int) $_SESSION['user_id']);

// What the form shows: what was just refused, the page being edited or
// copied, or a new page (for a campaign named in the link, when it is one).
$posted = $_SERVER['REQUEST_METHOD'] == 'POST';
$row = !$posted && ($editing || $copying) && is_array($landing_page_row) ? $landing_page_row : [];
$source = $posted ? $_POST : $row;
$value = static fn (string $name): string => (string) ($source[$name] ?? '');
$form = [
	'landing_page_id' => $posted ? $value('landing_page_id') : ($editing ? (string) ($row['landing_page_id'] ?? '') : ''),
	'landing_page_type' => $value('landing_page_type') === '1' ? '1' : '0',
	'aff_campaign_id' => $source !== [] ? $value('aff_campaign_id') : html_entity_decode((string) ($html['aff_campaign_id'] ?? ''), ENT_QUOTES, 'UTF-8'),
	'landing_page_nickname' => $value('landing_page_nickname') . (!$posted && $copying && $row !== [] ? ' (Copy)' : ''),
	'landing_page_url' => $value('landing_page_url'),
	'leave_behind_page_url' => $value('leave_behind_page_url'),
];
$editId = $editing ? (int) ($_GET['edit_landing_page_id'] ?? 0) : 0;
$action = $self . ($editing ? '?edit_landing_page_id=' . $editId : ($copying ? '?copy_landing_page_id=' . (int) ($_GET['copy_landing_page_id'] ?? 0) : ''));

// Landing Page Optimizer deeplink: paired installs jump to the hosted create
// page with context params; unpaired ones land on the pairing panel. Degrades
// to unpaired before the upgrade adds the lpo_status pref column.
$lpo_paired = false;
$lpo_install_hash = '';
try {
	$lpo_result = $db->query("SELECT u.install_hash, up.lpo_status FROM 202_users AS u INNER JOIN 202_users_pref AS up ON (up.user_id = u.user_id) WHERE u.user_id = '" . $uid . "'");
	$lpo_row = ($lpo_result instanceof mysqli_result) ? $lpo_result->fetch_assoc() : null;
	$lpo_install_hash = trim((string) ($lpo_row['install_hash'] ?? ''));
	// a pairing is only deeplinkable with a real install hash — a blank one
	// would emit install= and break the hosted page, so treat it as unpaired
	$lpo_paired = (string) ($lpo_row['lpo_status'] ?? '') === 'active' && $lpo_install_hash !== '';
} catch (Throwable $lpo_lookup_error) {
	// pre-upgrade schema; keep the panel link
}
$lpo_create_base = \Prosper202\Lpo\PairingClient::saasBaseUrl() . '/api/customers/experiments/create';
$optimizeLink = static function (array $page) use ($lpo_paired, $lpo_create_base, $lpo_install_hash, $base): string {
	if ($lpo_paired) {
		return '<a class="p202-list__action" href="' . p202_setup_e($lpo_create_base . '?lp=' . urlencode((string) ($page['landing_page_url'] ?? '')) . '&install=' . urlencode($lpo_install_hash)) . '" target="_blank" rel="noopener">optimize</a>';
	}
	return '<a class="p202-list__action" href="' . p202_setup_e($base . '202-account/api-integrations.php#lpo') . '">optimize</a>';
};

$advancedPages = p202_setup_rows($db, "SELECT * FROM `202_landing_pages` WHERE `user_id`='" . $uid . "' AND landing_page_type='1' AND landing_page_deleted='0' ORDER BY landing_page_nickname ASC");
$simplePagesByCampaign = [];
foreach (p202_setup_rows($db, "SELECT landing_page_id, landing_page_nickname, landing_page_url, aff_campaign_id FROM `202_landing_pages` WHERE `user_id`='" . $uid . "' AND `landing_page_deleted`='0' AND landing_page_type='0' ORDER BY landing_page_nickname ASC") as $page) {
	$simplePagesByCampaign[(int) $page['aff_campaign_id']][] = $page;
}

$pageItem = static function (array $page, bool $advanced) use ($self, $token, $canRemove, $editId, $optimizeLink): string {
	$id = (int) $page['landing_page_id'];
	$name = (string) $page['landing_page_nickname'];
	$out = '<li class="p202-list__item' . ($editId === $id ? ' is-active' : '') . '" data-p202-filter-text="' . p202_setup_e($name) . '">'
		. '<span class="p202-list__name">' . p202_setup_e($name) . '</span>'
		. '<span class="p202-list__actions">'
		. '<a class="p202-list__action" href="' . p202_setup_e($self . '?edit_landing_page_id=' . $id) . '">edit</a>'
		. '<a class="p202-list__action" href="' . p202_setup_e($self . '?copy_landing_page_id=' . $id) . '">copy</a>'
		. ($advanced ? $optimizeLink($page) : '');
	if ($canRemove) {
		$out .= p202_setup_remove_form($self, [
			'delete_landing_page_id' => $id,
			'delete_landing_page_name' => $name,
			'delete_landing_page_type' => $advanced ? '1' : '0',
			'token' => $token,
		], 'Remove the landing page "' . $name . '"? Clicks already tracked keep their history.');
	}
	return $out . '</span><span class="p202-list__meta">' . p202_setup_e($page['landing_page_url'] ?? '') . '</span></li>';
};

template_top('Landing Page Setup'); ?>

<div class="p202-page-header p202-page-header--accent">
	<div class="p202-page-header__icon"><i class="bi bi-file-earmark"></i></div>
	<div class="p202-page-header__text">
		<h1 class="p202-page-header__title">Landing Pages</h1>
		<p class="p202-page-header__desc">The pages you send clicks to before an offer: a simple page promotes one campaign, an advanced page several.</p>
	</div>
</div>

<?php
echo p202_setup_query_flashes([
	'added' => 'Landing page added. Install its code from Get LP Code.',
	'saved' => 'Landing page saved.',
	'deleted' => 'Landing page removed. Clicks already tracked keep their history.',
], $_GET);
if ($error) {
	echo p202_setup_error_flashes($error, ['landing_page_type', 'aff_campaign_id', 'landing_page_nickname', 'landing_page_url']);
}
?>

<div class="row g-4">
	<div class="col-12 col-lg-6">
		<section class="p202-panel" id="landing-page-form">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title"><?php echo $editing ? 'Edit landing page' : ($copying ? 'Copy landing page' : 'Add a landing page'); ?></h2>
				<p class="p202-panel__sub">Optional: direct links need no landing page.</p>
			</div>
			<div class="p202-panel__body">
				<form method="post" action="<?php echo p202_setup_e($action); ?>">
					<?php echo p202_setup_token_field($token); ?>
					<input type="hidden" name="landing_page_id" value="<?php echo p202_setup_e($form['landing_page_id']); ?>">
					<fieldset class="mb-3">
						<legend class="form-label">Type</legend>
						<div class="form-check">
							<input class="form-check-input" type="radio" name="landing_page_type" id="landing_page_type1" value="0"<?php echo $form['landing_page_type'] === '0' ? ' checked' : ''; ?>>
							<label class="form-check-label" for="landing_page_type1">Simple: one offer on the page</label>
						</div>
						<div class="form-check">
							<input class="form-check-input" type="radio" name="landing_page_type" id="landing_page_type2" value="1"<?php echo $form['landing_page_type'] === '1' ? ' checked' : ''; ?>>
							<label class="form-check-label" for="landing_page_type2">Advanced: several offers on the page</label>
						</div>
						<?php echo p202_setup_feedback($error, 'landing_page_type'); ?>
					</fieldset>

					<div class="mb-3" data-p202-show-when="landing_page_type=0" data-p202-disable-hidden<?php echo $form['landing_page_type'] === '1' ? ' hidden' : ''; ?>>
						<label class="form-label" for="aff_campaign_id">Campaign</label>
						<select class="form-select<?php echo p202_setup_invalid($error, 'aff_campaign_id'); ?>" id="aff_campaign_id" name="aff_campaign_id" required<?php echo $form['landing_page_type'] === '1' ? ' disabled' : ''; ?>>
							<?php echo p202_setup_options($campaignOptions, $form['aff_campaign_id'] !== '' ? $form['aff_campaign_id'] : p202_setup_only_option($campaignOptions), $campaignOptions === [] ? 'No campaigns yet' : 'Choose the campaign this page promotes'); ?>
						</select>
						<?php if ($campaignOptions === []) { ?>
							<div class="form-text">A simple landing page promotes one campaign. <a href="<?php echo p202_setup_e($base . 'tracking202/setup/aff_campaigns.php'); ?>">Add a campaign</a> first.</div>
						<?php } ?>
						<?php echo p202_setup_feedback($error, 'aff_campaign_id'); ?>
					</div>
					<div data-p202-show-when="landing_page_type=1" data-p202-disable-hidden<?php echo $form['landing_page_type'] === '1' ? '' : ' hidden'; ?>>
						<?php // An advanced page belongs to no one campaign. ?>
						<input type="hidden" name="aff_campaign_id" value="0"<?php echo $form['landing_page_type'] === '1' ? '' : ' disabled'; ?>>
					</div>

					<div class="mb-3">
						<label class="form-label" for="landing_page_nickname">Nickname</label>
						<input type="text" class="form-control<?php echo p202_setup_invalid($error, 'landing_page_nickname'); ?>" id="landing_page_nickname" name="landing_page_nickname" value="<?php echo p202_setup_e($form['landing_page_nickname']); ?>" maxlength="255" required>
						<?php echo p202_setup_feedback($error, 'landing_page_nickname'); ?>
					</div>

					<div class="mb-3">
						<label class="form-label" for="landing_page_url">Landing page URL</label>
						<textarea class="form-control font-monospace<?php echo p202_setup_invalid($error, 'landing_page_url'); ?>" id="landing_page_url" name="landing_page_url" rows="3" placeholder="https://" required><?php echo p202_setup_e($form['landing_page_url']); ?></textarea>
						<?php echo p202_setup_feedback($error, 'landing_page_url'); ?>
						<?php echo p202_setup_placeholders('landing_page_url', 'setup-landing-pages-placeholders'); ?>
					</div>

					<?php if ($leaveBehind) { ?>
						<details class="p202-disclosure mb-3" data-p202-remember="setup-landing-pages-advanced"<?php echo $form['leave_behind_page_url'] !== '' ? ' open' : ''; ?>>
							<summary>Advanced <span class="p202-disclosure__hint">leave-behind page</span></summary>
							<div class="p202-disclosure__body">
								<label class="form-label" for="leave_behind_page_url">Leave-behind URL</label>
								<textarea class="form-control font-monospace" id="leave_behind_page_url" name="leave_behind_page_url" rows="2" placeholder="https://"><?php echo p202_setup_e($form['leave_behind_page_url']); ?></textarea>
								<div class="form-text">None by default. A page loaded behind the offer once a visitor clicks out of your landing page.</div>
							</div>
						</details>
					<?php } ?>

					<div class="p202-form-actions">
						<?php if ($editing || $copying) { ?>
							<a class="btn btn-link" href="<?php echo p202_setup_e($self); ?>">Cancel</a>
						<?php } ?>
						<button type="submit" class="btn btn-primary" id="addedLp"><?php echo $editing ? 'Save changes' : 'Add landing page'; ?></button>
					</div>
				</form>
			</div>
		</section>
	</div>

	<div class="col-12 col-lg-6">
		<section class="p202-panel mb-4">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title">Advanced landing pages</h2>
				<span class="p202-pill p202-pill--accent"><?php echo count($advancedPages); ?></span>
				<?php if (count($advancedPages) > 5) { ?>
					<div class="p202-panel__aside"><?php echo p202_setup_list_filter('advanced-page-list', 'Filter…'); ?></div>
				<?php } ?>
			</div>
			<div class="p202-panel__body">
				<?php if ($advancedPages === []) { ?>
					<p class="text-body-secondary mb-0">None yet. Choose Advanced when one page promotes several offers.</p>
				<?php } else { ?>
					<ul class="p202-list" id="advanced-page-list">
						<?php foreach ($advancedPages as $page) {
							echo $pageItem($page, true);
						} ?>
					</ul>
				<?php } ?>
			</div>
		</section>

		<section class="p202-panel">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title">Simple landing pages</h2>
				<span class="p202-pill p202-pill--accent"><?php echo array_sum(array_map('count', $simplePagesByCampaign)); ?></span>
			</div>
			<div class="p202-panel__body">
				<?php if ($simplePagesByCampaign === []) { ?>
					<div class="p202-empty">
						<i class="bi bi-file-earmark p202-empty__icon"></i>
						<strong class="p202-empty__title">No simple landing pages yet</strong>
						<div>Add the page a campaign's clicks land on, and it is listed here under its campaign.</div>
						<div class="p202-empty__action"><a class="btn btn-secondary btn-sm" href="#landing_page_nickname">Add a landing page</a></div>
					</div>
				<?php } else { ?>
					<ul class="p202-list">
						<?php foreach ($campaignOptions as $group) {
							$groupCampaigns = array_filter($group['options'], static fn ($campaignId): bool => isset($simplePagesByCampaign[(int) $campaignId]), ARRAY_FILTER_USE_KEY);
							if ($groupCampaigns === []) {
								continue;
							} ?>
							<li class="p202-list__item">
								<span class="p202-list__name"><?php echo p202_setup_e($group['label']); ?></span>
								<ul class="p202-list__children">
									<?php foreach ($groupCampaigns as $campaignId => $campaign) { ?>
										<li class="p202-list__item">
											<span class="p202-list__name"><?php echo p202_setup_e($campaign['label']); ?></span>
											<ul class="p202-list__children">
												<?php foreach ($simplePagesByCampaign[(int) $campaignId] as $page) {
													echo $pageItem($page, false);
												} ?>
											</ul>
										</li>
									<?php } ?>
								</ul>
							</li>
						<?php } ?>
					</ul>
				<?php } ?>
			</div>
		</section>
	</div>
</div>

<?php echo p202_setup_script_tag($base); ?>
<?php template_bottom();
