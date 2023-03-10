<?php

class PROSPER202
{

    function prosper202_version()
    {
        $database = DB::getInstance();
        $db = $database->getConnection();
        
        // select the mysql version
        $version_sql = "SELECT version FROM 202_version";
        $version_result = $db->query($version_sql);
        $version_row = $version_result->fetch_assoc();
        $prosper202_version = $version_row['version'];
        
        // if there is no mysql version, this is an older 1.0.0-1.0.2 release, just return version 1.0.0 for simplicitly sake
        if (! $prosper202_version) {
            $prosper202_version = '1.0.2';
        }
        
        return $prosper202_version;
    }

    function php_version()
    {
        global $version;
        $php_version = $version;
        return $php_version;
    }
}

class UPGRADE
{

    function upgrade_databases($time_from)
    {             
        global $dbname;
        
        ini_set('max_execution_time', 60 * 20);
        ini_set('max_input_time', 60 * 20);
        
        //Try to disable mysql strict mode
        $sql = "SET session sql_mode= ''";
        $result = _mysqli_query($sql);

        $partition_start = time();
        $partition_end = strtotime('+3 years', $partition_start);
        
        $sql = "SELECT PLUGIN_NAME as Name, PLUGIN_STATUS as Status FROM INFORMATION_SCHEMA.PLUGINS WHERE PLUGIN_TYPE='STORAGE ENGINE' AND PLUGIN_NAME='partition' AND PLUGIN_STATUS='ACTIVE'";
        $result = _mysqli_query($sql);
        
        if ($result->num_rows != 1) {
            $mysql_partitioning_fail = 1;
        }

        // get the old version
        $prosper202_version = PROSPER202::prosper202_version();
        $php_version = PROSPER202::php_version();
        
        // if the mysql is 1.0.2, upgrade to 1.0.3
        if ($prosper202_version == '1.0.2') {
            
            // create the new mysql version table
            $sql = "CREATE TABLE IF NOT EXISTS `202_version` (
					  `version` varchar(50) NOT NULL
					) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);
            
            // drop the old table
            $sql = "DROP TABLE `202_sort_landings`";
            $result = _mysqli_query($sql);
            
            // create the new landing page sorting table
            $sql = "CREATE TABLE IF NOT EXISTS `202_sort_landing_pages` (
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
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);
            
            // this is now up to 1.0.3
            $sql = "INSERT INTO 202_version SET version='1.0.3'";
            $result = _mysqli_query($sql);
            
            // now set the new mysql version
            $prosper202_version = '1.0.3';
        }
        
        // upgrade from 1.0.3 to 1.0.4
        if ($prosper202_version == '1.0.3') {
            $sql = "UPDATE 202_version SET version='1.0.4'";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.0.4';
        }
        
        // upgrade from 1.0.4 to 1.0.5
        if ($prosper202_version == '1.0.4') {
            $sql = "UPDATE 202_version SET version='1.0.5'";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.0.5';
        }
        
        // upgrade from 1.0.5 to 1.0.6
        if ($prosper202_version == '1.0.5') {
            $sql = "UPDATE 202_version SET version='1.0.6'";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.0.6';
        }
        
        // upgrade from 1.0.6 to 1.1.0 - here we had some database modifications to make it scale better.
        if ($prosper202_version == '1.0.6') {
            
            // this is upgrading things to BIGINT
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
            
            // mysql version set to 1.1.0 now
            $sql = "UPDATE 202_version SET version='1.1.0'";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.1.0';
        }
        
        // upgrade from 1.1.0 to 1.1.1
        if ($prosper202_version == '1.1.0') {
            $sql = "UPDATE 202_version SET version='1.1.1'";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.1.1';
        }
        
        // upgrade from 1.1.1 to 1.1.2
        if ($prosper202_version == '1.1.1') {
            $sql = "UPDATE 202_version SET version='1.1.2'";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.1.2';
        }
        
        // upgrade from 1.1.2 to 1.2.0
        if ($prosper202_version == '1.1.2') {
            
            $result = _mysqli_query("	 CREATE TABLE IF NOT EXISTS `202_rotations` (
										  `aff_campaign_id` mediumint(8) unsigned NOT NULL,
										  `rotation_num` tinyint(4) NOT NULL,
										  PRIMARY KEY (`aff_campaign_id`)
										) ENGINE=MEMORY DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
            
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
            
            $result = _mysqli_query(" 	ALTER TABLE  `202_aff_campaigns` CHANGE  `aff_campaign_url`  `aff_campaign_url` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL");
            
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
            $prosper202_version = '1.2.0';
        }
        
        // upgrade from 1.2.0 to 1,2,1
        if ($prosper202_version == '1.2.0') {
            $sql = "UPDATE 202_version SET version='1.2.1'";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.2.1';
        }
        
        // upgrade from 1.2.1 to 1.3.0
        if ($prosper202_version == '1.2.1') {
            
            $result = _mysqli_query(" 	ALTER TABLE  `202_users` ADD  `user_api_key` VARCHAR( 255 ) NOT NULL AFTER  `user_pass_time` ; ");
            $result = _mysqli_query(" 	ALTER TABLE  `202_users` ADD  `user_stats202_app_key` VARCHAR( 255 ) NOT NULL AFTER  `user_api_key` ; ");
            $sql = "UPDATE 202_version SET version='1.3.0'";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.3.0';
        }
        
        // upgrade from 1.3.0 to 1.3.1
        if ($prosper202_version == '1.3.0') {
            
            $result = _mysqli_query(" 	ALTER TABLE  `202_clicks_spy` ENGINE = InnoDB ");
            $result = _mysqli_query(" 	ALTER TABLE  `202_last_ips` ENGINE = InnoDB ");
            
            $sql = "UPDATE 202_version SET version='1.3.1'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.3.1';
        }
        
        // upgrade from 1.3.1 to 1.3.2
        if ($prosper202_version == '1.3.1') {
            
            $result = _mysqli_query(" 	ALTER TABLE  `202_clicks_spy` ENGINE = InnoDB ");
            $result = _mysqli_query(" 	ALTER TABLE  `202_last_ips` ENGINE = InnoDB ");
            
            $sql = "UPDATE 202_version SET version='1.3.2'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.3.2';
        }
        
        // upgrade from 1.3.2 to 1.4
        if ($prosper202_version == '1.3.2') {
            
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
										) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
            
            $sql = "UPDATE 202_version SET version='1.4'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.4';
        }
        
        // upgrade from 1.4 to 1.4.1
        if ($prosper202_version == '1.4') {
            $result = _mysqli_query(" 	CREATE TABLE `202_tracking_c1` (
										  `c1_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
										  `c1` varchar(50) NOT NULL,
										  PRIMARY KEY (`c1_id`),
										  UNIQUE KEY `c1` (`c1`(191))
										) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
            
            $result = _mysqli_query(" 	CREATE TABLE `202_tracking_c2` (
										  `c2_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
										  `c2` varchar(50) NOT NULL,
										  PRIMARY KEY (`c2_id`),
										  UNIQUE KEY `c2` (`c2`(191))
										) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
            
            $result = _mysqli_query(" 	CREATE TABLE `202_tracking_c3` (
										  `c3_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
										  `c3` varchar(50) NOT NULL,
										  PRIMARY KEY (`c3_id`),
										  UNIQUE KEY `c3` (`c3`(191))
										) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
            
            $result = _mysqli_query(" 	CREATE TABLE `202_tracking_c4` (
										  `c4_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
										  `c4` varchar(50) NOT NULL,
										  PRIMARY KEY (`c4_id`),
										  UNIQUE KEY `c4` (`c4`(191))
										) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
            $sql = "UPDATE 202_version SET version='1.4.1'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.4.1';
        }
        
        // upgrade from 1.4.1 to 1.4.2
        if ($prosper202_version == '1.4.1') {
            $result = _mysqli_query(" 	 DROP TABLE `202_clicks_tracking`; ");
            
            $result = _mysqli_query(" 	 CREATE TABLE `202_clicks_tracking` (
										  `click_id` bigint(20) unsigned NOT NULL,
										  `c1_id` bigint(20) NOT NULL,
										  `c2_id` bigint(20) NOT NULL,
										  `c3_id` bigint(20) NOT NULL,
										  `c4_id` bigint(20) NOT NULL,
										  PRIMARY KEY (`click_id`)
										) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
            
            $sql = "UPDATE 202_version SET version='1.4.2'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.4.2';
        }
        
        // upgrade from 1.4.2 to 1.4.3
        if ($prosper202_version == '1.4.2') {
            
            $result = _mysqli_query(" 	ALTER TABLE  `202_clicks_spy` ENGINE = InnoDB ");
            $result = _mysqli_query(" 	ALTER TABLE  `202_last_ips` ENGINE = InnoDB ");
            
            $sql = "UPDATE 202_version SET version='1.4.3'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.4.3';
        }
        
        // upgrade from 1.4.3 to 1.5
        if ($prosper202_version == '1.4.3') {
            
            $sql = "UPDATE 202_version SET version='1.5'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.5';
        }
        
        // upgrade from 1.5 to 1.5.1
        if ($prosper202_version == '1.5') {
            
            $sql = "UPDATE 202_version SET version='1.5.1'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.5.1';
        }
        
        // upgrade from 1.5.1 to 1.6
        if ($prosper202_version == '1.5.1') {
            
            $result = _mysqli_query("CREATE TABLE IF NOT EXISTS `202_alerts` (
			  `prosper_alert_id` int(11) NOT NULL,
			  `prosper_alert_seen` tinyint(1) NOT NULL,
			  UNIQUE KEY `prosper_alert_id` (`prosper_alert_id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
            
            $result = _mysqli_query("CREATE TABLE IF NOT EXISTS `202_offers` (
				  `user_id` mediumint(8) unsigned NOT NULL,
				  `offer_id` mediumint(10) unsigned NOT NULL,
				  `offer_seen` tinyint(1) NOT NULL DEFAULT '1',
				  UNIQUE KEY `user_id` (`user_id`,`offer_id`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
            
            $result = _mysqli_query("ALTER TABLE  `202_cronjobs` ENGINE = InnoDB;");
            
            $sql = "UPDATE 202_version SET version='1.6'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.6';
        }
        
        // upgrade from 1.6 beta to 1.6.1 stable
        if ($prosper202_version == '1.6') {
            
            $sql = "UPDATE 202_version SET version='1.6.1'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.6.1';
        }
        
        // upgrade from 1.6.1 to 1.6.2 beta
        if ($prosper202_version == '1.6.1') {
            
            $sql = "UPDATE 202_version SET version='1.6.2'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.6.2';
        }
        
        // upgrade from 1.6.2 to 1.7 beta
        if ($prosper202_version == '1.6.2') {
            
            $sql = "UPDATE 202_version SET version='1.7'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.7';
            
            $sql = "CREATE TABLE IF NOT EXISTS `202_pixel_types` (
  			  `pixel_type_id` TINYINT UNSIGNED NOT NULL AUTO_INCREMENT ,
  		  	  `pixel_type` VARCHAR(45) NULL ,
  			  PRIMARY KEY (`pixel_type_id`) ,
  		      UNIQUE INDEX `pixel_type_UNIQUE` (`pixel_type` ASC) 
  			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);
            
            $sql = "CREATE TABLE IF NOT EXISTS `202_ppc_account_pixels` (
 			  `pixel_id` mediumint(8) unsigned NOT NULL auto_increment,
  			  `pixel_code` text NOT NULL,
  			  `pixel_type_id` mediumint(8) unsigned NOT NULL,
  			  `ppc_account_id` mediumint(8) unsigned NOT NULL,
  			  PRIMARY KEY  (`pixel_id`)
 			  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);
            
            $sql = "CREATE TABLE `202_clicks_total` (
			  `click_count` int(20) unsigned NOT NULL default '0',
 			  PRIMARY KEY  (`click_count`)
			  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);
            
            $sql = "INSERT IGNORE INTO `202_pixel_types` (`pixel_type`) VALUES 
			  ('Image'),
			  ('Iframe'),
			  ('Javascript'),
			  ('Postback')";
            $result = _mysqli_query($sql);
            
            $sql = "INSERT IGNORE INTO `202_platforms` (`platform_name`) VALUES 
			  ('Mobile'),
			  ('Tablet');";
            $result = _mysqli_query($sql);
            
            $sql = "INSERT IGNORE INTO `202_clicks_total` (`click_count`) VALUES
		(0);";
            $result = _mysqli_query($sql);
        }
        
        // upgrade from 1.7 beta to 1.7.1 beta
        if ($prosper202_version == '1.7') {
            
            $sql = "UPDATE 202_version SET version='1.7.1'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.7.1';
            $sql = "CREATE TABLE IF NOT EXISTS `202_sort_keywords_lpctr` (
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
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);
            
            $sql = "CREATE TABLE IF NOT EXISTS `202_sort_text_ads_lpctr` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);
            
            $sql = "CREATE TABLE IF NOT EXISTS `202_sort_referers_lpctr` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);
            
            $sql = "ALTER TABLE `202_tracking_c1` CHANGE COLUMN `c1` `c1` VARCHAR(350) NOT NULL  ;";
            $result = _mysqli_query($sql);
            $sql = "ALTER TABLE `202_tracking_c2` CHANGE COLUMN `c2` `c2` VARCHAR(350) NOT NULL  ;";
            $result = _mysqli_query($sql);
            $sql = "ALTER TABLE `202_tracking_c3` CHANGE COLUMN `c3` `c3` VARCHAR(350) NOT NULL  ;";
            $result = _mysqli_query($sql);
            $sql = "ALTER TABLE `202_tracking_c4` CHANGE COLUMN `c4` `c4` VARCHAR(350) NOT NULL  ;";
            $result = _mysqli_query($sql);
        }
        
        // upgrade from 1.7.1 to 1.7.2 beta
        if ($prosper202_version == '1.7.1') {
            
            $sql = "UPDATE 202_version SET version='1.7.2'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.7.2';
        }
        
        // upgrade from 1.7.2 to 1.7.3
        if ($prosper202_version == '1.7.2') {
            
            $sql = "UPDATE 202_version SET version='1.7.3'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.7.3';
            $sql = "ALTER TABLE `202_users` MODIFY COLUMN `user_timezone` VARCHAR(50) NOT NULL default 'Pacific/Pitcairn';";
            $result = _mysqli_query($sql);
            $sql = "UPDATE `202_users` SET user_timezone='Pacific/Pitcairn' WHERE user_id=1";
            $result = _mysqli_query($sql);
            $sql = "ALTER TABLE `202_sort_breakdowns`" . " ADD `sort_breakdown_click_throughs` mediumint(8) unsigned NOT NULL AFTER `sort_breakdown_clicks`," . " ADD `sort_breakdown_ctr` decimal(10,2) NOT NULL AFTER `sort_breakdown_click_throughs`," . " ADD KEY `sort_breakdown_click_throughs` (`sort_breakdown_click_throughs`)," . " ADD KEY `sort_breakdown_ctr` (`sort_breakdown_ctr`)";
            $result = _mysqli_query($sql);
            $sql = "ALTER TABLE `202_clicks_spy` ADD INDEX (`click_id`)";
            $result = _mysqli_query($sql);
            $sql = "INSERT INTO `202_pixel_types` (`pixel_type`) VALUES ('Raw')";
            $result = _mysqli_query($sql);
            $sql = "ALTER TABLE `202_users_pref` ADD COLUMN `cache_time` VARCHAR(4) NOT NULL default '0';";
            $result = _mysqli_query($sql);
        }
        
        // upgrade from 1.7.3 to 1.7.4
        if ($prosper202_version == '1.7.3') {
            
            $sql = "UPDATE 202_version SET version='1.7.4'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.7.4';
            $sql = "ALTER TABLE `202_users_pref` ADD COLUMN `cb_key` VARCHAR(250) NOT NULL;";
            $result = _mysqli_query($sql);
            $sql = "ALTER TABLE `202_users_pref` ADD COLUMN `cb_verified` tinyint(1) NOT NULL default '0';";
            $result = _mysqli_query($sql);
        }
        
        // upgrade from 1.7.4 to 1.7.5
        if ($prosper202_version == '1.7.4') {
            
            $sql = "UPDATE 202_version SET version='1.7.5'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.7.5';
        }
        
        // upgrade from 1.7.5 to 1.7.6
        if ($prosper202_version == '1.7.5') {
            
            $sql = "UPDATE 202_version SET version='1.7.6'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.7.6';
            $sql = "ALTER TABLE `202_users` ADD COLUMN `clickserver_api_key` VARCHAR(250) NOT NULL;";
            $result = _mysqli_query($sql);
        }
        
        // upgrade from 1.7.6 to 1.8.0
        if ($prosper202_version == '1.7.6') {
            
            $sql = "UPDATE 202_version SET version='1.8.0'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.8.0';
        }
        
        // upgrade from 1.8.0 to 1.8.1
        if ($prosper202_version == '1.8.0') {
            
            $sql = "UPDATE 202_version SET version='1.8.1'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.8.1';
        }
        
        // upgrade from 1.8.1 to 1.8.2
        if ($prosper202_version == '1.8.1') {
            
            $sql = "UPDATE 202_version SET version='1.8.2'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.8.2';
        }
        
        // upgrade from 1.8.2 to 1.8.2.1
        if ($prosper202_version == '1.8.2') {
            
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
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);
            
            $sql = "CREATE TABLE `202_locations_country` (
				  `country_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				  `country_code` varchar(3) NOT NULL DEFAULT '',
				  `country_name` varchar(50) NOT NULL DEFAULT '',
				  PRIMARY KEY (`country_id`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
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
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
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
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);
            
            $sql = "CREATE TABLE `202_locations_isp` (
				  `isp_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				  `isp_name` varchar(50) NOT NULL DEFAULT '',
				  PRIMARY KEY (`isp_id`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);
            
            $sql = "CREATE TABLE `202_sort_isps` (
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
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
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
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);
            
            $sql = "TRUNCATE TABLE 202_browsers;";
            $result = _mysqli_query($sql);
            
            $sql = "TRUNCATE TABLE 202_platforms;";
            $result = _mysqli_query($sql);
            
            $sql = "CREATE TABLE IF NOT EXISTS `202_device_types` (
			  `type_id` tinyint(1) unsigned NOT NULL,
			  `type_name` varchar(50) NOT NULL,
			  PRIMARY KEY (`type_id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);
            
            $sql = "INSERT INTO `202_device_types` (`type_id`, `type_name`)
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
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
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
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
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
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
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
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);
            
            $sql = "ALTER TABLE `202_users` ADD COLUMN `install_hash` varchar(255) NOT NULL,
										  ADD COLUMN `user_hash` varchar(255) NOT NULL,
										  ADD COLUMN `modal_status` int(1) NOT NULL,
										  ADD COLUMN `vip_perks_status` int(1) NOT NULL;";
            $result = _mysqli_query($sql);
            
            $hash = md5(uniqid(rand(), TRUE));
            $user_hash = intercomHash($hash);
            
            $sql = "UPDATE 202_users SET install_hash='" . $hash . "', user_hash='" . $user_hash . "' WHERE user_id='1'";
            $result = _mysqli_query($sql);
            
            $sql = "UPDATE 202_version SET version='1.8.2.1'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.8.2.1';
        }
        
        // upgrade from 1.8.2.1 to 1.8.2.2
        if ($prosper202_version == '1.8.2.1') {
            
            $sql = "ALTER TABLE `202_clicks_advance` ADD COLUMN `region_id` bigint(20) unsigned NOT NULL;";
            $result = _mysqli_query($sql);
            
            $sql = "CREATE TABLE IF NOT EXISTS `202_locations_region` (
				  `region_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				  `main_country_id` mediumint(8) unsigned NOT NULL,
				  `region_name` varchar(50) NOT NULL,
				  PRIMARY KEY (`region_id`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
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
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);
            
            $sql = "ALTER TABLE `202_users_pref` ADD COLUMN `user_pref_region_id` tinyint(3) unsigned NOT NULL;";
            $result = _mysqli_query($sql);
            
            $sql = "UPDATE 202_version SET version='1.8.2.2'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.8.2.2';
        }
        
        // upgrade from 1.8.2.2 to 1.8.3
        if ($prosper202_version == '1.8.2.2') {
            
            $sql = "CREATE TABLE IF NOT EXISTS `202_rotators` (
			  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			  `user_id` int(11) NOT NULL,
			  `tracker_id` int(11) NOT NULL,
			  `name` varchar(255) NOT NULL DEFAULT '',
			  `default_url` text NOT NULL,
			  `redirect_url` text NOT NULL,
			  `redirect_campaign` int(11) DEFAULT NULL,
  			  `default_campaign` int(11) DEFAULT NULL,
			  PRIMARY KEY (`id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);
            
            $sql = "CREATE TABLE IF NOT EXISTS `202_rotator_rules` (
			  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			  `rotator_id` int(11) NOT NULL,
			  `type` varchar(50) NOT NULL DEFAULT '',
			  `statement` varchar(50) NOT NULL DEFAULT '',
			  `value` text NOT NULL,
			  PRIMARY KEY (`id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);
            
            $sql = "CREATE TABLE IF NOT EXISTS `202_rotator_clicks` (
			  `click_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			  `user_id` mediumint(8) unsigned NOT NULL,
			  `rotator_id` mediumint(8) unsigned NOT NULL,
			  `click_time` int(10) unsigned NOT NULL,
			  `redirects` int(1) unsigned NOT NULL,
			  `defaults` int(1) unsigned NOT NULL,
			  PRIMARY KEY (`click_id`),
			  KEY `rotator_id` (`rotator_id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);
            
            $sql = "CREATE TABLE IF NOT EXISTS `202_sort_rotators` (
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
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);
            
            $sql = "ALTER TABLE `202_clicks` ADD COLUMN `rotator_id` mediumint(0) unsigned NOT NULL;";
            $result = _mysqli_query($sql);
            
            $sql = "DROP TABLE IF EXISTS 202_sort_browsers, 202_sort_cities, 202_sort_countries, 202_sort_devices, 202_sort_ips, 202_sort_isps, 202_sort_keywords, 202_sort_keywords_lpctr, 202_sort_landing_pages, 202_sort_platforms, 202_sort_referers, 202_sort_referers_lpctr, 202_sort_regions, 202_sort_text_ads, 202_sort_text_ads_lpctr;";
            $result = _mysqli_query($sql);
            
            $sql = "UPDATE 202_version SET version='1.8.3'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.8.3';
        }
        
        // upgrade from 1.8.3 to 1.8.3.1
        if ($prosper202_version == '1.8.3') {
            $sql = "UPDATE 202_version SET version='1.8.3.1'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.8.3.1';
        }
        
        // upgrade from 1.8.3.1 to 1.8.3.2
        if ($prosper202_version == '1.8.3.1') {
            $sql = "UPDATE 202_version SET version='1.8.3.2'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.8.3.2';
        }
        
        // upgrade from 1.8.3.2 to 1.8.3.3
        if ($prosper202_version == '1.8.3.2') {
            $sql = "UPDATE 202_version SET version='1.8.3.3'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.8.3.3';
        }
        
        // upgrade from 1.8.3.3 to 1.8.4
        if ($prosper202_version == '1.8.3.3') {
            
            $sql = "ALTER TABLE `202_clicks` MODIFY `rotator_id` int(10) unsigned NOT NULL;";
            $result = _mysqli_query($sql);
            
            $sql = "ALTER TABLE `202_clicks` ADD COLUMN `rule_id` int(10) unsigned NOT NULL;";
            $result = _mysqli_query($sql);
            
            $sql = "ALTER TABLE `202_trackers` ADD COLUMN `rotator_id` int(11) unsigned NOT NULL;";
            $result = _mysqli_query($sql);
            
            $sql = "DROP TABLE IF EXISTS 202_sort_rotators, 202_rotator_rules, 202_rotator_clicks, 202_rotators;";
            $result = _mysqli_query($sql);
            
            $sql = "CREATE TABLE IF NOT EXISTS `202_rotators` (
			  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			  `user_id` int(11) NOT NULL,
			  `name` varchar(255) NOT NULL DEFAULT '',
			  `default_url` text,
			  `default_campaign` int(11) DEFAULT NULL,
			  PRIMARY KEY (`id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);
            
            $sql = "CREATE TABLE IF NOT EXISTS `202_rotator_rules` (
			  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			  `rotator_id` int(11) NOT NULL,
			  `rule_name` varchar(255) NOT NULL DEFAULT '',
			  `status` int(11) DEFAULT NULL,
			  `redirect_url` text,
			  `redirect_campaign` int(11) DEFAULT NULL,
			  PRIMARY KEY (`id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);
            
            $sql = "CREATE TABLE IF NOT EXISTS `202_rotator_rules_criteria` (
			  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			  `rotator_id` int(11) NOT NULL,
			  `rule_id` int(11) NOT NULL,
			  `type` varchar(50) NOT NULL DEFAULT '',
			  `statement` varchar(50) NOT NULL DEFAULT '',
			  `value` text NOT NULL,
			  PRIMARY KEY (`id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);
            
            $sql = "SELECT  CONCAT('ALTER TABLE ', table_name, ' ENGINE=InnoDB;') AS sql_statements
			FROM    information_schema.tables AS tb
			WHERE   table_schema = '" . $dbname . "'
			AND     `ENGINE` = 'MyISAM'
			AND     `TABLE_TYPE` = 'BASE TABLE'
			ORDER BY table_name DESC;";
            $result = _mysqli_query($sql);
            
            while ($row = $result->fetch_assoc()) {
                _mysqli_query($row['sql_statements']);
            }
            
            $sql = "UPDATE 202_version SET version='1.8.4'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.8.4';
        }
        
        // upgrade from 1.8.4 to 1.8.5
        if ($prosper202_version == '1.8.4') {
            $sql = "UPDATE 202_version SET version='1.8.5'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.8.5';
        }
        
        // upgrade from 1.8.5 to 1.8.6
        if ($prosper202_version == '1.8.5') {
            $sql = "UPDATE 202_version SET version='1.8.6'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.8.6';
        }
        
        // upgrade from 1.8.6 to 1.8.7
        if ($prosper202_version == '1.8.6') {
            $sql = "UPDATE 202_version SET version='1.8.7'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.8.7';
        }
        
        // upgrade from 1.8.7 to 1.8.8
        if ($prosper202_version == '1.8.7') {
            $sql = "UPDATE 202_version SET version='1.8.8'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.8.8';
        }
        
        // upgrade from 1.8.8 to 1.8.9
        if ($prosper202_version == '1.8.8') {
            $sql = "UPDATE 202_version SET version='1.8.9'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.8.9';
        }
        
        // upgrade from 1.8.9 to 1.8.10
        if ($prosper202_version == '1.8.9') {
            $sql = "UPDATE 202_version SET version='1.8.10'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.8.10';
        }
        
        //upgrade from 1.8.10 to 1.8.11
        if ($prosper202_version == '1.8.10') {
            $sql = "UPDATE 202_version SET version='1.8.11'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.8.11';
        }
        
        // upgrade from 1.8.11/12/13/14/15/16 to 1.9.0
        if ($prosper202_version == '1.8.11'||$prosper202_version == '1.8.12'||$prosper202_version == '1.8.13'||$prosper202_version == '1.8.14'||$prosper202_version == '1.8.15'||$prosper202_version == '1.8.16') {
            $sql = "CREATE TABLE IF NOT EXISTS `202_dirty_hours` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `ppc_account_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
          `aff_campaign_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
          `user_id` mediumint(8) unsigned NOT NULL,
          `click_time_from` int(10) unsigned NOT NULL,
          `click_time_to` int(10) unsigned NOT NULL,
          `deleted` bit(1) NOT NULL DEFAULT b'0',
          `processed` bit(1) NOT NULL DEFAULT b'0',
          PRIMARY KEY (`ppc_account_id`,`aff_campaign_id`,`user_id`,`click_time_from`,`click_time_to`),
          UNIQUE KEY `id` (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            
           
            $result = _mysqli_query($sql);
            
            $sql="ALTER TABLE 202_users ENGINE = InnoDB";
            $result = _mysqli_query($sql);
            
            $sql = "CREATE TABLE IF NOT EXISTS `202_dataengine` (
              `user_id` mediumint(8) unsigned NOT NULL,
              `click_id` bigint(20) unsigned NOT NULL,
              `click_time` int(10) NOT NULL DEFAULT '0',
              `ppc_network_id` mediumint(8) unsigned DEFAULT '0',
              `ppc_account_id` mediumint(8) unsigned NOT NULL,
              `aff_network_id` mediumint(8) unsigned DEFAULT '0',
              `aff_campaign_id` mediumint(8) unsigned DEFAULT '0',
              `landing_page_id` mediumint(8) unsigned NOT NULL,
              `keyword_id` bigint(20) unsigned DEFAULT '0',
              `utm_medium_id` bigint(20) unsigned DEFAULT '0',
              `utm_source_id` bigint(20) unsigned DEFAULT '0',
              `utm_campaign_id` bigint(20) unsigned DEFAULT '0',
              `utm_term_id` bigint(20) unsigned DEFAULT '0',
              `utm_content_id` bigint(20) unsigned DEFAULT '0',
              `text_ad_id` mediumint(8) unsigned DEFAULT '0',
              `click_referer_site_url_id` bigint(20) unsigned DEFAULT NULL,
              `country_id` bigint(20) unsigned DEFAULT '0',
              `region_id` bigint(20) unsigned DEFAULT '0',
              `city_id` bigint(20) unsigned DEFAULT '0',
              `isp_id` bigint(20) unsigned DEFAULT '0',
              `browser_id` bigint(20) unsigned DEFAULT '0',
              `device_id` bigint(20) unsigned DEFAULT '0',
              `platform_id` bigint(20) unsigned DEFAULT '0',
              `ip_id` bigint(20) unsigned DEFAULT NULL,
              `c1_id` bigint(20) unsigned DEFAULT '0',
              `c2_id` bigint(20) unsigned DEFAULT '0',
              `c3_id` bigint(20) unsigned DEFAULT '0',
              `c4_id` bigint(20) unsigned DEFAULT '0',
              `variable_set_id` varchar(255) CHARACTER SET utf8mb4 DEFAULT '0',
              `rotator_id` bigint(20) unsigned DEFAULT '0',
              `rule_id` bigint(20) unsigned DEFAULT '0',
              `rule_redirect_id` bigint(20) unsigned DEFAULT '0',
              `click_lead` tinyint(1) NOT NULL DEFAULT '0',
              `click_filtered` tinyint(1) NOT NULL DEFAULT '0',
              `click_bot` tinyint(1) NOT NULL DEFAULT '0',
              `click_alp` tinyint(1) NOT NULL DEFAULT '0',
              `clicks` bigint(21) NOT NULL DEFAULT '0',
              `click_out` decimal(25,0) DEFAULT NULL,
              `leads` decimal(25,0) DEFAULT NULL,
              `payout` decimal(8,2) NOT NULL,
              `income` decimal(35,5) DEFAULT NULL,
              `cost` decimal(29,5) DEFAULT NULL,
              PRIMARY KEY (`click_id`,`click_time`),
              KEY `user_id` (`user_id`,`click_time`),
              KEY `dataenginejob` (`click_time`,`ppc_network_id`,`aff_network_id`,`keyword_id`,`click_referer_site_url_id`,`country_id`,`region_id`,`city_id`,`browser_id`,`device_id`,`platform_id`,`ip_id`,`c1_id`,`c2_id`,`c3_id`,`c4_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);

            if (!$mysql_partitioning_fail) {
                $partition_time = $partition_start;
                $sql = "/*!50100 ALTER TABLE `202_dataengine` PARTITION BY RANGE (click_time) (";
                for ($i=0; $partition_time <= $partition_end; $i++) { 
                    if ($i > 0) {
                        $partition_time = strtotime('+1 week', $partition_time);
                    }
                    $sql .= "PARTITION p".$i." VALUES LESS THAN (".$partition_time.") ENGINE = InnoDB,";
                    $p_count = $i;
                }
                $p_count = $p_count + 1;
                $sql .= "PARTITION p".$p_count." VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */;";
                $result = _mysqli_query($sql);
            }
            
            $sql = "CREATE TABLE IF NOT EXISTS `202_google` (
          `click_id` bigint(20) unsigned NOT NULL,
          `gclid` varchar(150) NOT NULL,
          `utm_source_id` bigint(20) unsigned NOT NULL,
          `utm_medium_id` bigint(20) unsigned NOT NULL,
          `utm_campaign_id` bigint(20) unsigned NOT NULL,
          `utm_term_id` bigint(20) unsigned NOT NULL,
          `utm_content_id` bigint(20) unsigned NOT NULL,
          PRIMARY KEY (`click_id`,`gclid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);
            
            $sql = "CREATE TABLE IF NOT EXISTS `202_utm_campaign` (
  `utm_campaign_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `utm_campaign` varchar(350) NOT NULL DEFAULT '',
  PRIMARY KEY (`utm_campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);
            
            $sql = "CREATE TABLE IF NOT EXISTS `202_utm_content` (
  `utm_content_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `utm_content` varchar(350) NOT NULL DEFAULT '',
  PRIMARY KEY (`utm_content_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);
            
            $sql = "CREATE TABLE IF NOT EXISTS `202_utm_medium` (
  `utm_medium_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `utm_medium` varchar(350) NOT NULL DEFAULT '',
  PRIMARY KEY (`utm_medium_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);
            
            $sql = "CREATE TABLE IF NOT EXISTS `202_utm_source` (
  `utm_source_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `utm_source` varchar(350) NOT NULL DEFAULT '',
  PRIMARY KEY (`utm_source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);
            
            $sql = "CREATE TABLE IF NOT EXISTS `202_utm_term` (
  `utm_term_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `utm_term` varchar(350) NOT NULL DEFAULT '',
  PRIMARY KEY (`utm_term_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);
            
            $sql = "ALTER TABLE `202_users_pref` ADD COLUMN `user_pref_referer_data` varchar(10) NOT NULL DEFAULT 'browser';";
            $result = _mysqli_query($sql);
            
            $sql = "UPDATE 202_version SET version='1.9.0'; ";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.9.0';
        }

        // upgrade from 1.9.0 to 1.9.1
        if ($prosper202_version == '1.9.0') {

            $sql = "ALTER TABLE `202_rotator_rules` ADD COLUMN `redirect_lp` int(11) DEFAULT NULL;";
            $result = _mysqli_query($sql);

            $sql = "ALTER TABLE `202_rotator_rules` ADD COLUMN `auto_monetizer` tinyint(1) DEFAULT NULL;";
            $result = _mysqli_query($sql);

            $sql = "ALTER TABLE `202_rotators` ADD COLUMN `default_lp` int(11) DEFAULT NULL;";
            $result = _mysqli_query($sql);

            $sql = "ALTER TABLE `202_rotators` ADD COLUMN `auto_monetizer` tinyint(1) DEFAULT NULL;";
            $result = _mysqli_query($sql);

            $sql = "UPDATE 202_version SET version='1.9.1'";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.9.1';
        }

        // upgrade from 1.9.0 to 1.9.1
        if ($prosper202_version == '1.9.1') {
        
            $sql = "UPDATE 202_version SET version='1.9.2'";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.9.2';
        }

        // upgrade from 1.9.2 to 1.9.3
        if ($prosper202_version == '1.9.2') {
            
            $sql = "ALTER TABLE `202_trackers` ADD COLUMN `click_cpa` decimal(7,5) DEFAULT NULL;";
            $result = _mysqli_query($sql);

            $sql ="CREATE TABLE `202_cpa_trackers` (
              `click_id` bigint(20) unsigned NOT NULL,
              `tracker_id_public` int(11) unsigned NOT NULL,
              KEY `tracker_id` (`tracker_id_public`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);

            $sql = "CREATE TABLE  IF NOT EXISTS `202_conversion_logs` (
              `conv_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
              `click_id` bigint(20) unsigned NOT NULL,
              `campaign_id` mediumint(8) unsigned NOT NULL,
              `user_id` mediumint(8) unsigned NOT NULL,
              `click_time` int(10) NOT NULL,
              `conv_time` int(10) NOT NULL,
              `time_difference` text NOT NULL,
              `ip` varchar(15) NOT NULL DEFAULT '',
              `pixel_type` int(11) unsigned NOT NULL,
              `user_agent` text NOT NULL,
              PRIMARY KEY (`conv_id`),
              KEY `click_id` (`click_id`),
              KEY `user_id` (`user_id`),
              KEY `campaign_id` (`campaign_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);
            
            $sql = "CREATE TABLE  IF NOT EXISTS `202_dataengine_job` (
              `time_from` int(10) unsigned NOT NULL DEFAULT '0',
              `time_to` int(10) unsigned NOT NULL DEFAULT '0',
              `processing` tinyint(1) NOT NULL DEFAULT '0',
              `processed` tinyint(1) NOT NULL DEFAULT '0'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);

            $sql = "TRUNCATE TABLE 202_dataengine";
            $result = _mysqli_query($sql);
            
            $de = new DataEngine();
            $de->setRowsForOldClickUpgrade($time_from);

            $sql = "UPDATE 202_version SET version='1.9.3'";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.9.3';
        }

        // upgrade from 1.9.3 to 1.9.4
        if ($prosper202_version == '1.9.3') {

            $sql = "DROP TABLE 202_charts";
            $result = _mysqli_query($sql);

            $sql = "CREATE TABLE IF NOT EXISTS `202_charts` (
              `user_id` mediumint(8) unsigned NOT NULL,
              `data` text NOT NULL,
              `chart_time_range` varchar(255) NOT NULL DEFAULT '',
              KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);

            $sql ="INSERT INTO `202_charts` (`user_id`, `data`, `chart_time_range`)
                   VALUES
                   (1, 'a:3:{i:0;a:2:{s:11:\"campaign_id\";s:1:\"0\";s:10:\"value_type\";s:6:\"clicks\";}i:1;a:2:{s:11:\"campaign_id\";s:1:\"0\";s:10:\"value_type\";s:9:\"click_out\";}i:2;a:2:{s:11:\"campaign_id\";s:1:\"0\";s:10:\"value_type\";s:5:\"leads\";}}', 'days');";
            $result = _mysqli_query($sql);

            
            $sql = "ALTER TABLE `202_users` 
            ADD COLUMN `user_fname` varchar(50) DEFAULT NULL,
            ADD COLUMN `user_lname` varchar(50) DEFAULT NULL,
            ADD COLUMN `user_active` int(1) NOT NULL DEFAULT '1',
            ADD COLUMN `user_deleted` int(1) NOT NULL DEFAULT '0'";
            $result = _mysqli_query($sql);
            
            $sql = "UPDATE 202_version SET version='1.9.4'";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.9.4';
        }

        // upgrade from 1.9.4 to 1.9.5
        if ($prosper202_version == '1.9.4') {
            
            $sql = "ALTER TABLE 202_users ENGINE = INNODB";
            $result = _mysqli_query($sql);
            
            $sql = "ALTER TABLE 202_users_pref 
                    ADD COLUMN `user_pref_cloak_referer` varchar(11) DEFAULT 'origin',
                    ADD COLUMN `user_slack_incoming_webhook` text NOT NULL";
            $result = _mysqli_query($sql);

            $sql = "CREATE TABLE IF NOT EXISTS `202_permissions` (
              `permission_id` int(11) NOT NULL AUTO_INCREMENT,
              `permission_description` varchar(50) NOT NULL,
              PRIMARY KEY (`permission_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);

            $sql = "INSERT INTO `202_permissions` (`permission_id`, `permission_description`)
                    VALUES
                        (1, 'add_users'),
                        (2, 'add_edit_delete_admin'),
                        (3, 'remove_traffic_source'),
                        (4, 'remove_traffic_source_account'),
                        (5, 'remove_campaign_category'),
                        (6, 'remove_campaign'),
                        (7, 'remove_landing_page'),
                        (8, 'remove_text_ad'),
                        (9, 'remove_rotator'),
                        (10, 'remove_rotator_criteria'),
                        (11, 'remove_rotator_rule'),
                        (12, 'access_to_campaign_data'),
                        (13, 'delete_individual_subids'),
                        (14, 'access_to_setup_section'),
                        (15, 'access_to_update_section'),
                        (16, 'access_to_personal_settings'),
                        (17, 'access_to_vip_perks'),
                        (18, 'access_to_clickservers'),
                        (19, 'access_to_api_integrations'),
                        (20, 'access_to_settings'),
                        (21, 'remove_tracker');";
            $result = _mysqli_query($sql);            

            $sql = "CREATE TABLE IF NOT EXISTS `202_roles` (
              `role_id` int(11) NOT NULL AUTO_INCREMENT,
              `role_name` varchar(50) NOT NULL,
              PRIMARY KEY (`role_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);

            $sql = "INSERT INTO `202_roles` (`role_id`, `role_name`)
                    VALUES
                        (1, 'Super user'),
                        (2, 'Admin'),
                        (3, 'Campaign manager'),
                        (4, 'Campaign optimizer'),
                        (5, 'Campaign viewer');";
            $result = _mysqli_query($sql);
                        
            $sql = "CREATE TABLE IF NOT EXISTS `202_role_permission` (
              `role_id` int(11) NOT NULL,
              `permission_id` int(11) NOT NULL,
              KEY `role_id` (`role_id`),
              KEY `permission_id` (`permission_id`),
              CONSTRAINT `202_role_permission_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `202_roles` (`role_id`),
              CONSTRAINT `202_role_permission_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `202_permissions` (`permission_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);

            $sql = "INSERT INTO `202_role_permission` (`role_id`, `permission_id`)
                    VALUES
                        (1, 1),
                        (1, 2),
                        (1, 3),
                        (1, 4),
                        (1, 5),
                        (1, 6),
                        (1, 7),
                        (1, 8),
                        (1, 9),
                        (1, 10),
                        (1, 11),
                        (1, 12),
                        (1, 13),
                        (1, 14),
                        (1, 15),
                        (1, 16),
                        (1, 17),
                        (1, 18),
                        (1, 19),
                        (1, 20),
                        (1, 21),
                        (2, 1),
                        (2, 3),
                        (2, 4),
                        (2, 5),
                        (2, 6),
                        (2, 7),
                        (2, 8),
                        (2, 9),
                        (2, 10),
                        (2, 11),
                        (2, 12),
                        (2, 13),
                        (2, 14),
                        (2, 15),
                        (2, 16),
                        (2, 17),
                        (2, 18),
                        (2, 19),
                        (2, 20),
                        (2, 21),
                        (3, 12),
                        (3, 14),
                        (3, 15),
                        (4, 12);";
            $result = _mysqli_query($sql);
                        
            $sql = "CREATE TABLE IF NOT EXISTS `202_user_role` (
              `user_id` mediumint(8) unsigned NOT NULL,
              `role_id` int(11) NOT NULL,
              KEY `user_id` (`user_id`),
              KEY `role_id` (`role_id`),
              CONSTRAINT `202_user_role_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `202_users` (`user_id`),
              CONSTRAINT `202_user_role_ibfk_2` FOREIGN KEY (`role_id`) REFERENCES `202_roles` (`role_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);

            $sql = "INSERT INTO `202_user_role` (`user_id`, `role_id`) VALUES (1, 1);";
            $result = _mysqli_query($sql);

            $sql = "ALTER TABLE 202_rotators MODIFY `auto_monetizer` char(4) DEFAULT NULL";
            $result = _mysqli_query($sql);

            $sql = "ALTER TABLE 202_rotator_rules MODIFY `auto_monetizer` char(4) DEFAULT NULL";
            $result = _mysqli_query($sql);

            $sql = "ALTER TABLE 202_trackers MODIFY `click_cpc` decimal(7,5) DEFAULT NULL";
            $result = _mysqli_query($sql);
           
            
            $sql = "CREATE TABLE IF NOT EXISTS `202_custom_variables` (
              `custom_variable_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
              `ppc_variable_id` bigint(20) unsigned NOT NULL,
              `variable` varchar(350) NOT NULL DEFAULT '',
              PRIMARY KEY (`custom_variable_id`),
              KEY `variable` (`variable`(191)),
              KEY `ppc_variable_id` (`ppc_variable_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);

            $sql = "CREATE TABLE IF NOT EXISTS `202_clicks_variable` (
              `click_id` bigint(20) unsigned NOT NULL,
              `variable_set_id` bigint(20) unsigned NOT NULL,
              KEY `custom_variable_id` (`variable_set_id`),
              KEY `click_id` (`click_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);

            $sql = "CREATE TABLE IF NOT EXISTS `202_ppc_network_variables` (
              `ppc_variable_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
              `ppc_network_id` mediumint(8) NOT NULL,
              `name` varchar(255) NOT NULL DEFAULT '',
              `parameter` varchar(255) NOT NULL DEFAULT '',
              `placeholder` varchar(255) NOT NULL DEFAULT '',
              `deleted` tinyint(1) NOT NULL DEFAULT '0',
              PRIMARY KEY (`ppc_variable_id`),
              KEY `ppc_network_id` (`ppc_network_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);

            $sql = "CREATE TABLE IF NOT EXISTS `202_variable_sets` (
              `variable_set_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
              `variables` varchar(255) NOT NULL DEFAULT '',
              KEY `custom_variable_id` (`variables`),
              KEY `click_id` (`variable_set_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);

            $sql = "ALTER TABLE 202_dirty_hours 
                    ADD COLUMN `ppc_network_id` mediumint(8) unsigned DEFAULT '0',
                    ADD COLUMN `aff_network_id` mediumint(8) unsigned DEFAULT '0',
                    ADD COLUMN `landing_page_id` mediumint(8) unsigned NOT NULL,
                    ADD COLUMN `keyword_id` bigint(20) unsigned DEFAULT '0',
                    ADD COLUMN `utm_medium_id` bigint(20) unsigned DEFAULT '0',
                    ADD COLUMN `utm_source_id` bigint(20) unsigned DEFAULT '0',
                    ADD COLUMN `utm_campaign_id` bigint(20) unsigned DEFAULT '0',
                    ADD COLUMN `utm_term_id` bigint(20) unsigned DEFAULT '0',
                    ADD COLUMN `utm_content_id` bigint(20) unsigned DEFAULT '0',
                    ADD COLUMN `text_ad_id` mediumint(8) unsigned DEFAULT '0',
                    ADD COLUMN `click_referer_site_url_id` bigint(20) unsigned DEFAULT '0',
                    ADD COLUMN `country_id` bigint(20) unsigned DEFAULT '0',
                    ADD COLUMN `region_id` bigint(20) unsigned DEFAULT '0',
                    ADD COLUMN `city_id` bigint(20) unsigned DEFAULT '0',
                    ADD COLUMN `isp_id` bigint(20) unsigned DEFAULT '0',
                    ADD COLUMN `browser_id` bigint(20) unsigned DEFAULT '0',
                    ADD COLUMN `device_id` bigint(20) unsigned DEFAULT '0',
                    ADD COLUMN `platform_id` bigint(20) unsigned DEFAULT '0',
                    ADD COLUMN `ip_id` bigint(20) unsigned DEFAULT '0',
                    ADD COLUMN `c1_id` bigint(20) unsigned DEFAULT '0',
                    ADD COLUMN `c2_id` bigint(20) unsigned DEFAULT '0',
                    ADD COLUMN `c3_id` bigint(20) unsigned DEFAULT '0',
                    ADD COLUMN `c4_id` bigint(20) unsigned DEFAULT '0',
                    ADD COLUMN `variable_set_id` varchar(255) DEFAULT '0',
                    ADD COLUMN `click_filtered` tinyint(1) NOT NULL DEFAULT '0',
                    ADD COLUMN `click_bot` tinyint(1) NOT NULL DEFAULT '0',
                    ADD COLUMN `click_alp` tinyint(1) NOT NULL DEFAULT '0'";
            $result = _mysqli_query($sql);

            $sql = "CREATE TABLE `202_cronjob_logs` (
                      `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                      `last_execution_time` int(10) unsigned NOT NULL,
                      PRIMARY KEY (`id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);
            
           // $sql = "ALTER TABLE 202_dataengine ADD COLUMN `variable_set_id` varchar(255) DEFAULT '0'";
            //$result = _mysqli_query($sql);
            
            $sql = "UPDATE 202_version SET version='1.9.5'";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.9.5';
        }

        if ($prosper202_version == '1.9.5') {

            $sql = "CREATE TABLE `202_clicks_rotator` (
                  `click_id` bigint(20) unsigned NOT NULL,
                  `rotator_id` bigint(20) unsigned NOT NULL,
                  `rule_id` bigint(20) unsigned NOT NULL,
                  `rule_redirect_id` bigint(20) unsigned NOT NULL,
                  PRIMARY KEY (`click_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);

            $sql = "CREATE TABLE `202_dataengine_new` (
                `user_id` mediumint(8) unsigned NOT NULL,
                `click_id` bigint(20) unsigned NOT NULL,
                `click_time` int(10) NOT NULL DEFAULT '0',
                `ppc_network_id` mediumint(8) unsigned DEFAULT '0',
                `ppc_account_id` mediumint(8) unsigned NOT NULL,
                `aff_network_id` mediumint(8) unsigned DEFAULT '0',
                `aff_campaign_id` mediumint(8) unsigned DEFAULT '0',
                `landing_page_id` mediumint(8) unsigned NOT NULL,
                `keyword_id` bigint(20) unsigned DEFAULT '0',
                `utm_medium_id` bigint(20) unsigned DEFAULT '0',
                `utm_source_id` bigint(20) unsigned DEFAULT '0',
                `utm_campaign_id` bigint(20) unsigned DEFAULT '0',
                `utm_term_id` bigint(20) unsigned DEFAULT '0',
                `utm_content_id` bigint(20) unsigned DEFAULT '0',
                `text_ad_id` mediumint(8) unsigned DEFAULT '0',
                `click_referer_site_url_id` bigint(20) unsigned DEFAULT NULL,
                `country_id` bigint(20) unsigned DEFAULT '0',
                `region_id` bigint(20) unsigned DEFAULT '0',
                `city_id` bigint(20) unsigned DEFAULT '0',
                `isp_id` bigint(20) unsigned DEFAULT '0',
                `browser_id` bigint(20) unsigned DEFAULT '0',
                `device_id` bigint(20) unsigned DEFAULT '0',
                `platform_id` bigint(20) unsigned DEFAULT '0',
                `ip_id` bigint(20) unsigned DEFAULT NULL,
                `c1_id` bigint(20) unsigned DEFAULT '0',
                `c2_id` bigint(20) unsigned DEFAULT '0',
                `c3_id` bigint(20) unsigned DEFAULT '0',
                `c4_id` bigint(20) unsigned DEFAULT '0',
                `variable_set_id` varchar(255) CHARACTER SET utf8mb4 DEFAULT '0',
                `rotator_id` bigint(20) unsigned DEFAULT '0',
                `rule_id` bigint(20) unsigned DEFAULT '0',
                `rule_redirect_id` bigint(20) unsigned DEFAULT '0',
                `click_lead` tinyint(1) NOT NULL DEFAULT '0',
                `click_filtered` tinyint(1) NOT NULL DEFAULT '0',
                `click_bot` tinyint(1) NOT NULL DEFAULT '0',
                `click_alp` tinyint(1) NOT NULL DEFAULT '0',
                `clicks` bigint(21) NOT NULL DEFAULT '0',
                `click_out` decimal(25,0) DEFAULT NULL,
                `leads` decimal(25,0) DEFAULT NULL,
                `payout` decimal(8,2) NOT NULL,
                `income` decimal(35,5) DEFAULT NULL,
                `cost` decimal(29,5) DEFAULT NULL,
                PRIMARY KEY (`click_id`,`click_time`),
                KEY `user_id` (`user_id`,`click_time`),
                KEY `dataenginejob` (`click_time`,`ppc_network_id`,`aff_network_id`,`keyword_id`,`click_referer_site_url_id`,`country_id`,`region_id`,`city_id`,`browser_id`,`device_id`,`platform_id`,`ip_id`,`c1_id`,`c2_id`,`c3_id`,`c4_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);

            if (!$mysql_partitioning_fail) {
                $partition_time = $partition_start;
                $sql = "/*!50100 ALTER TABLE `202_dataengine_new` PARTITION BY RANGE (click_time) (";
                for ($i=0; $partition_time <= $partition_end; $i++) { 
                    if ($i > 0) {
                        $partition_time = strtotime('+1 week', $partition_time);
                    }
                    $sql .= "PARTITION p".$i." VALUES LESS THAN (".$partition_time.") ENGINE = InnoDB,";
                    $p_count = $i;
                }
                $p_count = $p_count + 1;
                $sql .= "PARTITION p".$p_count." VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */;";
                $result = _mysqli_query($sql);
            }
            
            $time_to = time();
            $time_from = $time_to - 604800;
            $snippet = "AND 2c.user_id = 1";

            $sql = "RENAME TABLE 202_dataengine TO 202_dataengine_old";
            $result = _mysqli_query($sql);

            $sql = "RENAME TABLE 202_dataengine_new TO 202_dataengine";
            $result = _mysqli_query($sql);

            $de = new DataEngine();
            $de->getSummary($time_from, $time_to, $snippet, 1, true, false);
            
            $sql = "CREATE TABLE `202_rotator_rules_redirects` (
                  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                  `rule_id` int(11) NOT NULL,
                  `redirect_url` text,
                  `redirect_campaign` int(11) DEFAULT NULL,
                  `redirect_lp` int(11) DEFAULT NULL,
                  `auto_monetizer` char(4) DEFAULT NULL,
                  `weight` char(3) DEFAULT '0',
                  `name` text NOT NULL,
                  PRIMARY KEY (`id`)
                ) ENGINE=InnoDB";
            $result = _mysqli_query($sql);

            $sql = "SELECT * FROM 202_rotator_rules";
            $result = _mysqli_query($sql);
            if ($result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    if ($row['redirect_url'] != null) {
                        $redirect_name = "URL: <a href=".$row['redirect_url'].">link</a>";
                    } else if ($row['redirect_campaign'] != null) {
                        $redirect_type_sql = "SELECT aff_campaign_name FROM 202_aff_campaigns WHERE aff_campaign_id = '".$row['redirect_campaign']."'";
                        $redirect_type_result = _mysqli_query($redirect_type_sql);
                        $redirect_type_row = $redirect_type_result->fetch_assoc();
                        $redirect_name = "Campaign: ".$redirect_type_row['aff_campaign_name'];
                    } else if ($row['redirect_lp'] != null) {
                        $redirect_type_sql = "SELECT landing_page_nickname FROM 202_landing_pages WHERE landing_page_id = '".$row['redirect_lp']."'";
                        $redirect_type_result = _mysqli_query($redirect_type_sql);
                        $redirect_type_row = $redirect_type_result->fetch_assoc();
                        $redirect_name = "Landing page: ".$redirect_type_row['landing_page_nickname'];
                    } else if ($row['auto_monetizer'] != null) {
                        $redirect_name = "Auto Monetizer";
                    }

                    $insert_redirect_sql = "INSERT INTO 202_rotator_rules_redirects
                                            SET 
                                            rule_id = '".$row['id']."',";

                    if ($row['redirect_url'] != null) {
                        $insert_redirect_sql .= "redirect_url = '".$row['redirect_url']."',";
                    }

                    if ($row['redirect_campaign'] != null) {
                        $insert_redirect_sql .= "redirect_campaign = '".$row['redirect_campaign']."',";
                    }

                    if ($row['redirect_lp'] != null) {
                        $insert_redirect_sql .= "redirect_lp = '".$row['redirect_lp']."',";
                    }

                    if ($row['auto_monetizer'] != null) {
                        $insert_redirect_sql .= "auto_monetizer = '".$row['auto_monetizer']."',";
                    }

                    $insert_redirect_sql .= "name = '".$redirect_name."'";
                    
                    $insert_redirect_result = _mysqli_query($insert_redirect_sql);        
                }
            }

            $sql = "ALTER TABLE 202_rotator_rules DROP redirect_url, DROP redirect_campaign, DROP redirect_lp, DROP auto_monetizer, ADD COLUMN `splittest` tinyint(1) NOT NULL DEFAULT '0'";
            $result = _mysqli_query($sql);

            $sql = "ALTER TABLE 202_users_pref ADD COLUMN `auto_cron` tinyint(1) NOT NULL DEFAULT '0'";
            $result = _mysqli_query($sql);

            $autocron = false;

            $sql = "SELECT * FROM 202_cronjob_logs";
            $result = _mysqli_query($sql);
            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                
                $last_five_minutes = time() - 300;
                
                if ($row['last_execution_time'] < $last_five_minutes) {
                    $autocron = true;
                }

            } else {
                $autocron = true;
            }

            if ($autocron) {
                $cron = callAutoCron('register');

                if ($cron['status'] == 'success') {
                    $sql = "UPDATE 202_users_pref SET auto_cron = '1' WHERE user_id = '1'";
                    $result = _mysqli_query($sql);
                }
            }
            

            $sql = "UPDATE 202_version SET version='1.9.6'";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.9.6';

        }

        if ($prosper202_version == '1.9.6') {

            $sql = "ALTER TABLE 202_users_pref ADD COLUMN `user_daily_email` char(2) NOT NULL DEFAULT '07'";
            $result = _mysqli_query($sql);
            
            $sql = "SELECT user_timezone, install_hash, user_daily_email FROM 202_users LEFT JOIN 202_users_pref USING (user_id) WHERE user_id = 1";
            $result = _mysqli_query($sql);
            $row = $result->fetch_assoc();

            registerDailyEmail($row['user_daily_email'], $row['user_timezone'], $row['install_hash']);

            $sql = "ALTER TABLE 202_keywords CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
            $result = _mysqli_query($sql);

            $sql = "UPDATE 202_version SET version='1.9.7'";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.9.7';
        }
        
        if ($prosper202_version == '1.9.7') {
        
            $sql = "UPDATE 202_version SET version='1.9.8'";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.9.8';
        }

        if ($prosper202_version == '1.9.8') {
        
            $sql = "UPDATE 202_version SET version='1.9.9'";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.9.9';
        }

        if ($prosper202_version == '1.9.9') {
        
            $sql = "UPDATE 202_version SET version='1.9.10'";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.9.10';
        }

        if ($prosper202_version == '1.9.10') {
        
            $sql = "UPDATE 202_version SET version='1.9.11'";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.9.11';
        }

        if ($prosper202_version == '1.9.11') {
            $sql = "ALTER TABLE 202_rotators ADD COLUMN `public_id` int(11) NOT NULL";
            $result = _mysqli_query($sql);

            $sql = "SELECT id FROM 202_rotators";
            $result = _mysqli_query($sql);
            if ($result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    _mysqli_query("UPDATE 202_rotators SET public_id = '".rand(1,9).$row['id'].rand(1,9)."' WHERE id = '".$row['id']."'");
                }
            }

            $sql = "UPDATE 202_version SET version='1.9.12'";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.9.12';
        }
        
        if ($prosper202_version == '1.9.12') {
        
            $sql = "UPDATE 202_version SET version='1.9.13'";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.9.13';
        }

        if ($prosper202_version == '1.9.13') {
        
            $sql = "UPDATE 202_version SET version='1.9.14'";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.9.14';
        }

        if ($prosper202_version == '1.9.14') {
        
            $sql = "UPDATE 202_version SET version='1.9.15'";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.9.15';
        }        

        if ($prosper202_version == '1.9.15') {
        
            $sql = "UPDATE 202_version SET version='1.9.16'";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.9.16';
        }
        
        if ($prosper202_version == '1.9.16') {
        
            $sql = "UPDATE 202_version SET version='1.9.17'";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.9.17';
        }

        if ($prosper202_version == '1.9.17') {
            $sql = "CREATE TABLE IF NOT EXISTS `202_dni_networks` (
              `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
              `user_id` mediumint(8) unsigned NOT NULL,
              `networkId` varchar(255) NOT NULL DEFAULT '',
              `apiKey` varchar(255) NOT NULL,
              `affiliateId` int(11) unsigned DEFAULT NULL,
              `name` varchar(255) NOT NULL DEFAULT '',
              `type` varchar(255) NOT NULL DEFAULT '',
              `time` int(10) unsigned NOT NULL,
              `processed` smallint(1) NOT NULL DEFAULT '0',
              PRIMARY KEY (`id`),
              KEY `networkId` (`networkId`(191))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);

            $sql = "ALTER TABLE 202_aff_networks ADD COLUMN `dni_network_id` mediumint(8) DEFAULT NULL, ADD INDEX `dni_network_id` (`dni_network_id`)";
            $result = _mysqli_query($sql);


            $sql = "UPDATE 202_version SET version='1.9.18'";
            $result = _mysqli_query($sql);
            $prosper202_version = '1.9.18';
        }

	    if ($prosper202_version == '1.9.18') {

		    $sql = "
				CREATE TABLE `202_auth_keys` (
				  `user_id` mediumint(8) NOT NULL,
				  `auth_key` varchar(64) NOT NULL,
				  `expires` int(11) NOT NULL,
				  KEY `202_auth_keys_user_id_auth_key` (`user_id`,`auth_key`),
				  KEY `202_auth_keys_expires` (`expires`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
		    $result = _mysqli_query($sql);

		    $sql = "ALTER TABLE `202_users` ADD COLUMN `secret_key` CHAR(48) NULL  AFTER `user_deleted`";
		    $result = _mysqli_query($sql);

		    $sql = "UPDATE 202_version SET version='1.9.19'";
		    $result = _mysqli_query($sql);

		    $prosper202_version = '1.9.19';
	    }

        if ($prosper202_version == '1.9.19') {

            $sql = "ALTER TABLE `202_dni_networks` ADD COLUMN `shortDescription` varchar(255) NOT NULL, ADD COLUMN `favIcon` varchar(255) NOT NULL";
            $result = _mysqli_query($sql);

            $sql = "UPDATE 202_version SET version='1.9.20'";
            $result = _mysqli_query($sql);

            $prosper202_version = '1.9.20';
        }

        if ($prosper202_version == '1.9.20') {

            $sql = "UPDATE 202_version SET version='1.9.21'";
            $result = _mysqli_query($sql);

            $prosper202_version = '1.9.21';
        }

        if ($prosper202_version == '1.9.21') {
            
            $sql = "DROP INDEX ppc_network_id ON 202_ppc_network_variables";
            $result = _mysqli_query($sql);
            
            $sql = "CREATE INDEX ppc_network_id ON 202_ppc_network_variables (ppc_network_id,deleted)";
            $result = _mysqli_query($sql);
            
            //make gclid longer for everyone currently on pro
            $sql="ALTER TABLE 202_google MODIFY gclid VARCHAR(150)";
            $result = _mysqli_query($sql);
            
            $sql="ALTER TABLE `202_users_pref` ADD COLUMN `user_pref_dynamic_bid` tinyint(1) NOT NULL DEFAULT '0'";
            $result = _mysqli_query($sql);
            
            $sql = "UPDATE 202_version SET version='1.9.22'";
            $result = _mysqli_query($sql);
        
            $prosper202_version = '1.9.22';
        }
        
        if ($prosper202_version == '1.9.22') {
        
            $sql = "UPDATE 202_version SET version='1.9.23'";
            $result = _mysqli_query($sql);
        
            $prosper202_version = '1.9.23';
        }

        if ($prosper202_version == '1.9.23') {
        
            $sql = "UPDATE 202_version SET version='1.9.24'";
            $result = _mysqli_query($sql);
        
            $prosper202_version = '1.9.24';
        }
        
        if ($prosper202_version == '1.9.24') {
        
            $sql = "UPDATE 202_version SET version='1.9.25'";
            $result = _mysqli_query($sql);
        
            $prosper202_version = '1.9.25';
        } 

        if ($prosper202_version == '1.9.25') {
        
            $sql = "UPDATE 202_version SET version='1.9.26'";
            $result = _mysqli_query($sql);
        
            $prosper202_version = '1.9.26';
        }

        if ($prosper202_version == '1.9.26') {
           
            $sql = "UPDATE 202_version SET version='1.9.27'";
            $result = _mysqli_query($sql);
        
            $prosper202_version = '1.9.27';
        }
        
        if ($prosper202_version == '1.9.27') {

            $sql="ALTER TABLE `202_users` DROP `leave_behind_page_url`";
            $result = _mysqli_query($sql);
            
            $sql="ALTER TABLE `202_users` DROP `user_mods`";
            $result = _mysqli_query($sql);

            $sql="ALTER TABLE `202_users` ADD COLUMN  `user_mods_lb` tinyint(1) unsigned NOT NULL DEFAULT '0'";
            $result = _mysqli_query($sql);
            
            $sql = "UPDATE 202_version SET version='1.9.28'";
            $result = _mysqli_query($sql);
        
            $prosper202_version = '1.9.28';
        }
        
        if ($prosper202_version == '1.9.28') {
        
            $sql="ALTER TABLE `202_users_pref` ADD COLUMN `user_pref_subid` bigint(20) unsigned DEFAULT NULL";
            $result = _mysqli_query($sql);
        
            $sql = "UPDATE 202_version SET version='1.9.29'";
            $result = _mysqli_query($sql);
            
            $prosper202_version = '1.9.29';
        }

        if ($prosper202_version == '1.9.29' || $prosper202_version == '1.9.30' || $prosper202_version == '1.9.30a' || $prosper202_version == '1.9.30b') {
        
            $sql="ALTER TABLE `202_conversion_logs` ADD COLUMN `transaction_id` varchar(255) DEFAULT NULL, ADD COLUMN `click_payout` decimal(11,5) NOT NULL, ADD COLUMN `deleted` tinyint(4) NOT NULL DEFAULT '0'";
            $result = _mysqli_query($sql);
            
            $sql="ALTER TABLE `202_users_pref` ADD COLUMN `user_auto_database_optimization_days` int(11) unsigned DEFAULT '0', ADD COLUMN `zaxaa_api_signature` varchar(250) DEFAULT NULL, ADD COLUMN `jvzoo_ipn_secret_key` varchar(250) DEFAULT NULL";
            $result = _mysqli_query($sql);
            
            $sql="ALTER TABLE `202_users_pref` ADD COLUMN `user_account_currency` char(3) NOT NULL DEFAULT 'USD'";
            $result = _mysqli_query($sql);           
                       
            $sql = "CREATE TABLE `202_variable_sets2` (`variable_set_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,`variables` varchar(255) NOT NULL DEFAULT '',PRIMARY KEY (`variable_set_id`,`variables`(191)),KEY `custom_variable_id` (`variables`(191)),KEY `click_id` (`variable_set_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
            $result = _mysqli_query($sql);
            
            $sql="ALTER TABLE `202_aff_campaigns` ADD COLUMN `aff_campaign_currency` char(3) NOT NULL DEFAULT 'USD', ADD COLUMN `aff_campaign_foreign_payout` decimal(8,2) NOT NULL";
            $result = _mysqli_query($sql);

            $sql="ALTER TABLE `202_users` ADD COLUMN `p202_customer_api_key` char(60) DEFAULT NULL";
            $result = _mysqli_query($sql);

            $sql = "UPDATE 202_version SET version='1.9.30b'";
            $result = _mysqli_query($sql);
            
            $sql= "CREATE TABLE `202_filters` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `filter_name` enum('Clicks','Click Throughs','LP CTR','Leads','S/U','Payout','EPC','CPC','eCPA','Income','Cost','Net','ROI') DEFAULT NULL,
            `filter_condition` enum('>','<','=','>=','<=','!=') DEFAULT NULL,
            `filter_value` decimal(20,5) DEFAULT NULL,
            PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);
            
            $prosper202_version = '1.9.30b';
            
            //Move data from variable_sets into variable_sets2
            $sql="SELECT * from 202_variable_sets";
            
            $result = _mysqli_query($sql);
            
            $i=0;
            $row ='';
            
            while ($sql_row = $result->fetch_assoc()) {
                $vars=( explode(',',$sql_row['variables']));
                 
                foreach ($vars as $var){
                    $row.= "(". $sql_row['variable_set_id'].",".$var."),";
                }
                 
                 
                if(($i % 600)==0){
                    $row="insert ignore into `202_variable_sets2` (`variable_set_id`, `variables`) values ".rtrim($row,',').";";
                    _mysqli_query($row);

                    $i=0;
                    $row='';
                }
                $i++;
                 
            
            }
            //add the last bit of data if there's any left
            if($row)
            {                
                $row="insert ignore into `202_variable_sets2` (`variable_set_id`, `variables`) values ".rtrim($row,',').";";
                _mysqli_query($row);
            }
            
        }

        if ($prosper202_version == '1.9.30' || $prosper202_version == '1.9.30a' || $prosper202_version == '1.9.30b') {

            $sql = "UPDATE 202_version SET version='1.9.31'";
            $result = _mysqli_query($sql);
            
            $prosper202_version = '1.9.31';
        }
           
        if ($prosper202_version == '1.9.31') {
            $sql = "CREATE TABLE `202_ad_network_feeds` (
              `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
              `user_id` mediumint(8) NOT NULL,
              `creative_group` varchar(255) NOT NULL DEFAULT '',
              `story_url` text NOT NULL,
              `feed_name` char(12) NOT NULL DEFAULT '',
              PRIMARY KEY (`id`),
              KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);

            $sql = "CREATE TABLE `202_ad_network_ads` (
              `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
              `feed_id` int(11) NOT NULL,
              `ad` text NOT NULL,
              PRIMARY KEY (`id`),
              KEY `feed_id` (`feed_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);

            $sql = "CREATE TABLE `202_ad_network_titles` (
              `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
              `feed_id` int(11) NOT NULL,
              `title` char(100) NOT NULL DEFAULT '',
              PRIMARY KEY (`id`),
              KEY `feed_id` (`feed_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);

            $sql = "UPDATE 202_version SET version='1.9.32'";
            $result = _mysqli_query($sql);
            
            $prosper202_version = '1.9.32';
        }

        if ($prosper202_version == '1.9.32') {

            $sql = "UPDATE 202_version SET version='1.9.33'";
            $result = _mysqli_query($sql);
            
            $prosper202_version = '1.9.33';
        }

        if ($prosper202_version == '1.9.33') {
            $sql = "CREATE TABLE `202_ad_feed_contentad_tokens` (
              `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
              `feed_id` int(11) NOT NULL,
              `utm_campaign` varchar(350) DEFAULT NULL,
              `utm_source` varchar(350) DEFAULT NULL,
              `utm_medium` varchar(350) DEFAULT NULL,
              `utm_content` varchar(350) DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `feed_id` (`feed_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);

            $sql = "CREATE TABLE `202_ad_feed_outbrain_tokens` (
              `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
              `feed_id` int(11) NOT NULL,
              `utm_campaign` varchar(350) DEFAULT NULL,
              `utm_source` varchar(350) DEFAULT NULL,
              `utm_medium` varchar(350) DEFAULT NULL,
              `utm_content` varchar(350) DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `feed_id` (`feed_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);

            $sql = "CREATE TABLE `202_ad_feed_taboola_tokens` (
              `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
              `feed_id` int(11) NOT NULL,
              `utm_campaign` varchar(350) DEFAULT NULL,
              `utm_source` varchar(350) DEFAULT NULL,
              `utm_medium` varchar(350) DEFAULT NULL,
              `utm_content` varchar(350) DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `feed_id` (`feed_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);

            $sql = "CREATE TABLE `202_ad_feed_custom_tokens` (
              `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
              `feed_id` int(11) NOT NULL,
              `network` varchar(255) NOT NULL,
              `custom_token` varchar(350) NOT NULL DEFAULT '',
              `value` varchar(350) NOT NULL DEFAULT '',
              PRIMARY KEY (`id`),
              KEY `feed_id` (`feed_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);
            
            $sql="ALTER TABLE `202_ad_feed_contentad_tokens` ADD COLUMN `utm_term` varchar(350) DEFAULT NULL";
            $result = _mysqli_query($sql);

            $sql="ALTER TABLE `202_ad_feed_taboola_tokens` ADD COLUMN `utm_term` varchar(350) DEFAULT NULL";
            $result = _mysqli_query($sql);

            $sql="ALTER TABLE `202_ad_feed_outbrain_tokens` ADD COLUMN `utm_term` varchar(350) DEFAULT NULL";
            $result = _mysqli_query($sql);

            $sql = "UPDATE 202_version SET version='1.9.34'";
            $result = _mysqli_query($sql);
            
            $prosper202_version = '1.9.34';
        }

        if ($prosper202_version == '1.9.34') {

            $sql="ALTER TABLE `202_users_pref` ADD COLUMN `revcontent_user_id` varchar(250) DEFAULT NULL, ADD COLUMN `revcontent_user_secret` varchar(250) DEFAULT NULL";
            $result = _mysqli_query($sql);

            $sql = "CREATE TABLE `202_ad_feed_revcontent_tokens` (
              `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
              `feed_id` int(11) NOT NULL,
              `utm_campaign` varchar(350) DEFAULT NULL,
              `utm_source` varchar(350) DEFAULT NULL,
              `utm_medium` varchar(350) DEFAULT NULL,
              `utm_content` varchar(350) DEFAULT NULL,
              `utm_term` varchar(350) DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `feed_id` (`feed_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);

            $sql="ALTER TABLE `202_ad_network_feeds` ADD COLUMN `revcontent_boost_id` text";
            $result = _mysqli_query($sql);

            $sql = "UPDATE 202_version SET version='1.9.35'";
            $result = _mysqli_query($sql);
            
            $prosper202_version = '1.9.35';
        }

        if ($prosper202_version == '1.9.35') {
            $sql="ALTER TABLE `202_users_pref` ADD COLUMN `facebook_ads_linked` int(1) NOT NULL DEFAULT '0'";
            $result = _mysqli_query($sql);

            $sql="ALTER TABLE `202_ad_network_feeds` ADD COLUMN `facebook_ad_set_id` int(11) DEFAULT NULL";
            $result = _mysqli_query($sql);

            $sql="CREATE TABLE `202_ad_feed_facebook_tokens` (
              `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
              `feed_id` int(11) NOT NULL,
              `utm_campaign` varchar(350) DEFAULT NULL,
              `utm_source` varchar(350) DEFAULT NULL,
              `utm_medium` varchar(350) DEFAULT NULL,
              `utm_content` varchar(350) DEFAULT NULL,
              `utm_term` varchar(350) DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `feed_id` (`feed_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);

            $sql="CREATE TABLE `202_ad_network_bodies` (
              `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
              `feed_id` int(11) NOT NULL,
              `body_text` char(90) NOT NULL DEFAULT '',
              PRIMARY KEY (`id`),
              KEY `feed_id` (`feed_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            $result = _mysqli_query($sql);

            $sql = "UPDATE 202_version SET version='1.9.36'";
            $result = _mysqli_query($sql);
            
            $prosper202_version = '1.9.36';
        }
        
        if ($prosper202_version == '1.9.36') {
        
            $sql = "UPDATE 202_version SET version='1.9.37'";
            $result = _mysqli_query($sql);
        
            $prosper202_version = '1.9.37';
        }

        if ($prosper202_version == '1.9.37') {
        
            $sql = "UPDATE 202_version SET version='1.9.38'";
            $result = _mysqli_query($sql);
        
            $prosper202_version = '1.9.38';
        }

        if ($prosper202_version == '1.9.38') {
            
            $sql="ALTER TABLE `202_users_pref` ADD COLUMN `user_pref_ad_settings` varchar(11) NOT NULL DEFAULT 'show_all'";
            $result = _mysqli_query($sql);

            $sql = "UPDATE 202_users_pref SET maxmind_isp='0'";
            $result = _mysqli_query($sql);
            
            
            $sql = "UPDATE 202_version SET version='1.9.39'";
            $result = _mysqli_query($sql);
        
            $prosper202_version = '1.9.39';
        }  

        if ($prosper202_version == '1.9.39') {
        
            $sql="ALTER TABLE 202_landing_pages ADD COLUMN leave_behind_page_url varchar(255) NOT NULL DEFAULT ''";
            $result = _mysqli_query($sql);
        
            $sql = "UPDATE 202_version SET version='1.9.40'";
            $result = _mysqli_query($sql);
        
            $prosper202_version = '1.9.40';
        }      

        if ($prosper202_version == '1.9.40') {
        
            $sql="ALTER TABLE 202_ppc_accounts ADD COLUMN `ppc_account_default` tinyint(1) unsigned NOT NULL DEFAULT '0'";
            $result = _mysqli_query($sql);
        
            $sql="ALTER TABLE 202_ppc_accounts ADD INDEX (`ppc_account_default`)";
            $result = _mysqli_query($sql);
            
            $sql = "UPDATE 202_version SET version='1.9.41'";
            $result = _mysqli_query($sql);
        
            $prosper202_version = '1.9.41';
        }
        
        if ($prosper202_version == '1.9.41') {
        
            $sql="INSERT INTO 202_users_pref(user_id) SELECT user_id from `202_users` where `user_id` not in (select user_id from 202_users_pref)";
            $result = _mysqli_query($sql);
        
            $sql = "UPDATE 202_version SET version='1.9.42'";
            $result = _mysqli_query($sql);
        
            $prosper202_version = '1.9.42';
        }

        if ($prosper202_version == '1.9.42') {
        
        
            $sql = "UPDATE 202_version SET version='1.9.43'";
            $result = _mysqli_query($sql);
        
            $prosper202_version = '1.9.43';
        }        

        if ($prosper202_version == '1.9.43') {
        
        
            $sql = "UPDATE 202_version SET version='1.9.44'";
            $result = _mysqli_query($sql);
        
            $prosper202_version = '1.9.44';
        }

        if ($prosper202_version == '1.9.44') {
        
        
            $sql = "UPDATE 202_version SET version='1.9.45'";
            $result = _mysqli_query($sql);
        
            $prosper202_version = '1.9.45';
        }
        
        if ($prosper202_version == '1.9.45') {
            
            //This is a fix for 1.9.44 not setting this up for new installs
            $sql="ALTER TABLE 202_ppc_accounts ADD COLUMN `ppc_account_default` tinyint(1) unsigned NOT NULL DEFAULT '0'";
            $result = _mysqli_query($sql);
            
            $sql="ALTER TABLE 202_ppc_accounts ADD INDEX (`ppc_account_default`)";
            $result = _mysqli_query($sql);
        
            $sql = "UPDATE 202_version SET version='1.9.46'";
            $result = _mysqli_query($sql);
        
            $prosper202_version = '1.9.46';
        }

        if ($prosper202_version == '1.9.46') {
        
        
            $sql = "UPDATE 202_version SET version='1.9.47'";
            $result = _mysqli_query($sql);
        
            $prosper202_version = '1.9.47';
        }

        if ($prosper202_version == '1.9.47') {
            
            $sql="ALTER TABLE 202_users ADD COLUMN `user_public_publisher_id` varchar(10) DEFAULT NULL";
            $result = _mysqli_query($sql);

            $sql="ALTER TABLE 202_users ADD INDEX (`user_public_publisher_id`)";
            $result = _mysqli_query($sql);

            $sql="INSERT INTO `202_roles` (`role_id`, `role_name`) VALUES (6, 'Publisher')";
            $result = _mysqli_query($sql);
                        
            // Add publisher Ids to all existing users
            createPublisherIds();
            
            $sql = "UPDATE 202_version SET version='1.9.48'";
            $result = _mysqli_query($sql);
        
            $prosper202_version = '1.9.48';
        }
        
        if ($prosper202_version == '1.9.48') {
        
            $sql = "UPDATE 202_version SET version='1.9.49'";
            $result = _mysqli_query($sql);
        
            $prosper202_version = '1.9.49';
        }

        if ($prosper202_version == '1.9.49') {

            $sql="ALTER TABLE 202_users ADD COLUMN `user_dash_email` varchar(100) NOT NULL";
            $result = _mysqli_query($sql);
        
            $sql = "UPDATE 202_version SET version='1.9.50'";
            $result = _mysqli_query($sql);
        
            $prosper202_version = '1.9.50';
        }
        
        if ($prosper202_version == '1.9.50') {
        
             $sql="ALTER TABLE 202_cpa_trackers ADD PRIMARY KEY (`click_id`)";
            $result = _mysqli_query($sql);
            
            $sql="ALTER TABLE 202_users_pref ADD COLUMN `user_delete_data_clickid` int(10) unsigned DEFAULT NULL";
            $result = _mysqli_query($sql);
            
            $sql="ALTER TABLE 202_users_pref ADD COLUMN `user_pref_privacy` varchar(100) NOT NULL DEFAULT 'disabled'";
            $result = _mysqli_query($sql);
            
            $sql="ALTER TABLE `202_locations_city` ADD INDEX   `city_name` (`city_name`,`main_country_id`)";
            $result = _mysqli_query($sql);
            
            $sql="CREATE TABLE IF NOT EXISTS `202_bing` (
            `click_id` bigint(20) unsigned NOT NULL,
            `msclkid` varchar(150) NOT NULL,
            `utm_source_id` bigint(20) unsigned NOT NULL,
            `utm_medium_id` bigint(20) unsigned NOT NULL,
            `utm_campaign_id` bigint(20) unsigned NOT NULL,
            `utm_term_id` bigint(20) unsigned NOT NULL,
            `utm_content_id` bigint(20) unsigned NOT NULL,
            PRIMARY KEY (`click_id`,`msclkid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            
            $result = _mysqli_query($sql);
            
            $sql="CREATE TABLE `202_ips_v6` (
            `ip_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `ip_address` varbinary(16) NOT NULL DEFAULT '',
            `location_id` mediumint(8) unsigned NOT NULL,
            PRIMARY KEY (`ip_id`),
            KEY `ip_address` (`ip_address`),
            KEY `location_id` (`location_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
            
            $result = _mysqli_query($sql);
            
                        //set collations and char set
            
            $sql = "ALTER TABLE `202_ips` CHANGE `ip_address` `ip_address` VARCHAR(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL";
            $result = _mysqli_query($sql);  
            
            $sql = "UPDATE 202_version SET version='1.9.51'";
            $result = _mysqli_query($sql);
        
            $prosper202_version = '1.9.51';
        }
        
        if ($prosper202_version == '1.9.51') {
        
        
            //set collations and char set
        
            $sql = "ALTER TABLE `202_ips` CHANGE `ip_address` `ip_address` VARCHAR(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL";
            $result = _mysqli_query($sql);

            // for the varchar(191) fix
            $sql = "CREATE TABLE  IF NOT EXISTS `202_variable_sets2` (`variable_set_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,`variables` varchar(255) NOT NULL DEFAULT '',PRIMARY KEY (`variable_set_id`,`variables`(191)),KEY `custom_variable_id` (`variables`(191)),KEY `click_id` (`variable_set_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
            $result = _mysqli_query($sql);
            
            //Move data from variable_sets into variable_sets2
            $sql="SELECT * from 202_variable_sets";
            
            $result = _mysqli_query($sql);
            
            $i=0;
            $row ='';
            
            while ($sql_row = $result->fetch_assoc()) {
                $vars=( explode(',',$sql_row['variables']));
                 
                foreach ($vars as $var){
                    $row.= "(". $sql_row['variable_set_id'].",".$var."),";
                }
                 
                 
                if(($i % 600)==0){
                    $row="insert ignore into `202_variable_sets2` (`variable_set_id`, `variables`) values ".rtrim($row,',').";";
                    _mysqli_query($row);

                    $i=0;
                    $row='';
                }
                $i++;
                 
            
            }
            //add the last bit of data if there's any left
            if($row)
            {                
                $row="insert ignore into `202_variable_sets2` (`variable_set_id`, `variables`) values ".rtrim($row,',').";";
                _mysqli_query($row);
            }

            //add fbclid table
            $sql="CREATE TABLE IF NOT EXISTS `202_facebook` (
            `click_id` bigint(20) unsigned NOT NULL,
            `fbclid` varchar(150) NOT NULL,
            `utm_source_id` bigint(20) unsigned NOT NULL,
            `utm_medium_id` bigint(20) unsigned NOT NULL,
            `utm_campaign_id` bigint(20) unsigned NOT NULL,
            `utm_term_id` bigint(20) unsigned NOT NULL,
            `utm_content_id` bigint(20) unsigned NOT NULL,
            PRIMARY KEY (`click_id`,`fbclid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
            $result = _mysqli_query($sql);
            
            $sql="ALTER TABLE `202_ppc_account_pixels` ADD INDEX  `ppc_account_id` (`ppc_account_id`)";
            $result = _mysqli_query($sql);

            $sql = "UPDATE 202_version SET version='1.9.52'";
            $result = _mysqli_query($sql);
        
            $prosper202_version = '1.9.52';
        }
        
        
        if ($prosper202_version == '1.9.52') {

            $sql="ALTER TABLE 202_users_pref ADD COLUMN `ipqs_api_key` varchar(250) DEFAULT NULL";
            $result = _mysqli_query($sql);
        
            $sql = "UPDATE 202_version SET version='1.9.53'";
            $result = _mysqli_query($sql);
        
            $prosper202_version = '1.9.53';
        }

        if ($prosper202_version == '1.9.53') {
            $sql="CREATE TABLE IF NOT EXISTS `202_bot202_facebook_pixel_assistant` (
                `b202_fbpa_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
                `landing_page_id` mediumint(8) unsigned NOT NULL,
                `b202_fbpa_status` tinyint(1) unsigned NOT NULL DEFAULT '0',
                `b202_fbpa_dynamic_epv` tinyint(1) unsigned NOT NULL DEFAULT '0',
                `b202_fbpa_content_name` varchar(100) COLLATE utf8mb4_bin DEFAULT NULL,
                `b202_fbpa_content_type` tinyint(3) unsigned NOT NULL DEFAULT '0',
                `b202_fbpa_outbound_clicks` tinyint(1) unsigned NOT NULL DEFAULT '0',
                PRIMARY KEY (`b202_fbpa_id`),
                UNIQUE KEY `landing_page_id` (`landing_page_id`)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;";
              $result = _mysqli_query($sql);
            
            $sql="CREATE TABLE IF NOT EXISTS `202_bot202_facebook_pixel_content_type` (
                `content_type_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `content_type` varchar(50) COLLATE utf8mb4_bin DEFAULT NULL,
                `content_type_description` varchar(100) COLLATE utf8mb4_bin DEFAULT NULL,
                PRIMARY KEY (`content_type_id`),
                KEY `content_type_id` (`content_type_id`,`content_type_description`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;";
            $result = _mysqli_query($sql);
            
            $sql="CREATE TABLE IF NOT EXISTS `202_bot202_facebook_pixel_click_events` (
                `event_type_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `event_type` varchar(50) COLLATE utf8mb4_bin DEFAULT NULL,
                `event_type_description` varchar(100) COLLATE utf8mb4_bin DEFAULT NULL,
                PRIMARY KEY (`event_type_id`),
                KEY `event_type_id` (`event_type_id`,`event_type_description`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;";
            $result = _mysqli_query($sql);
              

            $sql ="INSERT IGNORE INTO `202_pixel_types` (`pixel_type`) VALUES
				('Bot202 Facebook Pixel Assistant');";
		    $result = _mysqli_query($sql);
        
            $sql = "UPDATE 202_version SET version='1.9.54'";
            $result = _mysqli_query($sql);
        
            $prosper202_version = '1.9.54';
        }        

        if ($prosper202_version == '1.9.54') {

            $sql="DROP TABLE IF EXISTS `202_bot202_facebook_pixel_click_events`";
            $result = _mysqli_query($sql);
        
            $sql="CREATE TABLE IF NOT EXISTS `202_bot202_facebook_pixel_click_events` (
                `event_type_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `event_type` varchar(50) COLLATE utf8mb4_bin DEFAULT NULL,
                `event_type_description` varchar(100) COLLATE utf8mb4_bin DEFAULT NULL,
                PRIMARY KEY (`event_type_id`),
                KEY `event_type_id` (`event_type_id`,`event_type_description`)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin";
            $result = _mysqli_query($sql);

            $sql="INSERT INTO `202_bot202_facebook_pixel_click_events` (`event_type_id`, `event_type`, `event_type_description`)
                    VALUES
                    (1,X'56696577436F6E74656E74',X'547261636B2041733A205669657720436F6E74656E74'),
                    (2,X'416464546F43617274',X'547261636B2041733A2041646420546F2043617274');";
            $result = _mysqli_query($sql);

            $sql="DROP TABLE IF EXISTS `202_bot202_facebook_pixel_content_type`;";
            $result = _mysqli_query($sql);

            $sql="CREATE TABLE IF NOT EXISTS `202_bot202_facebook_pixel_content_type` (
                `content_type_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `content_type` varchar(50) COLLATE utf8mb4_bin DEFAULT NULL,
                `content_type_description` varchar(100) COLLATE utf8mb4_bin DEFAULT NULL,
                PRIMARY KEY (`content_type_id`),
                KEY `content_type_id` (`content_type_id`,`content_type_description`)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;";
            $result = _mysqli_query($sql);

            $sql="INSERT INTO `202_bot202_facebook_pixel_content_type` (`content_type_id`, `content_type`, `content_type_description`)
            VALUES
                (1,X'70726F64756374',X'65436F6D6D657263652050726F6475637420466F722053616C65'),
                (2,X'666C69676874',X'4169726C696E6520466C6967687473'),
                (3,X'686F74656C',X'486F74656C20526F6F6D7320262047656E6572616C204163636F6D6D6F646174696F6E73'),
                (4,X'64657374696E6174696F6E',X'54726176656C2044657374696E6174696F6E'),
                (5,X'76656869636C65',X'4E6577202620557365642056656869636C6573'),
                (6,X'686F6D655F6C697374696E67',X'5265616C2045737461746520262052656E74616C2050726F7065727479');";
            $result = _mysqli_query($sql);

            $sql="ALTER TABLE `202_aff_campaigns` ADD INDEX  `aff_campaign_id` (`aff_campaign_id`,`aff_campaign_name`)";
            $result = _mysqli_query($sql);                           
            
            if($_POST['lp_ssl'] == 1 || !isset($_POST['lp_ssl'])){ 

                //upgrade to ssl if user requests it or if no option is set
                $sql="UPDATE 202_landing_pages set landing_page_url = REPLACE(landing_page_url,'http://','https://')";
                $result = _mysqli_query($sql);
            }

            $sql = "UPDATE 202_version SET version='1.9.55'";
            $result = _mysqli_query($sql);
        
            $prosper202_version = '1.9.55';
        }

        //This will enable p202 to downgrade to this version if installed over a newer version
        if ($prosper202_version > '1.9.55') {
           
        
            $prosper202_version = '1.9.55';
            $sql = "UPDATE 202_version SET version='".$prosper202_version."'";
            $result = _mysqli_query($sql);
        }
        
        return true;

    }
} 