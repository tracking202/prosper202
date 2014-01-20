<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();

template_top($server_row,'Get Trackers',NULL,NULL,NULL);  ?>

<div id="info">
	<h2>Get the Destination URLs to be used in your Text Ads</h2>
	Here is where you generate your tracking links to be used in your PPC advertisements.  Please make sure to test your links.<br/>If you are using a landing page, you should have already installed your landing page code prior to coming to this step.
</div>

	<form id="tracking_form" method="post">
		<table class="setup">
			<tr valign="top">
				<td class="left_caption">Get Text Ad Code For</td>	
				<td>
					<input type="radio" name="tracker_type" value="0" onClick="tracker_select(this.value);" CHECKED > Direct Link Setup, or Simple Landing Page Setup<br/>
					<input type="radio" name="tracker_type" value="1" onClick="tracker_select(this.value);"> Advanced Landing Page Setup
					<? echo $error['landing_page_type']; ?>
				</td>
			</tr>
			
			<tr><td colspan="2"><hr/></td></tr>
			
			<tr  id="tracker_aff_network" >
				<td class="left_caption">Affiliate Network</td>
				<td>
	                    <img id="aff_network_id_div_loading" style="display: none;" src="/202-img/loader-small.gif"/>
	                    <div id="aff_network_id_div"></div>
	                </td>
			</tr>                                             
			<tr id="tracker_aff_campaign">
				<td  class="left_caption">Campaign</td>
				<td>
					<img id="aff_campaign_id_div_loading" style="display: none;" src="/202-img/loader-small.gif"/>
					<div id="aff_campaign_id_div"></div>
				</td>
			</tr>
			<tr  id="tracker_method_of_promotion">
				<td class="left_caption">Method of Promotion</td>
				<td>
					<img id="method_of_promotion_div_loading" style="display: none;" src="/202-img/loader-small.gif"/>
					<div id="method_of_promotion_div" style="display: none;">
						
					</div>
				</td>
			</tr>
			<tr valign="top">
				<td class="left_caption">Landing Page</td>
				<td>
					<img id="landing_page_div_loading" style="display: none;" src="/202-img/loader-small.gif"/>
					<div id="landing_page_div" style="display: none;"></div>
				</td>
			</tr>
			<tr>
				<td class="left_caption">Ad Copy</td>
				<td>
					<img id="text_ad_id_div_loading" style="display: none;" src="/202-img/loader-small.gif"/>
					<div id="text_ad_id_div"></div>
				</td>
			</tr>
			<tr valign="top">
				<td class="left_caption">Ad Preview</td>
				<td>
					<img id="ad_preview_div_loading" style="display: none;" src="/202-img/loader-small.gif"/>
					<div id="ad_preview_div"></div>
				</td>
			</tr>
			
			
            <tr>
                <td class="left_caption">Cloaking</td>
                <td style="white-space: nowrap;">
				<select name="click_cloaking">
					<option value="-1">Campaign Default On/Off</option>
                        	<option value="0">Off - Overide Campaign Default</option>
					<option value="1">On - Override Campaign Default</option>
                    </select>
                </td>
            </tr>
			<tr>
				<td class="left_caption">PPC Network</td>
				<td>
					<img id="ppc_network_id_div_loading" style="display: none;" src="/202-img/loader-small.gif"/>
                    		<div id="ppc_network_id_div"></div>
				</td>
			</tr>
			<tr>
				<td class="left_caption">PPC Account</td>
				<td>
					<img id="ppc_account_id_div_loading" style="display: none;" src="/202-img/loader-small.gif"/>
					<div id="ppc_account_id_div"></div>
				</td>
			</tr>
			<tr>
				<td class="left_caption">Max CPC</td>
				<td valign="center"> $ <input type="text" name="cpc_dollars" maxlength="2" value="0" style="width: 15px; text-align: right;"/>.<input type="text" name="cpc_cents" maxlength="5" value="00" style="width: 40px; text-align: left;"/> <span style="font-size: 11px; padding-left: 10px;">you can now enter cpc amounts as small as 0.00001</span>
				</td>
			</tr>
			<tr>
				<td></td>
			<tr>
				<td class="left_caption">Tracking ID c1</td>
				<td valign="center">
					<input type="text" name="c1" />
					<span style="font-size: 11px; padding-left: 10px;">c1-c4 variables must be no longer than 350 characters.</span>
				</td>
			</tr>
			<tr>
				<td class="left_caption">Tracking ID c2</td>
				<td valign="center"><input type="text" name="c2" /></td>
			</tr>
			<tr>
				<td class="left_caption">Tracking ID c3</td>
				<td valign="center"><input type="text" name="c3" /></td>
			</tr>
			<tr>
				<td class="left_caption">Tracking ID c4</td>
				<td valign="center"><input type="text" name="c4" /></td>
			</tr>
		</table>
	</form>
	<table style="margin: 5px auto;">
		<tr>
			<td>
				<button onclick="   $('tracking_link_loading').style.display='inline';
									$('tracking_link').style.display='none';
									new Ajax.Updater('tracking_link', '../ajax/generate_tracking_link.php', 
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
	<div id="tracking_link" style="width: 500px; margin: 0px auto;">
	
	</div>
																					
	<!-- open up the ajax aff network -->
	<script type="text/javascript">
        load_aff_network_id(0);
        /*load_aff_campaign_id(0,0);
		load_text_ad_id(0,0);
		load_ad_preview(0);*/ 
		load_method_of_promotion('');
		/*load_landing_page(0, 0, '');*/
		load_ppc_network_id(0);
        /*load_ppc_account_id(0,0);*/        
	</script>
	
	
					<script type="text/javascript">
						function tracker_select(tracker_type) {
							if (tracker_type == '0') { 
								$('tracker_aff_network').style.display = 'table-row';
								$('tracker_aff_campaign').style.display = 'table-row';
								$('tracker_method_of_promotion').style.display = 'table-row';
								load_aff_network_id(0);
								load_aff_campaign_id(0,0);
								load_landing_page(0, 0, '');
							} else if (tracker_type == '1') {
								$('tracker_aff_network').style.display = 'none';
								$('tracker_aff_campaign').style.display = 'none';
								$('tracker_method_of_promotion').style.display = 'none';
								load_aff_network_id(0);
								load_aff_campaign_id(0,0);
								load_landing_page(0, 0, 'advlandingpage');
							}	
						}
					</script>	
		
<? template_bottom($server_row);