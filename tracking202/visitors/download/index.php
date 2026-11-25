<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-30) . '/202-config/connect.php');
require_once(substr(__DIR__, 0,-30) . '/202-config/functions-report-prefs.php');
	
//make sure user is logged in or die
	AUTH::require_user();

// Draw the view the page rendered, not whatever the stored filters say by
// now (ReportView); a request that carries none reads the stored ones.
$reportView = p202_report_view_begin();
	
//start displaying the data     
	header("Content-type: application/octet-stream");
	
# replace excelfile.xls with whatever you want the filename to default to
	header("Content-Disposition: attachment; filename=T202_visitors_".time().".xls");
	header("Pragma: no-cache");
	header("Expires: 0");  
		
	
//get stuff: the Visitors list, as click_history.php reads it (a name only
//when it is the click's own account's, CLAUDE.md #27)
	$command = "SELECT 2c.click_id, 2c.click_time, 2c.click_alp, text_ad_name, aff_campaign_name, aff_campaign_id_public, landing_page_nickname, ppc_network_name, ppc_account_name, " . \Prosper202\DataEngine\IpAddressSql::address('2i', '2i6') . " AS ip_address, keyword, 2c.click_out, click_lead, click_filtered, click_id_public, click_cloaking, 2c.click_referer_site_url_id, click_landing_site_url_id, click_outbound_site_url_id, click_cloaking_site_url_id, click_redirect_site_url_id,	2b.browser_name, 2p.platform_name, 2d.device_name, 202_device_types.type_name, 2cy.country_name, 2cy.country_code, 2rg.region_name, 202_locations_city.city_name, 2is.isp_name, 
2su.site_url_address AS referer,2sd.site_domain_host AS referer_host,
2cl.site_url_address AS landing,2cld.site_domain_host AS landing_host,
2co.site_url_address AS outbound,2cod.site_domain_host AS outbound_host,
2cc.site_url_address AS cloaking,2ccd.site_domain_host AS cloaking_host,
2credir.site_url_address AS redirect,2credird.site_domain_host AS redirect_host,
gclid, 2g.gbraid, 2g.wbraid, 2g.ttclid, 2g.msclkid, 2g.fbclid, 2g.oppref,
2uca.utm_campaign,2uco.utm_content,2um.utm_medium,2us.utm_source,2ut.utm_term
FROM 202_dataengine AS 2c
LEFT JOIN 202_clicks_record USING (click_id) 
LEFT JOIN 202_clicks_site AS 2cs ON (2c.click_id = 2cs.click_id) 
LEFT JOIN 202_aff_campaigns AS 2ac ON (2c.aff_campaign_id = 2ac.aff_campaign_id AND 2ac.user_id = 2c.user_id) 
LEFT JOIN 202_ppc_accounts AS 2pa ON (2c.ppc_account_id = 2pa.ppc_account_id AND 2pa.user_id = 2c.user_id)
LEFT JOIN 202_ppc_networks AS 2pn ON (2pa.ppc_network_id = 2pn.ppc_network_id AND 2pn.user_id = 2c.user_id) 
LEFT JOIN 202_landing_pages ON (202_landing_pages.landing_page_id = 2c.landing_page_id AND 202_landing_pages.user_id = 2c.user_id) 
LEFT JOIN 202_text_ads ON (202_text_ads.text_ad_id = 2c.text_ad_id AND 202_text_ads.user_id = 2c.user_id) 
LEFT JOIN 202_ips AS 2i ON (2c.ip_id = 2i.ip_id) 
" . \Prosper202\DataEngine\IpAddressSql::join('2i', '2i6') . "
LEFT JOIN 202_keywords AS 2k ON (2c.keyword_id = 2k.keyword_id) 
LEFT JOIN 202_browsers AS 2b ON (2c.browser_id = 2b.browser_id) 
LEFT JOIN 202_platforms AS 2p ON (2c.platform_id = 2p.platform_id) 
LEFT JOIN 202_device_models AS 2d ON (2c.device_id = 2d.device_id) 
LEFT JOIN 202_device_types ON (202_device_types.type_id = 2d.device_type) 
LEFT JOIN 202_locations_country AS 2cy ON (2c.country_id = 2cy.country_id) 
LEFT JOIN 202_locations_region AS 2rg ON (2c.region_id = 2rg.region_id) 
LEFT JOIN 202_locations_city ON (202_locations_city.city_id = 2c.city_id) 
LEFT JOIN 202_locations_isp AS 2is ON (2c.isp_id = 2is.isp_id)
LEFT JOIN 202_site_urls AS 2su ON (2c.click_referer_site_url_id = 2su.site_url_id) 
LEFT JOIN 202_site_urls as 2cl ON (click_landing_site_url_id = 2cl.site_url_id) 
LEFT JOIN 202_site_urls as 2co ON (click_outbound_site_url_id = 2co.site_url_id) 
LEFT JOIN 202_site_urls as 2cc ON (click_cloaking_site_url_id = 2cc.site_url_id) 
LEFT JOIN 202_site_urls as 2credir ON (click_redirect_site_url_id = 2credir.site_url_id) 
LEFT JOIN 202_site_domains AS 2sd ON (2su.site_domain_id = 2sd.site_domain_id) 
LEFT JOIN 202_site_domains as 2cld ON (2cld.site_domain_id = 2cl.site_domain_id) 
LEFT JOIN 202_site_domains as 2cod ON (2cod.site_domain_id = 2co.site_domain_id) 
LEFT JOIN 202_site_domains as 2ccd ON (2ccd.site_domain_id = 2cc.site_domain_id) 
LEFT JOIN 202_site_domains as 2credird ON (2credird.site_domain_id = 2credir.site_domain_id) 
LEFT JOIN 202_google as 2g ON (2g.click_id = 2c.click_id )
LEFT JOIN 202_utm_campaign AS 2uca ON (2g.utm_campaign_id = 2uca.utm_campaign_id) 
LEFT JOIN 202_utm_content AS 2uco ON (2g.utm_content_id = 2uco.utm_content_id) 
LEFT JOIN 202_utm_medium AS 2um ON (2g.utm_medium_id = 2um.utm_medium_id) 
LEFT JOIN 202_utm_source AS 2us ON (2g.utm_source_id = 2us.utm_source_id) 
LEFT JOIN 202_utm_term AS 2ut ON (2g.utm_term_id = 2ut.utm_term_id) ";
	$db_table = "2c";
	$query = query($command);
	

//run query
	$click_sql = $query['click_sql'];
	$click_result = $db->query($click_sql) or record_mysql_error($click_sql);

//set the timezone for the user, to display dates in their timezone
	AUTH::set_timezone($_SESSION['user_timezone']);

// Every cell through one function (TsvCell): a tab or a line break a
// visitor sent moved every column after it.
	echo \Prosper202\Report\VisitorsDownload::header();
	$trackingBase = \Prosper202\Click\TrackingBaseUrl::forRequest($_SERVER);
	while ($click_row = $click_result->fetch_array(MYSQLI_ASSOC)) {
		echo \Prosper202\Report\VisitorsDownload::row($click_row, $trackingBase);
	}
