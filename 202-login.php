<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php');
   include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/Mobile_Detect.php');
   include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/vendor/autoload.php');
   use UAParser\Parser;
   $detect = new Mobile_Detect;
   $parser = Parser::create();
   $result = $parser->parse($detect->getUserAgent());
	
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	
	$mysql['user_name'] = $db->real_escape_string($_POST['user_name']);
	
	$user_pass = salt_user_pass($_POST['user_pass']);
	$mysql['user_pass'] = $db->real_escape_string($user_pass);
	
	//check to see if this user exists
	$user_sql = "	SELECT 	* 
					FROM 		202_users  
				 	WHERE 	user_name='".$mysql['user_name']."'
					AND     		user_pass='".$mysql['user_pass']."'";
	$user_result = _mysqli_query($user_sql);
	$user_row = $user_result->fetch_assoc();
		
	if (!$user_row) { 
		$error['user'] = 'Your username or password is incorrect.';
	}
		
	//RECORD THIS USER LOGIN, into user_logs
		$mysql['login_server'] = $db->real_escape_string ( serialize($_SERVER) );
		$mysql['login_session'] = $db->real_escape_string ( serialize($_SESSION) );
		$mysql['login_error'] = $db->real_escape_string ( serialize($error) );
		$mysql['ip_address'] = $db->real_escape_string ( $_SERVER['REMOTE_ADDR'] ); 
		
		$mysql['login_time'] = time();
		
		if ($error) { 
			$mysql['login_success'] = 0;
		} else {
			$mysql['login_success'] = 1;	
		}
	
	//record everything that happend during this crime scene.
		$user_log_sql = "INSERT INTO 			202_users_log
								   SET			user_name='".$mysql['user_name']."',
												user_pass='".$mysql['user_pass']."',
												ip_address='".$mysql['ip_address']."',
												login_time='".$mysql['login_time']."',
												login_success = '".$mysql['login_success']."',
												login_error='".$mysql['login_error']."',
												login_server='".$mysql['login_server']."',
												login_session='".$mysql['login_session']."'";
		$user_log_result = $db->query($user_log_sql) or record_mysql_error($user_log_sql);
	
	if (!$error) {
		
		$ip_id = INDEXES::get_ip_id($_SERVER['HTTP_X_FORWARDED_FOR']);
		$survey_data = getSurveyData($user_row['install_hash']);

		if ($survey_data['modal']) {
			$mysql['modal_status'] = 0;
		} else {
			$mysql['modal_status'] = 1;
		}

		if ($survey_data['vip_perks']) {
			$mysql['vip_perks_status'] = 1;
		} else {
			$mysql['vip_perks_status'] = 0;
		}

		$mysql['ip_id'] = $db->real_escape_string($ip_id);

		$api_sql = "user_last_login_ip_id='".$mysql['ip_id']."', modal_status='".$mysql['modal_status']."', vip_perks_status='".$mysql['vip_perks_status']."'";
   
		//update this users last login_ip_address
		$user_sql = "	UPDATE 	202_users 
						SET		".$api_sql."
					 	WHERE 	user_name='".$mysql['user_name']."'
						AND     		user_pass='".$mysql['user_pass']."'";
		$user_result = _mysqli_query($user_sql);
		
	
		//set session variables			
		$_SESSION['session_fingerprint'] = md5('session_fingerprint' . $_SERVER['HTTP_USER_AGENT'] . session_id());
		$_SESSION['session_time'] = time();
		$_SESSION['user_name'] = $user_row['user_name'];
		$_SESSION['user_id'] = $user_row['user_id'];
		$_SESSION['user_api_key'] = $user_row['user_api_key'];
		$_SESSION['user_stats202_app_key'] = $user_row['user_stats202_app_key'];
		$_SESSION['user_timezone'] = $user_row['user_timezone'];
		//$_SESSION['clickserver_api_key'] = $user_row['clickserver_api_key']; 
		
		//Detect if users is mobile or tablet - if mobile, redirect to mobile view. If tablet, show main view.
		if( $detect->isMobile() && !$detect->isTablet() ){
 			//redirect to mini stats
			header('location: /202-Mobile/mini-stats/');
		} else {
			//redirect to account scree
			header('location: /202-account');
		}

		
	}
		
	$html['user_name'] = htmlentities($_POST['user_name'], ENT_QUOTES, 'UTF-8');
	
}	
	
	info_top(); ?>
	<div class="row">
	<div class="main col-xs-4">
	  <center><img src="202-img/prosper202.png"></center>
      <form class="form-signin form-horizontal" role="form" method="post" action="">
	      <div class="form-group <?php if ($error['user']) echo "has-error";?>">
	      <?php if ($error['user']) { ?>
		            <div class="tooltip right in login_tooltip"><div class="tooltip-arrow"></div>
		            <div class="tooltip-inner"><?php echo $error['user'];?></div></div>
	      <?php } ?>
	        	<input type="text" class="form-control first" name="user_name" placeholder="Username">
	        	<input type="password" class="form-control last" name="user_pass" placeholder="Password">
	        	<a href="202-lost-pass.php" class="text-info forgot-text">I forgot my password/username</a>
	      <button class="btn btn-lg btn-p202 btn-block" type="submit">Sign in</button>
	      </div>
      </form>
     <!-- P202_CS_Login_Page_288x200 -->
<div id='div-gpt-ad-1398648278789-0' style='width:288px; height:200px;'><script type='text/javascript'>googletag.cmd.push(function() { googletag.display('div-gpt-ad-1398648278789-0'); });</script></div>
</div>
    </div>
    </div>

<?php if($result->ua->family == "IE") { ?>
	<script type="text/javascript">
		$(window).load(function(){
		    $('#browser_modal').modal({
		      backdrop: 'static',
		      show: true,
		  	})
		});
	</script>
    <!-- Browser detect modal-->
    <div class="modal fade" id="browser_modal">
	  <div class="modal-dialog">
	    <div class="modal-content">
	      <div class="modal-header">
	        <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
	        <h4 class="modal-title">Internet Explorer Detected</h4>
	      </div>
	      <div class="modal-body">
	        <p>Internet Explorer is not supported by Prosper202 version 1.8 and more.</p>
	        <p>Recommended browsers:</p>
	        <p>
	        	<a href="http://www.google.com/chrome/" target="_blank">Google Chrome <img src="/202-img/chrome.png"></a>
				<a href="http://www.mozilla.org/en-US/firefox/new/‎" target="_blank">Mozilla Firefox <img src="/202-img/firefox.png"></a>
				<a href="http://www.apple.com/safari" target="_blank">Safari (Mac OS X) <img src="/202-img/safari.png"></a>
	        </p>
	      </div>
	      <div class="modal-footer">
	        <button type="button" class="btn btn-default" data-dismiss="modal">Got it!</button>
	      </div>
	    </div><!-- /.modal-content -->
	  </div><!-- /.modal-dialog -->
	</div><!-- /.modal -->
<?php }   

info_bottom(); ?>