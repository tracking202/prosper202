<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();

template_top($server_row,'Get Simple Landing Page Code',NULL,NULL,NULL);  ?>

<div id="info">
	<h2>Setup a Simple Landing Page</h2>
	Here is where you need to setup your landing pages, installing the javascript and PHP code prior to getting your Text Ad Tracking Urls.
</div>


	<form id="tracking_form" method="post">
		<table class="setup">
			<tr>
				<td class="left_caption">Affiliate Network</td>
				<td>
                    <img id="aff_network_id_div_loading" style="display: none;" src="http://<? echo $_SERVER['STATIC_SERVER_NAME']; ?>/images/loader-small.gif"/>
                    <div id="aff_network_id_div"></div>
                </td>
			</tr>                                             
			<tr>
				<td class="left_caption">Campaign</td>
				<td>
					<img id="aff_campaign_id_div_loading" style="display: none;" src="/202-img/loader-small.gif"/>
					<div id="aff_campaign_id_div"></div>
				</td>
			</tr>
			<tr style="display: none;">
				<td class="left_caption">Ad Copy</td>
				<td>
					<img id="text_ad_id_div_loading" style="display: none;" src="/202-img/loader-small.gif"/>
					<div id="text_ad_id_div"></div>
				</td>
			</tr>
			<tr style="display: none;">
				<td class="left_caption">Ad Preview</td>
				<td>
					<img id="ad_preview_div_loading" style="display: none;" src="/202-img/loader-small.gif"/>
					<div id="ad_preview_div"></div>
				</td>
			</tr>
			<tr>
				<td class="left_caption">Method of Promotion</td>
				<td>
					<select id="method_of_promotion"  name="method_of_promotion">
						<option value="landingpage" selected="">Landing Page</option>
					</select>
				</td>
			</tr>
			<tr valign="top">
				<td class="left_caption">Landing Page</td>
				<td>
					<img id="landing_page_div_loading" style="display: none;" src="/202-img/loader-small.gif"/>
					<div id="landing_page_div" style="display: none;"></div>
				</td>
			</tr>
		</table>
	</form>
	<table style="margin: 5px auto;">
		<tr>
			<td>
				<button onclick="   $('tracking_link_loading').style.display='inline';
									$('tracking_link').style.display='none';
									new Ajax.Updater('tracking_link', '../ajax/get_landing_code.php', 
									{
										parameters: $('tracking_form').serialize(true),
										onSuccess: function() { 
											$('tracking_link_loading').style.display='none';
											$('tracking_link').style.display='block';   
										}
									});">Generate Tracking Link</button>
			</td>
			<td>
				<img id="tracking_link_loading" style="display: none;" src="/202-img/loader-small.gif"/>      
			</td>
		</tr>
	</table>           
	<div id="tracking_link" style="width: 700px; margin: 0px auto;">
	
	</div>
																					
	<!-- open up the ajax aff network -->
	<script type="text/javascript">
        load_aff_network_id(0);
        /*load_aff_campaign_id(0,0);
		load_text_ad_id(0,0);
		load_ad_preview(0);*/ 
		load_method_of_promotion('landingpage');
		/*load_landing_page(0, 0, '');*/
		load_ppc_network_id(0);
        /*load_ppc_account_id(0,0);*/        
	</script>
		
<? template_bottom($server_row);