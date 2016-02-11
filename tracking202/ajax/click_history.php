<?php include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/connect.php'); 

AUTH::require_user();

//if spy is enabled, run the query in a certain way.
	if ($_GET['spy'] == 1) {
		
		$command = "SELECT 2c.click_id, 2c.click_time, 2c.click_alp, text_ad_name, aff_campaign_name, aff_campaign_id_public, landing_page_nickname, ppc_network_name, ppc_account_name, ip_address, keyword, 2c.click_out, click_lead, click_filtered, click_id_public, click_cloaking, 2c.click_referer_site_url_id, click_landing_site_url_id, click_outbound_site_url_id, click_cloaking_site_url_id, click_redirect_site_url_id,	2b.browser_name, 2p.platform_name, 2d.device_name, 202_device_types.type_name, 2cy.country_name, 2cy.country_code, 2rg.region_name, 202_locations_city.city_name, 2is.isp_name, 
2su.site_url_address AS referer,2sd.site_domain_host AS referer_host,
2cl.site_url_address AS landing,2cld.site_domain_host AS landing_host,
2co.site_url_address AS outbound,2cod.site_domain_host AS outbound_host,
2cc.site_url_address AS cloaking,2ccd.site_domain_host AS cloaking_host,
2credir.site_url_address AS redirect,2credird.site_domain_host AS redirect_host
FROM 202_dataengine AS 2c
LEFT JOIN 202_clicks_record USING (click_id)
LEFT JOIN 202_clicks_site AS 2cs ON (2c.click_id = 2cs.click_id) 
LEFT JOIN 202_aff_campaigns AS 2ac ON (2c.aff_campaign_id = 2ac.aff_campaign_id) 
LEFT JOIN 202_ppc_accounts AS 2pa ON (2c.ppc_account_id = 2pa.ppc_account_id)
LEFT JOIN 202_ppc_networks AS 2pn ON (2pa.ppc_network_id = 2pn.ppc_network_id) 
LEFT JOIN 202_landing_pages ON (202_landing_pages.landing_page_id = 2c.landing_page_id) 
LEFT JOIN 202_text_ads AS 2ta ON (2c.text_ad_id = 2ta.text_ad_id) 
LEFT JOIN 202_ips AS 2i ON (2c.ip_id = 2i.ip_id) 
LEFT JOIN 202_keywords AS 2k ON (2c.keyword_id = 2k.keyword_id) 
LEFT JOIN 202_browsers AS 2b ON (2c.browser_id = 2b.browser_id) 
LEFT JOIN 202_platforms AS 2p ON (2c.platform_id = 2p.platform_id) 
LEFT JOIN 202_device_models AS 2d ON (2c.device_id = 2d.device_id) 
LEFT JOIN 202_device_types ON (202_device_types.type_id = 2d.device_type) 
LEFT JOIN 202_locations_country AS 2cy ON (2c.country_id = 2cy.country_id) 
LEFT JOIN 202_locations_region AS 2rg ON (2c.region_id = 2rg.region_id) 
LEFT JOIN 202_locations_city ON (202_locations_city.city_id = 2c.city_id) 
LEFT JOIN 202_locations_isp AS 2is ON (2c.isp_id = 2is.isp_id)
LEFT JOIN 202_site_urls AS 2su ON (2c.click_referer_site_url_id = 2su.site_url_id) 
LEFT JOIN 202_site_urls as 2cl ON (click_landing_site_url_id = 2cl.site_url_id) 
LEFT JOIN 202_site_urls as 2co ON (click_outbound_site_url_id = 2co.site_url_id) 
LEFT JOIN 202_site_urls as 2cc ON (click_cloaking_site_url_id = 2cc.site_url_id) 
LEFT JOIN 202_site_urls as 2credir ON (click_redirect_site_url_id = 2credir.site_url_id) 
LEFT JOIN 202_site_domains AS 2sd ON (2su.site_domain_id = 2sd.site_domain_id) 
LEFT JOIN 202_site_domains as 2cld ON (2cld.site_domain_id = 2cl.site_domain_id) 
LEFT JOIN 202_site_domains as 2cod ON (2cod.site_domain_id = 2co.site_domain_id) 
LEFT JOIN 202_site_domains as 2ccd ON (2ccd.site_domain_id = 2cc.site_domain_id) 
LEFT JOIN 202_site_domains as 2credird ON (2credird.site_domain_id = 2credir.site_domain_id)
";
		
//		$command .= " LEFT JOIN 202_aff_networks AS 2an ON (2ac.aff_network_id = 2an.aff_network_id) ";

		
		$db_table = "2c";
		$query = query($command, $db_table, false, true, true, ' ORDER BY 2c.click_id DESC ', false, 30, true, true);
	} else {
		$command = "SELECT 2c.click_id, 2c.click_time, 2c.click_alp, text_ad_name, aff_campaign_name, aff_campaign_id_public, landing_page_nickname, ppc_network_name, ppc_account_name, ip_address, keyword, 2c.click_out, click_lead, click_filtered, click_id_public, click_cloaking, 2c.click_referer_site_url_id, click_landing_site_url_id, click_outbound_site_url_id, click_cloaking_site_url_id, click_redirect_site_url_id,	2b.browser_name, 2p.platform_name, 2d.device_name, 202_device_types.type_name, 2cy.country_name, 2cy.country_code, 2rg.region_name, 202_locations_city.city_name, 2is.isp_name, 
2su.site_url_address AS referer,2sd.site_domain_host AS referer_host,
2cl.site_url_address AS landing,2cld.site_domain_host AS landing_host,
2co.site_url_address AS outbound,2cod.site_domain_host AS outbound_host,
2cc.site_url_address AS cloaking,2ccd.site_domain_host AS cloaking_host,
2credir.site_url_address AS redirect,2credird.site_domain_host AS redirect_host
FROM 202_dataengine AS 2c
LEFT JOIN 202_clicks_record USING (click_id)
LEFT JOIN 202_clicks_site AS 2cs ON (2c.click_id = 2cs.click_id) 
LEFT JOIN 202_aff_campaigns AS 2ac ON (2c.aff_campaign_id = 2ac.aff_campaign_id) 
LEFT JOIN 202_ppc_accounts AS 2pa ON (2c.ppc_account_id = 2pa.ppc_account_id)
LEFT JOIN 202_ppc_networks AS 2pn ON (2pa.ppc_network_id = 2pn.ppc_network_id) 
LEFT JOIN 202_landing_pages ON (202_landing_pages.landing_page_id = 2c.landing_page_id) 
LEFT JOIN 202_text_ads AS 2ta ON (2c.text_ad_id = 2ta.text_ad_id) 
LEFT JOIN 202_ips AS 2i ON (2c.ip_id = 2i.ip_id) 
LEFT JOIN 202_keywords AS 2k ON (2c.keyword_id = 2k.keyword_id) 
LEFT JOIN 202_browsers AS 2b ON (2c.browser_id = 2b.browser_id) 
LEFT JOIN 202_platforms AS 2p ON (2c.platform_id = 2p.platform_id) 
LEFT JOIN 202_device_models AS 2d ON (2c.device_id = 2d.device_id) 
LEFT JOIN 202_device_types ON (202_device_types.type_id = 2d.device_type) 
LEFT JOIN 202_locations_country AS 2cy ON (2c.country_id = 2cy.country_id) 
LEFT JOIN 202_locations_region AS 2rg ON (2c.region_id = 2rg.region_id) 
LEFT JOIN 202_locations_city ON (202_locations_city.city_id = 2c.city_id) 
LEFT JOIN 202_locations_isp AS 2is ON (2c.isp_id = 2is.isp_id)
LEFT JOIN 202_site_urls AS 2su ON (2c.click_referer_site_url_id = 2su.site_url_id) 
LEFT JOIN 202_site_urls as 2cl ON (click_landing_site_url_id = 2cl.site_url_id) 
LEFT JOIN 202_site_urls as 2co ON (click_outbound_site_url_id = 2co.site_url_id) 
LEFT JOIN 202_site_urls as 2cc ON (click_cloaking_site_url_id = 2cc.site_url_id) 
LEFT JOIN 202_site_urls as 2credir ON (click_redirect_site_url_id = 2credir.site_url_id) 
LEFT JOIN 202_site_domains AS 2sd ON (2su.site_domain_id = 2sd.site_domain_id) 
LEFT JOIN 202_site_domains as 2cld ON (2cld.site_domain_id = 2cl.site_domain_id) 
LEFT JOIN 202_site_domains as 2cod ON (2cod.site_domain_id = 2co.site_domain_id) 
LEFT JOIN 202_site_domains as 2ccd ON (2ccd.site_domain_id = 2cc.site_domain_id) 
LEFT JOIN 202_site_domains as 2credird ON (2credird.site_domain_id = 2credir.site_domain_id) 
";
		$db_table = "2c";
		$query = query($command, $db_table, true, true, true, '  ORDER BY 2c.click_id DESC ', $_POST['offset'], true, true);
	}  

	
//run query
	$click_sql = $query['click_sql'];
	$click_result = $db->query($click_sql) or record_mysql_error($click_sql); 

	$html['from'] = $html['from'] = htmlentities($query['from'], ENT_QUOTES, 'UTF-8');
	$html['to'] = htmlentities($query['to'], ENT_QUOTES, 'UTF-8'); 
	$html['rows'] = htmlentities($query['rows'], ENT_QUOTES, 'UTF-8');  
	?>
	<div class="row" style="margin-top: 10px;">
		<div class="col-xs-6">
			<span class="infotext"><?php printf('<div class="results">Results <b>%s - %s</b> of <b>%s</b></div>',$html['from'],$html['to'],$html['rows']);  ?></span>
		</div>
		<div class="col-xs-6 text-right" style="top: -10px;">
			<img style="margin-bottom:2px;" src="<?php echo get_absolute_url();?>202-img/icons/16x16/page_white_excel.png"/>
			<a style="font-size:12px;" target="_new" href="<?php echo get_absolute_url();?>tracking202/visitors/download/">
				<strong>Download to excel</strong>
			</a>
		</div>
	</div>
	<?php 	 
	
//set the timezone for the user, to display dates in their timezone
	AUTH::set_timezone($_SESSION['user_timezone']);
	
//start displaying the data     
	?>
<div class="row">
	<div class="col-xs-12" style="margin-top: 10px;">
	 <table class="table table-bordered" id="stats-table">
	 	<thead>
			<tr style="background-color: #f2fbfa;">
				<td>Subid</td>
				<td style="text-align:left; padding-left:10px;">Date</td>
				<td>User Agent</td>
				<td>GEO</td>
				<td>ISP/Carrier</td>
				<td>Click</td>
				<td>IP</td>
				<td>PPC Account</td>
				<td>Offer / LP</td>
				<td>Referer</td>
				<td>Text Ad</td>
				<td>Links</td>
				<td>Keyword</td>
			</tr>
		</thead>
		<tbody>
		 
		<?php 	
	
//If this is spy view, the last clicks in the last 5 seconds go into a hidden div, then it is made visible with a scriptialouc affect, so this div contains the clicks iwthin the last 5 seconds
	if ($_GET['spy'] == 1) { 
		$new = true; 
	} 

//if there is no clicks to display let them know :(
	if ($click_result->num_rows == 0) { 
		?><div style="text-align: center; font-size: 14px; border-bottom: 1px rgb(234,234,234) solid; padding: 10px;">You have no data to display with your above filters currently.</div><?php 		if ($_GET['spy'] == 1) { 
			?><div style="text-align: center; font-size: 14px; border-bottom: 1px rgb(234,234,234) solid; padding: 10px;">The spy view only shows clicks activity within the past 24 hours.</div><?php 		}        
	}    
	
//now display all the clicks
	while ($click_row = $click_result->fetch_array(MYSQLI_ASSOC)) {   
								
		$mysql['click_id'] = $db->real_escape_string($click_row['click_id']);
		
	

	
		$html['referer'] = htmlentities($click_row['referer'], ENT_QUOTES, 'UTF-8');   
		$html['referer_host'] = htmlentities($click_row['referer_host'], ENT_QUOTES, 'UTF-8');

		$html['landing'] = htmlentities($click_row['landing'], ENT_QUOTES, 'UTF-8');   
		$html['landing_host'] = htmlentities($click_row['landing_host'], ENT_QUOTES, 'UTF-8');   
		
		$html['outbound'] = htmlentities($click_row['outbound'], ENT_QUOTES, 'UTF-8');   
		$html['outbound_host'] = htmlentities($click_row['outbound_host'], ENT_QUOTES, 'UTF-8');   
		
		//this is alittle different
		if ($click_row['click_cloaking']) {
			
			//if not a landing page
			if (!$click_row['click_alp']) { 
				$html['cloaking'] = htmlentities( 'http://' .$_SERVER['SERVER_NAME'] . get_absolute_url().'tracking202/redirect/cl.php?pci=' . $click_row['click_id_public'] );
				$html['cloaking_host'] = htmlentities( $_SERVER['SERVER_NAME'] );   
			} else { 
				//advanced lander
				$html['cloaking'] = htmlentities( 'http://' .$_SERVER['SERVER_NAME'] . get_absolute_url().'tracking202/redirect/off.php?acip='. $click_row['aff_campaign_id_public'] . '&pci=' . $click_row['click_id_public'] );
				$html['cloaking_host'] = htmlentities( $_SERVER['SERVER_NAME'] );   
			}
		} else {
			$html['cloaking'] = '';
			$html['cloaking_host'] = '';	
		}

		$html['redirect'] = htmlentities($click_row['redirect'], ENT_QUOTES, 'UTF-8');   
		$html['redirect_host'] = htmlentities($click_row['redirect_host'], ENT_QUOTES, 'UTF-8');  
		  
		
		$html['click_id'] = htmlentities($click_row['click_id'], ENT_QUOTES, 'UTF-8');
		$html['click_time'] = date('m/d/y g:ia',$click_row['click_time']); 
		$html['aff_campaign_id'] = htmlentities($click_row['aff_campaign_id'], ENT_QUOTES, 'UTF-8');   
		$html['landing_page_nickname'] = htmlentities($click_row['landing_page_nickname'], ENT_QUOTES, 'UTF-8');   
		$html['ppc_account_id'] = htmlentities($click_row['ppc_account_id'], ENT_QUOTES, 'UTF-8');   
		$html['text_ad_id'] = htmlentities($click_row['text_ad_id'], ENT_QUOTES, 'UTF-8');   
		$html['text_ad_name'] = htmlentities($click_row['text_ad_name'], ENT_QUOTES, 'UTF-8');
		
		if ($click_row['aff_campaign_name'] != null) {
		 	$html['aff_campaign_name'] = htmlentities($click_row['aff_campaign_name'], ENT_QUOTES, 'UTF-8');
		} else {
			$html['aff_campaign_name'] = "Redirector url";
		}

		$html['aff_network_name'] = htmlentities($click_row['aff_network_name'], ENT_QUOTES, 'UTF-8');
		$html['ppc_network_name'] = htmlentities($click_row['ppc_network_name'], ENT_QUOTES, 'UTF-8');
		$html['ppc_account_name'] = htmlentities($click_row['ppc_account_name'], ENT_QUOTES, 'UTF-8');
		$html['ip_address'] = htmlentities($click_row['ip_address'], ENT_QUOTES, 'UTF-8');
		$html['click_cpc'] = htmlentities(dollar_format($click_row['click_cpc']), ENT_QUOTES, 'UTF-8');
		$html['keyword'] = htmlentities($click_row['keyword'], ENT_QUOTES, 'UTF-8');
		$html['click_lead'] = htmlentities($click_row['click_lead'], ENT_QUOTES, 'UTF-8');
		$html['click_filtered'] = htmlentities($click_row['click_filtered'], ENT_QUOTES, 'UTF-8');
		$html['device_name'] = htmlentities($click_row['device_name'], ENT_QUOTES, 'UTF-8');
		$html['browser_name'] = htmlentities($click_row['browser_name'], ENT_QUOTES, 'UTF-8'); 
		$html['platform_name'] = htmlentities($click_row['platform_name'], ENT_QUOTES, 'UTF-8');
		$html['country_code'] = htmlentities($click_row['country_code'], ENT_QUOTES, 'UTF-8');
		$html['country_name'] = htmlentities($click_row['country_name'], ENT_QUOTES, 'UTF-8');
		$html['region_name'] = htmlentities($click_row['region_name'], ENT_QUOTES, 'UTF-8');
		$html['city_name'] = htmlentities($click_row['city_name'], ENT_QUOTES, 'UTF-8');
		$html['isp_name'] = htmlentities($click_row['isp_name'], ENT_QUOTES, 'UTF-8');       
	
		if($html['referer']) {
		    $parsed = parse_url($html['referer']);
		    if (empty($parsed['scheme'])) {
		        $html['referer'] = 'http://' . $html['referer'];
		    }}
		    
		//rotate colors
		$html['row_class'] = 'item';
		if ($x == 0) {
			$html['row_class'] = 'item alt';
			$x=1;
		} else {
			$x--;
		}     
									 
		$ppc_network_icon = pcc_network_icon($click_row['ppc_network_name'],$click_row['ppc_account_name'],$html['referer_host']); 
        
        if (!$click_row['type_name']) {
            $html['device_type'] = '<span id="device-tooltip"><span data-toggle="tooltip" title="Browser: '.$html['browser_name'].'<br/> Platform: '.$html['platform_name'].' <br/>Device: '.$html['device_name'].'"><img title="'.$click_row['type_name'].'" src="'.get_absolute_url().'202-img/icons/platforms/other.png/></span></span>';
        } else {
        	$html['device_type'] = '<span id="device-tooltip"><span data-toggle="tooltip" title="Browser: '.$html['browser_name'].'<br/> Platform: '.$html['platform_name'].' <br/>Device: '.$html['device_name'].'"><img title="'.$click_row['type_name'].'" src="'.get_absolute_url().'202-img/icons/platforms/'.$click_row['type_name'].'.png"/></span></span> <img src="'.get_absolute_url().'202-img/icons/browsers/'.getBrowserIcon($html['browser_name']).'.png">';
        }

        if (!$html['country_code']) {
			$html['country_code'] = 'non';
		}
		
		//if this is an advance landing page, make the offer name, the landing page name
		if ($click_row['click_alp'] == 1) { 
			$html['aff_campaign_name'] = $html['landing_page_nickname'];
		}
		
		
		//before it ends, if this click is past 5 seconds, set true to $endofnewclicks
		$diff = time() - $click_row['click_time']; 
		if (($diff > 5) and ($new == true))  { 
			$new = false; ?>     
		<?php } ?>
		
			<tr <?php if (($diff <= 5) and ($new == true)) {echo 'class="new-click" style="display:none;"';}?>>
				<td id="<?php echo $html['click_id']; ?>"><?php printf('%s', $html['click_id']); ?></td>
				<td style="text-align:left; padding-left:10px;"><?php echo $html['click_time']; ?></td>
				<td class="device_info"><?php echo $html['device_type']; ?></td>
				<td class="geo"><span data-toggle="tooltip" <?php echo 'title="'.$html['country_name'].' ('.$html['country_code'].'), '.$html['city_name'].' ('.$html['region_name'].')"';?>><img src="<?php echo get_absolute_url();?>202-img/flags/<?php echo strtolower($html['country_code']);?>.png"></span></td>
				<td class="isp"><?php if($html['isp_name']) echo $html['isp_name']; else echo "-"?></td>
				<td class="filter">
					<?php if ($click_row['click_filtered'] == '1') { ?>
						  <img style="margin-right: auto;" src="<?php echo get_absolute_url();?>202-img/icons/16x16/delete.png" alt="Filtered Out Click" title="filtered out click"/> 
					<?php } elseif ($click_row['click_lead'] == '1') { ?>
						  <img style="margin-right: auto;" src="<?php echo get_absolute_url();?>202-img/icons/16x16/money_dollar.png" alt="Converted Click" title="converted click" width="16px" height="16px"/> 
					<?php } else { ?>
						  <img style="margin-right: auto;" src="<?php echo get_absolute_url();?>202-img/icons/16x16/add.png" alt="Real Click" title="real click"/> 
					<?php } ?>
				</td>
				<td class="ip"><?php echo $html['ip_address']; ?></td>
				<td class="ppc"><?php echo $ppc_network_icon; ?></td>
				<td class="aff"><?php echo $html['aff_campaign_name']; ?></td>
				<td class="referer_big"><div style="text-overflow: ellipsis; overflow : hidden; white-space: nowrap; width: 150px;" title="<?php if($html['referer']) echo $html['referer']; else echo "-";   ?>"><?php
					printf('<a href="%s" target="_new" title="Referer">%s</a>',$html['referer'],$html['referer_host']);?><?php  ?></div></td>
				<td class="ad"><?php if($html['text_ad_name']) echo $html['text_ad_name']; else echo "-";?></td>
				<td class="referer" >
					<?php if ($html['referer'] != '') { printf('<a href="%s" target="_new" ><img src="%s202-img/icons/16x16/control_end_blue.png" alt="Referer" title="Referer: %s"/></a></div>',$html['referer'],get_absolute_url(),$html['referer']); } ?>
					<?php if ($html['landing'] != '') { printf('<a href="%s" target="_new"><img src="%s202-img/icons/16x16/control_pause_blue.png" alt="Landing"  title="Landing Page: %s"/></a>',$html['landing'],get_absolute_url(),$html['landing']); } ?>
					<?php if (($html['outbound'] != '') and ($click_row['click_out'] == 1)) { printf('<a href="%s" target="_new"><img src="%s202-img/icons/16x16/control_play_blue.png" alt="Outbound" title="Outbound: %s"/></a>',$html['outbound'],get_absolute_url(),$html['outbound']); } ?>
					<?php if (($html['cloaking'] != '') and ($click_row['click_out'] == 1)) { printf('<a href="%s" target="_new"><img src="%s202-img/icons/16x16/control_equalizer_blue.png" alt="Cloaking" title="Cloaked Referer: %s"/></a>',$html['cloaking'],get_absolute_url(),$html['cloaking']); } ?>
					<?php if (($html['redirect'] != '') and ($click_row['click_out'] == 1)) { printf('<a href="%s" target="_new"><img src="%s202-img/icons/16x16/control_fastforward_blue.png" alt="Redirection" title="Redirect: %s"/></a>',$html['redirect'],get_absolute_url(),$html['redirect']); } ?>
				</td>
				<td class="keyword"><div style="text-overflow: ellipsis; overflow : hidden; white-space: nowrap; width: 250px;" title="<?php if($html['keyword']) echo $html['keyword']; else echo "-";   ?>"><?php if($html['keyword']) echo "<em>".$html['keyword']."</em>"; else echo "-"; ?></div></td>
			
			</tr>
			</div>
	<?php  } ?>
	</tbody>
</table>
<script type="text/javascript">
	//tooltips int
	$("[data-toggle=tooltip]").tooltip({html: true});
</script>
</div>
</div>

<?php if (($query['pages'] > 2) and ($_GET['spy'] != 1)) { ?>
<div class="row">
<div class="col-xs-12 text-center">
	<div class="pagination" id="table-pages">
	    <ul>
			<?php if ($query['offset'] > 0) {
					printf(' <li class="previous"><a class="fui-arrow-left" onclick="loadContent(\'%tracking202/ajax/click_history.php\',\'%s\',\'%s\');"></a></li>',get_absolute_url(), $query['offset'] - 1, $html['order']);
				}

				if ($query['pages'] > 1) {
					for ($i=0; $i < $query['pages']-1; $i++) {
						if (($i >= $query['offset'] - 10) and ($i < $query['offset'] + 11)) {
							if ($query['offset'] == $i) { $class = 'class="active"'; }
							printf(' <li %s><a onclick="loadContent(\'%stracking202/ajax/click_history.php\',\'%s\',\'%s\');">%s</a></li>', $class, get_absolute_url(), $i, $html['order'], $i+1);
							unset($class);
						}
					}
				}

				if ($query['offset'] > 0) {
					printf(' <li class="next"><a class="fui-arrow-right" onclick="loadContent(\'%stracking202/ajax/click_history.php\',\'%s\',\'%s\');"></a></li>', get_absolute_url(), $query['offset'] + 1, $html['order']);
				}
			?>
		</ul>
	</div>
	</div>
</div>
<?php } ?>



