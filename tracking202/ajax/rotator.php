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

	$campaigns_sql = "SELECT aff_campaign_id, aff_campaign_name FROM 202_aff_campaigns LEFT JOIN  202_aff_networks using(aff_network_id) WHERE 202_aff_campaigns.user_id = '".$mysql['user_id']."' AND `aff_campaign_deleted`=0 AND `aff_network_deleted`=0 AND 202_aff_networks.user_id = 202_aff_campaigns.user_id";
	
	$campaigns_result = $db->query($campaigns_sql);
	$campaigns = [];

	if ($campaigns_result->num_rows > 0) {
		while ($campaigns_row = $campaigns_result->fetch_assoc()) {
			$campaigns[] = ['id' => $campaigns_row['aff_campaign_id'], 'name' => $campaigns_row['aff_campaign_name']];
		}
	}

	$lp_sql = "SELECT landing_page_id, landing_page_nickname, landing_page_type FROM 202_landing_pages JOIN 202_aff_campaigns using (aff_campaign_id) WHERE 202_landing_pages.user_id = '".$mysql['user_id']."' AND COALESCE(aff_campaign_deleted,0) = 0 AND COALESCE(landing_page_deleted,0) = 0 ORDER BY landing_page_type,landing_page_nickname";
	
	$lp_result = $db->query($lp_sql);
	$lps = [];

	if ($lp_result->num_rows > 0) {
		while ($lp_row = $lp_result->fetch_assoc()) {
			$lps[] = ['id' => $lp_row['landing_page_id'], 'name' => $lp_row['landing_page_nickname'], 'type' => $lp_row['landing_page_type']];
		}
	}

if (isset($_POST['add_more_redirects']) && $_POST['add_more_redirects'] == true) { ?>
	
		<div class="row" style="margin-top:5px" data-redirect-id="none">
			<div class="col-xs-4" id="redirect_type_radio">
				<label for="redirect_type" style="margin-left: -15px;" class="col-xs-5 control-label"></label>
					<select class="form-control input-sm" name="redirect_type" id="redirect_type_select">
						<option value="campaign">Campaign</option>
						<option value="lp">Landing Page</option>
						<option value="url">Url</option>
						<!--<option value="monetizer">Auto Monetizer</option>-->
					</select>
			</div>
			<div class="col-xs-4" id="redirect_campaign_select" style="margin-left: -3%">
				<select class="form-control input-sm" name="redirect_campaign" style="width: 100%;">
					<option value="">select campaign</option>
						<?php 
							foreach ($campaigns as $campaign) { ?>
								<option value="<?php echo $campaign['id'];?>"><?php echo $campaign['name'];?></option>
						<?php } ?>
				</select>
			</div>
			<div class="col-xs-4" id="redirect_lp_select" style="display:none; margin-left: -3%">
				<select class="form-control input-sm" name="redirect_lp" style="width: 100%;">
					<option value="">select landing page</option>
					<?php 
						foreach ($lps as $lp) { ?>
							<option value="<?php echo $lp['id'];?>"><?php echo $lp['name']; if($lp['type']) echo " (advanced)"; else echo " (simple)";?></option>
					<?php } ?>
				</select>
			</div>

			<div class="col-xs-4" id="redirect_url_input" style="display:none; margin-left: -3%">	
				<div class="input-group input-group-sm">
					<span class="input-group-addon"><i class="fa fa-globe"></i></span>
					<input name="redirect_url" class="form-control" style="width: 150%;" type="text" placeholder="http://">
				</div>
			</div>

			<!--<div class="col-xs-4" id="redirect_monetizer" style="display:none; margin-left: -3%">	
				<span class="small">Auto Monetizer as redirect destination.</span>
			</div>-->

			<div class="col-xs-2" id="split_weight">	
				<div class="input-group input-group-sm">
					<span class="input-group-addon"><i class="fa">weight</i></span>
					<input name="split_weight" class="form-control" type="text" placeholder="50">
				</div>
			</div>
			<span class="fui-cross" id="remove_redirect"></span>
		</div>

		<script type="text/javascript">
		$(document).ready(function() {
			$('[name="redirect_campaign"]').select2();
			$('[name="redirect_lp"]').select2();
		});
	</script>
<?php }

if (isset($_POST['get_rotators']) && isset($_POST['rotator_id']) && $_POST['get_rotators'] == true) { ?>
	
	<select class="form-control input-sm" name="tracker_rotator">
	    <option value=""> -- </option>
		<?php 			$rotator_sql = "SELECT *
	                        FROM    202_rotators
	                        WHERE user_id='".$mysql['user_id']."'
	                        ORDER BY `id` ASC";
	        $rotator_result = $db->query($rotator_sql) or record_mysql_error($rotator_sql);

	        while ($rotator_row = $rotator_result->fetch_array(MYSQLI_ASSOC)) {
	            
	            $html['rotator_name'] = htmlentities((string)($rotator_row['name'] ?? ''), ENT_QUOTES, 'UTF-8');
	            $html['rotator_id'] = htmlentities((string)($rotator_row['id'] ?? ''), ENT_QUOTES, 'UTF-8');
	            
	            if ($_POST['rotator_id'] == $rotator_row['id']) {
	                $selected = 'selected=""';   
	            } else {
	                $selected = '';  
	            }   
	            
	            printf('<option %s value="%s">%s</option>', $selected, $html['rotator_id'],$html['rotator_name']);

	        } ?>
	</select>

<?php }


if (isset($_GET['autocomplete']) && isset($_GET['type']) && isset($_GET['query']) && $_GET['autocomplete'] == 'true') {

	header("Content-type: application/json; charset=utf-8");

	$data = rotator_data(urlencode($_GET['query']), $_GET['type']);
	print_r($data);
}


if (isset($_POST['post_rules']) && $_POST['post_rules'] == true && isset($_POST['data'])) {

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

				$rule_sql = "UPDATE 202_rotator_rules SET rotator_id='".$rotator_id."', rule_name='".$rule_name."', splittest='".$splittest."', status='".$status."' WHERE id='".$rule['rule_id']."'";
				$rule_result = $db->query($rule_sql);
				$rule_id = $rule['rule_id'];
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
						$rule_redirect_id = $db->real_escape_string($redirect['id']);
						$rule_redirect_sql = "UPDATE 202_rotator_rules_redirects SET rule_id='".$rule_id."', ".$redirect_sql." WHERE id = '".$rule_redirect_id."'";
						$rule_redirect_result = $db->query($rule_redirect_sql);
						$redirects_ids[] = $redirect['id'];
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
						$criteria_sql = "UPDATE 202_rotator_rules_criteria SET rotator_id='".$rotator_id."', rule_id='".$rule_id."', type='".$type."', statement='".$statement."', value='".$value."' WHERE id='".$criteria['criteria_id']."'";
						$criteria_result = $db->query($criteria_sql);
						$criteria_id[] = $criteria['criteria_id'];
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

			$redirects_ids = implode(', ', $redirects_ids);
			$delete_redirects_sql = "DELETE FROM 202_rotator_rules_redirects WHERE id NOT IN (".$redirects_ids.") AND rule_id = '".$rule_id."'";
			$delete_redirects_result = $db->query($delete_redirects_sql);

		}
	}

	$criteria_id = implode(', ', $criteria_id);
	$rules_id = implode(', ', $rules_id);
	
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
	$result = $db->query($sql);

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
	$result = $db->query($sql);

	if ($criteria_result == true) {
		echo "DONE";
	}

}

if (isset($_POST['rule_details']) && $_POST['rule_details'] == true) {
	$id = $db->real_escape_string((string)$_POST['rule_id']);
	$sql = "SELECT * FROM 
				 202_rotator_rules AS ru 
				 LEFT JOIN 202_rotator_rules_criteria AS cr ON ru.id = cr.rule_id
				 LEFT JOIN 202_rotator_rules_redirects AS rr ON ru.id = rr.rule_id
				 WHERE ru.id = '".$id."'";
	$result = $db->query($sql);
	?>

	<div class="row">
		<div class="col-xs-12">
		<span class="infotext">Here you can see criteria for rule.</span>
			<table class="table table-bordered" id="stats-table" style="margin-top: 10px;">
				<thead>
					<tr style="background-color: #f2fbfa;">   
						<th colspan="4" style="text-align:left">Rule criteria</th>
					</tr>
				</thead>
				<tbody>
				<?php 
				$redirects = [];
				$criteria_displayed = [];
				
				while ($row = $result->fetch_assoc()) {
					// Collect redirect information
					if (!empty($row['redirect_url']) || !empty($row['redirect_campaign']) || !empty($row['redirect_lp'])) {
						$redirect_key = $row['redirect_url'] . '|' . $row['redirect_campaign'] . '|' . $row['redirect_lp'];
						if (!isset($redirects[$redirect_key])) {
							$redirects[$redirect_key] = [
								'redirect_url' => $row['redirect_url'],
								'redirect_campaign' => $row['redirect_campaign'], 
								'redirect_lp' => $row['redirect_lp'],
								'weight' => $row['weight'] ?? null
							];
						}
					}

					// Show criteria (avoid duplicates)
					if (!empty($row['type'])) {
						$criteria_key = $row['type'] . '|' . $row['statement'] . '|' . $row['value'];
						if (!isset($criteria_displayed[$criteria_key])) {
							if ($row['statement'] == 'is') {
								$statement = 'is';
							} else {
								$statement = 'is not';
							}

							?>
							<tr>
								<td style="text-align:left; padding-left:10px;">If</td>
								<td style="text-align:left; padding-left:10px;"><?php echo ucfirst((string) $row['type'])?></td>
								<td style="text-align:left; padding-left:10px;"><?php echo $statement;?></td>
								<td style="text-align:left; padding-left:10px;"><?php echo $row['value'];?></td>
							</tr>
							<?php
							$criteria_displayed[$criteria_key] = true;
						}
					}
				} // Close while loop
				?>
				</tbody>
			</table>

			<div class="col-xs-12">
				<div class="row">
					<div class="form-group">
					    <label for="redirect_url" class="col-sm-3 control-label">Redirects to: </label>
					    <div class="col-sm-9">
					    <?php 
					    if (count($redirects) > 1) {
					    	echo '<div class="small" style="margin-top: 10px;"><strong>Split test with ' . count($redirects) . ' redirects:</strong></div>';
					    }
					    
					    foreach ($redirects as $redirect) {
					    	if (!empty($redirect['redirect_campaign'])) {
					    		// Get campaign name
					    		$redirect_campaign_sql = "SELECT aff_campaign_name FROM 202_aff_campaigns WHERE aff_campaign_id = '".$redirect['redirect_campaign']."'";
					    		$redirect_campaign_result = $db->query($redirect_campaign_sql);
					    		$redirect_campaign_row = $redirect_campaign_result->fetch_assoc();
					    		
					    		echo '<div class="small" style="margin-top: 5px;"><span class="label label-info"><i>' . 
					    		     ($redirect_campaign_row['aff_campaign_name'] ?? 'Campaign ID: ' . $redirect['redirect_campaign']) . 
					    		     '</i></span> campaign';
					    		if (!empty($redirect['weight'])) echo ' (weight: ' . $redirect['weight'] . ')';
					    		echo '</div>';
					    		
					    	} elseif (!empty($redirect['redirect_lp'])) {
					    		// Get landing page name  
					    		$redirect_lp_sql = "SELECT landing_page_nickname FROM 202_landing_pages WHERE landing_page_id = '".$redirect['redirect_lp']."' AND landing_page_deleted='0'";
					    		$redirect_lp_result = $db->query($redirect_lp_sql);
					    		$redirect_lp_row = $redirect_lp_result->fetch_assoc();
					    		
					    		echo '<div class="small" style="margin-top: 5px;"><span class="label label-success"><i>' . 
					    		     ($redirect_lp_row['landing_page_nickname'] ?? 'Landing Page ID: ' . $redirect['redirect_lp']) . 
					    		     '</i></span> landing page';
					    		if (!empty($redirect['weight'])) echo ' (weight: ' . $redirect['weight'] . ')';
					    		echo '</div>';
					    		
					    	} elseif (!empty($redirect['redirect_url'])) {
					    		echo '<div class="small" style="margin-top: 5px;"><span class="label label-default"><i>' . $redirect['redirect_url'] . '</i></span> URL';
					    		if (!empty($redirect['weight'])) echo ' (weight: ' . $redirect['weight'] . ')';
					    		echo '</div>';
					    	}
					    }
					    
					    if (empty($redirects)) {
					    	echo '<div class="small" style="margin-top: 10px; color: #999;">No redirect configured</div>';
					    }
					    ?>
					    </div>
					</div>
				</div>
				
			</div>
		</div>
	</div>

<?php }

if (isset($_POST['add_more_criteria']) && $_POST['add_more_criteria'] == true) { ?>
					<div class="criteria" id="criteria" data-criteria-id="none">
						<div class="form-group">
		    				<label for="rule_type">If</label>
							<select class="form-control input-sm" name="rule_type" style="margin: 0px 5px;">
								<option value="country">Country</option>
								<option value="region">State/Region</option>
								<option value="city">Cities</option>
								<option value="isp" <?php if(!$user_row['maxmind_isp']) echo "disabled";?>>ISP/Carrier</option>
								<option value="ip">IP Address</option>
								<option value="browser">Browser Name</option>
								<option value="platform">OS</option>
								<option value="device">Device Type</option>
							</select>
		  				</div>
					
						<div class="form-group">
							<label for="rule_statement"><i class="fa fa-angle-double-right"></i></label>
							<select class="form-control input-sm" name="rule_statement" style="margin: 0px 5px;">
								<option value="is">IS</option>
								<option value="is_not">IS NOT</option>
							</select>
						</div>

						<div class="form-group" id="tags_select">
							<label for="rule_value">equal to:</label>
							<input id="tag" class="value_select" name="value" placeholder="Type in country and hit Enter"/>
						</div>
						<div class="form-group">
							<a href="#remove_criteria" style="color: #34495e;"><span class="fui-cross" id="remove_criteria"></span></a>
						</div>
					</div>	
<?php }

if (isset($_POST['add_more_rules']) && $_POST['add_more_rules'] == true) { ?>
	<div class="col-xs-12 rule_added" style="margin-top:15px;">
		<div class="col-xs-12 rules" data-rule-id="none">
		<a href="#remove_rule" style="color: #34495e;"><span class="fui-cross" id="remove_rule"></span></a>
			<div class="row">
				<div class="col-xs-12">
					<div class="form-group">
						<label for="rule_name">Rule name: </label>
						<input class="form-control input-sm" name="rule_name" id="rule_name" placeholder="Type in rule name"/>
					</div>
					<div class="form-group inactive" style="float:right; margin-right: 25px;">
						<label class="checkbox" for="inactive" style="margin-bottom: 12px;padding-left: 32px;">
				            <input type="checkbox" id="inactive" name="inactive" data-toggle="checkbox">
				            Inactive
				        </label>
					</div>
					<div class="form-group splittest" style="float:right; margin-right: 25px;">
				        <label class="checkbox" for="splittest" style="margin-bottom: 12px;padding-left: 32px;">
				            <input type="checkbox" class="splittest-checkbox" id="splittest" name="splittest" data-toggle="checkbox">
				            Split test
				        </label>
					</div>
				</div>
			</div>

			<div class="row form_seperator" style="margin-top:10px; margin-bottom:10px;">
				<div class="col-xs-12" style="width: 97.5%;"></div>
			</div>	

			<div class="row">
					<div class="col-xs-10" id="criteria_container">
						<div class="criteria" id="criteria" data-criteria-id="none">
							<div class="form-group">
			    				<label for="rule_type">If</label>
								<select class="form-control input-sm" name="rule_type" style="margin: 0px 5px;">
									<option value="country">Country</option>
									<option value="region">State/Region</option>
									<option value="city">Cities</option>
									<option value="isp" <?php if(!$user_row['maxmind_isp']) echo "disabled";?>>ISP/Carrier</option>
									<option value="ip">IP Address</option>
									<option value="browser">Browser Name</option>
									<option value="platform">OS</option>
									<option value="device">Device Type</option>
								</select>
			  				</div>
						
							<div class="form-group">
								<label for="rule_statement"><i class="fa fa-angle-double-right"></i></label>
								<select class="form-control input-sm" name="rule_statement" style="margin: 0px 5px;">
									<option value="is">IS</option>
									<option value="is_not">IS NOT</option>
								</select>
							</div>

							<div class="form-group" id="tags_select">
								<label for="rule_value">equal to:</label>
								<input id="tag" class="value_select" name="value" placeholder="Type in country and hit Enter"/>
							</div>
						</div>
					</div>		
				<div class="col-xs-2" style="margin-left: -18px; margin-top: 10px;">
					<div class="form-group">
						<img id="addmore_criteria_loading" class="loading" src="<?php echo get_absolute_url();?>202-img/loader-small.gif" style="display:none; position: absolute; top: 4px; left: -20px;">
						<button id="add_more_criteria" class="btn btn-xs btn-default"><span class="fui-plus"></span> Add more criteria</button>
					</div>
				</div>
			</div>

			<div class="row form_seperator" style="margin-top:10px; margin-bottom:10px;">
				<div class="col-xs-12" style="width: 97.5%;"></div>
			</div>

			<div class="row" id="simple-redirect" data-redirect-id="none">
						<div class="col-xs-4" id="redirect_type_radio">
							<label for="redirect_type" style="margin-left: -15px;" class="col-xs-5 control-label">Redirects to: </label>
							<select class="form-control input-sm" name="redirect_type" id="redirect_type_select">
								<option value="campaign">Campaign</option>
								<option value="lp">Landing Page</option>
								<option value="url">Url</option>
								<!--<option value="monetizer">Auto Monetizer</option>-->
							</select>
						</div>

						<div class="col-xs-4" id="redirect_campaign_select" style="margin-left: -3%">
							<select class="form-control input-sm" name="redirect_campaign" style="width: 100%;">
								<option value="">select campaign</option>
								<?php 
									foreach ($campaigns as $campaign) { ?>
										<option value="<?php echo $campaign['id'];?>"><?php echo $campaign['name'];?></option>
								<?php } ?>
							</select>
						</div>

						<div class="col-xs-4" id="redirect_lp_select" style="display:none; margin-left: -3%">
							<select class="form-control input-sm" name="redirect_lp" style="width: 100%;">
								<option value="">select landing page</option>
								<?php 
									foreach ($lps as $lp) { ?>
										<option value="<?php echo $lp['id'];?>"><?php echo $lp['name']; if($lp['type']) echo " (advanced)"; else echo " (simple)";?></option>
								<?php } ?>
							</select>
						</div>

						<div class="col-xs-8" id="redirect_url_input" style="display:none; width: 67.5%; margin-left: -3%">	
							<div class="input-group input-group-sm">
								<span class="input-group-addon"><i class="fa fa-globe"></i></span>
								<input name="redirect_url" class="form-control" style="width: 150%;" type="text" placeholder="http://">
							</div>
						</div>

						<!--<div class="col-xs-8" id="redirect_monetizer" style="display:none; width: 67.5%; margin-left: -3%">	
							<span class="small">Auto Monetizer as redirect destination.</span>
						</div>-->
			</div>

			<div class="row" id="splittest-redirects" style="display:none">
					<div class="row" data-redirect-id="none">
						<div class="col-xs-4" id="redirect_type_radio">
							<label for="redirect_type" style="margin-left: -15px;" class="col-xs-5 control-label">Redirects to: </label>
							<select class="form-control input-sm" name="redirect_type" id="redirect_type_select">
								<option value="campaign">Campaign</option>
								<option value="lp">Landing Page</option>
								<option value="url">Url</option>
								<!--<option value="monetizer">Auto Monetizer</option>-->
							</select>
						</div>

						<div class="col-xs-4" id="redirect_campaign_select" style="margin-left: -3%">
							<select class="form-control input-sm" name="redirect_campaign" style="width: 100%;">
								<option value="">select campaign</option>
								<?php 
									foreach ($campaigns as $campaign) { ?>
										<option value="<?php echo $campaign['id'];?>"><?php echo $campaign['name'];?></option>
								<?php } ?>
							</select>
						</div>

						<div class="col-xs-4" id="redirect_lp_select" style="display:none; margin-left: -3%">
							<select class="form-control input-sm" name="redirect_lp" style="width: 100%;">
								<option value="">select landing page</option>
								<?php 
									foreach ($lps as $lp) { ?>
										<option value="<?php echo $lp['id'];?>"><?php echo $lp['name']; if($lp['type']) echo " (advanced)"; else echo " (simple)";?></option>
								<?php } ?>
							</select>
						</div>

						<div class="col-xs-4" id="redirect_url_input" style="display:none; margin-left: -3%">	
							<div class="input-group input-group-sm">
								<span class="input-group-addon"><i class="fa fa-globe"></i></span>
								<input name="redirect_url" class="form-control" style="width: 150%;" type="text" placeholder="http://">
							</div>
						</div>

						<!--<div class="col-xs-4" id="redirect_monetizer" style="display:none; margin-left: -3%">	
							<span class="small">Auto Monetizer as redirect destination.</span>
						</div>-->

						<div class="col-xs-2" id="split_weight">	
							<div class="input-group input-group-sm">
								<span class="input-group-addon"><i class="fa">weight</i></span>
								<input name="split_weight" class="form-control" type="text" placeholder="100">
							</div>
						</div>

						<div class="col-xs-2">
							<div class="form-group" style="  margin-left: 5px; margin-top: 8px;">
								<img id="addmore_redirects_loading" class="loading" src="<?php echo get_absolute_url();?>202-img/loader-small.gif" style="display:none; position: absolute; top: 4px; left: -20px;">
								<button id="add_more_redirects" class="btn btn-xs btn-default"><span class="fui-plus"></span> Add more redirects</button>
							</div>
						</div>
					</div>	
			</div>

		</div>
	</div>

	<script type="text/javascript">
		$(document).ready(function() {
			$('[data-toggle="checkbox"]').radiocheck();
			$('[name="redirect_campaign"]').select2();
			$('[name="redirect_lp"]').select2();
		});
	</script>

<?php } 

if (isset($_POST['rule_defaults']) && $_POST['rule_defaults'] == true && isset($_POST['rotator_id'])) { 

	$id = $db->real_escape_string((string)$_POST['rotator_id']);
	$rotator_sql = "SELECT * FROM 202_rotators WHERE id = '".$id."'";
	$rotator_result = $db->query($rotator_sql);

	if ($rotator_result->num_rows > 0) {
		$rotator_row = $rotator_result->fetch_assoc();
	} else {
		// Initialize with default values if no rotator found
		$rotator_row = [
			'default_campaign' => null,
			'default_lp' => null,
			'default_url' => null,
			'auto_monetizer' => null
		];
	} ?>
	
				<div class="col-xs-4">
					<label for="default_type" class="col-xs-5 control-label">Defaults to: </label>
					<select class="form-control input-sm" name="default_type" id="default_type_select">
						<option value="campaign" <?php if($rotator_row['default_campaign'] != null) echo "selected"; ?>>Campaign</option>
						<option value="lp" <?php if($rotator_row['default_lp'] != null) echo "selected"; ?>>Landing Page</option>
						<option value="url" <?php if($rotator_row['default_url'] != null) echo "selected"; ?>>Url</option>
						<!--<option value="monetizer" <?php if($rotator_row['auto_monetizer'] != null) echo "selected"; ?>>Auto Monetizer</option>-->
					</select>
				</div>

				<div class="col-xs-4" id="default_campaign_select" <?php if($rotator_row['default_campaign'] == null && $rotator_row['default_url'] == null && $rotator_row['default_lp'] == null && $rotator_row['auto_monetizer'] == null) {echo 'style="display:block;"';} elseif($rotator_row['default_campaign'] == null) {echo 'style="display:none;"';}?>>
					<select class="form-control input-sm" name="default_campaign" style="width: 100%;">
						<option value="">select campaign</option>
							<?php 
								foreach ($campaigns as $campaign) { 
									if ($rotator_row['default_campaign'] == $campaign['id']) { ?>
										<option value="<?php echo $campaign['id'];?>" selected><?php echo $campaign['name'];?></option>
									<?php } else { ?>
										<option value="<?php echo $campaign['id'];?>"><?php echo $campaign['name'];?></option>
								<?php } 
							} ?>
					</select>
				</div>

				<div class="col-xs-4" id="default_lp_select" style="margin-left: 0px; <?php if($rotator_row['default_lp'] == null) echo 'display:none;';?>">
					<select class="form-control input-sm" name="default_lp" style="width: 100%;">
						<option value="">select landing page</option>
						<?php 
							foreach ($lps as $lp) {
								if ($rotator_row['default_lp'] == $lp['id']) { ?>
									<option value="<?php echo $lp['id'];?>" selected><?php echo $lp['name']; if($lp['type']) echo " (advanced)"; else echo " (simple)";?></option>
								<?php } else { ?>
									<option value="<?php echo $lp['id'];?>"><?php echo $lp['name']; if($lp['type']) echo " (advanced)"; else echo " (simple)";?></option>
								<?php }
						} ?>
					</select>
				</div>

				<div class="col-xs-8" id="default_url_input" style="width: 40.3%; margin-left: -7%; <?php if($rotator_row['default_url'] == null) echo 'display:none;';?>">	
					<div class="input-group input-group-sm">
						<span class="input-group-addon"><i class="fa fa-globe"></i></span>
						<input name="default_url" class="form-control" type="text" style="width: 197%;" placeholder="http://" value="<?php echo $rotator_row['default_url'];?>">
					</div>
				</div>

				<script type="text/javascript">
					$(document).ready(function() {
						$("#default_type_select").select2();
						$('[name="default_campaign"]').select2();
						$('[name="default_lp"]').select2();
					});
				</script>

				<!--<div class="col-xs-8" id="default_monetizer" style="width: 40.3%; margin-left: -7%; margin-top: 5px; <?php if($rotator_row['auto_monetizer'] == null) echo 'display:none;';?>">	
					<span class="small">Auto Monetizer as redirect destination.</span>
				</div>-->

<?php }

if (isset($_POST['generate_rules']) && $_POST['generate_rules'] == true && isset($_POST['rotator_id'])) { 

	$id = $db->real_escape_string((string)$_POST['rotator_id']);
	$rotator_sql = "SELECT * FROM 202_rotators WHERE id = '".$id."'";
	$rotator_result = $db->query($rotator_sql);

	if ($rotator_result->num_rows > 0) {
		$rotator_row = $rotator_result->fetch_assoc();
	}

	$rule_sql = "SELECT * FROM 202_rotator_rules WHERE rotator_id = '".$id."'";
	$rule_result = $db->query($rule_sql);

	if ($rule_result->num_rows == 0) { $rand_rule_id = random_int(1000,100000); ?>
					<div class="col-xs-12" style="margin-top:15px;">
						<div class="col-xs-12 rules" data-rule-id="none" id="<?php echo $rand_rule_id;?>">
							<div class="row">
								<div class="col-xs-12">
									<div class="form-group">
										<label for="rule_name">Rule name: </label>
										<input class="form-control input-sm" name="rule_name" id="rule_name" placeholder="Type in rule name"/>
									</div>
									<div class="form-group inactive" style="float:right; margin-right: 25px;">
										<label class="checkbox" for="inactive_<?php echo $rand_rule_id;?>" style="margin-bottom: 12px;padding-left: 32px;">
								            <input type="checkbox" id="inactive_<?php echo $rand_rule_id;?>" name="inactive_<?php echo $rand_rule_id;?>" data-toggle="checkbox">
								            Inactive
								        </label>
									</div>
									<div class="form-group splittest" style="float:right; margin-right: 25px;">
								        <label class="checkbox" for="splittest_<?php echo $rand_rule_id;?>" style="margin-bottom: 12px;padding-left: 32px;">
								            <input type="checkbox" class="splittest-checkbox" id="splittest_<?php echo $rand_rule_id;?>" name="splittest_<?php echo $rand_rule_id;?>" data-toggle="checkbox">
								            Split test
								        </label>
									</div>
								</div>
							</div>

							<div class="row form_seperator" style="margin-top:10px; margin-bottom:10px;">
								<div class="col-xs-12" style="width: 97.5%;"></div>
							</div>	

							<div class="row">
									<div class="col-xs-10" id="criteria_container">
										<div class="criteria" id="criteria" data-criteria-id="none">
											<div class="form-group">
							    				<label for="rule_type">If</label>
												<select class="form-control input-sm" name="rule_type" style="margin: 0px 5px;">
													<option value="country">Country</option>
													<option value="region">State/Region</option>
													<option value="city">Cities</option>
													<option value="isp" <?php if(!$user_row['maxmind_isp']) echo "disabled";?>>ISP/Carrier</option>
													<option value="ip">IP Address</option>
													<option value="browser">Browser Name</option>
													<option value="platform">OS</option>
													<option value="device">Device Type</option>
												</select>
							  				</div>
										
											<div class="form-group">
												<label for="rule_statement"><i class="fa fa-angle-double-right"></i></label>
												<select class="form-control input-sm" name="rule_statement" style="margin: 0px 5px;">
													<option value="is">IS</option>
													<option value="is_not">IS NOT</option>
												</select>
											</div>

											<div class="form-group">
												<label for="rule_value">equal to:</label>
												<input id="tag" class="value_select" name="value" placeholder="Type in country and hit Enter"/>
											</div>
										</div>
									</div>	
								<div class="col-xs-2" style="margin-left: -18px; margin-top: 10px;">
									<div class="form-group">
										<img id="addmore_criteria_loading" class="loading" src="<?php echo get_absolute_url();?>202-img/loader-small.gif" style="display:none; position: absolute; top: 4px; left: -20px;">
										<button id="add_more_criteria" class="btn btn-xs btn-default"><span class="fui-plus"></span> Add more criteria</button>
									</div>
								</div>
							</div>

							<div class="row form_seperator" style="margin-top:10px; margin-bottom:10px;">
								<div class="col-xs-12" style="width: 97.5%;"></div>
							</div>

							<div class="row" id="simple-redirect" data-redirect-id="none">
										<div class="col-xs-4" id="redirect_type_radio">
											<label for="redirect_type" style="margin-left: -15px;" class="col-xs-5 control-label">Redirects to: </label>
											<select class="form-control input-sm" name="redirect_type" id="redirect_type_select">
												<option value="campaign">Campaign</option>
												<option value="lp">Landing Page</option>
												<option value="url">Url</option>
												<!--<option value="monetizer">Auto Monetizer</option>-->
											</select>
										</div>

										<div class="col-xs-4" id="redirect_campaign_select" style="margin-left: -3%">
											<select class="form-control input-sm" name="redirect_campaign" style="width: 100%;">
												<option value="">select campaign</option>
												<?php 
													foreach ($campaigns as $campaign) { ?>
														<option value="<?php echo $campaign['id'];?>"><?php echo $campaign['name'];?></option>
												<?php } ?>
											</select>
										</div>

										<div class="col-xs-4" id="redirect_lp_select" style="display:none; margin-left: -3%">
											<select class="form-control input-sm" name="redirect_lp" style="width: 100%;">
												<option value="">select landing page</option>
												<?php 
													foreach ($lps as $lp) { ?>
														<option value="<?php echo $lp['id'];?>"><?php echo $lp['name']; if($lp['type']) echo " (advanced)"; else echo " (simple)";?></option>
												<?php } ?>
											</select>
										</div>

										<div class="col-xs-8" id="redirect_url_input" style="display:none; width: 67.5%; margin-left: -3%">	
											<div class="input-group input-group-sm">
												<span class="input-group-addon"><i class="fa fa-globe"></i></span>
												<input name="redirect_url" class="form-control" style="width: 150%;" type="text" placeholder="http://">
											</div>
										</div>

										<!--<div class="col-xs-8" id="redirect_monetizer" style="display:none; width: 67.5%; margin-left: -3%">	
											<span class="small">Auto Monetizer as redirect destination.</span>
										</div>-->
							</div>

							<div class="row" id="splittest-redirects" style="display:none">
										<div class="row" data-redirect-id="none">
											<div class="col-xs-4" id="redirect_type_radio">
												<label for="redirect_type" style="margin-left: -15px;" class="col-xs-5 control-label">Redirects to: </label>
												<select class="form-control input-sm" name="redirect_type" id="redirect_type_select">
													<option value="campaign">Campaign</option>
													<option value="lp">Landing Page</option>
													<option value="url">Url</option>
													<!--<option value="monetizer">Auto Monetizer</option>-->
												</select>
											</div>

											<div class="col-xs-4" id="redirect_campaign_select" style="margin-left: -3%">
												<select class="form-control input-sm" name="redirect_campaign" style="width: 100%;">
													<option value="">select campaign</option>
													<?php 
														foreach ($campaigns as $campaign) { ?>
															<option value="<?php echo $campaign['id'];?>"><?php echo $campaign['name'];?></option>
													<?php } ?>
												</select>
											</div>

											<div class="col-xs-4" id="redirect_lp_select" style="display:none; margin-left: -3%">
												<select class="form-control input-sm" name="redirect_lp" style="width: 100%;">
													<option value="">select landing page</option>
													<?php 
														foreach ($lps as $lp) { ?>
															<option value="<?php echo $lp['id'];?>"><?php echo $lp['name']; if($lp['type']) echo " (advanced)"; else echo " (simple)";?></option>
													<?php } ?>
												</select>
											</div>

											<div class="col-xs-4" id="redirect_url_input" style="display:none; margin-left: -3%">	
												<div class="input-group input-group-sm">
													<span class="input-group-addon"><i class="fa fa-globe"></i></span>
													<input name="redirect_url" class="form-control" style="width: 150%;" type="text" placeholder="http://">
												</div>
											</div>

											<!--<div class="col-xs-4" id="redirect_monetizer" style="display:none; margin-left: -3%">	
												<span class="small">Auto Monetizer as redirect destination.</span>
											</div>-->

											<div class="col-xs-2" id="split_weight">	
												<div class="input-group input-group-sm">
													<span class="input-group-addon"><i class="fa">weight</i></span>
													<input name="split_weight" class="form-control" type="text" placeholder="100">
												</div>
											</div>

											<div class="col-xs-2">
												<div class="form-group" style="  margin-left: 5px; margin-top: 8px;">
													<img id="addmore_redirects_loading" class="loading" src="<?php echo get_absolute_url();?>202-img/loader-small.gif" style="display:none; position: absolute; top: 4px; left: -20px;">
													<button id="add_more_redirects" class="btn btn-xs btn-default"><span class="fui-plus"></span> Add more redirects</button>
												</div>
											</div>
										</div>
							</div>

						</div>
					</div>	
					
					<script type="text/javascript">
						$(document).ready(function() {
							rotator_tags_autocomplete('tag', 'country');
							$('[data-toggle="checkbox"]').radiocheck();
							$('[name="redirect_campaign"]').select2();
							$('[name="redirect_lp"]').select2();
						});
					</script>

	<?php } elseif ($rule_result->num_rows > 0) {
		$count = 0;
		while ($rule_row = $rule_result->fetch_assoc()) { $count++;?>
					<div class="col-xs-12" style="margin-top:15px;">
						<div class="col-xs-12 rules" data-rule-id="<?php echo $rule_row['id'];?>">
						<?php if($count >= 2) { ?>
							<?php if ($userObj->hasPermission("remove_rotator_rule")) { ?>
								<a href="#remove_rule" style="color: #34495e;"><span class="fui-cross" id="remove_rule"></span></a>
							<?php } ?>	
						<?php } ?>
							<div class="row">
								<div class="col-xs-12">
									<div class="form-group">
										<label for="rule_name">Rule name: </label>
										<input class="form-control input-sm" name="rule_name_<?php echo $rule_row['id'];?>" id="rule_name" placeholder="Type in rule name" value="<?php echo $rule_row['rule_name'];?>"/>
									</div>
									<div class="form-group inactive" style="float:right; margin-right: 25px;">
										<label class="checkbox" for="inactive_<?php echo $rule_row['id'];?>" style="margin-bottom: 12px;padding-left: 32px;">
								            <input type="checkbox" id="inactive_<?php echo $rule_row['id'];?>" name="inactive_<?php echo $rule_row['id'];?>" data-toggle="checkbox" <?php if($rule_row['status'] == false) echo "checked"?>>
								            Inactive
								        </label>
									</div>
									<div class="form-group splittest" style="float:right; margin-right: 25px;">
								        <label class="checkbox" for="splittest_<?php echo $rule_row['id'];?>" style="margin-bottom: 12px;padding-left: 32px;">
								            <input type="checkbox" class="splittest-checkbox" id="splittest_<?php echo $rule_row['id'];?>" name="splittest_<?php echo $rule_row['id'];?>" data-toggle="checkbox" <?php if ($rule_row['splittest']) echo "checked";?>>
								            Split test
								        </label>
									</div>
								</div>
							</div>

							<div class="row form_seperator" style="margin-top:10px; margin-bottom:10px;">
								<div class="col-xs-12" style="width: 97.5%;"></div>
							</div>	

							<div class="row">
									<div class="col-xs-10" id="criteria_container">
									<?php
										$criteria_sql = "SELECT * FROM 202_rotator_rules_criteria WHERE rule_id = '".$rule_row['id']."'";
										$criteria_result = $db->query($criteria_sql);

										if ($criteria_result->num_rows == 0) { ?>
											<div class="criteria" id="criteria" data-criteria-id="none">
												<div class="form-group">
								    				<label for="rule_type">If</label>
													<select class="form-control input-sm" name="rule_type" style="margin: 0px 5px;">
														<option value="country">Country</option>
														<option value="region">State/Region</option>
														<option value="city">Cities</option>
														<option value="isp" <?php if(!$user_row['maxmind_isp']) echo "disabled";?>>ISP/Carrier</option>
														<option value="ip">IP Address</option>
														<option value="browser">Browser Name</option>
														<option value="platform">OS</option>
														<option value="device">Device Type</option>
													</select>
								  				</div>
											
												<div class="form-group">
													<label for="rule_statement"><i class="fa fa-angle-double-right"></i></label>
													<select class="form-control input-sm" name="rule_statement" style="margin: 0px 5px;">
														<option value="is">IS</option>
														<option value="is_not">IS NOT</option>
													</select>
												</div>

												<div class="form-group">
													<label for="rule_value">equal to:</label>
													<input id="tag_<?php echo $criteria_row['id'];?>" class="value_select" name="value" placeholder="Type in country and hit Enter"/>
												</div>

											</div>

											<script type="text/javascript">
												$(document).ready(function() {
													rotator_tags_autocomplete('tag_<?php echo $criteria_row['id'];?>', 'country');	
												});
											</script>

										<?php } elseif ($criteria_result->num_rows > 0) { 
											$criteria_count = 0;
											
												while ($criteria_row = $criteria_result->fetch_assoc()) { $criteria_count++;?>
													<div class="criteria" id="criteria" data-criteria-id="<?php echo $criteria_row['id'];?>">
														<div class="form-group">
										    				<label for="rule_type">If</label>
															<select class="form-control input-sm" name="rule_type" style="margin: 0px 5px;">
																<option value="country" <?php if($criteria_row['type'] == 'country') echo "selected";?>>Country</option>
																<option value="region" <?php if($criteria_row['type'] == 'region') echo "selected";?>>State/Region</option>
																<option value="city" <?php if($criteria_row['type'] == 'city') echo "selected";?>>Cities</option>
																<option value="isp" <?php if($criteria_row['type'] == 'isp') echo "selected";?> <?php if(!$user_row['maxmind_isp']) echo "disabled";?>>ISP/Carrier</option>
																<option value="ip" <?php if($criteria_row['type'] == 'ip') echo "selected";?>>IP Address</option>
																<option value="browser" <?php if($criteria_row['type'] == 'browser') echo "selected";?>>Browser Name</option>
																<option value="platform" <?php if($criteria_row['type'] == 'platform') echo "selected";?>>OS</option>
																<option value="device" <?php if($criteria_row['type'] == 'device') echo "selected";?>>Device Type</option>
															</select>
										  				</div>
													
														<div class="form-group">
															<label for="rule_statement"><i class="fa fa-angle-double-right"></i></label>
															<select class="form-control input-sm" name="rule_statement" style="margin: 0px 5px;">
																<option value="is" <?php if($criteria_row['statement'] == 'is') echo "selected";?>>IS</option>
																<option value="is_not" <?php if($criteria_row['statement'] == 'is_not') echo "selected";?>>IS NOT</option>
															</select>
														</div>

														<div class="form-group">
															<label for="rule_value">equal to:</label>
															<input id="tag_<?php echo $criteria_row['id'];?>" class="value_select" name="value" placeholder="Type in country and hit Enter"/>
														</div>
														<?php if($criteria_count >= 2) { ?>
															<?php if ($userObj->hasPermission("remove_rotator_criteria")) { ?>
																<div class="form-group">
																	<a href="#remove_criteria" style="color: #34495e;"><span class="fui-cross" id="remove_criteria"></span></a>
																</div>
															<?php } ?>	
														<?php } ?>
													</div>
													<script type="text/javascript">
														$(document).ready(function() {
															<?php if($criteria_row['type'] == 'ip') { ?>
																rotator_tags_autocomplete_ip("tag_<?php echo $criteria_row['id'];?>");
															<?php } elseif ($criteria_row['type'] == 'device') { ?>
																rotator_tags_autocomplete_devices("tag_<?php echo $criteria_row['id'];?>");
															<?php } elseif($criteria_row['type'] == 'country') { 
																$data = explode(',', (string) $criteria_row['value']);
																$country = [];
																	foreach ($data as $value) {
																		$country[] = ['value' => $value, 'label' => substr($value, 0, strpos($value, '('))]; 
																	} ?>
																rotator_tags_autocomplete("tag_<?php echo $criteria_row['id'];?>", "<?php echo $criteria_row['type'];?>");
																
															<?php } else { ?>
																rotator_tags_autocomplete("tag_<?php echo $criteria_row['id'];?>", "<?php echo $criteria_row['type'];?>");
															<?php } 

															if(isset($rule_row['type']) && $rule_row['type'] == 'country') { ?>
																$("#tag_<?php echo $criteria_row['id'];?>").tokenfield("setTokens", <?php print_r(json_encode($country));?>);
															<?php } else { ?>
																$("#tag_<?php echo $criteria_row['id'];?>").tokenfield("setTokens", "<?php echo $criteria_row['value'];?>");
															<?php }?>	
														});
													</script>
												<?php } ?>
											
										<?php }
									?>
										
									</div>	
								<div class="col-xs-2" style="margin-left: -18px; margin-top: 10px;">
									<div class="form-group">
										<img id="addmore_criteria_loading" class="loading" src="<?php echo get_absolute_url();?>202-img/loader-small.gif" style="display:none; position: absolute; top: 4px; left: -20px;">
										<button id="add_more_criteria" class="btn btn-xs btn-default"><span class="fui-plus"></span> Add more criteria</button>
									</div>
								</div>
							</div>

							<div class="row form_seperator" style="margin-top:10px; margin-bottom:10px;">
								<div class="col-xs-12" style="width: 97.5%;"></div>
							</div>

							<?php 

							$redirects_sql = "SELECT * FROM 202_rotator_rules_redirects WHERE rule_id = '".$rule_row['id']."'";
							$redirects_result = $db->query($redirects_sql);

							$criteria_row = [];

							if ($rule_row['splittest'] == 0) { 
								$redirects_row = $redirects_result->fetch_assoc();
							} else {
								// For split tests, initialize empty redirects_row to prevent undefined variable errors
								$redirects_row = [
									'id' => null,
									'redirect_campaign' => null,
									'redirect_lp' => null, 
									'redirect_url' => null,
									'auto_monetizer' => null
								];
							}

							?>

							<div class="row" id="simple-redirect" data-redirect-id="<?php if($rule_row['splittest'] == 0) echo $redirects_row['id']; else echo 'none';?>" <?php if ($rule_row['splittest']) echo 'style="display:none"';?>>
										<div class="col-xs-4" id="redirect_type_radio">
											<label for="redirect_type" style="margin-left: -15px;" class="col-xs-5 control-label">Redirects to: </label>
											<select class="form-control input-sm" name="redirect_type" id="redirect_type_select">
												<option value="campaign" <?php if($redirects_row['redirect_campaign'] != null) echo "selected"; ?>>Campaign</option>
												<option value="lp" <?php if($redirects_row['redirect_lp'] != null) echo "selected"; ?>>Landing Page</option>
												<option value="url" <?php if($redirects_row['redirect_url'] != null) echo "selected"; ?>>Url</option>
												<!--<option value="monetizer" <?php if($redirects_row['auto_monetizer'] != null) echo "selected"; ?>>Auto Monetizer</option>-->
											</select>
										</div>

										<div class="col-xs-4" id="redirect_campaign_select" style="margin-left: -3%; <?php if($rule_row['splittest'] != true){if($redirects_row['redirect_campaign'] == null) echo 'display:none';}?>">
											<select class="form-control input-sm" name="redirect_campaign" style="width: 100%;">
												<option value="">select campaign</option>
												<?php 
												  	foreach ($campaigns as $campaign) { 
												  		if ($redirects_row['redirect_campaign'] == $campaign['id']) { ?>
												  			<option value="<?php echo $campaign['id'];?>" selected><?php echo $campaign['name'];?></option>
												  		<?php } else { ?>
												  			<option value="<?php echo $campaign['id'];?>"><?php echo $campaign['name'];?></option>
												  		<?php } 
												  } ?>
											</select>
										</div>

										<div class="col-xs-4" id="redirect_lp_select" style="margin-left: -3%; <?php if($redirects_row['redirect_lp'] == null) echo 'display:none';?>">
											<select class="form-control input-sm" name="redirect_lp" style="width: 100%;">
												<option value="">select landing page</option>
												<?php 
													foreach ($lps as $lp) {
														if ($redirects_row['redirect_lp'] == $lp['id']) { ?>
															<option value="<?php echo $lp['id'];?>" selected><?php echo $lp['name']; if($lp['type']) echo " (advanced)"; else echo " (simple)";?></option>
												  		<?php } else { ?>
												  			<option value="<?php echo $lp['id'];?>"><?php echo $lp['name']; if($lp['type']) echo " (advanced)"; else echo " (simple)";?></option>
												  		<?php }
												} ?>
											</select>
										</div>

										<div class="col-xs-8" id="redirect_url_input" style="width: 67.5%; margin-left: -3%; <?php if($redirects_row['redirect_url'] == null) echo 'display:none';?>">	
											<div class="input-group input-group-sm">
												<span class="input-group-addon"><i class="fa fa-globe"></i></span>
												<input name="redirect_url" class="form-control" style="width: 150%;" type="text" placeholder="http://" value="<?php echo $redirects_row['redirect_url'];?>">
											</div>
										</div>

										<!--<div class="col-xs-8" id="redirect_monetizer" style="width: 67.5%; margin-left: -3%; <?php if($redirects_row['auto_monetizer'] == null) echo 'display:none';?>">	
											<span class="small">Auto Monetizer as redirect destination.</span>
										</div>-->
							</div>

							<div class="row" id="splittest-redirects" <?php if (!$rule_row['splittest']) echo 'style="display:none"';?>>

							<?php 
							if ($rule_row['splittest'] != 0) {
								$redirect_counter = 0;
								while ($redirects_row = $redirects_result->fetch_assoc()) { $redirect_counter++; ?>
							
								<div class="row" data-redirect-id="<?php echo $redirects_row['id'];?>" style="margin-top:5px">
									<div class="col-xs-4" id="redirect_type_radio">
										<label for="redirect_type" style="margin-left: -15px;" class="col-xs-5 control-label"><?php if($redirect_counter == 1) { ?>Redirects to: <?php } ?></label>
										<select class="form-control input-sm" name="redirect_type" id="redirect_type_select">
											<option value="campaign" <?php if($redirects_row['redirect_campaign'] != null) echo "selected"; ?>>Campaign</option>
											<option value="lp" <?php if($redirects_row['redirect_lp'] != null) echo "selected"; ?>>Landing Page</option>
											<option value="url" <?php if($redirects_row['redirect_url'] != null) echo "selected"; ?>>Url</option>
											<!--<option value="monetizer" <?php if($redirects_row['auto_monetizer'] != null) echo "selected"; ?>>Auto Monetizer</option>-->
										</select>
									</div>

									<div class="col-xs-4" id="redirect_campaign_select" style="margin-left: -3%; <?php if($redirects_row['redirect_campaign'] == null) echo 'display:none';?>">
										<select class="form-control input-sm" name="redirect_campaign" style="width: 100%;">
											<option value="">select campaign</option>
											<?php 
												  	foreach ($campaigns as $campaign) { 
												  		if ($redirects_row['redirect_campaign'] == $campaign['id']) { ?>
												  			<option value="<?php echo $campaign['id'];?>" selected><?php echo $campaign['name'];?></option>
												  		<?php } else { ?>
												  			<option value="<?php echo $campaign['id'];?>"><?php echo $campaign['name'];?></option>
												  		<?php } 
												  } ?>
										</select>
									</div>

									<div class="col-xs-4" id="redirect_lp_select" style="margin-left: -3%; <?php if($redirects_row['redirect_lp'] == null) echo 'display:none';?>">
										<select class="form-control input-sm" name="redirect_lp" style="width: 100%;">
											<option value="">select landing page</option>
											<?php 
													foreach ($lps as $lp) {
														if ($redirects_row['redirect_lp'] == $lp['id']) { ?>
															<option value="<?php echo $lp['id'];?>" selected><?php echo $lp['name']; if($lp['type']) echo " (advanced)"; else echo " (simple)";?></option>
												  		<?php } else { ?>
												  			<option value="<?php echo $lp['id'];?>"><?php echo $lp['name']; if($lp['type']) echo " (advanced)"; else echo " (simple)";?></option>
												  		<?php }
												} ?>
										</select>
									</div>

									<div class="col-xs-4" id="redirect_url_input" style="margin-left: -3%; <?php if($redirects_row['redirect_url'] == null) echo 'display:none';?>">	
										<div class="input-group input-group-sm">
											<span class="input-group-addon"><i class="fa fa-globe"></i></span>
											<input name="redirect_url" class="form-control" style="width: 150%;" type="text" placeholder="http://" value="<?php echo $redirects_row['redirect_url'];?>">
										</div>
									</div>

									<!--<div class="col-xs-4" id="redirect_monetizer" style="margin-left: -3%; <?php if($redirects_row['auto_monetizer'] == null) echo 'display:none';?>">	
										<span class="small">Auto Monetizer as redirect destination.</span>
									</div>-->

									<div class="col-xs-2" id="split_weight">	
										<div class="input-group input-group-sm">
											<span class="input-group-addon"><i class="fa">weight</i></span>
											<input name="split_weight" class="form-control" type="text" placeholder="100" value="<?php echo $redirects_row['weight'];?>">
										</div>
									</div>
									<?php if ($redirect_counter > 1) { ?>
										<span class="fui-cross" id="remove_redirect"></span>
									<?php } ?>

									<?php if($redirect_counter == 1) { ?>
										<div class="col-xs-2">
											<div class="form-group" style="  margin-left: 5px; margin-top: 8px;">
												<img id="addmore_redirects_loading" class="loading" src="<?php echo get_absolute_url();?>202-img/loader-small.gif" style="display:none; position: absolute; top: 4px; left: -20px;">
												<button id="add_more_redirects" class="btn btn-xs btn-default"><span class="fui-plus"></span> Add more redirects</button>
											</div>
										</div>
									<?php } ?>
								</div>
							<?php } 
							} else { ?>
								<div class="row" data-redirect-id="none">
									<div class="col-xs-4" id="redirect_type_radio">
										<label for="redirect_type" style="margin-left: -15px;" class="col-xs-5 control-label">Redirects to: </label>
										<select class="form-control input-sm" name="redirect_type" id="redirect_type_select">
											<option value="campaign">Campaign</option>
											<option value="lp">Landing Page</option>
											<option value="url">Url</option>
											<!--<option value="monetizer">Auto Monetizer</option>-->
										</select>
									</div>

									<div class="col-xs-4" id="redirect_campaign_select" style="margin-left: -3%">
										<select class="form-control input-sm" name="redirect_campaign" style="width: 100%;">
											<option value="">select campaign</option>
											<?php 
												foreach ($campaigns as $campaign) { ?>
													<option value="<?php echo $campaign['id'];?>"><?php echo $campaign['name'];?></option>
											<?php } ?>
										</select>
									</div>

									<div class="col-xs-4" id="redirect_lp_select" style="display:none; margin-left: -3%">
										<select class="form-control input-sm" name="redirect_lp" style="width: 100%;">
											<option value="">select landing page</option>
											<?php 
												foreach ($lps as $lp) { ?>
													<option value="<?php echo $lp['id'];?>"><?php echo $lp['name']; if($lp['type']) echo " (advanced)"; else echo " (simple)";?></option>
											<?php } ?>
										</select>
									</div>

									<div class="col-xs-4" id="redirect_url_input" style="display:none; margin-left: -3%">	
										<div class="input-group input-group-sm">
											<span class="input-group-addon"><i class="fa fa-globe"></i></span>
											<input name="redirect_url" class="form-control" style="width: 150%;" type="text" placeholder="http://">
										</div>
									</div>

									<!--<div class="col-xs-4" id="redirect_monetizer" style="display:none; margin-left: -3%">	
										<span class="small">Auto Monetizer as redirect destination.</span>
									</div>-->

									<div class="col-xs-2" id="split_weight">	
										<div class="input-group input-group-sm">
											<span class="input-group-addon"><i class="fa">weight</i></span>
											<input name="split_weight" class="form-control" type="text" placeholder="100">
										</div>
									</div>

									<div class="col-xs-2">
										<div class="form-group" style="  margin-left: 5px; margin-top: 8px;">
											<img id="addmore_redirects_loading" class="loading" src="<?php echo get_absolute_url();?>202-img/loader-small.gif" style="display:none; position: absolute; top: 4px; left: -20px;">
											<button id="add_more_redirects" class="btn btn-xs btn-default"><span class="fui-plus"></span> Add more redirects</button>
										</div>
									</div>
								</div>
							<?php } ?>	
							</div>

						</div>
					</div>	
					
					
		<?php } ?>

					<script type="text/javascript">
					$(document).ready(function() {
						$('[data-toggle="checkbox"]').radiocheck();
						$('[name="redirect_campaign"]').select2();
						$('[name="redirect_lp"]').select2();
					});
					</script>
	<?php }
	

} ?>
