<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

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

	if  ((!$_POST['from']) and (!$_POST['to'])) { 
		$error['time'] = '<div class="error">Please enter in the dates from and to like this <strong>mm/dd/yyyy</strong></div>';      
	}
		$clean['from'] = mktime(0,0,0,$from_month,$from_day,$from_year);
		$html['from'] = date('m/d/y g:ia', $clean['from']);  
	                                                                                                             
		$clean['to'] = mktime(23,59,59,$to_month,$to_day,$to_year); 
		$html['to'] = date('m/d/y g:ia', $clean['to']);             

//set mysql variables
	$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);	
	
//check affiliate network id, that you own
	if ($_POST['aff_network_id']) {
		$mysql['aff_network_id'] = mysql_real_escape_string($_POST['aff_network_id']);    
		$aff_network_sql = "SELECT * FROM 202_aff_networks WHERE aff_network_id='".$mysql['aff_network_id']."' AND user_id='".$mysql['user_id']."'";
		$aff_network_result = mysql_query($aff_network_sql) or record_mysql_error($aff_network_sql);
		$aff_network_row = mysql_fetch_assoc($aff_network_result);
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
		$mysql['aff_campaign_id'] = mysql_real_escape_string($_POST['aff_campaign_id']);    
		$aff_campaign_sql = "SELECT * FROM 202_aff_campaigns WHERE aff_campaign_id='".$mysql['aff_campaign_id']."' AND user_id='".$mysql['user_id']."'";
		$aff_campaign_result = mysql_query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql);
		$aff_campaign_row = mysql_fetch_assoc($aff_campaign_result);
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
		$mysql['text_ad_id'] = mysql_real_escape_string($_POST['text_ad_id']);    
		$text_ad_sql = "SELECT * FROM 202_text_ads WHERE text_ad_id='".$mysql['text_ad_id']."' AND user_id='".$mysql['user_id']."'";
		$text_ad_result = mysql_query($text_ad_sql) or record_mysql_error($text_ad_sql);
		$text_ad_row = mysql_fetch_assoc($text_ad_result);
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
			$mysql['landing_page_id'] = mysql_real_escape_string($_POST['landing_page_id']);    
			$landing_page_sql = "SELECT * FROM 202_landing_pages WHERE landing_page_id='".$mysql['landing_page_id']."' AND user_id='".$mysql['user_id']."'";
			$landing_page_result = mysql_query($landing_page_sql) or record_mysql_error($landing_page_sql);
			$landing_page_row = mysql_fetch_assoc($landing_page_result);
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
		$mysql['ppc_network_id'] = mysql_real_escape_string($_POST['ppc_network_id']);    
		$ppc_network_sql = "SELECT * FROM 202_ppc_networks WHERE ppc_network_id='".$mysql['ppc_network_id']."' AND user_id='".$mysql['user_id']."'";
		$ppc_network_result = mysql_query($ppc_network_sql) or record_mysql_error($ppc_network_sql);
		$ppc_network_row = mysql_fetch_assoc($ppc_network_result);
		if (!$ppc_network_row) { 
			$error['user'] = '<div class="error">You can not modify other peoples cpc history.</div>'; 
		} else {
			$html['ppc_network_name'] = htmlentities($ppc_network_row['ppc_network_name'], ENT_QUOTES, 'UTF-8');
		} 
	} else {
		$html['ppc_network_name'] = 'ALL your PPC networks';        
	}

//check ppc_account id, that you own
	if ($_POST['ppc_account_id']) {
		$mysql['ppc_account_id'] = mysql_real_escape_string($_POST['ppc_account_id']);    
		$ppc_account_sql = "SELECT * FROM 202_ppc_accounts WHERE ppc_account_id='".$mysql['ppc_account_id']."' AND user_id='".$mysql['user_id']."'";
		$ppc_account_result = mysql_query($ppc_account_sql) or record_mysql_error($ppc_account_sql);
		$ppc_account_row = mysql_fetch_assoc($ppc_account_result);
		if (!$ppc_account_row) { 
			$error['user'] = '<div class="error">You can not modify other peoples cpc history.</div>'; 
		} else {
			$html['ppc_account_name'] = htmlentities($ppc_account_row['ppc_account_name'], ENT_QUOTES, 'UTF-8');
		} 
	} else {
		$html['ppc_account_name'] = 'ALL your PPC accounts in these PPC networks';        
	}  
	
	if((!is_numeric($_POST['cpc_dollars'])) or (!is_numeric($_POST['cpc_cents']))) { 
		$error['cpc'] = '<div class="error">You did not input a numeric max CPC.</div>'; 
	} else {
		$click_cpc = $_POST['cpc_dollars'] . '.' . $_POST['cpc_cents'];
		$html['click_cpc'] = htmlentities('$'.$click_cpc, ENT_QUOTES, 'UTF-8');
		$mysql['click_cpc'] = mysql_real_escape_string($click_cpc);
	}
	
		
//echo error
	echo $error['time'] . $error['user'];      

//if there was an error terminate, or else just continue to run
	if ($error) { die(); }  ?>
	

<table style="margin: 0px auto;">
	<tr>
		<th colspan="2"><h3 class="green">Double Check Your Update CPC Settings</h3></th>
	</tr>
	<tr>
		<td class="left_caption"><img src="/202-img/icons/16x16/exclamation.png" align="right"></td>
		<td>
			<p>
				Please make sure the following information below is accurate<br/>
				before preceding. When you make your changes the clicks are<br/>
				updated for immediately so make sure you set it correctly.<br/><br>
				Note: Your update could take a while depending on how many</br>
				clicks you have selected to update, you will know when the</br>
				update is complete, do not click update twice.</br>
			</p>
		</td>
	<tr>
	<? if ($_POST['tracker_type'] == 0) { ?>
	<tr>
		<td class="left_caption">Affiliate Network</td>
		<td><? echo $html['aff_network_name']; ?></td>
	</tr>                                             
	<tr>
		<td class="left_caption">Campaign</td>
		<td><? echo $html['aff_campaign_name']; ?><td>
	</tr>
	<? } ?>
	<tr>
		<td class="left_caption">Text Ad</td>
		<td><? echo $html['text_ad_name']; ?></td>
	</tr>
	<? if ($_POST['tracker_type'] == 0) { ?>
	<tr>
		<td class="left_caption">Method of Promotion</td>
		<td><? echo $html['method_of_promotion']; ?></td>
	</tr>
	<? } ?>
	<tr valign="top">
		<td class="left_caption">Landing Page</td>
		<td><? echo $html['landing_page_name']; ?></td>
	</tr>
	<tr>
		<td class="left_caption">PPC Network</td>
		<td><? echo $html['ppc_network_name']; ?></td>
	</tr>
	<tr>
		<td class="left_caption">PPC Account</td>
		<td><? echo $html['ppc_account_name']; ?></td>
	</tr>
	<tr>
		<td class="left_caption">From</td>
		<td><? echo $html['from']; ?></td>
	</tr>
	<tr>
		<td class="left_caption">To</td>
		<td><? echo $html['to']; ?></td>
	</tr>
	<tr>
		<td class="left_caption">Updated CPC</td>
		<td><? echo $html['click_cpc']; ?></td>
	</tr>
	<tr>
		<td colspan="2"><p style="font-weight: bold; color: #900; text-align: center;">BE VERY SURE YOU WANT TO DO THIS!</td>
	</tr>
	<tr valign="middle">
		<td colspan="2" style="text-align: center;"><button onclick="update_cpc2();">Update My CPC</button> <img id="update_cpc2_loading" style="display: none;" src="/202-img/loader-small.gif"/></td>
	</tr>
</table>

<div id="update_cpc2" style="width: 500px; margin: 0px auto;"></div>