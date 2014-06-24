<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();

template_top('Update CPC',NULL,NULL,NULL);  ?>

<div class="row">
	<div class="col-xs-12">
		<h6>Here is where you can update your CPCs</h6>
		<small>	Because T202 assumes that you are paying full CPC each time, we understand you won't be paying this each time.  So to refine your stats you can update your old history's cpc to make them more accurate.  Simply choose your setup below to update your cpc for a specific time period, and a specific set of variables.</small>
	</div>
</div>

<div class="row form_seperator" style="margin-bottom:15px; margin-top:15px;">
	<div class="col-xs-12"></div>
</div>

<div class="row">
	<div class="col-xs-6">
		<form id="cpc_form" method="post" action="" class="form-horizontal" role="form" style="margin:0px 0px 0px 15px;">
			<div class="form-group" style="margin-bottom: 0px;" id="tracker-type">
				<label class="col-xs-5 control-label" style="text-align: left;" id="width-tooltip">Adjust CPC For:</label>

				<div class="col-xs-7" style="margin-top: 15px;">
					<label class="radio" style="line-height: 0.5;">
	            		<input type="radio" name="tracker_type" value="0" data-toggle="radio" checked="">
	            			Direct Link or Landing Page
	          		</label>
	          		<label class="radio" style="line-height: 0.5;">
	            		<input type="radio" name="tracker_type" value="1" data-toggle="radio">
	            			Advanced Landing Page Setup
	          		</label>
	          	</div>
	        </div>

			<div id="tracker_aff_network" class="form-group" style="margin-bottom: 0px;">
		        <label for="aff_network_id" class="col-xs-5 control-label" style="text-align: left;">Affiliate Network:</label>
		        <div class="col-xs-7" style="margin-top: 10px;">
		        	<img id="aff_network_id_div_loading" class="loading" style="display: none;" src="/202-img/loader-small.gif"/>
	                <div id="aff_network_id_div"></div>
		        </div>
		    </div>

			<div id="tracker_aff_campaign" class="form-group" style="margin-bottom: 0px;">
		        <label for="aff_campaign_id" class="col-xs-5 control-label" style="text-align: left;">Affiliate Campaign:</label>
		        <div class="col-xs-7" style="margin-top: 10px;">
		        	<img id="aff_campaign_id_div_loading" class="loading" src="/202-img/loader-small.gif" style="display: none;"/>
			        <div id="aff_campaign_id_div">
			            <select class="form-control input-sm" id="aff_campaign_id" disabled="">
			                <option>--</option>
			            </select>
			        </div>
		        </div>
		    </div>

		    <div id="tracker_method_of_promotion" class="form-group" style="margin-bottom: 0px;">
		        <label for="method_of_promotion" class="col-xs-5 control-label" style="text-align: left;">Method of Promotion:</label>
		        <div class="col-xs-7" style="margin-top: 10px;">
		        	<img id="method_of_promotion_div_loading" class="loading" style="display: none;" src="/202-img/loader-small.gif"/>
					<div id="method_of_promotion_div">
						<select class="form-control input-sm" id="method_of_promotion" disabled="">
			                <option>--</option>
			            </select>
			        </div>
		        </div>
		    </div>

		    <div class="form-group" style="margin-bottom: 0px;">
		        <label for="landing_page_id" class="col-xs-5 control-label" style="text-align: left;">Landing Page:</label>
		        <div class="col-xs-7" style="margin-top: 10px;">
		        	<img id="landing_page_div_loading" class="loading" style="display: none;" src="/202-img/loader-small.gif"/>
					<div id="landing_page_div">
						<select class="form-control input-sm" id="landing_page_id" disabled="">
			                <option>--</option>
			            </select>
					</div>
		        </div>
		    </div>

		    <div class="form-group" style="margin-bottom: 0px;">
		        <label for="text_ad_id" class="col-xs-5 control-label" style="text-align: left;">Ad Copy:</label>
		        <div class="col-xs-7" style="margin-top: 10px;">
		        	<img id="text_ad_id_div_loading" class="loading" style="display: none;" src="/202-img/loader-small.gif"/>
					<div id="text_ad_id_div">
						<select class="form-control input-sm" id="text_ad_id" disabled="">
			                <option>--</option>
			            </select>
					</div>
		        </div>
		    </div>

		    <div class="form-group" style="margin-bottom: 0px;">
		        <label class="col-xs-5 control-label" style="text-align: left;">Ad Preview </label>
		        <div class="col-xs-7" style="margin-top: 10px;">
		        	<img id="ad_preview_div_loading" class="loading" style="display: none;" src="/202-img/loader-small.gif"/>
					<div id="ad_preview_div">
						<div class="panel panel-default" style="opacity:0.5; border-color: #3498db; margin-bottom:0px">
							<div class="panel-body">
								<span id="ad-preview-headline"><?php if ($html['text_ad_headline']) { echo $html['text_ad_headline']; } else { echo 'Luxury Cruise to Mars'; } ?></span><br/>
								<span id="ad-preview-body"><?php if ($html['text_ad_description']) { echo $html['text_ad_description']; } else { echo 'Visit the Red Planet in style. Low-gravity fun for everyone!'; } ?></span><br/>
								<span id="ad-preview-url"><?php if ($html['text_ad_display_url']) { echo $html['text_ad_display_url']; } else { echo 'www.example.com'; } ?></span>
							</div>
						</div>
					</div>
		        </div>
		    </div>

		    <div class="form-group" style="margin-bottom: 0px;">
		        <label for="ppc_network_id" class="col-xs-5 control-label" style="text-align: left;">PPC Network:</label>
		        <div class="col-xs-7" style="margin-top: 10px;">
		        	<img id="ppc_network_id_div_loading" class="loading" style="display: none;" src="/202-img/loader-small.gif"/>
					<div id="ppc_network_id_div"></div>
		        </div>
		    </div>

		    <div class="form-group" style="margin-bottom: 0px;">
		        <label for="ppc_account_id" class="col-xs-5 control-label" style="text-align: left;">PPC Account:</label>
		        <div class="col-xs-7" style="margin-top: 10px;">
		        	<img id="ppc_account_id_div_loading" class="loading" style="display: none;" src="/202-img/loader-small.gif"/>
					<div id="ppc_account_id_div">
						<select class="form-control input-sm" id="ppc_account_id" disabled="">
			                <option>--</option>
			            </select>
					</div>
		        </div>
		    </div>
		    
		    <div id="update-cpc-dates">
			    <div class="form-group" style="margin-bottom: 0px;">
			        <label for="from" class="col-xs-5 control-label" style="text-align: left;">Date From:</label>
			        <div class="col-xs-7" style="margin-top: 10px;">
			        	<input type="text" name="from" id="from" class="form-control input-sm" placeholder="mm/dd/yy">
			        </div>
			    </div>

			    <div class="form-group" style="margin-bottom: 0px;">
			        <label for="to" class="col-xs-5 control-label" style="text-align: left;">Date To:</label>
			        <div class="col-xs-7" style="margin-top: 10px;">
			        	<input type="text" name="to" id="to" class="form-control input-sm" placeholder="mm/dd/yy">
			        </div>
			    </div>
			</div>

		    <div class="form-group" style="margin-bottom: 0px;">
				<label class="col-xs-5 control-label" for="cpc_dollars" style="text-align: left;">New CPC:</label>
				<div class="col-xs-7" style="margin-top: 10px;">
					<div class="input-group input-group-sm">
		          	  <span class="input-group-addon">$</span>
		          	  <input class="form-control" name="cpc_dollars" id="cpc_dollars" maxlength="2" type="text" value="0">

		          	  <span class="input-group-addon">&cent;</span>
		          	  <input class="form-control" name="cpc_cents" maxlength="5" id="cpc_cents" type="text" value="00">
		          	</div>
		          	<span class="help-block" style="font-size: 11px;">you can now enter cpc amounts as small as 0.00001</span>
				</div>
			</div>

		    <div class="form-group">
				<div class="col-xs-12" style="margin-top: 10px;">
					<input type="button" id="update-cpc" class="btn btn-sm btn-p202 btn-block" value="Update CPC">					
				</div>
			</div>

		</form>
	</div>
	<div class="col-xs-6">
		<div class="panel panel-default" style="margin-top: 15px;">
			<div class="panel-heading"><center>Double Check Your Update CPC Settings</center></div>
			<div class="panel-body" style="opacity:0.5;" id="confirm-cpc-update-content">
				<center><small>Click <em>"Update CPC"</em> to confirm cpc update!</small></center>
			</div>
		</div>
	</div>
</div>

<!-- open up the ajax aff network -->
<script type="text/javascript">
	$(document).ready(function() {
	   	load_aff_network_id(0);
        load_method_of_promotion('');
        load_ppc_network_id(0);
	});
</script>

<?php template_bottom();