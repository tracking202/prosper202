<?php

//with php redirect them to the #top automatically everytime
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();

//build the get query for the stats202 restful api
$get = array();
$get['apiKey'] = $_SESSION['user_api_key'];
$get['stats202AppKey'] = $_SESSION['user_stats202_app_key'];
$query = http_build_query($get);

//build the offers202 api string
$url = TRACKING202_API_URL . "/stats202/addDownloadRequest?$query";
#echo "<p>$url</p>";

//grab the url
$xml = getUrl($url);
#echo $xml;

$getOfferStats = convertXmlIntoArray($xml); 
checkForApiErrors($getOfferStats); 
$getOfferStats = $getOfferStats['getOfferStats'];