<?php include_once(dirname( __FILE__ ) . '/202-config/connect.php');
 	
	info_top(); ?>
	<div class="row" style="position:absolute;left:1em;">
	<div class="main col-xs-4" style="left:5em;width:344px;box-shadow: 0 0 12px 0 rgba(0, 0, 0, 0.1), 0 10px 30px 0 rgba(0, 0, 0, 0.2);">
	  <center><img src="202-img/prosper202.png"></center>
	  <br><center><p>Your Prosper202 ClickServer API License Key Is Missing or Expired</p></center><br>
       <a class='btn btn-lg btn-p202 btn-block' type='submit' href='https://my.tracking202.com/api/customers/login?redirect=get-api'>Click Here To Get & Save Your API Key into Prosper202 ClickServer</a><br><br><br><br><br><br>
<?php 
			
echo "
       <img src='https://my.tracking202.com/api/v2/dni/deeplink/cookie/set/".base64_encode($strProtocol .  $_SERVER['SERVER_NAME'] . get_absolute_url())."'>	
       ";?>
       
    </div>
    </div>

<?php   

info_bottom(); ?>