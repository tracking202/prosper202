<?php  
ob_start();

function template_top($title = 'Prosper202 ClickServer') { global $navigation; global $version;
	$user_data = get_user_data_feedback($_SESSION['user_id']);
?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en"> 
<head>

<title><?php echo $title; ?></title>
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="description" content="description" />
<meta name="keywords" content="keywords"/>
<meta name="copyright" content="Prosper202, Inc" />
<meta name="author" content="Prosper202, Inc" />
<meta name="MSSmartTagsPreventParsing" content="TRUE"/>

<meta name="robots" content="noindex, nofollow" />
<meta http-equiv="Content-Script-Type" content="text/javascript" />
<meta http-equiv="Content-Style-Type" content="text/css" />
<meta http-equiv="imagetoolbar" content="no"/>
  
<link rel="shortcut icon" href="/202-img/favicon.gif" type="image/ico"/> 
<!-- Loading Bootstrap -->
<link href="/202-css/css/bootstrap.min.css" rel="stylesheet">
<!-- Loading Flat UI -->
<link href="/202-css/css/flat-ui.css" rel="stylesheet">
<!-- Loading Font Awesome -->
<link href="https://netdna.bootstrapcdn.com/font-awesome/4.0.3/css/font-awesome.css" rel="stylesheet">
<!-- Loading Tags Input CSS -->
<link href="/202-css/css/bootstrap-tokenfield.min.css" rel="stylesheet">
<link href="/202-css/css/tokenfield-typeahead.min.css" rel="stylesheet">
<!-- Loading Custom CSS -->
<link href="/202-css/custom.css" rel="stylesheet">
<!--[if lt IE 9]>
      <script src="https://oss.maxcdn.com/libs/html5shiv/3.7.0/html5shiv.js"></script>
      <script src="https://oss.maxcdn.com/libs/respond.js/1.4.2/respond.min.js"></script>
<![endif]-->
<!-- Load JS here -->
<script src="https://ajax.googleapis.com/ajax/libs/jquery/2.0.3/jquery.min.js"></script>
<script type="text/javascript" src="https://code.jquery.com/ui/1.10.3/jquery-ui.js"></script>
<script type="text/javascript" src="/202-js/jquery-ui-1.10.3.custom.min.js"></script>
<script type="text/javascript" src="/202-js/bootstrap.min.js"></script>
<script type="text/javascript" src="/202-js/flatui-radio.js"></script>
<script type="text/javascript" src="/202-js/flatui-checkbox.js"></script>
<script type="text/javascript" src="/202-js/jquery.validate.min.js"></script>
<script type="text/javascript" src="/202-js/bootstrap-tokenfield.min.js"></script>
<script type="text/javascript" src="/202-js/typeahead.bundle.js"></script>
<script type="text/javascript" src="/202-js/tablesort.min.js"></script>
<script type="text/javascript" src="/202-js/custom.js"></script>
<script type="text/javascript" src="/202-js/iio-rum.min.js"></script>		
<?php switch ($navigation[1]) {
	
	case "tracking202": ?>
	<?php break;

case "202-account": ?>
<?php if(($navigation[1] == "202-account") AND !$navigation[2]) { ?>
<script type="text/javascript" src="/202-js/home.js"></script>
<?php } ?>

<script type="text/javascript" src="/202-js/bootstrap-switch.js"></script>
<script type="text/javascript" src="/202-js/account.min.js"></script>
<?php break; } ?>

</head>
<body>

<!-- START MAIN CONTAINER -->
<div class="container">
<div class="main_wrapper">
	<div class="row">
  		<div class="col-xs-3">
  			<!-- this is the prosper202 top-left logo/banner placement -->
			<script type="text/javascript" charset="utf-8">
			var is_ssl = ("https:" == document.location.protocol);
			var asset_url = is_ssl ? "https://ads.tracking202.com/prosper202-cs-topleft/" : "<?php echo TRACKING202_ADS_URL; ?>/prosper202-cs-topleft/";
			document.write(unescape("%3Ciframe%20class%3D%22advertise-top-left%22%20src%3D%22"+asset_url+"%22%20scrolling%3D%22no%22%20frameborder%3D%220%22%3E%3C/iframe%3E"));
			</script>
		</div>
  		<div class="col-xs-9">
	  		<nav class="navbar navbar-default" role="navigation">
				<ul class="nav navbar-nav">
					<li <?php if (($navigation[1] == '202-account') AND !$navigation[2]) { echo 'class="active";'; } ?>><a href="/202-account/" id="HomePage">Home</a></li>			      
				    <li <?php if ($navigation[1] == 'tracking202') { echo 'class="active";'; } ?>><a href="/tracking202/" id="ClickServerPage">Prosper202 ClickServer</a></li>
				    <li <?php if ($navigation[1] == 'offers202') { echo 'class="active";'; } ?>><a href="/offers202/" id="Offers202Page">Offers202</a></li>
				    <li <?php if ($navigation[1] == '202-resources') { echo 'class="active";'; } ?>><a href="/202-resources/" id="ResourcesPage">Featured Resources</a></li>
				</ul>
				<ul class="nav navbar-nav navbar-right">
					<li id="account-dropdown" class="dropdown <?php if ($navigation[1] == '202-account' AND $navigation[2]) { echo 'active'; } ?>">
					    <a href="#" class="dropdown-toggle" data-toggle="dropdown">My Account <?php if($user_data['vip_perks_status']) echo '<span class="label label-important" id="notification">1</span>';?><b class="caret"></b></a>
					    <span class="dropdown-arrow"></span>
					    <ul class="dropdown-menu">
					        <li <?php if ($navigation[2] == 'account.php') { echo 'class="active";'; } ?>><a href="/202-account/account.php" id="PersonalSettingsPage">Personal Settings</a></li>
					        <li <?php if ($navigation[2] == 'vip-perks.php') { echo 'class="active";'; } ?>><a href="/202-account/vip-perks.php" id="VIPPerksPage">VIP Perks Profile</a> <?php if($user_data['vip_perks_status']) echo '<span class="label label-important" id="notification-perks">1</span>';?></li>
					        <li <?php if ($navigation[2] == 'clickservers.php') { echo 'class="active";'; } ?>><a href="/202-account/clickservers.php" id="ClickServerManagementPage">ClickServer Management</a></li>
					        <li <?php if ($navigation[2] == 'api-integrations.php') { echo 'class="active";'; } ?>><a href="/202-account/api-integrations.php" id="3rdPartyAPIPage">3rd Party API Integrations</a></li>
					        <li <?php if ($navigation[2] == 'administration.php') { echo 'class="active";'; } ?>><a href="/202-account/administration.php" id="SettingsPage">Settings<span class="fui-gear icon-navbar"></span></a></li>
					        <li <?php if ($navigation[2] == 'help.php') { echo 'class="active";'; } ?>><a href="/202-account/help.php" id="HelpPage">Help<span class="fui-question icon-navbar"></span></a></li>
					    </ul>
					</li>		      
					<li><a href="/202-account/signout.php" id="SignoutPage">Sign Out<span class="fui-exit icon-navbar"></span></a></li>
				</ul>  		      	    
	       	</nav>
  		</div>
	</div>
	<div id="update_needed"></div>
	
	<?php if ($navigation[1] == 'tracking202') {  include_once($_SERVER['DOCUMENT_ROOT'] . '/tracking202/_config/top.php'); } ?>
	<div class="main" <?php if ($navigation[2] == 'setup') { echo 'style="border-top-left-radius:0px;"'; } ?>>

			<?php if ($navigation[1] == 'tracking202') {
				if(($navigation[2] == 'setup') or ($navigation[2] == 'overview') or ($navigation[2] == 'analyze') or ($navigation[2] == 'update')){
					include_once($_SERVER['DOCUMENT_ROOT'] . '/tracking202/_config/sub-menu.php');
				} 
			} ?>
		
	<?php } function template_bottom() { global $version; 
		$user_data = get_user_data_feedback($_SESSION['user_id']);?>
	</div>
	

	<div style="clear: both;"></div>
	<div class="row footer">
		<div class="col-xs-12">
		Thank you for marketing with <a href="http://prosper202.com" target="_blank">Prosper202</a>
		&middot; 
		<a href="/202-account/help.php">Help</a>
		&middot; 
		<a href="http://prosper202.com/apps/docs/" target="_blank">Documentation</a>
		&middot; 
		<a href="http://prosper202.com/apps/donate/" target="_blank">Donate</a>
		&middot; 
		<a href="http://support.tracking202.com" target="_blank">Forum</a>
		&middot; 
		
		<?php if ($_SESSION['update_needed'] == true) { ?>
		 	<strong>Your Prosper202 ClickServer <?php echo $version; ?> is out of date. <a href="/202-account/auto-upgrade.php">1-Click Upgrade</a> - <a href="http://my.tracking202.com/202-login.php?rd=cs202-NA==" target="_blank">Manual upgrade</a>.</strong>
		 <?php } else { ?>
		 	Your Prosper202 ClickServer <?php echo $version; ?> is up to date.
		 <?php } ?>
		 
		 <br><br>Local time: <?php echo date(DATE_RFC2822); ?>

		 <br><br><a rel="license" href="http://my.tracking202.com/license/" target="_blank">Copyright &copy; <?php echo date("Y") ?> Blue Terra LLC. All rights reserved</a>.
	</div>
	</div>
</div>
</div>

<!-- this is the prosper202 support widget -->
<?php
	if(clickserver_api_key_validate($user_data['api_key'], 'get', 'auth', 'db')){
		$member_status = "Pro";
	} else {
		$member_status = "Not Pro";
	}
?>

<script id="IntercomSettingsScriptTag">
  window.intercomSettings = {
  	user_id: "<?php echo $user_data['install_hash']; ?>",
    email: "<?php echo $user_data['user_email']; ?>",
    user_hash: "<?php echo $user_data['user_hash'];?>",
    created_at: <?php echo $user_data['time_stamp']; ?>,
    "widget": {
    	"activator": "#IntercomDefaultWidget"
  	},
    Active_Subscription: "<?php echo $member_status;?>",
    API_key: "<?php echo $user_data['api_key'];?>",
    ClickServer_Version: "<?php echo $version; ?>",
    PHP_Version: "<?php echo phpversion(); ?>",
    MYSQL_Version: "<?php echo mysqli_get_client_info(); ?>",
    app_id: "hciib3ia"
  };
</script>
<script>(function(){var w=window;var ic=w.Intercom;if(typeof ic==="function"){ic('reattach_activator');ic('update',intercomSettings);}else{var d=document;var i=function(){i.c(arguments)};i.q=[];i.c=function(args){i.q.push(args)};w.Intercom=i;function l(){var s=d.createElement('script');s.type='text/javascript';s.async=true;s.src='https://static.intercomcdn.com/intercom.v1.js';var x=d.getElementsByTagName('script')[0];x.parentNode.insertBefore(s,x);}if(w.attachEvent){w.attachEvent('onload',l);}else{w.addEventListener('load',l,false);}};})()</script>

<?php if (!$user_data['modal_status']) { 
	$data = getSurveyData($user_data['install_hash']);?>

	<script type="text/javascript">
	  $(window).load(function(){
	    $('#survey-modal').modal({
	      backdrop: 'static',
	      show: true,
	  	})
	  });
	</script>

<!-- Start survey modal -->
      <div id="survey-modal" class="modal fade" role="dialog" aria-hidden="true">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h4 class="modal-title">Prosper202 VIP Perks</h4>
            </div>
            <div class="modal-body">
              <span class="infotext">Wouldn't you love to have new campaign opportunities, private campaigns, business relationships, discounts and special offers and more handed to you? Now you can with the Prosper202 VIP Perk program.<br></br>
                Fill out your profile information to customize your Prosper202 VIP Perks experience. The information will be used to uniquely match you up with coupons, discounts, and enhanced payouts or exclusive offers from top Affiliate Networks, Ad Networks, Tool and Hosting providers and more.</span>
               <span id="perks-error" class="small error" style="display:none; position:absolute; right: 23px; margin-top: 39px;"><span class="fui-alert"></span> Whoops! Looks like you forget to answer some questions.</span>
		  
              <form class="form-horizontal" role="form" id="survey-form">
                <?php foreach ($data['question_groups'] as $question_group) { ?>
		            <h6><?php echo $question_group['title'];?></h6>
		                  
		            <div class="row form_seperator">
		                <div class="col-xs-12"></div>
		            </div>

		            <?php foreach ($data['questions'] as $question) { 
		                if ($question_group['id'] == $question['group_id']) { 
		                	$highlighted = false;

		                	if ($question['answer']) {
		                		if($question['answer'] == 'Yes') {
			                        $answer = 'Yes';
			                    } else {
			                        $answer = 'No';
			                    }
		                	} else {
		                		 $answer = false;

		                		 if ($question['highlighted']) {
			                        $highlighted = true;
			                    }
		                	}
		                ?>
		                    <div class="form-group">
		                    <label for="<?php echo $question['id'];?>" class="col-sm-8 control-label"><?php echo $question['name'];?> <?php if($highlighted) echo '<span class="label label-important">New!</span>'; ?></label>
		                    <div class="col-sm-4">
		                      <label class="radio radio-inline">
		                        <input type="radio" name="<?php echo $question['id'];?>" value="Yes" data-toggle="radio" required <?php if($answer == 'Yes') echo "checked"; ?>>
		                        Yes
		                      </label>
		                      <label class="radio radio-inline">
		                        <input type="radio" name="<?php echo $question['id'];?>" value="No" data-toggle="radio" <?php if($answer == 'No') echo "checked"; ?>>
		                        No
		                      </label>
		                    </div>
		                </div>
		               <?php } ?>
		                
		            <?php } ?>

		        <?php } ?>

            </div>
            <div class="modal-footer">
              <img style="display:none;left: -25px; top: 12px;" id="perks-loading" src="/202-img/loader-small.gif">
              <a href="#" class="btn btn-wide btn-p202" id="survey-form-submit">Submit answers</a>
            </div>
            </form>
          </div>
        </div>        
      </div>
<!-- End survey modal -->
<?php } ?>

</body>
<?php } ?>
