<?php

//with php redirect them to the #top automatically everytime
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();
AUTH::require_valid_app_key('stats202', $_SESSION['user_api_key'], $_SESSION['user_stats202_app_key']);




header("Content-type: application/octet-stream");

# replace excelfile.xls with whatever you want the filename to default to
header("Content-Disposition: attachment; filename=Stats202-Stats-".time().".xls");
header("Pragma: public");
//header("Expires: 0");

		

//get the dates for this users' preferences
$dates = userPrefDate();

//build the get query for the offers202 restful api
$get = array();
$get['apiKey'] = $_SESSION['user_api_key'];
$get['stats202AppKey'] = $_SESSION['user_stats202_app_key'];
$get['dateFrom'] = $dates['from_date'];
$get['dateTo'] = $dates['to_date'];
if ($_SESSION['stats202_order']) $get['order'] = ($_SESSION['stats202_order']);
if ($_SESSION['stats202_by']) $get['by'] = ($_SESSION['stats202_by']);
if (!$_SESSION['stats202_by']) $_SESSION['stats202_by'] = 'DESC';
$query = http_build_query($get);


//build the offers202 api string
$url = TRACKING202_API_URL . "/stats202/getStats?$query";
#echo "<p>$url</p>";

//grab the url
$data = getUrl($url);

//parse out the array
$xmlToArray    = new XmlToArray($data); 
$getStats = $xmlToArray->createArray(); 
$getStats = $getStats['getStats'];

echo "statAccountNickName";
echo "\t" . "statImpressions";
echo "\t" . "statClicks";
echo "\t" . "statActions";
echo "\t" . "statEpc";
echo "\t" . "statTotal";

if ($getStats['stats'])	$stats = $getStats['stats'][0]['stat'];
for ($x = 0; $x < count($stats); $x++) { 
	
	if ($stats[$x]['statClicks'] > 0) { $stats[$x]['statClicks'] = number_format($stats[$x]['statClicks']); } else { $stats[$x]['statClicks'] = '-'; }
	if ($stats[$x]['statActions'] > 0) { $stats[$x]['statActions'] = number_format($stats[$x]['statActions']);} else { $stats[$x]['statActions'] = '-'; }
	if ($stats[$x]['statImpressions'] > 0) { $stats[$x]['statImpressions'] = number_format($stats[$x]['statImpressions']);} else { $stats[$x]['statImpressions'] = '-'; }
	if ($stats[$x]['statTotal'] > 0) { $stats[$x]['statTotal'] = '$'.number_format($stats[$x]['statTotal'], 2);} else { $stats[$x]['statTotal'] = '-'; }
	if ($stats[$x]['statEpc'] > 0) { $stats[$x]['statEpc'] = '$'.number_format($stats[$x]['statEpc'], 2);} else { $stats[$x]['statEpc'] = '-'; }
	
	$stats[$x]['offerName'] = "({$stats[$x]['offerNetworkId']}) {$stats[$x]['offerName']}";
		
	$html = @array_map('htmlentities', $stats[$x]);
	
	echo "\n" . "{$html['statAccountNickName']}";
	echo "\t" . "{$html['statImpressions']}";
	echo "\t" . "{$html['statClicks']}";
	echo "\t" . "{$html['statActions']}";
	echo "\t" . "{$html['statEpc']}";
	echo "\t" . "{$html['statTotal']}";
} 