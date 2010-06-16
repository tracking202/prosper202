<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();

//this deletes all this users cached data to the old result sets, we want new stuff because they just updated old clicks
	memcache_delete_user_keys();

template_top('Update CPC',NULL,NULL,NULL);  ?>

<div id="info">
	<h2>Here is where you can update your CPCs</h2>
	Because T202 assumes that you are paying full CPC each time, we understand you won't be paying this each time.  So to refine your stats you can update your old history's cpc to make them more accurate.  Simply choose your setup below to update your cpc for a specific time period, and a specific set of variables.
</div>

	<form id="cpc_form" method="post">
		<table class="setup">
			<tr valign="top">
				<td class="left_caption">Adjust CPC For</td>	
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
				<td colspan="2"><br/></td> 
			</tr>
			<tr>
				<td class="left_caption">From</td>
				<td>
					<input onclick="$('from_cal').style.display='block'; $('to_cal').style.display='none'; " type="text" name="from" id="from" value="mm/dd/yy"/>
					<div id="from_cal" class="scal tinyscal" style="position: absolute; z-index: 10; display: none;"></div>
					<script type="text/javascript">
							var options = ({
							updateformat: 'mm/dd/yyyy'			    
						});
						var from_cal = new scal('from_cal','from', options);
					</script>
				</td>
			</tr>
			<tr>
				<td class="left_caption">To</td>
				<td>
					<input onclick="$('from_cal').style.display='none'; $('to_cal').style.display='block'; " type="text" name="to" id="to" value="mm/dd/yy"/>
					<div id="to_cal" class="scal tinyscal" style="position: absolute; z-index: 10; display: none;"></div>
					<script type="text/javascript">
							var options = ({
							updateformat: 'mm/dd/yyyy'			    
						});
						var to_cal = new scal('to_cal','to', options);
					</script>
				</td>

			</tr>
			<tr>
				<td colspan="2"><br/></td> 
			</tr>
			<tr>
				<td class="left_caption">New CPC</td>
				<td valign="center"> $ <input type="text" name="cpc_dollars" maxlength="2" value="0" style="width: 15px; text-align: right;"/>.<input type="text" name="cpc_cents" maxlength="5" value="00" style="width: 50px;"/>
				</td>
			</tr>
			<tr>
				<td colspan="2"><br/></td> 
			</tr>
		</table>
	</form>
	<table style="margin: 5px auto;">
		<tr>
			<td>
				<button onclick="update_cpc();">Update CPC</button>
			</td>
			<td><img id="update_cpc_loading" style="display: none;" src="/202-img/loader-small.gif"/></td>
		</tr>
	</table>           
	<div id="update_cpc" style="width: 500px; margin: 0px auto;"></div>
																					
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

	function update_cpc() { 
		$('update_cpc_loading').style.display='inline';
		$('update_cpc').style.display='none';
		new Ajax.Updater('update_cpc', '../ajax/update_cpc.php', 
		{
			method: 'post',
			parameters: $('cpc_form').serialize(true),
			onSuccess: function() { 
				$('update_cpc_loading').style.display='none';
				$('update_cpc').style.display='block';   
			}
		});	
	}
	
	function update_cpc2() { 
	
		$('update_cpc2_loading').style.display='inline';
		$('update_cpc2').style.display='none';
		new Ajax.Updater('update_cpc2', '../ajax/update_cpc2.php', 
		{
			parameters: $('cpc_form').serialize(true),
			onSuccess: function() { 
				$('update_cpc2_loading').style.display='none';
				$('update_cpc2').style.display='block';   
			}
		});    
		
	}

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
		
<? template_bottom();