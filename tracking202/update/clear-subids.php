<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-19) . '/202-config/connect.php');

AUTH::require_user();

if (!$userObj->hasPermission("access_to_update_section")) {
	header('location: '.get_absolute_url().'tracking202/');
	die();
}

template_top('Clear Subids');  ?>

<div class="row">
	<div class="col-xs-12">
		<h6>Delete all subids for a specific campaign.</h6>
		<small>If you accidentally uploaded all of your subids, instead of only the converted subids, you can delete them all here, and then reupload again!</small>
	</div>
</div>

<div class="row form_seperator" style="margin-bottom:15px; margin-top:15px;">
	<div class="col-xs-12"></div>
</div>

<div class="row">
	<div class="col-xs-6">
		<form id="clear_subids_form" method="post" action="" class="form-horizontal" role="form" style="margin:0px 0px 0px 15px;">
			<div id="tracker_aff_network" class="form-group" style="margin-bottom: 0px;">
		        <label for="aff_network_id" class="col-xs-4 control-label" style="text-align: left;">Affiliate Network:</label>
		        <div class="col-xs-6" style="margin-top: 10px;">
		        	<img id="aff_network_id_div_loading" class="loading" style="display: none;" src="/202-img/loader-small.gif"/>
	                <div id="aff_network_id_div"></div>
		        </div>
		    </div>

			<div id="tracker_aff_campaign" class="form-group" style="margin-bottom: 0px;">
		        <label for="aff_campaign_id" class="col-xs-4 control-label" style="text-align: left;">Affiliate Campaign:</label>
		        <div class="col-xs-6" style="margin-top: 10px;">
		        	<img id="aff_campaign_id_div_loading" class="loading" src="<?php echo get_absolute_url();?>202-img/loader-small.gif" style="display: none;"/>
			        <div id="aff_campaign_id_div">
			            <select class="form-control input-sm" id="aff_campaign_id" disabled="">
			                <option>--</option>
			            </select>
			        </div>
		        </div>
		    </div>

		    <div class="form-group">
				<div class="col-xs-10" style="margin-top: 10px;">
					<input type="button" id="clear-subids" class="btn btn-sm btn-p202 btn-block" value="Clear Subids">					
				</div>
			</div>

		</form>
	</div>
	<div class="col-xs-6" id="response">
		
	</div>
</div>

<!-- open up the ajax aff network -->
<script type="text/javascript">
	$(document).ready(function() {
	   	load_aff_network_id(0);
	});
</script>
		
<?php template_bottom();