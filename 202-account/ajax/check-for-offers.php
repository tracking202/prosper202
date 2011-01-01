<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user(); 

//get the new offer count

$json = getUrl( TRACKING202_RSS_URL . '/cleervoyance/offers?type=json');
$json = json_decode($json, true);

//this grabs all of the current offers avaliable
$offers = $json['offers'];

//set this to 0 at the start
$new_offers = 0;

//now go through the offers if they exist
if ($offers) {
	foreach ($offers as $offer) { 
		
		//now check to see if they are recent or not
		$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
		$mysql['offer_id'] = mysql_real_escape_string($offer['id']);
		$sql = "SELECT * FROM 202_offers WHERE user_id='".$mysql['user_id']."' AND offer_id='".$mysql['offer_id']."'";
		$result = _mysql_query($sql);
		$row = mysql_fetch_assoc($result);
		
		if (!$row) {
			$new_offers++;	
		}
	}
}

$_SESSION['new_offers'] = $new_offers;
die();