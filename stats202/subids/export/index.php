<?php

//with php redirect them to the #top automatically everytime
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();
AUTH::require_valid_app_key('stats202', $_SESSION['user_api_key'], $_SESSION['user_stats202_app_key']);



header("Content-type: application/octet-stream");

# replace excelfile.xls with whatever you want the filename to default to
header("Content-Disposition: attachment; filename=Stats202-Subids-".time().".xls");
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
$url = TRACKING202_API_URL . "/stats202/getSubids?$query";
#echo "<p>$url</p>";

//grab the url
$data = getUrl($url);

//parse out the array
$xmlToArray    = new XmlToArray($data); 
$getSubids = $xmlToArray->createArray(); 
$getSubids = $getSubids['getSubids'];

echo "subidDate";
echo "\t" . "statAccountNickName";
echo "\t" . "subid";
echo "\t" . "subidActions";
echo "\t" . "subidAmount";

if ($getSubids['subids'])	$subids = $getSubids['subids'][0]['subid'];
for ($x = 0; $x < count($subids); $x++) { 
	
	if ($subids[$x]['subidActions'] > 0) { $subids[$x]['subidActions'] = number_format($subids[$x]['subidActions']);} else { $subids[$x]['subidActions'] = '-'; }
	if ($subids[$x]['subidAmount'] > 0) { $subids[$x]['subidAmount'] = '$' . number_format($subids[$x]['subidAmount'],2); } else { $subids[$x]['subidAmount'] = '-'; }
	
	$html = @array_map('htmlentities', $subids[$x]);

	echo "\n" . "{$html['subidDate']}";
	echo "\t" . "{$html['statAccountNickName']}";
	echo "\t" . "{$html['subid']}";
	echo "\t" . "{$html['subidActions']}";
	echo "\t" . "{$html['subidAmount']}";
	
} 