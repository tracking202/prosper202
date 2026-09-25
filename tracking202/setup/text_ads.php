<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-18) . '/202-config/connect.php');

AUTH::require_user();

if (!$userObj->hasPermission("access_to_setup_section")) {
	header('location: '.get_absolute_url().'tracking202/');
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
$user_sql = "SELECT 2u.user_name as username, 2up.user_slack_incoming_webhook AS url FROM 202_users AS 2u INNER JOIN 202_users_pref AS 2up ON (2up.user_id = 1) WHERE 2u.user_id = '".$mysql['user_own_id']."'";
$user_results = $db->query($user_sql);
$user_row = $user_results->fetch_assoc();

if (!empty($user_row['url'])) 
	$slack = new Slack($user_row['url']);

if (!empty($_GET['edit_text_ad_id'])) { 
	$editing = true; 
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	// Require a valid session token for this state-changing request.
	if (!hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_POST['token'] ?? ''))) {
		$error['token'] = '<div class="error">Invalid or expired form token. Please reload the page and try again.</div>';
	}

	if ($_POST['text_ad_type'] == 0) {
		
		//text ad type
		$aff_campaign_id = trim((string)($_POST['aff_campaign_id'] ?? ''));
		if (empty($aff_campaign_id)) { $error['aff_campaign_id'] = '<div class="error">What campaign is this advertisement for?</div>'; }
	
	
		//check to see if they are the owners of this affiliate network
		$mysql['aff_campaign_id'] = $db->real_escape_string((string)($_POST['aff_campaign_id'] ?? ''));
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$aff_campaign_sql = "SELECT * FROM `202_aff_campaigns` WHERE `user_id`='".$mysql['user_id']."' AND `aff_campaign_id`='".$mysql['aff_campaign_id']."'";
		$aff_campaign_result = $db->query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql);
		if ($aff_campaign_result->num_rows == 0 ) {
			$error['wrong_user'] = '<div class="error">You are not authorized to add an campaign to another users network</div>';    
		} else {
			$aff_campaign_row = $aff_campaign_result->fetch_assoc();
		}
	
	}
	
	if ($_POST['text_ad_type'] == 1) { 
		$landing_page_id = trim((string) $_POST['landing_page_id']);
		if (empty($landing_page_id)) { $error['landing_page_id'] = '<div class="error">Please select a landing page.</div>'; }

		$mysql['landing_page_id'] = $db->real_escape_string((string)$_POST['landing_page_id']);
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$landing_page_sql = "SELECT * FROM `202_landing_pages` WHERE `user_id`='".$mysql['user_id']."' AND `landing_page_id`='".$mysql['landing_page_id']."'";
		$landing_page_result = $db->query($landing_page_sql) or record_mysql_error($landing_page_sql);
		if ($landing_page_result->num_rows == 0 ) {
			$error['wrong_user'] = '<div class="error">You are not authorized to add an text add to another users landing page</div>';    
		} else {
			$landing_page_row = $landing_page_result->fetch_assoc();
		}
	}
		
		
	$text_ad_name = trim((string) $_POST['text_ad_name']);
	if (empty($text_ad_name)) { $error['text_ad_name'] = '<div class="error">Give this ad variation a nickname</div>'; }
	
	$text_ad_headline = trim((string) $_POST['text_ad_headline']);
	if (empty($text_ad_headline)) { $error['text_ad_headline'] = '<div class="error">What is your ad headline?</div>'; }
	
	$text_ad_description = trim((string) $_POST['text_ad_description']);
	if (empty($text_ad_description)) { $error['text_ad_description'] = '<div class="error">What is your ad description?</div>'; }
	
	$text_ad_display_url = trim((string) $_POST['text_ad_display_url']);
	if (empty($text_ad_display_url)) { $error['text_ad_display_url'] = '<div class="error">What is your ad display URL?</div>'; }
	

	
	//if editing, check to make sure the own the campaign they are editing
	if ($editing == true) {
		$mysql['text_ad_id'] = $db->real_escape_string((string)$_POST['text_ad_id']);
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$ad_varation_sql = "SELECT 
							202_text_ads.aff_campaign_id AS text_add_aff_campaign_id,
							202_text_ads.landing_page_id AS text_add_landing_page_id,
							202_text_ads.text_ad_name AS text_ad_name,
							202_text_ads.text_ad_headline AS text_ad_headline,
							202_text_ads.text_ad_description AS text_ad_description,
							202_text_ads.text_ad_display_url AS text_ad_display_url,
							202_aff_campaigns.aff_campaign_name AS text_add_aff_campaign_name,
							202_landing_pages.landing_page_nickname AS text_add_landing_page_nickname  
							FROM 202_text_ads LEFT JOIN 202_aff_campaigns USING (aff_campaign_id) LEFT JOIN 202_landing_pages USING (landing_page_id) WHERE 202_text_ads.user_id='".$mysql['user_id']."' AND text_ad_id='".$mysql['text_ad_id']."'";
		$text_ad_result = $db->query($ad_varation_sql) or record_mysql_error($ad_varation_sql);
		if ($text_ad_result->num_rows == 0 ) {
			$error['wrong_user'] = ($error['wrong_user'] ?? '') . '<div class="error">You are not authorized to modify another users campaign</div>';    
		} else {
			$text_ad_row = $text_ad_result->fetch_assoc();
		}
	}

	if (!$error) { 
		$mysql['text_ad_id'] = $db->real_escape_string((string)$_POST['text_ad_id']);
		$mysql['text_ad_type'] = $db->real_escape_string((string)$_POST['text_ad_type']);
		$mysql['landing_page_id'] = $db->real_escape_string((string)$_POST['landing_page_id']);
		$mysql['aff_campaign_id'] = $db->real_escape_string((string)$_POST['aff_campaign_id']);
		$mysql['text_ad_name'] = $db->real_escape_string((string)$_POST['text_ad_name']);
		$mysql['text_ad_headline'] = $db->real_escape_string((string)$_POST['text_ad_headline']);
		$mysql['text_ad_description'] = $db->real_escape_string((string)$_POST['text_ad_description']);
		$mysql['text_ad_display_url'] = $db->real_escape_string((string)$_POST['text_ad_display_url']);
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$mysql['text_ad_time'] = $db->real_escape_string((string)time());
		
		if ($editing == true) { $text_ad_sql  = "UPDATE `202_text_ads` SET"; } 
		else {                  $text_ad_sql  = "INSERT INTO `202_text_ads` SET"; }
		
								$text_ad_sql .= "     `aff_campaign_id`='".$mysql['aff_campaign_id']."',
													  `text_ad_type`='".$mysql['text_ad_type']."',
													  `landing_page_id`='".$mysql['landing_page_id']."',
													  `text_ad_name`='".$mysql['text_ad_name']."',
													  `text_ad_headline`='".$mysql['text_ad_headline']."',
													  `text_ad_description`='".$mysql['text_ad_description']."',
													  `text_ad_display_url`='".$mysql['text_ad_display_url']."',
													  `user_id`='".$mysql['user_id']."',
													  `text_ad_time`='".$mysql['text_ad_time']."'";
													  
		if ($editing == true) { $text_ad_sql  .= "WHERE `text_ad_id`='".$mysql['text_ad_id']."'"; } 
		$text_ad_result = $db->query($text_ad_sql) or record_mysql_error($text_ad_sql);
		$add_success = true;

		//if the edit worked ok redirec them
		if ($editing == true) {
			if ($slack) {
				if ($_POST['text_ad_type'] == 0) {
					if ($text_ad_row['text_add_aff_campaign_id'] != $_POST['aff_campaign_id']) {
						$slack->push('ad_copy_campaign_changed', ['name' => $text_ad_row['text_ad_name'], 'old_campaign' => $text_ad_row['text_add_aff_campaign_name'], 'new_campaign' => $aff_campaign_row['aff_campaign_name'], 'user' => $user_row['username']]);
					}
				}

				if ($_POST['text_ad_type'] == 1) {
					if ($text_ad_row['text_add_landing_page_id'] != $_POST['landing_page_id']) {
						$slack->push('ad_copy_landing_page_changed', ['name' => $text_ad_row['text_ad_name'], 'old_lp' => $text_ad_row['text_add_landing_page_nickname'], 'new_lp' => $landing_page_row['landing_page_nickname'], 'user' => $user_row['username']]);
					}
				}

				if ($text_ad_row['text_ad_name'] != $_POST['text_ad_name']) {
					$slack->push('ad_copy_name_changed', ['old_name' => $text_ad_row['text_ad_name'], 'new_name' => $_POST['text_ad_name'], 'user' => $user_row['username']]);
				}

				if ($text_ad_row['text_ad_headline'] != $_POST['text_ad_headline']) {
					$slack->push('ad_copy_headline_changed', ['name' => $_POST['text_ad_name'], 'old_headline' => $text_ad_row['text_ad_headline'], 'new_headline' => $_POST['text_ad_headline'], 'user' => $user_row['username']]);
				}

				if ($text_ad_row['text_ad_description'] != $_POST['text_ad_description']) {
					$slack->push('ad_copy_description_changed', ['name' => $_POST['text_ad_name'], 'old_description' => $text_ad_row['text_ad_description'], 'new_description' => $_POST['text_ad_description'], 'user' => $user_row['username']]);
				}

				if ($text_ad_row['text_ad_display_url'] != $_POST['text_ad_display_url']) {
					$slack->push('ad_copy_display_url_changed', ['name' => $_POST['text_ad_name'], 'old_url' => $text_ad_row['text_ad_display_url'], 'new_url' => $_POST['text_ad_display_url'], 'user' => $user_row['username']]);
				}
			}
			header('location: '.get_absolute_url().'tracking202/setup/text_ads.php');   
			
		} else {
			if($slack)
				$slack->push('ad_copy_created', ['name' => $_POST['text_ad_name'], 'user' => $user_row['username']]);
		}
		
		$editing = false;
		
		
	}
}

if (isset($_GET['delete_text_ad_id'])) {

	// Require a valid session token for this state-changing request.
	if (!hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_GET['token'] ?? ''))) {
		header('location: ' . get_absolute_url() . 'tracking202/setup/text_ads.php');
		die();
	}

	if ($userObj->hasPermission("remove_text_ad")) {
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$mysql['text_ad_id'] = $db->real_escape_string((string)$_GET['delete_text_ad_id']);
		$mysql['text_ad_time'] = time();
		
		$delete_sql = " UPDATE  `202_text_ads`
						SET     `text_ad_deleted`='1',
								`text_ad_time`='".$mysql['text_ad_time']."'
						WHERE   `user_id`='".$mysql['user_id']."'
						AND     `text_ad_id`='".$mysql['text_ad_id']."'";
		if ($delete_result = $db->query($delete_sql) or record_mysql_error($delete_sql)) {
			$delete_success = true;
			if($slack)
				$slack->push('ad_copy_deleted', ['name' => $_GET['delete_text_ad_name'], 'user' => $user_row['username']]);
		}
	} else {
		header('location: '.get_absolute_url().'tracking202/setup/text_ads.php');
	}
}

if (!empty($_GET['edit_text_ad_id'])) { 
	
	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
	$mysql['text_ad_id'] = $db->real_escape_string((string)$_GET['edit_text_ad_id']);
	
	$text_ad_sql = "SELECT * 
						 FROM   `202_text_ads`
						 WHERE  `text_ad_id`='".$mysql['text_ad_id']."'
						 AND    `user_id`='".$mysql['user_id']."'";
	$text_ad_result = $db->query($text_ad_sql) or record_mysql_error($text_ad_sql);
	$text_ad_row = $text_ad_result->fetch_assoc() ?? [];
	

	$mysql['aff_campaign_id'] = $db->real_escape_string((string) ($text_ad_row['aff_campaign_id'] ?? ''));
	$html['landing_page_id'] = htmlentities((string)($text_ad_row['landing_page_id'] ?? ''), ENT_QUOTES, 'UTF-8');    
	$html['text_ad_type'] = htmlentities((string)($text_ad_row['text_ad_type'] ?? ''), ENT_QUOTES, 'UTF-8');    
	$html['aff_campaign_id'] = htmlentities((string)($text_ad_row['aff_campaign_id'] ?? ''), ENT_QUOTES, 'UTF-8');    
	$html['text_ad_id'] = htmlentities((string)($_GET['edit_text_ad_id'] ?? ''), ENT_QUOTES, 'UTF-8');    
	$html['text_ad_name'] = htmlentities((string)($text_ad_row['text_ad_name'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['text_ad_headline'] = htmlentities((string)($text_ad_row['text_ad_headline'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['text_ad_description'] = htmlentities((string)($text_ad_row['text_ad_description'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['text_ad_display_url'] = htmlentities((string)($text_ad_row['text_ad_display_url'] ?? ''), ENT_QUOTES, 'UTF-8');
	 

} elseif (!empty($_GET['copy_text_ad_id'])) { 
	
	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
	$mysql['text_ad_id'] = $db->real_escape_string((string)$_GET['copy_text_ad_id']);
	
	$text_ad_sql = "SELECT * 
						 FROM   `202_text_ads`
						 WHERE  `text_ad_id`='".$mysql['text_ad_id']."'
						 AND    `user_id`='".$mysql['user_id']."'";
	$text_ad_result = $db->query($text_ad_sql) or record_mysql_error($text_ad_sql);
	$text_ad_row = $text_ad_result->fetch_assoc() ?? [];
	
	$html['text_ad_type'] = htmlentities((string)($text_ad_row['text_ad_type'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['landing_page_id'] = htmlentities((string)($text_ad_row['landing_page_id'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['text_ad_name'] = htmlentities((string)($text_ad_row['text_ad_name'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['text_ad_headline'] = htmlentities((string)($text_ad_row['text_ad_headline'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['text_ad_description'] = htmlentities((string)($text_ad_row['text_ad_description'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['text_ad_display_url'] = htmlentities((string)($text_ad_row['text_ad_display_url'] ?? ''), ENT_QUOTES, 'UTF-8');
	 

} elseif (($_SERVER['REQUEST_METHOD'] == 'POST') and ($add_success != true)) {
	
	$mysql['aff_campaign_id'] = $db->real_escape_string((string)$_POST['aff_campaign_id']);
   	$html['aff_campaign_id'] = htmlentities((string)($_POST['aff_campaign_id'] ?? ''), ENT_QUOTES, 'UTF-8');
    
    	$html['text_ad_type'] = htmlentities((string)($_POST['text_ad_type'] ?? ''), ENT_QUOTES, 'UTF-8');   
	$html['landing_page_id'] = htmlentities((string)($_POST['landing_page_id'] ?? ''), ENT_QUOTES, 'UTF-8');   
	$html['aff_network_id'] = htmlentities((string)($_POST['aff_network_id'] ?? ''), ENT_QUOTES, 'UTF-8');   
	$html['text_ad_id'] = htmlentities((string)($_POST['text_ad_id'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['text_ad_name'] = htmlentities((string)($_POST['text_ad_name'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['text_ad_headline'] = htmlentities((string)($_POST['text_ad_headline'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['text_ad_description'] = htmlentities((string)($_POST['text_ad_description'] ?? ''), ENT_QUOTES, 'UTF-8');
	$html['text_ad_display_url'] = htmlentities((string)($_POST['text_ad_display_url'] ?? ''), ENT_QUOTES, 'UTF-8');
	
}

if ((($editing === true) || ($add_success !== true)) && !empty($mysql['aff_campaign_id'])) {
    //now grab the affiliate network id, per that aff campaign id
    $aff_campaign_sql = "SELECT * FROM `202_aff_campaigns` WHERE `aff_campaign_id`='".$mysql['aff_campaign_id']."'";
    $aff_campaign_result = $db->query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql);
    $aff_campaign_row = $aff_campaign_result->fetch_assoc();

    $mysql['aff_network_id'] = $db->real_escape_string((string) ($aff_campaign_row['aff_network_id'] ?? ''));
    $aff_network_sql = "SELECT * FROM `202_aff_networks` WHERE `aff_network_id`='".$mysql['aff_network_id']."'";
    $aff_network_result = $db->query($aff_network_sql) or record_mysql_error($aff_network_sql);
    $aff_network_row = $aff_network_result->fetch_assoc();

    $html['aff_network_id'] = htmlentities((string)($aff_network_row['aff_network_id'] ?? ''), ENT_QUOTES, 'UTF-8');
}

// Post-redirect-get: a saved or removed ad answers with a redirect, so a
// reload cannot submit the form (or the remove link) a second time.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $add_success == true) {
	header('location: ' . get_absolute_url() . 'tracking202/setup/text_ads.php?' . (!empty($_GET['edit_text_ad_id']) ? 'saved=1' : 'added=1'));
	exit;
}
if ($delete_success == true) {
	header('location: ' . get_absolute_url() . 'tracking202/setup/text_ads.php?deleted=1');
	exit;
}

require_once __DIR__ . '/_includes/setup_ui.php';

$base = get_absolute_url();
$self = $base . 'tracking202/setup/text_ads.php';
$token = (string) ($_SESSION['token'] ?? '');
$uid = $db->real_escape_string((string) $_SESSION['user_id']);
$canRemove = $userObj->hasPermission("remove_text_ad");
$campaignOptions = p202_setup_campaign_options($db, (int) $_SESSION['user_id']);
$advancedPages = p202_setup_rows($db, "SELECT landing_page_id, landing_page_nickname FROM `202_landing_pages` WHERE `user_id`='" . $uid . "' AND landing_page_type='1' AND landing_page_deleted='0' ORDER BY landing_page_nickname ASC");
$advancedOptions = [];
foreach ($advancedPages as $page) {
	$advancedOptions[(string) $page['landing_page_id']] = (string) $page['landing_page_nickname'];
}
$adsByCampaign = [];
$adsByPage = [];
foreach (p202_setup_rows($db, "SELECT text_ad_id, text_ad_name, text_ad_headline, aff_campaign_id, landing_page_id, text_ad_type FROM `202_text_ads` WHERE `user_id`='" . $uid . "' AND `text_ad_deleted`='0' ORDER BY `text_ad_name` ASC") as $ad) {
	if ((string) $ad['text_ad_type'] === '1') {
		$adsByPage[(int) $ad['landing_page_id']][] = $ad;
	} else {
		$adsByCampaign[(int) $ad['aff_campaign_id']][] = $ad;
	}
}

// What the form shows: what was just refused, the ad being edited or copied,
// or a new ad. Editing is read from the URL: the handler resets $editing
// after a save, and a refused edit must still post back to the edit URL.
$posted = $_SERVER['REQUEST_METHOD'] == 'POST';
$isEdit = !empty($_GET['edit_text_ad_id']);
$isCopy = !$isEdit && !empty($_GET['copy_text_ad_id']);
$row = !$posted && ($isEdit || $isCopy) && is_array($text_ad_row ?? null) ? $text_ad_row : [];
$source = $posted ? $_POST : $row;
$value = static fn (string $name): string => (string) ($source[$name] ?? '');
$form = [
	'text_ad_id' => $posted ? $value('text_ad_id') : ($isEdit ? (string) ($row['text_ad_id'] ?? '') : ''),
	'text_ad_type' => $value('text_ad_type') === '1' ? '1' : '0',
	'aff_campaign_id' => $value('aff_campaign_id'),
	'landing_page_id' => $value('landing_page_id'),
	'text_ad_name' => $value('text_ad_name'),
	'text_ad_headline' => $value('text_ad_headline'),
	'text_ad_description' => $value('text_ad_description'),
	'text_ad_display_url' => $value('text_ad_display_url'),
];
$editId = $isEdit ? (int) $_GET['edit_text_ad_id'] : 0;
$action = $self . ($isEdit ? '?edit_text_ad_id=' . $editId : ($isCopy ? '?copy_text_ad_id=' . (int) $_GET['copy_text_ad_id'] : ''));
$isAdvanced = $form['text_ad_type'] === '1';

$adItem = static function (array $ad) use ($self, $token, $canRemove, $editId): string {
	$id = (int) $ad['text_ad_id'];
	$name = (string) $ad['text_ad_name'];
	$out = '<li class="p202-list__item' . ($editId === $id ? ' is-active' : '') . '" data-p202-filter-text="' . p202_setup_e($name) . '">'
		. '<span class="p202-list__name">' . p202_setup_e($name) . '</span>'
		. '<span class="p202-list__actions">'
		. '<a class="p202-list__action" href="' . p202_setup_e($self . '?edit_text_ad_id=' . $id) . '">edit</a>'
		. '<a class="p202-list__action" href="' . p202_setup_e($self . '?copy_text_ad_id=' . $id) . '">copy</a>';
	if ($canRemove) {
		$out .= p202_setup_remove_form($self, ['delete_text_ad_id' => $id, 'delete_text_ad_name' => $name, 'token' => $token],
			'Remove the ad "' . $name . '"? Clicks already tracked keep their history.');
	}
	return $out . '</span><span class="p202-list__meta">' . p202_setup_e($ad['text_ad_headline'] ?? '') . '</span></li>';
};

template_top('Text Ads Setup'); ?>

<div class="p202-page-header p202-page-header--accent">
	<div class="p202-page-header__icon"><i class="bi bi-fonts"></i></div>
	<div class="p202-page-header__text">
		<h1 class="p202-page-header__title">Text Ads</h1>
		<p class="p202-page-header__desc">Your ad copy variations, so reports can tell which headline, description and display URL performed.</p>
	</div>
</div>

<?php
echo p202_setup_query_flashes([
	'added' => 'Ad added. Choose it when you get a tracking link.',
	'saved' => 'Ad saved.',
	'deleted' => 'Ad removed. Clicks already tracked keep their history.',
], $_GET);
if ($error) {
	echo p202_setup_error_flashes($error, ['aff_campaign_id', 'landing_page_id', 'text_ad_name', 'text_ad_headline', 'text_ad_description', 'text_ad_display_url']);
}
?>

<div class="row g-4">
	<div class="col-12 col-lg-6">
		<?php if ($campaignOptions === [] && $advancedOptions === []) { ?>
			<div class="p202-empty">
				<i class="bi bi-fonts p202-empty__icon"></i>
				<strong class="p202-empty__title">Add a campaign first</strong>
				<div>A text ad sends clicks to a campaign or an advanced landing page, and you have neither yet.</div>
				<div class="p202-empty__action"><a class="btn btn-primary btn-sm" href="<?php echo p202_setup_e($base . 'tracking202/setup/aff_campaigns.php'); ?>">Add a campaign</a></div>
			</div>
		<?php } else { ?>
		<section class="p202-panel" id="text-ad-form">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title"><?php echo $isEdit ? 'Edit ad' : ($isCopy ? 'Copy ad' : 'Add an ad'); ?></h2>
				<p class="p202-panel__sub">Optional: only for traffic you buy with text ads.</p>
			</div>
			<div class="p202-panel__body">
				<form method="post" action="<?php echo p202_setup_e($action); ?>">
					<?php echo p202_setup_token_field($token); ?>
					<input type="hidden" name="text_ad_id" value="<?php echo p202_setup_e($form['text_ad_id']); ?>">
					<fieldset class="mb-3">
						<legend class="form-label">The ad sends clicks to</legend>
						<div class="form-check">
							<input class="form-check-input" type="radio" name="text_ad_type" id="text_ad_type1" value="0"<?php echo $isAdvanced ? '' : ' checked'; ?>>
							<label class="form-check-label" for="text_ad_type1">A campaign, directly or through a simple landing page</label>
						</div>
						<div class="form-check">
							<input class="form-check-input" type="radio" name="text_ad_type" id="text_ad_type2" value="1"<?php echo $isAdvanced ? ' checked' : ''; ?>>
							<label class="form-check-label" for="text_ad_type2">An advanced landing page</label>
						</div>
					</fieldset>

					<div class="mb-3" data-p202-show-when="text_ad_type=0" data-p202-disable-hidden<?php echo $isAdvanced ? ' hidden' : ''; ?>>
						<label class="form-label" for="aff_campaign_id">Campaign</label>
						<select class="form-select<?php echo p202_setup_invalid($error, 'aff_campaign_id'); ?>" id="aff_campaign_id" name="aff_campaign_id" required<?php echo $isAdvanced ? ' disabled' : ''; ?>>
							<?php echo p202_setup_options($campaignOptions, $form['aff_campaign_id'] !== '' ? $form['aff_campaign_id'] : p202_setup_only_option($campaignOptions), $campaignOptions === [] ? 'No campaigns yet' : 'Choose a campaign'); ?>
						</select>
						<input type="hidden" name="landing_page_id" value="0"<?php echo $isAdvanced ? ' disabled' : ''; ?>>
						<?php echo p202_setup_feedback($error, 'aff_campaign_id'); ?>
					</div>
					<div class="mb-3" data-p202-show-when="text_ad_type=1" data-p202-disable-hidden<?php echo $isAdvanced ? '' : ' hidden'; ?>>
						<label class="form-label" for="landing_page_id">Advanced landing page</label>
						<select class="form-select<?php echo p202_setup_invalid($error, 'landing_page_id'); ?>" id="landing_page_id" name="landing_page_id" required<?php echo $isAdvanced ? '' : ' disabled'; ?>>
							<?php echo p202_setup_options($advancedOptions, $form['landing_page_id'] !== '' && $form['landing_page_id'] !== '0' ? $form['landing_page_id'] : p202_setup_only_option($advancedOptions), $advancedOptions === [] ? 'No advanced landing pages yet' : 'Choose a landing page'); ?>
						</select>
						<input type="hidden" name="aff_campaign_id" value="0"<?php echo $isAdvanced ? '' : ' disabled'; ?>>
						<?php echo p202_setup_feedback($error, 'landing_page_id'); ?>
					</div>

					<div class="mb-3">
						<label class="form-label" for="text_ad_name">Ad nickname</label>
						<input type="text" class="form-control<?php echo p202_setup_invalid($error, 'text_ad_name'); ?>" id="text_ad_name" name="text_ad_name" value="<?php echo p202_setup_e($form['text_ad_name']); ?>" maxlength="255" required>
						<div class="form-text">How you find this ad among the others in reports.</div>
						<?php echo p202_setup_feedback($error, 'text_ad_name'); ?>
					</div>
					<div class="mb-3">
						<label class="form-label" for="text_ad_headline">Headline</label>
						<input type="text" class="form-control<?php echo p202_setup_invalid($error, 'text_ad_headline'); ?>" id="text_ad_headline" name="text_ad_headline" value="<?php echo p202_setup_e($form['text_ad_headline']); ?>" required>
						<?php echo p202_setup_feedback($error, 'text_ad_headline'); ?>
					</div>
					<div class="mb-3">
						<label class="form-label" for="text_ad_description">Description</label>
						<textarea class="form-control<?php echo p202_setup_invalid($error, 'text_ad_description'); ?>" id="text_ad_description" name="text_ad_description" rows="2" required><?php echo p202_setup_e($form['text_ad_description']); ?></textarea>
						<?php echo p202_setup_feedback($error, 'text_ad_description'); ?>
					</div>
					<div class="mb-3">
						<label class="form-label" for="text_ad_display_url">Display URL</label>
						<input type="text" class="form-control<?php echo p202_setup_invalid($error, 'text_ad_display_url'); ?>" id="text_ad_display_url" name="text_ad_display_url" value="<?php echo p202_setup_e($form['text_ad_display_url']); ?>" required>
						<?php echo p202_setup_feedback($error, 'text_ad_display_url'); ?>
					</div>

					<div class="mb-3">
						<span class="form-label d-block">Preview</span>
						<div class="card">
							<div class="card-body py-2">
								<div class="fw-bold text-primary" data-p202-mirror="#text_ad_headline" data-p202-mirror-empty="Luxury Cruise to Mars"><?php echo p202_setup_e($form['text_ad_headline'] !== '' ? $form['text_ad_headline'] : 'Luxury Cruise to Mars'); ?></div>
								<div class="small" data-p202-mirror="#text_ad_description" data-p202-mirror-empty="Visit the Red Planet in style. Low-gravity fun for everyone!"><?php echo p202_setup_e($form['text_ad_description'] !== '' ? $form['text_ad_description'] : 'Visit the Red Planet in style. Low-gravity fun for everyone!'); ?></div>
								<div class="small text-success" data-p202-mirror="#text_ad_display_url" data-p202-mirror-empty="www.example.com"><?php echo p202_setup_e($form['text_ad_display_url'] !== '' ? $form['text_ad_display_url'] : 'www.example.com'); ?></div>
							</div>
						</div>
					</div>

					<div class="p202-form-actions">
						<?php if ($isEdit || $isCopy) { ?>
							<a class="btn btn-link" href="<?php echo p202_setup_e($self); ?>">Cancel</a>
						<?php } ?>
						<button type="submit" class="btn btn-primary" id="addedTextAd"><?php echo $isEdit ? 'Save changes' : 'Add ad'; ?></button>
					</div>
				</form>
			</div>
		</section>
		<?php } ?>
	</div>

	<div class="col-12 col-lg-6">
		<section class="p202-panel mb-4">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title">Ads for campaigns</h2>
				<span class="p202-pill p202-pill--accent"><?php echo array_sum(array_map('count', $adsByCampaign)); ?></span>
			</div>
			<div class="p202-panel__body">
				<?php if ($adsByCampaign === []) { ?>
					<div class="p202-empty">
						<i class="bi bi-fonts p202-empty__icon"></i>
						<strong class="p202-empty__title">No text ads yet</strong>
						<div>Store each variation of your ad copy here, then pick it when you get a tracking link.</div>
						<?php if ($campaignOptions !== [] || $advancedOptions !== []) { ?><div class="p202-empty__action"><a class="btn btn-secondary btn-sm" href="#text_ad_name">Add your first ad</a></div><?php } ?>
					</div>
				<?php } else { ?>
					<ul class="p202-list">
						<?php foreach ($campaignOptions as $group) {
							$groupCampaigns = array_filter($group['options'], static fn ($campaignId): bool => isset($adsByCampaign[(int) $campaignId]), ARRAY_FILTER_USE_KEY);
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
												<?php foreach ($adsByCampaign[(int) $campaignId] as $ad) {
													echo $adItem($ad);
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

		<section class="p202-panel">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title">Ads for advanced landing pages</h2>
				<span class="p202-pill p202-pill--accent"><?php echo array_sum(array_map('count', $adsByPage)); ?></span>
			</div>
			<div class="p202-panel__body">
				<?php if ($adsByPage === []) { ?>
					<p class="text-body-secondary mb-0">None yet.</p>
				<?php } else { ?>
					<ul class="p202-list">
						<?php foreach ($advancedPages as $page) {
							$pageAds = $adsByPage[(int) $page['landing_page_id']] ?? [];
							if ($pageAds === []) {
								continue;
							} ?>
							<li class="p202-list__item">
								<span class="p202-list__name"><?php echo p202_setup_e($page['landing_page_nickname']); ?></span>
								<ul class="p202-list__children">
									<?php foreach ($pageAds as $ad) {
										echo $adItem($ad);
									} ?>
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
