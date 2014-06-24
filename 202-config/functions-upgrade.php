<?php

class PROSPER202 { 
	
	function mysql_version() { 

		$database = DB::getInstance();
		$db = $database->getConnection();

		//select the mysql version
		$version_sql = "SELECT version FROM 202_version";
		$version_result = $db->query($version_sql);
		$version_row = $version_result->fetch_assoc();
		$mysql_version = $version_row['version'];
		
		//if there is no mysql version, this is an older 1.0.0-1.0.2 release, just return version 1.0.0 for simplicitly sake
		if (!$mysql_version) { $mysql_version = '1.0.2';}
	
		return $mysql_version;
	}
	
	function php_version() { 
		global $version;
		$php_version = $version;
		return $php_version;
	}
}



class UPGRADE {
	

	function upgrade_databases() {
		
		ini_set('max_execution_time', 60*10);
		ini_set('max_input_time', 60*10);

		
		//get the old version
		$mysql_version = PROSPER202::mysql_version();
		$php_version = PROSPER202::php_version();
		
		//if the mysql is 1.0.2, upgrade to 1.0.3
		if ($mysql_version == '1.0.2') { 
		
			//create the new mysql version table
			$sql = "CREATE TABLE IF NOT EXISTS `202_version` (
					  `version` varchar(50) NOT NULL
					) ENGINE=InnoDB ;";
			$result = _mysqli_query($sql);

			//drop the old table
			$sql ="DROP TABLE `202_sort_landings`";
			$result = _mysqli_query($sql);
		
			//create the new landing page sorting table
			$sql ="CREATE TABLE IF NOT EXISTS `202_sort_landing_pages` (
				  `sort_landing_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
				  `user_id` mediumint(8) unsigned NOT NULL,
				  `landing_page_id` mediumint(8) unsigned NOT NULL,
				  `sort_landing_page_clicks` mediumint(8) unsigned NOT NULL,
				  `sort_landing_page_click_throughs` mediumint(8) unsigned NOT NULL,
				  `sort_landing_page_ctr` decimal(10,2) NOT NULL,
				  `sort_landing_page_leads` mediumint(8) unsigned NOT NULL,
				  `sort_landing_page_su_ratio` decimal(10,2) NOT NULL,
				  `sort_landing_page_payout` decimal(6,2) NOT NULL,
				  `sort_landing_page_epc` decimal(10,2) NOT NULL,
				  `sort_landing_page_avg_cpc` decimal(5,2) NOT NULL,
				  `sort_landing_page_income` decimal(10,2) NOT NULL,
				  `sort_landing_page_cost` decimal(10,2) NOT NULL,
				  `sort_landing_page_net` decimal(10,2) NOT NULL,
				  `sort_landing_page_roi` decimal(10,2) NOT NULL,
				  PRIMARY KEY (`sort_landing_id`),
				  KEY `user_id` (`user_id`),
				  KEY `landing_page_id` (`landing_page_id`),
				  KEY `sort_landing_page_clicks` (`sort_landing_page_clicks`),
				  KEY `sort_landing_page_click_throughs` (`sort_landing_page_click_throughs`),
				  KEY `sort_landing_page_ctr` (`sort_landing_page_ctr`),
				  KEY `sort_landing_page_leads` (`sort_landing_page_leads`),
				  KEY `sort_landing_page_su_ratio` (`sort_landing_page_su_ratio`),
				  KEY `sort_landing_page_payout` (`sort_landing_page_payout`),
				  KEY `sort_landing_page_epc` (`sort_landing_page_epc`),
				  KEY `sort_landing_page_avg_cpc` (`sort_landing_page_avg_cpc`),
				  KEY `sort_landing_page_income` (`sort_landing_page_income`),
				  KEY `sort_landing_page_cost` (`sort_landing_page_cost`),
				  KEY `sort_landing_page_net` (`sort_landing_page_net`),
				  KEY `sort_landing_page_roi` (`sort_landing_page_roi`)
				) ENGINE=InnoDB   ;";
			$result = _mysqli_query($sql);
			
			 			
			//this is now up to 1.0.3
			$sql = "INSERT INTO 202_version SET version='1.0.3'";
			$result = _mysqli_query($sql);
			
			//now set the new mysql version
			$mysql_version = '1.0.3';
			
		}
		
		//upgrade from 1.0.3 to 1.0.4
		if ($mysql_version == '1.0.3') {
			$sql = "UPDATE 202_version SET version='1.0.4'";	
			$result = _mysqli_query($sql);
			$mysql_version = '1.0.4';
		} 

		//upgrade from 1.0.4 to 1.0.5
		if ($mysql_version == '1.0.4') {
			$sql = "UPDATE 202_version SET version='1.0.5'";	
			$result = _mysqli_query($sql);
			$mysql_version = '1.0.5';
		} 
		
		//upgrade from 1.0.5 to 1.0.6
		if ($mysql_version == '1.0.5') {
			$sql = "UPDATE 202_version SET version='1.0.6'";	
			$result = _mysqli_query($sql);
			$mysql_version = '1.0.6';
		} 
		
		//upgrade from 1.0.6 to 1.1.0 - here we had some database modifications to make it scale better.
		if ($mysql_version == '1.0.6') {
			
			//this is upgrading things to BIGINT
			$result = _mysqli_query("ALTER TABLE `202_clicks` 			CHANGE `click_id` `click_id` BIGINT UNSIGNED NOT NULL");
			$result = _mysqli_query("ALTER TABLE `202_clicks_advance` 	CHANGE `click_id` `click_id` BIGINT UNSIGNED NOT NULL , 
																			CHANGE `keyword_id` `keyword_id` BIGINT UNSIGNED NOT NULL ,
																			CHANGE `ip_id` `ip_id` BIGINT UNSIGNED NOT NULL");
			$result = _mysqli_query(" ALTER TABLE `202_clicks_counter` 	CHANGE `click_id` `click_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT  ");
			$result = _mysqli_query(" ALTER TABLE `202_clicks_record` 	CHANGE `click_id` `click_id` BIGINT UNSIGNED NOT NULL  ");
			$result = _mysqli_query(" ALTER TABLE `202_clicks_site` 		CHANGE `click_id` `click_id` BIGINT UNSIGNED NOT NULL ,
																			CHANGE `click_referer_site_url_id` `click_referer_site_url_id` BIGINT UNSIGNED NOT NULL ,
																			CHANGE `click_landing_site_url_id` `click_landing_site_url_id` BIGINT UNSIGNED NOT NULL ,
																			CHANGE `click_outbound_site_url_id` `click_outbound_site_url_id` BIGINT UNSIGNED NOT NULL ,
																			CHANGE `click_cloaking_site_url_id` `click_cloaking_site_url_id` BIGINT UNSIGNED NOT NULL ,
																			CHANGE `click_redirect_site_url_id` `click_redirect_site_url_id` BIGINT UNSIGNED NOT NULL ");
			$result = _mysqli_query(" ALTER TABLE `202_clicks_spy` 		CHANGE `click_id` `click_id` BIGINT UNSIGNED NOT NULL  ");
			$result = _mysqli_query(" ALTER TABLE `202_ips` 			CHANGE `ip_id` `ip_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT  ");
			$result = _mysqli_query(" ALTER TABLE `202_keywords` 		CHANGE `keyword_id` `keyword_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT  ");
			$result = _mysqli_query(" ALTER TABLE `202_last_ips` 		CHANGE `ip_id` `ip_id` BIGINT NOT NULL  ");
			$result = _mysqli_query(" ALTER TABLE `202_mysql_errors` 	CHANGE `ip_id` `ip_id` BIGINT UNSIGNED NOT NULL ,
																			CHANGE `site_id` `site_id` BIGINT UNSIGNED NOT NULL ");
			$result = _mysqli_query(" ALTER TABLE `202_site_domains` 	CHANGE `site_domain_id` `site_domain_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT  ");
			$result = _mysqli_query(" ALTER TABLE `202_site_urls` 		CHANGE `site_url_id` `site_url_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT ,
																			CHANGE `site_domain_id` `site_domain_id` BIGINT UNSIGNED NOT NULL ");
			$result = _mysqli_query(" ALTER TABLE `202_sort_ips` CHANGE `ip_id` `ip_id` BIGINT UNSIGNED NOT NULL  ");
			$result = _mysqli_query(" ALTER TABLE `202_sort_keywords` CHANGE `keyword_id` `keyword_id` BIGINT UNSIGNED NOT NULL  ");
			$result = _mysqli_query(" ALTER TABLE `202_sort_referers` CHANGE `referer_id` `referer_id` BIGINT UNSIGNED NOT NULL  ");
			$result = _mysqli_query(" ALTER TABLE `202_users` CHANGE `user_last_login_ip_id` `user_last_login_ip_id` BIGINT UNSIGNED NOT NULL  ");
			
			//mysql version set to 1.1.0 now
			$sql = "UPDATE 202_version SET version='1.1.0'";	
			$result = _mysqli_query($sql);
			$mysql_version = '1.1.0';
		}
		
		//upgrade from 1.1.0 to 1.1.1
		if ($mysql_version == '1.1.0') { 
			$sql = "UPDATE 202_version SET version='1.1.1'";	
			$result = _mysqli_query($sql);
			$mysql_version = '1.1.1';
		} 
		
		
		//upgrade from 1.1.1 to 1.1.2
		if ($mysql_version == '1.1.1') { 
			$sql = "UPDATE 202_version SET version='1.1.2'";	
			$result = _mysqli_query($sql);
			$mysql_version = '1.1.2';
		} 
		
		//upgrade from 1.1.2 to 1.2.0
		if ($mysql_version == '1.1.2') { 
			
			$result = _mysqli_query("	 CREATE TABLE IF NOT EXISTS `202_rotations` (
										  `aff_campaign_id` mediumint(8) unsigned NOT NULL,
										  `rotation_num` tinyint(4) NOT NULL,
										  PRIMARY KEY (`aff_campaign_id`)
										) ENGINE=MEMORY ; ");
			
			$result = _mysqli_query("	INSERT INTO 202_browsers SET browser_id = '9', browser_name = 'Chrome'");
			$result = _mysqli_query("	INSERT INTO 202_browsers SET browser_id = '10', browser_name = 'Mobile'");
			$result = _mysqli_query("	INSERT INTO 202_browsers SET browser_id = '11', browser_name = 'Console'"); 
			$result = _mysqli_query(" 	ALTER TABLE  `202_clicks` CHANGE  `click_cpc`  `click_cpc` DECIMAL( 7, 5 ) NOT NULL "); 
			$result = _mysqli_query(" 	ALTER TABLE  `202_trackers` CHANGE  `click_cpc`  `click_cpc` DECIMAL( 7, 5 ) NOT NULL "); 
			
			$result = _mysqli_query(" 	ALTER TABLE  `202_users_pref` ADD  `user_cpc_or_cpv` CHAR( 3 ) NOT NULL DEFAULT  'cpc' AFTER  `user_pref_chart` ; "); 
			$result = _mysqli_query(" 	ALTER TABLE  `202_users_pref` ADD  `user_keyword_searched_or_bidded` VARCHAR( 255 ) NOT NULL DEFAULT  'searched' AFTER  `user_cpc_or_cpv` ; "); 
			
			
			$result = _mysqli_query(" 	ALTER TABLE  `202_aff_campaigns` ADD  `aff_campaign_url_2` TEXT NOT NULL AFTER  `aff_campaign_url` ,
										ADD  `aff_campaign_url_3` TEXT NOT NULL AFTER  `aff_campaign_url_2` ,
										ADD  `aff_campaign_url_4` TEXT NOT NULL AFTER  `aff_campaign_url_3` ,
										ADD  `aff_campaign_url_5` TEXT NOT NULL AFTER  `aff_campaign_url_4` ;");

			$result = _mysqli_query(" 	ALTER TABLE  `202_aff_campaigns` CHANGE  `aff_campaign_url`  `aff_campaign_url` TEXT CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL");
			
			$result = _mysqli_query(" 	ALTER TABLE  `202_aff_campaigns` ADD  `aff_campaign_rotate` TINYINT( 1 ) NOT NULL DEFAULT  '0' AFTER  `aff_campaign_time` ;");
			
			$result = _mysqli_query(" 	ALTER TABLE`202_sort_breakdowns` CHANGE `sort_breakdown_avg_cpc` `sort_breakdown_avg_cpc` DECIMAL( 7, 5 ) NOT NULL ,
										CHANGE `sort_breakdown_cost` `sort_breakdown_cost` DECIMAL( 13, 5 ) NOT NULL ,
										CHANGE `sort_breakdown_net` `sort_breakdown_net` DECIMAL( 13, 5 ) NOT NULL;");
										
			$result = _mysqli_query(" 	ALTER TABLE`202_sort_ips` CHANGE `sort_ip_avg_cpc` `sort_ip_avg_cpc` DECIMAL( 7, 5 ) NOT NULL ,
										CHANGE `sort_ip_cost` `sort_ip_cost` DECIMAL( 13, 5 ) NOT NULL ,
										CHANGE `sort_ip_net` `sort_ip_net` DECIMAL( 13, 5 ) NOT NULL;");
								
			$result = _mysqli_query(" 	ALTER TABLE`202_sort_keywords` CHANGE `sort_keyword_avg_cpc` `sort_keyword_avg_cpc` DECIMAL( 7, 5 ) NOT NULL ,
										CHANGE `sort_keyword_cost` `sort_keyword_cost` DECIMAL( 13, 5 ) NOT NULL ,
										CHANGE `sort_keyword_net` `sort_keyword_net` DECIMAL( 13, 5 ) NOT NULL;");
										
			$result = _mysqli_query("   ALTER TABLE`202_sort_landing_pages` CHANGE `sort_landing_page_avg_cpc` `sort_landing_page_avg_cpc` DECIMAL( 7, 5 ) NOT NULL ,
										CHANGE `sort_landing_page_cost` `sort_landing_page_cost` DECIMAL( 13, 5 ) NOT NULL ,
										CHANGE `sort_landing_page_net` `sort_landing_page_net` DECIMAL( 13, 5 ) NOT NULL;");
										
			$result = _mysqli_query(" 	ALTER TABLE`202_sort_referers` CHANGE `sort_referer_avg_cpc` `sort_referer_avg_cpc` DECIMAL( 7, 5 ) NOT NULL ,
										CHANGE `sort_referer_cost` `sort_referer_cost` DECIMAL( 13, 5 ) NOT NULL ,
										CHANGE `sort_referer_net` `sort_referer_net` DECIMAL( 13, 5 ) NOT NULL;");
										
			$result = _mysqli_query(" 	ALTER TABLE`202_sort_text_ads` CHANGE `sort_text_ad_avg_cpc` `sort_text_ad_avg_cpc` DECIMAL( 7, 5 ) NOT NULL ,
										CHANGE `sort_text_ad_cost` `sort_text_ad_cost` DECIMAL( 13, 5 ) NOT NULL ,
										CHANGE `sort_text_ad_net` `sort_text_ad_net` DECIMAL( 13, 5 ) NOT NULL; ");
 

			$sql = "UPDATE 202_version SET version='1.2.0'";	
			$result = _mysqli_query($sql);
			$mysql_version = '1.2.0';
		} 
		  
		//upgrade from 1.2.0 to 1,2,1
		if ($mysql_version == '1.2.0') { 
			$sql = "UPDATE 202_version SET version='1.2.1'";	
			$result = _mysqli_query($sql);
			$mysql_version = '1.2.1';
		} 
		
		//upgrade from 1.2.1 to 1.3.0
		if ($mysql_version == '1.2.1') {
			
			$result = _mysqli_query(" 	ALTER TABLE  `202_users` ADD  `user_api_key` VARCHAR( 255 ) NOT NULL AFTER  `user_pass_time` ; ");
			$result = _mysqli_query(" 	ALTER TABLE  `202_users` ADD  `user_stats202_app_key` VARCHAR( 255 ) NOT NULL AFTER  `user_api_key` ; ");
			$sql = "UPDATE 202_version SET version='1.3.0'";	
			$result = _mysqli_query($sql);
			$mysql_version = '1.3.0';
		} 
		
		//upgrade from 1.3.0 to 1.3.1
		if ($mysql_version == '1.3.0') {
			
			$result = _mysqli_query(" 	ALTER TABLE  `202_clicks_spy` ENGINE = MYISAM "); 
			$result = _mysqli_query(" 	ALTER TABLE  `202_last_ips` ENGINE = MYISAM "); 
			
			$sql = "UPDATE 202_version SET version='1.3.1'; ";
			$result = _mysqli_query($sql);
			$mysql_version = '1.3.1';
		}
		
		
		//upgrade from 1.3.1 to 1.3.2
		if ($mysql_version == '1.3.1') { 
			
			$result = _mysqli_query(" 	ALTER TABLE  `202_clicks_spy` ENGINE = MYISAM "); 
			$result = _mysqli_query(" 	ALTER TABLE  `202_last_ips` ENGINE = MYISAM "); 
			
			$sql = "UPDATE 202_version SET version='1.3.2'; ";	
			$result = _mysqli_query($sql); 
			$mysql_version = '1.3.2';
		} 
		
		//upgrade from 1.3.2 to 1.4
		if ($mysql_version == '1.3.2') { 

			$result = _mysqli_query("	ALTER TABLE 202_users_pref ADD COLUMN `user_tracking_domain` varchar(255) NOT NULL DEFAULT '';");
			$result = _mysqli_query("	ALTER TABLE 202_users_pref ADD COLUMN `user_pref_group_1` tinyint(3);");
			$result = _mysqli_query("	ALTER TABLE 202_users_pref ADD COLUMN `user_pref_group_2` tinyint(3);");
			$result = _mysqli_query("	ALTER TABLE 202_users_pref ADD COLUMN `user_pref_group_3` tinyint(3);");
			$result = _mysqli_query("	ALTER TABLE 202_users_pref ADD COLUMN `user_pref_group_4` tinyint(3);");
			
			$result = _mysqli_query("	UPDATE 202_aff_campaigns SET aff_campaign_url=CONCAT(aff_campaign_url,'[[subid]]') ");
										
			$result = _mysqli_query(" 	CREATE TABLE `202_clicks_tracking` (
										  `click_id` bigint(20) unsigned NOT NULL,
										  `c1` varchar(255) NOT NULL DEFAULT '',
										  `c2` varchar(255) NOT NULL DEFAULT '',
										  `c3` varchar(255) NOT NULL DEFAULT '',
										  `c4` varchar(255) NOT NULL DEFAULT '',
										  PRIMARY KEY (`click_id`)
										) ENGINE=InnoDB ; ");
			
			$sql = "UPDATE 202_version SET version='1.4'; ";
			$result = _mysqli_query($sql); 
			$mysql_version = '1.4';
		}
		
		//upgrade from 1.4 to 1.4.1
		if ($mysql_version == '1.4') {
			$result = _mysqli_query(" 	CREATE TABLE `202_tracking_c1` (
										  `c1_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
										  `c1` varchar(50) NOT NULL,
										  PRIMARY KEY (`c1_id`),
										  UNIQUE KEY `c1` (`c1`)
										) ENGINE=InnoDB AUTO_INCREMENT=1 ; ");
			
			$result = _mysqli_query(" 	CREATE TABLE `202_tracking_c2` (
										  `c2_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
										  `c2` varchar(50) NOT NULL,
										  PRIMARY KEY (`c2_id`),
										  UNIQUE KEY `c2` (`c2`)
										) ENGINE=InnoDB AUTO_INCREMENT=1 ; ");
			
			$result = _mysqli_query(" 	CREATE TABLE `202_tracking_c3` (
										  `c3_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
										  `c3` varchar(50) NOT NULL,
										  PRIMARY KEY (`c3_id`),
										  UNIQUE KEY `c3` (`c3`)
										) ENGINE=InnoDB AUTO_INCREMENT=1 ; ");
			
			$result = _mysqli_query(" 	CREATE TABLE `202_tracking_c4` (
										  `c4_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
										  `c4` varchar(50) NOT NULL,
										  PRIMARY KEY (`c4_id`),
										  UNIQUE KEY `c4` (`c4`)
										) ENGINE=InnoDB AUTO_INCREMENT=1 ; ");
			$sql = "UPDATE 202_version SET version='1.4.1'; ";
			$result = _mysqli_query($sql); 
			$mysql_version = '1.4.1';
		}
		
		//upgrade from 1.4.1 to 1.4.2
		if ($mysql_version == '1.4.1') {
			$result = _mysqli_query(" 	 DROP TABLE `202_clicks_tracking`; ");
			
			$result = _mysqli_query(" 	 CREATE TABLE `202_clicks_tracking` (
										  `click_id` bigint(20) unsigned NOT NULL,
										  `c1_id` bigint(20) NOT NULL,
										  `c2_id` bigint(20) NOT NULL,
										  `c3_id` bigint(20) NOT NULL,
										  `c4_id` bigint(20) NOT NULL,
										  PRIMARY KEY (`click_id`)
										) ENGINE=InnoDB ; ");
			
			$sql = "UPDATE 202_version SET version='1.4.2'; ";
			$result = _mysqli_query($sql); 
			$mysql_version = '1.4.2';
		}
		
		//upgrade from 1.4.2 to 1.4.3
		if ($mysql_version == '1.4.2') {
			
			$result = _mysqli_query(" 	ALTER TABLE  `202_clicks_spy` ENGINE = MYISAM "); 
			$result = _mysqli_query(" 	ALTER TABLE  `202_last_ips` ENGINE = MYISAM "); 
			
			$sql = "UPDATE 202_version SET version='1.4.3'; ";
			$result = _mysqli_query($sql);
			$mysql_version = '1.4.3';
		}
		
		//upgrade from 1.4.3 to 1.5
		if ($mysql_version == '1.4.3') {
			
			$sql = "UPDATE 202_version SET version='1.5'; ";
			$result = _mysqli_query($sql);
			$mysql_version = '1.5';
		}
		
		//upgrade from 1.5 to 1.5.1
		if ($mysql_version == '1.5') {
			
			$sql = "UPDATE 202_version SET version='1.5.1'; ";
			$result = _mysqli_query($sql);
			$mysql_version = '1.5.1';
		}
		
		
		//upgrade from 1.5.1 to 1.6
		if ($mysql_version == '1.5.1') {
			
			$result = _mysqli_query("CREATE TABLE IF NOT EXISTS `202_alerts` (
			  `prosper_alert_id` int(11) NOT NULL,
			  `prosper_alert_seen` tinyint(1) NOT NULL,
			  UNIQUE KEY `prosper_alert_id` (`prosper_alert_id`)
			) ENGINE=InnoDB ;");
			
			$result = _mysqli_query("CREATE TABLE IF NOT EXISTS `202_offers` (
				  `user_id` mediumint(8) unsigned NOT NULL,
				  `offer_id` mediumint(10) unsigned NOT NULL,
				  `offer_seen` tinyint(1) NOT NULL DEFAULT '1',
				  UNIQUE KEY `user_id` (`user_id`,`offer_id`)
				) ENGINE=InnoDB ;");
			
			$result = _mysqli_query("ALTER TABLE  `202_cronjobs` ENGINE = MYISAM;");
			
			$sql = "UPDATE 202_version SET version='1.6'; ";
			$result = _mysqli_query($sql);
			$mysql_version = '1.6';
		}
		
		//upgrade from 1.6 beta to 1.6.1 stable
		if ($mysql_version == '1.6') {
			
			$sql = "UPDATE 202_version SET version='1.6.1'; ";
			$result = _mysqli_query($sql);
			$mysql_version = '1.6.1';
		}

		//upgrade from 1.6.1 to 1.6.2 beta
		if ($mysql_version == '1.6.1') {
			
			$sql = "UPDATE 202_version SET version='1.6.2'; ";
			$result = _mysqli_query($sql);
			$mysql_version = '1.6.2';
			
					
		}
		

				//upgrade from 1.6.2 to 1.7 beta
		if ($mysql_version == '1.6.2') {
			
			$sql = "UPDATE 202_version SET version='1.7'; ";
			$result = _mysqli_query($sql);
			$mysql_version = '1.7';
			
					$sql ="CREATE TABLE IF NOT EXISTS `202_pixel_types` (
  			  `pixel_type_id` TINYINT UNSIGNED NOT NULL AUTO_INCREMENT ,
  		  	  `pixel_type` VARCHAR(45) NULL ,
  			  PRIMARY KEY (`pixel_type_id`) ,
  		      UNIQUE INDEX `pixel_type_UNIQUE` (`pixel_type` ASC) 
  			) ENGINE=InnoDB ;";
		$result = _mysqli_query($sql);
		
		$sql ="CREATE TABLE IF NOT EXISTS `202_ppc_account_pixels` (
 			  `pixel_id` mediumint(8) unsigned NOT NULL auto_increment,
  			  `pixel_code` text NOT NULL,
  			  `pixel_type_id` mediumint(8) unsigned NOT NULL,
  			  `ppc_account_id` mediumint(8) unsigned NOT NULL,
  			  PRIMARY KEY  (`pixel_id`)
 			  ) ENGINE=InnoDB ;";
		$result = _mysqli_query($sql);
		
		$sql ="CREATE TABLE `202_clicks_total` (
			  `click_count` int(20) unsigned NOT NULL default '0',
 			  PRIMARY KEY  (`click_count`)
			  ) ENGINE=InnoDB ;";
		$result = _mysqli_query($sql);
		
		$sql ="INSERT IGNORE INTO `202_pixel_types` (`pixel_type`) VALUES 
			  ('Image'),
			  ('Iframe'),
			  ('Javascript'),
			  ('Postback')";
		$result = _mysqli_query($sql);
		
		$sql ="INSERT IGNORE INTO `202_platforms` (`platform_name`) VALUES 
			  ('Mobile'),
			  ('Tablet');";
		$result = _mysqli_query($sql);
			
		
		$sql ="INSERT IGNORE INTO `202_clicks_total` (`click_count`) VALUES
		(0);";
		$result = _mysqli_query($sql);
		
			
		}	
		
				//upgrade from 1.7 beta to 1.7.1 beta
			if ($mysql_version == '1.7') {
			
			$sql = "UPDATE 202_version SET version='1.7.1'; ";
			$result = _mysqli_query($sql);
			$mysql_version = '1.7.1';
				$sql ="CREATE TABLE IF NOT EXISTS `202_sort_keywords_lpctr` (
  			  `sort_keyword_id` int(10) unsigned NOT NULL auto_increment,
  			  `user_id` mediumint(8) unsigned NOT NULL,
  			  `keyword_id` bigint(20) unsigned NOT NULL,
 			  `sort_keyword_clicks` mediumint(8) unsigned NOT NULL,
 			  `sort_keyword_click_throughs` mediumint(8) unsigned NOT NULL,
		      `sort_keyword_ctr` decimal(10,2) NOT NULL,  
 		      `sort_keyword_leads` mediumint(8) unsigned NOT NULL,
			  `sort_keyword_su_ratio` decimal(10,2) NOT NULL,
			  `sort_keyword_payout` decimal(6,2) NOT NULL,
			  `sort_keyword_epc` decimal(10,2) NOT NULL,
			  `sort_keyword_avg_cpc` decimal(7,5) NOT NULL,
			  `sort_keyword_income` decimal(10,2) NOT NULL,
			  `sort_keyword_cost` decimal(13,5) NOT NULL,
			  `sort_keyword_net` decimal(13,5) NOT NULL,
  			  `sort_keyword_roi` decimal(10,2) NOT NULL,
			  PRIMARY KEY  (`sort_keyword_id`),
			  KEY `user_id` (`user_id`),
			  KEY `keyword_id` (`keyword_id`),
			  KEY `sort_keyword_clicks` (`sort_keyword_clicks`)
			) ENGINE=InnoDB AUTO_INCREMENT=1;";
				$result = _mysqli_query($sql);
				
		$sql ="CREATE TABLE IF NOT EXISTS `202_sort_text_ads_lpctr` (
  `sort_text_ad_id` int(10) unsigned NOT NULL auto_increment,
  `user_id` mediumint(8) unsigned NOT NULL,
  `text_ad_id` mediumint(8) unsigned NOT NULL,
  `sort_text_ad_clicks` mediumint(8) unsigned NOT NULL,
  `sort_text_ad_click_throughs` mediumint(8) unsigned NOT NULL,
  `sort_text_ad_ctr` decimal(10,2) NOT NULL,  
  `sort_text_ad_leads` mediumint(8) unsigned NOT NULL,
  `sort_text_ad_su_ratio` decimal(10,2) NOT NULL,
  `sort_text_ad_payout` decimal(6,2) NOT NULL,
  `sort_text_ad_epc` decimal(10,2) NOT NULL,
  `sort_text_ad_avg_cpc` decimal(7,5) NOT NULL,
  `sort_text_ad_income` decimal(10,2) NOT NULL,
  `sort_text_ad_cost` decimal(13,5) NOT NULL,
  `sort_text_ad_net` decimal(13,5) NOT NULL,
  `sort_text_ad_roi` decimal(10,2) NOT NULL,
  PRIMARY KEY  (`sort_text_ad_id`),
  KEY `user_id` (`user_id`),
  KEY `keyword_id` (`text_ad_id`),
  KEY `sort_keyword_clicks` (`sort_text_ad_clicks`),
  KEY `sort_keyword_leads` (`sort_text_ad_leads`),
  KEY `sort_keyword_signup_ratio` (`sort_text_ad_su_ratio`),
  KEY `sort_keyword_payout` (`sort_text_ad_payout`),
  KEY `sort_keyword_epc` (`sort_text_ad_epc`),
  KEY `sort_keyword_cpc` (`sort_text_ad_avg_cpc`),
  KEY `sort_keyword_income` (`sort_text_ad_income`),
  KEY `sort_keyword_cost` (`sort_text_ad_cost`),
  KEY `sort_keyword_net` (`sort_text_ad_net`),
  KEY `sort_keyword_roi` (`sort_text_ad_roi`)
) ENGINE=InnoDB  AUTO_INCREMENT=1 ;";	
			$result = _mysqli_query($sql);

	$sql="CREATE TABLE IF NOT EXISTS `202_sort_referers_lpctr` (
  `sort_referer_id` int(10) unsigned NOT NULL auto_increment,
  `user_id` mediumint(8) unsigned NOT NULL,
  `referer_id` bigint(20) unsigned NOT NULL,
  `sort_referer_clicks` mediumint(8) unsigned NOT NULL,
  `sort_referer_click_throughs` mediumint(8) unsigned NOT NULL,
  `sort_referer_ctr` decimal(10,2) NOT NULL,
  `sort_referer_leads` mediumint(8) unsigned NOT NULL,
  `sort_referer_su_ratio` decimal(10,2) NOT NULL,
  `sort_referer_payout` decimal(6,2) NOT NULL,
  `sort_referer_epc` decimal(10,2) NOT NULL,
  `sort_referer_avg_cpc` decimal(7,5) NOT NULL,
  `sort_referer_income` decimal(10,2) NOT NULL,
  `sort_referer_cost` decimal(13,5) NOT NULL,
  `sort_referer_net` decimal(13,5) NOT NULL,
  `sort_referer_roi` decimal(10,2) NOT NULL,
  PRIMARY KEY  (`sort_referer_id`),
  KEY `user_id` (`user_id`),
  KEY `keyword_id` (`referer_id`),
  KEY `sort_keyword_clicks` (`sort_referer_clicks`),
  KEY `sort_keyword_leads` (`sort_referer_leads`),
  KEY `sort_keyword_signup_ratio` (`sort_referer_su_ratio`),
  KEY `sort_keyword_payout` (`sort_referer_payout`),
  KEY `sort_keyword_epc` (`sort_referer_epc`),
  KEY `sort_keyword_cpc` (`sort_referer_avg_cpc`),
  KEY `sort_keyword_income` (`sort_referer_income`),
  KEY `sort_keyword_cost` (`sort_referer_cost`),
  KEY `sort_keyword_net` (`sort_referer_net`),
  KEY `sort_keyword_roi` (`sort_referer_roi`)
) ENGINE=InnoDB;";
			$result = _mysqli_query($sql);
			
$sql="ALTER TABLE `202_tracking_c1` CHANGE COLUMN `c1` `c1` VARCHAR(350) NOT NULL  ;";
			$result = _mysqli_query($sql);
$sql="ALTER TABLE `202_tracking_c2` CHANGE COLUMN `c2` `c2` VARCHAR(350) NOT NULL  ;";
			$result = _mysqli_query($sql);
$sql="ALTER TABLE `202_tracking_c3` CHANGE COLUMN `c3` `c3` VARCHAR(350) NOT NULL  ;";
			$result = _mysqli_query($sql);
$sql="ALTER TABLE `202_tracking_c4` CHANGE COLUMN `c4` `c4` VARCHAR(350) NOT NULL  ;";
			$result = _mysqli_query($sql);	
			}
			
			//upgrade from 1.7.1 to 1.7.2 beta
		if ($mysql_version == '1.7.1') {
			
			$sql = "UPDATE 202_version SET version='1.7.2'; ";
			$result = _mysqli_query($sql);
			$mysql_version = '1.7.2';
					
		}

		//upgrade from 1.7.2 to 1.7.3
		if ($mysql_version == '1.7.2') {
			
			$sql = "UPDATE 202_version SET version='1.7.3'; ";
			$result = _mysqli_query($sql);
			$mysql_version = '1.7.3';
			$sql="ALTER TABLE `202_users` MODIFY COLUMN `user_timezone` VARCHAR(50) NOT NULL default 'Pacific/Pitcairn';";
			$result = _mysqli_query($sql);
			$sql = "UPDATE `202_users` SET user_timezone='Pacific/Pitcairn' WHERE user_id=1";
			$result = _mysqli_query($sql);
			$sql = "ALTER TABLE `202_sort_breakdowns`"
				." ADD `sort_breakdown_click_throughs` mediumint(8) unsigned NOT NULL AFTER `sort_breakdown_clicks`,"
				." ADD `sort_breakdown_ctr` decimal(10,2) NOT NULL AFTER `sort_breakdown_click_throughs`,"
				." ADD KEY `sort_breakdown_click_throughs` (`sort_breakdown_click_throughs`),"
				." ADD KEY `sort_breakdown_ctr` (`sort_breakdown_ctr`)";
			$result = _mysqli_query($sql);
			$sql = "ALTER TABLE `202_clicks_spy` ADD INDEX (`click_id`)";
			$result = _mysqli_query($sql);
			$sql = "INSERT INTO `202_pixel_types` (`pixel_type`) VALUES ('Raw')";
			$result = _mysqli_query($sql);
			$sql = "ALTER TABLE `202_users_pref` ADD COLUMN `cache_time` VARCHAR(4) NOT NULL default '0';";
			$result = _mysqli_query($sql);
						
		}

		//upgrade from 1.7.3 to 1.7.4
		if ($mysql_version == '1.7.3') {

			$sql = "UPDATE 202_version SET version='1.7.4'; ";
			$result = _mysqli_query($sql);
			$mysql_version = '1.7.4';
			$sql = "ALTER TABLE `202_users_pref` ADD COLUMN `cb_key` VARCHAR(250) NOT NULL;";
			$result = _mysqli_query($sql);
			$sql = "ALTER TABLE `202_users_pref` ADD COLUMN `cb_verified` tinyint(1) NOT NULL default '0';";
			$result = _mysqli_query($sql);
						
		}

		//upgrade from 1.7.4 to 1.7.5
		if ($mysql_version == '1.7.4') {

			$sql = "UPDATE 202_version SET version='1.7.5'; ";
			$result = _mysqli_query($sql);
			$mysql_version = '1.7.5';
						
		}

		//upgrade from 1.7.5 to 1.7.6
		if ($mysql_version == '1.7.5') {

			$sql = "UPDATE 202_version SET version='1.7.6'; ";
			$result = _mysqli_query($sql);
			$mysql_version = '1.7.6';
			$sql = "ALTER TABLE `202_users` ADD COLUMN `clickserver_api_key` VARCHAR(250) NOT NULL;";
			$result = _mysqli_query($sql);
						
		}

		//upgrade from 1.7.6 to 1.8.0
		if ($mysql_version == '1.7.6') {

			$sql = "UPDATE 202_version SET version='1.8.0'; ";
			$result = _mysqli_query($sql);
			$mysql_version = '1.8.0';
						
		}

		//upgrade from 1.8.0 to 1.8.1
		if ($mysql_version == '1.8.0') {

			$sql = "UPDATE 202_version SET version='1.8.1'; ";
			$result = _mysqli_query($sql);
			$mysql_version = '1.8.1';
						
		}

		//upgrade from 1.8.1 to 1.8.2
		if ($mysql_version == '1.8.1') {
		
			$sql = "UPDATE 202_version SET version='1.8.2'; ";
			$result = _mysqli_query($sql);
			$mysql_version = '1.8.2';
		
		}

		//upgrade from 1.8.2 to 1.8.2.1
		if ($mysql_version == '1.8.2') {
		
			$sql = "DROP TABLE IF EXISTS 202_locations";
			$result = _mysqli_query($sql);
			$sql = "DROP TABLE IF EXISTS 202_locations_country";
			$result = _mysqli_query($sql);
			$sql = "DROP TABLE IF EXISTS 202_locations_city";
			$result = _mysqli_query($sql);
			$sql = "DROP TABLE IF EXISTS 202_locations_block";
			$result = _mysqli_query($sql);
			$sql = "DROP TABLE IF EXISTS 202_locations_coordinates";
			$result = _mysqli_query($sql);
			$sql = "DROP TABLE IF EXISTS 202_locations_region";
			$result = _mysqli_query($sql);
			$sql = "ALTER TABLE `202_clicks_advance` ADD COLUMN `country_id` bigint(20) unsigned NOT NULL;";
			$result = _mysqli_query($sql);
			$sql = "ALTER TABLE `202_clicks_advance` ADD COLUMN `city_id` bigint(20) unsigned NOT NULL;";
			$result = _mysqli_query($sql);

			$sql = "CREATE TABLE `202_locations_city` (
				  `city_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				  `main_country_id` mediumint(8) unsigned NOT NULL,
				  `city_name` varchar(50) NOT NULL DEFAULT '',
				  PRIMARY KEY (`city_id`)
				) ENGINE=InnoDB ;";
			$result = _mysqli_query($sql);

			$sql = "CREATE TABLE `202_locations_country` (
				  `country_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				  `country_code` varchar(3) NOT NULL DEFAULT '',
				  `country_name` varchar(50) NOT NULL DEFAULT '',
				  PRIMARY KEY (`country_id`)
				) ENGINE=InnoDB ;";
			$result = _mysqli_query($sql);

			$sql = "CREATE TABLE `202_sort_cities` (
			  `sort_city_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
			  `user_id` mediumint(8) unsigned NOT NULL,
			  `city_id` bigint(20) unsigned NOT NULL,
			  `country_id` bigint(20) unsigned NOT NULL,
			  `sort_city_clicks` mediumint(8) unsigned NOT NULL,
			  `sort_city_leads` mediumint(8) unsigned NOT NULL,
			  `sort_city_su_ratio` decimal(10,2) NOT NULL,
			  `sort_city_payout` decimal(6,2) NOT NULL,
			  `sort_city_epc` decimal(10,2) NOT NULL,
			  `sort_city_avg_cpc` decimal(7,5) NOT NULL,
			  `sort_city_income` decimal(10,2) NOT NULL,
			  `sort_city_cost` decimal(13,5) NOT NULL,
			  `sort_city_net` decimal(13,5) NOT NULL,
			  `sort_city_roi` decimal(10,2) NOT NULL,
			  PRIMARY KEY (`sort_city_id`)
			) ENGINE=InnoDB ;";
			$result = _mysqli_query($sql);

			$sql = "CREATE TABLE `202_sort_countries` (
				  `sort_country_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
				  `user_id` mediumint(8) unsigned NOT NULL,
				  `country_id` bigint(20) unsigned NOT NULL,
				  `sort_country_clicks` mediumint(8) unsigned NOT NULL,
				  `sort_country_leads` mediumint(8) unsigned NOT NULL,
				  `sort_country_su_ratio` decimal(10,2) NOT NULL,
				  `sort_country_payout` decimal(6,2) NOT NULL,
				  `sort_country_epc` decimal(10,2) NOT NULL,
				  `sort_country_avg_cpc` decimal(7,5) NOT NULL,
				  `sort_country_income` decimal(10,2) NOT NULL,
				  `sort_country_cost` decimal(13,5) NOT NULL,
				  `sort_country_net` decimal(13,5) NOT NULL,
				  `sort_country_roi` decimal(10,2) NOT NULL,
				  PRIMARY KEY (`sort_country_id`)
				) ENGINE=InnoDB ;";
			$result = _mysqli_query($sql);

			$sql ="CREATE TABLE `202_locations_isp` (
				  `isp_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				  `isp_name` varchar(50) NOT NULL DEFAULT '',
				  PRIMARY KEY (`isp_id`)
				) ENGINE=InnoDB ;";
			$result = _mysqli_query($sql);

			$sql ="CREATE TABLE `202_sort_isps` (
				  `sort_isp_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
				  `user_id` mediumint(8) unsigned NOT NULL,
				  `isp_id` bigint(20) unsigned NOT NULL,
				  `sort_isp_clicks` mediumint(8) unsigned NOT NULL,
				  `sort_isp_leads` mediumint(8) unsigned NOT NULL,
				  `sort_isp_su_ratio` decimal(10,2) NOT NULL,
				  `sort_isp_payout` decimal(6,2) NOT NULL,
				  `sort_isp_epc` decimal(10,2) NOT NULL,
				  `sort_isp_avg_cpc` decimal(7,5) NOT NULL,
				  `sort_isp_income` decimal(10,2) NOT NULL,
				  `sort_isp_cost` decimal(13,5) NOT NULL,
				  `sort_isp_net` decimal(13,5) NOT NULL,
				  `sort_isp_roi` decimal(10,2) NOT NULL,
				  PRIMARY KEY (`sort_isp_id`)
				) ENGINE=InnoDB ;";
			$result = _mysqli_query($sql);

			$sql = "ALTER TABLE `202_clicks_advance` ADD COLUMN `isp_id` bigint(20) unsigned NOT NULL;";
			$result = _mysqli_query($sql);

			$sql = "ALTER TABLE `202_users_pref` ADD COLUMN `maxmind_isp` tinyint(1) NOT NULL default '0';";
			$result = _mysqli_query($sql);

			$sql = "ALTER TABLE `202_users_pref` ADD COLUMN `user_pref_isp_id` tinyint(3) unsigned NOT NULL;";
			$result = _mysqli_query($sql);

			$sql = "ALTER TABLE `202_users_pref` ADD COLUMN `user_pref_device_id` tinyint(3) unsigned NOT NULL;";
			$result = _mysqli_query($sql);

			$sql = "ALTER TABLE `202_users_pref` ADD COLUMN `user_pref_browser_id` tinyint(3) unsigned NOT NULL;";
			$result = _mysqli_query($sql);

			$sql = "ALTER TABLE `202_users_pref` ADD COLUMN `user_pref_platform_id` tinyint(3) unsigned NOT NULL;";
			$result = _mysqli_query($sql);

			$sql = "CREATE TABLE IF NOT EXISTS `202_api_keys` (
				  `user_id` mediumint(8) unsigned NOT NULL,
				  `api_key` varchar(250) NOT NULL DEFAULT '',
				  `created_at` int(10) NOT NULL
				) ENGINE=InnoDB ;";
			$result = _mysqli_query($sql);
			
			$sql = "TRUNCATE TABLE 202_browsers;";
			$result = _mysqli_query($sql);

			$sql = "TRUNCATE TABLE 202_platforms;";
			$result = _mysqli_query($sql);

			$sql ="CREATE TABLE IF NOT EXISTS `202_device_types` (
			  `type_id` tinyint(1) unsigned NOT NULL,
			  `type_name` varchar(50) NOT NULL,
			  PRIMARY KEY (`type_id`)
			) ENGINE=InnoDB  ;";
			$result = _mysqli_query($sql);

			$sql ="INSERT INTO `202_device_types` (`type_id`, `type_name`)
				VALUES
					(1, 'Desktop'),
					(2, 'Mobile'),
					(3, 'Tablet'),
					(4, 'Bot');";
			$result = _mysqli_query($sql);

			$sql = "CREATE TABLE IF NOT EXISTS `202_device_models` (
			  `device_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			  `device_name` varchar(50) NOT NULL,
			  `device_type` tinyint(1) NOT NULL,
			  PRIMARY KEY (`device_id`)
			) ENGINE=InnoDB ;";
			$result = _mysqli_query($sql);

			$sql = "ALTER TABLE `202_clicks_advance` ADD COLUMN `device_id` bigint(20) unsigned NOT NULL;";
			$result = _mysqli_query($sql);

			$sql = "ALTER TABLE `202_clicks` ADD COLUMN `click_bot` tinyint(1) NOT NULL default '0';";
			$result = _mysqli_query($sql);

			$sql = "ALTER TABLE `202_clicks_spy` ADD COLUMN `click_bot` tinyint(1) NOT NULL default '0';";
			$result = _mysqli_query($sql);

			$sql = "CREATE TABLE `202_sort_devices` (
			  `sort_device_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
			  `user_id` mediumint(8) unsigned NOT NULL,
			  `device_id` bigint(20) unsigned NOT NULL,
			  `sort_device_clicks` mediumint(8) unsigned NOT NULL,
			  `sort_device_leads` mediumint(8) unsigned NOT NULL,
			  `sort_device_su_ratio` decimal(10,2) NOT NULL,
			  `sort_device_payout` decimal(6,2) NOT NULL,
			  `sort_device_epc` decimal(10,2) NOT NULL,
			  `sort_device_avg_cpc` decimal(7,5) NOT NULL,
			  `sort_device_income` decimal(10,2) NOT NULL,
			  `sort_device_cost` decimal(13,5) NOT NULL,
			  `sort_device_net` decimal(13,5) NOT NULL,
			  `sort_device_roi` decimal(10,2) NOT NULL,
			  PRIMARY KEY (`sort_device_id`)
			) ENGINE=InnoDB ;";
			$result = _mysqli_query($sql);

			$sql = "CREATE TABLE IF NOT EXISTS `202_sort_browsers` (
			  `sort_browser_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
			  `user_id` mediumint(8) unsigned NOT NULL,
			  `browser_id` bigint(20) unsigned NOT NULL,
			  `sort_browser_clicks` mediumint(8) unsigned NOT NULL,
			  `sort_browser_leads` mediumint(8) unsigned NOT NULL,
			  `sort_browser_su_ratio` decimal(10,2) NOT NULL,
			  `sort_browser_payout` decimal(6,2) NOT NULL,
			  `sort_browser_epc` decimal(10,2) NOT NULL,
			  `sort_browser_avg_cpc` decimal(7,5) NOT NULL,
			  `sort_browser_income` decimal(10,2) NOT NULL,
			  `sort_browser_cost` decimal(13,5) NOT NULL,
			  `sort_browser_net` decimal(13,5) NOT NULL,
			  `sort_browser_roi` decimal(10,2) NOT NULL,
			  PRIMARY KEY (`sort_browser_id`)
			) ENGINE=InnoDB ;";
			$result = _mysqli_query($sql);
			
			$sql = "CREATE TABLE IF NOT EXISTS `202_sort_platforms` (
			  `sort_platform_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
			  `user_id` mediumint(8) unsigned NOT NULL,
			  `platform_id` bigint(20) unsigned NOT NULL,
			  `sort_platform_clicks` mediumint(8) unsigned NOT NULL,
			  `sort_platform_leads` mediumint(8) unsigned NOT NULL,
			  `sort_platform_su_ratio` decimal(10,2) NOT NULL,
			  `sort_platform_payout` decimal(6,2) NOT NULL,
			  `sort_platform_epc` decimal(10,2) NOT NULL,
			  `sort_platform_avg_cpc` decimal(7,5) NOT NULL,
			  `sort_platform_income` decimal(10,2) NOT NULL,
			  `sort_platform_cost` decimal(13,5) NOT NULL,
			  `sort_platform_net` decimal(13,5) NOT NULL,
			  `sort_platform_roi` decimal(10,2) NOT NULL,
			  PRIMARY KEY (`sort_platform_id`)
			) ENGINE=InnoDB ;";
			$result = _mysqli_query($sql);

			$sql="ALTER TABLE `202_users` ADD COLUMN `install_hash` varchar(255) NOT NULL,
										  ADD COLUMN `user_hash` varchar(255) NOT NULL,
										  ADD COLUMN `modal_status` int(1) NOT NULL,
										  ADD COLUMN `vip_perks_status` int(1) NOT NULL;";
			$result = _mysqli_query($sql);

			$hash = md5(uniqid(rand(), TRUE));
			$user_hash = intercomHash($hash);

			$sql="UPDATE 202_users SET install_hash='".$hash."', user_hash='".$user_hash."' WHERE user_id='1'";
			$result = _mysqli_query($sql);

			$sql = "UPDATE 202_version SET version='1.8.2.1'; ";
			$result = _mysqli_query($sql);
			$mysql_version = '1.8.2.1';
		
		}

		//upgrade from 1.8.2.1 to 1.8.2.2
		if ($mysql_version == '1.8.2.1') {
			
			$sql = "ALTER TABLE `202_clicks_advance` ADD COLUMN `region_id` bigint(20) unsigned NOT NULL;";
			$result = _mysqli_query($sql);

			$sql ="CREATE TABLE IF NOT EXISTS `202_locations_region` (
				  `region_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				  `main_country_id` mediumint(8) unsigned NOT NULL,
				  `region_name` varchar(50) NOT NULL,
				  PRIMARY KEY (`region_id`)
				) ENGINE=InnoDB  ;";
			$result = _mysqli_query($sql);

			$sql = "CREATE TABLE IF NOT EXISTS `202_sort_regions` (
			  `sort_regions_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
			  `user_id` mediumint(8) unsigned NOT NULL,
			  `region_id` bigint(20) unsigned NOT NULL,
			  `country_id` bigint(20) unsigned NOT NULL,
			  `sort_region_clicks` mediumint(8) unsigned NOT NULL,
			  `sort_region_leads` mediumint(8) unsigned NOT NULL,
			  `sort_region_su_ratio` decimal(10,2) NOT NULL,
			  `sort_region_payout` decimal(6,2) NOT NULL,
			  `sort_region_epc` decimal(10,2) NOT NULL,
			  `sort_region_avg_cpc` decimal(7,5) NOT NULL,
			  `sort_region_income` decimal(10,2) NOT NULL,
			  `sort_region_cost` decimal(13,5) NOT NULL,
			  `sort_region_net` decimal(13,5) NOT NULL,
			  `sort_region_roi` decimal(10,2) NOT NULL,
			  PRIMARY KEY (`sort_regions_id`)
			) ENGINE=InnoDB;";
			$result = _mysqli_query($sql);

			$sql = "ALTER TABLE `202_users_pref` ADD COLUMN `user_pref_region_id` tinyint(3) unsigned NOT NULL;";
			$result = _mysqli_query($sql);

			$sql = "UPDATE 202_version SET version='1.8.2.2'; ";
			$result = _mysqli_query($sql);
			$mysql_version = '1.8.2.2';
		
		}

		//upgrade from 1.8.2.2 to 1.8.3
		if ($mysql_version == '1.8.2.2') {
			
			$sql="CREATE TABLE IF NOT EXISTS `202_rotators` (
			  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			  `user_id` int(11) NOT NULL,
			  `tracker_id` int(11) NOT NULL,
			  `name` varchar(255) NOT NULL DEFAULT '',
			  `default_url` text NOT NULL,
			  `redirect_url` text NOT NULL,
			  `redirect_campaign` int(11) DEFAULT NULL,
  			  `default_campaign` int(11) DEFAULT NULL,
			  PRIMARY KEY (`id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8;";
			$result = _mysqli_query($sql);

			$sql="CREATE TABLE IF NOT EXISTS `202_rotator_rules` (
			  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			  `rotator_id` int(11) NOT NULL,
			  `type` varchar(50) NOT NULL DEFAULT '',
			  `statement` varchar(50) NOT NULL DEFAULT '',
			  `value` text NOT NULL,
			  PRIMARY KEY (`id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8;";
			$result = _mysqli_query($sql);

			$sql="CREATE TABLE IF NOT EXISTS `202_rotator_clicks` (
			  `click_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			  `user_id` mediumint(8) unsigned NOT NULL,
			  `rotator_id` mediumint(8) unsigned NOT NULL,
			  `click_time` int(10) unsigned NOT NULL,
			  `redirects` int(1) unsigned NOT NULL,
			  `defaults` int(1) unsigned NOT NULL,
			  PRIMARY KEY (`click_id`),
			  KEY `rotator_id` (`rotator_id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8;";
			$result = _mysqli_query($sql);

			$sql="CREATE TABLE IF NOT EXISTS `202_sort_rotators` (
			  `sort_rotator_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
			  `user_id` mediumint(8) unsigned NOT NULL,
			  `campaign_id` mediumint(8) unsigned NOT NULL,
			  `rotator_id` mediumint(8) unsigned NOT NULL,
			  `sort_rotator_clicks` mediumint(8) unsigned NOT NULL,
			  `sort_rotator_leads` mediumint(8) unsigned NOT NULL,
			  `sort_rotator_su_ratio` decimal(10,2) NOT NULL,
			  `sort_rotator_payout` decimal(6,2) NOT NULL,
			  `sort_rotator_epc` decimal(10,2) NOT NULL,
			  `sort_rotator_avg_cpc` decimal(7,5) NOT NULL,
			  `sort_rotator_income` decimal(10,2) NOT NULL,
			  `sort_rotator_cost` decimal(13,5) NOT NULL,
			  `sort_rotator_net` decimal(13,5) NOT NULL,
			  `sort_rotator_roi` decimal(10,2) NOT NULL,
			  `type` varchar(50) NOT NULL DEFAULT '',
			  PRIMARY KEY (`sort_rotator_id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8;";
			$result = _mysqli_query($sql);

			$sql = "ALTER TABLE `202_clicks` ADD COLUMN `rotator_id` mediumint(0) unsigned NOT NULL;";
			$result = _mysqli_query($sql);

			$sql = "DROP TABLE IF EXISTS 202_sort_browsers, 202_sort_cities, 202_sort_countries, 202_sort_devices, 202_sort_ips, 202_sort_isps, 202_sort_keywords, 202_sort_keywords_lpctr, 202_sort_landing_pages, 202_sort_platforms, 202_sort_referers, 202_sort_referers_lpctr, 202_sort_regions, 202_sort_text_ads, 202_sort_text_ads_lpctr;";
			$result = _mysqli_query($sql);

			$sql = "UPDATE 202_version SET version='1.8.3'; ";
			$result = _mysqli_query($sql);
			$mysql_version = '1.8.3';
		}

		//upgrade from 1.8.3 to 1.8.3.1
		if ($mysql_version == '1.8.3') {
			$sql = "UPDATE 202_version SET version='1.8.3.1'; ";
			$result = _mysqli_query($sql);
			$mysql_version = '1.8.3.1';
		}

		//upgrade from 1.8.3.1 to 1.8.3.2
		if ($mysql_version == '1.8.3.1') {
			$sql = "UPDATE 202_version SET version='1.8.3.2'; ";
			$result = _mysqli_query($sql);
			$mysql_version = '1.8.3.2';
		}

		//upgrade from 1.8.3.2 to 1.8.3.3
		if ($mysql_version == '1.8.3.2') {
			$sql = "UPDATE 202_version SET version='1.8.3.3'; ";
			$result = _mysqli_query($sql);
			$mysql_version = '1.8.3.3';
		}

		//upgrade from 1.8.3.3 to 1.8.4
		if ($mysql_version == '1.8.3.3') {
			
			$sql = "ALTER TABLE `202_clicks` MODIFY `rotator_id` int(10) unsigned NOT NULL;";
			$result = _mysqli_query($sql);

			$sql = "ALTER TABLE `202_clicks` ADD COLUMN `rule_id` int(10) unsigned NOT NULL;";
			$result = _mysqli_query($sql);

			$sql = "ALTER TABLE `202_trackers` ADD COLUMN `rotator_id` int(11) unsigned NOT NULL;";
			$result = _mysqli_query($sql);

			$sql = "DROP TABLE IF EXISTS 202_sort_rotators, 202_rotator_rules, 202_rotator_clicks, 202_rotators;";
			$result = _mysqli_query($sql);

			$sql="CREATE TABLE `202_rotators` (
			  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			  `user_id` int(11) NOT NULL,
			  `name` varchar(255) NOT NULL DEFAULT '',
			  `default_url` text,
			  `default_campaign` int(11) DEFAULT NULL,
			  PRIMARY KEY (`id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8;";
			$result = _mysqli_query($sql);

			$sql="CREATE TABLE `202_rotator_rules` (
			  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			  `rotator_id` int(11) NOT NULL,
			  `rule_name` varchar(255) NOT NULL DEFAULT '',
			  `status` int(11) DEFAULT NULL,
			  `redirect_url` text,
			  `redirect_campaign` int(11) DEFAULT NULL,
			  PRIMARY KEY (`id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8;";
			$result = _mysqli_query($sql);

			$sql="CREATE TABLE `202_rotator_rules_criteria` (
			  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			  `rotator_id` int(11) NOT NULL,
			  `rule_id` int(11) NOT NULL,
			  `type` varchar(50) NOT NULL DEFAULT '',
			  `statement` varchar(50) NOT NULL DEFAULT '',
			  `value` text NOT NULL,
			  PRIMARY KEY (`id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8;";
			$result = _mysqli_query($sql);

			$sql = "SELECT  CONCAT('ALTER TABLE ', table_name, ' ENGINE=InnoDB;') AS sql_statements
			FROM    information_schema.tables AS tb
			WHERE   table_schema = '".$dbname."'
			AND     `ENGINE` = 'MyISAM'
			AND     `TABLE_TYPE` = 'BASE TABLE'
			ORDER BY table_name DESC;";
			$result = _mysqli_query($sql);

			while ($row = $result->fetch_assoc()) {
			    $db->query($row['sql_statements']);
			}

			$sql = "UPDATE 202_version SET version='1.8.4'; ";
			$result = _mysqli_query($sql);
			$mysql_version = '1.8.4';
		}

		return true;
	}
}