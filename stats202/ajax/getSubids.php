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
	case "subid":
	case "subidActions":	
	case "subidDate":	
	case "subidAmount":	
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
if ($_SESSION['stats202_by']) $get['by'] = ($_SESSION['stats202_by']);
if (!$_SESSION['stats202_by']) $_SESSION['stats202_by'] = 'DESC';
if (!$_SESSION['stats202_limit']) $_SESSION['stats202_limit'] = 100;
if ($_SESSION['stats202_limit'] > 500) $_SESSION['stats202_limit'] = 500; //only allow a 500 limit on this view
if ($_SESSION['stats202_limit']) $get['limit'] = ($_SESSION['stats202_limit']);
if ($_POST['page']) $get['offset'] = $get['limit'] * ($_POST['page']-1);
$query = http_build_query($get);


//build the offers202 api string
$url = TRACKING202_API_URL . "/stats202/getSubids?$query";
#echo "<p>$url</p>"; 

//grab the url
$xml = getUrl($url);
$getSubids = convertXmlIntoArray($xml);
checkForApiErrors($getSubids); 
$getSubids = $getSubids['getSubids'];



$summary = $getSubids['summary'][0];
$total_rows = $summary['total_rows'];
$offset = $summary['offset'];
$limit = $summary['limit'];
$totalSubidActions = $summary['totalSubidActions'];
$totalSubidAmount = $summary['totalSubidAmount'];
$totalSubids = $summary['total_rows'];
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
$results = "<div class='offers-results'>Results <strong>{$html['from']} - {$html['to']}</strong> of about <strong>{$html['results']}</strong> subids.</div>";
echo $results;


//create the navigation bar
$navBar = "<div class='offers-nav'>";
$navBar .= "<strong>Page {$html['page']} of {$html['pages']}</strong>  "; ?>
<? if ($pages > 1) { $navBar .= " <span style='padding: 0px 10px'>&mdash;</span> ";
	if ($page > 1) {
		$navBar .= ' <a  class="pointer" onclick="getSubids('.htmlentities($i).')">First</a> ';
		$navBar .= ' <a  class="pointer" onclick="getSubids('.htmlentities($page - 1).')">Prev</a> ';
	}
	
	if ($pages > 1) {
		for ($i=1; $i <= $pages; $i++) {                         
			if (($i >= $page - 3) and ($i < $page + 4)) { 
				if ($page == $i) { $class = 'style="font-weight: bold;"'; } 
				$navBar .=' <a '.$class.'  class="pointer" onclick="getSubids('.htmlentities($i).')">'.htmlentities(number_format($i)).'</a> ';
				unset($class);
			}
		}
	} 
	
	if ($page < $pages ) {
		$navBar .= ' <a  class="pointer" onclick="getSubids('.htmlentities($page + 1).')">Next</a> ';
		$navBar .= ' <a  class="pointer" onclick="getSubids('.htmlentities($pages).')">Last</a> ';
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




$subidDateBy = $_SESSION['stats202_by'];
$subidBy = $_SESSION['stats202_by'];
$subidActionsBy = $_SESSION['stats202_by'];
$subidAmount = $_SESSION['stats202_by'];


switch ($_SESSION['stats202_order']) { 
	case "statAccountNickName":
		if ($_SESSION['stats202_by'] == 'ASC')	{	$statAccountNickNameArrow = '&#9650;';		$statAccountNickNameBy = 'DESC'; }
		else 										{	$statAccountNickNameArrow = '&#9660;';		$statAccountNickNameBy = 'ASC'; }
		break;
	case "subidActions":
		if ($_SESSION['stats202_by'] == 'ASC')	{	$subidActionsArrow = '&#9650;';			$subidActionsBy = 'DESC'; }
		else 										{	$subidActionsArrow = '&#9660;';			$subidActionsBy = 'ASC'; }
		break;
	case "subidAmount":
		if ($_SESSION['stats202_by'] == 'ASC')	{	$subidAmountArrow = '&#9650;';		$subidAmountBy = 'DESC'; }
		else 										{	$subidAmountArrow = '&#9660;';		$subidAmountBy = 'ASC'; }
		break;
	case "subid":	
		if ($_SESSION['stats202_by'] == 'ASC')	{	$subidArrow = '&#9650;';		$subidBy = 'DESC'; }
		else 										{	$subidArrow = '&#9660;';		$subidBy = 'ASC'; }
		break;
	default:
	case "subidDate":
		if ($_SESSION['stats202_by'] == 'ASC')	{	$subidDateArrow = '&#9650;';			$subidDateBy = 'DESC'; }
		else 										{	$subidDateArrow = '&#9660;';			$subidDateBy = 'ASC'; }
		break;
	
}

?> 

<table cellpadding="0" cellspacing="0" class="offers-table">
	<tr>
		<th class='nowrap left'><a class="pointer" onclick="getSubids('', 'subidDate', '<? echo $subidDateBy; ?>');">Date <? echo $subidDateArrow; ?></a></th>
		<th class='nowrap left'><a class="pointer" onclick="getSubids('', 'statAccountNickName', '<? echo $statAccountNickNameBy; ?>');">Account <? echo $statAccountNickNameArrow; ?></a></th>
		<th class='nowrap left'><a class="pointer" onclick="getSubids('', 'subid', '<? echo $subidBy; ?>');">Subid <? echo $subidArrow; ?></a></th>
		<th class='nowrap right'><a class="pointer" onclick="getSubids('', 'subidActions', '<? echo $subidActionsBy; ?>');">Actions <? echo $subidActionsArrow; ?></a></th>
		<th class='nowrap right'><a class="pointer" onclick="getSubids('', 'subidAmount', '<? echo $subidAmountBy; ?>');">Amount <? echo $subidAmountArrow; ?></a></th>
	</tr>

	<? 
	if ($getSubids['subids'])	$subids = $getSubids['subids'][0]['subid'];
	for ($x = 0; $x < count($subids); $x++) { 
		
		#print_r_html($subids[$x]);
		
		$total['subidActions'] = $total['subidActions'] + $subids[$x]['subidActions'];
		$total['subidAmount'] = $total['subidAmount'] + $subids[$x]['subidAmount'];
		
		if ($subids[$x]['subidActions'] > 0) { $subids[$x]['subidActions'] = number_format($subids[$x]['subidActions']);} else { $subids[$x]['subidActions'] = '-'; }
		if ($subids[$x]['subidAmount'] > 0) { $subids[$x]['subidAmount'] = '$' . number_format($subids[$x]['subidAmount'],2); } else { $subids[$x]['subidAmount'] = '-'; }
		
		$html = @array_map('htmlentities', $subids[$x]);
		if (strlen($html['statAccountNickName']) > 30) { $html['statAccountNickName'] = substr($html['statAccountNickName'],0,30).'...'; }  
		
		$html['row_class'] = '';
		if ($z == 0) {
			$html['row_class'] = ' alt';
			$z=1;
		} else {
			$z--;
		}  ?>
	
		<tr class="<? echo $html['row_class']; ?>">
			<td class="left"><? echo $html['subidDate']; ?></td>
			<td class="left"><? echo $html['statAccountNickName']; ?></td>
			<td class="left"><? echo $html['subid']; ?></td>
			<td class="right"><? echo $html['subidActions']; ?></td>
			<td class="right"><? echo $html['subidAmount']; ?></td>
		</tr>		

	<? } ?>
	
	<? $html['totalSubids'] = htmlentities(number_format($totalSubids));
	$html['totalSubidActions'] = htmlentities(number_format($totalSubidActions));
	$html['totalSubidAmount'] = htmlentities('$'.number_format($totalSubidAmount, 2));  ?>
	
	<tr class="bottom">
		<td class="left" colspan="3"><strong>Totals</strong></td>
		<td class="right"><strong><? echo $html['totalSubidActions']; ?></strong></td>  
		<td class="right"><strong><? echo $html['totalSubidAmount']; ?></strong></td>
	</tr>
</table>

<? echo $navBar; ?>