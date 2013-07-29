<?php

//with php redirect them to the #top automatically everytime
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();

//get the user prefs
$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
$user_sql = "SELECT * FROM 202_users_pref WHERE user_id=".$mysql['user_id'];
$user_result = _mysql_query($user_sql);
$user_row = mysql_fetch_assoc($user_result);
	
$_SESSION['stats202_limit'] = $user_row['user_pref_limit'];



switch ($_POST['order']) { 
	case "statAccountNickName":
	case "offerName":
	case "statClicks":
	case "statImpressions":
	case "statActions":
	case "statEpc":	
	case "statTotal":	
		$_SESSION['stats202_order'] = $_POST['order'];
		break;
}

switch ($_POST['by']) {
	case "DESC":
		$_SESSION['stats202_by'] = "DESC";
		break;
	case "ASC":
		$_SESSION['stats202_by'] = "ASC";
		break;	
}


//get the dates for this users' preferences
$dates = userPrefDate();

//build the get query for the offers202 restful api
$get = array();
$get['apiKey'] = $_SESSION['user_api_key'];
$get['stats202AppKey'] = $_SESSION['user_stats202_app_key'];
$get['dateFrom'] = $dates['from_date'];
$get['dateTo'] = $dates['to_date'];
if ($_SESSION['stats202_order']) $get['order'] = ($_SESSION['stats202_order']);
$get['limit'] = ($_SESSION['stats202_limit']);
if ($_SESSION['stats202_by']) $get['by'] = ($_SESSION['stats202_by']);
if (!$_SESSION['stats202_by']) $_SESSION['stats202_by'] = 'DESC';
if ($_POST['page']) $get['offset'] = $get['limit'] * ($_POST['page']-1);
$query = http_build_query($get);

//build the offers202 api string
$url = TRACKING202_API_URL . "/stats202/getOfferStats?$query";
#echo "<p>$url</p>";

//grab the url
$xml = getUrl($url);
$getOfferStats = convertXmlIntoArray($xml);
checkForApiErrors($getOfferStats); 
$getOfferStats = $getOfferStats['getOfferStats'];





$summary = $getOfferStats['summary'][0];
$total_rows = $summary['total_rows'];
$offset = $summary['offset'];
$limit = $summary['limit'];
$totalOfferStatImpressions = $summary['totalOfferStatImpressions'];
$totalOfferStatClicks = $summary['totalOfferStatClicks'];
$totalOfferStatActions = $summary['totalOfferStatActions'];
$totalOfferStatTotal = $summary['totalOfferStatTotal'];
$totalOfferStatEpc = $summary['totalOfferStatEpc'];
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
<?php if ($pages > 1) { $navBar .= " <span style='padding: 0px 10px'>&mdash;</span> ";
	if ($page > 1) {
		$navBar .= ' <a  class="pointer" onclick="getOfferStats('.htmlentities($i).')">First</a> ';
		$navBar .= ' <a class="pointer"  onclick="getOfferStats('.htmlentities($page - 1).')">Prev</a> ';
	}
	
	if ($pages > 1) {
		for ($i=1; $i <= $pages; $i++) {                         
			if (($i >= $page - 3) and ($i < $page + 4)) { 
				if ($page == $i) { $class = 'style="font-weight: bold;"'; } 
				$navBar .=' <a '.$class.' class="pointer"  onclick="getOfferStats('.htmlentities($i).')">'.htmlentities(number_format($i)).'</a> ';
				unset($class);
			}
		}
	} 
	
	if ($page < $pages ) {
		$navBar .= ' <a class="pointer"  onclick="getOfferStats('.htmlentities($page + 1).')">Next</a> ';
		$navBar .= ' <a class="pointer"  onclick="getOfferStats('.htmlentities($pages).')">Last</a> ';
	} 
} 


$navBar .= "</div> <div class='clear'></div>"; ?>


<div class="offers-export">
	<a target="_new" href="export">
		<strong>Download to excel</strong>
		<img src="/202-img/icons/16x16/page_white_excel.png" style="margin: 0px 0px -3px 3px;"/>
	</a>
</div>
<div style="clear: both;"></div><? 





$statAccountNickNameBy = $_SESSION['stats202_by'];
$offerNameBy = $_SESSION['stats202_by'];
$statClicksBy = $_SESSION['stats202_by'];
$statImpressionsBy = $_SESSION['stats202_by'];
$statActionsBy = $_SESSION['stats202_by'];
$statEpcBy = $_SESSION['stats202_by'];
$statTotalBy = $_SESSION['stats202_by'];

switch ($_SESSION['stats202_order']) { 
	case "statAccountNickName":
		if ($_SESSION['stats202_by'] == 'ASC')	{	$statAccountNickNameArrow = '&#9650;';		$statAccountNickNameBy = 'DESC'; }
		else 										{	$statAccountNickNameArrow = '&#9660;';		$statAccountNickNameBy = 'ASC'; }
		break;
	case "offerName":
		if ($_SESSION['stats202_by'] == 'ASC')	{	$offerNameArrow = '&#9650;';		$offerNameBy = 'DESC'; }
		else 										{	$offerNameArrow = '&#9660;';		$offerNameBy = 'ASC'; }
		break;
	case "statClicks":
		if ($_SESSION['stats202_by'] == 'ASC')	{	$statClicksArrow = '&#9650;';			$statClicksBy = 'DESC'; }
		else 										{	$statClicksArrow = '&#9660;';			$statClicksBy = 'ASC'; }
		break;
	case "statImpressions":
		if ($_SESSION['stats202_by'] == 'ASC')	{	$statImpressionsArrow = '&#9650;';		$statImpressionsBy = 'DESC'; }
		else 										{	$statImpressionsArrow = '&#9660;';		$statImpressionsBy = 'ASC'; }
		break;
	case "statActions":
		if ($_SESSION['stats202_by'] == 'ASC')	{	$statActionsArrow = '&#9650;';			$statActionsBy = 'DESC'; }
		else 										{	$statActionsArrow = '&#9660;';			$statActionsBy = 'ASC'; }
		break;
	case "statEpc":	
		if ($_SESSION['stats202_by'] == 'ASC')	{	$statEpcArrow = '&#9650;';	$statEpcBy = 'DESC'; }
		else 										{	$statEpcArrow = '&#9660;';	$statEpcBy = 'ASC'; }
		break;
	default:
	case "statTotal":	
		if ($_SESSION['stats202_by'] == 'ASC')	{	$statTotalArrow = '&#9650;';		$statTotalBy = 'DESC'; }
		else 										{	$statTotalArrow = '&#9660;';		$statTotalBy = 'ASC'; }
		break;
	
	
}	?> 


<table cellpadding="0" cellspacing="0" class="offers-table">
	<tr>
		<th class='nowrap left'><a class="pointer" onclick="getOfferStats('', 'statAccountNickName', '<?php echo$statAccountNickNameBy; ?>');">Account <?php echo$statAccountNickNameArrow; ?></a></th>
		<th class='nowrap left'><a class="pointer" onclick="getOfferStats('', 'offerName', '<?php echo$offerNameBy; ?>');">Offer <?php echo$offerNameArrow; ?></a></th>
		<?php if (iphone() == false) { ?><th class='nowrap right'><a class="pointer" onclick="getOfferStats('', 'statImpressions', '<?php echo$statImpressionsBy; ?>');">Impr. <?php echo$statImpressionsArrow; ?></a></th><?php } ?>
		<th class='nowrap right'><a class="pointer" onclick="getOfferStats('', 'statClicks', '<?php echo$statClicksBy; ?>');">Clicks <?php echo$statClicksArrow; ?></a></th>
		<th class='nowrap right'><a class="pointer" onclick="getOfferStats('', 'statActions', '<?php echo$statActionsBy; ?>');">Actions <?php echo$statActionsArrow; ?></a></th>
		<?php if (iphone() == false) { ?><th class='nowrap right'><a class="pointer" onclick="getOfferStats('', 'statEpc', '<?php echo$statEpcBy; ?>');">EPC<?php echo$statEpcArrow; ?></a></th><?php } ?>
		<th class='nowrap right'><a class="pointer" onclick="getOfferStats('', 'statTotal', '<?php echo$statTotalBy; ?>');">Total <?php echo$statTotalArrow; ?></a></th>
	</tr>

	<?php if ($getOfferStats['offerStats']) $offerStats = $getOfferStats['offerStats'][0]['offerStat'];
	for ($x = 0; $x < count($offerStats); $x++) { 
		
		#print_r_html($offerStats[$x]); 
		
		$total['offerStatClicks'] = $total['offerStatClicks'] + $offerStats[$x]['offerStatClicks'];
		$total['offerStatActions'] = $total['offerStatActions'] + $offerStats[$x]['offerStatActions'];
		$total['offerStatImpressions'] = $total['offerStatImpressions'] + $offerStats[$x]['offerStatImpressions'];
		$total['offerStatTotal'] =$total['offerStatTotal'] + $offerStats[$x]['offerStatTotal'];
		$total['offerStatEpc'] = @round($total['offerStatTotal'] / $total['offerStatClicks'], 2);
		
		if ($offerStats[$x]['offerStatClicks'] > 0) { $offerStats[$x]['offerStatClicks'] = number_format($offerStats[$x]['offerStatClicks']); } else { $offerStats[$x]['offerStatClicks'] = '-'; }
		if ($offerStats[$x]['offerStatActions'] > 0) { $offerStats[$x]['offerStatActions'] = number_format($offerStats[$x]['offerStatActions']);} else { $offerStats[$x]['offerStatActions'] = '-'; }
		if ($offerStats[$x]['offerStatImpressions'] > 0) { $offerStats[$x]['offerStatImpressions'] = number_format($offerStats[$x]['offerStatImpressions']);} else { $offerStats[$x]['offerStatImpressions'] = '-'; }
		if ($offerStats[$x]['offerStatTotal'] > 0) { $offerStats[$x]['offerStatTotal'] = '$'.number_format($offerStats[$x]['offerStatTotal'], 2);} else { $offerStats[$x]['offerStatTotal'] = '-'; }
		if ($offerStats[$x]['offerStatEpc'] > 0) { $offerStats[$x]['offerStatEpc'] = '$'.number_format($offerStats[$x]['offerStatEpc'], 2);} else { $offerStats[$x]['offerStatEpc'] = '-'; }
		
		$offerStats[$x]['offerName'] = "({$offerStats[$x]['offerNetworkId']}) {$offerStats[$x]['offerName']}";
		
		$html = @array_map('htmlentities', $offerStats[$x]);
		
		$html['row_class'] = '';
		if ($z == 0) {
			$html['row_class'] = ' alt';
			$z=1;
		} else {
			$z--;
		}
		
		if (iphone() == true) { 
			if (strlen($html['statAccountNickName']) > 10) { $html['statAccountNickName'] = substr($html['statAccountNickName'],0,10).'...'; }  
		} else {
			if (strlen($html['statAccountNickName']) > 30) { $html['statAccountNickName'] = substr($html['statAccountNickName'],0,30).'...'; }  
		}  ?>
	
		<tr class="<?php echo$html['row_class']; ?>">
			<td class="left"><?php echo$html['statAccountNickName']; ?></td>
			<td class="left"><?php echo$html['offerName']; ?></td>
			<?php if (iphone() == false) { ?><td class="right"><?php echo$html['offerStatImpressions']; ?></td><?php } ?>
			<td class="right"><?php echo$html['offerStatClicks']; ?></td>  
			<td class="right"><?php echo$html['offerStatActions']; ?></td>
			<?php if (iphone() == false) { ?><td class="right"><?php echo$html['offerStatEpc']; ?></td><?php } ?>
			<td class="right"><?php echo$html['offerStatTotal']; ?></td>
		</tr>		

	<?php } 
	

	$html['totalSubids'] = htmlentities(number_format($totalSubids));
	$html['totalOfferStatImpressions'] = htmlentities(number_format($totalOfferStatImpressions));
	$html['totalOfferStatClicks'] = htmlentities(number_format($totalOfferStatClicks));
	$html['totalOfferStatActions'] = htmlentities(number_format($totalOfferStatActions));
	$html['totalOfferStatEpc'] = htmlentities('$'.number_format($totalOfferStatEpc, 2));  
	$html['totalOfferStatTotal'] = htmlentities('$'.number_format($totalOfferStatTotal, 2)); ?>
	
	<tr class="bottom">
		<td class="left" colspan="2"><strong>Totals</strong></td>
		<td class="right"><strong><?php echo$html['totalOfferStatImpressions']; ?></strong></td>  
		<td class="right"><strong><?php echo$html['totalOfferStatClicks']; ?></strong></td>
		<td class="right"><strong><?php echo$html['totalOfferStatActions']; ?></strong></td>
		<td class="right"><strong><?php echo$html['totalOfferStatEpc']; ?></strong></td>
		<td class="right"><strong><?php echo$html['totalOfferStatTotal']; ?></strong></td>
	</tr>
</table>

<?php echo$navBar; ?>