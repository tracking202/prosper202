<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();


//build the get query for the offers202 restful api
$get = array();
$get['apiKey'] = $_SESSION['user_api_key'];
$query = http_build_query($get);



//build the offers202 api string
$url = TRACKING202_API_URL . "/offers202/getNetworks?$query";

$xml = getUrl($url);
$getNetworks = convertXmlIntoArray($xml);
$getNetworks = $getNetworks['getNetworks'];
$networks = $getNetworks['networks'][0]['network'];

echo "<select name='networkId' id='networkId' onchange='setOffersPref();'>";
	echo "<option value=''>All Networks</option>";
	echo "<option value=''>--</option>";
	for ($x = 0; $x < count($networks); $x++) { 
			
		$html = array_map('htmlentities', $networks[$x]);
		
		if ($_SESSION['offers202_network_id'] == $html['networkId']) 	$selected = 'SELECTED';
		else 																$selected = '';
		
		echo "<option $selected value='{$html['networkId']}'>{$html['networkName']} ({$html['networkOffers']})</option>";
		
	}
echo "</select>"; 