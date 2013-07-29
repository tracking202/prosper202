<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php');

AUTH::require_user();



if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	$aff_network_name = trim($_POST['aff_network_name']);
	if (empty($aff_network_name)) { $error['aff_network_name'] = '<div class="error">Type in the name of your affiliate network.</div>'; }
	
	if (!$error) {
		$mysql['aff_network_name'] = mysql_real_escape_string($_POST['aff_network_name']);
		$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
		$mysql['aff_network_time'] = time();
		
		$aff_network_sql = "INSERT INTO `202_aff_networks`
							SET         `user_id`='".$mysql['user_id']."',
										`aff_network_name`='".$mysql['aff_network_name']."',
										`aff_network_time`='".$mysql['aff_network_time']."'";
		$aff_network_result = mysql_query($aff_network_sql) or record_mysql_error($aff_network_sql);
		
		$add_success = true;
	}
}

if (isset($_GET['delete_aff_network_id'])) { 

	$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
	$mysql['aff_network_id'] = mysql_real_escape_string($_GET['delete_aff_network_id']);
	$mysql['aff_network_time'] = time();
	
	
		
	$delete_sql = " UPDATE  `202_aff_networks`
					SET     `aff_network_deleted`='1',
							`aff_network_time`='".$mysql['aff_network_time']."'
					WHERE   `user_id`='".$mysql['user_id']."'
					AND     `aff_network_id`='".$mysql['aff_network_id']."'";
	if ($delete_result = mysql_query($delete_sql) or record_mysql_error($delete_result)) {
		$delete_success = true;
	}
}
	


template_top('Affiliate Networks Setup',NULL,NULL,NULL); ?>
 
<div id="info">
    <h2>Affiliate Network Setup</h2>
    Add the affiliate networks you work with here.
</div>

<table cellspacing="0" cellpadding="0" class="setup">
	<tr valign="top">
		<td>
			<?php if ($error) { ?>
				<div class="warning"><div><h3>There were errors with your submission.</h3></div></div>
			<?php } echo $error['token']; ?>

			<?php if ($add_success == true) { ?>
				<div class="success"><div><h3>Your submission was successful</h3>You have succesfully added an affiliate network to your account.</div></div>
			<?php } ?>

			<?php if ($delete_success == true) { ?>
				<div class="success"><div><h3>You deletion was successful</h3>You have succesfully deleted an affiliate network from your account.</div></div>
			<?php } ?>

			<form method="post" action="<?php echo$_SERVER['REDIRECT_URL']; ?>">
				<table style="margin: 0px auto;">    
					<tr>
						<td colspan="2" style="width: 400px;">
							<h2 class="green">Add Affiliate Network</h2>
							<p style="text-align: justify;">What affiliate companies do you use? Some examples include Copeac, Blooads, and Commission Junction.</p>
						</td>
					</tr>
					<tr><td/><br/></tr>
					<tr>
						<td class="left_caption">Affiliate Network</td>
						<td>
							<input type="text" name="aff_network_name" style="display: inline;"/>
							<input type="submit" value="Add" style="display: inline; margin-left: 10px;"/>
						</td>
					</tr>
				</table>
				<?php echo$error['aff_network_name']; ?> 
			</form>
		</td>
		<td class="setup-right">   
			<h2 class="green">My Affiliate Networks</h2>
			
			<ul>		
			<?  $mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
				$aff_network_sql = "SELECT * FROM `202_aff_networks` WHERE `user_id`='".$mysql['user_id']."' AND `aff_network_deleted`='0' ORDER BY `aff_network_name` ASC";
				$aff_network_result = mysql_query($aff_network_sql) or record_mysql_error($aff_network_sql);
				if (mysql_num_rows($aff_network_result) == 0 ) { 
					?><li>You have not added any networks.</li><?
				}
				
				while ($aff_network_row = mysql_fetch_array($aff_network_result, MYSQL_ASSOC)) {
					$html['aff_network_name'] = htmlentities($aff_network_row['aff_network_name'], ENT_QUOTES, 'UTF-8');
					$html['aff_network_id'] = htmlentities($aff_network_row['aff_network_id'], ENT_QUOTES, 'UTF-8');
					
					printf('<li>%s - <a href="?delete_aff_network_id=%s" style="font-size: 9px;">remove</a></li>', $html['aff_network_name'],$html['aff_network_id']);
					
				} ?> 
			</ul>
		</td>
	</tr>
</table>
		


<?php template_bottom();