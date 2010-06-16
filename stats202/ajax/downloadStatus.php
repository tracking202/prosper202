<?php

//with php redirect them to the #top automatically everytime
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();
AUTH::set_timezone($_SESSION['user_timezone']);

if ($_SESSION['stats202_enabled']) {
	
	//check to see if there is a current job processing, 
	//use the stats202 api to find this information
	
	//build the get query for the stats202 restful api
	$get = array();
	$get['apiKey'] = $_SESSION['user_api_key'];
	$get['stats202AppKey'] = $_SESSION['user_stats202_app_key'];
	$query = http_build_query($get);
	
	
	//build the offers202 api string
	$url = TRACKING202_API_URL . "/stats202/isDownloadComplete?$query";
	 
	//grab the url
	$xml = getUrl($url);
	$isDownloadComplete = convertXmlIntoArray($xml);
	checkForApiErrors($isDownloadComplete); 
	$isDownloadComplete = $isDownloadComplete['isDownloadComplete'];	
	$downloadComplete = $isDownloadComplete['downloadComplete'];	
	
	if ($downloadComplete == 'false') {
		
		echo "<img src='/202-img/refresh-animated.gif' style='margin: 0px 5px -3px 0px;'/>Stats202 is now updating your subid conversions & earnings amount... this will only take a moment.";
		
		//mark this field that the download is not complete
		echo "<input type='hidden' id='downloadComplete' value='0'/>";
		
	} else {
		//show the last time stats202 updated
		$url = TRACKING202_API_URL . "/stats202/getLastDownloadTime?$query";
		 
		//grab the url
		$xml = getUrl($url);
		$getLastDownloadTime = convertXmlIntoArray($xml);
		checkForApiErrors($getLastDownloadTime); 
		$getLastDownloadTime = $getLastDownloadTime['getLastDownloadTime'];	
		$lastDownloadTime = $getLastDownloadTime['lastDownloadTime'];	
	
		//make a pretty date
		if ($lastDownloadTime != 'false') { 
			$today_time = mktime(0,0,0, date('n',time()) ,date('j',time()), date('Y',time()));
			if ($lastDownloadTime >= $today_time) { $last_update_date = 'today at ' . date('g:i a', $lastDownloadTime); }
			else {
				//if yesterday
				$yesterday_time = $today_time - 86400;
				if ($lastDownloadTime >= $yesterday_time) { $last_update_date = 'yesterday at ' . date('g:i a', $lastDownloadTime); }
				else {
					//another day
					 $last_update_date = date('\o\n M d, Y', $lastDownloadTime);
				}	
			}
		}
		
		if ($lastDownloadTime == 'false') {
			echo "
				Stats202 has never updated your conversions yet, to update them click the sync button below.
				<div>
					<input type='button' class='submit' value='sync stats202' onclick='addDownloadRequest();' />
				</div>
			";
		}
		else {
			echo "
				<div style='line-height: 1.4em;'>
					Stats202 last synced in your conversions $last_update_date.<br/>
					<em style='font-size: .9em;'>Refresh your screen to see the updates. Click sync below to download your stats again.</em>
				</div>
				<div>
					<input type='button' class='submit' value='sync stats202' onclick='addDownloadRequest();' />
				</div>
			";
			
		}
		
		//mark this field that the download is complete
		echo "<input type='hidden' id='downloadComplete' value='1'/>";
	}
	
} 


?>
