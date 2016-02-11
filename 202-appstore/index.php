<?php 
include_once(substr(dirname( __FILE__ ), 0,-13) . '/202-config/connect.php'); 

AUTH::require_user(); 

template_top('Prosper202 ClickServer App Store');  

	if ($_POST['update_clickserver_api_key'] == '1') {

		if ($_POST['token'] != $_SESSION['token']) { $error['token'] = 'You must use our forms to submit data.';  }

		if (!preg_match('/\*/', $_POST['clickserver_api_key'])) {
			if (!clickserver_api_key_validate($_POST['clickserver_api_key']) && $mysql['clickserver_api_key'] !='') { $error['clickserver_api_key'] = 'This API Key appears invalid.'; }

			if (!$error || $mysql['clickserver_api_key'] =='') {
					
				$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
				$mysql['clickserver_api_key'] = $db->real_escape_string($_POST['clickserver_api_key']);
				$user_sql = "	UPDATE 	`202_users`
								SET     		`clickserver_api_key`='".$mysql['clickserver_api_key']."'
								WHERE  	`user_id`='".$mysql['user_id']."'";
				$user_result = $db->query($user_sql);

				$update_clickserver_api_key_done = true;
					
			}
		}
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
	
	//make it hide most of the api keys
	$hideChars = 22;
	for ($x = 0; $x < $hideChars; $x++) $hiddenPart .= '*';
	if ($html['clickserver_api_key']) $html['clickserver_api_key'] = $hiddenPart . substr($html['clickserver_api_key'], $hideChars, 99);
	
?>

<!-- Modal -->
<div class="modal fade" id="myModal" tabindex="-1" role="dialog" aria-labelledby="myModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title" id="myModalLabel">Your Prosper202 ClickServer API Key Is Needed To Activate The App Store</h4>
      </div>
      <div class="modal-body">
       <!--  If you've ever registered on the Tracking202.com site, you can quickly login below get your ClickServer API Key. If not, register below to automatically get an API Key. --> 
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
        <!-- <button type="button" class="btn btn-primary">Click Here To Get Your ClickServer API Key</button> -->
      </div>
    </div>
  </div>
</div>

<div class="row home">
  <div class="col-xs-12">
  	<h4><img src="<?php echo get_absolute_url();?>202-img/new/icons/building.svg" alt="ribbon" class="tile-hot-ribbon"> Prosper202 App Store - 1-Click Install Apps & Services</h4>
	
  </div>
</div>
  <br/>
<?php 
if ($html['clickserver_api_key']!=''){?>
 <div class="row account">
	<div class="col-xs-12">
		<h6>Your Prosper202 ClickServer API Key Is Needed To Activate The App Store</h6>
	</div>
	<div class="col-xs-4">
		<div class="panel panel-default account_left">
			<div class="panel-body">
			    Update your Prosper202 ClickServer API Key. Warning: NEVER share your Prosper202 ClickServer API key with anyone!
			</div>
		</div>
	</div>
	<div class="col-xs-8">
		<form class="form-horizontal" style="padding-top:0px;" role="form" method="post" action="">
		<input type="hidden" name="update_clickserver_api_key" value="1" />
		<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" />
			<div class="form-group <?php if($error['clickserver_api_key']) echo "has-error";?>">
				<label for="clickserver_api_key" class="col-xs-4 control-label">My ClickServer API Key:
					<?php if($error['clickserver_api_key']) { ?> <span class="fui-alert" style="font-size: 12px;" data-toggle="tooltip" title="<?php echo $error['clickserver_api_key']; ?>"></span> <?php } ?>
				</label>
				<div class="col-xs-8">
					<input type="text" class="form-control input-sm" id="clickserver_api_key" name="clickserver_api_key" value="<?php echo $html['clickserver_api_key']; ?>">
				</div>
			</div>

			<div class="form-group">
				<div class="col-xs-8 col-xs-offset-4">
					<button class="btn btn-md btn-p202 btn-block" type="submit">Update API Key</button>					
				</div>
			</div>
		</form>
		<br>
		
	</div>
</div>
<div class="row demo-tiles">
<span id="app-placeholder">
    <img src="http://tracking202-static.s3.amazonaws.com/img-appstore.png">
</span>
</div>
 <?php     
}
    
else{
  

			//Initiate curl
			$ch = curl_init();
			// Disable SSL verification
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			// Will return the response, if false it print the response
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			// Set the url
			curl_setopt($ch, CURLOPT_URL, 'http://my.tracking202.com/feed/appstore/');
			// Execute
			$result=curl_exec($ch);
			curl_close($ch);

			$data = json_decode($result, true);
			$count=1;
		
if($data){
		foreach ($data['deals'] as $deal) {
		    if($count%4==1) 
		        echo '<div class="row demo-tiles">';?>
		
		  
				
        <div class="col-xs-3">
          <div class="tile">
          <?php if($deal['app-status']=="popular"){ ?>
          <img src="<?php echo get_absolute_url();?>202-img/new/icons/ribbon.svg" alt="ribbon" class="tile-hot-ribbon">
          <?php }?>
            <img src="<?php echo $deal['app-img'];?>" class="tile-image big-illustration">
            <h3 class="tile-title"><?php echo $deal['title'];?></h3>
            <p><?php echo $deal['app-description'];?></p>
            <?php 
            if($deal['app-install']=='installed'){
            ?>
            <a class="btn btn-primary btn-large btn-block" href="">Installed <span class="fui-check"></span><br>
            <?php echo $deal['app-price'];?>
            </a>
            <?php 
            }
            else if($deal['app-install']=='un-installed'){
                ?>
                <a class="btn btn-inverse btn-large btn-block" href="">Install Now <span class="fui-upload"></span><br>
                <?php echo $deal['app-price'];?>
                </a>
                <?php 
            }
            else if($deal['app-install']=='coming-soon'){
                ?>
                            <a class="btn btn-warning btn-large btn-block" href="">Coming Soon... <span class="fui-time"></span><br>
                            <?php echo $deal['app-price'];?>
                            </a>
                            <?php 
                        }
            ?>
          </div>
        </div>
		<?php  
      if($count%4==0)
          echo '</div>';
      $count++;
       }
        }
		else{
		    echo "Sorry Resources Feed Not Found: Please try again later";
		}

		
}
//end of else		?>
		
<?php template_bottom(); ?>

   