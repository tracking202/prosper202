<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php');

AUTH::require_user();

if ($_POST['edit_rotator']) {
		$editing = true;
}

if ($_GET['rules_added']) {
		$add_success = true;
}

if ($_SERVER['REQUEST_METHOD'] == "POST") {

	$rotator_name = trim($_POST['rotator_name']);
	if (empty($rotator_name)) {
		$error['rotator_name'] = '<div class="error">Type in the name of your rotator!</div>';
	}

	if (!$error) {
		$mysql['rotator_name'] = $db->real_escape_string ($_POST['rotator_name']);
		$mysql['user_id'] = $db->real_escape_string ($_SESSION ['user_id']);

		if ($editing == true) {
			$sql = "UPDATE `202_aff_networks` SET";
		} else {
			$sql = "INSERT INTO 202_rotators SET name='".$mysql['rotator_name']."', user_id='".$mysql['user_id']."'";
		}

		$result = $db->query($sql);
		$rotator_id = $db->insert_id;

		$sql = "UPDATE 202_rotators SET tracker_id='".rand(1,9) . $rotator_id . rand(1,9)."' WHERE id='".$rotator_id."'";
		$result = $db->query($sql);
		$add_success = true;
	}
}

if (isset($_GET['delete_rotator_id'])) {

	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
	$mysql['rotator_id'] = $db->real_escape_string($_GET['delete_rotator_id']);

	$delete_sql = "DELETE FROM 202_rotators WHERE id='".$mysql['rotator_id']."' AND user_id='".$mysql['user_id']."'";

	if (_mysqli_query($delete_sql)) {
		$rule_sql = "DELETE FROM 202_rotator_rules WHERE rotator_id='".$mysql['rotator_id']."'";

		if (_mysqli_query($rule_sql)) {
		 	$criteria_sql = "DELETE FROM 202_rotator_rules_criteria WHERE rotator_id='".$mysql['rotator_id']."'";
		 	if (_mysqli_query($criteria_sql)) {
		 		$delete_success = true;
		 	}
		 } 
	}
}


template_top('Smart Rotator',NULL,NULL,NULL); ?>

<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<div class="row">
			<div class="col-xs-5">
				<h6>Smart Rotator Setup</h6>
			</div>
			<div class="col-xs-7">
				<div class="error pull-right" id="form_erors" style="display: none;margin-top: 20px;">
					<small><span class="fui-alert"></span> Hey! Make sure all field are filled.</small>
				</div>
				<div class="<?php if($error) echo "error"; else echo "success";?> pull-right" id="form_response" style="margin-top: 20px;">
					<small>
						<?php if ($error) { ?> 
							<span class="fui-alert"></span> There were errors with your submission. <?php echo $error['token']; ?>
						<?php } ?>
						<?php if ($add_success == true) { ?> 
							<span class="fui-check-inverted"></span> Your submission was successful. Your changes have been saved.
						<?php } ?>
						<?php if ($delete_success == true) { ?>
							<span class="fui-check-inverted"></span> Your deletion was successful. You have succesfully removed a rotator.
						<?php } ?>
					</small>
				</div>
			</div>
		</div>
	</div>
	<div class="col-xs-12">
		<small>Setup Smart Rotator, to rotate visitors, based on defined rules you set.</small>
	</div>
</div>

<div class="row form_seperator" style="margin-bottom:15px;">
	<div class="col-xs-12"></div>
</div>

<div class="row">
	<div class="col-xs-7">
		<small><strong>Add New Smart Rotator</strong></small><br/>
		<span class="infotext">Give a name for your rotator.</span>
				
		<form method="post" action="" class="form-inline" role="form" style="margin:15px 0px;">
			<div class="form-group">
				<label class="sr-only" for="rotator_name">Smart Rotator</label>
				<input type="text" class="form-control input-sm" id="rotator_name" name="rotator_name" placeholder="Rotator name">
			</div>
			<button type="submit" class="btn btn-xs btn-p202" id="addRotator">Add</button>
		</form>
	</div>

	<div class="col-xs-5">
		<div class="panel panel-default">
			<div class="panel-heading">My Smart Rotators</div>
			<div class="panel-body">
			<ul>
			<?
				$mysql ['user_id'] = $db->real_escape_string ( $_SESSION ['user_id'] );
				$sql = "SELECT * FROM `202_rotators` WHERE `user_id`='" . $mysql ['user_id'] . "' ORDER BY `name` ASC";
				$result = $db->query ( $sql ) or record_mysql_error ( $sql );
				if ($result->num_rows == 0) {
					?><li>You have not added any rotator.</li><?
				}
							
				while ( $row = $result->fetch_array (MYSQL_ASSOC) ) {
					$html ['name'] = htmlentities ( $row ['name'], ENT_QUOTES, 'UTF-8' );
					$html ['id'] = htmlentities ( $row ['id'], ENT_QUOTES, 'UTF-8' );
									
					printf ( '<li>%s - <a href="?delete_rotator_id=%s">remove</a></li>', $html ['name'], $html ['id'] );
									
					$rule_sql = "SELECT * FROM `202_rotator_rules` WHERE `rotator_id`='" . $row['id'] . "' ORDER BY `id` ASC";
					$rule_result = $db->query ( $rule_sql ) or record_mysql_error ( $rule_sql );
					if ($rule_result->num_rows == 0) {
						?><ul><li>You have not added any rules.</li></ul><?
					} else { 
						echo "<ul>";
						while ($rule_row = $rule_result->fetch_array()) { 
							$criteria_sql = "SELECT * FROM `202_rotator_rules_criteria` WHERE `rule_id`='" . $rule_row['id'] . "' ORDER BY `id` ASC";
							$criteria_result = $db->query ( $criteria_sql ) or record_mysql_error ( $criteria_sql );
							if ($criteria_result->num_rows > 0) {
								$criteria = "You have ".$criteria_result->num_rows." criteria added";
							} else {
								$criteria = "No criteria added";
							}

						?>
							<li><?php echo $rule_row['rule_name']." - ".$criteria;?> (<a href="" id="rule_details" data-id="<?php echo $rule_row['id'];?>" data-toggle="modal" data-target="#rule_values_modal">Details</a>)</li>
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

<div class="row form_seperator" style="margin-bottom:15px;">
	<div class="col-xs-12"></div>
</div>

<div class="row">
	<div class="col-xs-12">
		<small><strong>Add Rule to Your Smart Rotator</strong></small><br/>
		<span class="infotext">Select rotator, to add new rule. You can add as many rules as you want, for each rotator.</span>
	</div>
</div>

<form class="form-inline" onsubmit="return false;" role="form" id="rule_form" method="post" action="">
<div class="row" style="margin-top:15px;">
			<div class="col-xs-4">
				<div class="form-group">
					<img id="rules_loading" class="loading" src="/202-img/loader-small.gif" style="display:none;right: -20px;top: 10px;"/>
					<label for="rotator_id" style="margin-right:5px;">Select rotator: </label>
					<select class="form-control input-sm" name="rotator_id" style="min-width: 130px;">
						<option value="0">--</option>
						<?php  $mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
						$rotator_sql = "SELECT * FROM `202_rotators` WHERE `user_id`='".$mysql['user_id']."' ORDER BY `name` ASC";
						$rotator_result = _mysqli_query($rotator_sql) ; 
						while ($rotator_row = $rotator_result->fetch_array(MYSQL_ASSOC)) {

							$html['rotator_name'] = htmlentities($rotator_row['name'], ENT_QUOTES, 'UTF-8');
							$html['rotator_id'] = htmlentities($rotator_row['id'], ENT_QUOTES, 'UTF-8');

							printf('<option value="%s">%s</option>', $html['rotator_id'],$html['rotator_name']);

						} ?>
					</select>
				</div>
			</div>

			<div id="defaults_container" style="opacity:0.5">
				<div class="col-xs-4">
					<label for="default_type" class="col-xs-5 control-label">Defaults to: </label>
					<label class="radio radio-inline">
						<input type="radio" name="default_type" id="default_type1" value="campaign" data-toggle="radio" disabled checked="">
						Campaign
					</label>
						
					<label class="radio radio-inline">
						<input type="radio" name="default_type" id="default_type2" value="url" data-toggle="radio" disabled>
						URL
					</label>
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
						<input class="form-control input-sm" name="rule_name" placeholder="Type in rule name" disabled/>
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
						<div class="criteria" id="criteria">
							<div class="form-group">
			    				<label for="rule_type">If</label>
								<select class="form-control input-sm" name="rule_type" style="margin: 0px 5px;" disabled>
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
								<select class="form-control input-sm" name="rule_statement" style="margin: 0px 5px;" disabled>
									<option value="is">IS</option>
									<option value="is_not">IS NOT</option>
								</select>
							</div>

							<div class="form-group">
								<label for="rule_value">equal to:</label>
								<input id="tag" class="value_select" name="value" placeholder="Type in country and hit Enter" disabled/>
							</div>
						</div>
					</div>

					<div class="col-xs-2" style="margin-left: -18px; margin-top: 10px;">
						<div class="form-group">
							<img id="addmore_criteria_loading" class="loading" src="/202-img/loader-small.gif" style="display:none; position: absolute; top: 4px; left: -20px;">
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
							<label class="radio radio-inline">
								<input type="radio" name="redirect_type" id="redirect_type1" value="campaign" data-toggle="radio" disabled checked="">
								Campaign
							</label>
								
							<label class="radio radio-inline">
								<input type="radio" name="redirect_type" id="redirect_type2" value="url" data-toggle="radio" disabled>
								URL
							</label>
						</div>

						<div class="col-xs-4" id="redirect_campaign_select">
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
	<div class="col-xs-12 text-right" style="margin-top:15px;">
		<img id="addmore_loading" class="loading" src="/202-img/loader-small.gif" style="display: none; position: static;">
		<button id="add_more_rules" class="btn btn-xs btn-default" disabled><span class="fui-plus"></span> Add more rules</button>
		<button class="btn btn-xs btn-p202" id="post_rules" disabled>Save rules</button>
	</div>
</div>
</form>
				
			
<script type="text/javascript">
$(document).ready(function() {
	rotator_tags_autocomplete('tag', 'country');
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
<?php template_bottom();