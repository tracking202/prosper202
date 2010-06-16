<?php

//with php redirect them to the #top automatically everytime
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();
AUTH::require_valid_app_key('stats202', $_SESSION['user_api_key'], $_SESSION['user_stats202_app_key']);


header("Content-type: application/octet-stream");

# replace excelfile.xls with whatever you want the filename to default to
header("Content-Disposition: attachment; filename=Stats202-OfferStats-".time().".xls");
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
$url = TRACKING202_API_URL . "/stats202/getOfferStats?$query";
#echo "<p>$url</p>";

//grab the url
$data = getUrl($url);

//parse out the array
$xmlToArray    = new XmlToArray($data); 
$getOfferStats = $xmlToArray->createArray(); 
$getOfferStats = $getOfferStats['getOfferStats'];

echo "statAccountNickName";
echo "\t" . "offerName";
echo "\t" . "offerStatImpressions";
echo "\t" . "offerStatClicks";
echo "\t" . "offerStatActions";
echo "\t" . "offerStatEpc";
echo "\t" . "offerStatTotal";

if ($getOfferStats['offerStats'])	$offerStats = $getOfferStats['offerStats'][0]['offerStat'];
for ($x = 0; $x < count($offerStats); $x++) { 
	
	if ($offerStats[$x]['offerStatClicks'] > 0) { $offerStats[$x]['offerStatClicks'] = number_format($offerStats[$x]['offerStatClicks']); } else { $offerStats[$x]['offerStatClicks'] = '-'; }
	if ($offerStats[$x]['offerStatActions'] > 0) { $offerStats[$x]['offerStatActions'] = number_format($offerStats[$x]['offerStatActions']);} else { $offerStats[$x]['offerStatActions'] = '-'; }
	if ($offerStats[$x]['offerStatImpressions'] > 0) { $offerStats[$x]['offerStatImpressions'] = number_format($offerStats[$x]['offerStatImpressions']);} else { $offerStats[$x]['offerStatImpressions'] = '-'; }
	if ($offerStats[$x]['offerStatTotal'] > 0) { $offerStats[$x]['offerStatTotal'] = '$'.number_format($offerStats[$x]['offerStatTotal'], 2);} else { $offerStats[$x]['offerStatTotal'] = '-'; }
	if ($offerStats[$x]['offerStatEpc'] > 0) { $offerStats[$x]['offerStatEpc'] = '$'.number_format($offerStats[$x]['offerStatEpc'], 2);} else { $offerStats[$x]['offerStatEpc'] = '-'; }
	
	$offerStats[$x]['offerName'] = "({$offerStats[$x]['offerNetworkId']}) {$offerStats[$x]['offerName']}";
		
	$html = @array_map('htmlentities', $offerStats[$x]);
	
	echo "\n" . "{$html['statAccountNickName']}";
	echo "\t" . "{$html['offerName']}";
	echo "\t" . "{$html['offerStatImpressions']}";
	echo "\t" . "{$html['offerStatClicks']}";
	echo "\t" . "{$html['offerStatActions']}";
	echo "\t" . "{$html['offerStatEpc']}";
	echo "\t" . "{$html['offerStatTotal']}";
} 