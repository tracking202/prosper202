<?php include_once(substr(dirname( __FILE__ ), 0,-30) . '/202-config/connect.php'); 
	
//make sure user is logged in or die
	AUTH::require_user();
	
//start displaying the data     
	header("Content-type: application/octet-stream");
	
# replace excelfile.xls with whatever you want the filename to default to
	header("Content-Disposition: attachment; filename=T202_visitors_".time().".xls");
	header("Pragma: no-cache");
	header("Expires: 0");  
		
	
//get stuff
	$command = "SELECT 2c.click_id, 2c.click_time, 2c.click_alp, text_ad_name, aff_campaign_name, aff_campaign_id_public, landing_page_nickname, ppc_network_name, ppc_account_name, ip_address, keyword, 2c.click_out, click_lead, click_filtered, click_id_public, click_cloaking, 2c.click_referer_site_url_id, click_landing_site_url_id, click_outbound_site_url_id, click_cloaking_site_url_id, click_redirect_site_url_id,	2b.browser_name, 2p.platform_name, 2d.device_name, 202_device_types.type_name, 2cy.country_name, 2cy.country_code, 2rg.region_name, 202_locations_city.city_name, 2is.isp_name, 
2su.site_url_address AS referer,2sd.site_domain_host AS referer_host,
2cl.site_url_address AS landing,2cld.site_domain_host AS landing_host,
2co.site_url_address AS outbound,2cod.site_domain_host AS outbound_host,
2cc.site_url_address AS cloaking,2ccd.site_domain_host AS cloaking_host,
2credir.site_url_address AS redirect,2credird.site_domain_host AS redirect_host,
gclid,
2uca.utm_campaign,2uco.utm_content,2um.utm_medium,2us.utm_source,2ut.utm_term
FROM 202_dataengine AS 2c
LEFT JOIN 202_clicks_record USING (click_id) 
LEFT JOIN 202_clicks_site AS 2cs ON (2c.click_id = 2cs.click_id) 
LEFT JOIN 202_aff_campaigns AS 2ac ON (2c.aff_campaign_id = 2ac.aff_campaign_id) 
LEFT JOIN 202_ppc_accounts AS 2pa ON (2c.ppc_account_id = 2pa.ppc_account_id)
LEFT JOIN 202_ppc_networks AS 2pn ON (2pa.ppc_network_id = 2pn.ppc_network_id) 
LEFT JOIN 202_landing_pages ON (202_landing_pages.landing_page_id = 2c.landing_page_id) 
LEFT JOIN 202_text_ads ON (202_text_ads.text_ad_id = 2c.text_ad_id) 
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
LEFT JOIN 202_google as 2g ON (2g.click_id = 2c.click_id )
LEFT JOIN 202_utm_campaign AS 2uca ON (2g.utm_campaign_id = 2uca.utm_campaign_id) 
LEFT JOIN 202_utm_content AS 2uco ON (2g.utm_content_id = 2uco.utm_content_id) 
LEFT JOIN 202_utm_medium AS 2um ON (2g.utm_medium_id = 2um.utm_medium_id) 
LEFT JOIN 202_utm_source AS 2us ON (2g.utm_source_id = 2us.utm_source_id) 
LEFT JOIN 202_utm_term AS 2ut ON (2g.utm_term_id = 2ut.utm_term_id) ";
	$db_table = "2c";
	$query = query($command, $db_table, true, true, true, '  ORDER BY 2c.click_id DESC ', $_POST['offset'], false, false);
	

//run query
	$click_sql = $query['click_sql'];
	$click_result = $db->query($click_sql) or record_mysql_error($click_sql); 
	//echo $click_sql;	
//html escape vars
	$html['from'] = htmlentities($query['from'], ENT_QUOTES, 'UTF-8');
	$html['to'] = htmlentities($query['to'], ENT_QUOTES, 'UTF-8'); 
	$html['rows'] = htmlentities($query['rows'], ENT_QUOTES, 'UTF-8'); 

//set the timezone for the user, to display dates in their timezone
	AUTH::set_timezone($_SESSION['user_timezone']);
	
	echo 	"Subid" . "\t" . 
			"Date" . "\t" . 
			"Browser" . "\t" . 
			"OS"  . "\t" . 
			"PPC Network"  . "\t" . 
			"PPC account"  . "\t" . 
			"Click Real/Filtered"  . "\t" . 
			"IP Address"  . "\t" .
			"ISP/Carrier"  . "\t" .
			"Country"  . "\t" .
			"Country Code"  . "\t" .
			"City"  . "\t" . 
			"Offer/LP"  . "\t" . 
			"Text Ad"  . "\t" . 
			"Referer" . "\t" . 
			"Landing" . "\t" . 
			"Outbound" . "\t" . 
			"Cloaked Referer" . "\t" . 
			"Redirect" . "\t" . 
			"Keyword" . "\t" .
	        "gclid" . "\t" .
	        "utm_campaign" . "\t" .
	        "utm_content" . "\t" .
	        "utm_medium" . "\t" .
	        "utm_source" . "\t" .
	        "utm_term" . "\n";
	
//now display all the clicks
	while ($click_row = $click_result->fetch_array(MYSQLI_ASSOC)) {   
								
		$mysql['click_id'] = $db->real_escape_string($click_row['click_id']);
		
		
			//Country GEO data

			$html['location_country_code'] = htmlentities($click_row['country_code'], ENT_QUOTES, 'UTF-8');   
			$html['location_country_name'] = htmlentities($click_row['country_name'], ENT_QUOTES, 'UTF-8');
			
			//City GEO data
			$html['location_city_name'] = htmlentities($click_row['city_name'], ENT_QUOTES, 'UTF-8');

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
		   

		
		$html['aff_campaign_id'] = htmlentities($click_row['aff_campaign_id'], ENT_QUOTES, 'UTF-8');   
		$html['landing_page_nickname'] = htmlentities($click_row['landing_page_nickname'], ENT_QUOTES, 'UTF-8');   
		$html['ppc_account_id'] = htmlentities($click_row['ppc_account_id'], ENT_QUOTES, 'UTF-8');   
		$html['text_ad_id'] = htmlentities($click_row['text_ad_id'], ENT_QUOTES, 'UTF-8');   
		$html['text_ad_name'] = htmlentities($click_row['text_ad_name'], ENT_QUOTES, 'UTF-8'); 
		$html['aff_campaign_name'] = htmlentities($click_row['aff_campaign_name'], ENT_QUOTES, 'UTF-8');
		$html['aff_network_name'] = htmlentities($click_row['aff_network_name'], ENT_QUOTES, 'UTF-8');
		$html['ppc_network_name'] = htmlentities($click_row['ppc_network_name'], ENT_QUOTES, 'UTF-8');
		$html['ppc_account_name'] = htmlentities($click_row['ppc_account_name'], ENT_QUOTES, 'UTF-8');
		$html['ip_address'] = htmlentities($click_row['ip_address'], ENT_QUOTES, 'UTF-8');
		$html['isp_name'] = htmlentities($click_row['isp_name'], ENT_QUOTES, 'UTF-8');
		$html['click_cpc'] = htmlentities(dollar_format($click_row['click_cpc']), ENT_QUOTES, 'UTF-8');
		$html['keyword'] = htmlentities($click_row['keyword'], ENT_QUOTES, 'UTF-8');
		$html['click_lead'] = htmlentities($click_row['click_lead'], ENT_QUOTES, 'UTF-8');
		$html['click_filtered'] = htmlentities($click_row['click_filtered'], ENT_QUOTES, 'UTF-8');      
		$html['browser_name'] = htmlentities($click_row['browser_name'], ENT_QUOTES, 'UTF-8');
		$html['platform_name'] = htmlentities($click_row['platform_name'], ENT_QUOTES, 'UTF-8');      
				
		$html['location'] = '';
		if ($click_row['location_country_name']) {
			if ($click_row['location_country_name']) { 
				$origin = $click_row['location_country_name']; 
			} if (($click_row['location_region_code']) and (!is_numeric($click_row['location_region_code']))) { 
				$origin = $click_row['location_region_code'] . ', ' . $origin; 
			} if ($click_row['location_city_name']) { 
				$origin = $click_row['location_city_name'] . ', ' . $origin;  
			}
			
			$html['origin'] = htmlentities($origin, ENT_QUOTES, 'UTF-8');  
		}  
		
		if ($click_row['click_filtered'] == '1') { 
			$click_filtered = 'filtered';
		} elseif ($click_row['click_lead'] == '1') {
			$click_filtered = 'conversion';
		} else {
			$click_filtered = 'real';
		}
		
	echo 	$click_row['click_id'] . "\t" . 
			date('m/d/y g:ia',$click_row['click_time']) . "\t" . 
			$html['browser_name'] . "\t" . 
			$html['platform_name']  . "\t" . 
			$html['ppc_network_name']  . "\t" . 
			$html['ppc_account_name']  . "\t" . 
			$click_filtered  . "\t" . 
			$html['ip_address']  . "\t" .
			$html['isp_name']  . "\t" .
			$html['location_country_name']  . "\t" .
			$html['location_country_code']  . "\t" .
			$html['location_city_name']  . "\t" .
			$html['aff_campaign_name']  . "\t" . 
			$html['text_ad_name']  . "\t" . 
			$html['referer'] . "\t" . 
			$html['landing'] . "\t" . 
			$html['outbound'] . "\t" . 
			$html['cloaking'] . "\t" . 
			$html['redirect'] . "\t" . 
			$html['keyword'] . "\t" .
			$click_row['gclid'] . "\t" .
			$click_row['utm_campaign'] . "\t" .
			$click_row['utm_content'] . "\t" .
			$click_row['utm_medium'] . "\t" .
			$click_row['utm_source'] . "\t" .
			$click_row['utm_term'] . "\n";
			
	}
