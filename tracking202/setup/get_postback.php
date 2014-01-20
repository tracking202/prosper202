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

?>

<script type="text/javascript">
	function pixel_type_select(pixel_type) {
		if (pixel_type == '0') { 
			$('pixel_type_simple_id').show();
			$('pixel_type_advanced_id').hide();
			$('advanced_pixel_type_tbody').hide();
			$('pixel_type_universal_id').hide();
		} else if (pixel_type == '1') {
			$('pixel_type_simple_id').hide();
			$('pixel_type_advanced_id').show();
			$('advanced_pixel_type_tbody').show();
			$('pixel_type_universal_id').hide();
		} else if (pixel_type == '2') {
			$('pixel_type_simple_id').hide();
			$('pixel_type_advanced_id').hide();
			$('advanced_pixel_type_tbody').hide();
			$('pixel_type_universal_id').show();
			
		}	
	}
	function pixel_data_changed() {
		var pixel_code = '<img height="1" width="1" border="0" style="display: none;" src="{0}://' + '<?php echo getTrackingDomain() ?>' + '/tracking202/static/gpx.php?amount={1}" />';
		var pixel_code_2 = '<img height="1" width="1" border="0" style="display: none;" src="{0}://' + '<?php echo getTrackingDomain() ?>' + '/tracking202/static/gpx.php?amount={1}&cid={2}" />';
		
		var postback_code = '{0}://' + '<?php echo getTrackingDomain() ?>' + '/tracking202/static/gpb.php?amount={1}&subid=';
		var postback_code_2 = '{0}://' + '<?php echo getTrackingDomain() ?>' + '/tracking202/static/gpb.php?amount={1}&cid={2}&subid=';
		
		var universal_pixel_code = '<iframe height="1" width="1" border="0" style="display: none;" frameborder="0" scrolling="no" src="{0}://' + '<?php echo getTrackingDomain() ?>' + '/tracking202/static/upx.php?amount={1}" />';
		var universal_pixel_code_2 = '<iframe height="1" width="1" border="0" style="display: none;" frameborder="=" scrolling="no" src="{0}://' + '<?php echo getTrackingDomain() ?>' + '/tracking202/static/upx.php?amount={1}&cid={2}" />';

		var pixelTypeValue = Form.getInputs('pixel_form','radio','pixel_type').find(function(radio) { return radio.checked; }).value;
		var secureTypeValue = Form.getInputs('pixel_form','radio','secure_type').find(function(radio) { return radio.checked; }).value;

		var http_val = 'http';
		if(secureTypeValue==1) {
			var http_val = 'https';
		}
		
		var amount_value = $('amount_value').getValue();
		var campaign_id_value = '';
		if($('aff_campaign_id')) {
			campaign_id_value = $('aff_campaign_id').getValue();
		}
		
		$('unsecure_pixel').setValue(pixel_code.gsub(/\{0\}/,http_val).gsub(/\{1\}/,amount_value));
		$('unsecure_pixel_2').setValue(pixel_code_2.gsub(/\{0\}/,http_val).gsub(/\{1\}/,amount_value).gsub(/\{2\}/,campaign_id_value));
		$('unsecure_postback').setValue(postback_code.gsub(/\{0\}/,http_val).gsub(/\{1\}/,amount_value));
		$('unsecure_postback_2').setValue(postback_code_2.gsub(/\{0\}/,http_val).gsub(/\{1\}/,amount_value).gsub(/\{2\}/,campaign_id_value));
		$('unsecure_universal_pixel').setValue(universal_pixel_code.gsub(/\{0\}/,http_val).gsub(/\{1\}/,amount_value));
		$('unsecure_universal_pixel_2').setValue(universal_pixel_code_2.gsub(/\{0\}/,http_val).gsub(/\{1\}/,amount_value).gsub(/\{2\}/,campaign_id_value));
	}
</script>

<div id="info">
<h2>Get your Pixel or Post Back URL</h2>
By placing a pixel on the advertiser page, everytime you get a
conversion it will fire a tracking pixel and update your subids
automatically.<br />
Watch Conversions in REAL-TIME in your SPY view! The Post Back URL is
supported by some networks, this will automatically post back to<br />
T202 when you get a lead and again, automatically update your subids.<br />
Use the options below to generate the type of Pixel or Post Back URL to
be placed.<br />
</div>

<form name="pixel_form" id="pixel_form">
<table class="setup">
	<thead>
		<tr valign="top">
			<td class="left_caption">Get Pixel Code For:</td>
			<td><input type="radio" name="pixel_type" value="0"
				onClick="pixel_type_select(this.value);" checked="checked" /> Simple
			Pixel (only one click can be tracked simultaneously)<br />
			<input type="radio" name="pixel_type" value="1"
				onClick="pixel_type_select(this.value);" /> Advanced Pixel (multiple
			clicks can be tracked simultaneously)<br />
			<input type="radio" name="pixel_type" value="2"
				onClick="pixel_type_select(this.value);" /> Universal Smart Pixel
			(Tracks 202 conversions, and intelligently fires 3rd party pixels as
			needed)</td>
		</tr>
		<tr valign="top">
			<td class="left_caption">Secure Link:</td>
			<td><input type="radio" name="secure_type" value="0"
				onchange="pixel_data_changed()" checked="checked" /> http:// <input
				type="radio" name="secure_type" value="1"
				onchange="pixel_data_changed()" /> https:// <br />
			<span style="color: #900;">(https:// will only work if your domain
			has an SSL installed)</span></td>
		</tr>
		<tr valign="top">
			<td class="left_caption">Amount:</td>
			<td><input type="text" value="" onkeyup="pixel_data_changed()"
				id="amount_value" /> <br />
			<span style="color: #900;">(enter an amount to override the affiliate
			campaign default)</span></td>
		</tr>
	</thead>
	<tbody id="advanced_pixel_type_tbody" style="display: none;">
		<tr id="lp_aff_network"
		<? if ($html['landing_page_type'] == '1') { echo ' style="display:none;"'; } ?>>
			<td class="left_caption">Aff Network:</td>
			<td><img id="aff_network_id_div_loading"
				src="/202-img/loader-small.gif" />
			<div id="aff_network_id_div" style="display: inline;"></div>
			</td>
		</tr>
		<tr id="lp_aff_campaign"
		<? if ($html['landing_page_type'] == '1') { echo ' style="display:none;"'; } ?>>
			<td class="left_caption">Aff Campaign:</td>
			<td><img id="aff_campaign_id_div_loading"
				src="/202-img/loader-small.gif" style="display: none;" />
			<div id="aff_campaign_id_div" style="display: inline;"></div>
			</td>
		</tr>
	</tbody>
</table>
</form>
<script type="text/javascript">
    
   load_aff_network_id();
   
</script>

		<?php

		echo '<style> textarea.code_snippet { width: 100%; height: 40px; } </style>';

		printf('
<div id="pixel_type_simple_id">
<h2>Simple Global Tracking Pixel</h2>
		Here is the tracking pixel for your t202 account. Give this to the network or advertiser you are working with and ask them to place it on the confirmation page.
		With the pixel placed on the confirmation page, everytime you get a lead or sale, it will fire the pixel and update your leads automatically when this pixel fires.
		If you are confused about which pixel you need (secured or unsecured), please contact the advertiser or network and they should be able to tell you which one you\'ll need.<br/><br/>
		
		<textarea class="code_snippet" id="unsecure_pixel">%s</textarea><br/>
<h2>Simple Global Post Back URL</h2>
		If the network you work with supports post back URLs, you can use this URL. The network should use this post-back URL and call it when a lead or sale takes place
		and they should put the SUBID at the end of the url. When the post back url is called it should automatically update your subids for you.
		If you are confused about which link you need (secured or unsecured), please contact the advertiser or network and they should be able to tell you which one you\'ll need.<br/><br/>
		If the affiliate network you are working with can only pass the ?sid= variable, you can replace ?subid= with ?sid= <br/><br/>

		<textarea class="code_snippet" id="unsecure_postback">%s</textarea><br/>
</div>
', $unSecuredPixel, $unSecuredPostBackUrl
		);

		printf('
<div id="pixel_type_advanced_id" style="display:none;">
<h2>Advanced Global Tracking Pixel</h2>
		Here is the tracking pixel for your t202 account. Give this to the network or advertiser you are working with and ask them to place it on the confirmation page.
		With the pixel placed on the confirmation page, everytime you get a lead or sale, it will fire the pixel and update your leads automatically when this pixel fires. For different confirmation pages on potentially the same traffic, you can change the cid to indicate which click should be tracked. If traffic is being split into multiple clicks on multiple campaigns, using the appropriate cid guarantees that the correct click will be tracked on the correct campaign.
		If you are confused about which pixel you need (secured or unsecured), please contact the advertiser or network and they should be able to tell you which one you\'ll need.<br/><br/>

		<textarea class="code_snippet" id="unsecure_pixel_2">%s</textarea><br/>
<h2>Advanced Global Post Back URL</h2>
		If the network you work with supports post back URLs, you can use this URL. The network should use this post-back URL and call it when a lead or sale takes place
		and they should put the SUBID at the end of the url. When the post back url is called it should automatically update your subids for you.
		If you are confused about which link you need (secured or unsecured), please contact the advertiser or network and they should be able to tell you which one you\'ll need.<br/><br/>
		If the affiliate network you are working with can only pass the ?sid= variable, you can replace ?subid= with ?sid= <br/><br/>

		<textarea class="code_snippet" id="unsecure_postback_2">%s</textarea><br/>
</div>
', $unSecuredPixel_2, $unSecuredPostBackUrl_2
		);
		
		printf('
<div id="pixel_type_universal_id" style="display:none;">
<h2>Universal Smart Tracking Pixel</h2>
		Here is the universal smart tracking pixel for your t202 account. Give this to the network or advertiser you are working with and ask them to place it on the confirmation page.
		With the pixel placed on the confirmation page, everytime you get a lead or sale, it will fire the pixel and update your leads automatically when this pixel fires. Additionally, it will fire the pixel for the traffic source that genearted this sale or lead.
		If you are confused about which pixel you need (secured or unsecured), please contact the advertiser or network and they should be able to tell you which one you\'ll need.<br/><br/>
		
		<textarea class="code_snippet" id="unsecure_universal_pixel">%s</textarea><br/>

</div>
', $unSecuredUniversalPixel
		);		

		template_bottom($server_row);