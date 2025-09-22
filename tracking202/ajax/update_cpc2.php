<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -17) . '/202-config/connect.php');

AUTH::require_user();

AUTH::set_timezone($_SESSION['user_timezone']);

//check variables

// Initialize error array
$error = [];
// Initialize html array
$html = [];

$from = explode('/', $_POST['from'] ?? '');
$from_month = $from[0] ?? '';
$from_day = $from[1] ?? '';
$from_year = $from[2] ?? '';

$to = explode('/', $_POST['to'] ?? '');
$to_month = $to[0] ?? '';
$to_day = $to[1] ?? '';
$to_year = $to[2] ?? '';

//if from or to, validate, and if validated, set it accordingly

if ((!isset($_POST['from']) || !$_POST['from']) and (!isset($_POST['to']) || !$_POST['to'])) {
	$error['time'] = '<div class="error">Please enter in the dates from and to like this <strong>mm/dd/yyyy</strong></div>';
}
$clean['from'] = mktime(0, 0, 0, (int)$from_month, (int)$from_day, (int)$from_year);
$html['from'] = date('m/d/y g:ia', $clean['from']);

$clean['to'] = mktime(23, 59, 59, (int)$to_month, (int)$to_day, (int)$to_year);
$html['to'] = date('m/d/y g:ia', $clean['to']);

//set mysql variables
// Initialize mysql array
$mysql = [];
$mysql['from'] = $db->real_escape_string((string)$clean['from']);
$mysql['to'] = $db->real_escape_string((string)$clean['to']);
$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);

//check affiliate network id, that you own
if (isset($_POST['aff_network_id']) && $_POST['aff_network_id']) {
	$mysql['aff_network_id'] = $db->real_escape_string((string)$_POST['aff_network_id']);
	$aff_network_sql = "SELECT * FROM 202_aff_networks WHERE aff_network_id='" . $mysql['aff_network_id'] . "' AND user_id='" . $mysql['user_id'] . "'";
	$aff_network_result = $db->query($aff_network_sql) or record_mysql_error($aff_network_sql);
	$aff_network_row = $aff_network_result->fetch_assoc();
	if (!$aff_network_row) {
		$error['user'] = '<div class="error">You can not modify other peoples cpc history.</div>';
	} else {
		$html['aff_network_name'] = htmlentities((string)($aff_network_row['aff_network_name'] ?? ''), ENT_QUOTES, 'UTF-8');
	}
} else {
	$html['aff_network_name'] = 'ALL your affiliate networks';
}

//check aff_campaign id, that you own
if (isset($_POST['aff_campaign_id']) && $_POST['aff_campaign_id']) {
	$mysql['aff_campaign_id'] = $db->real_escape_string((string)$_POST['aff_campaign_id']);
	$aff_campaign_sql = "SELECT * FROM 202_aff_campaigns WHERE aff_campaign_id='" . $mysql['aff_campaign_id'] . "' AND user_id='" . $mysql['user_id'] . "'";
	$aff_campaign_result = $db->query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql);
	$aff_campaign_row = $aff_campaign_result->fetch_assoc();
	if (!$aff_campaign_row) {
		$error['user'] = '<div class="error">You can not modify other peoples cpc history.</div>';
	} else {
		$html['aff_campaign_name'] = htmlentities((string)($aff_campaign_row['aff_campaign_name'] ?? ''), ENT_QUOTES, 'UTF-8');
	}
} else {
	$html['aff_campaign_name'] = 'ALL your affiliate campaigns in these affiliate networks';
}

//check text_ad id, that you own
if (isset($_POST['text_ad_id']) && $_POST['text_ad_id']) {
	$mysql['text_ad_id'] = $db->real_escape_string((string)$_POST['text_ad_id']);
	$text_ad_sql = "SELECT * FROM 202_text_ads WHERE text_ad_id='" . $mysql['text_ad_id'] . "' AND user_id='" . $mysql['user_id'] . "'";
	$text_ad_result = $db->query($text_ad_sql) or record_mysql_error($text_ad_sql);
	$text_ad_row = $text_ad_result->fetch_assoc();
	if (!$text_ad_row) {
		$error['user'] = '<div class="error">You can not modify other peoples cpc history.</div>';
	} else {
		$html['text_ad_name'] = htmlentities((string)($text_ad_row['text_ad_name'] ?? ''), ENT_QUOTES, 'UTF-8');
	}
} else {
	$html['text_ad_name'] = 'ALL your text ads in these affiliate campaigns';
}

//check method of promotion, that you own
if (isset($_POST['method_of_promotion']) && $_POST['method_of_promotion']) {
	if ($_POST['method_of_promotion'] == 'landingpage') {
		$html['method_of_promotion'] = 'Landing pages';
		$mysql['method_of_promotion'] = ' AND click_landing_site_url_id!=\'0\' ';
	} else {
		$html['method_of_promotion'] = 'Direct links';
		$mysql['method_of_promotion'] = ' AND click_landing_site_url_id=\'0\' ';
	}
} else {
	$html['method_of_promotion'] = 'BOTH direct links and landing pages';
}

//check landing_page id, that you own
if ((isset($_POST['method_of_promotion']) && $_POST['method_of_promotion'] == 'landingpage') or (isset($_POST['tracker_type']) && $_POST['tracker_type'] == 1)) {
	if (isset($_POST['landing_page_id']) && $_POST['landing_page_id']) {
		$mysql['landing_page_id'] = $db->real_escape_string((string)$_POST['landing_page_id']);
		$landing_page_sql = "SELECT * FROM 202_landing_pages WHERE landing_page_id='" . $mysql['landing_page_id'] . "' AND user_id='" . $mysql['user_id'] . "'";
		$landing_page_result = $db->query($landing_page_sql) or record_mysql_error($landing_page_sql);
		$landing_page_row = $landing_page_result->fetch_assoc();
		if (!$landing_page_row) {
			$error['user'] = '<div class="error">You can not modify other peoples cpc history.</div>';
		} else {
			$html['landing_page_name'] = htmlentities((string)($landing_page_row['landing_page_nickname'] ?? ''), ENT_QUOTES, 'UTF-8');
		}
	} else {
		$html['landing_page_name'] = 'ALL your landing pages in these affiliate campaigns';
	}
} else {
	$html['landing_page_name'] = 'n/a';
}

//check affiliate network id, that you own
if (isset($_POST['ppc_network_id']) && $_POST['ppc_network_id']) {
	$mysql['ppc_network_id'] = $db->real_escape_string((string)$_POST['ppc_network_id']);
	$ppc_network_sql = "SELECT * FROM 202_ppc_networks WHERE ppc_network_id='" . $mysql['ppc_network_id'] . "' AND user_id='" . $mysql['user_id'] . "'";
	$ppc_network_result = $db->query($ppc_network_sql) or record_mysql_error($ppc_network_sql);
	$ppc_network_row = $ppc_network_result->fetch_assoc();
	if (!$ppc_network_row) {
		$error['user'] = '<div class="error">You can not modify other peoples cpc history.</div>';
	} else {
		$html['ppc_network_name'] = htmlentities((string)($ppc_network_row['ppc_network_name'] ?? ''), ENT_QUOTES, 'UTF-8');
	}
} else {
	$html['ppc_network_name'] = 'ALL your PPC networks';
}

//check ppc_account id, that you own
if (isset($_POST['ppc_account_id']) && $_POST['ppc_account_id']) {
	$mysql['ppc_account_id'] = $db->real_escape_string((string)$_POST['ppc_account_id']);
	$ppc_account_sql = "SELECT * FROM 202_ppc_accounts WHERE ppc_account_id='" . $mysql['ppc_account_id'] . "' AND user_id='" . $mysql['user_id'] . "'";
	$ppc_account_result = $db->query($ppc_account_sql) or record_mysql_error($ppc_account_sql);
	$ppc_account_row = $ppc_account_result->fetch_assoc();
	if (!$ppc_account_row) {
		$error['user'] = '<div class="error">You can not modify other peoples cpc history.</div>';
	} else {
		$html['ppc_account_name'] = htmlentities((string)($ppc_account_row['ppc_account_name'] ?? ''), ENT_QUOTES, 'UTF-8');
	}
} else {
	$html['ppc_account_name'] = 'ALL your PPC accounts in these PPC networks';
}

if ((!isset($_POST['cpc_dollars']) || !is_numeric($_POST['cpc_dollars'])) or (!isset($_POST['cpc_cents']) || !is_numeric($_POST['cpc_cents']))) {
	$error['cpc'] = '<div class="error">You did not input a numeric max CPC.</div>';
} else {
	$click_cpc = $_POST['cpc_dollars'] . '.' . $_POST['cpc_cents'];
	$html['click_cpc'] = htmlentities((string) dollar_format($click_cpc), ENT_QUOTES, 'UTF-8');
	$mysql['click_cpc'] = $db->real_escape_string($click_cpc);
}


//echo error
echo ($error['time'] ?? '') . ($error['user'] ?? '') . ($error['cpc'] ?? '');

//if there was an error terminate, or else just continue to run
if ($error) {
	die();
}

$de = [];

// update regular clicks
$sql = "UPDATE  202_clicks LEFT JOIN 202_clicks_advance USING (click_id) 
						   LEFT JOIN 202_clicks_site USING (click_id) 
						   LEFT JOIN 202_aff_campaigns ON (202_clicks.aff_campaign_id = 202_aff_campaigns.aff_campaign_id)
						   LEFT JOIN 202_aff_networks ON (202_aff_campaigns.aff_network_id = 202_aff_networks.aff_network_id)
						   LEFT JOIN 202_ppc_accounts ON (202_ppc_accounts.ppc_account_id = 202_clicks.ppc_account_id)
						   LEFT JOIN 202_ppc_networks ON (202_ppc_networks.ppc_network_id = 202_ppc_accounts.ppc_network_id)
			SET     click_cpc='" . $mysql['click_cpc'] . "'
			WHERE   202_clicks.user_id='" . $mysql['user_id'] . "'";
if (isset($mysql['aff_network_id']) && $mysql['aff_network_id']) {
	$sql .= " AND 202_aff_networks.aff_network_id='" . $mysql['aff_network_id'] . "' ";
}
if (isset($mysql['aff_campaign_id']) && $mysql['aff_campaign_id']) {
	$sql .= " AND 202_clicks.aff_campaign_id='" . $mysql['aff_campaign_id'] . "' ";
}
if (isset($mysql['text_ad_id']) && $mysql['text_ad_id']) {
	$sql .= " AND 202_clicks_advance.text_ad_id='" . $mysql['text_ad_id'] . "' ";
}
if (isset($mysql['landing_page_id']) && $mysql['landing_page_id']) {
	$sql .= " AND 202_clicks.landing_page_id='" . $mysql['landing_page_id'] . "' ";
}
if (isset($mysql['ppc_network_id']) && $mysql['ppc_network_id']) {
	$sql .= " AND 202_ppc_networks.ppc_network_id='" . $mysql['ppc_network_id'] . "' ";
}
if (isset($mysql['ppc_account_id']) && $mysql['ppc_account_id']) {
	$sql .= " AND 202_clicks.ppc_account_id='" . $mysql['ppc_account_id'] . "' ";
}

$sql .= $mysql['method_of_promotion'] ?? '';
$sql .= " AND click_time >=' " . $mysql['from'] . "' AND click_time <= '" . $mysql['to'] . "'";
$result = $db->query($sql) or record_mysql_error($sql);
$clicks_updated = $db->affected_rows;

if (isset($mysql['aff_campaign_id']) && $mysql['aff_campaign_id']) {
	$de['aff_campaign_id'] = $mysql['aff_campaign_id'];
} else {
	$de['aff_campaign_id'] = 0;
}
if (isset($mysql['ppc_account_id']) && $mysql['ppc_account_id']) {
	$de['ppc_account_id'] = $mysql['ppc_account_id'];
} else {
	$de['ppc_account_id'] = 0;
}

$de['user_id'] = $mysql['user_id'];
$de['click_time_from'] = $mysql['from'];
$de['click_time_to'] = $mysql['to'];

$dirty_hours_sql = "INSERT IGNORE INTO 
						202_dirty_hours 
						SET 
						ppc_account_id = '" . $de['ppc_account_id'] . "', 
						aff_campaign_id = '" . $de['aff_campaign_id'] . "',
						user_id = '" . $de['user_id'] . "',
						click_time_from = '" . $de['click_time_from'] . "',
						click_time_to = '" . $de['click_time_to'] . "'";

if (isset($mysql['aff_network_id']) && $mysql['aff_network_id']) {
	$dirty_hours_sql .= ", aff_network_id = '" . $mysql['aff_network_id'] . "'";
}
if (isset($mysql['text_ad_id']) && $mysql['text_ad_id']) {
	$dirty_hours_sql .= ", text_ad_id = '" . $mysql['text_ad_id'] . "'";
}
if (isset($mysql['landing_page_id']) && $mysql['landing_page_id']) {
	$dirty_hours_sql .= ", landing_page_id = '" . $mysql['landing_page_id'] . "'";
}
if (isset($mysql['ppc_network_id']) && $mysql['ppc_network_id']) {
	$dirty_hours_sql .= ", ppc_network_id = '" . $mysql['ppc_network_id'] . "'";
}

$db->query($dirty_hours_sql);

echo '<p style="text-align: center; font-weight: bold;">' . $clicks_updated . ' clicks updated.</p>';
