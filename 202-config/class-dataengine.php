<?php
ini_set('memory_limit', '-1');
if (! $_SESSION['user_timezone']) {
    date_default_timezone_set('GMT');
} else {
    date_default_timezone_set($_SESSION['user_timezone']);
}

class DataEngine
{

    private $total_clicks = '';

    private $mysql = Array();

    private static $db;

    private static $found_rows;

    private $forDownload = 0;

    function __construct()
    {
        try {
            $database = DB::getInstance();
            self::$db = $database->getConnection();
        } catch (Exception $e) {
            self::$db = false;
        }
        
        $this->mysql['user_id'] = self::$db->real_escape_string($_SESSION['user_own_id']);
        
        if($_SESSION['publisher']==false){ //user is able to see all camapigns
            $this->mysql['user_id_query'] = " WHERE 2st.user_id != '0' ";
        }
        else{
            $this->mysql['user_id_query'] = " WHERE 2st.user_id ='" . $_SESSION['user_own_id'] . "' "; //user can only see thier campaigns
        }
        
        
        // make sure mysql uses the timezone choses by the user
        
        $timezone = new DateTimeZone(date_default_timezone_get()); // Get default system timezone to create a new DateTimeZone object
        $offset = $timezone->getOffset(new DateTime()); // Offset in seconds to UTC
        $offsetHours = round(($offset) / 3600);
        if ($offsetHours >= 0)
            $offsetHours = '+' . $offsetHours;
        $tzSql = "SET time_zone = '" . $offsetHours . ":00'";
        $click_result = self::$db->query($tzSql);
    }

    function setDownload()
    {
        $this->forDownload = 1;
    }

    function setDisplay()
    {
        $this->forDownload = 0;
    }

    function foundRows()
    {
        return self::$found_rows;
    }

    function getReportData($reportType, $clickFrom, $clickTo, $cpv)
    {
        switch ($reportType) {
            case 'LpOverview':
                return $this->doLpOverviewReport($clickFrom, $clickTo, $cpv);
                break;
            case 'campaignOverview':
                return $this->doCampaignOverviewReport($clickFrom, $clickTo, $cpv);
                break;
            case 'slp_direct_link_per_ppc':
                return $this->doPerPpcReport('slp_direct_link', $clickFrom, $clickTo, $cpv);
                break;
            case 'alp_per_ppc':
                return $this->doPerPpcReport('alp', $clickFrom, $clickTo, $cpv);
                break;
            case 'breakdown':
                return $this->doBreakdownReport($clickFrom, $clickTo, $cpv);
                break;
            case 'hourly':
                return $this->doHourlyReport($clickFrom, $clickTo, $cpv);
                break;
            case 'weekly':
                return $this->doWeeklyReport($clickFrom, $clickTo, $cpv);
                break;
            case 'keyword':
                return $this->doKeywordReport($clickFrom, $clickTo, $cpv);
                break;
            case 'textad':
                return $this->doTextadReport($clickFrom, $clickTo, $cpv);
                break;
            case 'referer':
                return $this->doRefererReport($clickFrom, $clickTo, $cpv);
                break;
            case 'ip':
                return $this->doIPReport($clickFrom, $clickTo, $cpv);
                break;
            case 'country':
                return $this->doCountryReport($clickFrom, $clickTo, $cpv);
                break;
            case 'region':
                return $this->doRegionReport($clickFrom, $clickTo, $cpv);
                break;
            case 'city':
                return $this->doCityReport($clickFrom, $clickTo, $cpv);
                break;
            case 'isp':
                return $this->doISPReport($clickFrom, $clickTo, $cpv);
                break;
            case 'landingpage':
                return $this->doLandingPageReport($clickFrom, $clickTo, $cpv);
                break;
            case 'device':
                return $this->doDeviceReport($clickFrom, $clickTo, $cpv);
                break;
            case 'browser':
                return $this->doBrowserReport($clickFrom, $clickTo, $cpv);
                break;
            case 'platform':
                return $this->doPlatformReport($clickFrom, $clickTo, $cpv);
                break;
            case 'variable':
                return $this->doVariableReport($clickFrom, $clickTo, $cpv);
                break;
        }
    }

    function getFilters()
    {
        $mysql['user_id'] = self::$db->real_escape_string($_SESSION['user_id']);
        if (isset($_POST['offset']) && $_POST['offset'] != '') {
            $mysql['offset'] = self::$db->real_escape_string($_POST['offset']);
        } else {
            $mysql['offset'] = 0;
        }
        
        $user_sql = "SELECT * FROM 202_users_pref WHERE user_id=" . $mysql['user_id'];
        $user_result = _mysqli_query($user_sql); // ($user_sql);
        $user_row = $user_result->fetch_assoc();
        $breakdown = $user_row['user_pref_breakdown'];
        
        if ($user_row['user_pref_show'] == 'all') {
            $click_filtered = '';
        }
        if ($user_row['user_pref_subid'] != '0' && ! empty($user_row['user_pref_subid'])) {
            $click_filtered .= " AND 2st.click_id=" . $user_row['user_pref_subid'];
        }
        if ($user_row['user_pref_show'] == 'real') {
            $click_filtered = " AND click_filtered='0' ";
        }
        if ($user_row['user_pref_show'] == 'filtered') {
            $click_filtered = " AND click_filtered='1' ";
        }
        if ($user_row['user_pref_show'] == 'filtered_bot') {
            $click_filtered = " AND click_bot='1' ";
        }
        if ($user_row['user_pref_show'] == 'leads') {
            $click_filtered = " AND click_lead!='0' ";
        }
        if ($user_row['user_pref_device_id'] != '0' && ! empty($user_row['user_pref_device_id'])) {
            $click_filtered .= " AND 2st.device_id in (select device_id from 202_device_models where device_type=" . $user_row['user_pref_device_id'] . ")";
        }
        if ($user_row['user_pref_browser_id'] != '0' && ! empty($user_row['user_pref_browser_id'])) {
            $click_filtered .= " AND 2st.browser_id=" . $user_row['user_pref_browser_id'];
        }
        if ($user_row['user_pref_platform_id'] != '0' && ! empty($user_row['user_pref_platform_id'])) {
            $click_filtered .= " AND 2st.platform_id=" . $user_row['user_pref_platform_id'];
        }
        if ($user_row['user_pref_country_id'] != '0' && ! empty($user_row['user_pref_country_id'])) {
            $click_filtered .= " AND 2st.country_id=" . $user_row['user_pref_country_id'];
        }
        if ($user_row['user_pref_region_id'] != '0' && ! empty($user_row['user_pref_region_id'])) {
            $click_filtered .= " AND 2st.region_id=" . $user_row['user_pref_region_id'];
        }
        if ($user_row['user_pref_isp_id'] != '0' && ! empty($user_row['user_pref_isp_id'])) {
            $click_filtered .= " AND 2st.isp_id=" . $user_row['user_pref_isp_id'];
        }
        
        // No Traffic Source is set to 16777215 also the biggest number in the database. Because 0 is being used to indicate all Traffic Sources
        if ($user_row['user_pref_ppc_network_id'] == '16777215') {
            $click_filtered .= " AND 2st.ppc_network_id IS NULL";
        } else 
            if ($user_row['user_pref_ppc_network_id'] != '0' && ! empty($user_row['user_pref_ppc_network_id'])) {
                $click_filtered .= " AND 2st.ppc_network_id=" . $user_row['user_pref_ppc_network_id'];
            }
        if ($user_row['user_pref_ppc_account_id'] != '0' && ! empty($user_row['user_pref_ppc_account_id'])) {
            $click_filtered .= " AND 2st.ppc_account_id=" . $user_row['user_pref_ppc_account_id'];
        }
        if ($user_row['user_pref_aff_network_id'] != '0' && ! empty($user_row['user_pref_aff_network_id'])) {
            $click_filtered .= " AND 2st.aff_network_id=" . $user_row['user_pref_aff_network_id'];
        }
        if ($user_row['user_pref_aff_campaign_id'] != '0' && ! empty($user_row['user_pref_aff_campaign_id'])) {
            $click_filtered .= " AND 2st.aff_campaign_id=" . $user_row['user_pref_aff_campaign_id'];
        }
        if ($user_row['user_pref_text_ad_id'] != '0' && ! empty($user_row['user_pref_text_ad_id'])) {
            $click_filtered .= " AND 2st.text_ad_id=" . $user_row['user_pref_text_ad_id'];
        }
        if ($user_row['user_pref_landing_page_id'] != '0' && ! empty($user_row['user_pref_landing_page_id'])) {
            $click_filtered .= " AND 2st.landing_page_id=" . $user_row['user_pref_landing_page_id'];
        }
        
        if ($user_row['user_pref_method_of_promotion'] == 'directlink') {
            $click_filtered .= " AND 2st.landing_page_id = 0";
        } else 
            if ($user_row['user_pref_method_of_promotion'] == 'landingpage') {
                $click_filtered .= " AND 2st.landing_page_id != 0";
            }
        if ($user_row['user_cpc_or_cpv'] == 'cpv')
            $cpv = true;
        else
            $cpv = false;
        
        if ($user_row['user_pref_keyword']) {
            $click_filtered .= " AND 2k.keyword like '%" . $user_row['user_pref_keyword'] . "%'";
            $click_filtered_arr['join'] = ' LEFT OUTER JOIN 202_keywords AS 2k ON (2k.keyword_id=2st.keyword_id) ';
        }
        
        if ($user_row['user_pref_ip']) {
            
            $ip_address = self::ipAddress($user_row['user_pref_ip']);
            
            $ip_id = self::get_ip_id($ip_address);
          
            if ($ip_id != '') {
                $click_filtered .= " AND 2st.ip_id=" . $ip_id;
            } else {
                $click_filtered .= " AND 2st.ip_id=''";
            } // make sure results are blank if the filter is not found
        }
        
        if ($user_row['user_pref_referer']) {
            $referer_id = self::get_site_url_id($user_row['user_pref_referer']);
            if ($referer_id != '') {
                $click_filtered .= " AND 2st.click_referer_site_url_id in (" . $referer_id . ")";
            } else {
                $click_filtered .= " AND 2st.click_referer_site_url_id=''";
            } // make sure results are blank if the filter is not found
        }
        
        $click_filtered_arr['filter'] = $click_filtered;
        if ($user_row['user_pref_limit'] && $this->forDownload == 0) {
            $mysql['offset'] = ($mysql['offset'] * $user_row['user_pref_limit']);
            $click_filtered_arr['limit'] = ' Limit ' . $mysql['offset'] . ',' . $user_row['user_pref_limit'];
        } else {
            $click_filtered_arr['limit'] = '';
        }
        
        return $click_filtered_arr;
    }
    
    /*
     * function getIpxQueryObj(array $filters, $from, $to) {
     * $query = array();
     * $filterInClickLevel = false;
     * $filterInIpxLevel = array(
     * 'user_pref_ppc_network_id',
     * 'user_pref_ppc_account_id',
     * 'user_pref_aff_network_id',
     * 'user_pref_aff_campaign_id',
     * 'user_pref_text_ad_id',
     * 'user_pref_landing_page_id',
     * 'user_pref_method_of_promotion'
     * );
     *
     * foreach ($filters as $key => $value) {
     * if ($filterInClickLevel == true) {
     * break;
     * }
     * if (!in_array($key, $filterInIpxLevel)) {
     * $filterInClickLevel = true;
     * }
     * }
     *
     * if ($filterInClickLevel == false) {
     * $query['select'] = " impressions, (sum(clicks)/impressions)*100 as ictr, ";
     * $query['join'] = " LEFT JOIN (SELECT count(*) as impressions, landing_page_id FROM 202_clicks_impressions WHERE impression_time >= ".$from." AND impression_time <= ".$to." GROUP BY landing_page_id) AS 202_clicks_impressions ON (202_clicks_impressions.landing_page_id = 202_dataengine.landing_page_id)";
     * } else {
     * $query['select'] = " count(202_clicks_impressions.impression_id) as impressions, (sum(clicks)/count(202_clicks_impressions.impression_id))*100 as ictr, ";
     * $query['join'] = " LEFT JOIN 202_clicks_impressions on (202_dataengine.click_id = 202_clicks_impressions.click_id)";
     * }
     * }
     */
    function getAccountOverviewFilters()
    {
        $mysql['user_id'] = self::$db->real_escape_string($_SESSION['user_id']);
        $user_sql = "SELECT user_pref_show FROM 202_users_pref WHERE user_id=" . $mysql['user_id'];
        $user_result = _mysqli_query($user_sql); // ($user_sql);
        $user_row = $user_result->fetch_assoc();
        
        if ($user_row['user_pref_show'] == 'all') {
            $click_filtered = '';
        }
        if ($user_row['user_pref_show'] == 'real') {
            $click_filtered = " AND click_filtered='0' ";
        }
        if ($user_row['user_pref_show'] == 'filtered') {
            $click_filtered = " AND click_filtered='1' ";
        }
        if ($user_row['user_pref_show'] == 'filtered_bot') {
            $click_filtered = " AND click_bot='1' ";
        }
        if ($user_row['user_pref_show'] == 'leads') {
            $click_filtered = " AND click_lead!='0' ";
        }
        
        return $click_filtered;
    }
    
    // this returns the keyword_id
    function get_keyword_id($keyword)
    {
        $mysql['keyword'] = self::$db->real_escape_string($keyword);
        
        $keyword_sql = "SELECT group_concat(keyword_id) as keyword_id FROM 202_keywords WHERE keyword like '%" . $mysql['keyword'] . "%'";
        $keyword_row = memcache_mysql_fetch_assoc($keyword_sql);
        $keyword_id[] = $keyword_row['keyword_id'];
        return $keyword_id[0];
    }

    function get_ip_id($ip)
    {
        global $memcacheWorking, $memcache;

        
        $mysql['ip_address'] = self::$db->real_escape_string($ip->address);
        
        if($inet6_ntoa == '' && $ip->type == 'ipv6'){
            $mysql['ip_address'] = inet6_aton($mysql['ip_address']); //encode for db check
        }
        
        if ($ip->type==='ipv6') {
            $ip_sql = 'SELECT 202_ips.ip_id FROM 202_ips_v6  INNER JOIN 202_ips on (202_ips_v6.ip_id = 202_ips.ip_address COLLATE utf8mb4_general_ci) WHERE 202_ips_v6.ip_address= '.$inet6_aton.'("' . $mysql['ip_address'] . '")';
        }
        else{
            $ip_sql = "SELECT ip_id FROM 202_ips WHERE ip_address='" . $mysql['ip_address'] . "'";
        }
                
        if ($memcacheWorking) {
            $time = 2592000; // 7 days in sec
                            // get from memcached
            $getID = $memcache->get(md5("ip-id" . $mysql['ip_address'] . systemHash()));
            
            if ($getID) {
                $ip_id = $getID;
                return $ip_id;
            } else {
                
                $ip_result = _mysqli_query($ip_sql);
                $ip_row = $ip_result->fetch_assoc();
                if ($ip_row) {
                    // if this ip_id already exists, return the ip_id for it.
                    $ip_id = $ip_row['ip_id'];
                    // add to memcached
                    $setID = setCache(md5("ip-id" . $mysql['ip_address'] . systemHash()), $ip_id, $time);
                    return $ip_id;
                } else {
                    //insert ip
                    INDEXES::insert_ip(self::$db,$ip);
                }
            }
        } else {
            $ip_result = _mysqli_query($ip_sql);
            $ip_row = $ip_result->fetch_assoc();
           
            if ($ip_row) {
                // if this ip already exists, return the ip_id for it.
                $ip_id = $ip_row['ip_id'];
                return $ip_id;
            } else {
                //insert ip
                INDEXES::insert_ip(self::$db,$ip);
            }
        }
    }
    
    
    function ipAddress($ip_address){
        $ip = new stdClass;
    
        if (filter_var($ip_address, FILTER_VALIDATE_IP)) {
            $ip->address=$ip_address;
            if (filter_var($ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ip->type='ipv4';
            } else {
                $ip->type='ipv6';
            }
        } else {
            $ip->type='invalid';
        }
    
        return $ip;
    }
    
    // this returns the site_url_id, when a site_url_address is given
    function get_site_url_id($site_url_address)
    {
        global $memcacheWorking, $memcache;
        
        $mysql['site_url_address'] = self::$db->real_escape_string($site_url_address);
        
        if ($memcacheWorking) {
            $time = 604800; // 7 days in sec
                            // get from memcached
            $getURL = $memcache->get(md5("url-id" . $site_url_address . systemHash()));
            if ($getURL) {
                return $getURL;
            } else {
                $site_url_sql = "SELECT  GROUP_CONCAT(distinct 2de.click_referer_site_url_id) AS site_url_id FROM 202_dataengine as 2de LEFT JOIN 202_site_urls ON (2de.click_referer_site_url_id = site_url_id)  WHERE site_url_address LIKE '%" . $mysql['site_url_address'] . "%'";
                
                $site_url_result = _mysqli_query($site_url_sql);
                $site_url_row = $site_url_result->fetch_assoc();
                if ($site_url_row) {
                    // if this site_url_id already exists, return the site_url_id for it.
                    $site_url_id = $site_url_row['site_url_id'];
                    $setID = $memcache->set(md5("url-id" . $site_url_address . systemHash()), $site_url_id, false, $time);
                    return $site_url_id;
                }
            }
        } else {
            
            $site_url_sql = "SELECT GROUP_CONCAT(distinct 2de.click_referer_site_url_id) AS site_url_id FROM 202_dataengine as 2de LEFT JOIN 202_site_urls ON (2de.click_referer_site_url_id = site_url_id)  WHERE site_url_address LIKE '%" . $mysql['site_url_address'] . "%'";
            $site_url_result = _mysqli_query($site_url_sql);
            $site_url_row = $site_url_result->fetch_assoc();
            
            if ($site_url_row) {
                // if this site_url_id already exists, return the site_url_id for it.
                $site_url_id = $site_url_row['site_url_id'];
                
                return $site_url_id;
            }
        }
    }

    function doLpOverviewReport($clickFrom, $clickTo, $cpv)
    {
        $mysql['from'] = $clickFrom;
        $mysql['to'] = $clickTo;
        $click_filtered = $this->getAccountOverviewFilters();
        // SQL_CALC_FOUND_ROWS
        
        $click_sql = "select landing_page_nickname,
2st.landing_page_id, sum(clicks) as clicks, sum(click_out) as click_out,";
        $click_sql .= "(clicks/click_out)*100 as ctr,SUM(leads) AS leads,(SUM(click_lead)/sum(clicks))*100 as su_ratio,
(SUM(income) / sum(leads)) AS payout,SUM(income)/SUM(clicks) as epc,SUM(cost)/sum(clicks) AS cpc,SUM(income) AS income, SUM(cost) AS cost,(SUM(income)-SUM(cost)) AS net,((SUM(income)-SUM(cost))/SUM(cost)*100 ) as roi
from 202_dataengine as 2st
LEFT OUTER JOIN 202_landing_pages USING (landing_page_id)";
        $click_sql .= $this->mysql['user_id_query'];
        $click_sql .= "
AND 2st.click_time >= " . $mysql['from'] . "
AND 2st.click_time <= " . $mysql['to'] . $click_filtered . "
group BY landing_page_id
ORDER BY landing_page_id ASC";
        
      
        $click_result = _mysqli_query($click_sql); // ($click_sql);
        $i = 0;
        
        while ($click_row = $click_result->fetch_assoc()) {
            if ($click_row) {
                $i ++;
                $data[] = $this->htmlFormat($click_row, $cpv);
                $totals['clicks'] = $totals['clicks'] + $click_row['clicks'];
                $totals['click_out'] = $totals['click_out'] + $click_row['click_out'];
                $totals['ctr'] = @round($totals['click_out'] / $totals['clicks'] * 100, 2);
                $totals['cost'] = $totals['cost'] + $click_row['cost'];
                $totals['cpc'] = @round($totals['cost'] / $totals['clicks'], 5);
                $totals['leads'] = $totals['leads'] + $click_row['leads'];
                $totals['su_ratio'] = @round($totals['leads'] / $totals['clicks'] * 100, 2);
                $totals['payout'] = @round(($totals['payout'] + $click_row['payout']) / $i, 2);
                $totals['income'] = $totals['income'] + $click_row['income'];
                $totals['epc'] = @round($totals['income'] / $totals['clicks'], 5);
                $totals['net'] = $totals['income'] - $totals['cost'];
               $totals['roi'] = ($totals['cost']=='0') ? 0 : @round($totals['net'] / $totals['cost'] * 100);
            }
        }
        
        $data[] = $this->htmlFormat($totals, $cpv, 'total');
        // $click_sql = //query to get the number of results
        return $data;
    }

    function doCampaignOverviewReport($clickFrom, $clickTo, $cpv)
    {
        $mysql['from'] = $clickFrom;
        $mysql['to'] = $clickTo;
        $click_filtered = $this->getAccountOverviewFilters();

         $click_sql = "SELECT  2c.aff_campaign_id, 
             2ac.aff_campaign_name, 
             SUM(2c.clicks) AS clicks,
            SUM(2c.click_out) AS click_out, 
            SUM(2c.leads) AS leads, 
            2ac.aff_campaign_payout AS payout, 
            (clicks/click_out)*100 as ctr,
            SUM(2c.income) AS income, 
            SUM(2c.cost) AS cost,
            SUM(income)/SUM(clicks) as epc,
            (SUM(click_lead)/sum(clicks))*100 as su_ratio,
            SUM(cost)/sum(clicks) AS cpc,
            (SUM(income)-SUM(cost)) AS net,
            ((SUM(income)-SUM(cost))/SUM(cost)*100 ) as roi 
             FROM 202_dataengine AS 2c 
             LEFT OUTER JOIN 202_aff_campaigns AS 2ac ON (2c.aff_campaign_id = 2ac.aff_campaign_id) 
             WHERE 2c.user_id = " . $this->mysql['user_id'] . "
AND 2c.click_time >= " . $mysql['from'] . "
AND 2c.click_time <= " . $mysql['to'] . $click_filtered . "
             GROUP BY IF(2c.aff_campaign_id is null or 2c.aff_campaign_id = '', '0', 2c.aff_campaign_id)";
         
       /* $click_sql = "SELECT 2pn.ppc_network_id as ppc_network_id, 2pn.ppc_network_name, 2ac.aff_network_id, 2an.aff_network_name,
            2c.aff_campaign_id, 2ac.aff_campaign_name, SUM(2c.clicks) AS clicks,
            SUM(2c.click_out) AS click_out, 
            SUM(2c.leads) AS leads, 
            2ac.aff_campaign_payout AS payout, 
            (clicks/click_out)*100 as ctr,
            SUM(2c.income) AS income, 
            SUM(2c.cost) AS cost,
            SUM(income)/SUM(clicks) as epc,
            (SUM(click_lead)/sum(clicks))*100 as su_ratio,
            SUM(cost)/sum(clicks) AS cpc,
            (SUM(income)-SUM(cost)) AS net,
            ((SUM(income)-SUM(cost))/SUM(cost)*100 ) as roi
            FROM 202_dataengine AS 2c LEFT OUTER JOIN 202_aff_campaigns AS 2ac ON (2c.aff_campaign_id = 2ac.aff_campaign_id)
            LEFT OUTER JOIN 202_ppc_accounts AS 2pa ON (2c.ppc_account_id = 2pa.ppc_account_id)
            LEFT OUTER JOIN 202_ppc_networks AS 2pn ON (2pa.ppc_network_id = 2pn.ppc_network_id)
            LEFT OUTER JOIN 202_aff_networks AS 2an ON (2ac.aff_network_id = 2an.aff_network_id)
            WHERE 2c.user_id = " . $this->mysql['user_id'] . "
AND 2c.click_time >= " . $mysql['from'] . "
AND 2c.click_time <= " . $mysql['to'] . $click_filtered . "
            GROUP BY IFNULL(2c.aff_campaign_id, '')";*/
      //  echo $click_sql;
    //    die();
        
   /*      $click_sql = "select aff_network_name,
aff_campaign_name,
2st.aff_campaign_id, sum(clicks) as clicks,  sum(click_out) as click_out,";
        $click_sql .= "(clicks/click_out)*100 as ctr,SUM(leads) AS leads, (SUM(click_lead)/sum(clicks))*100 as su_ratio,
(SUM(income) / sum(leads)) AS payout,SUM(income)/SUM(clicks) as epc,SUM(cost)/sum(clicks) AS cpc,SUM(income) AS income, SUM(cost) AS cost,(SUM(income)-SUM(cost)) AS net,((SUM(income)-SUM(cost))/SUM(cost)*100 ) as roi
from 202_dataengine as 2st
LEFT JOIN 202_aff_campaigns USING (aff_campaign_id)
LEFT JOIN 202_aff_networks on (2st.aff_network_id= 202_aff_networks.`aff_network_id`)";
        $click_sql .=  $this->mysql['user_id_query'] . "
AND 2st.click_time >= " . $mysql['from'] . "
AND 2st.click_time <= " . $mysql['to'] . $click_filtered . "
AND 2st.aff_campaign_id IS NOT NULL group BY aff_campaign_id
ORDER BY aff_campaign_id ASC"; */

       
        $click_result = _mysqli_query($click_sql); 
        
        $i = 0;
        while ($click_row = $click_result->fetch_assoc()) {
            $i ++;
            $data[] = $this->htmlFormat($click_row, $cpv,'','overview');
            $totals['clicks'] = $totals['clicks'] + $click_row['clicks'];
            $totals['click_out'] = $totals['click_out'] + $click_row['click_out'];
            $totals['ctr'] = @round($totals['click_out'] / $totals['clicks'] * 100, 2);
            $totals['cost'] = $totals['cost'] + $click_row['cost'];
            $totals['cpc'] = @round($totals['cost'] / $totals['clicks'], 5);
            $totals['leads'] = $totals['leads'] + $click_row['leads'];
            $totals['su_ratio'] = @round($totals['leads'] / $totals['clicks'] * 100, 2);
            $totals['payout'] = @round(($totals['payout'] + $click_row['payout']) / $i, 2);
            $totals['income'] = $totals['income'] + $click_row['income'];
            $totals['epc'] = @round($totals['income'] / $totals['clicks'], 5);
            $totals['net'] = $totals['income'] - $totals['cost'];
            $totals['roi'] = ($totals['cost']=='0') ? 0 : @round($totals['net'] / $totals['cost'] * 100);
            
        }
        $data[] = $this->htmlFormat($totals, $cpv, 'total');

        return $data;
        
    }

    function doPerPpcReport($type, $clickFrom, $clickTo, $cpv)
    {
        $mysql['from'] = $clickFrom;
        $mysql['to'] = $clickTo;
        $click_sql = "select";
        
        if ($type == 'slp_direct_link') {
            $select_by_id = "aff_campaign_id";
            $click_sql .= "
            aff_network_name,
            aff_campaign_name,
            2st.aff_campaign_id,";
        } else 
            if ($type == 'alp') {
                $select_by_id = "landing_page_id";
                $click_sql .= "
            landing_page_nickname, 
            2st.landing_page_id,";
            }
        
        $click_sql .= "
        sum(clicks) as clicks,
        sum(click_out) as click_out,
        (clicks/click_out)*100 as ctr,
        SUM(leads) AS leads,
        (SUM(click_lead)/sum(clicks))*100 as su_ratio,
        (SUM(income) / sum(leads)) AS payout, 
        SUM(income)/SUM(clicks) as epc,
        SUM(cost)/sum(clicks) AS cpc,
        SUM(income) AS income,
        SUM(cost) AS cost,
        (SUM(income)-SUM(cost)) AS net,
        ((SUM(income)-SUM(cost))/SUM(cost)*100 ) as roi
        from 202_dataengine as 2st";
        
        if ($type == 'slp_direct_link') {
            $click_sql .= "
            LEFT JOIN 202_aff_campaigns USING (aff_campaign_id)
            LEFT JOIN 202_aff_networks on (2st.aff_network_id= 202_aff_networks.`aff_network_id`)";
        } else 
            if ($type == 'alp') {
                $click_sql .= "
            LEFT JOIN 202_landing_pages USING (landing_page_id)";
            }
        
        $click_sql .= $this->mysql['user_id_query'];
        
        if ($type == 'slp_direct_link') {
            $click_sql .= "
            AND 2st.aff_campaign_id IS TRUE";
        } else 
            if ($type == 'alp') {
                $click_sql .= "
            AND 2st.aff_campaign_id IS FALSE
            AND 2st.landing_page_id IS TRUE";
            }
        
        $click_sql .= "
        AND 2st.click_time >= '" . $mysql['from'] . "' 
        AND 2st.click_time <= '" . $mysql['to'] . "'";
        
        if ($type == 'slp_direct_link') {
            $click_sql .= "
            group BY aff_campaign_id 
            ORDER BY aff_campaign_id ASC";
        } else 
            if ($type == 'alp') {
                $click_sql .= "
            group BY landing_page_id 
            ORDER BY landing_page_id ASC";
            }

        $click_result = _mysqli_query($click_sql);
        
        $ids = array();
        
        while ($click_row = $click_result->fetch_assoc()) {
            if ($click_row) {
                $data[$click_row[$select_by_id]] = $this->htmlFormat($click_row, $cpv, 'total');
                $ids[] = $click_row[$select_by_id];
            }
        }
        
        $ppc_sql = "select
            ppc_account_name,
            ppc_network_name,
            2st.ppc_account_id, 
            2st.{$select_by_id},
            sum(clicks) as clicks,
            sum(click_out) as click_out,
            (clicks/click_out)*100 as ctr,
            SUM(leads) AS leads,
            (SUM(click_lead)/sum(clicks))*100 as su_ratio,
            (SUM(income) / sum(leads)) AS payout, 
            SUM(income)/SUM(clicks) as epc,
            SUM(cost)/sum(clicks) AS cpc,
            SUM(income) AS income,
            SUM(cost) AS cost,
            (SUM(income)-SUM(cost)) AS net,
            ((SUM(income)-SUM(cost))/SUM(cost)*100 ) as roi
            from 202_dataengine as 2st
            LEFT JOIN 202_ppc_accounts ON (2st.ppc_account_id = 202_ppc_accounts.ppc_account_id) 
            LEFT JOIN 202_ppc_networks ON (202_ppc_accounts.ppc_network_id = 202_ppc_networks.ppc_network_id)".
            $this->mysql['user_id_query'].
            " AND 2st.{$select_by_id} IN (" . implode(",", $ids) . ")";
        
        if ($type == 'alp') {
            $ppc_sql .= " AND 2st.aff_campaign_id IS FALSE";
        }
        
        $ppc_sql .= "
            AND 2st.click_time >= '" . $mysql['from'] . "' 
            AND 2st.click_time <= '" . $mysql['to'] . "' 
            group BY 2st.{$select_by_id},2st.ppc_account_id 
            ORDER BY 2st.ppc_account_id ASC;";
   
        $ppc_result = _mysqli_query($ppc_sql);

        if ($ppc_result->num_rows > 0) {
            while ($ppc_row = $ppc_result->fetch_assoc()) {
                $data[$ppc_row[$select_by_id]]['ppc_accounts'][$ppc_row['ppc_account_id']] = $this->htmlFormat($ppc_row, $cpv);
            }
        }
        
        return $data;
    }

    function doBreakdownReport($clickFrom, $clickTo, $cpv)
    {
        $mysql['from'] = $clickFrom;
        $mysql['to'] = $clickTo;
        $up = new UserPrefs();
        // $rangeBreakdwon=$up->getPref('user_pref_breakdown');
        // echo $rangeBreakdwon;
        switch ($up->getPref('user_pref_breakdown')) {
            case 'hour':
                $groupby = " HOUR(FROM_UNIXTIME(click_time)) ";
                $dateFormat = "DATE_FORMAT(FROM_UNIXTIME(click_time),'%b %d, %Y at %l%p')";
                break;
            case 'day':
                $groupby = " DAY(FROM_UNIXTIME(click_time)) ";
                $dateFormat = "DATE_FORMAT(FROM_UNIXTIME(click_time),'%b %d, %Y')";
                break;
            case 'month':
                $groupby = " MONTH(FROM_UNIXTIME(click_time)) ";
                $dateFormat = "DATE_FORMAT(FROM_UNIXTIME(click_time),'%b %Y')";
                break;
            case 'year':
                $groupby = " YEAR(FROM_UNIXTIME(click_time)) ";
                $dateFormat = "DATE_FORMAT(FROM_UNIXTIME(click_time),'%Y')";
                break;
        }

        $click_filtered = $this->getFilters();
        $click_sql = " 
SELECT " . $dateFormat . " as click_time_from_disp,sum(clicks) as clicks, sum(click_out) as click_out, 
	    (clicks/click_out)*100 as ctr,
	    SUM(leads) AS leads, 
(SUM(click_lead)/sum(clicks))*100 as su_ratio,
(SUM(income) / sum(leads)) AS payout,  
SUM(2st.income) AS income, 
SUM(2st.income)/sum(clicks) as epc,
SUM(2st.cost) AS cost,
SUM(2st.cost)/sum(clicks) AS cpc,
(SUM(2st.income)-SUM(2st.cost)) AS net,
((SUM(2st.income)-SUM(2st.cost))/SUM(2st.cost)*100 ) as roi FROM 202_dataengine as 2st " . $click_filtered['join'] . $this->mysql['user_id_query'] . " AND click_time >= " . $mysql['from'] . " AND click_time <= " . $mysql['to'] . $click_filtered['filter'] . " group by" . $groupby . $this->sortOrder('sort_breakdown_time_order asc');

        $click_result = _mysqli_query($click_sql); // ($click_sql);
        $i = 0; // counter for averages
        while ($click_row = $click_result->fetch_assoc()) {
            $i ++; // increment the counter so we can get the right avarage
            $data[] = $this->htmlFormat($click_row, $cpv);
            $totals['clicks'] = $totals['clicks'] + $click_row['clicks'];
            $totals['click_out'] = $totals['click_out'] + $click_row['click_out'];
            $totals['ctr'] = @round($totals['click_out'] / $totals['clicks'] * 100, 2);
            $totals['cost'] = $totals['cost'] + $click_row['cost'];
            $totals['cpc'] = @round($totals['cost'] / $totals['clicks'], 5);
            $totals['leads'] = $totals['leads'] + $click_row['leads'];
            $totals['su_ratio'] = @round($totals['leads'] / $totals['clicks'] * 100, 2);
            $totals['payout'] = @round(($totals['payout'] + $click_row['payout']) / $i, 2);
            $totals['income'] = $totals['income'] + $click_row['income'];
            $totals['epc'] = @round($totals['income'] / $totals['clicks'], 5);
            $totals['net'] = $totals['income'] - $totals['cost'];
            $totals['roi'] = ($totals['cost']=='0') ? 0 : @round($totals['net'] / $totals['cost'] * 100);
        }
        $data[] = $this->htmlFormat($totals, $cpv, 'total');
        
        return $data;
    }

    function doHourlyReport($clickFrom, $clickTo, $cpv)
    {
        $mysql['from'] = $clickFrom;
        $mysql['to'] = $clickTo;
        $up = new UserPrefs();
        
        $click_filtered = $this->getFilters();
        $click_sql = "
SELECT  DATE_FORMAT(FROM_UNIXTIME(click_time),'%l %p')  as click_time_from_disp, DATE_FORMAT(FROM_UNIXTIME(click_time),'%p') as ampm, sum(clicks) as clicks, sum(click_out) as click_out,
	    (clicks/click_out)*100 as ctr,
	    SUM(leads) AS leads,
(SUM(click_lead)/sum(clicks))*100 as su_ratio,
(SUM(income) / sum(leads)) AS payout, 
SUM(2st.income) AS income,
SUM(2st.income)/sum(clicks) as epc,
SUM(2st.cost) AS cost,
SUM(2st.cost)/sum(clicks) AS cpc,
(SUM(2st.income)-SUM(2st.cost)) AS net,
((SUM(2st.income)-SUM(2st.cost))/SUM(2st.cost)*100 ) as roi FROM 202_dataengine as 2st " . $click_filtered['join'] . $this->mysql['user_id_query'] . " AND click_time >= " . $mysql['from'] . " AND click_time <= " . $mysql['to'] . $click_filtered['filter'] . " group by HOUR(FROM_UNIXTIME(click_time)) " . $this->sortOrder('breakdown asc');
  
        $click_result = _mysqli_query($click_sql); // ($click_sql);
        $i = 0;
        while ($click_row = $click_result->fetch_assoc()) {
            $i ++;
            
            $data[] = $this->htmlFormat($click_row, $cpv);
            $totals['clicks'] = $totals['clicks'] + $click_row['clicks'];
            $totals['click_out'] = $totals['click_out'] + $click_row['click_out'];
            $totals['ctr'] = @round($totals['click_out'] / $totals['clicks'] * 100, 2);
            $totals['cost'] = $totals['cost'] + $click_row['cost'];
            $totals['cpc'] = @round($totals['cost'] / $totals['clicks'], 5);
            $totals['leads'] = $totals['leads'] + $click_row['leads'];
            $totals['su_ratio'] = @round($totals['leads'] / $totals['clicks'] * 100, 2);
            $totals['payout'] = @round(($totals['payout'] + $click_row['payout']) / $i, 2);
            $totals['income'] = $totals['income'] + $click_row['income'];
            $totals['epc'] = @round($totals['income'] / $totals['clicks'], 5);
            $totals['net'] = $totals['income'] - $totals['cost'];
           $totals['roi'] = ($totals['cost']=='0') ? 0 : @round($totals['net'] / $totals['cost'] * 100);
            // print_r($click_row);
        }
        $data[] = $this->htmlFormat($totals, $cpv, 'total');
        
        return $data;
    }

    function doWeeklyReport($clickFrom, $clickTo, $cpv)
    {
        $mysql['from'] = $clickFrom;
        $mysql['to'] = $clickTo;
        $up = new UserPrefs();
        $click_filtered = $this->getFilters();
        $click_sql = "
SELECT DATE_FORMAT(FROM_UNIXTIME(click_time),'%a') as click_time_from_disp, DATE_FORMAT(FROM_UNIXTIME(click_time),'%w') as click_time_from_sort, sum(clicks) as clicks, sum(click_out) as click_out,
	    (clicks/click_out)*100 as ctr,
	    SUM(leads) AS leads,
(SUM(click_lead)/sum(clicks))*100 as su_ratio,
(SUM(income) / sum(leads)) AS payout, 
SUM(2st.income) AS income,
SUM(2st.income)/sum(clicks) as epc,
SUM(2st.cost) AS cost,
SUM(2st.cost)/sum(clicks) AS cpc,
(SUM(2st.income)-SUM(2st.cost)) AS net,
((SUM(2st.income)-SUM(2st.cost))/SUM(2st.cost)*100 ) as roi FROM 202_dataengine as 2st " . $click_filtered['join'] .  $this->mysql['user_id_query'] . " AND click_time >= " . $mysql['from'] . " AND click_time <= " . $mysql['to'] . $click_filtered['filter'] . " group by click_time_from_disp  ORDER BY click_time_from_sort ASC"; // . $this->sortOrder();
                                                                                                                                                                                                                                                                                                                                                         
        $click_result = _mysqli_query($click_sql); // ($click_sql);
        $i = 0;
        while ($click_row = $click_result->fetch_assoc()) {
            $i ++;
            // print_r($click_row);
            $data[] = $this->htmlFormat($click_row, $cpv);
            $totals['clicks'] = $totals['clicks'] + $click_row['clicks'];
            $totals['click_out'] = $totals['click_out'] + $click_row['click_out'];
            $totals['ctr'] = @round($totals['click_out'] / $totals['clicks'] * 100, 2);
            $totals['cost'] = $totals['cost'] + $click_row['cost'];
            $totals['cpc'] = @round($totals['cost'] / $totals['clicks'], 5);
            $totals['leads'] = $totals['leads'] + $click_row['leads'];
            $totals['su_ratio'] = @round($totals['leads'] / $totals['clicks'] * 100, 2);
            $totals['payout'] = @round(($totals['payout'] + $click_row['payout']) / $i, 2);
            $totals['income'] = $totals['income'] + $click_row['income'];
            $totals['epc'] = @round($totals['income'] / $totals['clicks'], 5);
            $totals['net'] = $totals['income'] - $totals['cost'];
           $totals['roi'] = ($totals['cost']=='0') ? 0 : @round($totals['net'] / $totals['cost'] * 100);
            // print_r($click_row);
        }
        $data[] = $this->htmlFormat($totals, $cpv, 'total');
        
        return $data;
    }

    function doKeywordReport($clickFrom, $clickTo, $cpv)
    {
        $mysql['from'] = $clickFrom;
        $mysql['to'] = $clickTo;
        $up = new UserPrefs();
        $click_filtered = $this->getFilters();
        $click_sql = "
SELECT SQL_CALC_FOUND_ROWS `keyword`,sum(clicks) as clicks, sum(click_out) as click_out,
	    (clicks/click_out)*100 as ctr,
	    SUM(leads) AS leads,
(SUM(click_lead)/sum(clicks))*100 as su_ratio,
(SUM(income) / sum(leads)) AS payout, 
SUM(2st.income) AS income,
SUM(2st.income)/sum(clicks) as epc,
SUM(2st.cost) AS cost,
SUM(2st.cost)/sum(clicks) AS cpc,
(SUM(2st.income)-SUM(2st.cost)) AS net,
((SUM(2st.income)-SUM(2st.cost))/SUM(2st.cost)*100 ) as roi FROM 202_dataengine as 2st LEFT JOIN 202_keywords as 2k on (2st.keyword_id= 2k.keyword_id)             
            " . $this->mysql['user_id_query'] . " AND click_time >= " . $mysql['from'] . " AND click_time <= " . $mysql['to'] . $click_filtered['filter'] . " group by keyword" . $this->sortOrder() . $click_filtered['limit'];

        $click_result = _mysqli_query($click_sql); // ($click_sql);
        
        $i = 0;
        while ($click_row = $click_result->fetch_assoc()) {
            $i ++;
            // print_r($click_row);
            $data[] = $this->htmlFormat($click_row, $cpv);
            $totals['clicks'] = $totals['clicks'] + $click_row['clicks'];
            $totals['click_out'] = $totals['click_out'] + $click_row['click_out'];
            $totals['ctr'] = @round($totals['click_out'] / $totals['clicks'] * 100, 2);
            $totals['cost'] = $totals['cost'] + $click_row['cost'];
            $totals['cpc'] = @round($totals['cost'] / $totals['clicks'], 5);
            $totals['leads'] = $totals['leads'] + $click_row['leads'];
            $totals['su_ratio'] = @round($totals['leads'] / $totals['clicks'] * 100, 2);
            $totals['payout'] = @round(($totals['payout'] + $click_row['payout']) / $i, 2);
            $totals['income'] = $totals['income'] + $click_row['income'];
            $totals['epc'] = @round($totals['income'] / $totals['clicks'], 5);
            $totals['net'] = $totals['income'] - $totals['cost'];
           $totals['roi'] = ($totals['cost']=='0') ? 0 : @round($totals['net'] / $totals['cost'] * 100);
            // print_r($click_row);
        }
        $data[] = $this->htmlFormat($totals, $cpv, 'total');
        
        $count_sql = "select FOUND_ROWS() as found_rows"; // query to get the number of results
        $count_result = _mysqli_query($count_sql);
        $count_row = $count_result->fetch_assoc();
        self::$found_rows = ($count_row['found_rows']);
        
        return $data;
    }

    function doTextadReport($clickFrom, $clickTo, $cpv)
    {
        $mysql['from'] = $clickFrom;
        $mysql['to'] = $clickTo;
        $up = new UserPrefs();
        
        $click_filtered = $this->getFilters();
        $click_sql = "
SELECT SQL_CALC_FOUND_ROWS `text_ad_name`,sum(clicks) as clicks, sum(click_out) as click_out,
	    (clicks/click_out)*100 as ctr,
	    SUM(leads) AS leads,
(SUM(click_lead)/sum(clicks))*100 as su_ratio,
(SUM(income) / sum(leads)) AS payout, 
SUM(2st.income) AS income,
SUM(2st.income)/sum(clicks) as epc,
SUM(2st.cost) AS cost,
SUM(2st.cost)/sum(clicks) AS cpc,
(SUM(2st.income)-SUM(2st.cost)) AS net,
((SUM(2st.income)-SUM(2st.cost))/SUM(2st.cost)*100 ) as roi FROM 202_dataengine as 2st " . $click_filtered['join'] . "  
LEFT JOIN 202_text_ads on (2st.text_ad_id= 202_text_ads.text_ad_id)
            " . $this->mysql['user_id_query'] . " AND click_time >= " . $mysql['from'] . " AND click_time <= " . $mysql['to'] . $click_filtered['filter'] . " group by text_ad_name" . $this->sortOrder() . $click_filtered['limit'];

        $click_result = _mysqli_query($click_sql); // ($click_sql);
        $i = 0;
        while ($click_row = $click_result->fetch_assoc()) {
            $i ++;
            // print_r($click_row);
            $data[] = $this->htmlFormat($click_row, $cpv);
            $totals['clicks'] = $totals['clicks'] + $click_row['clicks'];
            $totals['click_out'] = $totals['click_out'] + $click_row['click_out'];
            $totals['ctr'] = @round($totals['click_out'] / $totals['clicks'] * 100, 2);
            $totals['cost'] = $totals['cost'] + $click_row['cost'];
            $totals['cpc'] = @round($totals['cost'] / $totals['clicks'], 5);
            $totals['leads'] = $totals['leads'] + $click_row['leads'];
            $totals['su_ratio'] = @round($totals['leads'] / $totals['clicks'] * 100, 2);
            $totals['payout'] = @round(($totals['payout'] + $click_row['payout']) / $i, 2);
            $totals['income'] = $totals['income'] + $click_row['income'];
            $totals['epc'] = @round($totals['income'] / $totals['clicks'], 5);
            $totals['net'] = $totals['income'] - $totals['cost'];
           $totals['roi'] = ($totals['cost']=='0') ? 0 : @round($totals['net'] / $totals['cost'] * 100);
            // print_r($click_row);
        }
        $data[] = $this->htmlFormat($totals, $cpv, 'total');
        
        $count_sql = "select FOUND_ROWS() as found_rows"; // query to get the number of results
        $count_result = _mysqli_query($count_sql);
        $count_row = $count_result->fetch_assoc();
        self::$found_rows = ($count_row['found_rows']);
        
        return $data;
    }

    function doRefererReport($clickFrom, $clickTo, $cpv)
    {
        $mysql['from'] = $clickFrom;
        $mysql['to'] = $clickTo;
        $up = new UserPrefs();
        
        $click_filtered = $this->getFilters();
        $click_sql = "
SELECT  SQL_CALC_FOUND_ROWS site_domain_host as referer_name,sum(clicks) as clicks, sum(click_out) as click_out,
	    (clicks/click_out)*100 as ctr,
	    SUM(leads) AS leads,
(SUM(click_lead)/sum(clicks))*100 as su_ratio,
(SUM(income) / sum(leads)) AS payout, 
SUM(2st.income) AS income,
SUM(2st.income)/sum(clicks) as epc,
SUM(2st.cost) AS cost,
SUM(2st.cost)/sum(clicks) AS cpc,
(SUM(2st.income)-SUM(2st.cost)) AS net,
((SUM(2st.income)-SUM(2st.cost))/SUM(2st.cost)*100 ) as roi FROM 202_dataengine as 2st " . $click_filtered['join'] . "  
LEFT JOIN 202_site_urls on (2st.click_referer_site_url_id = 202_site_urls.site_url_id)
 LEFT JOIN 202_site_domains on (202_site_domains.site_domain_id = 202_site_urls.site_domain_id)
            " . $this->mysql['user_id_query'] . " AND click_time >= " . $mysql['from'] . " AND click_time <= " . $mysql['to'] . $click_filtered['filter'] . " group by referer_name" . $this->sortOrder() . $click_filtered['limit'];

        $click_result = _mysqli_query($click_sql); // ($click_sql);
        $i = 0;
        while ($click_row = $click_result->fetch_assoc()) {
            $i ++;
            // print_r($click_row);
            $data[] = $this->htmlFormat($click_row, $cpv);
            $totals['clicks'] = $totals['clicks'] + $click_row['clicks'];
            $totals['click_out'] = $totals['click_out'] + $click_row['click_out'];
            $totals['ctr'] = @round($totals['click_out'] / $totals['clicks'] * 100, 2);
            $totals['cost'] = $totals['cost'] + $click_row['cost'];
            $totals['cpc'] = @round($totals['cost'] / $totals['clicks'], 5);
            $totals['leads'] = $totals['leads'] + $click_row['leads'];
            $totals['su_ratio'] = @round($totals['leads'] / $totals['clicks'] * 100, 2);
            $totals['payout'] = @round(($totals['payout'] + $click_row['payout']) / $i, 2);
            $totals['income'] = $totals['income'] + $click_row['income'];
            $totals['epc'] = @round($totals['income'] / $totals['clicks'], 5);
            $totals['net'] = $totals['income'] - $totals['cost'];
           $totals['roi'] = ($totals['cost']=='0') ? 0 : @round($totals['net'] / $totals['cost'] * 100);
            // print_r($click_row);
        }
        $data[] = $this->htmlFormat($totals, $cpv, 'total');
        
        $count_sql = "select FOUND_ROWS() as found_rows"; // query to get the number of results
        $count_result = _mysqli_query($count_sql);
        $count_row = $count_result->fetch_assoc();
        self::$found_rows = ($count_row['found_rows']);
        
        return $data;
    }

    function doIPReport($clickFrom, $clickTo, $cpv)
    {
        $mysql['from'] = $clickFrom;
        $mysql['to'] = $clickTo;
        $up = new UserPrefs();
        $click_filtered = $this->getFilters();
        $click_sql = "
SELECT  SQL_CALC_FOUND_ROWS IFNULL(".$inet6_ntoa."(2i6.ip_address),2i.ip_address) as ip_address,sum(clicks) as clicks, sum(click_out) as click_out,
	    (clicks/click_out)*100 as ctr,
	    SUM(leads) AS leads,
(SUM(click_lead)/sum(clicks))*100 as su_ratio,
(SUM(income) / sum(leads)) AS payout, 
SUM(2st.income) AS income,
SUM(2st.income)/sum(clicks) as epc,
SUM(2st.cost) AS cost,
SUM(2st.cost)/sum(clicks) AS cpc,
(SUM(2st.income)-SUM(2st.cost)) AS net,
((SUM(2st.income)-SUM(2st.cost))/SUM(2st.cost)*100 ) as roi FROM 202_dataengine as 2st " . $click_filtered['join'] . "  
LEFT JOIN 202_ips AS 2i on (2st.ip_id = 2i.ip_id)
LEFT JOIN 202_ips_v6 AS 2i6 ON (2i6.ip_id = 2i.ip_address COLLATE utf8mb4_general_ci)
             " . $this->mysql['user_id_query'] . " AND click_time >= " . $mysql['from'] . " AND click_time <= " . $mysql['to'] . $click_filtered['filter'] . " group by ip_address" . $this->sortOrder() . $click_filtered['limit'];

        $click_result = _mysqli_query($click_sql);
        $i = 0;
        while ($click_row = $click_result->fetch_assoc()) {
            $i ++;
            $data[] = $this->htmlFormat($click_row, $cpv);
            $totals['clicks'] = $totals['clicks'] + $click_row['clicks'];
            $totals['click_out'] = $totals['click_out'] + $click_row['click_out'];
            $totals['ctr'] = @round($totals['click_out'] / $totals['clicks'] * 100, 2);
            $totals['cost'] = $totals['cost'] + $click_row['cost'];
            $totals['cpc'] = @round($totals['cost'] / $totals['clicks'], 5);
            $totals['leads'] = $totals['leads'] + $click_row['leads'];
            $totals['su_ratio'] = @round($totals['leads'] / $totals['clicks'] * 100, 2);
            $totals['payout'] = @round(($totals['payout'] + $click_row['payout']) / $i, 2);
            $totals['income'] = $totals['income'] + $click_row['income'];
            $totals['epc'] = @round($totals['income'] / $totals['clicks'], 5);
            $totals['net'] = $totals['income'] - $totals['cost'];
            $totals['roi'] = ($totals['cost']=='0') ? 0 : @round($totals['net'] / $totals['cost'] * 100);
        }
        $data[] = $this->htmlFormat($totals, $cpv, 'total');
        
        $count_sql = "select FOUND_ROWS() as found_rows"; // query to get the nymber of results
        $count_result = _mysqli_query($count_sql);
        $count_row = $count_result->fetch_assoc();
        self::$found_rows = ($count_row['found_rows']);
        
        return $data;
    }

    function doCountryReport($clickFrom, $clickTo, $cpv)
    {
        $mysql['from'] = $clickFrom;
        $mysql['to'] = $clickTo;
        $up = new UserPrefs();
        $click_filtered = $this->getFilters();
        $click_sql = "
SELECT  SQL_CALC_FOUND_ROWS country_name,country_code,sum(clicks) as clicks, sum(click_out) as click_out,
	    (clicks/click_out)*100 as ctr,
	    SUM(leads) AS leads,
(SUM(click_lead)/sum(clicks))*100 as su_ratio,
(SUM(income) / sum(leads)) AS payout, 
SUM(2st.income) AS income,
SUM(2st.income)/sum(clicks) as epc,
SUM(2st.cost) AS cost,
SUM(2st.cost)/sum(clicks) AS cpc,
(SUM(2st.income)-SUM(2st.cost)) AS net,
((SUM(2st.income)-SUM(2st.cost))/SUM(2st.cost)*100 ) as roi FROM 202_dataengine as 2st " . $click_filtered['join'] . "  
LEFT JOIN 202_locations_country on (2st.country_id = 202_locations_country.country_id)
            " . $this->mysql['user_id_query'] . " AND click_time >= " . $mysql['from'] . " AND click_time <= " . $mysql['to'] . $click_filtered['filter'] . " group by country_name" . $this->sortOrder() . $click_filtered['limit'];

        $click_result = _mysqli_query($click_sql); // ($click_sql);
        $i = 0;
        while ($click_row = $click_result->fetch_assoc()) {
            $i ++;
            // print_r($click_row);
            $data[] = $this->htmlFormat($click_row, $cpv);
            $totals['clicks'] = $totals['clicks'] + $click_row['clicks'];
            $totals['click_out'] = $totals['click_out'] + $click_row['click_out'];
            $totals['ctr'] = @round($totals['click_out'] / $totals['clicks'] * 100, 2);
            $totals['cost'] = $totals['cost'] + $click_row['cost'];
            $totals['cpc'] = @round($totals['cost'] / $totals['clicks'], 5);
            $totals['leads'] = $totals['leads'] + $click_row['leads'];
            $totals['su_ratio'] = @round($totals['leads'] / $totals['clicks'] * 100, 2);
            $totals['payout'] = @round(($totals['payout'] + $click_row['payout']) / $i, 2);
            $totals['income'] = $totals['income'] + $click_row['income'];
            $totals['epc'] = @round($totals['income'] / $totals['clicks'], 5);
            $totals['net'] = $totals['income'] - $totals['cost'];
           $totals['roi'] = ($totals['cost']=='0') ? 0 : @round($totals['net'] / $totals['cost'] * 100);
            // print_r($click_row);
        }
        $data[] = $this->htmlFormat($totals, $cpv, 'total');
        
        $count_sql = "select FOUND_ROWS() as found_rows"; // query to get the nymber of results
        $count_result = _mysqli_query($count_sql);
        $count_row = $count_result->fetch_assoc();
        self::$found_rows = ($count_row['found_rows']);
        
        return $data;
    }

    function doRegionReport($clickFrom, $clickTo, $cpv)
    {
        $mysql['from'] = $clickFrom;
        $mysql['to'] = $clickTo;
        $up = new UserPrefs();
        $click_filtered = $this->getFilters();
        $click_sql = "
SELECT  SQL_CALC_FOUND_ROWS region_name,country_code,sum(clicks) as clicks, sum(click_out) as click_out,
	    (clicks/click_out)*100 as ctr,
	    SUM(leads) AS leads,
(SUM(click_lead)/sum(clicks))*100 as su_ratio,
(SUM(income) / sum(leads)) AS payout, 
SUM(2st.income) AS income,
SUM(2st.income)/sum(clicks) as epc,
SUM(2st.cost) AS cost,
SUM(2st.cost)/sum(clicks) AS cpc,
(SUM(2st.income)-SUM(2st.cost)) AS net,
((SUM(2st.income)-SUM(2st.cost))/SUM(2st.cost)*100 ) as roi FROM 202_dataengine as 2st " . $click_filtered['join'] . "  
LEFT JOIN 202_locations_region on (2st.region_id = 202_locations_region.region_id)
LEFT JOIN 202_locations_country on (202_locations_region.main_country_id = 202_locations_country.country_id) 
            " . $this->mysql['user_id_query'] . " AND click_time >= " . $mysql['from'] . " AND click_time <= " . $mysql['to'] . $click_filtered['filter'] . " group by region_name" . $this->sortOrder() . $click_filtered['limit'];

        $click_result = _mysqli_query($click_sql); // ($click_sql);
        $i = 0;
        while ($click_row = $click_result->fetch_assoc()) {
            $i ++;
            // print_r($click_row);
            $data[] = $this->htmlFormat($click_row, $cpv);
            $totals['clicks'] = $totals['clicks'] + $click_row['clicks'];
            $totals['click_out'] = $totals['click_out'] + $click_row['click_out'];
            $totals['ctr'] = @round($totals['click_out'] / $totals['clicks'] * 100, 2);
            $totals['cost'] = $totals['cost'] + $click_row['cost'];
            $totals['cpc'] = @round($totals['cost'] / $totals['clicks'], 5);
            $totals['leads'] = $totals['leads'] + $click_row['leads'];
            $totals['su_ratio'] = @round($totals['leads'] / $totals['clicks'] * 100, 2);
            $totals['payout'] = @round(($totals['payout'] + $click_row['payout']) / $i, 2);
            $totals['income'] = $totals['income'] + $click_row['income'];
            $totals['epc'] = @round($totals['income'] / $totals['clicks'], 5);
            $totals['net'] = $totals['income'] - $totals['cost'];
           $totals['roi'] = ($totals['cost']=='0') ? 0 : @round($totals['net'] / $totals['cost'] * 100);
            // print_r($click_row);
        }
        $data[] = $this->htmlFormat($totals, $cpv, 'total');
        
        $count_sql = "select FOUND_ROWS() as found_rows"; // query to get the nymber of results
        $count_result = _mysqli_query($count_sql);
        $count_row = $count_result->fetch_assoc();
        self::$found_rows = ($count_row['found_rows']);
        
        return $data;
    }

    function doCityReport($clickFrom, $clickTo, $cpv)
    {
        $mysql['from'] = $clickFrom;
        $mysql['to'] = $clickTo;
        $up = new UserPrefs();
        $click_filtered = $this->getFilters();
        $click_sql = "
SELECT  SQL_CALC_FOUND_ROWS city_name,country_code,sum(clicks) as clicks, sum(click_out) as click_out,
	    (clicks/click_out)*100 as ctr,
	    SUM(leads) AS leads,
(SUM(click_lead)/sum(clicks))*100 as su_ratio,
(SUM(income) / sum(leads)) AS payout, 
SUM(2st.income) AS income,
SUM(2st.income)/sum(clicks) as epc,
SUM(2st.cost) AS cost,
SUM(2st.cost)/sum(clicks) AS cpc,
(SUM(2st.income)-SUM(2st.cost)) AS net,
((SUM(2st.income)-SUM(2st.cost))/SUM(2st.cost)*100 ) as roi FROM 202_dataengine as 2st " . $click_filtered['join'] . "  
LEFT JOIN 202_locations_city on (2st.city_id = 202_locations_city.city_id)
LEFT JOIN 202_locations_country on (202_locations_city.main_country_id = 202_locations_country.country_id)         
            " . $this->mysql['user_id_query'] . " AND click_time >= " . $mysql['from'] . " AND click_time <= " . $mysql['to'] . $click_filtered['filter'] . " group by city_name" . $this->sortOrder() . $click_filtered['limit'];

        $click_result = _mysqli_query($click_sql); // ($click_sql);
        $i = 0;
        while ($click_row = $click_result->fetch_assoc()) {
            $i ++;
            // print_r($click_row);
            $data[] = $this->htmlFormat($click_row, $cpv);
            $totals['clicks'] = $totals['clicks'] + $click_row['clicks'];
            $totals['click_out'] = $totals['click_out'] + $click_row['click_out'];
            $totals['ctr'] = @round($totals['click_out'] / $totals['clicks'] * 100, 2);
            $totals['cost'] = $totals['cost'] + $click_row['cost'];
            $totals['cpc'] = @round($totals['cost'] / $totals['clicks'], 5);
            $totals['leads'] = $totals['leads'] + $click_row['leads'];
            $totals['su_ratio'] = @round($totals['leads'] / $totals['clicks'] * 100, 2);
            $totals['payout'] = @round(($totals['payout'] + $click_row['payout']) / $i, 2);
            $totals['income'] = $totals['income'] + $click_row['income'];
            $totals['epc'] = @round($totals['income'] / $totals['clicks'], 5);
            $totals['net'] = $totals['income'] - $totals['cost'];
           $totals['roi'] = ($totals['cost']=='0') ? 0 : @round($totals['net'] / $totals['cost'] * 100);
            // print_r($click_row);
        }
        $data[] = $this->htmlFormat($totals, $cpv, 'total');
        
        $count_sql = "select FOUND_ROWS() as found_rows"; // query to get the nymber of results
        $count_result = _mysqli_query($count_sql);
        $count_row = $count_result->fetch_assoc();
        self::$found_rows = ($count_row['found_rows']);
        
        return $data;
    }

    function doISPReport($clickFrom, $clickTo, $cpv)
    {
        $mysql['from'] = $clickFrom;
        $mysql['to'] = $clickTo;
        $up = new UserPrefs();
        $click_filtered = $this->getFilters();
        $click_sql = "
SELECT  SQL_CALC_FOUND_ROWS isp_name,sum(clicks) as clicks, sum(click_out) as click_out,
	    (clicks/click_out)*100 as ctr,
	    SUM(leads) AS leads,
(SUM(click_lead)/sum(clicks))*100 as su_ratio,
(SUM(income) / sum(leads)) AS payout, 
SUM(2st.income) AS income,
SUM(2st.income)/sum(clicks) as epc,
SUM(2st.cost) AS cost,
SUM(2st.cost)/sum(clicks) AS cpc,
(SUM(2st.income)-SUM(2st.cost)) AS net,
((SUM(2st.income)-SUM(2st.cost))/SUM(2st.cost)*100 ) as roi FROM 202_dataengine as 2st " . $click_filtered['join'] . "  
LEFT JOIN 202_locations_isp on (2st.isp_id = 202_locations_isp.isp_id)
            " . $this->mysql['user_id_query'] . " AND click_time >= " . $mysql['from'] . " AND click_time <= " . $mysql['to'] . $click_filtered['filter'] . " group by isp_name" . $this->sortOrder() . $click_filtered['limit'];

        $click_result = _mysqli_query($click_sql); // ($click_sql);
        $i = 0;
        while ($click_row = $click_result->fetch_assoc()) {
            $i ++;
            // print_r($click_row);
            $data[] = $this->htmlFormat($click_row, $cpv);
            $totals['clicks'] = $totals['clicks'] + $click_row['clicks'];
            $totals['click_out'] = $totals['click_out'] + $click_row['click_out'];
            $totals['ctr'] = @round($totals['click_out'] / $totals['clicks'] * 100, 2);
            $totals['cost'] = $totals['cost'] + $click_row['cost'];
            $totals['cpc'] = @round($totals['cost'] / $totals['clicks'], 5);
            $totals['leads'] = $totals['leads'] + $click_row['leads'];
            $totals['su_ratio'] = @round($totals['leads'] / $totals['clicks'] * 100, 2);
            $totals['payout'] = @round(($totals['payout'] + $click_row['payout']) / $i, 2);
            $totals['income'] = $totals['income'] + $click_row['income'];
            $totals['epc'] = @round($totals['income'] / $totals['clicks'], 5);
            $totals['net'] = $totals['income'] - $totals['cost'];
           $totals['roi'] = ($totals['cost']=='0') ? 0 : @round($totals['net'] / $totals['cost'] * 100);
            // print_r($click_row);
        }
        $data[] = $this->htmlFormat($totals, $cpv, 'total');
        
        $count_sql = "select FOUND_ROWS() as found_rows"; // query to get the nymber of results
        $count_result = _mysqli_query($count_sql);
        $count_row = $count_result->fetch_assoc();
        self::$found_rows = ($count_row['found_rows']);
        
        return $data;
    }

    function doLandingPageReport($clickFrom, $clickTo, $cpv)
    {
        $mysql['from'] = $clickFrom;
        $mysql['to'] = $clickTo;
        $up = new UserPrefs();
        $click_filtered = $this->getFilters();
        $click_sql = "
SELECT  SQL_CALC_FOUND_ROWS landing_page_nickname,sum(clicks) as clicks, sum(click_out) as click_out,
	    (clicks/click_out)*100 as ctr,
	    SUM(leads) AS leads,
(SUM(click_lead)/sum(clicks))*100 as su_ratio,
(SUM(income) / sum(leads)) AS payout, 
SUM(2st.income) AS income,
SUM(2st.income)/sum(clicks) as epc,
SUM(2st.cost) AS cost,
SUM(2st.cost)/sum(clicks) AS cpc,
(SUM(2st.income)-SUM(2st.cost)) AS net,
((SUM(2st.income)-SUM(2st.cost))/SUM(2st.cost)*100 ) as roi FROM 202_dataengine as 2st " . $click_filtered['join'] . "  
LEFT JOIN 202_landing_pages on (2st.landing_page_id = 202_landing_pages.landing_page_id)
            " . $this->mysql['user_id_query'] . " AND click_time >= " . $mysql['from'] . " AND click_time <= " . $mysql['to'] . $click_filtered['filter'] . " group by landing_page_nickname" . $this->sortOrder() . $click_filtered['limit'];

        $click_result = _mysqli_query($click_sql); // ($click_sql);
        $i = 0;
        while ($click_row = $click_result->fetch_assoc()) {
            $i ++;
            // print_r($click_row);
            $data[] = $this->htmlFormat($click_row, $cpv);
            $totals['clicks'] = $totals['clicks'] + $click_row['clicks'];
            $totals['click_out'] = $totals['click_out'] + $click_row['click_out'];
            $totals['ctr'] = @round($totals['click_out'] / $totals['clicks'] * 100, 2);
            $totals['cost'] = $totals['cost'] + $click_row['cost'];
            $totals['cpc'] = @round($totals['cost'] / $totals['clicks'], 5);
            $totals['leads'] = $totals['leads'] + $click_row['leads'];
            $totals['su_ratio'] = @round($totals['leads'] / $totals['clicks'] * 100, 2);
            $totals['payout'] = @round(($totals['payout'] + $click_row['payout']) / $i, 2);
            $totals['income'] = $totals['income'] + $click_row['income'];
            $totals['epc'] = @round($totals['income'] / $totals['clicks'], 5);
            $totals['net'] = $totals['income'] - $totals['cost'];
           $totals['roi'] = ($totals['cost']=='0') ? 0 : @round($totals['net'] / $totals['cost'] * 100);
            // print_r($click_row);
        }
        $data[] = $this->htmlFormat($totals, $cpv, 'total');
        
        $count_sql = "select FOUND_ROWS() as found_rows"; // query to get the nymber of results
        $count_result = _mysqli_query($count_sql);
        $count_row = $count_result->fetch_assoc();
        self::$found_rows = ($count_row['found_rows']);
        
        return $data;
    }

    function doDeviceReport($clickFrom, $clickTo, $cpv)
    {
        $mysql['from'] = $clickFrom;
        $mysql['to'] = $clickTo;
        $up = new UserPrefs();
        $click_filtered = $this->getFilters();
        $click_sql = "
SELECT  SQL_CALC_FOUND_ROWS device_name,sum(clicks) as clicks, sum(click_out) as click_out,
	    (clicks/click_out)*100 as ctr,
	    SUM(leads) AS leads,
(SUM(click_lead)/sum(clicks))*100 as su_ratio,
(SUM(income) / sum(leads)) AS payout, 
SUM(2st.income) AS income,
SUM(2st.income)/sum(clicks) as epc,
SUM(2st.cost) AS cost,
SUM(2st.cost)/sum(clicks) AS cpc,
(SUM(2st.income)-SUM(2st.cost)) AS net,
((SUM(2st.income)-SUM(2st.cost))/SUM(2st.cost)*100 ) as roi FROM 202_dataengine as 2st " . $click_filtered['join'] . "  
LEFT JOIN 202_device_models on (2st.device_id = 202_device_models.device_id)
            " . $this->mysql['user_id_query'] . " AND click_time >= " . $mysql['from'] . " AND click_time <= " . $mysql['to'] . $click_filtered['filter'] . " group by device_name" . $this->sortOrder() . $click_filtered['limit'];

        $click_result = _mysqli_query($click_sql); // ($click_sql);
        $i = 0;
        while ($click_row = $click_result->fetch_assoc()) {
            $i ++;
            // print_r($click_row);
            $data[] = $this->htmlFormat($click_row, $cpv);
            $totals['clicks'] = $totals['clicks'] + $click_row['clicks'];
            $totals['click_out'] = $totals['click_out'] + $click_row['click_out'];
            $totals['ctr'] = @round($totals['click_out'] / $totals['clicks'] * 100, 2);
            $totals['cost'] = $totals['cost'] + $click_row['cost'];
            $totals['cpc'] = @round($totals['cost'] / $totals['clicks'], 5);
            $totals['leads'] = $totals['leads'] + $click_row['leads'];
            $totals['su_ratio'] = @round($totals['leads'] / $totals['clicks'] * 100, 2);
            $totals['payout'] = @round(($totals['payout'] + $click_row['payout']) / $i, 2);
            $totals['income'] = $totals['income'] + $click_row['income'];
            $totals['epc'] = @round($totals['income'] / $totals['clicks'], 5);
            $totals['net'] = $totals['income'] - $totals['cost'];
           $totals['roi'] = ($totals['cost']=='0') ? 0 : @round($totals['net'] / $totals['cost'] * 100);
            // print_r($click_row);
        }
        $data[] = $this->htmlFormat($totals, $cpv, 'total');
        
        $count_sql = "select FOUND_ROWS() as found_rows"; // query to get the nymber of results
        $count_result = _mysqli_query($count_sql);
        $count_row = $count_result->fetch_assoc();
        self::$found_rows = ($count_row['found_rows']);
        
        return $data;
    }

    function doBrowserReport($clickFrom, $clickTo, $cpv)
    {
        $mysql['from'] = $clickFrom;
        $mysql['to'] = $clickTo;
        $up = new UserPrefs();
        $click_filtered = $this->getFilters();
        $click_sql = "
SELECT  SQL_CALC_FOUND_ROWS browser_name,sum(clicks) as clicks, sum(click_out) as click_out,
	    (clicks/click_out)*100 as ctr,
	    SUM(leads) AS leads,
(SUM(click_lead)/sum(clicks))*100 as su_ratio,
(SUM(income) / sum(leads)) AS payout, 
SUM(2st.income) AS income,
SUM(2st.income)/sum(clicks) as epc,
SUM(2st.cost) AS cost,
SUM(2st.cost)/sum(clicks) AS cpc,
(SUM(2st.income)-SUM(2st.cost)) AS net,
((SUM(2st.income)-SUM(2st.cost))/SUM(2st.cost)*100 ) as roi FROM 202_dataengine as 2st " . $click_filtered['join'] . "  
LEFT JOIN 202_browsers on (2st.browser_id = 202_browsers.browser_id)
            " . $this->mysql['user_id_query'] . " AND click_time >= " . $mysql['from'] . " AND click_time <= " . $mysql['to'] . $click_filtered['filter'] . " group by browser_name" . $this->sortOrder() . $click_filtered['limit'];

        $click_result = _mysqli_query($click_sql); // ($click_sql);
        $i = 0;
        while ($click_row = $click_result->fetch_assoc()) {
            $i ++;
            // print_r($click_row);
            $data[] = $this->htmlFormat($click_row, $cpv);
            $totals['clicks'] = $totals['clicks'] + $click_row['clicks'];
            $totals['click_out'] = $totals['click_out'] + $click_row['click_out'];
            $totals['ctr'] = @round($totals['click_out'] / $totals['clicks'] * 100, 2);
            $totals['cost'] = $totals['cost'] + $click_row['cost'];
            $totals['cpc'] = @round($totals['cost'] / $totals['clicks'], 5);
            $totals['leads'] = $totals['leads'] + $click_row['leads'];
            $totals['su_ratio'] = @round($totals['leads'] / $totals['clicks'] * 100, 2);
            $totals['payout'] = @round(($totals['payout'] + $click_row['payout']) / $i, 2);
            $totals['income'] = $totals['income'] + $click_row['income'];
            $totals['epc'] = @round($totals['income'] / $totals['clicks'], 5);
            $totals['net'] = $totals['income'] - $totals['cost'];
           $totals['roi'] = ($totals['cost']=='0') ? 0 : @round($totals['net'] / $totals['cost'] * 100);
            // print_r($click_row);
        }
        $data[] = $this->htmlFormat($totals, $cpv, 'total');
        
        $count_sql = "select FOUND_ROWS() as found_rows"; // query to get the nymber of results
        $count_result = _mysqli_query($count_sql);
        $count_row = $count_result->fetch_assoc();
        self::$found_rows = ($count_row['found_rows']);
        return $data;
    }

    function doPlatformReport($clickFrom, $clickTo, $cpv)
    {
        $mysql['from'] = $clickFrom;
        $mysql['to'] = $clickTo;
        $up = new UserPrefs();
        $click_filtered = $this->getFilters();
        $click_sql = "
SELECT  SQL_CALC_FOUND_ROWS platform_name,sum(clicks) as clicks, sum(click_out) as click_out,
	    (clicks/click_out)*100 as ctr,
	    SUM(leads) AS leads,
(SUM(click_lead)/sum(clicks))*100 as su_ratio,
(SUM(income) / sum(leads)) AS payout, 
SUM(2st.income) AS income,
SUM(2st.income)/sum(clicks) as epc,
SUM(2st.cost) AS cost,
SUM(2st.cost)/sum(clicks) AS cpc,
(SUM(2st.income)-SUM(2st.cost)) AS net,
((SUM(2st.income)-SUM(2st.cost))/SUM(2st.cost)*100 ) as roi FROM 202_dataengine as 2st " . $click_filtered['join'] . "  
LEFT JOIN 202_platforms on (2st.platform_id = 202_platforms.platform_id)
            " . $this->mysql['user_id_query'] . " AND click_time >= " . $mysql['from'] . " AND click_time <= " . $mysql['to'] . $click_filtered['filter'] . " group by platform_name" . $this->sortOrder() . $click_filtered['limit'];

        $click_result = _mysqli_query($click_sql); // ($click_sql);
        $i = 0;
        while ($click_row = $click_result->fetch_assoc()) {
            $i ++;
            // print_r($click_row);
            $data[] = $this->htmlFormat($click_row, $cpv);
            $totals['clicks'] = $totals['clicks'] + $click_row['clicks'];
            $totals['click_out'] = $totals['click_out'] + $click_row['click_out'];
            $totals['ctr'] = @round($totals['click_out'] / $totals['clicks'] * 100, 2);
            $totals['cost'] = $totals['cost'] + $click_row['cost'];
            $totals['cpc'] = @round($totals['cost'] / $totals['clicks'], 5);
            $totals['leads'] = $totals['leads'] + $click_row['leads'];
            $totals['su_ratio'] = @round($totals['leads'] / $totals['clicks'] * 100, 2);
            $totals['payout'] = @round(($totals['payout'] + $click_row['payout']) / $i, 2);
            $totals['income'] = $totals['income'] + $click_row['income'];
            $totals['epc'] = @round($totals['income'] / $totals['clicks'], 5);
            $totals['net'] = $totals['income'] - $totals['cost'];
           $totals['roi'] = ($totals['cost']=='0') ? 0 : @round($totals['net'] / $totals['cost'] * 100);
            // print_r($click_row);
        }
        $data[] = $this->htmlFormat($totals, $cpv, 'total');
        
        $count_sql = "select FOUND_ROWS() as found_rows"; // query to get the nymber of results
        $count_result = _mysqli_query($count_sql);
        $count_row = $count_result->fetch_assoc();
        self::$found_rows = ($count_row['found_rows']);
        
        return $data;
    }

    function doVariableReport($clickFrom, $clickTo, $cpv)
    {
        $mysql['from'] = $clickFrom;
        $mysql['to'] = $clickTo;
        $up = new UserPrefs();
        $click_filtered = $this->getFilters();
        
        $click_sql = " SELECT 2st.user_id,
        2st.ppc_network_id,
        ppc_network_name,
        sum(clicks) as clicks,
        sum(click_out) as click_out,
        (sum(click_out)/sum(clicks))*100 as ctr,
        SUM(leads) AS leads,
        (SUM(click_lead)/sum(clicks))*100 as su_ratio,
        (SUM(income) / sum(leads)) AS payout, 
        SUM(2st.income) AS income,
        SUM(2st.income)/sum(clicks) as epc,
        SUM(2st.cost) AS cost,
        SUM(2st.cost)/sum(clicks) AS cpc,
        (SUM(2st.income)-SUM(2st.cost)) AS net,
        ((SUM(2st.income)-SUM(2st.cost))/SUM(2st.cost)*100 ) as roi,
        GROUP_CONCAT(DISTINCT(2st.variable_set_id)) as variable_set_ids
        FROM 202_dataengine as 2st
        JOIN 202_ppc_networks ON (202_ppc_networks.ppc_network_id = 2st.ppc_network_id)".
        $this->mysql['user_id_query']." AND 2st.variable_set_id != 0 AND click_time >= " . $mysql['from'] . " AND click_time <= " . $mysql['to'] . $click_filtered['filter'] . "
        group by 2st.user_id, 2st.ppc_network_id" .$click_filtered['limit'];
        $click_result = _mysqli_query($click_sql);
        
        //group by 2st.user_id, 2st.ppc_network_id" . $this->sortOrder() . $click_filtered['limit'];
        if ($click_result) {
            $i = 0;
            while ($click_row = $click_result->fetch_assoc()) {
                
                if($_SESSION['publisher']==true){
                if ($click_row['user_id'] == $this->mysql['user_id']) {
                    $i ++;
                    $totals['clicks'] = $totals['clicks'] + $click_row['clicks'];
                    $totals['click_out'] = $totals['click_out'] + $click_row['click_out'];
                    $totals['ctr'] = @round($totals['click_out'] / $totals['clicks'] * 100, 2);
                    $totals['cost'] = $totals['cost'] + $click_row['cost'];
                    $totals['cpc'] = @round($totals['cost'] / $totals['clicks'], 5);
                    $totals['leads'] = $totals['leads'] + $click_row['leads'];
                    $totals['su_ratio'] = @round($totals['leads'] / $totals['clicks'] * 100, 2);
                    $totals['payout'] = @round(($totals['payout'] + $click_row['payout']) / $i, 2);
                    $totals['income'] = $totals['income'] + $click_row['income'];
                    $totals['epc'] = @round($totals['income'] / $totals['clicks'], 5);
                    $totals['net'] = $totals['income'] - $totals['cost'];
                   $totals['roi'] = ($totals['cost']=='0') ? 0 : @round($totals['net'] / $totals['cost'] * 100);
                }}
                else{
                    $i ++;
                    $totals['clicks'] = $totals['clicks'] + $click_row['clicks'];
                    $totals['click_out'] = $totals['click_out'] + $click_row['click_out'];
                    $totals['ctr'] = @round($totals['click_out'] / $totals['clicks'] * 100, 2);
                    $totals['cost'] = $totals['cost'] + $click_row['cost'];
                    $totals['cpc'] = @round($totals['cost'] / $totals['clicks'], 5);
                    $totals['leads'] = $totals['leads'] + $click_row['leads'];
                    $totals['su_ratio'] = @round($totals['leads'] / $totals['clicks'] * 100, 2);
                    $totals['payout'] = @round(($totals['payout'] + $click_row['payout']) / $i, 2);
                    $totals['income'] = $totals['income'] + $click_row['income'];
                    $totals['epc'] = @round($totals['income'] / $totals['clicks'], 5);
                    $totals['net'] = $totals['income'] - $totals['cost'];
                    $totals['roi'] = ($totals['cost']=='0') ? 0 : @round($totals['net'] / $totals['cost'] * 100);
                }
            }
            $data[] = $this->htmlFormat($totals, $cpv, 'total');
        }
        $click_sql = " SELECT
            2st.user_id, 
    ppc_network_name,
    name as variable_name,
    variable as variable_value,
    sum(clicks) as clicks,
    sum(click_out) as click_out,
    (sum(click_out) / sum(clicks)) * 100 as ctr,
    SUM(leads) AS leads,
    (SUM(click_lead) / sum(clicks)) * 100 as su_ratio,
    (SUM(income) / sum(leads)) AS payout, 
    SUM(2st.income) AS income,
    SUM(2st.income) / sum(clicks) as epc,
    SUM(2st.cost) AS cost,
    SUM(2st.cost) / sum(clicks) AS cpc,
    (SUM(2st.income) - SUM(2st.cost)) AS net,
    ((SUM(2st.income) - SUM(2st.cost)) / SUM(2st.cost) * 100) as roi,
    2st.ppc_network_id,
    2st.variable_set_id,
    variables,
    202_custom_variables.ppc_variable_id
FROM
    202_dataengine as 2st
        JOIN
    202_variable_sets2 USING (variable_set_id)
        JOIN
    202_custom_variables ON (202_custom_variables.custom_variable_id = 202_variable_sets2.variables)
        JOIN
    202_ppc_network_variables ON (202_custom_variables.ppc_variable_id = 202_ppc_network_variables.ppc_variable_id)
        JOIN
    202_ppc_networks ON (202_ppc_networks.ppc_network_id = 2st.ppc_network_id)
".$this->mysql['user_id_query']."
        AND 2st.variable_set_id != 0
        AND click_time >= " . $mysql['from'] . " AND click_time <= " . $mysql['to'] . $click_filtered['filter'] . "
group by ppc_network_id , name , variable
ORDER BY ppc_network_id , name , variable";


        
        $click_result = _mysqli_query($click_sql);
        // Loop Traffic Sources
        if ($click_result) {
            while ($click_row = $click_result->fetch_assoc()) {
                $data[$click_row['ppc_network_id']][] = $this->htmlFormat($click_row, $cpv);
                $data[$click_row['ppc_network_id']]['variables'][$click_row['ppc_variable_id']][] = $this->htmlFormat($click_row, $cpv);
                $data[$click_row['ppc_network_id']]['variables'][$click_row['ppc_variable_id']]['values'][] = $this->htmlFormat($click_row, $cpv);
            }
        }
        
        $data[] = $this->htmlFormat($totals, $cpv, 'total');
        return $data;
    }

    function htmlFormat($click_row, $cpv, $type = '', $mainKey = '')
    {

        $currency_result = self::$db->query("SELECT user_account_currency FROM 202_users_pref WHERE user_id = '" . $this->mysql['user_id'] . "'");
        $currency_row = $currency_result->fetch_assoc();
        $currency_row['user_account_currency'];
        
        $prepend = '';
        $theCTR = @round($click_row['click_out'] / $click_row['clicks'] * 100, 2);
        if ($type == 'total')
            $prepend = "total_";
        if ($click_row) {
            foreach ($click_row as $key => $data_row) {
                switch ($key) {
                    case 'clicks':
                    case 'leads':
                    case 'click_out':
                        $html[$prepend . $key] = htmlentities(number_format($data_row), ENT_QUOTES, 'UTF-8');
                        break;
                    case 'su_ratio':
                        
                        $html[$prepend . $key] = htmlentities(round($data_row, 2) . '%', ENT_QUOTES, 'UTF-8');
                        break;
                    
                    case 'ctr':
                        $html[$prepend . $key] = htmlentities(round($theCTR, 2) . '%', ENT_QUOTES, 'UTF-8');
                        break;
                    case 'payout':
                    case 'income':
                        $html[$prepend . $key] = htmlentities(dollar_format($data_row, $currency_row['user_account_currency']), ENT_QUOTES, 'UTF-8');
                        break;
                    case 'epc':
                    case 'cpc':
                    case 'cost':
                    case 'net':
                        $html[$prepend . $key] = htmlentities(dollar_format($data_row, $currency_row['user_account_currency'], $cpv), ENT_QUOTES, 'UTF-8');
                        break;
                    case 'roi':
                        $html[$prepend . $key] = htmlentities(number_format($data_row) . '%', ENT_QUOTES, 'UTF-8');
                        break;
                    case 'click_time_from_disp':
                        $upper = array(
                            'AM',
                            'PM'
                        );
                        $lower = array(
                            'am',
                            'pm'
                        );
                        $html[$prepend . $key] = htmlentities(str_replace($upper, $lower, $data_row), ENT_QUOTES, 'UTF-8');
                        break;
                    case 'keyword':
                        if (! $data_row)
                            $html[$prepend . $key] = htmlentities('[no keyword]', ENT_QUOTES, 'UTF-8');
                        else
                            $html[$prepend . $key] = htmlentities($data_row, ENT_QUOTES, 'UTF-8');
                        break;
                    case 'text_ad_name':
                        if (! $data_row)
                            $html[$prepend . $key] = htmlentities('[no text ad]', ENT_QUOTES, 'UTF-8');
                        else
                            $html[$prepend . $key] = htmlentities($data_row, ENT_QUOTES, 'UTF-8');
                        break;
                    case 'referer_name':
                        if (! $data_row)
                            $html[$prepend . $key] = htmlentities('[no referer]', ENT_QUOTES, 'UTF-8');
                        else
                            $html[$prepend . $key] = htmlentities($data_row, ENT_QUOTES, 'UTF-8');
                        break;
                    case 'ip_address':
                        if (! $data_row)
                            $html[$prepend . $key] = htmlentities('[no ip]', ENT_QUOTES, 'UTF-8');
                        else {
                            if (ctype_print($data_row)) { // non binary value so just return
                                $html[$prepend . $key] = htmlentities($data_row, ENT_QUOTES, 'UTF-8');
                            } else { // binary value so decode and return
                                $html[$prepend . $key] = htmlentities(inet6_ntoa($data_row), ENT_QUOTES, 'UTF-8'); // decode ipv6 for display
                            }
                        }
                        break;
                    case 'country_name':
                        if (! $data_row)
                            $html[$prepend . $key] = htmlentities('[no country]', ENT_QUOTES, 'UTF-8');
                        else
                            $html[$prepend . $key] = htmlentities($data_row, ENT_QUOTES, 'UTF-8');
                        break;
                    case 'region_name':
                        if (! $data_row)
                            $html[$prepend . $key] = htmlentities('[no region]', ENT_QUOTES, 'UTF-8');
                        else
                            $html[$prepend . $key] = htmlentities($data_row, ENT_QUOTES, 'UTF-8');
                        break;
                    case 'city_name':
                        if (! $data_row)
                            $html[$prepend . $key] = htmlentities('[no city]', ENT_QUOTES, 'UTF-8');
                        else
                            $html[$prepend . $key] = htmlentities($data_row, ENT_QUOTES, 'UTF-8');
                        break;
                    case 'country_code':
                        if (! $data_row)
                            $html[$prepend . $key] = htmlentities('non', ENT_QUOTES, 'UTF-8');
                        else
                            $html[$prepend . $key] = htmlentities($data_row, ENT_QUOTES, 'UTF-8');
                        break;
                    case 'isp_name':
                        if (! $data_row)
                            $html[$prepend . $key] = htmlentities('[no isp]', ENT_QUOTES, 'UTF-8');
                        else
                            $html[$prepend . $key] = htmlentities($data_row, ENT_QUOTES, 'UTF-8');
                        break;
                    case 'landing_page_nickname':
                        if (! $data_row)
                            $html[$prepend . $key] = htmlentities('[direct link]', ENT_QUOTES, 'UTF-8');
                        else
                            $html[$prepend . $key] = htmlentities($data_row, ENT_QUOTES, 'UTF-8');
                        break;
                    
                    case 'device':
                        if (! $data_row)
                            $html[$prepend . $key] = htmlentities('[no device]', ENT_QUOTES, 'UTF-8');
                        else
                            $html[$prepend . $key] = htmlentities($data_row, ENT_QUOTES, 'UTF-8');
                        break;
                    case 'browser':
                        if (! $data_row)
                            $html[$prepend . $key] = htmlentities('[no browser]', ENT_QUOTES, 'UTF-8');
                        else
                            $html[$prepend . $key] = htmlentities($data_row, ENT_QUOTES, 'UTF-8');
                        break;
                    case 'platform':
                        if (! $data_row)
                            $html[$prepend . $key] = htmlentities('[no platform]', ENT_QUOTES, 'UTF-8');
                        else
                            $html[$prepend . $key] = htmlentities($data_row, ENT_QUOTES, 'UTF-8');
                        break;
                    default:
                        $html[$prepend . $key] = htmlentities($data_row, ENT_QUOTES, 'UTF-8');
                }
            }
        }

        if (strlen($html['aff_campaign_name']) == 0){
            $html['aff_campaign_name'] = "[Landing Page/Smart Redirector Campaign]";
            if($mainKey='overview')
                $html['aff_campaign_name'] = "[Landing Page/Smart Redirector Campaign]";
        }
        
        $stop = array(
            'total_clicks',
            'total_click_out',
            'total_ctr',
            'total_cost',
            'total_cpc',
            'total_leads',
            'total_su_ratio',
            'total_payout',
            'total_income',
            'total_epc',
            'total_net',
            'total_roi'
        );
        foreach ($stop as $key) {
            
            if ($html[$key] == '') {
                $html[$key] = '0';
            }
        }

        return ($html);
    }

    function sortOrder($order='')
    {

        // run the order by settings
        if(isset($order)){
            $_POST['order'] = htmlentities($order, ENT_QUOTES, 'UTF-8');
        }
        
        $html['order'] = htmlentities($_POST['order'], ENT_QUOTES, 'UTF-8');
        
        
        
        $html['sort_breakdown_time_order'] = 'sort_breakdown_time_order asc';
        if ($_POST['order'] == 'sort_breakdown_time_order desc') {
            $html['sort_breakdown_time_order'] = 'sort_breakdown_time_order desc';
            $mysql['order'] = 'ORDER BY click_time DESC';
        } elseif ($_POST['order'] == 'sort_breakdown_time_order asc') {
            $html['sort_breakdown_time_order'] = 'sort_breakdown_time_order asc';
            $mysql['order'] = 'ORDER BY click_time ASC';
        }
        
        $html['sort_breakdown_order'] = 'breakdown asc';
        if ($_POST['order'] == 'breakdown desc') {
            $html['sort_breakdown_order'] = 'breakdown desc';
            $mysql['order'] = 'ORDER BY cast(DATE_FORMAT(FROM_UNIXTIME(click_time),"%k") as UNSIGNED) DESC';
        } elseif ($_POST['order'] == 'breakdown asc') {
            $html['sort_breakdown_order'] = 'breakdown asc';
            $mysql['order'] = 'ORDER BY cast(DATE_FORMAT(FROM_UNIXTIME(click_time),"%k") as UNSIGNED) ASC';
        }
        
        $html['sort_breakdown_clicks_order'] = 'sort_breakdown_clicks asc';
        if ($_POST['order'] == 'sort_breakdown_clicks asc') {
            $html['sort_breakdown_clicks_order'] = 'sort_breakdown_clicks desc';
            $mysql['order'] = 'ORDER BY `clicks` DESC';
        } elseif ($_POST['order'] == 'sort_breakdown_clicks desc') {
            $html['sort_breakdown_clicks_order'] = 'sort_breakdown_clicks asc';
            $mysql['order'] = 'ORDER BY `clicks` ASC';
        }
        
        $html['sort_breakdown_click_throughs_order'] = 'sort_breakdown_click_throughs asc';
        if ($_POST['order'] == 'sort_breakdown_click_throughs asc') {
            $html['sort_breakdown_click_throughs_order'] = 'sort_breakdown_click_throughs desc';
            $mysql['order'] = 'ORDER BY `click_out` DESC';
        } elseif ($_POST['order'] == 'sort_breakdown_click_throughs desc') {
            $html['sort_breakdown_click_throughs_order'] = 'sort_breakdown_click_throughs asc';
            $mysql['order'] = 'ORDER BY `click_out` ASC';
        }
        
        $html['sort_breakdown_ctr_order'] = 'sort_breakdown_ctr asc';
        if ($_POST['order'] == 'sort_breakdown_ctr asc') {
            $html['sort_breakdown_ctr_order'] = 'sort_breakdown_ctr desc';
            $mysql['order'] = 'ORDER BY `ctr` DESC';
        } elseif ($_POST['order'] == 'sort_breakdown_ctr desc') {
            $html['sort_breakdown_ctr_order'] = 'sort_breakdown_ctr asc';
            $mysql['order'] = 'ORDER BY `ctr` ASC';
        }
        
        $html['sort_breakdown_leads_order'] = 'sort_breakdown_leads asc';
        if ($_POST['order'] == 'sort_breakdown_leads asc') {
            $html['sort_breakdown_leads_order'] = 'sort_breakdown_leads desc';
            $mysql['order'] = 'ORDER BY `leads` DESC';
        } elseif ($_POST['order'] == 'sort_breakdown_leads desc') {
            $html['sort_breakdown_leads_order'] = 'sort_breakdown_leads asc';
            $mysql['order'] = 'ORDER BY `leads` ASC';
        }
        
        $html['sort_breakdown_su_ratio_order'] = 'sort_breakdown_su_ratio asc';
        if ($_POST['order'] == 'sort_breakdown_su_ratio asc') {
            $html['sort_breakdown_su_ratio_order'] = 'sort_breakdown_su_ratio desc';
            $mysql['order'] = 'ORDER BY `su` DESC';
        } elseif ($_POST['order'] == 'sort_breakdown_su_ratio desc') {
            $html['sort_breakdown_su_ratio_order'] = 'sort_breakdown_su_ratio asc';
            $mysql['order'] = 'ORDER BY `su` ASC';
        }
        
        $html['sort_breakdown_payout_order'] = 'sort_breakdown_payout asc';
        if ($_POST['order'] == 'sort_breakdown_payout asc') {
            $html['sort_breakdown_payout_order'] = 'sort_breakdown_payout desc';
            $mysql['order'] = 'ORDER BY `payout` DESC';
        } elseif ($_POST['order'] == 'sort_breakdown_payout desc') {
            $html['sort_breakdown_payout_order'] = 'sort_breakdown_payout asc';
            $mysql['order'] = 'ORDER BY `payout` ASC';
        }
        
        $html['sort_breakdown_epc_order'] = 'sort_breakdown_epc asc';
        if ($_POST['order'] == 'sort_breakdown_epc asc') {
            $html['sort_breakdown_epc_order'] = 'sort_breakdown_epc desc';
            $mysql['order'] = 'ORDER BY `epc` DESC';
        } elseif ($_POST['order'] == 'sort_breakdown_epc desc') {
            $html['sort_breakdown_epc_order'] = 'sort_breakdown_epc asc';
            $mysql['order'] = 'ORDER BY `epc` ASC';
        }
        
        $html['sort_breakdown_cpc_order'] = 'sort_breakdown_cpc asc';
        if ($_POST['order'] == 'sort_breakdown_cpc asc') {
            $html['sort_breakdown_cpc_order'] = 'sort_breakdown_cpc desc';
            $mysql['order'] = 'ORDER BY `cpc` DESC';
        } elseif ($_POST['order'] == 'sort_breakdown_cpc desc') {
            $html['sort_breakdown_cpc_order'] = 'sort_breakdown_cpc asc';
            $mysql['order'] = 'ORDER BY `cpc` ASC';
        }
        
        $html['sort_breakdown_income_order'] = 'sort_breakdown_income asc';
        if ($_POST['order'] == 'sort_breakdown_income asc') {
            $html['sort_breakdown_income_order'] = 'sort_breakdown_income desc';
            $mysql['order'] = 'ORDER BY `income` DESC';
        } elseif ($_POST['order'] == 'sort_breakdown_income desc') {
            $html['sort_breakdown_income_order'] = 'sort_breakdown_income asc';
            $mysql['order'] = 'ORDER BY `income` ASC';
        }
        
        $html['sort_breakdown_cost_order'] = 'sort_breakdown_cost asc';
        if ($_POST['order'] == 'sort_breakdown_cost asc') {
            $html['sort_breakdown_cost_order'] = 'sort_breakdown_cost desc';
            $mysql['order'] = 'ORDER BY `cost` DESC';
        } elseif ($_POST['order'] == 'sort_breakdown_cost desc') {
            $html['sort_breakdown_cost_order'] = 'sort_breakdown_cost asc';
            $mysql['order'] = 'ORDER BY `cost` ASC';
        }
        
        $html['sort_breakdown_net_order'] = 'sort_breakdown_net asc';
        if ($_POST['order'] == 'sort_breakdown_net asc') {
            $html['sort_breakdown_net_order'] = 'sort_breakdown_net desc';
            $mysql['order'] = 'ORDER BY `net` DESC';
        } elseif ($_POST['order'] == 'sort_breakdown_net desc') {
            $html['sort_breakdown_net_order'] = 'sort_breakdown_net asc';
            $mysql['order'] = 'ORDER BY `net` ASC';
        }
        
        $html['sort_breakdown_roi_order'] = 'sort_breakdown_roi asc';
        if ($_POST['order'] == 'sort_breakdown_roi asc') {
            $html['sort_breakdown_roi_order'] = 'sort_breakdown_roi desc';
            $mysql['order'] = 'ORDER BY `roi` DESC';
        } elseif ($_POST['order'] == 'sort_breakdown_roi desc') {
            $html['sort_breakdown_roi_order'] = 'sort_breakdown_roi asc';
            $mysql['order'] = 'ORDER BY `roi` ASC';
        }
        
        if (empty($mysql['order'])) {
            $mysql['order'] = ' ORDER BY leads DESC';
        }
        return ($mysql['order']);
    }
    
    // dirty hours by clicks id: This function marks the hour range that the click happened in for updating reports
    function setDirtyHour($click_id)
    {
        global $ip_address,$db, $dbGlobalLink;
        if (! isset($click_id) || $click_id == '') { // if not find the list clicks id of the ip within a 30 day range
            $mysql['user_id'] = 1;
            $mysql['ip_address'] = $db->real_escape_string($ip_address->address);
            
            if($inet6_ntoa == '' && $ip_address->type == 'ipv6'){
                $mysql['ip_address'] = inet6_aton($mysql['ip_address']); //encode for db check
            }
            
            $daysago = time() - 86400; // 24 hours
            $click_sql1 = 'SELECT  202_clicks.click_id
                           FROM            202_clicks
                           LEFT JOIN       202_clicks_advance USING (click_id)
                           LEFT JOIN       202_ips USING (ip_id)
                           LEFT JOIN       202_ips_v6 ON (202_ips_v6.ip_id = 202_ips.ip_address COLLATE utf8mb4_general_ci)
                           WHERE           IFNULL('.$inet6_ntoa.'(202_ips_v6.ip_address),202_ips.ip_address)="'.$mysql['ip_address'].'"
                           AND             202_clicks.user_id="' . $mysql['user_id'] . '"
                           AND             202_clicks.click_time >= "' . $daysago . '"
                           ORDER BY        202_clicks.click_id DESC
                           LIMIT           1';
            
            $click_result1 = $db->query($click_sql1) or record_mysql_error($click_sql1);
            $click_row1 = $click_result1->fetch_assoc();
            // empy $mysql array
            unset($mysql);
            $mysql['click_id'] = $db->real_escape_string($click_row1['click_id']);
            $click_id = $mysql['click_id'];
            // $mysql['ppc_account_id'] = $db->real_escape_string($click_row1['ppc_account_id']);
        }
        
        if (! isset($click_id) || $click_id == '') {
            return false;
        }
        
        $dsql = " insert into 202_dataengine(user_id,
click_id,
click_time,
ppc_network_id,
ppc_account_id,
aff_network_id,
aff_campaign_id,
landing_page_id,
keyword_id,
utm_source_id,
utm_medium_id,
utm_campaign_id,
utm_term_id,
utm_content_id,
text_ad_id,
click_referer_site_url_id,
country_id,
region_id,
city_id,
isp_id,
browser_id,
device_id,
platform_id,
ip_id,
c1_id,
c2_id,
c3_id,
c4_id,
variable_set_id,
rotator_id,
rule_id,
rule_redirect_id,
click_lead,
click_filtered,
click_bot,
click_alp,
clicks,
click_out,
leads,
payout,
income,
cost)  
    SELECT 
2c.user_id,
2c.click_id,
2c.click_time,
2pn.ppc_network_id, 
2c.ppc_account_id,
2an.aff_network_id,
2ac.aff_campaign_id,
2c.landing_page_id,
2k.keyword_id,
2gg.utm_source_id,
2gg.utm_medium_id,
2gg.utm_campaign_id,
2gg.utm_term_id,
2gg.utm_content_id,
2ta.text_ad_id,
2cs.click_referer_site_url_id,
2cy.country_id,
2rg.region_id,
2ci.city_id,
2is.isp_id,
2b.browser_id,
2dm.device_id,
2p.platform_id,
2ca.ip_id,
2tc1.c1_id,
2tc2.c2_id,
2tc3.c3_id,
2tc4.c4_id,
2cv.variable_set_id,
2rc.rotator_id,
2rc.rule_id,
2rc.rule_redirect_id,
2c.`click_lead`,
2c.`click_filtered`,
2c.`click_bot`,
2c.`click_alp`, 
1 AS clicks, 
2cr.click_out AS click_out, 
2c.click_lead AS leads, 
2c.click_payout AS payout, 
IF (2c.click_lead>0,2c.click_payout,0) AS income, 
2c.click_cpc AS cost 
FROM 202_clicks AS 2c 
LEFT OUTER JOIN 202_clicks_record AS 2cr ON (2c.click_id = 2cr.click_id) 
LEFT OUTER JOIN 202_aff_campaigns AS 2ac ON (2c.aff_campaign_id = 2ac.aff_campaign_id) 
LEFT OUTER JOIN 202_clicks_advance AS 2ca ON (2c.click_id = 2ca.click_id) 
LEFT OUTER JOIN 202_browsers AS 2b ON (2ca.browser_id = 2b.browser_id) 
LEFT OUTER JOIN 202_platforms AS 2p ON (2ca.platform_id = 2p.platform_id) 
LEFT OUTER JOIN 202_aff_networks AS 2an ON (2ac.aff_network_id = 2an.aff_network_id) 
LEFT OUTER JOIN 202_ppc_accounts AS 2pa ON (2c.ppc_account_id = 2pa.ppc_account_id) 
LEFT OUTER JOIN 202_ppc_networks AS 2pn ON (2pa.ppc_network_id = 2pn.ppc_network_id)
LEFT OUTER JOIN 202_keywords AS 2k ON (2ca.keyword_id = 2k.keyword_id)
LEFT OUTER JOIN 202_google AS 2gg ON (2c.click_id = 2gg.click_id)
LEFT OUTER JOIN 202_landing_pages AS 2lp ON (2c.landing_page_id = 2lp.landing_page_id)
LEFT OUTER JOIN 202_text_ads AS 2ta ON (2ca.text_ad_id = 2ta.text_ad_id)
LEFT OUTER JOIN 202_clicks_site AS 2cs ON (2c.click_id = 2cs.click_id)
LEFT OUTER JOIN 202_clicks_tracking AS 2ct ON (2c.click_id = 2ct.click_id)
LEFT OUTER JOIN 202_site_urls AS 2suf ON (2cs.click_referer_site_url_id = 2suf.site_url_id) 
LEFT OUTER JOIN 202_locations_country AS 2cy ON (2ca.country_id = 2cy.country_id) 
LEFT OUTER JOIN 202_locations_region AS 2rg ON (2ca.region_id = 2rg.region_id) 
LEFT OUTER JOIN 202_locations_city AS 2ci ON (2ca.city_id = 2ci.city_id)
LEFT OUTER JOIN 202_locations_isp AS 2is ON (2ca.isp_id = 2is.isp_id) 
LEFT OUTER JOIN 202_device_models AS 2dm ON (2ca.device_id = 2dm.device_id)
LEFT OUTER JOIN 202_ips AS 2i ON (2ca.ip_id = 2i.ip_id)
LEFT OUTER JOIN 202_tracking_c1 AS 2tc1 ON (2ct.c1_id = 2tc1.c1_id) 
LEFT OUTER JOIN 202_tracking_c2 AS 2tc2 ON (2ct.c2_id = 2tc2.c2_id) 
LEFT OUTER JOIN 202_tracking_c3 AS 2tc3 ON (2ct.c3_id = 2tc3.c3_id) 
LEFT OUTER JOIN 202_tracking_c4 AS 2tc4 ON (2ct.c4_id = 2tc4.c4_id)
LEFT OUTER JOIN 202_clicks_variable AS 2cv ON (2c.click_id = 2cv.click_id)
LEFT OUTER JOIN 202_clicks_rotator AS 2rc ON (2c.click_id = 2rc.click_id)
WHERE 2c.click_id=" . $click_id . "
on duplicate key update 
click_lead=values(click_lead),
click_bot=values(click_bot),
click_out=values(click_out),
click_filtered=values(click_filtered),    
leads=values(leads),
payout=values(payout),
income=values(income),
cost=values(cost),
rotator_id=values(rotator_id),
rule_id=values(rule_id),
rule_redirect_id=values(rule_redirect_id),
aff_campaign_id=values(aff_campaign_id),
aff_network_id=values(aff_network_id)";
        
        $result = $db->query($dsql);
    }

    function processDirtyHours()
    {
        global $db, $dbGlobalLink;
        $time_start = microtime(true);
        set_time_limit(0);
        
        $delayed_sql = "
			SELECT *
			FROM 202_dirty_hours
			where processed != 1
            ";
        
        $delayed_result = self::$db->query($delayed_sql);
        if (! $delayed_result)
            exit();
        while ($delayed_row = $delayed_result->fetch_assoc()) {
            $mysql['ppc_account_id'] = self::$db->real_escape_string($delayed_row['ppc_account_id']);
            $mysql['aff_campaign_id'] = self::$db->real_escape_string($delayed_row['aff_campaign_id']);
            $mysql['user_id'] = self::$db->real_escape_string($delayed_row['user_id']);
            $mysql['click_time_from'] = self::$db->real_escape_string($delayed_row['click_time_from']);
            $mysql['click_time_to'] = self::$db->real_escape_string($delayed_row['click_time_to']);
            $mysql['ppc_network_id'] = self::$db->real_escape_string($delayed_row['ppc_network_id']);
            $mysql['aff_network_id'] = self::$db->real_escape_string($delayed_row['aff_network_id']);
            $mysql['landing_page_id'] = self::$db->real_escape_string($delayed_row['landing_page_id']);
            $mysql['keyword_id'] = self::$db->real_escape_string($delayed_row['keyword_id']);
            $mysql['utm_source_id'] = self::$db->real_escape_string($delayed_row['utm_source_id']);
            $mysql['utm_medium_id'] = self::$db->real_escape_string($delayed_row['utm_medium_id']);
            $mysql['utm_campaign_id'] = self::$db->real_escape_string($delayed_row['utm_campaign_id']);
            $mysql['utm_term_id'] = self::$db->real_escape_string($delayed_row['utm_term_id']);
            $mysql['utm_content_id'] = self::$db->real_escape_string($delayed_row['utm_content_id']);
            $mysql['text_ad_id'] = self::$db->real_escape_string($delayed_row['text_ad_id']);
            $mysql['click_referer_site_url_id'] = self::$db->real_escape_string($delayed_row['click_referer_site_url_id']);
            $mysql['country_id'] = self::$db->real_escape_string($delayed_row['country_id']);
            $mysql['region_id'] = self::$db->real_escape_string($delayed_row['region_id']);
            $mysql['city_id'] = self::$db->real_escape_string($delayed_row['city_id']);
            $mysql['isp_id'] = self::$db->real_escape_string($delayed_row['isp_id']);
            $mysql['browser_id'] = self::$db->real_escape_string($delayed_row['browser_id']);
            $mysql['device_id'] = self::$db->real_escape_string($delayed_row['device_id']);
            $mysql['platform_id'] = self::$db->real_escape_string($delayed_row['platform_id']);
            $mysql['ip_id'] = self::$db->real_escape_string($delayed_row['ip_id']);
            $mysql['c1_id'] = self::$db->real_escape_string($delayed_row['c1_id']);
            $mysql['c2_id'] = self::$db->real_escape_string($delayed_row['c2_id']);
            $mysql['c3_id'] = self::$db->real_escape_string($delayed_row['c3_id']);
            $mysql['c4_id'] = self::$db->real_escape_string($delayed_row['c4_id']);
            $mysql['variable_set_id'] = self::$db->real_escape_string($delayed_row['variable_set_id']);
            $mysql['click_filtered'] = self::$db->real_escape_string($delayed_row['click_filtered']);
            $mysql['click_bot'] = self::$db->real_escape_string($delayed_row['click_bot']);
            $mysql['click_alp'] = self::$db->real_escape_string($delayed_row['click_alp']);
            
            $snippet = "AND 2c.user_id = " . $mysql['user_id'];
            $d_snippet = "";
            
            if ($mysql['ppc_account_id']) {
                $snippet .= " AND 2c.ppc_account_id =" . $mysql['ppc_account_id'];
            }
            
            if ($mysql['aff_campaign_id']) {
                $snippet .= " AND 2ac.aff_campaign_id =" . $mysql['aff_campaign_id'];
            }
            
            if ($mysql['ppc_network_id']) {
                $d_snippet .= " AND 2pn.ppc_network_id =" . $mysql['ppc_network_id'];
            }
            
            if ($mysql['aff_network_id']) {
                $d_snippet .= " AND 2an.aff_network_id =" . $mysql['aff_network_id'];
            }
            
            if ($mysql['landing_page_id']) {
                $d_snippet .= " AND 2c.landing_page_id =" . $mysql['landing_page_id'];
            }
            
            if ($mysql['keyword_id']) {
                $d_snippet .= " AND 2k.keyword_id =" . $mysql['keyword_id'];
            }
            
            if ($mysql['utm_source_id']) {
                $d_snippet .= " AND 2gg.utm_source_id =" . $mysql['utm_source_id'];
            }
            
            if ($mysql['utm_medium_id']) {
                $d_snippet .= " AND 2gg.utm_medium_id =" . $mysql['utm_medium_id'];
            }
            
            if ($mysql['utm_campaign_id']) {
                $d_snippet .= " AND 2gg.utm_campaign_id =" . $mysql['utm_campaign_id'];
            }
            
            if ($mysql['utm_term_id']) {
                $d_snippet .= " AND 2gg.utm_term_id =" . $mysql['utm_term_id'];
            }
            
            if ($mysql['utm_content_id']) {
                $d_snippet .= " AND 2gg.utm_content_id =" . $mysql['utm_content_id'];
            }
            
            if ($mysql['text_ad_id']) {
                $d_snippet .= " AND 2ta.text_ad_id =" . $mysql['text_ad_id'];
            }
            
            if ($mysql['click_referer_site_url_id']) {
                $d_snippet .= " AND 2cs.click_referer_site_url_id =" . $mysql['click_referer_site_url_id'];
            }
            
            if ($mysql['country_id']) {
                $d_snippet .= " AND 2cy.country_id =" . $mysql['country_id'];
            }
            
            if ($mysql['region_id']) {
                $d_snippet .= " AND 2rg.region_id =" . $mysql['region_id'];
            }
            
            if ($mysql['city_id']) {
                $d_snippet .= " AND 2ci.city_id =" . $mysql['city_id'];
            }
            
            if ($mysql['isp_id']) {
                $d_snippet .= " AND 2is.isp_id =" . $mysql['isp_id'];
            }
            
            if ($mysql['browser_id']) {
                $d_snippet .= " AND 2b.browser_id =" . $mysql['browser_id'];
            }
            
            if ($mysql['device_id']) {
                $d_snippet .= " AND 2dm.device_id =" . $mysql['device_id'];
            }
            
            if ($mysql['platform_id']) {
                $d_snippet .= " AND 2p.platform_id =" . $mysql['platform_id'];
            }
            
            if ($mysql['ip_id']) {
                $d_snippet .= " AND 2ca.ip_id =" . $mysql['ip_id'];
            }
            
            if ($mysql['c1_id']) {
                $d_snippet .= " AND 2tc1.c1_id =" . $mysql['c1_id'];
            }
            
            if ($mysql['c2_id']) {
                $d_snippet .= " AND 2tc2.c2_id =" . $mysql['c2_id'];
            }
            
            if ($mysql['c3_id']) {
                $d_snippet .= " AND 2tc3.c3_id =" . $mysql['c3_id'];
            }
            
            if ($mysql['c4_id']) {
                $d_snippet .= " AND 2tc4.c4_id =" . $mysql['c4_id'];
            }
            
            if ($mysql['variable_set_id']) {
                $d_snippet .= " AND 2cv.variable_set_id =" . $mysql['variable_set_id'];
            }
            
            if ($mysql['click_filtered']) {
                $d_snippet .= " AND 2c.click_filtered =" . $mysql['click_filtered'];
            }
            
            if ($mysql['click_bot']) {
                $d_snippet .= " AND 2c.click_bot =" . $mysql['click_bot'];
            }
            
            if ($mysql['click_alp']) {
                $d_snippet .= " AND 2c.click_alp =" . $mysql['click_alp'];
            }
            
            // $query="DELETE FROM 202_dataengine WHERE click_time >= " . $mysql['click_time_from'] . " AND click_time <= " . $mysql['click_time_to'] . " " . $d_snippet;
            
            // $remove = array("2ac.", "2c.", "2cv.", "2tc4.", "2tc3.", "2tc2.", "2tc1.", "2ca.", "2p.", "2dm.", "2b.", "2is.", "2ci.", "2rg.", "2cy.", "2cs.", "2ta.", "2gg.", "2k.", "2an.", "2pn.");
            // $query = str_replace($remove, "", $query);
            // self::$db->query($query) or die(self::$db->error . '<br/><br/>' . $query);
            
            $this->getSummary($mysql['click_time_from'], $mysql['click_time_to'], $snippet);
            $sql = "
			UPDATE
			202_dirty_hours
			set processed='1'
				where id=
		" . $delayed_row['id'];

            $result = self::$db->query($sql);
            
            // $update_sql = $delayed_row['delayed_sql'];
            // echo $update_sql."<br>";
            // $update_result = _mysql_query($update_sql);
            // echo $update_result;
            

            
            $sql = "
			UPDATE
			202_dirty_hours
			set deleted='1'
        			where id=
        			    " . $delayed_row['id'];

            $result = self::$db->query($sql);
            flush();
        }
        
        $sql = "
			UPDATE
            202_dirty_hours
            set deleted='1'
            where processed=1";

        // $result = _mysql_query($sql);
        
        $sql = "	DELETE
            FROM 202_dirty_hours
            where deleted=1";

        $result = self::$db->query($sql);
        
        $time_end = microtime(true);
        
        // dividing with 60 will give the execution time in minutes other wise seconds
        $execution_time = ($time_end - $time_start);
        
        // execution time of the script
        // echo '<b>Total Execution Time:</b> ' . $execution_time . ' Secs';
    }

    function getSummary($start, $end, $params, $user_id = 1, $upgrade = false, $new = false)
    {
        global $db, $click_filtered, $dbGlobalLink;
        $mysql['from'] = $db->real_escape_string($start); // mysqli_real_escape_string($dbGlobalLink,$start);
        $mysql['to'] = $db->real_escape_string($end);
        $mysql['user_id'] = $db->real_escape_string($user_id);
        
        if ($upgrade) {
            $sql = "UPDATE 202_dataengine_job SET processing = '1' WHERE time_from ='" . $mysql['from'] . "' AND time_to = '" . $mysql['to'] . "'";
            $db->query($sql);
        }
        

        if ($new == true) {
            $table = '202_dataengine_new';
        } else {
            $table = '202_dataengine';
        }
        
        if (1) {
            
            // insert into dataengine
            $query = "
 insert into " . $table . "(user_id,
click_id,
click_time,
ppc_network_id,
ppc_account_id,
aff_network_id,
aff_campaign_id,
landing_page_id,
keyword_id,
utm_medium_id,
utm_source_id,
utm_campaign_id,
utm_term_id,
utm_content_id,
text_ad_id,
click_referer_site_url_id,
country_id,
region_id,
city_id,
isp_id,
browser_id,
device_id,
platform_id,
ip_id,
c1_id,
c2_id,
c3_id,
c4_id,
variable_set_id,
rotator_id,
rule_id,
rule_redirect_id,
click_lead,
click_filtered,
click_bot,
click_alp,
clicks,
click_out,
leads,
payout,
income,
cost)  
    SELECT 
2c.user_id,
2c.click_id,
2c.click_time,
2pn.ppc_network_id, 
2c.ppc_account_id,
2an.aff_network_id,
2ac.aff_campaign_id,
2c.landing_page_id,
2k.keyword_id,
2gg.utm_source_id,
2gg.utm_medium_id,
2gg.utm_campaign_id,
2gg.utm_term_id,
2gg.utm_content_id,
2ta.text_ad_id,
2cs.click_referer_site_url_id,
2cy.country_id,
2rg.region_id,
2ci.city_id,
2is.isp_id,
2b.browser_id,
2dm.device_id,
2p.platform_id,
2ca.ip_id,
2tc1.c1_id,
2tc2.c2_id,
2tc3.c3_id,
2tc4.c4_id,
2cv.variable_set_id,
2rc.rotator_id,
2rc.rule_id,
2rc.rule_redirect_id,
2c.`click_lead`,
2c.`click_filtered`,
2c.`click_bot`,
2c.`click_alp`, 
1 AS clicks, 
2cr.click_out AS click_out, 
2c.click_lead AS leads, 
2c.click_payout AS payout, 
IF (2c.click_lead>0,2c.click_payout,0) AS income, 
2c.click_cpc AS cost 
FROM 202_clicks AS 2c 
LEFT OUTER JOIN 202_clicks_record AS 2cr ON (2c.click_id = 2cr.click_id) 
LEFT OUTER JOIN 202_aff_campaigns AS 2ac ON (2c.aff_campaign_id = 2ac.aff_campaign_id) 
LEFT OUTER JOIN 202_clicks_advance AS 2ca ON (2c.click_id = 2ca.click_id) 
LEFT OUTER JOIN 202_browsers AS 2b ON (2ca.browser_id = 2b.browser_id) 
LEFT OUTER JOIN 202_platforms AS 2p ON (2ca.platform_id = 2p.platform_id) 
LEFT OUTER JOIN 202_aff_networks AS 2an ON (2ac.aff_network_id = 2an.aff_network_id) 
LEFT OUTER JOIN 202_ppc_accounts AS 2pa ON (2c.ppc_account_id = 2pa.ppc_account_id) 
LEFT OUTER JOIN 202_ppc_networks AS 2pn ON (2pa.ppc_network_id = 2pn.ppc_network_id)
LEFT OUTER JOIN 202_keywords AS 2k ON (2ca.keyword_id = 2k.keyword_id)
LEFT OUTER JOIN 202_google AS 2gg ON (2c.click_id = 2gg.click_id)
LEFT OUTER JOIN 202_landing_pages AS 2lp ON (2c.landing_page_id = 2lp.landing_page_id)
LEFT OUTER JOIN 202_text_ads AS 2ta ON (2ca.text_ad_id = 2ta.text_ad_id)
LEFT OUTER JOIN 202_clicks_site AS 2cs ON (2c.click_id = 2cs.click_id)
LEFT OUTER JOIN 202_clicks_tracking AS 2ct ON (2c.click_id = 2ct.click_id)
LEFT OUTER JOIN 202_site_urls AS 2suf ON (2cs.click_referer_site_url_id = 2suf.site_url_id) 
LEFT OUTER JOIN 202_locations_country AS 2cy ON (2ca.country_id = 2cy.country_id) 
LEFT OUTER JOIN 202_locations_region AS 2rg ON (2ca.region_id = 2rg.region_id) 
LEFT OUTER JOIN 202_locations_city AS 2ci ON (2ca.city_id = 2ci.city_id)
LEFT OUTER JOIN 202_locations_isp AS 2is ON (2ca.isp_id = 2is.isp_id) 
LEFT OUTER JOIN 202_device_models AS 2dm ON (2ca.device_id = 2dm.device_id)
LEFT OUTER JOIN 202_ips AS 2i ON (2ca.ip_id = 2i.ip_id)
LEFT OUTER JOIN 202_tracking_c1 AS 2tc1 ON (2ct.c1_id = 2tc1.c1_id) 
LEFT OUTER JOIN 202_tracking_c2 AS 2tc2 ON (2ct.c2_id = 2tc2.c2_id) 
LEFT OUTER JOIN 202_tracking_c3 AS 2tc3 ON (2ct.c3_id = 2tc3.c3_id) 
LEFT OUTER JOIN 202_tracking_c4 AS 2tc4 ON (2ct.c4_id = 2tc4.c4_id)
LEFT OUTER JOIN 202_clicks_variable AS 2cv ON (2c.click_id = 2cv.click_id)
LEFT OUTER JOIN 202_clicks_rotator AS 2rc ON (2c.click_id = 2rc.click_id)
WHERE 2c.click_time >= " . $mysql['from'] . "
AND 2c.click_time <= " . $mysql['to'] . " " . $params . "
on duplicate key update 
click_lead=values(click_lead),
click_bot=values(click_bot),
click_out=values(click_out),
click_filtered=values(click_filtered),    
leads=values(leads),
payout=values(payout),
income=values(income),
cost=values(cost),
rotator_id=values(rotator_id),
rule_id=values(rule_id),
rule_redirect_id=values(rule_redirect_id),
aff_campaign_id=values(aff_campaign_id),
aff_network_id=values(aff_network_id)";
            // die($query);
        }
        $this->doQuery($query, $mysql['from'], $mysql['to'], $upgrade, $new);
        return $query . "<br><br>";
    }

    function doQuery($query, $from, $to, $upgrade = false, $new = false)
    {
        global $db, $dbGlobalLink;
        $info_result = $result = $db->query($query) or die($db->error . '<br/><br/>' . $query);
        // if (mysqli_num_rows($info_result)>0)
        // if ($info_result->num_rows>0)
        // die(($info_result->num_rows));
        // $this->doSummary($info_result, $from, $to, $upgrade, $new);
    }

    function doSummary($info_result, $from, $to, $upgrade = false, $new = false)
    {
        global $db, $dbGlobalLink;
        $dbGlobalLink = $db;
        // echo "*-";
        // echo $info_result->num_rows;
        // $info_r2= $db->query($info_result) or die($db->error . '<br/><br/>' . $query);
        // die($db);
        $upgrade_from = mysqli_real_escape_string($dbGlobalLink, $from);
        $upgrade_to = mysqli_real_escape_string($dbGlobalLink, $to);
        
        $mysql['from'] = $db->real_escape_string($from); // mysqli_real_escape_string($dbGlobalLink,$start);
        $mysql['to'] = $db->real_escape_string($to);
        $mysql['user_id'] = $db->real_escape_string($user_id);
        
        if ($new)
            $table = "202_dataengine_new";
        else
            $table = "202_dataengine";
        
        $tq = "INSERT INTO " . $table . " (";
        $flist = '';
        $is = '';
        $mysql = array();
        $list = ' ';
        $i = 0;
        $stop = array();
        mysqli_data_seek($info_result, 0);
        
        while ($r = mysqli_fetch_array($info_result, MYSQLI_ASSOC)) {
            $list .= "(";
            
            foreach ($r as $key => $value) {
                $bigvalue .= "-" . $value;
                if ($i == 0) {
                    $flist .= $key . ",";
                    $fr .= "$key = VALUES($key),";
                }
                if ($i >= 0) {
                    if (! $value)
                        $list .= "'',";
                    else
                        $list .= mysqli_real_escape_string($dbGlobalLink, $value) . ",";
                }
            }
            
            $list = substr($list, 0, - 1);
            $list .= ",'" . sha1($bigvalue) . "'),";
            $i ++;
            
            $bigvalue = '';
            // $is .= "(" . implode(',', $mysql) . "),";
            $outsql = $tq . substr($flist, 0, - 1) . ",encode) VALUES " . substr($list, 0, - 1) . " ON DUPLICATE KEY UPDATE ";
            
            $outsql .= substr($fr, 0, - 1);
        }
        
        $is_result = _mysqli_query($outsql);
        
        if ($upgrade) {
            $sql = "UPDATE 202_dataengine_job SET processing = '0', processed = '1' WHERE time_from = '" . $upgrade_from . "' AND time_to = '" . $upgrade_to . "'";
            $res = _mysqli_query($sql);
        }
    }

    function setRowsForOldClickUpgrade($start)
    {
        global $db, $dbGlobalLink;
        $dbGlobalLink = $db;
        
        $end = time();
        $query = "SELECT (click_time - click_time % 3600) AS hourstart FROM 202_clicks WHERE click_time <= " . $end . " and click_time >= " . $start . " GROUP BY hourstart";
        $result = $db->query($query);
        
        $full_day = array();
        $hours = 1;
        $counter = 0;
        
        while ($row = mysqli_fetch_array($result, MYSQLI_ASSOC)) {
            
            $counter ++;
            
            if ($hours == 1) {
                $full_day[] = $row['hourstart'];
            }
            
            if ($hours == 24 || $counter == $result->num_rows) {
                $full_day[] = $row['hourstart'] + 3599;
                $hours = 0;
                
                $mysql['time_from'] = $db->real_escape_string($full_day[0]);
                $mysql['time_to'] = $db->real_escape_string($full_day[1]);
                
                $sql = "INSERT INTO 202_dataengine_job SET time_from = '" . $mysql['time_from'] . "', time_to = '" . $mysql['time_to'] . "'";
                $res = $db->query($sql);
                
                $full_day = array();
            }
            
            $hours ++;
        }
    }

    function processClickUpgrade()
    {
        global $db, $dbGlobalLink;
        $dbGlobalLink = $db;

        if (function_exists('curl_version')) { // if curel is installed use the multiget method
            include_once (substr(dirname(__FILE__), 0, - 10) . '/202-cronjobs/process_dataengine_job.php');
        } else { // loop daily
            $query = "SELECT * FROM 202_dataengine_job WHERE processed = '0'";
            $result = $db->query($query);
            $row = $result->fetch_assoc();
            
            if ($result->num_rows) {
                if (! $row['processing']) {
                    $snippet = "AND 2c.user_id = " . 1;
                    
                    $mysql['click_time_from'] = $db->real_escape_string($row['time_from']);
                    $mysql['click_time_to'] = $db->real_escape_string($row['time_to']);
                    
                    $this->getSummary($mysql['click_time_from'], $mysql['click_time_to'], $snippet, 1, true);
                }
            }
        }
    }

    function getChart($from, $to, $user_chart_data, $time_range, $rangeOutputFormat, $rangePeriod)
    {
        $chart = array();
        $series = array();
        
        $mysql['from'] = self::$db->real_escape_string($from);
        $mysql['to'] = self::$db->real_escape_string($to);
        $click_filtered = $this->getAccountOverviewFilters();
        
        if ($user_chart_data) {
            foreach ($user_chart_data as $chart_data) {
                $chart[$chart_data['campaign_id']][] = $chart_data['value_type'];
            }
        }
        
        $campaigns = array_keys($chart);
        
        foreach ($campaigns as $campaign) {
            
            $i = 0;
            $types = array();
            $data = array();
            $sqlSelectObj = '';
            
            foreach ($chart[$campaign] as $type) {
                $i ++;
                if (count($chart[$campaign]) == $i) {
                    $end = "";
                } else {
                    $end = ",";
                }
                
                switch ($type) {
                    case 'clicks':
                        $sqlSelectObj .= " SUM(clicks) AS clicks" . $end;
                        $typeName = "Clicks";
                        break;
                    case 'click_out':
                        $sqlSelectObj .= " SUM(click_out) AS click_out" . $end;
                        $typeName = "Click Throughs";
                        break;
                    case 'ctr':
                        $sqlSelectObj .= " (clicks/click_out)*100 AS ctr" . $end;
                        $typeName = "CTR";
                        break;
                    case 'leads':
                        $sqlSelectObj .= " SUM(leads) AS leads" . $end;
                        $typeName = "Leads";
                        break;
                    case 'su_ratio':
                        $sqlSelectObj .= " (SUM(click_lead)/SUM(clicks))*100 AS su_ratio" . $end;
                        $typeName = "Avg S/U";
                        break;
                    case 'payout':
                        $sqlSelectObj .= " (SUM(income) / sum(leads)) AS payout, " . $end;
                        $typeName = "Avg Payout";
                        break;
                    case 'epc':
                        $sqlSelectObj .= " SUM(income)/clicks AS epc" . $end;
                        $typeName = "Avg EPC";
                        break;
                    case 'cpc':
                        $sqlSelectObj .= " SUM(cost)/SUM(clicks) AS cpc" . $end;
                        $typeName = "Avg CPC";
                        break;
                    case 'income':
                        $sqlSelectObj .= " SUM(income) AS income" . $end;
                        $typeName = "Income";
                        break;
                    case 'cost':
                        $sqlSelectObj .= " SUM(cost) AS cost" . $end;
                        $typeName = "Cost";
                        break;
                    case 'net':
                        $sqlSelectObj .= " (SUM(income)-SUM(cost)) AS net" . $end;
                        $typeName = "Net";
                        break;
                    case 'roi':
                        $sqlSelectObj .= " ((SUM(income)-SUM(cost))/SUM(cost)*100 ) AS roi" . $end;
                        $typeName = "ROI";
                        break;
                }
                
                $types[] = array(
                    'type_name' => $typeName,
                    'sql_name' => $type
                );
            }
            
            if ($time_range == 'hours') {
                $rangeGroupby = "DATE_FORMAT(FROM_UNIXTIME(click_time),'%b %d %Y %l:00%p')";
                $rangeFormat = ", DATE_FORMAT(FROM_UNIXTIME(click_time),'%b %d %Y %l:00%p') AS date_range";
            } else 
                if ($time_range == 'days') {
                    $rangeGroupby = "DATE_FORMAT(FROM_UNIXTIME(click_time),'%b %d %Y')";
                    $rangeFormat = ", DATE_FORMAT(FROM_UNIXTIME(click_time),'%b %d %Y') AS date_range";
                }
            
            if ($campaign != '0') {
                $rangeFormat .= ", aff_campaign_name";
            }
            
            $sqlObj = "SELECT" . $sqlSelectObj . $rangeFormat . " FROM 202_dataengine ";
            
            if ($campaign != '0') {
                $sqlObj .= "LEFT JOIN 202_aff_campaigns USING (aff_campaign_id) ";
            }
            
            $sqlObj .= "WHERE click_time >= '" . $from . "' AND click_time <= '" . $to . "' ";
            
            if ($campaign != '0') {
                $sqlObj .= "AND aff_campaign_id = '" . $campaign . "' ";
            }
            $sqlObj .= $click_filtered . " ";
            $sqlObj .= "GROUP BY " . $rangeGroupby . ";";
            
            $result = self::$db->query($sqlObj);
            
            if($result){
            while ($row = $result->fetch_assoc()) {
                
                $campaign_name = $row['aff_campaign_name'];
                $data['categories'][$row['date_range']] = $row['date_range'];
                foreach ($row as $key => $value) {
                    $sqlName = '';
                    switch ($key) {
                        case 'clicks':
                            $sqlName = "clicks";
                            break;
                        case 'click_out':
                            $sqlName = "click_out";
                            break;
                        case 'ctr':
                            $sqlName = "ctr";
                            break;
                        case 'leads':
                            $sqlName = "leads";
                            break;
                        case 'su_ratio':
                            $sqlName = "su_ratio";
                            break;
                        case 'payout':
                            $sqlName = "payout";
                            break;
                        case 'epc':
                            $sqlName = "epc";
                            break;
                        case 'cpc':
                            $sqlName = "cpc";
                            break;
                        case 'income':
                            $sqlName = "income";
                            break;
                        case 'cost':
                            $sqlName = "cost";
                            break;
                        case 'net':
                            $sqlName = "net";
                            break;
                        case 'roi':
                            $sqlName = "roi";
                            break;
                    }
                    
                    if ($sqlName != '') {
                        $data['data'][$row['date_range']][$sqlName] = $row[$sqlName];
                    }
                }
                }
            }
            
            foreach ($types as $type) {
                $seriesData = array();
                $series_name = $type['type_name'];
                if ($campaign_name != '') {
                    $series_name .= " (" . $campaign_name . ")";
                } else {
                    $series_name .= " (all)";
                }
                
                foreach ($rangePeriod as $range) {
                    if ($time_range == 'days') {
                        $key = $range->format('M d Y');
                    } else 
                        if ($time_range == 'hours') {
                            $key = $range->format('M d Y g:iA');
                        }
                    if (isset($data['categories'][$key])) {
                        // $seriesData[] = $data['data'][$data['categories'][$key]][$type['sql_name']];
                        // print_r( $data['data'][$key][$type['sql_name']]);
                        $seriesData[] = $data['data'][$key][$type['sql_name']];
                    } else {
                        $seriesData[] = '0';
                    }
                }
                
                $series[] = array(
                    'name' => $series_name,
                    'data' => $seriesData
                );
            }
        }
        
        $chart = array();
        
        $chart['series'] = $series;
        return $chart;
    }
}

class DisplayData
{

    private static $db;

    function __construct()
    {
        try {
            $database = DB::getInstance();
            self::$db = $database->getConnection();
        } catch (Exception $e) {
            self::$db = false;
        }
    }

    function displayReport($reportType, $theData, $foundRows = '')
    {
        global $userObj;
        
        $paginateReport = true;
        switch ($reportType) {
            case 'LpOverview':
                $featureLabel = "Direct Link / Landing Pages";
                $downloadUrl = '';
                break;
            case 'campaignOverview':
                $featureLabel = "Campaigns";
                $downloadUrl = '';
                break;
            case 'breakdown':
                
                $featureLabel = "Time";
                $paginateReport = false;
                $downloadUrl = '';
                break;
            case 'hourly':
            case 'weekly':
                $featureLabel = "Time";
                $paginateReport = false;
                break;
            case 'keyword':
                $featureLabel = "Keyword";
                $downloadUrl = 'keywords_download.php';
                break;
            case 'textad':
                $featureLabel = "Text ad";
                $downloadUrl = 'text_ads_download.php';
                break;
            case 'referer':
                $featureLabel = "Referer";
                $downloadUrl = 'referers_download.php';
                break;
            case 'ip':
                $featureLabel = "IP";
                $downloadUrl = 'ips_download.php';
                break;
            case 'country':
                $featureLabel = "Country";
                $downloadUrl = 'countries_download.php';
                break;
            case 'region':
                $featureLabel = "Region";
                $downloadUrl = 'regions_download.php';
                break;
            case 'city':
                $featureLabel = "City";
                $downloadUrl = 'cities_download.php';
                break;
            case 'isp':
                $featureLabel = "ISP/Carrier";
                $downloadUrl = 'isps_download.php';
                break;
            case 'landingpage':
                $featureLabel = "Landing Page";
                $downloadUrl = 'landing_pages_download.php';
                break;
            case 'device':
                $featureLabel = "Device";
                $downloadUrl = 'device_download.php';
                break;
            case 'browser':
                $featureLabel = "Browser";
                $downloadUrl = 'browser_download.php';
                break;
            case 'platform':
                $featureLabel = "Platform";
                $downloadUrl = 'platform_download.php';
                break;
        }
        
        if ($downloadUrl != '') {
            echo '<div class="row">
                    <div class="col-xs-12 text-right" style="padding-bottom: 10px;">
                        <img style="margin-bottom:2px;" src="' . get_absolute_url() . '202-img/icons/16x16/page_white_excel.png"/>
                        <a style="font-size:12px;" target="_new" href="' . get_absolute_url() . 'tracking202/analyze/' . $downloadUrl . '">
                            <strong>Download to excel</strong>
                        </a>
                    </div>
                </div>';
        }
        
        echo '<table class="table table-bordered table-hover" id="stats-table">
        <thead>
        <tr style="background-color: #f2fbfa;">
        <th colspan="4" style="text-align:left">' . $featureLabel . '</th>
        <th>Clicks</th>
        <th>Click Throughs</th>
        <th>CTR</th>     
        <th>Leads</th>
        <th>Avg S/U</th>
        <th>Avg Payout</th>
        <th>Avg EPC</th>
        <th>Avg CPC</th>
        <th>Income</th>
        <th>Cost</th>
        <th>Net</th>
        <th>ROI</th>
        </tr>
        </thead>
        <tbody>';
        
        $obj = new ArrayObject($theData);
        $it = $obj->getIterator();
        $totals_row = $obj->count();
        
        if (strlen($html['referer_name']) > 20)
            $html['referer_name_trim'] = substr($html['referer_name'], 0, 60) . '...';
        
        for ($i = 0; $i < $obj->count(); $i ++) {
            // echo $it->key() . "=" . $it->current() . "\n";
            $obj2 = new ArrayObject($it->current());
            $html = $obj2->getIterator();
            
            switch ($reportType) {
                case 'LpOverview':
                    $featureKey = $html['landing_page_nickname'];
                    break;
                case 'campaignOverview':
                    $featureKey = $html['aff_network_name'] . ' - ' . $html['aff_campaign_name'];
                    break;
                case 'breakdown':
                case 'hourly':
                case 'weekly':
                    $featureKey = $html['click_time_from_disp'];
                    break;
                case 'keyword':
                    $featureKey = '<div style="text-overflow: ellipsis; overflow : hidden; white-space: nowrap;  
 width: 250px;" title="' . $html['keyword'] . '">' . $html['keyword'] . '</div>';
                    break;
                case 'textad':
                    $featureKey = $html['text_ad_name'];
                    break;
                case 'referer':
                    $featureKey = '<div style="text-overflow: ellipsis; overflow : hidden; white-space: nowrap;  
 width: 250px;" title="' . $html['referer_name'] . '">' . $html['referer_name'] . '</div>';
                    break;
                case 'ip':
                    $featureKey = '<div style="text-overflow: ellipsis; overflow : hidden; white-space: nowrap;  
 width: 100%;" title="' . $html['ip_address'] . '">' . $html['ip_address'] . '</div>';
                    break;
                case 'country':
                    $featureKey = '<img src="' . get_absolute_url() . '202-img/flags/' . strtolower($html['country_code']) . '.png"> ' . $html['country_name'] . ' (' . $html['country_code'] . ')';
                    break;
                case 'region':
                    $featureKey = '<img src="' . get_absolute_url() . '202-img/flags/' . strtolower($html['country_code']) . '.png"> ' . $html['region_name'] . ' (' . $html['country_code'] . ')';
                    break;
                case 'city':
                    $featureKey = '<img src="' . get_absolute_url() . '202-img/flags/' . strtolower($html['country_code']) . '.png"> ' . $html['city_name'] . ' (' . $html['country_code'] . ')';
                    break;
                case 'isp':
                    $featureKey = $html['isp_name'];
                    break;
                case 'landingpage':
                    $featureKey = '<div style="text-overflow: ellipsis; overflow : hidden; white-space: nowrap;  
 width: 240px;" title="' . $html['landing_page_nickname'] . '">' . $html['landing_page_nickname'] . '</div>';
                    break;
                case 'device':
                    $featureKey = $html['device_name'];
                    break;
                case 'browser':
                    $featureKey = $html['browser_name'];
                    break;
                case 'platform':
                    $featureKey = $html['platform_name'];
                    break;
            }
            
            if (self::convertToNumber($html['net']) > 0) {
                $netStyle = 'primary';
            } elseif ($html['net'] < 0) {
                $netStyle = 'important';
            } else {
                $netStyle = 'default';
            }
            if ($html['roi'] > 0) {
                $roiStyle = 'primary';
            } elseif ($html['roi'] < 0) {
                $roiStyle = 'important';
            } else {
                $roiStyle = 'default';
            }
            if (self::convertToNumber($html['total_net']) > 0) {
                $totalNetStyle = 'primary';
            } elseif ($html['total_net'] < 0) {
                $totalNetStyle = 'important';
            } else {
                $totalNetStyle = 'default';
            }
            if ($html['total_roi'] > 0) {
                $totalRoiStyle = 'primary';
            } elseif ($html['total_roi'] < 0) {
                $totalRoiStyle = 'important';
            } else {
                $totalRoiStyle = 'default';
            }
            
            if ($i != $obj->count() - 1) {
                
                if (! $userObj->hasPermission("access_to_campaign_data") && !$_SESSION['publisher']) {
                    $html['clicks'] = '?';
                    $html['click_out'] = '?';
                    $html['leads'] = '?';
                    $html['income'] = '?';
                    $html['cost_wrapper'] = '?';
                    $html['net'] = '?';
                } else {
                    $html['cost_wrapper'] = '(' . $html['cost'] . ')';
                }
                
                echo ' <tr>
               <td colspan="4" style="text-align:left; padding-left:10px">' . $featureKey . '</td>
                   <td>' . $html['clicks'] . '</td>
                       
                        <td>' . $html['click_out'] . '</td>   
            			<td>' . $html['ctr'] . '</td>
            			<td>' . $html['leads'] . '</td>
            			<td>' . $html['su_ratio'] . '</td>
            			<td>' . $html['payout'] . '</td> 
            			<td>' . $html['epc'] . '</td>
            			<td>' . $html['cpc'] . '</td>
            			<td><span class="label label-info">' . $html['income'] . '</span></td>
            			<td><span class="label label-info">' . $html['cost_wrapper'] . '</span></td>
            			<td> <span class="label label-' . $netStyle . '">' . $html['net'] . '</span></td>
            			<td> <span class="label label-' . $roiStyle . '">' . $html['roi'] . '</span></td>    
            			
            		</tr> ';
            } else {
                
                if (! $userObj->hasPermission("access_to_campaign_data") && !$_SESSION['publisher']) {
                    $html['total_clicks'] = '?';
                    $html['total_click_out'] = '?';
                    $html['total_leads'] = '?';
                    $html['total_income'] = '?';
                    $html['total_cost_wrapper'] = '?';
                    $html['total_net'] = '?';
                } else {
                    $html['total_cost_wrapper'] = '(' . $html['total_cost'] . ')';
                }
                
                echo '<tr style="background-color: #F8F8F8;" id="totals" class="no-sort">
        <td colspan="4" style="text-align:left; padding-left:10px;"><strong>Totals for report</strong></td>
        <td><strong>' . $html['total_clicks'] . '</strong></td>
            <td><strong>' . $html['total_click_out'] . '</strong></td>
                <td><strong>' . $html['total_ctr'] . '</strong></td>
        			<td><strong>' . $html['total_leads'] . '</strong></td>
        			<td><strong>' . $html['total_su_ratio'] . '</strong></td>
        			<td><strong>' . $html['total_payout'] . '</strong></td>
        			<td><strong>' . $html['total_epc'] . '</strong></td>
        			<td><strong>' . $html['total_cpc'] . '</strong></td>
        			<td><strong>' . $html['total_income'] . '</strong></td>
        			<td><strong>' . $html['total_cost_wrapper'] . '</strong></td>
        			<td><strong><span class="label label-' . $totalNetStyle . '">' . $html['total_net'] . '</span></strong></td>
        			<td><strong><span class="label label-' . $totalRoiStyle . '">' . $html['total_roi'] . '</span></strong></td>
        		</tr>
        		</tbody>
        	</table> ';
            }
            $it->next();
        }
        if ($paginateReport)
            echo $this->paginate($reportType, $foundRows); // $it->next();
    }

    function displayPerPPCReport($type, $theData)
    {
        global $userObj;
        
        switch ($type) {
            case 'slp_direct_link':
                $featureLabel = "[direct link & simple lp]";
                break;
            
            case 'alp':
                $featureLabel = "[adv lp]";
                break;
        }
        
        if (!empty($theData)) {
            
            foreach ($theData as $campaign) {
                
                switch ($type) {
                    case 'slp_direct_link':
                        $name = $campaign['total_aff_network_name'] . ' - ' . $campaign['total_aff_campaign_name'];
                        break;
                    
                    case 'alp':
                        $name = $campaign['total_landing_page_nickname'];
                        break;
                }
                
                if (self::convertToNumber($campaign['total_net']) > 0) {
                    $totalNetStyle = 'primary';
                } elseif ($campaign['total_net'] < 0) {
                    $totalNetStyle = 'important';
                } else {
                    $totalNetStyle = 'default';
                }
                if ($campaign['total_roi'] > 0) {
                    $totalRoiStyle = 'primary';
                } elseif ($campaign['total_roi'] < 0) {
                    $totalRoiStyle = 'important';
                } else {
                    $totalRoiStyle = 'default';
                }
                
                echo '
            <strong><small>' . $name . ' <span style="font-size: 65%; color: grey; font-weight: normal;">' . $featureLabel . '</span></small></strong>
            <table class="table table-bordered table-hover" id="stats-table">
            <thead>
            <tr style="background-color: #f2fbfa;">
            <th colspan="4" class="no-sort" style="text-align:left">Traffic Source - Traffic Source Account</th>
            <th>Clicks</th>
            <th>Click Throughs</th>
            <th>CTR</th>
            <th>Leads</th>
            <th>Avg S/U</th>
            <th>Avg Payout</th>
            <th>Avg EPC</th>
            <th>Avg CPC</th>
            <th>Income</th>
            <th>Cost</th>
            <th>Net</th>
            <th>ROI</th>
            </tr>
            </thead>
            <tbody>';
                foreach ($campaign['ppc_accounts'] as $ppc_account) {
                    if (self::convertToNumber($ppc_account['net']) > 0) {
                        $netStyle = 'primary';
                    } elseif ($ppc_account['net'] < 0) {
                        $netStyle = 'important';
                    } else {
                        $netStyle = 'default';
                    }
                    if ($ppc_account['roi'] > 0) {
                        $roiStyle = 'primary';
                    } elseif ($ppc_account['roi'] < 0) {
                        $roiStyle = 'important';
                    } else {
                        $roiStyle = 'default';
                    }
                    
                    if (! $userObj->hasPermission("access_to_campaign_data") && !$_SESSION['publisher']) {
                        $ppc_account['clicks'] = '?';
                        $ppc_account['click_out'] = '?';
                        $ppc_account['leads'] = '?';
                        $ppc_account['income'] = '?';
                        $ppc_account['cost_wrapper'] = '?';
                        $ppc_account['net'] = '?';
                    } else {
                        $ppc_account['cost_wrapper'] = '(' . $ppc_account['cost'] . ')';
                    }
                    
                    if(($ppc_account['ppc_network_name'] != '')  && ($ppc_account['ppc_account_name'] != '')){
                     
                        $source_name =  $ppc_account['ppc_network_name'] . ' - ' . $ppc_account['ppc_account_name'];
                    }else{
                        $source_name = '[No Traffic Source Account]';
                    }

                    echo ' <tr>
                   <td colspan="4" style="text-align:left; padding-left:10px">' . $source_name . '</td>
                   <td>' . $ppc_account['clicks'] . '</td>
                       
                        <td>' . $ppc_account['click_out'] . '</td>   
                        <td>' . $ppc_account['ctr'] . '</td>
                        <td>' . $ppc_account['leads'] . '</td>
                        <td>' . $ppc_account['su_ratio'] . '</td>
                        <td>' . $ppc_account['payout'] . '</td> 
                        <td>' . $ppc_account['epc'] . '</td>
                        <td>' . $ppc_account['cpc'] . '</td>
                        <td><span class="label label-info">' . $ppc_account['income'] . '</span></td>
                        <td><span class="label label-info">' . $ppc_account['cost_wrapper'] . '</span></td>
                        <td> <span class="label label-' . $netStyle . '">' . $ppc_account['net'] . '</span></td>
                        <td> <span class="label label-' . $roiStyle . '">' . $ppc_account['roi'] . '</span></td>    
                        
                </tr> ';
                }
                
                if (! $userObj->hasPermission("access_to_campaign_data") && !$_SESSION['publisher']) {
                    $campaign['total_clicks'] = '?';
                    $campaign['total_click_out'] = '?';
                    $campaign['total_leads'] = '?';
                    $campaign['total_income'] = '?';
                    $campaign['cost_wrapper'] = '?';
                    $campaign['net'] = '?';
                } else {
                    $campaign['cost_wrapper'] = '(' . $campaign['total_cost'] . ')';
                }
                
                echo '<tr style="background-color: #F8F8F8;" id="totals" class="no-sort">
                    <td colspan="4" style="text-align:left; padding-left:10px;"><strong>Totals for report</strong></td>
                    <td><strong>' . $campaign['total_clicks'] . '</strong></td>
                    <td><strong>' . $campaign['total_click_out'] . '</strong></td>
                    <td><strong>' . $campaign['total_ctr'] . '</strong></td>
                    <td><strong>' . $campaign['total_leads'] . '</strong></td>
                    <td><strong>' . $campaign['total_su_ratio'] . '</strong></td>
                    <td><strong>' . $campaign['total_payout'] . '</strong></td>
                    <td><strong>' . $campaign['total_epc'] . '</strong></td>
                    <td><strong>' . $campaign['total_cpc'] . '</strong></td>
                    <td><strong>' . $campaign['total_income'] . '</strong></td>
                    <td><strong>' . $campaign['cost_wrapper'] . '</strong></td>
                    <td><strong><span class="label label-' . $totalNetStyle . '">' . $campaign['total_net'] . '</span></strong></td>
                    <td><strong><span class="label label-' . $totalRoiStyle . '">' . $campaign['total_roi'] . '</span></strong></td>
                </tr>
                </tbody>
            </table> ';
            }
        }
    }

    function displayVariableReport($theData)
    {
        global $userObj;
        
        $obj = new ArrayObject($theData);
        $it = $obj->getIterator();
        $totals_row = $obj->count();
        
        echo '<div class="row">
                    <div class="col-xs-12 text-right" style="padding-bottom: 10px;">
                        <img style="margin-bottom:2px;" src="' . get_absolute_url() . '202-img/icons/16x16/page_white_excel.png"/>
                        <a style="font-size:12px;" target="_new" href="' . get_absolute_url() . 'tracking202/analyze/variables_download.php">
                            <strong>Download to excel</strong>
                        </a>
                    </div>
                </div>';
        
        echo '<table class="table table-bordered" id="stats-table">
            <thead>
            <tr style="background-color: #f2fbfa;">
            <th style="text-align:left">Variables</th>
            <th>Clicks</th>
            <th>Click Throughs</th>
             <th>CTR</th>      
            <th>Leads</th>
            <th>Avg S/U</th>
            <th>Avg Payout</th>
            <th>Avg EPC</th>
            <th>Avg CPC</th>
            <th>Income</th>
            <th>Cost</th>
            <th>Net</th>
            <th>ROI</th>
            </tr>
            </thead>
            <tbody>';
        // echo $obj->count();
        for ($i = 0; $i < $obj->count(); $i ++) {
            $obj2 = new ArrayObject($it->current());
            $html = $obj2->getIterator();
            
            if (self::convertToNumber($html['net']) > 0) {
                $netStyle = 'primary';
            } elseif ($html['net'] < 0) {
                $netStyle = 'important';
            } else {
                $netStyle = 'default';
            }
            if ($html['roi'] > 0) {
                $roiStyle = 'primary';
            } elseif ($html['roi'] < 0) {
                $roiStyle = 'important';
            } else {
                $roiStyle = 'default';
            }
            
            if (self::convertToNumber($html['total_net']) > 0) {
                $totalNetStyle = 'primary';
            } elseif ($html['total_net'] < 0) {
                $totalNetStyle = 'important';
            } else {
                $totalNetStyle = 'default';
            }
            if ($html['total_roi'] > 0) {
                $totalRoiStyle = 'primary';
            } elseif ($html['total_roi'] < 0) {
                $totalRoiStyle = 'important';
            } else {
                $totalRoiStyle = 'default';
            }
            
            if ($i != $obj->count() - 1) {
                
                if ($html['variables']) {
                    $jj = 0;
                    foreach ($html['variables'] as $variables) {
                        
                        if (self::convertToNumber($variables['net']) > 0) {
                            $variables_netStyle = 'primary';
                        } elseif ($variables['net'] < 0) {
                            $variables_netStyle = 'important';
                        } else {
                            $variables_netStyle = 'default';
                        }
                        if ($variables['roi'] > 0) {
                            $variables_roiStyle = 'primary';
                        } elseif ($variables['roi'] < 0) {
                            $variables_roiStyle = 'important';
                        } else {
                            $variables_roiStyle = 'default';
                        }
                        echo '
                    <tr class="sub">
                       <td class="result_main_column_level_1" colspan="13"><strong>' . $html[$i]['ppc_network_name'] . ' - ' . $variables[0]['variable_name'] . '</strong></td>
                    </tr> ';
                        $jj ++;
                        if ($variables['values']) {
                            foreach ($variables['values'] as $value) {
                                if (self::convertToNumber($value['net']) > 0) {
                                    $value_netStyle = 'primary';
                                } elseif ($value['net'] < 0) {
                                    $value_netStyle = 'important';
                                } else {
                                    $value_netStyle = 'default';
                                }
                                if ($value['roi'] > 0) {
                                    $value_roiStyle = 'primary';
                                } elseif ($value['roi'] < 0) {
                                    $value_roiStyle = 'important';
                                } else {
                                    $value_roiStyle = 'default';
                                }
                                
                                echo '
                        <tr class="lite">
                           <td class="result_main_column_level_3">' . $value['variable_value'] . '</td>
                           <td>' . $value['clicks'] . '</td>
                           <td>' . $value['click_out'] . '</td>   
                           <td>' . $value['ctr'] . '</td> 
                           <td>' . $value['leads'] . '</td>
                           <td>' . $value['su_ratio'] . '</td>
                           <td>' . $value['payout'] . '</td> 
                           <td>' . $value['epc'] . '</td>
                           <td>' . $value['cpc'] . '</td>
                           <td><span class="label label-info">' . $value['income'] . '</span></td>
                           <td><span class="label label-info">' . $value['cost'] . '</span></td>
                           <td> <span class="label label-' . $value_netStyle . '">' . $value['net'] . '</span></td>
                           <td> <span class="label label-' . $value_roiStyle . '">' . $value['roi'] . '</span></td>    
                        </tr> ';
                            }
                        }
                    }
                }
            } else {
                
                echo '
                <tr style="background-color: #F8F8F8;" id="totals" class="no-sort">
                    <td class="result_main_column_level_1"><strong>Totals for report</strong></td>
                    <td><strong>' . $html['total_clicks'] . '</strong></td>
                    <td><strong>' . $html['total_click_out'] . '</strong></td>
                    <td><strong>' . $html['total_ctr'] . '</strong></td>
                    <td><strong>' . $html['total_leads'] . '</strong></td>
                    <td><strong>' . $html['total_su_ratio'] . '</strong></td>
                    <td><strong>' . $html['total_payout'] . '</strong></td>
                    <td><strong>' . $html['total_epc'] . '</strong></td>
                    <td><strong>' . $html['total_cpc'] . '</strong></td>
                    <td><strong>' . $html['total_income'] . '</strong></td>
                    <td><strong>' . $html['total_cost'] . '</strong></td>
                    <td><strong><span class="label label-' . $totalNetStyle . '">' . $html['total_net'] . '</span></strong></td>
                    <td><strong><span class="label label-' . $totalRoiStyle . '">' . $html['total_roi'] . '</span></strong></td>
                </tr>
            </tbody>
            </table>';
            }
            
            $it->next();
        }
    }

    function downloadReport($reportType, $theData, $foundRows = '')
    {
        global $userObj;
        
        switch ($reportType) {
            case 'keyword':
                $featureLabel = "Keyword";
                break;
            case 'textad':
                $featureLabel = "Text ad";
                break;
            case 'referer':
                $featureLabel = "Referer";
                break;
            case 'ip':
                $featureLabel = "IP";
                break;
            case 'country':
                $featureLabel = "Country";
                break;
            case 'region':
                $featureLabel = "Region";
                break;
            case 'city':
                $featureLabel = "City";
                break;
            case 'isp':
                $featureLabel = "ISP/Carrier";
                break;
            case 'landingpage':
                $featureLabel = "Landing Page";
                break;
            case 'device':
                $featureLabel = "Device";
                break;
            case 'browser':
                $featureLabel = "Browser";
                break;
            case 'platform':
                $featureLabel = "Platform";
                break;
        }
        
        echo $featureLabel . "\t" . "Clicks" . "\t" . "Click Throughs" . "\t" . "LP CTR" . "\t" . "Leads" . "\t" . "S/U" . "\t" . "Payout" . "\t" . "EPC" . "\t" . "Avg CPC" . "\t" . "Income" . "\t" . "Cost" . "\t" . "Net" . "\t" . "ROI" . "\n";
        
        $obj = new ArrayObject($theData);
        $it = $obj->getIterator();
        $totals_row = $obj->count();
        
        for ($i = 0; $i < $obj->count(); $i ++) {
            // echo $it->key() . "=" . $it->current() . "\n";
            $obj2 = new ArrayObject($it->current());
            $html = $obj2->getIterator();
            
            switch ($reportType) {
                
                case 'keyword':
                    $featureKey = $html['keyword'];
                    break;
                case 'textad':
                    $featureKey = $html['text_ad_name'];
                    break;
                case 'referer':
                    $featureKey = $html['referer_name'];
                    break;
                case 'ip':
                    $featureKey = $html['ip_address'];
                    break;
                case 'country':
                    if (array_key_exists("country_name", $html) && array_key_exists("country_code", $html)) {
                        $featureKey = $html['country_name'] . ' (' . $html['country_code'] . ')';
                    } else {
                        $featureKey = false;
                    }
                    break;
                case 'region':
                    if (array_key_exists("region_name", $html) && array_key_exists("country_code", $html)) {
                        $featureKey = $html['region_name'] . ' (' . $html['country_code'] . ')';
                    } else {
                        $featureKey = false;
                    }
                    break;
                case 'city':
                    if (array_key_exists("city_name", $html) && array_key_exists("country_code", $html)) {
                        $featureKey = $html['city_name'] . ' (' . $html['country_code'] . ')';
                    } else {
                        $featureKey = false;
                    }
                    break;
                case 'isp':
                    $featureKey = $html['isp_name'];
                    break;
                case 'landingpage':
                    $featureKey = $html['landing_page_nickname'];
                    break;
                case 'device':
                    $featureKey = $html['device_name'];
                    break;
                case 'browser':
                    $featureKey = $html['browser_name'];
                    break;
                case 'platform':
                    $featureKey = $html['platform_name'];
                    break;
            }
            
            if ($featureKey) {
                
                if (! $userObj->hasPermission("access_to_campaign_data") && !$_SESSION['publisher']) {
                    $html['clicks'] = '?';
                    $html['click_out'] = '?';
                    $html['leads'] = '?';
                    $html['income'] = '?';
                    $html['cost'] = '?';
                    $html['net'] = '?';
                }
                
                echo $featureKey . "\t" . $html['clicks'] . "\t" . $html['click_out'] . "\t" . $html['ctr'] . "\t" . $html['leads'] . "\t" . $html['su_ratio'] . "\t" . $html['payout'] . "\t" . $html['epc'] . "\t" . $html['cpc'] . "\t" . $html['income'] . "\t" . $html['cost'] . "\t" . $html['net'] . "\t" . $html['roi'] . "\n";
            }
            
            $it->next();
        }
    }

    function downloadVariables($theData)
    {
        global $userObj;
        
        $obj = new ArrayObject($theData);
        $it = $obj->getIterator();
        $totals_row = $obj->count();
        
        echo "Custom Variables" . "\t" . "Clicks" . "\t" . "Click Throughs" . "\t" . "LP CTR" . "\t" . "Leads" . "\t" . "S/U" . "\t" . "Payout" . "\t" . "EPC" . "\t" . "Avg CPC" . "\t" . "Income" . "\t" . "Cost" . "\t" . "Net" . "\t" . "ROI" . "\n";
        
        for ($i = 0; $i < $obj->count(); $i ++) {
            $obj2 = new ArrayObject($it->current());
            $html = $obj2->getIterator();
            
            if ($i != $obj->count() - 1) {
                echo "- " . $html['ppc_network_name'] . "\t" . $html['clicks'] . "\t" . $html['click_out'] . "\t" . $html['ctr'] . "\t" . $html['leads'] . "\t" . $html['su_ratio'] . "\t" . $html['payout'] . "\t" . $html['epc'] . "\t" . $html['cpc'] . "\t" . $html['income'] . "\t" . $html['cost'] . "\t" . $html['net'] . "\t" . $html['roi'] . "\n";
                
                foreach ($html['variables'] as $variables) {
                    echo " - " . $variables['variable_name'] . "\t" . $variables['clicks'] . "\t" . $variables['click_out'] . "\t" . $variables['ctr'] . "\t" . $variables['leads'] . "\t" . $variables['su_ratio'] . "\t" . $variables['payout'] . "\t" . $variables['epc'] . "\t" . $variables['cpc'] . "\t" . $variables['income'] . "\t" . $variables['cost'] . "\t" . $variables['net'] . "\t" . $variables['roi'] . "\n";
                    
                    foreach ($variables['values'] as $value) {
                        
                        echo " -- " . $value['variable_value'] . "\t" . $value['clicks'] . "\t" . $value['click_out'] . "\t" . $value['ctr'] . "\t" . $value['leads'] . "\t" . $value['su_ratio'] . "\t" . $value['payout'] . "\t" . $value['epc'] . "\t" . $value['cpc'] . "\t" . $value['income'] . "\t" . $value['cost'] . "\t" . $value['net'] . "\t" . $value['roi'] . "\n";
                    }
                }
            }
            
            $it->next();
        }
    }

    function convertToNumber($val)
    {
        $moneyStuff = array(
            "$",
            ","
        );
        
        return str_replace($moneyStuff, "", $val);
    }

    function paginate($reportType, $foundRows)
    {
        switch ($reportType) {
            case 'textad':
                $reportType = "text_ads";
                break;
            case 'landingpage':
                $reportType = "landing_pages";
                break;
            
            case 'keyword':
            case 'referer':
            case 'ip':
            case 'region':
            case 'isp':
            case 'device':
            case 'browser':
            case 'platform':
                $reportType = $reportType . 's';
                break;
            case 'country':
            case 'city':
                $reportType = substr($reportType, 0, - 1) . 'ies';
                break;
        }
        $up = new UserPrefs();
        $fileName = "sort_" . $reportType . ".php";
        
        $query['pages'] = ceil((int)$foundRows / (int)$up->getPref('user_pref_limit'));
        
        if (isset($_POST['offset']) && $_POST['offset'] != '') {
            $query['offset'] = self::$db->real_escape_string($_POST['offset']);
        } else {
            $query['offset'] = 0;
        }
        
        if ($query['pages'] > 1) {
            ?>
<div class="row">
	<div class="col-xs-12 text-center">
		<div class="pagination" id="table-pages">
			<ul>
        			<?php
            
if ($query['pages'] > 1) {
                if ($query['offset'] == 0)
                    $page = 0;
                else
                    $page = $query['offset'] - 1;
                printf(' <li class="previous"><a class="fui-arrow-left" onclick="loadContent(\'%stracking202/ajax/%s\',\'%s\',\'%s\');"></a></li>', get_absolute_url(), $fileName, $page, $html['order']);
            }
            
            if ($query['pages'] > 1) {
                for ($i = 0; $i < $query['pages']; $i ++) {
                    if (($i >= $query['offset'] - 10) and ($i < $query['offset'] + 11)) {
                        if ($query['offset'] == $i) {
                            $class = 'class="active"';
                        }
                        printf(' <li %s><a onclick="loadContent(\'%stracking202/ajax/%s\',\'%s\',\'%s\');">%s</a></li>', $class, get_absolute_url(), $fileName, $i, $html['order'], $i + 1);
                        unset($class);
                    }
                }
            }
            
            if ($query['pages'] > 1) {
                if ($query['offset'] + 1 == $query['pages'])
                    $page = $query['offset'];
                else
                    $page = $query['offset'] + 1;
                printf(' <li class="next"><a class="fui-arrow-right" onclick="loadContent(\'%stracking202/ajax/%s\',\'%s\',\'%s\');"></a></li>', get_absolute_url(), $fileName, $page, $html['order']);
            }
            ?>
        		</ul>
		</div>
	</div>
</div>
<?php
        
}
    }
}

class UserPrefs
{

    private static $userPref = array();

    static $mysql = Array();

    private static $db;

    function __construct()
    {
        try {
            $database = DB::getInstance();
            self::$db = $database->getConnection();
        } catch (Exception $e) {
            self::$db = false;
        }
        $this->mysql['user_id'] = self::$db->real_escape_string($_SESSION['user_id']);
        
        $user_sql = "SELECT * FROM 202_users_pref WHERE user_id=" . $this->mysql['user_id'];
        $user_result = _mysqli_query($user_sql, $dbGlobalLink); // ($user_sql);
        $user_row = $user_result->fetch_assoc();
        $breakdown = $user_row['user_pref_breakdown'];
        
        foreach ($user_row as $key => $value) {
            self::$userPref[$key] = $value;
        }
        // self::$userPref['user_pref_show'] = $user_row['user_pref_show'];
        // print_r($user_row);
        if ($user_row['user_pref_show'] == 'all') {
            $click_filtered = '';
        }
        if ($user_row['user_pref_show'] == 'real') {
            $click_filtered = " AND click_filtered='0' ";
        }
        if ($user_row['user_pref_show'] == 'filtered') {
            $click_filtered = " AND click_filtered='1' ";
        }
        if ($user_row['user_pref_show'] == 'filtered_bot') {
            $click_filtered = " AND click_bot='1' ";
        }
        if ($user_row['user_pref_show'] == 'leads') {
            $click_filtered = " AND click_lead!='0' ";
        }
        if ($user_row['user_pref_ppc_network_id'] != '0') {
            $click_filtered .= " AND ppc_network_id=" . $user_row['user_pref_ppc_network_id'];
        }
        if ($user_row['user_pref_ppc_account_id'] != '0') {
            $click_filtered .= " AND ppc_account_id=" . $user_row['user_pref_ppc_account_id'];
        }
        if ($user_row['user_pref_aff_network_id'] != '0') {
            $click_filtered .= " AND aff_network_id=" . $user_row['user_pref_aff_network_id'];
        }
        if ($user_row['user_pref_aff_campaign_id'] != '0') {
            $click_filtered .= " AND aff_campaign_id=" . $user_row['user_pref_aff_campaign_id'];
        }
        if ($user_row['user_pref_text_ad_id'] != '0') {
            $click_filtered .= " AND text_ad_id=" . $user_row['user_pref_text_ad_id'];
        }
        if ($user_row['user_pref_method_of_promotion'] == 'directlink') {
            $click_filtered .= " AND 2st.landing_page_id = 0";
        } else 
            if ($user_row['user_pref_method_of_promotion'] == 'landingpage') {
                $click_filtered .= " AND 2st.landing_page_id != 0";
            }
        /*
         * if ($user_row['user_pref_aff_campaign_id'] != '0') { $click_filtered.= " AND aff_campaign_id=".$user_row['user_pref_aff_campaign_id']; }
         * if ($user_row['user_pref_aff_campaign_id'] != '0') { $click_filtered.= " AND aff_campaign_id=".$user_row['user_pref_aff_campaign_id']; }
         * if ($user_row['user_pref_aff_campaign_id'] != '0') { $click_filtered.= " AND aff_campaign_id=".$user_row['user_pref_aff_campaign_id']; }
         * if ($user_row['user_pref_aff_campaign_id'] != '0') { $click_filtered.= " AND aff_campaign_id=".$user_row['user_pref_aff_campaign_id']; }
         */
        if ($user_row['user_cpc_or_cpv'] == 'cpv')
            $cpv = true;
        else
            $cpv = false;
    }

    public static function getPref($pref)
    {
        // print_r(self::$userPref);
        return self::$userPref[$pref];
    }
}

?>