<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-17) . '/202-config/connect.php');

AUTH::require_user();

// validate session token before any state change
if (!hash_equals((string)($_SESSION['token'] ?? ''), (string)($_POST['token'] ?? ''))) {
	http_response_code(403);
	die('Invalid token');
}

$slack = false;
$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
$mysql['user_own_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
$user_sql = "SELECT 2u.user_name as username, 2up.user_slack_incoming_webhook AS url FROM 202_users AS 2u INNER JOIN 202_users_pref AS 2up ON (2up.user_id = 1) WHERE 2u.user_id = '".$mysql['user_own_id']."'";
$user_results = $db->query($user_sql);
$user_row = $user_results->fetch_assoc();

if (!empty($user_row['url'])) 
	$slack = new Slack($user_row['url']);

// Initialize variables to prevent undefined variable warnings
$error = [];
$html = [];

// The answer is markup for Setup › Get Links (v2): a refusal is a danger
// flash and stops here; a warning is a warning flash above the link. The
// sentences are the ones this endpoint always said.
require_once dirname(__DIR__) . '/setup/_includes/setup_ui.php';

//check variables
	// A direct link or simple landing page needs its campaign. This check
	// read `!empty($_POST['tracker_type'])`, which is false for '0', so it
	// never ran and a link to no campaign was stored; it runs now.
	if ((string) ($_POST['tracker_type'] ?? '') === '0') {

		if(empty($_POST['aff_network_id'])) { $error['aff_network_id'] = 'You have not selected an affiliate network.'; }
		if(empty($_POST['aff_campaign_id'])) { $error['aff_campaign_id'] = 'You have not selected an affiliate campaign.'; }
		if(empty($_POST['method_of_promotion'])) { $error['method_of_promotion'] = 'You have to select your method of promoting this affiliate link.'; }

		if (!empty($error)) {
			foreach ($error as $sentence) {
				echo p202_flash('bad', $sentence);
			}
			die();
		}

	} else if(!empty($_POST['tracker_type']) && $_POST['tracker_type'] == 2) {
		if(empty($_POST['tracker_rotator'])) { die(p202_flash('bad', 'You have not selected rotator.')); }
	}

	//but we'll allow them to choose the following options, can make a tracker link without but they will be notified
	if (isset($_POST['tracker_type']) && $_POST['tracker_type'] != 2) {
		if(empty($_POST['click_cloaking'])) { $error['click_cloaking'] = 'This tracking link is not attached to any cloaking preference, are you sure you want to do this?'; }
	}


	if (!empty($_POST['ppc_network_id']) and empty($_POST['ppc_account_id'])) {
		die(p202_flash('bad', 'You have a traffic source selected, but no account for it. To track a traffic source, choose one of its accounts; add one on Traffic Sources if it has none.'));
	}
	if(empty($_POST['ppc_network_id'])) { $error['ppc_network_id'] = 'This tracking link is not attached to any traffic source, are you sure you want to do this?'; }
	if(empty($_POST['ppc_account_id'])) { $error['ppc_account_id'] = 'This tracking link is not attached to any traffic source account, are you sure you want to do this?'; }
	if((!isset($_POST['cpc_dollars']) || !is_numeric($_POST['cpc_dollars'])) or (!isset($_POST['cpc_cents']) || !is_numeric($_POST['cpc_cents']))) { $error['cpc'] = 'This tracking link does not have its CPC set, are you sure you want to do this?'; }

	//if they do a landing page, make sure they have one
	if (!empty($_POST['method_of_promotion']) && $_POST['method_of_promotion'] == 'landingpage') {
		if (empty($_POST['landing_page_id'])) {
			die(p202_flash('bad', 'You have not selected a landing page to use.'));
		}
	}

	// Every id this link stores, or reads a name or a setting from, must be
	// this account's. The lookups below and the INSERT took them as posted,
	// so another account's campaign, source, account, ad or redirector could
	// be attached to a tracker here and its names read back through this
	// account's Slack notices (#164, #173). Checked before anything is
	// written or deleted, as the sibling code endpoints check theirs.
	$owned = [
		'aff_network_id' => ['202_aff_networks', 'aff_network_id', 'aff_network_deleted', 'That campaign category'],
		'aff_campaign_id' => ['202_aff_campaigns', 'aff_campaign_id', 'aff_campaign_deleted', 'That campaign'],
		'landing_page_id' => ['202_landing_pages', 'landing_page_id', 'landing_page_deleted', 'That landing page'],
		'text_ad_id' => ['202_text_ads', 'text_ad_id', 'text_ad_deleted', 'That text ad'],
		'ppc_network_id' => ['202_ppc_networks', 'ppc_network_id', 'ppc_network_deleted', 'That traffic source'],
		'ppc_account_id' => ['202_ppc_accounts', 'ppc_account_id', 'ppc_account_deleted', 'That traffic source account'],
		'tracker_rotator' => ['202_rotators', 'id', null, 'That redirector'],
	];
	$ownedRows = [];
	foreach ($owned as $field => [$table, $column, $deletedColumn, $what]) {
		$raw = $_POST[$field] ?? '';
		if (!is_string($raw)) {
			die(p202_flash('bad', $what . ' was sent more than once.'));
		}
		if ($raw === '' || $raw === '0') {
			continue;
		}
		if (preg_match('/^[1-9]\d{0,9}$/D', $raw) !== 1) {
			die(p202_flash('bad', $what . ' is not one of yours, or it was removed.'));
		}
		$ownedSql = 'SELECT * FROM `' . $table . '` WHERE `' . $column . '` = ? AND `user_id` = ?'
			. ($deletedColumn !== null ? ' AND COALESCE(`' . $deletedColumn . '`, 0) = 0' : '') . ' LIMIT 1';
		$ownedStmt = $db->prepare($ownedSql);
		if ($ownedStmt === false) {
			record_mysql_error($db, $ownedSql);
		}
		$ownedId = (int) $raw;
		$ownedUser = (int) $_SESSION['user_id'];
		$ownedStmt->bind_param('ii', $ownedId, $ownedUser);
		if (!$ownedStmt->execute()) {
			$ownedStmt->close();
			record_mysql_error($db, $ownedSql);
		}
		$ownedResult = $ownedStmt->get_result();
		if ($ownedResult === false) {
			$ownedStmt->close();
			record_mysql_error($db, $ownedSql);
		}
		$ownedRow = $ownedResult->fetch_assoc();
		$ownedStmt->close();
		if ($ownedRow === null) {
			die(p202_flash('bad', $what . ' is not one of yours, or it was removed.'));
		}
		$ownedRows[$field] = $ownedRow;
	}
	// An account is chosen under its source: one from another source would
	// be stored with the posted source's variables in its link.
	if (isset($ownedRows['ppc_account_id'], $ownedRows['ppc_network_id'])
		&& (string) $ownedRows['ppc_account_id']['ppc_network_id'] !== (string) $ownedRows['ppc_network_id']['ppc_network_id']) {
		die(p202_flash('bad', 'That traffic source account belongs to a different traffic source.'));
	}
	if (isset($ownedRows['aff_campaign_id'], $ownedRows['aff_network_id'])
		&& (string) $ownedRows['aff_campaign_id']['aff_network_id'] !== (string) $ownedRows['aff_network_id']['aff_network_id']) {
		die(p202_flash('bad', 'That campaign belongs to a different category.'));
	}

//echo the warnings: the link is still made
	foreach (['text_ad_id', 'ppc_network_id', 'ppc_account_id', 'cpc', 'click_cloaking', 'cloaking_url'] as $warning) {
		if (isset($error[$warning])) {
			echo p202_flash('warn', $error[$warning]);
		}
	}

//show tracking code

	$input_landing_page_id = $db->real_escape_string((string)($_POST['landing_page_id'] ?? '0'));
	$landing_page_sql = "SELECT * FROM `202_landing_pages` WHERE `landing_page_id`='".$input_landing_page_id."' AND `user_id`='".$mysql['user_id']."'";
	$landing_page_result = $db->query($landing_page_sql) or record_mysql_error($landing_page_sql);
	$landing_page_row = $landing_page_result->fetch_assoc();
	
	if (isset($_POST['cost_type']) && $_POST['cost_type'] == 'cpc') {
		$click_cpc = ($_POST['cpc_dollars'] ?? '0') . '.' . ($_POST['cpc_cents'] ?? '00');
		$mysql['click_cpc'] = $db->real_escape_string($click_cpc);
		$cost_sql = "`click_cpc`='".$mysql['click_cpc']."',";
	} else if (isset($_POST['cost_type']) && $_POST['cost_type'] == 'cpa') {
		$click_cpa = ($_POST['cpa_dollars'] ?? '0') . '.' . ($_POST['cpa_cents'] ?? '00');
		$mysql['click_cpa'] = $db->real_escape_string($click_cpa);
		$cost_sql = "`click_cpa`='".$mysql['click_cpa']."',";
	}

	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
	$mysql['aff_campaign_id'] = $db->real_escape_string((string)($_POST['aff_campaign_id'] ?? '0'));
	$mysql['text_ad_id'] = $db->real_escape_string((string)($_POST['text_ad_id'] ?? '0'));
	$mysql['ppc_network_id'] = $db->real_escape_string((string)($_POST['ppc_network_id'] ?? '0')); 
	$mysql['ppc_account_id'] = $db->real_escape_string((string)($_POST['ppc_account_id'] ?? '0')); 
	$mysql['click_cloaking'] = $db->real_escape_string((string)($_POST['click_cloaking'] ?? '0')); 
	$mysql['landing_page_id'] = $db->real_escape_string((string)($landing_page_row['landing_page_id'] ?? '0'));
	$mysql['rotator_id'] = $db->real_escape_string((string)($_POST['tracker_rotator'] ?? '0'));
	$mysql['tracker_time'] = time();
	

	if (isset($_POST['edit_tracker']) && $_POST['edit_tracker'] && isset($_POST['tracker_id']) && $_POST['tracker_id']) {
		$mysql['tracker_id_public'] = $db->real_escape_string((string)$_POST['tracker_id']);
		$get_tracker_sql = "SELECT 
							tracker_id, 
							tracker_id_public,
							202_trackers.aff_campaign_id,
							text_ad_id,
							ppc_account_id,
							aff_network_name,
							aff_campaign_name,
							landing_page_nickname,
							202_trackers.landing_page_id,
							rotator_id,
							text_ad_name,
							ppc_network_name,
							ppc_account_name,
							click_cloaking,
							click_cpc,
							click_cpa,
							name 
							FROM 202_trackers
							LEFT JOIN 202_aff_campaigns USING (aff_campaign_id)
							LEFT JOIN 202_aff_networks USING (aff_network_id)
							LEFT JOIN 202_text_ads USING (text_ad_id)
							LEFT JOIN 202_ppc_accounts USING (ppc_account_id)
							LEFT JOIN 202_ppc_networks USING (ppc_network_id)
							LEFT JOIN 202_landing_pages ON (202_trackers.landing_page_id = 202_landing_pages.landing_page_id) 
							LEFT JOIN 202_rotators ON (202_trackers.rotator_id = 202_rotators.id)
							WHERE 202_trackers.tracker_id_public = '".$mysql['tracker_id_public']."' AND 202_trackers.user_id = '".$mysql['user_id']."'";
		
		$get_tracker_result = $db->query($get_tracker_sql);
		$get_tracker_row = $get_tracker_result->fetch_assoc();		

		if ($get_tracker_result->num_rows > 0) {
			$drop_tracker = "DELETE FROM 202_trackers WHERE tracker_id = '".$get_tracker_row['tracker_id']."'";
			$drop_tracker_result = $db->query($drop_tracker);
		}
	}

	$db->begin_transaction();

	$tracker_sql = "INSERT INTO `202_trackers`
					SET			`user_id`='".$mysql['user_id']."',
								`aff_campaign_id`='".$mysql['aff_campaign_id']."',
								`tracker_id_public`='0',
								`text_ad_id`='".$mysql['text_ad_id']."',
								`ppc_account_id`='".$mysql['ppc_account_id']."',
								".$cost_sql."
								`landing_page_id`='".$mysql['landing_page_id']."',
								`rotator_id`='".$mysql['rotator_id']."',
								`click_cloaking`='".$mysql['click_cloaking']."',
								`tracker_time`='".$mysql['tracker_time']."'";
	$tracker_result = $db->query($tracker_sql);
	if (!$tracker_result) {
		$db->rollback();
		record_mysql_error($tracker_sql);
		die('Error creating tracker');
	}

	$tracker_row['tracker_id'] = $db->insert_id;
	$mysql['tracker_id'] = $db->real_escape_string((string)$tracker_row['tracker_id']);

	if (isset($_POST['edit_tracker']) && $_POST['edit_tracker'] && isset($_POST['tracker_id']) && $_POST['tracker_id'] && $get_tracker_result->num_rows > 0) {
		$mysql['tracker_id_public'] = $db->real_escape_string((string)$get_tracker_row['tracker_id_public']);
	} else {
		$tracker_id_public = random_int(1,9) . $tracker_row['tracker_id'] . random_int(1,9);
		$mysql['tracker_id_public'] = $db->real_escape_string((string)$tracker_id_public);
	}

	$tracker_id_public = $mysql['tracker_id_public'];

	$tracker_sql = "UPDATE 		`202_trackers`
					SET			`tracker_id_public`='".$mysql['tracker_id_public']."'
					WHERE		`tracker_id`='".$mysql['tracker_id']."'";
	$tracker_result = $db->query($tracker_sql);
	if (!$tracker_result) {
		$db->rollback();
		record_mysql_error($tracker_sql);
		die('Error setting tracker ID');
	}

	$db->commit();

	$parsed_url = [];
	if (!empty($landing_page_row['landing_page_url'])) {
		$parsed_url = parse_url((string) $landing_page_row['landing_page_url']);
	}

	//setup array of all internally recognized url variables
	$t202variables = [
	    "c1",
	    "c2",
	    "c3",
	    "c4",
	    "utm_source",
	    "utm_medium",
	    "utm_campaign",
	    "utm_term",
	    "utm_content",
	    "t202ref",
	    "t202b",
	    "t202kw"
	];
	
	$tracking_variable_string = '&';
	
	$get_variables = "SELECT * FROM 202_ppc_network_variables WHERE ppc_network_id = '".$mysql['ppc_network_id']."' AND deleted = 0";
	$get_variables_result = $db->query($get_variables);
	
	// Initialize html array
	$html = [];
	
	//loop over all our internal vars to see if user has set up a custom var in step 1
	if ($get_variables_result->num_rows > 0) {
	    while ($get_variables_row = $get_variables_result->fetch_assoc()) {

	        $key=array_search($get_variables_row['parameter'], $t202variables); // look for the current paramaeter in the list of internal url variables

	        if($key===FALSE){ //if not found the output 
	             $tracking_variable_string .= $get_variables_row['parameter'].'=' . $get_variables_row['placeholder'] . '&';
	        }
	        else{ //if found save into our html array for later
	            $html[$t202variables[$key]]=$get_variables_row['placeholder'];
	        }
	       unset($key); //unset just in case old values get stuck
	        
	    }
	}

	//loop over all our internal variables again
    foreach ($t202variables as $key) {

        if (isset($_POST[$key]) && trim((string) $_POST[$key]) != '') { //if there is a non empty value posted, then overwrite current value in html array with it 
            $html[$key] = $db->real_escape_string(trim((string) $_POST[$key]));
        }
        if (isset($html[$key]) || $key=='t202kw')
            $tracking_variable_string .= $key . '=' . ($html[$key] ?? '') . '&'; //now write out the values/ but only if they are not empty with the exception of t202kw with we will write out no matter what
    }
    
    //remove & from end of the variable
    $tracking_variable_string=rtrim($tracking_variable_string,'&');


	if ($slack) {
		$editTracker = !empty($_POST['edit_tracker']);

		$tracker_type = 'Unknown';
		switch ($_POST['tracker_type'] ?? '') {
			case '0':
				if (($_POST['method_of_promotion'] ?? '') == 'directlink') {
						$tracker_type = 'Direct Link';
				} else if (($_POST['method_of_promotion'] ?? '') == 'landingpage') {
						$tracker_type = 'Simple Landing Page';
				}
				break;

			case '1':
				$tracker_type = 'Advanced Landing Page';
				break;

			case '2':
				$tracker_type = 'Smart Rotator';
				break;
		}

		if (!$editTracker) {

			$slack->push('tracking_link_created', ['type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'user' => $user_row['username']]);

		} else if($editTracker && !empty($_POST['tracker_id']) && $get_tracker_result->num_rows > 0) {

			if ($_POST['tracker_type'] == '0') {
				if ($_POST['aff_campaign_id'] != $get_tracker_row['aff_campaign_id']) {
					
					$mysql['aff_campaign_id'] = $db->real_escape_string((string)$_POST['aff_campaign_id']);
					$sql = "SELECT aff_network_name, aff_campaign_name FROM 202_aff_campaigns LEFT JOIN 202_aff_networks USING (aff_network_id) WHERE aff_campaign_id = '".$mysql['aff_campaign_id']."'";
					$result = $db->query($sql);
					$row = $result->fetch_assoc();

					$slack->push('tracking_link_category_changed', ['type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'old_category' => $get_tracker_row['aff_network_name'], 'new_category' => $row['aff_network_name'], 'user' => $user_row['username']]);
					$slack->push('tracking_link_campaign_changed', ['type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'old_campaign' => $get_tracker_row['aff_campaign_name'], 'new_campaign' => $row['aff_campaign_name'], 'user' => $user_row['username']]);
				}

				if ($_POST['method_of_promotion'] == 'directlink') {
					if ($get_tracker_row['landing_page_id']) {
						$slack->push('tracking_link_method_of_promotion_changed', ['type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'old_method' => 'Landing Page', 'new_method' => 'Direct Link', 'user' => $user_row['username']]);
					}
				}

				if ($_POST['method_of_promotion'] == 'landingpage') {
					if ($get_tracker_row['landing_page_id'] == 0) {
						$slack->push('tracking_link_method_of_promotion_changed', ['type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'old_method' => 'Direct Link', 'new_method' => 'Landing Page', 'user' => $user_row['username']]);
					}
				}
			}

			if ($_POST['method_of_promotion'] == 'landingpage' || $_POST['tracker_type'] == '1') {
				if (($get_tracker_row['landing_page_id']) && $_POST['landing_page_id'] != $get_tracker_row['landing_page_id']) {
					
					$mysql['landing_page_id'] = $db->real_escape_string((string)($_POST['landing_page_id'] ?? '0'));
					$sql = "SELECT landing_page_nickname FROM 202_landing_pages WHERE landing_page_id = '".$mysql['landing_page_id']."'";
					$result = $db->query($sql);
					$row = $result->fetch_assoc();

					$slack->push('tracking_link_landing_page_changed', ['type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'old_lp' => $get_tracker_row['landing_page_nickname'], 'new_lp' => $row['landing_page_nickname'], 'user' => $user_row['username']]);
				}
			}

			if (isset($_POST['tracker_type']) && ($_POST['tracker_type'] == '0' || $_POST['tracker_type'] == '1')) {

				if (isset($_POST['text_ad_id']) && $get_tracker_row['text_ad_id']) {
					$mysql['text_ad_id'] = $db->real_escape_string((string)($_POST['text_ad_id'] ?? '0'));
					$sql = "SELECT text_ad_name FROM 202_text_ads WHERE text_ad_id = '".$mysql['text_ad_id']."'";
					$result = $db->query($sql);
					$row = $result->fetch_assoc();

					$slack->push('tracking_link_text_ad_changed', ['type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'old_ad' => $get_tracker_row['text_ad_name'], 'new_ad' => $row['text_ad_name'], 'user' => $user_row['username']]);
				}

				if (isset($_POST['text_ad_id']) && !$get_tracker_row['text_ad_id']) {
					$mysql['text_ad_id'] = $db->real_escape_string((string)($_POST['text_ad_id'] ?? '0'));
					$sql = "SELECT text_ad_name FROM 202_text_ads WHERE text_ad_id = '".$mysql['text_ad_id']."'";
					$result = $db->query($sql);
					$row = $result->fetch_assoc();

					$slack->push('tracking_link_text_ad_added', ['type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'ad' => $row['text_ad_name'], 'user' => $user_row['username']]);
				}

				if (!$_POST['text_ad_id'] && $get_tracker_row['text_ad_id']) {
					
					$slack->push('tracking_link_text_ad_removed', ['type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'ad' => $get_tracker_row['text_ad_name'], 'user' => $user_row['username']]);

				}

				if ($_POST['click_cloaking'] != $get_tracker_row['click_cloaking']) {
					if ($get_tracker_row['click_cloaking'] == '-1') {
						$from_type = 'Campaign Default On/Off';
					} else if ($get_tracker_row['click_cloaking'] == '0') {
						$from_type = 'Off - Overide Campaign Default';
					} else {
						$from_type = 'On - Overide Campaign Default';
					}

					if ($_POST['click_cloaking'] == '-1') {
						$to_type = 'Campaign Default On/Off';
					} else if ($_POST['click_cloaking'] == '0') {
						$to_type = 'Off - Overide Campaign Default';
					} else {
						$to_type = 'On - Overide Campaign Default';
					}

					$slack->push('tracking_link_cloaking_changed', ['type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'old_type' => $from_type, 'new_type' => $to_type, 'user' => $user_row['username']]);
				}
			}

			if ($_POST['ppc_account_id'] != $get_tracker_row['ppc_account_id']) {
				$mysql['ppc_account_id'] = $db->real_escape_string((string)$_POST['ppc_account_id']);
				$sql = "SELECT ppc_account_name, ppc_network_name FROM 202_ppc_accounts LEFT JOIN 202_ppc_networks USING (ppc_network_id) WHERE ppc_account_id = '".$mysql['ppc_account_id']."'";
				$result = $db->query($sql);
				$row = $result->fetch_assoc();

				$slack->push('tracking_link_pcc_network_changed', ['type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'old_source' => $get_tracker_row['ppc_network_name'], 'new_source' => $row['ppc_network_name'], 'user' => $user_row['username']]);
				$slack->push('tracking_link_ppc_account_changed', ['type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'old_account' => $get_tracker_row['ppc_account_name'], 'new_account' => $row['ppc_account_name'], 'user' => $user_row['username']]);
			}

			if (isset($_POST['cost_type']) && $_POST['cost_type'] == 'cpc') {
				if ($get_tracker_row['click_cpc'] == null) {
					$slack->push('tracking_link_cost_type_changed', ['type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'old_type' => 'CPA', 'new_type' => 'CPC', 'user' => $user_row['username']]);
				} else {
					if ($click_cpc != $get_tracker_row['click_cpc']) {
						$slack->push('tracking_link_cost_value_changed', ['type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'old_value' => $get_tracker_row['click_cpc'], 'new_value' => $click_cpc, 'user' => $user_row['username']]);
					}
				}

			} else if ($_POST['cost_type'] == 'cpa') {
				if ($get_tracker_row['click_cpa'] == null) {
					$slack->push('tracking_link_cost_type_changed', ['type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'old_type' => 'CPC', 'new_type' => 'CPA', 'user' => $user_row['username']]);
				} else if ($click_cpa != $get_tracker_row['click_cpa']) {
					$slack->push('tracking_link_cost_value_changed', ['type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'old_value' => $get_tracker_row['click_cpa'], 'new_value' => $click_cpa, 'user' => $user_row['username']]);
				}
			}

			if ($_POST['tracker_type'] == '2') {
				if ($_POST['tracker_rotator'] != $get_tracker_row['rotator_id']) {
					$mysql['rotator_id'] = $db->real_escape_string((string)$_POST['tracker_rotator']);
					$sql = "SELECT name FROM 202_rotators WHERE id = '".$mysql['rotator_id']."'";
					$result = $db->query($sql);
					$row = $result->fetch_assoc();

					$slack->push('tracking_link_rotator_changed', ['type' => $tracker_type, 'id' => $tracker_row['tracker_id'], 'old_rotator' => $get_tracker_row['name'], 'new_rotator' => $row['name'], 'user' => $user_row['username']]);
				}
			}
		}
	}
	

	// ── The answer ─────────────────────────────────────────────────────
	$trackingLinkParts = [];
	if (($_POST['method_of_promotion'] ?? '') == 'directlink') {
		$trackingLinkParts[] = 'http://' . getTrackingDomain() . get_absolute_url() . 'tracking202/redirect/dl.php?t202id=' . $tracker_id_public . $tracking_variable_string;
	}
	if (($_POST['tracker_type'] ?? '') == 2) {
		$trackingLinkParts[] = 'http://' . getTrackingDomain() . get_absolute_url() . 'tracking202/redirect/rtr.php?t202id=' . $tracker_id_public . $tracking_variable_string;
	}
	if ((($_POST['method_of_promotion'] ?? '') == 'landingpage') or (($_POST['tracker_type'] ?? '') == 1)) {
		$destination_url = ($parsed_url['scheme'] ?? 'http') . '://' .
		                   ($parsed_url['host'] ?? '') .
		                   ($parsed_url['path'] ?? '') . '?';
		if (!empty($parsed_url['query'])) {
			$destination_url .= $parsed_url['query'] . '&';
		}
		$destination_url .= 't202id=' . $tracker_id_public;
		if (!empty($parsed_url['fragment'])) {
			$destination_url .= '#' . $parsed_url['fragment'];
		}
		$destination_url .= $tracking_variable_string;
		$trackingLinkParts[] = $destination_url;
	}

	if (isset($_POST['edit_tracker']) && $_POST['edit_tracker'] && isset($_POST['tracker_id']) && $_POST['tracker_id']) {
		echo p202_flash('ok', 'Tracker updated. Your tracking link stays the same.');
	}
	foreach ($trackingLinkParts as $trackingLink) {
		echo p202_setup_code_box($trackingLink, ['label' => 'Destination URL']);
	}
	?>
<p class="form-text">Use this as the destination URL in your campaign at the traffic source. It carries everything set above, so when you change the cost, the account or the ad, get a new link. To track keywords, put your traffic source's keyword token right after <code>t202kw=</code>. Test the link yourself before you run traffic to it.</p>
<details class="p202-disclosure" data-p202-remember="setup-get-links-keywords">
	<summary>Keyword tokens <span class="p202-disclosure__hint">examples for the big ad networks</span></summary>
	<div class="p202-disclosure__body">
		<ul class="mb-2">
			<li>Microsoft Advertising (Bing): <code>&amp;t202kw={QueryString}</code></li>
			<li>Google Ads: <code>&amp;t202kw={keyword}</code></li>
		</ul>
		<p class="mb-0">When you change a bid, get a new link: an old link keeps reporting the cost it was made with. In most cases each text ad should have its own link.</p>
	</div>
</details>
