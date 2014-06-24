<?
include_once ($_SERVER ['DOCUMENT_ROOT'] . '/202-config/connect.php');

AUTH::require_user ();

if ($_GET ['edit_aff_network_id']) {
	$editing = true;
}

if ($_SERVER ['REQUEST_METHOD'] == 'POST') {
	$aff_network_name = trim ( $_POST ['aff_network_name'] );
	if (empty ( $aff_network_name )) {
		$error ['aff_network_name'] = '<div class="error">Type in the name of your campaign\'s category.</div>';
	}

	//if editing, check to make sure the own the network they are editing
	if ($editing == true) {
		$mysql ['aff_network_id'] = $db->real_escape_string ( $_GET ['edit_aff_network_id'] );
		$mysql ['user_id'] = $db->real_escape_string ( $_SESSION ['user_id'] );
		$aff_network_sql = "SELECT * FROM `202_aff_networks` WHERE `user_id`='" . $mysql ['user_id'] . "' AND `aff_network_id`='" . $mysql ['aff_network_id'] . "'";
		$aff_network_result = $db->query ( $aff_network_sql ) or record_mysql_error ( $aff_network_sql );
		if ($aff_network_result->num_rows == 0) {
			$error ['wrong_user'] = '<div class="error">You are not authorized to edit another users network</div>';
		}
	}
	
	if (! $error) {
		$mysql ['aff_network_name'] = $db->real_escape_string ( $_POST ['aff_network_name'] );
		$mysql ['user_id'] = $db->real_escape_string ( $_SESSION ['user_id'] );
		$mysql ['aff_network_time'] = time ();

		if ($editing == true) {
			$aff_network_sql = "UPDATE `202_aff_networks` SET";
		} else {
			$aff_network_sql = "INSERT INTO `202_aff_networks` SET";
		}
		
		$aff_network_sql .= "`user_id`='" . $mysql ['user_id'] . "',
										`aff_network_name`='" . $mysql ['aff_network_name'] . "',
										`aff_network_time`='" . $mysql ['aff_network_time'] . "'";
		if ($editing == true) {
			$aff_network_sql .= "WHERE `aff_network_id`='" . $mysql ['aff_network_id'] . "'";
		}
		$aff_network_result = $db->query ( $aff_network_sql ) or record_mysql_error ( $aff_network_sql );
		
		$add_success = true;
	}
}
 

if ($_GET ['edit_aff_network_id']) {
	
	$mysql ['user_id'] = $db->real_escape_string ( $_SESSION ['user_id'] );
	$mysql ['aff_network_id'] = $db->real_escape_string ( $_GET ['edit_aff_network_id'] );
	
	$aff_network_sql = "SELECT 	* 
						 FROM   	`202_aff_networks`
						 WHERE  	`aff_network_id`='" . $mysql ['aff_network_id'] . "'
						 AND    		`user_id`='" . $mysql ['user_id'] . "'";
	$aff_network_result = $db->query ( $aff_network_sql ) or record_mysql_error ( $aff_network_sql );
	$aff_network_row = $aff_network_result->fetch_assoc();
	
	$html = array_map ( 'htmlentities', $aff_network_row );
	$html ['aff_network_id'] = htmlentities ( $_GET ['edit_aff_network_id'], ENT_QUOTES, 'UTF-8' );

}

//this will override the edit, if posting and edit fail
if (($_SERVER ['REQUEST_METHOD'] == 'POST') and ($add_success != true)) {
	
	$selected ['aff_network_id'] = $_POST ['aff_network_id'];
	$html = array_map ( 'htmlentities', $_POST );
}

if (isset ( $_GET ['delete_aff_network_id'] )) {
	
	$mysql ['user_id'] = $db->real_escape_string ( $_SESSION ['user_id'] );
	$mysql ['aff_network_id'] = $db->real_escape_string ( $_GET ['delete_aff_network_id'] );
	$mysql ['aff_network_time'] = time ();
	
	$delete_sql = " UPDATE  `202_aff_networks`
					SET     `aff_network_deleted`='1',
							`aff_network_time`='" . $mysql ['aff_network_time'] . "'
					WHERE   `user_id`='" . $mysql ['user_id'] . "'
					AND     `aff_network_id`='" . $mysql ['aff_network_id'] . "'";
	if ($delete_result = $db->query ( $delete_sql ) or record_mysql_error ( $delete_result )) {
		$delete_success = true;
	}
}

template_top ( 'Campaign Category Setup', NULL, NULL, NULL );
?>

<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<div class="row">
			<div class="col-xs-4">
				<h6>Campaign Category Setup</h6>
			</div>
			<div class="col-xs-8">
				<div class="<?php if($error) echo "error"; else echo "success";?> pull-right" style="margin-top: 20px;">
					<small>
						<?php if ($error) { ?> 
							<span class="fui-alert"></span> There were errors with your submission. <?php echo $error['token']; ?>
						<?php } ?>
						<?php if ($add_success == true) { ?>
								<?php if($editing == true) { ?>
									<span class="fui-check-inverted"></span> Your submission was successful. You have succesfully edited category.
								<?php } else { ?>
									<span class="fui-check-inverted"></span> Your submission was successful. You have succesfully added a category to your account.
								<?php } ?>
						<?php } ?>
						<?php if ($delete_success == true) { ?>
							<span class="fui-check-inverted"></span> You have succesfully deleted a category from your account.
						<?php } ?>
						
					</small>
				</div>
			</div>
		</div>
	</div>
	<div class="col-xs-12">
		<small>Add categories for the offer you will you work with here. For example you may enter in the affiliate networks you work or you could enter in different categories/niches such as "Lead Gen", "Mobile", "Dating" or "CPS".</small>
	</div>
</div>

<div class="row form_seperator" style="margin-bottom:15px;">
	<div class="col-xs-12"></div>
</div>

<div class="row">
	<div class="col-xs-7">
		<small><strong>Add Campaign Category</strong></small><br/>
		<span class="infotext">What Campaign Categories do you want to use? Some examples include Commission Junction, A4D, Mobile, Dating etc.</span>
				
		<form method="post" action="<?php echo $_SERVER ['REDIRECT_URL']; ?>" class="form-inline" role="form" style="margin:15px 0px;">
			<div class="form-group <?php if($error['aff_network_name']) echo "has-error";?>">
				<label class="sr-only" for="aff_network_name">Traffic source</label>
				<input type="text" class="form-control input-sm" id="aff_network_name" name="aff_network_name" placeholder="Campaign category" value="<?php echo $html['aff_network_name']; ?>">
			</div>
			<button type="submit" class="btn btn-xs btn-p202" <?php if ($network_editing != true) { echo 'id="addCategory"'; }?>><?php if ($network_editing == true) { echo 'Edit'; } else { echo 'Add'; } ?></button>
			<?php if ($editing == true) { ?>
				<button type="submit" class="btn btn-xs btn-danger" onclick="/tracking202/setup/aff_networks.php'; return false;">Cancel</button>
			<?php } ?>
		</form>
	</div>
	<div class="col-xs-4 col-xs-offset-1">
		<div class="panel panel-default">
			<div class="panel-heading">My Campaign Categories</div>
			<div class="panel-body">
				<ul>		
					<?
					$mysql ['user_id'] = $db->real_escape_string ( $_SESSION ['user_id'] );
					$aff_network_sql = "SELECT * FROM `202_aff_networks` WHERE `user_id`='" . $mysql ['user_id'] . "' AND `aff_network_deleted`='0' ORDER BY `aff_network_name` ASC";
					$aff_network_result = $db->query ( $aff_network_sql ) or record_mysql_error ( $aff_network_sql );
					if ($aff_network_result->num_rows == 0) {
						?><li>You have not added any networks.</li><?
					}
					
					while ( $aff_network_row = $aff_network_result->fetch_array (MYSQL_ASSOC) ) {
						$html ['aff_network_name'] = htmlentities ( $aff_network_row ['aff_network_name'], ENT_QUOTES, 'UTF-8' );
						$html ['aff_network_id'] = htmlentities ( $aff_network_row ['aff_network_id'], ENT_QUOTES, 'UTF-8' );
						
						printf ( '<li>%s - <a href="?edit_aff_network_id=%s">edit</a> - <a href="?delete_aff_network_id=%s" onclick="return confirmSubmit(\'Are You Sure You Want To Delete This Campaign Category?\');">remove</a></li>', $html ['aff_network_name'], $html ['aff_network_id'], $html ['aff_network_id'] );
					
					}
					?> 
				</ul>
			</div>
		</div>
	</div>
</div>

<?php template_bottom (); ?>