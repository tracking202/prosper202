<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php');

AUTH::require_user();


if ($_GET['edit_ppc_account_id']) {
	$editing = true;
}
elseif ($_GET['edit_ppc_network_id']) {
	$network_editing = true;
	$mysql['ppc_network_id'] = $db->real_escape_string($_GET['edit_ppc_network_id']);
}


if ($_SERVER['REQUEST_METHOD'] == 'POST') {


	if (isset($_POST['ppc_network_name'])) {
		$ppc_network_name = trim($_POST['ppc_network_name']);
		if (empty($ppc_network_name)) { $error['ppc_network_name'] = 'Type in the name the traffic source.'; }

		if (!$error) {
			$mysql['ppc_network_id'] = $db->real_escape_string($_POST['ppc_network_id']);
			$mysql['ppc_network_name'] = $db->real_escape_string($_POST['ppc_network_name']);
			$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
			$mysql['ppc_network_time'] = time();
			if ($network_editing == true) { $ppc_network_sql  = " UPDATE 202_ppc_networks SET"; }
			else {
				$ppc_network_sql = "INSERT INTO `202_ppc_networks` SET";}
				$ppc_network_sql .= " `user_id`='".$mysql['user_id']."',
								  `ppc_network_name`='".$mysql['ppc_network_name']."',
								  `ppc_network_time`='".$mysql['ppc_network_time']."'";
				if ($network_editing == true) { $ppc_network_sql  .= "WHERE ppc_network_id='".$mysql['ppc_network_id']."'"; }
				$ppc_network_result = _mysqli_query($ppc_network_sql) ; //($ppc_network_sql);
				$add_success = true;
				if ($network_editing == true) {
					//if editing true, refresh back with the edit get variable GONE GONE!
					header('location: /tracking202/setup/ppc_accounts.php');
				}
					
		}
	}

	if (isset($_POST['ppc_network_id']) ) {

		$ppc_account_name = trim($_POST['ppc_account_name']);
		$do_edit_ppc_account = trim(filter_input(INPUT_POST, 'do_edit_ppc_account', FILTER_SANITIZE_NUMBER_INT));
		if ($ppc_account_name == '' && $do_edit_ppc_account == '1') { $error['ppc_account_name'] = 'What is the username for this account?'; }

		$ppc_network_id = trim($_POST['ppc_network_id']);
		if ($ppc_network_id == '') { $error['ppc_network_id'] = 'What traffic source is this account attached to?'; }

		if (!$error) {
			//check to see if this user is the owner of the ppc network hes trying to add an account to
			$mysql['ppc_network_id'] = $db->real_escape_string($_POST['ppc_network_id']);
			$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);

			$ppc_network_sql = "SELECT * FROM `202_ppc_networks` WHERE `user_id`='".$mysql['user_id']."' AND `ppc_network_id`='".$mysql['ppc_network_id']."'";
			$ppc_network_result = _mysqli_query($ppc_network_sql) ; //($ppc_network_sql);
			if ($ppc_network_result->num_rows == 0 ) {
				$error['wrong_user'] = 'You are not authorized to add an account to another user\'s traffic source';
			}
		}
		if (!$error) {
			//check to see if this user is the owner of the ppc network hes trying to edit
			$mysql['ppc_network_id'] = $db->real_escape_string($_POST['ppc_network_id']);
			$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);

			$ppc_network_sql = "SELECT * FROM `202_ppc_networks` WHERE `user_id`='".$mysql['user_id']."' AND `ppc_network_id`='".$mysql['ppc_network_id']."'";
			$ppc_network_result = _mysqli_query($ppc_network_sql) ; //($ppc_network_sql);
			if ($ppc_network_result->num_rows == 0 ) {
				$error['wrong_user'] = 'You are not authorized to add an account to another user\'s traffic source'.$ppc_network_sql ;
			}
		}
		if (!$error) {
			//if editing, check to make sure the own the ppc account they are editing
			if ($editing == true) {
				$mysql['ppc_account_id'] = $db->real_escape_string($_GET['edit_ppc_account_id']);
				$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
				$ppc_account_sql = "SELECT COUNT(*) FROM `202_ppc_accounts` WHERE `user_id`='".$mysql['user_id']."' AND `ppc_account_id`='".$mysql['ppc_account_id']."'";
				$ppc_account_result = _mysqli_query($ppc_account_sql) ; //($ppc_account_sql);
				if ($ppc_account_result->num_rows == 0 ) {
					$error['wrong_user'] .= 'You are not authorized to modify another user\'s traffic source account';
				}
			}
		}
			
		if (!$error) {
			$mysql['ppc_network_id'] = $db->real_escape_string($_POST['ppc_network_id']);
			$mysql['ppc_account_name'] = $db->real_escape_string($_POST['ppc_account_name']);
			$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
			$mysql['ppc_account_time'] = time();
			$mysql['pixel_type_id'] = $db->real_escape_string($_POST['pixel_type_id']);
			$mysql['pixel_id'] = $db->real_escape_string($_POST['pixel_id']);

			if (get_magic_quotes_gpc()) {

				$mysql['pixel_code'] = $db->real_escape_string(trim($_POST['pixel_code']));
				
			} else {
				
				$mysql['pixel_code'] = $db->real_escape_string(trim(addslashes($_POST['pixel_code'])));
			}

			if ($editing == true) { $ppc_account_sql  = " UPDATE 202_ppc_accounts SET"; }
			else {                  $ppc_account_sql  = " INSERT INTO 202_ppc_accounts SET"; }

			$ppc_account_sql .= " ppc_account_name='".$mysql['ppc_account_name']."',
														  ppc_network_id='".$mysql['ppc_network_id']."',
														  user_id='".$mysql['user_id']."',
														  ppc_account_time='".$mysql['ppc_account_time']."'";

			if ($editing == true) { $ppc_account_sql  .= "WHERE ppc_account_id='".$mysql['ppc_account_id']."'"; }

			$ppc_account_result = _mysqli_query($ppc_account_sql) ; //($ppc_account_sql);
			$add_success = true;
			if($mysql['pixel_code']!="" && $mysql['pixel_type_id']!=""){

					
				if($editing && $mysql['pixel_id']!=""){
					$pixel_sql="UPDATE 202_ppc_account_pixels SET pixel_code='".$mysql['pixel_code']."', pixel_type_id=".$mysql['pixel_type_id']." WHERE pixel_id=".$mysql['pixel_id']."";
				}
				else{
					$the_ppc_account_id = $db->insert_id !=0 ? $db->insert_id : $mysql['ppc_account_id'];
					$pixel_sql="INSERT INTO 202_ppc_account_pixels (ppc_account_id, pixel_code,pixel_type_id)
							VALUES(".$the_ppc_account_id.",'" 
							.$mysql['pixel_code']."',"
							.$mysql['pixel_type_id'].")";

				}
					
				$ppc_account_result = _mysqli_query($pixel_sql) ;
			}
			if ($editing == true) {
				//if editing true, refresh back with the edit get variable GONE GONE!
				header('location: /tracking202/setup/ppc_accounts.php');
			}

		}
	}
}

if (isset($_GET['delete_ppc_network_id'])) {

	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
	$mysql['ppc_network_id'] = $db->real_escape_string($_GET['delete_ppc_network_id']);
	$mysql['ppc_network_time'] = time();

	$delete_sql = " UPDATE  `202_ppc_networks`
					SET     `ppc_network_deleted`='1',
							`ppc_network_time`='".$mysql['ppc_network_time']."'
					WHERE   `user_id`='".$mysql['user_id']."'
					AND     `ppc_network_id`='".$mysql['ppc_network_id']."'";
	if ($delete_result = _mysqli_query($delete_sql)) { //($delete_result)) {
		$delete_success = true;
	}
}

if (isset($_GET['delete_ppc_account_id'])) {

	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
	$mysql['ppc_account_id'] = $db->real_escape_string($_GET['delete_ppc_account_id']);
	$mysql['ppc_account_time'] = time();

	$delete_sql = " UPDATE  `202_ppc_accounts`
					SET     `ppc_account_deleted`='1',
							`ppc_account_time`='".$mysql['ppc_account_time']."'
					WHERE   `user_id`='".$mysql['user_id']."'
					AND     `ppc_account_id`='".$mysql['ppc_account_id']."'";
	if ($delete_result = _mysqli_query($delete_sql)) {
		$delete_success = true;
	}
}

if ($_GET['edit_ppc_network_id']) {

	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
	$mysql['ppc_network_id'] = $db->real_escape_string($_GET['edit_ppc_network_id']);

	$ppc_network_sql = "SELECT  *
						 FROM   `202_ppc_networks`
						 WHERE  `ppc_network_id`='".$mysql['ppc_network_id']."'
						 AND    `user_id`='".$mysql['user_id']."'";
	$ppc_network_result = _mysqli_query($ppc_network_sql) ;
	$ppc_network_row = $ppc_network_result->fetch_assoc();
	 
	$html['ppc_network_name'] = htmlentities($ppc_network_row['ppc_network_name'], ENT_QUOTES, 'UTF-8');
	 
}

if ($_GET['edit_ppc_account_id']) {

	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
	$mysql['ppc_account_id'] = $db->real_escape_string($_GET['edit_ppc_account_id']);

	$ppc_account_sql = "SELECT  *
						 FROM   `202_ppc_accounts`
						 WHERE  `ppc_account_id`='".$mysql['ppc_account_id']."'
						 AND    `user_id`='".$mysql['user_id']."'";
	$ppc_account_result = _mysqli_query($ppc_account_sql) ; //($ppc_account_sql);
	$ppc_account_row = $ppc_account_result->fetch_assoc();

	$selected['ppc_network_id'] = $ppc_account_row['ppc_network_id'];
	$html['ppc_account_name'] = htmlentities($ppc_account_row['ppc_account_name'], ENT_QUOTES, 'UTF-8');


	$selected['ppc_network_id'] = $ppc_account_row['ppc_network_id'];
	$ppc_account_pixel_sql = "SELECT  *
						 FROM   `202_ppc_account_pixels`
						 WHERE  `ppc_account_id`=".$mysql['ppc_account_id']."";
	//echo $ppc_account_pixel_sql;
	$ppc_account_pixel_result = _mysqli_query($ppc_account_pixel_sql) ; //($ppc_account_sql);
	$ppc_account_pixel_row = $ppc_account_pixel_result->fetch_array(MYSQL_ASSOC);
	$selected['pixel_id'] = $ppc_account_pixel_row['pixel_id'];
	$selected['pixel_type_id'] = $ppc_account_pixel_row['pixel_type_id'];
	
	if ($selected['pixel_type_id'] == 5) {
		$selected['pixel_code'] = stripslashes($ppc_account_pixel_row['pixel_code']);
		$selected['pixel_code'] = htmlentities($selected['pixel_code']);
	}else{
		$selected['pixel_code'] = $ppc_account_pixel_row['pixel_code'];
	}
	

	 
}

if ($error) {
	//if someone happend take the post stuff and add it
	$selected['ppc_network_id'] = $_POST['ppc_network_id'];
	$html['ppc_account_name'] = htmlentities($_POST['ppc_account_name'], ENT_QUOTES, 'UTF-8');

}


template_top('Traffic Sources',NULL,NULL,NULL); ?>

<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<div class="row">
			<div class="col-xs-5">
				<h6>Traffic Source Account Setup</h6>
			</div>
			<div class="col-xs-7">
				<div class="<?php if($error) echo "error"; else echo "success";?> pull-right" style="margin-top: 20px;">
					<small>
						<?php if ($error) { ?> 
							<span class="fui-alert"></span> There were errors with your submission. <?php echo $error['token']; ?>
						<?php } ?>
						<?php if ($add_success == true) { ?> 
							<span class="fui-check-inverted"></span> Your submission was successful. Your changes have been saved.
						<?php } ?>
						<?php if ($delete_success == true) { ?>
							<span class="fui-check-inverted"></span> Your deletion was successful. You have succesfully removed an account.
						<?php } ?>
					</small>
				</div>
			</div>
		</div>
	</div>
	<div class="col-xs-12">
		<small>Add the Traffic Source (PPC, Display, Social, Email etc) you use, and usernames for each account you have.</small>
	</div>
</div>

<div class="row form_seperator" style="margin-bottom:15px;">
	<div class="col-xs-12"></div>
</div>

<div class="row">
	<div class="col-xs-7">
		<div class="row">
			<div class="col-xs-12">
				<small><strong>Add Traffic Source</strong></small><br/>
				<span class="infotext">What Traffic Sources do you use? Some examples include, Facebook, Plentyoffish, BingAds, & Google Adwords.</span>
				
				<form method="post" action="<?php echo $_SERVER['REDIRECT_URL']; ?>" class="form-inline" role="form" style="margin:15px 0px;">
				  <div class="form-group <?php if($error['ppc_network_name']) echo "has-error";?>">
				    <label class="sr-only" for="ppc_network_name">Traffic source</label>
				    <input type="text" class="form-control input-sm" id="ppc_network_name" name="ppc_network_name" placeholder="Traffic source" value="<?php echo $html['ppc_network_name']; ?>">
				  </div>
				  	<button type="submit" class="btn btn-xs btn-p202"><?php if ($network_editing == true) { echo 'Edit'; } else { echo 'Add'; } ?></button>
				  	<?php if ($network_editing == true) { ?>
						<input type="hidden" name="ppc_network_id" value="<?php echo filter_input(INPUT_GET, 'edit_ppc_network_id', FILTER_SANITIZE_NUMBER_INT);?>">
						<button type="submit" class="btn btn-xs btn-danger" onclick="window.location='/tracking202/setup/ppc_accounts.php'; return false;">Cancel</button>
					<?php } ?>
				</form>

			</div>

			<div class="col-xs-12" style="margin-top: 15px;">
				<small><strong>Add Traffic Source Accounts and Pixels</strong></small><br/>
				<span class="infotext">What accounts to do you have with each Traffic Source? For instance, if you have two Facebook accounts, you can add them both here. This way you can track how individual accounts on each source are doing.</span>
				
				<form style="margin:15px 0px;" method="post" action="<?php if ($delete_success == true) { echo $_SERVER['REDIRECT_URL']; }?>" class="form-horizontal" role="form">
				  <div class="form-group <?php if($error['ppc_network_id']) echo "has-error"; ?>" style="margin-bottom: 0px;">
				    <label for="ppc_network_id" class="col-xs-4 control-label" style="text-align: left;">Traffic Source:</label>
				    <div class="col-xs-5">
				      <select class="form-control input-sm" name="ppc_network_id" id="ppc_network_id">
				      <option value="">---</option>
				      <?php  $mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
						$ppc_network_sql = "SELECT * FROM `202_ppc_networks` WHERE `user_id`='".$mysql['user_id']."' AND `ppc_network_deleted`='0' ORDER BY `ppc_network_name` ASC";
						$ppc_network_result = _mysqli_query($ppc_network_sql) ; //($ppc_network_sql);
						while ($ppc_network_row = $ppc_network_result->fetch_array(MYSQL_ASSOC)) {

							$html['ppc_network_name'] = htmlentities($ppc_network_row['ppc_network_name'], ENT_QUOTES, 'UTF-8');
							$html['ppc_network_id'] = htmlentities($ppc_network_row['ppc_network_id'], ENT_QUOTES, 'UTF-8');


							if ($selected['ppc_network_id'] == $ppc_network_row['ppc_network_id']) {
								printf('<option selected="selected" value="%s">%s</option>', $html['ppc_network_id'],$html['ppc_network_name']);
							} else {
								printf('<option value="%s">%s</option>', $html['ppc_network_id'],$html['ppc_network_name']);
							}



						} ?>
				      </select>
				      <input type="hidden" name="do_edit_ppc_account" value="1">
				    </div>
				  </div>

				  <div class="form-group <?php if($error['ppc_account_name']) echo "has-error"; ?>" style="margin-bottom: 0px;">
				    <label for="ppc_account_name" class="col-xs-4 control-label" style="text-align: left;">Account Username:</label>
				    <div class="col-xs-5">
				      <input type="ppc_account_name" class="form-control input-sm" id="ppc_account_name" name="ppc_account_name" value="<?php echo $html['ppc_account_name']; ?>">
				    </div>
				  </div>

				  <div class="form-group" style="margin-bottom: 0px;">
				    <label for="pixel_type_id" class="col-xs-4 control-label" style="text-align: left;">Pixel Type:</label> <span class="fui-info" style="font-size: 12px;" data-toggle="tooltip" title="" data-original-title="Optional: Select the type of pixel this traffic source uses"></span>
				    <div class="col-xs-5">
				      <select class="form-control input-sm" name="pixel_type_id" id="pixel_type_id">
				      <option value="">---</option>
				      <?
						$ppc_network_sql = "SELECT * FROM `202_pixel_types`";
						$ppc_network_result = _mysqli_query($ppc_network_sql) ; //($ppc_network_sql);
						while ($ppc_network_row = $ppc_network_result->fetch_array(MYSQL_ASSOC)) {

							$html['pixel_type'] = htmlentities($ppc_network_row['pixel_type'], ENT_QUOTES, 'UTF-8');
							$html['pixel_type_id'] = htmlentities($ppc_network_row['pixel_type_id'], ENT_QUOTES, 'UTF-8');


							if ($selected['pixel_type_id'] == $ppc_network_row['pixel_type_id']) {
								printf('<option selected="selected" value="%s">%s</option>', $html['pixel_type_id'],$html['pixel_type']);
							} else {
								printf('<option value="%s">%s</option>', $html['pixel_type_id'],$html['pixel_type']);
							}



						} ?>
				      </select>
				      <input type="hidden" name="do_edit_ppc_account" value="1">
				    </div>
				  </div>

				   <div class="form-group" style="margin-bottom: 0px;">
				    <label for="pixel_code" class="col-xs-4 control-label" style="text-align: left;">Pixel Code:</label> <span class="fui-info" style="font-size: 12px;" data-toggle="tooltip" title="" data-original-title="Optional: Ifyou selected a Pixel Type abouve then enter the code for the pixel here. For all pixel tymes, except for Raw, simply type in the url value of the src"></span>
				    <div class="col-xs-5">
						<textarea class="form-control" name="pixel_code" id="pixel_code" rows="3"><?php echo $selected['pixel_code']; ?></textarea>				    
						<input type="hidden" name="pixel_id" value="<?php echo $selected['pixel_id'];?>">
					</div>
				  </div>

				  	<div class="form-group" style="margin-top:7px;">
				    	<div class="col-xs-5 col-xs-offset-4">
				    	<?php if ($editing == true) { ?>
					    	<div class="row">
					    		<div class="col-xs-6">
					    			<button class="btn btn-sm btn-p202 btn-block" type="submit">Edit</button>					
					    		</div>
					    		<div class="col-xs-6">
									<input type="hidden" name="pixel_id" value="<?php echo $selected['pixel_id'];?>">
									<button type="submit" class="btn btn-sm btn-danger btn-block" onclick="window.location='/tracking202/setup/ppc_accounts.php'; return false;">Cancel</button>					    		</div>
					    	</div>
				    	<?php } else { ?>
				    		<button class="btn btn-sm btn-p202 btn-block" type="submit">Add</button>					
						<?php } ?>
						</div>
					</div>

				</form>

			</div>
		</div>
	</div>
	<div class="col-xs-4 col-xs-offset-1">
		<div class="panel panel-default">
			<div class="panel-heading">My Traffic Sources</div>
			<div class="panel-body">
			  
			<ul>
			<?php  $mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
			$ppc_network_sql = "SELECT * FROM `202_ppc_networks` WHERE `user_id`='".$mysql['user_id']."' AND `ppc_network_deleted`='0' ORDER BY `ppc_network_name` ASC";
			$ppc_network_result = _mysqli_query($ppc_network_sql) ; //($ppc_network_sql);
			if ($ppc_network_result->num_rows == 0 ) {
				?>
				<li>You have not added any networks.</li>
				<?
			}

			while ($ppc_network_row = $ppc_network_result->fetch_array(MYSQL_ASSOC)) {

				//print out the PPC networks
				$html['ppc_network_name'] = htmlentities($ppc_network_row['ppc_network_name'], ENT_QUOTES, 'UTF-8');
				$url['ppc_network_id'] = urlencode($ppc_network_row['ppc_network_id']);
				printf('<li>%s - <a href="?edit_ppc_network_id=%s">edit</a> - <a href="?delete_ppc_network_id=%s" onclick="return confirmSubmit(\'Are You Sure You Want To Delete This Traffic Source?\');">remove</a></li>', $html['ppc_network_name'],$url['ppc_network_id'],$url['ppc_network_id']);

				?>
				<ul style="margin-top: 0px;">
				<?

				//print out the individual accounts per each PPC network
				$mysql['ppc_network_id'] = $db->real_escape_string($ppc_network_row['ppc_network_id']);
				$ppc_account_sql = "SELECT * FROM `202_ppc_accounts` WHERE `ppc_network_id`='".$mysql['ppc_network_id']."' AND `ppc_account_deleted`='0' ORDER BY `ppc_account_name` ASC";
				$ppc_account_result = _mysqli_query($ppc_account_sql) ; //($ppc_account_sql);

				while ($ppc_account_row = $ppc_account_result->fetch_array(MYSQL_ASSOC)) {
						
					$html['ppc_account_name'] = htmlentities($ppc_account_row['ppc_account_name'], ENT_QUOTES, 'UTF-8');
					$url['ppc_account_id'] = urlencode($ppc_account_row['ppc_account_id']);
						
					printf('<li>%s - <a href="?edit_ppc_account_id=%s">edit</a> - <a href="?delete_ppc_account_id=%s" onclick="return confirmSubmit(\'Are You Sure You Want To Delete This Account?\');">remove</a></li>', $html['ppc_account_name'],$url['ppc_account_id'],$url['ppc_account_id']);

				}

				?>
				</ul>
				<?

			} ?>
			</ul>
			</div>
		</div>
	</div>
</div>

<?php template_bottom();