<?php
 if(!$_SESSION['user_timezone'])
{
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
    private $forDownload=0;
    
    function __construct()
    {
        try {
            $database = DB::getInstance();
            self::$db = $database->getConnection();
        } catch (Exception $e) {
            self::$db = false;
        }
        $this->mysql['user_id'] = self::$db->real_escape_string($_SESSION['user_id']);
        //make sure mysql uses the timezone choses by the user

        $timezone = new DateTimeZone(date_default_timezone_get()); // Get default system timezone to create a new DateTimeZone object
        $offset = $timezone->getOffset(new DateTime()); // Offset in seconds to UTC
        $offsetHours = round(($offset)/3600);
        
        $tzSql="SET time_zone = '".$offsetHours.":00'";
        if($offsetHours!=0)
            $click_result = self::$db->query($tzSql);
          
    }
    
    // dirty hours by clicks id: This function marks the hour range that the click happened in for updating reports
    function setDirtyHour($click_id)
    {
       // echo $click_id."      ";
        global $db, $dbGlobalLink;
        if(!isset($click_id) || $click_id=='') {  //if not find the list clicks id of the ip within a 30 day range
            $mysql['user_id'] = 1;
            $mysql['ip_address'] = $db->real_escape_string($_SERVER['REMOTE_ADDR']);
            $daysago = time() - 86400; // 24 hours
            $click_sql1 = "     SELECT  202_clicks.click_id
                                        FROM            202_clicks
                                        LEFT JOIN       202_clicks_advance USING (click_id)
                                        LEFT JOIN       202_ips USING (ip_id)
                                        WHERE   202_ips.ip_address='".$mysql['ip_address']."'
                                        AND             202_clicks.user_id='".$mysql['user_id']."'
                                        AND             202_clicks.click_time >= '".$daysago."'
                                        ORDER BY        202_clicks.click_id DESC
                                        LIMIT           1";
    
            $click_result1 = $db->query($click_sql1) or record_mysql_error($click_sql1);
            $click_row1 = $click_result1->fetch_assoc();
            //empy  $mysql array
            unset($mysql);
            $mysql['click_id'] = $db->real_escape_string($click_row1['click_id']);
            $click_id = $mysql['click_id'];
            // $mysql['ppc_account_id'] = $db->real_escape_string($click_row1['ppc_account_id']);
        }
    
        if(!isset($click_id) || $click_id=='') {
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
(2c.click_payout*2c.click_lead) AS income, 
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
WHERE 2c.click_id=" . $click_id."
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

}

?>