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

#print_r_html($_SESSION);

//build the get query for the offers202 restful api
$get = array();
$get['apiKey'] = $_SESSION['user_api_key'];
$get['stats202AppKey'] = $_SESSION['user_stats202_app_key'];
$get['dateFrom'] = $dates['from_date'];
$get['dateTo'] = $dates['to_date'];
if ($_SESSION['stats202_order']) $get['order'] = ($_SESSION['stats202_order']);
if ($_SESSION['stats202_by']) $get['by'] = ($_SESSION['stats202_by']);
if (!$_SESSION['stats202_by']) $_SESSION['stats202_by'] = 'DESC';
$get['limit'] = $_SESSION['stats202_limit'];
if ($_POST['page']) $get['offset'] = $get['limit'] * ($_POST['page']-1);
$query = http_build_query($get);


//build the offers202 api string
$url = TRACKING202_API_URL . "/stats202/getStats?$query";
#echo "$url<br/><br/>";

//grab the url
$xml = getUrl($url);
$getStats = convertXmlIntoArray($xml);
checkForApiErrors($getStats); 
$getStats = $getStats['getStats'];



$summary = $getStats['summary'][0];
$total_rows = $summary['total_rows'];
$offset = $summary['offset'];
$limit = $summary['limit'];
$totalStatImpressions = $summary['totalStatImpressions'];
$totalStatClicks = $summary['totalStatClicks'];
$totalStatActions = $summary['totalStatActions'];
$totalStatTotal = $summary['totalStatTotal'];
$totalStatEpc = $summary['totalStatEpc'];
$totalSubids = $summary['total_rows'];

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
if ($total_rows < $to) {
	$to = $total_rows;
}
if (!$total_rows) {
	$from = 0;
}

$html['from'] = htmlentities(number_format($from));
$html['to'] = htmlentities(number_format($to));
$html['results'] = htmlentities(number_format($total_rows));
$results = "<div class='offers-results'>Results <strong>{$html['from']} - {$html['to']}</strong> of about <strong>{$html['results']}</strong> accounts.</div>";
echo $results;


//create the navigation bar
$navBar = "<div class='offers-nav'>";
$navBar .= "<strong>Page {$html['page']} of {$html['pages']}</strong>  "; ?>
<?php if ($pages > 1) { $navBar .= " <span style='padding: 0px 10px'>&mdash;</span> ";
	if ($page > 1) {
		$navBar .= ' <a class="pointer"  onclick="getStats('.htmlentities($i).')">First</a> ';
		$navBar .= ' <a class="pointer"  onclick="getStats('.htmlentities($page - 1).')">Prev</a> ';
	}
	
	if ($pages > 1) {
		for ($i=1; $i <= $pages; $i++) {                         
			if (($i >= $page - 3) and ($i < $page + 4)) { 
				if ($page == $i) { $class = 'style="font-weight: bold;"'; } 
				$navBar .=' <a '.$class.' class="pointer"  onclick="getStats('.htmlentities($i).')">'.htmlentities(number_format($i)).'</a> ';
				unset($class);
			}
		}
	} 
	
	if ($page < $pages ) {
		$navBar .= ' <a class="pointer"   onclick="getStats('.htmlentities($page + 1).')">Next</a> ';
		$navBar .= ' <a class="pointer"  onclick="getStats('.htmlentities($pages).')">Last</a> ';
	} 
} 

$navBar .= "</div> <div class='clear'></div>"; ?>


<div class="offers-export">
	<a target="_new" href="export">
		<strong>Download to excel</strong>
		<img src="/202-img/icons/16x16/page_white_excel.png" style="margin: 0px 0px -3px 3px;"/>
	</a>
</div>
<div style="clear: both;"></div><?php 




$statAccountNickNameBy = $_SESSION['stats202_by'];
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
		<th class='nowrap left'><a class="pointer" onclick="getStats('', 'statAccountNickName', '<?php echo $statAccountNickNameBy; ?>');">Account <?php echo $statAccountNickNameArrow; ?></a></th>
		<?php if (iphone() == false) { ?><th class='nowrap right'><a class="pointer" onclick="getStats('', 'statImpressions', '<?php echo $statImpressionsBy; ?>');">Impr. <?php echo $statImpressionsArrow; ?></a></th><?php } ?>
		<th class='nowrap right'><a class="pointer" onclick="getStats('', 'statClicks', '<?php echo $statClicksBy; ?>');">Clicks <?php echo $statClicksArrow; ?></a></th>
		<th class='nowrap right'><a class="pointer" onclick="getStats('', 'statActions', '<?php echo $statActionsBy; ?>');">Actions <?php echo $statActionsArrow; ?></a></th>
		<?php if (iphone() == false) { ?><th class='nowrap right'><a class="pointer" onclick="getStats('', 'statEpc', '<?php echo $statEpcBy; ?>');">EPC<?php echo $statEpcArrow; ?></a></th><?php } ?>
		<th class='nowrap right'><a class="pointer" onclick="getStats('', 'statTotal', '<?php echo $statTotalBy; ?>');">Total <?php echo $statTotalArrow; ?></a></th>
	</tr>

	<?php if ($getStats['stats'])	$stats = $getStats['stats'][0]['stat'];
	for ($x = 0; $x < count($stats); $x++) { 
		
		#print_r_html($stats[$x]);
		
		$total['statClicks'] = $total['statClicks'] + $stats[$x]['statClicks'];
		$total['statActions'] = $total['statActions'] + $stats[$x]['statActions'];
		$total['statImpressions'] = $total['statImpressions'] + $stats[$x]['statImpressions'];
		$total['statTotal'] =$total['statTotal'] + $stats[$x]['statTotal'];
		$total['statEpc'] = @round($total['statTotal'] / $total['statClicks'], 2);
		
		if ($stats[$x]['statClicks'] > 0) { $stats[$x]['statClicks'] = number_format($stats[$x]['statClicks']); } else { $stats[$x]['statClicks'] = '-'; }
		if ($stats[$x]['statActions'] > 0) { $stats[$x]['statActions'] = number_format($stats[$x]['statActions']);} else { $stats[$x]['statActions'] = '-'; }
		if ($stats[$x]['statImpressions'] > 0) { $stats[$x]['statImpressions'] = number_format($stats[$x]['statImpressions']);} else { $stats[$x]['statImpressions'] = '-'; }
		if ($stats[$x]['statTotal'] > 0) { $stats[$x]['statTotal'] = '$'.number_format($stats[$x]['statTotal'], 2);} else { $stats[$x]['statTotal'] = '-'; }
		if ($stats[$x]['statEpc'] > 0) { $stats[$x]['statEpc'] = '$'.number_format($stats[$x]['statEpc'], 2);} else { $stats[$x]['statEpc'] = '-'; }
		
		$html = @array_map('htmlentities', $stats[$x]);
		
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
	
		<tr class="<?php echo $html['row_class']; ?>">
			<td class="left"><?php echo $html['statAccountNickName']; ?></td>
			<?php if (iphone() == false) { ?><td class="right"><?php echo $html['statImpressions']; ?></td><?php } ?>
			<td class="right"><?php echo $html['statClicks']; ?></td>  
			<td class="right"><?php echo $html['statActions']; ?></td>
			<?php if (iphone() == false) { ?><td class="right"><?php echo $html['statEpc']; ?></td><?php } ?>
			<td class="right"><?php echo $html['statTotal']; ?></td>
		</tr>		

	<?php }

	$html['totalStatImpressions'] = htmlentities(number_format($totalStatImpressions));
	$html['totalStatClicks'] = htmlentities(number_format($totalStatClicks));
	$html['totalStatActions'] = htmlentities(number_format($totalStatActions));
	$html['totalStatEpc'] = htmlentities('$'.number_format($totalStatEpc, 2));  
	$html['totalStatTotal'] = htmlentities('$'.number_format($totalStatTotal, 2)); ?>
	
	<tr class="bottom">
		<td class="left"><strong>Totals</strong></td>
		<td class="right"><strong><?php echo $html['totalStatImpressions']; ?></strong></td>  
		<td class="right"><strong><?php echo $html['totalStatClicks']; ?></strong></td>
		<td class="right"><strong><?php echo $html['totalStatActions']; ?></strong></td>
		<td class="right"><strong><?php echo $html['totalStatEpc']; ?></strong></td>
		<td class="right"><strong><?php echo $html['totalStatTotal']; ?></strong></td>
	</tr>
</table>

<?php echo $navBar; ?>