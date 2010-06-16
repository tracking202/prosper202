<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();


?>

<select name="aff_network_id" id="aff_network_id" onchange="load_aff_campaign_id(this.value, 0);">
    <option value="0"> -- </option>
	<?  $mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
		$aff_network_sql = "SELECT * FROM `202_aff_networks` WHERE `user_id`='".$mysql['user_id']."' AND `aff_network_deleted`='0' ORDER BY `aff_network_name` ASC";
        $aff_network_result = mysql_query($aff_network_sql) or record_mysql_error($aff_network_sql);

        while ($aff_network_row = mysql_fetch_array($aff_network_result, MYSQL_ASSOC)) {
            
            $html['aff_network_name'] = htmlentities($aff_network_row['aff_network_name'], ENT_QUOTES, 'UTF-8');
            $html['aff_network_id'] = htmlentities($aff_network_row['aff_network_id'], ENT_QUOTES, 'UTF-8');
            
            if ($_POST['aff_network_id'] == $aff_network_row['aff_network_id']) {
                $selected = 'selected=""';   
            } else {
                $selected = '';  
            }   
            
            printf('<option %s value="%s">%s</option>', $selected, $html['aff_network_id'],$html['aff_network_name']);

        } ?>
</select>