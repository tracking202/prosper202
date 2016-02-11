<?php

class Slack {

  private $webhook_url;

  function __construct($webhook_url){
    $this->webhook_url = $webhook_url;
  }

  public function push($placeholder, $vars = array()){
  	$message = $this->getMessage($placeholder, $vars);
  	return $this->sendMessage($message);
  }

  private function getMessage($placeholder, $vars = array()) {
  	static $messages = array(
  		
  		//Traffic Source
  		'traffic_source_created' => 'Traffice Source: [name] Created by #[user]',
  		'traffic_source_deleted' => 'Traffice Source: [name] Deleted by #[user]',
  		'traffic_source_name_changed' => 'Traffice Source Name changed from: [old_name] to: [new_name] by #[user]',

  		//Traffic Source Account
  		'traffic_source_account_created' => 'Traffice Source Account: [account_name] added to: [network_name] by #[user]',
  		'traffic_source_account_deleted' => 'Traffice Source Account: [account_name] Deleted by #[user]',
  		'traffic_source_account_name_changed' => 'Traffice Source: [network_name] Account Name changed from: [old_account_name] to: [new_account_name] by #[user]',
  		'traffic_source_account_pixel_added' => '[type] Pixel Code added to Traffic Source: [network_name] Account: [account_name] by #[user]',
  		'traffic_source_account_pixel_code_changed' => 'Traffic Source: [network_name] Account: [account_name] Pixel Code changed by #[user]',
  		'traffic_source_account_pixel_type_changed' => 'Traffic Source: [network_name] Account: [account_name] Pixel Type changed from: [old_pixel_type] to: [new_pixel_type] by #[user]',
  		
  		//Campaign Category
  		'campaign_category_created' => 'Campaign Category: [name] Created by #[user]',
  		'campaign_category_deleted' => 'Campaign Category: [name] Deleted by #[user]',
  		'campaign_category_name_changed' => 'Campaign Category Name changed from: [old_name] to: [new_name] by #[user]',

  		//Campaign
  		'campaign_created' => 'Campaign: [name] Created by #[user]',
  		'campaign_name_changed' => 'Campaign Name changed from: [old_name] to: [new_name] by #[user]',
  		'campaign_category_changed' => 'Campaign: [name] Category changed from [old_category] to: [new_category] by #[user]',
  		'campaign_category_rotation_changed' => 'Campaign: [name] Rotations turned [status] by #[user]',
  		'campaign_url_changed' => 'Campaign: [name] URL changed from: [old_url] to: [new_url] by #[user]',
  		'campaign_payout_changed' => 'Campaign: [name] Payout changed from: $[old_payout] to: $[new_payout] by #[user]',
  		'campaign_cloaking_changed' => 'Campaign: [name] Cloaking turned [status] by #[user]',

  		//Simple Landing Page
  		'simple_landing_page_created' => 'Simple Landing Page: [name] Created by #[user]',
  		'simple_landing_page_deleted' => 'Simple Landing Page: [name] Deleted by #[user]',
  		'simple_landing_page_campaign_changed' => 'Simple Landing Page: [name] Campaign changed from: [old_campaign] to: [new_campaign] by #[user]',
  		'simple_landing_page_name_changed' => 'Simple Landing Page Nickname changed from: [old_name] to: [new_name] by #[user]',
  		'simple_landing_page_url_changed' => 'Simple Landing Page: [name] URL changed from: [old_url] to: [new_url] by #[user]',

  		//Advanced Landing Page
  		'advanced_landing_page_created' => 'Advanced Landing Page: [name] Created by #[user]',
  		'advanced_landing_page_deleted' => 'Advanced Landing Page: [name] Deleted by #[user]',
  		'advanced_landing_page_name_changed' => 'Advanced Landing Page Nickname changed from: [old_name] to: [new_name] by #[user]',
  		'advanced_landing_page_url_changed' => 'Advanced Landing Page: [name] URL changed from: [old_url] to: [new_url] by #[user]',

  		//Text Ad
  		'ad_copy_created' => 'Ad Copy: [name] Created by #[user]',
  		'ad_copy_deleted' => 'Ad Copy: [name] Deleted by #[user]',
  		'ad_copy_campaign_changed' => 'Ad Copy: [name] Campaign changed from: [old_campaign] to: [new_campaign] by #[user]',
  		'ad_copy_landing_page_changed' => 'Ad Copy: [name] Landing Page changed from: [old_lp] to: [new_lp] by #[user]',
  		'ad_copy_name_changed' => 'Ad Copy Name changed from: [old_name] to: [new_name] by #[user]',
  		'ad_copy_headline_changed' => 'Ad Copy: [name] Headline changed from: [old_headline] to: [new_headline] by #[user]',
  		'ad_copy_description_changed' => 'Ad Copy: [name] Description changed from: [old_description] to: [new_description] by #[user]',
  		'ad_copy_display_url_changed' => 'Ad Copy: [name] Display URL changed from: [old_url] to: [new_url] by #[user]',

  		//Smart Rotator
  		'rotator_created' => 'Smart Redirector: [name] Created by #[user]',
  		'rotator_deleted' => 'Smart Redirector: [name] Deleted by #[user]',

  		//Smart Redirector Defaults
  		'rotator_defaults_added' => 'Smart Redirector: [name] Defaults set to: [default_type] - [default_value] by #[user]',
  		'rotator_defaults_added_to_monetizer' => 'Smart Redirector: [name] Defaults set to: Auto Monetizer by #[user]',
  		'rotator_defaults_changed' => 'Smart Redirector: [name] Defaults changed from: [default_from_type] - [default_from_value] to: [default_to_type] - [default_to_value] by #[user]',
  		'rotator_defaults_changed_from_monetizer' => 'Smart Redirector: [name] Defaults changed from: Auto Monetizer to: [default_to_type] - [default_to_value] by #[user]',
  		'rotator_defaults_changed_to_monetizer' => 'Smart Redirector: [name] Defaults changed from: [default_from_type] - [default_from_value] to: Auto Monetizer by #[user]',
  		
  		//Smart Redirector Rule
  		'rotator_rule_created' => 'Smart Redirector: [rotator] Rule: [rule] Added by #[user]',
  		'rotator_rule_deleted' => 'Smart Redirector: [rotator] Rule: [rule] Removed by #[user]',
  		'rotator_rule_name_changed' => 'Smart Redirector: [rotator] Rule Name changed from: [old_name] to: [new_name] by #[user]',
  		'rotator_rule_status_changed' => 'Smart Redirector: [rotator] Rule: [rule] Status changed from: [old_status] to: [new_status] by #[user]',
  		'rotator_rules_criteria_created' => 'Smart Redirector: [rotator] Rule: [rule] Criteria: If [criteria] Added by #[user]',
  		'rotator_rules_criteria_deleted' => 'Smart Redirector: [rotator] Rule: [rule] Criteria: If [criteria] Removed by #[user]',

  		//Smart Redirector Rule Redirect
  		'rotator_redirect_changed' => 'Smart Redirector: [rotator] Rule: [rule] Redirect changed from: [redirect_from_type] - [redirect_from_value] to: [redirect_to_type] - [redirect_to_value] by #[user]',
  		'rotator_redirect_changed_from_monetizer' => 'Smart Redirector: [rotator] Rule: [rule] Redirect changed from: Auto Monetizer to: [redirect_to_type] - [redirect_to_value] by #[user]',
  		'rotator_redirect_changed_to_monetizer' => 'Smart Redirector: [rotator] Rule: [rule] Redirect changed from: [redirect_from_type] - [redirect_from_value] to: Auto Monetizer by #[user]',

      //Simple Landing Page Code Generated
      'simple_landing_page_code_generated' => 'Simple Landing Page: [name] Code:\n - Simple Landing Category: [network]\n - Simple Landing Page Campaign: [campaign]\nCreated by #[user]',

      //Advanced Landing Page Code Generated
      'advanced_landing_page_code_generated' => 'Advanced Landing Page: [name] Code:\n - Offers Selected:\n [offers]Created by #[user]',

      //Tracking Link
      'tracking_link_created' => '[type] Tracking Link: Id:[id] Created by #[user]',
      'tracking_link_deleted' => '[type] Tracking Link: Id:[id] Deleted by #[user]',

      //Tracking Link Changed
      'tracking_link_category_changed' => '[type] Tracking Link: Id:[id] Category changed from: [old_category] to: [new_category] by #[user]',
      'tracking_link_campaign_changed' => '[type] Tracking Link: Id:[id] Campaign changed from: [old_campaign] to: [new_campaign] by #[user]',
      'tracking_link_method_of_promotion_changed' => '[type] Tracking Link: Id:[id] Method Of Promotion changed from: [old_method] to: [new_method] by #[user]',
  	  'tracking_link_landing_page_changed' => '[type] Tracking Link: Id:[id] Landing Page changed from: [old_lp] to: [new_lp] by #[user]',
      'tracking_link_text_ad_added' => '[type] Tracking Link: Id:[id] Text Ad: [ad] Added by #[user]',
      'tracking_link_text_ad_removed' => '[type] Tracking Link: Id:[id] Text Ad: [ad] Removed by #[user]',
      'tracking_link_text_ad_changed' => '[type] Tracking Link: Id:[id] Text Ad changed from: [old_ad] to: [new_ad] by #[user]',
      'tracking_link_cloaking_changed' => '[type] Tracking Link: Id:[id] Cloaking changed from: [old_type] to: [new_type] by #[user]',
      'tracking_link_pcc_network_changed' => '[type] Tracking Link: Id:[id] Traffic Source changed from: [old_source] to: [new_source] by #[user]',
      'tracking_link_ppc_account_changed' => '[type] Tracking Link: Id:[id] Traffic Source Account changed from: [old_account] to: [new_account] by #[user]',
      'tracking_link_cost_type_changed' => '[type] Tracking Link: Id:[id] Cost Type changed from: [old_type] to: [new_type] by #[user]',
      'tracking_link_cost_value_changed' => '[type] Tracking Link: Id:[id] Cost Value changed from: $[old_value] to: $[new_value] by #[user]',
      'tracking_link_rotator_changed' => '[type] Tracking Link: Id:[id] Rotator changed from: [old_rotator] to: [new_rotator] by #[user]',

      //User Personal Settings
      'user_time_zone_changed' => '#[user] changed the timezone setting from: [old_zone] to: [new_zone]',
      'user_cache_time_changed' => '#[user] changed the cache time setting from: [old_time] to: [new_time]',
      'user_keyword_preference_changed' => '#[user] changed the Keyword Preference setting from: [old_pref] to: [new_pref]',
      'user_referer_changed' => '#[user] changed the Referer Preference setting from: [old_pref] to: [new_pref]',
      'user_pref_cloak_referer_changed' => '#[user] changed the Cloaked Referer setting from: [old_pref] to: [new_pref]',
      'user_email_changed' => '#[user] changed the main account email from: [old_email] to: [new_email]',
      'user_added_app_api_key' => '#[user] created a Prosper202 App API Key',
      'user_removed_app_api_key' => '#[user] removed a Prosper202 App API Key',
      'user_updated_clickserver_api_key' => '#[user] entered a new Prosper202 ClickServer API Key',

      //3rd Party Integrations
      'cb_key_updated' => '#[user] entered a new ClickBank Secret Key',
      'cb_key_verified' => 'Your ClickBank Notification URL has been verified',
      'user_slack_incoming_webhook_updated' => '#[user] entered a new Slack Incoming Webhook URL',

      //User Management
      'user_management_user' => '#[user] [type] a Prosper202 ClickServer User: #[username] ([role])',

      //Delete Click Data
      'click_data_deleted' => '#[user] deleted all click data prior to: [date]',

      //MaxMind ISP/Carrier Lookup
      'maxmind_isp_changed' => '#[user] [type] MaxMind ISP/Carrier Lookup',

      //Failed Login Attempt
      'failed_login' => 'Failed Login Attempt:\n - Username: [username]\n - IP Address: [ip]',

    );

  	$message = $messages[$placeholder];

  	foreach($vars as $key => $value){
	    $message = str_replace('['.$key.']', $value, $message);
	}

	return $message;
  }

  private function sendMessage($message) {

    $data = "payload=" . json_encode(array(
            "channel"  		=> "#prosper202",
            "text"      	=> urlencode($message),
            "username" 		=> $_SERVER['SERVER_NAME'],
            "icon_url"    	=> "https://s3-us-west-2.amazonaws.com/slack-files2/avatars/2015-02-28/3879263534_1958d0336574bea6f52d_48.jpg"
    		));

    $ch = curl_init($this->webhook_url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);

    return $result;
  }
}