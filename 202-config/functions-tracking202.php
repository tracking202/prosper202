<?php 


//This function will return true, if a user is logged in correctly, and false, if they are not.

function send_email($to,$subject,$message,$from,$type_id) {
	global $server_row;
	
	//add spam compliancy to email
	
		
	    	////////////////////////////////////////////////////////////////////////////////
	    
	    	//$header = $mail->make_header($from,$to, $subject, $priority,$cc, $bcc);
		
	    	if ($from == $_SERVER['SERVER_ADMIN']) { $from_name = 'Tracking202'; } else { $from_name = $from; }
	    	
	    	$header = "From: " . $from_name . " <" . $from . "> \r\n";
	    	$header .= "Reply-To: ".$from." \r\n";
	    	$header .=  "To: " . $to . " \r\n";
	    	$header .=  "Subject: " . $subject . " \r\n";
	    	$header .= "Content-Type: text/html; charset=\"iso-8859-1\" \r\n";
	    	$header .= "Content-Transfer-Encoding: 8bit \r\n";
	    	$header .= "MIME-Version: 1.0 \r\n";
	
	    	////////////////////////////////////////////////////////////////////////////////
	    	
	    	mail($to,$from,$message,$header);
	 
	    	
	//record email in mysql database
	
	//get information from sender
		$mysql['email_from'] = mysql_real_escape_string($from);
		$user_sql = "SELECT user_id FROM users_info WHERE user_email='".$mysql['email_from']."'";
		$user_result = _mysql_query($user_sql) ; ; ; //($user_sql);
		$user_row = mysql_fetch_assoc($user_result);
		$mysql['email_from_user_id'] = mysql_real_escape_string($user_row['user_id']);    
	
	//get information from receiever
		$mysql['email_to'] = mysql_real_escape_string($to);
		$user_sql = "SELECT user_id FROM users_info WHERE user_email='".$mysql['email_to']."'";
		$user_result = _mysql_query($user_sql) ; ; //($user_sql);
		$user_row = mysql_fetch_assoc($user_result);
		$mysql['email_to_user_id'] = mysql_real_escape_string($user_row['user_id']);    

		
	//get server information
		$site_url_address = 'http://' . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
		$site_url_id = INDEXES::get_site_url_id($site_url_address);
		 
		$ip_id = INDEXES::get_ip_id($_SERVER['HTTP_X_FORWARDED_FOR']);
		
		$mysql['site_url_id'] = mysql_real_escape_string($site_url_id);
		$mysql['ip_id'] = mysql_real_escape_string($ip_id);
		$mysql['email_time'] = time();
		$mysql['email_subject'] = mysql_real_escape_string($subject);
		$mysql['email_message'] = mysql_real_escape_string($message);
		$mysql['email_type_id'] = mysql_real_escape_string($type_id);
		
		 
	//record email in mysql database
		$record_sql = "INSERT  INTO    emails 
								SET     email_to_user_id = '" . $mysql['email_to_user_id'] . "',
										email_from_user_id = '" . $mysql['email_from_user_id'] . "',    
										email_to = '" . $mysql['email_to'] . "',    
										email_from = '" . $mysql['email_from'] . "',
										ip_id = '" . $mysql['ip_id'] . "',
										email_time = '" . $mysql['email_time'] . "',
										email_subject = '" . $mysql['email_subject'] . "',
										email_message = '" . $mysql['email_message'] . "',
										email_type_id = '" . $mysql['email_type_id'] . "',
										site_url_id = '" . $mysql['site_url_id'] . "'";
		$record_result = _mysql_query($record_sql);  ; //($record_sql);
		
}

function record_mysql_error($sql)
{
	global $server_row;
										 
	//record the mysql error
		$clean['mysql_error_text'] = mysql_error(); 

	//if on dev server, echo the error
		
			echo $sql . '<br/><br/>' .$clean['mysql_error_text'] .'<br/><br/>';   die();
	
		
		 
		$ip_id = INDEXES::get_ip_id($_SERVER['HTTP_X_FORWARDED_FOR']);
		$mysql['ip_id'] = mysql_real_escape_string($ip_id);     
		
		$site_url = 'http://'.$_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
		$site_id = INDEXES::get_site_url_id($site_url); 
		$mysql['site_id'] = mysql_real_escape_string($site_id); 

		$mysql['user_id'] = mysql_real_escape_string(strip_tags($_SESSION['user_id']));
		$mysql['mysql_error_text'] = mysql_real_escape_string($clean['mysql_error_text']);
		$mysql['mysql_error_sql'] = mysql_real_escape_string($sql);
		$mysql['script_url'] = mysql_real_escape_string(strip_tags($_SERVER['SCRIPT_URL'])); 
		$mysql['server_name'] = mysql_real_escape_string(strip_tags($_SERVER['SERVER_NAME'])); 
		$mysql['mysql_error_time'] = time();
									 
		$report_sql = "INSERT     INTO  202_mysql_errors
								SET     mysql_error_text='".$mysql['mysql_error_text']."',
										mysql_error_sql='".$mysql['mysql_error_sql']."',
										user_id='".$mysql['user_id']."',
										ip_id='".$mysql['ip_id']."',
										site_id='".$mysql['site_id']."',
										mysql_error_time='".$mysql['mysql_error_time']."'"; 
		$report_query = _mysql_query($report_sql);
											 
	//email administration of the error
		$to = $_SERVER['SERVER_ADMIN'];
		$subject = 'mysql error reported - ' . $site_url;
		$message = '<b>A mysql error has been reported</b><br/><br/>
		
					time: '. date('r',time()) . '<br/>
					server_name: ' . $_SERVER['SERVER_NAME'] . '<br/><br/>
					
					user_id: ' . $_SESSION['user_id'] . '<br/>
					script_url: ' . $site_url . '<br/>
					$_SERVER: ' . serialize($_SERVER) . '<br/><br/>
					
					. . . . . . . . <br/><br/>
												 
					_mysql_query: ' . $sql . '<br/><br/>
					 
					mysql_error: ' . $clean['mysql_error_text'];
		$from = $_SERVER['SERVER_ADMIN'];
		$type = 3; //type 3 is mysql_error
	
		//send_email($to,$subject,$message,$from,$type);
	
	//report error to user and end page ?>
		<div class="warning" style="margin: 40px auto; width: 450px;">
			<div>
				<h3>A database error has occured, the webmaster has been notified</h3>
				<p>If this error persists, you may email us directly: <? printf('<a href="mailto:%s">%s</a>',$_SERVER['SERVER_ADMIN'],$_SERVER['SERVER_ADMIN']); ?></p>
			</div>
		</div>
		
	   
		<? template_bottom($server_row);  die();
}



function dollar_format($amount, $cpv = false) {
	if ($cpv == true) {
		$decimals = 5;
	} else {
		$decimals = 2;
	}
	
	if ($amount >= 0) {
		$new_amount = "\$".sprintf("%.".$decimals."f",$amount);
	} else { 
		$new_amount = "\$".sprintf("%.".$decimals."f",substr($amount,1,strlen($amount)));
		$new_amount = '('.$new_amount.')';    
	}
	
	return $new_amount;
} 
							 




function display_calendar($page, $show_time, $show_adv, $show_bottom, $show_limit, $show_breakdown, $show_type, $show_cpc_or_cpv = true, $show_adv_breakdown = false) { 
  	global $navigation;
 
	AUTH::set_timezone($_SESSION['user_timezone']);
  		 
	$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
	$user_sql = "SELECT * FROM 202_users_pref WHERE user_id=".$mysql['user_id'];
	$user_result = _mysql_query($user_sql);
	$user_row = mysql_fetch_assoc($user_result);
	
	$html['user_pref_aff_network_id'] = htmlentities($user_row['user_pref_aff_network_id'], ENT_QUOTES, 'UTF-8');
	$html['user_pref_aff_campaign_id'] = htmlentities($user_row['user_pref_aff_campaign_id'], ENT_QUOTES, 'UTF-8');
	$html['user_pref_text_ad_id'] = htmlentities($user_row['user_pref_text_ad_id'], ENT_QUOTES, 'UTF-8');
	$html['user_pref_method_of_promotion'] = htmlentities($user_row['user_pref_method_of_promotion'], ENT_QUOTES, 'UTF-8');
	$html['user_pref_landing_page_id'] = htmlentities($user_row['user_pref_landing_page_id'], ENT_QUOTES, 'UTF-8');
	$html['user_pref_ppc_network_id'] = htmlentities($user_row['user_pref_ppc_network_id'], ENT_QUOTES, 'UTF-8');
	$html['user_pref_ppc_account_id'] = htmlentities($user_row['user_pref_ppc_account_id'], ENT_QUOTES, 'UTF-8');
	$html['user_pref_group_1'] = htmlentities($user_row['user_pref_group_1'], ENT_QUOTES, 'UTF-8');
	$html['user_pref_group_2'] = htmlentities($user_row['user_pref_group_2'], ENT_QUOTES, 'UTF-8');
	$html['user_pref_group_3'] = htmlentities($user_row['user_pref_group_3'], ENT_QUOTES, 'UTF-8');
	$html['user_pref_group_4'] = htmlentities($user_row['user_pref_group_4'], ENT_QUOTES, 'UTF-8');
	
	$time = grab_timeframe();   
	$html['from'] = date('m/d/Y - G:i', $time['from']);
	$html['to'] = date('m/d/Y - G:i', $time['to']);  
	$html['country'] = htmlentities($user_row['user_pref_country'], ENT_QUOTES, 'UTF-8');
	$html['ip'] = htmlentities($user_row['user_pref_ip'], ENT_QUOTES, 'UTF-8');
	$html['referer'] = htmlentities($user_row['user_pref_referer'], ENT_QUOTES, 'UTF-8');
	$html['keyword'] = htmlentities($user_row['user_pref_keyword'], ENT_QUOTES, 'UTF-8'); 
	$html['page'] = htmlentities($page, ENT_QUOTES, 'UTF-8'); ?>
	

	<form onsubmit="return false;" id="user_prefs">
		<input type="hidden" name="duration" value="1"/>
		<input type="hidden" name="user_pref_adv" id="user_pref_adv" value="<? if ($user_row['user_pref_adv'] == '1') { echo '1'; } ?>" />
		
		<table class="s-top" cellspacing="0" cellpadding="0" id="s-top">
			<tr valign="top">
				<td class="s-top-left"/>
				<td class="s-top-middle">
					<table class="s-top-middle-table" cellspacing="0" cellpadding="0">
						<tr>
							<td class="s-top-middle-table-left">Refine your search:</td>
							<td >
								<table cellspacing="0" cellpadding="0" class="s-top-middle-table-right" <? if ($show_time == false) { echo 'style="display:none;"'; } ?>>
									<tr>
										<td>
											Start Date: <input onclick=" $('from_cal').style.display='block'; $('to_cal').style.display='none';  unset_user_pref_time_predefined();"   class="s-input s-input-date" type="text" name="from" id="from" value="<? echo $html['from']; ?>" onkeydown="$('from_cal').style.display='none'; unset_user_pref_time_predefined();"/>
											
											<div id="from_cal" class="scal tinyscal" style="position: absolute; z-index: 10; display: none;"></div>
											<script type="text/javascript">
												var options = ({
												     updateformat: 'mm/dd/yyyy - 0:00',
	            									     month:<? echo date('m', $time['from']); ?>,
												     year:<? echo date('Y', $time['from']); ?>,
												     day: <? echo date('d', $time['from']); ?>
												});
												var from_cal = new scal('from_cal','from', options);
											</script>
											
										</td>
										<td>
											End Date: <input onclick=" $('to_cal').style.display='block'; $('from_cal').style.display='none';  unset_user_pref_time_predefined();"  class="s-input s-input-date" type="text" name="to" id="to" value="<? echo $html['to']; ?>" onkeydown="$('to_cal').style.display='none'; unset_user_pref_time_predefined();"/>		
											
											<div id="to_cal" class="scal tinyscal" style="position: absolute; z-index: 10; display: none;"></div>
											<script type="text/javascript">
												var options = ({
												     updateformat: 'mm/dd/yyyy - 23:59',
	            									     month:<? echo date('m', $time['from']); ?>,
												     year:<? echo date('Y', $time['from']); ?>,
												     day: <? echo date('d', $time['from']); ?>
												});
												var to_cal = new scal('to_cal','to', options);
											</script>
											
										</td>
										<td><select class="s-input" name="user_pref_time_predefined" id="user_pref_time_predefined" onchange="set_user_pref_time_predefined();">
											<option value="">Custom Date</option>                                       
											<option <? if ($time['user_pref_time_predefined'] == 'today') { echo 'selected=""'; } ?> value="today">Today</option>
											<option <? if ($time['user_pref_time_predefined'] == 'yesterday') { echo 'selected=""'; } ?> value="yesterday">Yesterday</option>
											<option <? if ($time['user_pref_time_predefined'] == 'last7') { echo 'selected=""'; } ?> value="last7">Last 7 Days</option>
											<option <? if ($time['user_pref_time_predefined'] == 'last14') { echo 'selected=""'; } ?> value="last14">Last 14 Days</option>
											<option <? if ($time['user_pref_time_predefined'] == 'last30') { echo 'selected=""'; } ?> value="last30">Last 30 Days</option>
											<option <? if ($time['user_pref_time_predefined'] == 'thismonth') { echo 'selected=""'; } ?> value="thismonth">This Month</option>
											<option <? if ($time['user_pref_time_predefined'] == 'lastmonth') { echo 'selected=""'; } ?> value="lastmonth">Last Month</option>
											<option <? if ($time['user_pref_time_predefined'] == 'thisyear') { echo 'selected=""'; } ?> value="thisyear">This Year</option>
											<option <? if ($time['user_pref_time_predefined'] == 'lastyear') { echo 'selected=""'; } ?> value="lastyear">Last Year</option>
											<option <? if ($time['user_pref_time_predefined'] == 'alltime') { echo 'selected=""'; } ?> value="alltime">All Time</option>
										</select></td>
									</tr>
								</table>
							</td>
						</tr>
					</table>
				</td>
				<td class="s-top-right"/>
			</tr>
		</table>
	   
	   <div class="s-bottom" >
	   <? if ($navigation[1] == 'tracking202') { ?>
			<div id="s-main" <? if ($show_adv == false) { echo 'style="display:none;"'; } ?>>
				<table class="s-table" cellspacing="0" cellpadding="0">
					<tr>
						<td >
							<table cellspacing="0" cellpadding="0" class="s-table-left">
								<tr>
									<td>PPC Network/Account</td>
									<td><img id="ppc_network_id_div_loading" style="display: none;" src="/202-img/loader-small.gif"/><div id="ppc_network_id_div"></div></td>
									<td class="s-td-slim"><img id="ppc_account_id_div_loading" style="display: none;" src="/202-img/loader-small.gif"/><div id="ppc_account_id_div"></div></td> 
								</tr>
							</table>
						</td>
						<td>
							<table cellspacing="0" cellpadding="0" class="s-table-right">
								<tr>
									<td>Keyword</td>
									<td><input name="keyword" id="keyword" type="text" value="<? echo $html['keyword']; ?>"/></td> 
								</tr>
							</table>
						</td>
					</tr>
					<tr>
						<td>
							 <table cellspacing="0" cellpadding="0" class="s-table-left">
								<tr>
									<td>Aff Network/Campaign</td>
									<td><img id="aff_network_id_div_loading" style="display: none;" src="/202-img/loader-small.gif"/><div id="aff_network_id_div" ></div></td>
									<td class="s-td-slim"><img id="aff_campaign_id_div_loading" style="display: none;" src="/202-img/loader-small.gif"/><div id="aff_campaign_id_div"></div></td>
								</tr>
							</table>
						</td>
						<td >
							<table cellspacing="0" cellpadding="0" class="s-table-right">
								<tr>
									<td>Visitor IP</td>
									<td><input name="ip" id="ip" type="text" value="<? echo $html['ip']; ?>"/> </td> 
								</tr>
							</table>
						</td>  
					</tr>
				</table>
			</div>
			
			<div class="s-adv" id="s-adv" style="<? if (($user_row['user_pref_adv'] != '1') or ($show_adv == false)) { echo 'display: none;'; } ?>">
				<div class="s-border" <? if ($show_adv == false) { echo 'style="display:none;"'; } ?>></div>
				<table class="s-table" cellspacing="0" cellpadding="0">
					<tr>
						<td>
							<table cellspacing="0" cellpadding="0" class="s-table-left">
								<tr>
									<td>Text Ad</td>
									<td><img id="text_ad_id_div_loading" style="display: none;" src="/202-img/loader-small.gif"/><div id="text_ad_id_div"></div></td>
								</tr>
							</table>
						</td>
						<td rowspan="3"><img id="ad_preview_div_loading" style="display: none;" src="/202-img/loader-small.gif"/><div id="ad_preview_div"></div></td>
						
					</tr>
					<tr>
						<td>
							<table cellspacing="0" cellpadding="0" class="s-table-left">
								<tr>
									<td>Method of Promotion</td>
									<td><img id="method_of_promotion_div_loading" style="display: none;" src="/202-img/loader-small.gif"/><div id="method_of_promotion_div" style="display: none;"></div></td>
								</tr>
							</table>
						</td>
						<td>
							<table cellspacing="0" cellpadding="0" class="s-table-right">
								<tr>
									<td>Country</td>
									<td><input name="country" id="country" readonly="readonly" type="text" value="<? echo $html['country']; ?>"/> </td> 
								</tr>
							</table>
						</td>
					</tr>
					<tr>
						<td>
							<table cellspacing="0" cellpadding="0" class="s-table-left">
								<tr>
									<td>Landing Page</td>
									<td>
										<img id="landing_page_div_loading" style="display: none;" src="/202-img/loader-small.gif"/>
										<div id="landing_page_div" style="display: none;"></div>
									</td>
								</tr>
							</table>
						</td>
						<td>
							<table cellspacing="0" cellpadding="0" class="s-table-right">
								<tr>
									<td>Referer</td>
									<td><input name="referer" id="referer" type="text" value="<? echo $html['referer']; ?>"/> </td> 
								</tr>
							</table>
						</td>
					</tr>
				</table>
			</div>
			
		<? } ?>
		<?php if($show_adv_breakdown==true) { ?>
			<div class="s-adv" id="s-adv" style="<? if ($show_adv_breakdown == false) { echo 'display: none;'; } ?>">
				<div class="s-border" <? if ($show_adv_breakdown == false) { echo 'style="display:none;"'; } ?>></div>
				<table class="s-table" cellspacing="0" cellpadding="0">
					<tr>
						<td>
							<table cellspacing="0" cellpadding="0" class="s-table-left">
								<tr>
									<td>
										<span>Group By </span>
										<select name="details[]">
											<?php foreach(ReportSummaryForm::getDetailArray() AS $detail_item) { ?>
												<option value="<?php echo $detail_item ?>" <?php echo $html['user_pref_group_1']==$detail_item ? 'selected="selected"' : ''; ?>><?php echo ReportBasicForm::translateDetailLevelById($detail_item); ?></option>
											<?php } ?>
										</select>
									</td>
									<td>
										<span>Then Group By</span>
										<select name="details[]">
											<option value="<?php echo ReportBasicForm::DETAIL_LEVEL_NONE; ?>" <?php echo $html['user_pref_group_1']==ReportBasicForm::DETAIL_LEVEL_NONE ? 'selected="selected"' : ''; ?>><?php echo ReportBasicForm::translateDetailLevelById(ReportBasicForm::DETAIL_LEVEL_NONE); ?></option>
											<?php foreach(ReportSummaryForm::getDetailArray() AS $detail_item) { ?>
												<option value="<?php echo $detail_item ?>" <?php echo $html['user_pref_group_2']==$detail_item ? 'selected="selected"' : ''; ?>><?php echo ReportBasicForm::translateDetailLevelById($detail_item); ?></option>
											<?php } ?>
										</select>
									</td>
									<td>
										<span>Then Group By</span>
										<select name="details[]">
											<option value="<?php echo ReportBasicForm::DETAIL_LEVEL_NONE; ?>" <?php echo $html['user_pref_group_1']==ReportBasicForm::DETAIL_LEVEL_NONE ? 'selected="selected"' : ''; ?>><?php echo ReportBasicForm::translateDetailLevelById(ReportBasicForm::DETAIL_LEVEL_NONE); ?></option>
											<?php foreach(ReportSummaryForm::getDetailArray() AS $detail_item) { ?>
												<option value="<?php echo $detail_item ?>" <?php echo $html['user_pref_group_3']==$detail_item ? 'selected="selected"' : ''; ?>><?php echo ReportBasicForm::translateDetailLevelById($detail_item); ?></option>
											<?php } ?>
										</select>
									</td>
									<td>
										<span>Then Group By</span>
										<select name="details[]">
											<option value="<?php echo ReportBasicForm::DETAIL_LEVEL_NONE; ?>" <?php echo $html['user_pref_group_1']==ReportBasicForm::DETAIL_LEVEL_NONE ? 'selected="selected"' : ''; ?>><?php echo ReportBasicForm::translateDetailLevelById(ReportBasicForm::DETAIL_LEVEL_NONE); ?></option>
											<?php foreach(ReportBasicForm::getDetailArray() AS $detail_item) { ?>
												<option value="<?php echo $detail_item ?>" <?php echo $html['user_pref_group_4']==$detail_item ? 'selected="selected"' : ''; ?>><?php echo ReportBasicForm::translateDetailLevelById($detail_item); ?></option>
											<?php } ?>
										</select>
									</td>
								</tr>
							</table>
						</td>
					</tr>
					<tr>
						<td>
							<table cellspacing="0" cellpadding="0" class="s-table-left">
								<tr>
									
								</tr>
							</table>
						</td>
					</tr>
				</table>
			</div>
		<?php } ?>
			<div class="s-adv">
				<div class="s-border" <? if ($show_adv == false) { echo 'style="display:none;"'; } ?>></div>
				<table class="s-table" cellspacing="0" cellpadding="0">
					 <tr>
						<td>
							<table cellspacing="0" cellpadding="0" class="s-table-left" <? if ($show_bottom == false) { echo 'style="display:none;"'; } ?>>
								<tr>
									<td>Display</td>
									<td>
										<select name="user_pref_limit" <? if ($show_limit == false) { echo 'style="display:none;"'; } ?>>
											<option <? if ($user_row['user_pref_limit'] == '10') { echo 'SELECTED'; } ?> value="10">10</option>
											<option <? if ($user_row['user_pref_limit'] == '25') { echo 'SELECTED'; } ?> value="25">25</option>
											<option <? if ($user_row['user_pref_limit'] == '50') { echo 'SELECTED'; } ?> value="50">50</option>
											<option <? if ($user_row['user_pref_limit'] == '75') { echo 'SELECTED'; } ?> value="75">75</option> 
											<option <? if ($user_row['user_pref_limit'] == '100') { echo 'SELECTED'; } ?> value="100">100</option>
											<option <? if ($user_row['user_pref_limit'] == '150') { echo 'SELECTED'; } ?> value="150">150</option>
											<option <? if ($user_row['user_pref_limit'] == '200') { echo 'SELECTED'; } ?> value="200">200</option>
										</select>
									</td>
									<td>
										<select name="user_pref_breakdown" <? if ($show_breakdown == false) { echo 'style="display:none;"'; } ?>>
											<option <? if ($user_row['user_pref_breakdown'] == 'hour') { echo 'SELECTED'; } ?> value="hour">By Hour</option>
											<option <? if ($user_row['user_pref_breakdown'] == 'day') { echo 'SELECTED'; } ?> value="day">By Day</option>
											<option <? if ($user_row['user_pref_breakdown'] == 'month') { echo 'SELECTED'; } ?> value="month">By Month</option>  
											<option <? if ($user_row['user_pref_breakdown'] == 'year') { echo 'SELECTED'; } ?> value="year">By Year</option>  
										</select>
									</td>
									<td>
										<select name="user_pref_chart" <? if ($show_breakdown == false) { echo 'style="display:none;"'; } ?>>
											<option <? if ($user_row['user_pref_chart'] == 'profitloss') { echo 'SELECTED'; } ?> value="profitloss">Profit Loss Bar Graph</option>
											<option <? if ($user_row['user_pref_chart'] == 'clicks') { echo 'SELECTED'; } ?> value="clicks">Clicks Line Graph</option>
											<option <? if ($user_row['user_pref_chart'] == 'leads') { echo 'SELECTED'; } ?> value="leads">Leads Line Graph</option>
											<option <? if ($user_row['user_pref_chart'] == 'su_ratio') { echo 'SELECTED'; } ?> value="su_ratio">S/U Ratio Line Graph</option>
											<option <? if ($user_row['user_pref_chart'] == 'payout') { echo 'SELECTED'; } ?> value="payout">Payout Line Graph</option>
											<option <? if ($user_row['user_pref_chart'] == 'epc') { echo 'SELECTED'; } ?> value="epc">EPC Line Graph</option>
											<option <? if ($user_row['user_pref_chart'] == 'cpc') { echo 'SELECTED'; } ?> value="cpc">Avg CPC Line Graph</option>
											<option <? if ($user_row['user_pref_chart'] == 'income') { echo 'SELECTED'; } ?> value="income">Income Line Graph</option>
											<option <? if ($user_row['user_pref_chart'] == 'cost') { echo 'SELECTED'; } ?> value="cost">Cost Line Graph</option>
											<option <? if ($user_row['user_pref_chart'] == 'net') { echo 'SELECTED'; } ?> value="net">Net Line Graph</option>
											<option <? if ($user_row['user_pref_chart'] == 'roi') { echo 'SELECTED'; } ?> value="roi">ROI Line Graph</option> 
										</select>
									</td>
									<td>
										<select name="user_pref_show" <? if ($show_type == false) { echo 'style="display:none;"'; } ?>>
											<option <? if ($user_row['user_pref_show'] == 'all') { echo 'SELECTED'; } ?> value="all">Show All Clicks</option>
											<option <? if ($user_row['user_pref_show'] == 'real') { echo 'SELECTED'; } ?> value="real">Show Real Clicks</option>
											<option <? if ($user_row['user_pref_show'] == 'filtered') { echo 'SELECTED'; } ?> value="filtered">Show Filtered Out Clicks</option>
											<option <? if ($user_row['user_pref_show'] == 'leads') { echo 'SELECTED'; } ?> value="leads">Show Converted Clicks</option>
										</select>
									</td>
									<td class="s-td-slim">
										<select name="user_cpc_or_cpv" <? if ($show_cpc_or_cpv == false) { echo 'style="display:none;"'; } ?>>
											<option <? if ($user_row['user_cpc_or_cpv'] == 'cpc') { echo 'SELECTED'; } ?> value="cpc">CPC Costs</option>
											<option <? if ($user_row['user_cpc_or_cpv'] == 'cpv') { echo 'SELECTED'; } ?> value="cpv">CPV Costs</option>
										</select>
									</td>
								</tr>
							</table>
						</td>
						<td>
							<table cellspacing="0" cellpadding="0" class="s-table-right">
								<tr>
									<!-- This first is so the ENTER is defaulted to the first submit -->
									<td id="s-status-loading" style="display:none;"><img src="/202-img/loader-small.gif"/></td>
									<td style="display: none;"><input type="submit" id="s-search" class="s-submit s-submit1" onclick="set_user_prefs('<? echo $html['page']; ?>');" value="Save User Preferences"/></td>
									<td><button id="s-toogleAdv" class="s-submit s-submit2" onclick="toggleAdvanced();" <? if ($show_adv == false) { echo 'style="display:none;"'; } ?>><? if ($user_row['user_pref_adv'] != '1') { echo 'More Options'; } else { echo 'Less Options'; } ?>
									<td><input type="submit" id="s-search" class="s-submit s-submit1" onclick="set_user_prefs('<? echo $html['page']; ?>');" value="Set Preferences"/></td>
								</tr>
							</table>
						</td>
					</tr>
				</table>   
			</div>
	   </div>
	   <div id="s-status"></div>  
   </form>
   
   <div id="m-content"></div>                                
   
   <script type="text/javascript">
		
		/* TIME SETTING FUNCTION */ 
		function set_user_pref_time_predefined() {
			
			$('to_cal').style.display='none'; 
			$('from_cal').style.display='none';
	
			if($('user_pref_time_predefined').options[$('user_pref_time_predefined').selectedIndex].value == 'today') {
				<?  $time['from'] = mktime(0,0,0,date('m',time()),date('d',time()),date('Y',time()));
					$time['to'] = mktime(23,59,59,date('m',time()),date('d',time()),date('Y',time())); ?>
				
				//now set the from and to dates
				$('from').value='<? echo date('m/d/y - G:i',$time['from']); ?>';
				$('to').value='<? echo date('m/d/y - G:i',$time['to']); ?>';  
				
				//now set the calendar dates too
				var d = new Date(<? printf('%s, %s, %s', date('Y',$time['from']), date('n',$time['from']), date('j',$time['from'])); ?>);
				from_cal.setCurrentDate(d);
				
				var d = new Date(<? printf('%s, %s, %s', date('Y',$time['to']), date('n',$time['to']), date('j',$time['to'])); ?>);
				to_cal.setCurrentDate(d);
			}
			
			if($('user_pref_time_predefined').options[$('user_pref_time_predefined').selectedIndex].value == 'yesterday') {
				<?  $time['from'] = mktime(0,0,0,date('m',time()-86400),date('d',time()-86400),date('Y',time()-86400));
					$time['to'] = mktime(23,59,59,date('m',time()-86400),date('d',time()-86400),date('Y',time()-86400)); ?>
				
				//now set the from and to dates
				$('from').value='<? echo date('m/d/y - G:i',$time['from']); ?>';
				$('to').value='<? echo date('m/d/y - G:i',$time['to']); ?>';  
				
				//now set the calendar dates too
				var d = new Date(<? printf('%s, %s, %s', date('Y',$time['from']), date('n',$time['from']), date('j',$time['from'])); ?>);
				from_cal.setCurrentDate(d);
				
				var d = new Date(<? printf('%s, %s, %s', date('Y',$time['to']), date('n',$time['to']), date('j',$time['to'])); ?>);
				to_cal.setCurrentDate(d);
			}
			
			if($('user_pref_time_predefined').options[$('user_pref_time_predefined').selectedIndex].value == 'last7') {
				<?  $time['from'] = mktime(0,0,0,date('m',time()-86400*7),date('d',time()-86400*7),date('Y',time()-86400*7));
					$time['to'] = mktime(23,59,59,date('m',time()),date('d',time()),date('Y',time()));  ?>
				
				//now set the from and to dates
				$('from').value='<? echo date('m/d/y - G:i',$time['from']); ?>';
				$('to').value='<? echo date('m/d/y - G:i',$time['to']); ?>';  
				
				//now set the calendar dates too
				var d = new Date(<? printf('%s, %s, %s', date('Y',$time['from']), date('n',$time['from']), date('j',$time['from'])); ?>);
				from_cal.setCurrentDate(d);
				
				var d = new Date(<? printf('%s, %s, %s', date('Y',$time['to']), date('n',$time['to']), date('j',$time['to'])); ?>);
				to_cal.setCurrentDate(d);
			}
			
			if($('user_pref_time_predefined').options[$('user_pref_time_predefined').selectedIndex].value == 'last14') {
				<?  $time['from'] = mktime(0,0,0,date('m',time()-86400*14),date('d',time()-86400*14),date('Y',time()-86400*14));
					$time['to'] = mktime(23,59,59,date('m',time()),date('d',time()),date('Y',time()));  ?>
				
				//now set the from and to dates
				$('from').value='<? echo date('m/d/y - G:i',$time['from']); ?>';
				$('to').value='<? echo date('m/d/y - G:i',$time['to']); ?>';  
				
				//now set the calendar dates too
				var d = new Date(<? printf('%s, %s, %s', date('Y',$time['from']), date('n',$time['from']), date('j',$time['from'])); ?>);
				from_cal.setCurrentDate(d);
				
				var d = new Date(<? printf('%s, %s, %s', date('Y',$time['to']), date('n',$time['to']), date('j',$time['to'])); ?>);
				to_cal.setCurrentDate(d);
			}
			
			if($('user_pref_time_predefined').options[$('user_pref_time_predefined').selectedIndex].value == 'last30') {
				<?  $time['from'] = mktime(0,0,0,date('m',time()-86400*30),date('d',time()-86400*30),date('Y',time()-86400*30));
					$time['to'] = mktime(23,59,59,date('m',time()),date('d',time()),date('Y',time()));    ?>
				
				//now set the from and to dates
				$('from').value='<? echo date('m/d/y - G:i',$time['from']); ?>';
				$('to').value='<? echo date('m/d/y - G:i',$time['to']); ?>';  
				
				//now set the calendar dates too
				var d = new Date(<? printf('%s, %s, %s', date('Y',$time['from']), date('n',$time['from']), date('j',$time['from'])); ?>);
				from_cal.setCurrentDate(d);
				
				var d = new Date(<? printf('%s, %s, %s', date('Y',$time['to']), date('n',$time['to']), date('j',$time['to'])); ?>);
				to_cal.setCurrentDate(d);
			}
			
			if($('user_pref_time_predefined').options[$('user_pref_time_predefined').selectedIndex].value == 'thismonth') {
				<?  $time['from'] = mktime(0,0,0,date('m',time()),1,date('Y',time()));
					$time['to'] = mktime(23,59,59,date('m',time()),date('d',time()),date('Y',time()));   ?>
				
				//now set the from and to dates
				$('from').value='<? echo date('m/d/y - G:i',$time['from']); ?>';
				$('to').value='<? echo date('m/d/y - G:i',$time['to']); ?>';  
				
				//now set the calendar dates too
				var d = new Date(<? printf('%s, %s, %s', date('Y',$time['from']), date('n',$time['from']), date('j',$time['from'])); ?>);
				from_cal.setCurrentDate(d);
				
				var d = new Date(<? printf('%s, %s, %s', date('Y',$time['to']), date('n',$time['to']), date('j',$time['to'])); ?>);
				to_cal.setCurrentDate(d);
			}
	
			if($('user_pref_time_predefined').options[$('user_pref_time_predefined').selectedIndex].value == 'lastmonth') {
				<?  $time['from'] = mktime(0,0,0,date('m',time()-2629743),1,date('Y',time()-2629743));
					$time['to'] = mktime(23,59,59,date('m',time()-2629743),getLastDayOfMonth(date('m',time()-2629743), date('Y',time()-2629743)),date('Y',time()-2629743));   ?>
				
				//now set the from and to dates
				$('from').value='<? echo date('m/d/y - G:i',$time['from']); ?>';
				$('to').value='<? echo date('m/d/y - G:i',$time['to']); ?>';  
				
				//now set the calendar dates too
				var d = new Date(<? printf('%s, %s, %s', date('Y',$time['from']), date('n',$time['from']), date('j',$time['from'])); ?>);
				from_cal.setCurrentDate(d);
				
				var d = new Date(<? printf('%s, %s, %s', date('Y',$time['to']), date('n',$time['to']), date('j',$time['to'])); ?>);
				to_cal.setCurrentDate(d);
			}
			
			if($('user_pref_time_predefined').options[$('user_pref_time_predefined').selectedIndex].value == 'thisyear') {
				<?  $time['from'] = mktime(0,0,0,1,1,date('Y',time()));
					$time['to'] = mktime(23,59,59,date('m',time()),date('d',time()),date('Y',time()));   ?>
				
				//now set the from and to dates
				$('from').value='<? echo date('m/d/y - G:i',$time['from']); ?>';
				$('to').value='<? echo date('m/d/y - G:i',$time['to']); ?>';  
				
				//now set the calendar dates too
				var d = new Date(<? printf('%s, %s, %s', date('Y',$time['from']), date('n',$time['from']), date('j',$time['from'])); ?>);
				from_cal.setCurrentDate(d);
				
				var d = new Date(<? printf('%s, %s, %s', date('Y',$time['to']), date('n',$time['to']), date('j',$time['to'])); ?>);
				to_cal.setCurrentDate(d);
			}
			
			if($('user_pref_time_predefined').options[$('user_pref_time_predefined').selectedIndex].value == 'lastyear') {
				<?  $time['from'] = mktime(0,0,0,1,1,date('Y',time()-31556926));
					$time['to'] = mktime(0,0,0,12,getLastDayOfMonth(date('m',time()-31556926), date('Y',time()-31556926)),date('Y',time()-31556926));   ?>
				
				//now set the from and to dates
				$('from').value='<? echo date('m/d/y - G:i',$time['from']); ?>';
				$('to').value='<? echo date('m/d/y - G:i',$time['to']); ?>';  
				
				//now set the calendar dates too
				var d = new Date(<? printf('%s, %s, %s', date('Y',$time['from']), date('n',$time['from']), date('j',$time['from'])); ?>);
				from_cal.setCurrentDate(d);
				
				var d = new Date(<? printf('%s, %s, %s', date('Y',$time['to']), date('n',$time['to']), date('j',$time['to'])); ?>);
				to_cal.setCurrentDate(d);
			}
			
			if($('user_pref_time_predefined').options[$('user_pref_time_predefined').selectedIndex].value == 'alltime') {
				<?  
				//for the time from, do something special select the exact date this user was registered and use that :)
				$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
				$user_sql = "SELECT user_time_register FROM 202_users WHERE user_id='".$mysql['user_id']."'";
				$user_result = mysql_query($user_sql) or record_mysql_error($user_sql);
				$user_row = mysql_fetch_assoc($user_result);
				$time['from'] = $user_row['user_time_register'];
				
				$time['from'] = mktime(0,0,0,date('m',$time['from']),date('d',$time['from']),date('Y',$time['from']));  
				$time['to'] = mktime(23,59,59,date('m',time()),date('d',time()),date('Y',time()));    ?>
		
				//now set the from and to dates
				$('from').value='<? echo date('m/d/y - G:i',$time['from']); ?>';
				$('to').value='<? echo date('m/d/y - G:i',$time['to']); ?>';  
				
				//now set the calendar dates too
				var d = new Date(<? printf('%s, %s, %s', date('Y',$time['from']), date('n',$time['from']), date('j',$time['from'])); ?>);
				from_cal.setCurrentDate(d);
				
				var d = new Date(<? printf('%s, %s, %s', date('Y',$time['to']), date('n',$time['to']), date('j',$time['to'])); ?>);
				to_cal.setCurrentDate(d);
			}
			
			//bump the date down for some reason it keeps adding ONE MONTH?!?!?!?!
			from_cal.setCurrentDate('monthdown');
			to_cal.setCurrentDate('monthdown');
			
		}
	
		/* TOGGLE FUNCTION */
		function toggleAdvanced() { 
			
			$('to_cal').style.display='none'; 
			$('from_cal').style.display='none';
			
			Effect.toggle('s-adv','blind');
			if ($('text_ad_id')) {  $('text_ad_id').selectedIndex = 0;  } 
			if ($('method_of_promotion')) {  $('method_of_promotion').selectedIndex = 0;  } 
			if ($('landing_page_id')) {  $('landing_page_id').selectedIndex = 0;  } 
			$('ad_preview_div').style.display = 'none';
			$('country').value = '';
			$('referer').value = '';
			
			if($('s-adv').style.display == 'none') { 
				$('user_pref_adv').value = '1';
				$('s-toogleAdv').innerHTML = 'Less Options';
			} else {
				$('user_pref_adv').value = '';
				$('s-toogleAdv').innerHTML = 'More Options';
			}
			
			<? /*set_user_prefs('<? echo $html['page']; ?>'); */ ?>
		}
		
		/* SHOW FIELDS */        

		load_ppc_network_id('<? echo $html['user_pref_ppc_network_id']; ?>');
		<? if ($html['user_pref_ppc_account_id'] != '') { ?>
			load_ppc_account_id('<? echo $html['user_pref_ppc_network_id']; ?>','<? echo $html['user_pref_ppc_account_id']; ?>');      
		<? } ?>
		
		load_aff_network_id('<? echo $html['user_pref_aff_network_id']; ?>');
		<? if ($html['user_pref_aff_campaign_id'] != '') { ?>
			load_aff_campaign_id('<? echo $html['user_pref_aff_network_id']; ?>','<? echo $html['user_pref_aff_campaign_id']; ?>');
		<? } ?>
		
		<? if ($html['user_pref_text_ad_id'] != '') { ?>
			load_text_ad_id('<? echo $html['user_pref_aff_campaign_id']; ?>','<? echo $html['user_pref_text_ad_id']; ?>');
			load_ad_preview('<? echo $html['user_pref_text_ad_id']; ?>'); 
		<? } ?>
		
		load_method_of_promotion('<? echo $html['user_pref_method_of_promotion']; ?>');
		
		<? if ($html['user_pref_landing_page_id'] != '') { ?>
			load_landing_page('<? echo $html['user_pref_aff_campaign_id']; ?>', '<? echo $html['user_pref_landing_page_id']; ?>', '<? echo $html['user_pref_method_of_promotion']; ?>');
		<? } ?>

   </script> 
<? }


function grab_timeframe() {
	
	AUTH::set_timezone($_SESSION['user_timezone']);
	
	$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
	$user_sql = "SELECT user_pref_time_predefined, user_pref_time_from, user_pref_time_to FROM 202_users_pref WHERE user_id='".$mysql['user_id']."'";
	$user_result = _mysql_query($user_sql) ; ; //($user_sql); 
	$user_row = mysql_fetch_assoc($user_result);
	
	if (($user_row['user_pref_time_predefined'] == 'today') or ($user_row['pref_time_from'] != '')) { 
		$time['from'] = mktime(0,0,0,date('m',time()),date('d',time()),date('Y',time()));
		$time['to'] = mktime(23,59,59,date('m',time()),date('d',time()),date('Y',time()));    
	}
	
	if($user_row['user_pref_time_predefined'] == 'yesterday') { 
		$time['from'] = mktime(0,0,0,date('m',time()-86400),date('d',time()-86400),date('Y',time()-86400));
		$time['to'] = mktime(23,59,59,date('m',time()-86400),date('d',time()-86400),date('Y',time()-86400));    
	}
	
	if($user_row['user_pref_time_predefined'] == 'last7') { 
		$time['from'] = mktime(0,0,0,date('m',time()-86400*7),date('d',time()-86400*7),date('Y',time()-86400*7));
		$time['to'] = mktime(23,59,59,date('m',time()),date('d',time()),date('Y',time()));    
	}
	
	if($user_row['user_pref_time_predefined'] == 'last14') { 
		$time['from'] = mktime(0,0,0,date('m',time()-86400*14),date('d',time()-86400*14),date('Y',time()-86400*14));
		$time['to'] = mktime(23,59,59,date('m',time()),date('d',time()),date('Y',time()));    
	}
	
	if($user_row['user_pref_time_predefined'] == 'last30') { 
		$time['from'] = mktime(0,0,0,date('m',time()-86400*30),date('d',time()-86400*30),date('Y',time()-86400*30));
		$time['to'] = mktime(23,59,59,date('m',time()),date('d',time()),date('Y',time()));    
	}
	
	if($user_row['user_pref_time_predefined'] == 'thismonth') { 
		$time['from'] = mktime(0,0,0,date('m',time()),1,date('Y',time()));
		$time['to'] = mktime(23,59,59,date('m',time()),date('d',time()),date('Y',time()));  
	}
	
	if($user_row['user_pref_time_predefined'] == 'lastmonth') { 
		$time['from'] = mktime(0,0,0,date('m',time()-2629743),1,date('Y',time()-2629743));
		$time['to'] = mktime(23,59,59,date('m',time()-2629743),getLastDayOfMonth(date('m',time()-2629743), date('Y',time()-2629743)),date('Y',time()-2629743));
	}
	
	if($user_row['user_pref_time_predefined'] == 'thisyear') { 
		$time['from'] = mktime(0,0,0,1,1,date('Y',time()));
		$time['to'] = mktime(23,59,59,date('m',time()),date('d',time()),date('Y',time()));    
	}
	
	if($user_row['user_pref_time_predefined'] == 'lastyear') { 
		$time['from'] = mktime(0,0,0,1,1,date('Y',time()-31556926));
		$time['to'] = mktime(0,0,0,12,getLastDayOfMonth(date('m',time()-31556926), date('Y',time()-31556926)),date('Y',time()-31556926));    
	}
	
	if($user_row['user_pref_time_predefined'] == 'alltime') { 
		
		//for the time from, do something special select the exact date this user was registered and use that :)
		$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
		$user2_sql = "SELECT user_time_register FROM 202_users WHERE user_id='".$mysql['user_id']."'";
		$user2_result = mysql_query($user2_sql) or record_mysql_error($user2_sql);
		$user2_row = mysql_fetch_assoc($user2_result);
		$time['from'] = $user2_row['user_time_register']; 
	
		$time['from'] = mktime(0,0,0,date('m',$time['from']),date('d',$time['from']),date('Y',$time['from']));  
		$time['to'] = mktime(23,59,59,date('m',time()),date('d',time()),date('Y',time())); 
	}
	
	if($user_row['user_pref_time_predefined'] == '') { 
		$time['from'] = $user_row['user_pref_time_from'];
		$time['to'] = $user_row['user_pref_time_to'];
	}
	

   $time['user_pref_time_predefined'] = $user_row['user_pref_time_predefined'];
   return $time;   
}

function getLastDayOfMonth($month, $year){
	return date("d", mktime(0, 0, 0, $month + 1, 0, $year));
}

function getTrackingDomain() {
	$tracking_domain_sql = "
		SELECT
			`user_tracking_domain`
		FROM
			`202_users_pref`
		WHERE
			`user_id`='".mysql_real_escape_string($_SESSION['user_id'])."'
	";
	$tracking_domain_result = _mysql_query($tracking_domain_sql) ; ; //($user_sql);
	$tracking_domain_row = mysql_fetch_assoc($tracking_domain_result);
	$tracking_domain = $_SERVER['SERVER_NAME'];
	if(strlen($tracking_domain_row['user_tracking_domain'])>0) {
		$tracking_domain = $tracking_domain_row['user_tracking_domain'];
	}
	return $tracking_domain;
}

//the above, if true, are options to turn on specific filtering techniques.
function query($command, $db_table, $pref_time, $pref_adv, $pref_show, $pref_order, $offset, $pref_limit, $count) {
											   
	//grab user preferences  
	$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);    
	$user_sql = "SELECT * FROM 202_users_pref WHERE user_id='".$mysql['user_id']."'";
	$user_result = _mysql_query($user_sql) ; ; //($user_sql);
	$user_row = mysql_fetch_assoc($user_result);
	
	
	//do extra joins if advance selector is enabled
	if ($pref_adv == true) {
		
		
		//if ppc network lookup with no individual ppc network account loookup do this
		if ($user_row['user_pref_ppc_network_id'] and !($user_row['user_pref_ppc_account_id'])) { 
			
			if (!preg_match('/202_ppc_accounts/', $command)) {
				$command .= " LEFT JOIN 202_ppc_accounts AS 2pa ON (2c.ppc_account_id = 2pa.ppc_account_id) ";
			}
			
			if (!preg_match('/202_ppc_networks/', $command)) {
				$command .= " LEFT JOIN 202_ppc_networks AS 2pn ON (2pa.ppc_network_id = 2pn.ppc_network_id) ";
			}
		}
		
		//if aff network lookup with no individual aff campaign loookup do this
		if ($user_row['user_pref_aff_network_id'] and !($user_row['user_pref_aff_campaign_id'])) { 
			
			if (!preg_match('/202_aff_campaigns/', $command)) {
				$command .= " LEFT JOIN 202_aff_campaigns AS 2ac ON (2c.aff_campaign_id = 2ac.aff_campaign_id) ";
			}
			
			if (!preg_match('/202_aff_networks/', $command)) {
				$command .= " LEFT JOIN 202_aff_networks AS 2an ON (2ac.aff_network_id = 2an.aff_network_id) ";
			}
		}
		
		
		//if domain lookup
		if ($user_row['user_pref_referer'] and !preg_match('/202_site_domains/', $command)) { 
			
			if (!preg_match('/202_clicks_site/', $command)) {
				$command .= " LEFT JOIN 202_clicks_site AS 2cs ON (2c.click_id = 2cs.click_id) ";
			}
			
			if (!preg_match('/202_site_urls/', $command)) {
				$command .= " LEFT JOIN 202_site_urls AS 2su ON (2cs.click_referer_site_url_id = 2su.site_url_id) ";
			}
			$command .= " LEFT JOIN 202_site_domains AS 2sd ON (2su.site_domain_id = 2sd.site_domain_id) ";
		}
		
		
		//if there is a keyword lookup, and we have not joined the 202 keywords table. do so now
		if ($user_row['user_pref_keyword'] and !preg_match('/202_keywords/', $command)) { 
			$command .= " LEFT JOIN 202_keywords AS 2k ON (2ca.keyword_id = 2k.keyword_id) ";
		}
		
		//if there is a ip lookup, and we have not joined the 202 ip table. do so now
		if ($user_row['user_pref_ip'] and !preg_match('/202_ips/', $command)) { 
			$command .= " LEFT JOIN 202_ips AS 2i ON (2ca.ip_id = 2i.ip_id) ";
		}
	}
	
	
	
	$click_sql = $command . " WHERE $db_table.user_id='".$mysql['user_id']."' ";
	

	//set show preferences
	if ($pref_show == true) {
		if ($user_row['user_pref_show'] == 'filtered') {                 
			$click_sql .=   " AND click_filtered='1' ";
		} elseif ($user_row['user_pref_show'] == 'real') {
			$click_sql .=   " AND click_filtered='0' ";
		} elseif ($user_row['user_pref_show'] == 'leads') {
			$click_sql .=   " AND click_filtered='0' AND click_lead='1' ";   
		}   
	}
		
	//set advanced preferences                    
	if ($pref_adv == true) {
		if ($user_row['user_pref_ppc_network_id'] and !($user_row['user_pref_ppc_account_id'])) { 
			$mysql['user_pref_ppc_network_id'] = mysql_real_escape_string($user_row['user_pref_ppc_network_id']);
			$click_sql .=   "  AND      2pn.ppc_network_id='".$mysql['user_pref_ppc_network_id']."'";
		}
		

		if ($user_row['user_pref_ppc_account_id']) { 
			$mysql['user_pref_ppc_account_id'] = mysql_real_escape_string($user_row['user_pref_ppc_account_id']);
			$click_sql .=   " AND      2c.ppc_account_id='".$mysql['user_pref_ppc_account_id']."'";
		}
		 
	   if ($user_row['user_pref_aff_network_id'] and !$user_row['user_pref_aff_campaign_id']) { 

			$mysql['user_pref_aff_network_id'] = mysql_real_escape_string($user_row['user_pref_aff_network_id']);
			$click_sql .=   "  AND      2an.aff_network_id='".$mysql['user_pref_aff_network_id']."'";
		}
		
		if ($user_row['user_pref_aff_campaign_id']) { 
			$mysql['user_pref_aff_campaign_id'] = mysql_real_escape_string($user_row['user_pref_aff_campaign_id']);
			$click_sql .=   " AND      2c.aff_campaign_id='".$mysql['user_pref_aff_campaign_id']."'";
		}
		if ($user_row['user_pref_text_ad_id']) { 
			$mysql['user_pref_text_ad_id'] = mysql_real_escape_string($user_row['user_pref_text_ad_id']);
			$click_sql .=   " AND      2ca.text_ad_id='".$mysql['user_pref_text_ad_id']."'";
		} 
		if ($user_row['user_pref_method_of_promotion'] != '0') { 
			if ($user_row['user_pref_method_of_promotion'] == 'directlink') { 
				$click_sql .=   " AND      2c.landing_page_id=''";       
			} elseif ($user_row['user_pref_method_of_promotion'] == 'landingpage') {  
				$click_sql .=   " AND      2c.landing_page_id!=''";     
			} 
		}
		

		if ($user_row['user_pref_landing_page_id']) { 
			$mysql['user_landing_page_id'] = mysql_real_escape_string($user_row['user_pref_landing_page_id']);
			$click_sql .=   " AND      2c.landing_page_id='".$mysql['user_landing_page_id']."'";
		}   
		
		/*if ($user_row['pref_country_id']) { 
			$mysql['user_pref_country_id'] = mysql_real_escape_string($user_row['pref_country_id']);
			$click_sql .=   " AND      pref_country_id=".$mysql['user_pref_country_id'];
		}*/
		
		if ($user_row['user_pref_referer']) { 
			$mysql['user_pref_referer'] = mysql_real_escape_string($user_row['user_pref_referer']);
			$click_sql .=   " AND 2sd.site_domain_host LIKE CONVERT( _utf8 '%".$mysql['user_pref_referer']."%' USING latin1 )
							COLLATE latin1_swedish_ci ";    
		}
		
		if ($user_row['user_pref_keyword']) { 
			$mysql['user_pref_keyword'] = mysql_real_escape_string($user_row['user_pref_keyword']);
			$click_sql .=   " AND 2k.keyword LIKE CONVERT( _utf8 '%".$mysql['user_pref_keyword']."%' USING latin1 )
							COLLATE latin1_swedish_ci ";    
		}
		
		if ($user_row['user_pref_ip']) { 
			$mysql['user_pref_ip'] = mysql_real_escape_string($user_row['user_pref_ip']);
			$click_sql .=   " AND 2i.ip_address LIKE CONVERT( _utf8 '%".$mysql['user_pref_ip']."%' USING latin1 )
							COLLATE latin1_swedish_ci ";    
		}	
	}
	
	//set time preferences
	if ($pref_time == true) {    
		$time = grab_timeframe();  
		
		$mysql['from'] = mysql_real_escape_string($time['from']);
		$mysql['to'] = mysql_real_escape_string($time['to']);
		if ($mysql['from'] != '') {
			$click_sql .=   " AND click_time > ".$mysql['from']." "; 
		}
		if ($mysql['to'] != '') {
			$click_sql .=   " AND click_time < ".$mysql['to']." "; 
		}
	}

	//set limit preferences 
	if ($pref_order == true) {
		$click_sql .= $pref_order;    
	}
	
 
	//only if we want to count stuff like the click history clciks do we need to do any of the stuff below.
	if ($count == true) { 
 
		//before it limits, we want to know the TOTAL number of rows
		$click_result = _mysql_query($click_sql) ; //($click_sql);
		$rows = mysql_num_rows($click_result);
		
		
		//only if there is a limit set, run this code 
		if ($pref_limit != false) {
		   
			//rows is the total count of rows in this query.
			$query['rows'] = $rows; 
			$query['offset'] = $offset;
			
			if ((is_numeric($offset) and ($pref_limit == true)) or ($pref_limit == true)) {
				$click_sql .= " LIMIT ";   
			}
		   
			if (is_numeric($offset) and ($pref_limit == true)) {
				$mysql['offset'] = mysql_real_escape_string($offset*$user_row['user_pref_limit']);
				$click_sql .= $mysql['offset'].",";
				
				//declare starting row number
				$query['from'] = ($query['offset'] * $user_row['user_pref_limit']) + 1;
			} else {
				$query['from'] = 1;      
			}
			
			if ($pref_limit == true) {
				
				if (is_numeric($pref_limit)) {
					$mysql['user_pref_limit'] = mysql_real_escape_string($pref_limit);                    
				} else {
					$mysql['user_pref_limit'] = mysql_real_escape_string($user_row['user_pref_limit']);
				}
				$click_sql .= $mysql['user_pref_limit'];
				
				//declare the number of pages
				$query['pages'] = ceil($query['rows']/$user_row['user_pref_limit']) + 1; 
				
				//declare end starting row number
				$query['to'] = ($query['from']  + $user_row['user_pref_limit']) -1;
				if ($query['to'] > $query['rows']) { 
					$query['to'] = $query['rows'];    
				}
					 
			} else {
				$query['pages'] = 1;
				$query['to'] = $query['rows'];   
			}
			
			if (($query['from'] == 1) and ($query['to'] == 0)) {
				$query['from'] = 0;   
			}
		}
 
	}
		   
	$query['click_sql'] = $click_sql;
	#echo  $click_sql . '<br/><br/>';
	
	return $query;
	
}

function display_suggestion($suggestion_row) {

	//lets determine, if this user has already voted on this:
	$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
	$mysql['suggestion_id'] = mysql_real_escape_string($suggestion_row['suggestion_id']);
	$votes_sql = "SELECT COUNT(*) FROM suggestion_votes WHERE user_id='".$mysql['user_id']."' AND suggestion_id='".$mysql['suggestion_id']."'";
	
	$votes_result = _mysql_query($votes_sql) ; //($votes_sql);
	$already_voted = '';
	if (mysql_result($votes_result,0,0) > 0) { $already_voted = '1'; } 
	
	if ($suggestion_row['votes'] > 0) { $suggestion_row['votes'] = '+' . $suggestion_row['votes']; }
	$mysql['user_id'] = mysql_real_escape_string($suggestion_row['user_id']);
	$user_sql = "SELECT user_username FROM users WHERE user_id='".$mysql['user_id']."'";
	$user_result = _mysql_query($user_sql) ; ; //($user_sql);
	$user_row = mysql_fetch_assoc($user_result);
	
	$html['suggestion_id'] = htmlentities($suggestion_row['suggestion_id'], ENT_QUOTES, 'UTF-8');
	$html['user_username'] = htmlentities($user_row['user_username'], ENT_QUOTES, 'UTF-8');
	$html['suggestion_time'] = date('M d, Y', $suggestion_row['suggestion_time']);
	$html['suggestion_votes'] = htmlentities($suggestion_row['suggestion_votes'], ENT_QUOTES, 'UTF-8');
	$html['suggestion_text'] = htmlentities($suggestion_row['suggestion_text'], ENT_QUOTES, 'UTF-8'); ?>
	
	<li id="c-comment<? echo $html['suggestion_id']; ?>">
		<table class="c-table" cellspacing="0" cellpadding="0">
			<tr class="c-head">
				<td class="c-info"><strong><? echo $html['user_username']; ?></strong> <span class="c-time"><? echo $html['suggestion_time']; ?></span></td>
				<td class="c-votes" id="c-votes<? echo $html['suggestion_id']; ?>"><? echo $html['suggestion_votes']; ?> rating</td>
				<td class="c-vote-no">
					<img id="c-vote-no<? echo $html['suggestion_id']; ?>" src="/202-img/icons/18x18/vote-no<? if ($already_voted == '1') { echo '-off'; } ?>.png" alt="Vote No" title="Vote No" <? if ($already_voted != '1') { ?> onclick="vote('<? echo $html['suggestion_id']; ?>','','1');" <? } ?>/>
				</td> 
				<td class="c-vote-yes">
					<img id="c-vote-yes<? echo $html['suggestion_id']; ?>" src="/202-img/icons/18x18/vote-yes<? if ($already_voted == '1') { echo '-off'; } ?>.png" alt="Vote Yes" title="Vote Yes" <? if ($already_voted != '1') { ?> onclick="vote('<? echo $html['suggestion_id']; ?>','1','');" <? } ?>/>
				</td>
							
				<? if (AUTH::admin_logged_in() == true) { ?>
					<td class="c-delete">
						<img id="c-delete<? echo $html['suggestion_id']; ?>" src="/202-img/icons/16x16/cancel.png" title="Delete" onclick="deleteComment('<? echo $html['suggestion_id']; ?>');"/>
					</td> 
					<td class="c-complete">
						<img id="c-complete<? echo $html['suggestion_id']; ?>" src="/202-img/icons/16x16/accept.png" title="Completed" onclick="completeComment('<? echo $html['suggestion_id']; ?>');"/>
					</td>
				<? } ?>
				
			</tr>
		</table>
		<div class="c-body">
			<? echo $html['suggestion_text']; ?> 
			<div style="text-align: right;"><?  //show on show comments, if there are comments
				$comments = 0;
				$comments = numberofcomments($suggestion_row['suggestion_id']);
				if ($comments['from'] != '') { ?>
					<a class="onclick_color c-onclick"  id="c-showComments<? echo $html['suggestion_id']; ?>" onclick="showComments('<? echo $html['suggestion_id']; ?>');">[Show Comments <? echo $comments['from'] . ' of ' .$comments['to']; ?>]</a>
					<a class="onclick_color c-onclick"  id="c-hideComments<? echo $html['suggestion_id']; ?>" onclick="hideComments('<? echo $html['suggestion_id']; ?>');" style="display: none;">[Hide Comments]</a>
				<? } ?>
				<a class="onclick_color c-onclick" id="c-showReply<? echo $html['suggestion_id']; ?>" onclick="showCreply('<? echo $html['suggestion_id']; ?>');">[Reply]</a>
				<a class="onclick_color c-onclick" id="c-hideReply<? echo $html['suggestion_id']; ?>" onclick="hideCreply('<? echo $html['suggestion_id']; ?>');" style="display: none;">[Hide Reply]</a> 
			</div>
		</div>
		<div id="c-row2<? echo $html['suggestion_id']; ?>" class="c-row2">
			<div id="c-post<? echo $html['suggestion_id']; ?>" style="display: none;">
				<div id="c-options<? echo $html['suggestion_id']; ?>" class="c-highlight">
					[Reply]
				</div>
				<div id="c-reply<? echo $html['suggestion_id']; ?>" class="c-reply">
					<form id="c-reply-form<? echo $html['suggestion_id']; ?>" onsubmit="return suggestionReply('<? echo $html['suggestion_id']; ?>');" method="post">
						<input type="hidden" name="suggestion_reply_to_id" value="<? echo $html['suggestion_id']; ?>"/>
						<textarea name="c-suggestion" id="c-suggestion<? echo $html['suggestion_id']; ?>" class="c-reply-textarea"></textarea>
						<div id="c-error<? echo $html['suggestion_id']; ?>" class="error" style="display: none;">The submission you sent us was empty!</div>
						<input type="submit" value="Submit Comment" class="c-reply-submit"/>    
					</form>
				</div>
			</div>
			
			<div id="c-replies<? echo $html['suggestion_id']; ?>" style="display: none;">
				<? if ($comments > 0) { ?>
					<div class="comment2">
						<ul> 
							<li> <?
								$mysql['suggestion_id'] = mysql_real_escape_string($suggestion_row['suggestion_id']);
								$suggestion2_sql = "SELECT * FROM suggestions WHERE suggestion_reply_to_id='".$mysql['suggestion_id']."'";
								$suggestion2_result = _mysql_query($suggestion2_sql) ; //($suggestion2_sql);
								
								while ($suggestion2_row = mysql_fetch_array($suggestion2_result)) {
									display_suggestion($suggestion2_row);       
								} ?>
							</li>
						</ul>
					</div>
				<? } ?>
			</div>
		</div>
	</li> <? 
	
}

function numberofcomments($suggestion_id) {
	
	$mysql['suggestion_reply_to_id'] = mysql_real_escape_string($suggestion_id);
	$suggestion_sql = "SELECT * FROM suggestions WHERE suggestion_reply_to_id='".$mysql['suggestion_reply_to_id']."' ORDER BY suggestion_votes DESC";
	$suggestion_result = _mysql_query($suggestion_sql) ; //($suggestion_sql);
	$comments['from'] = mysql_num_rows($suggestion_result);
	if ($comments['from'] > 0) { 
		$comments['to'] = $comments['from'];
		while ($suggestion_row = mysql_fetch_array($suggestion_result)) {
			$comments2 = numberofcomments($suggestion_row['suggestion_id']);
			$comments['to'] = $comments['to'] + $comments2['to'];
		}
	}   

	return $comments;
} 
					



function pcc_network_icon($ppc_network_name,$ppc_account_name) {
	//7search
	if ((preg_match("/7search/i", $ppc_network_name)) or (preg_match("/7 search/i", $ppc_network_name))) {
		$ppc_network_icon = '7search.ico';    
	}

	//adbrite
	if (preg_match("/adbrite/i", $ppc_network_name)) {
		$ppc_network_icon = 'adbrite.ico';    
	}

	//adoori
	if (preg_match("/adoori/i", $ppc_network_name)) {
		$ppc_network_icon = 'adoori.ico';    
	}	

	//adTegrity
	if ((preg_match("/adtegrity/i", $ppc_network_name)) or (preg_match("/ad tegrity/i", $ppc_network_name))) {
		$ppc_network_icon = 'adtegrity.png';    
	}

	//ask
	if (preg_match("/ask/i", $ppc_network_name)) {
		$ppc_network_icon = 'ask.ico';    
	}

	//adblade
	if ((preg_match("/adblade/i", $ppc_network_name)) or (preg_match("/ad blade/i", $ppc_network_name))) {
		$ppc_network_icon = 'adblade.ico';    
	}
	
	//adsonar
	if ((preg_match("/adsonar/i", $ppc_network_name)) or (preg_match("/ad sonar/i", $ppc_network_name))
	    or (preg_match("/quigo/i", $ppc_network_name))) {
		$ppc_network_icon = 'adsonar.png';    
	}
	
	//marchex
	if ((preg_match("/marchex/i", $ppc_network_name)) or (preg_match("/goclick/i", $ppc_network_name))) {
		$ppc_network_icon = 'marchex.png';    
	}

	//bidvertiser
	if (preg_match("/bidvertiser/i", $ppc_network_name)) {
		$ppc_network_icon = 'bidvertiser.gif';    
	}

	//enhance
	if (preg_match("/enhance/i", $ppc_network_name)) {
		$ppc_network_icon = 'enhance.ico';    
	}

	//facebook
	if ((preg_match("/facebook/i", $ppc_network_name)) or (preg_match("/fb/i", $ppc_network_name))) {
		$ppc_network_icon = 'facebook.ico';    
	}
	
	//findology
	if (preg_match("/findology/i", $ppc_network_name)) {
		$ppc_network_icon = 'findology.png';    
	}
	
	//google
	if ((preg_match("/google/i", $ppc_network_name)) or (preg_match("/adwords/i", $ppc_network_name))) {
		$ppc_network_icon = 'google.ico';    
	}

	//kanoodle
	if (preg_match("/kanoodle/i", $ppc_network_name)) {
		$ppc_network_icon = 'kanoodle.ico';    
	}

	//looksmart
	if (preg_match("/looksmart/i", $ppc_network_name)) {
		$ppc_network_icon = 'looksmart.gif';    
	}
	
	//hi5
	if ( (preg_match("/hi5/i", $ppc_network_name)) or (preg_match("/hi 5/i", $ppc_network_name))) {
		$ppc_network_icon = 'hi5.ico';    
	}

	//miva
	if ((preg_match("/miva/i", $ppc_network_name)) or (preg_match("/searchfeed/i", $ppc_network_name))) {
		$ppc_network_icon = 'miva.ico';    
	}

	//msn
	if ((preg_match("/microsoft/i", $ppc_network_name)) or (preg_match("/MSN/i", $ppc_network_name)) 
	    or (preg_match("/bing/i", $ppc_network_name)) or (preg_match("/adcenter/i", $ppc_network_name))  ) {
		$ppc_network_icon = 'msn.ico';    
	}

	//pulse360
	if ((preg_match("/pulse360/i", $ppc_network_name)) or (preg_match("/pulse 360/i", $ppc_network_name))) {
		$ppc_network_icon = 'pulse360.ico';    
	}

	//search123
	if ((preg_match("/search123/i", $ppc_network_name)) or (preg_match("/search 123/i", $ppc_network_name))) {
		$ppc_network_icon = 'google.ico';    
	}

	//searchfeed
	if (preg_match("/searchfeed/i", $ppc_network_name)) {
		$ppc_network_icon = 'searchfeed.gif';    
	} 

	//yahoo
	if ((preg_match("/yahoo/i", $ppc_network_name)) or (preg_match("/YSM/i", $ppc_network_name))) {
		$ppc_network_icon = 'yahoo.ico';    
	}


	//mediatraffic
	if ((preg_match("/mediatraffic/i", $ppc_network_name)) or (preg_match("/media traffic/i", $ppc_network_name))) {
		$ppc_network_icon = 'mediatraffic.png';    
	}

	//mochi
	if ((preg_match("/mochi/i", $ppc_network_name)) or (preg_match("/mochimedia/i", $ppc_network_name))
	    or (preg_match("/mochi media/i", $ppc_network_name))) {
		$ppc_network_icon = 'mochi.ico';    
	}	

	//myspace
	if ((preg_match("/myspace/i", $ppc_network_name)) or (preg_match("/my space/i", $ppc_network_name))
	    or (preg_match("/myads/i", $ppc_network_name)) or (preg_match("/my ads/i", $ppc_network_name))) {
		$ppc_network_icon = 'myspace.ico';    
	}
	
	//fox audience network
	if (preg_match("/fox/i", $ppc_network_name)) {
		$ppc_network_icon = 'foxnetwork.ico';    
	}
	
	//adsdaq
	if (preg_match("/adsdaq/i", $ppc_network_name)) {
		$ppc_network_icon = 'adsdaq.png';    
	}
	
	//twitter
	if (preg_match("/twitter/i", $ppc_network_name)) {
		$ppc_network_icon = 'twitter.ico';    
	}
	
		
	//amazon
	if (preg_match("/amazon/i", $ppc_network_name)) {
		$ppc_network_icon = 'amazon.ico';    
	}
	
	//adengage
	if ((preg_match("/adengage/i", $ppc_network_name)) or (preg_match("/ad engage/i", $ppc_network_name))) {
		$ppc_network_icon = 'adengage.ico';    
	}
	
	//adtoll
	if ((preg_match("/adtoll/i", $ppc_network_name)) or (preg_match("/ad toll/i", $ppc_network_name))) {
		$ppc_network_icon = 'adtoll.ico';    
	}
	
	//ezanga
	if ((preg_match("/ezangag/i", $ppc_network_name)) or (preg_match("/e zanga/i", $ppc_network_name))) {
		$ppc_network_icon = 'ezanga.ico';    
	}
	
	//aol
	if ((preg_match("/aol/i", $ppc_network_name)) or (preg_match("/quigo/i", $ppc_network_name))) {
		$ppc_network_icon = 'aol.ico';    
	}
	
	//aol
	if ((preg_match("/revtwt/i", $ppc_network_name)) or (preg_match("/rev twt/i", $ppc_network_name))) {
		$ppc_network_icon = 'revtwt.ico';    
	}
	
	//advertising.com
	if (preg_match("/advertising.com/i", $ppc_network_name)) {
		$ppc_network_icon = 'advertising.com.ico';    
	}
	
	//advertise.com
	if (preg_match("/advertise.com/i", $ppc_network_name)) {
		$ppc_network_icon = 'advertise.com.gif';    
	}
	
	//adready
	if ((preg_match("/adready/i", $ppc_network_name)) or (preg_match("/ad ready/i", $ppc_network_name))) {
		$ppc_network_icon = 'adready.ico';    
	}
	
	//abc search
	if ((preg_match("/abcsearch/i", $ppc_network_name)) or (preg_match("/abc search/i", $ppc_network_name))) {
		$ppc_network_icon = 'abcsearch.png';    
	}
	
	//abc search
	if ((preg_match("/megaclick/i", $ppc_network_name)) or (preg_match("/mega click/i", $ppc_network_name))) {
		$ppc_network_icon = 'megaclick.ico';    
	}
	
	//etology
	if (preg_match("/etology/i", $ppc_network_name)) {
		$ppc_network_icon = 'etology.ico';    
	}
	
	
	//youtube
	if ((preg_match("/youtube/i", $ppc_network_name)) or (preg_match("/you tube/i", $ppc_network_name))) {
		$ppc_network_icon = 'youtube.ico';    
	}

	//social media
	if ((preg_match("/socialmedia/i", $ppc_network_name)) or (preg_match("/social media/i", $ppc_network_name))) {
		$ppc_network_icon = 'socialmedia.ico';    
	}

	//zango
	if ((preg_match("/zango/i", $ppc_network_name)) or (preg_match("/leadimpact/i", $ppc_network_name))
	     or (preg_match("/lead impact/i", $ppc_network_name))) {
		$ppc_network_icon = 'zango.ico';    
	} 
	
	//jema media
	if ((preg_match("/jema media/i", $ppc_network_name)) or (preg_match("/jemamedia/i", $ppc_network_name))) {
		$ppc_network_icon = 'jemamedia.png';    
	} 
	
	//direct cpv
	if ((preg_match("/directcpv/i", $ppc_network_name)) or (preg_match("/direct cpv/i", $ppc_network_name))) {
		$ppc_network_icon = 'directcpv.png';    
	} 
	
	//linksador
	if ((preg_match("/linksador/i", $ppc_network_name))) {
		$ppc_network_icon = 'linksador.png';    
	} 
	
	//adon network
	if ((preg_match("/adonnetwork/i", $ppc_network_name)) or (preg_match("/adon network/i", $ppc_network_name)) 
	     or (preg_match("/Adon/i", $ppc_network_name)) or (preg_match("/ad-on/i", $ppc_network_name)) ) {
		$ppc_network_icon = 'adonnetwork.ico';     
	}
	
	//plenty of fish
	if ((preg_match("/plentyoffish/i", $ppc_network_name)) or (preg_match("/plenty of fish/i", $ppc_network_name)) 
	     or (preg_match("/pof/i", $ppc_network_name)) ) {
		$ppc_network_icon = 'plentyoffish.ico';     
	}

	//clicksor
	if (preg_match("/clicksor/i", $ppc_network_name)) {
		$ppc_network_icon = 'clicksor.ico';    
	} 

	//traffic vance
	if ((preg_match("/trafficvance/i", $ppc_network_name)) or (preg_match("/traffic vance/i", $ppc_network_name))) {
		$ppc_network_icon = 'trafficvance.ico';    
	}
	
	//adknowledge
	if ((preg_match("/adknowledge/i", $ppc_network_name)) or (preg_match("/bidsystem/i", $ppc_network_name))
		or (preg_match("/bid system/i", $ppc_network_name)) or (preg_match("/cubics/i", $ppc_network_name))) {
		$ppc_network_icon = 'adknowledge.ico';    
	}
	
	if ((preg_match("/admob/i", $ppc_network_name)) or (preg_match("/ad mob/i", $ppc_network_name))) {
		$ppc_network_icon = 'admob.ico';    
	}
	
	if ((preg_match("/adside/i", $ppc_network_name)) or (preg_match("/ad side/i", $ppc_network_name))) {
		$ppc_network_icon = 'adside.ico';    
	}


	//unknown
	if (!isset($ppc_network_icon)) {
		$ppc_network_icon = 'unknown.gif'; 
	} 

	$html['ppc_network_icon'] = '<img src="/202-img/icons/ppc/'.$ppc_network_icon.'" width="16" height="16" alt="'.$ppc_network_name.'" title="'.$ppc_network_name.': '.$ppc_account_name.'"/>';


	return $html['ppc_network_icon'];
} 



class FILTER {
	
	function startFilter($click_id, $ip_id, $ip_address, $user_id) {
		 
		//we only do the other checks, if the first ones have failed.
		//we will return the variable filter, if the $filter returns TRUE, when the click is inserted and recorded we will insert the new click already inserted,
		//what was lagign this query is before it would insert a click, then scan it and then update the click, the updating later on was lagging, now we will just insert and it will not stop the clicks from being redirected becuase of a slow update.
			
		//check the user
		$filter = FILTER::checkUserIP($click_id, $ip_id, $user_id);
		if ($filter == false) {
			
			//check the netrange  
			$filter = FILTER::checkNetrange($click_id, $ip_address);
			if ($filter == false) {  
			
				$filter = FILTER::checkLastIps($user_id, $ip_id);
				
				/*
				//check the configurations   
				$filter = FILTER::checkIPTiming($click_id, $ip_id, $user_id, $click_time, 1, 150); if ($filter == false) { 
				$filter = FILTER::checkIPTiming($click_id, $ip_id, $user_id, $click_time, 20, 3600); if ($filter == false) {  
				$filter = FILTER::checkIPTiming($click_id, $ip_id, $user_id, $click_time, 50, 86400); if ($filter == false) {  
				$filter = FILTER::checkIPTiming($click_id, $ip_id, $user_id, $click_time, 100, 2629743); if ($filter == false) {  
				$filter = FILTER::checkIPTiming($click_id, $ip_id, $user_id, $click_time, 1000, 7889231); if ($filter == false) {  
				}}}}}
				*/
			}
		}
		
		if ($filter == true) { 
			return 1;    
		} else { 
			return 0;    
		}
	}
	
	function checkUserIP($click_id, $ip_id, $user_id) {
	 
		$mysql['ip_id'] = mysql_real_escape_string($ip_id);      
		$mysql['user_id'] = mysql_real_escape_string($user_id);    
		
		$count_sql = "SELECT    COUNT(*) 
					  FROM      202_users 
					  WHERE     user_id='".$mysql['user_id']."' 
					  AND       user_last_login_ip_id='".$mysql['ip_id']."'";
		$count_result = _mysql_query($count_sql) ; //($count_sql);
	
		//if the click_id's ip address, is the same ip adddress of the click_id's owner's last logged in ip, filter this.  This means if the ip hit on the page was the same as the owner of the click affiliate program, we want to filter out the clicks by the owner when he/she  is trying to test 
		if (mysql_result($count_result,0,0) > 0) { 
			
			return true;  
		}
		return false;    
	}
	
	function checkNetrange($click_id, $ip_address) {
	
		$ip_address = ip2long($ip_address);
		
		//check each netrange
		/*google1 */ if (($ip_address >= 1208926208) and ($ip_address <= 1208942591)) { return true;  }
		/*MSN */ if (($ip_address >= 1093926912) and ($ip_address <= 1094189055)) { return true;  }
		/*google2 */ if (($ip_address >= 3512041472) and ($ip_address <= 3512074239)) { return true;  }
		/*Yahoo */ if (($ip_address >= 3640418304) and ($ip_address <= 3640426495)) { return true;  }
		/*google3 */ if (($ip_address >= 1123631104) and ($ip_address <= 1123639295)) { return true;  }
		/*level 3 communications */ if (($ip_address >= 1094189056) and ($ip_address <= 1094451199)) { return true;  }
		/*yahoo2 */ if (($ip_address >= 3515031552) and ($ip_address <= 3515039743)) { return true;  }
		/*Yahoo3 */ if (($ip_address >= 3633393664) and ($ip_address <= 3633397759)) { return true;  }
		/*Google5 */ if (($ip_address >= 1089052672) and ($ip_address <= 1089060863)) { return true;  }
		/*Yahoo */ if (($ip_address >= 1209925632) and ($ip_address <= 1209991167)) { return true;  }
		/*Yahoo */ if (($ip_address >= 1241907200) and ($ip_address <= 1241972735)) { return true;  }
		/*Performance Systems International Inc. */ if (($ip_address >= 637534208) and ($ip_address <= 654311423)) { return true;  }
		/*Microsoft */ if (($ip_address >= 3475898368) and ($ip_address <= 3475963903)) { return true;  }
		/*googleNew */ if (($ip_address >= -782925824) and ($ip_address <= -782893057)) { return true;  }
		
		//if it was none of theses, return false
		return false;           
	}  
	
	//this will filter out a click if it the IP WAS RECORDED, for a particular user within the last 24 hours, if it existed before, filter out this click.
	function checkLastIps($user_id, $ip_id) {

		$mysql['user_id'] = mysql_real_escape_string($user_id);
		$mysql['ip_id'] = mysql_real_escape_string($ip_id);
		
		$check_sql = "SELECT COUNT(*) AS count FROM 202_last_ips WHERE user_id='".$mysql['user_id']."' AND ip_id='".$mysql['ip_id']."'";
		$check_result = _mysql_query($check_sql) ; //($check_sql);
		$check_row = mysql_fetch_assoc($check_result);
		$count = $check_row['count'];
		 
		if ($count > 0) {
			//if this ip has been seen within the last 24 hours, filter it out. 
			return true;
		} else {
			
			//else if this ip has not been recorded, record it now
			$mysql['time'] = time();
			$insert_sql = "INSERT INTO 202_last_ips SET user_id='".$mysql['user_id']."', ip_id='".$mysql['ip_id']."', time='".$mysql['time']."'";
			$insert_result = _mysql_query($insert_sql) ; //($insert_sql);
			return false;	
		}
		
	}
	
	/* RETIRED FUNCTION
	//This thing lagged the crap outa the servers
	function checkIPTiming($click_id, $ip_id, $user_id, $click_time, $count, $seconds_ago) { 
		
		if ($click_time == 0) {
			return false;
			$click_time = time();
		}
		
		//filter out this entire ip, if this ip was detected five times within 30 seconds
		$mysql['click_id'] = mysql_real_escape_string($click_id);
		$mysql['user_id'] = mysql_real_escape_string($user_id);
		$mysql['ip_id'] = mysql_real_escape_string($ip_id);
		$mysql['seconds_ago'] = $click_time - $seconds_ago;
		
		if ($seconds_ago == 150) {
			$memcache = 0;	
		} else {
			$memcache = 1;	
		}
		
		//$difference = $mysql['seconds_ago'] - $click_time;
		//echo "difference $difference ::";
		
	
		$check_sql = "SELECT COUNT(*) AS count FROM clicks LEFT JOIN clicks_advance USING (click_id) WHERE ip_id='".$mysql['ip_id']."' AND click_time BETWEEN ".$mysql['seconds_ago'] ." AND $click_time";
		//echo "<p>$check_sql</p>";
		$check_result = _mysql_query($check_sql) ; //($check_sql); 
		$check_row = mysql_fetch_assoc($check_result);
		if ($check_row['count'] > $count) {
			//echo " " .$check_row['count'] . " within $seconds_ago seconds greater than $count  ";
			//not only update from, but also backwords
			return true;
		}
		
		return false;    
	} */
}
























  /*****************************************************************

	File name: browser.php
	Author: Gary White
	Last modified: November 10, 2003
	
	**************************************************************

	Copyright (C) 2003  Gary White
	
	This program is free software; you can redistribute it and/or
	modify it under the terms of the GNU General Public License
	as published by the Free Software Foundation; either version 2
	of the License, or (at your option) any later version.
	
	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details at:
	http://www.gnu.org/copyleft/gpl.html

	**************************************************************

	Browser class
	
	Identifies the user's Operating system, browser and version
	by parsing the HTTP_USER_AGENT string sent to the server
	
	Typical Usage:
	
		require_once($_SERVER['DOCUMENT_ROOT'].'/include/browser.php');
		$br = new Browser;
		echo "$br->Platform, $br->Name version $br->Version";
	
	For operating systems, it will correctly identify:
		Microsoft Windows
		MacIntosh
		Linux

	Anything not determined to be one of the above is considered to by Unix
	because most Unix based browsers seem to not report the operating system.
	The only known problem here is that, if a HTTP_USER_AGENT string does not
	contain the operating system, it will be identified as Unix. For unknown
	browsers, this may not be correct.
	
	For browsers, it should correctly identify all versions of:
		Amaya
		Galeon
		iCab
		Internet Explorer
			For AOL versions it will identify as Internet Explorer (AOL) and the version
			will be the AOL version instead of the IE version.
		Konqueror
		Lynx
		Mozilla
		Netscape Navigator/Communicator
		OmniWeb
		Opera
		Pocket Internet Explorer for handhelds
		Safari
		WebTV
*****************************************************************/

class browser{

	var $Name = "Unknown";
	var $Version = "Unknown";
	var $Platform = "Unknown";
	var $UserAgent = "Not reported";
	var $AOL = false;

	function browser(){
		$agent = $_SERVER['HTTP_USER_AGENT'];

		// initialize properties
		$bd['platform'] = "Unknown";
		$bd['browser'] = "Unknown";
		$bd['version'] = "Unknown";
		$this->UserAgent = $agent;

		// find operating system
		if (eregi("win", $agent))
			$bd['platform'] = "Windows";
		elseif (eregi("mac", $agent))
			$bd['platform'] = "MacIntosh";
		elseif (eregi("linux", $agent))
			$bd['platform'] = "Linux";
		elseif (eregi("OS/2", $agent))
			$bd['platform'] = "OS2";
		elseif (eregi("BeOS", $agent))
			$bd['platform'] = "BeOS";

		// test for Opera        
		if (eregi("opera",$agent)){
			$val = stristr($agent, "opera");
			if (eregi("/", $val)){
				$val = explode("/",$val);
				$bd['browser'] = $val[0];
				$val = explode(" ",$val[1]);
				$bd['version'] = $val[0];
			}else{
				$val = explode(" ",stristr($val,"opera"));
				$bd['browser'] = $val[0];
				$bd['version'] = $val[1];
			}

		// test for WebTV
		}elseif(eregi("webtv",$agent)){
			$val = explode("/",stristr($agent,"webtv"));
			$bd['browser'] = $val[0];
			$bd['version'] = $val[1];
		
		// test for MS Internet Explorer version 1
		}elseif(eregi("microsoft internet explorer", $agent)){
			$bd['browser'] = "MSIE";
			$bd['version'] = "1.0";
			$var = stristr($agent, "/");
			if (ereg("308|425|426|474|0b1", $var)){
				$bd['version'] = "1.5";
			}

		// test for NetPositive
		}elseif(eregi("NetPositive", $agent)){
			$val = explode("/",stristr($agent,"NetPositive"));
			$bd['platform'] = "BeOS";
			$bd['browser'] = $val[0];
			$bd['version'] = $val[1];

		// test for MS Internet Explorer
		}elseif(eregi("msie",$agent) && !eregi("opera",$agent)){
			$val = explode(" ",stristr($agent,"msie"));
			$bd['browser'] = $val[0];
			$bd['version'] = $val[1];
		
		// test for MS Pocket Internet Explorer
		}elseif(eregi("mspie",$agent) || eregi('pocket', $agent)){
			$val = explode(" ",stristr($agent,"mspie"));
			$bd['browser'] = "MSPIE";
			$bd['platform'] = "WindowsCE";
			if (eregi("mspie", $agent))
				$bd['version'] = $val[1];
			else {
				$val = explode("/",$agent);
				$bd['version'] = $val[1];
			}
			
		// test for Galeon
		}elseif(eregi("galeon",$agent)){
			$val = explode(" ",stristr($agent,"galeon"));
			$val = explode("/",$val[0]);
			$bd['browser'] = $val[0];
			$bd['version'] = $val[1];
			
		// test for Konqueror
		}elseif(eregi("Konqueror",$agent)){
			$val = explode(" ",stristr($agent,"Konqueror"));
			$val = explode("/",$val[0]);
			$bd['browser'] = $val[0];
			$bd['version'] = $val[1];
			
		// test for iCab
		}elseif(eregi("icab",$agent)){
			$val = explode(" ",stristr($agent,"icab"));
			$bd['browser'] = $val[0];
			$bd['version'] = $val[1];

		// test for OmniWeb
		}elseif(eregi("omniweb",$agent)){
			$val = explode("/",stristr($agent,"omniweb"));
			$bd['browser'] = $val[0];
			$bd['version'] = $val[1];

		// test for Phoenix
		}elseif(eregi("Phoenix", $agent)){
			$bd['browser'] = "Phoenix";
			$val = explode("/", stristr($agent,"Phoenix/"));
			$bd['version'] = $val[1];
		
		// test for Firebird
		}elseif(eregi("firebird", $agent)){
			$bd['browser']="Firebird";
			$val = stristr($agent, "Firebird");
			$val = explode("/",$val);
			$bd['version'] = $val[1];
			
		// test for Firefox
		}elseif(eregi("Firefox", $agent)){
			$bd['browser']="Firefox";
			$val = stristr($agent, "Firefox");
			$val = explode("/",$val);
			$bd['version'] = $val[1];
			
	  // test for Mozilla Alpha/Beta Versions
		}elseif(eregi("mozilla",$agent) && 
			eregi("rv:[0-9].[0-9][a-b]",$agent) && !eregi("netscape",$agent)){
			$bd['browser'] = "Mozilla";
			$val = explode(" ",stristr($agent,"rv:"));
			eregi("rv:[0-9].[0-9][a-b]",$agent,$val);
			$bd['version'] = str_replace("rv:","",$val[0]);
			
		// test for Mozilla Stable Versions
		}elseif(eregi("mozilla",$agent) &&
			eregi("rv:[0-9]\.[0-9]",$agent) && !eregi("netscape",$agent)){
			$bd['browser'] = "Mozilla";
			$val = explode(" ",stristr($agent,"rv:"));
			eregi("rv:[0-9]\.[0-9]\.[0-9]",$agent,$val);
			$bd['version'] = str_replace("rv:","",$val[0]);
		
		// test for Lynx & Amaya
		}elseif(eregi("libwww", $agent)){
			if (eregi("amaya", $agent)){
				$val = explode("/",stristr($agent,"amaya"));
				$bd['browser'] = "Amaya";
				$val = explode(" ", $val[1]);
				$bd['version'] = $val[0];
			} else {
				$val = explode("/",$agent);
				$bd['browser'] = "Lynx";
				$bd['version'] = $val[1];
			}
		
		// test for Safari
		}elseif(eregi("safari", $agent)){
			$bd['browser'] = "Safari";
			$bd['version'] = "";

		// remaining two tests are for Netscape
		}elseif(eregi("netscape",$agent)){
			$val = explode(" ",stristr($agent,"netscape"));
			$val = explode("/",$val[0]);
			$bd['browser'] = $val[0];
			$bd['version'] = $val[1];
		}elseif(eregi("mozilla",$agent) && !eregi("rv:[0-9]\.[0-9]\.[0-9]",$agent)){
			$val = explode(" ",stristr($agent,"mozilla"));
			$val = explode("/",$val[0]);
			$bd['browser'] = "Netscape";
			$bd['version'] = $val[1];
		}
		
		// clean up extraneous garbage that may be in the name
		$bd['browser'] = ereg_replace("[^a-z,A-Z]", "", $bd['browser']);
		// clean up extraneous garbage that may be in the version        
		$bd['version'] = ereg_replace("[^0-9,.,a-z,A-Z]", "", $bd['version']);
		
		// check for AOL
		if (eregi("AOL", $agent)){
			$var = stristr($agent, "AOL");
			$var = explode(" ", $var);
			$bd['aol'] = ereg_replace("[^0-9,.,a-z,A-Z]", "", $var[1]);
		}
		
		
		if (preg_match("/Windows/i", $bd['platform'])) { $bd['platform'] = 1; }
		if (preg_match("/Macintosh/i", $bd['platform'])) { $bd['platform'] = 2; }  
		if (preg_match("/Linux/i", $bd['platform'])) { $bd['platform'] = 3; } 
		if (preg_match("/OS2/i", $bd['platform'])) { $bd['platform'] = 4; } 
		if (preg_match("/BeOS/i", $bd['platform'])) { $bd['platform'] = 5; } 

		if (preg_match("/Internet Explorer/i", $bd['browser'])) { $bd['browser'] = 1; }
		if (preg_match("/MSIE/i", $bd['browser'])) { $bd['browser'] = 1; } 
		if (preg_match("/Mozilla/i", $bd['browser'])) { $bd['browser'] = 2; } 
		if (preg_match("/Firefox/i", $bd['browser'])) { $bd['browser'] = 2; } 
		if (preg_match("/Konqueror/i", $bd['browser'])) { $bd['browser'] = 3; } 
		if (preg_match("/Netscape/i", $bd['browser'])) { $bd['browser'] = 4; } 
		if (preg_match("/OmniWeb/i", $bd['browser'])) { $bd['browser'] = 5; } 
		if (preg_match("/Opera/i", $bd['browser'])) { $bd['browser'] = 6; } 
		if (preg_match("/Safari/i", $bd['browser'])) { $bd['browser'] = 7; } 
		if (preg_match("/AOL/i", $bd['browser'])) { $bd['browser'] = 8; } 
		if (preg_match("/Chrome/i", $agent)) { $bd['browser'] = 9; } 
		if (preg_match("/iphone/i", $agent)) { $bd['browser'] = 10; } 
		if (preg_match("/mobile/i", $agent)) { $bd['browser'] = 10; } 
		if (preg_match("/blackberry/i", $agent)) { $bd['browser'] = 10; } 
		if (preg_match("/treo/i", $agent)) { $bd['browser'] = 10; } 
		if (preg_match("/g1/i", $agent)) { $bd['browser'] = 10; } 
		if (preg_match("/android/i", $agent)) { $bd['browser'] = 10; } 
		if (preg_match("/pearl/i", $agent)) { $bd['browser'] = 10; } 
		if (preg_match("/dash/i", $agent)) { $bd['browser'] = 10; } 
		if (preg_match("/sidekick/i", $agent)) { $bd['browser'] = 10; }  
		if (preg_match("/wing/i", $agent)) { $bd['browser'] = 10; } 
		if (preg_match("/xbox/i", $agent)) { $bd['browser'] = 11; } 
		if (preg_match("/wii/i", $agent)) { $bd['browser'] = 11; } 
		if (preg_match("/playstation/i", $agent)) { $bd['browser'] = 11; } 
		
		
		// finally assign our properties
		$this->Browser = $bd['browser'];
		$this->Platform = $bd['platform'];
	   
	   // $this->Version = $bd['version'];
	   // $this->AOL = $bd['aol'];  
	}
}


class INDEXES {
	
		
	//this returns the ip_id, when a ip_address is given
	function get_ip_id($ip_address) {
	
		$mysql['ip_address'] = mysql_real_escape_string($ip_address);
		
		$ip_sql = "SELECT ip_id FROM 202_ips WHERE ip_address='".$mysql['ip_address']."'";
		$ip_result = _mysql_query($ip_sql);
		$ip_row = mysql_fetch_assoc($ip_result); 
		if ($ip_row) {
			//if this ip already exists, return the ip_id for it.
			$ip_id = $ip_row['ip_id'];
			
			return $ip_id;    
		} else {
			//else if this  doesn't exist, insert the new iprow, and return the_id for this new row we found
			//but before we do this, we need to grab the location_id
			$location_id = INDEXES::get_location_id($ip_address); 
			$mysql['location_id'] = mysql_real_escape_string($location_id);    
			$ip_sql = "INSERT INTO 202_ips SET ip_address='".$mysql['ip_address']."', location_id='".$mysql['location_id']."'";
			$ip_result = _mysql_query($ip_sql) ; //($ip_sql);
			$ip_id = mysql_insert_id();
			
			return $ip_id;    
		}
	}   
	
	//this returns the site_url_id, when a site_url_address is given
	function get_site_url_id($site_url_address) { 
	
		$mysql['site_url_address'] = mysql_real_escape_string($site_url_address);
		$site_domain_id = INDEXES::get_site_domain_id($site_url_address); 
		$mysql['site_domain_id'] = mysql_real_escape_string($site_domain_id);    
		$site_url_sql = "INSERT INTO 202_site_urls SET site_domain_id='".$mysql['site_domain_id']."', site_url_address='".$mysql['site_url_address']."'"; 
		$site_url_result = _mysql_query($site_url_sql) ; //($site_url_sql);
		$site_url_id = mysql_insert_id();
		return $site_url_id;
	}    
	
	//this returns the site_domain_id, when a site_url_address is given
	function get_site_domain_id($site_url_address) { 
	
		$parsed_url = @parse_url($site_url_address);
		$site_domain_host = $parsed_url['host'];
		$site_domain_host = str_replace('www.','',$site_domain_host);
		$mysql['site_domain_host'] = mysql_real_escape_string($site_domain_host);
		
		$site_domain_sql = "SELECT site_domain_id FROM 202_site_domains WHERE site_domain_host='".$mysql['site_domain_host']."'"; 
		$site_domain_result = _mysql_query($site_domain_sql);
		$site_domain_row = mysql_fetch_assoc($site_domain_result);
		if ($site_domain_row) {
			//if this site_domain_id already exists, return the site_domain_id for it.
			$site_domain_id = $site_domain_row['site_domain_id'];
			return $site_domain_id;    
		} else {
			//else if this  doesn't exist, insert the new iprow, and return the_id for this new row we found
			$site_domain_sql = "INSERT INTO 202_site_domains SET site_domain_host='".$mysql['site_domain_host']."'"; 
			$site_domain_result = _mysql_query($site_domain_sql) ; //($site_domain_sql);
			$site_domain_id = mysql_insert_id();
			return $site_domain_id;    
		}    
	} 
	
	//this returns the keyword_id
	function get_keyword_id($keyword) {
		
		//only grab the first 255 charactesr of keyword
		$keyword = substr($keyword, 0, 255);
		$mysql['keyword'] = mysql_real_escape_string($keyword);
		
		$keyword_sql = "SELECT keyword_id FROM 202_keywords WHERE keyword='".$mysql['keyword']."'";
		$keyword_result = _mysql_query($keyword_sql);
		$keyword_row = mysql_fetch_assoc($keyword_result);
		if ($keyword_row) {
			//if this already exists, return the id for it
			$keyword_id = $keyword_row['keyword_id'];
			return $keyword_id;    
		} else {
			//else if this ip doesn't exist, insert the row and grab the id for it
			$keyword_sql = "INSERT INTO 202_keywords SET keyword='".$mysql['keyword']."'";
			$keyword_result = _mysql_query($keyword_sql) ; //($keyword_sql);
			$keyword_id = mysql_insert_id();
			return $keyword_id;    
		}
	}
	
	//this returns the c1 id
	function get_c1_id($c1) {
		
		//only grab the first 50 charactesr of c1
		$c1 = substr($c1, 0, 50);
		$mysql['c1'] = mysql_real_escape_string($c1);
		
		$c1_sql = "SELECT c1_id FROM 202_tracking_c1 WHERE c1='".$mysql['c1']."'";
		$c1_result = _mysql_query($c1_sql);
		$c1_row = mysql_fetch_assoc($c1_result);
		if ($c1_row) {
			//if this already exists, return the id for it
			$c1_id = $c1_row['c1_id'];
			return $c1_id;    
		} else {
			//else if this ip doesn't exist, insert the row and grab the id for it
			$c1_sql = "INSERT INTO 202_tracking_c1 SET c1='".$mysql['c1']."'";
			$c1_result = _mysql_query($c1_sql) ; //($c1_sql);
			$c1_id = mysql_insert_id();
			return $c1_id;     
		}
	}
	
	//this returns the c2 id
	function get_c2_id($c2) {
		
		//only grab the first 50 charactesr of c2
		$c2 = substr($c2, 0, 50);
		$mysql['c2'] = mysql_real_escape_string($c2);
		
		$c2_sql = "SELECT c2_id FROM 202_tracking_c2 WHERE c2='".$mysql['c2']."'";
		$c2_result = _mysql_query($c2_sql);
		$c2_row = mysql_fetch_assoc($c2_result);
		if ($c2_row) {
			//if this already exists, return the id for it
			$c2_id = $c2_row['c2_id'];
			return $c2_id;    
		} else {
			//else if this ip doesn't exist, insert the row and grab the id for it
			$c2_sql = "INSERT INTO 202_tracking_c2 SET c2='".$mysql['c2']."'";
			$c2_result = _mysql_query($c2_sql) ; //($c2_sql);
			$c2_id = mysql_insert_id();
			return $c2_id;    
		}
	}
	
	//this returns the c3 id
	function get_c3_id($c3) {
		
		//only grab the first 50 charactesr of c3
		$c3 = substr($c3, 0, 50);
		$mysql['c3'] = mysql_real_escape_string($c3);
		
		$c3_sql = "SELECT c3_id FROM 202_tracking_c3 WHERE c3='".$mysql['c3']."'";
		$c3_result = _mysql_query($c3_sql);
		$c3_row = mysql_fetch_assoc($c3_result);
		if ($c3_row) {
			//if this already exists, return the id for it
			$c3_id = $c3_row['c3_id'];
			return $c3_id;    
		} else {
			//else if this ip doesn't exist, insert the row and grab the id for it
			$c3_sql = "INSERT INTO 202_tracking_c3 SET c3='".$mysql['c3']."'";
			$c3_result = _mysql_query($c3_sql) ; //($c3_sql);
			$c3_id = mysql_insert_id();
			return $c3_id;    
		}
	}
	
	//this returns the c4 id
	function get_c4_id($c4) {
		
		//only grab the first 50 charactesr of c4
		$c4 = substr($c4, 0, 50);
		$mysql['c4'] = mysql_real_escape_string($c4);
		
		$c4_sql = "SELECT c4_id FROM 202_tracking_c4 WHERE c4='".$mysql['c4']."'";
		$c4_result = _mysql_query($c4_sql);
		$c4_row = mysql_fetch_assoc($c4_result);
		if ($c4_row) {
			//if this already exists, return the id for it
			$c4_id = $c4_row['c4_id'];
			return $c4_id;    
		} else {
			//else if this ip doesn't exist, insert the row and grab the id for it
			$c4_sql = "INSERT INTO 202_tracking_c4 SET c4='".$mysql['c4']."'";
			$c4_result = _mysql_query($c4_sql) ; //($c4_sql);
			$c4_id = mysql_insert_id();
			return $c4_id;    
		}
	}
	
	//this returns the location_id
	function get_location_id($ip_address) {

		if (geoLocationDatabaseInstalled() == true) { 
			$clean['ip_address'] = ip2long($ip_address);
			$mysql['ip_address'] = mysql_real_escape_string($clean['ip_address']);
			$location_sql = "SELECT location_id FROM 202_locations_block WHERE location_block_ip_from >= '".$mysql['ip_address']."' AND location_block_ip_to <= '".$mysql['ip_address'] ."'";
			$location_row = memcache_mysql_fetch_assoc($location_sql);
			$location_id = $location_row['location_id'];
			return $location_id;
		} else {
			return 0;	
		}
	}
	
	function get_platform_and_browser_id() {
		$br = new Browser;
		$id['platform'] = $br->Platform;
		$id['browser'] = $br->Browser; 
		return $id;      
	}
}






 function getLocation($addr) {
   
	$ipnum = sprintf("%u", ip2long($addr));
	$mysql['ipnum'] = mysql_real_escape_string($ipnum);
	$block_sql = "SELECT location_id FROM blocks WHERE ".$mysql['ipnum']." BETWEEN startIpNum AND endIpNum";
	$block_row = memcache_mysql_fetch_assoc($block_sql);
	
	$mysql['location_id'] = mysql_real_escape_string($block_row['location_id']);
	$location_sql = "SELECT country_code, region, city FROM locations WHERE location_id='".$mysql['location_id']."'";
	$location_row = memcache_mysql_fetch_assoc($location_sql);
	
	$mysql['country_code'] = mysql_real_escape_string($location_row['country_code']);
	$country_sql = "SELECT country_name FROM countries WHERE country_code='".$mysql['country_code']."'";
	$country_row = memcache_mysql_fetch_assoc($country_sql);
	
	$location['country_name'] = $country_row['country_name'];
	$location['country_code'] = $location_row['country_code'];
	$location['region'] = $location_row['region'];
	$location['city'] = $location_row['city'];

	return $location;
}






function showChart ($chart, $chartWidth, $chartHeight) {
	
	$reg_key = "C1XUW9CU8Y4L.NS5T4Q79KLYCK07EK";
	
	$chart_xml = SendChartData ( $chart );
	$mysql['chart_xml'] = mysql_real_escape_string($chart_xml);

	$chart_sql = "INSERT INTO 202_charts SET chart_xml='".$mysql['chart_xml']."'";
	$chart_result = _mysql_query($chart_sql) ; //($chart_sql);
	$chart_id = mysql_insert_id();

	$url['chart_id'] = urlencode($chart_id);                                                                                                       
	echo InsertChart ( '/202-charts/charts.swf', 
					   '/202-charts/charts_library', 
					   '/202-charts/showChart.php?chart_id='.$url['chart_id'],
						$chartWidth, $chartHeight, 'FFFFFF', false, $reg_key );             
}


function runBreakdown($user_pref) {
	//grab time
		$time = grab_timeframe();    
	 
	 //get breakdown pref
		$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);   
		$user_sql = "
			SELECT
				user_time_register,
				user_pref_breakdown,
				user_pref_chart,
				user_pref_show
			FROM
				202_users
				LEFT JOIN 202_users_pref USING (user_id) 
			WHERE
				202_users.user_id='".$mysql['user_id']."'";
		$user_result = _mysql_query($user_sql) ; ; //($user_sql);
		$user_row = mysql_fetch_assoc($user_result);

		if ($user_row['user_pref_show'] == 'all') { $click_flitered = ''; }
		if ($user_row['user_pref_show'] == 'real') { $click_filtered = " AND click_filtered='0' "; }
		if ($user_row['user_pref_show'] == 'filtered') { $click_filtered = " AND click_filtered='1' "; }
		if ($user_row['user_pref_show'] == 'leads') { $click_filtered = " AND click_lead='1' "; } 

		
	//breakdown should be hour, day, month, or year.
		$breakdown = $user_row['user_pref_breakdown'];
		$pref_chart = $user_row['user_pref_chart'];
		
	//first delete old report
		$breakdown_sql = "
			DELETE
			FROM 202_sort_breakdowns
			WHERE user_id='".$mysql['user_id']."'
		";
		$breakdown_result = _mysql_query($breakdown_sql) ; //($breakdown_sql);
	
	//find where to start from.
		$start = $time['from'];
		$end = $time['to'];
	 
	 //make sure the start isn't past this users registration time, and likewise, make sure END isn't past today, else theses will try to grab reports for dates that do not exists slowing down mysql doing reports for nothing.    
		if ($user_row['user_time_register'] > $start) {
			$start = $user_row['user_time_register'];    
		}
		
		if (time() < $end) {
			$end = time();    
		}
		
	$x=0; 
	while ($end > $start) { 
		
		if ($breakdown == 'hour') { 
			$from = mktime(date('G',$end),0,0,date('m',$end),date('d',$end),date('y',$end));
			$to = mktime(date('G',$end),59,59,date('m',$end),date('d',$end),date('y',$end));
			$end = $end - 3600; 
		} elseif ($breakdown == 'day') { 
			$from = mktime(0,0,0,date('m',$end),date('d',$end),date('y',$end));
			$to = mktime(23,59,59,date('m',$end),date('d',$end),date('y',$end)); 
			$end = $end - 86400;
		} elseif ($breakdown == 'month') {
			$from = mktime(0,0,0,date('m',$end),1,date('y',$end));
			$to = mktime(23,59,59,date('m',$end),@getLastDayOfMonth(date('m',$end)),date('y',$end)); 
			$end = $end - 2629743;  
		} elseif ($breakdown == 'year') { 
			$from = mktime(0,0,0,1,1,date('y',$end));  
			$to = mktime(23,59,59,@getLastDayOfMonth(date('m',$end)),1,12,date('y',$end));
			$end = $end - 31556926;            
		}
 
		$mysql['from'] = mysql_real_escape_string($from);    
		$mysql['to'] = mysql_real_escape_string($to);    

		//build query
		$command = "
			SELECT
				COUNT(*) AS clicks,
				AVG(2c.click_cpc) AS avg_cpc,
				SUM(2c.click_lead) AS leads,
				SUM(2c.click_payout*2c.click_lead) AS income
			FROM
				202_clicks AS 2c
		";
		$db_table = "2c";
		$pref_time = false;
		if ($user_pref == true) {   
			$pref_adv = true;
			$command = $command . "
				LEFT JOIN 202_clicks_advance AS 2ca ON (2ca.click_id = 2c.click_id)
				LEFT JOIN 202_clicks_site AS 2cs ON (2cs.click_id = 2c.click_id)
			";
		} else {
			$pref_adv = false;     
		}
		$command = $command . "
			LEFT JOIN 202_aff_campaigns AS 2ac ON (2c.aff_campaign_id = 2ac.aff_campaign_id)
			LEFT JOIN 202_aff_networks AS 2an ON (2an.aff_network_id = 2ac.aff_network_id)
		";
		
		$pref_show = false;
		$pref_order = "
			AND (2c.click_alp = '1' OR (2ac.aff_campaign_deleted='0' AND 2an.aff_network_deleted='0'))
			$click_filtered
			AND 2c.click_time > ".$mysql['from'] ."
			AND 2c.click_time <= ".$mysql['to'] ."
		";
		$offset = false;
		$pref_limit = false;
		$count = false;  
		
		$query = query($command, $db_table, $pref_time, $pref_adv, $pref_show, $pref_order, $offset, $pref_limit, $count);
		$click_sql = $query['click_sql']; 
		
		$click_result = _mysql_query($click_sql);
		$click_row = mysql_fetch_assoc($click_result);
		
		//get the stats
		$clicks = 0;  
		$clicks = $click_row['clicks'];
		
		$total_clicks = $total_clicks + $clicks;        
	
		//avg cpc and cost    
		$avg_cpc = 0;
		$avg_cpc = $click_row['avg_cpc']; 
		
		$cost = 0;
		$cost = $clicks * $avg_cpc; 
		
		$total_cost = $total_cost + $cost;
		$total_avg_cpc = @round($total_cost/$total_clicks, 5);

		//leads
		$leads = 0;
		$leads = $click_row['leads'];
		
		$total_leads = $total_leads + $leads;        

		//signup ratio
		$su_ratio - 0;
		$su_ratio = @round($leads/$clicks*100,2);
		
		$total_su_ratio = @round($total_leads/$total_clicks*100,2);
		
		//were not using payout
		//current payout
		//$payout = 0;
		//$payout = $info_row['aff_campaign_payout'];

		//income
		$income = 0;
		$income = $click_row['income'];
		
		$total_income = $total_income + $income;    
		
		//grab the EPC
		$epc = 0;
		$epc = @round($income/$clicks,2);
				
		$total_epc = @round($total_income/$total_clicks,2);
		
		//net income
		$net = 0;
		$net = $income - $cost;
		
		$total_net = $total_income - $total_cost;
		
		//roi
		$roi = 0;
		$roi = @round($net/$cost*100);    
					
		$total_roi = @round($total_net/$total_cost);
		
		//html escape vars
		$mysql['clicks'] = mysql_real_escape_string($clicks);
		$mysql['leads'] = mysql_real_escape_string($leads);
		$mysql['su_ratio'] = mysql_real_escape_string($su_ratio);
		$mysql['epc'] = mysql_real_escape_string($epc);
		$mysql['avg_cpc'] = mysql_real_escape_string($avg_cpc);
		$mysql['income'] = mysql_real_escape_string($income);
		$mysql['cost'] = mysql_real_escape_string($cost);
		$mysql['net'] = mysql_real_escape_string($net);
		$mysql['roi'] = mysql_real_escape_string($roi);
		
		//insert chart
		$sort_breakdown_sql = "
			INSERT INTO
				202_sort_breakdowns
			SET
				sort_breakdown_from='".$mysql['from']."',
				sort_breakdown_to='".$mysql['to']."',
				user_id='".$mysql['user_id']."',
				sort_breakdown_clicks='".$mysql['clicks']."',
				sort_breakdown_leads='".$mysql['leads']."',
				sort_breakdown_su_ratio='".$mysql['su_ratio']."',
				sort_breakdown_payout='".$mysql['sort_breakdown_payout']."',
				sort_breakdown_epc='".$mysql['epc']."',
				sort_breakdown_avg_cpc='".$mysql['avg_cpc']."',
				sort_breakdown_income='".$mysql['income']."',
				sort_breakdown_cost='".$mysql['cost']."',
				sort_breakdown_net='".$mysql['net']."',
				sort_breakdown_roi='".$mysql['roi']."'
		";
		$sort_breakdown_result = _mysql_query($sort_breakdown_sql) ; //($sort_breakdown_sql);
	}
	
	$breakdown_sql = "
		SELECT *
		FROM 202_sort_breakdowns
		WHERE user_id='".$mysql['user_id']."'
	";
	$breakdown_result = _mysql_query($breakdown_sql) ; //($breakdown_sql);

	$chartWidth = $_POST['chartWidth'];
	$chartHeight = 180;
	
	//find where to start from.
		$start = $time['from']; 
		$end = $time['to']; 
		
	 //make sure the start isn't past this users registration time, and likewise, make sure END isn't past today, else theses will try to grab reports for dates that do not exists slowing down mysql doing reports for nothing.    
		if ($user_row['user_time_register'] > $start) {
			$start = $user_row['user_time_register'];    
		}
		
		if (time() < $end) {
			$end = time();    
		}
	
	//cacluate the skip
	$x=0; 
	while ($start < $end) { 
		if ($breakdown == 'hour') { 
			$start = $start + 3600; 
		} elseif ($breakdown == 'day') { 
			$start = $start + 86400;
		} elseif ($breakdown == 'month') {
			$start = $start + 2629743;  
		} elseif ($breakdown == 'year') { 
			$start = $start + 31556926;            
		}
		$x++;
	} 

	$skip=0;
	if ($breakdown == hour) { 
		while ($x > 9) { 
			$skip++;
			$x = $x - 9;        
		}
	} else {
		while ($x > 14) { 
			$skip++;
			$x = $x - 14;        
		}    
	}
	
	/* THIS IS A NET INCOME BAR GRAPH */
	if ($pref_chart == 'profitloss') { 
	
		//start the PHP multi-dimensional array and create the region titles
		$chart [ 'chart_data' ][ 0 ][ 0 ] = "";
		$chart [ 'chart_data' ][ 1 ][ 0 ] = "Income";
		$chart [ 'chart_data' ][ 2 ][ 0 ] = "Cost";
		$chart [ 'chart_data' ][ 3 ][ 0 ] = "Net";

		//extract the data from the query result one row at a time
		for ( $i=0; $i < mysql_num_rows($breakdown_result); $i++ ) {

		   //determine which column in the PHP array the current data belongs to
		   $col = mysql_result ( $breakdown_result, $i, "sort_breakdown_from");

		   //populate the PHP array with the Year title
		   $date = mysql_result ( $breakdown_result, $i, "sort_breakdown_from");
		   $date = date_chart($breakdown, $date);      
		  
		   $chart [ 'chart_data' ][ 0 ][ $col ] = $date;

		   //populate the PHP array with the revenue data
		   $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_income");
		   $chart [ 'chart_data' ][ 2 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_cost");
		   $chart [ 'chart_data' ][ 3 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_net");
		}
		
		$chart[ 'series_color' ] = array (  "70CF40", "CF4040", "409CCF","000000"); 
		$chart[ 'series_gap' ] = array ( 'set_gap'=>40, 'bar_gap'=>-35 );
		$chart[ 'chart_grid_h' ] = array ( 'alpha'=>20, 'color'=>"000000", 'thickness'=>1, 'type'=>"dashed" );
		$chart[ 'axis_value' ] = array (   'bold'             =>  false, 'size'             =>  10                   );                     
		$chart[ 'axis_category' ] = array (    'skip'          =>  $skip, 'bold'          =>  false, 'size'          =>  10); 
		$chart[ 'legend_label' ] = array (   'bold'    =>  true,   'size'    =>  12, ); 
		$chart[ 'chart_pref' ] = array ( 'line_thickness'=>1, 'point_shape'=>"none", 'fill_shape'=>true );                             
		$chart[ 'chart_rect' ] = array (   'x'               =>  40,'y'               =>  20,'width'           =>  $chartWidth-60,'height'          =>  $chartHeight,);                                   
		$chart[ 'chart_transition' ] = array ( 'type'=>"scale", 'delay'=>.5, 'duration'=>.5, 'order'=>"series" );
	
	} else {
	
		//start the PHP multi-dimensional array and create the region titles
		$chart [ 'chart_data' ][ 0 ][ 0 ] = "";
		
		if ($pref_chart == 'clicks') { $chart [ 'chart_data' ][ 1 ][ 0 ] = "Clicks"; }
		elseif ($pref_chart == 'leads') { $chart [ 'chart_data' ][ 1 ][ 0 ] = "Leads"; }  
		elseif ($pref_chart == 'su_ratio') { $chart [ 'chart_data' ][ 1 ][ 0 ] = "Signup Ratio"; }   
		elseif ($pref_chart == 'payout') { $chart [ 'chart_data' ][ 1 ][ 0 ] = "Payout"; }   
		elseif ($pref_chart == 'epc') { $chart [ 'chart_data' ][ 1 ][ 0 ] = "EPC"; } 
		elseif ($pref_chart == 'cpc') { $chart [ 'chart_data' ][ 1 ][ 0 ] = "Avg CPC"; } 
		elseif ($pref_chart == 'income') { $chart [ 'chart_data' ][ 1 ][ 0 ] = "Income"; } 
		elseif ($pref_chart == 'cost') { $chart [ 'chart_data' ][ 1 ][ 0 ] = "Cost"; } 
		elseif ($pref_chart == 'net') { $chart [ 'chart_data' ][ 1 ][ 0 ] = "Net"; } 
		elseif ($pref_chart == 'roi') { $chart [ 'chart_data' ][ 1 ][ 0 ] = "ROI"; } 
		
		//extract the data from the query result one row at a time
		for ( $i=0; $i < mysql_num_rows($breakdown_result); $i++ ) {

		   //determine which column in the PHP array the current data belongs to
		   $col = mysql_result ( $breakdown_result, $i, "sort_breakdown_from");

		   //populate the PHP array with the Year title
		   $date = mysql_result ( $breakdown_result, $i, "sort_breakdown_from");
		   $date = date_chart($breakdown, $date);
		   
		   $chart [ 'chart_data' ][ 0 ][ $col ] = $date;

		   //populate the PHP array with the revenue data
		  
		   
			if ($pref_chart == 'clicks') {  $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_clicks");  }
			elseif ($pref_chart == 'leads') {  $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_leads");  }  
			elseif ($pref_chart == 'su_ratio') {  $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_su_ratio");  }   
			elseif ($pref_chart == 'payout') {  $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_payout");  }   
			elseif ($pref_chart == 'epc') { $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_epc");  } 
			elseif ($pref_chart == 'cpc') {  $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_avg_cpc");  } 
			elseif ($pref_chart == 'income') { $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_income");  } 
			elseif ($pref_chart == 'cost') {  $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_cost");  } 
			elseif ($pref_chart == 'net') {  $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_net");  } 
			elseif ($pref_chart == 'roi') {  $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_roi");  } 
		}

		//$chart[ 'series_color' ] = array (  "003399"); 
		$chart[ 'series_color' ] = array (  "000000"); 
		$chart[ 'chart_type' ] = "Line";                          
		//$chart[ 'chart_transition' ] = array ( 'type'=>"dissolve", 'delay'=>.5, 'duration'=>.5, 'order'=>"series" ); 
		$chart[ 'chart_grid_h' ] = array ( 'alpha'=>20, 'color'=>"000000", 'thickness'=>1, 'type'=>"dashed" );
		   
	
	}    
	$chart[ 'chart_pref' ] = array ( 'line_thickness'=>1, 'point_shape'=>"circle", 'fill_shape'=>false );   
	$chart[ 'axis_value' ] = array (   'bold'             =>  false, 'size'             =>  10                   );                     
	$chart[ 'axis_category' ] = array (    'skip'          =>  $skip, 'bold'          =>  false, 'size'          =>  10); 
	$chart[ 'legend_label' ] = array (   'bold'    =>  true,   'size'    =>  12, ); 
	$chart[ 'chart_rect' ] = array (   'x'               =>  40,'y'               =>  20,'width'           =>  $chartWidth-60,'height'          =>  $chartHeight,);                                   
	
	showChart ($chart, $chartWidth-20, $chartHeight+40) ;

	
	?><div style="padding: 3px 0px;"></div><?
}


function date_chart($breakdown, $date) {
	if ($breakdown == 'hour') {
		$date = date('m/d/y g:ia', $date);
	} elseif ($breakdown == 'day') { 
		$date = date('M jS', $date);      
	} elseif ($breakdown == 'month') { 
		$date = date('M Y', $date);      
	} elseif ($breakdown == 'year') { 
		$date = date('Y', $date);      
	}
	return $date;
}


function runHourly($user_pref) {

	//grab time
		$time = grab_timeframe();    
	 
	 //get breakdown pref
		$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);   
		$user_sql = "
			SELECT
				user_time_register,
				user_pref_breakdown,
				user_pref_chart,
				user_pref_show
			FROM
				202_users AS 2u
				LEFT JOIN 202_users_pref AS 2up ON (2up.user_id = 2u.user_id)
			WHERE
				2u.user_id='".$mysql['user_id']."'
		";
		$user_result = _mysql_query($user_sql) ; ; //($user_sql);
		$user_row = mysql_fetch_assoc($user_result);


		if ($user_row['user_pref_show'] == 'all') { $click_flitered = ''; }
		if ($user_row['user_pref_show'] == 'real') { $click_filtered = " AND click_filtered='0' "; }
		if ($user_row['user_pref_show'] == 'filtered') { $click_filtered = " AND click_filtered='1' "; }
		if ($user_row['user_pref_show'] == 'leads') { $click_filtered = " AND click_lead='1' "; } 

	//breakdown should be hour, day, month, or year.
		$pref_chart = $user_row['user_pref_chart'];
		
	//first delete old report
		$breakdown_sql = "
			DELETE
			FROM 202_sort_breakdowns
			WHERE user_id='".$mysql['user_id']."'
		";
		$breakdown_result = _mysql_query($breakdown_sql) ; //($breakdown_sql);
	
	//find where to start from.
		$start = $time['from'];
		$end = $time['to'];

	 
	 //make sure the start isn't past this users registration time, and likewise, make sure END isn't past today, else theses will try to grab reports for dates that do not exists slowing down mysql doing reports for nothing.    
		if ($user_row['user_time_register'] > $start) {
			$start = $user_row['user_time_register'];    
		}
		
		if (time() < $end) {
			$end = time();    
		}
		
	$x=0; 
	while ($end > $start) { 
		
		//each hour
		$from = mktime(date('G',$end),0,0,date('m',$end),date('d',$end),date('y',$end));
		$to = mktime(date('G',$end),59,59,date('m',$end),date('d',$end),date('y',$end));
		$end = $end - 3600; 

		$hour =  date('G', $end);
		
		$mysql['from'] = mysql_real_escape_string($from);    
		$mysql['to'] = mysql_real_escape_string($to);    

		//build query
		$command = "
			SELECT
				COUNT(*) AS clicks,
				SUM(click_cpc) AS cost,
				SUM(2c.click_lead) AS leads,
				SUM(2c.click_payout*2c.click_lead) AS income
			FROM
				202_clicks AS 2c
		";
		$db_table = "2c";
		$pref_time = false;
		if ($user_pref == true) {   
			$pref_adv = true;
			$command = $command . "
				LEFT JOIN 202_clicks_advance AS 2ca ON (2ca.click_id = 2c.click_id)
				LEFT JOIN 202_clicks_site AS 2cs ON (2cs.click_id = 2c.click_id)
			";
		} else {
			$pref_adv = false;     
		}
		$command = $command . "
			LEFT JOIN 202_aff_campaigns AS 2ac ON (2c.aff_campaign_id = 2ac.aff_campaign_id)
			LEFT JOIN 202_aff_networks AS 2an ON (2an.aff_network_id = 2ac.aff_network_id)
		";
		
		$pref_show = false;
		$pref_order = "
			AND (2c.click_alp = '1' OR (2ac.aff_campaign_deleted='0' AND 2an.aff_network_deleted='0'))
			$click_filtered
			AND 2c.click_time > ".$mysql['from'] ."
			AND 2c.click_time <= ".$mysql['to'] ."
		";
		$offset = false;
		$pref_limit = false;
		$count = false;  
		
		$query = query($command, $db_table, $pref_time, $pref_adv, $pref_show, $pref_order, $offset, $pref_limit, $count); 
		$click_sql = $query['click_sql'];  
		
		$click_result = _mysql_query($click_sql) ; //($click_sql);
		$click_row = mysql_fetch_assoc($click_result);
		
		
		//get the stats
		$clicks[$hour] = $click_row['clicks'] + $clicks[$hour];
		
		$total_clicks = $total_clicks + $click_row['clicks'];
	
		//avg cpc and cost    
		$cost[$hour] = $click_row['cost'] + $cost[$hour]; 
		
		if ($clicks[$hour] > 0) { 
		$avg_cpc[$hour] = $cost[$hour] / $clicks[$hour];
		}
		
		$total_cost = $total_cost +  $click_row['cost'];
		$total_avg_cpc = @round($total_cost/$total_clicks, 5);

		//leads
		$leads[$hour] = $click_row['leads'] + $leads[$hour];
		
		$total_leads = $total_leads +  $click_row['leads'] ;

		//signup ratio
		$su_ratio[$hour] = @round($leads[$hour]/$clicks[$hour]*100,2);
		
		$total_su_ratio = @round($total_leads/$total_clicks*100,2);
		
		//were not using payout
		//current payout
		//$payout = 0;
		//$payout = $info_row['aff_campaign_payout'];

		//income
		$income[$hour] = $click_row['income'] + $income[$hour];
		
		$total_income = $total_income +  $click_row['income'];
		
		//grab the EPC
		$epc = @round($income[$hour]/$clicks[$hour],2);
				
		$total_epc = @round($total_income/$total_clicks,2);
		
		//net income
		$net[$hour] = $income[$hour] - $cost[$hour];
		
		$total_net = $total_income - $total_cost;
		
		//roi
		$roi[$hour] = @round($net[$hour]/$cost[$hour]*100);    
					
		$total_roi = @round($total_net/$total_cost);
	}
	
	for ($hour=0; $hour < 24; $hour++) { 

		//html escape vars
		$from = $hour;
		$to = $hour +1;   if ($to == 24) { $to = 0; }
		
		$mysql['from'] = mysql_real_escape_string($from);
		$mysql['to'] = mysql_real_escape_string($to);
		$mysql['clicks'] = mysql_real_escape_string($clicks[$hour]);
		$mysql['leads'] = mysql_real_escape_string($leads[$hour]);
		$mysql['su_ratio'] = mysql_real_escape_string($su_ratio[$hour]);
		$mysql['epc'] = mysql_real_escape_string($epc[$hour]);
		$mysql['avg_cpc'] = mysql_real_escape_string($avg_cpc[$hour]);
		$mysql['income'] = mysql_real_escape_string($income[$hour]);
		$mysql['cost'] = mysql_real_escape_string($cost[$hour]);
		$mysql['net'] = mysql_real_escape_string($net[$hour]);
		$mysql['roi'] = mysql_real_escape_string($roi[$hour]);
		
		//insert chart
		$sort_breakdown_sql = "
			INSERT INTO 202_sort_breakdowns
			SET
				sort_breakdown_from='".$mysql['from']."',
				sort_breakdown_to='".$mysql['to']."',
				user_id='".$mysql['user_id']."',
				sort_breakdown_clicks='".$mysql['clicks']."',
				sort_breakdown_leads='".$mysql['leads']."',
				sort_breakdown_su_ratio='".$mysql['su_ratio']."',
				sort_breakdown_payout='".$mysql['sort_breakdown_payout']."',
				sort_breakdown_epc='".$mysql['epc']."',
				sort_breakdown_avg_cpc='".$mysql['avg_cpc']."',
				sort_breakdown_income='".$mysql['income']."',
				sort_breakdown_cost='".$mysql['cost']."',
				sort_breakdown_net='".$mysql['net']."',
				sort_breakdown_roi='".$mysql['roi']."'
		";
		$sort_breakdown_result = _mysql_query($sort_breakdown_sql) ; //($sort_breakdown_sql);    
	}
	
	
	$breakdown_sql = "
		SELECT *
		FROM 202_sort_breakdowns
		WHERE user_id='".$mysql['user_id']."'
		ORDER BY sort_breakdown_from ASC
	";
	$breakdown_result = _mysql_query($breakdown_sql) ; //($breakdown_sql);

	$chartWidth = $_POST['chartWidth'];
	$chartHeight = 180;
	
	
	/* THIS IS A NET INCOME BAR GRAPH */
	if ($pref_chart == 'profitloss') { 
	
		//start the PHP multi-dimensional array and create the region titles
		$chart [ 'chart_data' ][ 0 ][ 0 ] = "";
		$chart [ 'chart_data' ][ 1 ][ 0 ] = "Income";
		$chart [ 'chart_data' ][ 2 ][ 0 ] = "Cost";
		$chart [ 'chart_data' ][ 3 ][ 0 ] = "Net";


		//extract the data from the query result one row at a time
		for ( $i=0; $i < mysql_num_rows($breakdown_result); $i++ ) {

		   //determine which column in the PHP array the current data belongs to
		   $col = mysql_result ( $breakdown_result, $i, "sort_breakdown_from");
		  $col++;
		   
		   
		   //populate the PHP array with the Year title
		   $hour = mysql_result ( $breakdown_result, $i, "sort_breakdown_from");
	
		   if ($hour == 0) { $hour = 'midnight'; } 
		   if (( $hour > 0) and ($hour < 12)) { $hour = $hour . 'am'; } 
		   if ($hour == 12) { $hour =  'noon'; }
		   if ($hour > 12) { $hour = ($hour - 12) . 'pm'; }
		  
		   $chart [ 'chart_data' ][ 0 ][ $col ] = $hour;

		   //populate the PHP array with the revenue data
		   $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_income");
		   $chart [ 'chart_data' ][ 2 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_cost");
		   $chart [ 'chart_data' ][ 3 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_net");
		}
		
		$chart[ 'series_color' ] = array (  "70CF40", "CF4040", "409CCF","000000"); 
		$chart[ 'series_gap' ] = array ( 'set_gap'=>40, 'bar_gap'=>-35 );
		$chart[ 'chart_grid_h' ] = array ( 'alpha'=>20, 'color'=>"000000", 'thickness'=>1, 'type'=>"dashed" );
		$chart[ 'axis_value' ] = array (   'bold'             =>  false, 'size'             =>  10                   );                     
		$chart[ 'axis_category' ] = array (   'skip'          =>  3,  'bold'          =>  false, 'size'          =>  10); 
		$chart[ 'legend_label' ] = array (   'bold'    =>  true,   'size'    =>  12, ); 
		$chart[ 'chart_pref' ] = array ( 'line_thickness'=>1, 'point_shape'=>"none", 'fill_shape'=>true );                             
		$chart[ 'chart_rect' ] = array (   'x'               =>  40,'y'               =>  20,'width'           =>  $chartWidth-60,'height'          =>  $chartHeight,);                                   
		$chart[ 'chart_transition' ] = array ( 'type'=>"scale", 'delay'=>.5, 'duration'=>.5, 'order'=>"series" );
	
	} else {
	
		//start the PHP multi-dimensional array and create the region titles
		$chart [ 'chart_data' ][ 0 ][ 0 ] = "";
		
		if ($pref_chart == 'clicks') { $chart [ 'chart_data' ][ 1 ][ 0 ] = "Clicks"; }
		elseif ($pref_chart == 'leads') { $chart [ 'chart_data' ][ 1 ][ 0 ] = "Leads"; }  
		elseif ($pref_chart == 'su_ratio') { $chart [ 'chart_data' ][ 1 ][ 0 ] = "Signup Ratio"; }   
		elseif ($pref_chart == 'payout') { $chart [ 'chart_data' ][ 1 ][ 0 ] = "Payout"; }   
		elseif ($pref_chart == 'epc') { $chart [ 'chart_data' ][ 1 ][ 0 ] = "EPC"; } 
		elseif ($pref_chart == 'cpc') { $chart [ 'chart_data' ][ 1 ][ 0 ] = "Avg CPC"; } 
		elseif ($pref_chart == 'income') { $chart [ 'chart_data' ][ 1 ][ 0 ] = "Income"; } 
		elseif ($pref_chart == 'cost') { $chart [ 'chart_data' ][ 1 ][ 0 ] = "Cost"; } 
		elseif ($pref_chart == 'net') { $chart [ 'chart_data' ][ 1 ][ 0 ] = "Net"; } 
		elseif ($pref_chart == 'roi') { $chart [ 'chart_data' ][ 1 ][ 0 ] = "ROI"; } 
		
		//extract the data from the query result one row at a time
		for ( $i=0; $i < mysql_num_rows($breakdown_result); $i++ ) {

		   //determine which column in the PHP array the current data belongs to
		   $col = mysql_result ( $breakdown_result, $i, "sort_breakdown_from");
		   $col++;
		   
		   
		    //populate the PHP array with the Year title
		   $hour = mysql_result ( $breakdown_result, $i, "sort_breakdown_from");
	
		   if ($hour == 0) { $hour = 'midnight'; } 
		   if (( $hour > 0) and ($hour < 12)) { $hour = $hour . 'am'; } 
		   if ($hour == 12) { $hour =  'noon'; }
		   if ($hour > 12) { $hour = ($hour - 12) . 'pm'; }
		  
		   $chart [ 'chart_data' ][ 0 ][ $col ] = $hour;

		   //populate the PHP array with the revenue data
		  
		   
			if ($pref_chart == 'clicks') {  $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_clicks");  }
			elseif ($pref_chart == 'leads') {  $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_leads");  }  
			elseif ($pref_chart == 'su_ratio') {  $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_su_ratio");  }   
			elseif ($pref_chart == 'payout') {  $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_payout");  }   
			elseif ($pref_chart == 'epc') { $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_epc");  } 
			elseif ($pref_chart == 'cpc') {  $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_avg_cpc");  } 
			elseif ($pref_chart == 'income') { $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_income");  } 
			elseif ($pref_chart == 'cost') {  $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_cost");  } 
			elseif ($pref_chart == 'net') {  $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_net");  } 
			elseif ($pref_chart == 'roi') {  $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_roi");  } 
		}

		//$chart[ 'series_color' ] = array (  "003399"); 
		$chart[ 'series_color' ] = array (  "000000"); 
		$chart[ 'chart_type' ] = "Line";                          
		$chart[ 'chart_transition' ] = array ( 'type'=>"dissolve", 'delay'=>.5, 'duration'=>.5, 'order'=>"series" ); 
		$chart[ 'chart_grid_h' ] = array ( 'alpha'=>20, 'color'=>"000000", 'thickness'=>1, 'type'=>"dashed" );
		   
	
	}    
	$chart[ 'chart_pref' ] = array ( 'line_thickness'=>1, 'point_shape'=>"circle", 'fill_shape'=>false );   
	$chart[ 'axis_value' ] = array (   'bold'             =>  false, 'size'             =>  10                   );                     
	$chart[ 'axis_category' ] = array (    'bold'          =>  false, 'size'          =>  10); 
	$chart[ 'legend_label' ] = array (   'bold'    =>  true,   'size'    =>  12, ); 
	$chart[ 'chart_rect' ] = array (   'x'               =>  40,'y'               =>  20,'width'           =>  $chartWidth-60,'height'          =>  $chartHeight,);                                   
	

	
	showChart ($chart, $chartWidth-20, $chartHeight+40) ;

	
	?><div style="padding: 3px 0px;"></div><?
}

function runWeekly($user_pref) {

	//grab time
		$time = grab_timeframe();    
	 
	 //get breakdown pref
		$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);   
		$user_sql = "SELECT 	user_time_register,
								user_pref_breakdown,
								user_pref_chart,
								user_pref_show
					 FROM   202_users LEFT JOIN 202_users_pref USING (user_id) 
					 WHERE  202_users.user_id='".$mysql['user_id']."'";
		$user_result = _mysql_query($user_sql) ; ; //($user_sql);
		$user_row = mysql_fetch_assoc($user_result);

		if ($user_row['user_pref_show'] == 'all') { $click_flitered = ''; }
		if ($user_row['user_pref_show'] == 'real') { $click_filtered = " AND click_filtered='0' "; }
		if ($user_row['user_pref_show'] == 'filtered') { $click_filtered = " AND click_filtered='1' "; }
		if ($user_row['user_pref_show'] == 'leads') { $click_filtered = " AND click_lead='1' "; } 

		
	//breakdown should be hour, day, month, or year.
		$breakdown = 'day';
		$pref_chart = $user_row['user_pref_chart'];
		
	//first delete old report
		$breakdown_sql = "
			DELETE
			FROM 202_sort_breakdowns
			WHERE user_id='".$mysql['user_id']."'
		";
		$breakdown_result = _mysql_query($breakdown_sql) ; //($breakdown_sql);
	
	//find where to start from.
		$start = $time['from'];
		$end = $time['to'];
	 
	 //make sure the start isn't past this users registration time, and likewise, make sure END isn't past today, else theses will try to grab reports for dates that do not exists slowing down mysql doing reports for nothing.    
		if ($user_row['user_time_register'] > $start) {
			$start = $user_row['user_time_register'];    
		}
		
		if (time() < $end) {
			$end = time();    
		}
		
		
	$x=0; 
	while ($end > $start) { 
		
		$from = mktime(0,0,0,date('m',$end),date('d',$end),date('y',$end));
		$to = mktime(23,59,59,date('m',$end),date('d',$end),date('y',$end)); 
		$end = $end - 86400;
		
		$day =  date('D', $end);
		switch ($day) { 
			case "Sun": $day = 1; break;
			case "Mon": $day = 2; break;
			case "Tue": $day = 3; break;
			case "Wed": $day = 4; break;
			case "Thu": $day = 5; break;
			case "Fri": $day = 6; break;
			case "Sat": $day = 7; break;
		}
		
		$mysql['from'] = mysql_real_escape_string($from);    
		$mysql['to'] = mysql_real_escape_string($to);    

		//build query
		$command = "
			SELECT
				COUNT(*) AS clicks,
				SUM(click_cpc) AS cost,
				SUM(2c.click_lead) AS leads,
				SUM(2c.click_payout*2c.click_lead) AS income
			FROM
				202_clicks AS 2c
		";
		$db_table = "2c";
		$pref_time = false;
		if ($user_pref == true) {   
			$pref_adv = true;
			$command = $command . "
				LEFT JOIN 202_clicks_advance AS 2ca ON (2ca.click_id = 2c.click_id)
				LEFT JOIN 202_clicks_site AS 2cs ON (2cs.click_id = 2c.click_id)
			";
		} else {
			$pref_adv = false;     
		}
		$command = $command . "
			LEFT JOIN 202_aff_campaigns AS 2ac ON (2c.aff_campaign_id = 2ac.aff_campaign_id)
			LEFT JOIN 202_aff_networks AS 2an ON (2an.aff_network_id = 2ac.aff_network_id)
		";
		
		$pref_show = false;
		$pref_order = "
			AND (2c.click_alp = '1' OR (2ac.aff_campaign_deleted='0' AND 2an.aff_network_deleted='0'))
			$click_filtered
			AND 2c.click_time > ".$mysql['from'] ."
			AND 2c.click_time <= ".$mysql['to'] ."
		";
		
		$offset = false;
		$pref_limit = false;
		$count = false;  
		
		$query = query($command, $db_table, $pref_time, $pref_adv, $pref_show, $pref_order, $offset, $pref_limit, $count);
		
		$click_sql = $query['click_sql'];
		
		$click_result = _mysql_query($click_sql) ; //($click_sql);
		$click_row = mysql_fetch_assoc($click_result);
		
		
		//get the stats
		$clicks[$day] = $click_row['clicks'] + $clicks[$day];
		
		$total_clicks = $total_clicks + $click_row['clicks'];
	
		//avg cpc and cost    
		$cost[$day] = $click_row['cost'] + $cost[$day]; 
		
		if ($clicks[$day] > 0) { 
		$avg_cpc[$day] = $cost[$day] / $clicks[$day];
		}
		
		$total_cost = $total_cost +  $click_row['cost'];
		$total_avg_cpc = @round($total_cost/$total_clicks, 5);

		//leads
		$leads[$day] = $click_row['leads'] + $leads[$day];
		
		$total_leads = $total_leads +  $click_row['leads'] ;

		//signup ratio
		$su_ratio[$day] = @round($leads[$day]/$clicks[$day]*100,2);
		
		$total_su_ratio = @round($total_leads/$total_clicks*100,2);
		
		//were not using payout
		//current payout
		//$payout = 0;
		//$payout = $info_row['aff_campaign_payout'];

		//income
		$income[$day] = $click_row['income'] + $income[$day];
		
		$total_income = $total_income +  $click_row['income'];
		
		//grab the EPC
		$epc = @round($income[$day]/$clicks[$day],2);
				
		$total_epc = @round($total_income/$total_clicks,2);
		
		//net income
		$net[$day] = $income[$day] - $cost[$day];
		
		$total_net = $total_income - $total_cost;
		
		//roi
		$roi[$day] = @round($net[$day]/$cost[$day]*100);    
					
		$total_roi = @round($total_net/$total_cost);
	}
	
	for ($day = 1; $day < 8; $day++) { 
	
		//html escape vars
		$from = $day;
		//$to = $hour +1;   if ($to == 24) { $to = 0; }
		
		$mysql['from'] = mysql_real_escape_string($from);
		$mysql['to'] = mysql_real_escape_string($to);
		$mysql['clicks'] = mysql_real_escape_string($clicks[$day]);
		$mysql['leads'] = mysql_real_escape_string($leads[$day]);
		$mysql['su_ratio'] = mysql_real_escape_string($su_ratio[$day]);
		$mysql['epc'] = mysql_real_escape_string($epc[$day]);
		$mysql['avg_cpc'] = mysql_real_escape_string($avg_cpc[$day]);
		$mysql['income'] = mysql_real_escape_string($income[$day]);
		$mysql['cost'] = mysql_real_escape_string($cost[$day]);
		$mysql['net'] = mysql_real_escape_string($net[$day]);
		$mysql['roi'] = mysql_real_escape_string($roi[$day]);
		
		//insert chart
		$sort_breakdown_sql = "
			INSERT INTO 202_sort_breakdowns
			SET
				sort_breakdown_from='".$mysql['from']."',
				sort_breakdown_to='".$mysql['to']."',
				user_id='".$mysql['user_id']."',
				sort_breakdown_clicks='".$mysql['clicks']."',
				sort_breakdown_leads='".$mysql['leads']."',
				sort_breakdown_su_ratio='".$mysql['su_ratio']."',
				sort_breakdown_payout='".$mysql['sort_breakdown_payout']."',
				sort_breakdown_epc='".$mysql['epc']."',
				sort_breakdown_avg_cpc='".$mysql['avg_cpc']."',
				sort_breakdown_income='".$mysql['income']."',
				sort_breakdown_cost='".$mysql['cost']."',
				sort_breakdown_net='".$mysql['net']."',
				sort_breakdown_roi='".$mysql['roi']."'
		";
		$sort_breakdown_result = _mysql_query($sort_breakdown_sql) ; #echo "<p>$sort_breakdown_sql</p>";
	}
	
	
	$breakdown_sql = "
		SELECT *
		FROM 202_sort_breakdowns
		WHERE user_id='".$mysql['user_id']."'
		ORDER BY sort_breakdown_from ASC
	";
	$breakdown_result = _mysql_query($breakdown_sql) ; //($breakdown_sql);

	$chartWidth = $_POST['chartWidth'];
	$chartHeight = 180;
	
	
	/* THIS IS A NET INCOME BAR GRAPH */
	if ($pref_chart == 'profitloss') { 
	
		//start the PHP multi-dimensional array and create the region titles
		$chart [ 'chart_data' ][ 0 ][ 0 ] = "";
		$chart [ 'chart_data' ][ 1 ][ 0 ] = "Income";
		$chart [ 'chart_data' ][ 2 ][ 0 ] = "Cost";
		$chart [ 'chart_data' ][ 3 ][ 0 ] = "Net";


		//extract the data from the query result one row at a time
		for ( $i=0; $i < mysql_num_rows($breakdown_result); $i++ ) {

		   //determine which column in the PHP array the current data belongs to
		   $col = mysql_result ( $breakdown_result, $i, "sort_breakdown_from");
		  $col++;
		   
		   
		   //populate the PHP array with the Year title
		   $day = mysql_result ( $breakdown_result, $i, "sort_breakdown_from");
		   
		   switch ($day) { 
				case 1: $day = "Sun"; break;
				case 2: $day = "Mon"; break;
				case 3: $day = "Tue"; break;
				case 4: $day = "Wed"; break;
				case 5: $day = "Thu"; break;
				case 6: $day = "Fri"; break;
				case 7: $day = "Sat"; break;
			}
	
		    $chart [ 'chart_data' ][ 0 ][ $col ] = $day;

		   //populate the PHP array with the revenue data
		   $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_income");
		   $chart [ 'chart_data' ][ 2 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_cost");
		   $chart [ 'chart_data' ][ 3 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_net");
		}
		
		$chart[ 'series_color' ] = array (  "70CF40", "CF4040", "409CCF","000000"); 
		$chart[ 'series_gap' ] = array ( 'set_gap'=>40, 'bar_gap'=>-35 );
		$chart[ 'chart_grid_h' ] = array ( 'alpha'=>20, 'color'=>"000000", 'thickness'=>1, 'type'=>"dashed" );
		$chart[ 'axis_value' ] = array (   'bold'             =>  false, 'size'             =>  10                   );                     
		$chart[ 'axis_category' ] = array (   'skip'          =>  3,  'bold'          =>  false, 'size'          =>  10); 
		$chart[ 'legend_label' ] = array (   'bold'    =>  true,   'size'    =>  12, ); 
		$chart[ 'chart_pref' ] = array ( 'line_thickness'=>1, 'point_shape'=>"none", 'fill_shape'=>true );                             
		$chart[ 'chart_rect' ] = array (   'x'               =>  40,'y'               =>  20,'width'           =>  $chartWidth-60,'height'          =>  $chartHeight,);                                   
		$chart[ 'chart_transition' ] = array ( 'type'=>"scale", 'delay'=>.5, 'duration'=>.5, 'order'=>"series" );
	
	} else {
	
		//start the PHP multi-dimensional array and create the region titles
		$chart [ 'chart_data' ][ 0 ][ 0 ] = "";
		
		if ($pref_chart == 'clicks') { $chart [ 'chart_data' ][ 1 ][ 0 ] = "Clicks"; }
		elseif ($pref_chart == 'leads') { $chart [ 'chart_data' ][ 1 ][ 0 ] = "Leads"; }  
		elseif ($pref_chart == 'su_ratio') { $chart [ 'chart_data' ][ 1 ][ 0 ] = "Signup Ratio"; }   
		elseif ($pref_chart == 'payout') { $chart [ 'chart_data' ][ 1 ][ 0 ] = "Payout"; }   
		elseif ($pref_chart == 'epc') { $chart [ 'chart_data' ][ 1 ][ 0 ] = "EPC"; } 
		elseif ($pref_chart == 'cpc') { $chart [ 'chart_data' ][ 1 ][ 0 ] = "Avg CPC"; } 
		elseif ($pref_chart == 'income') { $chart [ 'chart_data' ][ 1 ][ 0 ] = "Income"; } 
		elseif ($pref_chart == 'cost') { $chart [ 'chart_data' ][ 1 ][ 0 ] = "Cost"; } 
		elseif ($pref_chart == 'net') { $chart [ 'chart_data' ][ 1 ][ 0 ] = "Net"; } 
		elseif ($pref_chart == 'roi') { $chart [ 'chart_data' ][ 1 ][ 0 ] = "ROI"; } 
		
		//extract the data from the query result one row at a time
		for ( $i=0; $i < mysql_num_rows($breakdown_result); $i++ ) {

		   //determine which column in the PHP array the current data belongs to
		   $col = mysql_result ( $breakdown_result, $i, "sort_breakdown_from");
		   $col++;
		   
		   
		    //populate the PHP array with the Year title
		   $day = mysql_result ( $breakdown_result, $i, "sort_breakdown_from");
		   switch ($day) { 
				case 1: $day = "Sun"; break;
				case 2: $day = "Mon"; break;
				case 3: $day = "Tue"; break;
				case 4: $day = "Wed"; break;
				case 5: $day = "Thu"; break;
				case 6: $day = "Fri"; break;
				case 7: $day = "Sat"; break;
			}

		   $chart [ 'chart_data' ][ 0 ][ $col ] = $day;

		   //populate the PHP array with the revenue data
		  
		   
			if ($pref_chart == 'clicks') {  $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_clicks");  }
			elseif ($pref_chart == 'leads') {  $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_leads");  }  
			elseif ($pref_chart == 'su_ratio') {  $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_su_ratio");  }   
			elseif ($pref_chart == 'payout') {  $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_payout");  }   
			elseif ($pref_chart == 'epc') { $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_epc");  } 
			elseif ($pref_chart == 'cpc') {  $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_avg_cpc");  } 
			elseif ($pref_chart == 'income') { $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_income");  } 
			elseif ($pref_chart == 'cost') {  $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_cost");  } 
			elseif ($pref_chart == 'net') {  $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_net");  } 
			elseif ($pref_chart == 'roi') {  $chart [ 'chart_data' ][ 1 ][ $col ] = mysql_result ( $breakdown_result, $i, "sort_breakdown_roi");  } 
		}

		//$chart[ 'series_color' ] = array (  "003399"); 
		$chart[ 'series_color' ] = array (  "000000"); 
		$chart[ 'chart_type' ] = "Line";                          
		$chart[ 'chart_transition' ] = array ( 'type'=>"dissolve", 'delay'=>.5, 'duration'=>.5, 'order'=>"series" ); 
		$chart[ 'chart_grid_h' ] = array ( 'alpha'=>20, 'color'=>"000000", 'thickness'=>1, 'type'=>"dashed" );
		   
	
	}    
	$chart[ 'chart_pref' ] = array ( 'line_thickness'=>1, 'point_shape'=>"circle", 'fill_shape'=>false );   
	$chart[ 'axis_value' ] = array (   'bold'             =>  false, 'size'             =>  10                   );                     
	$chart[ 'axis_category' ] = array (    'bold'          =>  false, 'size'          =>  10); 
	$chart[ 'legend_label' ] = array (   'bold'    =>  true,   'size'    =>  12, ); 
	$chart[ 'chart_rect' ] = array (   'x'               =>  40,'y'               =>  20,'width'           =>  $chartWidth-60,'height'          =>  $chartHeight,);                                   
	

	
	showChart ($chart, $chartWidth-20, $chartHeight+40) ;

	
	?><div style="padding: 3px 0px;"></div><?
	
}


//for the memcache functions, we want to make a function that will be able to store al the memcache keys for a specific user, so when they update it, we can clear out all the associated memcache keys for that user, so we need two functions one to record all the use memcache keys, and another to delete all those user memcahces keys, will associate it in an array and use the main user_id for the identifier.


function memcache_set_user_key($sql) { 

	if (AUTH::logged_in() == true) { 
	
		global $memcache;
	
		$sql = md5($sql);
		$user_id = $_SESSION['user_id'];
		
		$getCache = $memcache -> get($user_id);
		
		$queries = explode(",",$getCache);
		
		if (!in_array( $sql, $queries ) ) {
		
			$queries[] = $sql;
		
		}
		
		$queries = implode(",", $queries);
		
		$setCache = $memcache -> set ($user_id, $queries);
		
	}	

}


function memcache_delete_user_keys() {

	/*global $memcache;

	$user_id = $_SESSION['user_id'];
	
	$queryKeys = explode(",", $memcache -> get($user_id));
	
	foreach ($queryKeys as $deletedKey) {
		if ($deletedKey != '') { 
			$memcache -> delete($deletedKey);
		}
	}*/

}


function memcache_mysql_fetch_assoc( $sql, $allowCaching = 1, $minutes = 5 ) {
	
	global $memcacheWorking, $memcache;

	if ($memcacheWorking == false) { 
		
		$result = _mysql_query($sql);
		$row = mysql_fetch_assoc($result);
		return $row;
	} else {

		if( $allowCaching == 0 ) {
			$result = _mysql_query($sql);
			$row = mysql_fetch_assoc($result);
			return $row;
		} else {

			// Check if its set
			$getCache = $memcache->get( md5( $sql ) );
			
			if( $getCache === false ) {
				// cache this data
				$fetchArray = mysql_fetch_assoc( _mysql_query( $sql ) );
				$setCache = $memcache->set( md5( $sql ), serialize( $fetchArray ), false, 60*$minutes  );
				
				//store all this users memcache keys, so we can delete them fast later on
				memcache_set_user_key($sql);
				
				return $fetchArray;
			
			} else {
			
				// Data Cached
				return unserialize( $getCache );
			}
		}
	}
}

function foreach_memcache_mysql_fetch_assoc( $sql, $allowCaching = 1 ) {
	 
	global $memcacheWorking, $memcache;
	
	if ($memcacheWorking == false) { 
		$row = array();
		$result = _mysql_query($sql) ; //($sql);
		while ($fetch = mysql_fetch_assoc($result)) {
			$row[] = $fetch;
		}
		return $row;
	} else {

		if( $allowCaching == 0 ) {
			$row = array();
			$result = _mysql_query($sql) ; //($sql);
			while ($fetch = mysql_fetch_assoc($result)) {
				$row[] = $fetch;
			}
			return $row;
		} else {
			
			$getCache = $memcache->get( md5( $sql ) );
			if( $getCache === false ) { 
				//if data is NOT cache, cache this data
				$row = array();     
				$result = _mysql_query($sql) ; //($sql);
				while ($fetch = mysql_fetch_assoc($result)) {
					$row[] = $fetch;
				}
				$setCache = $memcache->set( md5( $sql ), serialize( $row ), false, 60*5 );  
				
				//store all this users memcache keys, so we can delete them fast later on
				memcache_set_user_key($sql);
				
				return $row;
			} else {
				//if data is cached, returned the cache data Data Cached
				return unserialize( $getCache );
			}
		}
	}
}

/* to use this function

$sql = "SELECT * FROM users";
$result = foreach_memcache_mysql_fetch_assoc($sql);
foreach( $result as $key => $row ) {
	print_r_html( $row );
}   */ 


$CHRONO_STARTTIME = 0;
define("RET_TIME", "ms"); //Can be set to "ms" for milliseconds
//or "s" for seconds
function chronometer()
{
   global $CHRONO_STARTTIME;
   
   $now = microtime(TRUE);  // float, in _seconds_
   
   if (RET_TIME === 's') {
	   $now = $now + time();
	   $malt = 1;
	   $round = 7;
   } elseif (RET_TIME === 'ms') {
	   $malt = 1000;
	   $round = 3;
   } else {
	   die("Unsupported RET_TIME value");
   }
   
   if ($CHRONO_STARTTIME > 0) {
	   /* Stop the chronometer : return the amount of time since it was started,
	   in ms with a precision of 3 decimal places, and reset the start time.
	   We could factor the multiplication by 1000 (which converts seconds
	   into milliseconds) to save memory, but considering that floats can
	   reach e+308 but only carry 14 decimals, this is certainly more precise */
	   
	   $retElapsed = round($now * $malt - $CHRONO_STARTTIME * $malt, $round);
	   
	   $CHRONO_STARTTIME = $now;
	   
	   return $retElapsed;
   } else {
	   // Start the chronometer : save the starting time
	  
	   $CHRONO_STARTTIME = $now;
	   
	   return 0;
   }
}










function break_lines($text) { 
	$text = '<p class="first">' . $text;
	$text = str_replace("\r",'</p><p>',$text);
	$text = $text . '</p>';	
	return $text;
}



//this funciton delays an SQL statement, puts in in a mysql table, to be cronjobed out every 5 minutes
function delay_sql($delayed_sql) {
	
	$mysql['delayed_sql'] = str_replace("'","''",$delayed_sql);
	$mysql['delayed_time'] = time();
	
	$delayed_sql="INSERT INTO  202_delayed_sqls 
					
					(
						delayed_sql ,
						delayed_time
					)
					
					VALUES 
					(
						'".$mysql['delayed_sql'] ."',
						'".$mysql['delayed_time']."'
					);";
	
	$delayed_result = _mysql_query($delayed_sql) ; //($delayed_sql);
}




function rotateTrackerUrl($tracker_row) { 
	
	if (!$tracker_row['aff_campaign_rotate']) return $tracker_row['aff_campaign_url'];
	
	$mysql['aff_campaign_id'] = mysql_real_escape_string($tracker_row['aff_campaign_id']);
	$urls = array();
	array_push($urls, $tracker_row['aff_campaign_url']);

	
	if ($tracker_row['aff_campaign_url_2']) array_push($urls, $tracker_row['aff_campaign_url_2']);
	if ($tracker_row['aff_campaign_url_3']) array_push($urls, $tracker_row['aff_campaign_url_3']);
	if ($tracker_row['aff_campaign_url_4']) array_push($urls, $tracker_row['aff_campaign_url_4']);
	if ($tracker_row['aff_campaign_url_5']) array_push($urls, $tracker_row['aff_campaign_url_5']);
	
	$count = count($urls);
	
	$sql5 = "SELECT rotation_num FROM 202_rotations WHERE aff_campaign_id='".$mysql['aff_campaign_id']."'";
	$result5 = _mysql_query($sql5);
	$row5 = mysql_fetch_assoc($result5);
	if ($row5) { 
		
		$old_num = $row5['rotation_num'];
		if ($old_num >= ($count - 1))		$num = 0;
		else 						$num = $old_num + 1;
		
		$mysql['num'] = mysql_real_escape_string($num);
		$sql5 = " UPDATE 202_rotations SET rotation_num='".$mysql['num']."' WHERE aff_campaign_id='".$mysql['aff_campaign_id']."'";
		$result5 = _mysql_query($sql5);
	
	} else {
		//insert the rotation
		$num = 0;
		$mysql['num'] = mysql_real_escape_string($num);
		$sql5 = " INSERT INTO 202_rotations SET aff_campaign_id='".$mysql['aff_campaign_id']."',  rotation_num='".$mysql['num']."' ";
		$result5 = _mysql_query($sql5);
		$rotation_num = 0;
	}
	
	$url = $urls[$num];
	return $url;
}

function replaceTrackerPlaceholders($url,$click_id) {
	//get the tracker placeholder values
	$mysql['click_id'] = mysql_real_escape_string($click_id);
	
	if(preg_match('/\[\[c1\]\]/', $url) || preg_match('/\[\[c2\]\]/', $url) || preg_match('/\[\[c3\]\]/', $url) || preg_match('/\[\[c4\]\]/', $url)) {
		$click_sql = "
			SELECT
				2c.click_id,
				2tc1.c1,
				2tc2.c2,
				2tc3.c3,
				2tc4.c4
			FROM
				202_clicks AS 2c
				LEFT OUTER JOIN 202_clicks_tracking AS 2ct ON (2ct.click_id = 2c.click_id)
				LEFT OUTER JOIN 202_tracking_c1 AS 2tc1 ON (2ct.c1_id = 2tc1.c1_id)
				LEFT OUTER JOIN 202_tracking_c2 AS 2tc2 ON (2ct.c2_id = 2tc2.c2_id)
				LEFT OUTER JOIN 202_tracking_c3 AS 2tc3 ON (2ct.c3_id = 2tc3.c3_id)
				LEFT OUTER JOIN 202_tracking_c4 AS 2tc4 ON (2ct.c4_id = 2tc4.c4_id)
			WHERE
				2c.click_id='".$mysql['click_id']."'
		";
		$click_result = _mysql_query($click_sql);
		$click_row = mysql_fetch_assoc($click_result);
		
		$url = preg_replace('/\[\[c1\]\]/', $click_row['c1'], $url);
		$url = preg_replace('/\[\[c2\]\]/', $click_row['c2'], $url);
		$url = preg_replace('/\[\[c3\]\]/', $click_row['c3'], $url);
		$url = preg_replace('/\[\[c4\]\]/', $click_row['c4'], $url);
	}
	
	$url = preg_replace('/\[\[subid\]\]/', $mysql['click_id'], $url);
	
	return $url;
}

function setClickIdCookie($click_id,$campaign_id=0) {
	//set the cookie for the PIXEL to fire, expire in 30 days
	$expire = time() + 2592000;
	setcookie('tracking202subid',$click_id,$expire,'/', $_SERVER['SERVER_NAME']);
	setcookie('tracking202subid_a_' . $campaign_id,$click_id,$expire,'/', $_SERVER['SERVER_NAME']);
}

?>