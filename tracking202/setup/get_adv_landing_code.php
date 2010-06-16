<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();

template_top($server_row,'Get Advanced Landing Page Code',NULL,NULL,NULL);  ?>

	<div id="info">
		<h2>Setup an Advanced Landing Page - Get Code</h2>
		Select what landing page you wish to use, and then add all the different campaigns you plan on running with the landing page.<br/><br/>
</div>


	<h1></h1>

	
	<form id="tracking_form" method="post">
		<input type="hidden" id="counter" name="counter" value="0"/>
		<table class="setup">
			<tr>
				<td class="left_caption">Landing Page</td>
				<td>
					<select name="landing_page_id" id="landing_page_id" onchange="">					
						<option value="0"> -- </option> <?
						$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
						$landing_page_sql = "SELECT * FROM 202_landing_pages WHERE user_id='".$mysql['user_id']."' AND landing_page_type='1' AND landing_page_deleted='0'";
						$landing_page_result = _mysql_query($landing_page_sql); // or record_mysql_error($landing_page_sql);
						while ($landing_page_row = mysql_fetch_array($landing_page_result, MYSQL_ASSOC)) {
							$html['landing_page_id'] = htmlentities($landing_page_row['landing_page_id'], ENT_QUOTES, 'UTF-8');
							$html['landing_page_nickname'] = htmlentities($landing_page_row['landing_page_nickname'], ENT_QUOTES, 'UTF-8');
							printf('<option value="%s">%s</option>', $html['landing_page_id'], $html['landing_page_nickname']); 
						} ?>
					</select>
				</td>
			</tr>
			<tr>
				<td colspan="2"><p>Now select what offers you are going to run on on this landing page, you can add as many as you want.</p></td>
			</tr>
			<tr valign="top">
				<td class="left_caption">Select Offers</td>
				<td valign="middle">
					<div id="area_1">
						<select name="aff_campaign_id_1" id="aff_campaign_id_1" onchange="">
							<option value="0"> -- </option> 	
							<? 	$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
								$aff_campaign_sql = "SELECT aff_campaign_id, aff_campaign_name, aff_network_name FROM 202_aff_campaigns LEFT JOIN 202_aff_networks USING (aff_network_id) WHERE 202_aff_campaigns.user_id='".$mysql['user_id']."' AND aff_campaign_deleted='0' AND aff_network_deleted=0 ORDER BY aff_network_name ASC";
							
								$aff_campaign_result = _mysql_query($aff_campaign_sql); // or record_mysql_error($aff_campaign_sql);
								while ($aff_campaign_row = mysql_fetch_assoc($aff_campaign_result)) { 
									$html['aff_campaign_id'] = htmlentities($aff_campaign_row['aff_campaign_id'], ENT_QUOTES, 'UTF-8');
									$html['aff_campaign_name'] = htmlentities($aff_campaign_row['aff_campaign_name'], ENT_QUOTES, 'UTF-8');
									$html['aff_network_name'] = htmlentities($aff_campaign_row['aff_network_name'], ENT_QUOTES, 'UTF-8');
									printf('<option value="%s">%s: %s</option>', $html['aff_campaign_id'], $html['aff_network_name'], $html['aff_campaign_name']); 
								} ?>
						</select>
						<a class="onclick_color" onclick="remove_area(1);">[remove]</a>
					</div>
					<div id="new_aff_campaigns">
						<img id="load_aff_campaign_1_loading" style="display: none;" src="/202-img/loader-small.gif"/>
						<div id="load_aff_campaign_1"></div>
					</div>
				</td>
			</tr>
		</table>
	</form>
	<script type="text/javascript">
		
		function remove_area(counter) {
			$('area_'+counter).parentNode.removeChild( $('area_'+counter) );	
			
		} 
	
		function load_new_aff_campaign() { 
				var counter = $('counter').value;
				counter++;
				
			if($('load_aff_campaign_'+counter+'_loading')) {
				$('counter').value = counter;

				$('load_aff_campaign_'+counter+'_loading').style.display='block';
				$('load_aff_campaign_'+counter).style.display='none';   
				new Ajax.Updater('load_aff_campaign_'+counter, '../ajax/adv_landing_pages.php',  {
					parameters: $('tracking_form').serialize(true),
					onSuccess: function() { 
						$('load_aff_campaign_'+counter+'_loading').style.display='none';
						$('load_aff_campaign_'+counter).style.display='block';   
					}
				});
			}
		}
		
		function get_adv_landing_code() { 
			$('tracking_link_loading').style.display='block';
			$('tracking_link').style.display='none';   
			new Ajax.Updater('tracking_link', '../ajax/get_adv_landing_code.php',  {
				parameters: $('tracking_form').serialize(true),
				onSuccess: function() { 
					$('tracking_link_loading').style.display='none';
					$('tracking_link').style.display='block';   				
				}
			});
				
		}
		
	</script>
	
	<table style="margin: 5px auto;">
		<tr>
			<td>
				<button onclick="load_new_aff_campaign(); return false;">Add Another Offer To This Page</button>
				<button onclick="get_adv_landing_code();">Get Landing Page Codes</button>
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