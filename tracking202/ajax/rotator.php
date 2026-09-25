<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-17) . '/202-config/connect.php');

AUTH::require_user();

$slack = false;

$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
$mysql['user_own_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
$user_sql = "SELECT 202_users.user_name AS username, 202_users_pref.maxmind_isp, 202_users_pref.user_slack_incoming_webhook AS url FROM 202_users_pref LEFT JOIN 202_users ON (202_users.user_id = '".$mysql['user_own_id']."') WHERE 202_users_pref.user_id='1'";
$user_result = $db->query($user_sql);
$user_row = $user_result->fetch_assoc();

if (!empty($user_row['url'])) 
	$slack = new Slack($user_row['url']);

/*
 * Setup › Redirector's two server calls: the rule-value suggestions and saving
 * the rules. The markup fragments this file also served (the classic editor's
 * add_more_*, rule_defaults, generate_rules and rule_details, and the
 * get_rotators select for Get Links) went with the classic pages: the v2 pages
 * render the editor and the lists themselves (U4).
 */

if (isset($_GET['autocomplete']) && isset($_GET['type']) && isset($_GET['query']) && $_GET['autocomplete'] == 'true') {

	header("Content-type: application/json; charset=utf-8");

	$data = rotator_data(urlencode($_GET['query']), $_GET['type']);
	print_r($data);
}

if (isset($_POST['post_rules']) && $_POST['post_rules'] == true && isset($_POST['data'])) {

	// Require a valid session token for this state-changing request.
	if (!hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_POST['token'] ?? ''))) {
		die("ERROR");
	}

	$defaults_added = false;
	$defaults_changed = false;

	if ($_POST['default_type'] == null || $_POST['defaults'] == null) {
		die("ERROR");
	}

	foreach ($_POST['data'] as $rule) {
		$rule_empty = count($rule) != count(array_filter($rule));
		if ($rule_empty) {
			die("ERROR");
		}
			foreach ($rule['criteria'] as $criteria) {
				$criteria_empty = count($criteria) != count(array_filter($criteria));
				if ($criteria_empty) {
					die("ERROR");
				}	
			}

			foreach ($rule['redirects'] as $redirect) {
				$criteria_empty = count($redirect) != count(array_filter($redirect));
				if ($criteria_empty) {
					die("ERROR");
				}	
			}
	}


	$rotator_id = $db->real_escape_string((string)$_POST['rotator_id']);
	$defaults = $db->real_escape_string((string)$_POST['defaults']);

	// Verify the user owns this rotator before applying any changes to it or its rules.
	$ownership_sql = "SELECT id FROM 202_rotators WHERE id='" . $rotator_id . "' AND user_id='" . $mysql['user_id'] . "' LIMIT 1";
	$ownership_result = $db->query($ownership_sql) or record_mysql_error($ownership_sql);
	if (!$ownership_result || !$ownership_result->fetch_assoc()) {
		die("ERROR");
	}

	$rotator_sql = "SELECT 
					2ro.name,
					2ro.default_campaign,
					2ro.default_url,
					2ro.default_lp,
					2ro.auto_monetizer,
					2ac.aff_campaign_name,
					2lp.landing_page_nickname
					FROM 202_rotators AS 2ro 
					LEFT JOIN 202_aff_campaigns AS 2ac ON (2ro.default_campaign = 2ac.aff_campaign_id)
					LEFT JOIN 202_landing_pages AS 2lp ON (2ro.default_lp = 2lp.landing_page_id)
					WHERE 2ro.id = '".$rotator_id."' AND 2ro.user_id = '".$mysql['user_id']."' AND 2lp.landing_page_deleted='0'";
	$rotator_result = $db->query($rotator_sql);
	$rotator_row = $rotator_result->fetch_assoc();
	$rotator_name = $rotator_row['name'] ?? '';
	$canSlack = $slack && $rotator_name !== '';

	if ($canSlack) {
		if (!$rotator_row['default_campaign'] && !$rotator_row['default_url'] && !$rotator_row['default_lp'] && !$rotator_row['auto_monetizer']) {
			$defaults_added = true;
		} else {

			if ($rotator_row['default_campaign']) {
				if ( (($_POST['default_type'] == 'campaign') && ($rotator_row['default_campaign'] != $_POST['defaults'])) || (($_POST['default_type'] != 'campaign') && ($rotator_row['default_campaign'] != $_POST['defaults']))) {
					$default_from_type = "Campaign";
					$default_from_value = $rotator_row['aff_campaign_name'];
					$defaults_changed = true;
				}
			} else if ($rotator_row['default_url']) {
				if (($rotator_row['default_url'] != $_POST['defaults']) && ($_POST['default_type'] != 'url')) {
					$default_from_type = "URL";
					$default_from_value = $rotator_row['default_url'];
					$defaults_changed = true;
				}
			} else if ($rotator_row['default_lp']) {
				if ( (($_POST['default_type'] == 'lp') && ($rotator_row['default_lp'] != $_POST['defaults'])) || (($_POST['default_type'] != 'lp') && ($rotator_row['default_lp'] != $_POST['defaults']))) {
					$default_from_type = "Landing Page";
					$default_from_value = $rotator_row['landing_page_nickname'];
					$defaults_changed = true;
				}
			} else if ($rotator_row['auto_monetizer']) {
				if (($rotator_row['auto_monetizer'] != $_POST['defaults']) && ($_POST['default_type'] != 'monetizer')) {
					$default_from_type = "Auto Monetizer";
					$defaults_changed = true;
				}
			}
		}
	}

	switch ($_POST['default_type']) {
		case 'campaign':
			$default_sql = "default_campaign='".$defaults."', default_url=null, default_lp=null, auto_monetizer=null";
			
			if ($canSlack) {
				$default_campaign_id = $db->real_escape_string($defaults);
				$default_campaign_sql = "SELECT aff_campaign_name FROM 202_aff_campaigns WHERE aff_campaign_id = '".$default_campaign_id."'";
				$default_campaign_result = $db->query($default_campaign_sql);
				$default_campaign_row = $default_campaign_result->fetch_assoc();

				if ($defaults_added) {
					$slack->push('rotator_defaults_added', ['name' => $rotator_name, 'default_type' => 'Campaign', 'default_value' => $default_campaign_row['aff_campaign_name'], 'user' => $user_row['username']]);
				} else if ($defaults_changed) {
					if ($default_from_type != "Auto Monetizer") {
						$slack->push('rotator_defaults_changed', ['name' => $rotator_name, 'default_from_type' => $default_from_type, 'default_from_value' => $default_from_value, 'default_to_type' => 'Campaign', 'default_to_value' => $default_campaign_row['aff_campaign_name'], 'user' => $user_row['username']]);
					} else if ($default_from_type == "Auto Monetizer"){
						$slack->push('rotator_defaults_changed_from_monetizer', ['name' => $rotator_name, 'default_to_type' => 'Campaign', 'default_to_value' => $default_campaign_row['aff_campaign_name'], 'user' => $user_row['username']]);
					}
				}
			}

			break;
		
		case 'url':
			$default_sql = "default_url='".$defaults."', default_campaign=null, default_lp=null, auto_monetizer=null";

			if ($canSlack) {
				if ($defaults_added) {
					$slack->push('rotator_defaults_added', ['name' => $rotator_name, 'default_type' => 'URL', 'default_value' => $defaults, 'user' => $user_row['username']]);
				} else if ($defaults_changed) {
					if ($default_from_type != "Auto Monetizer") {
						$slack->push('rotator_defaults_changed', ['name' => $rotator_name, 'default_from_type' => $default_from_type, 'default_from_value' => $default_from_value, 'default_to_type' => 'URL', 'default_to_value' => $defaults, 'user' => $user_row['username']]);
					} else {
						$slack->push('rotator_defaults_changed_from_monetizer', ['name' => $rotator_name, 'default_to_type' => 'URL', 'default_to_value' => $defaults, 'user' => $user_row['username']]);
					}
				}
			}

			break;

		case 'lp':
			$default_sql = "default_lp='".$defaults."', default_campaign=null, default_url=null, auto_monetizer=null";

			if ($canSlack) {
				$default_lp_id = $db->real_escape_string($defaults);
				$default_lp_sql = "SELECT landing_page_nickname FROM 202_landing_pages WHERE landing_page_id = '".$default_lp_id."' AND landing_page_deleted='0'";
				$default_lp_result = $db->query($default_lp_sql);
				$default_lp_row = $default_lp_result->fetch_assoc();

				if ($defaults_added) {
					$slack->push('rotator_defaults_added', ['name' => $rotator_name, 'default_type' => 'Landing Page', 'default_value' => $default_lp_row['landing_page_nickname'], 'user' => $user_row['username']]);
				} else if ($defaults_changed) {
					if ($default_from_type != "Auto Monetizer") {
						$slack->push('rotator_defaults_changed', ['name' => $rotator_name, 'default_from_type' => $default_from_type, 'default_from_value' => $default_from_value, 'default_to_type' => 'Landing Page', 'default_to_value' => $default_lp_row['landing_page_nickname'], 'user' => $user_row['username']]);
					} else {
						$slack->push('rotator_defaults_changed_from_monetizer', ['name' => $rotator_name, 'default_to_type' => 'Landing Page', 'default_to_value' => $default_lp_row['landing_page_nickname'], 'user' => $user_row['username']]);
					}
				}
			}
			
			break;

		case 'monetizer':
			$default_sql = "default_lp=null, default_campaign=null, default_url=null, auto_monetizer='true'";

			if ($canSlack) {
				if ($defaults_added) {
					$slack->push('rotator_defaults_added_to_monetizer', ['name' => $rotator_name, 'user' => $user_row['username']]);
				} else if ($defaults_changed) {
					if ($default_from_type != "Auto Monetizer") {
						$slack->push('rotator_defaults_changed_to_monetizer', ['name' => $rotator_name, 'default_from_type' => $default_from_type, 'default_from_value' => $default_from_value, 'user' => $user_row['username']]);
					}
				}
			}
			
			break;		
	}

	$sql = "UPDATE 202_rotators SET ".$default_sql." WHERE id='".$rotator_id."'";
	$result = $db->query($sql);

	if ($result) {
		$rules_id = [];
		$criteria_id = [];
		$criteria_added = [];
		$rules_added = [];

		foreach ($_POST['data'] as $rule) {
			$redirects_ids = [];
			$redirect_changed = false;

			$rule_name = $db->real_escape_string($rule['rule_name']);
			if ($rule['status'] == 'active') {$status = 1;} else {$status = 0;}
			if ($rule['split'] == 'true') {$splittest = 1;} else {$splittest = 0;}

			if ($rule['rule_id'] != 'none') {
				/*$old_rule_sql = "SELECT 
								2rl.rule_name,
								2rl.status,
								2rl.redirect_campaign,
								2rl.redirect_url,
								2rl.redirect_lp,
								2rl.auto_monetizer,
								2ac.aff_campaign_name,
								2lp.landing_page_nickname
								FROM 202_rotator_rules AS 2rl 
								LEFT JOIN 202_aff_campaigns AS 2ac ON (2rl.redirect_campaign = 2ac.aff_campaign_id)
								LEFT JOIN 202_landing_pages AS 2lp ON (2rl.redirect_lp = 2lp.landing_page_id)
								WHERE 2rl.id='".$rule['rule_id']."'";
				$old_rule_result = $db->query($old_rule_sql);
				$old_rule_row = $old_rule_result->fetch_assoc();

				if ($old_rule_row['redirect_campaign']) {
					if ( (($rule['redirect_type'] == 'campaign') && ($old_rule_row['redirect_campaign'] != $rule['redirects'])) || (($rule['redirect_type'] != 'campaign') && ($rotator_row['redirect_campaign'] != $rule['redirects']))) {
						$redirect_from_type = "Campaign";
						$redirect_from_value = $old_rule_row['aff_campaign_name'];
						$redirect_changed = true;
					}
				} else if ($old_rule_row['redirect_url']) {
					if (($old_rule_row['redirect_url'] != $rule['redirects']) && ($rule['redirect_type'] != 'url')) {
						$redirect_from_type = "URL";
						$redirect_from_value = $old_rule_row['redirect_url'];
						$redirect_changed = true;
					}
				} else if ($old_rule_row['redirect_lp']) {
					if ( (($rule['redirect_type'] == 'lp') && ($old_rule_row['redirect_lp'] != $rule['redirects'])) || (($rule['redirect_type'] != 'lp') && ($old_rule_row['redirect_lp'] != $rule['redirects']))) {
						$redirect_from_type = "Landing Page";
						$redirect_from_value = $old_rule_row['landing_page_nickname'];
						$redirect_changed = true;
					}
				} else if ($old_rule_row['auto_monetizer']) {
					if (($old_rule_row['auto_monetizer'] != $rule['redirects']) && ($rule['redirect_type'] != 'monetizer')) {
						$redirect_from_type = "Auto Monetizer";
						$redirect_changed = true;
					}
				}

				switch ($rule['redirect_type']) {
					case 'campaign':
						if ($slack && $redirect_changed) {
							$redirect_campaign_id = $db->real_escape_string($redirects);
							$redirect_campaign_sql = "SELECT aff_campaign_name FROM 202_aff_campaigns WHERE aff_campaign_id = '".$redirect_campaign_id."'";
							$redirect_campaign_result = $db->query($redirect_campaign_sql);
							$redirect_campaign_row = $redirect_campaign_result->fetch_assoc();

							if ($redirect_from_type != "Auto Monetizer") {
								$slack->push('rotator_redirect_changed', array('rotator' => $rotator_row['name'], 'rule' => $rule_name, 'redirect_from_type' => $redirect_from_type, 'redirect_from_value' => $redirect_from_value, 'redirect_to_type' => 'Campaign', 'redirect_to_value' => $redirect_campaign_row['aff_campaign_name'], 'user' => $user_row['username']));
							} else if ($redirect_from_type == "Auto Monetizer"){
								$slack->push('rotator_redirect_changed_from_monetizer', array('rotator' => $rotator_row['name'], 'rule' => $rule_name, 'redirect_to_type' => 'Campaign', 'redirect_to_value' => $redirect_campaign_row['aff_campaign_name'], 'user' => $user_row['username']));
							}
						}
						break;
					
					case 'url':
						if ($slack && $redirect_changed) {
							if ($redirect_from_type != "Auto Monetizer") {
								$slack->push('rotator_redirect_changed', array('rotator' => $rotator_row['name'], 'rule' => $rule_name, 'redirect_from_type' => $redirect_from_type, 'redirect_from_value' => $redirect_from_value, 'redirect_to_type' => 'URL', 'redirect_to_value' => $redirects, 'user' => $user_row['username']));
							} else if ($redirect_from_type == "Auto Monetizer"){
								$slack->push('rotator_redirect_changed_from_monetizer', array('rotator' => $rotator_row['name'], 'rule' => $rule_name, 'redirect_to_type' => 'URL', 'redirect_to_value' => $redirects, 'user' => $user_row['username']));
							}
						}
						break;

					case 'lp':
						if ($slack && $redirect_changed) {
							$redirect_lp_id = $db->real_escape_string($redirects);
							$redirect_lp_sql = "SELECT landing_page_nickname FROM 202_landing_pages WHERE landing_page_id = '".$redirect_lp_id."'";
							$redirect_lp_result = $db->query($redirect_lp_sql);
							$redirect_lp_row = $redirect_lp_result->fetch_assoc();

							if ($redirect_from_type != "Auto Monetizer") {
								$slack->push('rotator_redirect_changed', array('rotator' => $rotator_row['name'], 'rule' => $rule_name, 'redirect_from_type' => $redirect_from_type, 'redirect_from_value' => $redirect_from_value, 'redirect_to_type' => 'Landing Page', 'redirect_to_value' => $redirect_lp_row['landing_page_nickname'], 'user' => $user_row['username']));
							} else if ($redirect_from_type == "Auto Monetizer"){
								$slack->push('rotator_redirect_changed_from_monetizer', array('rotator' => $rotator_row['name'], 'rule' => $rule_name, 'redirect_to_type' => 'Landing Page', 'redirect_to_value' => $redirect_lp_row['landing_page_nickname'], 'user' => $user_row['username']));
							}
						}
						break;	

					case 'monetizer':
						if ($slack && $redirect_changed) {
							if ($default_from_type != "Auto Monetizer") {
								$slack->push('rotator_redirect_changed_to_monetizer', array('rotator' => $rotator_row['name'], 'rule' => $rule_name, 'redirect_from_type' => $redirect_from_type, 'redirect_from_value' => $redirect_from_value, 'user' => $user_row['username']));
							}
						}
						break;	
				}*/

				$rule_sql = "UPDATE 202_rotator_rules SET rotator_id='".$rotator_id."', rule_name='".$rule_name."', splittest='".$splittest."', status='".$status."' WHERE id='".(int)$rule['rule_id']."'";
				$rule_result = $db->query($rule_sql);
				$rule_id = (int)$rule['rule_id'];
				$rules_id[] = $rule_id;

				foreach ($rule['redirects'] as $redirect) {
					$redirect_value = $db->real_escape_string($redirect['value']);
					switch ($redirect['type']) {
						case 'campaign':
						$redirect_type_sql = "SELECT aff_campaign_name FROM 202_aff_campaigns WHERE aff_campaign_id = '".$redirect_value."'";
						$redirect_type_result = $db->query($redirect_type_sql);
						$redirect_type_row = $redirect_type_result ? $redirect_type_result->fetch_assoc() : null;
						$redirect_campaign_name = $redirect_type_row['aff_campaign_name'] ?? 'Unknown Campaign';
						$redirect_name = "Campaign: ".$redirect_campaign_name;
							$redirect_sql = "redirect_campaign='".$redirect_value."', redirect_url=null, redirect_lp=null, auto_monetizer=null, name='".$redirect_name."'";
							break;
						
						case 'url':
							$redirect_name = "URL: <a href=".$redirect_value.">link</a>";
							$redirect_sql = "redirect_url='".$redirect_value."', redirect_campaign=null, redirect_lp=null, auto_monetizer=null, name='".$redirect_name."'";
							break;

						case 'lp':
						$redirect_type_sql = "SELECT landing_page_nickname FROM 202_landing_pages WHERE landing_page_id = '".$redirect_value."' AND landing_page_deleted='0'";
						$redirect_type_result = $db->query($redirect_type_sql);
						$redirect_type_row = $redirect_type_result ? $redirect_type_result->fetch_assoc() : null;
						$redirect_lp_name = $redirect_type_row['landing_page_nickname'] ?? 'Unknown Landing Page';
						$redirect_name = "Landing page: ".$redirect_lp_name;
							$redirect_sql = "redirect_lp='".$redirect_value."', redirect_url=null, redirect_campaign=null, auto_monetizer=null, name='".$redirect_name."'";
							break;
						
						case 'monetizer':
							$redirect_name = "Auto Monetizer";
							$redirect_sql = "redirect_lp=null, redirect_url=null, redirect_campaign=null, auto_monetizer=true, name='".$redirect_name."'";
							break;		
					}

					if ($splittest) {
						$redirect_weight = $db->real_escape_string($redirect['weight']);
						$redirect_sql .= ", weight='".$redirect_weight."'";
					}

					if ($redirect['id'] != 'none') {
						$rule_redirect_id = (int)$redirect['id'];
						$rule_redirect_sql = "UPDATE 202_rotator_rules_redirects SET rule_id='".$rule_id."', ".$redirect_sql." WHERE id = '".$rule_redirect_id."'";
						$rule_redirect_result = $db->query($rule_redirect_sql);
						$redirects_ids[] = $rule_redirect_id;
					} else {
						$rule_redirect_sql = "INSERT INTO 202_rotator_rules_redirects SET rule_id='".$rule_id."', ".$redirect_sql."";
						$rule_redirect_result = $db->query($rule_redirect_sql);
						$redirects_ids[] = $db->insert_id;
					}
				}

				if ($slack) {
					if ($old_rule_row['rule_name'] != $rule_name) {
						$slack->push('rotator_rule_name_changed', ['rotator' => $rotator_row['name'], 'old_name' => $old_rule_row['rule_name'], 'new_name' => $rule_name, 'user' => $user_row['username']]);
					}

					if ($old_rule_row['status'] != $status) {
						if ($old_rule_row['status'] == '1') {
							$old_status = 'active';
						} else if ($old_rule_row['status'] == '0') {
							$old_status = 'inactive';
						}

						if ($status) {
							$new_status = 'active';
						} else {
							$new_status = 'inactive';
						}

						$slack->push('rotator_rule_status_changed', ['rotator' => $rotator_row['name'], 'rule' => $rule_name, 'old_status' => $old_status, 'new_status' => $new_status, 'user' => $user_row['username']]);
					}
				}
			} else {
				$rule_sql = "INSERT INTO 202_rotator_rules SET rotator_id='".$rotator_id."', rule_name='".$rule_name."', splittest='".$splittest."', status='".$status."'";
				$rule_result = $db->query($rule_sql);
				$rule_id = $db->insert_id;
				$rules_id[] = $rule_id;

				foreach ($rule['redirects'] as $redirect) {
					$redirect_value = $db->real_escape_string($redirect['value']);
					switch ($redirect['type']) {
						case 'campaign':
							$redirect_sql = "redirect_campaign='".$redirect_value."', redirect_url=null, redirect_lp=null, auto_monetizer=null";
							break;
						
						case 'url':
							$redirect_sql = "redirect_url='".$redirect_value."', redirect_campaign=null, redirect_lp=null, auto_monetizer=null";
							break;

						case 'lp':
						    $redirect_type_sql = "SELECT landing_page_nickname FROM 202_landing_pages WHERE landing_page_id = '".$redirect_value."' AND landing_page_deleted='0'";
						    $redirect_type_result = $db->query($redirect_type_sql);
						    $redirect_type_row = $redirect_type_result->fetch_assoc();
						    $redirect_name = "Landing page: ".$redirect_type_row['landing_page_nickname'];
						    $redirect_sql = "redirect_lp='".$redirect_value."', redirect_url=null, redirect_campaign=null, auto_monetizer=null, name='".$redirect_name."'";
							break;
						
						case 'monetizer':
							$redirect_sql = "redirect_lp=null, redirect_url=null, redirect_campaign=null, auto_monetizer=true";
							break;		
					}

					if ($splittest) {
						$redirect_weight = $db->real_escape_string($redirect['weight']);
						$redirect_sql .= ", weight='".$redirect_weight."'";
					}

					$rule_redirect_sql = "INSERT INTO 202_rotator_rules_redirects SET rule_id='".$rule_id."', ".$redirect_sql."";
				
					$rule_redirect_result = $db->query($rule_redirect_sql);
					$redirects_ids[] = $db->insert_id;
				}

				if ($canSlack) 
					$slack->push('rotator_rule_created', ['rotator' => $rotator_name, 'rule' => $rule_name, 'user' => $user_row['username']]);
			}
			

			if ($rule_result) {
				foreach ($rule['criteria'] as $criteria) {
					$type = $db->real_escape_string($criteria['type']);
					$statement = $db->real_escape_string($criteria['statement']);
					$value = $db->real_escape_string($criteria['value']);

					if ($criteria['criteria_id'] != 'none') {
						$criteria_sql = "UPDATE 202_rotator_rules_criteria SET rotator_id='".$rotator_id."', rule_id='".$rule_id."', type='".$type."', statement='".$statement."', value='".$value."' WHERE id='".(int)$criteria['criteria_id']."'";
						$criteria_result = $db->query($criteria_sql);
						$criteria_id[] = (int)$criteria['criteria_id'];
					} else {
						$criteria_sql = "INSERT INTO 202_rotator_rules_criteria SET rotator_id='".$rotator_id."', rule_id='".$rule_id."', type='".$type."', statement='".$statement."', value='".$value."'";
						$criteria_result = $db->query($criteria_sql);
						$criteria_inserted_id = $db->insert_id;
						$criteria_id[] = $criteria_inserted_id;

							if ($canSlack) {
								$criteria_value = $type." ".$statement." ".$value;
								$slack->push('rotator_rules_criteria_created', ['rotator' => $rotator_name, 'rule' => $rule_name, 'criteria' => $criteria_value, 'user' => $user_row['username']]);
						}
					}

					$criteria_added[] = $criteria['criteria_id'];
				}
			}

			// Sentinel 0 keeps the IN list valid when nothing was inserted/updated (ids are >= 1, so all orphans match).
			$redirects_ids = $redirects_ids === [] ? '0' : implode(', ', $redirects_ids);
			$delete_redirects_sql = "DELETE FROM 202_rotator_rules_redirects WHERE id NOT IN (".$redirects_ids.") AND rule_id = '".$rule_id."'";
			$delete_redirects_result = $db->query($delete_redirects_sql) or record_mysql_error($delete_redirects_sql);

		}
	}

	// Sentinel 0 keeps the IN list valid when nothing was inserted/updated (ids are >= 1, so all orphans match).
	$criteria_id = $criteria_id === [] ? '0' : implode(', ', $criteria_id);
	$rules_id = $rules_id === [] ? '0' : implode(', ', $rules_id);

	if ($slack && $rotator_row) {
		$sql = "SELECT rule_name FROM 202_rotator_rules WHERE id NOT IN (".$rules_id.") AND rotator_id='".$rotator_id."'";
		$result = $db->query($sql);
		if ($result->num_rows > 0) {
			while ($row = $result->fetch_assoc()) {
				$slack->push('rotator_rule_deleted', ['rotator' => $rotator_row['name'], 'rule' => $row['rule_name'], 'user' => $user_row['username']]);
			}
		}
	}

	$sql = "DELETE FROM `202_rotator_rules` WHERE `id` NOT IN (".$rules_id.") AND rotator_id='".$rotator_id."'";
	$result = $db->query($sql) or record_mysql_error($sql);

	if ($slack && isset($rotator_row['name'])) {
		$sql = "SELECT 2rc.id, 2rc.type, 2rc.statement, 2rc.value, 2rl.rule_name
				FROM 202_rotator_rules_criteria AS 2rc 
				LEFT JOIN 202_rotator_rules AS 2rl ON (2rc.rule_id = 2rl.id) 
				WHERE 2rc.id NOT IN (".$criteria_id.") AND 2rc.rotator_id='".$rotator_id."'";
		$result = $db->query($sql);
		if ($result->num_rows > 0) {
			while ($row = $result->fetch_assoc()) {
				if (!in_array($row['id'], $criteria_added)) {
					$criteria_value = $row['type']." ".$row['statement']." ".$row['value'];
					$slack->push('rotator_rules_criteria_deleted', ['rotator' => $rotator_row['name'], 'rule' => $row['rule_name'], 'criteria' => $criteria_value, 'user' => $user_row['username']]);
				}
			}
		}
	}		


	$sql = "DELETE FROM `202_rotator_rules_criteria` WHERE `id` NOT IN (".$criteria_id.") AND rotator_id='".$rotator_id."'";
	$result = $db->query($sql) or record_mysql_error($sql);

	if ($criteria_result == true) {
		echo "DONE";
	}

}
