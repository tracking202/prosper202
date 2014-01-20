<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 
	
//make sure user is logged in or die
	AUTH::require_user();
	
//start displaying the data     
	header("Content-type: application/octet-stream");
	
# replace excelfile.xls with whatever you want the filename to default to
	header("Content-Disposition: attachment; filename=T202_visitors_".time().".xls");
	header("Pragma: no-cache");
	header("Expires: 0");  
		
	
//get stuff
	$command = "SELECT * FROM 202_clicks AS 2c LEFT JOIN 202_clicks_advance AS 2ca USING (click_id) LEFT JOIN 202_clicks_site AS 2cs USING (click_id)";
	$db_table = "2c";
	$query = query($command, $db_table, true, true, true, '  ORDER BY 2c.click_id DESC ', $_POST['offset'], true, false);
	
	
//run query
	$click_sql = $query['click_sql'];
	$click_result = mysql_query($click_sql) or record_mysql_error($click_sql); 
	
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
			"Offer/LP"  . "\t" . 
			"Text Ad"  . "\t" . 
			"Referer" . "\t" . 
			"Landing" . "\t" . 
			"Outbound" . "\t" . 
			"Cloaked Referer" . "\t" . 
			"Redirect" . "\t" . 
			"Keyword" . "\n";
	
//now display all the clicks
	while ($click_row = mysql_fetch_array($click_result, MYSQL_ASSOC)) {   
								
		$mysql['click_id'] = mysql_real_escape_string($click_row['click_id']);
		
		$click_sql2 = "SELECT  2c.click_id,
								click_alp,
								text_ad_name,
								aff_campaign_name,
								aff_campaign_id_public,
								landing_page_nickname,
								ppc_network_name,
								ppc_account_name,
								ip_address,
								keyword,
								click_out,
								click_lead,
								click_filtered,
								click_id_public,
								click_cloaking,
								click_referer_site_url_id,
								click_landing_site_url_id,
								click_outbound_site_url_id,
								click_cloaking_site_url_id,
								click_redirect_site_url_id,"; /*
								location_country_name,
								location_country_code,
								location_region_code,
								location_city_name,*/ $click_sql2 .="
								202_browsers.browser_name,
								202_platforms.platform_name
					  FROM      202_clicks AS 2c
					  					LEFT JOIN 202_clicks_advance USING (click_id)
										LEFT JOIN 202_clicks_record USING (click_id)
										LEFT JOIN 202_clicks_site USING (click_id)
										LEFT JOIN 202_aff_campaigns ON (202_aff_campaigns.aff_campaign_id = 2c.aff_campaign_id)
										LEFT JOIN 202_ppc_accounts ON (202_ppc_accounts.ppc_account_id = 2c.ppc_account_id)
										LEFT JOIN 202_ppc_networks USING (ppc_network_id)
										LEFT JOIN 202_landing_pages ON (202_landing_pages.landing_page_id = 2c.landing_page_id)
										LEFT JOIN 202_text_ads ON (202_text_ads.text_ad_id = 202_clicks_advance.text_ad_id)
										LEFT JOIN 202_ips ON (202_ips.ip_id = 202_clicks_advance.ip_id)
										LEFT JOIN 202_keywords ON (202_keywords.keyword_id = 202_clicks_advance.keyword_id)
										LEFT JOIN 202_browsers ON (202_browsers.browser_id = 202_clicks_advance.browser_id)
										LEFT JOIN 202_platforms ON (202_platforms.platform_id = 202_clicks_advance.platform_id)";
										/*LEFT JOIN 202_locations USING (location_id)
										LEFT JOIN 202_locations_country USING (location_country_id)
										LEFT JOIN 202_locations_city USING (location_city_id)
										LEFT JOIN 202_locations_region USING (location_region_id)*/
		$click_sql2 .= "	  WHERE     2c.click_id='".$mysql['click_id']."'";
		$click_row2 = memcache_mysql_fetch_assoc($click_sql2);
		$click_row = array_merge($click_row, $click_row2);
		
		$mysql['click_referer_site_url_id'] = mysql_real_escape_string($click_row['click_referer_site_url_id']);
		$site_url_sql = "SELECT * FROM 202_site_urls LEFT JOIN 202_site_domains USING (site_domain_id) 
						 WHERE  202_site_urls.site_url_id = '".$mysql['click_referer_site_url_id']."'
						 AND    202_site_urls.site_domain_id = 202_site_domains.site_domain_id";
		$site_url_row = memcache_mysql_fetch_assoc($site_url_sql);
		$html['referer'] = htmlentities($site_url_row['site_url_address'], ENT_QUOTES, 'UTF-8');   
		$html['referer_host'] = htmlentities($site_url_row['site_domain_host'], ENT_QUOTES, 'UTF-8');   

		$mysql['click_landing_site_url_id'] = mysql_real_escape_string($click_row['click_landing_site_url_id']);
		$site_url_sql = "SELECT * FROM 202_site_urls LEFT JOIN 202_site_domains USING (site_domain_id) 
						 WHERE  202_site_urls.site_url_id = '".$mysql['click_landing_site_url_id']."'
						 AND    202_site_urls.site_domain_id = 202_site_domains.site_domain_id";
		$site_url_row = memcache_mysql_fetch_assoc($site_url_sql);
		$html['landing'] = htmlentities($site_url_row['site_url_address'], ENT_QUOTES, 'UTF-8');   
		$html['landing_host'] = htmlentities($site_url_row['site_domain_host'], ENT_QUOTES, 'UTF-8');   
		
		$mysql['click_outbound_site_url_id'] = mysql_real_escape_string($click_row['click_outbound_site_url_id']);
		$site_url_sql = "SELECT * FROM 202_site_urls LEFT JOIN 202_site_domains USING (site_domain_id) 
						 WHERE  202_site_urls.site_url_id = '".$mysql['click_outbound_site_url_id']."'
						 AND    202_site_urls.site_domain_id = 202_site_domains.site_domain_id";
		$site_url_row = memcache_mysql_fetch_assoc($site_url_sql);
		$html['outbound'] = htmlentities($site_url_row['site_url_address'], ENT_QUOTES, 'UTF-8');   
		$html['outbound_host'] = htmlentities($site_url_row['site_domain_host'], ENT_QUOTES, 'UTF-8');   

		//this is alittle different
		if ($click_row['click_cloaking']) {
			
			//if not a landing page
			if (!$click_row['click_alp']) { 
				$html['cloaking'] = htmlentities( 'http://' .$_SERVER['SERVER_NAME'] . '/tracking202/redirect/cl.php?pci=' . $click_row['click_id_public'] );
				$html['cloaking_host'] = htmlentities( $_SERVER['SERVER_NAME'] );   
			} else { 
				//advanced lander
				$html['cloaking'] = htmlentities( 'http://' .$_SERVER['SERVER_NAME'] . '/tracking202/redirect/off.php?acip='. $click_row['aff_campaign_id_public'] . '&pci=' . $click_row['click_id_public'] );
				$html['cloaking_host'] = htmlentities( $_SERVER['SERVER_NAME'] );   
			}
		} else {
			$html['cloaking'] = '';
			$html['cloaking_host'] = '';	
		}

		$mysql['click_redirect_site_url_id'] = mysql_real_escape_string($click_row['click_redirect_site_url_id']);
		$site_url_sql = "SELECT * FROM 202_site_urls LEFT JOIN 202_site_domains USING (site_domain_id) 
						 WHERE  202_site_urls.site_url_id = '".$mysql['click_redirect_site_url_id']."'
						 AND    202_site_urls.site_domain_id = 202_site_domains.site_domain_id";
		$site_url_result = mysql_query($site_url_sql) or record_mysql_error($site_url_sql);
		$site_url_row = mysql_fetch_assoc($site_url_result);
		$html['redirect'] = htmlentities($site_url_row['site_url_address'], ENT_QUOTES, 'UTF-8');   
		$html['redirect_host'] = htmlentities($site_url_row['site_domain_host'], ENT_QUOTES, 'UTF-8');  
		  

		
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
			$click_row['browser_name'] . "\t" . 
			$click_row['platform_name']  . "\t" . 
			$click_row['ppc_network_name']  . "\t" . 
			$click_row['ppc_account_name']  . "\t" . 
			$click_filtered  . "\t" . 
			$click_row['ip_address']  . "\t" . 
			$click_row['aff_campaign_name']  . "\t" . 
			$click_row['text_ad_name']  . "\t" . 
			$html['referer'] . "\t" . 
			$html['landing'] . "\t" . 
			$html['outbound'] . "\t" . 
			$html['cloaking'] . "\t" . 
			$html['redirect'] . "\t" . 
			$click_row['keyword'] . "\n";
			
	}
