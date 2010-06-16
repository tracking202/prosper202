<?php

/**
 * ReportBasicForm contains methods to work with the advertiser_bounty_summary table.
 *  
 * @author Ben Rotz
 * @since 2008-11-04 11:43 MST
 */

class ReportBasicForm {

	const DETAIL_LEVEL_NONE = 0;
	const DETAIL_LEVEL_PPC_NETWORK = 1;
	const DETAIL_LEVEL_PPC_ACCOUNT = 2;
	const DETAIL_LEVEL_AFFILIATE_NETWORK = 3;
	const DETAIL_LEVEL_CAMPAIGN = 4;
	const DETAIL_LEVEL_LANDING_PAGE = 5;
	const DETAIL_LEVEL_C1 = 6;
	const DETAIL_LEVEL_C2 = 7;
	const DETAIL_LEVEL_C3 = 8;
	const DETAIL_LEVEL_C4 = 9;
	const DETAIL_LEVEL_KEYWORD = 10;
	const DETAIL_LEVEL_TEXT_AD = 11;
	const DETAIL_LEVEL_REFERER = 12;
	const DETAIL_LEVEL_REDIRECT = 13;
	const DETAIL_LEVEL_IP = 14;
	const DETAIL_LEVEL_INTERVAL = 15;

	const DETAIL_GROUP_NONE = 0;

	const DISPLAY_TYPE_TABLE = 0;
	const DISPLAY_TYPE_ROW = 1;

	const DISPLAY_TOTAL_FIRST = 0;
	const DISPLAY_TOTAL_LAST = 1;

	const DISPLAY_LEVEL_NONE = 0;
	const DISPLAY_LEVEL_TITLE = 1;
	const DISPLAY_LEVEL_CLICK_COUNT = 2;
	const DISPLAY_LEVEL_CLICK_OUT_COUNT = 3;
	const DISPLAY_LEVEL_LEAD_COUNT = 4;
	const DISPLAY_LEVEL_SU = 5;
	const DISPLAY_LEVEL_PAYOUT = 6;
	const DISPLAY_LEVEL_EPC = 7;
	const DISPLAY_LEVEL_CPC = 8;
	const DISPLAY_LEVEL_INCOME = 9;
	const DISPLAY_LEVEL_COST = 10;
	const DISPLAY_LEVEL_NET = 11;
	const DISPLAY_LEVEL_ROI = 12;
	const DISPLAY_LEVEL_OPTIONS = 13;
	
	const SORT_NAME = 0;
	const SORT_CLICK = 1;
	const SORT_LEAD = 2;
	const SORT_SU = 3;
	const SORT_PAYOUT = 4;
	const SORT_EPC = 5;
	const SORT_CPC = 6;
	const SORT_INCOME = 7;
	const SORT_COST = 8;
	const SORT_NET = 9;
	const SORT_ROI = 10;
	
	const REPORT_TYPE_DEFAULT = 1;
	const REPORT_TYPE_EXPORT = 2;
	const REPORT_TYPE_PRINT = 3;
	const REPORT_TYPE_EXPORT_LEAD = 4;

	const DETAIL_INTERVAL_NONE = 0;
	const DETAIL_INTERVAL_DAY = 1;
	const DETAIL_INTERVAL_WEEK = 2;
	const DETAIL_INTERVAL_MONTH = 3;
	const DETAIL_INTERVAL_HOUR = 4;

	private static $DISPLAY_LEVEL_ARRAY = array(self::DISPLAY_LEVEL_TITLE,self::DISPLAY_LEVEL_CLICK_COUNT,self::DISPLAY_LEVEL_LEAD_COUNT,self::DISPLAY_LEVEL_SU,self::DISPLAY_LEVEL_PAYOUT,self::DISPLAY_LEVEL_EPC,self::DISPLAY_LEVEL_CPC,self::DISPLAY_LEVEL_INCOME,self::DISPLAY_LEVEL_COST,self::DISPLAY_LEVEL_NET,self::DISPLAY_LEVEL_ROI);
	private static $DETAIL_LEVEL_ARRAY = array(self::DETAIL_LEVEL_PPC_NETWORK,self::DETAIL_LEVEL_PPC_ACCOUNT,self::DETAIL_LEVEL_AFFILIATE_NETWORK,self::DETAIL_LEVEL_CAMPAIGN,self::DETAIL_LEVEL_LANDING_PAGE,self::DETAIL_LEVEL_KEYWORD,self::DETAIL_LEVEL_TEXT_AD,self::DETAIL_LEVEL_REFERER,self::DETAIL_LEVEL_REDIRECT,self::DETAIL_LEVEL_IP,self::DETAIL_LEVEL_C1,self::DETAIL_LEVEL_C2,self::DETAIL_LEVEL_C3,self::DETAIL_LEVEL_C4);
	private static $SORT_LEVEL_ARRAY = array(self::SORT_NAME,self::SORT_CLICK,self::SORT_LEAD,self::SORT_SU,self::SORT_PAYOUT,self::SORT_EPC,self::SORT_CPC,self::SORT_INCOME,self::SORT_COST,self::SORT_NET,self::SORT_ROI);
	
	private static $DETAIL_INTERVAL_ARRAY = array(self::DETAIL_INTERVAL_DAY,self::DETAIL_INTERVAL_WEEK,self::DETAIL_INTERVAL_MONTH);

	const DETAIL_PAY_CHANGES_NONE = 0;
	const DETAIL_PAY_CHANGES_ALL = 1;
	const DETAIL_PAY_CHANGES_BOUNTY = 2;
	const DETAIL_PAY_CHANGES_PAYOUT = 3;
	const DETAIL_PAY_CHANGES_ADJUSTMENT = 4;

	private static $DETAIL_PAY_CHANGES_ARRAY = array(self::DETAIL_PAY_CHANGES_ALL,self::DETAIL_PAY_CHANGES_BOUNTY,self::DETAIL_PAY_CHANGES_PAYOUT,self::DETAIL_PAY_CHANGES_ADJUSTMENT);

	const FILTER_OPERATION_NONE = 0;
	const FILTER_OPERATION_EQUAL_TO = 1;
	const FILTER_OPERATION_NOT_EQUAL_TO = 2;
	const FILTER_OPERATION_LESS_THAN = 3;
	const FILTER_OPERATION_LESS_THAN_OR_EQUAL_TO = 4;
	const FILTER_OPERATION_GREATER_THAN = 5;
	const FILTER_OPERATION_GREATER_THAN_OR_EQUAL_TO = 6;

	private static $FILTER_OPERATION_ARRAY = array(self::FILTER_OPERATION_GREATER_THAN_OR_EQUAL_TO, self::FILTER_OPERATION_GREATER_THAN, self::FILTER_OPERATION_LESS_THAN_OR_EQUAL_TO, self::FILTER_OPERATION_LESS_THAN, self::FILTER_OPERATION_EQUAL_TO, self::FILTER_OPERATION_NOT_EQUAL_TO);

	const DATE_FORM_ISO = "c";
	const DATE_FORM_MDY = "m/d/Y";
	const DATE_OPTION_RANGE = 0;
	const DATE_OPTION_TOD = 1;
	const DATE_OPTION_YES = 2;
	const DATE_OPTION_L7D = 3;
	const DATE_OPTION_LWK = 4;
	const DATE_OPTION_L2WK = 5;
	const DATE_OPTION_L3WK = 6;
	const DATE_OPTION_L4WK = 7;
	const DATE_OPTION_MTD = 8;
	const DATE_OPTION_M2TD = 9;
	const DATE_OPTION_M3TD = 10;
	const DATE_OPTION_LMO = 11;
	const DATE_OPTION_L3MO = 12;
	const DATE_OPTION_L6MO = 13;
	const DATE_OPTION_L12MO = 14;
	const DATE_OPTION_NXTR = 15;
	const DATE_OPTION_PRVR = 16;
	const DATE_OPTION_NXTD = 17;
	const DATE_OPTION_PRVD = 18;
	const DATE_OPTION_YTD = 19;
	const DATE_OPTION_L4D = 20;
	const DATE_OPTION_STRING_RANGE = 'custom';
	const DATE_OPTION_STRING_TOD = 'today';
	const DATE_OPTION_STRING_YES = 'yesterday';
	const DATE_OPTION_STRING_L7D = 'last7days';
	const DATE_OPTION_STRING_L4D = 'last4days';
	const DATE_OPTION_STRING_LWK = 'lastweek';
	const DATE_OPTION_STRING_L2WK = 'last2week';
	const DATE_OPTION_STRING_L3WK = 'last3week';
	const DATE_OPTION_STRING_L4WK = 'last4week';
	const DATE_OPTION_STRING_MTD = 'month';
	const DATE_OPTION_STRING_M2TD = 'prev2month';
	const DATE_OPTION_STRING_M3TD = 'prev3month';
	const DATE_OPTION_STRING_LMO = 'lastmonth';
	const DATE_OPTION_STRING_L3MO = 'last3months';
	const DATE_OPTION_STRING_L6MO = 'last6months';
	const DATE_OPTION_STRING_L12MO = 'last12months';
	const DATE_OPTION_STRING_YTD = 'year';
	
	// +-----------------------------------------------------------------------+
	// | PRIVATE VARIABLES                                                     |
	// +-----------------------------------------------------------------------+

	private $report_type;
	private $publisher_list;
	private $advertiser_list;
	private $offer_list;
	private $domain_list;
	private $account_user_list;
	protected $display_type;
	private $display_total_position;
	protected $display_check;
	protected $display;
	protected $display_order;
	protected $filter_display;
	protected $details_check;
	protected $details;
	protected $details_sort;
	protected $detail_interval;
	protected $detail_columns;
	protected $detail_columns_sort;
	protected $details_in_columns;
	private $additional_options_toggle;
	protected $show_advertiser_scrub;
	protected $show_scrub;
	private $show_adjustments;
	private $date_intervals;
	protected $submit_report;
	protected $calculate_dates;
	protected $show_title_id;
	protected $rollup_sub_tables;
	protected $base_date_option;
	protected $hide_report_parameters;
	protected $drill_down;
	protected $detail_pay_changes;
	protected $detail_group;
	protected $filter_detail_level;
	protected $filter_display_level;
	protected $filter_operation;
	protected $filter_value;

	// +-----------------------------------------------------------------------+
	// | PUBLIC METHODS                                                        |
	// +-----------------------------------------------------------------------+

	/**
	 * Returns the date_option
	 * @return integer
	 */
	function getDateOption() {
		if (is_null($this->date_option)) {
			$this->date_option = self::DATE_OPTION_TOD;
		}
		return $this->date_option;
	}
	
	/**
	 * Returns the DETAIL_INTERVAL_ARRAY
	 * @return array
	 */
	static function getDetailIntervalArray() {
		return self::$DETAIL_INTERVAL_ARRAY;
	}

	/**
	 * Returns the DETAIL_PAY_CHANGES_ARRAY
	 * @return array
	 */
	static function getDetailPayChangesArray() {
		return self::$DETAIL_PAY_CHANGES_ARRAY;
	}

	/**
	 * Returns the FILTER_OPERATION_ARRAY
	 * @return array
	 */
	static function getFilterOperationArray() {
		return self::$FILTER_OPERATION_ARRAY;
	}

	/**
	 * Returns the report_type
	 * @return boolean
	 */
	function getReportType() {
		if (is_null($this->report_type)) {
			$this->report_type = self::REPORT_TYPE_DEFAULT;
		}
		return $this->report_type;
	}

	/**
	 * Sets the report_type
	 * @param boolean
	 */
	function setReportType($arg0) {
		$this->report_type = $arg0;
	}

	/**
	 * Returns the account_user_list
	 * @return array
	 */
	function getAccountUserList() {
		if (is_null($this->account_user_list)) {
			$this->account_user_list = $this->getContext()->getController()->getForm("Bloosky", "AccountUserList");
		}
		return $this->account_user_list;
	}

	/**
	 * Sets the account_user_list
	 * @param array
	 */
	function setAccountUserList($arg0) {
		$tmp_list = $this->getAccountUserList();
		$tmp_list->populate($arg0);
		$this->account_user_list = $tmp_list;
	}

	/**
	 * Returns the advertiser_list
	 * @return array
	 */
	function getAdvertiserList() {
		if (is_null($this->advertiser_list)) {
			$this->advertiser_list = $this->getContext()->getController()->getForm("Bloosky", "ReportingAdvertiserList");
		}
		return $this->advertiser_list;
	}

	/**
	 * Sets the advertiser_list
	 * @param array
	 */
	function setAdvertiserList($arg0) {
		$tmp_list = $this->getAdvertiserList();
		$tmp_list->populate($arg0);
		$this->advertiser_list = $tmp_list;
	}

	/**
	 * Returns the publisher_list
	 * @return array
	 */
	function getPublisherList() {
		if (is_null($this->publisher_list)) {
			$this->publisher_list = $this->getContext()->getController()->getForm("Bloosky", "ReportingPublisherList");
		}
		return $this->publisher_list;
	}

	/**
	 * Sets the publisher_list
	 * @param array
	 */
	function setPublisherList($arg0) {
		$tmp_list = $this->getPublisherList();
		$tmp_list->populate($arg0);
		$this->publisher_list = $tmp_list;
	}

	/**
	 * Returns the offer_list
	 * @return array
	 */
	function getOfferList() {
		if (is_null($this->offer_list)) {
			$this->offer_list = $this->getContext()->getController()->getForm("Bloosky", "ReportingOfferListNew");
		}
		return $this->offer_list;
	}

	/**
	 * Returns the offer_list_publisher_populated
	 * @return array
	 */
	function getOfferListPublisherPopulated() {
		$offer_list_publisher_populated = $this->getOfferList();
		$offer_list_publisher_populated->setPublisherIdArray($this->getPublisherList()->getFilteredPublisherIdArray());
		return $offer_list_publisher_populated;
	}

	/**
	 * Returns the offer_list_advertiser_populated
	 * @return array
	 */
	function getOfferListAdvertiserPopulated() {
		$offer_list_advertiser_populated = $this->getOfferList();
		$offer_list_advertiser_populated->setAdvertiserIdArray($this->getAdvertiserList()->getFilteredAdvertiserIdArray());
		return $offer_list_advertiser_populated;
	}

	/**
	 * Adds the lead offer_list
	 * @return string
	 */
	function setOfferList($arg0) {
		$tmp_list = $this->getOfferList();
		$tmp_list->populate($arg0);
		$this->offer_list = $tmp_list;
	}

	/**
	 * Returns the domain_list
	 * @return array
	 */
	function getDomainList() {
		if (is_null($this->domain_list)) {
			$this->domain_list = $this->getContext()->getController()->getForm('Bloosky', 'ReportingDomainList');
		}
		return $this->domain_list;
	}

	/**
	 * Sets the domain_list
	 * @param array
	 */
	function setDomainList($arg0) {
		$tmp_list = $this->getDomainList();
		$tmp_list->populate($arg0);
		$this->domain_list = $tmp_list;
	}

	/**
	 * Returns the show_advertiser_scrub
	 * @return boolean
	 */
	function getShowAdvertiserScrub() {
		if (is_null($this->show_advertiser_scrub)) {
			$this->show_advertiser_scrub = false;
		}
		return $this->show_advertiser_scrub;
	}

	/**
	 * Sets the show_advertiser_scrub
	 * @param boolean
	 */
	function setShowAdvertiserScrub($arg0) {
		$this->show_advertiser_scrub = $arg0;
	}

	/**
	 * Returns the show_scrub
	 * @return boolean
	 */
	function getShowScrub() {
		if (is_null($this->show_scrub)) {
			$this->show_scrub = false;
		}
		return $this->show_scrub;
	}

	/**
	 * Sets the show_scrub
	 * @param boolean
	 */
	function setShowScrub($arg0) {
		$this->show_scrub = $arg0;
	}

	/**
	 * Returns the show_adjustments
	 * @return boolean
	 */
	function getShowAdjustments() {
		if (is_null($this->show_adjustments)) {
			$this->show_adjustments = true;
		}
		return $this->show_adjustments;
	}

	/**
	 * Sets the show_adjustments
	 * @param boolean
	 */
	function setShowAdjustments($arg0) {
		$this->show_adjustments = $arg0;
	}

	/**
	 * Returns the display_type
	 * @return int
	 */
	public function getDisplayType() {
		if(is_null($this->display_type)) {
			$this->display_type = self::DISPLAY_TYPE_TABLE;
		}
		return $this->display_type;
	}

	/**
	 * Sets the display_type
	 * @param int
	 */
	public function setDisplayType($arg0) {
		$this->display_type = $arg0;
	}

	/**
	 * Returns if DISPLAY_TYPE_TABLE
	 * @return boolean
	 */
	public function isDisplayTypeTable() {
		return $this->getDisplayType() == self::DISPLAY_TYPE_TABLE;
	}

	/**
	 * Returns the display_total_position
	 * @return int
	 */
	public function getDisplayTotalPosition() {
		if(is_null($this->display_total_position)) {
			$this->display_total_position = self::DISPLAY_TOTAL_FIRST;
		}
		return $this->display_total_position;
	}

	/**
	 * Sets the display_total_position
	 * @param int
	 */
	public function setDisplayTotalPosition($arg0) {
		$this->display_total_position = $arg0;
	}

	/**
	 * Returns the display_check
	 * @return array
	 */
	function getDisplayCheck() {
		if (is_null($this->display_check)) {
			$this->display_check = array();
		}
		return $this->display_check;
	}

	/**
	 * Sets the display_check
	 * @param array
	 */
	function setDisplayCheck($arg0) {
		$this->display_check = $arg0;
	}

	/**
	 * Adds to the display_check
	 * @param integer
	 */
	function addDisplayCheck($arg0,$key=0) {
		$tmp_array = $this->getDisplayCheck();
		if(is_null($key)) {
			$tmp_array[] = $arg0;
		} else {
			$tmp_array[$key] = $arg0;
		}
		$this->setDisplayCheck($tmp_array);
	}

	/**
	 * Returns if the detail_check is selected
	 * @param integer
	 */
	function isDisplayCheckSelected($key) {
		$display_check_array = $this->getDisplayCheck();
		if(array_key_exists($key,$display_check_array)) {
			if($display_check_array[$key]) {
				return true;
			}
		}
		return false;
	}

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
	 * Returns the display
	 * @return array
	 */
	function getDisplay() {
		if (is_null($this->display)) {
			$this->display = array();
		}
		return $this->display;
	}

	/**
	 * Sets the display
	 * @param array
	 */
	function setDisplay($arg0) {
		$this->display = $arg0;
	}

	/**
	 * Adds to the display
	 * @param integer
	 */
	function addDisplay($arg0,$key=0) {
		if(is_null($this->display)) {
			$this->display = array();
		}
		$tmp_array = $this->getDisplay();
		if(is_null($key)) {
			$tmp_array[] = $arg0;
		} else {
			$tmp_array[$key] = $arg0;
		}
		$this->setDisplay($tmp_array);
	}

	/**
	 * Returns if the display_id is selected
	 * @return boolean
	 */
	function isDisplayIdSelected($arg0) {
		return in_array($arg0, $this->getDisplay());
	}

	/**
	 * Returns if the display_id is selected in the corresponding array key
	 * @return boolean
	 */
	function isDisplayIdSelectedInKey($arg0,$key) {
		$display_array = $this->getDisplay();
		if(array_key_exists($key,$display_array)) {
			if($display_array[$key] == $arg0) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns the display_order
	 * @return array
	 */
	function getDisplayOrder() {
		if (is_null($this->display_order)) {
			$this->display_order = array();
		}
		return $this->display_order;
	}

	/**
	 * Sets the display_order
	 * @param array
	 */
	function setDisplayOrder($arg0) {
		$this->display_order = $arg0;
	}

	/**
	 * Adds to the display_order
	 * @param integer
	 */
	function addDisplayOrder($arg0,$key=0) {
		if(is_null($this->display_order)) {
			$this->display_order = array();
		}
		$tmp_array = $this->getDisplayOrder();
		if(is_null($key)) {
			$tmp_array[] = $arg0;
		} else {
			$tmp_array[$key] = $arg0;
		}
		$this->setDisplayOrder($tmp_array);
	}

	/**
	 * Returns the filter_display
	 * @return array
	 */
	function getFilterDisplay() {
		if (is_null($this->filter_display)) {
			$this->filter_display = array();
		}
		return $this->filter_display;
	}

	/**
	 * Sets the filter_display
	 * @param array
	 */
	function setFilterDisplay($arg0) {
		$this->filter_display = $arg0;
	}

	/**
	 * Returns the details_check
	 * @return array
	 */
	function getDetailsCheck() {
		if (is_null($this->details_check)) {
			$this->details_check = array();
		}
		return $this->details_check;
	}

	/**
	 * Sets the details_check
	 * @param array
	 */
	function setDetailsCheck($arg0) {
		$this->details_check = $arg0;
	}

	/**
	 * Adds to the details_check
	 * @param integer
	 */
	function addDetailsCheck($arg0,$key=0) {
		if(is_null($this->details_check)) {
			$this->details_check = array();
		}
		$tmp_array = $this->getDetailsCheck();
		if(is_null($key)) {
			$tmp_array[] = $arg0;
		} else {
			$tmp_array[$key] = $arg0;
		}
		$this->setDetailsCheck($tmp_array);
	}

	/**
	 * Returns if the detail_check is selected
	 * @param integer
	 */
	function isDetailsCheckSelected($key) {
		$details_check_array = $this->getDetailsCheck();
		if(array_key_exists($key,$details_check_array)) {
			if($details_check_array[$key]) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns the details
	 * @return array
	 */
	function getDetails() {
		if (is_null($this->details)) {
			$this->details = array();
		}
		return $this->details;
	}

	/**
	 * Sets the details
	 * @param array
	 */
	function setDetails($arg0) {
		$this->details = $arg0;
	}

	/**
	 * Adds to the details
	 * @param integer
	 */
	function addDetails($arg0,$key=0) {
		if(is_null($this->details)) {
			$this->details = array();
		}
		$tmp_array = $this->getDetails();
		if(is_null($key)) {
			$tmp_array[] = $arg0;
		} else {
			$tmp_array[$key] = $arg0;
		}
		$this->setDetails($tmp_array);
	}

	/**
	 * Returns if the detail_id is selected
	 * @return boolean
	 */
	function isDetailIdSelected($arg0) {
		return in_array($arg0, $this->getDetails());
	}

	/**
	 * Returns if the detail_id is selected in the corresponding array key
	 * @return boolean
	 */
	function isDetailIdSelectedInKey($arg0,$key) {
		$details_array = $this->getDetails();
		if(array_key_exists($key,$details_array)) {
			if($details_array[$key] == $arg0) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns if the detail_id is selected before the current detail_id
	 * @return boolean
	 */
	function isDetailIdSelectedBeforeKey($arg0,$detail_id) {
		$details_array = $this->getDetails();
		for($i=0;$i<$detail_id;$i++) {
			if(array_key_exists($i,$details_array)) {
				if($details_array[$i] == $arg0) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Returns the details_sort
	 * @return array
	 */
	function getDetailsSort() {
		if (is_null($this->details_sort)) {
			$this->details_sort = array();
		}
		return $this->details_sort;
	}

	/**
	 * Sets the details_sort
	 * @param array
	 */
	function setDetailsSort($arg0) {
		$this->details_sort = $arg0;
	}

	/**
	 * Adds to the details_sort
	 * @param integer
	 */
	function addDetailsSort($arg0,$key) {
		$tmp_array = $this->getDetailsSort();
		if(is_null($key)) {
			$tmp_array[] = $arg0;
		} else {
			$tmp_array[$key] = $arg0;
		}
		$this->setDetailsSort($tmp_array);
	}

	/**
	 * Returns if the details_sort_id is selected
	 * @return boolean
	 */
	function isDetailSortIdSelected($arg0) {
		return in_array($arg0, $this->getDetailsSort());
	}

	/**
	 * Returns if the details_sort_id is selected in the corresponding array key
	 * @return boolean
	 */
	function isDetailSortIdSelectedInKey($arg0,$key) {
		$details_sort_array = $this->getDetailsSort();
		if(array_key_exists($key,$details_sort_array)) {
			if($details_sort_array[$key] == $arg0) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns the detail_columns_sort
	 * @return array
	 */
	function getDetailColumnsSort() {
		if (is_null($this->detail_columns_sort)) {
			$this->detail_columns_sort = array();
		}
		return $this->detail_columns_sort;
	}

	/**
	 * Sets the detail_columns_sort
	 * @param array
	 */
	function setDetailColumnsSort($arg0) {
		$this->detail_columns_sort = $arg0;
	}

	/**
	 * Adds to the detail_columns_sort
	 * @param integer
	 */
	function addDetailColumnsSort($arg0,$key) {
		$tmp_array = $this->getDetailColumnsSort();
		if(is_null($key)) {
			$tmp_array[] = $arg0;
		} else {
			$tmp_array[$key] = $arg0;
		}
		$this->setDetailColumnsSort($tmp_array);
	}

	/**
	 * Returns if the detail_columns_sort_id is selected
	 * @return boolean
	 */
	function isDetailColumnSortIdSelected($arg0) {
		return in_array($arg0, $this->getDetailColumnsSort());
	}

	/**
	 * Returns if the details_sort_id is selected in the corresponding array key
	 * @return boolean
	 */
	function isDetailColumnSortIdSelectedInKey($arg0,$key) {
		$detail_columns_sort_array = $this->getDetailColumnsSort();
		if(array_key_exists($key,$detail_columns_sort_array)) {
			if($detail_columns_sort_array[$key] == $arg0) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns the detail_columns_sort with array key
	 * @return string
	 */
	function getDetailColumnsSortByKey($key) {
		$detail_columns_sort = $this->getDetailColumnsSort();
		if(array_key_exists($key,$detail_columns_sort)) {
			return $detail_columns_sort[$key];
		}
		return '';
	}

	/**
	 * Returns the details with array key
	 * @return string
	 */
	function getCurrentDetailByKey($key) {
		$key = $key - 1;
		$details = $this->getDetails();
		if(array_key_exists($key,$details)) {
			return $details[$key];
		}
		return '';
	}

	/**
	 * Returns the details with array key
	 * @return string
	 */
	function getDetailsByKey($key) {
		$details = $this->getDetails();
		if(array_key_exists($key,$details)) {
			return $details[$key];
		}
		return '';
	}

	/**
	 * Returns the details_sort with array key
	 * @return string
	 */
	function getDetailsSortByKey($key) {
		$details_sort = $this->getDetailsSort();
		if(array_key_exists($key,$details_sort)) {
			return $details_sort[$key];
		}
		return '';
	}

	/**
	 * Returns the detail_interval
	 * @return int
	 */
	function getDetailInterval() {
		if (is_null($this->detail_interval)) {
			$this->detail_interval = self::DETAIL_INTERVAL_NONE;
		}
		return $this->detail_interval;
	}

	/**
	 * Sets the detail_interval
	 * @param array
	 */
	function setDetailInterval($arg0) {
		$this->detail_interval = $arg0;
	}

	/**
	 * Returns if the key is a calendar interval
	 * @return boolean
	 */
	function isDetailIdSelectedInterval($key) {
		$details_array = $this->getDetails();
		if(array_key_exists($key,$details_array)) {
			if($details_array[$key] == self::DETAIL_LEVEL_INTERVAL) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns if the key is a calendar interval
	 * @return boolean
	 */
	function isDetailColumnIdSelectedInterval($key) {
		$detail_columns_array = $this->getDetailColumns();
		if(array_key_exists($key,$detail_columns_array)) {
			if($detail_columns_array[$key] == self::DETAIL_LEVEL_INTERVAL) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns the detail_columns
	 * @return array
	 */
	public function getDetailColumns() {
		if (is_null($this->detail_columns)) {
			$this->detail_columns = array();
		}
		return $this->detail_columns;
	}

	/**
	 * Sets the detail_columns
	 * @param array
	 */
	public function setDetailColumns($arg0) {
		$this->detail_columns = $arg0;
	}

	/**
	 * Adds to the detail_columns
	 * @param integer
	 */
	public function addDetailColumns($arg0,$key=null) {
		$tmp_array = $this->getDetailColumns();
		if(is_null($key)) {
			$tmp_array[] = $arg0;
		} else {
			$tmp_array[$key] = $arg0;
		}
		$this->setDetailColumns($tmp_array);
	}

	/**
	 * Returns if the detail_column_id is selected
	 * @return boolean
	 */
	public function isDetailColumnIdSelected($arg0) {
		return in_array($arg0, $this->getDetailColumns());
	}

	/**
	 * Returns if the detail_column_id is selected in the corresponding array key
	 * @return boolean
	 */
	public function isDetailColumnIdSelectedInKey($arg0,$key) {
		$detail_columns_array = $this->getDetailColumns();
		if(array_key_exists($key,$detail_columns_array)) {
			if($detail_columns_array[$key] == $arg0) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns the detail column with array key
	 * @return string
	 */
	public function getDetailColumnsByKey($key) {
		$detail_columns = $this->getDetailColumns();
		if(array_key_exists($key,$detail_columns)) {
			return $detail_columns[$key];
		}
		return '';
	}

	/**
	 * Returns the details_in_columns
	 * @return boolean
	 */
	function getDetailsInColumns() {
		if(is_null($this->details_in_columns)) {
			$this->details_in_columns = false;
		}
		return $this->details_in_columns;
	}

	/**
	 * Returns the details_in_columns
	 * @param boolean
	 */
	public function setDetailsInColumns($arg0) {
		$this->details_in_columns = $arg0;
	}


	/**
	 * Returns the detail_group
	 * @return int
	 */
	public function getDetailGroup() {
		if (is_null($this->detail_group)) {
			$this->detail_group = self::DETAIL_GROUP_NONE;
		}
		return $this->detail_group;
	}

	/**
	 * Sets the detail_group
	 * @param int
	 */
	public function setDetailGroup($arg0) {
		$this->detail_group = $arg0;
	}

	/**
	 * Returns if the detail_id is selected
	 * @return boolean
	 */
	public function isDetailGroupSelected($arg0) {
		return $arg0 == $this->getDetailGroup();
	}

	/**
	 * Returns the filter_detail_level
	 * @return array
	 */
	public function getFilterDetailLevel() {
		if(is_null($this->filter_detail_level)) {
			$this->filter_detail_level = array();
		}
		return $this->filter_detail_level;
	}

	/**
	 * Sets the filter_detail_level
	 * @param array
	 */
	public function setFilterDetailLevel($arg0) {
		$this->filter_detail_level = $arg0;
	}

	/**
	 * Adds to the filter_detail_level
	 * @param mixed
	 * @param mixed
	 */
	public function addFilterDetailLevel($arg0, $key = null) {
		$tmp_array = $this->getFilterDetailLevel();
		if(!is_null($key)) {
			$tmp_array[$key] = $arg0;
		} else {
			$tmp_array[] = $arg0;
		}
		$this->setFilterDetailLevel($tmp_array);
	}

	/**
	 * Returns a list of filter keys by detail level
	 * @param int
	 * @return array
	 */
	public function getFilterKeysByDetailLevel($detail_level_id) {
		$keys = array();

		foreach($this->getFilterDetailLevel() as $key => $filter_detail_level) {
			if($filter_detail_level == $detail_level_id) {
				$keys[] = $key;
			}
		}
		return $keys;
	}

	/**
	 * Returns a list of filter keys by detail and display level
	 * @param int
	 * @return array
	 */
	public function getFilterKeysByDetailAndDisplayLevel($detail_level_id, $display_level_id) {
		$keys = array();
		$filter_display_levels = $this->getFilterDisplayLevel();
		
		foreach($this->getFilterDetailLevel() as $key => $filter_detail_level) {
			if($filter_detail_level == $detail_level_id) {
				if($filter_display_levels[$key] == $display_level_id) {
					$keys[] = $key;
				}
			}
		}
		return $keys;
	}
	

	/**
	 * Returns the filter_display_level
	 * @return array
	 */
	public function getFilterDisplayLevel() {
		if(is_null($this->filter_display_level)) {
			$this->filter_display_level = array();
		}
		return $this->filter_display_level;
	}

	/**
	 * Sets the filter_display_level
	 * @param array
	 */
	public function setFilterDisplayLevel($arg0) {
		$this->filter_display_level = $arg0;
	}

	/**
	 * Adds to the filter_display_level
	 * @param mixed
	 * @param mixed
	 */
	public function addFilterDisplayLevel($arg0, $key = null) {
		$tmp_array = $this->getFilterDisplayLevel();
		if(!is_null($key)) {
			$tmp_array[$key] = $arg0;
		} else {
			$tmp_array[] = $arg0;
		}
		$this->setFilterDisplayLevel($tmp_array);
	}

	/**
	 * Returns if the filter_display_level_id is selected in the corresponding array key
	 * @return boolean
	 */
	public function isFilterDisplaySelectedInKey($arg0, $key) {
		$filter_display_level = $this->getFilterDisplayLevel();
		if(array_key_exists($key,$filter_display_level)) {
			if($filter_display_level[$key] == $arg0) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns a filter display level by index key
	 * @param mixed
	 * @param mixed
	 */
	public function getFilterDisplayLevelByKey($key) {
		$filter_display_levels = $this->getFilterDisplayLevel();
		if(array_key_exists($key, $filter_display_levels)) {
			return $filter_display_levels[$key];
		}
		return '';
	}

	/**
	 * Returns the filter_operation
	 * @return array
	 */
	public function getFilterOperation() {
		if(is_null($this->filter_operation)) {
			$this->filter_operation = array();
		}
		return $this->filter_operation;
	}

	/**
	 * Sets the filter_operation
	 * @param array
	 */
	public function setFilterOperation($arg0) {
		$this->filter_operation = $arg0;
	}

	/**
	 * Adds to the filter_operation
	 * @param mixed
	 * @param mixed
	 */
	public function addFilterOperation($arg0, $key = null) {
		$tmp_array = $this->getFilterOperation();
		if(!is_null($key)) {
			$tmp_array[$key] = $arg0;
		} else {
			$tmp_array[] = $arg0;
		}
		$this->setFilterOperation($tmp_array);
	}

	/**
	 * Returns if the filter_operation is selected in the corresponding array key
	 * @return boolean
	 */
	public function isFilterOperationSelectedInKey($arg0, $key) {
		$filter_operation = $this->getFilterOperation();
		if(array_key_exists($key,$filter_operation)) {
			if($filter_operation[$key] == $arg0) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns a filter operation by index key
	 * @param mixed
	 * @param mixed
	 */
	public function getFilterOperationByKey($key) {
		$filter_operations = $this->getFilterOperation();
		if(array_key_exists($key, $filter_operations)) {
			return $filter_operations[$key];
		}
		return self::FILTER_OPERATION_NONE;
	}

	/**
	 * Returns the filter_value
	 * @return array
	 */
	public function getFilterValue() {
		if(is_null($this->filter_value)) {
			$this->filter_value = array();
		}
		return $this->filter_value;
	}

	/**
	 * Sets the filter_value
	 * @param array
	 */
	public function setFilterValue($arg0) {
		$this->filter_value = $arg0;
	}

	/**
	 * Adds to the filter_value
	 * @param mixed
	 * @param mixed
	 */
	public function addFilteValue($arg0, $key = null) {
		$tmp_array = $this->getFilterValue();
		if(!is_null($key)) {
			$tmp_array[$key] = $arg0;
		} else {
			$tmp_array[] = $arg0;
		}
		$this->setFilterValue($tmp_array);
	}

	/**
	 * Returns a filter value by index key
	 * @param mixed
	 * @param mixed
	 */
	public function getFilterValueByKey($key) {
		$filter_values = $this->getFilterValue();
		if(array_key_exists($key, $filter_values)) {
			return $filter_values[$key];
		}
		return '';
	}

	/**
	 * Returns the $hide_report_parameters
	 * @return int
	 */
	function getHideReportParameters() {
		if(is_null($this->hide_report_parameters)) {
			$this->hide_report_parameters = 0;
		}
		return $this->hide_report_parameters;
	}

	/**
	 * Sets $hide_report_parameters
	 * @param int
	 */
	function setHideReportParameters($arg0) {
		$this->hide_report_parameters = $arg0;
	}

	/**
	 * Returns the $drill_down
	 * @return int
	 */
	function getDrillDown() {
		if(is_null($this->drill_down)) {
			$this->drill_down = 0;
		}
		return $this->drill_down;
	}

	/**
	 * Sets $drill_down
	 * @param int
	 */
	function setDrillDown($arg0) {
		$this->drill_down = $arg0;
	}

	/**
	 * Returns the additional_options_toggle
	 * @return int
	 */
	function getAdditionalOptionsToggle() {
		if (is_null($this->additional_options_toggle)) {
			$this->additional_options_toggle = false;
		}
		return $this->additional_options_toggle;
	}

	/**
	 * Sets the additional_options_toggle
	 * @param array
	 */
	function setAdditionalOptionsToggle($arg0) {
		$this->additional_options_toggle = $arg0;
	}

	/**
	 * Returns the calculate_dates.
	 *
	 * @return boolean
	 */
	public function getCalculateDates() {
		if (is_null($this->calculate_dates)) {
			$this->calculate_dates = true;
		}
		return $this->calculate_dates;
	}

	/**
	 * Sets the calculate_dates.
	 *
	 * @param boolean
	 */
	public function setCalculateDates($arg0) {
		$this->calculate_dates = $arg0;
	}

	/**
	 * Returns the show_title_id
	 * @return boolean
	 */
	function getShowTitleId() {
		if (is_null($this->show_title_id)) {
			$this->show_title_id = false;
		}
		return $this->show_title_id;
	}

	/**
	 * Sets the show_title_id
	 * @param boolean
	 */
	function setShowTitleId($arg0) {
		$this->show_title_id = $arg0;
	}

	/**
	 * Returns the rollup_sub_tables
	 * @return boolean
	 */
	function getRollupSubTables() {
		if (is_null($this->rollup_sub_tables)) {
			$this->rollup_sub_tables = false;
		}
		return $this->rollup_sub_tables;
	}

	/**
	 * Sets the rollup_sub_tables
	 * @param boolean
	 */
	function setRollupSubTables($arg0) {
		$this->rollup_sub_tables = $arg0;
	}

	/**
	 * Returns a string for the current date of report.
	 *
	 * @return string
	 */
	public function getRanOn() {
		$retVal = "report run on: " . date("m/d/Y h:i a");
		return $retVal;
	}

	/**
	 * Returns the submit_report.
	 *
	 * @return boolean
	 */
	public function getSubmitReport() {
		if (is_null($this->submit_report)) {
			$this->submit_report = false;
		}
		return $this->submit_report;
	}

	/**
	 * Sets the submit_report.
	 *
	 * @param boolean
	 */
	public function setSubmitReport($arg0) {
		$this->submit_report = $arg0;
	}

	/**
	 * Returns the base_date_option.
	 * @return int
	 */
	public function getBaseDateOption() {
		if (is_null($this->base_date_option)) {
			$this->base_date_option = $this->getDateOption();
		}
		return $this->base_date_option;
	}

	/**
	 * Sets the base_date_option.
	 * @param int
	 */
	public function setBaseDateOption($arg0) {
		$this->base_date_option = $arg0;
	}

	/**
	 * Returns the date option timestamp.
	 *
	 * @return integer
	 */
	public function getStartDateTimestamp($arg0) {
		if ($arg0 == self::DATE_OPTION_TOD) {
			return strtotime("now");
		} else if ($arg0 == self::DATE_OPTION_YES) {
			return strtotime("yesterday");
		} else if ($arg0 == self::DATE_OPTION_LWK) {
			return mktime(0, 0, 0, date("m"), (date("d")- date("w"))-7, date("Y"));
		} else if ($arg0 == self::DATE_OPTION_L2WK) {
			return mktime(0, 0, 0, date("m"), (date("d")- date("w"))-14, date("Y"));
		} else if ($arg0 == self::DATE_OPTION_L3WK) {
			return mktime(0, 0, 0, date("m"), (date("d")- date("w"))-21, date("Y"));
		} else if ($arg0 == self::DATE_OPTION_L4WK) {
			return mktime(0, 0, 0, date("m"), (date("d")- date("w"))-28, date("Y"));
		} else if ($arg0 == self::DATE_OPTION_MTD) {
			/* If we're the first day of the month, MTD is still last month's numbers */
			if(date('j') == '1') {
				return strtotime(date('m/1/Y', strtotime('now - 1 month')));
			}
			return strtotime(date("m/1/Y", strtotime("now")));
		} else if ($arg0 == self::DATE_OPTION_M2TD) {
			return strtotime(date("m/1/Y", strtotime("now - 1 months")));
		} else if ($arg0 == self::DATE_OPTION_M3TD) {
			return strtotime(date("m/1/Y", strtotime("now - 2 months")));
		} else if ($arg0 == self::DATE_OPTION_LMO) {
			/* If we're the first day of the month, LMO is two months ago */
			if(date('j') == '1') {
				return strtotime(date("m/1/Y", strtotime('now - 2 months')));
			}
			return strtotime(date("m/1/Y", strtotime('now - 1 month')));
		} else if ($arg0 == self::DATE_OPTION_L3MO) {
			return strtotime("now - 3 months");
		} else if ($arg0 == self::DATE_OPTION_L6MO) {
			return strtotime("now - 6 months");
		} else if ($arg0 == self::DATE_OPTION_L12MO) {
			return strtotime("now - 1 year");
		} else if ($arg0 == self::DATE_OPTION_L7D) {
			return strtotime("now - 7 days");
		} else if ($arg0 == self::DATE_OPTION_L4D) {
			return strtotime("now - 4 days");
		} else if ($arg0 == self::DATE_OPTION_YTD) {
			/* If we're the first day of the year, YTD is still last year's numbers */
			if(date('z') == '0') {
				return strtotime(date('1/1/Y', strtotime('now - 1 year')));
			}
			return strtotime(date('1/1/Y', strtotime('now')));
		}
		return strtotime("now");
	}

	/**
	 * Returns the date option timestamp.
	 *
	 * @return integer
	 */
	public function getEndDateTimestamp($arg0) {
		if ($arg0 == self::DATE_OPTION_TOD) {
			return strtotime("now");
		} else if ($arg0 == self::DATE_OPTION_YES) {
			return strtotime("yesterday");
		} else if ($arg0 == self::DATE_OPTION_LWK) {
			return mktime(0, 0, 0, date("m"), (date("d")- date("w"))-1, date("Y"));
		} else if ($arg0 == self::DATE_OPTION_L2WK) {
			return mktime(0, 0, 0, date("m"), (date("d")- date("w"))-8, date("Y"));
		} else if ($arg0 == self::DATE_OPTION_L3WK) {
			return mktime(0, 0, 0, date("m"), (date("d")- date("w"))-15, date("Y"));
		} else if ($arg0 == self::DATE_OPTION_L4WK) {
			return mktime(0, 0, 0, date("m"), (date("d")- date("w"))-22, date("Y"));
		} else if ($arg0 == self::DATE_OPTION_MTD) {
			/* If we're the first day of the month, MTD ends on the last day of last month */
			if(date('j') == '1') {
				return strtotime(date("m/t/Y", strtotime('now - 1 month')));
			}
			return strtotime("now - 1 day");
		} else if ($arg0 == self::DATE_OPTION_M2TD) {
			return strtotime("now");
		} else if ($arg0 == self::DATE_OPTION_M3TD) {
			return strtotime("now");
		} else if ($arg0 == self::DATE_OPTION_LMO) {
			/* If we're the first day of the month, LMO is two months ago */
			if(date('j') == '1') {
				return strtotime(date("m/t/Y", strtotime('now - 2 months')));
			}
			return strtotime(date("m/t/Y", strtotime('now - 1 month')));
		} else if ($arg0 == self::DATE_OPTION_L3MO) {
			return strtotime("now");
		} else if ($arg0 == self::DATE_OPTION_L6MO) {
			return strtotime("now");
		} else if ($arg0 == self::DATE_OPTION_L12MO) {
			return strtotime("now");
		} else if ($arg0 == self::DATE_OPTION_L7D) {
			return strtotime("now - 1 day");
		} else if ($arg0 == self::DATE_OPTION_L4D) {
			return strtotime("now - 1 day");
		} else if ($arg0 == self::DATE_OPTION_YTD) {
			/* If we're the first day of the year, YTD ends on the last day of last year */
			if(date('z') == '0') {
				return strtotime(date('12/31/Y', strtotime('now - 1 year')));
			}
			return strtotime('now - 1 day');
		}
		return strtotime("now");
	}

	/**
	 * Translates the detail level
	 * @return string
	 */
	static function translateDetailLevelById($arg0) {
		if ($arg0 == self::DETAIL_LEVEL_NONE) {
			return "None";
		} else if ($arg0 == self::DETAIL_LEVEL_PPC_NETWORK) {
			return "PPC Network";
		} else if ($arg0 == self::DETAIL_LEVEL_PPC_ACCOUNT) {
			return "PPC Account";
		} else if ($arg0 == self::DETAIL_LEVEL_AFFILIATE_NETWORK) {
			return "Affiliate Network";
		} else if ($arg0 == self::DETAIL_LEVEL_CAMPAIGN) {
			return "Campaign";
		} else if ($arg0 == self::DETAIL_LEVEL_LANDING_PAGE) {
			return "Landing Page";
		} else if ($arg0 == self::DETAIL_LEVEL_KEYWORD) {
			return "Keyword";
		} else if ($arg0 == self::DETAIL_LEVEL_TEXT_AD) {
			return "Text Ad";
		} else if ($arg0 == self::DETAIL_LEVEL_REFERER) {
			return "Referer";
		} else if ($arg0 == self::DETAIL_LEVEL_REDIRECT) {
			return "Redirect";
		} else if ($arg0 == self::DETAIL_LEVEL_IP) {
			return "IP";
		} else if ($arg0 == self::DETAIL_LEVEL_C1) {
			return "c1";
		} else if ($arg0 == self::DETAIL_LEVEL_C2) {
			return "c2";
		} else if ($arg0 == self::DETAIL_LEVEL_C3) {
			return "c3";
		} else if ($arg0 == self::DETAIL_LEVEL_C4) {
			return "c4";
		} else {
			return "Unknown";
		}
	}

	/**
	 * Translates the detail level to a th header
	 * @return string
	 */
	static function translateDetailLevelToHeaderById($arg0) {
		if ($arg0 == self::DETAIL_LEVEL_NONE) {
			return "None";
		} else if ($arg0 == self::DETAIL_LEVEL_PPC_NETWORK) {
			return "PPC Network";
		} else if ($arg0 == self::DETAIL_LEVEL_PPC_ACCOUNT) {
			return "PPC Account";
		} else if ($arg0 == self::DETAIL_LEVEL_AFFILIATE_NETWORK) {
			return "Aff Network";
		} else if ($arg0 == self::DETAIL_LEVEL_CAMPAIGN) {
			return "Campaign";
		} else if ($arg0 == self::DETAIL_LEVEL_LANDING_PAGE) {
			return "Landing Page";
		} else if ($arg0 == self::DETAIL_LEVEL_KEYWORD) {
			return "Keyword";
		} else if ($arg0 == self::DETAIL_LEVEL_TEXT_AD) {
			return "Text Ad";
		} else if ($arg0 == self::DETAIL_LEVEL_REFERER) {
			return "Referer";
		} else if ($arg0 == self::DETAIL_LEVEL_REDIRECT) {
			return "Redirect";
		} else if ($arg0 == self::DETAIL_LEVEL_IP) {
			return "IP";
		} else if ($arg0 == self::DETAIL_LEVEL_C1) {
			return "c1";
		} else if ($arg0 == self::DETAIL_LEVEL_C2) {
			return "c2";
		} else if ($arg0 == self::DETAIL_LEVEL_C3) {
			return "c3";
		} else if ($arg0 == self::DETAIL_LEVEL_C4) {
			return "c4";
		} else {
			return "Unknown";
		}
	}

	/**
	 * Translates the display level
	 * @return string
	 */
	static function translateDisplayLevelById($arg0) {
		return 'show ' . self::translateDisplayLevelNameById($arg0);
	}

	/**
	 * Translates the display level to a name
	 * @return string
	 */
	static function translateDisplayLevelNameById($arg0) {
		if ($arg0 == self::DISPLAY_LEVEL_NONE) {
			return 'None';
		} else if ($arg0 == self::DISPLAY_LEVEL_TITLE) {
			return 'Title';
		} else if ($arg0 == self::DISPLAY_LEVEL_CLICK_COUNT) {
			return 'Clicks';
		} else if ($arg0 == self::DISPLAY_LEVEL_LEAD_COUNT) {
			return 'Leads';
		} else if ($arg0 == self::DISPLAY_LEVEL_SU) {
			return 'S/U';
		} else if ($arg0 == self::DISPLAY_LEVEL_PAYOUT) {
			return 'Payout';
		} else if ($arg0 == self::DISPLAY_LEVEL_EPC) {
			return 'EPC';
		} else if ($arg0 == self::DISPLAY_LEVEL_CPC) {
			return 'CPC';
		} else if ($arg0 == self::DISPLAY_LEVEL_INCOME) {
			return 'Income';
		} else if ($arg0 == self::DISPLAY_LEVEL_COST) {
			return 'Cost';
		} else if ($arg0 == self::DISPLAY_LEVEL_NET) {
			return 'Net';
		} else if ($arg0 == self::DISPLAY_LEVEL_ROI) {
			return 'ROI';
		} else {
			return 'Unknown';
		}
	}

	/**
	 * Translates the detail interval
	 * @return string
	 */
	static function translateDetailIntervalById($arg0) {
		if ($arg0 == self::DETAIL_INTERVAL_NONE) {
			return "none";
		} else if ($arg0 == self::DETAIL_INTERVAL_DAY) {
			return "by day";
		} else if ($arg0 == self::DETAIL_INTERVAL_WEEK) {
			return "by week";
		} else if ($arg0 == self::DETAIL_INTERVAL_MONTH) {
			return "by month";
		} else if ($arg0 == self::DETAIL_INTERVAL_HOUR) {
			return "by hour";
		} else {
			return "unknown";
		}
	}

	/**
	 * Translates the detail level
	 * @return string
	 */
	static function translateSortById($arg0) {
		if ($arg0 == self::SORT_NAME) {
			return "by name";
		} else if ($arg0 == self::SORT_CLICK) {
			return "by clicks";
		} else if ($arg0 == self::SORT_LEAD) {
			return "by leads";
		} else if ($arg0 == self::SORT_SU) {
			return "by s/u";
		} else if ($arg0 == self::SORT_PAYOUT) {
			return "by payout";
		} else if ($arg0 == self::SORT_EPC) {
			return "by epc";
		} else if ($arg0 == self::SORT_CPC) {
			return "by cpc";
		} else if ($arg0 == self::SORT_INCOME) {
			return "by income";
		} else if ($arg0 == self::SORT_COST) {
			return "by cost";
		} else if ($arg0 == self::SORT_NET) {
			return "by net";
		} else if ($arg0 == self::SORT_ROI) {
			return "by roi";
		} else {
			return "unknown";
		}
	}

	/**
	 * Returns the name of the date type option.
	 *
	 * @return string
	 */
	static function translateDateOption($arg0) {
		/* @var $basic_report_form self */
		$basic_report_form = Controller::getInstance()->getContext()->getController()->getGlobalForm("BasicReporting");
		if ($arg0 == self::DATE_OPTION_TOD) {
			return "current today, " . date("F jS", $basic_report_form->getStartDateTimestamp($arg0));
		} else if ($arg0 == self::DATE_OPTION_YES) {
			return "yesterday, " . date("F jS", $basic_report_form->getStartDateTimestamp($arg0));
		} else if ($arg0 == self::DATE_OPTION_LWK) {
			return "last week, " . date("F jS", $basic_report_form->getStartDateTimestamp($arg0)) . " - " . date("F, jS", $basic_report_form->getEndDateTimestamp($arg0));
		} else if ($arg0 == self::DATE_OPTION_L2WK) {
			return "last 2 week, " . date("F jS", $basic_report_form->getStartDateTimestamp($arg0)) . " - " . date("F, jS", $basic_report_form->getEndDateTimestamp($arg0));
		} else if ($arg0 == self::DATE_OPTION_L3WK) {
			return "last 3 week, " . date("F jS", $basic_report_form->getStartDateTimestamp($arg0)) . " - " . date("F, jS", $basic_report_form->getEndDateTimestamp($arg0));
		} else if ($arg0 == self::DATE_OPTION_L4WK) {
			return "last 4 week, " . date("F jS", $basic_report_form->getStartDateTimestamp($arg0)) . " - " . date("F, jS", $basic_report_form->getEndDateTimestamp($arg0));
		} else if ($arg0 == self::DATE_OPTION_MTD) {
			return "month to date, " . date('F jS', $basic_report_form->getStartDateTimestamp($arg0)) . ' - ' . date('F jS', $basic_report_form->getEndDateTimestamp($arg0));
		} else if ($arg0 == self::DATE_OPTION_M2TD) {
			return date("F", $basic_report_form->getStartDateTimestamp($arg0)) . " - " . date("F", $basic_report_form->getEndDateTimestamp($arg0)) . " (MTD)";
		} else if ($arg0 == self::DATE_OPTION_M3TD) {
			return date("F", $basic_report_form->getStartDateTimestamp($arg0)) . " - " . date("F", $basic_report_form->getEndDateTimestamp($arg0)) . " (MTD)";
		} else if ($arg0 == self::DATE_OPTION_LMO) {
			return "last month, " . date('F jS', $basic_report_form->getStartDateTimestamp($arg0)) . ' - ' . date('F jS', $basic_report_form->getEndDateTimestamp($arg0));
		} else if ($arg0 == self::DATE_OPTION_L3MO) {
			return "last 3 month, " . date("F jS Y", $basic_report_form->getStartDateTimestamp($arg0)) . " - " . date("M, jS Y", $basic_report_form->getEndDateTimestamp($arg0));
		} else if ($arg0 == self::DATE_OPTION_L6MO) {
			return "last 6 month, " . date("F jS Y", $basic_report_form->getStartDateTimestamp($arg0)) . " - " . date("M, jS Y", $basic_report_form->getEndDateTimestamp($arg0));
		} else if ($arg0 == self::DATE_OPTION_L12MO) {
			return "last 12 month, " . date("F jS Y", $basic_report_form->getStartDateTimestamp($arg0)) . " - " . date("M, jS Y", $basic_report_form->getEndDateTimestamp($arg0));
		} else if ($arg0 == self::DATE_OPTION_L7D) {
			return "last 7 days, " . date("F jS", $basic_report_form->getStartDateTimestamp($arg0)) . " - " . date("F jS", $basic_report_form->getEndDateTimestamp($arg0));
		} else if ($arg0 == self::DATE_OPTION_L4D) {
			return "last 4 days, " . date("F jS", $basic_report_form->getStartDateTimestamp($arg0)) . " - " . date("F jS", $basic_report_form->getEndDateTimestamp($arg0));
		} else if ($arg0 == self::DATE_OPTION_YTD) {
			return 'year to date, ' . date('F jS', $basic_report_form->getStartDateTimestamp($arg0)) . ' - ' . date('F jS', $basic_report_form->getEndDateTimestamp($arg0));
		}
		return "custom date: " . date("m/d/Y", $basic_report_form->getStartDateTimestamp($arg0)) . " - " . date("m/d/Y", $basic_report_form->getEndDateTimestamp($arg0));
	}

	/**
	 * Translates the filter operation to a display symbol
	 * @param int
	 * @return string
	 */
	public function translateFilterOperationToDisplay($filter_operation) {
		if($filter_operation == self::FILTER_OPERATION_EQUAL_TO) {
			return 'equal to';
		} else if ($filter_operation == self::FILTER_OPERATION_NOT_EQUAL_TO) {
			return 'not equal to';
		} else if ($filter_operation == self::FILTER_OPERATION_LESS_THAN) {
			return 'less than';
		} else if ($filter_operation == self::FILTER_OPERATION_LESS_THAN_OR_EQUAL_TO) {
			return 'less than or equal to';
		} else if ($filter_operation == self::FILTER_OPERATION_GREATER_THAN) {
			return 'greater than';
		} else if ($filter_operation == self::FILTER_OPERATION_GREATER_THAN_OR_EQUAL_TO) {
			return 'greater than or equal to';
		}

		return '';
	}

	/**
	 * Perform a filter check based on the $filter_key and $test_value
	 * @param mixed
	 * @param int
	 * @return boolean
	 */
	public function performFilterCheck($test_value, $parent_value, $filter_key) {
		$filter_operation = $this->getFilterOperationByKey($filter_key);
		$filter_value = $this->getFilterValueByKey($filter_key);
		$is_percentage = strpos($filter_value, '%');
		if($is_percentage !== false) {
			if($parent_value == 0) {return false;}
			$filter_value = (floatval($filter_value) / 100) * $parent_value;
		}

		if($filter_operation == self::FILTER_OPERATION_EQUAL_TO) {
			return $test_value == $filter_value;
		} else if ($filter_operation == self::FILTER_OPERATION_NOT_EQUAL_TO) {
			return $test_value != $filter_value;
		} else if ($filter_operation == self::FILTER_OPERATION_LESS_THAN) {
			return $test_value < $filter_value;
		} else if ($filter_operation == self::FILTER_OPERATION_LESS_THAN_OR_EQUAL_TO) {
			return $test_value <= $filter_value;
		} else if ($filter_operation == self::FILTER_OPERATION_GREATER_THAN) {
			return $test_value > $filter_value;
		} else if ($filter_operation == self::FILTER_OPERATION_GREATER_THAN_OR_EQUAL_TO) {
			return $test_value >= $filter_value;
		}

		return true;
	}

	/**
	 * Returns the start_time
	 * @return string
	 */
	function getStartTimeFormatted() {
		return date("m/d/Y g:i a",$this->getStartTime());
	}
	
	/**
	 * Returns the start_time
	 * @return string
	 */
	function getStartTime() {
		if (is_null($this->start_time)) {
			$this->start_time = 0;
		}
		return $this->start_time;
	}
	
	/**
	 * Sets the end_time
	 * @param string
	 */
	function setStartTime($arg0) {
		$this->start_time = $arg0;
	}
	
	/**
	 * Returns the end_time
	 * @return string
	 */
	function getEndTimeFormatted() {
		return date("m/d/Y g:i a",$this->getEndTime());
	}
	
	/**
	 * Returns the end_time
	 * @return string
	 */
	function getEndTime() {
		if (is_null($this->end_time)) {
			$this->end_time = 0;
		}
		return $this->end_time;
	}
	
	/**
	 * Sets the end_time
	 * @param string
	 */
	function setEndTime($arg0) {
		$this->end_time = $arg0;
	}
	
	/**
	 * Returns the start date.
	 *
	 * @return string
	 */
	public function getStartDate() {
		if ($this->getCalculateDates()) {
			return date("m/d/Y g:i a", self::getStartTime($this->getDateOption()));
		}
		return date("m/d/Y g:i a");
	}

	/**
	 * Returns the start date time
	 * @return string
	 */
	public function getStartDateTime() {
		return date('m/d/Y g:i a', self::getStartTime($this->getDateOption()));
	}

	/**
	 * Returns the end date.
	 *
	 * @return string
	 */
	public function getEndDate() {
		if ($this->getCalculateDates()) {
			return date("m/d/Y g:i a", self::getEndTime($this->getDateOption()));
		}
		return date("m/d/Y g:i a");
	}

	/**
	 * Returns the end date time
	 * @return string
	 */
	public function getEndDateTime() {
		return date('m/d/Y g:i a', self::getEndTime($this->getDateOption()));
	}

	/**
	 * Returns the previous base start date
	 * @return string
	 */
	function getPreviousBaseStartDate() {
		if ($this->getBaseDateOption() == self::DATE_OPTION_TOD) {
			$ret_val = strtotime($this->getStartDate() . " - 1 day");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_YES) {
			$ret_val = strtotime($this->getStartDate() . " - 1 day");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_L7D) {
			$ret_val = strtotime($this->getStartDate() . " - 7 days");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_L4D) {
			$ret_val = strtotime($this->getStartDate() . " - 4 days");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_LWK) {
			$ret_val = strtotime($this->getStartDate() . " - 1 week");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_L2WK) {
			$ret_val = strtotime($this->getStartDate() . " - 2 week");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_L3WK) {
			$ret_val = strtotime($this->getStartDate() . " - 3 week");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_L4WK) {
			$ret_val = strtotime($this->getStartDate() . " - 4 week");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_MTD) {
			$ret_val = strtotime($this->getStartDate() . " - 1 month");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_M2TD) {
			$ret_val = strtotime($this->getStartDate() . " - 2 month");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_M3TD) {
			$ret_val = strtotime($this->getStartDate() . " - 3 month");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_LMO) {
			$ret_val = strtotime($this->getStartDate() . " - 1 month");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_L3MO) {
			$ret_val = strtotime($this->getStartDate() . " - 3 months");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_L6MO) {
			$ret_val = strtotime($this->getStartDate() . " - 6 months");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_L12MO) {
			$ret_val = strtotime($this->getStartDate() . " - 1 year");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_YTD) {
			$ret_val = strtotime($this->getStartDate() . ' - 1 year');
		} else if ($this->getDateDiff() == 0) {
			$ret_val = strtotime($this->getStartDate() . " - 1 day");
		} else {
			$ret_val = strtotime($this->getStartDate()) - $this->getDateDiff();
		}
		
		return date("m/d/Y g:i a", $ret_val);
	}

	/**
	 * Returns the next base start date
	 * @return string
	 */
	function getNextBaseStartDate() {
		if ($this->getBaseDateOption() == self::DATE_OPTION_TOD) {
			$ret_val = strtotime($this->getStartDate() . " + 1 day");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_YES) {
			$ret_val = strtotime($this->getStartDate() . " + 1 day");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_L7D) {
			$ret_val = strtotime($this->getStartDate() . " + 7 days");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_L4D) {
			$ret_val = strtotime($this->getStartDate() . " + 4 days");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_LWK) {
			$ret_val = strtotime($this->getStartDate() . " + 1 week");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_L2WK) {
			$ret_val = strtotime($this->getStartDate() . " + 2 week");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_L3WK) {
			$ret_val = strtotime($this->getStartDate() . " + 3 week");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_L4WK) {
			$ret_val = strtotime($this->getStartDate() . " + 4 week");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_MTD) {
			$ret_val = strtotime($this->getStartDate() . " + 1 month");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_M2TD) {
			$ret_val = strtotime($this->getStartDate() . " + 2 month");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_M3TD) {
			$ret_val = strtotime($this->getStartDate() . " + 3 month");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_LMO) {
			$ret_val = strtotime($this->getStartDate() . " + 1 month");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_L3MO) {
			$ret_val = strtotime($this->getStartDate() . " + 3 months");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_L6MO) {
			$ret_val = strtotime($this->getStartDate() . " + 6 months");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_L12MO) {
			$ret_val = strtotime($this->getStartDate() . " + 1 year");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_YTD) {
			$ret_val = strtotime($this->getStartDate() . ' + 1 year');
		} else if ($this->getDateDiff() == 0) {
			$ret_val = strtotime($this->getStartDate() . " + 1 day");
		} else {
			$ret_val = strtotime($this->getStartDate()) + $this->getDateDiff();
		}

		return date("m/d/Y g:i a", $ret_val);
	}

	/**
	 * Returns the previous base end date
	 * @return string
	 */
	function getPreviousBaseEndDate() {
		if ($this->getBaseDateOption() == self::DATE_OPTION_TOD) {
			$ret_val = strtotime($this->getEndDate() . " - 1 day");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_YES) {
			$ret_val = strtotime($this->getEndDate() . " - 1 day");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_L7D) {
			$ret_val = strtotime($this->getEndDate() . " - 7 days");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_L4D) {
			$ret_val = strtotime($this->getEndDate() . " - 4 days");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_LWK) {
			$ret_val = strtotime($this->getEndDate() . " - 1 week");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_L2WK) {
			$ret_val = strtotime($this->getEndDate() . " - 2 week");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_L3WK) {
			$ret_val = strtotime($this->getEndDate() . " - 3 week");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_L4WK) {
			$ret_val = strtotime($this->getEndDate() . " - 4 week");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_MTD) {
			$ret_val = strtotime(date("m/t/Y", strtotime($this->getPreviousBaseStartDate())));
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_M2TD) {
			$ret_val = strtotime(date("m/t/Y", strtotime($this->getPreviousBaseStartDate())) . " - 1 months");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_M3TD) {
			$ret_val = strtotime(date("m/t/Y", strtotime($this->getPreviousBaseStartDate())) . " - 2 months");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_LMO) {
			$ret_val = strtotime(date("m/t/Y", strtotime($this->getPreviousBaseStartDate())));
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_L3MO) {
			$ret_val = strtotime($this->getEndDate() . " - 3 months");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_L6MO) {
			$ret_val = strtotime($this->getEndDate() . " - 6 months");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_L12MO) {
			$ret_val = strtotime($this->getEndDate() . " - 1 year");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_YTD) {
			$ret_val = strtotime($this->getEndDate() . ' - 1 year');
		} else if ($this->getDateDiff() == 0) {
			$ret_val = strtotime($this->getEndDate() . " - 1 day");
		} else {
			$ret_val = strtotime($this->getEndDate()) - $this->getDateDiff();
		}

		return date("m/d/Y g:i a", $ret_val);
	}

	/**
	 * Returns the next base end date
	 * @return string
	 */
	function getNextBaseEndDate() {
		if ($this->getBaseDateOption() == self::DATE_OPTION_TOD) {
			$ret_val = strtotime($this->getEndDate() . " + 1 day");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_YES) {
			$ret_val = strtotime($this->getEndDate() . " + 1 day");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_L7D) {
			$ret_val = strtotime($this->getEndDate() . " + 7 days");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_L4D) {
			$ret_val = strtotime($this->getEndDate() . " + 4 days");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_LWK) {
			$ret_val = strtotime($this->getEndDate() . " + 1 week");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_L2WK) {
			$ret_val = strtotime($this->getEndDate() . " + 2 week");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_L3WK) {
			$ret_val = strtotime($this->getEndDate() . " + 3 week");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_L4WK) {
			$ret_val = strtotime($this->getEndDate() . " + 4 week");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_MTD) {
			$ret_val = strtotime(date("m/t/Y", strtotime($this->getNextBaseStartDate())));
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_M2TD) {
			$ret_val = strtotime(date("m/t/Y", strtotime($this->getNextBaseStartDate())) . " + 1 month");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_M3TD) {
			$ret_val = strtotime(date("m/t/Y", strtotime($this->getNextBaseStartDate())) . " + 2 month");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_LMO) {
			$ret_val = strtotime(date("m/t/Y", strtotime($this->getNextBaseStartDate())));
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_L3MO) {
			$ret_val = strtotime($this->getEndDate() . " + 3 months");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_L6MO) {
			$ret_val = strtotime($this->getEndDate() . " + 6 months");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_L12MO) {
			$ret_val = strtotime($this->getEndDate() . " + 1 year");
		} else if ($this->getBaseDateOption() == self::DATE_OPTION_YTD) {
			$ret_val = strtotime($this->getEndDate() . ' + 1 year');
		} else if ($this->getDateDiff() == 0) {
			$ret_val = strtotime($this->getEndDate() . " + 1 day");
		} else {
			$ret_val = strtotime($this->getEndDate()) + $this->getDateDiff();
		}

		return date("m/d/Y g:i a", $ret_val);
	}

	static function getWeekStart($datetime) {
		//1-7, monday-sunday
		$dayInWeek=date("N", $datetime);

		$weekStartEndPosition=array(
			1=>array("start"=>0,"end"=>6),
			2=>array("start"=>-1,"end"=>5),
			3=>array("start"=>-2,"end"=>4),
			4=>array("start"=>-3,"end"=>3),
			5=>array("start"=>-4,"end"=>2),
			6=>array("start"=>-5,"end"=>1),
			7=>array("start"=>-6,"end"=>0)
		);
		return mktime(0,0,0,date("n",$datetime),date("j",$datetime)+$weekStartEndPosition[$dayInWeek]["start"],date("Y",$datetime));
	}

	static function getWeekEnd($datetime) {
		//1-7, monday-sunday
		$dayInWeek=date("N", $datetime);

		$weekStartEndPosition=array(
			1=>array("start"=>0,"end"=>6),
			2=>array("start"=>-1,"end"=>5),
			3=>array("start"=>-2,"end"=>4),
			4=>array("start"=>-3,"end"=>3),
			5=>array("start"=>-4,"end"=>2),
			6=>array("start"=>-5,"end"=>1),
			7=>array("start"=>-6,"end"=>0)
		);
		return mktime(23,59,59,date("n",$datetime),date("j",$datetime)+$weekStartEndPosition[$dayInWeek]["end"],date("Y",$datetime));
	}

	static function getMonthStart($datetime) {
		return mktime(0,0,0,date("n",$datetime),1,date("Y",$datetime));
	}

	static function getMonthEnd($datetime) {
		return mktime(23,59,59,date("n",$datetime),date("t", $datetime),date("Y",$datetime));
	}

	public function getTimePeriodCounter() {
		if(is_null($this->date_intervals)) {
			$dates = array();
			$start_time = strtotime($this->getStartDate());
			$end_time = strtotime($this->getEndDate());
			if($this->getDetailInterval()==self::DETAIL_INTERVAL_DAY) {
				for($current_time = $start_time;$current_time<=$end_time;$current_time = strtotime('+1 day',$current_time)) {
					$date_interval = array();
					$date_interval["start"] = mktime(0,0,0,date("n",$current_time),date("j",$current_time),date("Y",$current_time));
					$date_interval["end"] = mktime(23,59,59,date("n",$current_time),date("j",$current_time),date("Y",$current_time));
					$dates[] = $date_interval;
				}
			} else if($this->getDetailInterval()==self::DETAIL_INTERVAL_WEEK) {
				$start_time = self::getWeekStart(strtotime($this->getStartDate()));
				$end_time = self::getWeekEnd(strtotime($this->getEndDate()));
				for($current_time = $start_time;$current_time<=$end_time;$current_time = strtotime('+7 day',$current_time)) {
					$date_interval = array();
					$start_of_week = self::getWeekStart($current_time);
					if($start_of_week < strtotime($this->getStartDate())) {
						$start_of_week = strtotime($this->getStartDate());
					}
					$end_of_week = self::getWeekEnd($current_time);
					if($end_of_week > strtotime($this->getEndDate())) {
						$end_of_week = strtotime($this->getEndDate());
					}
					$date_interval["start"] = mktime(0,0,0,date("n",$start_of_week),date("j",$start_of_week),date("Y",$start_of_week));
					$date_interval["end"] = mktime(23,59,59,date("n",$end_of_week),date("j",$end_of_week),date("Y",$end_of_week));
					$dates[] = $date_interval;
				}
			} else if($this->getDetailInterval()==self::DETAIL_INTERVAL_MONTH) {
				$start_time = self::getMonthStart(strtotime($this->getStartDate()));
				$end_time = self::getMonthEnd(strtotime($this->getEndDate()));
				for($current_time = $start_time;$current_time<=$end_time;$current_time = strtotime("+1 month",strtotime(date("F",$current_time) . "1"))) {
					$date_interval = array();
					$start_of_month = self::getMonthStart($current_time);
					if($start_of_month < strtotime($this->getStartDate())) {
						$start_of_month = strtotime($this->getStartDate());
					}
					$end_of_month = self::getMonthEnd($current_time);
					if($end_of_month > strtotime($this->getEndDate())) {
						$end_of_month = strtotime($this->getEndDate());
					}
					$date_interval["start"] = mktime(0,0,0,date("n",$start_of_month),date("j",$start_of_month),date("Y",$start_of_month));
					$date_interval["end"] = mktime(23,59,59,date("n",$end_of_month),date("j",$end_of_month),date("Y",$end_of_month));
					$dates[] = $date_interval;
				}
			}
			$this->date_intervals = $dates;
		}
		return $this->date_intervals;
	}

	public function getTimePeriodTitle($date_interval) {
		$title = '';
		$start_time = $date_interval["start"];
		$end_time = $date_interval["end"];
		if($this->getDetailInterval()==self::DETAIL_INTERVAL_DAY) {
			$title .= date("m/d/Y",$start_time);
		} else if($this->getDetailInterval()==self::DETAIL_INTERVAL_WEEK) {
			$title .= "Week of " . date("m/d/Y",$start_time);
			if($start_time>self::getWeekStart($start_time)) {
				$title .= " (Partial)";
			}
			if($end_time<self::getWeekEnd($end_time)) {
				$title .= " (until the " . date("jS",$end_time) . ")";
			}
		} else if($this->getDetailInterval()==self::DETAIL_INTERVAL_MONTH) {
			$title .= date("M",$start_time);
			if($start_time>self::getMonthStart($start_time)) {
				$title .= " (from the " . date("jS",$start_time) . ")";
			}
			if($end_time<self::getMonthEnd($end_time)) {
				$title .= " (until the " . date("jS",$end_time) . ")";
			}
		}
		return $title;
	}

	public function getTimePeriodIntervalId($start_time) {
		$interval_id = 0;
		if($this->getDetailInterval()==self::DETAIL_INTERVAL_DAY) {
			$interval_id = UtilityFunctions::sqlToDaysConversion(date("m/d/Y",$start_time));
		} else if($this->getDetailInterval()==self::DETAIL_INTERVAL_WEEK) {
			$interval_id = UtilityFunctions::sqlWeekConversion(date("m/d/Y",$start_time));
		} else if($this->getDetailInterval()==self::DETAIL_INTERVAL_MONTH) {
			$interval_id = date("n",$start_time);
		}
		return $interval_id;
	}

	public function getHtmlReportResults($title) {
		$report_string = '';

		$report_string .= '<div class="result_report_data">';
		if(count($this->getReportData()->getChildArray()) > 0) {
			$report_string .= $this->getReportHtml($title);
		} else {
			$report_string .= $this->getEmptyReportHtml($title);
		}
		$report_string .= '</div>';

		return $report_string;
	}

	/**
	 * Returns the html body of the report
	 * @return $report_string
	 */
	public function getReportHtml($title) {
		$colspan = count($this->getDisplay());
		if($this->getDetailsInColumns()) {
			$colspan += count($this->getDetails()) - 1;
		}

		/* Add extra column for rollup sub tables */
		if ($this->getRollupSubTables()) {
			$colspan++;
		}

		/* Add extra columns for column details */
		if(count($this->getDetailColumns()) > 0) {
			$displayColumns = count($this->getDisplay());
			if($this->isDisplayIdSelected(self::DISPLAY_LEVEL_TITLE)) {
				--$displayColumns;
			}
			if($this->isDisplayIdSelected(self::DISPLAY_LEVEL_OPTIONS)) {
				--$displayColumns;
			}

			/* This should have else statements added to do additional types of detail column id selected */
			if($this->isDetailColumnIdSelected(self::DETAIL_LEVEL_INTERVAL)) {
				$baseCount = count($this->getTimePeriodCounter()) - 1;
				$colspan += $baseCount * $displayColumns;
			}
		}

		$report_string = '';

		$report_string .= '<div class="report_overflow" '.(($this->isDisplayTypeTable()) ? 'style="border: 0;"' : '').'>';

		$report_string .= '<table class="result_report_table">';
		$report_string .= '<thead '.(($this->isDisplayTypeTable()) ? 'class="group"' : '').'><tr>';
		$report_string .= '<th class="result_main_column_level_0" colspan="'.$colspan.'">';
		$report_string .= '<div>'.$title.' for '.date('m/d/Y', strtotime($this->getStartDate())).' to '.date('m/d/Y', strtotime($this->getEndDate())).'</div>';
		$report_string .= '<div class="small">'.$this->getRanOn();
		$report_string .= '</div>';
		$report_string .= '</th>';
		$report_string .= '</tr></thead>';

		/* If we are display totals at the bottom, show the grand total in the table foot */
		if($this->getDisplayTotalPosition()) {
			$report_string .= '<tfoot '.(($this->isDisplayTypeTable()) ? 'class="group"' : '').'>';
			$report_string .= $this->getRowHeaderHtml('sub2');
			$report_string .= $this->getRowHtml($this->getReportData(), 'sub');
			$report_string .= '</tfoot>';
		}

		$report_string .= $this->getOuterTBody();
		$num_details = count($this->getDetails());
		$num_in_report = count($this->getReportData()->getChildArray());

		/* If we're not display totals at the bottom, show the grand total now */
		if(!$this->getDisplayTotalPosition()) {
			$report_string .= $this->getInnerTBody();
			$report_string .= $this->getRowHeaderHtml('sub2');
			$report_string .= $this->getRowHtml($this->getReportData(), 'sub');
			$report_string .= $this->getInnerTBodyClosing();
		}

		foreach($this->getReportData()->getChildArrayBySort() as $key => $data) {
			if(($num_details > 1 && $this->getDisplayType() == self::DISPLAY_TYPE_TABLE) || $key == 0) {
				$report_string .= $this->getInnerTBody();
				$report_string .= $this->getRowHeaderHtml('sub2');
			}

			$class = false;
			if($num_details<=1) {
				$class = self::getNextCssRowClass($key == 0);
			}
			$report_string .= $this->getReportRowHtml($data, $class, $class, $this->getDisplayTotalPosition());

			if($num_details > 1 || $num_in_report == $key + 1) {
				$report_string .= $this->getInnerTBodyClosing();
			}
		}
		$report_string .= $this->getOuterTBodyClosing();

		$report_string .= '</table>';

		$report_string .= '</div>';

		if($this->getRollupSubTables()) {
			$report_string .= '
				<script type="text/javascript">
					function rollupSubTable(e,type) {
						var img = $(e.target);
						var toggleType = type || "toggle";
						if(toggleType == "toggle") {
							toggleType = (img.attr("src") == "/202-img/btnExpand.gif") ? "show" : "hide";
						}
						var rel_attr = img.closest("a").attr("rel");
						if(toggleType=="show") {
							$(".rollup_sub_row_"+rel_attr).show();
							img.attr("src","/202-img/btnCollapse.gif");
						} else {
							$(".rollup_sub_row_"+rel_attr+"_close:visible").hide().find("a.rollup_sub_anchor img").attr("src", "/202-img/btnExpand.gif");
							img.attr("src","/202-img/btnExpand.gif");
						}
					}

					$(function() {
						$(".rollup_sub_anchor").bind("click.rollupSubTable", rollupSubTable);
					});
				</script>
			';
		}

		return $report_string;
	}

	/**
	 * Returns the html row of the report row form
	 * @return $report_string
	 */
	private function getReportRowHtml($data, $forced_class = false, $build_class = false, $parent_last = false) {
		$report_row = '';

		$class = ($forced_class) ? $forced_class : 'sub';

		/* if display totals at the top */
		if(!$parent_last) {
			$report_row .= $this->getRowHtml($data, $class . " " . $build_class);
		}
		//Check for more child forms and get their rows as well
		if(is_callable(array($data, 'getChildArrayBySort'), false)) {

			foreach($data->getChildArrayBySort() as $child_key => $child_data) {
				$child_class = ($forced_class) ? $forced_class : self::getNextCssRowClass($child_key == 0);
				if ($this->getRollupSubTables()) {
					$build_class = 'rollup_sub_row_' . $data->getDetailId() . '_' . $data->getId();
					$child_class .=  ' ' . $build_class . '_close';
				}
				$report_row .= $this->getReportRowHtml($child_data, $child_class, $build_class);
			}
		}

		/* if display totals at the bottom */
		if($parent_last) {
			$report_row .= $this->getRowHtml($data, $class);
		}

		return $report_row;
	}

	/**
	 * Returns outer tbody based on display_type
	 * @return string
	 */
	public function getOuterTBody() {
		return ($this->isDisplayTypeTable()) ?
			'<tbody class="group_spacing"><tr><td>&nbsp;</td></tr></tbody>' :
			'<tbody>';
	}

	/**
	 * Returns outer tbody closing based on display_type
	 * @return string
	 */
	public function getOuterTBodyClosing() {
		return ($this->isDisplayTypeTable()) ? '' : '</tbody>';
	}

	/**
	 * Returns inner tbody based on display_type
	 * @return string
	 */
	public function getInnerTBody() {
		return ($this->isDisplayTypeTable()) ? '<tbody class="group">' : '';
	}

	/**
	 * Returns inner tbody closing based on display_type
	 * @return string
	 */
	public function getInnerTBodyClosing() {
		return ($this->isDisplayTypeTable()) ?
			'</tbody>
				<tbody class="group_spacing"><tr>
					<td>&nbsp;</td>
				</tr></tbody>' :
			'';
	}

	public function getEmptyReportHtml($title) {
		$report_string = '';

		$report_string .= '<div class="report_overflow" '.(($this->isDisplayTypeTable()) ? 'style="border: 0;"' : '').'>';
		$report_string .= '<table class="result_report_table">';
		$report_string .= '<thead '.(($this->isDisplayTypeTable()) ? 'class="group"' : '').'><tr>';
		$report_string .= '<th class="result_main_column_level_0" colspan="1">';
		$report_string .= '<div>'.$title.' for '.date('m/d/Y', strtotime($this->getStartDate())).' to '.date('m/d/Y', strtotime($this->getEndDate())).'</div>';
		$report_string .= '<div class="small">'.$this->getRanOn().'</div>';
		$report_string .= '</th>';
		$report_string .= '</tr></thead>';
		$report_string .= $this->getOuterTBody();

		$report_string .= '<tbody>';
		$report_string .= '<tr><td colspan="3" style="text-align: center;"><div class="report_empty_msg">No results were found using the report parameters.</td></tr>';
		$report_string .= '</tbody>';

		$report_string .= $this->getOuterTBody();
		$report_string .= '</table></div>';
		return $report_string;
	}

	/**
	 * Returns the html body of the report
	 * @return $report_string
	 */
	public function getPrintReportHtml($title) {
		$colspan = count($this->getDisplay());
		if($this->getDetailsInColumns()) {
			$colspan += count($this->getDetails()) - 1;
		}

		/* Add extra column for rollup sub tables */
		if ($this->getRollupSubTables()) {
			$colspan++;
		}

		/* Add extra columns for column details */
		if(count($this->getDetailColumns()) > 0) {
			$displayColumns = count($this->getDisplay());
			if($this->isDisplayIdSelected(self::DISPLAY_LEVEL_TITLE)) {
				--$displayColumns;
			}
			if($this->isDisplayIdSelected(self::DISPLAY_LEVEL_OPTIONS)) {
				--$displayColumns;
			}

			/* This should have else statements added to do additional types of detail column id selected */
			if($this->isDetailColumnIdSelected(self::DETAIL_LEVEL_INTERVAL)) {
				$baseCount = count($this->getTimePeriodCounter()) - 1;
				$colspan += $baseCount * $displayColumns;
			}
		}

		$num_details = count($this->getDetails());
		

		$report_string = '';

		$report_string .= '<table class="result_report_table">';

		foreach($this->getReportData()->getChildArrayBySort() as $key => $data) {
			if($num_details > 1 || $key == 0) {
				$report_string .= '<tbody class="group"><tr class="title">
					<td class="result_report_title" colspan="'.$colspan.'">';

				if($num_details > 1) {
					$report_string .= '<div>'.$data->getPrintTitle();
					$report_string .= '</div>';
				}

				$report_string .= '<div class="'.((count($this->getDetails()) > 1) ? 'small' : '').'">'.$title.' for '.date('m/d/Y', strtotime($this->getStartDate())).' to '.date('m/d/Y', strtotime($this->getEndDate())).'</div>
						<div class="small">'.$this->getRanOn().'</div>
					</td>
				</tr>';
			}

			if($num_details > 1 || $key == 0) {
				$report_string .= $this->getPrintRowHeaderHtml('sub2');
			}

			$class = false;
			if($num_details<=1) {
				$class = self::getNextCssRowClass($key == 0);
			}

			$report_string .= $this->getPrintReportRowHtml($data, $class, $this->getDisplayTotalPosition());

			if($num_details > 1) {
				$report_string .= '</tbody><tbody class="group_spacing"><tr><td colspan="'.$colspan.'" style="height: 50px;">&nbsp;</td></tr></tbody>';
			}
		}

		if($num_details > 1) {
			$report_string .= '<tbody class="group"><tr class="title">
				<td class="result_report_title" colspan="'.$colspan.'">
					<div>'.$title.' for '.date('m/d/Y', strtotime($this->getStartDate())).' to '.date('m/d/Y', strtotime($this->getEndDate())).'</div>
					<div class="small">'.$this->getRanOn().'</div>
				</td>
			</tr>';
			$report_string .= $this->getPrintRowHeaderHtml('sub2');
			$report_string .= $this->getPrintRowHtml($this->getReportData(), 'sub');
			$report_string .= '</tbody>';
		} else {
			$report_string .= '<tfoot>';
			$report_string .= $this->getPrintRowHtml($this->getReportData(), 'sub');
			$report_string .= '</tfoot>';
		}

		$report_string .= '</table>';

		return $report_string;
	}

	/**
	 * Returns the html row of the report row form
	 * @return $report_string
	 */
	public function getPrintReportRowHtml($data, $forced_class = false, $parent_last = false) {
		$report_row = '';

		$class = ($forced_class) ? $forced_class : 'sub';
		if(!$parent_last) {
			$report_row .= $this->getPrintRowHtml($data, $class);
		}

		//Check for more child forms and get their rows as well
		if(is_callable(array($data, 'getChildArrayBySort'), false)) {
			foreach($data->getChildArrayBySort() as $child_key=> $child_data) {
				$child_class = ($forced_class) ? $forced_class : self::getNextCssRowClass($child_key == 0);
				$report_row .= $this->getPrintReportRowHtml($child_data, $child_class);
			}
		}
		if($parent_last) {
			$report_row .= $this->getPrintRowHtml($data, $class);
		}

		return $report_row;
	}

	static function echoCell($str) {
		echo "\"" . $str . "\"" . ",";
	}

	static function echoRow() {
		echo "\n";
	}
	
	static function getNextCssRowClass($reset=false) {
		static $count = 0;
		if ($reset === true) {
			$count = 0;
		}
		return $count++ % 2 ? "dark" : "lite";
	}
}

?>