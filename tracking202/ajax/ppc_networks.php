<?php include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/connect.php'); 

AUTH::require_user(); ?>

<select class="form-control input-sm" name="ppc_network_id" id="ppc_network_id" onchange="load_ppc_account_id(this.value, 0);">
    <option value=""> -- </option>
    <option value="16777215" <?php if ($_POST['ppc_network_id'] == '16777215') echo 'selected=""'; ?>>[No PPC Network]</option>
	<?php  $mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
		$ppc_network_sql = "SELECT * FROM `202_ppc_networks` WHERE `user_id`='".$mysql['user_id']."' AND `ppc_network_deleted`='0' ORDER BY `ppc_network_name` ASC";
        $ppc_network_result = $db->query($ppc_network_sql) or record_mysql_error($ppc_network_sql);

        while ($ppc_network_row = $ppc_network_result->fetch_array(MYSQLI_ASSOC)) {
            
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