<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();



$mysql['ppc_network_id'] = mysql_real_escape_string($_POST['ppc_network_id']);      
		$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
		$ppc_account_sql = "SELECT * FROM `202_ppc_accounts` WHERE `user_id`='".$mysql['user_id']."' AND `ppc_network_id`='".$mysql['ppc_network_id']."' AND `ppc_account_deleted`='0' ORDER BY `ppc_account_name` ASC";
		$ppc_account_result = mysql_query($ppc_account_sql) or record_mysql_error($ppc_account_sql);

		if (mysql_num_rows($ppc_account_result) == 0) {
		
			//echo '<div class="error">You have not added any PPC accounts for this PPC network yet.</div>';
		
		} else { ?>
		
		<select name="ppc_account_id" id="ppc_account_id">			
			<option value=""> -- </option> <?
		
			while ($ppc_account_row = mysql_fetch_array($ppc_account_result, MYSQL_ASSOC)) {
	
				$html['ppc_account_id'] = htmlentities($ppc_account_row['ppc_account_id'], ENT_QUOTES, 'UTF-8');
				$html['ppc_account_name'] = htmlentities($ppc_account_row['ppc_account_name'], ENT_QUOTES, 'UTF-8');
				
                if ($_POST['ppc_account_id'] == $ppc_account_row['ppc_account_id']) {
					$selected = 'selected=""';   
				} else {
					$selected = '';  
				}
                
				printf('<option %s value="%s">%s</option>', $selected, $html['ppc_account_id'], $html['ppc_account_name']);  
	
			} ?>
		</select>
	<? }
 