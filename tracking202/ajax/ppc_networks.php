<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user(); ?>

<select name="ppc_network_id" id="ppc_network_id" onchange="load_ppc_account_id(this.value, 0);">
    <option value=""> -- </option>
	<?  $mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
		$ppc_network_sql = "SELECT * FROM `202_ppc_networks` WHERE `user_id`='".$mysql['user_id']."' AND `ppc_network_deleted`='0' ORDER BY `ppc_network_name` ASC";
        $ppc_network_result = mysql_query($ppc_network_sql) or record_mysql_error($ppc_network_sql);

        while ($ppc_network_row = mysql_fetch_array($ppc_network_result, MYSQL_ASSOC)) {
            
			$html['ppc_network_name'] = htmlentities($ppc_network_row['ppc_network_name'], ENT_QUOTES, 'UTF-8');
            $html['ppc_network_id'] = htmlentities($ppc_network_row['ppc_network_id'], ENT_QUOTES, 'UTF-8');
            
            if ($_POST['ppc_network_id'] == $ppc_network_row['ppc_network_id']) {
                $selected = 'selected=""';   
            } else {
                $selected = '';  
            }
            
            printf('<option %s value="%s">%s</option>', $selected, $html['ppc_network_id'],$html['ppc_network_name']);

        } ?>
</select>