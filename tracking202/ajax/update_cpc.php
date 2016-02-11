<?php include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/connect.php'); 

AUTH::require_user();
	
//check variables

	$from = explode('/', $_POST['from']);
	$from_month = $from[0];
	$from_day = $from[1];
	$from_year = $from[2];

	$to = explode('/', $_POST['to']);
	$to_month = $to[0];
	$to_day = $to[1];
	$to_year = $to[2];

//if from or to, validate, and if validated, set it accordingly

	if  ((!$_POST['from']) || (!$_POST['to'])) { 
		$error['time'] = '<div class="error"><small><span class="fui-alert"></span>Please enter in the dates from and to like this <strong>mm/dd/yyyy</strong></small></div>';      
	} else {
		$clean['from'] = mktime(0,0,0,$from_month,$from_day,$from_year);
		$html['from'] = date('m/d/y g:ia', $clean['from']);  
	                                                                                                             
		$clean['to'] = mktime(23,59,59,$to_month,$to_day,$to_year); 
		$html['to'] = date('m/d/y g:ia', $clean['to']);
	}             

//set mysql variables
	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);	
	
//check affiliate network id, that you own
	if ($_POST['aff_network_id']) {
		$mysql['aff_network_id'] = $db->real_escape_string($_POST['aff_network_id']);    
		$aff_network_sql = "SELECT * FROM 202_aff_networks WHERE aff_network_id='".$mysql['aff_network_id']."' AND user_id='".$mysql['user_id']."'";
		$aff_network_result = $db->query($aff_network_sql) or record_mysql_error($aff_network_sql);
		$aff_network_row = $aff_network_result->fetch_assoc();
		if (!$aff_network_row) { 
			$error['user'] = '<div class="error">You can not modify other peoples cpc history.</div>'; 
		} else {
			$html['aff_network_name'] = htmlentities($aff_network_row['aff_network_name'], ENT_QUOTES, 'UTF-8');
		} 
	} else {
		$html['aff_network_name'] = 'ALL your affiliate networks';		
	}

//check aff_campaign id, that you own
	if ($_POST['aff_campaign_id']) {
		$mysql['aff_campaign_id'] = $db->real_escape_string($_POST['aff_campaign_id']);    
		$aff_campaign_sql = "SELECT * FROM 202_aff_campaigns WHERE aff_campaign_id='".$mysql['aff_campaign_id']."' AND user_id='".$mysql['user_id']."'";
		$aff_campaign_result = $db->query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql);
		$aff_campaign_row = $aff_campaign_result->fetch_assoc();
		if (!$aff_campaign_row) { 
			$error['user'] = '<div class="error">You can not modify other peoples cpc history.</div>'; 
		} else {
			$html['aff_campaign_name'] = htmlentities($aff_campaign_row['aff_campaign_name'], ENT_QUOTES, 'UTF-8');
		} 
	} else {
		$html['aff_campaign_name'] = 'ALL your affiliate campaigns in these affiliate networks';        
	}    
	
//check text_ad id, that you own
	if ($_POST['text_ad_id']) {
		$mysql['text_ad_id'] = $db->real_escape_string($_POST['text_ad_id']);    
		$text_ad_sql = "SELECT * FROM 202_text_ads WHERE text_ad_id='".$mysql['text_ad_id']."' AND user_id='".$mysql['user_id']."'";
		$text_ad_result = $db->query($text_ad_sql) or record_mysql_error($text_ad_sql);
		$text_ad_row = $text_ad_result->fetch_assoc();
		if (!$text_ad_row) { 
			$error['user'] = '<div class="error">You can not modify other peoples cpc history.</div>'; 
		} else {
			$html['text_ad_name'] = htmlentities($text_ad_row['text_ad_name'], ENT_QUOTES, 'UTF-8');
		} 
	} else {
		$html['text_ad_name'] = 'ALL your text ads in these affiliate campaigns';        
	}
	
//check method of promotion, that you own
	if ($_POST['method_of_promotion']) {
		if ($_POST['method_of_promotion'] == 'landingpage') { 
			$html['method_of_promotion'] = 'Landing pages';
			$mysql['method_of_promotion'] = ' click_landing_site_url_id!=0 ';	
		} else {
			$html['method_of_promotion'] = 'Direct links';
			$mysql['method_of_promotion'] = ' click_landing_site_url_id=0 ';	
		}
	} else {
		$html['method_of_promotion'] = 'BOTH direct links and landing pages';        
	} 	
	
//check landing_page id, that you own
	if (($_POST['method_of_promotion'] == 'landingpage') or ($_POST['tracker_type'] == 1))  { 
		if ($_POST['landing_page_id']) {
			$mysql['landing_page_id'] = $db->real_escape_string($_POST['landing_page_id']);    
			$landing_page_sql = "SELECT * FROM 202_landing_pages WHERE landing_page_id='".$mysql['landing_page_id']."' AND user_id='".$mysql['user_id']."'";
			$landing_page_result = $db->query($landing_page_sql) or record_mysql_error($landing_page_sql);
			$landing_page_row = $landing_page_result->fetch_assoc();
			if (!$landing_page_row) { 
				$error['user'] = '<div class="error">You can not modify other peoples cpc history.</div>'; 
			} else {
				$html['landing_page_name'] = htmlentities($landing_page_row['landing_page_nickname'], ENT_QUOTES, 'UTF-8');
			} 
		} else {
			$html['landing_page_name'] = 'ALL your landing pages in these affiliate campaigns';        
		}
	} else {
		$html['landing_page_name'] = 'n/a';  		
	}
	
//check affiliate network id, that you own
	if ($_POST['ppc_network_id']) {
		$mysql['ppc_network_id'] = $db->real_escape_string($_POST['ppc_network_id']);    
		$ppc_network_sql = "SELECT * FROM 202_ppc_networks WHERE ppc_network_id='".$mysql['ppc_network_id']."' AND user_id='".$mysql['user_id']."'";
		$ppc_network_result = $db->query($ppc_network_sql) or record_mysql_error($ppc_network_sql);
		$ppc_network_row = $ppc_network_result->fetch_assoc();
		if (!$ppc_network_row) { 
			$error['user'] = '<div class="error"><small><span class="fui-alert"></span>You can not modify other peoples cpc history.</small></div>'; 
		} else {
			$html['ppc_network_name'] = htmlentities($ppc_network_row['ppc_network_name'], ENT_QUOTES, 'UTF-8');
		} 
	} else {
		$html['ppc_network_name'] = 'ALL your PPC networks';        
	}

//check ppc_account id, that you own
	if ($_POST['ppc_account_id']) {
		$mysql['ppc_account_id'] = $db->real_escape_string($_POST['ppc_account_id']);    
		$ppc_account_sql = "SELECT * FROM 202_ppc_accounts WHERE ppc_account_id='".$mysql['ppc_account_id']."' AND user_id='".$mysql['user_id']."'";
		$ppc_account_result = $db->query($ppc_account_sql) or record_mysql_error($ppc_account_sql);
		$ppc_account_row = $ppc_account_result->fetch_assoc();
		if (!$ppc_account_row) { 
			$error['user'] = '<div class="error">You can not modify other peoples cpc history.</div>'; 
		} else {
			$html['ppc_account_name'] = htmlentities($ppc_account_row['ppc_account_name'], ENT_QUOTES, 'UTF-8');
		} 
	} else {
		$html['ppc_account_name'] = 'ALL your PPC accounts in these PPC networks';        
	}  
	
	if((!is_numeric($_POST['cpc_dollars'])) or (!is_numeric($_POST['cpc_cents']))) { 
		$error['cpc'] = '<div class="error"><small><span class="fui-alert"></span>You did not input a numeric max CPC.</small></div>'; 
	} else {
		$click_cpc = $_POST['cpc_dollars'] . '.' . $_POST['cpc_cents'];
		$html['click_cpc'] = htmlentities('$'.$click_cpc, ENT_QUOTES, 'UTF-8');
		$mysql['click_cpc'] = $db->real_escape_string($click_cpc);
	}
	
		
//echo error
	echo $error['time'] . $error['user'];      

//if there was an error terminate, or else just continue to run
	if ($error) { die(); }  ?>
	
<span class="infotext">Please make sure the following information below is accurate before proceding. When you make your changes the clicks are
	  updated immediately so make sure you set it correctly.<br></br>
	  <em><span class="fui-info"></span> Note: Your update could take a while depending on how many
				clicks you have selected to update, you will know when the
				update is complete, do not click update twice.</em>
</span>

<br></br>

<small>

<?php if ($_POST['tracker_type'] == 0) { ?>
	<strong>Affiliate Network:</strong> <em><?php echo $html['aff_network_name']; ?></em><br/>
	<strong>Campaign:</strong> <em><?php echo $html['aff_campaign_name']; ?></em><br/>
<?php } ?>

	<strong>Text Ad:</strong> <em><?php echo $html['text_ad_name']; ?></em><br/>

<?php if ($_POST['tracker_type'] == 0) { ?>
	<strong>Method of Promotion:</strong> <em><?php echo $html['method_of_promotion']; ?></em><br/>
<?php } ?>

	<strong>Landing Page:</strong> <em><?php echo $html['landing_page_name']; ?></em><br/>
	<strong>PPC Network:</strong> <em><?php echo $html['ppc_network_name']; ?></em><br/>
	<strong>PPC Account:</strong> <em><?php echo $html['ppc_account_name']; ?></em><br/>
	<strong>Date From:</strong> <em><?php echo $html['from']; ?></em><br/>
	<strong>Date To:</strong> <em><?php echo $html['to']; ?></em><br/>
	<strong>Updated CPC:</strong> <em><?php echo $html['click_cpc']; ?></em><br></br>
</small>

<div class="error text-center"><span class="fui-alert"></span>BE VERY SURE YOU WANT TO DO THIS!</div>

<div class="col-xs-12 text-center" style="margin-top: 10px;">
		<input onclick="update_cpc2();" type="button" id="update-cpc-confirm" class="btn btn-sm btn-p202 btn-block" value="Update My CPC">
</div>

<div id="update_cpc2" class="col-xs-12 text-center" style="margin-top: 25px; display:none;">
	<div class="row form_seperator" style="margin-bottom:15px;">
		<div class="col-xs-12"></div>
	</div>

	<div id="update_cpc2_content">
		
	</div>
</div>

<script type="text/javascript">
	function update_cpc2(){
		var element = $("#update_cpc2_content");
		$.post("/tracking202/ajax/update_cpc2.php", $('#cpc_form').serialize(true))
		  .done(function(data) {
		  	$('#update_cpc2').show();
		  	element.html(data);
		});
	}
</script>
