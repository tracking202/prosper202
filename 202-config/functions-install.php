<?php

class INSTALL {

	function install_databases() {

		$database = DB::getInstance();
    	$db = $database->getConnection();

		$php_version = PROSPER202::php_version();

		//create the new mysql version table
		$sql = "CREATE TABLE IF NOT EXISTS `202_version` (
					  `version` varchar(50) NOT NULL
					) ENGINE=InnoDB ;";
		$result = _mysqli_query($sql);

		//now add the what version this software is
		$sql = "INSERT INTO 202_version SET version='$php_version'";
		$result = _mysqli_query($sql);

		//create sessions table
		$sql = "CREATE TABLE IF NOT EXISTS `202_sessions` (
				  `session_id` varchar(100) NOT NULL DEFAULT '',
				  `session_data` text NOT NULL,
				  `expires` int(11) NOT NULL DEFAULT '0',
				  PRIMARY KEY (`session_id`)
				) ENGINE=InnoDB ;";
		$result = _mysqli_query($sql);

		$sql = "CREATE TABLE IF NOT EXISTS `202_cronjobs` (
				  `cronjob_type` char(5) NOT NULL,
				  `cronjob_time` int(11) NOT NULL,
				  KEY `cronjob_type` (`cronjob_type`,`cronjob_time`)
				) ENGINE=InnoDB ;"; 
		$result = _mysqli_query($sql);

		$sql = "CREATE TABLE IF NOT EXISTS `202_mysql_errors` (
  `mysql_error_id` mediumint(8) unsigned NOT NULL auto_increment,
  `mysql_error_text` text NOT NULL,
  `mysql_error_sql` text NOT NULL,
  `user_id` mediumint(8) unsigned NOT NULL,
  `ip_id` bigint(20) unsigned NOT NULL,
  `mysql_error_time` int(10) unsigned NOT NULL,
  `site_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (`mysql_error_id`)
) ENGINE=InnoDB ;";
		$result = _mysqli_query($sql);

		$sql = "CREATE TABLE IF NOT EXISTS `202_users_log` (
			  `login_id` mediumint(9) NOT NULL auto_increment,
			  `user_name` varchar(255) NOT NULL,
			  `user_pass` varchar(255) NOT NULL,
			  `ip_address` varchar(255) NOT NULL,
			  `login_time` int(10) unsigned NOT NULL,
			  `login_success` tinyint(1) NOT NULL,
			  `login_error` text NOT NULL,
			  `login_server` text NOT NULL,
			  `login_session` text NOT NULL,
			  PRIMARY KEY  (`login_id`),
			  KEY `login_pass` (`login_success`),
			  KEY `ip_address` (`ip_address`)
			) ENGINE=InnoDB   ;";
		$result = _mysqli_query($sql);

		//create users table
		$sql = "CREATE TABLE IF NOT EXISTS `202_users` (
  `user_id` mediumint(8) unsigned NOT NULL auto_increment,
  `user_name` varchar(50) NOT NULL,
  `user_pass` char(32) NOT NULL,
  `user_email` varchar(100) NOT NULL,
  `user_timezone` varchar(50) NOT NULL default 'Pacific/Pitcairn',
  `user_time_register` int(10) unsigned NOT NULL,
  `user_pass_key` varchar(255) NOT NULL,
  `user_pass_time` int(10) unsigned NOT NULL,
  `user_api_key` varchar(255) NOT NULL,
  `user_stats202_app_key` varchar(255) NOT NULL,
  `user_last_login_ip_id` bigint(20) unsigned NOT NULL,
  `clickserver_api_key` varchar(255) NOT NULL,
  `install_hash` varchar(255) NOT NULL,
  `user_hash` varchar(255) NOT NULL,
  `modal_status` int(1) NOT NULL,
  `vip_perks_status` int(1) NOT NULL,
  PRIMARY KEY  (`user_id`),
  KEY `user_name` (`user_name`,`user_pass`),
  KEY `user_pass_key` (`user_pass_key`(5)),
  KEY `user_last_login_ip_id` (`user_last_login_ip_id`)
) ENGINE=InnoDB  ;
";
		$result = _mysqli_query($sql);

		//create users table
		$sql = "CREATE TABLE IF NOT EXISTS `202_users_pref` (
  `user_id` mediumint(8) unsigned NOT NULL,
  `user_pref_limit` tinyint(3) unsigned NOT NULL DEFAULT '50',
  `user_pref_show` varchar(25) NOT NULL,
  `user_pref_time_from` int(10) unsigned NOT NULL,
  `user_pref_time_to` int(10) unsigned NOT NULL,
  `user_pref_time_predefined` varchar(25) NOT NULL DEFAULT 'today',
  `user_pref_adv` tinyint(1) NOT NULL,
  `user_pref_ppc_network_id` mediumint(8) unsigned NOT NULL,
  `user_pref_ppc_account_id` mediumint(8) unsigned NOT NULL,
  `user_pref_aff_network_id` mediumint(8) unsigned NOT NULL,
  `user_pref_aff_campaign_id` mediumint(8) unsigned NOT NULL,
  `user_pref_text_ad_id` mediumint(8) unsigned NOT NULL,
  `user_pref_method_of_promotion` varchar(25) NOT NULL,
  `user_pref_landing_page_id` mediumint(8) unsigned NOT NULL,
  `user_pref_country_id` tinyint(3) unsigned NOT NULL,
  `user_pref_region_id` tinyint(3) unsigned NOT NULL,
  `user_pref_device_id` tinyint(3) unsigned NOT NULL,
  `user_pref_browser_id` tinyint(3) unsigned NOT NULL,
  `user_pref_platform_id` tinyint(3) unsigned NOT NULL,
  `user_pref_isp_id` tinyint(3) unsigned NOT NULL,
  `user_pref_ip` varchar(100) NOT NULL,
  `user_pref_referer` varchar(100) NOT NULL,
  `user_pref_keyword` varchar(100) NOT NULL,
  `user_pref_breakdown` varchar(100) NOT NULL DEFAULT 'day',
  `user_pref_chart` varchar(255) NOT NULL DEFAULT 'net',
  `user_cpc_or_cpv` char(3) NOT NULL DEFAULT 'cpc',
  `user_keyword_searched_or_bidded` varchar(255) NOT NULL DEFAULT 'searched',
  `user_tracking_domain` varchar(255) NOT NULL DEFAULT '',
  `user_pref_group_2` tinyint(3) NOT NULL,
  `user_pref_group_3` tinyint(3) NOT NULL,
  `user_pref_group_4` tinyint(3) NOT NULL,
  `user_pref_group_1` tinyint(3) NOT NULL,
  `cache_time` VARCHAR(4) NOT NULL DEFAULT '0',
  `cb_key` VARCHAR(250) NOT NULL,
  `cb_verified` tinyint(1) NOT NULL default '0',
  `maxmind_isp` tinyint(1) NOT NULL default '0',
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB ;
";
		$result = _mysqli_query($sql);

		//create clicks_tracking table
		$sql = "CREATE TABLE IF NOT EXISTS `202_clicks_tracking` (
				  `click_id` bigint(20) unsigned NOT NULL,
				  `c1_id` bigint(20) NOT NULL,
				  `c2_id` bigint(20) NOT NULL,
				  `c3_id` bigint(20) NOT NULL,
				  `c4_id` bigint(20) NOT NULL,
				  PRIMARY KEY (`click_id`)
				) ENGINE=InnoDB 
		";
		$result = _mysqli_query($sql);

		//create c1 table
		$sql = "CREATE TABLE IF NOT EXISTS `202_tracking_c1` (
		  `c1_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		  `c1` varchar(50) NOT NULL,
		  PRIMARY KEY (`c1_id`)
		) ENGINE=InnoDB AUTO_INCREMENT=1 ;
		";
		$result = _mysqli_query($sql);

		//create c2 table
		$sql = "CREATE TABLE IF NOT EXISTS `202_tracking_c2` (
		  `c2_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		  `c2` varchar(50) NOT NULL,
		  PRIMARY KEY (`c2_id`)
		) ENGINE=InnoDB AUTO_INCREMENT=1 ;
		";
		$result = _mysqli_query($sql);

		//create c3 table
		$sql = "CREATE TABLE IF NOT EXISTS `202_tracking_c3` (
		  `c3_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		  `c3` varchar(50) NOT NULL,
		  PRIMARY KEY (`c3_id`)
		) ENGINE=InnoDB AUTO_INCREMENT=1 ;
		";
		$result = _mysqli_query($sql);

		//create c4 table
		$sql = "CREATE TABLE IF NOT EXISTS `202_tracking_c4` (
		  `c4_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		  `c4` varchar(50) NOT NULL,
		  PRIMARY KEY (`c4_id`)
		) ENGINE=InnoDB AUTO_INCREMENT=1 ;
		";
		$result = _mysqli_query($sql);

		//export202 - information schema

		$sql =" CREATE TABLE IF NOT EXISTS `202_export_adgroups` (
				  `export_session_id` mediumint(8) unsigned NOT NULL,
				  `export_campaign_id` mediumint(8) unsigned NOT NULL,
				  `export_adgroup_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
				  `export_adgroup_name` varchar(255) NOT NULL,
				  `export_adgroup_status` tinyint(1) NOT NULL,
				  `export_adgroup_max_search_cpc` decimal(10,2) NOT NULL,
				  `export_adgroup_max_content_cpc` decimal(10,2) NOT NULL,
				  `export_adgroup_search` tinyint(1) NOT NULL,
				  `export_adgroup_content` tinyint(1) NOT NULL,
				  PRIMARY KEY (`export_adgroup_id`),
				  KEY `export_campaign_id` (`export_campaign_id`),
				  KEY `export_session_id` (`export_session_id`)
				) ENGINE=InnoDB   ;";
		$result = _mysqli_query($sql);



		$sql ="CREATE TABLE IF NOT EXISTS `202_export_campaigns` (
				  `export_session_id` mediumint(8) unsigned NOT NULL,
				  `export_campaign_id` mediumint(9) NOT NULL AUTO_INCREMENT,
				  `export_campaign_name` varchar(255) NOT NULL,
				  `export_campaign_status` tinyint(1) NOT NULL,
				  `export_campaign_daily_budget` decimal(10,2) unsigned NOT NULL,
				  PRIMARY KEY (`export_campaign_id`),
				  KEY `export_session_id` (`export_session_id`)
				) ENGINE=InnoDB   ;";
		$result = _mysqli_query($sql);



		$sql ="CREATE TABLE IF NOT EXISTS `202_export_keywords` (
				  `export_session_id` mediumint(8) unsigned NOT NULL,
				  `export_campaign_id` mediumint(8) unsigned NOT NULL,
				  `export_adgroup_id` mediumint(8) unsigned NOT NULL,
				  `export_keyword_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
				  `export_keyword_status` tinyint(1) NOT NULL,
				  `export_keyword` varchar(255) NOT NULL,
				  `export_keyword_match` varchar(10) NOT NULL,
				  `export_keyword_watchlist` tinyint(1) NOT NULL,
				  `export_keyword_max_cpc` decimal(10,2) NOT NULL,
				  `export_keyword_destination_url` varchar(255) NOT NULL,
				  PRIMARY KEY (`export_keyword_id`),
				  KEY `export_session_id` (`export_session_id`),
				  KEY `export_campaign_id` (`export_campaign_id`),
				  KEY `export_adgroup_id` (`export_adgroup_id`),
				  KEY `export_keyword_match` (`export_keyword_match`)
				) ENGINE=InnoDB   ;";
		$result = _mysqli_query($sql);


		$sql ="CREATE TABLE IF NOT EXISTS `202_export_sessions` (
				  `export_session_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
				  `export_session_id_public` varchar(255) NOT NULL,
				  `export_session_time` int(10) unsigned NOT NULL,
				  `export_session_ip` varchar(255) NOT NULL,
				  PRIMARY KEY (`export_session_id`),
				  KEY `session_id_public` (`export_session_id_public`(5))
				) ENGINE=InnoDB    ;";
		$result = _mysqli_query($sql);


		$sql ="CREATE TABLE IF NOT EXISTS `202_export_textads` (
				  `export_session_id` mediumint(8) unsigned NOT NULL,
				  `export_campaign_id` mediumint(8) unsigned NOT NULL,
				  `export_adgroup_id` mediumint(8) unsigned NOT NULL,
				  `export_textad_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
				  `export_textad_name` varchar(255) NOT NULL,
				  `export_textad_title` varchar(255) NOT NULL,
				  `export_textad_description_full` varchar(255) NOT NULL,
				  `export_textad_description_line1` varchar(255) NOT NULL,
				  `export_textad_description_line2` varchar(255) NOT NULL,
				  `export_textad_display_url` varchar(255) NOT NULL,
				  `export_textad_destination_url` varchar(255) NOT NULL,
				  `export_textad_status` tinyint(1) NOT NULL,
				  PRIMARY KEY (`export_textad_id`),
				  KEY `export_session_id` (`export_session_id`),
				  KEY `export_campaign_id` (`export_campaign_id`),
				  KEY `export_adgroup_id` (`export_adgroup_id`)
				) ENGINE=InnoDB   ;";
		$result = _mysqli_query($sql);


		//tracking202 schema

		$sql ="CREATE TABLE IF NOT EXISTS `202_aff_campaigns` (
				  `aff_campaign_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
				  `aff_campaign_id_public` int(10) unsigned NOT NULL,
				  `user_id` mediumint(8) unsigned NOT NULL,
				  `aff_network_id` mediumint(8) unsigned NOT NULL,
				  `aff_campaign_deleted` tinyint(1) NOT NULL DEFAULT '0',
				  `aff_campaign_name` varchar(50) NOT NULL,
				  `aff_campaign_url` text NOT NULL,
				  `aff_campaign_url_2` text NOT NULL,
				  `aff_campaign_url_3` text NOT NULL,
				  `aff_campaign_url_4` text NOT NULL,
				  `aff_campaign_url_5` text NOT NULL,
				  `aff_campaign_payout` decimal(5,2) NOT NULL,
				  `aff_campaign_cloaking` tinyint(1) NOT NULL DEFAULT '0',
				  `aff_campaign_time` int(10) unsigned NOT NULL,
				  `aff_campaign_rotate` tinyint(1) NOT NULL DEFAULT '0',
				  PRIMARY KEY (`aff_campaign_id`),
				  KEY `aff_network_id` (`aff_network_id`),
				  KEY `aff_campaign_deleted` (`aff_campaign_deleted`),
				  KEY `user_id` (`user_id`),
				  KEY `aff_campaign_name` (`aff_campaign_name`(5)),
				  KEY `aff_campaign_id_public` (`aff_campaign_id_public`)
				) ENGINE=InnoDB  ;";
		$result = _mysqli_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_aff_networks` (
  `aff_network_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` mediumint(8) unsigned NOT NULL,
  `aff_network_name` varchar(50) NOT NULL,
  `aff_network_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `aff_network_time` int(10) unsigned NOT NULL,
  PRIMARY KEY (`aff_network_id`),
  KEY `user_id` (`user_id`),
  KEY `aff_network_deleted` (`aff_network_deleted`),
  KEY `aff_network_name` (`aff_network_name`(5))
) ENGINE=InnoDB  ;";
		$result = _mysqli_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_browsers` (
  `browser_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `browser_name` varchar(50) NOT NULL,
  PRIMARY KEY (`browser_id`)
) ENGINE=InnoDB  ;";
		$result = _mysqli_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_charts` (
  `chart_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `chart_xml` mediumtext NOT NULL,
  PRIMARY KEY (`chart_id`)
) ENGINE=InnoDB  ;";
		$result = _mysqli_query($sql);

		//this is partitioned from 2012-01-01 to 2014-12-31 for mysql 5.1 users
		//create the click table
		$sql ="CREATE TABLE `202_clicks` (
		  `click_id` bigint(20) unsigned NOT NULL,
		  `user_id` mediumint(8) unsigned NOT NULL,
		  `aff_campaign_id` mediumint(8) unsigned NOT NULL,
		  `landing_page_id` mediumint(8) unsigned NOT NULL,
		  `ppc_account_id` mediumint(8) unsigned NOT NULL,
		  `click_cpc` decimal(7,5) NOT NULL,
		  `click_payout` decimal(6,2) NOT NULL,
		  `click_lead` tinyint(1) NOT NULL DEFAULT '0',
		  `click_filtered` tinyint(1) NOT NULL DEFAULT '0',
		  `click_bot` tinyint(1) NOT NULL DEFAULT '0',
		  `click_alp` tinyint(1) NOT NULL DEFAULT '0',
		  `click_time` int(10) unsigned NOT NULL,
		  `rotator_id` int(10) unsigned NOT NULL,
		  `rule_id` int(10) unsigned NOT NULL,
		  KEY `aff_campaign_id` (`aff_campaign_id`),
		  KEY `ppc_account_id` (`ppc_account_id`),
		  KEY `click_lead` (`click_lead`),
		  KEY `click_filtered` (`click_filtered`),
		  KEY `click_id` (`click_id`),
		  KEY `overview_index` (`user_id`,`click_filtered`,`aff_campaign_id`,`ppc_account_id`),
		  KEY `user_id` (`user_id`,`click_lead`),
		  KEY `click_alp` (`click_alp`),
		  KEY `landing_page_id` (`landing_page_id`),
		  KEY `overview_index2` (`user_id`,`click_filtered`,`landing_page_id`,`aff_campaign_id`),
		  KEY `rotator_id` (`rotator_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8;";
		$result = _mysqli_query($sql);

		//run the alter table to setup partioning if they have mysql 5.1 or greater
		$sql = "/*!50100 ALTER TABLE `202_clicks`
					PARTITION BY RANGE (click_time) (
					PARTITION p32 VALUES LESS THAN (1326578400) ENGINE = MyISAM,
					PARTITION p33 VALUES LESS THAN (1327788000) ENGINE = MyISAM,
					PARTITION p34 VALUES LESS THAN (1328997600) ENGINE = MyISAM,
					PARTITION p35 VALUES LESS THAN (1330207200) ENGINE = MyISAM,
					PARTITION p36 VALUES LESS THAN (1331416800) ENGINE = MyISAM,
					PARTITION p37 VALUES LESS THAN (1332626400) ENGINE = MyISAM,
					PARTITION p38 VALUES LESS THAN (1333832400) ENGINE = MyISAM,
					PARTITION p39 VALUES LESS THAN (1335042000) ENGINE = MyISAM,
					PARTITION p40 VALUES LESS THAN (1336251600) ENGINE = MyISAM,
					PARTITION p41 VALUES LESS THAN (1337461200) ENGINE = MyISAM,
					PARTITION p42 VALUES LESS THAN (1338670800) ENGINE = MyISAM,
					PARTITION p43 VALUES LESS THAN (1339880400) ENGINE = MyISAM,
					PARTITION p44 VALUES LESS THAN (1341090000) ENGINE = MyISAM,
					PARTITION p45 VALUES LESS THAN (1342299600) ENGINE = MyISAM,
					PARTITION p46 VALUES LESS THAN (1343509200) ENGINE = MyISAM,
					PARTITION p47 VALUES LESS THAN (1344718800) ENGINE = MyISAM,
					PARTITION p48 VALUES LESS THAN (1345928400) ENGINE = MyISAM,
					PARTITION p49 VALUES LESS THAN (1347138000) ENGINE = MyISAM,
					PARTITION p50 VALUES LESS THAN (1348347600) ENGINE = MyISAM,
					PARTITION p51 VALUES LESS THAN (1349557200) ENGINE = MyISAM,
					PARTITION p52 VALUES LESS THAN (1350766800) ENGINE = MyISAM,
					PARTITION p53 VALUES LESS THAN (1351980000) ENGINE = MyISAM,
					PARTITION p54 VALUES LESS THAN (1353189600) ENGINE = MyISAM,
					PARTITION p55 VALUES LESS THAN (1354399200) ENGINE = MyISAM,
					PARTITION p56 VALUES LESS THAN (1355608800) ENGINE = MyISAM,
					PARTITION p57 VALUES LESS THAN (1356818400) ENGINE = MyISAM,
					PARTITION p58 VALUES LESS THAN (1358028000) ENGINE = MyISAM,
					PARTITION p59 VALUES LESS THAN (1359237600) ENGINE = MyISAM,
					PARTITION p60 VALUES LESS THAN (1360447200) ENGINE = MyISAM,
					PARTITION p70 VALUES LESS THAN (1361656800) ENGINE = MyISAM,
					PARTITION p71 VALUES LESS THAN (1362866400) ENGINE = MyISAM,
					PARTITION p72 VALUES LESS THAN (1364076000) ENGINE = MyISAM,
					PARTITION p73 VALUES LESS THAN (1365282000) ENGINE = MyISAM,
					PARTITION p74 VALUES LESS THAN (1366491600) ENGINE = MyISAM,
					PARTITION p75 VALUES LESS THAN (1367701200) ENGINE = MyISAM,
					PARTITION p76 VALUES LESS THAN (1368910800) ENGINE = MyISAM,
					PARTITION p77 VALUES LESS THAN (1370120400) ENGINE = MyISAM,
					PARTITION p78 VALUES LESS THAN (1371330000) ENGINE = MyISAM,
					PARTITION p79 VALUES LESS THAN (1372539600) ENGINE = MyISAM,
					PARTITION p80 VALUES LESS THAN (1373749200) ENGINE = MyISAM,
					PARTITION p81 VALUES LESS THAN (1374958800) ENGINE = MyISAM,
					PARTITION p82 VALUES LESS THAN (1376168400) ENGINE = MyISAM,
					PARTITION p83 VALUES LESS THAN (1377378000) ENGINE = MyISAM,
					PARTITION p84 VALUES LESS THAN (1378587600) ENGINE = MyISAM,
					PARTITION p85 VALUES LESS THAN (1379797200) ENGINE = MyISAM,
					PARTITION p86 VALUES LESS THAN (1381006800) ENGINE = MyISAM,
					PARTITION p87 VALUES LESS THAN (1382216400) ENGINE = MyISAM,
					PARTITION p88 VALUES LESS THAN (1383429600) ENGINE = MyISAM,
					PARTITION p89 VALUES LESS THAN (1384639200) ENGINE = MyISAM,
					PARTITION p90 VALUES LESS THAN (1385848800) ENGINE = MyISAM,
					PARTITION p91 VALUES LESS THAN (1387058400) ENGINE = MyISAM,
					PARTITION p92 VALUES LESS THAN (1388268000) ENGINE = MyISAM,
					PARTITION p93 VALUES LESS THAN (1389477600) ENGINE = MyISAM,
					PARTITION p94 VALUES LESS THAN (1390687200) ENGINE = MyISAM,
					PARTITION p95 VALUES LESS THAN (1391896800) ENGINE = MyISAM,
					PARTITION p96 VALUES LESS THAN (1393106400) ENGINE = MyISAM,
					PARTITION p97 VALUES LESS THAN (1394316000) ENGINE = MyISAM,
					PARTITION p98 VALUES LESS THAN (1395525600) ENGINE = MyISAM,
					PARTITION p99 VALUES LESS THAN (1396731600) ENGINE = MyISAM,
					PARTITION p100 VALUES LESS THAN (1397941200) ENGINE = MyISAM,
					PARTITION p101 VALUES LESS THAN (1399150800) ENGINE = MyISAM,
					PARTITION p102 VALUES LESS THAN (1400360400) ENGINE = MyISAM,
					PARTITION p103 VALUES LESS THAN (1401570000) ENGINE = MyISAM,
					PARTITION p104 VALUES LESS THAN (1402779600) ENGINE = MyISAM,
					PARTITION p105 VALUES LESS THAN (1403989200) ENGINE = MyISAM,
					PARTITION p106 VALUES LESS THAN (1405198800) ENGINE = MyISAM,
					PARTITION p107 VALUES LESS THAN (1406408400) ENGINE = MyISAM,
					PARTITION p108 VALUES LESS THAN (1407618000) ENGINE = MyISAM,
					PARTITION p109 VALUES LESS THAN (1408827600) ENGINE = MyISAM,
					PARTITION p110 VALUES LESS THAN (1410037200) ENGINE = MyISAM,
					PARTITION p111 VALUES LESS THAN (1411246800) ENGINE = MyISAM,
					PARTITION p112 VALUES LESS THAN (1412456400) ENGINE = MyISAM,
					PARTITION p113 VALUES LESS THAN (1413666000) ENGINE = MyISAM,
					PARTITION p114 VALUES LESS THAN (1414879200) ENGINE = MyISAM,
					PARTITION p115 VALUES LESS THAN (1416088800) ENGINE = MyISAM,
					PARTITION p116 VALUES LESS THAN (1417298400) ENGINE = MyISAM,
					PARTITION p117 VALUES LESS THAN (1418508000) ENGINE = MyISAM,
					PARTITION p118 VALUES LESS THAN (1419717600) ENGINE = MyISAM,
					PARTITION p119 VALUES LESS THAN (1420927200) ENGINE = MyISAM,
					PARTITION p120 VALUES LESS THAN MAXVALUE ENGINE = MyISAM) */;";
		$result = $db->query($sql); #don't throw error if the partitioning doesn't work

		$sql ="CREATE TABLE IF NOT EXISTS `202_clicks_advance` (
  `click_id` bigint(20) unsigned NOT NULL,
  `text_ad_id` mediumint(8) unsigned NOT NULL,
  `keyword_id` bigint(20) unsigned NOT NULL,
  `ip_id` bigint(20) unsigned NOT NULL,
  `country_id` bigint(20) unsigned NOT NULL,
  `region_id` bigint(20) unsigned NOT NULL,
  `city_id` bigint(20) unsigned NOT NULL,
  `platform_id` bigint(20) unsigned NOT NULL,
  `browser_id` bigint(20) unsigned NOT NULL,
  `device_id` bigint(20) unsigned NOT NULL,
  `isp_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (`click_id`),
  KEY `text_ad_id` (`text_ad_id`),
  KEY `keyword_id` (`keyword_id`),
  KEY `ip_id` (`ip_id`)
) ENGINE=InnoDB ;";
		$result = _mysqli_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_clicks_counter` (
  `click_id` bigint(20) unsigned NOT NULL auto_increment,
  PRIMARY KEY  (`click_id`)
) ENGINE=InnoDB  ;";
		$result = _mysqli_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_clicks_record` (
  `click_id` bigint(20) unsigned NOT NULL,
  `click_id_public` bigint(20) unsigned NOT NULL,
  `click_cloaking` tinyint(1) NOT NULL default '0',
  `click_in` tinyint(1) NOT NULL default '0',
  `click_out` tinyint(1) NOT NULL default '0',
  `click_reviewed` tinyint(1) NOT NULL default '0',
  PRIMARY KEY  (`click_id`),
  KEY `click_id_public` (`click_id_public`),
  KEY `click_in` (`click_in`),
  KEY `click_out` (`click_out`),
  KEY `click_cloak` (`click_cloaking`),
  KEY `click_reviewed` (`click_reviewed`)
) ENGINE=InnoDB ;";
		$result = _mysqli_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_clicks_site` (
  `click_id` bigint(20) unsigned NOT NULL,
  `click_referer_site_url_id` bigint(20) unsigned NOT NULL,
  `click_landing_site_url_id` bigint(20) unsigned NOT NULL,
  `click_outbound_site_url_id` bigint(20) unsigned NOT NULL,
  `click_cloaking_site_url_id` bigint(20) unsigned NOT NULL,
  `click_redirect_site_url_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (`click_id`),
  KEY `click_referer_site_url_id` (`click_referer_site_url_id`)
) ENGINE=InnoDB ;";
		$result = _mysqli_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_clicks_spy` (
  `click_id` bigint(20) unsigned NOT NULL,
  `user_id` mediumint(8) unsigned NOT NULL,
  `aff_campaign_id` mediumint(8) unsigned NOT NULL,
  `landing_page_id` mediumint(8) unsigned NOT NULL,
  `ppc_account_id` mediumint(8) unsigned NOT NULL,
  `click_cpc` decimal(4,2) NOT NULL,
  `click_payout` decimal(6,2) NOT NULL,
  `click_lead` tinyint(1) NOT NULL default '0',
  `click_filtered` tinyint(1) NOT NULL default '0',
  `click_bot` tinyint(1) NOT NULL default '0',
  `click_alp` tinyint(1) NOT NULL default '0',
  `click_time` int(10) unsigned NOT NULL, 
  KEY `ppc_account_id` (`ppc_account_id`),
  KEY `click_lead` (`click_lead`),
  KEY `click_filtered` (`click_filtered`),
  KEY `click_id` (`click_id`),
  KEY `aff_campaign_id` (`aff_campaign_id`),
  KEY `overview_index` (`user_id`,`click_filtered`,`aff_campaign_id`,`ppc_account_id`,`click_lead`),
  KEY `user_lead` (`user_id`,`click_lead`),
  KEY `click_alp` (`click_alp`),
  KEY `landing_page_id` (`landing_page_id`),
  KEY `overview_index2` (`user_id`,`click_filtered`,`landing_page_id`,`aff_campaign_id`),
  INDEX (click_id)
) ENGINE=InnoDB ;";
		$result = _mysqli_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_delayed_sqls` (
  `delayed_sql` text NOT NULL,
  `delayed_time` int(10) unsigned NOT NULL
) ENGINE=InnoDB ;";
		$result = _mysqli_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_ips` (
  `ip_id` bigint(20) unsigned NOT NULL auto_increment,
  `ip_address` varchar(15) NOT NULL,
  `location_id` mediumint(8) unsigned NOT NULL,
  PRIMARY KEY  (`ip_id`),
  KEY `ip_address` (`ip_address`),
  KEY `location_id` (`location_id`)
) ENGINE=InnoDB  ;";
		$result = _mysqli_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_keywords` (
  `keyword_id` bigint(20) unsigned NOT NULL auto_increment,
  `keyword` varchar(50) NOT NULL,
  PRIMARY KEY  (`keyword_id`),
  KEY `keyword` (`keyword`(10))
) ENGINE=InnoDB  ;";
		$result = _mysqli_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_landing_pages` (
  `landing_page_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` mediumint(8) unsigned NOT NULL,
  `landing_page_id_public` int(10) unsigned NOT NULL,
  `aff_campaign_id` mediumint(8) unsigned NOT NULL,
  `landing_page_nickname` varchar(50) NOT NULL,
  `landing_page_url` varchar(255) NOT NULL,
  `landing_page_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `landing_page_time` int(10) unsigned NOT NULL,
  `landing_page_type` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`landing_page_id`),
  KEY `landing_page_id_public` (`landing_page_id_public`),
  KEY `aff_campaign_id` (`aff_campaign_id`),
  KEY `landing_page_deleted` (`landing_page_deleted`),
  KEY `user_id` (`user_id`),
  KEY `landing_page_type` (`landing_page_type`)
) ENGINE=InnoDB  ;";
		$result = _mysqli_query($sql);

		$sql ="
CREATE TABLE IF NOT EXISTS `202_last_ips` (
  `user_id` mediumint(9) NOT NULL,
  `ip_id` bigint(20) NOT NULL,
  `time` int(10) unsigned NOT NULL,
  KEY `ip_index` (`user_id`,`ip_id`)
) ENGINE=InnoDB ;";
		$result = _mysqli_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_locations_city` (
  `city_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `main_country_id` mediumint(8) unsigned NOT NULL,
  `city_name` varchar(50) NOT NULL,
  PRIMARY KEY (`city_id`)
) ENGINE=InnoDB  ;";
		$result = _mysqli_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_locations_country` (
  `country_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `country_code` varchar(3) NOT NULL,
  `country_name` varchar(50) NOT NULL,
  PRIMARY KEY (`country_id`)
) ENGINE=InnoDB  ;";
		$result = _mysqli_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_locations_region` (
  `region_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `main_country_id` mediumint(8) unsigned NOT NULL,
  `region_name` varchar(50) NOT NULL,
  PRIMARY KEY (`region_id`)
) ENGINE=InnoDB  ;";
		$result = _mysqli_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_locations_isp` (
	  `isp_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	  `isp_name` varchar(50) NOT NULL DEFAULT '',
	  PRIMARY KEY (`isp_id`)
	) ENGINE=InnoDB ;";
		$result = _mysqli_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_platforms` (
  `platform_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `platform_name` varchar(50) NOT NULL,
  PRIMARY KEY (`platform_id`)
) ENGINE=InnoDB  ;";
		$result = _mysqli_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_device_types` (
  `type_id` tinyint(1) unsigned NOT NULL,
  `type_name` varchar(50) NOT NULL,
  PRIMARY KEY (`type_id`)
) ENGINE=InnoDB  ;";
		$result = _mysqli_query($sql);

		$sql = "CREATE TABLE IF NOT EXISTS `202_device_models` (
		  `device_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		  `device_name` varchar(50) NOT NULL,
		  `device_type` tinyint(1) NOT NULL,
		  PRIMARY KEY (`device_id`)
		) ENGINE=InnoDB ;";
		$result = _mysqli_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_ppc_accounts` (
  `ppc_account_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` mediumint(8) unsigned NOT NULL,
  `ppc_network_id` mediumint(8) unsigned NOT NULL,
  `ppc_account_name` varchar(50) NOT NULL,
  `ppc_account_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `ppc_account_time` int(10) unsigned NOT NULL,
  PRIMARY KEY (`ppc_account_id`),
  KEY `ppc_network_id` (`ppc_network_id`),
  KEY `ppc_account_deleted` (`ppc_account_deleted`),
  KEY `user_id` (`user_id`),
  KEY `ppc_account_name` (`ppc_account_name`(5))
) ENGINE=InnoDB  ;";
		$result = _mysqli_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_ppc_networks` (
  `ppc_network_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` mediumint(8) unsigned NOT NULL,
  `ppc_network_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `ppc_network_name` varchar(50) NOT NULL,
  `ppc_network_time` int(10) unsigned NOT NULL,
  PRIMARY KEY (`ppc_network_id`),
  KEY `user_id` (`user_id`),
  KEY `ppc_network_deleted` (`ppc_network_deleted`),
  KEY `ppc_network_name` (`ppc_network_name`(5))
) ENGINE=InnoDB  ;";
		$result = _mysqli_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_site_domains` (
  `site_domain_id` bigint(20) unsigned NOT NULL auto_increment,
  `site_domain_host` varchar(100) NOT NULL,
  PRIMARY KEY  (`site_domain_id`),
  KEY `site_domain_host` (`site_domain_host`(10))
) ENGINE=InnoDB  ;";
		$result = _mysqli_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_site_urls` (
  `site_url_id` bigint(20) unsigned NOT NULL auto_increment,
  `site_domain_id` bigint(20) unsigned NOT NULL,
  `site_url_address` text NOT NULL,
  PRIMARY KEY  (`site_url_id`),
  KEY `site_domain_id` (`site_domain_id`),
  KEY `site_url_address` (`site_url_address`(75))
) ENGINE=InnoDB  ;";
		$result = _mysqli_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_sort_breakdowns` (
		  `sort_breakdown_id` int(10) unsigned NOT NULL auto_increment,
		  `sort_breakdown_from` int(10) unsigned NOT NULL,
		  `sort_breakdown_to` int(10) unsigned NOT NULL,
		  `user_id` mediumint(8) unsigned NOT NULL,
		  `sort_breakdown_clicks` mediumint(8) unsigned NOT NULL,
		  `sort_breakdown_click_throughs` mediumint(8) unsigned NOT NULL,
		  `sort_breakdown_ctr` decimal(10,2) NOT NULL,
		  `sort_breakdown_leads` mediumint(8) unsigned NOT NULL,
		  `sort_breakdown_su_ratio` decimal(10,2) NOT NULL,
		  `sort_breakdown_payout` decimal(6,2) NOT NULL,
		  `sort_breakdown_epc` decimal(10,2) NOT NULL,
		  `sort_breakdown_avg_cpc` decimal(7,5) NOT NULL,
		  `sort_breakdown_income` decimal(10,2) NOT NULL,
		  `sort_breakdown_cost` decimal(13,5) NOT NULL,
		  `sort_breakdown_net` decimal(13,5) NOT NULL,
		  `sort_breakdown_roi` decimal(10,2) NOT NULL,
		  PRIMARY KEY  (`sort_breakdown_id`),
		  KEY `user_id` (`user_id`),
		  KEY `sort_keyword_clicks` (`sort_breakdown_clicks`),
		  KEY `sort_breakdown_click_throughs` (`sort_breakdown_click_throughs`),
		  KEY `sort_breakdown_ctr` (`sort_breakdown_ctr`),
		  KEY `sort_keyword_leads` (`sort_breakdown_leads`),
		  KEY `sort_keyword_signup_ratio` (`sort_breakdown_su_ratio`),
		  KEY `sort_keyword_payout` (`sort_breakdown_payout`),
		  KEY `sort_keyword_epc` (`sort_breakdown_epc`),
		  KEY `sort_keyword_cpc` (`sort_breakdown_avg_cpc`),
		  KEY `sort_keyword_income` (`sort_breakdown_income`),
		  KEY `sort_keyword_cost` (`sort_breakdown_cost`),
		  KEY `sort_keyword_net` (`sort_breakdown_net`),
		  KEY `sort_keyword_roi` (`sort_breakdown_roi`)
		) ENGINE=InnoDB  ;";
		$result = _mysqli_query($sql);

		//this is partitioned from 2009-07-01 to 2011-07-01 for mysql 5.1 users
		$sql ="CREATE TABLE IF NOT EXISTS `202_summary_overview` (
				  `user_id` mediumint(8) unsigned NOT NULL,
				  `aff_campaign_id` mediumint(8) unsigned NOT NULL,
				  `landing_page_id` mediumint(8) unsigned NOT NULL,
				  `ppc_account_id` mediumint(8) unsigned NOT NULL,
				  `click_time` int(10) unsigned NOT NULL,
				  KEY `aff_campaign_id` (`aff_campaign_id`),
				  KEY `user_id` (`user_id`),
				  KEY `ppc_account_id` (`ppc_account_id`),
				  KEY `landing_page_id` (`landing_page_id`),
				  KEY `click_time` (`click_time`)
				) ENGINE=InnoDB ";
		$result = _mysqli_query($sql);

		$sql ="/*!50100 ALTER TABLE `202_summary_overview`
					PARTITION BY RANGE (click_time) (
					PARTITION p32 VALUES LESS THAN (1247641200) ENGINE = MyISAM,
					PARTITION p33 VALUES LESS THAN (1248850800) ENGINE = MyISAM,
					PARTITION p34 VALUES LESS THAN (1250060400) ENGINE = MyISAM,
					PARTITION p35 VALUES LESS THAN (1251270000) ENGINE = MyISAM,
					PARTITION p36 VALUES LESS THAN (1252479600) ENGINE = MyISAM,
					PARTITION p37 VALUES LESS THAN (1253689200) ENGINE = MyISAM,
					PARTITION p38 VALUES LESS THAN (1254898800) ENGINE = MyISAM,
					PARTITION p39 VALUES LESS THAN (1256108400) ENGINE = MyISAM,
					PARTITION p40 VALUES LESS THAN (1257318000) ENGINE = MyISAM,
					PARTITION p41 VALUES LESS THAN (1258527600) ENGINE = MyISAM,
					PARTITION p42 VALUES LESS THAN (1259737200) ENGINE = MyISAM,
					PARTITION p43 VALUES LESS THAN (1260946800) ENGINE = MyISAM,
					PARTITION p44 VALUES LESS THAN (1262156400) ENGINE = MyISAM,
					PARTITION p45 VALUES LESS THAN (1263366000) ENGINE = MyISAM,
					PARTITION p46 VALUES LESS THAN (1264575600) ENGINE = MyISAM,
					PARTITION p47 VALUES LESS THAN (1265785200) ENGINE = MyISAM,
					PARTITION p48 VALUES LESS THAN (1266994800) ENGINE = MyISAM,
					PARTITION p49 VALUES LESS THAN (1268204400) ENGINE = MyISAM,
					PARTITION p50 VALUES LESS THAN (1269414000) ENGINE = MyISAM,
					PARTITION p51 VALUES LESS THAN (1270623600) ENGINE = MyISAM,
					PARTITION p52 VALUES LESS THAN (1271833200) ENGINE = MyISAM,
					PARTITION p53 VALUES LESS THAN (1273042800) ENGINE = MyISAM,
					PARTITION p54 VALUES LESS THAN (1274252400) ENGINE = MyISAM,
					PARTITION p55 VALUES LESS THAN (1275462000) ENGINE = MyISAM,
					PARTITION p56 VALUES LESS THAN (1276671600) ENGINE = MyISAM,
					PARTITION p57 VALUES LESS THAN (1277881200) ENGINE = MyISAM,
					PARTITION p58 VALUES LESS THAN (1279090800) ENGINE = MyISAM,
					PARTITION p59 VALUES LESS THAN (1280300400) ENGINE = MyISAM,
					PARTITION p60 VALUES LESS THAN (1281510000) ENGINE = MyISAM,
					PARTITION p61 VALUES LESS THAN (1282719600) ENGINE = MyISAM,
					PARTITION p62 VALUES LESS THAN (1283929200) ENGINE = MyISAM,
					PARTITION p63 VALUES LESS THAN (1285138800) ENGINE = MyISAM,
					PARTITION p64 VALUES LESS THAN (1286348400) ENGINE = MyISAM,
					PARTITION p65 VALUES LESS THAN (1287558000) ENGINE = MyISAM,
					PARTITION p66 VALUES LESS THAN (1288767600) ENGINE = MyISAM,
					PARTITION p67 VALUES LESS THAN (1289977200) ENGINE = MyISAM,
					PARTITION p68 VALUES LESS THAN (1291186800) ENGINE = MyISAM,
					PARTITION p69 VALUES LESS THAN (1292396400) ENGINE = MyISAM,
					PARTITION p70 VALUES LESS THAN (1293606000) ENGINE = MyISAM,
					PARTITION p71 VALUES LESS THAN (1294815600) ENGINE = MyISAM,
					PARTITION p72 VALUES LESS THAN (1296025200) ENGINE = MyISAM,
					PARTITION p73 VALUES LESS THAN (1297234800) ENGINE = MyISAM,
					PARTITION p74 VALUES LESS THAN (1298444400) ENGINE = MyISAM,
					PARTITION p75 VALUES LESS THAN (1299654000) ENGINE = MyISAM,
					PARTITION p76 VALUES LESS THAN (1300863600) ENGINE = MyISAM,
					PARTITION p77 VALUES LESS THAN (1302073200) ENGINE = MyISAM,
					PARTITION p78 VALUES LESS THAN (1303282800) ENGINE = MyISAM,
					PARTITION p79 VALUES LESS THAN (1304492400) ENGINE = MyISAM,
					PARTITION p80 VALUES LESS THAN (1305702000) ENGINE = MyISAM,
					PARTITION p81 VALUES LESS THAN (1306911600) ENGINE = MyISAM,
					PARTITION p82 VALUES LESS THAN (1308121200) ENGINE = MyISAM,
					PARTITION p83 VALUES LESS THAN (1309330800) ENGINE = MyISAM,
					PARTITION p84 VALUES LESS THAN (1310540400) ENGINE = MyISAM,
					PARTITION p85 VALUES LESS THAN MAXVALUE ENGINE = MyISAM) */;";
		$result = $db->query($sql); #dont throw error if this doesn't work

		$sql ="CREATE TABLE IF NOT EXISTS `202_text_ads` (
  `text_ad_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` mediumint(8) unsigned NOT NULL,
  `aff_campaign_id` mediumint(8) unsigned NOT NULL,
  `landing_page_id` mediumint(8) unsigned NOT NULL,
  `text_ad_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `text_ad_name` varchar(100) NOT NULL,
  `text_ad_headline` varchar(100) NOT NULL,
  `text_ad_description` varchar(100) NOT NULL,
  `text_ad_display_url` varchar(100) NOT NULL,
  `text_ad_time` int(10) unsigned NOT NULL,
  `text_ad_type` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`text_ad_id`),
  KEY `aff_campaign_id` (`aff_campaign_id`),
  KEY `text_ad_deleted` (`text_ad_deleted`),
  KEY `user_id` (`user_id`),
  KEY `text_ad_type` (`text_ad_type`),
  KEY `landing_page_id` (`landing_page_id`)
) ENGINE=InnoDB  ;";
		$result = _mysqli_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_trackers` (
  `tracker_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` mediumint(8) unsigned NOT NULL,
  `tracker_id_public` bigint(20) unsigned NOT NULL,
  `aff_campaign_id` mediumint(8) unsigned NOT NULL,
  `text_ad_id` mediumint(8) unsigned NOT NULL,
  `ppc_account_id` mediumint(8) unsigned NOT NULL,
  `landing_page_id` mediumint(8) unsigned NOT NULL,
  `rotator_id` int(11) unsigned NOT NULL,
  `click_cpc` decimal(7,5) NOT NULL,
  `click_cloaking` tinyint(1) NOT NULL,
  `tracker_time` int(10) unsigned NOT NULL,
  PRIMARY KEY (`tracker_id`),
  KEY `tracker_id_public` (`tracker_id_public`)
) ENGINE=InnoDB  ;";
		$result = _mysqli_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_rotations` (
			  `aff_campaign_id` mediumint(8) unsigned NOT NULL,
			  `rotation_num` tinyint(4) NOT NULL,
			  PRIMARY KEY (`aff_campaign_id`)
			) ENGINE=MEMORY ;
			";
		$result = _mysqli_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_alerts` (
				  `prosper_alert_id` int(11) NOT NULL,
				  `prosper_alert_seen` tinyint(1) NOT NULL,
				  UNIQUE KEY `prosper_alert_id` (`prosper_alert_id`)
				) ENGINE=InnoDB ;";
		$result = _mysqli_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_offers` (
			  `user_id` mediumint(8) unsigned NOT NULL,
			  `offer_id` mediumint(10) unsigned NOT NULL,
			  `offer_seen` tinyint(1) NOT NULL DEFAULT '1',
			  UNIQUE KEY `user_id` (`user_id`,`offer_id`)
			) ENGINE=InnoDB ;";
		$result = _mysqli_query($sql);

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

		$sql ="CREATE TABLE IF NOT EXISTS `202_clicks_total` (
			  `click_count` int(20) unsigned NOT NULL default '0',
 			  PRIMARY KEY  (`click_count`)
			  ) ENGINE=InnoDB ;";
		$result = _mysqli_query($sql);

		$sql ="INSERT IGNORE INTO `202_pixel_types` (`pixel_type`) VALUES
				('Image'),
				('Iframe'),
				('Javascript'),
				('Postback'),
				('Raw');";
		$result = _mysqli_query($sql);

		$sql ="INSERT IGNORE INTO `202_device_types` (`type_id`, `type_name`)
				VALUES
					(1, 'Desktop'),
					(2, 'Mobile'),
					(3, 'Tablet'),
					(4, 'Bot');";
		$result = _mysqli_query($sql);

		$sql ="INSERT IGNORE INTO `202_clicks_total` (`click_count`) VALUES
			  (0);";
		$result = _mysqli_query($sql);

			
$sql="ALTER TABLE `202_tracking_c1` CHANGE COLUMN `c1` `c1` VARCHAR(350) NOT NULL;";
			$result = _mysqli_query($sql);
$sql="ALTER TABLE `202_tracking_c2` CHANGE COLUMN `c2` `c2` VARCHAR(350) NOT NULL;";
			$result = _mysqli_query($sql);
$sql="ALTER TABLE `202_tracking_c3` CHANGE COLUMN `c3` `c3` VARCHAR(350) NOT NULL;";
			$result = _mysqli_query($sql);
$sql="ALTER TABLE `202_tracking_c4` CHANGE COLUMN `c4` `c4` VARCHAR(350) NOT NULL;";
			$result = _mysqli_query($sql);

$sql="CREATE TABLE IF NOT EXISTS `202_api_keys` (
  `user_id` mediumint(8) unsigned NOT NULL,
  `api_key` varchar(250) NOT NULL DEFAULT '',
  `created_at` int(10) NOT NULL
) ENGINE=InnoDB ;";
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

			
	}


}
