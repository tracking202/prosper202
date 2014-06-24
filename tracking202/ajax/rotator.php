<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();

$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
$user_sql = "SELECT maxmind_isp FROM 202_users_pref WHERE user_id='".$mysql['user_id']."'";
$user_result = $db->query($user_sql);
$user_row = $user_result->fetch_assoc();

	$campaigns_sql = "SELECT aff_campaign_id, aff_campaign_name FROM 202_aff_campaigns WHERE user_id = '".$mysql['user_id']."' AND `aff_campaign_deleted`=0";
	$campaigns_result = $db->query($campaigns_sql);
	$campaigns = array();

	if ($campaigns_result->num_rows > 0) {
		while ($campaigns_row = $campaigns_result->fetch_assoc()) {
			$campaigns[] = array('id' => $campaigns_row['aff_campaign_id'], 'name' => $campaigns_row['aff_campaign_name']);
		}
	}

if (isset($_POST['get_rotators']) && isset($_POST['rotator_id']) && $_POST['get_rotators'] == true) { ?>
	
	<select class="form-control input-sm" name="tracker_rotator">
	    <option value=""> -- </option>
		<?
			$rotator_sql = "SELECT *
	                        FROM    202_rotators
	                        WHERE user_id='".$mysql['user_id']."'
	                        ORDER BY `id` ASC";
	        $rotator_result = $db->query($rotator_sql) or record_mysql_error($rotator_sql);

	        while ($rotator_row = $rotator_result->fetch_array(MYSQL_ASSOC)) {
	            
	            $html['rotator_name'] = htmlentities($rotator_row['name'], ENT_QUOTES, 'UTF-8');
	            $html['rotator_id'] = htmlentities($rotator_row['id'], ENT_QUOTES, 'UTF-8');
	            
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

	$data = rotator_data($_GET['query'], $_GET['type']);
	print_r($data);
}


if (isset($_POST['post_rules']) && $_POST['post_rules'] == true && isset($_POST['data'])) {

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
	}


	$rotator_id = $db->real_escape_string($_POST['rotator_id']);
	$defaults = $db->real_escape_string($_POST['defaults']);

	if ($_POST['default_type'] == 'campaign') {
		$default_sql = "default_campaign='".$defaults."', default_url=null";
	} else if($_POST['default_type'] == 'url') {
		$default_sql = "default_url='".$defaults."', default_campaign=null";
	}

	$sql = "UPDATE 202_rotators SET ".$default_sql." WHERE id='".$rotator_id."'";
	$result = $db->query($sql);

	if ($result) {
		$rules_id = array();
		$criteria_id = array();

		foreach ($_POST['data'] as $rule) {

			$rule_name = $db->real_escape_string($rule['rule_name']);
			if ($rule['status'] == 'active') {$status = 1;} else {$status = 0;}

			$redirects = $db->real_escape_string($rule['redirects']);

			if ($rule['redirect_type'] == 'campaign') {
				$redirect_sql = "redirect_campaign='".$redirects."', redirect_url=null";
			} else if($rule['redirect_type'] == 'url') {
				$redirect_sql = "redirect_url='".$redirects."', redirect_campaign=null";
			}

			if ($rule['rule_id'] != 'none') {
				$rule_sql = "UPDATE 202_rotator_rules SET rotator_id='".$rotator_id."', rule_name='".$rule_name."', status='".$status."', ".$redirect_sql." WHERE id='".$rule['rule_id']."'";
				$rule_result = $db->query($rule_sql);
				$rule_id = $rule['rule_id'];
				$rules_id[] = $rule_id;
			} else {
				$rule_sql = "INSERT INTO 202_rotator_rules SET rotator_id='".$rotator_id."', rule_name='".$rule_name."', status='".$status."', ".$redirect_sql."";
				$rule_result = $db->query($rule_sql);
				$rule_id = $db->insert_id;
				$rules_id[] = $rule_id;
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
						$criteria_id[] = $db->insert_id;
					}
				}
			}

		}
	}

	$criteria_id = implode(', ', $criteria_id);
	$rules_id = implode(', ', $rules_id);
	$sql = "DELETE FROM `202_rotator_rules` WHERE `id` NOT IN (".$rules_id.") AND rotator_id='".$rotator_id."'";
	$result = $db->query($sql);
	$sql = "DELETE FROM `202_rotator_rules_criteria` WHERE `id` NOT IN (".$criteria_id.") AND rotator_id='".$rotator_id."'";
	$result = $db->query($sql);

	if ($criteria_result == true) {
		echo "DONE";
	}

}

if (isset($_POST['rule_details']) && $_POST['rule_details'] == true) {
	$id = $db->real_escape_string($_POST['rule_id']);
	$sql = "SELECT * FROM 
				 202_rotator_rules AS ru 
				 LEFT JOIN 202_rotator_rules_criteria AS cr ON ru.id = cr.rule_id
				 WHERE ru.id = '".$id."'";
	$result = $db->query($sql);?>

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
				<?php while ($row = $result->fetch_assoc()) {

					$redirect_url = $row['redirect_url'];
					$redirect_campaign = $row['redirect_campaign'];

					if ($row['statement'] == 'is') {
						$statement = 'is';
					} else {
						$statement = 'is not';
					}

					?>
					<tr>
						<td style="text-align:left; padding-left:10px;">If</td>
						<td style="text-align:left; padding-left:10px;"><?php echo ucfirst($row['type'])?></td>
						<td style="text-align:left; padding-left:10px;"><?php echo $statement;?></td>
						<td style="text-align:left; padding-left:10px;"><?php echo $row['value'];?></td>
					</tr>

				<?php }

				if ($redirect_campaign != null) {
					$redirect_campaign_sql = "SELECT aff_campaign_name FROM 202_aff_campaigns WHERE aff_campaign_id = '".$redirect_campaign."'";
					$redirect_campaign_result = $db->query($redirect_campaign_sql);
					$redirect_campaign_row = $redirect_campaign_result->fetch_assoc();
				}
				?>
				</tbody>
			</table>

			<div class="col-xs-12">
				<div class="row">
					<div class="form-group">
					    <label for="redirect_url" class="col-sm-3 control-label">Redirects to: </label>
					    <div class="col-sm-9">
					    <?php if($redirect_campaign != null) { ?>
					    	<div class="small" style="margin-top: 10px;"><span class="label label-info"><i><?php echo $redirect_campaign_row['aff_campaign_name'];?></i></span> campaign</div>
					    <?php } else { ?>
					      	<input style="color: #34495e" class="form-control input-sm" type="text" value="<?php echo $redirect_url;?>" readonly>
						<?php } ?>
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
					<div class="form-group" style="float:right; margin-right: 25px;">
						<label class="checkbox" for="inactive" style="margin-bottom: 12px;padding-left: 32px;">
				            <input type="checkbox" id="inactive" name="inactive" data-toggle="checkbox">
				            Inactive
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
						<img id="addmore_criteria_loading" class="loading" src="/202-img/loader-small.gif" style="display:none; position: absolute; top: 4px; left: -20px;">
						<button id="add_more_criteria" class="btn btn-xs btn-default"><span class="fui-plus"></span> Add more criteria</button>
					</div>
				</div>
			</div>

			<div class="row form_seperator" style="margin-top:10px; margin-bottom:10px;">
				<div class="col-xs-12" style="width: 97.5%;"></div>
			</div>

			<div class="row">
						<div class="col-xs-4" id="redirect_type_radio">
							<label for="redirect_type" style="margin-left: -15px;" class="col-xs-5 control-label">Redirects to: </label>
							<label class="radio radio-inline">
								<input type="radio" value="campaign" data-toggle="radio" checked="">
								Campaign
							</label>
								
							<label class="radio radio-inline">
								<input type="radio" value="url" data-toggle="radio">
								URL
							</label>
						</div>

						<div class="col-xs-4" id="redirect_campaign_select">
							<select class="form-control input-sm" name="redirect_campaign" style="width: 100%;">
								<option value="">--</option>
								<?php 
									foreach ($campaigns as $campaign) { ?>
										<option value="<?php echo $campaign['id'];?>"><?php echo $campaign['name'];?></option>
								<?php } ?>
							</select>
						</div>
						<div class="col-xs-8" id="redirect_url_input" style="display:none; width: 64.5%;">	
							<div class="input-group input-group-sm">
									<span class="input-group-addon"><i class="fa fa-globe"></i></span>
									<input name="redirect_url" class="form-control" type="text" placeholder="http://">
							</div>
			</div>

		</div>
	</div>

	<script type="text/javascript">
		$(document).ready(function() {
			$('[data-toggle="radio"]').radio();
			$('[data-toggle="checkbox"]').checkbox();
		});
	</script>

<?php } 

if (isset($_POST['rule_defaults']) && $_POST['rule_defaults'] == true && isset($_POST['rotator_id'])) { 

	$id = $db->real_escape_string($_POST['rotator_id']);
	$rotator_sql = "SELECT * FROM 202_rotators WHERE id = '".$id."'";
	$rotator_result = $db->query($rotator_sql);

	if ($rotator_result->num_rows > 0) {
		$rotator_row = $rotator_result->fetch_assoc();
	} ?>
	
				<div class="col-xs-4" id="defaults_type_radio">
					<label for="default_type" class="col-xs-5 control-label">Defaults to: </label>
					<label class="radio radio-inline">
						<input type="radio" name="default_type" id="default_type1" value="campaign" data-toggle="radio" <?php if($rotator_row['default_campaign'] == null && $rotator_row['default_url'] == null) {echo 'checked';} elseif($rotator_row['default_campaign'] != null) {echo 'checked';}?>>
						Campaign
					</label>
						
					<label class="radio radio-inline">
						<input type="radio" name="default_type" id="default_type2" value="url" data-toggle="radio" <?php if($rotator_row['default_url'] != null) echo "checked";?>>
						URL
					</label>
				</div>

				<div class="col-xs-4" id="default_campaign_select" <?php if($rotator_row['default_campaign'] == null && $rotator_row['default_url'] == null) {echo 'style="display:block;"';} elseif($rotator_row['default_campaign'] == null) {echo 'style="display:none;"';}?>>
					<select class="form-control input-sm" name="default_campaign" style="width: 100%;">
						<option value="">--</option>
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
				<div class="col-xs-4" id="default_url_input" <?php if($rotator_row['default_url'] == null) echo 'style="display:none;"';?>>	
					<div class="input-group input-group-sm">
							<span class="input-group-addon"><i class="fa fa-globe"></i></span>
							<input name="default_url" class="form-control" type="text" placeholder="http://" value="<?php echo $rotator_row['default_url'];?>">
					</div>
				</div>

				<script type="text/javascript">
					$(document).ready(function() {
						$('[data-toggle="radio"]').radio();
					});
				</script>

<?php }

if (isset($_POST['generate_rules']) && $_POST['generate_rules'] == true && isset($_POST['rotator_id'])) { 

	$id = $db->real_escape_string($_POST['rotator_id']);
	$rotator_sql = "SELECT * FROM 202_rotators WHERE id = '".$id."'";
	$rotator_result = $db->query($rotator_sql);

	if ($rotator_result->num_rows > 0) {
		$rotator_row = $rotator_result->fetch_assoc();
	}

	$rule_sql = "SELECT * FROM 202_rotator_rules WHERE rotator_id = '".$id."'";
	$rule_result = $db->query($rule_sql);

	if ($rule_result->num_rows == 0) { ?>
					<div class="col-xs-12" style="margin-top:15px;">
						<div class="col-xs-12 rules" data-rule-id="none">
							<div class="row">
								<div class="col-xs-12">
									<div class="form-group">
										<label for="rule_name">Rule name: </label>
										<input class="form-control input-sm" name="rule_name" id="rule_name" placeholder="Type in rule name"/>
									</div>
									<div class="form-group" style="float:right; margin-right: 25px;">
										<label class="checkbox" for="inactive" style="margin-bottom: 12px;padding-left: 32px;">
								            <input type="checkbox" id="inactive" name="inactive" data-toggle="checkbox">
								            Inactive
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
										<img id="addmore_criteria_loading" class="loading" src="/202-img/loader-small.gif" style="display:none; position: absolute; top: 4px; left: -20px;">
										<button id="add_more_criteria" class="btn btn-xs btn-default"><span class="fui-plus"></span> Add more criteria</button>
									</div>
								</div>
							</div>

							<div class="row form_seperator" style="margin-top:10px; margin-bottom:10px;">
								<div class="col-xs-12" style="width: 97.5%;"></div>
							</div>

							<div class="row">
										<div class="col-xs-4" id="redirect_type_radio">
											<label for="redirect_type" style="margin-left: -15px;" class="col-xs-5 control-label">Redirects to: </label>
											<label class="radio radio-inline">
												<input type="radio" name="redirect_type" id="redirect_type1" value="campaign" data-toggle="radio" checked="">
												Campaign
											</label>
												
											<label class="radio radio-inline">
												<input type="radio" name="redirect_type" id="redirect_type2" value="url" data-toggle="radio">
												URL
											</label>
										</div>

										<div class="col-xs-4" id="redirect_campaign_select">
											<select class="form-control input-sm" name="redirect_campaign" style="width: 100%;">
												<option value="">--</option>
												<?php 
													foreach ($campaigns as $campaign) { ?>
														<option value="<?php echo $campaign['id'];?>"><?php echo $campaign['name'];?></option>
												<?php } ?>
											</select>
										</div>
										<div class="col-xs-8" id="redirect_url_input" style="display:none; width: 64.5%;">	
											<div class="input-group input-group-sm">
													<span class="input-group-addon"><i class="fa fa-globe"></i></span>
													<input name="redirect_url" class="form-control" type="text" placeholder="http://">
											</div>
										</div>
							</div>

						</div>
					</div>	
					
					<script type="text/javascript">
						$(document).ready(function() {
							rotator_tags_autocomplete('tag', 'country');
							$('[data-toggle="checkbox"]').checkbox();
							$('input[name=redirect_type]').radio();
						});
					</script>

	<?php } elseif ($rule_result->num_rows > 0) {
		$count = 0;
		while ($rule_row = $rule_result->fetch_assoc()) { $count++;?>
					<div class="col-xs-12" style="margin-top:15px;">
						<div class="col-xs-12 rules" data-rule-id="<?php echo $rule_row['id'];?>">
						<?php if($count >= 2) { ?>
							<a href="#remove_rule" style="color: #34495e;"><span class="fui-cross" id="remove_rule"></span></a>
						<?php } ?>
							<div class="row">
								<div class="col-xs-12">
									<div class="form-group">
										<label for="rule_name">Rule name: </label>
										<input class="form-control input-sm" name="rule_name_<?php echo $rule_row['id'];?>" id="rule_name" placeholder="Type in rule name" value="<?php echo $rule_row['rule_name'];?>"/>
									</div>
									<div class="form-group" style="float:right; margin-right: 25px;">
										<label class="checkbox" for="inactive" style="margin-bottom: 12px;padding-left: 32px;">
								            <input type="checkbox" id="inactive" name="inactive" data-toggle="checkbox" <?php if($rule_row['status'] == false) echo "checked"?>>
								            Inactive
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
															<div class="form-group">
																<a href="#remove_criteria" style="color: #34495e;"><span class="fui-cross" id="remove_criteria"></span></a>
															</div>
														<?php } ?>
													</div>
													<script type="text/javascript">
														$(document).ready(function() {
															<?php if($criteria_row['type'] == 'ip') { ?>
																rotator_tags_autocomplete_ip("tag_<?php echo $criteria_row['id'];?>");
															<?php } elseif ($criteria_row['type'] == 'device') { ?>
																rotator_tags_autocomplete_devices("tag_<?php echo $criteria_row['id'];?>");
															<?php } elseif($criteria_row['type'] == 'country') { 
																$data = explode(',', $criteria_row['value']);
																$country = array();
																	foreach ($data as $value) {
																		$country[] = array('value' => $value, 'label' => substr($value, 0, strpos($value, '('))); 
																	} ?>
																rotator_tags_autocomplete("tag_<?php echo $criteria_row['id'];?>", "<?php echo $criteria_row['type'];?>");
																
															<?php } else { ?>
																rotator_tags_autocomplete("tag_<?php echo $criteria_row['id'];?>", "<?php echo $criteria_row['type'];?>");
															<?php } 

															if($rule_row['type'] == 'country') { ?>
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
										<img id="addmore_criteria_loading" class="loading" src="/202-img/loader-small.gif" style="display:none; position: absolute; top: 4px; left: -20px;">
										<button id="add_more_criteria" class="btn btn-xs btn-default"><span class="fui-plus"></span> Add more criteria</button>
									</div>
								</div>
							</div>

							<div class="row form_seperator" style="margin-top:10px; margin-bottom:10px;">
								<div class="col-xs-12" style="width: 97.5%;"></div>
							</div>

							<div class="row">
										<div class="col-xs-4" id="redirect_type_radio">
											<label for="redirect_type" style="margin-left: -15px;" class="col-xs-5 control-label">Redirects to: </label>
											<label class="radio radio-inline">
												<input type="radio" name="redirect_type_<?php echo $rule_row['id'];?>" value="campaign" data-toggle="radio" <?php if($rule_row['redirect_campaign'] != null) echo "checked";?>>
												Campaign
											</label>
												
											<label class="radio radio-inline">
												<input type="radio" name="redirect_type_<?php echo $rule_row['id'];?>" value="url" data-toggle="radio" <?php if($rule_row['redirect_url'] != null) echo "checked";?>>
												URL
											</label>
										</div>

										<div class="col-xs-4" id="redirect_campaign_select" <?php if($rule_row['redirect_campaign'] == null) echo 'style="display:none"';?>>
											<select class="form-control input-sm" name="redirect_campaign" style="width: 100%;">
												<option value="">--</option>
												<?php 
												  	foreach ($campaigns as $campaign) { 
												  		if ($rule_row['redirect_campaign'] == $campaign['id']) { ?>
												  			<option value="<?php echo $campaign['id'];?>" selected><?php echo $campaign['name'];?></option>
												  		<?php } else { ?>
												  			<option value="<?php echo $campaign['id'];?>"><?php echo $campaign['name'];?></option>
												  		<?php } 
												  } ?>
											</select>
										</div>
										<div class="col-xs-8" style="width: 64.5%; <?php if($rule_row['redirect_url'] == null) echo 'display:none';?>" id="redirect_url_input">	
											<div class="input-group input-group-sm">
													<span class="input-group-addon"><i class="fa fa-globe"></i></span>
													<input name="redirect_url" class="form-control" type="text" placeholder="http://" value="<?php echo $rule_row['redirect_url'];?>">
											</div>
										</div>
							</div>

						</div>
					</div>	
					
					
		<?php } ?>

					<script type="text/javascript">
					$(document).ready(function() {
						$('[data-toggle="checkbox"]').checkbox();
						$('[data-toggle="radio"]').radio();
					});
					</script>
	<?php }
	

} ?>
 