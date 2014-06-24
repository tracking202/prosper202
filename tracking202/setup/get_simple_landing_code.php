<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();

template_top($server_row,'Get Simple Landing Page Code',NULL,NULL,NULL);  ?>

<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<h6>Setup a Simple Landing Page</h6>
	</div>
	<div class="col-xs-12">
		<small>Here is where you need to setup your landing pages, installing the javascript and PHP code prior to getting your Text Ad Tracking Urls.</small>
	</div>
</div>		

<div class="row form_seperator" style="margin-bottom:15px;">
	<div class="col-xs-12"></div>
</div>

<div class="row">
	<div class="col-xs-5">
		<form id="tracking_form" method="post" action="" class="form-horizontal" role="form" style="margin:0px 0px 0px 15px;">
			<div class="form-group" style="margin-bottom: 0px;">
			    <label for="aff_campaign_id" class="col-xs-5 control-label" style="text-align: left;">Category:</label>
			    <div class="col-xs-6">
			        <img id="aff_network_id_div_loading" class="loading" src="/202-img/loader-small.gif"/>
	                <div id="aff_network_id_div"></div>
			    </div>
			</div>

			<div class="form-group" style="margin-bottom: 0px;">
			<label for="aff_campaign_id" class="col-xs-5 control-label" style="text-align: left;">Campaign:</label>
				<div class="col-xs-6" style="margin-top: 10px;">
				    <img id="aff_campaign_id_div_loading" class="loading" src="/202-img/loader-small.gif" style="display: none;"/>
			        <div id="aff_campaign_id_div">
			            <select class="form-control input-sm" id="aff_campaign_id" disabled="">
			                <option>--</option>
			            </select>
			        </div>
				</div>
			</div>

			<div class="form-group" style="margin-bottom: 0px;">
			    <label for="method_of_promotion" class="col-xs-5 control-label" style="text-align: left;">Promotion Method:</label>
			    <div class="col-xs-6" style="margin-top: 10px;">
			        <select class="form-control input-sm" id="method_of_promotion" name="method_of_promotion">
			            <option value="landingpage" selected="">Landing Page</option>
			        </select>
			    </div>
			</div>

			<div class="form-group" style="margin-bottom: 0px;">
		        <label for="landing_page_id" class="col-xs-5 control-label" style="text-align: left;">Landing Page:</label>
		        <div class="col-xs-6" style="margin-top: 10px;">
		        	<img id="landing_page_div_loading" class="loading" style="display: none;" src="/202-img/loader-small.gif"/>
					<div id="landing_page_div">
						<select class="form-control input-sm" id="landing_page_id" disabled="">
			                <option>--</option>
			            </select>
					</div>
		        </div>
		    </div>

		    <div class="form-group">
				<div class="col-xs-11" style="margin-top: 10px;">
					<input type="button" id="generate-tracking-link-simple" class="btn btn-sm btn-p202 btn-block" value="Get Landing Page Codes">					
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
			<div class="panel-heading"><center>Simple Landing Page Tracking Codes</center></div>
			<div class="panel-body" id="tracking-links" style="opacity: 0.5;">
				<center><small>Click <em>"Get Landing Page Codes"</em> to get tracking codes.</small></center>
			</div>
		</div>
	</div>	
</div>
<!-- open up the ajax aff network -->
<script type="text/javascript">
	$(document).ready(function() {
	   	load_aff_network_id(0);
	});
</script>
		
<?php template_bottom($server_row);