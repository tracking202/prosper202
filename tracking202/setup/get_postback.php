<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php');

AUTH::require_user();

template_top('Pixel And Postback URLs');

//the pixels
$unSecuredPixel = '<img height="1" width="1" border="0" style="display: none;" src="http://'. getTrackingDomain() .'/tracking202/static/gpx.php?amount=" />';
$unSecuredPixel_2 = '<img height="1" width="1" border="0" style="display: none;" src="http://'. getTrackingDomain() .'/tracking202/static/gpx.php?amount=&cid=" />';

//post back urls
$unSecuredPostBackUrl = 'http://'. getTrackingDomain() .'/tracking202/static/gpb.php?amount=&subid=';
$unSecuredPostBackUrl_2 = 'http://'. getTrackingDomain() .'/tracking202/static/gpb.php?amount=&subid=';

//universal pixel
$unSecuredUniversalPixel = '<iframe height="1" width="1" border="0" style="display: none;" frameborder="0" scrolling="no" src="http://'. getTrackingDomain() .'/tracking202/static/upx.php?amount=" />';

$unSecuredUniversalPixelJS = '
<script>
 var vars202={amount:"",cid:""};(function(d, s) {
 	var js, upxf = d.getElementsByTagName(s)[0], load = function(url, id) {
 		if (d.getElementById(id)) {return;}
 		if202 = d.createElement("iframe");if202.src = url;if202.id = id;if202.height = 1;if202.width = 0;if202.frameBorder = 1;if202.scrolling = "no";if202.noResize = true;
 		upxf.parentNode.insertBefore(if202, upxf);
 	};
 	load("http://'. getTrackingDomain() .'/tracking202/static/upx.php?amount="+vars202[\'amount\']+"&cid="+vars202[\'cid\'], "upxif");
 }(document, "script"));</script>
<noscript>
 	<iframe height="1" width="1" border="0" style="display: none;" frameborder="0" scrolling="no" src="http://'. getTrackingDomain() .'/tracking202/static/upx.php?amount=&cid= seamless></iframe>
</noscript>';

?>

<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<h6>Get your Pixel or Post Back URL</h6>
	</div>
	<div class="col-xs-12">
		<small>By placing a conversion pixel on the advertiser page, everytime you get a
				conversion it will fire and update your conversions
				automatically.<br />
				Watch Conversions in real0time in your spy view! The Post Back URL is
				supported by some networks, this is a Server to Server call.<br />

				Use the options below to generate the type of Pixel or Post Back URL to
				be placed.<br />
		</small>
	</div>
</div>	

<div class="row form_seperator" style="margin-bottom:15px;">
	<div class="col-xs-12"></div>
</div>

<div class="row">
	<div class="col-xs-12">
		<form method="post" id="tracking_form" class="form-horizontal" role="form" style="margin:0px 0px 15px 0px;">
			<div class="form-group" style="margin-bottom: 0px;" id="pixel-type">
				<label class="col-xs-2 control-label" style="text-align: left;">Get Pixel Code For:</label>

				<div class="col-xs-10" style="margin-top: 15px;">
					<label class="radio" style="line-height: 0.5;">
	            		<input type="radio" name="pixel_type" value="0" data-toggle="radio" checked="">
	            			Simple Pixel (only one click can be tracked simultaneously)
	          		</label>
	          		<label class="radio" style="line-height: 0.5;">
	            		<input type="radio" name="pixel_type" value="1" data-toggle="radio">
	            			Advanced Pixel (multiple clicks can be tracked simultaneously)
	          		</label>
	          		<label class="radio" style="line-height: 0.5;">
	            		<input type="radio" name="pixel_type" value="2" data-toggle="radio">
	            			Universal Smart Pixel (Tracks 202 conversions, and intelligently fires 3rd party pixels as needed)
	          		</label>
	          	</div>
	        </div>

	        <div class="form-group" style="margin-bottom: 0px;" id="secure-pixels">
				<label class="col-xs-2 control-label" style="text-align: left;">Secure Link:</label>

				<div class="col-xs-10" style="margin-top: 15px;">
					<div class="row">
						<div class="col-xs-2">
							<label class="radio" style="line-height: 0.5;">
		            			<input type="radio" name="secure_type" value="0" data-toggle="radio" checked="">
		            				No <span class="label label-primary">http://</span>
		          			</label>
						</div>

						<div class="col-xs-2">
							<label class="radio" style="line-height: 0.5;">
			            		<input type="radio" name="secure_type" value="1" data-toggle="radio">
			            			Yes <span class="label label-primary">https://</span>
			          		</label>
						</div>
					</div>
	          	</div>
	        </div>

	        <div class="form-group" style="margin-bottom: 0px;">
				<label class="col-xs-2 control-label" for="amount_value" style="text-align: left;">Amount:</label>
				<div class="col-xs-4" style="margin-top: 10px;">
					<input class="form-control input-sm" type="text" name="amount_value" id="amount_value"/>
					<span class="help-block" style="font-size: 10px;">(enter an amount to override the affiliate campaign default)</span>
				</div>
			</div>

			<div id="advanced_pixel_type" style="display:none;">
				<div class="form-group" style="margin-bottom: 0px;">
			        <label for="aff_network_id" class="col-xs-2 control-label" style="text-align: left;">Category:</label>
			        <div class="col-xs-4" style="margin-top: 10px;">
			        	<img id="aff_network_id_div_loading" src="/202-img/loader-small.gif" />
						<div id="aff_network_id_div"></div>
			        </div>
			    </div>
			    <div class="form-group" style="margin-bottom: 0px;">
			        <label for="aff_campaign_id" class="col-xs-2 control-label" style="text-align: left;">Campaign:</label>
			        <div class="col-xs-4" style="margin-top: 10px;">
			        	<img id="aff_campaign_id_div_loading" src="/202-img/loader-small.gif" style="display: none;" />
						<div id="aff_campaign_id_div">
							<select class="form-control input-sm" id="aff_campaign_id" disabled="">
			                	<option>--</option>
			            	</select>
						</div>
			        </div>
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
		<?php

		printf('
			<div id="pixel_type_simple_id">
				<div class="panel panel-default">
					<div class="panel-heading"><center>Simple Global Tracking Pixel</center></div>
					<div class="panel-body">
						<span class="infotext">Here is the tracking pixel for your p202 account. Give this to the network or advertiser you are working with and ask them to place it on the confirmation page.
						Once placed, it will fire the update your leads automatically when it is fired. Only use a secure https pixel if you have SSL installed.</span><br><br/>
						
						<textarea id="unsecure_pixel" class="form-control" rows="2" style="background-color: #f5f5f5; font-size: 12px;">%s</textarea>
					</div>
				</div>
				<div class="panel panel-default">
					<div class="panel-heading"><center>Simple Global Post Back URL</center></div>
					<div class="panel-body">
						<span class="infotext">If the network you work with supports post back URLs, you can use this URL. The network should use this post-back URL and call it when a lead or sale takes place
						and they should put the SUBID at the end of the url. Once called, it will automatically update your subids and conversion for you.
						Only use a secure https pixel if you have SSL installed.<br/>
						If the network you are working with can only pass the ?sid= variable, you can replace ?subid= with ?sid= </span><br><br/>
						
						<textarea id="unsecure_postback" class="form-control" rows="2" style="background-color: #f5f5f5; font-size: 12px;">%s</textarea>
					</div>
				</div>
			</div>
', $unSecuredPixel, $unSecuredPostBackUrl
		);

		printf('
<div id="pixel_type_advanced_id" style="display:none;">
	<div class="panel panel-default">
		<div class="panel-heading"><center>Advanced Global Tracking Pixel</center></div>
		<div class="panel-body">
			<span class="infotext">Here is the tracking pixel for your p202 account. Give this to the network or advertiser you are working with and ask them to place it on the confirmation page.
						Once placed, it will fire the update your leads automatically when it is fired. Only use a secure https pixel if you have SSL installed.</span><br><br/>
						
			<textarea id="unsecure_pixel_2" class="form-control" rows="2" style="background-color: #f5f5f5; font-size: 12px;">%s</textarea>
		</div>
	</div>

	<div class="panel panel-default">
		<div class="panel-heading"><center>Advanced Global Post Back URL</center></div>
		<div class="panel-body">
			<span class="infotext">If the network you work with supports post back URLs, you can use this URL. The network should use this post-back URL and call it when a lead or sale takes place
						and they should put the SUBID at the end of the url. Once called, it will automatically update your subids and conversion for you.
						Only use a secure https pixel if you have SSL installed.<br/>
						If the network you are working with can only pass the ?sid= variable, you can replace ?subid= with ?sid= </span><br><br/>
						
			<textarea id="unsecure_postback_2" class="form-control" rows="2" style="background-color: #f5f5f5; font-size: 12px;">%s</textarea>
		</div>
	</div>
</div>
', $unSecuredPixel_2, $unSecuredPostBackUrl_2
		);
		
		printf('
<div id="pixel_type_universal_id" style="display:none;">
	<div class="panel panel-default">
		<div class="panel-heading"><center>Javascript Universal Smart Tracking Pixel</center></div>
		<div class="panel-body">
			<span class="infotext">Here is the  Universal Smart Tracking Pixel for your p202 account. Give this to the network or advertiser you are working with and ask them to place it on the confirmation page.
						Once placed, it will fire the update your leads automatically when it is fired. Additionally, it will fire the pixel for the traffic source that genearted this sale or lead.
		Only use a secure https pixel if you have SSL installed.<br/>
		If the network you are working with can only pass the ?sid= variable, you can replace ?subid= with ?sid=</span><br><br/>
						
			<textarea id="unsecure_universal_pixel_js" class="form-control" rows="13" style="background-color: #f5f5f5; font-size: 12px;">%s</textarea>
		</div>
	</div>

	<div class="panel panel-default">
		<div class="panel-heading"><center>Iframe Universal Smart Tracking Pixel</center></div>
		<div class="panel-body">
			<textarea id="unsecure_universal_pixel" class="form-control" rows="2" style="background-color: #f5f5f5; font-size: 12px;">%s</textarea>
		</div>
	</div>

</div>
', $unSecuredUniversalPixelJS, $unSecuredUniversalPixel
		); ?>
	</div>
</div>

<script type="text/javascript">
$(document).ready(function() {
    $("#secure-pixels input:radio").on("toggle", function () {
    	$(this).radio("check");
		change_pixel_data();
    });

    $('#amount_value').keyup(function () { change_pixel_data(); });

	load_aff_network_id();
});

function change_pixel_data(){
	pixel_data_changed("<?php echo getTrackingDomain(); ?>");
}
</script>

<?php template_bottom($server_row); ?>