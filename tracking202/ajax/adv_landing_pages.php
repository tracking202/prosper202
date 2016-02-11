<?php include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/connect.php'); 

AUTH::require_user();

$count = $_POST['counter'];
$count = $count + 1;  
$html['count'] = htmlentities($count, ENT_QUOTES, 'UTF-8');

?>

<div id="area_<?php echo $count; ?>">
	<div class="col-xs-10" style="margin-bottom: -15px; z-index: 1;">
		<div class="form-group">
			<div class="col-xs-5" style="margin-top: 10px; margin-left: -15px;">
				<label class="radio">
				    <input type="radio" class="offer-type-radio" value="campaign" name="offer_type<?php echo $count; ?>" id="offer_type<?php echo $count; ?>1" data-toggle="radio" checked>
				        Campaign
				</label>
			</div>
			<div class="col-xs-5" style="margin-top: 10px;">
				<label class="radio">
					<input type="radio" class="offer-type-radio" value="rotator" name="offer_type<?php echo $count; ?>" id="offer_type<?php echo $count; ?>2" data-toggle="radio">
					    Rotator
				</label>
			</div>
		</div>	
	</div>
	<div class="campaign_select">
		<div class="form-group">
			<label for="aff_campaign_id_<?php echo $count; ?>" class="col-xs-5 control-label" style="text-align: left;">Select Campaign:</label>
			<div class="col-xs-6">
				<select class="form-control input-sm" name="aff_campaign_id_<?php echo $count; ?>" id="aff_campaign_id_<?php echo $count; ?>">					
				<option value="0"> -- </option> 	
					<?php 	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
							$aff_campaign_sql = "SELECT aff_campaign_id, aff_campaign_name, aff_network_name FROM 202_aff_campaigns LEFT JOIN 202_aff_networks USING (aff_network_id) WHERE 202_aff_campaigns.user_id='".$mysql['user_id']."' AND aff_campaign_deleted='0' AND aff_network_deleted=0 ORDER BY aff_network_name ASC";
										
							$aff_campaign_result = $db->query($aff_campaign_sql); // or record_mysql_error($aff_campaign_sql);
							while ($aff_campaign_row = $aff_campaign_result->fetch_assoc()) { 
								$html['aff_campaign_id'] = htmlentities($aff_campaign_row['aff_campaign_id'], ENT_QUOTES, 'UTF-8');
								$html['aff_campaign_name'] = htmlentities($aff_campaign_row['aff_campaign_name'], ENT_QUOTES, 'UTF-8');
								$html['aff_network_name'] = htmlentities($aff_campaign_row['aff_network_name'], ENT_QUOTES, 'UTF-8');
								printf('<option value="%s">%s: %s</option>', $html['aff_campaign_id'], $html['aff_network_name'], $html['aff_campaign_name']); 
							} ?>
				</select>
			</div>
			<div class="col-xs-1 remove-offer-grid">
				<span class="fui-cross remove-offer" onclick="remove_new_campaign(<?php echo $count; ?>)"></span>
			</div>
		</div>	
	</div>
	<div class="rotator_select" style="display:none">
		<div class="form-group">
			<label for="rotator_id_<?php echo $count; ?>" class="col-xs-5 control-label" style="text-align: left;">Select Rotator:</label>
			<div class="col-xs-6">
				<select class="form-control input-sm" name="rotator_id_<?php echo $count; ?>" id="rotator_id_<?php echo $count; ?>">					
				<option value="0"> -- </option> 	
				<?php 	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
						$rotator_sql = "SELECT id, name FROM 202_rotators WHERE user_id='".$mysql['user_id']."' ORDER BY name ASC";
										
						$rotator_result = $db->query($rotator_sql); // or record_mysql_error($aff_campaign_sql);
						while ($rotator_row = $rotator_result->fetch_assoc()) { 
							$html['rotator_id'] = htmlentities($rotator_row['id'], ENT_QUOTES, 'UTF-8');
							$html['rotator_name'] = htmlentities($rotator_row['name'], ENT_QUOTES, 'UTF-8');
							printf('<option value="%s">%s</option>', $html['rotator_id'], $html['rotator_name']); 
						} ?>
				</select>
			</div>
			<div class="col-xs-1 remove-offer-grid">
				<span class="fui-cross remove-offer" onclick="remove_new_campaign(<?php echo $count; ?>)"></span>
			</div>
		</div>	
	</div>
	<script type="text/javascript">
	$(document).ready(function() {
		$('[data-toggle="radio"]').radiocheck();
	});
	</script>
</div>

<div class="col-xs-6 col-xs-offset-5">
	<img id="load_aff_campaign_<?php echo $count; ?>_loading" style="display: none;" src="<?php echo get_absolute_url();?>202-img/loader-small.gif"/>
</div>
<div id="load_aff_campaign_<?php echo $count; ?>"></div>
	 
