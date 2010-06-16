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
	private static $DETAIL_LEVEL_ARRAY = array(ReportBasicForm::DETAIL_LEVEL_PPC_NETWORK,ReportBasicForm::DETAIL_LEVEL_PPC_ACCOUNT,ReportBasicForm::DETAIL_LEVEL_AFFILIATE_NETWORK,ReportBasicForm::DETAIL_LEVEL_CAMPAIGN,ReportBasicForm::DETAIL_LEVEL_LANDING_PAGE,ReportBasicForm::DETAIL_LEVEL_KEYWORD,ReportBasicForm::DETAIL_LEVEL_TEXT_AD,ReportBasicForm::DETAIL_LEVEL_REFERER,ReportBasicForm::DETAIL_LEVEL_IP,ReportBasicForm::DETAIL_LEVEL_C1,ReportBasicForm::DETAIL_LEVEL_C2,ReportBasicForm::DETAIL_LEVEL_C3,ReportBasicForm::DETAIL_LEVEL_C4);
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
			$this->isDetailIdSelected(ReportBasicForm::DETAIL_LEVEL_C4)
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
				ReportBasicForm::echoCell('$' . number_format($row->getNet(),2));
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
