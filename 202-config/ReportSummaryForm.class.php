<?php

/**
 * ReportSummaryForm contains methods to work with the report summaries.
 *  
 * @author Ben Rotz
 * @since 2008-11-04 11:43 MST
 */

// Include dependencies.
@set_time_limit(0);
require_once dirname(__FILE__) . "/ReportBasicForm.class.php";

class ReportSummaryForm extends ReportBasicForm {

	// +-----------------------------------------------------------------------+
	// | CONSTANTS                                                             |
	// +-----------------------------------------------------------------------+
	const DEBUG = 'MO_DEBUG';

	private static $DISPLAY_LEVEL_ARRAY = array(ReportBasicForm::DISPLAY_LEVEL_TITLE,ReportBasicForm::DISPLAY_LEVEL_CLICK_COUNT,ReportBasicForm::DISPLAY_LEVEL_LEAD_COUNT,ReportBasicForm::DISPLAY_LEVEL_SU,ReportBasicForm::DISPLAY_LEVEL_PAYOUT,ReportBasicForm::DISPLAY_LEVEL_EPC,ReportBasicForm::DISPLAY_LEVEL_CPC,ReportBasicForm::DISPLAY_LEVEL_INCOME,ReportBasicForm::DISPLAY_LEVEL_COST,ReportBasicForm::DISPLAY_LEVEL_NET,ReportBasicForm::DISPLAY_LEVEL_ROI);
	private static $DETAIL_LEVEL_ARRAY = array(ReportBasicForm::DETAIL_LEVEL_PPC_NETWORK,ReportBasicForm::DETAIL_LEVEL_PPC_ACCOUNT,ReportBasicForm::DETAIL_LEVEL_AFFILIATE_NETWORK,ReportBasicForm::DETAIL_LEVEL_CAMPAIGN,ReportBasicForm::DETAIL_LEVEL_LANDING_PAGE,ReportBasicForm::DETAIL_LEVEL_KEYWORD,ReportBasicForm::DETAIL_LEVEL_TEXT_AD,ReportBasicForm::DETAIL_LEVEL_REFERER,ReportBasicForm::DETAIL_LEVEL_COUNTRY,ReportBasicForm::DETAIL_LEVEL_REGION,ReportBasicForm::DETAIL_LEVEL_CITY,ReportBasicForm::DETAIL_LEVEL_ISP,ReportBasicForm::DETAIL_LEVEL_DEVICE_NAME,ReportBasicForm::DETAIL_LEVEL_DEVICE_TYPE,ReportBasicForm::DETAIL_LEVEL_BROWSER,ReportBasicForm::DETAIL_LEVEL_PLATFORM,ReportBasicForm::DETAIL_LEVEL_IP,ReportBasicForm::DETAIL_LEVEL_UTM_CAMPAIGN,ReportBasicForm::DETAIL_LEVEL_UTM_CONTENT,ReportBasicForm::DETAIL_LEVEL_UTM_MEDIUM,ReportBasicForm::DETAIL_LEVEL_UTM_SOURCE,ReportBasicForm::DETAIL_LEVEL_UTM_TERM,ReportBasicForm::DETAIL_LEVEL_C1,ReportBasicForm::DETAIL_LEVEL_C2,ReportBasicForm::DETAIL_LEVEL_C3,ReportBasicForm::DETAIL_LEVEL_C4,/*ReportBasicForm::DETAIL_LEVEL_CUSTOM_VAR_PARAMETER,ReportBasicForm::DETAIL_LEVEL_CUSTOM_VAR_VALUE*/ReportBasicForm::DETAIL_LEVEL_ROTATOR,ReportBasicForm::DETAIL_LEVEL_ROTATOR_RULE,ReportBasicForm::DETAIL_LEVEL_ROTATOR_RULE_REDIRECT,ReportBasicForm::DETAIL_LEVEL_TRANSACTIONS,ReportBasicForm::DETAIL_LEVEL_PUBLISHERS);
	private static $SORT_LEVEL_ARRAY = array(ReportBasicForm::SORT_NAME,ReportBasicForm::SORT_CLICK,ReportBasicForm::SORT_LEAD,ReportBasicForm::SORT_SU,ReportBasicForm::SORT_PAYOUT,ReportBasicForm::SORT_EPC,ReportBasicForm::SORT_CPC,ReportBasicForm::SORT_INCOME,ReportBasicForm::SORT_COST,ReportBasicForm::SORT_NET,ReportBasicForm::SORT_ROI);
	
	// +-----------------------------------------------------------------------+
	// | PRIVATE VARIABLES                                                     |
	// +-----------------------------------------------------------------------+
	
	/* These are used to store the report data */
	protected $report_data;
	/**
	 * Used to throw tabindexes on elements
	 * @var unknown_type
	 */
	private $tabIndexArray = array();

	// +-----------------------------------------------------------------------+
	// | PUBLIC METHODS                                                        |
	// +-----------------------------------------------------------------------+
	
	/**
	 * Returns the DISPLAY_LEVEL_ARRAY
	 * @return array
	 */
	function getDisplayArray() {
		$tmp_array = array();
		foreach($this->getDisplay() AS $display_item_key) {
			$tmp_array[] = $display_item_key;
		}
		foreach(self::$DISPLAY_LEVEL_ARRAY AS $additional_item) {
			if(!in_array($additional_item,$tmp_array)) {
				$tmp_array[] = $additional_item;
			}
		}
		return $tmp_array;
	}
	
	/**
	 * Returns the DETAIL_LEVEL_ARRAY
	 * @return array
	 */
	static function getDetailArray() {
		return self::$DETAIL_LEVEL_ARRAY;
	}
	
	/**
	 * Returns the SORT_LEVEL_ARRAY
	 * @return array
	 */
	static function getSortArray() {
		return self::$SORT_LEVEL_ARRAY;
	}
	
	/**
	 * Returns the display (overloaded from ReportBasicForm)
	 * @return array
	 */
	function getDisplay() {
		if (is_null($this->display)) {
            $this->display = array(ReportBasicForm::DISPLAY_LEVEL_TITLE,ReportBasicForm::DISPLAY_LEVEL_CLICK_COUNT,ReportBasicForm::DISPLAY_LEVEL_CLICK_OUT_COUNT,ReportBasicForm::DISPLAY_LEVEL_CTR,ReportBasicForm::DISPLAY_LEVEL_LEAD_COUNT,ReportBasicForm::DISPLAY_LEVEL_SU,ReportBasicForm::DISPLAY_LEVEL_PAYOUT,ReportBasicForm::DISPLAY_LEVEL_EPC,ReportBasicForm::DISPLAY_LEVEL_CPC,ReportBasicForm::DISPLAY_LEVEL_INCOME,ReportBasicForm::DISPLAY_LEVEL_COST,ReportBasicForm::DISPLAY_LEVEL_NET,ReportBasicForm::DISPLAY_LEVEL_ROI);
		}
		return $this->display;
	}
	
	/**
	 * Returns the report_data
	 * @return ReportSummaryGroupForm
	 */
	function getReportData() {
		if (is_null($this->report_data)) {
			$this->report_data = new ReportSummaryGroupForm();
			$this->report_data->setDetailId(0);
			$this->report_data->setParentClass($this);
		}
		return $this->report_data;
	}
	
	/**
	 * Sets the report_data
	 * @param RevenueReportGroupForm
	 */
	function setReportData($arg0) {
	 
		$this->report_data = $arg0;
	}
	
	/**
	 * Adds report_data
	 * @param $arg0
	 */
	function addReportData($arg0) {
		$this->getReportData()->populate($arg0);
	}
	
	/**
	 * Translates the detail level into a key
	 * @return string
	 */
	static function translateDetailKeyById($arg0) {
		if ($arg0 == ReportBasicForm::DETAIL_LEVEL_NONE) {
			return "";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_PPC_NETWORK) {
			return "ppc_network_id";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_PPC_ACCOUNT) {
			return "ppc_account_id";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_AFFILIATE_NETWORK) {
			return "affiliate_network_id";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_CAMPAIGN) {
			return "affiliate_campaign_id";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_LANDING_PAGE) {
			return "landing_page_id";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_KEYWORD) {
			return "keyword_id";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_TEXT_AD) {
			return "text_ad_id";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_REFERER) {
			return "referer_id";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_REDIRECT) {
			return "redirect_id";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_COUNTRY) {
			return "country_id";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_REGION) {
			return "region_id";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_CITY) {
			return "city_id";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_ISP) {
			return "isp_id";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_DEVICE_NAME) {
			return "device_id";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_DEVICE_TYPE) {
			return "type_id";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_BROWSER) {
			return "browser_id";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_PLATFORM) {
			return "platform_id";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_IP) {
			return "ip_id";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_UTM_CAMPAIGN) {
			return "utm_campaign_id";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_UTM_CONTENT) {
			return "utm_content_id";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_UTM_MEDIUM) {
			return "utm_medium_id";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_UTM_SOURCE) {
			return "utm_source_id";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_UTM_TERM) {
			return "utm_term_id";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C1) {
			return "c1";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C2) {
			return "c2";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C3) {
			return 'c3';
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C4) {
			return "c4";
		} /*else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_CUSTOM_VAR_PARAMETER) {
			return "ppc_variable_id";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_CUSTOM_VAR_VALUE) {
			return "custom_variable_id";
		}*/ else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_ROTATOR) {
			return "rotator_id";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_ROTATOR_RULE) {
			return "rule_id";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_ROTATOR_RULE_REDIRECT) {
			return "rule_redirect_id";
		} else if($arg0 == ReportBasicForm::DETAIL_LEVEL_TRANSACTIONS) {
			return "transaction_id";
		} else if($arg0 == ReportBasicForm::DETAIL_LEVEL_PUBLISHERS) {
			return "publisher_id";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_INTERVAL) {
			return "interval_id";
		} else {
			return "";
		}
	}
	
	/**
	 * Translates the detail level into a function
	 * @return string
	 */
	static function translateDetailFunctionById($arg0) {
		if ($arg0 == ReportBasicForm::DETAIL_LEVEL_NONE) {
			return "";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_PPC_NETWORK) {
			return "ReportSummaryPpcNetworkForm";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_PPC_ACCOUNT) {
			return "ReportSummaryPpcAccountForm";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_AFFILIATE_NETWORK) {
			return "ReportSummaryAffiliateNetworkForm";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_CAMPAIGN) {
			return "ReportSummaryCampaignForm";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_LANDING_PAGE) {
			return "ReportSummaryLandingPageForm";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_KEYWORD) {
			return "ReportSummaryKeywordForm";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_TEXT_AD) {
			return "ReportSummaryTextAdForm";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_REFERER) {
			return "ReportSummaryRefererForm";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_REDIRECT) {
			return "ReportSummaryRedirectForm";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_COUNTRY) {
			return "ReportSummaryCountryForm";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_REGION) {
			return "ReportSummaryRegionForm";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_CITY) {
			return "ReportSummaryCityForm";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_ISP) {
			return "ReportSummaryIspForm";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_DEVICE_NAME) {
			return "ReportSummaryDeviceNameForm";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_DEVICE_TYPE) {
			return "ReportSummaryDeviceTypeForm";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_BROWSER) {
			return "ReportSummaryBrowserForm";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_PLATFORM) {
			return "ReportSummaryPlatformForm";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_IP) {
			return "ReportSummaryIpForm";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_UTM_CAMPAIGN) {
			return "ReportSummaryUtmCampaignForm";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_UTM_CONTENT) {
			return "ReportSummaryUtmContentForm";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_UTM_MEDIUM) {
			return "ReportSummaryUtmMediumForm";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_UTM_SOURCE) {
			return "ReportSummaryUtmSourceForm";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_UTM_TERM) {
			return "ReportSummaryUtmTermForm";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C1) {
			return "ReportSummaryC1Form";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C2) {
			return "ReportSummaryC2Form";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C3) {
			return 'ReportSummaryC3Form';
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C4) {
			return "ReportSummaryC4Form";
		}/* else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_CUSTOM_VAR_PARAMETER) {
			return "ReportSummaryCustomVarParameterForm";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_CUSTOM_VAR_VALUE) {
			return "ReportSummaryCustomVarValueForm";
		}*/ else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_ROTATOR) {
			return "ReportSummaryRotatorForm";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_ROTATOR_RULE) {
			return "ReportSummaryRotatorRuleForm";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_ROTATOR_RULE_REDIRECT) {
			return "ReportSummaryRotatorRuleRedirectForm";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_TRANSACTIONS) {
			return "ReportSummaryTransactionsForm";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_PUBLISHERS) {
			return "ReportSummaryPublishersForm";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_INTERVAL) {
			return "ReportSummaryIntervalForm";
		} else {
			return "";
		}
	}
	
	// +-----------------------------------------------------------------------+
	// | RELATION METHODS                                                      |
	// +-----------------------------------------------------------------------+

	// +-----------------------------------------------------------------------+
	// | HELPER METHODS                                                        |
	// +-----------------------------------------------------------------------+
	
	/**
	 * Returns details in a group by string
	 * @param $arg0
	 * @return String
	 */
	function getGroupBy() {
		$details = $this->getDetails();
		$detail_key_array = array();
		foreach($details AS $detail_id) {
			$key = self::translateDetailKeyById($detail_id);
			if(strlen($key)>0) {
				$detail_key_array[] = self::translateDetailKeyById($detail_id);
			}
		}
		$detail_list = '';
		if(count($detail_key_array)>0) {
			$detail_key_array_wrapped=array_map(array($this, 'wrapGroupBy'),$detail_key_array);
		    $detail_list = 'GROUP BY ' . implode(',', $detail_key_array_wrapped);
		}

		return $detail_list;
	}
	
	function wrapGroupBy($gb){
	    switch ($gb) {
	        case 'ppc_network_id':
	        $gb='2pn.'.$gb;
	        break;
	        case 'ppc_account_id':
	        case 'landing_page_id':
	        case 'keyword_id':
	        case 'text_ad_id':
	        case 'country_id':
	        case 'region_id':
            case 'city_id':
            case 'isp_id':
            case 'device_id':
            case 'browser_id':
            case 'platform_id':
			case 'ip_id':
                $gb='2c.'.$gb;
                $groupby_null='0';
				break;
			case 'c1':
			$gb='2tc1.'.$gb;
				$groupby_null='0';
				break;
			case 'c2':
			$gb='2tc2.'.$gb;
				$groupby_null='0';
				break;
			case 'c3':
			$gb='2tc3.'.$gb;
				$groupby_null='0';
				break;
			case 'c4':
				$gb='2tc4.'.$gb;
				$groupby_null='0';
				break;	
            case 'utm_campaign_id':
            case 'utm_content_id':
            case 'utm_medium_id':
            case 'utm_source_id':
            case 'utm_term_id':
            case 'utm_campaign_id': {                
                $gb='2u'.substr($gb, 4, 3).".".$gb;
                $groupby_null='0';
                break;
            }  
            case 'transaction_id':
                $groupby_null='';
                break;
	        default:
	            $groupby_null='0';
	        break;
	    }
	   // return "IFNULL(".$gb.", '".$groupby_null."')";
	   return "IF(".$gb." is null or ".$gb." = '0', '', ".$gb.")";
	}
	
	/**
	 * Returns query in a string
	 * @return String
	 */
	function getQuery($user_id,$user_row) {

		$database = DB::getInstance();
		$db = $database->getConnection(); 
		
		if($_SESSION['publisher']==false){ //user is able to see all camapigns
		    $user_id_query = " != '0' ";
		}
		else{
		    $user_id_query = " = '" . $_SESSION['user_own_id'] . "' "; //user can only see thier campaigns
		}
		
		$info_sql = '';
		//select regular setup
		$info_sql .= "
			SELECT SQL_CALC_FOUND_ROWS
		";
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_PPC_NETWORK)) {
			$info_sql .= "
				2pn.ppc_network_id as ppc_network_id,
				2pn.ppc_network_name,
			";
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_PPC_ACCOUNT)) {
			$info_sql .= "
				2c.ppc_account_id,
				2pa.ppc_account_name,
			";
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_AFFILIATE_NETWORK)) {
			$info_sql .= "
				2ac.aff_network_id AS affiliate_network_id,
				2an.aff_network_name AS affiliate_network_name,
			";
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_CAMPAIGN)) {
			$info_sql .= "
				2c.aff_campaign_id AS affiliate_campaign_id,
				2ac.aff_campaign_name AS affiliate_campaign_name,
			";
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_LANDING_PAGE)) {
			$info_sql .= "
				2c.landing_page_id,
				2lp.landing_page_nickname AS landing_page_name,
			";
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_KEYWORD)) {
			$info_sql .= "
				2c.keyword_id,
				2k.keyword AS keyword_name,
			";
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_TEXT_AD)) {
			$info_sql .= "
				2c.text_ad_id,
				2ta.text_ad_name,
			";
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_REFERER)) {
			$info_sql .= "
				2c.click_referer_site_url_id AS referer_id,
				2suf.site_url_address AS referer_name,
			";
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_COUNTRY)) {
			$info_sql .= "
				2c.country_id,
				2cy.country_name AS country_name,
			";
		}

		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_REGION)) {
			$info_sql .= "
				2c.region_id,
				2rg.region_name AS region_name,
			";
		}

		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_CITY)) {
			$info_sql .= "
				2c.city_id,
				2ci.city_name AS city_name,
			";
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_ISP)) {
			$info_sql .= "
				2c.isp_id,
				2is.isp_name AS isp_name,
			";
		}

		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_DEVICE_NAME)) {
			$info_sql .= "
				2c.device_id,
				2d.device_name AS device_name,
			";
		}

		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_DEVICE_TYPE)) {
			$info_sql .= "
				2dt.type_id AS type_id,
				2dt.type_name AS type_name,
			";
		}

		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_BROWSER)) {
			$info_sql .= "
				2c.browser_id,
				2b.browser_name AS browser_name,
			";
		}

		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_PLATFORM)) {
			$info_sql .= "
				2c.platform_id,
				2p.platform_name AS platform_name,
			";
		}

		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_IP)) {

			if(isset($_SESSION['ipv6'])){
				 $inet6_ntoa='inet6_ntoa'; //decodes for display etc
				 $inet6_aton='inet6_aton'; //encodes for db
			}
			else{
				 $inet6_ntoa='';
				 $inet6_aton='';    
			}

			$info_sql .= "
				2c.ip_id,
				IFNULL(".$inet6_ntoa."(2i6.ip_address),2i.ip_address) AS ip_name,
			";
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_REDIRECT)) {
			$info_sql .= "
				2cs.click_redirect_site_url_id AS redirect_id,
				2sur.site_url_address AS redirect_name,
			";
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_UTM_CAMPAIGN)) {
			$info_sql .= "
			IF(2c.utm_campaign_id is null or 2c.utm_campaign_id = '0', '', 2c.utm_campaign_id) AS utm_campaign_id,
				2ucam.utm_campaign,
			";
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_UTM_CONTENT)) {
			$info_sql .= "
			IF(2c.utm_content_id is null or 2c.utm_content_id = '0', '', 2c.utm_content_id) AS utm_content_id,
				2ucon.utm_content,
			";
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_UTM_MEDIUM)) {
			$info_sql .= "
			IF(2c.utm_medium_id is null or 2c.utm_medium_id = '0', '', 2c.utm_medium_id) AS utm_medium_id,
				2umed.utm_medium,
			";
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_UTM_SOURCE)) {
			$info_sql .= "
			IF(2c.utm_source_id is null or 2c.utm_source_id = '0', '', 2c.utm_source_id) AS utm_source_id,
				2usou.utm_source,
			";
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_UTM_TERM)) {
			$info_sql .= "
			IF(2c.utm_term_id is null or 2c.utm_term_id = '0', '', 2c.utm_term_id) AS utm_term_id,
				2uter.utm_term,
			";
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C1)) {
			$info_sql .= "
			IF(2tc1.c1 is null, '', 2tc1.c1) AS c1,
			IF(2c.c1_id is null or 2c.c1_id= '0', '', 2c.c1_id) AS c1_id,
			";
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C2)) {
			$info_sql .= "
            IF(2tc2.c2 is null, '', 2tc2.c2) AS c2,
			IF(2c.c2_id is null or 2c.c2_id= '0', '', 2c.c2_id) AS c2_id,
			";
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C3)) {
			$info_sql .= "
            2c.c3_id,
			IF(c3 is null or c3 = '0', '', c3) AS c3,
			";
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C4)) {
			$info_sql .= "
			2c.c4_id,
			IF(c4 is null or c4 = '0', '', c4) AS c4,
			";
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_ROTATOR)) {
			$info_sql .= "
				2rt.id as rotator_id,
				2rt.name as rotator_name,
			";
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_ROTATOR_RULE)) {
			$info_sql .= "
				2rr.id as rule_id,
				2rr.rule_name,
			";
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_ROTATOR_RULE_REDIRECT)) {
			$info_sql .= "
				2rrr.id as rule_redirect_id,
				2rrr.name as rule_redirect_name,
			";
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_TRANSACTIONS)) {
			$info_sql .= "
				2cl.click_id as sub_id,
			    CASE
                    WHEN 2cl.transaction_id IS NULL OR 2cl.transaction_id = ''
                    THEN ''
                    ELSE 2cl.transaction_id
                END as transaction_id,
				
				2cl.conv_id,
			";
		}

		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_PUBLISHERS)) {
		    $info_sql .= "
		        2u.user_id as publisher_id,
				CONCAT('(',CONCAT_WS(' ', coalesce(2u.user_fname, 'Admin'), coalesce(2u.user_lname, 'User')) ,') - ', 2u.user_name) AS publisher_name,
			";
		}
				
		$info_sql .= "
				SUM(2c.clicks) AS clicks,
				SUM(2c.click_out) AS click_out,
				SUM(2c.leads) AS leads,
				2ac.aff_campaign_payout AS payout,
				SUM(2c.income) AS income,
				SUM(2c.cost) AS cost
		";

		$info_sql .= "
			FROM
				202_dataengine AS 2c";
				
		$info_sql .= "
			LEFT OUTER JOIN 202_aff_campaigns AS 2ac ON (2c.aff_campaign_id = 2ac.aff_campaign_id)
		";

		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_PPC_NETWORK) || $this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_PPC_ACCOUNT) || $user_row['user_pref_ppc_network_id']) {
			$info_sql .= "LEFT OUTER JOIN 202_ppc_accounts AS 2pa ON (2c.ppc_account_id = 2pa.ppc_account_id)";
			
			if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_PPC_NETWORK) || $user_row['user_pref_ppc_network_id']) {
				$info_sql .= "LEFT OUTER JOIN 202_ppc_networks AS 2pn ON (2pa.ppc_network_id = 2pn.ppc_network_id)";
			}
		}

		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_AFFILIATE_NETWORK) || $user_row['user_pref_aff_network_id']) {
			$info_sql .= "LEFT OUTER JOIN 202_aff_networks AS 2an ON (2ac.aff_network_id = 2an.aff_network_id)";
		}
		
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_LANDING_PAGE)) {
			$info_sql .= "LEFT OUTER JOIN 202_landing_pages AS 2lp ON (2c.landing_page_id = 2lp.landing_page_id)";
		}

		if ($user_row['user_pref_keyword']) {
			$mysql['user_pref_keyword'] = $db->real_escape_string($user_row['user_pref_keyword']);
			$info_sql .= "INNER JOIN 202_keywords AS 2k ON (2c.keyword_id = 2k.keyword_id AND 2k.keyword LIKE '%" . $mysql['user_pref_keyword'] . "%')";
		} else if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_KEYWORD)) {
			$info_sql .= "LEFT OUTER JOIN 202_keywords AS 2k ON (2c.keyword_id = 2k.keyword_id)";
		}

		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_TEXT_AD)) {
			$info_sql .= "LEFT OUTER JOIN 202_text_ads AS 2ta ON (2c.text_ad_id = 2ta.text_ad_id)";
		}

		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_REFERER) || $this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_REDIRECT) || $user_row['user_pref_referer']) {
			if ($user_row['user_pref_referer']) {
				$mysql['user_pref_referer'] = $db->real_escape_string($user_row['user_pref_referer']);
				$info_sql .= "LEFT OUTER JOIN 202_site_urls AS 2ru ON (2ru.site_url_address = '" . $mysql['user_pref_referer'] . "')";
				$info_sql .= "INNER JOIN 202_clicks_site AS 2cs ON (2cs.click_referer_site_url_id = 2ru.site_url_id AND 2c.click_referer_site_url_id = 2cs.click_referer_site_url_id)";
			}

			if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_REFERER)) {
				$info_sql .= "LEFT OUTER JOIN 202_site_urls AS 2suf ON (2c.click_referer_site_url_id = 2suf.site_url_id)";
			}
			if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_REDIRECT)) {
				$info_sql .= "LEFT OUTER JOIN 202_site_urls AS 2sur ON (2c.click_redirect_site_url_id = 2sur.site_url_id)";
			}
		}

		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_COUNTRY) || $user_row['user_pref_country_id']) {
			$info_sql .= "LEFT OUTER JOIN 202_locations_country AS 2cy ON (2c.country_id = 2cy.country_id)";
		}

		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_REGION) || $user_row['user_pref_region_id']) {
			$info_sql .= "LEFT OUTER JOIN 202_locations_region AS 2rg ON (2c.region_id = 2rg.region_id)";
		}

		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_CITY)) {
			$info_sql .= "LEFT OUTER JOIN 202_locations_city AS 2ci ON (2c.city_id = 2ci.city_id)";
		}

		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_ISP) || $user_row['user_pref_isp_id']) {
			$info_sql .= "LEFT OUTER JOIN 202_locations_isp AS 2is ON (2c.isp_id = 2is.isp_id)";
		}

		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_DEVICE_NAME) || $this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_DEVICE_TYPE) || $user_row['user_pref_device_id']) {
			$info_sql .= "LEFT OUTER JOIN 202_device_models AS 2d ON (2c.device_id = 2d.device_id)";
		}

		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_DEVICE_TYPE) || $user_row['user_pref_device_id']) {
			$info_sql .= "LEFT OUTER JOIN 202_device_types AS 2dt ON (2d.device_type = 2dt.type_id)";
		}

		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_BROWSER) || $user_row['user_pref_browser_id']) {
			$info_sql .= "LEFT OUTER JOIN 202_browsers AS 2b ON (2c.browser_id = 2b.browser_id)";
		}

		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_PLATFORM) || $user_row['user_pref_platform_id']) {
			$info_sql .= "LEFT OUTER JOIN 202_platforms AS 2p ON (2c.platform_id = 2p.platform_id)";
		}

		if ($user_row['user_pref_ip']) {
		    $mysql['user_pref_ip'] = $db->real_escape_string($user_row['user_pref_ip']);
		    
		    $ip_address=(ipAddress($mysql['user_pref_ip']));
		    $mysql['ip_id']=$db->real_escape_string(INDEXES::get_ip_id($ip_address));
		    
			$info_sql .= "INNER JOIN 202_ips AS 2i ON (2c.ip_id = 2i.ip_id AND 2c.ip_id ='" . $mysql['ip_id'] . "')";
			$info_sql .= "INNER JOIN 202_ips_v6 AS 2i6 ON (2i6.ip_id = 2i.ip_address COLLATE utf8mb4_general_ci)";
			
		} else if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_IP)) {
			$info_sql .= "LEFT OUTER JOIN 202_ips AS 2i ON (2c.ip_id = 2i.ip_id)";
			$info_sql .= "LEFT OUTER JOIN 202_ips_v6 AS 2i6 ON (2i6.ip_id = 2i.ip_address COLLATE utf8mb4_general_ci)";
		}

		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_UTM_CAMPAIGN)) {
			$info_sql .= "LEFT OUTER JOIN 202_utm_campaign AS 2ucam ON (2c.utm_campaign_id = 2ucam.utm_campaign_id)";
		}

		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_UTM_CONTENT)) {
			$info_sql .= "LEFT OUTER JOIN 202_utm_content AS 2ucon ON (2c.utm_content_id = 2ucon.utm_content_id)";
		}

		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_UTM_MEDIUM)) {
			$info_sql .= "LEFT OUTER JOIN 202_utm_medium AS 2umed ON (2c.utm_medium_id = 2umed.utm_medium_id)";
		}

		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_UTM_SOURCE)) {
			$info_sql .= "LEFT OUTER JOIN 202_utm_source AS 2usou ON (2c.utm_source_id = 2usou.utm_source_id)";
		}

		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_UTM_TERM)) {
			$info_sql .= "LEFT OUTER JOIN 202_utm_term AS 2uter ON (2c.utm_term_id = 2uter.utm_term_id)";
		}

		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C1)) {
			$info_sql .= "LEFT OUTER JOIN 202_tracking_c1 AS 2tc1 ON (2c.c1_id = 2tc1.c1_id)";
		}

		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C2)) {
			$info_sql .= "LEFT OUTER JOIN 202_tracking_c2 AS 2tc2 ON (2c.c2_id = 2tc2.c2_id)";
		}

		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C3)) {
			$info_sql .= "LEFT OUTER JOIN 202_tracking_c3 AS 2tc3 ON (2c.c3_id = 2tc3.c3_id)";
		}

		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C4)) {
			$info_sql .= "LEFT OUTER JOIN 202_tracking_c4 AS 2tc4 ON (2c.c4_id = 2tc4.c4_id)";
		}

		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_ROTATOR)) {
			$info_sql .= "LEFT OUTER JOIN 202_rotators AS 2rt ON (2c.rotator_id = 2rt.id)";
		}

		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_ROTATOR_RULE)) {
			$info_sql .= "LEFT OUTER JOIN 202_rotator_rules AS 2rr ON (2c.rule_id = 2rr.id)";
		}

		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_ROTATOR_RULE_REDIRECT)) {
			$info_sql .= "LEFT OUTER JOIN 202_rotator_rules_redirects AS 2rrr ON (2c.rule_redirect_id = 2rrr.id)";
		}

		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_TRANSACTIONS)) {
			$info_sql .= "LEFT OUTER JOIN 202_conversion_logs AS 2cl ON (2c.click_id = 2cl.click_id)";
		}

		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_PUBLISHERS)) {
		    $info_sql .= "LEFT OUTER JOIN 202_users AS 2u ON (2c.user_id = 2u.user_id)";
		}
		
		$info_sql .= "
			WHERE
				2c.user_id" . $user_id_query . "
				AND 2c.click_time >= " . $this->getStartTime() . "
				AND 2c.click_time <= " . $this->getEndTime() . " ";
		;

		if ($user_row['user_pref_subid']) {
		    $mysql['user_pref_subid'] = $db->real_escape_string($user_row['user_pref_subid']);
		    $info_sql .= "
				AND 2c.click_id='".$mysql['user_pref_subid']."'
			";
		}
	
		if ($user_row['user_pref_show'] == 'real') {
		    $info_sql .= "
				AND 2c.click_filtered=0
			";
		} else if ($user_row['user_pref_show'] == 'filtered') {
		    $info_sql .= "
				AND 2c.click_filtered=1
			";
		} else if ($user_row['user_pref_show'] == 'filtered_bot') {
		    $info_sql .= "
				AND 2c.click_bot=1
			";
		} else if ($user_row['user_pref_show'] == 'leads') {
		    $info_sql .= "
				AND 2c.click_lead!=0
			";
		}
		if ($user_row['user_pref_country_id']) {
		    $mysql['user_pref_country_id'] = $db->real_escape_string($user_row['user_pref_country_id']);
		    $info_sql .= "
				AND 2c.country_id='".$mysql['user_pref_country_id']."'
			";
		}
		
		if ($user_row['user_pref_region_id']) {
		    $mysql['user_pref_region_id'] = $db->real_escape_string($user_row['user_pref_region_id']);
		    $info_sql .= "
				AND 2c.region_id='".$mysql['user_pref_region_id']."'
			";
		}	
		
		if ($user_row['user_pref_browser_id']) {
		    $mysql['user_pref_browser_id'] = $db->real_escape_string($user_row['user_pref_browser_id']);
		    $info_sql .= "
				AND 2b.browser_id='".$mysql['user_pref_browser_id']."'
			";
		}

		if ($user_row['user_pref_device_id']) {
		    $mysql['user_pref_device_id'] = $db->real_escape_string($user_row['user_pref_device_id']);
		    $info_sql .= "
				AND 2dt.type_id='".$mysql['user_pref_device_id']."'
			";
		}
		
		if ($user_row['user_pref_platform_id']) {
		    $mysql['user_pref_platform_id'] = $db->real_escape_string($user_row['user_pref_platform_id']);
		    $info_sql .= "
				AND 2c.platform_id='".$mysql['user_pref_platform_id']."'
			";
		}
		
		
		if ($user_row['user_pref_text_ad_id']) { 
			$mysql['user_pref_text_ad_id'] = $db->real_escape_string($user_row['user_pref_text_ad_id']);
			$info_sql .= "
				AND 2c.text_ad_id='".$mysql['user_pref_text_ad_id']."'
			";
		}
		
		if ($user_row['user_pref_landing_page_id']) {
		    $mysql['user_pref_landing_page_id'] = $db->real_escape_string($user_row['user_pref_landing_page_id']);
		    $info_sql .= "
				AND 2c.landing_page_id='".$mysql['user_pref_landing_page_id']."'
			";
		}

		if ($user_row['user_pref_isp_id']) { 
			$mysql['user_pref_isp_id'] = $db->real_escape_string($user_row['user_pref_isp_id']);
			$info_sql .= "
				AND 2c.isp_id='".$mysql['user_pref_isp_id']."'
			";
		}

		if ($user_row['user_pref_aff_campaign_id']) {
		    $mysql['user_pref_aff_campaign_id'] = $db->real_escape_string($user_row['user_pref_aff_campaign_id']);
		    $info_sql .= "
				AND 2c.aff_campaign_id='".$mysql['user_pref_aff_campaign_id']."'
			";
		} else if ($user_row['user_pref_aff_network_id']) {
		    $mysql['user_pref_aff_network_id'] = $db->real_escape_string($user_row['user_pref_aff_network_id']);
		    $info_sql .= "
				AND 2c.aff_network_id='".$mysql['user_pref_aff_network_id']."'
			";
		}
		
		if ($user_row['user_pref_aff_campaign_id']) {
		    $mysql['user_pref_aff_campaign_id'] = $db->real_escape_string($user_row['user_pref_aff_campaign_id']);
		    $info_sql .= "
				AND 2c.aff_campaign_id='".$mysql['user_pref_aff_campaign_id']."'
			";
		}

        if ($user_row['user_pref_ppc_account_id']) {
		    $mysql['user_pref_ppc_account_id'] = $db->real_escape_string($user_row['user_pref_ppc_account_id']);
		    $info_sql .= "
				AND 2c.ppc_account_id='".$mysql['user_pref_ppc_account_id']."'
			";
		} else if ($user_row['user_pref_ppc_network_id'] == '16777215') {
		    $info_sql.= " AND 2c.ppc_network_id IS NULL ";
		} else if ($user_row['user_pref_ppc_network_id'] != '0' && !empty($user_row['user_pref_ppc_network_id'])) { 
		    $info_sql.= " AND 2pn.ppc_network_id=".$user_row['user_pref_ppc_network_id']." ";
		    $info_sql.= " AND 2pn.ppc_network_deleted = 0 ";
		
		} else if ($user_row['user_pref_ppc_network_id'] == '0') {
		    //$info_sql.= " AND COALESCE(2pn.ppc_network_deleted,0) = 0 ";
		}

		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_TRANSACTIONS)) {
			//$info_sql.= " AND 2c.click_lead != 0 ";
		}	

		$info_sql .= $this->getGroupBy();
		return $info_sql;
	}
	
	/**
	 * Returns the html for an entire row header
	 * @return String
	 */
	function getRowHeaderHtml($tr_class = "") {
		
		$html_val = "";
		
		$html_val .= "<tr class=\"" . $tr_class . "\" style=\"background-color: #f2fbfa;\">";
		
		if ($this->getRollupSubTables()) {
			$html_val .= "<th></th>";
		}
		foreach($this->getDisplay() AS $display_item_key) {
			if (ReportBasicForm::DISPLAY_LEVEL_TITLE==$display_item_key) {
				$html_val .= "<th class=\"result_main_column_level_0\"></th>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_CLICK_COUNT==$display_item_key) {
				$html_val .= "<th>Clicks</th>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_CLICK_OUT_COUNT==$display_item_key) {
				$html_val .= "<th>Click Throughs</th>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_CTR==$display_item_key) {
				$html_val .= "<th>LP CTR</th>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_LEAD_COUNT==$display_item_key) {
				$html_val .= "<th>Leads</th>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_SU==$display_item_key) {
				$html_val .= "<th>S/U</th>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_PAYOUT==$display_item_key) {
				$html_val .= "<th>Payout</th>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_EPC==$display_item_key) {
				$html_val .= "<th>EPC</th>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_CPC==$display_item_key) {
				$html_val .= "<th>CPC</th>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_INCOME==$display_item_key) {
				$html_val .= "<th>Income</th>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_COST==$display_item_key) {
				$html_val .= "<th>Cost</th>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_NET==$display_item_key) {
				$html_val .= "<th>Net</th>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_ROI==$display_item_key) {
				$html_val .= "<th>ROI</th>";
			}
		}
		
		$html_val .= "</tr>";
		return $html_val;
	}
	
	/**
	 * Returns the html for an entire row header
	 * @return String
	 */
	function getPrintRowHeaderHtml($tr_class = "") {
		$html_val = "";
		
		$html_val .= "<tr class=\"" . $tr_class . "\">";
		
		foreach($this->getDisplay() AS $display_item_key) {
			if (ReportBasicForm::DISPLAY_LEVEL_TITLE==$display_item_key) {
				$html_val .= "<th class=\"result_main_column_level_0\"></th>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_CLICK_COUNT==$display_item_key) {
				$html_val .= "<th>Clicks</th>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_CLICK_OUT_COUNT==$display_item_key) {
				$html_val .= "<th>Click Throughs</th>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_CTR==$display_item_key) {
				$html_val .= "<th>LP CTR</th>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_LEAD_COUNT==$display_item_key) {
				$html_val .= "<th>Leads</th>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_SU==$display_item_key) {
				$html_val .= "<th>S/U</th>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_PAYOUT==$display_item_key) {
				$html_val .= "<th>Payout</th>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_EPC==$display_item_key) {
				$html_val .= "<th>EPC</th>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_CPC==$display_item_key) {
				$html_val .= "<th>CPC</th>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_INCOME==$display_item_key) {
				$html_val .= "<th>Income</th>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_COST==$display_item_key) {
				$html_val .= "<th>Cost</th>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_NET==$display_item_key) {
				$html_val .= "<th>Net</th>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_ROI==$display_item_key) {
				$html_val .= "<th>ROI</th>";
			}
		}
		
		$html_val .= "</tr>";
		return $html_val;
	}
	
	/**
	 * Returns the export csv for an entire row
	 * @return String
	 */
	function getExportRowHeaderHtml() {
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_INTERVAL)) {
			ReportBasicForm::echoCell("Interval Id");
			ReportBasicForm::echoCell("Interval Range");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_PPC_NETWORK)) {
			ReportBasicForm::echoCell("Traffic Source Id");
			ReportBasicForm::echoCell("Traffic Source Name");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_PPC_ACCOUNT)) {
			ReportBasicForm::echoCell("Traffic Source Account Id");
			ReportBasicForm::echoCell("Traffic Source Account Name");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_AFFILIATE_NETWORK)) {
			ReportBasicForm::echoCell("Category Id");
			ReportBasicForm::echoCell("Category Name");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_CAMPAIGN)) {
			ReportBasicForm::echoCell("Campaign Id");
			ReportBasicForm::echoCell("Campaign Name");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_LANDING_PAGE)) {
			ReportBasicForm::echoCell("Landing Page Id");
			ReportBasicForm::echoCell("Landing Page Name");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_KEYWORD)) {
			ReportBasicForm::echoCell("Keyword Id");
			ReportBasicForm::echoCell("Keyword Name");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_TEXT_AD)) {
			ReportBasicForm::echoCell("Text Id");
			ReportBasicForm::echoCell("Text Name");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_REFERER)) {
			ReportBasicForm::echoCell("Referer Id");
			ReportBasicForm::echoCell("Referer Name");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_COUNTRY)) {
			ReportBasicForm::echoCell("Country Id");
			ReportBasicForm::echoCell("Country Name");
		}

		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_REGION)) {
			ReportBasicForm::echoCell("Region Id");
			ReportBasicForm::echoCell("Region Name");
		}

		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_CITY)) {
			ReportBasicForm::echoCell("City Id");
			ReportBasicForm::echoCell("City Name");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_ISP)) {
			ReportBasicForm::echoCell("ISP/Carrier Id");
			ReportBasicForm::echoCell("ISP/Carrier Name");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_DEVICE_NAME)) {
			ReportBasicForm::echoCell("Device Id");
			ReportBasicForm::echoCell("Device Name");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_DEVICE_TYPE)) {
			ReportBasicForm::echoCell("Type Id");
			ReportBasicForm::echoCell("Type Name");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_BROWSER)) {
			ReportBasicForm::echoCell("Browser Id");
			ReportBasicForm::echoCell("Browser Name");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_PLATFORM)) {
			ReportBasicForm::echoCell("Platform Id");
			ReportBasicForm::echoCell("Platform Name");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_IP)) {
			ReportBasicForm::echoCell("IP Id");
			ReportBasicForm::echoCell("IP Name");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C1)) {
			ReportBasicForm::echoCell("c1 Id");
			ReportBasicForm::echoCell("c1");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_UTM_CAMPAIGN)) {
			ReportBasicForm::echoCell("Utm_campaign");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_UTM_CONTENT)) {
			ReportBasicForm::echoCell("Utm_content");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_UTM_MEDIUM)) {
			ReportBasicForm::echoCell("Utm_medium");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_UTM_SOURCE)) {
			ReportBasicForm::echoCell("Utm_source");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_UTM_TERM)) {
			ReportBasicForm::echoCell("Utm_term");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C2)) {
			ReportBasicForm::echoCell("c2");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C3)) {
			ReportBasicForm::echoCell("c3");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C4)) {
			ReportBasicForm::echoCell("c4");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_ROTATOR)) {
			ReportBasicForm::echoCell("Rotator");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_ROTATOR_RULE)) {
			ReportBasicForm::echoCell("Rotator Rule");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_ROTATOR_RULE_REDIRECT)) {
			ReportBasicForm::echoCell("Rotator Rule Redirect");
		}

		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_TRANSACTIONS)) {
			ReportBasicForm::echoCell("Transaction ID");
		}

		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_PUBLISHERS)) {
		    ReportBasicForm::echoCell("Publisher/User");
		}
		/*if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_CUSTOM_VAR_PARAMETER)) {
			ReportBasicForm::echoCell("Custom Variable Name");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_CUSTOM_VAR_VALUE)) {
			ReportBasicForm::echoCell("Custom Variable Value");
		}*/
		foreach($this->getDisplay() AS $display_item_key) {
			if (ReportBasicForm::DISPLAY_LEVEL_CLICK_COUNT==$display_item_key) {
				ReportBasicForm::echoCell("Clicks");
			} else if (ReportBasicForm::DISPLAY_LEVEL_CLICK_OUT_COUNT==$display_item_key) {
				ReportBasicForm::echoCell("Click Throughs");
			} else if (ReportBasicForm::DISPLAY_LEVEL_CTR==$display_item_key) {
				ReportBasicForm::echoCell("LP CTR");
			} else if (ReportBasicForm::DISPLAY_LEVEL_LEAD_COUNT==$display_item_key) {
				ReportBasicForm::echoCell("Leads");
			} else if (ReportBasicForm::DISPLAY_LEVEL_SU==$display_item_key) {
				ReportBasicForm::echoCell("S/U");
			} else if (ReportBasicForm::DISPLAY_LEVEL_PAYOUT==$display_item_key) {
				ReportBasicForm::echoCell("Payout");
			} else if (ReportBasicForm::DISPLAY_LEVEL_EPC==$display_item_key) {
				ReportBasicForm::echoCell("EPC");
			} else if (ReportBasicForm::DISPLAY_LEVEL_CPC==$display_item_key) {
				ReportBasicForm::echoCell("CPC");
			} else if (ReportBasicForm::DISPLAY_LEVEL_INCOME==$display_item_key) {
				ReportBasicForm::echoCell("Income");
			} else if (ReportBasicForm::DISPLAY_LEVEL_COST==$display_item_key) {
				ReportBasicForm::echoCell("Cost");
			} else if (ReportBasicForm::DISPLAY_LEVEL_NET==$display_item_key) {
				ReportBasicForm::echoCell("Net");
			} else if (ReportBasicForm::DISPLAY_LEVEL_ROI==$display_item_key) {
				ReportBasicForm::echoCell("ROI");
			}
		}
		
		ReportBasicForm::echoRow();
	}
	
	/**
	 * Returns the html for an entire row
	 * @return String
	 */
	function getRowHtml($row,$tr_class = "") {
		global $userObj;
		
		$hideDate = false;
		
		if (!$userObj->hasPermission("access_to_campaign_data") && !$_SESSION['publisher']) {
			$hideDate = true;
		}

		$html_val = "";
		if ($this->getRollupSubTables() && ($row->getDetailId()>1)) {
			$html_val .= "<tr class=\"" . $tr_class . "\" style=\"display:none;\">";
		} else {
			$html_val .= "<tr class=\"" . $tr_class . "\">";
		}
		
		$current_detail = $this->getCurrentDetailByKey($row->getDetailId());
		
		if ($this->getRollupSubTables()) {
			if ($row->getDetailId() != 0 && $row->getDetailId() < count($this->getDetails())) {
				$html_val .= '<td>';
				$html_val .= '<a href="javascript:void(0);" class="rollup_sub_anchor" rel="' . $row->getDetailId() . '_' . $row->getId() . '">
					<img class="icon16" src="/202-img/btnExpand.gif" title="view additional information" />
				</a>';
				$html_val .= '</td>';
			} else {
				$html_val .= '<td></td>';
			}
		}
		foreach($this->getDisplay() AS $display_item_key) {
			if (ReportBasicForm::DISPLAY_LEVEL_TITLE==$display_item_key) {
				$html_val .= "<td class=\"result_main_column_level_" . $row->getDetailId() . "\">";
				$html_val .= $row->getTitle();
				$html_val .= "</td>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_CLICK_COUNT==$display_item_key) {
				
				if ($hideDate) {
					$html_val .= "<td>?</td>";
				} else {
					$html_val .= "<td>". $row->getClicks() ."</td>";
				}
				
			} else if (ReportBasicForm::DISPLAY_LEVEL_CLICK_OUT_COUNT==$display_item_key) {
				
				if ($hideDate) {
					$html_val .= "<td>?</td>";
				} else {
					$html_val .= "<td>". $row->getClickOut() ."</td>";
				}

			} else if (ReportBasicForm::DISPLAY_LEVEL_CTR==$display_item_key) {
				
				$html_val .= "<td>". $row->getCtr() ."%</td>";
				
			} else if (ReportBasicForm::DISPLAY_LEVEL_LEAD_COUNT==$display_item_key) {
				
				if ($hideDate) {
					$html_val .= "<td>?</td>";
				} else {
					$html_val .= "<td>". $row->getLeads() ."</td>";
				}
				
			} else if (ReportBasicForm::DISPLAY_LEVEL_SU==$display_item_key) {
				
				$html_val .= "<td>". round($row->getSu()*100,2) . '%' ."</td>";
				
			} else if (ReportBasicForm::DISPLAY_LEVEL_PAYOUT==$display_item_key) {
				
				$html_val .= "<td>". dollar_format($row->getPayout(), $this->currency) ."</td>";

			} else if (ReportBasicForm::DISPLAY_LEVEL_EPC==$display_item_key) {
				$html_val .= "<td>"
					. dollar_format($row->getEpc(), $this->currency) .
				"</td>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_CPC==$display_item_key) {

				$html_val .= "<td>". dollar_format($row->getCpc(), $this->currency) ."</td>";

			} else if (ReportBasicForm::DISPLAY_LEVEL_INCOME==$display_item_key) {

				if ($hideDate) {
					$html_val .= '<td class="m-row4">?</td>';
				} else {
					$html_val .= '<td class="m-row4">'. dollar_format($row->getIncome(), $this->currency) ."</td>";
				}

			} else if (ReportBasicForm::DISPLAY_LEVEL_COST==$display_item_key) {

				if ($hideDate) {
					$html_val .= '<td class="m-row4">?</td>';
				} else {
					$html_val .= '<td class="m-row4">'. dollar_format($row->getCost(), $this->currency) ."</td>";
				}

			} else if (ReportBasicForm::DISPLAY_LEVEL_NET==$display_item_key) {

				if ($hideDate) {
					$html_val .= '<td class="m-row_zero">?</td>';
				} else {
					if($row->getNet()<0) {
						$html_val .= '<td class="m-row_neg">';
					} else if($row->getNet()>0) {
						$html_val .= '<td class="m-row_pos">';
					} else {
						$html_val .= '<td class="m-row_zero">';
					}
					$html_val .= dollar_format($row->getNet(), $this->currency) . '</td>';
				}

			} else if (ReportBasicForm::DISPLAY_LEVEL_ROI==$display_item_key) {

					if($row->getRoi()<0) {
						$html_val .= '<td><span class="label label-important">';
					} else if($row->getRoi()>0) {
						$html_val .= '<td><span class="label label-primary">';
					} else {
						$html_val .= '<td><span class="label label-default">';
					}
					$html_val .= $row->getRoi() . "%</span></td>";
			}
		}
		
		$html_val .= "</tr>";
	
		return $html_val;
	}
	
	/**
	 * Returns the print html for an entire row
	 * @return String
	 */
	function getPrintRowHtml($row,$tr_class = "") {

		$html_val = "";
		
		$html_val .= "<tr class=\"" . $tr_class . "\">";
		$current_detail = $this->getCurrentDetailByKey($row->getDetailId());
		
		foreach($this->getDisplay() AS $display_item_key) {
			if (ReportBasicForm::DISPLAY_LEVEL_TITLE==$display_item_key) {
				$html_val .= "<td class=\"result_main_column_level_" . $row->getDetailId() . "\">";
				$html_val .= $row->getTitle();
				$html_val .= "</td>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_CLICK_COUNT==$display_item_key) {
				$html_val .= "<td>"
					. $row->getClicks() .
				"</td>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_CLICK_OUT_COUNT==$display_item_key) {
				$html_val .= "<td>"
					. $row->getClickOut() .
				"</td>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_CTR==$display_item_key) {
				$html_val .= "<td>"
				. $row->getCtr() .
				"%</td>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_LEAD_COUNT==$display_item_key) {
				$html_val .= "<td>"
					. $row->getLeads() .
				"</td>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_SU==$display_item_key) {
				$html_val .= "<td>"
					. round($row->getSu()*100,2) . '%' .
				"</td>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_PAYOUT==$display_item_key) {
				$html_val .= "<td>$"
					. number_format($row->getPayout(),2) .
				"</td>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_EPC==$display_item_key) {
				$html_val .= "<td>$"
					. number_format($row->getEpc(),2) .
				"</td>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_CPC==$display_item_key) {
				$html_val .= "<td>$"
					. number_format($row->getCpc()*100,2) .
				"</td>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_INCOME==$display_item_key) {
				$html_val .= "<td>$"
					. number_format($row->getIncome(),2) .
				"</td>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_COST==$display_item_key) {
				$html_val .= "<td>$"
					. number_format($row->getCost(),2) .
				"</td>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_NET==$display_item_key) {
				$html_val .= "<td>$"
					. number_format($row->getNet(),2) .
				"</td>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_ROI==$display_item_key) {
				$html_val .= "<td>"
					. $row->getRoi() .
				"</td>";
			}
		}
		
		$html_val .= "</tr>";
		return $html_val;
	}
	
	/**
	 * Returns the export csv for an entire row
	 * @return String
	 */
	function getExportRowHtml($row) {

		global $userObj;
		
		$hideDate = false;
		
		if (!$userObj->hasPermission("access_to_campaign_data") && !$_SESSION['publisher']) {
			$hideDate = true;
		}

		$current_detail = $this->getCurrentDetailByKey($row->getDetailId());
	
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_INTERVAL)) {
			ReportBasicForm::echoCell($row->getIntervalId());
			ReportBasicForm::echoCell($row->getFormattedIntervalName());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_PPC_NETWORK)) {
			ReportBasicForm::echoCell($row->getPpcNetworkId());
			ReportBasicForm::echoCell($row->getPpcNetworkName());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_PPC_ACCOUNT)) {
			ReportBasicForm::echoCell($row->getPpcAccountId());
			ReportBasicForm::echoCell($row->getPpcAccountName());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_AFFILIATE_NETWORK)) {
			ReportBasicForm::echoCell($row->getAffiliateNetworkId());
			ReportBasicForm::echoCell($row->getAffiliateNetworkName());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_CAMPAIGN)) {
			ReportBasicForm::echoCell($row->getAffiliateCampaignId());
			ReportBasicForm::echoCell($row->getAffiliateCampaignName());
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_LANDING_PAGE)) {
			ReportBasicForm::echoCell($row->getLandingPageId());
			ReportBasicForm::echoCell($row->getLandingPageName());
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_KEYWORD)) {
			ReportBasicForm::echoCell($row->getKeywordId());
			ReportBasicForm::echoCell($row->getKeywordName());
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_TEXT_AD)) {
			ReportBasicForm::echoCell($row->getTextAdId());
			ReportBasicForm::echoCell($row->getTextAdName());
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_REFERER)) {
			ReportBasicForm::echoCell($row->getRefererId());
			ReportBasicForm::echoCell($row->getRefererName());
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_COUNTRY)) {
			ReportBasicForm::echoCell($row->getCountryId());
			ReportBasicForm::echoCell($row->getCountryName());
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_REGION)) {
			ReportBasicForm::echoCell($row->getRegionId());
			ReportBasicForm::echoCell($row->getRegionName());
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_CITY)) {
			ReportBasicForm::echoCell($row->getCityId());
			ReportBasicForm::echoCell($row->getCityName());
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_ISP)) {
			ReportBasicForm::echoCell($row->getIspId());
			ReportBasicForm::echoCell($row->getIspName());
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_DEVICE_NAME)) {
			ReportBasicForm::echoCell($row->getDeviceId());
			ReportBasicForm::echoCell($row->getDeviceName());
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_DEVICE_TYPE)) {
			ReportBasicForm::echoCell($row->getDeviceId());
			ReportBasicForm::echoCell($row->getTypeName());
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_BROWSER)) {
			ReportBasicForm::echoCell($row->getBrowserId());
			ReportBasicForm::echoCell($row->getBrowserName());
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_PLATFORM)) {
			ReportBasicForm::echoCell($row->getPlatformId());
			ReportBasicForm::echoCell($row->getPlatformName());
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_IP)) {
			ReportBasicForm::echoCell($row->getIpId());
			ReportBasicForm::echoCell($row->getIpName());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_UTM_CAMPAIGN)) {
			ReportBasicForm::echoCell($row->getUtmCampaign());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_UTM_CONTENT)) {
			ReportBasicForm::echoCell($row->getUtmContent());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_UTM_MEDIUM)) {
			ReportBasicForm::echoCell($row->getUtmMedium());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_UTM_SOURCE)) {
			ReportBasicForm::echoCell($row->getUtmSource());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_UTM_TERM)) {
			ReportBasicForm::echoCell($row->getUtmTerm());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C1)) {
			ReportBasicForm::echoCell($row->getC1Id());
			ReportBasicForm::echoCell($row->getC1());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C2)) {
			ReportBasicForm::echoCell($row->getC2());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C3)) {
			ReportBasicForm::echoCell($row->getC3());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C4)) {
			ReportBasicForm::echoCell($row->getC4());
		}
		/*if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_CUSTOM_VAR_PARAMETER)) {
			ReportBasicForm::echoCell($row->getCustomVariableName());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_CUSTOM_VAR_VALUE)) {
			ReportBasicForm::echoCell($row->getCustomVariableValue());
		}*/

		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_ROTATOR)) {
			ReportBasicForm::echoCell($row->getRotatorName());
		}

		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_ROTATOR_RULE)) {
			ReportBasicForm::echoCell($row->getRuleName());
		}

		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_ROTATOR_RULE)) {
			ReportBasicForm::echoCell($row->getRuleRedirectName());
		}

		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_TRANSACTIONS)) {
			ReportBasicForm::echoCell($row->getTransactionIDName());
		}

		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_PUBLISHERS)) {
		    ReportBasicForm::echoCell($row->getPublisherIDName());
		}
		foreach($this->getDisplay() AS $display_item_key) {
			if (ReportBasicForm::DISPLAY_LEVEL_CLICK_COUNT==$display_item_key) {
				if ($hideDate) {
					ReportBasicForm::echoCell('?');
				} else {
					ReportBasicForm::echoCell($row->getClicks());
				}
			} else if (ReportBasicForm::DISPLAY_LEVEL_CLICK_OUT_COUNT==$display_item_key) {
				if ($hideDate) {
					ReportBasicForm::echoCell('?');
				} else {
					ReportBasicForm::echoCell($row->getClickOut());
				}
				
			} else if (ReportBasicForm::DISPLAY_LEVEL_CTR==$display_item_key) {
				ReportBasicForm::echoCell($row->getCtr());
				
			} else if (ReportBasicForm::DISPLAY_LEVEL_LEAD_COUNT==$display_item_key) {
				if ($hideDate) {
					ReportBasicForm::echoCell('?');
				} else {
					ReportBasicForm::echoCell($row->getLeads());
				}
				
			} else if (ReportBasicForm::DISPLAY_LEVEL_SU==$display_item_key) {
				ReportBasicForm::echoCell(round($row->getSu()*100,2) . '%');
				
			} else if (ReportBasicForm::DISPLAY_LEVEL_PAYOUT==$display_item_key) {
				ReportBasicForm::echoCell('$' . number_format($row->getPayout(),2));
				
			} else if (ReportBasicForm::DISPLAY_LEVEL_EPC==$display_item_key) {
				ReportBasicForm::echoCell('$' . number_format($row->getEpc(),2));
			} else if (ReportBasicForm::DISPLAY_LEVEL_CPC==$display_item_key) {
				ReportBasicForm::echoCell("$" . number_format($row->getCpc()*100,2));
				
			} else if (ReportBasicForm::DISPLAY_LEVEL_INCOME==$display_item_key) {
				if ($hideDate) {
					ReportBasicForm::echoCell('?');
				} else {
					ReportBasicForm::echoCell('$' . number_format($row->getIncome(),2));
				}
				
			} else if (ReportBasicForm::DISPLAY_LEVEL_COST==$display_item_key) {
				if ($hideDate) {
					ReportBasicForm::echoCell('?');
				} else {
					ReportBasicForm::echoCell('$' . number_format($row->getCost(),2));
				}
				
			} else if (ReportBasicForm::DISPLAY_LEVEL_NET==$display_item_key) {
				if ($hideDate) {
					ReportBasicForm::echoCell('?');
				} else {
					ReportBasicForm::echoCell('$' . number_format($row->getNet(),2));
				}
				
			} else if (ReportBasicForm::DISPLAY_LEVEL_ROI==$display_item_key) {
				ReportBasicForm::echoCell($row->getRoi());
				
			}
		}
		ReportBasicForm::echoRow();
	}
}

/**
 * ReportSummaryGroupForm contains methods to total tracking events by advertiser
 * @author Ben Rotz
 */
class ReportSummaryGroupForm extends ReportSummaryTotalForm {
	
}

/**
 * ReportSummaryPpcNetworkForm contains methods to total tracking events by advertiser
 * @author Ben Rotz
 */
class ReportSummaryPpcNetworkForm extends ReportSummaryTotalForm {
	
	/**
	 * Alias for getPpcNetworkId
	 * @return integer
	 */
	function getId() {
		return $this->getPpcNetworkId();
	}
	
	/**
	 * Alias for getPpcNetworkName
	 * @return integer
	 */
	function getName() {
		return $this->getPpcNetworkName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No Traffic Source]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No Traffic Source]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryPpcAccountForm contains methods to total tracking events by advertiser
 * @author Ben Rotz
 */
class ReportSummaryPpcAccountForm extends ReportSummaryTotalForm {
	
	/**
	 * Alias for getPpcAccountId
	 * @return integer
	 */
	function getId() {
		return $this->getPpcAccountId();
	}
	
	/**
	 * Alias for getPpcAccountName
	 * @return integer
	 */
	function getName() {
		return $this->getPpcAccountName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No Traffic Source Account]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No Traffic Source Account]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryAffiliateNetworkForm contains methods to total tracking events by advertiser
 * @author Ben Rotz
 */
class ReportSummaryAffiliateNetworkForm extends ReportSummaryTotalForm {
	
	/**
	 * Alias for getAffiliateNetworkId
	 * @return integer
	 */
	function getId() {
		return $this->getAffiliateNetworkId();
	}
	
	/**
	 * Alias for getAffiliateNetworkName
	 * @return integer
	 */
	function getName() {
		return $this->getAffiliateNetworkName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No Category]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No Category]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryLandingPageForm contains methods to total tracking events by publisher
 * @author Ben Rotz
 */
class ReportSummaryLandingPageForm extends ReportSummaryTotalForm {

	/**
	 * Alias for getLandingPageId
	 * @return integer
	 */
	function getId() {
		return $this->getLandingPageId();
	}
	
	/**
	 * Alias for getLandingPageName
	 * @return integer
	 */
	function getName() {
		return $this->getLandingPageName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No Landing Page]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No Landing Page]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryKeywordForm contains methods to total tracking events by publisher
 * @author Ben Rotz
 */
class ReportSummaryKeywordForm extends ReportSummaryTotalForm {

	/**
	 * Alias for getKeywordId
	 * @return integer
	 */
	function getId() {
		return $this->getKeywordId();
	}
	
	/**
	 * Alias for getKeywordName
	 * @return integer
	 */
	function getName() {
		return $this->getKeywordName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No Keyword]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No Keyword]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryTextAdForm contains methods to total tracking events by publisher
 * @author Ben Rotz
 */
class ReportSummaryTextAdForm extends ReportSummaryTotalForm {

	/**
	 * Alias for getTextAdId
	 * @return integer
	 */
	function getId() {
		return $this->getTextAdId();
	}
	
	/**
	 * Alias for getTextAdName
	 * @return integer
	 */
	function getName() {
		return $this->getTextAdName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No Text Ad]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No Text Ad]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryRefererForm contains methods to total tracking events by publisher
 * @author Ben Rotz
 */
class ReportSummaryRefererForm extends ReportSummaryTotalForm {

	/**
	 * Alias for getRefererId
	 * @return integer
	 */
	function getId() {
		return $this->getRefererId();
	}
	
	/**
	 * Alias for getRefererName
	 * @return integer
	 */
	function getName() {
		return $this->getRefererName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No Referer]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No Referer]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryRedirectForm contains methods to total tracking events by publisher
 * @author Ben Rotz
 */
class ReportSummaryRedirectForm extends ReportSummaryTotalForm {

	/**
	 * Alias for getRedirectId
	 * @return integer
	 */
	function getId() {
		return $this->getRedirectId();
	}
	
	/**
	 * Alias for getRedirectName
	 * @return integer
	 */
	function getName() {
		return $this->getRedirectName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No Redirect]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No Redirect]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryIpForm contains methods to total tracking events by publisher
 * @author Ben Rotz
 */
class ReportSummaryCountryForm extends ReportSummaryTotalForm {

	/**
	 * Alias for getCountryId
	 * @return integer
	 */
	function getId() {
		return $this->getCountryId();
	}
	
	/**
	 * Alias for getCountryName
	 * @return integer
	 */
	function getName() {
		return $this->getCountryName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No Country]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No Country]';
		}
		return $this->getName();
	}
}


/**
 * ReportSummaryIpForm contains methods to total tracking events by publisher
 * @author Ben Rotz
 */
class ReportSummaryRegionForm extends ReportSummaryTotalForm {

	/**
	 * Alias for getCountryId
	 * @return integer
	 */
	function getId() {
		return $this->getRegionId();
	}
	
	/**
	 * Alias for getCountryName
	 * @return integer
	 */
	function getName() {
		return $this->getRegionName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No Region]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No Region]';
		}
		return $this->getName();
	}
}


/**
 * ReportSummaryIpForm contains methods to total tracking events by publisher
 * @author Ben Rotz
 */
class ReportSummaryCityForm extends ReportSummaryTotalForm {

	/**
	 * Alias for getCityId
	 * @return integer
	 */
	function getId() {
		return $this->getCityId();
	}
	
	/**
	 * Alias for getCityName
	 * @return integer
	 */
	function getName() {
		return $this->getCityName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No City]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No City]';
		}
		return $this->getName();
	}
}


/**
 * ReportSummaryIpForm contains methods to total tracking events by publisher
 * @author Ben Rotz
 */
class ReportSummaryIspForm extends ReportSummaryTotalForm {

	/**
	 * Alias for getIspId
	 * @return integer
	 */
	function getId() {
		return $this->getIspId();
	}
	
	/**
	 * Alias for getIspName
	 * @return integer
	 */
	function getName() {
		return $this->getIspName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No Isp]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No Isp]';
		}
		return $this->getName();
	}
}


/**
 * ReportSummaryIpForm contains methods to total tracking events by publisher
 * @author Ben Rotz
 */
class ReportSummaryDeviceNameForm extends ReportSummaryTotalForm {

	/**
	 * Alias for getIspId
	 * @return integer
	 */
	function getId() {
		return $this->getDeviceId();
	}
	
	/**
	 * Alias for getIspName
	 * @return integer
	 */
	function getName() {
		return $this->getDeviceName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No Device]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No Device]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryIpForm contains methods to total tracking events by publisher
 * @author Ben Rotz
 */
class ReportSummaryDeviceTypeForm extends ReportSummaryTotalForm {

	/**
	 * Alias for getIspId
	 * @return integer
	 */
	function getId() {
		return $this->getTypeId();
	}
	
	/**
	 * Alias for getIspName
	 * @return integer
	 */
	function getName() {
		return $this->getTypeName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No Device Type]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No Device Type]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryIpForm contains methods to total tracking events by publisher
 * @author Ben Rotz
 */
class ReportSummaryBrowserForm extends ReportSummaryTotalForm {

	/**
	 * Alias for getIspId
	 * @return integer
	 */
	function getId() {
		return $this->getBrowserId();
	}
	
	/**
	 * Alias for getIspName
	 * @return integer
	 */
	function getName() {
		return $this->getBrowserName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No Browser]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No Browser]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryIpForm contains methods to total tracking events by publisher
 * @author Ben Rotz
 */
class ReportSummaryPlatformForm extends ReportSummaryTotalForm {

	/**
	 * Alias for getIspId
	 * @return integer
	 */
	function getId() {
		return $this->getPlatformId();
	}
	
	/**
	 * Alias for getIspName
	 * @return integer
	 */
	function getName() {
		return $this->getPlatformName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No Platform]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No Platform]';
		}
		return $this->getName();
	}
}


/**
 * ReportSummaryIpForm contains methods to total tracking events by publisher
 * @author Ben Rotz
 */
class ReportSummaryIpForm extends ReportSummaryTotalForm {

	/**
	 * Alias for getIpId
	 * @return integer
	 */
	function getId() {
		return $this->getIpId();
	}
	
	/**
	 * Alias for getIpName
	 * @return integer
	 */
	function getName() {
		return $this->getIpName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No IP]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No IP]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryCampaignForm contains methods to get the tracking events for an offer on the payment report form
 * @author Ben Rotz
 */
class ReportSummaryCampaignForm extends ReportSummaryTotalForm {
	
	/**
	 * Alias for getAffiliateCampaignId
	 * @return integer
	 */
	function getId() {
		return $this->getAffiliateCampaignId();
	}
	
	/**
	 * Alias for getAffiliateCampaignName
	 * @return integer
	 */
	function getName() {
		return $this->getAffiliateCampaignName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[Advanced Landing Page/Smart Redirector Campaign]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[Landing Page/Smart Redirector Campaign]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryC1Form contains methods to total tracking events by publisher_url_affiliate
 * @author Ben Rotz
 */
class ReportSummaryUtmCampaignForm extends ReportSummaryTotalForm {
	/**
	 * Alias for getC1
	 * @return integer
	 */
	function getId() {
		return $this->getUtmCampaign();
	}
	
	/**
	 * Alias for getC1
	 * @return integer
	 */
	function getName() {
		return $this->getUtmCampaign();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No utm_campaign]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No utm_campaign]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryC1Form contains methods to total tracking events by publisher_url_affiliate
 * @author Ben Rotz
 */
class ReportSummaryUtmContentForm extends ReportSummaryTotalForm {
	/**
	 * Alias for getC1
	 * @return integer
	 */
	function getId() {
		return $this->getUtmContent();
	}
	
	/**
	 * Alias for getC1
	 * @return integer
	 */
	function getName() {
		return $this->getUtmContent();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No utm_content]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No utm_content]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryC1Form contains methods to total tracking events by publisher_url_affiliate
 * @author Ben Rotz
 */
class ReportSummaryUtmMediumForm extends ReportSummaryTotalForm {
	/**
	 * Alias for getC1
	 * @return integer
	 */
	function getId() {
		return $this->getUtmMedium();
	}
	
	/**
	 * Alias for getC1
	 * @return integer
	 */
	function getName() {
		return $this->getUtmMedium();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No utm_medium]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No utm_medium]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryC1Form contains methods to total tracking events by publisher_url_affiliate
 * @author Ben Rotz
 */
class ReportSummaryUtmSourceForm extends ReportSummaryTotalForm {
	/**
	 * Alias for getC1
	 * @return integer
	 */
	function getId() {
		return $this->getUtmSource();
	}
	
	/**
	 * Alias for getC1
	 * @return integer
	 */
	function getName() {
		return $this->getUtmSource();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No utm_source]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No utm_source]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryC1Form contains methods to total tracking events by publisher_url_affiliate
 * @author Ben Rotz
 */
class ReportSummaryUtmTermForm extends ReportSummaryTotalForm {
	/**
	 * Alias for getC1
	 * @return integer
	 */
	function getId() {
		return $this->getUtmTerm();
	}
	
	/**
	 * Alias for getC1
	 * @return integer
	 */
	function getName() {
		return $this->getUtmTerm();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No utm_term]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No utm_term]';
		}
		return $this->getName();
	}
}

class ReportSummaryCustomVarParameterForm extends ReportSummaryTotalForm {
	/**
	 * Alias for getC1
	 * @return integer
	 */
	function getId() {
		return $this->getCustomVariableName();
	}
	
	/**
	 * Alias for getC1
	 * @return integer
	 */
	function getName() {
		return $this->getCustomVariableName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No Variable Name]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No Variable Name]';
		}
		return $this->getName();
	}
}

class ReportSummaryCustomVarValueForm extends ReportSummaryTotalForm {
	/**
	 * Alias for getC1
	 * @return integer
	 */
	function getId() {
		return $this->getCustomVariableValue();
	}
	
	/**
	 * Alias for getC1
	 * @return integer
	 */
	function getName() {
		return $this->getCustomVariableValue();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No Variable]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No Variable]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryC1Form contains methods to total tracking events by publisher_url_affiliate
 * @author Ben Rotz
 */
class ReportSummaryC1Form extends ReportSummaryTotalForm {
	/**
	 * Alias for getC1
	 * @return integer
	 */
	function getId() {
		return $this->getC1();
	}
	
	/**
	 * Alias for getC1
	 * @return integer
	 */
	function getName() {
		return $this->getC1();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No c1]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No c1]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryC2Form contains methods to get the tracking events for an offer on the payment report form
 * @author Ben Rotz
 */
class ReportSummaryC2Form extends ReportSummaryTotalForm {
	/**
	 * Alias for getC2
	 * @return integer
	 */
	function getId() {
		return $this->getC2();
	}
	
	/**
	 * Alias for getC2
	 * @return integer
	 */
	function getName() {
		return $this->getC2();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No c2]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No c2]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryC3Form contains methods to group the pay changes
 * @author Ben Rotz
 */
class ReportSummaryC3Form extends ReportSummaryTotalForm {
	/**
	 * Alias for getC3
	 * @return integer
	 */
	function getId() {
		return $this->getC3();
	}
	
	/**
	 * Alias for getC3
	 * @return integer
	 */
	function getName() {
		return $this->getC3();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No c3]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No c3]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryC4Form contains methods to get the tracking events for an account rep on the payment report form
 * @author Ben Rotz
 */
class ReportSummaryC4Form extends ReportSummaryTotalForm {
	/**
	 * Alias for getC4
	 * @return integer
	 */
	function getId() {
		return $this->getC4();
	}
	
	/**
	 * Alias for getC4
	 * @return integer
	 */
	function getName() {
		return $this->getC4();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No c4]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No c4]';
		}
		return $this->getName();
	}
}

class ReportSummaryRotatorForm extends ReportSummaryTotalForm {
	/**
	 * Alias for getC4
	 * @return integer
	 */
	function getId() {
		return $this->getRotatorId();
	}
	
	/**
	 * Alias for getC4
	 * @return integer
	 */
	function getName() {
		return $this->getRotatorName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No rotator]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No rotator]';
		}
		return $this->getName();
	}
}

class ReportSummaryRotatorRuleForm extends ReportSummaryTotalForm {
	/**
	 * Alias for getC4
	 * @return integer
	 */
	function getId() {
		return $this->getRuleId();
	}
	
	/**
	 * Alias for getC4
	 * @return integer
	 */
	function getName() {
		return $this->getRuleName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No rule]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No rotator]';
		}
		return $this->getName();
	}
}

class ReportSummaryRotatorRuleRedirectForm extends ReportSummaryTotalForm {
	/**
	 * Alias for getC4
	 * @return integer
	 */
	function getId() {
		return $this->getRuleRedirectId();
	}
	
	/**
	 * Alias for getC4
	 * @return integer
	 */
	function getName() {
		return $this->getRuleRedirectName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			//return '[Default redirect1]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[Default redirect2]';
		}
		return $this->getName();
	}
}

class ReportSummaryTransactionsForm extends ReportSummaryTotalForm {
	/**
	 * Alias for getC4
	 * @return integer
	 */
	function getId() {
		return $this->getConvId();
	}
	
	/**
	 * Alias for getC4
	 * @return integer
	 */
	function getName() {
		return $this->getTransactionIDName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
	    if ($this->getName()=='') {
	        return '[No Transaction ID]';
	    }
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
	    if ($this->getName()=='') {
	        return '[No Transaction ID]';
	    }	    
		return $this->getName();
	}
}

class ReportSummaryPublishersForm extends ReportSummaryTotalForm {
    /**
     * Alias for getPublisherId
     * @return integer
     */
    function getId() {
        return $this->getPublisherId();
    }

    /**
     * Alias for getPublisherIDName
     * @return integer
     */
    function getName() {
        return $this->getPublisherIDName();
    }

    /**
     * Alias for getName()
     * @return string
     */
    function getTitle() {
        if ($this->getName()=='') {
            return '[No Publisher/User]';
        }
        return $this->getName();
    }

    /**
     * Alias for getName()
     * @return string
     */
    function getPrintTitle() {
        if ($this->getName()=='') {
            return '[No Publisher/User]';
        }
        return $this->getName();
    }
}



/**
 * ReportSummaryIntervalForm contains methods to total tracking events by interval_id
 * @author Ben Rotz
 */
class ReportSummaryIntervalForm extends ReportSummaryTotalForm {

	/**
	 * Alias for getIntervalId
	 * @return integer
	 */
	function getId() {
		return $this->getIntervalId();
	}
	
	/**
	 * Alias for getIntervalName
	 * @return integer
	 */
	function getName() {
		return $this->getFormattedIntervalName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getTitle() {
		return $this->getName();
	}
	

	/**
	 * Alias
	 * @return string
	 */
	function getPrintTitle() {
		$html = $this->getName();
		return $html;
	}
}

/**
 * ReportSummaryTotalForm contains methods to store the totals for tracking events.  Every daily report form extends this form
 * @author Ben Rotz
 */
class ReportSummaryTotalForm {
	private $child_array;
	private $ppc_network_id;
	private $ppc_network_name;
	private $ppc_account_id;
	private $ppc_account_name;
	private $affiliate_network_id;
	private $affiliate_network_name;
	private $affiliate_campaign_id;
	private $affiliate_campaign_name;
	private $landing_page_id;
	private $landing_page_name;
	private $keyword_id;
	private $keyword_name;
	private $text_ad_id;
	private $text_ad_name;
	private $referer_id;
	private $referer_name;
	private $redirect_id;
	private $redirect_name;
	private $ip_id;
	private $ip_name;
	private $c1_id;
	private $c1;
	private $c2_id;
	private $c2;
	private $c3_id;
	private $c3;
	private $c4_id;
	private $c4;
	private $utm_campaign;
	private $utm_content;
	private $utm_medium;
	private $utm_source;
	private $utm_term;
	private $interval_id;
	private $interval_name;
	private $formatted_interval_name;
	private $clicks;
	private $leads;
	private $su;
	private $payout;
	private $epc;
	private $cpc;
	private $income;
	private $cost;
	private $net;
	private $roi;
	private $click_out;
	private $sub_id;
	private $conv_id;
	private $transaction_id;
	private $publisher_name;
	
	private $detail_id;
	private $parent_class;
	
	/**
	 * Returns the su
	 * @return number
	 */
	function getSu() {
		if($this->getClicks()!=0) {
			return ($this->getLeads()/$this->getClicks());
		} else {
			return 0;
		}
	}
	
	/**
	 * Returns the payout
	 * @return integer
	 */
	function getPayout() {
		if (is_null($this->payout)) {
			$this->payout = 0;
		}
		return $this->payout;
	}
	
	/**
	 * Sets the payout
	 * @param integer
	 */
	function setPayout($arg0) {
		$this->payout = $arg0;
	}
	
	/**
	 * Returns the su
	 * @return number
	 */
	function getEpc() {
		if($this->getClicks()!=0) {
			return ($this->getIncome()/$this->getClicks());
		} else {
			return 0;
		}
	}
	
	/**
	 * Returns the su
	 * @return number
	 */
	function getCpc() {
		if($this->getClicks()!=0) {
			return ($this->getCost()/$this->getClicks());
		} else {
			return 0;
		}
	}
	
	/**
	 * Returns the income 
	 * @return integer
	 */
	function getIncome() {
		$counter = 1;
		if (count($this->getChildArray()) > 0) {
			$ret_val = 0;
			foreach ($this->getChildArray() as $child_item) {
				/*if ($counter == 1) {
					$ret_val = $this->total_income;
				} else {
					$ret_val = $child_item->getIncome();
				}

				$counter++;
				*/

				$ret_val += $child_item->getIncome();

			}
			return $ret_val;
		} else {
			return $this->income;
		}
	}
	
	/**
	 * Sets the income
	 * @param integer
	 */
	function setIncome($arg0) {
		$this->income += $arg0;
	}

	function setTotalIncome($arg0) {
		$this->total_income = $arg0;
	}
	
	/**
	 * Returns the cost 
	 * @return integer
	 */
	function getCost() {
		$counter = 1;
		if (count($this->getChildArray()) > 0) {
			$ret_val = 0;
			foreach ($this->getChildArray() as $child_item) {
				/*if ($counter == 1) {
					$ret_val = $this->total_cost;
				} else {
					$ret_val = $child_item->getCost();
				}

				$counter++;
				*/
				$ret_val += $child_item->getCost();
			}
			return $ret_val;
		} else {
			return $this->cost;
		}
	}
	
	/**
	 * Sets the cost
	 * @param integer
	 */
	function setCost($arg0) {
		$this->cost += $arg0;
	}

	function setTotalCost($arg0) {
		$this->total_cost = $arg0;
	}
	
	/**
	 * Returns the su
	 * @return number
	 */
	function getNet() {
		return ($this->getIncome() - $this->getCost());
	}
	
	/**
	 * Returns the su
	 * @return number
	 */
	function getRoi() {
		if($this->getCost()!=0) {
			return @round(($this->getNet()/$this->getCost())*100);
		} else {
			return 0;
		}
	}
	
	/**
	 * Returns the ppc_network_id
	 * @return integer
	 */
	function getPpcNetworkId() {
		if (is_null($this->ppc_network_id)) {
			$this->ppc_network_id = 0;
		}
		return $this->ppc_network_id;
	}
	
	/**
	 * Sets the ppc_network_id
	 * @param integer
	 */
	function setPpcNetworkId($arg0) {
		$this->ppc_network_id = $arg0;
	}
	
	/**
	 * Returns the ppc_network_name
	 * @return string
	 */
	function getPpcNetworkName() {
		if (is_null($this->ppc_network_name)) {
			$this->ppc_network_name = "";
		}
		return $this->ppc_network_name;
	}
	
	/**
	 * Sets the ppc_network_name
	 * @param string
	 */
	function setPpcNetworkName($arg0) {
		$this->ppc_network_name = $arg0;
	}
	
	/**
	 * Returns the ppc_account_id
	 * @return integer
	 */
	function getPpcAccountId() {
		if (is_null($this->ppc_account_id)) {
			$this->ppc_account_id = 0;
		}
		return $this->ppc_account_id;
	}
	
	/**
	 * Sets the ppc_account_id
	 * @param integer
	 */
	function setPpcAccountId($arg0) {
		$this->ppc_account_id = $arg0;
	}
	
	/**
	 * Returns the ppc_account_name
	 * @return string
	 */
	function getPpcAccountName() {
		if (is_null($this->ppc_account_name)) {
			$this->ppc_account_name = "";
		}
		return $this->ppc_account_name;
	}
	
	/**
	 * Sets the ppc_account_name
	 * @param string
	 */
	function setPpcAccountName($arg0) {
		$this->ppc_account_name = $arg0;
	}
	
	/**
	 * Returns the affiliate_network_id
	 * @return integer
	 */
	function getAffiliateNetworkId() {
		if (is_null($this->affiliate_network_id)) {
			$this->affiliate_network_id = 0;
		}
		return $this->affiliate_network_id;
	}
	
	/**
	 * Sets the affiliate_network_id
	 * @param integer
	 */
	function setAffiliateNetworkId($arg0) {
		$this->affiliate_network_id = $arg0;
	}
	
	/**
	 * Returns the affiliate_network_name
	 * @return string
	 */
	function getAffiliateNetworkName() {
		if (is_null($this->affiliate_network_name)) {
			$this->affiliate_network_name = "";
		}
		return $this->affiliate_network_name;
	}
	
	/**
	 * Sets the affiliate_network_name
	 * @param string
	 */
	function setAffiliateNetworkName($arg0) {
		$this->affiliate_network_name = $arg0;
	}
	
	/**
	 * Returns the landing_page_id
	 * @return integer
	 */
	function getLandingPageId() {
		if (is_null($this->landing_page_id)) {
			$this->landing_page_id = 0;
		}
		return $this->landing_page_id;
	}
	
	/**
	 * Sets the landing_page_id
	 * @param integer
	 */
	function setLandingPageId($arg0) {
		$this->landing_page_id = $arg0;
	}
	
	/**
	 * Returns the landing_page_name
	 * @return string
	 */
	function getLandingPageName() {
		if (is_null($this->landing_page_name)) {
			$this->landing_page_name = "";
		}
		return $this->landing_page_name;
	}
	
	/**
	 * Sets the landing_page_name
	 * @param string
	 */
	function setLandingPageName($arg0) {
		$this->landing_page_name = $arg0;
	}
	
	/**
	 * Returns the keyword_id
	 * @return integer
	 */
	function getKeywordId() {
		if (is_null($this->keyword_id)) {
			$this->keyword_id = 0;
		}
		return $this->keyword_id;
	}
	
	/**
	 * Sets the keyword_id
	 * @param integer
	 */
	function setKeywordId($arg0) {
		$this->keyword_id = $arg0;
	}
	
	/**
	 * Returns the keyword_name
	 * @return string
	 */
	function getKeywordName() {
		if (is_null($this->keyword_name)) {
			$this->keyword_name = "";
		}
		return $this->keyword_name;
	}
	
	/**
	 * Sets the keyword_name
	 * @param string
	 */
	function setKeywordName($arg0) {
		$this->keyword_name = htmlspecialchars($arg0, ENT_QUOTES);
	}
	
	/**
	 * Returns the text_ad_id
	 * @return integer
	 */
	function getTextAdId() {
		if (is_null($this->text_ad_id)) {
			$this->text_ad_id = 0;
		}
		return $this->text_ad_id;
	}
	
	/**
	 * Sets the text_ad_id
	 * @param integer
	 */
	function setTextAdId($arg0) {
		$this->text_ad_id = $arg0;
	}
	
	/**
	 * Returns the text_ad_name
	 * @return string
	 */
	function getTextAdName() {
		if (is_null($this->text_ad_name)) {
			$this->text_ad_name = "";
		}
		return $this->text_ad_name;
	}
	
	/**
	 * Sets the text_ad_name
	 * @param string
	 */
	function setTextAdName($arg0) {
		$this->text_ad_name = $arg0;
	}
	
	/**
	 * Returns the referer_id
	 * @return integer
	 */
	function getRefererId() {
		if (is_null($this->referer_id)) {
			$this->referer_id = 0;
		}
		return $this->referer_id;
	}
	
	/**
	 * Sets the referer_id
	 * @param integer
	 */
	function setRefererId($arg0) {
		$this->referer_id = $arg0;
	}
	
	/**
	 * Returns the referer_name
	 * @return string
	 */
	function getRefererName() {
		if (is_null($this->referer_name)) {
			$this->referer_name = "";
		}
		return $this->referer_name;
	}
	
	/**
	 * Sets the referer_name
	 * @param string
	 */
	function setRefererName($arg0) {
		$this->referer_name = htmlspecialchars($arg0, ENT_QUOTES);
	}
	
	/**
	 * Returns the redirect_id
	 * @return integer
	 */
	function getRedirectId() {
		if (is_null($this->redirect_id)) {
			$this->redirect_id = 0;
		}
		return $this->redirect_id;
	}
	
	/**
	 * Sets the redirect_id
	 * @param integer
	 */
	function setRedirectId($arg0) {
		$this->redirect_id = $arg0;
	}
	
	/**
	 * Returns the redirect_name
	 * @return string
	 */
	function getRedirectName() {
		if (is_null($this->redirect_name)) {
			$this->redirect_name = "";
		}
		return $this->redirect_name;
	}
	
	/**
	 * Sets the redirect_name
	 * @param string
	 */
	function setRedirectName($arg0) {
		$this->redirect_name = $arg0;
	}
	
	/**
	 * Returns the country_id
	 * @return integer
	 */
	function getCountryId() {
		if (is_null($this->country_id)) {
			$this->country_id = 0;
		}
		return $this->country_id;
	}
	
	/**
	 * Sets the country_id
	 * @param integer
	 */
	function setCountryId($arg0) {
		$this->country_id = $arg0;
	}
	
	/**
	 * Returns the country_name
	 * @return string
	 */
	function getCountryName() {
		if (is_null($this->country_name)) {
			$this->country_name = "";
		}
		return $this->country_name;
	}
	
	/**
	 * Sets the country_name
	 * @param string
	 */
	function setCountryName($arg0) {
		$this->country_name = $arg0;
	}

	/**
	 * Returns the country_id
	 * @return integer
	 */
	function getRegionId() {
		if (is_null($this->region_id)) {
			$this->region_id = 0;
		}
		return $this->region_id;
	}
	
	/**
	 * Sets the country_id
	 * @param integer
	 */
	function setRegionId($arg0) {
		$this->region_id = $arg0;
	}
	
	/**
	 * Returns the country_name
	 * @return string
	 */
	function getRegionName() {
		if (is_null($this->region_name)) {
			$this->region_name = "";
		}
		return $this->region_name;
	}
	
	/**
	 * Sets the country_name
	 * @param string
	 */
	function setRegionName($arg0) {
		$this->region_name = $arg0;
	}


	/**
	 * Returns the city_id
	 * @return integer
	 */
	function getCityId() {
		if (is_null($this->city_id)) {
			$this->city_id = 0;
		}
		return $this->city_id;
	}
	
	/**
	 * Sets the city_id
	 * @param integer
	 */
	function setCityId($arg0) {
		$this->city_id = $arg0;
	}
	
	/**
	 * Returns the city_name
	 * @return string
	 */
	function getCityName() {
		if (is_null($this->city_name)) {
			$this->city_name = "";
		}
		return $this->city_name;
	}
	
	/**
	 * Sets the country_name
	 * @param string
	 */
	function setCityName($arg0) {
		$this->city_name = $arg0;
	}


	/**
	 * Returns the isp_id
	 * @return integer
	 */
	function getIspId() {
		if (is_null($this->isp_id)) {
			$this->isp_id = 0;
		}
		return $this->isp_id;
	}
	
	/**
	 * Sets the city_id
	 * @param integer
	 */
	function setIspId($arg0) {
		$this->isp_id = $arg0;
	}
	
	/**
	 * Returns the city_name
	 * @return string
	 */
	function getIspName() {
		if (is_null($this->isp_name)) {
			$this->isp_name = "";
		}
		return $this->isp_name;
	}
	
	/**
	 * Sets the country_name
	 * @param string
	 */
	function setIspName($arg0) {
		$this->isp_name = $arg0;
	}


	/**
	 * Returns the isp_id
	 * @return integer
	 */
	function getDeviceId() {
		if (is_null($this->device_id)) {
			$this->device_id = 0;
		}
		return $this->device_id;
	}
	
	/**
	 * Sets the city_id
	 * @param integer
	 */
	function setDeviceId($arg0) {
		$this->device_id = $arg0;
	}
	
	/**
	 * Returns the city_name
	 * @return string
	 */
	function getDeviceName() {
		if (is_null($this->device_name)) {
			$this->device_name = "";
		}
		return $this->device_name;
	}
	
	/**
	 * Sets the country_name
	 * @param string
	 */
	function setDeviceName($arg0) {
		$this->device_name = $arg0;
	}

	/**
	 * Returns the city_name
	 * @return string
	 */
	function getTypeName() {
		if (is_null($this->type_name)) {
			$this->type_name = "";
		}
		return $this->type_name;
	}
	
	/**
	 * Sets the country_name
	 * @param string
	 */
	function setTypeName($arg0) {
		$this->type_name = $arg0;
	}

	function getTypeId() {
		if (is_null($this->type_id)) {
			$this->type_id = 0;
		}
		return $this->type_id;
	}
	
	/**
	 * Sets the city_id
	 * @param integer
	 */
	function setTypeId($arg0) {
		$this->type_id = $arg0;
	}

	/**
	 * Returns the isp_id
	 * @return integer
	 */
	function getBrowserId() {
		if (is_null($this->browser_id)) {
			$this->browser_id = 0;
		}
		return $this->browser_id;
	}
	
	/**
	 * Sets the city_id
	 * @param integer
	 */
	function setBrowserId($arg0) {
		$this->browser_id = $arg0;
	}
	
	/**
	 * Returns the city_name
	 * @return string
	 */
	function getBrowserName() {
		if (is_null($this->browser_name)) {
			$this->browser_name = "";
		}
		return $this->browser_name;
	}
	
	/**
	 * Sets the country_name
	 * @param string
	 */
	function setBrowserName($arg0) {
		$this->browser_name = $arg0;
	}



	/**
	 * Returns the platform_id
	 * @return integer
	 */
	function getPlatformId() {
		if (is_null($this->platform_id)) {
			$this->platform_id = 0;
		}
		return $this->platform_id;
	}
	
	/**
	 * Sets the platform_id
	 * @param integer
	 */
	function setPlatformId($arg0) {
		$this->platform_id = $arg0;
	}
	
	/**
	 * Returns the city_name
	 * @return string
	 */
	function getPlatformName() {
		if (is_null($this->platform_name)) {
			$this->platform_name = "";
		}
		return $this->platform_name;
	}
	
	/**
	 * Sets the country_name
	 * @param string
	 */
	function setPlatformName($arg0) {
		$this->platform_name = $arg0;
	}



	/**
	 * Returns the ip_id
	 * @return integer
	 */
	function getIpId() {
		if (is_null($this->ip_id)) {
			$this->ip_id = 0;
		}
		return $this->ip_id;
	}
	
	/**
	 * Sets the ip_id
	 * @param integer
	 */
	function setIpId($arg0) {
		$this->ip_id = $arg0;
	}
	
	/**
	 * Returns the ip_name
	 * @return string
	 */
	function getIpName() {
		if (is_null($this->ip_name)) {
			$this->ip_name = "";
		}
		return $this->ip_name;
	}
	
	/**
	 * Sets the ip_name
	 * @param string
	 */
	function setIpName($arg0) {
		$this->ip_name = $arg0;
	}
	
	/**
	 * Returns the affiliate_campaign_id
	 * @return integer
	 */
	function getAffiliateCampaignId() {
		if (is_null($this->affiliate_campaign_id)) {
			$this->affiliate_campaign_id = 0;
		}
		return $this->affiliate_campaign_id;
	}
	
	/**
	 * Sets the affiliate_campaign_id
	 * @param integer
	 */
	function setAffiliateCampaignId($arg0) {
		$this->affiliate_campaign_id = $arg0;
	}
	
	/**
	 * Returns the affiliate_campaign_name
	 * @return string
	 */
	function getAffiliateCampaignName() {
		if (is_null($this->affiliate_campaign_name)) {
			$this->affiliate_campaign_name = "";
		}
		return $this->affiliate_campaign_name;
	}
	
	/**
	 * Sets the affiliate_campaign_name
	 * @param string
	 */
	function setAffiliateCampaignName($arg0) {
		$this->affiliate_campaign_name = htmlspecialchars($arg0, ENT_QUOTES);
	}
	
	/**
	 * Returns the c1
	 * @return string
	 */
	function getUtmCampaign() {
		if (is_null($this->utm_campaign)) {
			$this->utm_campaign = "";
		}
		return $this->utm_campaign;
	}
	
	/**
	 * Sets the c1
	 * @param string
	 */
	function setUtmCampaign($arg0) {
		$this->utm_campaign = htmlspecialchars($arg0, ENT_QUOTES);
	}

	/**
	 * Returns the c1
	 * @return string
	 */
	function getUtmContent() {
		if (is_null($this->utm_content)) {
			$this->utm_content = "";
		}
		return $this->utm_content;
	}
	
	/**
	 * Sets the c1
	 * @param string
	 */
	function setUtmContent($arg0) {
		$this->utm_content = htmlspecialchars($arg0, ENT_QUOTES);
	}

	/**
	 * Returns the c1
	 * @return string
	 */
	function getUtmMedium() {
		if (is_null($this->utm_medium)) {
			$this->utm_medium = "";
		}
		return $this->utm_medium;
	}
	
	/**
	 * Sets the c1
	 * @param string
	 */
	function setUtmMedium($arg0) {
		$this->utm_medium = htmlspecialchars($arg0, ENT_QUOTES);
	}

	/**
	 * Returns the c1
	 * @return string
	 */
	function getUtmSource() {
		if (is_null($this->utm_source)) {
			$this->utm_source = "";
		}
		return $this->utm_source;
	}
	
	/**
	 * Sets the c1
	 * @param string
	 */
	function setUtmSource($arg0) {
		$this->utm_source = $arg0;
	}

	/**
	 * Returns the c1
	 * @return string
	 */
	function getUtmTerm() {
		if (is_null($this->utm_term)) {
			$this->utm_term = "";
		}
		return $this->utm_term;
	}
	
	/**
	 * Sets the c1
	 * @param string
	 */
	function setUtmTerm($arg0) {
		$this->utm_term = htmlspecialchars($arg0, ENT_QUOTES);
	}

	function getCustomVariableName() {
		if (is_null($this->custom_variable_name)) {
			$this->custom_variable_name = "";
		}
		return $this->custom_variable_name;
	}


	function setCustomVariableName($arg0) {
		$this->custom_variable_name = htmlspecialchars($arg0, ENT_QUOTES);
	}

	function getCustomVariableValue() {
		if (is_null($this->custom_variable_value)) {
			$this->custom_variable_value = "";
		}
		return $this->custom_variable_value;
	}


	function setCustomVariableValue($arg0) {
		$this->custom_variable_value = htmlspecialchars($arg0, ENT_QUOTES);
	}



	/**
	 * Returns the c1_id
	 * @return integer
	 */
	function getC1Id() {
		if (is_null($this->c1_id)) {
			$this->c1_id = 0;
		}
		return $this->c1_id;
	}
	
	/**
	 * Sets the c1_id
	 * @param integer
	 */
	function setC1Id($arg0) {
		$this->c1_id = $arg0;
	}

	/**
	 * Returns the c1
	 * @return string
	 */
	function getC1() {
		if (is_null($this->c1)) {
			$this->c1 = '';
		}
		return $this->c1;
	}
	
	/**
	 * Sets the c1
	 * @param string
	 */
	function setC1($arg0) {
		$this->c1 = htmlspecialchars($arg0, ENT_QUOTES);
	}
	
	/**
	 * Returns the c2_id
	 * @return integer
	 */
	function getC2Id() {
		if (is_null($this->c2_id) || $this->c2_id =='') {
			$this->c2_id = 0;
		}
		return $this->c2_id;
	}
	
	/**
	 * Sets the c2_id
	 * @param integer
	 */
	function setC2Id($arg0) {
		$this->c2_id = $arg0;
	}

	/**
	 * Returns the c2
	 * @return string
	 */
	function getC2() {
		if (is_null($this->c2) || $this->c2 =='') {
			$this->c2 = '';
		}
		return $this->c2;
	}
	
	/**
	 * Sets the c2
	 * @param string
	 */
	function setC2($arg0) {
		$this->c2 = htmlspecialchars($arg0, ENT_QUOTES);
	}
	
	/**
	 * Returns the c3
	 * @return string
	 */
	function getC3() {
		if (is_null($this->c3)) {
			$this->c3 = '';
		}
		return $this->c3;
	}
	
	/**
	 * Sets the c3
	 * @param string
	 */
	function setC3($arg0) {
		$this->c3 = htmlspecialchars($arg0, ENT_QUOTES);
	}
	
	/**
	 * Returns the c4
	 * @return string
	 */
	function getC4() {
		if (is_null($this->c4)) {
			$this->c4 = '';
		}
		return $this->c4;
	}
	
	/**
	 * Sets the c4
	 * @param string
	 */
	function setC4($arg0) {
		$this->c4 = htmlspecialchars($arg0, ENT_QUOTES);
	}
	
	/**
	 * Sets the c4
	 * @param string
	 */
	function setRotatorId($arg0) {
		$this->rotator_id = $arg0;
	}

	function setRotatorName($arg0) {
		$this->rotator_name = $arg0;
	}

	/**
	 * Returns the c4
	 * @return string
	 */
	function getRotatorName() {
		if (is_null($this->rotator_name)) {
			$this->rotator_name = '';
		}
		return $this->rotator_name;
	}

	function getRotatorId() {
		if (is_null($this->rotator_id)) {
			$this->rotator_id = '';
		}
		return $this->rotator_id;
	}

	/**
	 * Sets the c4
	 * @param string
	 */
	function setRuleId($arg0) {
		$this->rule_id = $arg0;
	}

	function setRuleName($arg0) {
		$this->rule_name = $arg0;
	}

	/**
	 * Returns the c4
	 * @return string
	 */
	function getRuleName() {
		if (is_null($this->rule_name)) {
			$this->rule_name = '';
		}
		return $this->rule_name;
	}

	function getRuleId() {
		if (is_null($this->rule_id)) {
			$this->rule_id = '';
		}
		return $this->rule_id;
	}


	/**
	 * Returns the Rule redirect ID
	 * @param string
	 */
	function setRuleRedirectId($arg0) {
		$this->rule_redirect_id = $arg0;
	}

	function setRuleRedirectName($arg0) {
		$this->rule_redirect_name = $arg0;
	}

	/**
	 * Returns the Rule Redirect Name
	 * @return string
	 */
	function getRuleRedirectName() {
		if (($this->rule_redirect_name=='')) {
			//$this->rule_redirect_name = '[Default redirect]';
		}
		return $this->rule_redirect_name;
	}

	function getRuleRedirectId() {
		if (is_null($this->rule_redirect_id)) {
			$this->rule_redirect_id = '';
		}
		return $this->rule_redirect_id;
	}

	/**
	 * Returns the Transaction ID Name
	 * @return string
	 */
	function getTransactionIDName() {
	    if (is_null($this->transaction_id)) {
	        $this->transaction_id = '[No Transaction ID]';
	    }
	    return $this->transaction_id;
	}

	function setPublisherName($arg0) {
	    //ump($arg0);
		return $this->publisher_name = $arg0;
	}
	
	function getPublisherIDName() {
	    if (is_null($this->publisher_name)) {
	        $this->publisher_name = '[No Publisher/User]';
	    }
	    return $this->publisher_name;
	}
	
	function setPublisherId($arg0) {
	    $this->publisher_id = htmlspecialchars($arg0, ENT_QUOTES);
	}
	
	function getConvId() {
		return $this->conv_id;
	}
	
	function setConvId($arg0) {
	    $this->conv_id = $arg0;
	}
	
	function getSubId() {
		return $this->sub_id;
	}

	function setSubId($arg0) {
		$this->sub_id = $arg0;
	}

	function getTransactionId() {
	    if (is_null($this->transaction_id)) {
	        $this->transaction_id = 0;
	    }
	    return $this->transaction_id;
	}
	
	function setTransactionId($arg0) {
	    $this->transaction_id = htmlspecialchars($arg0, ENT_QUOTES);
	}

	function getPublisherId() {
	    return $this->publisher_id;
	}
	

	
	/**
	 * Returns the interval_id
	 * @return integer
	 */
	function getIntervalId() {
		if (is_null($this->interval_id)) {
			$this->interval_id = 0;
		}
		return $this->interval_id;
	}
	
	/**
	 * Sets the interval_id
	 * @param integer
	 */
	function setIntervalId($arg0) {
		$this->interval_id = $arg0;
	}
	
	
	/**
	 * Returns the formatted interval_name
	 * @return string
	 */
	function getFormattedIntervalName() {
		if(is_null($this->formatted_interval_name)) {
			$this->formatted_interval_name = '';
			if($this->getReportParameters()->getDetailInterval()==ReportBasicForm::DETAIL_INTERVAL_DAY) {
				$this->formatted_interval_name .= date("m/d/Y", strtotime($this->getIntervalName()));
			} else if($this->getReportParameters()->getDetailInterval()==ReportBasicForm::DETAIL_INTERVAL_WEEK) {
				$start_of_week = ReportBasicForm::getWeekStart(strtotime($this->getIntervalName()));
				$end_of_week = ReportBasicForm::getWeekEnd(strtotime($this->getIntervalName()));
				if($start_of_week < strtotime($this->getReportParameters()->getStartDate())) {
					$start_of_week = strtotime($this->getReportParameters()->getStartDate());
				}
				if($end_of_week > strtotime($this->getReportParameters()->getEndDate())) {
					$end_of_week = strtotime($this->getReportParameters()->getEndDate());
				}
				$this->formatted_interval_name .= date("m/d/Y",$start_of_week) . '-' . date("m/d/Y",$end_of_week);
			} else if($this->getReportParameters()->getDetailInterval()==ReportBasicForm::DETAIL_INTERVAL_MONTH) {
				$start_of_month = ReportBasicForm::getMonthStart(strtotime($this->getIntervalName()));
				$end_of_month = ReportBasicForm::getMonthEnd(strtotime($this->getIntervalName()));
				if($start_of_month < strtotime($this->getReportParameters()->getStartDate())) {
					$start_of_month = strtotime($this->getReportParameters()->getStartDate());
				}
				if($end_of_month > strtotime($this->getReportParameters()->getEndDate())) {
					$end_of_month = strtotime($this->getReportParameters()->getEndDate());
				}
				$this->formatted_interval_name .= date("m/d/Y",$start_of_month) . '-' . date("m/d/Y",$end_of_month);
			}
			
		}
		return $this->formatted_interval_name;
	}
	
	/**
	 * Returns the interval_name
	 * @return string
	 */
	function getIntervalName() {
		if (is_null($this->interval_name)) {
			$this->interval_name = "";
		}
		return $this->interval_name;
	}
	
	/**
	 * Sets the interval_name
	 * @param string
	 */
	function setIntervalName($arg0) {
		$this->interval_name = $arg0;
	}
	
	/**
	 * Returns the detail_id
	 * @return integer
	 */
	function getDetailId() {
		if (is_null($this->detail_id)) {
			$this->detail_id = 0;
		}
		return $this->detail_id;
	}
	
	/**
	 * Sets the detail_id
	 * @param integer
	 */
	function setDetailId($arg0) {
		$this->detail_id = $arg0;
	}
	
	/**
	 * Returns the child_array
	 * @return array
	 */
	function getChildArrayBySort() {
		$child_sort = $this->getReportParameters()->getDetailsSortByKey($this->getDetailId());
		if (is_null($this->child_array)) {
			$this->child_array = array();
		}
		
		if($child_sort==ReportBasicForm::SORT_ROI) {
			usort($this->child_array,array($this,"roiSort"));
		} else if($child_sort==ReportBasicForm::SORT_NET) {
			usort($this->child_array,array($this,"netSort"));
		} else if($child_sort==ReportBasicForm::SORT_COST) {
			usort($this->child_array,array($this,"costSort"));
		} else if($child_sort==ReportBasicForm::SORT_INCOME) {
			usort($this->child_array,array($this,"incomeSort"));
		} else if($child_sort==ReportBasicForm::SORT_CPC) {
			usort($this->child_array,array($this,"cpcSort"));
		} else if($child_sort==ReportBasicForm::SORT_EPC) {
			usort($this->child_array,array($this,"epcSort"));
		} else if($child_sort==ReportBasicForm::SORT_PAYOUT) {
			usort($this->child_array,array($this,"payoutSort"));
		} else if($child_sort==ReportBasicForm::SORT_SU) {
			usort($this->child_array,array($this,"suSort"));
		} else if($child_sort==ReportBasicForm::SORT_LEAD) {
			usort($this->child_array,array($this,"leadSort"));
		} else if($child_sort==ReportBasicForm::SORT_CLICK) {
			usort($this->child_array,array($this,"clickSort"));
		} else {
			usort($this->child_array,array($this,"nameSort"));
		}
		return $this->child_array;
	}
	
	static function nameSort($a,$b) {
		$aRev = $a->getName();
		$bRev = $b->getName();
    	return (strcasecmp($aRev,$bRev));
	}
	
	static function roiSort($a,$b) {
		$aRev = $a->getRoi();
		$bRev = $b->getRoi();
    	if ($aRev == $bRev) {
        	return 0;
    	}
    	return (($aRev < $bRev) ? 1 : -1);
	}
	
	static function netSort($a,$b) {
		$aRev = $a->getNet();
		$bRev = $b->getNet();
    	if ($aRev == $bRev) {
        	return 0;
    	}
    	return (($aRev < $bRev) ? 1 : -1);
	}
	
	static function costSort($a,$b) {
		$aRev = $a->getCost();
		$bRev = $b->getCost();
    	if ($aRev == $bRev) {
        	return 0;
    	}
    	return (($aRev < $bRev) ? 1 : -1);
	}
	
	static function incomeSort($a,$b) {
		$aRev = $a->getIncome();
		$bRev = $b->getIncome();
    	if ($aRev == $bRev) {
        	return 0;
    	}
    	return (($aRev < $bRev) ? 1 : -1);
	}
	
	static function cpcSort($a,$b) {
		$aRev = $a->getCpc();
		$bRev = $b->getCpc();
		if ($aRev == $bRev) {
        	return 0;
    	}
    	return (($aRev < $bRev) ? 1 : -1);
	}
	
	static function epcSort($a,$b) {
		$aRev = $a->getEpc();
		$bRev = $b->getEpc();
		if ($aRev == $bRev) {
        	return 0;
    	}
    	return (($aRev < $bRev) ? 1 : -1);
	}
	
	static function payoutSort($a,$b) {
		$aRev = $a->getPayout();
		$bRev = $b->getPayout();
		if ($aRev == $bRev) {
        	return 0;
    	}
    	return (($aRev < $bRev) ? 1 : -1);
	}
	
	static function suSort($a,$b) {
		$aRev = $a->getSu();
		$bRev = $b->getSu();
		if ($aRev == $bRev) {
        	return 0;
    	}
    	return (($aRev < $bRev) ? 1 : -1);
	}
	
	static function leadSort($a,$b) {
		$aRev = $a->getLeads();
		$bRev = $b->getLeads();
		if ($aRev == $bRev) {
        	return 0;
    	}
    	return (($aRev < $bRev) ? 1 : -1);
	}
	
	static function clickSort($a,$b) {
		$aClick = $a->getClicks();
		$bClick = $b->getClicks();
		if ($aClick == $bClick) {
        	return 0;
    	}
    	return (($aClick < $bClick) ? 1 : -1);
	}
	
	/**
	 * Returns the child_array
	 * @return array
	 */
	function getChildArray() {
		if (is_null($this->child_array)) {
			$this->child_array = array();
		}
		return $this->child_array;
	}
	
	/**
	 * Sets the child_array
	 * @param array
	 */
	function setChildArray($arg0) {
		$this->child_array = $arg0;
	}
	
	/**
	 * Populates this form
	 * @param $arg0
	 */
	function populate($arg0) {
	  if (is_array($arg0)) {
		 
			// Attempt to populate the form
			foreach ($arg0 as $key => $value) {
				if (is_array($value)) {
					$entry = preg_replace_callback("/_([a-zA-Z0-9])/",function($m){return strtoupper($m[1]);},$key);
					if (is_callable(array($this, 'add' . ucfirst($entry)),false, $callableName)) {
					    
						foreach ($value as $key2 => $value1) {
							if (is_string($value1)) {
								$this->{'add' . ucfirst($entry)}(trim($value1), $key2);
							} else {
								$this->{'add' . ucfirst($entry)}($value1, $key2);
							}
						}
					} else {
						$entry = preg_replace_callback("/_([a-zA-Z0-9])/",function($m){return strtoupper($m[1]);},$key);
						if (is_callable(array($this, 'set' . ucfirst($entry)),false, $callableName)) {
							if (is_string($value)) {
								$this->{'set' . ucfirst($entry)}(trim($value));
							} else {
								$this->{'set' . ucfirst($entry)}($value);
							}
						}
					}
				} else {
				    
					$entry = preg_replace_callback("/_([a-zA-Z0-9])/",function($m){return strtoupper($m[1]);},$key);
					if (is_callable(array($this, 'set' . ucfirst($entry)),false, $callableName)) {

					    if (is_string($value)) {
					        $this->{'set' . ucfirst($entry)}(trim($value));
						} else {
							$this->{'set' . ucfirst($entry)}($value);
						}
					} else if (is_callable(array($this, '__set'), false, $callableName)) {
						if (is_string($value)) {
							$this->__set($entry, trim($value));
						} else {
							$this->__set($entry, $value);
						}
					}
				}
			}
		} // End is_array($arg0)
		
		if ($this->getChildKey() != "") {
		    
		 //   return;
			if (array_key_exists($this->getChildKey(), $arg0)) {
			    
				$tmp_array = $this->getChildArray();
				
				$index = (!is_null($arg0[$this->getChildKey()])) ? $arg0[$this->getChildKey()] : 0;
				if (array_key_exists($index, $tmp_array)) {
					$child_tracking_form = $tmp_array[$index];
				} else {
					$child_tracking_form = $this->getChildForm();
				}
				$child_tracking_form->populate($arg0);
				$tmp_array[$child_tracking_form->getId()] = $child_tracking_form;
				$this->setChildArray($tmp_array);
			}
		}	
	}
	
	/**
	 * Returns the clicks 
	 * @return integer
	 */
	function getClicks() {
		$counter = 1;
		
		if (count($this->getChildArray()) > 0 && $this->clicks=='') {
			$ret_val = 0;
			foreach ($this->getChildArray() as $child_item) {
				/*
				if ($counter == 1) {
					$ret_val = $this->total_clicks;
				} else {
					$ret_val = $child_item->getClicks();
				}

				$counter++;
				*/
				$ret_val += $child_item->getClicks();
			}
			return $ret_val;
			
		} else {
			return $this->clicks;	
		}
	}
	
	/**
	 * Sets the clicks
	 * @param integer
	 */
	function setClicks($arg0) {
		
		$this->clicks += $arg0;
	}

	function setTotalClicks($arg0) {
		$this->total_clicks = $arg0;
	}
	
	/**
	 * Returns the click_out 
	 * @return integer
	 */
	function getClickOut() {
		$counter = 1;
		if (count($this->getChildArray()) > 0) {
			$ret_val = 0;
			foreach ($this->getChildArray() as $child_item) {
				/*if ($counter == 1) {
					$ret_val = $this->total_click_out;
				} else {
					$ret_val = $child_item->getClickOut();
				}

				$counter++;*/

				$ret_val += $child_item->getClickOut();
			}
			return $ret_val;
		} else {
			return $this->click_out;	
		}
	}
	
	/**
	 * Sets the click_out
	 * @param integer
	 */
	function setClickOut($arg0) {
		$this->click_out += $arg0;
	}

	function setTotalClickOut($arg0) {
		$this->total_click_out = $arg0;
	}

	/**
	 * Returns the ctr
	 * @return number
	 */
	function getCtr() {
		if($this->getClicks()!=0) {
			return @round(($this->getClickOut()/$this->getClicks())*100);
		} else {
			return 0;
		}
	}
	
	/**
	 * Returns the leads 
	 * @return integer
	 */
	function getLeads() {
		$counter = 1;
		if (count($this->getChildArray()) > 0) {
			$ret_val = 0;
			foreach ($this->getChildArray() as $child_item) {
				/*if ($counter == 1) {
					$ret_val = $this->total_leads;
				} else {
					$ret_val = $child_item->getLeads();
				}

				$counter++;*/
				$ret_val += $child_item->getLeads();
			}
			return $ret_val;
		} else {
			return $this->leads;	
		}
	}
	
	/**
	 * Sets the leads
	 * @param integer
	 */
	function setLeads($arg0) {
		$this->leads += $arg0;
	}

	function setTotalLeads($arg0) {
		$this->total_leads = $arg0;
	}
	
	/**
	 * Returns the top parameters
	 * @return int
	 */
	function getReportParameters() {
		$top_class = $this;
		for($loop_counter = 0;$loop_counter <= $this->getDetailId();$loop_counter++) {
			$top_class = $top_class->getParentClass();
		}
		return $top_class;
	}
	
	/**
	 * Returns the profit 
	 * @return float
	 */
	function getProfit() {
		return ($this->getAdvertiserRevenue() - $this->getPublisherRevenue());
	}
	
	/**
	 * Returns the margin
	 * @return float
	 */
	function getMargin() {
		if ($this->getAdvertiserRevenue() > 0) {
			return ($this->getProfit() / $this->getAdvertiserRevenue());
		} else {
			return 0;	
		}
	}
	
	/**
	 * Returns the conversion
	 * @return float
	 */
	function getConversion() {
		if ($this->getClicks() > 0) {
			return ($this->getPublisherActionCount() / $this->getClicks());
		} else {
			return 0;	
		}
	}
	
	/**
	 * Returns the parent_class
	 * @return integer
	 */
	function getParentClass() {
		if (is_null($this->parent_class)) {
			$this->parent_class = 0;
		}
		return $this->parent_class;
	}
	
	/**
	 * Sets the parent_class
	 * @param integer
	 */
	function setParentClass($arg0) {
		$this->parent_class = $arg0;
	}
	
	/**
	 * Returns the key to use for populating children
	 * @return string
	 */
	function getChildKey() {
		return ReportSummaryForm::translateDetailKeyById($this->getReportParameters()->getDetailsByKey($this->getDetailId()));
	}
	
	/**
	 * Returns a new child form
	 * @return Form
	 */
	function getChildForm() {
		$classname = ReportSummaryForm::translateDetailFunctionById($this->getReportParameters()->getDetailsByKey($this->getDetailId()));
		$child_class = new $classname();
		$next_id = $this->getDetailId() + 1;
		$child_class->setDetailId($next_id);
		$child_class->setParentClass($this);
		return $child_class;
	}

	/**
	 * abstract placeholder
	 * @return integer
	 */
	function getId() {
		return 0;
	}
	
	/**
	 * abstract placeholder
	 * @return integer
	 */
	function getName() {
		return 'a';
	}
	
	
	/**
	 * Alias
	 * @return string
	 */
	function getTitle() {
		return 'Page Total';
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getPrintTitle() {
		return 'Page Total';
	}
}
?>
