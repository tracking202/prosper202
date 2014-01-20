<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 


AUTH::require_user();

//print_r_html($_SESSION);

//set the preferences
if ($_POST['query']) 	$_SESSION['offers202_query'] = $_POST['query'];
if ($_POST['limit']) 		$_SESSION['offers202_limit'] = $_POST['limit'];



switch ($_POST['order']) { 
	case "networkName":
	case "offerName":
	case "offerPayout":
	case "offerType":
	case "offerUpdatedDate":	
	case "offerStartDate":	
		$_SESSION['offers202_order'] = $_POST['order'];
		break;
}

switch ($_POST['by']) {
	case "DESC":
		$_SESSION['offers202_by'] = "DESC";
		break;
	case "ASC":
		$_SESSION['offers202_by'] = "ASC";
		break;	
}



//build the get query for the offers202 restful api
$get = array();
$get['apiKey'] = $_SESSION['user_api_key'];
if ($_SESSION['offers202_network_id']) $get['networkId'] = ($_SESSION['offers202_network_id']);
if ($_SESSION['offers202_query']) $get['q'] = ($_SESSION['offers202_query']);
if ($_SESSION['offers202_limit']) $get['limit'] = ($_SESSION['offers202_limit']);
if ($_SESSION['offers202_order']) $get['order'] = ($_SESSION['offers202_order']);
if ($_SESSION['offers202_by']) $get['by'] = ($_SESSION['offers202_by']);
if (!$_SESSION['offers202_by']) $_SESSION['offers202_by'] = 'DESC';
if (!$_SESSION['offers202_limit']) $_SESSION['offers202_limit'] = 100;
if ($_POST['page']) $get['offset'] = $get['limit'] * ($_POST['page']-1);
$query = http_build_query($get);
 

if ($_SESSION['offers202_query']) { 
	$html['query'] = htmlentities($_SESSION['offers202_query']);
	echo "	<div style='text-align: center; padding: 5px 0px 15px;' id='addAlert'>
				Would you like to be notified every time a new &nbsp;<strong><em>{$html['query']}</em></strong>&nbsp; offer is found? 
				&nbsp;&nbsp; Simply <a href='/alerts202/?addAlert=1&alertType=offer&alertValue={$html['query']}'>click here to create this alert</a>.
			</div>";
}





//build the offers202 api string
$url = TRACKING202_API_URL . "/offers202/getOffers?$query"; 
$xml = getUrl($url);
$getOffers = convertXmlIntoArray($xml);

//check for api errors
checkForApiErrors($getOffers);

//if there were no errors continue
$getOffers = $getOffers['getOffers'];


$summary = $getOffers['summary'][0];
$total_rows = $summary['total_rows'];
$offset = $summary['offset'];
$limit = $summary['limit'];
#print_r_html($summary);

//paging
$page = $_POST['page'];
if (!$page) $page = 1;
$pages = ceil($total_rows / $limit);
if (!$pages) $pages = 1;

$html['page'] = htmlentities(number_format($page));
$html['pages'] = htmlentities(number_format($pages));

//returns the results array like google, but not used
$from = ($page * $limit) - $limit + 1;
$to = $page * $limit;
if ($total_rows < $to) 	$to = $total_rows;
if (!$total_rows) $from = 0;

$html['from'] = htmlentities(number_format($from));
$html['to'] = htmlentities(number_format($to));
$html['results'] = htmlentities(number_format($total_rows));
$results = "<div class='offers-results'>Results <strong>{$html['from']} - {$html['to']}</strong> of about <strong>{$html['results']}</strong> offers.</div>";
echo $results;


//create the navigation bar
$navBar = "<div class='offers-nav'>";
$navBar .= "<strong>Page {$html['page']} of {$html['pages']}</strong>  "; ?>
<? if ($pages > 1) { $navBar .= " <span style='padding: 0px 10px'>&mdash;</span> ";
	if ($page > 1) {
		$navBar .= ' <a href="#" onclick="getOffers('.htmlentities($i).')">First</a> ';
		$navBar .= ' <a href="#" onclick="getOffers('.htmlentities($page - 1).')">Prev</a> ';
	}
	
	if ($pages > 1) {
		for ($i=1; $i <= $pages; $i++) {                         
			if (($i >= $page - 3) and ($i < $page + 4)) { 
				if ($page == $i) { $class = 'style="font-weight: bold;"'; } 
				$navBar .=' <a '.$class.' href="#" onclick="getOffers('.htmlentities($i).')">'.htmlentities(number_format($i)).'</a> ';
				unset($class);
			}
		}
	} 
	
	if ($page < $pages ) {
		$navBar .= ' <a href="#" onclick="getOffers('.htmlentities($page + 1).')">Next</a> ';
		$navBar .= ' <a href="#" onclick="getOffers('.htmlentities($pages).')">Last</a> ';
	} 
} 
$navBar .= "</div> <div class='clear'></div>";

//display the nav bar
#echo $navBar;  ?>	

<div class="offers-export">
	<form id="offers_limit_form">
		Show:
		<select class="limit" name="limit" onchange="setOffersLimitPref();">
			<option <? if ($_SESSION['offers202_limit'] == 10) echo ' SELECTED '; ?> value="10">10 results</option>
			<option <? if ($_SESSION['offers202_limit'] == 25) echo ' SELECTED '; ?> value="25">25 results</option>
			<option <? if ($_SESSION['offers202_limit'] == 50) echo ' SELECTED '; ?> value="50">50 results</option>
			<option <? if ($_SESSION['offers202_limit'] == 75) echo ' SELECTED '; ?> value="75">75 results</option>
			<option <? if (($_SESSION['offers202_limit'] == 100) or (!$_SESSION['offers202_limit'])) echo ' SELECTED '; ?> value="100">100 results</option>
			<option <? if ($_SESSION['offers202_limit'] == 150) echo ' SELECTED '; ?> value="150">150 results</option>
			<option <? if ($_SESSION['offers202_limit'] == 200) echo ' SELECTED '; ?> value="200">200 results</option>
			<option <? if ($_SESSION['offers202_limit'] == 250) echo ' SELECTED '; ?> value="250">250 results</option>
			<option <? if ($_SESSION['offers202_limit'] == 500) echo ' SELECTED '; ?> value="500">500 results</option>
		</select>
	</form>
</div><div style="clear: both;"></div><? 


$networkNameBy = $_SESSION['offers202_by'];
$offerNameBy = $_SESSION['offers202_by'];
$offerPayoutBy = $_SESSION['offers202_by'];
$offerTypeBy = $_SESSION['offers202_by'];
$offerUpdatedDateBy = $_SESSION['offers202_by'];


switch ($_SESSION['offers202_order']) { 
	case "networkName":
		if ($_SESSION['offers202_by'] == 'ASC')	{	$networkNameArrow = '&#9650;';		$networkNameBy = 'DESC'; }
		else 										{	$networkNameArrow = '&#9660;';		$networkNameBy = 'ASC'; }
		break;
	case "offerName":
		if ($_SESSION['offers202_by'] == 'ASC')	{	$offerNameArrow = '&#9650;';			$offerNameBy = 'DESC'; }
		else 										{	$offerNameArrow = '&#9660;';			$offerNameBy = 'ASC'; }
		break;
	case "offerPayout":
		if ($_SESSION['offers202_by'] == 'ASC')	{	$offerPayoutArrow = '&#9650;';		$offerPayoutBy = 'DESC'; }
		else 										{	$offerPayoutArrow = '&#9660;';		$offerPayoutBy = 'ASC'; }
		break;
	case "offerType":
		if ($_SESSION['offers202_by'] == 'ASC')	{	$offerTypeArrow = '&#9650;';			$offerTypeBy = 'DESC'; }
		else 										{	$offerTypeArrow = '&#9660;';			$offerTypeBy = 'ASC'; }
		break;
	case "offerUpdatedDate":	
		if ($_SESSION['offers202_by'] == 'ASC')	{	$offerUpdatedDateArrow = '&#9650;';	$offerUpdatedDateBy = 'DESC'; }
		else 										{	$offerUpdatedDateArrow = '&#9660;';	$offerUpdatedDateBy = 'ASC'; }
		break;
	default:
	case "offerStartDate":	
		if ($_SESSION['offers202_by'] == 'ASC')	{	$offerStartDateArrow = '&#9650;';		$offerStartDateBy = 'DESC'; }
		else 										{	$offerStartDateArrow = '&#9660;';		$offerStartDateBy = 'ASC'; }
		break;
}


?> 


<table cellpadding="0" cellspacing="0" class="offers-table">
	<tr>
		<th class='nowrap'><a href="#" onclick="getOffers('', 'networkName', '<? echo $networkNameBy; ?>');">Network <? echo $networkNameArrow; ?></a></th>
		<th class='nowrap'><a href="#" onclick="getOffers('', 'offerName', '<? echo $offerNameBy; ?>');">Offer <? echo $offerNameArrow; ?></a></th>
		<th class='nowrap'><a href="#" onclick="getOffers('', 'offerPayout', '<? echo $offerPayoutBy; ?>');">Payout <? echo $offerPayoutArrow; ?></a></th>
		<th class='nowrap'><a href="#" onclick="getOffers('', 'offerType', '<? echo $offerTypeBy; ?>');">Type <? echo $offerTypeArrow; ?></a></th>
		<th class='nowrap'><a href="#" onclick="getOffers('', 'offerUpdatedDate', '<? echo $offerUpdatedDateBy; ?>');">Last Updated <? echo $offerUpdatedDateArrow; ?></a></th>
		<th class='nowrap'><a href="#" onclick="getOffers('', 'offerStartDate', '<? echo $offerStartDateBy; ?>');">Started <? echo $offerStartDateArrow; ?></a></th>
	</tr>
	 
	<? if ($total_rows) { 
		$offers = $getOffers['offers'][0]['offer'];
		for ($x = 0; $x < count($offers); $x++) { 
			$html = array_map('htmlentities', $offers[$x]);
			
			if ($html['networkUrl']) $html['networkName'] = "<a href='{$html['networkUrl']}'>{$html['networkName']}</a>";
			if ($html['offerUpdatedDate'] == date('Y-m-d', time() - (60*60*24))) $html['offerUpdatedDate'] = 'Yesterday';
			if ($html['offerStartDate'] == date('Y-m-d', time() - (60*60*24))) $html['offerStartDate'] = 'Yesterday';
			if (strtotime($html['offerStartDate']) >= mktime(0,0,0,date('m'), date('d'), date('Y'))) $html['offerStartDate'] = 'Today';
			if (strtotime($html['offerUpdatedDate']) >= mktime(0,0,0,date('m'), date('d'), date('Y'))) $html['offerUpdatedDate'] = 'Today';
			
			if ($html['offerNetworkId'])
					$html['offerNetworkId'] = "({$html['offerNetworkId']}) ";
					
			if ($html['offerUrl']) 	$html['offerName'] = "<a href='{$html['offerUrl']}'>{$html['offerNetworkId']}{$html['offerName']}</a>";
			else 					$html['offerName'] = "{$html['offerNetworkId']}{$html['offerName']}";
			
			if ($html['offerPayoutType'] == 'percent') 	$html['offerPayout'] .= '%'; 
			else 										$html['offerPayout'] = '$'.$html['offerPayout'];
			
			if ($y == 1) { $class = 'alt'; $y = 0; } else { $class = ''; $y = 1; }
			
			echo "<tr class='$class'>";
				echo "<td class='' style='white-space: nowrap;'>{$html['networkName']}</td>";
				echo "<td class=''>{$html['offerName']}</td>";
				echo "<td class=' center'>{$html['offerPayout']}</td>";
				echo "<td class=' center'>{$html['offerType']}</td>";
				//echo "<td class=' center'>{$html['offerTargetingSummary']}</td>";
				echo "<td class=' center nowrap'>{$html['offerUpdatedDate']}</td>";
				echo "<td class=' center'>{$html['offerStartDate']}</td>";
			echo "</tr>";
		} 
	} else {
		echo "<tr><td colspan='10' class='center'>No offers containing all your search items were found.</td></tr>";
	} ?>

</table>



<? echo $navBar;  ?>