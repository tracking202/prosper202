<?php
declare(strict_types=1);
function getAuth($db, $variables){
	$mysql['api_key'] = $db->real_escape_string($variables['apikey']);
	$key_sql = "SELECT 	*
				FROM   	`202_api_keys` 
				WHERE  	`api_key`='".$mysql['api_key']."'";
	$key_result = _mysqli_query($db, $key_sql);
	$key_row = $key_result->fetch_assoc();

	if($key_result->num_rows > 0) {

		$mysql['user_id'] = $db->real_escape_string($key_row['user_id']);
		$user_sql = "SELECT 	`user_timezone`
					FROM   	`202_users` 
					WHERE  	`user_id`='".$mysql['user_id']."'";
		$user_result = _mysqli_query($db, $user_sql);
		$user_row = $user_result->fetch_assoc();
		showCategories($db, $variables, $mysql['user_id']);
		//return array('msg' => 'Authorized', 'error' => false, 'status' => 202);
	} else {
		return array('msg' => 'Unauthorized', 'error' => true, 'status' => 401);
	}
}

function showCategories($db, $vars, $user){
    foreach ($vars as $key=>$var)
        $vars[$key]=$db->real_escape_string($var);
    //$vars = $db->real_escape_string($vars);
  //  print_r($vars);
    
    if($vars['action']=="list")
        $data= listCategories($db,$user);
   // print_r($data);
 
   
}

function listCategories($db,$user){
    $mysql['user_id'] = $db->real_escape_string($user);
    $aff_network_sql = "SELECT * FROM `202_aff_networks` WHERE `user_id`='".$mysql['user_id']."' AND `aff_network_deleted`='0' ORDER BY `aff_network_name` ASC";
    $aff_network_result = $db->query($aff_network_sql) or die();
  //  echo $aff_network_sql;
    while ($aff_network_row = $aff_network_result->fetch_array(MYSQL_ASSOC)) {
    
        $html['aff_network_name'] = htmlentities((string)($aff_network_row['aff_network_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $html['aff_network_id'] = htmlentities((string)($aff_network_row['aff_network_id'] ?? ''), ENT_QUOTES, 'UTF-8');
    $cat['categories'][]=array('category_id' => $html['aff_network_id'], 'category_name' => $html['aff_network_name']);    
}

$json = str_replace('\\/', '/', json_encode($cat));

print_r(pretty_json($json));

}
function runReports($db, $vars, $user, $timezone){

	date_default_timezone_set($timezone);

	$report_types = array('keywords', 'text_ads', 'referers', 'ips', 'countries', 'cities', 'carriers', 'landing_pages'); //report types

	if (in_array($vars['type'], $report_types))
	{	
		if(isset($vars['c1'])) { $c1 = $vars['c1']; } 

		if (isset($vars['c2'])) { $c2 = $vars['c2']; } 

		if (isset($vars['c3'])) { $c3 = $vars['c3']; } 

		if (isset($vars['c4'])) { $c4 = $vars['c4']; }


		if (isset($vars['cid']) && $vars['cid'] > 0) {
			if (getCampaignID($db, $vars['cid'], $user)) {
				$cid = $vars['cid'];
			}
		}

		if ($vars['date_from'] != null || $vars['date_to'] != null) {
			
			if(!validateDate($vars['date_from']) || !validateDate($vars['date_to'])){
				$data = array('msg' => 'Wrong date format', 'error' => true, 'status' => 404);
				$json = json_encode($data, true);
				print_r(pretty_json($json));
				die();
			}

			$timestamps = getTimestamp($vars['date_from'], $vars['date_to']);
			$date_from = $timestamps['from'];
			$date_to = $timestamps['to'];

		} else {
			$date_from = mktime(0,0,0,(int)date('m',time()),(int)date('d',time()),(int)date('Y',time()));
			$date_to = mktime(23,59,59,(int)date('m',time()),(int)date('d',time()),(int)date('Y',time()));
		}

	    switch($vars['type']) //each report type
		{
		    case 'keywords':
				return reportQuery($db, "keywords", "keyword_id", "keyword", $user, $date_from, $date_to, $cid, $c1, $c2, $c3, $c4);
				break;

			case 'text_ads':
				return reportQuery($db, "text_ads", "text_ad_id", "text_ad_name", $user, $date_from, $date_to, $cid, $c1, $c2, $c3, $c4);
				break;

			case 'referers':
				return reportQuery($db, "referers", "site_domain_id", "referer", $user, $date_from, $date_to, $cid, $c1, $c2, $c3, $c4);
				break;

			case 'ips':
				return reportQuery($db, "ips", "ip_id", "ip_address", $user, $date_from, $date_to, $cid, $c1, $c2, $c3, $c4);
				break;

			case 'countries':
				return reportQuery($db, "locations_country", "country_id", "country_name", $user, $date_from, $date_to, $cid, $c1, $c2, $c3, $c4);
				break;

			case 'cities':
				return reportQuery($db, "locations_city", "city_id", "city_name", $user, $date_from, $date_to, $cid, $c1, $c2, $c3, $c4);
				break;

			case 'cities':
				return reportQuery($db, "locations_city", "city_id", "city_name", $user, $date_from, $date_to, $cid, $c1, $c2, $c3, $c4);
				break;

			case 'carriers':
				return reportQuery($db, "locations_isp", "isp_id", "isp_name", $user, $date_from, $date_to, $cid, $c1, $c2, $c3, $c4);
				break;

			case 'landing_pages':
				return reportQuery($db, "landing_pages", "landing_page_id", "landing_page", $user, $date_from, $date_to, $cid, $c1, $c2, $c3, $c4);
				break;
		}

	} else {
		return array('msg' => 'Not allowed report type', 'error' => true, 'status' => 404);
	}
	
}

function reportQuery($db, $type, $id, $name, $user, $date_from, $date_to, $cid = null, $c1 = null, $c2 = null, $c3 = null, $c4 = null){

	$date = array(
			'date_from' => date('m/d/Y', $date_from),
			'date_to' => date('m/d/Y', $date_to),
			'time_zone' => date_default_timezone_get() 
	);

	$data = array();

	$mysql['user_id'] = $db->real_escape_string($user);
	$select_id = $db->real_escape_string($id);
	$mysql['date_from'] = $db->real_escape_string($date_from);
	$mysql['date_to'] = $db->real_escape_string($date_to);
	$mysql['aff_campaign_id'] = $db->real_escape_string($cid);
	$mysql['c1'] = $db->real_escape_string($c1);
	$mysql['c2'] = $db->real_escape_string($c2);
	$mysql['c3'] = $db->real_escape_string($c3);
	$mysql['c4'] = $db->real_escape_string($c4);

	$report_sql = "SELECT *
				FROM   	202_clicks AS 2c
				LEFT OUTER JOIN 202_clicks_advance AS 2ca ON (2ca.click_id = 2c.click_id)";

				//If referers report type
				if($type == "referers"){
					$report_sql .= "
						LEFT OUTER JOIN 202_clicks_site AS 2cs ON (2cs.click_id = 2c.click_id)
						LEFT OUTER JOIN 202_site_urls AS 2su ON (2cs.click_referer_site_url_id = 2su.site_url_id)
						LEFT OUTER JOIN 202_site_domains AS 2l ON (2l.site_domain_id = 2su.site_domain_id)";
				//If landing pages report type
				} elseif($type == "landing_pages") {
					$report_sql .= " LEFT OUTER JOIN 202_clicks_site AS 2cs ON (2cs.click_id = 2c.click_id)
									 LEFT OUTER JOIN 202_landing_pages AS 2lp ON (2lp.landing_page_id = 2c.landing_page_id)";
				} else {
					//If any other report type
					$report_sql .= " LEFT OUTER JOIN 202_".$type." AS 2l ON (2l.".$select_id." = 2ca.".$select_id.")";
				}

				//If any of C1-C4 variables are set
				if ($mysql['c1'] || $mysql['c2'] || $mysql['c3'] || $mysql['c4']) {
					$report_sql .= "LEFT OUTER JOIN 202_clicks_tracking AS 2cv ON (2cv.click_id = 2c.click_id)";
					
					if($mysql['c1']) { $report_sql .= "LEFT OUTER JOIN 202_tracking_c1 AS 2c1 ON (2c1.c1_id = 2cv.c1_id)"; } 

					if ($mysql['c2']) { $report_sql .= "LEFT OUTER JOIN 202_tracking_c2 AS 2c2 ON (2c2.c2_id = 2cv.c2_id)"; } 

					if ($mysql['c3']) { $report_sql .= "LEFT OUTER JOIN 202_tracking_c3 AS 2c3 ON (2c3.c3_id = 2cv.c3_id)"; } 

					if ($mysql['c4']) { $report_sql .= "LEFT OUTER JOIN 202_tracking_c4 AS 2c4 ON (2c4.c4_id = 2cv.c4_id)"; }
				}

				$report_sql .= " WHERE 2c.user_id='".$mysql['user_id']."' AND click_time > ".$mysql['date_from']." AND click_time < ".$mysql['date_to']."";

				//If C variables are set
				if($mysql['c1']) { $report_sql .= " AND 2c1.c1='".$mysql['c1']."'"; }
				if($mysql['c2']) { $report_sql .= " AND 2c2.c2='".$mysql['c2']."'"; }
				if($mysql['c3']) { $report_sql .= " AND 2c3.c3='".$mysql['c3']."'"; }
				if($mysql['c4']) { $report_sql .= " AND 2c4.c4='".$mysql['c4']."'"; }

				//If CID variable set
				if ($mysql['aff_campaign_id']) { $report_sql .= " AND 2c.aff_campaign_id='".$mysql['aff_campaign_id']."'"; } 
				
				//If ISP/Carriers report type 
				if($type == "locations_isp"){ $report_sql .= " AND 2ca.$select_id >= 1"; }
				
				//If landing pages report type
				if($type == "landing_pages"){ $report_sql .= " GROUP BY 2c.landing_page_id"; } else { $report_sql .= " GROUP BY 2l.$select_id"; }
	$report_result = $db->query($report_sql);
	$rows = $report_result->num_rows;
	if ($rows > 0) {

		while ($report_row = $report_result->fetch_assoc()) {
			$click_sql = "SELECT 
							COUNT(*) AS clicks,
							AVG(2c.click_cpc) AS avg_cpc,
							SUM(2cr.click_out) AS click_throughs,
							SUM(2c.click_lead) AS leads,
							SUM(2c.click_payout*2c.click_lead) AS income
					   FROM
							202_clicks AS 2c
					   LEFT OUTER JOIN 202_clicks_advance AS 2ca ON (2ca.click_id = 2c.click_id)";

					   //If referers report type
					   if($type == "referers"){
					   		$click_sql .= "
					   		LEFT OUTER JOIN 202_clicks_site AS 2cs ON (2cs.click_id = 2c.click_id)
							LEFT OUTER JOIN 202_site_urls AS 2su ON (2cs.click_referer_site_url_id=2su.site_url_id)
							LEFT OUTER JOIN 202_site_domains AS 2l ON (2l.site_domain_id = 2su.site_domain_id)";
					   } else {
					   		$report_sql .= " LEFT OUTER JOIN 202_".$type." AS 2l ON (2l.".$select_id." = 2ca.".$select_id.")";
					   }

					   //If any of C1-C4 variables are set
						if ($mysql['c1'] || $mysql['c2'] || $mysql['c3'] || $mysql['c4']) {
							$click_sql .= "LEFT OUTER JOIN 202_clicks_tracking AS 2cv ON (2cv.click_id = 2c.click_id)";
							
							if($mysql['c1']) { $click_sql .= "LEFT OUTER JOIN 202_tracking_c1 AS 2c1 ON (2c1.c1_id = 2cv.c1_id)"; } 

							if ($mysql['c2']) { $click_sql .= "LEFT OUTER JOIN 202_tracking_c2 AS 2c2 ON (2c2.c2_id = 2cv.c2_id)"; } 

							if ($mysql['c3']) { $click_sql .= "LEFT OUTER JOIN 202_tracking_c3 AS 2c3 ON (2c3.c3_id = 2cv.c3_id)"; } 

							if ($mysql['c4']) { $click_sql .= "LEFT OUTER JOIN 202_tracking_c4 AS 2c4 ON (2c4.c4_id = 2cv.c4_id)"; }
						}

					   //If any other
					   $click_sql .= " LEFT OUTER JOIN 202_clicks_record AS 2cr ON (2cr.click_id = 2c.click_id)
					   				  WHERE 2c.user_id='".$mysql['user_id']."' AND click_time > '".$mysql['date_from']."' AND click_time < '".$mysql['date_to']."'";

					   	//If C variables are set
						if($mysql['c1']) { $click_sql .= " AND 2c1.c1='".$mysql['c1']."'"; }
						if($mysql['c2']) { $click_sql .= " AND 2c2.c2='".$mysql['c2']."'"; }
						if($mysql['c3']) { $click_sql .= " AND 2c3.c3='".$mysql['c3']."'"; }
						if($mysql['c4']) { $click_sql .= " AND 2c4.c4='".$mysql['c4']."'"; }

					   //If CID variable is set	
					   if ($mysql['aff_campaign_id']) { $click_sql .= " AND 2c.aff_campaign_id='".$mysql['aff_campaign_id']."'"; }

					   //If referers report type
					   if($type == "referers"){
					   		$click_sql .="AND 2l.".$select_id."='".$report_row[$select_id]."'";
					   //If landing pages report type		
					   } elseif($type == "landing_pages") {
					   		$click_sql .= "AND 2c.".$select_id."='".$report_row[$select_id]."'
					   				  GROUP BY 2c.".$select_id;
					   } else {
					   		$click_sql .= "AND 2ca.".$select_id."='".$report_row[$select_id]."'";
					   }		

			$click_result = $db->query($click_sql);
			$click_row = $click_result->fetch_assoc();
				$country_code = '';

				//get the stats
					$clicks = 0;
					$clicks = $click_row['clicks'];

					$total_clicks = $total_clicks + $clicks;

					$click_throughs = 0;
					$click_throughs = $click_row['click_throughs'];

					$total_click_throughs = $total_click_throughs + $click_throughs;

				//ctr rate
					$ctr_ratio = 0;
					$ctr_ratio = @round($click_throughs/$clicks*100,2);

					$total_ctr_ratio = @round($total_click_throughs/$total_clicks*100,2);

				//avg cpc and cost
					$avg_cpc = 0;
					$avg_cpc = $click_row['avg_cpc'];

					$cost = 0;
					$cost = $clicks * $avg_cpc;

					$total_cost = $total_cost + $cost;
					$total_avg_cpc = @round($total_cost/$total_clicks, 5);

				//leads
					$leads = 0;
					$leads = $click_row['leads'];

					$total_leads = $total_leads + $leads;

				//signup ratio
					$su_ratio - 0;
					$su_ratio = @round($leads/$clicks*100,2);

					$total_su_ratio = @round($total_leads/$total_clicks*100,2);

				//current payout
					$payout = 0;
					$payout = $report_row['click_payout'];
					$total_payout = $total_payout + $payout;

				//income
					$income = 0;
					$income = $click_row['income'];

					$total_income = $total_income + $income;
				//grab the EPC
					$epc = 0;
					$epc = @round($income/$clicks,2);

					$total_epc = @@round($total_income/$total_clicks,2);

				//net income
					$net = 0;
					$net = $income - $cost;

					$total_net = $total_income - $total_cost;

				//roi
					$roi = 0;
					$roi = @round($net/$cost*100);

					$total_roi = @round($total_net/$total_cost);

			if ($name == "keyword") {
				if(!$report_row['keyword']) $report_row[$name] = "[no keyword]";
			}

			if ($name == "text_ad_name") {
				if(!$report_row['text_ad_name']) $report_row[$name] = "[no text ad]";
			}

			if ($name == "referer") {
				if(!$report_row['site_domain_host']) {
					$report_row[$name] = "[no referer]";
				} else {
					$report_row[$name] = $report_row['site_domain_host'];
				}
			}

			if ($type == "locations_country") $type = "countries";

			if ($type == "locations_city") $type = "cities";

			if ($type == "locations_isp") $type = "carriers";

			if ($type == "landing_pages"){
				if(!$report_row['landing_page_nickname']) {
					$report_row[$name] = "[direct link]";
				} else {
					$report_row[$name] = $report_row['landing_page_nickname'];
				}
			}

			$data[] = array(
				$name => $report_row[$name],
	        	"clicks" => $clicks,
	        	"click_throughs" => $click_throughs,
	        	"lp_ctr" => $ctr_ratio."%",
	        	"leads" => $leads,
	        	"su_ratio" => $su_ratio."%",
	        	"payout" => dollar_format($payout),
	        	"epc" => dollar_format($epc),
	        	"avg_cpc" => "$".$avg_cpc,
	        	"income" => dollar_format($income),
	        	"cost" => dollar_format($cost),
	        	"net" => dollar_format($net),
	        	"roi" => $roi."%"
	    	);
		}

		$totals = array(
			"clicks" => $total_clicks, 
			"click_throughs" => $total_click_throughs,
			"lp_ctr" => $total_ctr_ratio."%",
			"leads" => $total_leads,
			"su_ratio" => $total_su_ratio."%",
			"payout" => dollar_format($total_payout/$rows),
			"epc" => dollar_format($total_epc),
			"avg_cpc" => dollar_format($total_avg_cpc),
			"income" => dollar_format($total_income),
			"cost" => dollar_format($total_cost),
			"net" => dollar_format($total_net),
			"roi" => $total_roi."%"
		);

	} else {
		$totals = array();
	}

	return array("date_range" => $date, $type => $data, "totals" => $totals);
	
}

function getCampaignID($db, $campaign, $user){
	$mysql['user_id'] = $db->real_escape_string($user);
	$mysql['campaign_id'] = $db->real_escape_string($campaign);
	$key_sql = "SELECT 	*
				FROM   	`202_aff_campaigns` 
				WHERE  	`user_id`='".$mysql['user_id']."' AND `aff_campaign_id`='".$mysql['campaign_id']."'";
	$key_result = _mysqli_query($db, $key_sql);
	$key_row = $key_result->fetch_assoc();

	if($key_result->num_rows > 0) {
		return true;
	} else {
		$json = json_encode(array('msg' => 'Campaign not found', 'error' => true, 'status' => 404), true);
		print_r(pretty_json($json));
		die();
	}
}

function validateDate($date, $format = 'm/d/Y')
{	
	$d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) == $date;
}

function getTimestamp($datefrom, $dateto)
{	
	$date = array();

	$from = explode('/', $datefrom);
	$from_month = trim($from[0]);
	$from_day = trim($from[1]);
	$from_year = trim($from[2]);

	$date_from = mktime(0,0,0,(int)$from_month,(int)$from_day,(int)$from_year);

    $to = explode('/', $dateto); 
    $to_month = trim($to[0]);
    $to_day = trim($to[1]);
    $to_year = trim($to[2]);

    $date_to = mktime(23,59,59,(int)$to_month,(int)$to_day,(int)$to_year);

    $date['from'] = $date_from;
    $date['to'] = $date_to;

    return $date;
}

function pretty_json($json) {
 
    $result      = '';
    $pos         = 0;
    $strLen      = strlen($json);
    $indentStr   = '  ';
    $newLine     = "\n";
    $prevChar    = '';
    $outOfQuotes = true;
 
    for ($i=0; $i<=$strLen; $i++) {
 
        // Grab the next character in the string.
        $char = substr($json, $i, 1);
 
        // Are we inside a quoted string?
        if ($char == '"' && $prevChar != '\\') {
            $outOfQuotes = !$outOfQuotes;
 
        // If this character is the end of an element, 
        // output a new line and indent the next line.
        } else if(($char == '}' || $char == ']') && $outOfQuotes) {
            $result .= $newLine;
            $pos --;
            for ($j=0; $j<$pos; $j++) {
                $result .= $indentStr;
            }
        }
 
        // Add the character to the result string.
        $result .= $char;
 
        // If the last character was the beginning of an element, 
        // output a new line and indent the next line.
        if (($char == ',' || $char == '{' || $char == '[') && $outOfQuotes) {
            $result .= $newLine;
            if ($char == '{' || $char == '[') {
                $pos ++;
            }
 
            for ($j = 0; $j < $pos; $j++) {
                $result .= $indentStr;
            }
        }
 
        $prevChar = $char;
    }
 
    return $result;
}

function dollar_format($amount, $cpv = false) {
	if ($cpv == true) {
		$decimals = 5;
	} else {
		$decimals = 2;
	}
	
	if ($amount >= 0) {
		$new_amount = "\$".sprintf("%.".$decimals."f",$amount);
	} else { 
		$new_amount = "\$".sprintf("%.".$decimals."f",substr($amount,1,strlen($amount)));
		$new_amount = '('.$new_amount.')';    
	}
	
	return $new_amount;
} 

?>