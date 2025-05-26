<?php
declare(strict_types=1);
include_once(substr(dirname( __FILE__ ), 0,-18) . '/202-config/connect.php');

AUTH::require_user();

if (!$userObj->hasPermission("access_to_setup_section")) {
	header('location: '.get_absolute_url().'tracking202/');
	die();
}

template_top('Get Advanced Landing Page Code',NULL,NULL,NULL);  ?>

<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<h6>Setup an Advanced Landing Page - Get Code</h6>
	</div>
	<div class="col-xs-12">
		<small>Select what landing page you wish to use, and then add all the different campaigns you plan on running with the landing page.</small>
	</div>
</div>	

<div class="row form_seperator" style="margin-bottom:15px;">
	<div class="col-xs-12"></div>
</div>

<div class="row">
	<div class="col-xs-5">
		<form id="tracking_form" method="post" action="" class="form-horizontal" role="form" style="margin:0px 0px 0px 15px;">
		<input type="hidden" id="counter" name="counter" value="0"/>
			<div class="form-group" style="margin-bottom: 0px;">
			    <label for="landing_page_id" class="col-xs-5 control-label" style="text-align: left;">Landing Page:</label>
			    <div class="col-xs-6">
			    	<select class="form-control input-sm" name="landing_page_id" id="landing_page_id">					
						<option value="0"> -- </option> <?php
						$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
						$landing_page_sql = "SELECT * FROM 202_landing_pages WHERE user_id='".$mysql['user_id']."' AND landing_page_type='1' AND landing_page_deleted='0'";
						$landing_page_result = $db->query($landing_page_sql); // or record_mysql_error($landing_page_sql);
						while ($landing_page_row = $landing_page_result->fetch_array(MYSQLI_ASSOC)) {
							$html['landing_page_id'] = htmlentities((string)($landing_page_row['landing_page_id'] ?? ''), ENT_QUOTES, 'UTF-8');
							$html['landing_page_nickname'] = htmlentities((string)($landing_page_row['landing_page_nickname'] ?? ''), ENT_QUOTES, 'UTF-8');
							printf('<option value="%s">%s</option>', $html['landing_page_id'], $html['landing_page_nickname']); 
						} ?>
					</select>
			    </div>
			</div>

			<div class="form-group" style="margin-bottom: 0px;">
			    <div id="area_1">
				    <div class="col-xs-10" style="margin-bottom: -15px; z-index: 1;">
					    <div class="form-group">
					    	<div class="col-xs-5" style="margin-top: 10px;">
								<label class="radio">
				            		<input type="radio" class="offer-type-radio" value="campaign" name="offer_type1" id="offer_type1" data-toggle="radio" checked>
				            			Campaign
				          		</label>
				          	</div>
				          	<div class="col-xs-5" style="margin-top: 10px;">
					            <label class="radio">
					            	<input type="radio" class="offer-type-radio" value="rotator" name="offer_type1" id="offer_type2" data-toggle="radio">
					            		Rotator
					            </label>
					        </div>
					    </div>
				    </div>
				    <div class="campaign_select">
					    <label for="aff_campaign_id_1" class="col-xs-5 control-label" style="text-align: left;">Select Campaign:</label>
					    <div class="col-xs-6">
					    	<select class="form-control input-sm" name="aff_campaign_id_1" id="aff_campaign_id_1">					
								<option value="0"> -- </option> 	
									<?php 	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
										$aff_campaign_sql = "SELECT aff_campaign_id, aff_campaign_name, aff_network_name FROM 202_aff_campaigns LEFT JOIN 202_aff_networks USING (aff_network_id) WHERE 202_aff_campaigns.user_id='".$mysql['user_id']."' AND aff_campaign_deleted='0' AND aff_network_deleted=0 ORDER BY aff_network_name ASC";
									
										$aff_campaign_result = $db->query($aff_campaign_sql); // or record_mysql_error($aff_campaign_sql);
										while ($aff_campaign_row = $aff_campaign_result->fetch_assoc()) { 
											$html['aff_campaign_id'] = htmlentities((string)($aff_campaign_row['aff_campaign_id'] ?? ''), ENT_QUOTES, 'UTF-8');
											$html['aff_campaign_name'] = htmlentities((string)($aff_campaign_row['aff_campaign_name'] ?? ''), ENT_QUOTES, 'UTF-8');
											$html['aff_network_name'] = htmlentities((string)($aff_campaign_row['aff_network_name'] ?? ''), ENT_QUOTES, 'UTF-8');
											printf('<option value="%s">%s: %s</option>', $html['aff_campaign_id'], $html['aff_network_name'], $html['aff_campaign_name']); 
										} ?>
							</select>
					    </div>
				    </div>
				    <div class="rotator_select" style="display:none">
				    	<label for="rotator_id_1" class="col-xs-5 control-label" style="text-align: left;">Select Rotator:</label>
					    <div class="col-xs-6">
					    	<select class="form-control input-sm" name="rotator_id_1" id="rotator_id_1">					
								<option value="0"> -- </option> 	
									<?php 	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
										$rotator_sql = "SELECT id, name FROM 202_rotators WHERE user_id='".$mysql['user_id']."' ORDER BY name ASC";
									
										$rotator_result = $db->query($rotator_sql); // or record_mysql_error($aff_campaign_sql);
										while ($rotator_row = $rotator_result->fetch_assoc()) { 
											$html['rotator_id'] = htmlentities((string)($rotator_row['id'] ?? ''), ENT_QUOTES, 'UTF-8');
											$html['rotator_name'] = htmlentities((string)($rotator_row['name'] ?? ''), ENT_QUOTES, 'UTF-8');
											printf('<option value="%s">%s</option>', $html['rotator_id'], $html['rotator_name']); 
										} ?>
							</select>
					    </div>
				    </div>
			    </div>
			</div>

			<div class="col-xs-6 col-xs-offset-5">
				<img id="load_aff_campaign_1_loading" style="display: none;" src="<?php echo get_absolute_url();?>202-img/loader-small.gif"/>
			</div>

			<div id="load_aff_campaign_1"></div>

			<div class="form-group">
				<div class="col-xs-11" style="margin-top: 10px;">
					<input type="button" id="add-more-offers" class="btn btn-xs btn-info btn-block" value="Add Another Offer To This Page">
					<input type="button" id="generate-tracking-link-adv" class="btn btn-sm btn-p202 btn-block" value="Get Landing Page Codes">						
				</div>
			</div>

		</form>
	</div>
</div>

<div class="row form_seperator" style="margin-bottom:15px;">
	<div class="col-xs-12"></div>
</div>

<div class="row">
	<div class="col-xs-12">
		<div class="panel panel-default">
			<div class="panel-heading"><center>Advanced Landing Page Tracking Codes</center></div>
			<div class="panel-body" id="tracking-links" style="opacity: 0.5;">
				<center><small>Click <em>"Get Landing Page Codes"</em> to get tracking codes.</small></center>
			</div>
		</div>
	</div>
</div>
	
<?php template_bottom();
