<?php
//include mysql settings
include_once('connect.php');

//check to see if this is already installed, if so don't do anything
if (is_installed() == true) {
if(isset($_GET['customers_api_key']) && $_GET['customers_api_key'] != ''){
    header("Location: /202-login.php?customers_api_key=".$_GET['customers_api_key']);
}else{
    _die("<h6>Already Installed</h6>
    <small>You appear to have already installed Prosper202. To reinstall please clear your old database tables first. <a href='/202-login.php'>Login Now</a></small>");    
}
    
}

$html['user_api'] = htmlentities($_GET['customers_api_key'], ENT_QUOTES, 'UTF-8');
 
info_top(); ?>
	<div class="main col-xs-7 install">
	<center><img src="<?php echo get_absolute_url();?>202-img/prosper202.png"></center>
	
    <?php if($html['user_api'] == ''){?>
	<h6>Let's Start Off By Getting Your API Key</h6>
	<small>Your API Key Activates Prosper202 ClickServer & more. You'll need an active paid subscription to continue. Don't worry if you don't have a subscription yet, you'll be able to get one on the next few screens.</small>
	<br><br>
	<small><strong>IMPORTANT:</strong> Never share your private API key with anyone, it's linked directly to your payment information.</small>
    <?php }else {?>
	<h6>Great We Found Your API Key!</h6>
	<small>Now, let's get that saved and move on to the next step of the Prosper202 ClickServer Installation</small>
	<br><br>
	<small><strong>REMEMBER:</strong> Never share your private API key with anyone.</small>
   <?php }?>	
	<br><br>
		<form method=post action="" id="getapikey" class="form-horizontal" role="form">
            <div class="form-group <?php if ($error['user_email']) echo "has-error";?>">
			    <label for="user_api" class="col-xs-4 control-label"><strong>Prosper202 API Key:</strong><br> <small></label>
			    <div class="col-xs-8">
			      <input type="text" class="form-control input-sm" style="color:black;" id="user_api" name="user_api" value="<?php echo $html['user_api']; ?>" placeholder="Click Button To Get Your API Key" readonly>
			    </div>
			</div>
		  
            <?php if($html['user_api'] == ''){?>
			<a class="btn btn-lg btn-p202 btn-block" href="https://my.tracking202.com/api/customers/login?redirect=get-api">Click Here For Your API Key<span class="fui-check-inverted pull-right"></a>
			<?php }else {?>
			<button class="btn btn-lg btn-p202 btn-block" type="submit">Save API Key & Install Prosper202 ClickServer<span class="fui-check-inverted pull-right"></span></button>
			<?php }?>

		</form>
		</div>
<script type="text/javascript">
$(document).ready(function() {

	$( "#getapikey").submit(function( event ) {
	
		    var apikey = {apikey: $("#user_api").val()};
	
		$.post("<?php echo get_absolute_url();?>202-account/ajax/validate-apikey.php", apikey).done(function(response) {
			var json = $.parseJSON(response);
				if (json.msg === 'Key valid') {
					document.cookie ="user_api="+$("#user_api").val();
					document.location.href="install.php"
				}
				else{
					if (confirm('Your Api Key Is Invalid. Would You Like Help Finding Your Key?')) {
						document.location.href="https://my.tracking202.com/api/customers/login?redirect=get-api"
					} 
				}
	  	});
		  event.preventDefault();
	});
});

</script>	

<?php
if ( isset( $_SERVER["HTTPS"] ) && strtolower( $_SERVER["HTTPS"] ) == "on" ) {
    $strProtocol = 'https://';
} else {
    $strProtocol = 'http://';
}

?>
<img src="https://my.tracking202.com/api/v2/dni/deeplink/cookie/set/<?php echo base64_encode($strProtocol .  $_SERVER['SERVER_NAME'] . get_absolute_url());?>">	
	<?php info_bottom(); 
	
	
