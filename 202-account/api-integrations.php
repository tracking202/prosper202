<?php


include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/clickserver_api_management.php');

AUTH::require_user();

if ($_GET['cb_status'] == 1) {
        $mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
        $user_sql = "SELECT cb_verified
             FROM 202_users_pref
             WHERE user_id='".$mysql['user_id']."'";
        $user_results = $db->query($user_sql);
        $user_row = $user_results->fetch_assoc();
        if($user_row['cb_verified']) {
                echo '<span class="label label-primary">Verified</span>';
        } else {
                echo '<span class="label label-important">Unverified</span>';
        }
        die();
}

//get all of the user data
$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
$user_sql = "	SELECT 	*
				 FROM   	`202_users` 
				 LEFT JOIN	`202_users_pref` USING (user_id)
				 WHERE  	`202_users`.`user_id`='".$mysql['user_id']."'";
$user_result = $db->query($user_sql);
$user_row = $user_result->fetch_assoc();
$html = array_map('htmlentities', $user_row);

$cb_verified = $user_row['cb_verified'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	if ($_POST['change_cb_key'] == '1') {

        if ($_POST['cb_key'] == '') {
                                
            $error['cb_key'] .= 'Clickbank Secret Key can\'t be empty!';
        }

        if (!$error) {
                                
            $mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
            $mysql['cb_key'] = $db->real_escape_string($_POST['cb_key']);
            $mysql['cb_verified'] = $db->real_escape_string(0);

                if ($mysql['cb_key'] != $user_row['cb_key']) {
                                        
                    $user_sql = "
                                UPDATE
                                    `202_users_pref`
                                SET
                                    `cb_key`='".$mysql['cb_key']."',
                                    `cb_verified`='".$mysql['cb_verified']."'
                                WHERE
                                    `user_id`='".$mysql['user_id']."'
                                ";
                    $user_result = $db->query($user_sql);
                    $cb_verified = false;
                }
                                        
                $change_cb_key = true;                       
        }
    }

    $html = array_merge($html, array_map('htmlentities', $_POST));
}

template_top('API Integrations',NULL,NULL,NULL); 

?>

<div class="row account">
	<div class="col-xs-12">
		<div class="row">
			<div class="col-xs-4">
				<h6>ClickBank Sales Notification</h6>
			</div>
			<div class="col-xs-8">
			<?php if($change_cb_key) { ?>
				<div class="success" style="text-align:right"><small><span class="fui-check-inverted"></span> Your Clickbank secret key was changed succesfully.</small></div>
			<?php } ?>
			<?php if($error['cb_key']) { ?>
				<div class="error" style="text-align:right"><small><span class="fui-alert"></span> <?php echo $error['cb_key'];?></small></div>
			<?php } ?>
			</div>
		</div>
	</div>
	<div class="col-xs-4">
		<div class="row">
			<div class="col-xs-12">
				<div class="panel panel-default account_left">
					<div class="panel-body">
					    If you wish to use Clickbank Sales Notification Service, to update conversions, enter your Secret Key!
					</div>
				</div>
			</div>
			<div class="col-xs-12">
				<div class="panel panel-default account_left">
					<div class="panel-body">
					    <iframe width="100%" height="auto" src="//www.youtube.com/embed/M6zo3XuExL0" frameborder="0" allowfullscreen></iframe>
					</div>
				</div>			
			</div>
		</div>
	</div>

	<div class="col-xs-8">
		<strong><small>Your Clickbank Notification URL is:</small></strong><br/>
		<div class="row">

			<form class="form-horizontal" role="form" method="post" action="">

			<div class="col-xs-9">

				<small>
				<span id="cb_verified">
					<?php if(!$cb_verified) { ?>
			                <span class="label label-important">Unverified</span>
			        <?php } else { ?>
			                <span class="label label-primary">Verified</span>
			        <?php } ?>
			    </span> -
					<em><?php echo $strProtocol.''.getTrackingDomain().'/tracking202/static/cb202.php';?></em>
				</small>

					<input type="hidden" name="change_cb_key" value="1" />
					<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" />
					<div class="form-group" style="margin-top: 20px;">
						<label for="cb_key" class="col-xs-5 control-label" style="text-align:left">Clickbank Secret Key:</label>
						<div class="col-xs-7">
						    <input type="text" class="form-control input-sm" id="cb_key" name="cb_key" value="<?php echo $html['cb_key']; ?>">
						</div>
					</div>
			</div>

			<div class="col-xs-3">
				<a id="cb_status" class="btn btn-xs btn-warning btn-block">Check status</a>
				<br/>
				<div class="form-group">
				    <div class="col-xs-12">
						<button class="btn btn-xs btn-p202 btn-block" type="submit">Update Secret Key</button>				
					</div>
				</div>
			</div>

			</form>
		</div>
	</div>
</div>


<?php template_bottom();