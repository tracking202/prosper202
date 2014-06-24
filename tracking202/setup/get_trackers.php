<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();

template_top($server_row,'Get Trackers',NULL,NULL,NULL);  ?>

<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<h6>Get tracking links to be used in your campaigns</h6>
	</div>
	<div class="col-xs-12">
		<small>Please make sure to test your links.<br/>If you are using a landing page, you should have already installed your landing page code prior to coming to this step.</small>
	</div>
</div>	

<div class="row form_seperator" style="margin-bottom:15px;">
	<div class="col-xs-12"></div>
</div>

<div class="row">
	<div class="col-xs-7">
		<form method="post" id="tracking_form" class="form-horizontal" role="form" style="margin:0px 0px 0px 15px;">
			<div class="form-group <?php if($error['landing_page_type']) echo 'has-error';?>" style="margin-bottom: 0px;" id="tracker-type">
				<label class="col-xs-4 control-label" style="text-align: left;" id="width-tooltip">Get Text Ad Code For:</label>

				<div class="col-xs-8" style="margin-top: 15px;">
					<label class="radio" style="line-height: 0.5;">
	            		<input type="radio" name="tracker_type" value="0" data-toggle="radio" checked="">
	            			Direct Link Setup, or Simple Landing Page Setup
	          		</label>
	          		<label class="radio" style="line-height: 0.5;">
	            		<input type="radio" name="tracker_type" value="1" data-toggle="radio">
	            			Advanced Landing Page Setup
	          		</label>
	          		<label class="radio" style="line-height: 0.5;">
	            		<input type="radio" name="tracker_type" value="2" data-toggle="radio">
	            			Smart Rotator
	          		</label>
	          	</div>
	        </div>

	        <div id="tracker_aff_network" class="form-group" style="margin-bottom: 0px;">
		        <label for="aff_network_id" class="col-xs-4 control-label" style="text-align: left;">Category:</label>
		        <div class="col-xs-6" style="margin-top: 10px;">
		        	<img id="aff_network_id_div_loading" class="loading" style="display: none;" src="/202-img/loader-small.gif"/>
	                <div id="aff_network_id_div"></div>
		        </div>
		    </div>

		    <div id="tracker_aff_campaign" class="form-group" style="margin-bottom: 0px;">
		        <label for="aff_campaign_id" class="col-xs-4 control-label" style="text-align: left;">Campaign:</label>
		        <div class="col-xs-6" style="margin-top: 10px;">
		        	<img id="aff_campaign_id_div_loading" class="loading" src="/202-img/loader-small.gif" style="display: none;"/>
			        <div id="aff_campaign_id_div">
			            <select class="form-control input-sm" id="aff_campaign_id" disabled="">
			                <option>--</option>
			            </select>
			        </div>
		        </div>
		    </div>

		    <div id="tracker_method_of_promotion" class="form-group" style="margin-bottom: 0px;">
		        <label for="method_of_promotion" class="col-xs-4 control-label" style="text-align: left;">Method of Promotion:</label>
		        <div class="col-xs-6" style="margin-top: 10px;">
		        	<img id="method_of_promotion_div_loading" class="loading" style="display: none;" src="/202-img/loader-small.gif"/>
					<div id="method_of_promotion_div">
						<select class="form-control input-sm" id="method_of_promotion" disabled="">
			                <option>--</option>
			            </select>
			        </div>
		        </div>
		    </div>

		    <div id="tracker_lp" class="form-group" style="margin-bottom: 0px;">
		        <label for="landing_page_id" class="col-xs-4 control-label" style="text-align: left;">Landing Page:</label>
		        <div class="col-xs-6" style="margin-top: 10px;">
		        	<img id="landing_page_div_loading" class="loading" style="display: none;" src="/202-img/loader-small.gif"/>
					<div id="landing_page_div">
						<select class="form-control input-sm" id="landing_page_id" disabled="">
			                <option>--</option>
			            </select>
					</div>
		        </div>
		    </div>

		    <div id="tracker_ad_copy" class="form-group" style="margin-bottom: 0px;">
		        <label for="text_ad_id" class="col-xs-4 control-label" style="text-align: left;">Ad Copy:</label>
		        <div class="col-xs-6" style="margin-top: 10px;">
		        	<img id="text_ad_id_div_loading" class="loading" style="display: none;" src="/202-img/loader-small.gif"/>
					<div id="text_ad_id_div">
						<select class="form-control input-sm" id="text_ad_id" disabled="">
			                <option>--</option>
			            </select>
					</div>
		        </div>
		    </div>

		    <div id="tracker_ad_preview" class="form-group" style="margin-bottom: 0px;">
		        <label class="col-xs-4 control-label" style="text-align: left;">Ad Preview </label>
		        <div class="col-xs-6" style="margin-top: 10px;">
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

		    <div id="tracker_cloaking" class="form-group" style="margin-bottom: 0px;">
		        <label for="click_cloaking" class="col-xs-4 control-label" style="text-align: left;">Cloaking:</label>
		        <div class="col-xs-6" style="margin-top: 10px;">
					<select class="form-control input-sm" name="click_cloaking" id="click_cloaking">
			            <option value="-1">Campaign Default On/Off</option>
                        <option value="0">Off - Overide Campaign Default</option>
						<option value="1">On - Override Campaign Default</option>
			        </select>
		        </div>
		    </div>

		    <div id="tracker_rotator" class="form-group" style="display:none; margin-bottom: 0px;">
		        <label for="tracker_rotator" class="col-xs-4 control-label" style="text-align: left;">Rotator:</label>
		        <div class="col-xs-6" style="margin-top: 10px;">
			        <img id="rotator_id_div_loading" class="loading" style="display: none;" src="/202-img/loader-small.gif"/>
					<div id="rotator_id_div"></div>
		        </div>
		    </div>

		    <div class="form-group" style="margin-bottom: 0px;">
		        <label for="ppc_network_id" class="col-xs-4 control-label" style="text-align: left;">Traffic Source:</label>
		        <div class="col-xs-6" style="margin-top: 10px;">
		        	<img id="ppc_network_id_div_loading" class="loading" style="display: none;" src="/202-img/loader-small.gif"/>
					<div id="ppc_network_id_div"></div>
		        </div>
		    </div>

		    <div class="form-group" style="margin-bottom: 0px;">
		        <label for="ppc_account_id" class="col-xs-4 control-label" style="text-align: left;">Traffic Source Account:</label>
		        <div class="col-xs-6" style="margin-top: 10px;">
		        	<img id="ppc_account_id_div_loading" class="loading" style="display: none;" src="/202-img/loader-small.gif"/>
					<div id="ppc_account_id_div">
						<select class="form-control input-sm" id="ppc_account_id" disabled="">
			                <option>--</option>
			            </select>
					</div>
		        </div>
		    </div>

		    <div class="form-group" style="margin-bottom: 0px;">
				<label class="col-xs-4 control-label" for="cpc_dollars" style="text-align: left;">Max CPC:</label>
				<div class="col-xs-6" style="margin-top: 10px;">
					<div class="input-group input-group-sm">
		          	  <span class="input-group-addon">$</span>
		          	  <input class="form-control" name="cpc_dollars" id="cpc_dollars" maxlength="2" type="text" value="0">

		          	  <span class="input-group-addon">&cent;</span>
		          	  <input class="form-control" name="cpc_cents" maxlength="5" id="cpc_cents" type="text" value="00">
		          	</div>
		          	<span class="help-block" style="font-size: 11px;">you can enter cpc amounts as small as 0.00001</span>
				</div>
			</div>

			<div class="form-group" style="margin-bottom: 0px;">
				<label class="col-xs-4 control-label" for="c1" style="text-align: left;">Tracking ID c1:</label>
				<div class="col-xs-6" style="margin-top: 10px;">
					<input class="form-control input-sm" type="text" name="c1" id="c1"/>
					<span class="help-block" style="font-size: 10px;">c1-c4 variables must be no longer than 350 characters.</span>
				</div>
			</div>

			<div class="form-group" style="margin-bottom: 0px;">
				<label class="col-xs-4 control-label" for="c2" style="text-align: left;">Tracking ID c2:</label>
				<div class="col-xs-6" style="margin-top: 10px;">
					<input class="form-control input-sm" type="text" name="c2" id="c2"/>
				</div>
			</div>

			<div class="form-group" style="margin-bottom: 0px;">
				<label class="col-xs-4 control-label" for="c3" style="text-align: left;">Tracking ID c3:</label>
				<div class="col-xs-6" style="margin-top: 10px;">
					<input class="form-control input-sm" type="text" name="c3" id="c3"/>
				</div>
			</div>

			<div class="form-group" style="margin-bottom: 0px;">
				<label class="col-xs-4 control-label" for="c4" style="text-align: left;">Tracking ID c4:</label>
				<div class="col-xs-6" style="margin-top: 10px;">
					<input class="form-control input-sm" type="text" name="c4" id="c4"/>
				</div>
			</div>

			<div class="form-group">
				<div class="col-xs-10" style="margin-top: 10px;">
					<input type="button" id="get-links" class="btn btn-sm btn-p202 btn-block" value="Generate Tracking Link">					
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
			<div class="panel-heading"><center>Tracking Links</center></div>
			<div class="panel-body" id="tracking-links" style="opacity: 0.5;">
				<center><small>Click <em>"Generate Tracking Link"</em> to get tracking links.</small></center>
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
<?php template_bottom($server_row);