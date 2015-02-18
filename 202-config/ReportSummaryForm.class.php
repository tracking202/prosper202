<?php
/**
 * ReportSummaryForm contains methods to work with the report summaries.
 *  
 * @author Ben Rotz
 * @since 2008-11-04 11:43 MST
 */

// Include dependencies.
require_once dirname(__FILE__) . "/ReportBasicForm.class.php";

class ReportSummaryForm extends ReportBasicForm {

	// +-----------------------------------------------------------------------+
	// | CONSTANTS                                                             |
	// +-----------------------------------------------------------------------+
	const DEBUG = MO_DEBUG;

	private static $DISPLAY_LEVEL_ARRAY = array(ReportBasicForm::DISPLAY_LEVEL_TITLE,ReportBasicForm::DISPLAY_LEVEL_CLICK_COUNT,ReportBasicForm::DISPLAY_LEVEL_LEAD_COUNT,ReportBasicForm::DISPLAY_LEVEL_SU,ReportBasicForm::DISPLAY_LEVEL_PAYOUT,ReportBasicForm::DISPLAY_LEVEL_EPC,ReportBasicForm::DISPLAY_LEVEL_CPC,ReportBasicForm::DISPLAY_LEVEL_INCOME,ReportBasicForm::DISPLAY_LEVEL_COST,ReportBasicForm::DISPLAY_LEVEL_NET,ReportBasicForm::DISPLAY_LEVEL_ROI);
	private static $DETAIL_LEVEL_ARRAY = array(
		ReportBasicForm::DETAIL_LEVEL_PPC_NETWORK,
		ReportBasicForm::DETAIL_LEVEL_PPC_ACCOUNT,
		ReportBasicForm::DETAIL_LEVEL_AFFILIATE_NETWORK,
		ReportBasicForm::DETAIL_LEVEL_CAMPAIGN,
		ReportBasicForm::DETAIL_LEVEL_LANDING_PAGE,
		ReportBasicForm::DETAIL_LEVEL_KEYWORD,
		ReportBasicForm::DETAIL_LEVEL_TEXT_AD,
		ReportBasicForm::DETAIL_LEVEL_REFERER,
		ReportBasicForm::DETAIL_LEVEL_IP,
		ReportBasicForm::DETAIL_LEVEL_C1,
		ReportBasicForm::DETAIL_LEVEL_C2,
		ReportBasicForm::DETAIL_LEVEL_C3,
		ReportBasicForm::DETAIL_LEVEL_C4, 
		ReportBasicForm::DETAIL_LEVEL_C5,
		ReportBasicForm::DETAIL_LEVEL_C6,
		ReportBasicForm::DETAIL_LEVEL_C7,
		ReportBasicForm::DETAIL_LEVEL_C8, 
		ReportBasicForm::DETAIL_LEVEL_C9,
		ReportBasicForm::DETAIL_LEVEL_C10,
		ReportBasicForm::DETAIL_LEVEL_C11,
		ReportBasicForm::DETAIL_LEVEL_C12, 
		ReportBasicForm::DETAIL_LEVEL_C13,
		ReportBasicForm::DETAIL_LEVEL_C14,
		ReportBasicForm::DETAIL_LEVEL_C15, 
		ReportBasicForm::DETAIL_LEVEL_MV1,
		ReportBasicForm::DETAIL_LEVEL_MV2,
		ReportBasicForm::DETAIL_LEVEL_MV3,
		ReportBasicForm::DETAIL_LEVEL_MV4, 
		ReportBasicForm::DETAIL_LEVEL_MV5,
		ReportBasicForm::DETAIL_LEVEL_MV6,
		ReportBasicForm::DETAIL_LEVEL_MV7,
		ReportBasicForm::DETAIL_LEVEL_MV8, 
		ReportBasicForm::DETAIL_LEVEL_MV9,
		ReportBasicForm::DETAIL_LEVEL_MV10,
		ReportBasicForm::DETAIL_LEVEL_MV11,
		ReportBasicForm::DETAIL_LEVEL_MV12, 
		ReportBasicForm::DETAIL_LEVEL_MV13,
		ReportBasicForm::DETAIL_LEVEL_MV14,
		ReportBasicForm::DETAIL_LEVEL_MV15 		
	);
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
			$this->display = array(ReportBasicForm::DISPLAY_LEVEL_TITLE,ReportBasicForm::DISPLAY_LEVEL_CLICK_COUNT,ReportBasicForm::DISPLAY_LEVEL_CLICK_OUT_COUNT,ReportBasicForm::DISPLAY_LEVEL_LEAD_COUNT,ReportBasicForm::DISPLAY_LEVEL_SU,ReportBasicForm::DISPLAY_LEVEL_PAYOUT,ReportBasicForm::DISPLAY_LEVEL_EPC,ReportBasicForm::DISPLAY_LEVEL_CPC,ReportBasicForm::DISPLAY_LEVEL_INCOME,ReportBasicForm::DISPLAY_LEVEL_COST,ReportBasicForm::DISPLAY_LEVEL_NET,ReportBasicForm::DISPLAY_LEVEL_ROI);
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
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_IP) {
			return "ip_id";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C1) {
			return "c1";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C2) {
			return "c2";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C3) {
			return 'c3';
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C4) {
			return "c4";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C5) {
			return "c5";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C6) {
			return "c6";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C7) {
			return "c7";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C8) {
			return "c8";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C9) {
			return "c9";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C10) {
			return "c10";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C11) {
			return "c11";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C12) {
			return "c12";			
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C13) {
			return "c13";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C14) {
			return "c14";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C15) {
			return "c15";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_MV1) {
			return "LPG Snippet A";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_MV2) {
			return "LPG Snippet B";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_MV3) {
			return "LPG Snippet C";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_MV4) {
			return "LPG Snippet D";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_MV5) {
			return "LPG Snippet E";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_MV6) {
			return "LPG Snippet F";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_MV7) {
			return "LPG Snippet G";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_MV8) {
			return "LPG Snippet H";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_MV9) {
			return "mv9";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_MV10) {
			return "mv10";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_MV11) {
			return "mv11";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_MV12) {
			return "mv12";			
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_MV13) {
			return "mv13";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_MV14) {
			return "mv14";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_MV15) {
			return "mv15";			
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
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_IP) {
			return "ReportSummaryIpForm";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C1) {
			return "ReportSummaryC1Form";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C2) {
			return "ReportSummaryC2Form";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C3) {
			return 'ReportSummaryC3Form';
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C4) {
			return "ReportSummaryC4Form";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C5) {
			return "ReportSummaryC5Form";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C6) {
			return "ReportSummaryC6Form";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C7) {
			return "ReportSummaryC7Form";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C8) {
			return "ReportSummaryC8Form";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C9) {
			return "ReportSummaryC9Form";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C10) {
			return "ReportSummaryC10Form";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C11) {
			return "ReportSummaryC11Form";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C12) {
			return "ReportSummaryC12Form";			
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C13) {
			return "ReportSummaryC13Form";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C14) {
			return "ReportSummaryC14Form";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_C15) {
			return "ReportSummaryC15Form";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_MV1) {
			return "ReportSummaryMV1Form";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_MV2) {
			return "ReportSummaryMV2Form";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_MV3) {
			return "ReportSummaryMV3Form";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_MV4) {
			return "ReportSummaryMV4Form";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_MV5) {
			return "ReportSummaryMV5Form";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_MV6) {
			return "ReportSummaryMV6Form";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_MV7) {
			return "ReportSummaryMV7Form";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_MV8) {
			return "ReportSummaryMV8Form";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_MV9) {
			return "ReportSummaryMV9Form";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_MV10) {
			return "ReportSummaryMV10Form";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_MV11) {
			return "ReportSummaryMV11Form";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_MV12) {
			return "ReportSummaryMV12Form";			
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_MV13) {
			return "ReportSummaryMV13Form";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_MV14) {
			return "ReportSummaryMV14Form";
		} else if ($arg0 == ReportBasicForm::DETAIL_LEVEL_MV15) {
			return "ReportSummaryMV15Form";
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
			$detail_list = 'GROUP BY ' . implode(',', $detail_key_array);
		}
		return $detail_list;
	}
	
	
	/**
	 * Returns query in a string
	 * @return String
	 */
	function getQuery($user_id,$user_row) {
		$info_sql = '';
		//select regular setup
		$info_sql .= "
			SELECT
		";
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_PPC_NETWORK)) {
			$info_sql .= "
				2pn.ppc_network_id,
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
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_TEXT_AD)) {
			$info_sql .= "
				2ca.text_ad_id,
				2ta.text_ad_name,
			";
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_KEYWORD)) {
			$info_sql .= "
				2ca.keyword_id,
				2k.keyword AS keyword_name,
			";
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_IP)) {
			$info_sql .= "
				2ca.ip_id,
				2i.ip_address AS ip_name,
			";
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_REFERER)) {
			$info_sql .= "
				2cs.click_referer_site_url_id AS referer_id,
				2suf.site_url_address AS referer_name,
			";
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_REDIRECT)) {
			$info_sql .= "
				2cs.click_redirect_site_url_id AS redirect_id,
				2sur.site_url_address AS redirect_name,
			";
		}
        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C1)) {
            $info_sql .= "
                2ct.c1_id,
                2tc1.c1,
            ";
        }
        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C2)) {
            $info_sql .= "
                2ct.c2_id,
                2tc2.c2,
            ";
        }
        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C3)) {
            $info_sql .= "
                2ct.c3_id,
                2tc3.c3,
            ";
        }
        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C4)) {
            $info_sql .= "
                2ct.c4_id,
                2tc4.c4,
            ";
        }
        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C5)) {
            $info_sql .= "
                2ct.c5_id,
                2tc5.c5,
            ";
        }
        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C6)) {
            $info_sql .= "
                2ct.c6_id,
                2tc6.c6,
            ";
        }
        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C7)) {
            $info_sql .= "
                2ct.c7_id,
                2tc7.c7,
            ";
        }
        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C8)) {
            $info_sql .= "
                2ct.c8_id,
                2tc8.c8,
            ";
        }

        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C9)) {
            $info_sql .= "
                2ct.c9_id,
                2tc9.c9,
            ";
        }
        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C10)) {
            $info_sql .= "
                2ct.c10_id,
                2tc10.c10,
            ";
        }
        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C11)) {
            $info_sql .= "
                2ct.c11_id,
                2tc11.c11,
            ";
        }
        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C12)) {
            $info_sql .= "
                2ct.c12_id,
                2tc12.c12,
            ";
        }
        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C13)) {
            $info_sql .= "
                2ct.c13_id,
                2tc13.c13,
            ";
        }
        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C14)) {
            $info_sql .= "
                2ct.c14_id,
                2tc14.c14,
            ";
        }
        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C15)) {
            $info_sql .= "
                2ct.c15_id,
                2tc15.c15,
            ";
        }
        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV1)) {
            $info_sql .= "
                2ct.mv1_id,
                2tmv1.mv1,
            ";
        }
        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV2)) {
            $info_sql .= "
                2ct.mv2_id,
                2tmv2.mv2,
            ";
        }
        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV3)) {
            $info_sql .= "
                2ct.mv3_id,
                2tmv3.mv3,
            ";
        }
        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV4)) {
            $info_sql .= "
                2ct.mv4_id,
                2tmv4.mv4,
            ";
        }
        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV5)) {
            $info_sql .= "
                2ct.mv5_id,
                2tmv5.mv5,
            ";
        }
        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV6)) {
            $info_sql .= "
                2ct.mv6_id,
                2tmv6.mv6,
            ";
        }
        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV7)) {
            $info_sql .= "
                2ct.mv7_id,
                2tmv7.mv7,
            ";
        }
        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV8)) {
            $info_sql .= "
                2ct.mv8_id,
                2tmv8.mv8,
            ";
        }

        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV9)) {
            $info_sql .= "
                2ct.mv9_id,
                2tmv9.mv9,
            ";
        }
        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV10)) {
            $info_sql .= "
                2ct.mv10_id,
                2tmv10.mv10,
            ";
        }
        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV11)) {
            $info_sql .= "
                2ct.mv11_id,
                2tmv11.mv11,
            ";
        }
        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV12)) {
            $info_sql .= "
                2ct.mv12_id,
                2tmv12.mv12,
            ";
        }
        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV13)) {
            $info_sql .= "
                2ct.mv13_id,
                2tmv13.mv13,
            ";
        }
        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV14)) {
            $info_sql .= "
                2ct.mv14_id,
                2tmv14.mv14,
            ";
        }
        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV15)) {
            $info_sql .= "
                2ct.mv15_id,
                2tmv15.mv15,
            ";
        }
		$info_sql .= "
				COUNT(*) AS clicks,
				SUM(2cr.click_out) AS click_out,
				SUM(2c.click_lead) AS leads,
				2ac.aff_campaign_payout AS payout,
				SUM(2c.click_payout*2c.click_lead) AS income,
				SUM(2c.click_cpc) AS cost
		";
		$info_sql .= "
			FROM
				202_clicks AS 2c
				LEFT OUTER JOIN 202_clicks_record AS 2cr ON (2c.click_id = 2cr.click_id)
		";
		//if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_CAMPAIGN) || $this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_AFFILIATE_NETWORK)) {
			$info_sql .= "
				LEFT OUTER JOIN 202_aff_campaigns AS 2ac ON (2c.aff_campaign_id = 2ac.aff_campaign_id)
			";
		//}
		if(	$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_TEXT_AD) ||
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_KEYWORD) ||
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_IP) ||
			$user_row['user_pref_text_ad_id'] ||
			$user_row['user_pref_ip']
			) {
			$info_sql .= "
					LEFT OUTER JOIN 202_clicks_advance AS 2ca ON (2c.click_id = 2ca.click_id)
			";
			if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_TEXT_AD)) {
				$info_sql .= "
					LEFT OUTER JOIN 202_text_ads AS 2ta ON (2ca.text_ad_id = 2ta.text_ad_id)
				";
			}
			if($user_row['user_pref_keyword']) {
				$mysql['user_pref_keyword'] = mysql_real_escape_string($user_row['user_pref_keyword']);
				$info_sql .= "
					INNER JOIN 202_keywords AS 2k ON (
						2ca.keyword_id = 2k.keyword_id
						AND 2k.keyword LIKE '%" . $mysql['user_pref_keyword'] . "%'
					)
				";
			} else if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_KEYWORD)) {
				$info_sql .= "
					LEFT OUTER JOIN 202_keywords AS 2k ON (2ca.keyword_id = 2k.keyword_id)
				";
			}
			
			if($user_row['user_pref_ip']) {
				$mysql['user_pref_ip'] = mysql_real_escape_string($user_row['user_pref_ip']);
				$info_sql .= "
					INNER JOIN 202_ips AS 2i ON (
						2ca.ip_id = 2i.ip_id
						AND 2i.ip_address ='" . $mysql['user_pref_ip'] . "'
					)
				";
			} else if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_IP)) {
				$info_sql .= "
					LEFT OUTER JOIN 202_ips AS 2i ON (2ca.ip_id = 2i.ip_id)
				";
			}
		}
		if(	$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_AFFILIATE_NETWORK) ||
			$user_row['user_pref_aff_network_id']
			) {
			$info_sql .= "
				LEFT OUTER JOIN 202_aff_networks AS 2an ON (2ac.aff_network_id = 2an.aff_network_id) 
			";
		}
		if(
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_PPC_ACCOUNT) ||
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_PPC_NETWORK) ||
			$user_row['user_pref_ppc_network_id']
			) {
			$info_sql .= "
				LEFT OUTER JOIN 202_ppc_accounts AS 2pa ON (2c.ppc_account_id = 2pa.ppc_account_id)
			";
			if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_PPC_NETWORK) ||
			$user_row['user_pref_ppc_network_id']
			) {
				$info_sql .= "
					LEFT OUTER JOIN 202_ppc_networks AS 2pn ON (2pa.ppc_network_id = 2pn.ppc_network_id)
				";
			} else if($user_row['user_pref_ppc_network_id']) {
				
			}
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_LANDING_PAGE)) {
			$info_sql .= "
				LEFT OUTER JOIN 202_landing_pages AS 2lp ON (2c.landing_page_id = 2lp.landing_page_id)
			";
		}
		if(	$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_REFERER) ||
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_REDIRECT)
			) {
			$info_sql .= "
				LEFT OUTER JOIN 202_clicks_site AS 2cs ON (2c.click_id = 2cs.click_id)
			";
			if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_REFERER)) {
				$info_sql .= "
					LEFT OUTER JOIN 202_site_urls AS 2suf ON (2cs.click_referer_site_url_id = 2suf.site_url_id)
				";
			}
			if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_REDIRECT)) {
				$info_sql .= "
					LEFT OUTER JOIN 202_site_urls AS 2sur ON (2cs.click_redirect_site_url_id = 2sur.site_url_id)
				";
			}
		}
		if(	$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C1) ||
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C2) ||
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C3) ||
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C4) || 
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C5) ||
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C6) ||
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C7) ||
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C8) ||
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C9) ||
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C10) ||
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C11) ||
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C12) ||
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C13) ||
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C14) ||
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C15) ||
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV1) ||
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV2) ||
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV3) ||
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV4) || 
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV5) ||
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV6) ||
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV7) ||
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV8) ||
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV9) ||
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV10) ||
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV11) ||
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV12) ||
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV13) ||
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV14) ||
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV15) 
		) {
			$info_sql .= "
				LEFT OUTER JOIN 202_clicks_tracking AS 2ct ON (2c.click_id = 2ct.click_id)
			";
			if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C1)) {
				$info_sql .= "
					LEFT OUTER JOIN 202_tracking_c1 AS 2tc1 ON (2ct.c1_id = 2tc1.c1_id)
				";
			}
			if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C2)) {
				$info_sql .= "
					LEFT OUTER JOIN 202_tracking_c2 AS 2tc2 ON (2ct.c2_id = 2tc2.c2_id)
				";
			}
			if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C3)) {
				$info_sql .= "
					LEFT OUTER JOIN 202_tracking_c3 AS 2tc3 ON (2ct.c3_id = 2tc3.c3_id)
				";
			}
			if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C4)) {
				$info_sql .= "
					LEFT OUTER JOIN 202_tracking_c4 AS 2tc4 ON (2ct.c4_id = 2tc4.c4_id)
				";
			}
            if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C5)) {
                $info_sql .= "
                    LEFT OUTER JOIN 202_tracking_c5 AS 2tc5 ON (2ct.c5_id = 2tc5.c5_id)
                ";
            }
            if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C6)) {
                $info_sql .= "
                    LEFT OUTER JOIN 202_tracking_c6 AS 2tc6 ON (2ct.c6_id = 2tc6.c6_id)
                ";
            }
            if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C7)) {
                $info_sql .= "
                    LEFT OUTER JOIN 202_tracking_c7 AS 2tc7 ON (2ct.c7_id = 2tc7.c7_id)
                ";
            }
            if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C8)) {
                $info_sql .= "
                    LEFT OUTER JOIN 202_tracking_c8 AS 2tc8 ON (2ct.c8_id = 2tc8.c8_id)
                ";
            }
	        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C9)) {
                $info_sql .= "
                    LEFT OUTER JOIN 202_tracking_c9 AS 2tc9 ON (2ct.c9_id = 2tc9.c9_id)
                ";
            }
            if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C10)) {
                $info_sql .= "
                    LEFT OUTER JOIN 202_tracking_c10 AS 2tc10 ON (2ct.c10_id = 2tc10.c10_id)
                ";
            }
            if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C11)) {
                $info_sql .= "
                    LEFT OUTER JOIN 202_tracking_c11 AS 2tc11 ON (2ct.c11_id = 2tc11.c11_id)
                ";
            }
            if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C12)) {
                $info_sql .= "
                    LEFT OUTER JOIN 202_tracking_c12 AS 2tc12 ON (2ct.c12_id = 2tc12.c12_id)
                ";
            }
            if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C13)) {
                $info_sql .= "
                    LEFT OUTER JOIN 202_tracking_c13 AS 2tc13 ON (2ct.c13_id = 2tc13.c13_id)
                ";
            }
            if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C14)) {
                $info_sql .= "
                    LEFT OUTER JOIN 202_tracking_c14 AS 2tc14 ON (2ct.c14_id = 2tc14.c14_id)
                ";
            }
            if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C15)) {
                $info_sql .= "
                    LEFT OUTER JOIN 202_tracking_c15 AS 2tc15 ON (2ct.c15_id = 2tc15.c15_id)
                ";
            }
	        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV1)) {
                $info_sql .= "
                    LEFT OUTER JOIN 202_tracking_mv1 AS 2tmv1 ON (2ct.mv1_id = 2tmv1.mv1_id)
                ";
            }
            if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV2)) {
                $info_sql .= "
                    LEFT OUTER JOIN 202_tracking_mv2 AS 2tmv2 ON (2ct.mv2_id = 2tmv2.mv2_id)
                ";
            }
            if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV3)) {
                $info_sql .= "
                    LEFT OUTER JOIN 202_tracking_mv3 AS 2tmv3 ON (2ct.mv3_id = 2tmv3.mv3_id)
                ";
            }
            if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV4)) {
                $info_sql .= "
                    LEFT OUTER JOIN 202_tracking_mv4 AS 2tmv4 ON (2ct.mv4_id = 2tmv4.mv4_id)
                ";
            }
            if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV5)) {
                $info_sql .= "
                    LEFT OUTER JOIN 202_tracking_mv5 AS 2tmv5 ON (2ct.mv5_id = 2tmv5.mv5_id)
                ";
            }
            if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV6)) {
                $info_sql .= "
                    LEFT OUTER JOIN 202_tracking_mv6 AS 2tmv6 ON (2ct.mv6_id = 2tmv6.mv6_id)
                ";
            }
            if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV7)) {
                $info_sql .= "
                    LEFT OUTER JOIN 202_tracking_mv7 AS 2tmv7 ON (2ct.mv7_id = 2tmv7.mv7_id)
                ";
            }
            if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV8)) {
                $info_sql .= "
                    LEFT OUTER JOIN 202_tracking_mv8 AS 2tmv8 ON (2ct.mv8_id = 2tmv8.mv8_id)
                ";
            }
	        if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV9)) {
                $info_sql .= "
                    LEFT OUTER JOIN 202_tracking_mv9 AS 2tmv9 ON (2ct.mv9_id = 2tmv9.mv9_id)
                ";
            }
            if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV10)) {
                $info_sql .= "
                    LEFT OUTER JOIN 202_tracking_mv10 AS 2tmv10 ON (2ct.mv10_id = 2tmv10.mv10_id)
                ";
            }
            if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV11)) {
                $info_sql .= "
                    LEFT OUTER JOIN 202_tracking_mv11 AS 2tmv11 ON (2ct.mv11_id = 2tmv11.mv11_id)
                ";
            }
            if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV12)) {
                $info_sql .= "
                    LEFT OUTER JOIN 202_tracking_mv12 AS 2tmv12 ON (2ct.mv12_id = 2tmv12.mv12_id)
                ";
            }
            if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV13)) {
                $info_sql .= "
                    LEFT OUTER JOIN 202_tracking_mv13 AS 2tmv13 ON (2ct.mv13_id = 2tmv13.mv13_id)
                ";
            }
            if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV14)) {
                $info_sql .= "
                    LEFT OUTER JOIN 202_tracking_mv14 AS 2tmv14 ON (2ct.mv14_id = 2tmv14.mv14_id)
                ";
            }
            if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV15)) {
                $info_sql .= "
                    LEFT OUTER JOIN 202_tracking_mv15 AS 2tmv15 ON (2ct.mv15_id = 2tmv15.mv15_id)
                ";
            }

		}
		$info_sql .= "
			WHERE
				2c.user_id='" . $user_id . "'
				AND 2c.click_time > " . $this->getStartTime() . "
				AND 2c.click_time <= " . $this->getEndTime() . " ";
		;
		if ($user_row['user_pref_show'] == 'real') {
			$info_sql .= "
				AND 2c.click_filtered=0
			";
		} else if ($user_row['user_pref_show'] == 'filtered') {
			$info_sql .= "
				AND 2c.click_filtered=1
			";
		} else if ($user_row['user_pref_show'] == 'leads') {
			$info_sql .= "
				AND 2c.click_lead=1
			";
		}
		
		if ($user_row['user_pref_ppc_account_id']) {
			$mysql['user_pref_ppc_account_id'] = mysql_real_escape_string($user_row['user_pref_ppc_account_id']);
			$info_sql .= "
				AND 2c.ppc_account_id='".$mysql['user_pref_ppc_account_id']."'
			";
		} else if ($user_row['user_pref_ppc_network_id']) {
			$mysql['user_pref_ppc_network_id'] = mysql_real_escape_string($user_row['user_pref_ppc_network_id']);
			$info_sql .= "
				AND 2pn.ppc_network_id='".$mysql['user_pref_ppc_network_id']."'
			";
		}
		
		if ($user_row['user_pref_aff_campaign_id']) { 
			$mysql['user_pref_aff_campaign_id'] = mysql_real_escape_string($user_row['user_pref_aff_campaign_id']);
			$info_sql .= "
				AND 2c.aff_campaign_id='".$mysql['user_pref_aff_campaign_id']."'
			";
		} else if ($user_row['user_pref_aff_network_id']) { 
			$mysql['user_pref_aff_network_id'] = mysql_real_escape_string($user_row['user_pref_aff_network_id']);
			$info_sql .= "
				AND 2an.aff_network_id='".$mysql['user_pref_aff_network_id']."'
			";
		}
		
		if ($user_row['user_pref_text_ad_id']) { 
			$mysql['user_pref_text_ad_id'] = mysql_real_escape_string($user_row['user_pref_text_ad_id']);
			$info_sql .= "
				AND 2ca.text_ad_id='".$mysql['user_pref_text_ad_id']."'
			";
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
		
		$html_val .= "<tr class=\"" . $tr_class . "\">";
		
		if ($this->getRollupSubTables()) {
			$html_val .= "<th></th>";
		}
		foreach($this->getDisplay() AS $display_item_key) {
			if (ReportBasicForm::DISPLAY_LEVEL_TITLE==$display_item_key) {
				$html_val .= "<th class=\"result_main_column_level_0\"></th>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_CLICK_COUNT==$display_item_key) {
				$html_val .= "<th>Clicks</th>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_CLICK_OUT_COUNT==$display_item_key) {
				$html_val .= "<th>Click Outs</th>";
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
				$html_val .= "<th>Click Outs</th>";
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
			ReportBasicForm::echoCell("PPC Network Id");
			ReportBasicForm::echoCell("PPC Network Name");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_PPC_ACCOUNT)) {
			ReportBasicForm::echoCell("PPC Account Id");
			ReportBasicForm::echoCell("PPC Account Name");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_AFFILIATE_NETWORK)) {
			ReportBasicForm::echoCell("Affiliate Network Id");
			ReportBasicForm::echoCell("Affiliate Network Name");
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
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_IP)) {
			ReportBasicForm::echoCell("IP Id");
			ReportBasicForm::echoCell("IP Name");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C1)) {
			ReportBasicForm::echoCell("c1");
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
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C5)) {
			ReportBasicForm::echoCell("c5");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C6)) {
			ReportBasicForm::echoCell("c6");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C7)) {
			ReportBasicForm::echoCell("c7");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C8)) {
			ReportBasicForm::echoCell("c8");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C9)) {
			ReportBasicForm::echoCell("c9");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C10)) {
			ReportBasicForm::echoCell("c10");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C11)) {
			ReportBasicForm::echoCell("c11");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C12)) {
			ReportBasicForm::echoCell("c12");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C13)) {
			ReportBasicForm::echoCell("c13");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C14)) {
			ReportBasicForm::echoCell("c14");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C15)) {
			ReportBasicForm::echoCell("c15");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV1)) {
			ReportBasicForm::echoCell("mv1");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV2)) {
			ReportBasicForm::echoCell("mv2");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV3)) {
			ReportBasicForm::echoCell("mv3");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV4)) {
			ReportBasicForm::echoCell("mv4");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV5)) {
			ReportBasicForm::echoCell("mv5");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV6)) {
			ReportBasicForm::echoCell("mv6");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV7)) {
			ReportBasicForm::echoCell("mv7");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV8)) {
			ReportBasicForm::echoCell("mv8");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV9)) {
			ReportBasicForm::echoCell("mv9");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV10)) {
			ReportBasicForm::echoCell("mv10");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV11)) {
			ReportBasicForm::echoCell("mv11");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV12)) {
			ReportBasicForm::echoCell("mv12");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV13)) {
			ReportBasicForm::echoCell("mv13");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV14)) {
			ReportBasicForm::echoCell("mv14");
		}
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV15)) {
			ReportBasicForm::echoCell("mv15");
		}	

		foreach($this->getDisplay() AS $display_item_key) {
			if (ReportBasicForm::DISPLAY_LEVEL_CLICK_COUNT==$display_item_key) {
				ReportBasicForm::echoCell("Clicks");
			} else if (ReportBasicForm::DISPLAY_LEVEL_CLICK_OUT_COUNT==$display_item_key) {
				ReportBasicForm::echoCell("Click Outs");
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
				$html_val .= "<td>"
					. $row->getClicks() .
				"</td>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_CLICK_OUT_COUNT==$display_item_key) {
				$html_val .= "<td>"
					. $row->getClickOut() .
				"</td>";
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
				$html_val .= '<td class="m-row4">$'
					. number_format($row->getIncome(),2) .
				"</td>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_COST==$display_item_key) {
				$html_val .= '<td class="m-row4">$'
					. number_format($row->getCost(),2) .
				"</td>";
			} else if (ReportBasicForm::DISPLAY_LEVEL_NET==$display_item_key) {
				if($row->getNet()<0) {
					$html_val .= '<td class="m-row_neg">';
				} else if($row->getNet()>0) {
					$html_val .= '<td class="m-row_pos">';
				} else {
					$html_val .= '<td class="m-row_zero">';
				}
				$html_val .= '$' . number_format($row->getNet(),2) . '</td>';
			} else if (ReportBasicForm::DISPLAY_LEVEL_ROI==$display_item_key) {
				if($row->getRoi()<0) {
					$html_val .= '<td class="m-row_neg">';
				} else if($row->getRoi()>0) {
					$html_val .= '<td class="m-row_pos">';
				} else {
					$html_val .= '<td class="m-row_zero">';
				}
				$html_val .= $row->getRoi() . "%</td>";
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
		if ($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_IP)) {
			ReportBasicForm::echoCell($row->getIpId());
			ReportBasicForm::echoCell($row->getIpName());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C1)) {
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
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C5)) {
			ReportBasicForm::echoCell($row->getC5());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C6)) {
			ReportBasicForm::echoCell($row->getC6());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C7)) {
			ReportBasicForm::echoCell($row->getC7());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C8)) {
			ReportBasicForm::echoCell($row->getC8());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C9)) {
			ReportBasicForm::echoCell($row->getC9());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C10)) {
			ReportBasicForm::echoCell($row->getC10());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C11)) {
			ReportBasicForm::echoCell($row->getC11());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C12)) {
			ReportBasicForm::echoCell($row->getC12());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C13)) {
			ReportBasicForm::echoCell($row->getC13());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C14)) {
			ReportBasicForm::echoCell($row->getC14());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C15)) {
			ReportBasicForm::echoCell($row->getC15());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV1)) {
			ReportBasicForm::echoCell($row->getMV1());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV2)) {
			ReportBasicForm::echoCell($row->getMV2());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV3)) {
			ReportBasicForm::echoCell($row->getMV3());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV4)) {
			ReportBasicForm::echoCell($row->getMV4());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV5)) {
			ReportBasicForm::echoCell($row->getMV5());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV6)) {
			ReportBasicForm::echoCell($row->getMV6());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV7)) {
			ReportBasicForm::echoCell($row->getMV7());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV8)) {
			ReportBasicForm::echoCell($row->getMV8());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV9)) {
			ReportBasicForm::echoCell($row->getMV9());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV10)) {
			ReportBasicForm::echoCell($row->getMV10());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV11)) {
			ReportBasicForm::echoCell($row->getMV11());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV12)) {
			ReportBasicForm::echoCell($row->getMV12());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV13)) {
			ReportBasicForm::echoCell($row->getMV13());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV14)) {
			ReportBasicForm::echoCell($row->getMV14());
		}
		if($this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_MV15)) {
			ReportBasicForm::echoCell($row->getMV15());
		}
		
		foreach($this->getDisplay() AS $display_item_key) {
			if (ReportBasicForm::DISPLAY_LEVEL_CLICK_COUNT==$display_item_key) {
				ReportBasicForm::echoCell($row->getClicks());
			} else if (ReportBasicForm::DISPLAY_LEVEL_CLICK_OUT_COUNT==$display_item_key) {
				ReportBasicForm::echoCell($row->getClickOut());
			} else if (ReportBasicForm::DISPLAY_LEVEL_LEAD_COUNT==$display_item_key) {
				ReportBasicForm::echoCell($row->getLeads());
			} else if (ReportBasicForm::DISPLAY_LEVEL_SU==$display_item_key) {
				ReportBasicForm::echoCell(round($row->getSu()*100,2) . '%');
			} else if (ReportBasicForm::DISPLAY_LEVEL_PAYOUT==$display_item_key) {
				ReportBasicForm::echoCell('$' . number_format($row->getPayout(),2));
			} else if (ReportBasicForm::DISPLAY_LEVEL_EPC==$display_item_key) {
				ReportBasicForm::echoCell('$' . number_format($row->getEpc(),2));
			} else if (ReportBasicForm::DISPLAY_LEVEL_CPC==$display_item_key) {
				ReportBasicForm::echoCell("$" . number_format($row->getCpc()*100,2));
			} else if (ReportBasicForm::DISPLAY_LEVEL_INCOME==$display_item_key) {
				ReportBasicForm::echoCell('$' . number_format($row->getIncome(),2));
			} else if (ReportBasicForm::DISPLAY_LEVEL_COST==$display_item_key) {
				ReportBasicForm::echoCell('$' . number_format($row->getCost(),2));
			} else if (ReportBasicForm::DISPLAY_LEVEL_NET==$display_item_key) {
		c		ReportBasicForm::echoCell('$' . number_format($row->getNet(),2));
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
			return '[No PPC Network]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No PPC Network]';
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
			return '[No PPC Account]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No PPC Account]';
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
			return '[No Affiliate Network]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No Affiliate Network]';
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
			return '[No Campaign]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No Campaign]';
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

/**
 * ReportSummaryC5Form contains methods to get the tracking events for an account rep on the payment report form
 * @author Ben Rotz
 */
class ReportSummaryC5Form extends ReportSummaryTotalForm {
	/**
	 * Alias for getC5
	 * @return integer
	 */
	function getId() {
		return $this->getC5();
	}
	
	/**
	 * Alias for getC5
	 * @return integer
	 */
	function getName() {
		return $this->getC5();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No c5]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No c5]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryC6Form contains methods to get the tracking events for an account rep on the payment report form
 * @author Ben Rotz
 */
class ReportSummaryC6Form extends ReportSummaryTotalForm {
	/**
	 * Alias for getC6
	 * @return integer
	 */
	function getId() {
		return $this->getC6();
	}
	
	/**
	 * Alias for getC6
	 * @return integer
	 */
	function getName() {
		return $this->getC6();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No c6]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No c6]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryC7Form contains methods to get the tracking events for an account rep on the payment report form
 * @author Ben Rotz
 */
class ReportSummaryC7Form extends ReportSummaryTotalForm {
	/**
	 * Alias for getC7
	 * @return integer
	 */
	function getId() {
		return $this->getC7();
	}
	
	/**
	 * Alias for getC7
	 * @return integer
	 */
	function getName() {
		return $this->getC7();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No c7]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No c7]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryC8Form contains methods to get the tracking events for an account rep on the payment report form
 * @author Ben Rotz
 */
class ReportSummaryC8Form extends ReportSummaryTotalForm {
	/**
	 * Alias for getC8
	 * @return integer
	 */
	function getId() {
		return $this->getC8();
	}
	
	/**
	 * Alias for getC8
	 * @return integer
	 */
	function getName() {
		return $this->getC8();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No c8]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No c8]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryC9Form contains methods to get the tracking events for an account rep on the payment report form
 * @author Ben Rotz
 */
class ReportSummaryC9Form extends ReportSummaryTotalForm {
	/**
	 * Alias for getC9
	 * @return integer
	 */
	function getId() {
		return $this->getC9();
	}
	
	/**
	 * Alias for getC9
	 * @return integer
	 */
	function getName() {
		return $this->getC9();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No c9]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No c9]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryC10Form contains methods to get the tracking events for an account rep on the payment report form
 * @author Ben Rotz
 */
class ReportSummaryC10Form extends ReportSummaryTotalForm {
	/**
	 * Alias for getC10
	 * @return integer
	 */
	function getId() {
		return $this->getC10();
	}
	
	/**
	 * Alias for getC10
	 * @return integer
	 */
	function getName() {
		return $this->getC10();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No c10]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No c10]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryC11Form contains methods to get the tracking events for an account rep on the payment report form
 * @author Ben Rotz
 */
class ReportSummaryC11Form extends ReportSummaryTotalForm {
	/**
	 * Alias for getC11
	 * @return integer
	 */
	function getId() {
		return $this->getC11();
	}
	
	/**
	 * Alias for getC11
	 * @return integer
	 */
	function getName() {
		return $this->getC11();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No c11]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No c11]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryC12Form contains methods to get the tracking events for an account rep on the payment report form
 * @author Ben Rotz
 */
class ReportSummaryC12Form extends ReportSummaryTotalForm {
	/**
	 * Alias for getC12
	 * @return integer
	 */
	function getId() {
		return $this->getC12();
	}
	
	/**
	 * Alias for getC12
	 * @return integer
	 */
	function getName() {
		return $this->getC12();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No c12]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No c12]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryC13Form contains methods to get the tracking events for an account rep on the payment report form
 * @author Ben Rotz
 */
class ReportSummaryC13Form extends ReportSummaryTotalForm {
	/**
	 * Alias for getC13
	 * @return integer
	 */
	function getId() {
		return $this->getC13();
	}
	
	/**
	 * Alias for getC13
	 * @return integer
	 */
	function getName() {
		return $this->getC13();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No c13]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No c13]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryC14Form contains methods to get the tracking events for an account rep on the payment report form
 * @author Ben Rotz
 */
class ReportSummaryC14Form extends ReportSummaryTotalForm {
	/**
	 * Alias for getC14
	 * @return integer
	 */
	function getId() {
		return $this->getC14();
	}
	
	/**
	 * Alias for getC14
	 * @return integer
	 */
	function getName() {
		return $this->getC14();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No c14]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No c14]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryC15Form contains methods to get the tracking events for an account rep on the payment report form
 * @author Ben Rotz
 */
class ReportSummaryC15Form extends ReportSummaryTotalForm {
	/**
	 * Alias for getC15
	 * @return integer
	 */
	function getId() {
		return $this->getC15();
	}
	
	/**
	 * Alias for getC15
	 * @return integer
	 */
	function getName() {
		return $this->getC15();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No c15]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No c15]';
		}
		return $this->getName();
	}
}


/**
 * ReportSummaryMV1Form contains methods to total tracking events by publisher_url_affiliate
 * @author Ben Rotz
 */
class ReportSummaryMV1Form extends ReportSummaryTotalForm {
	/**
	 * Alias for getMV1
	 * @return integer
	 */
	function getId() {
		return $this->getMV1();
	}
	
	/**
	 * Alias for getMV1
	 * @return integer
	 */
	function getName() {
		return $this->getMV1();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[Snippet A unused]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[Snippet A unused]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryMV2Form contains methods to get the tracking events for an offer on the payment report form
 * @author Ben Rotz
 */
class ReportSummaryMV2Form extends ReportSummaryTotalForm {
	/**
	 * Alias for getMV2
	 * @return integer
	 */
	function getId() {
		return $this->getMV2();
	}
	
	/**
	 * Alias for getMV2
	 * @return integer
	 */
	function getName() {
		return $this->getMV2();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[Snippet B unused]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[Snippet B unused]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryMV3Form contains methods to group the pay changes
 * @author Ben Rotz
 */
class ReportSummaryMV3Form extends ReportSummaryTotalForm {
	/**
	 * Alias for getMV3
	 * @return integer
	 */
	function getId() {
		return $this->getMV3();
	}
	
	/**
	 * Alias for getMV3
	 * @return integer
	 */
	function getName() {
		return $this->getMV3();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[Snippet C unused]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[Snippet C unused]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryMV4Form contains methods to get the tracking events for an account rep on the payment report form
 * @author Ben Rotz
 */
class ReportSummaryMV4Form extends ReportSummaryTotalForm {
	/**
	 * Alias for getMV4
	 * @return integer
	 */
	function getId() {
		return $this->getMV4();
	}
	
	/**
	 * Alias for getMV4
	 * @return integer
	 */
	function getName() {
		return $this->getMV4();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[Snippet D unused]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[Snippet D unused]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryMV5Form contains methods to get the tracking events for an account rep on the payment report form
 * @author Ben Rotz
 */
class ReportSummaryMV5Form extends ReportSummaryTotalForm {
	/**
	 * Alias for getMV5
	 * @return integer
	 */
	function getId() {
		return $this->getMV5();
	}
	
	/**
	 * Alias for getMV5
	 * @return integer
	 */
	function getName() {
		return $this->getMV5();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[Snippet E unused]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[Snippet E unused]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryMV6Form contains methods to get the tracking events for an account rep on the payment report form
 * @author Ben Rotz
 */
class ReportSummaryMV6Form extends ReportSummaryTotalForm {
	/**
	 * Alias for getMV6
	 * @return integer
	 */
	function getId() {
		return $this->getMV6();
	}
	
	/**
	 * Alias for getMV6
	 * @return integer
	 */
	function getName() {
		return $this->getMV6();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[Snippet F unused]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[Snippet F unused';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryMV7Form contains methods to get the tracking events for an account rep on the payment report form
 * @author Ben Rotz
 */
class ReportSummaryMV7Form extends ReportSummaryTotalForm {
	/**
	 * Alias for getMV7
	 * @return integer
	 */
	function getId() {
		return $this->getMV7();
	}
	
	/**
	 * Alias for getMV7
	 * @return integer
	 */
	function getName() {
		return $this->getMV7();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[Snippet G unused]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[Snippet G unused]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryMV8Form contains methods to get the tracking events for an account rep on the payment report form
 * @author Ben Rotz
 */
class ReportSummaryMV8Form extends ReportSummaryTotalForm {
	/**
	 * Alias for getMV8
	 * @return integer
	 */
	function getId() {
		return $this->getMV8();
	}
	
	/**
	 * Alias for getMV8
	 * @return integer
	 */
	function getName() {
		return $this->getMV8();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[Snippet H unused]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[Snippet H unused]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryMV9Form contains methods to get the tracking events for an account rep on the payment report form
 * @author Ben Rotz
 */
class ReportSummaryMV9Form extends ReportSummaryTotalForm {
	/**
	 * Alias for getMV9
	 * @return integer
	 */
	function getId() {
		return $this->getMV9();
	}
	
	/**
	 * Alias for getMV9
	 * @return integer
	 */
	function getName() {
		return $this->getMV9();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No mv9]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No mv9]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryMV10Form contains methods to get the tracking events for an account rep on the payment report form
 * @author Ben Rotz
 */
class ReportSummaryMV10Form extends ReportSummaryTotalForm {
	/**
	 * Alias for getMV10
	 * @return integer
	 */
	function getId() {
		return $this->getMV10();
	}
	
	/**
	 * Alias for getMV10
	 * @return integer
	 */
	function getName() {
		return $this->getMV10();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No mv10]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No mv10]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryMV11Form contains methods to get the tracking events for an account rep on the payment report form
 * @author Ben Rotz
 */
class ReportSummaryMV11Form extends ReportSummaryTotalForm {
	/**
	 * Alias for getMV11
	 * @return integer
	 */
	function getId() {
		return $this->getMV11();
	}
	
	/**
	 * Alias for getMV11
	 * @return integer
	 */
	function getName() {
		return $this->getMV11();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No mv11]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No mv11]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryMV12Form contains methods to get the tracking events for an account rep on the payment report form
 * @author Ben Rotz
 */
class ReportSummaryMV12Form extends ReportSummaryTotalForm {
	/**
	 * Alias for getMV12
	 * @return integer
	 */
	function getId() {
		return $this->getMV12();
	}
	
	/**
	 * Alias for getMV12
	 * @return integer
	 */
	function getName() {
		return $this->getMV12();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No mv12]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No mv12]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryMV13Form contains methods to get the tracking events for an account rep on the payment report form
 * @author Ben Rotz
 */
class ReportSummaryMV13Form extends ReportSummaryTotalForm {
	/**
	 * Alias for getMV13
	 * @return integer
	 */
	function getId() {
		return $this->getMV13();
	}
	
	/**
	 * Alias for getMV13
	 * @return integer
	 */
	function getName() {
		return $this->getMV13();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No mv13]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No mv13]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryMV14Form contains methods to get the tracking events for an account rep on the payment report form
 * @author Ben Rotz
 */
class ReportSummaryMV14Form extends ReportSummaryTotalForm {
	/**
	 * Alias for getMV14
	 * @return integer
	 */
	function getId() {
		return $this->getMV14();
	}
	
	/**
	 * Alias for getMV14
	 * @return integer
	 */
	function getName() {
		return $this->getMV14();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No mv14]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No mv14]';
		}
		return $this->getName();
	}
}

/**
 * ReportSummaryMV15Form contains methods to get the tracking events for an account rep on the payment report form
 * @author Ben Rotz
 */
class ReportSummaryMV15Form extends ReportSummaryTotalForm {
	/**
	 * Alias for getMV15
	 * @return integer
	 */
	function getId() {
		return $this->getMV15();
	}
	
	/**
	 * Alias for getMV15
	 * @return integer
	 */
	function getName() {
		return $this->getMV15();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getTitle() {
		if ($this->getName()=='') {
			return '[No mv15]';
		}
		return $this->getName();
	}
	
	/**
	 * Alias for getName()
	 * @return string
	 */
	function getPrintTitle() {
		if ($this->getName()=='') {
			return '[No mv15]';
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
	private $c1;
	private $c1_name;
	private $c2;
	private $c3;
	private $c4;
	private $c5;
	private $c6;
	private $c7;
	private $c8;
	private $c9;
	private $c10;
	private $c11;
	private $c12;
	private $c13;
	private $c14;
	private $c15;
	
	private $mv1;
	private $mv2;
	private $mv3;
	private $mv4;
	private $mv5;
	private $mv6;
	private $mv7;
	private $mv8;
	private $mv9;
	private $mv10;
	private $mv11;
	private $mv12;
	private $mv13;
	private $mv14;
	private $mv15;

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
			return ($this->getLeads()/$this->getClicks());
		} else {
			return 0;
		}
	}
	
	/**
	 * Returns the income 
	 * @return integer
	 */
	function getIncome() {
		if (count($this->getChildArray()) > 0) {
			$ret_val = 0;
			foreach ($this->getChildArray() as $child_item) {
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
	
	/**
	 * Returns the cost 
	 * @return integer
	 */
	function getCost() {
		if (count($this->getChildArray()) > 0) {
			$ret_val = 0;
			foreach ($this->getChildArray() as $child_item) {
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
		$this->keyword_name = $arg0;
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
		$this->referer_name = $arg0;
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
		$this->affiliate_campaign_name = $arg0;
	}
	
    /**
     * Returns the c1
     * @return string
     */
    function getC1() {
        if (is_null($this->c1)) {
            $this->c1 = 0;
        }
        return $this->c1;
    }
    
    /**
     * Sets the c1
     * @param string
     */
    function setC1($arg0) {
        $this->c1 = $arg0;
    }
    
    /**
     * Returns the c2
     * @return string
     */
    function getC2() {
        if (is_null($this->c2)) {
            $this->c2 = '';
        }
        return $this->c2;
    }
    
    /**
     * Sets the c2
     * @param string
     */
    function setC2($arg0) {
        $this->c2 = $arg0;
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
        $this->c3 = $arg0;
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
        $this->c4 = $arg0;
    }
    
    /**
     * Returns the c5
     * @return string
     */
    function getC5() {
        if (is_null($this->c5)) {
            $this->c5 = 0;
        }
        return $this->c5;
    }
    
    /**
     * Sets the c5
     * @param string
     */
    function setC5($arg0) {
        $this->c5 = $arg0;
    }
    
    /**
     * Returns the c6
     * @return string
     */
    function getC6() {
        if (is_null($this->c6)) {
            $this->c6 = '';
        }
        return $this->c6;
    }
    
    /**
     * Sets the c6
     * @param string
     */
    function setC6($arg0) {
        $this->c6 = $arg0;
    }
    
    /**
     * Returns the c7
     * @return string
     */
    function getC7() {
        if (is_null($this->c7)) {
            $this->c7 = '';
        }
        return $this->c7;
    }
    
    /**
     * Sets the c7
     * @param string
     */
    function setC7($arg0) {
        $this->c7 = $arg0;
    }
    
    /**
     * Returns the c8
     * @return string
     */
    function getC8() {
        if (is_null($this->c8)) {
            $this->c8 = '';
        }
        return $this->c8;
    }
    
    /**
     * Sets the c8
     * @param string
     */
    function setC8($arg0) {
        $this->c8 = $arg0;
    }

    /**
     * Returns the c9
     * @return string
     */
    function getC9() {
        if (is_null($this->c9)) {
            $this->c9 = '';
        }
        return $this->c9;
    }
    
    /**
     * Sets the c9
     * @param string
     */
    function setC9($arg0) {
        $this->c9 = $arg0;
    }
    
    /**
     * Returns the c10
     * @return string
     */
    function getC10() {
        if (is_null($this->c10)) {
            $this->c10 = '';
        }
        return $this->c10;
    }
    
    /**
     * Sets the c10
     * @param string
     */
    function setC10($arg0) {
        $this->c10 = $arg0;
    }
    
    /**
     * Returns the c11
     * @return string
     */
    function getC11() {
        if (is_null($this->c11)) {
            $this->c11 = '';
        }
        return $this->c11;
    }
    
    /**
     * Sets the c11
     * @param string
     */
    function setC11($arg0) {
        $this->c11 = $arg0;
    }
    
    /**
     * Returns the c12
     * @return string
     */
    function getC12() {
        if (is_null($this->c12)) {
            $this->c12 = '';
        }
        return $this->c12;
    }
    
    /**
     * Sets the c12
     * @param string
     */
    function setC12($arg0) {
        $this->c12 = $arg0;
    }
    
    /**
     * Returns the c13
     * @return string
     */
    function getC13() {
        if (is_null($this->c13)) {
            $this->c13 = 0;
        }
        return $this->c13;
    }
    
    /**
     * Sets the c13
     * @param string
     */
    function setC13($arg0) {
        $this->c13 = $arg0;
    }
    
    /**
     * Returns the c14
     * @return string
     */
    function getC14() {
        if (is_null($this->c14)) {
            $this->c14 = '';
        }
        return $this->c14;
    }
    
    /**
     * Sets the c14
     * @param string
     */
    function setC14($arg0) {
        $this->c14 = $arg0;
    }
    
    /**
     * Returns the c15
     * @return string
     */
    function getC15() {
        if (is_null($this->c15)) {
            $this->c15 = '';
        }
        return $this->c15;
    }
    
    /**
     * Sets the c15
     * @param string
     */
    function setC15($arg0) {
        $this->c15 = $arg0;
    }
    

    /**
     * Returns the mv1
     * @return string
     */
    function getMV1() {
        if (is_null($this->mv1)) {
            $this->mv1 = 0;
        }
        return $this->mv1;
    }
    
    /**
     * Sets the mv1
     * @param string
     */
    function setMV1($arg0) {
        $this->mv1 = $arg0;
    }
    
    /**
     * Returns the mv2
     * @return string
     */
    function getMV2() {
        if (is_null($this->mv2)) {
            $this->mv2 = '';
        }
        return $this->mv2;
    }
    
    /**
     * Sets the mv2
     * @param string
     */
    function setMV2($arg0) {
        $this->mv2 = $arg0;
    }
    
    /**
     * Returns the mv3
     * @return string
     */
    function getMV3() {
        if (is_null($this->mv3)) {
            $this->mv3 = '';
        }
        return $this->mv3;
    }
    
    /**
     * Sets the mv3
     * @param string
     */
    function setMV3($arg0) {
        $this->mv3 = $arg0;
    }
    
    /**
     * Returns the mv4
     * @return string
     */
    function getMV4() {
        if (is_null($this->mv4)) {
            $this->mv4 = '';
        }
        return $this->mv4;
    }
    
    /**
     * Sets the mv4
     * @param string
     */
    function setMV4($arg0) {
        $this->mv4 = $arg0;
    }
    
    /**
     * Returns the mv5
     * @return string
     */
    function getMV5() {
        if (is_null($this->mv5)) {
            $this->mv5 = 0;
        }
        return $this->mv5;
    }
    
    /**
     * Sets the mv5
     * @param string
     */
    function setMV5($arg0) {
        $this->mv5 = $arg0;
    }
    
    /**
     * Returns the mv6
     * @return string
     */
    function getMV6() {
        if (is_null($this->mv6)) {
            $this->mv6 = '';
        }
        return $this->mv6;
    }
    
    /**
     * Sets the mv6
     * @param string
     */
    function setMV6($arg0) {
        $this->mv6 = $arg0;
    }
    
    /**
     * Returns the mv7
     * @return string
     */
    function getMV7() {
        if (is_null($this->mv7)) {
            $this->mv7 = '';
        }
        return $this->mv7;
    }
    
    /**
     * Sets the mv7
     * @param string
     */
    function setMV7($arg0) {
        $this->mv7 = $arg0;
    }
    
    /**
     * Returns the mv8
     * @return string
     */
    function getMV8() {
        if (is_null($this->mv8)) {
            $this->mv8 = '';
        }
        return $this->mv8;
    }
    
    /**
     * Sets the mv8
     * @param string
     */
    function setMV8($arg0) {
        $this->mv8 = $arg0;
    }

    /**
     * Returns the mv9
     * @return string
     */
    function getMV9() {
        if (is_null($this->mv9)) {
            $this->mv9 = '';
        }
        return $this->mv9;
    }
    
    /**
     * Sets the mv9
     * @param string
     */
    function setMV9($arg0) {
        $this->mv9 = $arg0;
    }
    
    /**
     * Returns the mv10
     * @return string
     */
    function getMV10() {
        if (is_null($this->mv10)) {
            $this->mv10 = '';
        }
        return $this->mv10;
    }
    
    /**
     * Sets the mv10
     * @param string
     */
    function setMV10($arg0) {
        $this->mv10 = $arg0;
    }
    
    /**
     * Returns the mv11
     * @return string
     */
    function getMV11() {
        if (is_null($this->mv11)) {
            $this->mv11 = '';
        }
        return $this->mv11;
    }
    
    /**
     * Sets the mv11
     * @param string
     */
    function setMV11($arg0) {
        $this->mv11 = $arg0;
    }
    
    /**
     * Returns the mv12
     * @return string
     */
    function getMV12() {
        if (is_null($this->mv12)) {
            $this->mv12 = '';
        }
        return $this->mv12;
    }
    
    /**
     * Sets the mv12
     * @param string
     */
    function setMV12($arg0) {
        $this->mv12 = $arg0;
    }
    
    /**
     * Returns the mv13
     * @return string
     */
    function getMV13() {
        if (is_null($this->mv13)) {
            $this->mv13 = 0;
        }
        return $this->mv13;
    }
    
    /**
     * Sets the mv13
     * @param string
     */
    function setMV13($arg0) {
        $this->mv13 = $arg0;
    }
    
    /**
     * Returns the mv14
     * @return string
     */
    function getMV14() {
        if (is_null($this->mv14)) {
            $this->mv14 = '';
        }
        return $this->mv14;
    }
    
    /**
     * Sets the mv14
     * @param string
     */
    function setMV14($arg0) {
        $this->mv14 = $arg0;
    }
    
    /**
     * Returns the mv15
     * @return string
     */
    function getMV15() {
        if (is_null($this->mv15)) {
            $this->mv15 = '';
        }
        return $this->mv15;
    }
    
    /**
     * Sets the mv15
     * @param string
     */
    function setMV15($arg0) {
        $this->mv15 = $arg0;
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
					$entry = preg_replace("/_([a-zA-Z0-9])/e","strtoupper('\\1')",$key);
					if (is_callable(array($this, 'add' . ucfirst($entry)),false, $callableName)) {
						foreach ($value as $key2 => $value1) {
							if (is_string($value1)) {
								$this->{'add' . ucfirst($entry)}(trim($value1), $key2);
							} else {
								$this->{'add' . ucfirst($entry)}($value1, $key2);
							}
						}
					} else {
						$entry = preg_replace("/_([a-zA-Z0-9])/e","strtoupper('\\1')",$key);
						if (is_callable(array($this, 'set' . ucfirst($entry)),false, $callableName)) {
							if (is_string($value)) {
								$this->{'set' . ucfirst($entry)}(trim($value));
							} else {
								$this->{'set' . ucfirst($entry)}($value);
							}
						}
					}
				} else {
					$entry = preg_replace("/_([a-zA-Z0-9])/e","strtoupper('\\1')",$key);
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
		if (count($this->getChildArray()) > 0) {
			$ret_val = 0;
			foreach ($this->getChildArray() as $child_item) {
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
	
	/**
	 * Returns the click_out 
	 * @return integer
	 */
	function getClickOut() {
		if (count($this->getChildArray()) > 0) {
			$ret_val = 0;
			foreach ($this->getChildArray() as $child_item) {
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
	
	/**
	 * Returns the leads 
	 * @return integer
	 */
	function getLeads() {
		if (count($this->getChildArray()) > 0) {
			$ret_val = 0;
			foreach ($this->getChildArray() as $child_item) {
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
		return 'Grand Total';
	}
	
	/**
	 * Alias
	 * @return string
	 */
	function getPrintTitle() {
		return 'Grand Total';
	}
}
?>
