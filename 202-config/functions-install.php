<?php
set_time_limit(0);

include_once(__DIR__ . '/functions-upgrade.php');

class INSTALL
{

  function install_databases()
  {

    $database = DB::getInstance();
    $db = $database->getConnection();

    $php_version = PROSPER202::php_version_static();

    //Try to disable mysql strict mode
    $sql = "SET session sql_mode= ''";
    $result = _mysqli_query($sql);


    //create the new mysql version table
    $sql = "CREATE TABLE IF NOT EXISTS `202_version` (
					  `version` varchar(50) NOT NULL
					) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    //now add the what version this software is
    $sql = "INSERT INTO 202_version SET version='$php_version'";
    $result = _mysqli_query($sql);

    // Create user_data_feedback table for storing user feedback and settings
    $sql = "CREATE TABLE IF NOT EXISTS `user_data_feedback` (
          `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
          `user_id` mediumint(8) unsigned NOT NULL,
          `install_hash` varchar(255) NOT NULL DEFAULT '',
          `user_email` varchar(100) NOT NULL DEFAULT '',
          `user_hash` varchar(255) NOT NULL DEFAULT '',
          `time_stamp` int(10) unsigned NOT NULL,
          `api_key` varchar(255) DEFAULT NULL,
          `vip_perks_status` tinyint(1) NOT NULL DEFAULT '0',
          `modal_status` tinyint(1) NOT NULL DEFAULT '0',
          PRIMARY KEY (`id`),
          UNIQUE KEY `user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    //create sessions table
    $sql = "CREATE TABLE IF NOT EXISTS `202_sessions` (
				  `session_id` varchar(100) NOT NULL DEFAULT '',
				  `session_data` text NOT NULL,
				  `expires` int(11) NOT NULL DEFAULT '0',
				  PRIMARY KEY (`session_id`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_cronjobs` (
				  `cronjob_type` char(5) NOT NULL,
				  `cronjob_time` int(11) NOT NULL,
				  KEY `cronjob_type` (`cronjob_type`,`cronjob_time`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
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
			  KEY `ip_address` (`ip_address`(191))
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    //create users table
    $sql = "CREATE TABLE IF NOT EXISTS `202_users` (
          `user_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
          `user_fname` varchar(50) DEFAULT NULL,
          `user_lname` varchar(50) DEFAULT NULL,
          `user_name` varchar(50) NOT NULL,
          `user_pass` varchar(255) NOT NULL,
          `user_email` varchar(100) NOT NULL,
          `user_dash_email` varchar(100) NOT NULL,
		  `user_public_publisher_id` varchar(10) DEFAULT NULL,
          `user_timezone` varchar(50) NOT NULL DEFAULT 'America/Los_Angeles',
          `user_time_register` int(10) unsigned NOT NULL,
          `user_pass_key` varchar(255) DEFAULT NULL,
          `user_pass_time` int(10) unsigned DEFAULT NULL,
          `user_api_key` varchar(255) DEFAULT NULL,
          `user_stats202_app_key` varchar(255) DEFAULT NULL,
          `user_last_login_ip_id` bigint(20) unsigned DEFAULT NULL,
          `clickserver_api_key` varchar(255) DEFAULT NULL,
          `install_hash` varchar(255) NOT NULL,
          `user_hash` varchar(255) NOT NULL,
          `modal_status` int(1) DEFAULT NULL,
          `vip_perks_status` int(1) DEFAULT NULL,
          `user_active` int(1) NOT NULL DEFAULT '1',
          `user_deleted` int(1) NOT NULL DEFAULT '0',
		  `secret_key` char(48) DEFAULT NULL,
          `user_mods_lb` tinyint(1) unsigned NOT NULL DEFAULT '0',
          `p202_customer_api_key` char(60) DEFAULT NULL,		    
	       PRIMARY KEY (`user_id`),
		   UNIQUE KEY `user_name_2` (`user_name`),
		   KEY `user_name` (`user_name`,`user_pass`),
		   KEY `user_pass_key` (`user_pass_key`(5)),
		   KEY `user_last_login_ip_id` (`user_last_login_ip_id`),
		   KEY `user_public_publisher_id` (`user_public_publisher_id`)
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;
        ";
    $result = _mysqli_query($sql);

    //create user prefs table
    $sql = "CREATE TABLE IF NOT EXISTS `202_users_pref` (
  `user_id` mediumint(8) unsigned NOT NULL,
  `user_pref_limit` tinyint(3) unsigned NOT NULL DEFAULT '50',
  `user_pref_show` varchar(25) DEFAULT NULL,
  `user_pref_time_from` int(10) unsigned DEFAULT NULL,
  `user_pref_time_to` int(10) unsigned DEFAULT NULL,
  `user_pref_time_predefined` varchar(25) NOT NULL DEFAULT 'today',
  `user_pref_adv` tinyint(1) DEFAULT NULL,
  `user_pref_ppc_network_id` mediumint(8) unsigned DEFAULT NULL,
  `user_pref_ppc_account_id` mediumint(8) unsigned DEFAULT NULL,
  `user_pref_aff_network_id` mediumint(8) unsigned DEFAULT NULL,
  `user_pref_aff_campaign_id` mediumint(8) unsigned DEFAULT NULL,
  `user_pref_text_ad_id` mediumint(8) unsigned DEFAULT NULL,
  `user_pref_method_of_promotion` varchar(25) DEFAULT NULL,
  `user_pref_landing_page_id` mediumint(8) unsigned DEFAULT NULL,
  `user_pref_country_id` tinyint(3) unsigned DEFAULT NULL,
  `user_pref_region_id` tinyint(3) unsigned DEFAULT NULL,
  `user_pref_device_id` tinyint(3) unsigned DEFAULT NULL,
  `user_pref_browser_id` tinyint(3) unsigned DEFAULT NULL,
  `user_pref_platform_id` tinyint(3) unsigned DEFAULT NULL,
  `user_pref_isp_id` tinyint(3) unsigned DEFAULT NULL,
  `user_pref_subid` bigint(20) unsigned DEFAULT NULL,
  `user_pref_ip` varchar(100) DEFAULT NULL,
  `user_pref_dynamic_bid` tinyint(1) NOT NULL DEFAULT '0',
  `user_pref_referer` varchar(100) DEFAULT NULL,
  `user_pref_keyword` varchar(100) DEFAULT NULL,
  `user_pref_breakdown` varchar(100) NOT NULL DEFAULT 'day',
  `user_pref_chart` varchar(255) NOT NULL DEFAULT 'net',
  `user_cpc_or_cpv` char(3) NOT NULL DEFAULT 'cpc',
  `user_keyword_searched_or_bidded` varchar(255) NOT NULL DEFAULT 'searched',
  `user_pref_privacy` varchar(100) NOT NULL DEFAULT 'disabled',
  `user_pref_referer_data` varchar(10) NOT NULL DEFAULT 'browser',
  `user_tracking_domain` varchar(255) NOT NULL DEFAULT '',
  `user_pref_group_2` tinyint(3) DEFAULT NULL,
  `user_pref_group_3` tinyint(3) DEFAULT NULL,
  `user_pref_group_4` tinyint(3) DEFAULT NULL,
  `user_pref_group_1` tinyint(3) DEFAULT NULL,
  `cache_time` varchar(4) NOT NULL DEFAULT '0',
  `cb_key` varchar(250) DEFAULT NULL,
  `cb_verified` tinyint(1) NOT NULL DEFAULT '0',
  `maxmind_isp` tinyint(1) NOT NULL DEFAULT '0',
  `chart_time_range` char(10) DEFAULT 'days',
  `user_slack_incoming_webhook` text DEFAULT NULL,
  `user_pref_cloak_referer` varchar(11) DEFAULT 'origin',
  `auto_cron` tinyint(1) NOT NULL DEFAULT '0',
  `user_daily_email` char(2) NOT NULL DEFAULT '07',
  `user_auto_database_optimization_days` int(11) unsigned DEFAULT '0',
  `user_delete_data_clickid` int(10) unsigned DEFAULT NULL,		    
  `zaxaa_api_signature` varchar(250) DEFAULT NULL,
  `jvzoo_ipn_secret_key` varchar(250) DEFAULT NULL,
  `user_account_currency` char(3) NOT NULL DEFAULT 'USD',
  `revcontent_user_id` varchar(250) DEFAULT NULL,
  `revcontent_user_secret` varchar(250) DEFAULT NULL,
  `facebook_ads_linked` int(1) NOT NULL DEFAULT '0',
  `user_pref_ad_settings` varchar(11) NOT NULL DEFAULT 'show_all',
  `ipqs_api_key` varchar(250) DEFAULT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;
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
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;
		";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_conversion_logs` (
                  `conv_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                  `click_id` bigint(20) unsigned NOT NULL,
		  `transaction_id` varchar(255) DEFAULT NULL,
		  `campaign_id` mediumint(8) unsigned NOT NULL,
		  `click_payout` decimal(11,5) NOT NULL,
		  `user_id` mediumint(8) unsigned NOT NULL,
		  `click_time` int(10) NOT NULL,
		  `conv_time` int(10) NOT NULL,
		  `time_difference` text NOT NULL,
		  `ip` varchar(15) NOT NULL DEFAULT '',
		  `pixel_type` int(11) unsigned NOT NULL,
		  `user_agent` text NOT NULL,
		  `deleted` tinyint(4) NOT NULL DEFAULT '0',
                  PRIMARY KEY (`conv_id`),
                  KEY `click_id` (`click_id`),
                  KEY `user_id` (`user_id`),
                  KEY `campaign_id` (`campaign_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_conversion_touchpoints` (
                  `touchpoint_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                  `conv_id` int(11) unsigned NOT NULL,
                  `click_id` bigint(20) unsigned NOT NULL,
                  `click_time` int(10) unsigned NOT NULL,
                  `position` smallint(5) unsigned NOT NULL DEFAULT '0',
                  `created_at` int(10) unsigned NOT NULL,
                  PRIMARY KEY (`touchpoint_id`),
                  KEY `conv_id` (`conv_id`),
                  KEY `click_lookup` (`click_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_attribution_models` (
                  `model_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                  `user_id` mediumint(8) unsigned NOT NULL,
		  `model_name` varchar(255) NOT NULL,
		  `model_slug` varchar(191) NOT NULL,
		  `model_type` varchar(50) NOT NULL,
		  `weighting_config` longtext,
		  `is_active` tinyint(1) NOT NULL DEFAULT '1',
		  `is_default` tinyint(1) NOT NULL DEFAULT '0',
		  `created_at` int(10) unsigned NOT NULL,
		  `updated_at` int(10) unsigned NOT NULL,
		  PRIMARY KEY (`model_id`),
		  UNIQUE KEY `model_slug_user` (`user_id`,`model_slug`),
		  KEY `user_default` (`user_id`,`is_default`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_attribution_snapshots` (
		  `snapshot_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		  `model_id` bigint(20) unsigned NOT NULL,
		  `user_id` mediumint(8) unsigned NOT NULL,
		  `scope_type` varchar(50) NOT NULL,
		  `scope_id` bigint(20) unsigned DEFAULT NULL,
		  `date_hour` int(10) unsigned NOT NULL,
		  `lookback_start` int(10) unsigned NOT NULL,
		  `lookback_end` int(10) unsigned NOT NULL,
		  `attributed_clicks` int(10) unsigned NOT NULL DEFAULT '0',
		  `attributed_conversions` int(10) unsigned NOT NULL DEFAULT '0',
		  `attributed_revenue` decimal(12,4) NOT NULL DEFAULT '0.0000',
		  `attributed_cost` decimal(12,4) NOT NULL DEFAULT '0.0000',
		  `created_at` int(10) unsigned NOT NULL,
		  PRIMARY KEY (`snapshot_id`),
		  KEY `model_hour_scope` (`model_id`,`date_hour`,`scope_type`,`scope_id`),
		  KEY `user_hour` (`user_id`,`date_hour`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_attribution_touchpoints` (
		  `touchpoint_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		  `snapshot_id` bigint(20) unsigned NOT NULL,
		  `conv_id` int(11) unsigned NOT NULL,
		  `click_id` bigint(20) unsigned NOT NULL,
		  `position` smallint(5) unsigned NOT NULL DEFAULT '0',
		  `credit` decimal(10,5) NOT NULL DEFAULT '0.00000',
		  `weight` decimal(10,5) NOT NULL DEFAULT '0.00000',
		  `created_at` int(10) unsigned NOT NULL,
		  PRIMARY KEY (`touchpoint_id`),
		  KEY `snapshot_conv` (`snapshot_id`,`conv_id`),
		  KEY `click_lookup` (`click_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_attribution_settings` (
		  `setting_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		  `user_id` mediumint(8) unsigned NOT NULL,
                  `scope_type` varchar(50) NOT NULL,
                  `scope_id` bigint(20) unsigned DEFAULT NULL,
                  `model_id` bigint(20) unsigned NOT NULL,
                  `multi_touch_enabled` tinyint(1) unsigned NOT NULL DEFAULT '1',
                  `multi_touch_enabled_at` int(10) unsigned DEFAULT NULL,
                  `multi_touch_disabled_at` int(10) unsigned DEFAULT NULL,
                  `effective_at` int(10) unsigned NOT NULL,
                  `created_at` int(10) unsigned NOT NULL,
                  `updated_at` int(10) unsigned NOT NULL,
                  PRIMARY KEY (`setting_id`),
                  UNIQUE KEY `user_scope` (`user_id`,`scope_type`,`scope_id`),
                  UNIQUE KEY `user_scope_model` (`user_id`,`scope_type`,`scope_id`,`model_id`),
                  UNIQUE KEY `user_scope_multi_touch` (`user_id`,`scope_type`,`scope_id`,`multi_touch_enabled`),
                  KEY `model_lookup` (`model_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_attribution_audit` (
                  `audit_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                  `user_id` mediumint(8) unsigned NOT NULL,
                  `model_id` bigint(20) unsigned DEFAULT NULL,
                  `action` varchar(50) NOT NULL,
                  `metadata` longtext,
                  `created_at` int(10) unsigned NOT NULL,
                  PRIMARY KEY (`audit_id`),
                  KEY `user_lookup` (`user_id`),
                  KEY `model_lookup` (`model_id`),
                  KEY `action_lookup` (`action`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_attribution_exports` (
                  `export_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                  `user_id` mediumint(8) unsigned NOT NULL,
                  `model_id` int(11) unsigned NOT NULL,
                  `scope_type` varchar(32) NOT NULL,
                  `scope_id` bigint(20) unsigned DEFAULT NULL,
                  `start_hour` int(10) unsigned NOT NULL,
                  `end_hour` int(10) unsigned NOT NULL,
                  `requested_format` varchar(16) NOT NULL DEFAULT 'csv',
                  `status` varchar(20) NOT NULL DEFAULT 'pending',
                  `options` longtext DEFAULT NULL,
                  `webhook_url` varchar(500) DEFAULT NULL,
                  `webhook_secret` varchar(255) DEFAULT NULL,
                  `webhook_headers` text DEFAULT NULL,
                  `file_path` varchar(500) DEFAULT NULL,
                  `rows_exported` int(11) unsigned DEFAULT NULL,
                  `queued_at` int(10) unsigned NOT NULL,
                  `started_at` int(10) unsigned DEFAULT NULL,
                  `completed_at` int(10) unsigned DEFAULT NULL,
                  `failed_at` int(10) unsigned DEFAULT NULL,
                  `last_error` text DEFAULT NULL,
                  `webhook_attempted_at` int(10) unsigned DEFAULT NULL,
                  `webhook_status_code` int(11) DEFAULT NULL,
                  `webhook_response_body` mediumtext DEFAULT NULL,
                  `created_at` int(10) unsigned NOT NULL,
                  `updated_at` int(10) unsigned NOT NULL,
                  PRIMARY KEY (`export_id`),
                  KEY `model_status` (`model_id`,`status`),
                  KEY `user_status` (`user_id`,`status`),
                  KEY `queued_at` (`queued_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    //create c1 table
    $sql = "CREATE TABLE IF NOT EXISTS `202_tracking_c1` (
		  `c1_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		  `c1` varchar(350) NOT NULL,
		  PRIMARY KEY (`c1_id`),
		  KEY `c1` (`c1`(191)) KEY_BLOCK_SIZE=350
		) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;
		";
    $result = _mysqli_query($sql);

    //create c2 table
    $sql = "CREATE TABLE IF NOT EXISTS `202_tracking_c2` (
		  `c2_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		  `c2` varchar(350) NOT NULL,
		  PRIMARY KEY (`c2_id`),
		  KEY `c2` (`c2`(191)) KEY_BLOCK_SIZE=350
		) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;
		";
    $result = _mysqli_query($sql);

    //create c3 table
    $sql = "CREATE TABLE IF NOT EXISTS `202_tracking_c3` (
		  `c3_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		  `c3` varchar(350) NOT NULL,
		  PRIMARY KEY (`c3_id`),
		  KEY `c3` (`c3`(191)) KEY_BLOCK_SIZE=350
		) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;
		";
    $result = _mysqli_query($sql);

    //create c4 table
    $sql = "CREATE TABLE IF NOT EXISTS `202_tracking_c4` (
		  `c4_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		  `c4` varchar(350) NOT NULL,
		  PRIMARY KEY (`c4_id`),
		  KEY `c4` (`c4`(191)) KEY_BLOCK_SIZE=350
		) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;
		";
    $result = _mysqli_query($sql);

    //export202 - information schema

    $sql = " CREATE TABLE IF NOT EXISTS `202_export_adgroups` (
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
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_export_campaigns` (
				  `export_session_id` mediumint(8) unsigned NOT NULL,
				  `export_campaign_id` mediumint(9) NOT NULL AUTO_INCREMENT,
				  `export_campaign_name` varchar(255) NOT NULL,
				  `export_campaign_status` tinyint(1) NOT NULL,
				  `export_campaign_daily_budget` decimal(10,2) unsigned NOT NULL,
				  PRIMARY KEY (`export_campaign_id`),
				  KEY `export_session_id` (`export_session_id`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);


    $sql = "CREATE TABLE IF NOT EXISTS `202_export_keywords` (
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
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);


    $sql = "CREATE TABLE IF NOT EXISTS `202_export_sessions` (
				  `export_session_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
				  `export_session_id_public` varchar(255) NOT NULL,
				  `export_session_time` int(10) unsigned NOT NULL,
				  `export_session_ip` varchar(255) NOT NULL,
				  PRIMARY KEY (`export_session_id`),
				  KEY `session_id_public` (`export_session_id_public`(5))
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);


    $sql = "CREATE TABLE IF NOT EXISTS `202_export_textads` (
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
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);


    //tracking202 schema

    $sql = "CREATE TABLE IF NOT EXISTS `202_aff_campaigns` (
				  `aff_campaign_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
				  `aff_campaign_id_public` int(10) unsigned DEFAULT NULL,
				  `user_id` mediumint(8) unsigned NOT NULL,
				  `aff_network_id` mediumint(8) unsigned NOT NULL,
				  `aff_campaign_deleted` tinyint(1) NOT NULL DEFAULT '0',
				  `aff_campaign_name` varchar(50) NOT NULL,
				  `aff_campaign_url` text NOT NULL,
				  `aff_campaign_url_2` text DEFAULT NULL,
				  `aff_campaign_url_3` text DEFAULT NULL,
				  `aff_campaign_url_4` text DEFAULT NULL,
				  `aff_campaign_url_5` text DEFAULT NULL,
				  `aff_campaign_payout` decimal(8,2) NOT NULL,
				  `aff_campaign_cloaking` tinyint(1) NOT NULL DEFAULT '0',
				  `aff_campaign_time` int(10) unsigned NOT NULL,
				  `aff_campaign_rotate` tinyint(1) NOT NULL DEFAULT '0',
				  `aff_campaign_currency` char(3) NOT NULL DEFAULT 'USD',
  				  `aff_campaign_foreign_payout` decimal(8,2) NOT NULL,
				  PRIMARY KEY (`aff_campaign_id`),
				  KEY `aff_network_id` (`aff_network_id`),
				  KEY `aff_campaign_deleted` (`aff_campaign_deleted`),
				  KEY `user_id` (`user_id`),
				  KEY `aff_campaign_name` (`aff_campaign_name`(5)),
				  KEY `aff_campaign_id_public` (`aff_campaign_id_public`),
          KEY `aff_campaign_id` (`aff_campaign_id`,`aff_campaign_name`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_aff_networks` (
  `aff_network_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `dni_network_id` mediumint(8) DEFAULT NULL,
  `user_id` mediumint(8) unsigned NOT NULL,
  `aff_network_name` varchar(50) NOT NULL,
  `aff_network_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `aff_network_time` int(10) unsigned NOT NULL,
  PRIMARY KEY (`aff_network_id`),
  KEY `user_id` (`user_id`),
  KEY `aff_network_deleted` (`aff_network_deleted`),
  KEY `aff_network_name` (`aff_network_name`(5)),
  KEY `dni_network_id` (`dni_network_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_browsers` (
  `browser_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `browser_name` varchar(50) NOT NULL,
  PRIMARY KEY (`browser_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_charts` (
  `user_id` mediumint(8) unsigned NOT NULL,
  `data` text NOT NULL,
  `chart_time_range` varchar(255) NOT NULL DEFAULT '',
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "INSERT INTO `202_charts` (`user_id`, `data`, `chart_time_range`)
				   VALUES
				   (1, 'a:3:{i:0;a:2:{s:11:\"campaign_id\";s:1:\"0\";s:10:\"value_type\";s:6:\"clicks\";}i:1;a:2:{s:11:\"campaign_id\";s:1:\"0\";s:10:\"value_type\";s:9:\"click_out\";}i:2;a:2:{s:11:\"campaign_id\";s:1:\"0\";s:10:\"value_type\";s:5:\"leads\";}}', 'days');";
    $result = _mysqli_query($sql);

    //create the click table
    $sql = "CREATE TABLE IF NOT EXISTS `202_clicks` (
		  `click_id` bigint(20) unsigned NOT NULL,
		  `user_id` mediumint(8) unsigned NOT NULL,
		  `aff_campaign_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
		  `landing_page_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
		  `ppc_account_id` mediumint(8) unsigned NOT NULL,
		  `click_cpc` decimal(7,5) NOT NULL,
		  `click_payout` decimal(11,5) NOT NULL DEFAULT '0.00000',
		  `click_lead` tinyint(1) NOT NULL DEFAULT '0',
		  `click_filtered` tinyint(1) NOT NULL DEFAULT '0',
		  `click_bot` tinyint(1) NOT NULL DEFAULT '0',
		  `click_alp` tinyint(1) NOT NULL DEFAULT '0',
		  `click_time` int(10) unsigned NOT NULL,
		  `rotator_id` int(10) unsigned NOT NULL DEFAULT '0',
		  `rule_id` int(10) unsigned NOT NULL DEFAULT '0',
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
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_clicks_advance` (
  `click_id` bigint(20) unsigned NOT NULL,
  `text_ad_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
  `keyword_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `ip_id` bigint(20) unsigned NOT NULL,
  `country_id` bigint(20) unsigned NOT NULL,
  `region_id` bigint(20) unsigned NOT NULL,
  `city_id` bigint(20) unsigned NOT NULL,
  `platform_id` bigint(20) unsigned NOT NULL,
  `browser_id` bigint(20) unsigned NOT NULL,
  `device_id` bigint(20) unsigned NOT NULL,
  `isp_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY  (`click_id`),
  KEY `text_ad_id` (`text_ad_id`),
  KEY `keyword_id` (`keyword_id`),
  KEY `ip_id` (`ip_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_clicks_counter` (
  `click_id` bigint(20) unsigned NOT NULL auto_increment,
  PRIMARY KEY  (`click_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_clicks_record` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_clicks_site` (
  `click_id` bigint(20) unsigned NOT NULL,
  `click_referer_site_url_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `click_landing_site_url_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `click_outbound_site_url_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `click_cloaking_site_url_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `click_redirect_site_url_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY  (`click_id`),
  KEY `click_referer_site_url_id` (`click_referer_site_url_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_clicks_spy` (
  `click_id` bigint(20) unsigned NOT NULL,
  `user_id` mediumint(8) unsigned NOT NULL,
  `aff_campaign_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
  `landing_page_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
  `ppc_account_id` mediumint(8) unsigned NOT NULL,
  `click_cpc` decimal(4,2) NOT NULL,
  `click_payout` decimal(8,2) NOT NULL DEFAULT '0.00000',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_delayed_sqls` (
  `delayed_sql` text NOT NULL,
  `delayed_time` int(10) unsigned NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_ips` (
  `ip_id` bigint(20) unsigned NOT NULL auto_increment,
  `ip_address` varchar(15) NOT NULL,
  `location_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY  (`ip_id`),
  KEY `ip_address` (`ip_address`),
  KEY `location_id` (`location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_ips_v6` (
            `ip_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `ip_address` varbinary(16) NOT NULL DEFAULT '',
            `location_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
            PRIMARY KEY (`ip_id`),
            KEY `ip_address` (`ip_address`),
            KEY `location_id` (`location_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";

    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_keywords` (
  `keyword_id` bigint(20) unsigned NOT NULL auto_increment,
  `keyword` varchar(150) NOT NULL,
  PRIMARY KEY  (`keyword_id`),
  KEY `keyword` (`keyword`(150))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_landing_pages` (
  `landing_page_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` mediumint(8) unsigned NOT NULL,
  `landing_page_id_public` int(10) unsigned DEFAULT NULL,
  `aff_campaign_id` mediumint(8) unsigned NOT NULL,
  `landing_page_nickname` varchar(50) NOT NULL,
  `landing_page_url` varchar(255) NOT NULL,
  `leave_behind_page_url` varchar(255) DEFAULT '',
  `landing_page_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `landing_page_time` int(10) unsigned NOT NULL,
  `landing_page_type` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`landing_page_id`),
  KEY `landing_page_id_public` (`landing_page_id_public`),
  KEY `aff_campaign_id` (`aff_campaign_id`),
  KEY `landing_page_deleted` (`landing_page_deleted`),
  KEY `user_id` (`user_id`),
  KEY `landing_page_type` (`landing_page_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "
CREATE TABLE IF NOT EXISTS `202_last_ips` (
  `user_id` mediumint(9) NOT NULL,
  `ip_id` bigint(20) NOT NULL,
  `time` int(10) unsigned NOT NULL,
  KEY `ip_index` (`user_id`,`ip_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_locations_city` (
  `city_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `main_country_id` mediumint(8) unsigned NOT NULL,
  `city_name` varchar(50) NOT NULL DEFAULT '',
  PRIMARY KEY (`city_id`),
  KEY `city_name` (`city_name`,`main_country_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_locations_country` (
  `country_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `country_code` varchar(3) NOT NULL,
  `country_name` varchar(50) NOT NULL,
  PRIMARY KEY (`country_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_locations_region` (
  `region_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `main_country_id` mediumint(8) unsigned NOT NULL,
  `region_name` varchar(50) NOT NULL,
  PRIMARY KEY (`region_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_locations_isp` (
	  `isp_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	  `isp_name` varchar(50) NOT NULL DEFAULT '',
	  PRIMARY KEY (`isp_id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_platforms` (
  `platform_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `platform_name` varchar(50) NOT NULL,
  PRIMARY KEY (`platform_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_device_types` (
  `type_id` tinyint(1) unsigned NOT NULL,
  `type_name` varchar(50) NOT NULL,
  PRIMARY KEY (`type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_device_models` (
		  `device_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		  `device_name` varchar(50) NOT NULL,
		  `device_type` tinyint(1) NOT NULL,
		  PRIMARY KEY (`device_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_ppc_accounts` (
  `ppc_account_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` mediumint(8) unsigned NOT NULL,
  `ppc_network_id` mediumint(8) unsigned NOT NULL,
  `ppc_account_name` varchar(50) NOT NULL,
  `ppc_account_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `ppc_account_time` int(10) unsigned NOT NULL,
  `ppc_account_default` tinyint(1) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`ppc_account_id`),
  KEY `ppc_network_id` (`ppc_network_id`),
  KEY `ppc_account_deleted` (`ppc_account_deleted`),
  KEY `user_id` (`user_id`),
  KEY `ppc_account_name` (`ppc_account_name`(5)),
  KEY `ppc_account_default` (`ppc_account_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_ppc_networks` (
  `ppc_network_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` mediumint(8) unsigned NOT NULL,
  `ppc_network_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `ppc_network_name` varchar(50) NOT NULL,
  `ppc_network_time` int(10) unsigned NOT NULL,
  PRIMARY KEY (`ppc_network_id`),
  KEY `user_id` (`user_id`),
  KEY `ppc_network_deleted` (`ppc_network_deleted`),
  KEY `ppc_network_name` (`ppc_network_name`(5))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_site_domains` (
  `site_domain_id` bigint(20) unsigned NOT NULL auto_increment,
  `site_domain_host` varchar(100) NOT NULL,
  PRIMARY KEY  (`site_domain_id`),
  KEY `site_domain_host` (`site_domain_host`(10))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_site_urls` (
  `site_url_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `site_domain_id` bigint(20) unsigned NOT NULL,
  `site_url_address` text NOT NULL,
  PRIMARY KEY (`site_url_id`),
  KEY `site_url_address` (`site_url_address`(191)),
  KEY `site_domain_id` (`site_domain_id`,`site_url_address`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_sort_breakdowns` (
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
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);


    $sql = "CREATE TABLE IF NOT EXISTS `202_text_ads` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_trackers` (
  `tracker_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` mediumint(8) unsigned NOT NULL,
  `tracker_id_public` bigint(20) unsigned NOT NULL,
  `aff_campaign_id` mediumint(8) unsigned NOT NULL,
  `text_ad_id` mediumint(8) unsigned NOT NULL,
  `ppc_account_id` mediumint(8) unsigned NOT NULL,
  `landing_page_id` mediumint(8) unsigned NOT NULL,
  `rotator_id` int(11) unsigned NOT NULL DEFAULT '0',
  `click_cpc` decimal(7,5) DEFAULT NULL,
  `click_cpa` decimal(7,5) DEFAULT NULL,
  `click_cloaking` tinyint(1) NOT NULL,
  `tracker_time` int(10) unsigned NOT NULL,
  PRIMARY KEY (`tracker_id`),
  KEY `tracker_id_public` (`tracker_id_public`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_cpa_trackers` (
  `click_id` bigint(20) unsigned NOT NULL,
  `tracker_id_public` int(11) unsigned NOT NULL,
  PRIMARY KEY (`click_id`),
  KEY `tracker_id` (`tracker_id_public`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_rotations` (
			  `aff_campaign_id` mediumint(8) unsigned NOT NULL,
			  `rotation_num` tinyint(4) NOT NULL,
			  PRIMARY KEY (`aff_campaign_id`)
			) ENGINE=MEMORY DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;
			";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_alerts` (
				  `prosper_alert_id` int(11) NOT NULL,
				  `prosper_alert_seen` tinyint(1) NOT NULL,
				  UNIQUE KEY `prosper_alert_id` (`prosper_alert_id`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_offers` (
			  `user_id` mediumint(8) unsigned NOT NULL,
			  `offer_id` mediumint(10) unsigned NOT NULL,
			  `offer_seen` tinyint(1) NOT NULL DEFAULT '1',
			  UNIQUE KEY `user_id` (`user_id`,`offer_id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_pixel_types` (
  			  `pixel_type_id` TINYINT UNSIGNED NOT NULL AUTO_INCREMENT ,
  		  	  `pixel_type` VARCHAR(45) NULL ,
  			  PRIMARY KEY (`pixel_type_id`) ,
  		      UNIQUE INDEX `pixel_type_UNIQUE` (`pixel_type` ASC) 
  			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_ppc_account_pixels` (
 			  `pixel_id` mediumint(8) unsigned NOT NULL auto_increment,
  			  `pixel_code` text NOT NULL,
  			  `pixel_type_id` mediumint(8) unsigned NOT NULL,
  			  `ppc_account_id` mediumint(8) unsigned NOT NULL,
  			  PRIMARY KEY  (`pixel_id`),
          KEY `ppc_account_id` (`ppc_account_id`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_clicks_total` (
			  `click_count` int(20) unsigned NOT NULL default '0',
 			  PRIMARY KEY  (`click_count`)
			  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "INSERT IGNORE INTO `202_pixel_types` (`pixel_type`) VALUES
				('Image'),
				('Iframe'),
				('Javascript'),
				('Postback'),
				('Raw'),
        ('Bot202 Facebook Pixel Assistant');";
    $result = _mysqli_query($sql);

    $sql = "INSERT IGNORE INTO `202_device_types` (`type_id`, `type_name`)
				VALUES
					(1, 'Desktop'),
					(2, 'Mobile'),
					(3, 'Tablet'),
					(4, 'Bot');";
    $result = _mysqli_query($sql);

    $sql = "INSERT IGNORE INTO `202_clicks_total` (`click_count`) VALUES
			  (0);";
    $result = _mysqli_query($sql);


    $sql = "CREATE TABLE IF NOT EXISTS `202_api_keys` (
  `user_id` mediumint(8) unsigned NOT NULL,
  `api_key` varchar(250) NOT NULL DEFAULT '',
  `created_at` int(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_rotators` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL DEFAULT '',
  `default_url` text,
  `default_campaign` int(11) DEFAULT NULL,
  `default_lp` int(11) DEFAULT NULL,
  `auto_monetizer` char(4) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS  `202_rotator_rules` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `rotator_id` int(11) NOT NULL,
  `rule_name` varchar(255) NOT NULL DEFAULT '',
  `splittest` tinyint(1) NOT NULL DEFAULT '0',
  `status` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS  `202_rotator_rules_criteria` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `rotator_id` int(11) NOT NULL,
  `rule_id` int(11) NOT NULL,
  `type` varchar(50) NOT NULL DEFAULT '',
  `statement` varchar(50) NOT NULL DEFAULT '',
  `value` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_dirty_hours` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ppc_account_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
  `aff_campaign_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
  `user_id` mediumint(8) unsigned NOT NULL,
  `click_time_from` int(10) unsigned NOT NULL,
  `click_time_to` int(10) unsigned NOT NULL,
  `deleted` bit(1) NOT NULL DEFAULT b'0',
  `processed` bit(1) NOT NULL DEFAULT b'0',
  `ppc_network_id` mediumint(8) unsigned DEFAULT '0',
  `aff_network_id` mediumint(8) unsigned DEFAULT '0',
  `landing_page_id` mediumint(8) unsigned NOT NULL,
  `keyword_id` bigint(20) unsigned DEFAULT '0',
  `utm_medium_id` bigint(20) unsigned DEFAULT '0',
  `utm_source_id` bigint(20) unsigned DEFAULT '0',
  `utm_campaign_id` bigint(20) unsigned DEFAULT '0',
  `utm_term_id` bigint(20) unsigned DEFAULT '0',
  `utm_content_id` bigint(20) unsigned DEFAULT '0',
  `text_ad_id` mediumint(8) unsigned DEFAULT '0',
  `click_referer_site_url_id` bigint(20) unsigned DEFAULT '0',
  `country_id` bigint(20) unsigned DEFAULT '0',
  `region_id` bigint(20) unsigned DEFAULT '0',
  `city_id` bigint(20) unsigned DEFAULT '0',
  `isp_id` bigint(20) unsigned DEFAULT '0',
  `browser_id` bigint(20) unsigned DEFAULT '0',
  `device_id` bigint(20) unsigned DEFAULT '0',
  `platform_id` bigint(20) unsigned DEFAULT '0',
  `ip_id` bigint(20) unsigned DEFAULT '0',
  `c1_id` bigint(20) unsigned DEFAULT '0',
  `c2_id` bigint(20) unsigned DEFAULT '0',
  `c3_id` bigint(20) unsigned DEFAULT '0',
  `c4_id` bigint(20) unsigned DEFAULT '0',
  `variable_set_id` varchar(255) DEFAULT '0',
  `click_filtered` tinyint(1) NOT NULL DEFAULT '0',
  `click_bot` tinyint(1) NOT NULL DEFAULT '0',
  `click_alp` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`ppc_account_id`,`aff_campaign_id`,`user_id`,`click_time_from`,`click_time_to`),
  UNIQUE KEY `id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_dataengine_job` (
              `time_from` int(10) unsigned NOT NULL DEFAULT '0',
              `time_to` int(10) unsigned NOT NULL DEFAULT '0',
              `processing` tinyint(1) NOT NULL DEFAULT '0',
              `processed` tinyint(1) NOT NULL DEFAULT '0'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_google` (
  `click_id` bigint(20) unsigned NOT NULL,
  `gclid` varchar(150) NOT NULL,
  `utm_source_id` bigint(20) unsigned NOT NULL,
  `utm_medium_id` bigint(20) unsigned NOT NULL,
  `utm_campaign_id` bigint(20) unsigned NOT NULL,
  `utm_term_id` bigint(20) unsigned NOT NULL,
  `utm_content_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`click_id`,`gclid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_bing` (
  `click_id` bigint(20) unsigned NOT NULL,
  `msclkid` varchar(150) NOT NULL,
  `utm_source_id` bigint(20) unsigned NOT NULL,
  `utm_medium_id` bigint(20) unsigned NOT NULL,
  `utm_campaign_id` bigint(20) unsigned NOT NULL,
  `utm_term_id` bigint(20) unsigned NOT NULL,
  `utm_content_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`click_id`,`msclkid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_facebook` (
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

    $sql = "CREATE TABLE IF NOT EXISTS `202_utm_campaign` (
  `utm_campaign_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `utm_campaign` varchar(350) NOT NULL DEFAULT '',
  PRIMARY KEY (`utm_campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_utm_content` (
  `utm_content_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `utm_content` varchar(350) NOT NULL DEFAULT '',
  PRIMARY KEY (`utm_content_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_utm_medium` (
  `utm_medium_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `utm_medium` varchar(350) NOT NULL DEFAULT '',
  PRIMARY KEY (`utm_medium_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_utm_source` (
  `utm_source_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `utm_source` varchar(350) NOT NULL DEFAULT '',
  PRIMARY KEY (`utm_source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_utm_term` (
  `utm_term_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `utm_term` varchar(350) NOT NULL DEFAULT '',
  PRIMARY KEY (`utm_term_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_permissions` (
        `permission_id` int(11) NOT NULL AUTO_INCREMENT,
        `permission_description` varchar(50) NOT NULL,
        PRIMARY KEY (`permission_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "INSERT IGNORE INTO `202_permissions` (`permission_id`, `permission_description`)
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
        (21, 'remove_tracker'),
        (22, 'view_attribution_reports'),
        (23, 'manage_attribution_models');";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_roles` (
       `role_id` int(11) NOT NULL AUTO_INCREMENT,
       `role_name` varchar(50) NOT NULL,
       PRIMARY KEY (`role_id`)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "INSERT IGNORE INTO `202_roles` (`role_id`, `role_name`)
        VALUES
        (1, 'Super user'),
        (2, 'Admin'),
        (3, 'Campaign manager'),
        (4, 'Campaign optimizer'),
        (5, 'Campaign viewer'),
        (6, 'Publisher');";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_role_permission` (
       `role_id` int(11) NOT NULL,
       `permission_id` int(11) NOT NULL,
       KEY `role_id` (`role_id`),
       KEY `permission_id` (`permission_id`),
       CONSTRAINT `202_role_permission_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `202_roles` (`role_id`),
       CONSTRAINT `202_role_permission_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `202_permissions` (`permission_id`)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "INSERT IGNORE INTO `202_role_permission` (`role_id`, `permission_id`)
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
        (1, 22),
        (1, 23),
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
        (2, 22),
        (2, 23),
        (3, 12),
        (3, 14),
        (3, 15),
        (3, 22),
        (4, 12);";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_user_role` (
       `user_id` mediumint(8) unsigned NOT NULL,
       `role_id` int(11) NOT NULL,
       KEY `user_id` (`user_id`),
       KEY `role_id` (`role_id`),
       CONSTRAINT `202_user_role_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `202_users` (`user_id`),
       CONSTRAINT `202_user_role_ibfk_2` FOREIGN KEY (`role_id`) REFERENCES `202_roles` (`role_id`)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_custom_variables` (
       `custom_variable_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
       `ppc_variable_id` bigint(20) unsigned NOT NULL,
       `variable` varchar(350) NOT NULL DEFAULT '',
       PRIMARY KEY (`custom_variable_id`),
       KEY `variable` (`variable`(191)),
       KEY `ppc_variable_id` (`ppc_variable_id`)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_clicks_variable` (
       `click_id` bigint(20) unsigned NOT NULL,
       `variable_set_id` bigint(20) unsigned NOT NULL,
       KEY `custom_variable_id` (`variable_set_id`),
       KEY `click_id` (`click_id`)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_ppc_network_variables` (
       `ppc_variable_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
       `ppc_network_id` mediumint(8) NOT NULL,
       `name` varchar(255) NOT NULL DEFAULT '',
       `parameter` varchar(255) NOT NULL DEFAULT '',
       `placeholder` varchar(255) NOT NULL DEFAULT '',
       `deleted` tinyint(1) NOT NULL DEFAULT '0',
       PRIMARY KEY (`ppc_variable_id`),
       KEY ppc_network_id (ppc_network_id,deleted)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_variable_sets` (
       `variable_set_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
       `variables` varchar(255) NOT NULL DEFAULT '',
       KEY `custom_variable_id` (`variables`(191)),
       KEY `click_id` (`variable_set_id`)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_variable_sets2` (
  `variable_set_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `variables` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`variable_set_id`,`variables`(191)),
  KEY `custom_variable_id` (`variables`(191)),
  KEY `click_id` (`variable_set_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_cronjob_logs` (
        `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
        `last_execution_time` int(10) unsigned NOT NULL,
        PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_clicks_rotator` (
       `click_id` bigint(20) unsigned NOT NULL,
       `rotator_id` bigint(20) unsigned NOT NULL,
       `rule_id` bigint(20) unsigned NOT NULL,
       `rule_redirect_id` bigint(20) unsigned NOT NULL,
       PRIMARY KEY (`click_id`)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);


    $sql = "CREATE TABLE IF NOT EXISTS `202_rotator_rules_redirects` (
       `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
       `rule_id` int(11) NOT NULL,
       `redirect_url` text,
       `redirect_campaign` int(11) DEFAULT NULL,
       `redirect_lp` int(11) DEFAULT NULL,
       `auto_monetizer` char(4) DEFAULT NULL,
       `weight` char(3) DEFAULT '0',
       `name` text NOT NULL,
       PRIMARY KEY (`id`)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_dni_networks` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` mediumint(8) unsigned NOT NULL,
  `networkId` varchar(255) NOT NULL DEFAULT '',
  `shortDescription` varchar(255) NOT NULL,
  `favIcon` varchar(255) NOT NULL,
  `apiKey` varchar(255) NOT NULL,
  `affiliateId` int(11) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL DEFAULT '',
  `type` varchar(255) NOT NULL DEFAULT '',
  `time` int(10) unsigned NOT NULL,
  `processed` smallint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `networkId` (`networkId`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_auth_keys` (
	  `user_id` mediumint(8) NOT NULL,
	  `auth_key` varchar(64) NOT NULL,
	  `expires` int(11) NOT NULL,
	  KEY `202_auth_keys_user_id_auth_key` (`user_id`,`auth_key`),
	  KEY `202_auth_keys_expires` (`expires`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_filters` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `filter_name` enum('Clicks','Click Throughs','LP CTR','Leads','S/U','Payout','EPC','CPC','eCPA','Income','Cost','Net','ROI') DEFAULT NULL,
  `filter_condition` enum('>','<','=','>=','<=','!=') DEFAULT NULL,
  `filter_value` decimal(20,5) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_ad_network_feeds` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` mediumint(8) NOT NULL,
  `creative_group` varchar(255) NOT NULL DEFAULT '',
  `story_url` text NOT NULL,
  `feed_name` char(12) NOT NULL DEFAULT '',
  `revcontent_boost_id` text,
  `facebook_ad_set_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_ad_network_ads` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `feed_id` int(11) NOT NULL,
  `ad` text NOT NULL,
  PRIMARY KEY (`id`),
  KEY `feed_id` (`feed_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_ad_network_titles` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `feed_id` int(11) NOT NULL,
  `title` char(100) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `feed_id` (`feed_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_ad_feed_contentad_tokens` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `feed_id` int(11) NOT NULL,
  `utm_campaign` varchar(350) DEFAULT NULL,
  `utm_source` varchar(350) DEFAULT NULL,
  `utm_medium` varchar(350) DEFAULT NULL,
  `utm_content` varchar(350) DEFAULT NULL,
  `utm_term` varchar(350) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `feed_id` (`feed_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_ad_feed_outbrain_tokens` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `feed_id` int(11) NOT NULL,
  `utm_campaign` varchar(350) DEFAULT NULL,
  `utm_source` varchar(350) DEFAULT NULL,
  `utm_medium` varchar(350) DEFAULT NULL,
  `utm_content` varchar(350) DEFAULT NULL,
  `utm_term` varchar(350) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `feed_id` (`feed_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_ad_feed_taboola_tokens` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `feed_id` int(11) NOT NULL,
  `utm_campaign` varchar(350) DEFAULT NULL,
  `utm_source` varchar(350) DEFAULT NULL,
  `utm_medium` varchar(350) DEFAULT NULL,
  `utm_content` varchar(350) DEFAULT NULL,
  `utm_term` varchar(350) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `feed_id` (`feed_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_ad_feed_custom_tokens` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `feed_id` int(11) NOT NULL,
  `network` varchar(255) NOT NULL,
  `custom_token` varchar(350) NOT NULL DEFAULT '',
  `value` varchar(350) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `feed_id` (`feed_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_ad_feed_revcontent_tokens` (
       `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
       `feed_id` int(11) NOT NULL,
       `utm_campaign` varchar(350) DEFAULT NULL,
       `utm_source` varchar(350) DEFAULT NULL,
       `utm_medium` varchar(350) DEFAULT NULL,
       `utm_content` varchar(350) DEFAULT NULL,
       `utm_term` varchar(350) DEFAULT NULL,
       PRIMARY KEY (`id`),
       KEY `feed_id` (`feed_id`)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_ad_feed_facebook_tokens` (
              `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
              `feed_id` int(11) NOT NULL,
              `utm_campaign` varchar(350) DEFAULT NULL,
              `utm_source` varchar(350) DEFAULT NULL,
              `utm_medium` varchar(350) DEFAULT NULL,
              `utm_content` varchar(350) DEFAULT NULL,
              `utm_term` varchar(350) DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `feed_id` (`feed_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_ad_network_bodies` (
              `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
              `feed_id` int(11) NOT NULL,
              `body_text` char(90) NOT NULL DEFAULT '',
              PRIMARY KEY (`id`),
              KEY `feed_id` (`feed_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;";
    $result = _mysqli_query($sql);

    //set collations and char set

    $sql = "ALTER TABLE `202_ips_v6` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
    $result = _mysqli_query($sql);


    // Add publisher Ids to all existing users
    createPublisherIds();

    $sql = "CREATE TABLE IF NOT EXISTS `202_bot202_facebook_pixel_assistant` (
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

    $sql = "CREATE TABLE IF NOT EXISTS `202_bot202_facebook_pixel_content_type` (
      `content_type_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
      `content_type` varchar(50) COLLATE utf8mb4_bin DEFAULT NULL,
      `content_type_description` varchar(100) COLLATE utf8mb4_bin DEFAULT NULL,
      PRIMARY KEY (`content_type_id`),
      KEY `content_type_id` (`content_type_id`,`content_type_description`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;";
    $result = _mysqli_query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS `202_bot202_facebook_pixel_click_events` (
    `event_type_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `event_type` varchar(50) COLLATE utf8mb4_bin DEFAULT NULL,
    `event_type_description` varchar(100) COLLATE utf8mb4_bin DEFAULT NULL,
    PRIMARY KEY (`event_type_id`),
    KEY `event_type_id` (`event_type_id`,`event_type_description`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;";
    $result = _mysqli_query($sql);
  }



  function install_database_partitions()
  {
    $database = DB::getInstance();
    $db = $database->getConnection();

    // Use the static method here
    $php_version = PROSPER202::php_version_static();

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

    if (!$mysql_partitioning_fail) {
      $sql = "/*!50100 ALTER TABLE `202_clicks_tracking` PARTITION BY RANGE (click_id) (";
      $p_count = 0;
      for ($i = 1; $i <= 100; $i++) {
        $sql .= "PARTITION p" . $i . " VALUES LESS THAN (" . (500000 * $i) . ") ENGINE = InnoDB,";
        $p_count = $i;
      }
      $p_count += 1;
      $sql .= "PARTITION p" . $p_count . " VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */;";
      $result = $db->query($sql);
    }

    if (!$mysql_partitioning_fail) {
      $sql = "/*!50100 ALTER TABLE `202_tracking_c1` PARTITION BY RANGE (c1_id) (";
      $p_count = 0;
      for ($i = 1; $i <= 100; $i++) {
        $sql .= "PARTITION p" . $i . " VALUES LESS THAN (" . (500000 * $i) . ") ENGINE = InnoDB,";
        $p_count = $i;
      }
      $p_count += 1;
      $sql .= "PARTITION p" . $p_count . " VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */;";
      $result = $db->query($sql);
    }

    if (!$mysql_partitioning_fail) {
      $sql = "/*!50100 ALTER TABLE `202_tracking_c2` PARTITION BY RANGE (c2_id) (";
      $p_count = 0;
      for ($i = 1; $i <= 100; $i++) {
        $sql .= "PARTITION p" . $i . " VALUES LESS THAN (" . (500000 * $i) . ") ENGINE = InnoDB,";
        $p_count = $i;
      }
      $p_count += 1;
      $sql .= "PARTITION p" . $p_count . " VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */;";
      $result = $db->query($sql);
    }


    if (!$mysql_partitioning_fail) {
      $sql = "/*!50100 ALTER TABLE `202_tracking_c3` PARTITION BY RANGE (c3_id) (";
      $p_count = 0;
      for ($i = 1; $i <= 100; $i++) {
        $sql .= "PARTITION p" . $i . " VALUES LESS THAN (" . (500000 * $i) . ") ENGINE = InnoDB,";
        $p_count = $i;
      }
      $p_count += 1;
      $sql .= "PARTITION p" . $p_count . " VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */;";
      $result = $db->query($sql);
    }

    if (!$mysql_partitioning_fail) {
      $sql = "/*!50100 ALTER TABLE `202_tracking_c4` PARTITION BY RANGE (c4_id) (";
      $p_count = 0;
      for ($i = 1; $i <= 100; $i++) {
        $sql .= "PARTITION p" . $i . " VALUES LESS THAN (" . (500000 * $i) . ") ENGINE = InnoDB,";
        $p_count = $i;
      }
      $p_count += 1;
      $sql .= "PARTITION p" . $p_count . " VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */;";
      $result = $db->query($sql);
    }

    if (!$mysql_partitioning_fail) {
      $partition_time = $partition_start;
      $sql = "/*!50100 ALTER TABLE `202_clicks` PARTITION BY RANGE (click_time) (";
      $p_count = 0;
      for ($i = 0; $partition_time <= $partition_end; $i++) {
        if ($i > 0) {
          $partition_time = strtotime('+1 week', $partition_time);
        }
        $sql .= "PARTITION p" . $i . " VALUES LESS THAN (" . $partition_time . ") ENGINE = InnoDB,";
        $p_count = $i;
      }
      $p_count += 1;
      $sql .= "PARTITION p" . $p_count . " VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */;";
      $result = $db->query($sql);
    }

    if (!$mysql_partitioning_fail) {
      $sql = "/*!50100 ALTER TABLE `202_clicks_advance` PARTITION BY RANGE (click_id) (";
      $p_count = 0;
      for ($i = 1; $i <= 100; $i++) {
        $sql .= "PARTITION p" . $i . " VALUES LESS THAN (" . (500000 * $i) . ") ENGINE = InnoDB,";
        $p_count = $i;
      }
      $p_count += 1;
      $sql .= "PARTITION p" . $p_count . " VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */;";
      $result = $db->query($sql);
    }

    if (!$mysql_partitioning_fail) {
      $sql = "/*!50100 ALTER TABLE `202_clicks_record` PARTITION BY RANGE (click_id) (";
      $p_count = 0;
      for ($i = 1; $i <= 100; $i++) {
        $sql .= "PARTITION p" . $i . " VALUES LESS THAN (" . (500000 * $i) . ") ENGINE = InnoDB,";
        $p_count = $i;
      }
      $p_count += 1;
      $sql .= "PARTITION p" . $p_count . " VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */;";
      $result = $db->query($sql);
    }

    if (!$mysql_partitioning_fail) {
      $sql = "/*!50100 ALTER TABLE `202_clicks_site` PARTITION BY RANGE (click_id) (";
      $p_count = 0;
      for ($i = 1; $i <= 100; $i++) {
        $sql .= "PARTITION p" . $i . " VALUES LESS THAN (" . (500000 * $i) . ") ENGINE = InnoDB,";
        $p_count = $i;
      }
      $p_count += 1;
      $sql .= "PARTITION p" . $p_count . " VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */;";
      $result = $db->query($sql);
    }

    if (!$mysql_partitioning_fail) {
      $sql = "/*!50100 ALTER TABLE `202_ips` PARTITION BY RANGE (ip_id) (";
      $p_count = 0;
      for ($i = 1; $i <= 100; $i++) {
        $sql .= "PARTITION p" . $i . " VALUES LESS THAN (" . (500000 * $i) . ") ENGINE = InnoDB,";
        $p_count = $i;
      }
      $p_count += 1;
      $sql .= "PARTITION p" . $p_count . " VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */;";
      $result = $db->query($sql);
    }

    if (!$mysql_partitioning_fail) {
      $sql = "/*!50100 ALTER TABLE `202_ips_v6` PARTITION BY RANGE (ip_id) (";
      $p_count = 0;
      for ($i = 1; $i <= 100; $i++) {
        $sql .= "PARTITION p" . $i . " VALUES LESS THAN (" . (500000 * $i) . ") ENGINE = InnoDB,";
        $p_count = $i;
      }
      $p_count += 1;
      $sql .= "PARTITION p" . $p_count . " VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */;";
      $result = $db->query($sql);
    }

    if (!$mysql_partitioning_fail) {
      $sql = "/*!50100 ALTER TABLE `202_keywords` PARTITION BY RANGE (keyword_id) (";
      $p_count = 0;
      for ($i = 1; $i <= 100; $i++) {
        $sql .= "PARTITION p" . $i . " VALUES LESS THAN (" . (500000 * $i) . ") ENGINE = InnoDB,";
        $p_count = $i;
      }
      $p_count += 1;
      $sql .= "PARTITION p" . $p_count . " VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */;";
      $result = $db->query($sql);
    }

    if (!$mysql_partitioning_fail) {
      $sql = "/*!50100 ALTER TABLE `202_site_domains` PARTITION BY RANGE (site_domain_id) (";
      $p_count = 0;
      for ($i = 1; $i <= 100; $i++) {
        $sql .= "PARTITION p" . $i . " VALUES LESS THAN (" . (500000 * $i) . ") ENGINE = InnoDB,";
        $p_count = $i;
      }
      $p_count += 1;
      $sql .= "PARTITION p" . $p_count . " VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */;";
      $result = $db->query($sql);
    }

    if (!$mysql_partitioning_fail) {
      $sql = "/*!50100 ALTER TABLE `202_site_urls` PARTITION BY RANGE (site_url_id) (";
      $p_count = 0;
      for ($i = 1; $i <= 100; $i++) {
        $sql .= "PARTITION p" . $i . " VALUES LESS THAN (" . (500000 * $i) . ") ENGINE = InnoDB,";
        $p_count = $i;
      }
      $p_count += 1;
      $sql .= "PARTITION p" . $p_count . " VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */;";
      $result = $db->query($sql);
    }

    if (!$mysql_partitioning_fail) {
      $partition_time = $partition_start;
      $sql = "/*!50100 ALTER TABLE `202_dataengine` PARTITION BY RANGE (click_time) (";
      for ($i = 0; $partition_time <= $partition_end; $i++) {
        if ($i > 0) {
          $partition_time = strtotime('+1 week', $partition_time);
        }
        $sql .= "PARTITION p" . $i . " VALUES LESS THAN (" . $partition_time . ") ENGINE = InnoDB,";
        $p_count = $i;
      }
      $p_count += 1;
      $sql .= "PARTITION p" . $p_count . " VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */;";
      $result = $db->query($sql);
    }

    if (!$mysql_partitioning_fail) {
      $sql = "/*!50100 ALTER TABLE `202_clicks_variable` PARTITION BY RANGE (click_id) (";
      $p_count = 0;
      for ($i = 1; $i <= 100; $i++) {
        $sql .= "PARTITION p" . $i . " VALUES LESS THAN (" . (500000 * $i) . ") ENGINE = InnoDB,";
        $p_count = $i;
      }
      $p_count += 1;
      $sql .= "PARTITION p" . $p_count . " VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */;";
      $result = $db->query($sql);
    }

    if (!$mysql_partitioning_fail) {
      $sql = "/*!50100 ALTER TABLE `202_clicks_rotator` PARTITION BY RANGE (click_id) (";
      $p_count = 0;
      for ($i = 1; $i <= 100; $i++) {
        $sql .= "PARTITION p" . $i . " VALUES LESS THAN (" . (500000 * $i) . ") ENGINE = InnoDB,";
        $p_count = $i;
      }
      $p_count += 1;
      $sql .= "PARTITION p" . $p_count . " VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */;";
      $result = $db->query($sql);
    }
  }
}
