<?php

class INSTALL {

	function install_databases() {

		$php_version = PROSPER202::php_version();

		//create the new mysql version table
		$sql = "CREATE TABLE IF NOT EXISTS `202_version` (
					  `version` varchar(50) NOT NULL
					) ENGINE=MyISAM DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

		//now add the what version this software is
		$sql = "INSERT INTO 202_version SET version='$php_version'";
		$result = _mysql_query($sql);

		//create sessions table
		$sql = "CREATE TABLE IF NOT EXISTS `202_sessions` (
				  `session_id` varchar(100) NOT NULL DEFAULT '',
				  `session_data` text NOT NULL,
				  `expires` int(11) NOT NULL DEFAULT '0',
				  PRIMARY KEY (`session_id`)
				) ENGINE=MyISAM DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

		$sql = "CREATE TABLE IF NOT EXISTS `202_cronjobs` (
				  `cronjob_type` char(5) NOT NULL,
				  `cronjob_time` int(11) NOT NULL,
				  KEY `cronjob_type` (`cronjob_type`,`cronjob_time`)
				) ENGINE=MYISAM DEFAULT CHARSET=latin1;"; 
		$result = _mysql_query($sql);

		$sql = "CREATE TABLE IF NOT EXISTS `202_mysql_errors` (
  `mysql_error_id` mediumint(8) unsigned NOT NULL auto_increment,
  `mysql_error_text` text NOT NULL,
  `mysql_error_sql` text NOT NULL,
  `user_id` mediumint(8) unsigned NOT NULL,
  `ip_id` bigint(20) unsigned NOT NULL,
  `mysql_error_time` int(10) unsigned NOT NULL,
  `site_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (`mysql_error_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

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
			) ENGINE=MyISAM DEFAULT CHARSET=latin1  ;";
		$result = _mysql_query($sql);

		//create users table
		$sql = "CREATE TABLE IF NOT EXISTS `202_users` (
  `user_id` mediumint(8) unsigned NOT NULL auto_increment,
  `user_name` varchar(50) NOT NULL,
  `user_pass` char(32) NOT NULL,
  `user_email` varchar(100) NOT NULL,
  `user_timezone` tinyint(3) NOT NULL default '-8',
  `user_time_register` int(10) unsigned NOT NULL,
  `user_pass_key` varchar(255) NOT NULL,
  `user_pass_time` int(10) unsigned NOT NULL,
  `user_api_key` varchar(255) NOT NULL,
  `user_stats202_app_key` varchar(255) NOT NULL,
  `user_last_login_ip_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (`user_id`),
  KEY `user_name` (`user_name`,`user_pass`),
  KEY `user_pass_key` (`user_pass_key`(5)),
  KEY `user_last_login_ip_id` (`user_last_login_ip_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1;
";
		$result = _mysql_query($sql);

		//create users table
		$sql = "CREATE TABLE `202_users_pref` (
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
  PRIMARY KEY (`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;
";
		$result = _mysql_query($sql);

		//create clicks_tracking table
		$sql = " CREATE TABLE `202_clicks_tracking` (
				  `click_id` bigint(20) unsigned NOT NULL,
				  `c1_id` bigint(20) NOT NULL,
				  `c2_id` bigint(20) NOT NULL,
				  `c3_id` bigint(20) NOT NULL,
				  `c4_id` bigint(20) NOT NULL,
				  PRIMARY KEY (`click_id`)
				) ENGINE=MyISAM DEFAULT CHARSET=latin1
		";
		$result = _mysql_query($sql);

		//create c1 table
		$sql = "CREATE TABLE `202_tracking_c1` (
		  `c1_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		  `c1` varchar(50) NOT NULL,
		  PRIMARY KEY (`c1_id`),
		  UNIQUE KEY `c1` (`c1`)
		) ENGINE=MyISAM AUTO_INCREMENT=1 DEFAULT CHARSET=latin1;
		";
		$result = _mysql_query($sql);

		//create c2 table
		$sql = "CREATE TABLE `202_tracking_c2` (
		  `c2_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		  `c2` varchar(50) NOT NULL,
		  PRIMARY KEY (`c2_id`),
		  UNIQUE KEY `c2` (`c2`)
		) ENGINE=MyISAM AUTO_INCREMENT=1 DEFAULT CHARSET=latin1;
		";
		$result = _mysql_query($sql);

		//create c3 table
		$sql = "CREATE TABLE `202_tracking_c3` (
		  `c3_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		  `c3` varchar(50) NOT NULL,
		  PRIMARY KEY (`c3_id`),
		  UNIQUE KEY `c3` (`c3`)
		) ENGINE=MyISAM AUTO_INCREMENT=1 DEFAULT CHARSET=latin1;
		";
		$result = _mysql_query($sql);

		//create c4 table
		$sql = "CREATE TABLE `202_tracking_c4` (
		  `c4_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		  `c4` varchar(50) NOT NULL,
		  PRIMARY KEY (`c4_id`),
		  UNIQUE KEY `c4` (`c4`)
		) ENGINE=MyISAM AUTO_INCREMENT=1 DEFAULT CHARSET=latin1;
		";
		$result = _mysql_query($sql);

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
				) ENGINE=MyISAM  DEFAULT CHARSET=latin1 ;";
		$result = _mysql_query($sql);



		$sql =" CREATE TABLE IF NOT EXISTS `202_export_campaigns` (
				  `export_session_id` mediumint(8) unsigned NOT NULL,
				  `export_campaign_id` mediumint(9) NOT NULL AUTO_INCREMENT,
				  `export_campaign_name` varchar(255) NOT NULL,
				  `export_campaign_status` tinyint(1) NOT NULL,
				  `export_campaign_daily_budget` decimal(10,2) unsigned NOT NULL,
				  PRIMARY KEY (`export_campaign_id`),
				  KEY `export_session_id` (`export_session_id`)
				) ENGINE=MyISAM  DEFAULT CHARSET=latin1 ;";
		$result = _mysql_query($sql);



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
				) ENGINE=MyISAM  DEFAULT CHARSET=latin1 ;";
		$result = _mysql_query($sql);


		$sql ="CREATE TABLE IF NOT EXISTS `202_export_sessions` (
				  `export_session_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
				  `export_session_id_public` varchar(255) NOT NULL,
				  `export_session_time` int(10) unsigned NOT NULL,
				  `export_session_ip` varchar(255) NOT NULL,
				  PRIMARY KEY (`export_session_id`),
				  KEY `session_id_public` (`export_session_id_public`(5))
				) ENGINE=MyISAM  DEFAULT CHARSET=latin1  ;";
		$result = _mysql_query($sql);


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
				) ENGINE=MyISAM  DEFAULT CHARSET=latin1 ;";
		$result = _mysql_query($sql);


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
				) ENGINE=MyISAM  DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

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
) ENGINE=MyISAM  DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_browsers` (
  `browser_id` tinyint(1) unsigned NOT NULL AUTO_INCREMENT,
  `browser_name` varchar(50) NOT NULL,
  PRIMARY KEY (`browser_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_charts` (
  `chart_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `chart_xml` mediumtext NOT NULL,
  PRIMARY KEY (`chart_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

		//this is partitioned from 2009-07-01 to 2011-07-01 for mysql 5.1 users
		//create the click table
		$sql ="CREATE TABLE IF NOT EXISTS `202_clicks` (
		  `click_id` bigint(20) unsigned NOT NULL,
		  `user_id` mediumint(8) unsigned NOT NULL,
		  `aff_campaign_id` mediumint(8) unsigned NOT NULL,
		  `landing_page_id` mediumint(8) unsigned NOT NULL,
		  `ppc_account_id` mediumint(8) unsigned NOT NULL,
		  `click_cpc` decimal(7,5) NOT NULL,
		  `click_payout` decimal(6,2) NOT NULL,
		  `click_lead` tinyint(1) NOT NULL default '0',
		  `click_filtered` tinyint(1) NOT NULL default '0',
		  `click_alp` tinyint(1) NOT NULL default '0',
		  `click_time` int(10) unsigned NOT NULL,
		  KEY `aff_campaign_id` (`aff_campaign_id`),
		  KEY `ppc_account_id` (`ppc_account_id`),
		  KEY `click_lead` (`click_lead`),
		  KEY `click_filtered` (`click_filtered`),
		  KEY `click_id` (`click_id`),
		  KEY `overview_index` (`user_id`,`click_filtered`,`aff_campaign_id`,`ppc_account_id`),
		  KEY `user_id` (`user_id`,`click_lead`),
		  KEY `click_alp` (`click_alp`),
		  KEY `landing_page_id` (`landing_page_id`),
		  KEY `overview_index2` (`user_id`,`click_filtered`,`landing_page_id`,`aff_campaign_id`)
		) ENGINE=MyISAM DEFAULT CHARSET=latin1";
		$result = _mysql_query($sql);

		//run the alter table to setup partioning if they have mysql 5.1 or greater
		$sql = "/*!50100 ALTER TABLE `202_clicks`
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
		$result = mysql_query($sql); #don't throw error if the partitioning doesn't work

		$sql ="CREATE TABLE IF NOT EXISTS `202_clicks_advance` (
  `click_id` bigint(20) unsigned NOT NULL,
  `text_ad_id` mediumint(8) unsigned NOT NULL,
  `keyword_id` bigint(20) unsigned NOT NULL,
  `ip_id` bigint(20) unsigned NOT NULL,
  `platform_id` tinyint(1) unsigned NOT NULL,
  `browser_id` tinyint(1) unsigned NOT NULL,
  PRIMARY KEY  (`click_id`),
  KEY `text_ad_id` (`text_ad_id`),
  KEY `keyword_id` (`keyword_id`),
  KEY `ip_id` (`ip_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_clicks_counter` (
  `click_id` bigint(20) unsigned NOT NULL auto_increment,
  PRIMARY KEY  (`click_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

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
) ENGINE=MyISAM DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_clicks_site` (
  `click_id` bigint(20) unsigned NOT NULL,
  `click_referer_site_url_id` bigint(20) unsigned NOT NULL,
  `click_landing_site_url_id` bigint(20) unsigned NOT NULL,
  `click_outbound_site_url_id` bigint(20) unsigned NOT NULL,
  `click_cloaking_site_url_id` bigint(20) unsigned NOT NULL,
  `click_redirect_site_url_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (`click_id`),
  KEY `click_referer_site_url_id` (`click_referer_site_url_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

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
  KEY `overview_index2` (`user_id`,`click_filtered`,`landing_page_id`,`aff_campaign_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_delayed_sqls` (
  `delayed_sql` text NOT NULL,
  `delayed_time` int(10) unsigned NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_ips` (
  `ip_id` bigint(20) unsigned NOT NULL auto_increment,
  `ip_address` varchar(15) NOT NULL,
  `location_id` mediumint(8) unsigned NOT NULL,
  PRIMARY KEY  (`ip_id`),
  KEY `ip_address` (`ip_address`),
  KEY `location_id` (`location_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_keywords` (
  `keyword_id` bigint(20) unsigned NOT NULL auto_increment,
  `keyword` varchar(50) NOT NULL,
  PRIMARY KEY  (`keyword_id`),
  KEY `keyword` (`keyword`(10))
) ENGINE=MyISAM  DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

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
) ENGINE=MyISAM  DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

		$sql ="
CREATE TABLE IF NOT EXISTS `202_last_ips` (
  `user_id` mediumint(9) NOT NULL,
  `ip_id` bigint(20) NOT NULL,
  `time` int(10) unsigned NOT NULL,
  KEY `ip_index` (`user_id`,`ip_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_locations` (
  `location_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `location_country_id` tinyint(3) unsigned NOT NULL,
  `location_region_id` mediumint(8) unsigned NOT NULL,
  `location_city_id` mediumint(8) unsigned NOT NULL,
  `location_coordinate_id` mediumint(8) unsigned NOT NULL,
  PRIMARY KEY (`location_id`),
  KEY `location_country_id` (`location_country_id`),
  KEY `location_region_id` (`location_region_id`),
  KEY `location_city_id` (`location_city_id`),
  KEY `location_coordinate_id` (`location_coordinate_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_locations_block` (
  `location_id` mediumint(8) unsigned NOT NULL,
  `location_block_ip_from` int(11) NOT NULL,
  `location_block_ip_to` int(11) NOT NULL,
  KEY `location_block_ip_from` (`location_block_ip_from`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1";
		$result = _mysql_query($sql);

		$sql ="/*!50100 ALTER TABLE `202_locations_block` PARTITION BY RANGE (
location_block_ip_to) (PARTITION p1 VALUES LESS THAN (33996344) ENGINE = MyISAM, PARTITION p2 VALUES LESS THAN (68519168) ENGINE = MyISAM, PARTITION p3 VALUES LESS THAN (69913184) ENGINE = MyISAM, PARTITION p4 VALUES LESS THAN (76081152) ENGINE = MyISAM, PARTITION p5 VALUES LESS THAN (78118912) ENGINE = MyISAM, PARTITION p6 VALUES LESS THAN (79617920) ENGINE = MyISAM, PARTITION p7 VALUES LESS THAN (83693568) ENGINE = MyISAM, PARTITION p8 VALUES LESS THAN (201658472) ENGINE = MyISAM, PARTITION p9 VALUES LESS THAN (202022048) ENGINE = MyISAM, PARTITION p10 VALUES LESS THAN (202511104) ENGINE = MyISAM, PARTITION p11 VALUES LESS THAN (202796544) ENGINE = MyISAM, PARTITION p12 VALUES LESS THAN (203023104) ENGINE = MyISAM, PARTITION p13 VALUES LESS THAN (203271936) ENGINE = MyISAM, PARTITION p14 VALUES LESS THAN (203539584) ENGINE = MyISAM, PARTITION p15 VALUES LESS THAN (203747328) ENGINE = MyISAM, PARTITION p16 VALUES LESS THAN (203998888) ENGINE = MyISAM, PARTITION p17 VALUES LESS THAN (204299896) ENGINE = MyISAM, PARTITION p18 VALUES LESS THAN (204594112) ENGINE = MyISAM, PARTITION p19 VALUES LESS THAN (207635336) ENGINE = MyISAM, PARTITION p20 VALUES LESS THAN (208257408) ENGINE = MyISAM, PARTITION p21 VALUES LESS THAN (208564224) ENGINE = MyISAM, PARTITION p22 VALUES LESS THAN (209846272) ENGINE = MyISAM, PARTITION p23 VALUES LESS THAN (210953792) ENGINE = MyISAM, PARTITION p24 VALUES LESS THAN (211248128) ENGINE = MyISAM, PARTITION p25 VALUES LESS THAN (211559872) ENGINE = MyISAM, PARTITION p26 VALUES LESS THAN (211852544) ENGINE = MyISAM, PARTITION p27 VALUES LESS THAN (212234016) ENGINE = MyISAM, PARTITION p28 VALUES LESS THAN (212541888) ENGINE = MyISAM, PARTITION p29 VALUES LESS THAN (212858800) ENGINE = MyISAM, PARTITION p30 VALUES LESS THAN (213200024) ENGINE = MyISAM, PARTITION p31 VALUES LESS THAN (213419416) ENGINE = MyISAM, PARTITION p32 VALUES LESS THAN (213544352) ENGINE = MyISAM, PARTITION p33 VALUES LESS THAN (213714784) ENGINE = MyISAM, PARTITION p34 VALUES LESS THAN (213905664) ENGINE = MyISAM, PARTITION p35 VALUES LESS THAN (214194416) ENGINE = MyISAM, PARTITION p36 VALUES LESS THAN (214427904) ENGINE = MyISAM, PARTITION p37 VALUES LESS THAN (215457024) ENGINE = MyISAM, PARTITION p38 VALUES LESS THAN (298127104) ENGINE = MyISAM, PARTITION p39 VALUES LESS THAN (403695616) ENGINE = MyISAM, PARTITION p40 VALUES LESS THAN (405223168) ENGINE = MyISAM, PARTITION p41 VALUES LESS THAN (406192384) ENGINE = MyISAM, PARTITION p42 VALUES LESS THAN (407838720) ENGINE = MyISAM, PARTITION p43 VALUES LESS THAN (410059776) ENGINE = MyISAM, PARTITION p44 VALUES LESS THAN (411316736) ENGINE = MyISAM, PARTITION p45 VALUES LESS THAN (413132544) ENGINE = MyISAM, PARTITION p46 VALUES LESS THAN (414541824) ENGINE = MyISAM, PARTITION p47 VALUES LESS THAN (415904256) ENGINE = MyISAM, PARTITION p48 VALUES LESS THAN (417339136) ENGINE = MyISAM, PARTITION p49 VALUES LESS THAN (418584064) ENGINE = MyISAM, PARTITION p50 VALUES LESS THAN (645266432) ENGINE = MyISAM, PARTITION p51 VALUES LESS THAN (974388448) ENGINE = MyISAM, PARTITION p52 VALUES LESS THAN (983828352) ENGINE = MyISAM, PARTITION p53 VALUES LESS THAN (986929728) ENGINE = MyISAM, PARTITION p54 VALUES LESS THAN (990090944) ENGINE = MyISAM, PARTITION p55 VALUES LESS THAN (996429568) ENGINE = MyISAM, PARTITION p56 VALUES LESS THAN (999948288) ENGINE = MyISAM, PARTITION p57 VALUES LESS THAN (1000006344) ENGINE = MyISAM, PARTITION p58 VALUES LESS THAN (1008756112) ENGINE = MyISAM, PARTITION p59 VALUES LESS THAN (1019090624) ENGINE = MyISAM, PARTITION p60 VALUES LESS THAN (1019116688) ENGINE = MyISAM, PARTITION p61 VALUES LESS THAN (1019139656) ENGINE = MyISAM, PARTITION p62 VALUES LESS THAN (1019193264) ENGINE = MyISAM, PARTITION p63 VALUES LESS THAN (1020284032) ENGINE = MyISAM, PARTITION p64 VALUES LESS THAN (1020815544) ENGINE = MyISAM, PARTITION p65 VALUES LESS THAN (1023950892) ENGINE = MyISAM, PARTITION p66 VALUES LESS THAN (1025458496) ENGINE = MyISAM, PARTITION p67 VALUES LESS THAN (1027127808) ENGINE = MyISAM, PARTITION p68 VALUES LESS THAN (1027820728) ENGINE = MyISAM, PARTITION p69 VALUES LESS THAN (1029590208) ENGINE = MyISAM, PARTITION p70 VALUES LESS THAN (1031275352) ENGINE = MyISAM, PARTITION p71 VALUES LESS THAN (1031950208) ENGINE = MyISAM, PARTITION p72 VALUES LESS THAN (1032200224) ENGINE = MyISAM, PARTITION p73 VALUES LESS THAN (1032833752) ENGINE = MyISAM, PARTITION p74 VALUES LESS THAN (1033441000) ENGINE = MyISAM, PARTITION p75 VALUES LESS THAN (1033491948) ENGINE = MyISAM, PARTITION p76 VALUES LESS THAN (1033980160) ENGINE = MyISAM, PARTITION p77 VALUES LESS THAN (1034913424) ENGINE = MyISAM, PARTITION p78 VALUES LESS THAN (1035539712) ENGINE = MyISAM, PARTITION p79 VALUES LESS THAN (1036291736) ENGINE = MyISAM, PARTITION p80 VALUES LESS THAN (1036769600) ENGINE = MyISAM, PARTITION p81 VALUES LESS THAN (1037729832) ENGINE = MyISAM, PARTITION p82 VALUES LESS THAN (1037861984) ENGINE = MyISAM, PARTITION p83 VALUES LESS THAN (1037965712) ENGINE = MyISAM, PARTITION p84 VALUES LESS THAN (1040187392) ENGINE = MyISAM, PARTITION p85 VALUES LESS THAN (1040473344) ENGINE = MyISAM, PARTITION p86 VALUES LESS THAN (1040760920) ENGINE = MyISAM, PARTITION p87 VALUES LESS THAN (1041042416) ENGINE = MyISAM, PARTITION p88 VALUES LESS THAN (1041527840) ENGINE = MyISAM, PARTITION p89 VALUES LESS THAN (1041726080) ENGINE = MyISAM, PARTITION p90 VALUES LESS THAN (1042249472) ENGINE = MyISAM, PARTITION p91 VALUES LESS THAN (1042884528) ENGINE = MyISAM, PARTITION p92 VALUES LESS THAN (1042905200) ENGINE = MyISAM, PARTITION p93 VALUES LESS THAN (1042926624) ENGINE = MyISAM, PARTITION p94 VALUES LESS THAN (1042939536) ENGINE = MyISAM, PARTITION p95 VALUES LESS THAN (1043391296) ENGINE = MyISAM, PARTITION p96 VALUES LESS THAN (1043446528) ENGINE = MyISAM, PARTITION p97 VALUES LESS THAN (1044003864) ENGINE = MyISAM, PARTITION p98 VALUES LESS THAN (1044474320) ENGINE = MyISAM, PARTITION p99 VALUES LESS THAN (1045026832) ENGINE = MyISAM, PARTITION p100 VALUES LESS THAN (1045423136) ENGINE = MyISAM, PARTITION p101 VALUES LESS THAN (1046046640) ENGINE = MyISAM, PARTITION p102 VALUES LESS THAN (1046356224) ENGINE = MyISAM, PARTITION p103 VALUES LESS THAN (1046402120) ENGINE = MyISAM, PARTITION p104 VALUES LESS THAN (1046512568) ENGINE = MyISAM, PARTITION p105 VALUES LESS THAN (1046724760) ENGINE = MyISAM, PARTITION p106 VALUES LESS THAN (1047057920) ENGINE = MyISAM, PARTITION p107 VALUES LESS THAN (1047409472) ENGINE = MyISAM, PARTITION p108 VALUES LESS THAN (1047440672) ENGINE = MyISAM, PARTITION p109 VALUES LESS THAN (1047552108) ENGINE = MyISAM, PARTITION p110 VALUES LESS THAN (1047998560) ENGINE = MyISAM, PARTITION p111 VALUES LESS THAN (1048295856) ENGINE = MyISAM, PARTITION p112 VALUES LESS THAN (1048843384) ENGINE = MyISAM, PARTITION p113 VALUES LESS THAN (1048859704) ENGINE = MyISAM, PARTITION p114 VALUES LESS THAN (1048875488) ENGINE = MyISAM, PARTITION p115 VALUES LESS THAN (1049055232) ENGINE = MyISAM, PARTITION p116 VALUES LESS THAN (1049293920) ENGINE = MyISAM, PARTITION p117 VALUES LESS THAN (1049763088) ENGINE = MyISAM, PARTITION p118 VALUES LESS THAN (1050234184) ENGINE = MyISAM, PARTITION p119 VALUES LESS THAN (1050267992) ENGINE = MyISAM, PARTITION p120 VALUES LESS THAN (1050339952) ENGINE = MyISAM, PARTITION p121 VALUES LESS THAN (1050513664) ENGINE = MyISAM, PARTITION p122 VALUES LESS THAN (1050633928) ENGINE = MyISAM, PARTITION p123 VALUES LESS THAN (1050677760) ENGINE = MyISAM, PARTITION p124 VALUES LESS THAN (1050697260) ENGINE = MyISAM, PARTITION p125 VALUES LESS THAN (1050711673) ENGINE = MyISAM, PARTITION p126 VALUES LESS THAN (1050758597) ENGINE = MyISAM, PARTITION p127 VALUES LESS THAN (1050772024) ENGINE = MyISAM, PARTITION p128 VALUES LESS THAN (1050798430) ENGINE = MyISAM, PARTITION p129 VALUES LESS THAN (1051212208) ENGINE = MyISAM, PARTITION p130 VALUES LESS THAN (1051409184) ENGINE = MyISAM, PARTITION p131 VALUES LESS THAN (1051544662) ENGINE = MyISAM, PARTITION p132 VALUES LESS THAN (1051832696) ENGINE = MyISAM, PARTITION p133 VALUES LESS THAN (1052110432) ENGINE = MyISAM, PARTITION p134 VALUES LESS THAN (1052462016) ENGINE = MyISAM, PARTITION p135 VALUES LESS THAN (1052621888) ENGINE = MyISAM, PARTITION p136 VALUES LESS THAN (1052972800) ENGINE = MyISAM, PARTITION p137 VALUES LESS THAN (1053709040) ENGINE = MyISAM, PARTITION p138 VALUES LESS THAN (1053986992) ENGINE = MyISAM, PARTITION p139 VALUES LESS THAN (1054638464) ENGINE = MyISAM, PARTITION p140 VALUES LESS THAN (1054971344) ENGINE = MyISAM, PARTITION p141 VALUES LESS THAN (1055490568) ENGINE = MyISAM, PARTITION p142 VALUES LESS THAN (1055779856) ENGINE = MyISAM, PARTITION p143 VALUES LESS THAN (1056113112) ENGINE = MyISAM, PARTITION p144 VALUES LESS THAN (1056321792) ENGINE = MyISAM, PARTITION p145 VALUES LESS THAN (1058114816) ENGINE = MyISAM, PARTITION p146 VALUES LESS THAN (1059689472) ENGINE = MyISAM, PARTITION p147 VALUES LESS THAN (1061312512) ENGINE = MyISAM, PARTITION p148 VALUES LESS THAN (1061552640) ENGINE = MyISAM, PARTITION p149 VALUES LESS THAN (1061764128) ENGINE = MyISAM, PARTITION p150 VALUES LESS THAN (1061954992) ENGINE = MyISAM, PARTITION p151 VALUES LESS THAN (1062099136) ENGINE = MyISAM, PARTITION p152 VALUES LESS THAN (1062263576) ENGINE = MyISAM, PARTITION p153 VALUES LESS THAN (1062444920) ENGINE = MyISAM, PARTITION p154 VALUES LESS THAN (1062591584) ENGINE = MyISAM, PARTITION p155 VALUES LESS THAN (1062698240) ENGINE = MyISAM, PARTITION p156 VALUES LESS THAN (1062854528) ENGINE = MyISAM, PARTITION p157 VALUES LESS THAN (1063092736) ENGINE = MyISAM, PARTITION p158 VALUES LESS THAN (1063311360) ENGINE = MyISAM, PARTITION p159 VALUES LESS THAN (1063426848) ENGINE = MyISAM, PARTITION p160 VALUES LESS THAN (1063553088) ENGINE = MyISAM, PARTITION p161 VALUES LESS THAN (1063789120) ENGINE = MyISAM, PARTITION p162 VALUES LESS THAN (1064057464) ENGINE = MyISAM, PARTITION p163 VALUES LESS THAN (1064232320) ENGINE = MyISAM, PARTITION p164 VALUES LESS THAN (1064328720) ENGINE = MyISAM, PARTITION p165 VALUES LESS THAN (1064517568) ENGINE = MyISAM, PARTITION p166 VALUES LESS THAN (1064663968) ENGINE = MyISAM, PARTITION p167 VALUES LESS THAN (1064798208) ENGINE = MyISAM, PARTITION p168 VALUES LESS THAN (1065000832) ENGINE = MyISAM, PARTITION p169 VALUES LESS THAN (1065159488) ENGINE = MyISAM, PARTITION p170 VALUES LESS THAN (1065338112) ENGINE = MyISAM, PARTITION p171 VALUES LESS THAN (1066064536) ENGINE = MyISAM, PARTITION p172 VALUES LESS THAN (1066130176) ENGINE = MyISAM, PARTITION p173 VALUES LESS THAN (1066521584) ENGINE = MyISAM, PARTITION p174 VALUES LESS THAN (1066687680) ENGINE = MyISAM, PARTITION p175 VALUES LESS THAN (1066822240) ENGINE = MyISAM, PARTITION p176 VALUES LESS THAN (1067537248) ENGINE = MyISAM, PARTITION p177 VALUES LESS THAN (1067847040) ENGINE = MyISAM, PARTITION p178 VALUES LESS THAN (1068091648) ENGINE = MyISAM, PARTITION p179 VALUES LESS THAN (1068361216) ENGINE = MyISAM, PARTITION p180 VALUES LESS THAN (1069681848) ENGINE = MyISAM, PARTITION p181 VALUES LESS THAN (1069835776) ENGINE = MyISAM, PARTITION p182 VALUES LESS THAN (1069903272) ENGINE = MyISAM, PARTITION p183 VALUES LESS THAN (1069951560) ENGINE = MyISAM, PARTITION p184 VALUES LESS THAN (1070003040) ENGINE = MyISAM, PARTITION p185 VALUES LESS THAN (1070052976) ENGINE = MyISAM, PARTITION p186 VALUES LESS THAN (1070105264) ENGINE = MyISAM, PARTITION p187 VALUES LESS THAN (1070183512) ENGINE = MyISAM, PARTITION p188 VALUES LESS THAN (1070255104) ENGINE = MyISAM, PARTITION p189 VALUES LESS THAN (1070306664) ENGINE = MyISAM, PARTITION p190 VALUES LESS THAN (1070365496) ENGINE = MyISAM, PARTITION p191 VALUES LESS THAN (1070443360) ENGINE = MyISAM, PARTITION p192 VALUES LESS THAN (1070502400) ENGINE = MyISAM, PARTITION p193 VALUES LESS THAN (1070592072) ENGINE = MyISAM, PARTITION p194 VALUES LESS THAN (1071673912) ENGINE = MyISAM, PARTITION p195 VALUES LESS THAN (1071745656) ENGINE = MyISAM, PARTITION p196 VALUES LESS THAN (1071811904) ENGINE = MyISAM, PARTITION p197 VALUES LESS THAN (1071930768) ENGINE = MyISAM, PARTITION p198 VALUES LESS THAN (1072072544) ENGINE = MyISAM, PARTITION p199 VALUES LESS THAN (1072128776) ENGINE = MyISAM, PARTITION p200 VALUES LESS THAN (1072445616) ENGINE = MyISAM, PARTITION p201 VALUES LESS THAN (1072543840) ENGINE = MyISAM, PARTITION p202 VALUES LESS THAN (1072724864) ENGINE = MyISAM, PARTITION p203 VALUES LESS THAN (1072897280) ENGINE = MyISAM, PARTITION p204 VALUES LESS THAN (1073378768) ENGINE = MyISAM, PARTITION p205 VALUES LESS THAN (1073523160) ENGINE = MyISAM, PARTITION p206 VALUES LESS THAN (1073619968) ENGINE = MyISAM, PARTITION p207 VALUES LESS THAN (1073722448) ENGINE = MyISAM, PARTITION p208 VALUES LESS THAN (1074273536) ENGINE = MyISAM, PARTITION p209 VALUES LESS THAN (1074875904) ENGINE = MyISAM, PARTITION p210 VALUES LESS THAN (1075490320) ENGINE = MyISAM, PARTITION p211 VALUES LESS THAN (1075899520) ENGINE = MyISAM, PARTITION p212 VALUES LESS THAN (1076113608) ENGINE = MyISAM, PARTITION p213 VALUES LESS THAN (1076158784) ENGINE = MyISAM, PARTITION p214 VALUES LESS THAN (1076504576) ENGINE = MyISAM, PARTITION p215 VALUES LESS THAN (1076777512) ENGINE = MyISAM, PARTITION p216 VALUES LESS THAN (1077030976) ENGINE = MyISAM, PARTITION p217 VALUES LESS THAN (1077116824) ENGINE = MyISAM, PARTITION p218 VALUES LESS THAN (1077167776) ENGINE = MyISAM, PARTITION p219 VALUES LESS THAN (1077372816) ENGINE = MyISAM, PARTITION p220 VALUES LESS THAN (1077651200) ENGINE = MyISAM, PARTITION p221 VALUES LESS THAN (1077813248) ENGINE = MyISAM, PARTITION p222 VALUES LESS THAN (1078040064) ENGINE = MyISAM, PARTITION p223 VALUES LESS THAN (1078292696) ENGINE = MyISAM, PARTITION p224 VALUES LESS THAN (1078622208) ENGINE = MyISAM, PARTITION p225 VALUES LESS THAN (1078883584) ENGINE = MyISAM, PARTITION p226 VALUES LESS THAN (1079190520) ENGINE = MyISAM, PARTITION p227 VALUES LESS THAN (1079707008) ENGINE = MyISAM, PARTITION p228 VALUES LESS THAN (1079956992) ENGINE = MyISAM, PARTITION p229 VALUES LESS THAN (1080824832) ENGINE = MyISAM, PARTITION p230 VALUES LESS THAN (1080909224) ENGINE = MyISAM, PARTITION p231 VALUES LESS THAN (1080942296) ENGINE = MyISAM, PARTITION p232 VALUES LESS THAN (1081445376) ENGINE = MyISAM, PARTITION p233 VALUES LESS THAN (1081832664) ENGINE = MyISAM, PARTITION p234 VALUES LESS THAN (1081893320) ENGINE = MyISAM, PARTITION p235 VALUES LESS THAN (1082389504) ENGINE = MyISAM, PARTITION p236 VALUES LESS THAN (1082876672) ENGINE = MyISAM, PARTITION p237 VALUES LESS THAN (1083255040) ENGINE = MyISAM, PARTITION p238 VALUES LESS THAN (1083417904) ENGINE = MyISAM, PARTITION p239 VALUES LESS THAN (1084236816) ENGINE = MyISAM, PARTITION p240 VALUES LESS THAN (1084381752) ENGINE = MyISAM, PARTITION p241 VALUES LESS THAN (1084505904) ENGINE = MyISAM, PARTITION p242 VALUES LESS THAN (1084654656) ENGINE = MyISAM, PARTITION p243 VALUES LESS THAN (1084784808) ENGINE = MyISAM, PARTITION p244 VALUES LESS THAN (1084953600) ENGINE = MyISAM, PARTITION p245 VALUES LESS THAN (1085143568) ENGINE = MyISAM, PARTITION p246 VALUES LESS THAN (1085614704) ENGINE = MyISAM, PARTITION p247 VALUES LESS THAN (1085946624) ENGINE = MyISAM, PARTITION p248 VALUES LESS THAN (1086751488) ENGINE = MyISAM, PARTITION p249 VALUES LESS THAN (1086821960) ENGINE = MyISAM, PARTITION p250 VALUES LESS THAN (1087112424) ENGINE = MyISAM, PARTITION p251 VALUES LESS THAN (1087151760) ENGINE = MyISAM, PARTITION p252 VALUES LESS THAN (1087188704) ENGINE = MyISAM, PARTITION p253 VALUES LESS THAN (1087222992) ENGINE = MyISAM, PARTITION p254 VALUES LESS THAN (1087749632) ENGINE = MyISAM, PARTITION p255 VALUES LESS THAN (1087970376) ENGINE = MyISAM, PARTITION p256 VALUES LESS THAN (1088049968) ENGINE = MyISAM, PARTITION p257 VALUES LESS THAN (1088108240) ENGINE = MyISAM, PARTITION p258 VALUES LESS THAN (1088220160) ENGINE = MyISAM, PARTITION p259 VALUES LESS THAN (1088967632) ENGINE = MyISAM, PARTITION p260 VALUES LESS THAN (1089445120) ENGINE = MyISAM, PARTITION p261 VALUES LESS THAN (1089654608) ENGINE = MyISAM, PARTITION p262 VALUES LESS THAN (1090002928) ENGINE = MyISAM, PARTITION p263 VALUES LESS THAN (1090054576) ENGINE = MyISAM, PARTITION p264 VALUES LESS THAN (1090096432) ENGINE = MyISAM, PARTITION p265 VALUES LESS THAN (1090348208) ENGINE = MyISAM, PARTITION p266 VALUES LESS THAN (1091389440) ENGINE = MyISAM, PARTITION p267 VALUES LESS THAN (1091623264) ENGINE = MyISAM, PARTITION p268 VALUES LESS THAN (1092043392) ENGINE = MyISAM, PARTITION p269 VALUES LESS THAN (1093114824) ENGINE = MyISAM, PARTITION p270 VALUES LESS THAN (1093304016) ENGINE = MyISAM, PARTITION p271 VALUES LESS THAN (1093354704) ENGINE = MyISAM, PARTITION p272 VALUES LESS THAN (1093666784) ENGINE = MyISAM, PARTITION p273 VALUES LESS THAN (1094557792) ENGINE = MyISAM, PARTITION p274 VALUES LESS THAN (1094775200) ENGINE = MyISAM, PARTITION p275 VALUES LESS THAN (1094831208) ENGINE = MyISAM, PARTITION p276 VALUES LESS THAN (1094887984) ENGINE = MyISAM, PARTITION p277 VALUES LESS THAN (1094950912) ENGINE = MyISAM, PARTITION p278 VALUES LESS THAN (1095034328) ENGINE = MyISAM, PARTITION p279 VALUES LESS THAN (1095141712) ENGINE = MyISAM, PARTITION p280 VALUES LESS THAN (1095354624) ENGINE = MyISAM, PARTITION p281 VALUES LESS THAN (1095974400) ENGINE = MyISAM, PARTITION p282 VALUES LESS THAN (1096057280) ENGINE = MyISAM, PARTITION p283 VALUES LESS THAN (1096090848) ENGINE = MyISAM, PARTITION p284 VALUES LESS THAN (1096126056) ENGINE = MyISAM, PARTITION p285 VALUES LESS THAN (1096169728) ENGINE = MyISAM, PARTITION p286 VALUES LESS THAN (1096208096) ENGINE = MyISAM, PARTITION p287 VALUES LESS THAN (1096419776) ENGINE = MyISAM, PARTITION p288 VALUES LESS THAN (1096673680) ENGINE = MyISAM, PARTITION p289 VALUES LESS THAN (1097075216) ENGINE = MyISAM, PARTITION p290 VALUES LESS THAN (1097140648) ENGINE = MyISAM, PARTITION p291 VALUES LESS THAN (1097200672) ENGINE = MyISAM, PARTITION p292 VALUES LESS THAN (1097264608) ENGINE = MyISAM, PARTITION p293 VALUES LESS THAN (1097377024) ENGINE = MyISAM, PARTITION p294 VALUES LESS THAN (1097878304) ENGINE = MyISAM, PARTITION p295 VALUES LESS THAN (1098009056) ENGINE = MyISAM, PARTITION p296 VALUES LESS THAN (1098127968) ENGINE = MyISAM, PARTITION p297 VALUES LESS THAN (1098230816) ENGINE = MyISAM, PARTITION p298 VALUES LESS THAN (1098387296) ENGINE = MyISAM, PARTITION p299 VALUES LESS THAN (1098508408) ENGINE = MyISAM, PARTITION p300 VALUES LESS THAN (1098640160) ENGINE = MyISAM, PARTITION p301 VALUES LESS THAN (1098816512) ENGINE = MyISAM, PARTITION p302 VALUES LESS THAN (1099739904) ENGINE = MyISAM, PARTITION p303 VALUES LESS THAN (1100448128) ENGINE = MyISAM, PARTITION p304 VALUES LESS THAN (1101253328) ENGINE = MyISAM, PARTITION p305 VALUES LESS THAN (1101559096) ENGINE = MyISAM, PARTITION p306 VALUES LESS THAN (1101824000) ENGINE = MyISAM, PARTITION p307 VALUES LESS THAN (1102965760) ENGINE = MyISAM, PARTITION p308 VALUES LESS THAN (1103229808) ENGINE = MyISAM, PARTITION p309 VALUES LESS THAN (1103387768) ENGINE = MyISAM, PARTITION p310 VALUES LESS THAN (1103489408) ENGINE = MyISAM, PARTITION p311 VALUES LESS THAN (1103609672) ENGINE = MyISAM, PARTITION p312 VALUES LESS THAN (1103707504) ENGINE = MyISAM, PARTITION p313 VALUES LESS THAN (1103780864) ENGINE = MyISAM, PARTITION p314 VALUES LESS THAN (1103855584) ENGINE = MyISAM, PARTITION p315 VALUES LESS THAN (1103942056) ENGINE = MyISAM, PARTITION p316 VALUES LESS THAN (1104026912) ENGINE = MyISAM, PARTITION p317 VALUES LESS THAN (1104114016) ENGINE = MyISAM, PARTITION p318 VALUES LESS THAN (1104204184) ENGINE = MyISAM, PARTITION p319 VALUES LESS THAN (1104291808) ENGINE = MyISAM, PARTITION p320 VALUES LESS THAN (1104363952) ENGINE = MyISAM, PARTITION p321 VALUES LESS THAN (1104446208) ENGINE = MyISAM, PARTITION p322 VALUES LESS THAN (1104540064) ENGINE = MyISAM, PARTITION p323 VALUES LESS THAN (1104634112) ENGINE = MyISAM, PARTITION p324 VALUES LESS THAN (1104752016) ENGINE = MyISAM, PARTITION p325 VALUES LESS THAN (1104851672) ENGINE = MyISAM, PARTITION p326 VALUES LESS THAN (1104943456) ENGINE = MyISAM, PARTITION p327 VALUES LESS THAN (1105094528) ENGINE = MyISAM, PARTITION p328 VALUES LESS THAN (1105173176) ENGINE = MyISAM, PARTITION p329 VALUES LESS THAN (1106307840) ENGINE = MyISAM, PARTITION p330 VALUES LESS THAN (1106406912) ENGINE = MyISAM, PARTITION p331 VALUES LESS THAN (1106485160) ENGINE = MyISAM, PARTITION p332 VALUES LESS THAN (1106568016) ENGINE = MyISAM, PARTITION p333 VALUES LESS THAN (1106734632) ENGINE = MyISAM, PARTITION p334 VALUES LESS THAN (1107282112) ENGINE = MyISAM, PARTITION p335 VALUES LESS THAN (1107315184) ENGINE = MyISAM, PARTITION p336 VALUES LESS THAN (1107339488) ENGINE = MyISAM, PARTITION p337 VALUES LESS THAN (1107357704) ENGINE = MyISAM, PARTITION p338 VALUES LESS THAN (1107820504) ENGINE = MyISAM, PARTITION p339 VALUES LESS THAN (1108185856) ENGINE = MyISAM, PARTITION p340 VALUES LESS THAN (1108503520) ENGINE = MyISAM, PARTITION p341 VALUES LESS THAN (1109627960) ENGINE = MyISAM, PARTITION p342 VALUES LESS THAN (1109835520) ENGINE = MyISAM, PARTITION p343 VALUES LESS THAN (1110287360) ENGINE = MyISAM, PARTITION p344 VALUES LESS THAN (1110770216) ENGINE = MyISAM, PARTITION p345 VALUES LESS THAN (1110994176) ENGINE = MyISAM, PARTITION p346 VALUES LESS THAN (1111380224) ENGINE = MyISAM, PARTITION p347 VALUES LESS THAN (1111544832) ENGINE = MyISAM, PARTITION p348 VALUES LESS THAN (1112059136) ENGINE = MyISAM, PARTITION p349 VALUES LESS THAN (1112103488) ENGINE = MyISAM, PARTITION p350 VALUES LESS THAN (1112356352) ENGINE = MyISAM, PARTITION p351 VALUES LESS THAN (1112847680) ENGINE = MyISAM, PARTITION p352 VALUES LESS THAN (1113413140) ENGINE = MyISAM, PARTITION p353 VALUES LESS THAN (1113882360) ENGINE = MyISAM, PARTITION p354 VALUES LESS THAN (1114486768) ENGINE = MyISAM, PARTITION p355 VALUES LESS THAN (1114928720) ENGINE = MyISAM, PARTITION p356 VALUES LESS THAN (1115217072) ENGINE = MyISAM, PARTITION p357 VALUES LESS THAN (1115390784) ENGINE = MyISAM, PARTITION p358 VALUES LESS THAN (1115586288) ENGINE = MyISAM, PARTITION p359 VALUES LESS THAN (1115782608) ENGINE = MyISAM, PARTITION p360 VALUES LESS THAN (1116253464) ENGINE = MyISAM, PARTITION p361 VALUES LESS THAN (1116369944) ENGINE = MyISAM, PARTITION p362 VALUES LESS THAN (1116482168) ENGINE = MyISAM, PARTITION p363 VALUES LESS THAN (1116615168) ENGINE = MyISAM, PARTITION p364 VALUES LESS THAN (1116975104) ENGINE = MyISAM, PARTITION p365 VALUES LESS THAN (1117324320) ENGINE = MyISAM, PARTITION p366 VALUES LESS THAN (1117749744) ENGINE = MyISAM, PARTITION p367 VALUES LESS THAN (1117960960) ENGINE = MyISAM, PARTITION p368 VALUES LESS THAN (1118510080) ENGINE = MyISAM, PARTITION p369 VALUES LESS THAN (1118699248) ENGINE = MyISAM, PARTITION p370 VALUES LESS THAN (1119100160) ENGINE = MyISAM, PARTITION p371 VALUES LESS THAN (1119359520) ENGINE = MyISAM, PARTITION p372 VALUES LESS THAN (1119397232) ENGINE = MyISAM, PARTITION p373 VALUES LESS THAN (1119418752) ENGINE = MyISAM, PARTITION p374 VALUES LESS THAN (1119966256) ENGINE = MyISAM, PARTITION p375 VALUES LESS THAN (1120419840) ENGINE = MyISAM, PARTITION p376 VALUES LESS THAN (1120961416) ENGINE = MyISAM, PARTITION p377 VALUES LESS THAN (1121311600) ENGINE = MyISAM, PARTITION p378 VALUES LESS THAN (1121534720) ENGINE = MyISAM, PARTITION p379 VALUES LESS THAN (1121997608) ENGINE = MyISAM, PARTITION p380 VALUES LESS THAN (1122164736) ENGINE = MyISAM, PARTITION p381 VALUES LESS THAN (1122757328) ENGINE = MyISAM, PARTITION p382 VALUES LESS THAN (1123255056) ENGINE = MyISAM, PARTITION p383 VALUES LESS THAN (1123780464) ENGINE = MyISAM, PARTITION p384 VALUES LESS THAN (1124050560) ENGINE = MyISAM, PARTITION p385 VALUES LESS THAN (1125554688) ENGINE = MyISAM, PARTITION p386 VALUES LESS THAN (1126456320) ENGINE = MyISAM, PARTITION p387 VALUES LESS THAN (1126486360) ENGINE = MyISAM, PARTITION p388 VALUES LESS THAN (1126581232) ENGINE = MyISAM, PARTITION p389 VALUES LESS THAN (1126668816) ENGINE = MyISAM, PARTITION p390 VALUES LESS THAN (1126826496) ENGINE = MyISAM, PARTITION p391 VALUES LESS THAN (1127526144) ENGINE = MyISAM, PARTITION p392 VALUES LESS THAN (1128166760) ENGINE = MyISAM, PARTITION p393 VALUES LESS THAN (1128325152) ENGINE = MyISAM, PARTITION p394 VALUES LESS THAN (1128620248) ENGINE = MyISAM, PARTITION p395 VALUES LESS THAN (1128723280) ENGINE = MyISAM, PARTITION p396 VALUES LESS THAN (1129083392) ENGINE = MyISAM, PARTITION p397 VALUES LESS THAN (1130221824) ENGINE = MyISAM, PARTITION p398 VALUES LESS THAN (1130462624) ENGINE = MyISAM, PARTITION p399 VALUES LESS THAN (1130627840) ENGINE = MyISAM, PARTITION p400 VALUES LESS THAN (1131214080) ENGINE = MyISAM, PARTITION p401 VALUES LESS THAN (1131551896) ENGINE = MyISAM, PARTITION p402 VALUES LESS THAN (1131795056) ENGINE = MyISAM, PARTITION p403 VALUES LESS THAN (1132094208) ENGINE = MyISAM, PARTITION p404 VALUES LESS THAN (1132485120) ENGINE = MyISAM, PARTITION p405 VALUES LESS THAN (1132660688) ENGINE = MyISAM, PARTITION p406 VALUES LESS THAN (1132810736) ENGINE = MyISAM, PARTITION p407 VALUES LESS THAN (1132948480) ENGINE = MyISAM, PARTITION p408 VALUES LESS THAN (1133364032) ENGINE = MyISAM, PARTITION p409 VALUES LESS THAN (1134009832) ENGINE = MyISAM, PARTITION p410 VALUES LESS THAN (1134460280) ENGINE = MyISAM, PARTITION p411 VALUES LESS THAN (1135643648) ENGINE = MyISAM, PARTITION p412 VALUES LESS THAN (1141047808) ENGINE = MyISAM, PARTITION p413 VALUES LESS THAN (1142183120) ENGINE = MyISAM, PARTITION p414 VALUES LESS THAN (1142244752) ENGINE = MyISAM, PARTITION p415 VALUES LESS THAN (1142320120) ENGINE = MyISAM, PARTITION p416 VALUES LESS THAN (1142419472) ENGINE = MyISAM, PARTITION p417 VALUES LESS THAN (1143808000) ENGINE = MyISAM, PARTITION p418 VALUES LESS THAN (1145117952) ENGINE = MyISAM, PARTITION p419 VALUES LESS THAN (1145668208) ENGINE = MyISAM, PARTITION p420 VALUES LESS THAN (1145816936) ENGINE = MyISAM, PARTITION p421 VALUES LESS THAN (1145895896) ENGINE = MyISAM, PARTITION p422 VALUES LESS THAN (1146046032) ENGINE = MyISAM, PARTITION p423 VALUES LESS THAN (1146640104) ENGINE = MyISAM, PARTITION p424 VALUES LESS THAN (1146938792) ENGINE = MyISAM, PARTITION p425 VALUES LESS THAN (1148067840) ENGINE = MyISAM, PARTITION p426 VALUES LESS THAN (1148862016) ENGINE = MyISAM, PARTITION p427 VALUES LESS THAN (1150030160) ENGINE = MyISAM, PARTITION p428 VALUES LESS THAN (1151336448) ENGINE = MyISAM, PARTITION p429 VALUES LESS THAN (1151928064) ENGINE = MyISAM, PARTITION p430 VALUES LESS THAN (1152596096) ENGINE = MyISAM, PARTITION p431 VALUES LESS THAN (1153372416) ENGINE = MyISAM, PARTITION p432 VALUES LESS THAN (1154527488) ENGINE = MyISAM, PARTITION p433 VALUES LESS THAN (1155892224) ENGINE = MyISAM, PARTITION p434 VALUES LESS THAN (1156599040) ENGINE = MyISAM, PARTITION p435 VALUES LESS THAN (1157202704) ENGINE = MyISAM, PARTITION p436 VALUES LESS THAN (1157368784) ENGINE = MyISAM, PARTITION p437 VALUES LESS THAN (1157559696) ENGINE = MyISAM, PARTITION p438 VALUES LESS THAN (1157817344) ENGINE = MyISAM, PARTITION p439 VALUES LESS THAN (1158295296) ENGINE = MyISAM, PARTITION p440 VALUES LESS THAN (1158519474) ENGINE = MyISAM, PARTITION p441 VALUES LESS THAN (1158937376) ENGINE = MyISAM, PARTITION p442 VALUES LESS THAN (1158971232) ENGINE = MyISAM, PARTITION p443 VALUES LESS THAN (1159386432) ENGINE = MyISAM, PARTITION p444 VALUES LESS THAN (1160012696) ENGINE = MyISAM, PARTITION p445 VALUES LESS THAN (1160414226) ENGINE = MyISAM, PARTITION p446 VALUES LESS THAN (1160827328) ENGINE = MyISAM, PARTITION p447 VALUES LESS THAN (1161125152) ENGINE = MyISAM, PARTITION p448 VALUES LESS THAN (1161153792) ENGINE = MyISAM, PARTITION p449 VALUES LESS THAN (1161646880) ENGINE = MyISAM, PARTITION p450 VALUES LESS THAN (1162485888) ENGINE = MyISAM, PARTITION p451 VALUES LESS THAN (1163412992) ENGINE = MyISAM, PARTITION p452 VALUES LESS THAN (1163679232) ENGINE = MyISAM, PARTITION p453 VALUES LESS THAN (1164784128) ENGINE = MyISAM, PARTITION p454 VALUES LESS THAN (1166051560) ENGINE = MyISAM, PARTITION p455 VALUES LESS THAN (1166686208) ENGINE = MyISAM, PARTITION p456 VALUES LESS THAN (1167507456) ENGINE = MyISAM, PARTITION p457 VALUES LESS THAN (1167902464) ENGINE = MyISAM, PARTITION p458 VALUES LESS THAN (1168953344) ENGINE = MyISAM, PARTITION p459 VALUES LESS THAN (1169337080) ENGINE = MyISAM, PARTITION p460 VALUES LESS THAN (1170252288) ENGINE = MyISAM, PARTITION p461 VALUES LESS THAN (1171347744) ENGINE = MyISAM, PARTITION p462 VALUES LESS THAN (1171633664) ENGINE = MyISAM, PARTITION p463 VALUES LESS THAN (1172049982) ENGINE = MyISAM, PARTITION p464 VALUES LESS THAN (1172462336) ENGINE = MyISAM, PARTITION p465 VALUES LESS THAN (1173219328) ENGINE = MyISAM, PARTITION p466 VALUES LESS THAN (1174319616) ENGINE = MyISAM, PARTITION p467 VALUES LESS THAN (1176048640) ENGINE = MyISAM, PARTITION p468 VALUES LESS THAN (1176801536) ENGINE = MyISAM, PARTITION p469 VALUES LESS THAN (1177875456) ENGINE = MyISAM, PARTITION p470 VALUES LESS THAN (1178390784) ENGINE = MyISAM, PARTITION p471 VALUES LESS THAN (1180782336) ENGINE = MyISAM, PARTITION p472 VALUES LESS THAN (1182143488) ENGINE = MyISAM, PARTITION p473 VALUES LESS THAN (1183242496) ENGINE = MyISAM, PARTITION p474 VALUES LESS THAN (1184117248) ENGINE = MyISAM, PARTITION p475 VALUES LESS THAN (1185443584) ENGINE = MyISAM, PARTITION p476 VALUES LESS THAN (1187153664) ENGINE = MyISAM, PARTITION p477 VALUES LESS THAN (1189165056) ENGINE = MyISAM, PARTITION p478 VALUES LESS THAN (1189533568) ENGINE = MyISAM, PARTITION p479 VALUES LESS THAN (1190215432) ENGINE = MyISAM, PARTITION p480 VALUES LESS THAN (1190639232) ENGINE = MyISAM, PARTITION p481 VALUES LESS THAN (1190983568) ENGINE = MyISAM, PARTITION p482 VALUES LESS THAN (1191495040) ENGINE = MyISAM, PARTITION p483 VALUES LESS THAN (1192274944) ENGINE = MyISAM, PARTITION p484 VALUES LESS THAN (1193511936) ENGINE = MyISAM, PARTITION p485 VALUES LESS THAN (1194889728) ENGINE = MyISAM, PARTITION p486 VALUES LESS THAN (1197053952) ENGINE = MyISAM, PARTITION p487 VALUES LESS THAN (1198481408) ENGINE = MyISAM, PARTITION p488 VALUES LESS THAN (1199157760) ENGINE = MyISAM, PARTITION p489 VALUES LESS THAN (1199677440) ENGINE = MyISAM, PARTITION p490 VALUES LESS THAN (1200388608) ENGINE = MyISAM, PARTITION p491 VALUES LESS THAN (1201617280) ENGINE = MyISAM, PARTITION p492 VALUES LESS THAN (1202477088) ENGINE = MyISAM, PARTITION p493 VALUES LESS THAN (1203815680) ENGINE = MyISAM, PARTITION p494 VALUES LESS THAN (1205317376) ENGINE = MyISAM, PARTITION p495 VALUES LESS THAN (1206962176) ENGINE = MyISAM, PARTITION p496 VALUES LESS THAN (1207622416) ENGINE = MyISAM, PARTITION p497 VALUES LESS THAN (1208192000) ENGINE = MyISAM, PARTITION p498 VALUES LESS THAN (1208277088) ENGINE = MyISAM, PARTITION p499 VALUES LESS THAN (1209143808) ENGINE = MyISAM, PARTITION p500 VALUES LESS THAN (1210057800) ENGINE = MyISAM, PARTITION p501 VALUES LESS THAN (1210090248) ENGINE = MyISAM, PARTITION p502 VALUES LESS THAN (1210117568) ENGINE = MyISAM, PARTITION p503 VALUES LESS THAN (1210881312) ENGINE = MyISAM, PARTITION p504 VALUES LESS THAN (1211934976) ENGINE = MyISAM, PARTITION p505 VALUES LESS THAN (1212679888) ENGINE = MyISAM, PARTITION p506 VALUES LESS THAN (1213785472) ENGINE = MyISAM, PARTITION p507 VALUES LESS THAN (1217730048) ENGINE = MyISAM, PARTITION p508 VALUES LESS THAN (1218743728) ENGINE = MyISAM, PARTITION p509 VALUES LESS THAN (1218845048) ENGINE = MyISAM, PARTITION p510 VALUES LESS THAN (1220779520) ENGINE = MyISAM, PARTITION p511 VALUES LESS THAN (1223456600) ENGINE = MyISAM, PARTITION p512 VALUES LESS THAN (1223826792) ENGINE = MyISAM, PARTITION p513 VALUES LESS THAN (1223850848) ENGINE = MyISAM, PARTITION p514 VALUES LESS THAN (1223870608) ENGINE = MyISAM, PARTITION p515 VALUES LESS THAN (1223892088) ENGINE = MyISAM, PARTITION p516 VALUES LESS THAN (1223915000) ENGINE = MyISAM, PARTITION p517 VALUES LESS THAN (1223945456) ENGINE = MyISAM, PARTITION p518 VALUES LESS THAN (1241567488) ENGINE = MyISAM, PARTITION p519 VALUES LESS THAN (1242107056) ENGINE = MyISAM, PARTITION p520 VALUES LESS THAN (1242171984) ENGINE = MyISAM, PARTITION p521 VALUES LESS THAN (1244229888) ENGINE = MyISAM, PARTITION p522 VALUES LESS THAN (1250140160) ENGINE = MyISAM, PARTITION p523 VALUES LESS THAN (1254962120) ENGINE = MyISAM, PARTITION p524 VALUES LESS THAN (1254978272) ENGINE = MyISAM, PARTITION p525 VALUES LESS THAN (1257094400) ENGINE = MyISAM, PARTITION p526 VALUES LESS THAN (1259394560) ENGINE = MyISAM, PARTITION p527 VALUES LESS THAN (1261084872) ENGINE = MyISAM, PARTITION p528 VALUES LESS THAN (1263681536) ENGINE = MyISAM, PARTITION p529 VALUES LESS THAN (1266582176) ENGINE = MyISAM, PARTITION p530 VALUES LESS THAN (1275211648) ENGINE = MyISAM, PARTITION p531 VALUES LESS THAN (1283158016) ENGINE = MyISAM, PARTITION p532 VALUES LESS THAN (1286823424) ENGINE = MyISAM, PARTITION p533 VALUES LESS THAN (1290548712) ENGINE = MyISAM, PARTITION p534 VALUES LESS THAN (1295780544) ENGINE = MyISAM, PARTITION p535 VALUES LESS THAN (1295793752) ENGINE = MyISAM, PARTITION p536 VALUES LESS THAN (1295807024) ENGINE = MyISAM, PARTITION p537 VALUES LESS THAN (1295820120) ENGINE = MyISAM, PARTITION p538 VALUES LESS THAN (1295833264) ENGINE = MyISAM, PARTITION p539 VALUES LESS THAN (1295846464) ENGINE = MyISAM, PARTITION p540 VALUES LESS THAN (1295859624) ENGINE = MyISAM, PARTITION p541 VALUES LESS THAN (1295872944) ENGINE = MyISAM, PARTITION p542 VALUES LESS THAN (1295886216) ENGINE = MyISAM, PARTITION p543 VALUES LESS THAN (1295899464) ENGINE = MyISAM, PARTITION p544 VALUES LESS THAN (1298661376) ENGINE = MyISAM, PARTITION p545 VALUES LESS THAN (1308262400) ENGINE = MyISAM, PARTITION p546 VALUES LESS THAN (1308923732) ENGINE = MyISAM, PARTITION p547 VALUES LESS THAN (1308959892) ENGINE = MyISAM, PARTITION p548 VALUES LESS THAN (1308995872) ENGINE = MyISAM, PARTITION p549 VALUES LESS THAN (1311510144) ENGINE = MyISAM, PARTITION p550 VALUES LESS THAN (1311756792) ENGINE = MyISAM, PARTITION p551 VALUES LESS THAN (1317175296) ENGINE = MyISAM, PARTITION p552 VALUES LESS THAN (1342396416) ENGINE = MyISAM, PARTITION p553 VALUES LESS THAN (1343233584) ENGINE = MyISAM, PARTITION p554 VALUES LESS THAN (1343268032) ENGINE = MyISAM, PARTITION p555 VALUES LESS THAN (1343302164) ENGINE = MyISAM, PARTITION p556 VALUES LESS THAN (1343331792) ENGINE = MyISAM, PARTITION p557 VALUES LESS THAN (1343365376) ENGINE = MyISAM, PARTITION p558 VALUES LESS THAN (1343394544) ENGINE = MyISAM, PARTITION p559 VALUES LESS THAN (1343418720) ENGINE = MyISAM, PARTITION p560 VALUES LESS THAN (1343455192) ENGINE = MyISAM, PARTITION p561 VALUES LESS THAN (1343480304) ENGINE = MyISAM, PARTITION p562 VALUES LESS THAN (1343513024) ENGINE = MyISAM, PARTITION p563 VALUES LESS THAN (1343543312) ENGINE = MyISAM, PARTITION p564 VALUES LESS THAN (1343580616) ENGINE = MyISAM, PARTITION p565 VALUES LESS THAN (1343615920) ENGINE = MyISAM, PARTITION p566 VALUES LESS THAN (1343647472) ENGINE = MyISAM, PARTITION p567 VALUES LESS THAN (1343676416) ENGINE = MyISAM, PARTITION p568 VALUES LESS THAN (1343712632) ENGINE = MyISAM, PARTITION p569 VALUES LESS THAN (1343746608) ENGINE = MyISAM, PARTITION p570 VALUES LESS THAN (1344972800) ENGINE = MyISAM, PARTITION p571 VALUES LESS THAN (1345595520) ENGINE = MyISAM, PARTITION p572 VALUES LESS THAN (1345633468) ENGINE = MyISAM, PARTITION p573 VALUES LESS THAN (1345660896) ENGINE = MyISAM, PARTITION p574 VALUES LESS THAN (1345672620) ENGINE = MyISAM, PARTITION p575 VALUES LESS THAN (1345784352) ENGINE = MyISAM, PARTITION p576 VALUES LESS THAN (1345801286) ENGINE = MyISAM, PARTITION p577 VALUES LESS THAN (1345812564) ENGINE = MyISAM, PARTITION p578 VALUES LESS THAN (1346320776) ENGINE = MyISAM, PARTITION p579 VALUES LESS THAN (1346933644) ENGINE = MyISAM, PARTITION p580 VALUES LESS THAN (1347528640) ENGINE = MyISAM, PARTITION p581 VALUES LESS THAN (1348295904) ENGINE = MyISAM, PARTITION p582 VALUES LESS THAN (1349077256) ENGINE = MyISAM, PARTITION p583 VALUES LESS THAN (1349722368) ENGINE = MyISAM, PARTITION p584 VALUES LESS THAN (1350049900) ENGINE = MyISAM, PARTITION p585 VALUES LESS THAN (1350063044) ENGINE = MyISAM, PARTITION p586 VALUES LESS THAN (1350070256) ENGINE = MyISAM, PARTITION p587 VALUES LESS THAN (1350091784) ENGINE = MyISAM, PARTITION p588 VALUES LESS THAN (1350104128) ENGINE = MyISAM, PARTITION p589 VALUES LESS THAN (1350156280) ENGINE = MyISAM, PARTITION p590 VALUES LESS THAN (1350170236) ENGINE = MyISAM, PARTITION p591 VALUES LESS THAN (1350178704) ENGINE = MyISAM, PARTITION p592 VALUES LESS THAN (1350186072) ENGINE = MyISAM, PARTITION p593 VALUES LESS THAN (1350193392) ENGINE = MyISAM, PARTITION p594 VALUES LESS THAN (1350204216) ENGINE = MyISAM, PARTITION p595 VALUES LESS THAN (1350223012) ENGINE = MyISAM, PARTITION p596 VALUES LESS THAN (1350231492) ENGINE = MyISAM, PARTITION p597 VALUES LESS THAN (1350257068) ENGINE = MyISAM, PARTITION p598 VALUES LESS THAN (1350265204) ENGINE = MyISAM, PARTITION p599 VALUES LESS THAN (1350275944) ENGINE = MyISAM, PARTITION p600 VALUES LESS THAN (1350289944) ENGINE = MyISAM, PARTITION p601 VALUES LESS THAN (1350340784) ENGINE = MyISAM, PARTITION p602 VALUES LESS THAN (1351077632) ENGINE = MyISAM, PARTITION p603 VALUES LESS THAN (1351807376) ENGINE = MyISAM, PARTITION p604 VALUES LESS THAN (1352667936) ENGINE = MyISAM, PARTITION p605 VALUES LESS THAN (1352993792) ENGINE = MyISAM, PARTITION p606 VALUES LESS THAN (1353676928) ENGINE = MyISAM, PARTITION p607 VALUES LESS THAN (1353743544) ENGINE = MyISAM, PARTITION p608 VALUES LESS THAN (1353768704) ENGINE = MyISAM, PARTITION p609 VALUES LESS THAN (1353798768) ENGINE = MyISAM, PARTITION p610 VALUES LESS THAN (1353835872) ENGINE = MyISAM, PARTITION p611 VALUES LESS THAN (1354506064) ENGINE = MyISAM, PARTITION p612 VALUES LESS THAN (1354738304) ENGINE = MyISAM, PARTITION p613 VALUES LESS THAN (1355213312) ENGINE = MyISAM, PARTITION p614 VALUES LESS THAN (1355530112) ENGINE = MyISAM, PARTITION p615 VALUES LESS THAN (1355565344) ENGINE = MyISAM, PARTITION p616 VALUES LESS THAN (1355590568) ENGINE = MyISAM, PARTITION p617 VALUES LESS THAN (1355625408) ENGINE = MyISAM, PARTITION p618 VALUES LESS THAN (1355660568) ENGINE = MyISAM, PARTITION p619 VALUES LESS THAN (1355694912) ENGINE = MyISAM, PARTITION p620 VALUES LESS THAN (1355720672) ENGINE = MyISAM, PARTITION p621 VALUES LESS THAN (1355742800) ENGINE = MyISAM, PARTITION p622 VALUES LESS THAN (1355764192) ENGINE = MyISAM, PARTITION p623 VALUES LESS THAN (1355792176) ENGINE = MyISAM, PARTITION p624 VALUES LESS THAN (1355867136) ENGINE = MyISAM, PARTITION p625 VALUES LESS THAN (1356744704) ENGINE = MyISAM, PARTITION p626 VALUES LESS THAN (1357656576) ENGINE = MyISAM, PARTITION p627 VALUES LESS THAN (1357950720) ENGINE = MyISAM, PARTITION p628 VALUES LESS THAN (1358655488) ENGINE = MyISAM, PARTITION p629 VALUES LESS THAN (1359017248) ENGINE = MyISAM, PARTITION p630 VALUES LESS THAN (1359109864) ENGINE = MyISAM, PARTITION p631 VALUES LESS THAN (1359157504) ENGINE = MyISAM, PARTITION p632 VALUES LESS THAN (1359472304) ENGINE = MyISAM, PARTITION p633 VALUES LESS THAN (1359823248) ENGINE = MyISAM, PARTITION p634 VALUES LESS THAN (1359838916) ENGINE = MyISAM, PARTITION p635 VALUES LESS THAN (1360736256) ENGINE = MyISAM, PARTITION p636 VALUES LESS THAN (1361386752) ENGINE = MyISAM, PARTITION p637 VALUES LESS THAN (1362755888) ENGINE = MyISAM, PARTITION p638 VALUES LESS THAN (1363126472) ENGINE = MyISAM, PARTITION p639 VALUES LESS THAN (1363729916) ENGINE = MyISAM, PARTITION p640 VALUES LESS THAN (1363921920) ENGINE = MyISAM, PARTITION p641 VALUES LESS THAN (1364233728) ENGINE = MyISAM, PARTITION p642 VALUES LESS THAN (1364440832) ENGINE = MyISAM, PARTITION p643 VALUES LESS THAN (1365148248) ENGINE = MyISAM, PARTITION p644 VALUES LESS THAN (1366308384) ENGINE = MyISAM, PARTITION p645 VALUES LESS THAN (1366332024) ENGINE = MyISAM, PARTITION p646 VALUES LESS THAN (1366368840) ENGINE = MyISAM, PARTITION p647 VALUES LESS THAN (1366399448) ENGINE = MyISAM, PARTITION p648 VALUES LESS THAN (1366439096) ENGINE = MyISAM, PARTITION p649 VALUES LESS THAN (1366464472) ENGINE = MyISAM, PARTITION p650 VALUES LESS THAN (1366500600) ENGINE = MyISAM, PARTITION p651 VALUES LESS THAN (1366531864) ENGINE = MyISAM, PARTITION p652 VALUES LESS THAN (1366568894) ENGINE = MyISAM, PARTITION p653 VALUES LESS THAN (1366584763) ENGINE = MyISAM, PARTITION p654 VALUES LESS THAN (1366612160) ENGINE = MyISAM, PARTITION p655 VALUES LESS THAN (1366636768) ENGINE = MyISAM, PARTITION p656 VALUES LESS THAN (1366660616) ENGINE = MyISAM, PARTITION p657 VALUES LESS THAN (1366674283) ENGINE = MyISAM, PARTITION p658 VALUES LESS THAN (1366740976) ENGINE = MyISAM, PARTITION p659 VALUES LESS THAN (1366790647) ENGINE = MyISAM, PARTITION p660 VALUES LESS THAN (1366793497) ENGINE = MyISAM, PARTITION p661 VALUES LESS THAN (1366891104) ENGINE = MyISAM, PARTITION p662 VALUES LESS THAN (1366965632) ENGINE = MyISAM, PARTITION p663 VALUES LESS THAN (1366977668) ENGINE = MyISAM, PARTITION p664 VALUES LESS THAN (1367011152) ENGINE = MyISAM, PARTITION p665 VALUES LESS THAN (1367036288) ENGINE = MyISAM, PARTITION p666 VALUES LESS THAN (1367072416) ENGINE = MyISAM, PARTITION p667 VALUES LESS THAN (1367185680) ENGINE = MyISAM, PARTITION p668 VALUES LESS THAN (1368432432) ENGINE = MyISAM, PARTITION p669 VALUES LESS THAN (1369166080) ENGINE = MyISAM, PARTITION p670 VALUES LESS THAN (1370203102) ENGINE = MyISAM, PARTITION p671 VALUES LESS THAN (1370225392) ENGINE = MyISAM, PARTITION p672 VALUES LESS THAN (1370868736) ENGINE = MyISAM, PARTITION p673 VALUES LESS THAN (1371221202) ENGINE = MyISAM, PARTITION p674 VALUES LESS THAN (1371233580) ENGINE = MyISAM, PARTITION p675 VALUES LESS THAN (1371253316) ENGINE = MyISAM, PARTITION p676 VALUES LESS THAN (1371262678) ENGINE = MyISAM, PARTITION p677 VALUES LESS THAN (1371272448) ENGINE = MyISAM, PARTITION p678 VALUES LESS THAN (1371526528) ENGINE = MyISAM, PARTITION p679 VALUES LESS THAN (1372703360) ENGINE = MyISAM, PARTITION p680 VALUES LESS THAN (1373274120) ENGINE = MyISAM, PARTITION p681 VALUES LESS THAN (1373590672) ENGINE = MyISAM, PARTITION p682 VALUES LESS THAN (1373630048) ENGINE = MyISAM, PARTITION p683 VALUES LESS THAN (1374996992) ENGINE = MyISAM, PARTITION p684 VALUES LESS THAN (1375490192) ENGINE = MyISAM, PARTITION p685 VALUES LESS THAN (1375523296) ENGINE = MyISAM, PARTITION p686 VALUES LESS THAN (1375706944) ENGINE = MyISAM, PARTITION p687 VALUES LESS THAN (1376437248) ENGINE = MyISAM, PARTITION p688 VALUES LESS THAN (1378243584) ENGINE = MyISAM, PARTITION p689 VALUES LESS THAN (1379624192) ENGINE = MyISAM, PARTITION p690 VALUES LESS THAN (1380207376) ENGINE = MyISAM, PARTITION p691 VALUES LESS THAN (1380244248) ENGINE = MyISAM, PARTITION p692 VALUES LESS THAN (1380306704) ENGINE = MyISAM, PARTITION p693 VALUES LESS THAN (1380334360) ENGINE = MyISAM, PARTITION p694 VALUES LESS THAN (1380358344) ENGINE = MyISAM, PARTITION p695 VALUES LESS THAN (1380383600) ENGINE = MyISAM, PARTITION p696 VALUES LESS THAN (1381103872) ENGINE = MyISAM, PARTITION p697 VALUES LESS THAN (1382223104) ENGINE = MyISAM, PARTITION p698 VALUES LESS THAN (1382816928) ENGINE = MyISAM, PARTITION p699 VALUES LESS THAN (1382910128) ENGINE = MyISAM, PARTITION p700 VALUES LESS THAN (1382981728) ENGINE = MyISAM, PARTITION p701 VALUES LESS THAN (1383021320) ENGINE = MyISAM, PARTITION p702 VALUES LESS THAN (1383180784) ENGINE = MyISAM, PARTITION p703 VALUES LESS THAN (1384063488) ENGINE = MyISAM, PARTITION p704 VALUES LESS THAN (1384786176) ENGINE = MyISAM, PARTITION p705 VALUES LESS THAN (1385065936) ENGINE = MyISAM, PARTITION p706 VALUES LESS THAN (1385775104) ENGINE = MyISAM, PARTITION p707 VALUES LESS THAN (1386385632) ENGINE = MyISAM, PARTITION p708 VALUES LESS THAN (1387358208) ENGINE = MyISAM, PARTITION p709 VALUES LESS THAN (1387817360) ENGINE = MyISAM, PARTITION p710 VALUES LESS THAN (1387851200) ENGINE = MyISAM, PARTITION p711 VALUES LESS THAN (1387888072) ENGINE = MyISAM, PARTITION p712 VALUES LESS THAN (1387914344) ENGINE = MyISAM, PARTITION p713 VALUES LESS THAN (1387936128) ENGINE = MyISAM, PARTITION p714 VALUES LESS THAN (1387966288) ENGINE = MyISAM, PARTITION p715 VALUES LESS THAN (1387989664) ENGINE = MyISAM, PARTITION p716 VALUES LESS THAN (1388013184) ENGINE = MyISAM, PARTITION p717 VALUES LESS THAN (1388041296) ENGINE = MyISAM, PARTITION p718 VALUES LESS THAN (1388077664) ENGINE = MyISAM, PARTITION p719 VALUES LESS THAN (1388101648) ENGINE = MyISAM, PARTITION p720 VALUES LESS THAN (1388129616) ENGINE = MyISAM, PARTITION p721 VALUES LESS THAN (1388156424) ENGINE = MyISAM, PARTITION p722 VALUES LESS THAN (1388197392) ENGINE = MyISAM, PARTITION p723 VALUES LESS THAN (1388231672) ENGINE = MyISAM, PARTITION p724 VALUES LESS THAN (1388261568) ENGINE = MyISAM, PARTITION p725 VALUES LESS THAN (1388289992) ENGINE = MyISAM, PARTITION p726 VALUES LESS THAN (1388424976) ENGINE = MyISAM, PARTITION p727 VALUES LESS THAN (1389110776) ENGINE = MyISAM, PARTITION p728 VALUES LESS THAN (1389549056) ENGINE = MyISAM, PARTITION p729 VALUES LESS THAN (1390670592) ENGINE = MyISAM, PARTITION p730 VALUES LESS THAN (1391228672) ENGINE = MyISAM, PARTITION p731 VALUES LESS THAN (1392012672) ENGINE = MyISAM, PARTITION p732 VALUES LESS THAN (1392709784) ENGINE = MyISAM, PARTITION p733 VALUES LESS THAN (1392723168) ENGINE = MyISAM, PARTITION p734 VALUES LESS THAN (1392734948) ENGINE = MyISAM, PARTITION p735 VALUES LESS THAN (1392748324) ENGINE = MyISAM, PARTITION p736 VALUES LESS THAN (1392760352) ENGINE = MyISAM, PARTITION p737 VALUES LESS THAN (1393114368) ENGINE = MyISAM, PARTITION p738 VALUES LESS THAN (1393305476) ENGINE = MyISAM, PARTITION p739 VALUES LESS THAN (1393316824) ENGINE = MyISAM, PARTITION p740 VALUES LESS THAN (1393329216) ENGINE = MyISAM, PARTITION p741 VALUES LESS THAN (1393341416) ENGINE = MyISAM, PARTITION p742 VALUES LESS THAN (1393353768) ENGINE = MyISAM, PARTITION p743 VALUES LESS THAN (1393367096) ENGINE = MyISAM, PARTITION p744 VALUES LESS THAN (1393379832) ENGINE = MyISAM, PARTITION p745 VALUES LESS THAN (1393393348) ENGINE = MyISAM, PARTITION p746 VALUES LESS THAN (1393406032) ENGINE = MyISAM, PARTITION p747 VALUES LESS THAN (1393417748) ENGINE = MyISAM, PARTITION p748 VALUES LESS THAN (1393463780) ENGINE = MyISAM, PARTITION p749 VALUES LESS THAN (1393474588) ENGINE = MyISAM, PARTITION p750 VALUES LESS THAN (1393487768) ENGINE = MyISAM, PARTITION p751 VALUES LESS THAN (1393499820) ENGINE = MyISAM, PARTITION p752 VALUES LESS THAN (1393512868) ENGINE = MyISAM, PARTITION p753 VALUES LESS THAN (1393525936) ENGINE = MyISAM, PARTITION p754 VALUES LESS THAN (1393539456) ENGINE = MyISAM, PARTITION p755 VALUES LESS THAN (1393553360) ENGINE = MyISAM, PARTITION p756 VALUES LESS THAN (1393569104) ENGINE = MyISAM, PARTITION p757 VALUES LESS THAN (1393582872) ENGINE = MyISAM, PARTITION p758 VALUES LESS THAN (1393593316) ENGINE = MyISAM, PARTITION p759 VALUES LESS THAN (1393603356) ENGINE = MyISAM, PARTITION p760 VALUES LESS THAN (1393613428) ENGINE = MyISAM, PARTITION p761 VALUES LESS THAN (1393624544) ENGINE = MyISAM, PARTITION p762 VALUES LESS THAN (1393634864) ENGINE = MyISAM, PARTITION p763 VALUES LESS THAN (1393648472) ENGINE = MyISAM, PARTITION p764 VALUES LESS THAN (1393691932) ENGINE = MyISAM, PARTITION p765 VALUES LESS THAN (1393704888) ENGINE = MyISAM, PARTITION p766 VALUES LESS THAN (1393717900) ENGINE = MyISAM, PARTITION p767 VALUES LESS THAN (1393765328) ENGINE = MyISAM, PARTITION p768 VALUES LESS THAN (1393779464) ENGINE = MyISAM, PARTITION p769 VALUES LESS THAN (1393796824) ENGINE = MyISAM, PARTITION p770 VALUES LESS THAN (1393811792) ENGINE = MyISAM, PARTITION p771 VALUES LESS THAN (1394927616) ENGINE = MyISAM, PARTITION p772 VALUES LESS THAN (1396334848) ENGINE = MyISAM, PARTITION p773 VALUES LESS THAN (1396736464) ENGINE = MyISAM, PARTITION p774 VALUES LESS THAN (1396779936) ENGINE = MyISAM, PARTITION p775 VALUES LESS THAN (1396818968) ENGINE = MyISAM, PARTITION p776 VALUES LESS THAN (1397937920) ENGINE = MyISAM, PARTITION p777 VALUES LESS THAN (1398535680) ENGINE = MyISAM, PARTITION p778 VALUES LESS THAN (1399125236) ENGINE = MyISAM, PARTITION p779 VALUES LESS THAN (1399342272) ENGINE = MyISAM, PARTITION p780 VALUES LESS THAN (1399362784) ENGINE = MyISAM, PARTITION p781 VALUES LESS THAN (1399380688) ENGINE = MyISAM, PARTITION p782 VALUES LESS THAN (1399410064) ENGINE = MyISAM, PARTITION p783 VALUES LESS THAN (1400029696) ENGINE = MyISAM, PARTITION p784 VALUES LESS THAN (1402031184) ENGINE = MyISAM, PARTITION p785 VALUES LESS THAN (1403511168) ENGINE = MyISAM, PARTITION p786 VALUES LESS THAN (1405330176) ENGINE = MyISAM, PARTITION p787 VALUES LESS THAN (1406027632) ENGINE = MyISAM, PARTITION p788 VALUES LESS THAN (1406069504) ENGINE = MyISAM, PARTITION p789 VALUES LESS THAN (1406373360) ENGINE = MyISAM, PARTITION p790 VALUES LESS THAN (1406866072) ENGINE = MyISAM, PARTITION p791 VALUES LESS THAN (1407786176) ENGINE = MyISAM, PARTITION p792 VALUES LESS THAN (1408014479) ENGINE = MyISAM, PARTITION p793 VALUES LESS THAN (1408031012) ENGINE = MyISAM, PARTITION p794 VALUES LESS THAN (1408276480) ENGINE = MyISAM, PARTITION p795 VALUES LESS THAN (1409645568) ENGINE = MyISAM, PARTITION p796 VALUES LESS THAN (1410241760) ENGINE = MyISAM, PARTITION p797 VALUES LESS THAN (1411138304) ENGINE = MyISAM, PARTITION p798 VALUES LESS THAN (1412784608) ENGINE = MyISAM, PARTITION p799 VALUES LESS THAN (1415204096) ENGINE = MyISAM, PARTITION p800 VALUES LESS THAN (1415884544) ENGINE = MyISAM, PARTITION p801 VALUES LESS THAN (1418906624) ENGINE = MyISAM, PARTITION p802 VALUES LESS THAN (1421635840) ENGINE = MyISAM, PARTITION p803 VALUES LESS THAN (1424591440) ENGINE = MyISAM, PARTITION p804 VALUES LESS THAN (1425856007) ENGINE = MyISAM, PARTITION p805 VALUES LESS THAN (1425864835) ENGINE = MyISAM, PARTITION p806 VALUES LESS THAN (1426265088) ENGINE = MyISAM, PARTITION p807 VALUES LESS THAN (1427306692) ENGINE = MyISAM, PARTITION p808 VALUES LESS THAN (1427399836) ENGINE = MyISAM, PARTITION p809 VALUES LESS THAN (1428147584) ENGINE = MyISAM, PARTITION p810 VALUES LESS THAN (1428192160) ENGINE = MyISAM, PARTITION p811 VALUES LESS THAN (1428222704) ENGINE = MyISAM, PARTITION p812 VALUES LESS THAN (1428254376) ENGINE = MyISAM, PARTITION p813 VALUES LESS THAN (1428284032) ENGINE = MyISAM, PARTITION p814 VALUES LESS THAN (1428315808) ENGINE = MyISAM, PARTITION p815 VALUES LESS THAN (1428344384) ENGINE = MyISAM, PARTITION p816 VALUES LESS THAN (1428381216) ENGINE = MyISAM, PARTITION p817 VALUES LESS THAN (1428409720) ENGINE = MyISAM, PARTITION p818 VALUES LESS THAN (1428446408) ENGINE = MyISAM, PARTITION p819 VALUES LESS THAN (1428476672) ENGINE = MyISAM, PARTITION p820 VALUES LESS THAN (1428511632) ENGINE = MyISAM, PARTITION p821 VALUES LESS THAN (1428540528) ENGINE = MyISAM, PARTITION p822 VALUES LESS THAN (1428576568) ENGINE = MyISAM, PARTITION p823 VALUES LESS THAN (1428605272) ENGINE = MyISAM, PARTITION p824 VALUES LESS THAN (1428651464) ENGINE = MyISAM, PARTITION p825 VALUES LESS THAN (1428679784) ENGINE = MyISAM, PARTITION p826 VALUES LESS THAN (1428710824) ENGINE = MyISAM, PARTITION p827 VALUES LESS THAN (1428735512) ENGINE = MyISAM, PARTITION p828 VALUES LESS THAN (1428761256) ENGINE = MyISAM, PARTITION p829 VALUES LESS THAN (1428790440) ENGINE = MyISAM, PARTITION p830 VALUES LESS THAN (1428812904) ENGINE = MyISAM, PARTITION p831 VALUES LESS THAN (1428842928) ENGINE = MyISAM, PARTITION p832 VALUES LESS THAN (1428869984) ENGINE = MyISAM, PARTITION p833 VALUES LESS THAN (1428895912) ENGINE = MyISAM, PARTITION p834 VALUES LESS THAN (1428928088) ENGINE = MyISAM, PARTITION p835 VALUES LESS THAN (1428963028) ENGINE = MyISAM, PARTITION p836 VALUES LESS THAN (1428998160) ENGINE = MyISAM, PARTITION p837 VALUES LESS THAN (1429035608) ENGINE = MyISAM, PARTITION p838 VALUES LESS THAN (1429058232) ENGINE = MyISAM, PARTITION p839 VALUES LESS THAN (1429076728) ENGINE = MyISAM, PARTITION p840 VALUES LESS THAN (1429104224) ENGINE = MyISAM, PARTITION p841 VALUES LESS THAN (1429132176) ENGINE = MyISAM, PARTITION p842 VALUES LESS THAN (1429153896) ENGINE = MyISAM, PARTITION p843 VALUES LESS THAN (1429177680) ENGINE = MyISAM, PARTITION p844 VALUES LESS THAN (1429200392) ENGINE = MyISAM, PARTITION p845 VALUES LESS THAN (1430686464) ENGINE = MyISAM, PARTITION p846 VALUES LESS THAN (1432585728) ENGINE = MyISAM, PARTITION p847 VALUES LESS THAN (1434204872) ENGINE = MyISAM, PARTITION p848 VALUES LESS THAN (1434253840) ENGINE = MyISAM, PARTITION p849 VALUES LESS THAN (1434286128) ENGINE = MyISAM, PARTITION p850 VALUES LESS THAN (1434331968) ENGINE = MyISAM, PARTITION p851 VALUES LESS THAN (1434375008) ENGINE = MyISAM, PARTITION p852 VALUES LESS THAN (1436429400) ENGINE = MyISAM, PARTITION p853 VALUES LESS THAN (1438274560) ENGINE = MyISAM, PARTITION p854 VALUES LESS THAN (1439642368) ENGINE = MyISAM, PARTITION p855 VALUES LESS THAN (1441743040) ENGINE = MyISAM, PARTITION p856 VALUES LESS THAN (1444884480) ENGINE = MyISAM, PARTITION p857 VALUES LESS THAN (1447037576) ENGINE = MyISAM, PARTITION p858 VALUES LESS THAN (1447149296) ENGINE = MyISAM, PARTITION p859 VALUES LESS THAN (1449542576) ENGINE = MyISAM, PARTITION p860 VALUES LESS THAN (1451630592) ENGINE = MyISAM, PARTITION p861 VALUES LESS THAN (1453129728) ENGINE = MyISAM, PARTITION p862 VALUES LESS THAN (1456801792) ENGINE = MyISAM, PARTITION p863 VALUES LESS THAN (1461511424) ENGINE = MyISAM, PARTITION p864 VALUES LESS THAN (1463169224) ENGINE = MyISAM, PARTITION p865 VALUES LESS THAN (1465125680) ENGINE = MyISAM, PARTITION p866 VALUES LESS THAN (1465252896) ENGINE = MyISAM, PARTITION p867 VALUES LESS THAN (1466309488) ENGINE = MyISAM, PARTITION p868 VALUES LESS THAN (1472282032) ENGINE = MyISAM, PARTITION p869 VALUES LESS THAN (1472314883) ENGINE = MyISAM, PARTITION p870 VALUES LESS THAN (1472542424) ENGINE = MyISAM, PARTITION p871 VALUES LESS THAN (1474329112) ENGINE = MyISAM, PARTITION p872 VALUES LESS THAN (1474966844) ENGINE = MyISAM, PARTITION p873 VALUES LESS THAN (1475015696) ENGINE = MyISAM, PARTITION p874 VALUES LESS THAN (1475887104) ENGINE = MyISAM, PARTITION p875 VALUES LESS THAN (1478505552) ENGINE = MyISAM, PARTITION p876 VALUES LESS THAN (1478548112) ENGINE = MyISAM, PARTITION p877 VALUES LESS THAN (1478583664) ENGINE = MyISAM, PARTITION p878 VALUES LESS THAN (1478619256) ENGINE = MyISAM, PARTITION p879 VALUES LESS THAN (1478662728) ENGINE = MyISAM, PARTITION p880 VALUES LESS THAN (1478684064) ENGINE = MyISAM, PARTITION p881 VALUES LESS THAN (1478720008) ENGINE = MyISAM, PARTITION p882 VALUES LESS THAN (1478745360) ENGINE = MyISAM, PARTITION p883 VALUES LESS THAN (1478775896) ENGINE = MyISAM, PARTITION p884 VALUES LESS THAN (1478801496) ENGINE = MyISAM, PARTITION p885 VALUES LESS THAN (1478817352) ENGINE = MyISAM, PARTITION p886 VALUES LESS THAN (1478850960) ENGINE = MyISAM, PARTITION p887 VALUES LESS THAN (1478876464) ENGINE = MyISAM, PARTITION p888 VALUES LESS THAN (1478906440) ENGINE = MyISAM, PARTITION p889 VALUES LESS THAN (1478939048) ENGINE = MyISAM, PARTITION p890 VALUES LESS THAN (1478962504) ENGINE = MyISAM, PARTITION p891 VALUES LESS THAN (1478991360) ENGINE = MyISAM, PARTITION p892 VALUES LESS THAN (1479040016) ENGINE = MyISAM, PARTITION p893 VALUES LESS THAN (1479070104) ENGINE = MyISAM, PARTITION p894 VALUES LESS THAN (1479096480) ENGINE = MyISAM, PARTITION p895 VALUES LESS THAN (1479129776) ENGINE = MyISAM, PARTITION p896 VALUES LESS THAN (1479166816) ENGINE = MyISAM, PARTITION p897 VALUES LESS THAN (1479193768) ENGINE = MyISAM, PARTITION p898 VALUES LESS THAN (1479209472) ENGINE = MyISAM, PARTITION p899 VALUES LESS THAN (1479241288) ENGINE = MyISAM, PARTITION p900 VALUES LESS THAN (1479299176) ENGINE = MyISAM, PARTITION p901 VALUES LESS THAN (1479317208) ENGINE = MyISAM, PARTITION p902 VALUES LESS THAN (1479332376) ENGINE = MyISAM, PARTITION p903 VALUES LESS THAN (1479372760) ENGINE = MyISAM, PARTITION p904 VALUES LESS THAN (1479422208) ENGINE = MyISAM, PARTITION p905 VALUES LESS THAN (1479438336) ENGINE = MyISAM, PARTITION p906 VALUES LESS THAN (1479472640) ENGINE = MyISAM, PARTITION p907 VALUES LESS THAN (1479495424) ENGINE = MyISAM, PARTITION p908 VALUES LESS THAN (1479572624) ENGINE = MyISAM, PARTITION p909 VALUES LESS THAN (1479610544) ENGINE = MyISAM, PARTITION p910 VALUES LESS THAN (1479630024) ENGINE = MyISAM, PARTITION p911 VALUES LESS THAN (1479648040) ENGINE = MyISAM, PARTITION p912 VALUES LESS THAN (1479668472) ENGINE = MyISAM, PARTITION p913 VALUES LESS THAN (1479719592) ENGINE = MyISAM, PARTITION p914 VALUES LESS THAN (1479765720) ENGINE = MyISAM, PARTITION p915 VALUES LESS THAN (1479779416) ENGINE = MyISAM, PARTITION p916 VALUES LESS THAN (1479830328) ENGINE = MyISAM, PARTITION p917 VALUES LESS THAN (1479860200) ENGINE = MyISAM, PARTITION p918 VALUES LESS THAN (1479892712) ENGINE = MyISAM, PARTITION p919 VALUES LESS THAN (1479920616) ENGINE = MyISAM, PARTITION p920 VALUES LESS THAN (1479970344) ENGINE = MyISAM, PARTITION p921 VALUES LESS THAN (1479987488) ENGINE = MyISAM, PARTITION p922 VALUES LESS THAN (1480021608) ENGINE = MyISAM, PARTITION p923 VALUES LESS THAN (1480044760) ENGINE = MyISAM, PARTITION p924 VALUES LESS THAN (1480074152) ENGINE = MyISAM, PARTITION p925 VALUES LESS THAN (1480096032) ENGINE = MyISAM, PARTITION p926 VALUES LESS THAN (1480126792) ENGINE = MyISAM, PARTITION p927 VALUES LESS THAN (1480145664) ENGINE = MyISAM, PARTITION p928 VALUES LESS THAN (1480223392) ENGINE = MyISAM, PARTITION p929 VALUES LESS THAN (1480256112) ENGINE = MyISAM, PARTITION p930 VALUES LESS THAN (1480275072) ENGINE = MyISAM, PARTITION p931 VALUES LESS THAN (1480310368) ENGINE = MyISAM, PARTITION p932 VALUES LESS THAN (1480420640) ENGINE = MyISAM, PARTITION p933 VALUES LESS THAN (1480450596) ENGINE = MyISAM, PARTITION p934 VALUES LESS THAN (1482689736) ENGINE = MyISAM, PARTITION p935 VALUES LESS THAN (1482717216) ENGINE = MyISAM, PARTITION p936 VALUES LESS THAN (1482803968) ENGINE = MyISAM, PARTITION p937 VALUES LESS THAN (1483507712) ENGINE = MyISAM, PARTITION p938 VALUES LESS THAN (1484001172) ENGINE = MyISAM, PARTITION p939 VALUES LESS THAN (1484009724) ENGINE = MyISAM, PARTITION p940 VALUES LESS THAN (1484016904) ENGINE = MyISAM, PARTITION p941 VALUES LESS THAN (1484026220) ENGINE = MyISAM, PARTITION p942 VALUES LESS THAN (1484033356) ENGINE = MyISAM, PARTITION p943 VALUES LESS THAN (1484042684) ENGINE = MyISAM, PARTITION p944 VALUES LESS THAN (1484049888) ENGINE = MyISAM, PARTITION p945 VALUES LESS THAN (1484062588) ENGINE = MyISAM, PARTITION p946 VALUES LESS THAN (1484102664) ENGINE = MyISAM, PARTITION p947 VALUES LESS THAN (1484109844) ENGINE = MyISAM, PARTITION p948 VALUES LESS THAN (1484118728) ENGINE = MyISAM, PARTITION p949 VALUES LESS THAN (1484126308) ENGINE = MyISAM, PARTITION p950 VALUES LESS THAN (1485433088) ENGINE = MyISAM, PARTITION p951 VALUES LESS THAN (1487012352) ENGINE = MyISAM, PARTITION p952 VALUES LESS THAN (1489405616) ENGINE = MyISAM, PARTITION p953 VALUES LESS THAN (1489428824) ENGINE = MyISAM, PARTITION p954 VALUES LESS THAN (1489934336) ENGINE = MyISAM, PARTITION p955 VALUES LESS THAN (1492665792) ENGINE = MyISAM, PARTITION p956 VALUES LESS THAN (1495395328) ENGINE = MyISAM, PARTITION p957 VALUES LESS THAN (1498824704) ENGINE = MyISAM, PARTITION p958 VALUES LESS THAN (1499587220) ENGINE = MyISAM, PARTITION p959 VALUES LESS THAN (1500909936) ENGINE = MyISAM, PARTITION p960 VALUES LESS THAN (1500949608) ENGINE = MyISAM, PARTITION p961 VALUES LESS THAN (1500987796) ENGINE = MyISAM, PARTITION p962 VALUES LESS THAN (1501019660) ENGINE = MyISAM, PARTITION p963 VALUES LESS THAN (1502986496) ENGINE = MyISAM, PARTITION p964 VALUES LESS THAN (1505383844) ENGINE = MyISAM, PARTITION p965 VALUES LESS THAN (1507506688) ENGINE = MyISAM, PARTITION p966 VALUES LESS THAN (1510928384) ENGINE = MyISAM, PARTITION p967 VALUES LESS THAN (1520131152) ENGINE = MyISAM, PARTITION p968 VALUES LESS THAN (1534069192) ENGINE = MyISAM, PARTITION p969 VALUES LESS THAN (1534078916) ENGINE = MyISAM, PARTITION p970 VALUES LESS THAN (1534086276) ENGINE = MyISAM, PARTITION p971 VALUES LESS THAN (1534093656) ENGINE = MyISAM, PARTITION p972 VALUES LESS THAN (1534102248) ENGINE = MyISAM, PARTITION p973 VALUES LESS THAN (1534110004) ENGINE = MyISAM, PARTITION p974 VALUES LESS THAN (1534117524) ENGINE = MyISAM, PARTITION p975 VALUES LESS THAN (1534127172) ENGINE = MyISAM, PARTITION p976 VALUES LESS THAN (1534175156) ENGINE = MyISAM, PARTITION p977 VALUES LESS THAN (1534187516) ENGINE = MyISAM, PARTITION p978 VALUES LESS THAN (1536207872) ENGINE = MyISAM, PARTITION p979 VALUES LESS THAN (1539777024) ENGINE = MyISAM, PARTITION p980 VALUES LESS THAN (1547712528) ENGINE = MyISAM, PARTITION p981 VALUES LESS THAN (1547726032) ENGINE = MyISAM, PARTITION p982 VALUES LESS THAN (1547743344) ENGINE = MyISAM, PARTITION p983 VALUES LESS THAN (1547756528) ENGINE = MyISAM, PARTITION p984 VALUES LESS THAN (1547770528) ENGINE = MyISAM, PARTITION p985 VALUES LESS THAN (1547784024) ENGINE = MyISAM, PARTITION p986 VALUES LESS THAN (1631729640) ENGINE = MyISAM, PARTITION p987 VALUES LESS THAN (1657341440) ENGINE = MyISAM, PARTITION p988 VALUES LESS THAN (2043723776) ENGINE = MyISAM, PARTITION p989 VALUES LESS THAN (2060483176) ENGINE = MyISAM, PARTITION p990 VALUES LESS THAN (2080413120) ENGINE = MyISAM, PARTITION p991 VALUES LESS THAN (2085728768) ENGINE = MyISAM, PARTITION p992 VALUES LESS THAN (2087270672) ENGINE = MyISAM, PARTITION p993 VALUES LESS THAN (2087353673) ENGINE = MyISAM, PARTITION p994 VALUES LESS THAN (2097155584) ENGINE = MyISAM, PARTITION p995 VALUES LESS THAN (2098335084) ENGINE = MyISAM, PARTITION p996 VALUES LESS THAN (2103259820) ENGINE = MyISAM, PARTITION p997 VALUES LESS THAN (2147483648) ENGINE = MyISAM) */;";
		$result = mysql_query($sql); #don't throw error if the partitioning doesn't work

		$sql ="CREATE TABLE IF NOT EXISTS `202_locations_city` (
  `location_city_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `location_city_name` varchar(50) NOT NULL,
  PRIMARY KEY (`location_city_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_locations_coordinates` (
  `location_coordinate_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `location_coordinate_latitude` decimal(6,4) NOT NULL,
  `location_coordinate_longitude` decimal(7,4) NOT NULL,
  PRIMARY KEY (`location_coordinate_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_locations_country` (
  `location_country_id` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `location_country_code` char(2) NOT NULL,
  `location_country_name` varchar(50) NOT NULL,
  PRIMARY KEY (`location_country_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_locations_region` (
  `location_region_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `location_region_code` char(2) NOT NULL,
  PRIMARY KEY (`location_region_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_platforms` (
  `platform_id` tinyint(1) unsigned NOT NULL AUTO_INCREMENT,
  `platform_name` varchar(50) NOT NULL,
  PRIMARY KEY (`platform_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

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
) ENGINE=MyISAM  DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

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
) ENGINE=MyISAM  DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_site_domains` (
  `site_domain_id` bigint(20) unsigned NOT NULL auto_increment,
  `site_domain_host` varchar(100) NOT NULL,
  PRIMARY KEY  (`site_domain_id`),
  KEY `site_domain_host` (`site_domain_host`(10))
) ENGINE=MyISAM  DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_site_urls` (
  `site_url_id` bigint(20) unsigned NOT NULL auto_increment,
  `site_domain_id` bigint(20) unsigned NOT NULL,
  `site_url_address` text NOT NULL,
  PRIMARY KEY  (`site_url_id`),
  KEY `site_domain_id` (`site_domain_id`),
  KEY `site_url_address` (`site_url_address`(75))
) ENGINE=MyISAM  DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_sort_breakdowns` (
		  `sort_breakdown_id` int(10) unsigned NOT NULL auto_increment,
		  `sort_breakdown_from` int(10) unsigned NOT NULL,
		  `sort_breakdown_to` int(10) unsigned NOT NULL,
		  `user_id` mediumint(8) unsigned NOT NULL,
		  `sort_breakdown_clicks` mediumint(8) unsigned NOT NULL,
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
		  KEY `sort_keyword_leads` (`sort_breakdown_leads`),
		  KEY `sort_keyword_signup_ratio` (`sort_breakdown_su_ratio`),
		  KEY `sort_keyword_payout` (`sort_breakdown_payout`),
		  KEY `sort_keyword_epc` (`sort_breakdown_epc`),
		  KEY `sort_keyword_cpc` (`sort_breakdown_avg_cpc`),
		  KEY `sort_keyword_income` (`sort_breakdown_income`),
		  KEY `sort_keyword_cost` (`sort_breakdown_cost`),
		  KEY `sort_keyword_net` (`sort_breakdown_net`),
		  KEY `sort_keyword_roi` (`sort_breakdown_roi`)
		) ENGINE=MyISAM  DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_sort_ips` (
		  `sort_ip_id` int(10) unsigned NOT NULL auto_increment,
		  `user_id` mediumint(8) unsigned NOT NULL,
		  `ip_id` bigint(20) unsigned NOT NULL,
		  `sort_ip_clicks` mediumint(8) unsigned NOT NULL,
		  `sort_ip_leads` mediumint(8) unsigned NOT NULL,
		  `sort_ip_su_ratio` decimal(10,2) NOT NULL,
		  `sort_ip_payout` decimal(6,2) NOT NULL,
		  `sort_ip_epc` decimal(10,2) NOT NULL,
		  `sort_ip_avg_cpc` decimal(7,5) NOT NULL,
		  `sort_ip_income` decimal(10,2) NOT NULL,
		  `sort_ip_cost` decimal(13,5) NOT NULL,
		  `sort_ip_net` decimal(13,5) NOT NULL,
		  `sort_ip_roi` decimal(10,2) NOT NULL,
		  PRIMARY KEY  (`sort_ip_id`),
		  KEY `user_id` (`user_id`),
		  KEY `keyword_id` (`ip_id`),
		  KEY `sort_keyword_clicks` (`sort_ip_clicks`),
		  KEY `sort_keyword_leads` (`sort_ip_leads`),
		  KEY `sort_keyword_signup_ratio` (`sort_ip_su_ratio`),
		  KEY `sort_keyword_payout` (`sort_ip_payout`),
		  KEY `sort_keyword_epc` (`sort_ip_epc`),
		  KEY `sort_keyword_cpc` (`sort_ip_avg_cpc`),
		  KEY `sort_keyword_income` (`sort_ip_income`),
		  KEY `sort_keyword_cost` (`sort_ip_cost`),
		  KEY `sort_keyword_net` (`sort_ip_net`),
		  KEY `sort_keyword_roi` (`sort_ip_roi`)
		) ENGINE=MyISAM  DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_sort_keywords` (
		  `sort_keyword_id` int(10) unsigned NOT NULL auto_increment,
		  `user_id` mediumint(8) unsigned NOT NULL,
		  `keyword_id` bigint(20) unsigned NOT NULL,
		  `sort_keyword_clicks` mediumint(8) unsigned NOT NULL,
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
		) ENGINE=MyISAM  DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_sort_landing_pages` (
		  `sort_landing_id` int(10) unsigned NOT NULL auto_increment,
		  `user_id` mediumint(8) unsigned NOT NULL,
		  `landing_page_id` mediumint(8) unsigned NOT NULL,
		  `sort_landing_page_clicks` mediumint(8) unsigned NOT NULL,
		  `sort_landing_page_click_throughs` mediumint(8) unsigned NOT NULL,
		  `sort_landing_page_ctr` decimal(10,2) NOT NULL,
		  `sort_landing_page_leads` mediumint(8) unsigned NOT NULL,
		  `sort_landing_page_su_ratio` decimal(10,2) NOT NULL,
		  `sort_landing_page_payout` decimal(6,2) NOT NULL,
		  `sort_landing_page_epc` decimal(10,2) NOT NULL,
		  `sort_landing_page_avg_cpc` decimal(7,5) NOT NULL,
		  `sort_landing_page_income` decimal(10,2) NOT NULL,
		  `sort_landing_page_cost` decimal(13,5) NOT NULL,
		  `sort_landing_page_net` decimal(13,5) NOT NULL,
		  `sort_landing_page_roi` decimal(10,2) NOT NULL,
		  PRIMARY KEY  (`sort_landing_id`),
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
		) ENGINE=MyISAM  DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_sort_referers` (
		  `sort_referer_id` int(10) unsigned NOT NULL auto_increment,
		  `user_id` mediumint(8) unsigned NOT NULL,
		  `referer_id` bigint(20) unsigned NOT NULL,
		  `sort_referer_clicks` mediumint(8) unsigned NOT NULL,
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
		) ENGINE=MyISAM  DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_sort_text_ads` (
		  `sort_text_ad_id` int(10) unsigned NOT NULL auto_increment,
		  `user_id` mediumint(8) unsigned NOT NULL,
		  `text_ad_id` mediumint(8) unsigned NOT NULL,
		  `sort_text_ad_clicks` mediumint(8) unsigned NOT NULL,
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
		) ENGINE=MyISAM  DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

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
				) ENGINE=MyISAM DEFAULT CHARSET=latin1";
		$result = _mysql_query($sql);

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
		$result = mysql_query($sql); #dont throw error if this doesn't work

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
) ENGINE=MyISAM  DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_trackers` (
  `tracker_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` mediumint(8) unsigned NOT NULL,
  `tracker_id_public` bigint(20) unsigned NOT NULL,
  `aff_campaign_id` mediumint(8) unsigned NOT NULL,
  `text_ad_id` mediumint(8) unsigned NOT NULL,
  `ppc_account_id` mediumint(8) unsigned NOT NULL,
  `landing_page_id` mediumint(8) unsigned NOT NULL,
  `click_cpc` decimal(7,5) NOT NULL,
  `click_cloaking` tinyint(1) NOT NULL,
  `tracker_time` int(10) unsigned NOT NULL,
  PRIMARY KEY (`tracker_id`),
  KEY `tracker_id_public` (`tracker_id_public`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_rotations` (
			  `aff_campaign_id` mediumint(8) unsigned NOT NULL,
			  `rotation_num` tinyint(4) NOT NULL,
			  PRIMARY KEY (`aff_campaign_id`)
			) ENGINE=MEMORY DEFAULT CHARSET=latin1;
			";
		$result = _mysql_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_alerts` (
				  `prosper_alert_id` int(11) NOT NULL,
				  `prosper_alert_seen` tinyint(1) NOT NULL,
				  UNIQUE KEY `prosper_alert_id` (`prosper_alert_id`)
				) ENGINE=MyISAM DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_offers` (
			  `user_id` mediumint(8) unsigned NOT NULL,
			  `offer_id` mediumint(10) unsigned NOT NULL,
			  `offer_seen` tinyint(1) NOT NULL DEFAULT '1',
			  UNIQUE KEY `user_id` (`user_id`,`offer_id`)
			) ENGINE=MyISAM DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_pixel_types` (
  			  `pixel_type_id` TINYINT UNSIGNED NOT NULL AUTO_INCREMENT ,
  		  	  `pixel_type` VARCHAR(45) NULL ,
  			  PRIMARY KEY (`pixel_type_id`) ,
  		      UNIQUE INDEX `pixel_type_UNIQUE` (`pixel_type` ASC) 
  			) ENGINE=MyISAM DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_ppc_account_pixels` (
 			  `pixel_id` mediumint(8) unsigned NOT NULL auto_increment,
  			  `pixel_code` text NOT NULL,
  			  `pixel_type_id` mediumint(8) unsigned NOT NULL,
  			  `ppc_account_id` mediumint(8) unsigned NOT NULL,
  			  PRIMARY KEY  (`pixel_id`)
 			  ) ENGINE=MyISAM DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

		$sql ="CREATE TABLE IF NOT EXISTS `202_clicks_total` (
			  `click_count` int(20) unsigned NOT NULL default '0',
 			  PRIMARY KEY  (`click_count`)
			  ) ENGINE=MyISAM DEFAULT CHARSET=latin1;";
		$result = _mysql_query($sql);

		$sql ="INSERT INTO `202_browsers` (`browser_id`, `browser_name`) VALUES
				(1, 'Internet Explorer'),
				(2, 'Firefox'),
				(3, 'Konqueror'),
				(4, 'Netscape Navigator'),
				(5, 'OmniWeb'),
				(6, 'Opera'),
				(7, 'Safari'),
				(8, 'AOL'),
				(9, 'Chrome'), 
				(10, 'Mobile'),
				(11, 'Console'),
				(12, 'Opera Mini'),
				(13, 'WebTV'),
				(14, 'Pocket Internet Explorer'),
				(15, 'iCab'),
				(16, 'Firebird'),
				(17, 'Iceweasel'),
				(18, 'Shiretoko'),
				(19, 'Mozilla'),
				(20, 'Amaya'),
				(21, 'Lynx'),
				(22, 'iPhone'),
				(23, 'iPod'),
				(24, 'iPad'),
				(25, 'Android'),				
				(26, 'GoogleBot'),
				(27, 'Yahoo! Slurp'),
				(28, 'W3C Validator'),
				(29, 'BlackBerry'),
				(30, 'IceCat'),
				(31, 'Nokia S60 OSS Browser'),
				(32, 'Nokia Browser'),
				(33, 'MSN Browser'),
				(34, 'MSN Bot'),
				(35, 'Galeon'),
				(36, 'NetPositive'),
				(37, 'Phoenix');";
		$result = _mysql_query($sql);

		$sql ="INSERT INTO `202_platforms` (`platform_id`, `platform_name`) VALUES
				(1, 'Windows'),
				(2, 'Macintosh'),
				(3, 'Linux'),
				(4, 'OS/2'),
				(5, 'BeOS'),
				(6, 'Mobile'),
				(7, 'Tablet');";
		$result = _mysql_query($sql);

		$sql ="INSERT INTO `202_pixel_types` (`pixel_type`) VALUES
				('Image'),
				('Iframe'),
				('Javascript'),
				('Postback');";
		$result = _mysql_query($sql);

		$sql ="INSERT IGNORE INTO `202_clicks_total` (`click_count`) VALUES
			  (0);";
		$result = _mysql_query($sql);

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
			) ENGINE=MyISAM AUTO_INCREMENT=1;";
				$result = _mysql_query($sql);
				
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
) ENGINE=MyISAM  AUTO_INCREMENT=1 ;";	
			$result = _mysql_query($sql);

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
) ENGINE=MyISAM;";
			$result = _mysql_query($sql);
			
$sql="ALTER TABLE `202_tracking_c1` CHANGE COLUMN `c1` `c1` VARCHAR(350) NOT NULL;";
			$result = _mysql_query($sql);
$sql="ALTER TABLE `202_tracking_c2` CHANGE COLUMN `c2` `c2` VARCHAR(350) NOT NULL;";
			$result = _mysql_query($sql);
$sql="ALTER TABLE `202_tracking_c3` CHANGE COLUMN `c3` `c3` VARCHAR(350) NOT NULL;";
			$result = _mysql_query($sql);
$sql="ALTER TABLE `202_tracking_c4` CHANGE COLUMN `c4` `c4` VARCHAR(350) NOT NULL;";
			$result = _mysql_query($sql);			
			
	}


}
