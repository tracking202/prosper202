<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();


$count = $_POST['counter'];
$count = $count + 1;  
$html['count'] = htmlentities($count, ENT_QUOTES, 'UTF-8');

?>

<div id="area_<? echo $count; ?>">
	<select name="aff_campaign_id_<? echo $count; ?>" id="aff_campaign_id_<? echo $count; ?>" onchange="">
		<option value="0"> -- </option> 	
		<? 	$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
			$aff_campaign_sql = "SELECT aff_campaign_id, aff_campaign_name, aff_network_name FROM 202_aff_campaigns LEFT JOIN 202_aff_networks USING (aff_network_id) WHERE 202_aff_campaigns.user_id='".$mysql['user_id']."' AND aff_campaign_deleted='0' AND aff_network_deleted=0  ORDER BY aff_network_name ASC";
			$aff_campaign_result = mysql_query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql);
			while ($aff_campaign_row = mysql_fetch_assoc($aff_campaign_result)) { 
				$html['aff_campaign_id'] = htmlentities($aff_campaign_row['aff_campaign_id'], ENT_QUOTES, 'UTF-8');
				$html['aff_campaign_name'] = htmlentities($aff_campaign_row['aff_campaign_name'], ENT_QUOTES, 'UTF-8');
				$html['aff_network_name'] = htmlentities($aff_campaign_row['aff_network_name'], ENT_QUOTES, 'UTF-8');
				printf('<option value="%s">%s: %s</option>', $html['aff_campaign_id'], $html['aff_network_name'], $html['aff_campaign_name']); 
			} ?>
	</select>
	<a class="onclick_color" onclick="remove_area(<? echo $count; ?>);">[remove]</a>
</div>

<img id="load_aff_campaign_<? echo $count; ?>_loading" style="display: none;" src="/202-img/loader-small.gif"/>
<div id="load_aff_campaign_<? echo $count; ?>"></div>
