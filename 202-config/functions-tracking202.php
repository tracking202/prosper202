<?php 

use UAParser\Parser;

//This function will return true, if a user is logged in correctly, and false, if they are not.

function send_email($to,$subject,$message,$from,$type_id) {

	$database = DB::getInstance();
	$db = $database->getConnection();

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
		$mysql['email_from'] = $db->real_escape_string($from);
		$user_sql = "SELECT user_id FROM users_info WHERE user_email='".$mysql['email_from']."'";
		$user_result = _mysqli_query($user_sql) ; ; ; //($user_sql);
		$user_row = $user_result->fetch_assoc();
		$mysql['email_from_user_id'] = $db->real_escape_string($user_row['user_id']);    
	
	//get information from receiever
		$mysql['email_to'] = $db->real_escape_string($to);
		$user_sql = "SELECT user_id FROM users_info WHERE user_email='".$mysql['email_to']."'";
		$user_result = _mysqli_query($user_sql) ; ; //($user_sql);
		$user_row = $user_result->fetch_assoc();
		$mysql['email_to_user_id'] = $db->real_escape_string($user_row['user_id']);    

		
	//get server information
		$site_url_address = 'http://' . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
		$site_url_id = INDEXES::get_site_url_id($site_url_address);
		 
		$ip_id = INDEXES::get_ip_id($_SERVER['HTTP_X_FORWARDED_FOR']);
		
		$mysql['site_url_id'] = $db->real_escape_string($site_url_id);
		$mysql['ip_id'] = $db->real_escape_string($ip_id);
		$mysql['email_time'] = time();
		$mysql['email_subject'] = $db->real_escape_string($subject);
		$mysql['email_message'] = $db->real_escape_string($message);
		$mysql['email_type_id'] = $db->real_escape_string($type_id);
		
		 
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
		$record_result = _mysqli_query($record_sql);  ; //($record_sql);
		
}

function record_mysql_error($sql) {

	$database = DB::getInstance();
	$db = $database->getConnection();

	global $server_row;
										 
	//record the mysql error
		$clean['mysql_error_text'] = mysqli_error($db); 

	//if on dev server, echo the error
		
			echo $sql . '<br/><br/>' .$clean['mysql_error_text'] .'<br/><br/>';   die();
	
		
		 
		$ip_id = INDEXES::get_ip_id($_SERVER['HTTP_X_FORWARDED_FOR']);
		$mysql['ip_id'] = $db->real_escape_string($ip_id);     
		
		$site_url = 'http://'.$_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
		$site_id = INDEXES::get_site_url_id($site_url); 
		$mysql['site_id'] = $db->real_escape_string($site_id); 

		$mysql['user_id'] = $db->real_escape_string(strip_tags($_SESSION['user_id']));
		$mysql['mysql_error_text'] = $db->real_escape_string($clean['mysql_error_text']);
		$mysql['mysql_error_sql'] = $db->real_escape_string($sql);
		$mysql['script_url'] = $db->real_escape_string(strip_tags($_SERVER['SCRIPT_URL'])); 
		$mysql['server_name'] = $db->real_escape_string(strip_tags($_SERVER['SERVER_NAME'])); 
		$mysql['mysql_error_time'] = time();
									 
		$report_sql = "INSERT     INTO  202_mysql_errors
								SET     mysql_error_text='".$mysql['mysql_error_text']."',
										mysql_error_sql='".$mysql['mysql_error_sql']."',
										user_id='".$mysql['user_id']."',
										ip_id='".$mysql['ip_id']."',
										site_id='".$mysql['site_id']."',
										mysql_error_time='".$mysql['mysql_error_time']."'"; 
		$report_query = _mysqli_query($report_sql);
											 
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
												 
					_mysqli_query: ' . $sql . '<br/><br/>
					 
					mysql_error: ' . $clean['mysql_error_text'];
		$from = $_SERVER['SERVER_ADMIN'];
		$type = 3; //type 3 is mysql_error
	
		//send_email($to,$subject,$message,$from,$type);
	
	//report error to user and end page ?>
		<div class="warning" style="margin: 40px auto; width: 450px;">
			<div>
				<h3>A database error has occured, the webmaster has been notified</h3>
				<p>If this error persists, you may email us directly: <?php printf('<a href="mailto:%s">%s</a>',$_SERVER['SERVER_ADMIN'],$_SERVER['SERVER_ADMIN']); ?></p>
			</div>
		</div>
		
	   
		<?php template_bottom($server_row);  die();
}



function dollar_format($amount, $cpv = false) {
	setlocale(LC_MONETARY, 'en_US.UTF-8');
	if ($cpv == true) {
		$decimals = 5;
	} else {
		$decimals = 2;
	}
	
	if ($amount >= 0) {
		$new_amount = money_format('%.'.$decimals.'n' ,$amount);
	} else { 
		$new_amount = money_format('%.'.$decimals.'n' ,$amount);
		$new_amount = '('.$new_amount.')';    
	}
	
	return $new_amount;
} 
							 




function display_calendar($page, $show_time, $show_adv, $show_bottom, $show_limit, $show_breakdown, $show_type, $show_cpc_or_cpv = true, $show_adv_breakdown = false) { 
  	global $navigation;
 	$database = DB::getInstance();
	$db = $database->getConnection();
	AUTH::set_timezone($_SESSION['user_timezone']);
  		 
	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
	$user_sql = "SELECT * FROM 202_users_pref WHERE user_id=".$mysql['user_id'];
	$user_result = _mysqli_query($user_sql);
	$user_row = $user_result->fetch_assoc();
	
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
	$html['ip'] = htmlentities($user_row['user_pref_ip'], ENT_QUOTES, 'UTF-8');
	$html['user_pref_country_id'] = htmlentities($user_row['user_pref_country_id'], ENT_QUOTES, 'UTF-8');
	$html['user_pref_region_id'] = htmlentities($user_row['user_pref_region_id'], ENT_QUOTES, 'UTF-8');
	$html['user_pref_isp_id'] = htmlentities($user_row['user_pref_isp_id'], ENT_QUOTES, 'UTF-8');
	$html['referer'] = htmlentities($user_row['user_pref_referer'], ENT_QUOTES, 'UTF-8');
	$html['keyword'] = htmlentities($user_row['user_pref_keyword'], ENT_QUOTES, 'UTF-8'); 
	$html['page'] = htmlentities($page, ENT_QUOTES, 'UTF-8');
	$html['user_pref_device_id'] = htmlentities($user_row['user_pref_device_id'], ENT_QUOTES, 'UTF-8');
	$html['user_pref_browser_id'] = htmlentities($user_row['user_pref_browser_id'], ENT_QUOTES, 'UTF-8');
	$html['user_pref_platform_id'] = htmlentities($user_row['user_pref_platform_id'], ENT_QUOTES, 'UTF-8'); ?>
	
<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
	<div id="preferences-wrapper">
		<span style="position: absolute; font-size:12px;"><span class="fui-search"></span> Refine your search: </span>
		<form id="user_prefs" onsubmit="return false;" class="form-inline text-right" role="form">
		<div class="row">
			<div class="col-xs-12">
				<label for="from">Start date: </label>
				<div class="form-group datepicker" style="margin-right: 5px;">
				    <input type="text" class="form-control input-sm" name="from" id="from" value="<?php echo $html['from']; ?>">
				</div>

				<label for="to">End date: </label>
				<div class="form-group datepicker">
				    <input type="text" class="form-control input-sm" name="to" id="to" value="<?php echo $html['to']; ?>">
				</div>

				<div class="form-group">
					<label class="sr-only" for="user_pref_time_predefined">Date</label>
					<select class="form-control input-sm" name="user_pref_time_predefined" id="user_pref_time_predefined" onchange="set_user_pref_time_predefined();">
					    <option value="">Custom Date</option>                                       
						<option <?php if ($time['user_pref_time_predefined'] == 'today') { echo 'selected=""'; } ?> value="today">Today</option>
						<option <?php if ($time['user_pref_time_predefined'] == 'yesterday') { echo 'selected=""'; } ?> value="yesterday">Yesterday</option>
						<option <?php if ($time['user_pref_time_predefined'] == 'last7') { echo 'selected=""'; } ?> value="last7">Last 7 Days</option>
						<option <?php if ($time['user_pref_time_predefined'] == 'last14') { echo 'selected=""'; } ?> value="last14">Last 14 Days</option>
						<option <?php if ($time['user_pref_time_predefined'] == 'last30') { echo 'selected=""'; } ?> value="last30">Last 30 Days</option>
						<option <?php if ($time['user_pref_time_predefined'] == 'thismonth') { echo 'selected=""'; } ?> value="thismonth">This Month</option>
						<option <?php if ($time['user_pref_time_predefined'] == 'lastmonth') { echo 'selected=""'; } ?> value="lastmonth">Last Month</option>
						<option <?php if ($time['user_pref_time_predefined'] == 'thisyear') { echo 'selected=""'; } ?> value="thisyear">This Year</option>
						<option <?php if ($time['user_pref_time_predefined'] == 'lastyear') { echo 'selected=""'; } ?> value="lastyear">Last Year</option>
						<option <?php if ($time['user_pref_time_predefined'] == 'alltime') { echo 'selected=""'; } ?> value="alltime">All Time</option>
					</select>
				</div>
			</div>
		</div>

		<div class="form_seperator" style="margin:5px 0px; padding:1px">
			<div class="col-xs-12"></div>
		</div>

		<?php if ($navigation[1] == 'tracking202') { ?>
		<div class="row" style="text-align:left; <?php if ($show_adv == false) { echo 'display:none;'; } ?>">
			<div class="col-xs-12" style="margin-top:5px;">
				<div class="row">
					<div class="col-xs-6">
						<label>PPC Network/Account: </label>
						
						<div class="form-group">
							<img id="ppc_network_id_div_loading" class="loading" style="display: none;" src="/202-img/loader-small.gif"/>
							<div id="ppc_network_id_div"></div>
						</div>

						<div class="form-group">
							<div id="ppc_account_id_div"></div>
						</div>
					</div>

					<div class="col-xs-6" style="text-align:right">
						<label>Visitor IP: </label>
						<div class="form-group">
							<input type="text" class="form-control input-sm" name="ip" id="ip" value="<?php echo $html['ip']; ?>"/>
						</div>
					</div>

					<div class="col-xs-6">
						<label>Aff Network/Campaign: </label>
						<div class="form-group">
							<img id="aff_network_id_div_loading" class="loading" style="display: none;" src="/202-img/loader-small.gif"/>
							<div id="aff_network_id_div" ></div>
						</div>

						<div class="form-group">
							<div id="aff_campaign_id_div"></div>
						</div>
					</div>

					<div class="col-xs-6" style="text-align:right">
						<div class="row">
							<div class="col-xs-6">
								<label>Keyword: </label>
								<div class="form-group">
									<input name="keyword" id="keyword" type="text" class="form-control input-sm" value="<?php echo $html['keyword']; ?>"/>
								</div>
							</div>
							<div class="col-xs-6">
								<label>Referer: </label>
								<div class="form-group">
									<input name="referer" id="referer" type="text" class="form-control input-sm" value="<?php echo $html['referer']; ?>"/>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<div class="form_seperator" style="margin:5px 0px; padding:1px; <?php if ($show_adv == false) { echo 'display:none;'; } ?>">
			<div class="col-xs-12"></div>
		</div>
		<div id="more-options" style="height: 87px; <?php if (($user_row['user_pref_adv'] != '1') or ($show_adv == false)) { echo 'display: none;'; } ?>">
			<div class="row" style="text-align:left;">
				<div class="col-xs-12" style="margin-top:5px;">
					<div class="row">
						<div class="col-xs-6">
							<label>Text Ad: </label>
							
							<div class="form-group">
								<img id="text_ad_id_div_loading" class="loading" style="display: none;" src="/202-img/loader-small.gif"/>
								<div id="text_ad_id_div" style="margin-left: 69px;"></div>
							</div>

							<div class="form-group">
								<img id="ad_preview_div_loading" class="loading" style="display: none;" src="/202-img/loader-small.gif"/>
								<div id="ad_preview_div" style="position: absolute;top: -12px; font-size:10px;"></div>
							</div>
						</div>

						<div class="col-xs-6" style="text-align:right">
							<div class="row">
								<div class="col-xs-6">
								<label>Device type: </label>
								<div class="form-group">
									<img id="device_id_div_loading" class="loading" style="right:0px; left: 5px;" src="/202-img/loader-small.gif"/>
									<div id="device_id_div" style="top: -12px; font-size:10px;">
										<select class="form-control input-sm" name="device_id" id="device_id">
	    									<option value="0"> -- </option>
	    								</select>
									</div>
								</div>
							</div>

							<div class="col-xs-6">
								<label>Country: </label>
								<div class="form-group">
									<img id="country_id_div_loading" class="loading" style="right:0px; left: 5px;" src="/202-img/loader-small.gif"/>
									<div id="country_id_div" style="top: -12px; font-size:10px;">
										<select class="form-control input-sm" name="country_id" id="country_id">
	    									<option value="0"> -- </option>
	    								</select>
									</div>
								</div>
							</div>
							</div>
						</div>

						<div class="col-xs-6">
							<label>Method of Promotion: </label>
							<div class="form-group">
								<img id="method_of_promotion_div_loading" class="loading" style="display: none;" src="/202-img/loader-small.gif"/>
								<div id="method_of_promotion_div" style="margin-left: 6px;"></div>
							</div>
						</div>

						<div class="col-xs-6" style="text-align:right">
							<div class="row">
								<div class="col-xs-6">
									<label>Browser: </label>
									<div class="form-group">
										<img id="browser_id_div_loading" class="loading" style="right:0px; left: 5px;" src="/202-img/loader-small.gif"/>
										<div id="browser_id_div" style="top: -12px; font-size:10px;">
											<select class="form-control input-sm" name="browser_id" id="browser_id">
		    									<option value="0"> -- </option>
		    								</select>
										</div>
									</div>
								</div>
								<div class="col-xs-6">
									<label>Region: </label>
									<div class="form-group">
										<img id="region_id_div_loading" class="loading" style="right:0px; left: 5px;" src="/202-img/loader-small.gif"/>
										<div id="region_id_div" style="top: -12px; font-size:10px;">
											<select class="form-control input-sm" name="region_id" id="region_id">
		    									<option value="0"> -- </option>
		    								</select>
										</div>
									</div>
								</div>
							</div>
						</div>

						<div class="col-xs-6">
							<label>Landing Page: </label>
							<div class="form-group">
								<img id="landing_page_div_loading" class="loading" style="display: none;" src="/202-img/loader-small.gif"/>
								<div id="landing_page_div" style="margin-left: 45px;"></div>
							</div>
						</div>

						<div class="col-xs-6" style="text-align:right">
							<div class="row">
								<div class="col-xs-6">
									<label>Platforms: </label>
									<div class="form-group">
										<img id="platform_id_div_loading" class="loading" style="right:0px; left: 5px;" src="/202-img/loader-small.gif"/>
										<div id="platform_id_div" style="top: -12px; font-size:10px;">
											<select class="form-control input-sm" name="platform_id" id="platform_id">
		    									<option value="0"> -- </option>
		    								</select>
										</div>
									</div>
								</div>
								<div class="col-xs-6">
									<label>ISP/Carrier: </label>
									<div class="form-group">
										<img id="isp_id_div_loading" class="loading" style="right:0px; left: 5px;" src="/202-img/loader-small.gif"/>
										<div id="isp_id_div" style="top: -12px; font-size:10px;">
											<select class="form-control input-sm" name="isp_id" id="isp_id">
		    									<option value="0"> -- </option>
		    								</select>
										</div>
									</div>
								</div>
							</div>
						</div>

					</div>
				</div>
			</div>

			<div class="form_seperator" style="margin:5px 0px; padding:1px;">
				<div class="col-xs-12"></div>
			</div>
		</div>

		<?php } ?>
		<?php if($show_adv_breakdown==true) { ?>
		<div class="row">
			<div class="col-xs-12" style="margin-top:5px; <?php if ($show_adv != false) { echo 'text-align:left;'; } ?> <?php if ($show_bottom == false) { echo 'display:none;'; } ?>">
				<label>Group By: </label>
				<div class="form-group">
					<label class="sr-only" for="user_pref_limit">Date</label>
					<select class="form-control input-sm" name="details[]">
						<?php foreach(ReportSummaryForm::getDetailArray() AS $detail_item) { ?>
							<option value="<?php echo $detail_item ?>" <?php echo $html['user_pref_group_1']==$detail_item ? 'selected="selected"' : ''; ?>><?php echo ReportBasicForm::translateDetailLevelById($detail_item); ?></option>
						<?php } ?>
					</select>
				</div>

				<label>Then Group By: </label>
				<div class="form-group">
					<label class="sr-only" for="user_pref_breakdown">Date</label>
					<select class="form-control input-sm" name="details[]">
						<option value="<?php echo ReportBasicForm::DETAIL_LEVEL_NONE; ?>" <?php echo $html['user_pref_group_1']==ReportBasicForm::DETAIL_LEVEL_NONE ? 'selected="selected"' : ''; ?>><?php echo ReportBasicForm::translateDetailLevelById(ReportBasicForm::DETAIL_LEVEL_NONE); ?></option>
						<?php foreach(ReportSummaryForm::getDetailArray() AS $detail_item) { ?>
							<option value="<?php echo $detail_item ?>" <?php echo $html['user_pref_group_2']==$detail_item ? 'selected="selected"' : ''; ?>><?php echo ReportBasicForm::translateDetailLevelById($detail_item); ?></option>
						<?php } ?>
					</select>
				</div>

				<label>Then Group By: </label>
				<div class="form-group">
					<label class="sr-only" for="user_pref_chart">Date</label>
					<select class="form-control input-sm" name="details[]">
						<option value="<?php echo ReportBasicForm::DETAIL_LEVEL_NONE; ?>" <?php echo $html['user_pref_group_1']==ReportBasicForm::DETAIL_LEVEL_NONE ? 'selected="selected"' : ''; ?>><?php echo ReportBasicForm::translateDetailLevelById(ReportBasicForm::DETAIL_LEVEL_NONE); ?></option>
						<?php foreach(ReportSummaryForm::getDetailArray() AS $detail_item) { ?>
							<option value="<?php echo $detail_item ?>" <?php echo $html['user_pref_group_3']==$detail_item ? 'selected="selected"' : ''; ?>><?php echo ReportBasicForm::translateDetailLevelById($detail_item); ?></option>
						<?php } ?>
					</select>
				</div>

				<label>Then Group By: </label>
				<div class="form-group">
					<label class="sr-only" for="user_pref_show">Date</label>
					<select class="form-control input-sm" name="details[]">
						<option value="<?php echo ReportBasicForm::DETAIL_LEVEL_NONE; ?>" <?php echo $html['user_pref_group_1']==ReportBasicForm::DETAIL_LEVEL_NONE ? 'selected="selected"' : ''; ?>><?php echo ReportBasicForm::translateDetailLevelById(ReportBasicForm::DETAIL_LEVEL_NONE); ?></option>
						<?php foreach(ReportBasicForm::getDetailArray() AS $detail_item) { ?>
							<option value="<?php echo $detail_item ?>" <?php echo $html['user_pref_group_4']==$detail_item ? 'selected="selected"' : ''; ?>><?php echo ReportBasicForm::translateDetailLevelById($detail_item); ?></option>
						<?php } ?>
					</select>
				</div>
				
			</div>
		</div>

		<div class="form_seperator" style="margin:5px 0px; padding:1px;">
			<div class="col-xs-12"></div>
		</div>

		<?php } ?>
		<div class="row">
			<div class="col-xs-12" style="margin-top:5px; <?php if ($show_adv != false) { echo 'text-align:left;'; } ?> <?php if ($show_bottom == false) { echo 'display:none;'; } ?>">
				<label>Display: </label>
				<div class="form-group">
					<label class="sr-only" for="user_pref_limit">Date</label>
					<select class="form-control input-sm" name="user_pref_limit" id="user_pref_limit" style="width: auto; <?php if ($show_limit == false) { echo 'display:none;'; } ?>">
					    <option <?php if ($user_row['user_pref_limit'] == '10') { echo 'SELECTED'; } ?> value="10">10</option>
						<option <?php if ($user_row['user_pref_limit'] == '25') { echo 'SELECTED'; } ?> value="25">25</option>
						<option <?php if ($user_row['user_pref_limit'] == '50') { echo 'SELECTED'; } ?> value="50">50</option>
						<option <?php if ($user_row['user_pref_limit'] == '75') { echo 'SELECTED'; } ?> value="75">75</option> 
						<option <?php if ($user_row['user_pref_limit'] == '100') { echo 'SELECTED'; } ?> value="100">100</option>
						<option <?php if ($user_row['user_pref_limit'] == '150') { echo 'SELECTED'; } ?> value="150">150</option>
						<option <?php if ($user_row['user_pref_limit'] == '200') { echo 'SELECTED'; } ?> value="200">200</option>
					</select>
				</div>

				<div class="form-group">
					<label class="sr-only" for="user_pref_breakdown">Date</label>
					<select class="form-control input-sm" name="user_pref_breakdown" id="user_pref_breakdown" <?php if ($show_breakdown == false) { echo 'style="display:none;"'; } ?>>
						<option <?php if ($user_row['user_pref_breakdown'] == 'hour') { echo 'SELECTED'; } ?> value="hour">By Hour</option>
						<option <?php if ($user_row['user_pref_breakdown'] == 'day') { echo 'SELECTED'; } ?> value="day">By Day</option>
						<option <?php if ($user_row['user_pref_breakdown'] == 'month') { echo 'SELECTED'; } ?> value="month">By Month</option>  
						<option <?php if ($user_row['user_pref_breakdown'] == 'year') { echo 'SELECTED'; } ?> value="year">By Year</option>  
					</select>
				</div>

				<div class="form-group">
					<label class="sr-only" for="user_pref_chart">Date</label>
					<select class="form-control input-sm" name="user_pref_chart" id="user_pref_chart" <?php if ($show_breakdown == false) { echo 'style="display:none;"'; } ?>>
						<option <?php if ($user_row['user_pref_chart'] == 'profitloss') { echo 'SELECTED'; } ?> value="profitloss">Profit Loss Bar Graph</option>
						<option <?php if ($user_row['user_pref_chart'] == 'clicks') { echo 'SELECTED'; } ?> value="clicks">Clicks Line Graph</option>
						<option <?php if ($user_row['user_pref_chart'] == 'leads') { echo 'SELECTED'; } ?> value="leads">Leads Line Graph</option>
						<option <?php if ($user_row['user_pref_chart'] == 'su_ratio') { echo 'SELECTED'; } ?> value="su_ratio">S/U Ratio Line Graph</option>
						<option <?php if ($user_row['user_pref_chart'] == 'payout') { echo 'SELECTED'; } ?> value="payout">Payout Line Graph</option>
						<option <?php if ($user_row['user_pref_chart'] == 'epc') { echo 'SELECTED'; } ?> value="epc">EPC Line Graph</option>
						<option <?php if ($user_row['user_pref_chart'] == 'cpc') { echo 'SELECTED'; } ?> value="cpc">Avg CPC Line Graph</option>
						<option <?php if ($user_row['user_pref_chart'] == 'income') { echo 'SELECTED'; } ?> value="income">Income Line Graph</option>
						<option <?php if ($user_row['user_pref_chart'] == 'cost') { echo 'SELECTED'; } ?> value="cost">Cost Line Graph</option>
						<option <?php if ($user_row['user_pref_chart'] == 'net') { echo 'SELECTED'; } ?> value="net">Net Line Graph</option>
						<option <?php if ($user_row['user_pref_chart'] == 'roi') { echo 'SELECTED'; } ?> value="roi">ROI Line Graph</option> 
					</select>
				</div>

				<div class="form-group">
					<label class="sr-only" for="user_pref_show">Date</label>
					<select style="width: 155px;" class="form-control input-sm" name="user_pref_show" id="user_pref_show" <?php if ($show_type == false) { echo 'style="display:none;"'; } ?>>
						<option <?php if ($user_row['user_pref_show'] == 'all') { echo 'SELECTED'; } ?> value="all">Show All Clicks</option>
						<option <?php if ($user_row['user_pref_show'] == 'real') { echo 'SELECTED'; } ?> value="real">Show Real Clicks</option>
						<option <?php if ($user_row['user_pref_show'] == 'filtered') { echo 'SELECTED'; } ?> value="filtered">Show Filtered Out Clicks</option>
						<option <?php if ($user_row['user_pref_show'] == 'filtered_bot') { echo 'SELECTED'; } ?> value="filtered_bot">Show Filtered Out Bot Clicks</option>
						<option <?php if ($user_row['user_pref_show'] == 'leads') { echo 'SELECTED'; } ?> value="leads">Show Converted Clicks</option>
					</select>
				</div>

				<div class="form-group">
					<label class="sr-only" for="user_cpc_or_cpv">Date</label>
					<select class="form-control input-sm" name="user_cpc_or_cpv" id="user_cpc_or_cpv" <?php if ($show_cpc_or_cpv == false) { echo 'style="display:none;"'; } ?>>
						<option <?php if ($user_row['user_cpc_or_cpv'] == 'cpc') { echo 'SELECTED'; } ?> value="cpc">CPC Costs</option>
						<option <?php if ($user_row['user_cpc_or_cpv'] == 'cpv') { echo 'SELECTED'; } ?> value="cpv">CPV Costs</option>
					</select>
				</div>
				<button id="s-search" style="width: 130px; <?php if ($show_adv != false) { echo 'float:right;'; } ?>" type="submit" class="btn btn-xs btn-info" onclick="set_user_prefs('<?php echo $html['page']; ?>');">Set Preferences</button>
				<button id="s-toogleAdv" style="width: 130px; margin-right: 5px; float:right; <?php if ($show_adv == false) { echo 'display:none;'; } ?>" type="submit" class="btn btn-xs btn-default">More Options</button>
			</div>
		</div>

		</form>
	</div>	   
</div>
</div>

<div class="row">
	<div class="col-xs-12">
		<div id="m-content">
			<div class="loading-stats"><span class="infotext">Loading stats...</span> <img src="/202-img/loader-small.gif"></div>
		</div>  
	</div>
</div>                              
   
   <script type="text/javascript">
		
		/* TIME SETTING FUNCTION */ 
		function set_user_pref_time_predefined() {

			var element = $('#user_pref_time_predefined');

			if (element.val() == 'today') {
				<?php $time['from'] = mktime(0,0,0,date('m',time()),date('d',time()),date('Y',time()));
					  $time['to'] = mktime(23,59,59,date('m',time()),date('d',time()),date('Y',time())); ?>

				$('#from').val('<?php echo date('m/d/y - G:i',$time['from']); ?>');
				$('#to').val('<?php echo date('m/d/y - G:i',$time['to']); ?>');
			}

			if (element.val() == 'yesterday') {
				<?php $time['from'] = mktime(0,0,0,date('m',time()-86400),date('d',time()-86400),date('Y',time()-86400));
					  $time['to'] = mktime(23,59,59,date('m',time()-86400),date('d',time()-86400),date('Y',time()-86400)); ?>

				$('#from').val('<?php echo date('m/d/y - G:i',$time['from']); ?>');
				$('#to').val('<?php echo date('m/d/y - G:i',$time['to']); ?>');
			}

			if (element.val() == 'last7') {
				<?php $time['from'] = mktime(0,0,0,date('m',time()-86400*7),date('d',time()-86400*7),date('Y',time()-86400*7));
					  $time['to'] = mktime(23,59,59,date('m',time()),date('d',time()),date('Y',time())); ?>

				$('#from').val('<?php echo date('m/d/y - G:i',$time['from']); ?>');
				$('#to').val('<?php echo date('m/d/y - G:i',$time['to']); ?>');
			}

			if (element.val() == 'last14') {
				<?php $time['from'] = mktime(0,0,0,date('m',time()-86400*14),date('d',time()-86400*14),date('Y',time()-86400*14));
					  $time['to'] = mktime(23,59,59,date('m',time()),date('d',time()),date('Y',time())); ?>

				$('#from').val('<?php echo date('m/d/y - G:i',$time['from']); ?>');
				$('#to').val('<?php echo date('m/d/y - G:i',$time['to']); ?>');
			}

			if (element.val() == 'last30') {
				<?php $time['from'] = mktime(0,0,0,date('m',time()-86400*30),date('d',time()-86400*30),date('Y',time()-86400*30));
					  $time['to'] = mktime(23,59,59,date('m',time()),date('d',time()),date('Y',time())); ?>

				$('#from').val('<?php echo date('m/d/y - G:i',$time['from']); ?>');
				$('#to').val('<?php echo date('m/d/y - G:i',$time['to']); ?>');
			}

			if (element.val() == 'thismonth') {
				<?php $time['from'] = mktime(0,0,0,date('m',time()),1,date('Y',time()));
					  $time['to'] = mktime(23,59,59,date('m',time()),date('d',time()),date('Y',time())); ?>

				$('#from').val('<?php echo date('m/d/y - G:i',$time['from']); ?>');
				$('#to').val('<?php echo date('m/d/y - G:i',$time['to']); ?>');
			}

			if (element.val() == 'lastmonth') {
				<?php $time['from'] = mktime(0,0,0,date('m',time()-2629743),1,date('Y',time()-2629743));
					  $time['to'] = mktime(23,59,59,date('m',time()-2629743),getLastDayOfMonth(date('m',time()-2629743), date('Y',time()-2629743)),date('Y',time()-2629743)); ?>

				$('#from').val('<?php echo date('m/d/y - G:i',$time['from']); ?>');
				$('#to').val('<?php echo date('m/d/y - G:i',$time['to']); ?>');
			}

			if (element.val() == 'thisyear') {
				<?php $time['from'] = mktime(0,0,0,1,1,date('Y',time()));
					  $time['to'] = mktime(23,59,59,date('m',time()),date('d',time()),date('Y',time())); ?>

				$('#from').val('<?php echo date('m/d/y - G:i',$time['from']); ?>');
				$('#to').val('<?php echo date('m/d/y - G:i',$time['to']); ?>');
			}

			if (element.val() == 'lastyear') {
				<?php $time['from'] = mktime(0,0,0,1,1,date('Y',time()-31556926));
					  $time['to'] = mktime(0,0,0,12,getLastDayOfMonth(date('m',time()-31556926), date('Y',time()-31556926)),date('Y',time()-31556926)); ?>

				$('#from').val('<?php echo date('m/d/y - G:i',$time['from']); ?>');
				$('#to').val('<?php echo date('m/d/y - G:i',$time['to']); ?>');
			}
			
			if (element.val() == 'alltime') {
				<?php  
				//for the time from, do something special select the exact date this user was registered and use that :)
				$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
				$user_sql = "SELECT user_time_register FROM 202_users WHERE user_id='".$mysql['user_id']."'";
				$user_result = $db->query($user_sql) or record_mysql_error($user_sql);
				$user_row = $user_result->fetch_assoc();
				$time['from'] = $user_row['user_time_register'];
				
				$time['from'] = mktime(0,0,0,date('m',$time['from']),date('d',$time['from']),date('Y',$time['from']));  
				$time['to'] = mktime(23,59,59,date('m',time()),date('d',time()),date('Y',time())); ?>

				$('#from').val('<?php echo date('m/d/y - G:i',$time['from']); ?>');
				$('#to').val('<?php echo date('m/d/y - G:i',$time['to']); ?>');
			}
		}
		
		/* SHOW FIELDS */        

		load_ppc_network_id('<?php echo $html['user_pref_ppc_network_id']; ?>');
		<?php if ($html['user_pref_ppc_account_id'] != '') { ?>
			load_ppc_account_id('<?php echo $html['user_pref_ppc_network_id']; ?>','<?php echo $html['user_pref_ppc_account_id']; ?>');      
		<?php } ?>
		
		load_aff_network_id('<?php echo $html['user_pref_aff_network_id']; ?>');
		<?php if ($html['user_pref_aff_campaign_id'] != '') { ?>
			load_aff_campaign_id('<?php echo $html['user_pref_aff_network_id']; ?>','<?php echo $html['user_pref_aff_campaign_id']; ?>');
		<?php } ?>
		
		<?php if ($html['user_pref_text_ad_id'] != '') { ?>
			load_text_ad_id('<?php echo $html['user_pref_aff_campaign_id']; ?>','<?php echo $html['user_pref_text_ad_id']; ?>');
			load_ad_preview('<?php echo $html['user_pref_text_ad_id']; ?>'); 
		<?php } ?>
		
		load_method_of_promotion('<?php echo $html['user_pref_method_of_promotion']; ?>');
		
		<?php if ($html['user_pref_landing_page_id'] != '') { ?>
			load_landing_page('<?php echo $html['user_pref_aff_campaign_id']; ?>', '<?php echo $html['user_pref_landing_page_id']; ?>', '<?php echo $html['user_pref_method_of_promotion']; ?>');
		<?php } ?>

		<?php if($show_adv != false) { ?>
			load_country_id('<?php echo $html['user_pref_country_id']; ?>');
			load_region_id('<?php echo $html['user_pref_region_id']; ?>');
			load_isp_id('<?php echo $html['user_pref_isp_id']; ?>');
			load_device_id('<?php echo $html['user_pref_device_id']; ?>');
			load_browser_id('<?php echo $html['user_pref_browser_id']; ?>');
			load_platform_id('<?php echo $html['user_pref_platform_id']; ?>');
		<?php } ?>
		

   </script> 
<?php }


function grab_timeframe() {
	
	AUTH::set_timezone($_SESSION['user_timezone']);

	$database = DB::getInstance();
	$db = $database->getConnection();

	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
	$user_sql = "SELECT user_pref_time_predefined, user_pref_time_from, user_pref_time_to FROM 202_users_pref WHERE user_id='".$mysql['user_id']."'";
	$user_result = _mysqli_query($user_sql) ; ; //($user_sql); 
	$user_row = $user_result->fetch_assoc();
	
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
		$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
		$user2_sql = "SELECT user_time_register FROM 202_users WHERE user_id='".$mysql['user_id']."'";
		$user2_result = $db->query($user2_sql) or record_mysql_error($user2_sql);
		$user2_row = $user2_result->fetch_assoc();
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
	$database = DB::getInstance();
	$db = $database->getConnection();
	$tracking_domain_sql = "
		SELECT
			`user_tracking_domain`
		FROM
			`202_users_pref`
		WHERE
			`user_id`='".$db->real_escape_string($_SESSION['user_id'])."'
	";
	$tracking_domain_result = _mysqli_query($tracking_domain_sql) ; ; //($user_sql);
	$tracking_domain_row = $tracking_domain_result->fetch_assoc();
	$tracking_domain = $_SERVER['SERVER_NAME'];
	if(strlen($tracking_domain_row['user_tracking_domain'])>0) {
		$tracking_domain = $tracking_domain_row['user_tracking_domain'];
	}
	return $tracking_domain;
}

//the above, if true, are options to turn on specific filtering techniques.
function query($command, $db_table, $pref_time, $pref_adv, $pref_show, $pref_order, $offset, $pref_limit, $count) {
	
	$database = DB::getInstance();
	$db = $database->getConnection();

	//grab user preferences  
	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);    
	$user_sql = "SELECT * FROM 202_users_pref WHERE user_id='".$mysql['user_id']."'";
	$user_result = _mysqli_query($user_sql) ; ; //($user_sql);
	$user_row = $user_result->fetch_assoc();
	
	
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

		//if there is a country lookup, and we have not joined the 202 country table. do so now
		if ($user_row['user_pref_country_id'] and !preg_match('/202_locations_country/', $command)) { 
			$command .= " LEFT JOIN 202_locations_country AS 2cy ON (2ca.country_id = 2cy.country_id) ";
		}

		//if there is a region lookup, and we have not joined the 202 region table. do so now
		if ($user_row['user_pref_region_id'] and !preg_match('/202_locations_region/', $command)) { 
			$command .= " LEFT JOIN 202_locations_region AS 2rg ON (2ca.region_id = 2rg.region_id) ";
		}

		//if there is a isp lookup, and we have not joined the 202 isp table. do so now
		if ($user_row['user_pref_isp_id'] and !preg_match('/202_locations_isp/', $command)) { 
			$command .= " LEFT JOIN 202_locations_isp AS 2is ON (2ca.isp_id = 2is.isp_id) ";
		}

		//if there is a device lookup, and we have not joined the 202 device table. do so now
		if ($user_row['user_pref_device_id'] and !preg_match('/202_device_models/', $command)) { 
			$command .= " LEFT JOIN 202_device_models AS 2d ON (2ca.device_id = 2d.device_id) ";
		}

		//if there is a browser lookup, and we have not joined the 202 browser table. do so now
		if ($user_row['user_pref_browser_id'] and !preg_match('/202_browsers/', $command)) { 
			$command .= " LEFT JOIN 202_browsers AS 2b ON (2ca.browser_id = 2b.browser_id) ";
		}

		//if there is a platform lookup, and we have not joined the 202 platform table. do so now
		if ($user_row['user_pref_platform_id'] and !preg_match('/202_platforms/', $command)) { 
			$command .= " LEFT JOIN 202_platforms AS 2p ON (2ca.platform_id = 2p.platform_id) ";
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
		} elseif ($user_row['user_pref_show'] == 'filtered_bot') {
			$click_sql .=   " AND click_bot='1'"; 
		}   
	}
		
	//set advanced preferences                    
	if ($pref_adv == true) {
		if ($user_row['user_pref_ppc_network_id'] and !($user_row['user_pref_ppc_account_id'])) { 
			$mysql['user_pref_ppc_network_id'] = $db->real_escape_string($user_row['user_pref_ppc_network_id']);
			$click_sql .=   "  AND      2pn.ppc_network_id='".$mysql['user_pref_ppc_network_id']."'";
		}
		

		if ($user_row['user_pref_ppc_account_id']) { 
			$mysql['user_pref_ppc_account_id'] = $db->real_escape_string($user_row['user_pref_ppc_account_id']);
			$click_sql .=   " AND      2c.ppc_account_id='".$mysql['user_pref_ppc_account_id']."'";
		}
		 
	   if ($user_row['user_pref_aff_network_id'] and !$user_row['user_pref_aff_campaign_id']) { 

			$mysql['user_pref_aff_network_id'] = $db->real_escape_string($user_row['user_pref_aff_network_id']);
			$click_sql .=   "  AND      2an.aff_network_id='".$mysql['user_pref_aff_network_id']."'";
		}
		
		if ($user_row['user_pref_aff_campaign_id']) { 
			$mysql['user_pref_aff_campaign_id'] = $db->real_escape_string($user_row['user_pref_aff_campaign_id']);
			$click_sql .=   " AND      2c.aff_campaign_id='".$mysql['user_pref_aff_campaign_id']."'";
		}
		if ($user_row['user_pref_text_ad_id']) { 
			$mysql['user_pref_text_ad_id'] = $db->real_escape_string($user_row['user_pref_text_ad_id']);
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
			$mysql['user_landing_page_id'] = $db->real_escape_string($user_row['user_pref_landing_page_id']);
			$click_sql .=   " AND      2c.landing_page_id='".$mysql['user_landing_page_id']."'";
		}   
		
		if ($user_row['user_pref_country_id']) { 
			$mysql['user_pref_country_id'] = $db->real_escape_string($user_row['user_pref_country_id']);
			$click_sql .=   " AND      2ca.country_id=".$mysql['user_pref_country_id'];
		}

		if ($user_row['user_pref_region_id']) { 
			$mysql['user_pref_region_id'] = $db->real_escape_string($user_row['user_pref_region_id']);
			$click_sql .=   " AND      2ca.region_id=".$mysql['user_pref_region_id'];
		}

		if ($user_row['user_pref_isp_id']) { 
			$mysql['user_pref_isp_id'] = $db->real_escape_string($user_row['user_pref_isp_id']);
			$click_sql .=   " AND      2is.isp_id=".$mysql['user_pref_isp_id'];
		}
		
		if ($user_row['user_pref_referer']) { 
			$mysql['user_pref_referer'] = $db->real_escape_string($user_row['user_pref_referer']);
			$click_sql .=   " AND 2sd.site_domain_host LIKE CONVERT( _utf8 '%".$mysql['user_pref_referer']."%' USING latin1 )
							COLLATE latin1_swedish_ci ";    
		}
		
		if ($user_row['user_pref_keyword']) { 
			$mysql['user_pref_keyword'] = $db->real_escape_string($user_row['user_pref_keyword']);
			$click_sql .=   " AND 2k.keyword LIKE CONVERT( _utf8 '%".$mysql['user_pref_keyword']."%' USING latin1 )
							COLLATE latin1_swedish_ci ";    
		}
		
		if ($user_row['user_pref_ip']) { 
			$mysql['user_pref_ip'] = $db->real_escape_string($user_row['user_pref_ip']);
			$click_sql .=   " AND 2i.ip_address LIKE CONVERT( _utf8 '%".$mysql['user_pref_ip']."%' USING latin1 )
							COLLATE latin1_swedish_ci ";    
		}

		if ($user_row['user_pref_device_id']) { 
			$mysql['user_pref_device_id'] = $db->real_escape_string($user_row['user_pref_device_id']);
			$click_sql .=   " AND      2d.device_type=".$mysql['user_pref_device_id'];    
		}

		if ($user_row['user_pref_browser_id']) { 
			$mysql['user_pref_browser_id'] = $db->real_escape_string($user_row['user_pref_browser_id']);
			$click_sql .=   " AND      2b.browser_id=".$mysql['user_pref_browser_id'];    
		}


		if ($user_row['user_pref_platform_id']) { 
			$mysql['user_pref_platform_id'] = $db->real_escape_string($user_row['user_pref_platform_id']);
			$click_sql .=   " AND      2p.platform_id=".$mysql['user_pref_platform_id'];    
		}	
	}
	
	//set time preferences
	if ($pref_time == true) {    
		$time = grab_timeframe();  
		
		$mysql['from'] = $db->real_escape_string($time['from']);
		$mysql['to'] = $db->real_escape_string($time['to']);
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
		$click_result = _mysqli_query($click_sql) ; //($click_sql);
		$rows = $click_result->num_rows;
		
		
		//only if there is a limit set, run this code 
		if ($pref_limit != false) {
		   
			//rows is the total count of rows in this query.
			$query['rows'] = $rows; 
			$query['offset'] = $offset;
			
			if ((is_numeric($offset) and ($pref_limit == true)) or ($pref_limit == true)) {
				$click_sql .= " LIMIT ";   
			}
		   
			if (is_numeric($offset) and ($pref_limit == true)) {
				$mysql['offset'] = $db->real_escape_string($offset*$user_row['user_pref_limit']);
				$click_sql .= $mysql['offset'].",";
				
				//declare starting row number
				$query['from'] = ($query['offset'] * $user_row['user_pref_limit']) + 1;
			} else {
				$query['from'] = 1;      
			}
			
			if ($pref_limit == true) {
				
				if (is_numeric($pref_limit)) {
					$mysql['user_pref_limit'] = $db->real_escape_string($pref_limit);                    
				} else {
					$mysql['user_pref_limit'] = $db->real_escape_string($user_row['user_pref_limit']);
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
	return $query;
	
}

function display_suggestion($suggestion_row) {

	$database = DB::getInstance();
	$db = $database->getConnection();

	//lets determine, if this user has already voted on this:
	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
	$mysql['suggestion_id'] = $db->real_escape_string($suggestion_row['suggestion_id']);
	$votes_sql = "SELECT * FROM suggestion_votes WHERE user_id='".$mysql['user_id']."' AND suggestion_id='".$mysql['suggestion_id']."'";
	
	$votes_result = _mysqli_query($votes_sql) ; //($votes_sql);
	$already_voted = '';
	if ($votes_result->num_rows > 0) { $already_voted = '1'; } 
	
	if ($suggestion_row['votes'] > 0) { $suggestion_row['votes'] = '+' . $suggestion_row['votes']; }
	$mysql['user_id'] = $db->real_escape_string($suggestion_row['user_id']);
	$user_sql = "SELECT user_username FROM users WHERE user_id='".$mysql['user_id']."'";
	$user_result = _mysqli_query($user_sql) ; ; //($user_sql);
	$user_row = $user_result->fetch_assoc();
	
	$html['suggestion_id'] = htmlentities($suggestion_row['suggestion_id'], ENT_QUOTES, 'UTF-8');
	$html['user_username'] = htmlentities($user_row['user_username'], ENT_QUOTES, 'UTF-8');
	$html['suggestion_time'] = date('M d, Y', $suggestion_row['suggestion_time']);
	$html['suggestion_votes'] = htmlentities($suggestion_row['suggestion_votes'], ENT_QUOTES, 'UTF-8');
	$html['suggestion_text'] = htmlentities($suggestion_row['suggestion_text'], ENT_QUOTES, 'UTF-8'); ?>
	
	<li id="c-comment<?php echo $html['suggestion_id']; ?>">
		<table class="c-table" cellspacing="0" cellpadding="0">
			<tr class="c-head">
				<td class="c-info"><strong><?php echo $html['user_username']; ?></strong> <span class="c-time"><?php echo $html['suggestion_time']; ?></span></td>
				<td class="c-votes" id="c-votes<?php echo $html['suggestion_id']; ?>"><?php echo $html['suggestion_votes']; ?> rating</td>
				<td class="c-vote-no">
					<img id="c-vote-no<?php echo $html['suggestion_id']; ?>" src="/202-img/icons/18x18/vote-no<?php if ($already_voted == '1') { echo '-off'; } ?>.png" alt="Vote No" title="Vote No" <?php if ($already_voted != '1') { ?> onclick="vote('<?php echo $html['suggestion_id']; ?>','','1');" <?php } ?>/>
				</td> 
				<td class="c-vote-yes">
					<img id="c-vote-yes<?php echo $html['suggestion_id']; ?>" src="/202-img/icons/18x18/vote-yes<?php if ($already_voted == '1') { echo '-off'; } ?>.png" alt="Vote Yes" title="Vote Yes" <?php if ($already_voted != '1') { ?> onclick="vote('<?php echo $html['suggestion_id']; ?>','1','');" <?php } ?>/>
				</td>
							
				<?php if (AUTH::admin_logged_in() == true) { ?>
					<td class="c-delete">
						<img id="c-delete<?php echo $html['suggestion_id']; ?>" src="/202-img/icons/16x16/cancel.png" title="Delete" onclick="deleteComment('<?php echo $html['suggestion_id']; ?>');"/>
					</td> 
					<td class="c-complete">
						<img id="c-complete<?php echo $html['suggestion_id']; ?>" src="/202-img/icons/16x16/accept.png" title="Completed" onclick="completeComment('<?php echo $html['suggestion_id']; ?>');"/>
					</td>
				<?php } ?>
				
			</tr>
		</table>
		<div class="c-body">
			<?php echo $html['suggestion_text']; ?> 
			<div style="text-align: right;"><?php  //show on show comments, if there are comments
				$comments = 0;
				$comments = numberofcomments($suggestion_row['suggestion_id']);
				if ($comments['from'] != '') { ?>
					<a class="onclick_color c-onclick"  id="c-showComments<?php echo $html['suggestion_id']; ?>" onclick="showComments('<?php echo $html['suggestion_id']; ?>');">[Show Comments <?php echo $comments['from'] . ' of ' .$comments['to']; ?>]</a>
					<a class="onclick_color c-onclick"  id="c-hideComments<?php echo $html['suggestion_id']; ?>" onclick="hideComments('<?php echo $html['suggestion_id']; ?>');" style="display: none;">[Hide Comments]</a>
				<?php } ?>
				<a class="onclick_color c-onclick" id="c-showReply<?php echo $html['suggestion_id']; ?>" onclick="showCreply('<?php echo $html['suggestion_id']; ?>');">[Reply]</a>
				<a class="onclick_color c-onclick" id="c-hideReply<?php echo $html['suggestion_id']; ?>" onclick="hideCreply('<?php echo $html['suggestion_id']; ?>');" style="display: none;">[Hide Reply]</a> 
			</div>
		</div>
		<div id="c-row2<?php echo $html['suggestion_id']; ?>" class="c-row2">
			<div id="c-post<?php echo $html['suggestion_id']; ?>" style="display: none;">
				<div id="c-options<?php echo $html['suggestion_id']; ?>" class="c-highlight">
					[Reply]
				</div>
				<div id="c-reply<?php echo $html['suggestion_id']; ?>" class="c-reply">
					<form id="c-reply-form<?php echo $html['suggestion_id']; ?>" onsubmit="return suggestionReply('<?php echo $html['suggestion_id']; ?>');" method="post">
						<input type="hidden" name="suggestion_reply_to_id" value="<?php echo $html['suggestion_id']; ?>"/>
						<textarea name="c-suggestion" id="c-suggestion<?php echo $html['suggestion_id']; ?>" class="c-reply-textarea"></textarea>
						<div id="c-error<?php echo $html['suggestion_id']; ?>" class="error" style="display: none;">The submission you sent us was empty!</div>
						<input type="submit" value="Submit Comment" class="c-reply-submit"/>    
					</form>
				</div>
			</div>
			
			<div id="c-replies<?php echo $html['suggestion_id']; ?>" style="display: none;">
				<?php if ($comments > 0) { ?>
					<div class="comment2">
						<ul> 
							<li> <?
								$mysql['suggestion_id'] = $db->real_escape_string($suggestion_row['suggestion_id']);
								$suggestion2_sql = "SELECT * FROM suggestions WHERE suggestion_reply_to_id='".$mysql['suggestion_id']."'";
								$suggestion2_result = _mysqli_query($suggestion2_sql) ; //($suggestion2_sql);
								
								while ($suggestion2_row = $suggestion2_result->fetch_array()) {
									display_suggestion($suggestion2_row);       
								} ?>
							</li>
						</ul>
					</div>
				<?php } ?>
			</div>
		</div>
	</li> <?php 
	
}

function numberofcomments($suggestion_id) {
	
	$database = DB::getInstance();
	$db = $database->getConnection();

	$mysql['suggestion_reply_to_id'] = $db->real_escape_string($suggestion_id);
	$suggestion_sql = "SELECT * FROM suggestions WHERE suggestion_reply_to_id='".$mysql['suggestion_reply_to_id']."' ORDER BY suggestion_votes DESC";
	$suggestion_result = _mysqli_query($suggestion_sql) ; //($suggestion_sql);
	$comments['from'] = $suggestion_result->num_rows;
	if ($comments['from'] > 0) { 
		$comments['to'] = $comments['from'];
		while ($suggestion_row = $suggestion_result->fetch_array()) {
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
	 	
	 	$database = DB::getInstance();
		$db = $database->getConnection();

		$mysql['ip_id'] = $db->real_escape_string($ip_id);      
		$mysql['user_id'] = $db->real_escape_string($user_id);    
		
		$count_sql = "SELECT    *
					  FROM      202_users 
					  WHERE     user_id='".$mysql['user_id']."' 
					  AND       user_last_login_ip_id='".$mysql['ip_id']."'";
		$count_result = _mysqli_query($count_sql) ; //($count_sql);
	
		//if the click_id's ip address, is the same ip adddress of the click_id's owner's last logged in ip, filter this.  This means if the ip hit on the page was the same as the owner of the click affiliate program, we want to filter out the clicks by the owner when he/she  is trying to test 
		if ($count_result->num_rows > 0) { 
			
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

		$database = DB::getInstance();
		$db = $database->getConnection();

		$mysql['user_id'] = $db->real_escape_string($user_id);
		$mysql['ip_id'] = $db->real_escape_string($ip_id);
		
		$check_sql = "SELECT * FROM 202_last_ips WHERE user_id='".$mysql['user_id']."' AND ip_id='".$mysql['ip_id']."'";
		$check_result = _mysqli_query($check_sql) ; //($check_sql);
		$count = $check_result->num_rows;
		 
		if ($count > 0) {
			//if this ip has been seen within the last 24 hours, filter it out. 
			return true;
		} else {
			
			//else if this ip has not been recorded, record it now
			$mysql['time'] = time();
			$insert_sql = "INSERT INTO 202_last_ips SET user_id='".$mysql['user_id']."', ip_id='".$mysql['ip_id']."', time='".$mysql['time']."'";
			$insert_result = _mysqli_query($insert_sql) ; //($insert_sql);
			return false;	
		}
		
	}

}

class PLATFORMS {
		
	    function get_device_info(){

	    	global $memcacheWorking, $memcache;
	    	$detect = new Mobile_Detect;
	    	$ua = $detect->getUserAgent();

			//If Cache working
			if ($memcacheWorking) {
				$device_info = $memcache->get(md5("user-agent" . $ua . systemHash()));

				if (!$device_info) {

					$parse_info = PLATFORMS::parseUserAgentInfo($detect);
					$memcache->set( md5("user-agent" . $ua . systemHash()), $parse_info, false);
					return $parse_info;

				} else {
					return $device_info;
				}

			}

			//If Cache is not working
			else {

				return PLATFORMS::parseUserAgentInfo($detect);
			}

	    }

	    function parseUserAgentInfo($detect){

	    		$database = DB::getInstance();
				$db = $database->getConnection();

	    		$parser = Parser::create();
	    		$result = $parser->parse($detect->getUserAgent());
				//If is not mobile or tablet
				if( !$detect->isMobile() && !$detect->isTablet() ){

					switch ($result->device->family) {
						//Is Bot
						case 'Bot':
							$type = "4";
							$result->device->family = "Bot";
							break;
						//Is Desktop
						case 'Other':
							$type = "1";
							$result->device->family = "Desktop";
							break;
					}
				} else {
					//If tablet
					if ($detect->isTablet()) {
						$type = "3";
					//If mobile	
					} else {
						$type = "2";
					}
				}

				//Select from DB and return ID's
				$mysql['browser'] = $db->real_escape_string($result->ua->family);
				$mysql['platform'] = $db->real_escape_string($result->os->family);
				$mysql['device'] = $db->real_escape_string($result->device->family);
				$mysql['device_type'] = $db->real_escape_string($type);

				//Get browser ID
				$browser_sql = "SELECT browser_id FROM 202_browsers WHERE browser_name='".$mysql['browser']."'";
				$browser_result = _mysqli_query($browser_sql);
				$browser_row = $browser_result->fetch_assoc();
					if ($browser_row) {
						 $browser_id = $browser_row['browser_id'];
					} else {
						$browser_sql = "INSERT INTO 202_browsers SET browser_name='".$mysql['browser']."'";
						$browser_result = _mysqli_query($browser_sql) ;
						$browser_id = $db->insert_id;
					}

				//Get platform ID
				$platform_sql = "SELECT platform_id FROM 202_platforms WHERE platform_name='".$mysql['platform']."'";
				$platform_result = _mysqli_query($platform_sql);
				$platform_row = $platform_result->fetch_assoc();
					if ($platform_row) {
						 $platform_id = $platform_row['platform_id'];
					} else {
						$platform_sql = "INSERT INTO 202_platforms SET platform_name='".$mysql['platform']."'";
						$platform_result = _mysqli_query($platform_sql) ;
						$platform_id = $db->insert_id;
					}

				//Get device model ID
				$device_sql = "SELECT device_id, device_type FROM 202_device_models WHERE device_name='".$mysql['device']."'";
				$device_result = _mysqli_query($device_sql);
				$device_row = $device_result->fetch_assoc();
					if ($device_row) {
						 $device_id = $device_row['device_id'];
						 $device_type = $device_row['device_type'];
					} else {
						$device_sql = "INSERT INTO 202_device_models SET device_name='".$mysql['device']."', device_type='".$mysql['device_type']."'";
						$device_result = _mysqli_query($device_sql) ;
						$device_id = $db->insert_id;
						$device_type = $type;
					}

				$data = array('browser' => $browser_id, 'platform' => $platform_id, 'device' => $device_id, 'type' => $device_type);
				return $data;
	    }
    }


class INDEXES {
	
		
	//this returns the location_country_id, when a Country Code is given
	function get_country_id($country_name, $country_code) {
		
		global $memcacheWorking, $memcache;
		
		if ($memcacheWorking) {
			$time = 2592000; //30 days in sec
			//get from memcached
			$getID = $memcache->get(md5("country-id" . $country_name . systemHash()));

			if ($getID) {
				$country_id = $getID;
				return $country_id;
			} else {

				$database = DB::getInstance();
				$db = $database->getConnection();

				$mysql['country_name'] = $db->real_escape_string($country_name);
				$mysql['country_code'] = $db->real_escape_string($country_code);

				$country_sql = "SELECT country_id FROM 202_locations_country WHERE country_code='".$mysql['country_code']."'";
				$country_result = _mysqli_query($country_sql);
				$country_row = $country_result->fetch_assoc(); 
				if ($country_row) {
					//if this ip_id already exists, return the ip_id for it.
					$country_id = $country_row['country_id'];
					//add to memcached
					$setID = $memcache->set( md5("country-id" . $country_name . systemHash()), $country_id, false, $time );
					return $country_id;
				} else {
					//else if this  doesn't exist, insert the new iprow, and return the_id for this new row we found
					$country_sql = "INSERT INTO 202_locations_country SET country_code='".$mysql['country_code']."', country_name='".$mysql['country_name']."'";
					$country_result = _mysqli_query($country_sql) ; //($ip_sql);
					$country_id = $db->insert_id;
					//add to memcached
					$setID = $memcache->set( md5("country-id" . $country_name . systemHash()), $country_id, false, $time );
					return $country_id;
				}    
			}
			
		} else {
			$database = DB::getInstance();
			$db = $database->getConnection();

			$mysql['country_name'] = $db->real_escape_string($country_name);
			$mysql['country_code'] = $db->real_escape_string($country_code);

			$country_sql = "SELECT country_id FROM 202_locations_country WHERE country_code='".$mysql['country_code']."'";
			$country_result = _mysqli_query($country_sql);
			$country_row = $country_result->fetch_assoc(); 
			if ($country_row) {
				//if this country already exists, return the location_country_id for it.
				$country_id = $country_row['country_id'];
				
				return $country_id;    
			} else {
				//else if this  doesn't exist, insert the new countryrow, and return the_id for this new row we found 
				$country_sql = "INSERT INTO 202_locations_country SET country_code='".$mysql['country_code']."', country_name='".$mysql['country_name']."'";
				$country_result = _mysqli_query($country_sql) ; //($ip_sql);
				$country_id = $db->insert_id;
				
				return $country_id;    
			}
		}
	}

	//this returns the location_city_id, when a City name is given
	function get_city_id($city_name, $country_id) {
		
		global $memcacheWorking, $memcache;
		
		if ($memcacheWorking) {
			$time = 2592000; //30 days in sec
			//get from memcached
			$getID = $memcache->get(md5( "city-id" . $city_name . $country_id . systemHash()));

			if ($getID) {
				$city_id = $getID;
				return $city_id;
			} else {

				$database = DB::getInstance();
				$db = $database->getConnection();

				$mysql['city_name'] = $db->real_escape_string($city_name);
				$mysql['country_id'] = $db->real_escape_string($country_id);

				$city_sql = "SELECT city_id FROM 202_locations_city WHERE city_name='".$mysql['city_name']."'";
				$city_result = _mysqli_query($city_sql);
				$city_row = $city_result->fetch_assoc(); 
				if ($city_row) {
					//if this ip_id already exists, return the ip_id for it.
					$city_id = $city_row['city_id'];
					//add to memcached
					$setID = $memcache->set( md5("city-id" . $city_name . $country_id . systemHash()), $city_id, false, $time );
					return $city_id;
				} else {
					//else if this  doesn't exist, insert the new iprow, and return the_id for this new row we found
					$city_sql = "INSERT INTO 202_locations_city SET city_name='".$mysql['city_name']."', main_country_id='".$mysql['country_id']."'";
					$city_result = _mysqli_query($city_sql) ; //($ip_sql);
					$city_id = $db->insert_id;
					//add to memcached
					$setID = $memcache->set( md5("city-id" . $city_name . $country_id . systemHash()), $city_id, false, $time );
					return $city_id;
				}    
			}
			
		} else {

			$database = DB::getInstance();
			$db = $database->getConnection();

			$mysql['city_name'] = $db->real_escape_string($city_name);
			$mysql['country_id'] = $db->real_escape_string($country_id);

			$city_sql = "SELECT city_id FROM 202_locations_city WHERE city_name='".$mysql['city_name']."'";
			$city_result = _mysqli_query($city_sql);
			$city_row = $city_result->fetch_assoc(); 
			if ($city_row) {
				//if this country already exists, return the location_country_id for it.
				$city_id = $city_row['city_id'];
				
				return $city_id;    
			} else {
				//else if this  doesn't exist, insert the new cityrow, and return the_id for this new row we found 
				$city_sql = "INSERT INTO 202_locations_city SET city_name='".$mysql['city_name']."', main_country_id='".$mysql['country_id']."'";
				$city_result = _mysqli_query($city_sql) ; //($ip_sql);
				$city_id = $db->insert_id;
				
				return $city_id;    
			}
		}
	}  

	//this returns the isp_id, when a isp name is given
	function get_isp_id($isp) {
		
		global $memcacheWorking, $memcache;
		
		if ($memcacheWorking) {
			$time = 604800; //7 days in sec
			//get from memcached
			$getID = $memcache->get(md5( "isp-id" . $isp . systemHash()));

			if ($getID) {
				$isp_id = $getID;
				return $isp_id;
			} else {

				$database = DB::getInstance();
				$db = $database->getConnection();

				$mysql['isp'] = $db->real_escape_string($isp);

				$isp_sql = "SELECT isp_id FROM 202_locations_isp WHERE isp_name='".$mysql['isp']."'";
				$isp_result = _mysqli_query($isp_sql);
				$isp_row = $isp_result->fetch_assoc(); 
				if ($isp_row) {
					//if this ip_id already exists, return the ip_id for it.
					$isp_id = $isp_row['isp_id'];
					//add to memcached
					$setID = $memcache->set( md5("isp-id" . $isp . systemHash()), $isp_id, false, $time );
					return $isp_id;
				} else {
					//else if this  doesn't exist, insert the new iprow, and return the_id for this new row we found
					$isp_sql = "INSERT INTO 202_locations_isp SET isp_name='".$mysql['isp']."'";
					$isp_result = _mysqli_query($isp_sql) ; //($isp_sql);
					$isp_id = $db->insert_id;
					//add to memcached
					$setID = $memcache->set( md5("isp-id" . $isp . systemHash()), $isp_id, false, $time );
					return $isp_id;
				}    
			}
			
		} else {

			$database = DB::getInstance();
			$db = $database->getConnection();

			$mysql['isp'] = $db->real_escape_string($isp);

			$isp_sql = "SELECT isp_id FROM 202_locations_isp WHERE isp_name='".$mysql['isp']."'";
			$isp_result = _mysqli_query($isp_sql);
			$isp_row = $isp_result->fetch_assoc(); 
			if ($isp_row) {
				//if this isp already exists, return the isp_id for it.
				$isp_id = $isp_row['isp_id'];
				
				return $isp_id;    
			} else {
				//else if this  doesn't exist, insert the new isp row, and return the_id for this new row we found 
				$isp_sql = "INSERT INTO 202_locations_isp SET isp_name='".$mysql['isp']."'";
				$isp_result = _mysqli_query($isp_sql) ; //($isp_sql);
				$isp_id = $db->insert_id;
				
				return $isp_id;    
			}
		}
	}

	//this returns the ip_id, when a ip_address is given
	function get_ip_id($ip_address) {
		
		global $memcacheWorking, $memcache;

		if ($memcacheWorking) {
			$time = 604800; //7 days in sec
			//get from memcached
			$getID = $memcache->get(md5("ip-id" . $ip_address . systemHash()));

			if ($getID) {
				$ip_id = $getID;
				return $ip_id;
			} else {

				$database = DB::getInstance();
				$db = $database->getConnection();

				$mysql['ip_address'] = $db->real_escape_string($ip_address);

				$ip_sql = "SELECT ip_id FROM 202_ips WHERE ip_address='".$mysql['ip_address']."'";
				$ip_result = _mysqli_query($ip_sql);
				$ip_row = $ip_result->fetch_assoc(); 
				if ($ip_row) {
					//if this ip_id already exists, return the ip_id for it.
					$ip_id = $ip_row['ip_id'];
					//add to memcached
					$setID = $memcache->set( md5( "ip-id" . $ip_address . systemHash()), $ip_id, false, $time );
					return $ip_id;
				} else {
					//else if this  doesn't exist, insert the new iprow, and return the_id for this new row we found
					$ip_sql = "INSERT INTO 202_ips SET ip_address='".$mysql['ip_address']."'";
					$ip_result = _mysqli_query($ip_sql) ; //($ip_sql);
					$ip_id = $db->insert_id;
					//add to memcached
					$setID = $memcache->set( md5( "ip-id" . $ip_address . systemHash()), $ip_id, false, $time );
					return $ip_id;
				}    
			}
			
		} else {

			$database = DB::getInstance();
			$db = $database->getConnection();

			$mysql['ip_address'] = $db->real_escape_string($ip_address);

			$ip_sql = "SELECT ip_id FROM 202_ips WHERE ip_address='".$mysql['ip_address']."'";
			$ip_result = _mysqli_query($ip_sql);
			$ip_row = $ip_result->fetch_assoc(); 
			if ($ip_row) {
				//if this ip already exists, return the ip_id for it.
				$ip_id = $ip_row['ip_id'];
				
				return $ip_id;    
			} else {
				//else if this  doesn't exist, insert the new iprow, and return the_id for this new row we found
				$ip_sql = "INSERT INTO 202_ips SET ip_address='".$mysql['ip_address']."'";
				$ip_result = _mysqli_query($ip_sql) ; //($ip_sql);
				$ip_id = $db->insert_id;
				
				return $ip_id;   
			}
		}
	}   
	
	
	//this returns the site_domain_id, when a site_url_address is given
	function get_site_domain_id($site_url_address) {

		global $memcacheWorking, $memcache;

		$parsed_url = @parse_url($site_url_address);
		$site_domain_host = $parsed_url['host'];
		$site_domain_host = str_replace('www.','',$site_domain_host);

		//if a cached key is found for this lpip, redirect to that url
		if ($memcacheWorking) {
			$time = 2592000; //30 days in sec
			//get from memcached
			$getID = $memcache->get( md5("domain-id" . $site_domain_host . systemHash()));

			if ($getID) {
				$site_domain_id = $getID;
				return $site_domain_id;
			} else {

				$database = DB::getInstance();
				$db = $database->getConnection();

				$mysql['site_domain_host'] = $db->real_escape_string($site_domain_host);

				$site_domain_sql = "SELECT site_domain_id FROM 202_site_domains WHERE site_domain_host='".$mysql['site_domain_host']."'"; 
				$site_domain_result = _mysqli_query($site_domain_sql);
				$site_domain_row = $site_domain_result->fetch_assoc();
				if ($site_domain_row) {
					//if this site_domain_id already exists, return the site_domain_id for it.
					$site_domain_id = $site_domain_row['site_domain_id'];
					//add to memcached
					$setID = $memcache->set( md5("domain-id" . $site_domain_host . systemHash()), $site_domain_id, false, $time );
					return $site_domain_id;
				} else {
					//else if this  doesn't exist, insert the new iprow, and return the_id for this new row we found
					$site_domain_sql = "INSERT INTO 202_site_domains SET site_domain_host='".$mysql['site_domain_host']."'"; 
					$site_domain_result = _mysqli_query($site_domain_sql) ; //($site_domain_sql);
					$site_domain_id = $db->insert_id;
					//add to memcached
					$setID = $memcache->set( md5("domain-id" . $site_domain_host . systemHash()), $site_domain_id, false, $time );
					return $site_domain_id;
				}    
			}
			
		} else {
			
			$database = DB::getInstance();
			$db = $database->getConnection();

			$mysql['site_domain_host'] = $db->real_escape_string($site_domain_host);

			$site_domain_sql = "SELECT site_domain_id FROM 202_site_domains WHERE site_domain_host='".$mysql['site_domain_host']."'"; 
			$site_domain_result = _mysqli_query($site_domain_sql);
			$site_domain_row = $site_domain_result->fetch_assoc();
			if ($site_domain_row) {
				//if this site_domain_id already exists, return the site_domain_id for it.
				$site_domain_id = $site_domain_row['site_domain_id'];
				//add to memcached
				return $site_domain_id;    
			} else {
				//else if this  doesn't exist, insert the new iprow, and return the_id for this new row we found
				$site_domain_sql = "INSERT INTO 202_site_domains SET site_domain_host='".$mysql['site_domain_host']."'"; 
				$site_domain_result = _mysqli_query($site_domain_sql) ; //($site_domain_sql);
				$site_domain_id = $db->insert_id;
				return $site_domain_id;    
			}    

		}
	}

	//this returns the site_url_id, when a site_url_address is given
	function get_site_url_id($site_url_address) { 
		
		global $memcacheWorking, $memcache;

		$site_domain_id = INDEXES::get_site_domain_id($site_url_address); 
		
			if ($memcacheWorking) {
				$time = 604800; //7 days in sec
				//get from memcached
				$getURL = $memcache->get( md5("url-id" . $site_url_address . systemHash()));
				if ($getURL) {
					return $getURL;

				} else {

					$database = DB::getInstance();
					$db = $database->getConnection();

					$mysql['site_url_address'] = $db->real_escape_string($site_url_address);
					$mysql['site_domain_id'] = $db->real_escape_string($site_domain_id);

					$site_url_sql = "SELECT site_url_id FROM 202_site_urls WHERE site_url_address='".$mysql['site_url_address']."'";
					$site_url_result = _mysqli_query($site_url_sql);
					$site_url_row = $site_url_result->fetch_assoc();
					if ($site_url_row) {
						//if this site_url_id already exists, return the site_url_id for it.
						$site_url_id = $site_url_row['site_url_id'];
						$setID = $memcache->set(md5("url-id" . $site_url_address . systemHash()), $site_url_id, false, $time);
						return $site_url_id;
					} else{

						$site_url_sql = "INSERT INTO 202_site_urls SET site_domain_id='".$mysql['site_domain_id']."', site_url_address='".$mysql['site_url_address']."'"; 
						$site_url_result = _mysqli_query($site_url_sql) ; //($site_url_sql);
						$site_url_id = $db->insert_id;
						$setID = $memcache->set(md5("url-id" . $site_url_address . systemHash()), $site_url_id, false, $time);
						return $site_url_id;
					} 

				}

			} else {

				$database = DB::getInstance();
				$db = $database->getConnection();

				$mysql['site_url_address'] = $db->real_escape_string($site_url_address);
				$mysql['site_domain_id'] = $db->real_escape_string($site_domain_id);

				$site_url_sql = "SELECT site_url_id FROM 202_site_urls WHERE site_url_address='".$mysql['site_url_address']."'";
				$site_url_result = _mysqli_query($site_url_sql);
				$site_url_row = $site_url_result->fetch_assoc();
				if ($site_url_row) {
					//if this site_url_id already exists, return the site_url_id for it.
					$site_url_id = $site_url_row['site_url_id'];
					return $site_url_id;
				} else{

					$site_url_sql = "INSERT INTO 202_site_urls SET site_domain_id='".$mysql['site_domain_id']."', site_url_address='".$mysql['site_url_address']."'"; 
					$site_url_result = _mysqli_query($site_url_sql) ; //($site_url_sql);
					$site_url_id = $db->insert_id;
					return $site_url_id;

				}

			}
	}       
	
	
	//this returns the keyword_id
	function get_keyword_id($keyword) {
		
		global $memcacheWorking, $memcache;

		//only grab the first 255 charactesr of keyword
		$keyword = substr($keyword, 0, 255);
		
		if ($memcacheWorking) {
				//get from memcached
				$getKeyword = $memcache->get( md5("keyword-id" . $keyword . systemHash()));
				if ($getKeyword) {
					return $getKeyword;

				} else {

					$database = DB::getInstance();
					$db = $database->getConnection();

					$mysql['keyword'] = $db->real_escape_string($keyword);

					$keyword_sql = "SELECT keyword_id FROM 202_keywords WHERE keyword='".$mysql['keyword']."'";
					$keyword_result = _mysqli_query($keyword_sql);
					$keyword_row = $keyword_result->fetch_assoc();
					if ($keyword_row) {
						//if this already exists, return the id for it
						$keyword_id = $keyword_row['keyword_id'];
						$setID = $memcache->set(md5("keyword-id" . $keyword . systemHash()), $keyword_id, false);
						return $keyword_id;
					} else{

						$keyword_sql = "INSERT INTO 202_keywords SET keyword='".$mysql['keyword']."'";
						$keyword_result = _mysqli_query($keyword_sql) ; //($keyword_sql);
						$keyword_id = $db->insert_id;
						$setID = $memcache->set(md5("keyword-id" . $keyword . systemHash()), $keyword_id, false);
						return $keyword_id;
					} 

				}

		} else {
				$database = DB::getInstance();
				$db = $database->getConnection();

				$mysql['keyword'] = $db->real_escape_string($keyword);

				$keyword_sql = "SELECT keyword_id FROM 202_keywords WHERE keyword='".$mysql['keyword']."'";
				$keyword_result = _mysqli_query($keyword_sql);
				$keyword_row = $keyword_result->fetch_assoc();
				if ($keyword_row) {
					//if this already exists, return the id for it
					$keyword_id = $keyword_row['keyword_id'];
					return $keyword_id;    
				} else {
					//else if this ip doesn't exist, insert the row and grab the id for it
					$keyword_sql = "INSERT INTO 202_keywords SET keyword='".$mysql['keyword']."'";
					$keyword_result = _mysqli_query($keyword_sql) ; //($keyword_sql);
					$keyword_id = $db->insert_id;
					return $keyword_id;    
				}

		}
	}
	
	//this returns the c1 id
	function get_c1_id($c1) {
		
		global $memcacheWorking, $memcache;

		//only grab the first 350 charactesr of c1
		$c1 = substr($c1, 0, 350);
		
		if ($memcacheWorking) {
				//get from memcached
				$getc1 = $memcache->get( md5("c1-id" . $c1 . systemHash()));
				if ($getc1) {
					return $getc1;

				} else {

					$database = DB::getInstance();
					$db = $database->getConnection();

					$mysql['c1'] = $db->real_escape_string($c1);

					$c1_sql = "SELECT c1_id FROM 202_tracking_c1 WHERE c1='".$mysql['c1']."'";
					$c1_result = _mysqli_query($c1_sql);
					$c1_row = $c1_result->fetch_assoc();
					if ($c1_row) {
						//if this already exists, return the id for it
						$c1_id = $c1_row['c1_id'];
						$setID = $memcache->set(md5("c1-id" . $c1 . systemHash()), $c1_id, false);
						return $c1_id;
					} else{

						$c1_sql = "INSERT INTO 202_tracking_c1 SET c1='".$mysql['c1']."'";
						$c1_result = _mysqli_query($c1_sql) ; //($c1_sql);
						$c1_id = $db->insert_id;
						$setID = $memcache->set(md5("c1-id" . $c1 . systemHash()), $c1_id, false);
						return $c1_id;
					} 

				}

		} else {

				$database = DB::getInstance();
				$db = $database->getConnection();
					
				$mysql['c1'] = $db->real_escape_string($c1);

				$c1_sql = "SELECT c1_id FROM 202_tracking_c1 WHERE c1='".$mysql['c1']."'";
				$c1_result = _mysqli_query($c1_sql);
				$c1_row = $c1_result->fetch_assoc();
				if ($c1_row) {
					//if this already exists, return the id for it
					$c1_id = $c1_row['c1_id'];
					return $c1_id;    
				} else {
					//else if this ip doesn't exist, insert the row and grab the id for it
					$c1_sql = "INSERT INTO 202_tracking_c1 SET c1='".$mysql['c1']."'";
					$c1_result = _mysqli_query($c1_sql) ; //($c1_sql);
					$c1_id = $db->insert_id;
					return $c1_id;     
				}

		}
	}

	//this returns the c2 id
	function get_c2_id($c2) {
		
		global $memcacheWorking, $memcache;

		//only grab the first 350 charactesr of c2
		$c2 = substr($c2, 0, 350);
		
		if ($memcacheWorking) {
				//get from memcached
				$getc2 = $memcache->get( md5("c2-id" . $c2 . systemHash()));
				if ($getc2) {
					return $getc2;

				} else {

					$database = DB::getInstance();
					$db = $database->getConnection();

					$mysql['c2'] = $db->real_escape_string($c2);

					$c2_sql = "SELECT c2_id FROM 202_tracking_c2 WHERE c2='".$mysql['c2']."'";
					$c2_result = _mysqli_query($c2_sql);
					$c2_row = $c2_result->fetch_assoc();
					if ($c2_row) {
						//if this already exists, return the id for it
						$c2_id = $c2_row['c2_id'];
						$setID = $memcache->set(md5("c2-id" . $c2 . systemHash()), $c2_id, false);
						return $c2_id;
					} else{

						$c2_sql = "INSERT INTO 202_tracking_c2 SET c2='".$mysql['c2']."'";
						$c2_result = _mysqli_query($c2_sql) ; //($c2_sql);
						$c2_id = $db->insert_id;
						$setID = $memcache->set(md5("c2-id" . $c2 . systemHash()), $c2_id, false);
						return $c2_id;
					} 

				}

		} else {

				$database = DB::getInstance();
				$db = $database->getConnection();

				$mysql['c2'] = $db->real_escape_string($c2);

				$c2_sql = "SELECT c2_id FROM 202_tracking_c2 WHERE c2='".$mysql['c2']."'";
				$c2_result = _mysqli_query($c2_sql);
				$c2_row = $c2_result->fetch_assoc();
				if ($c2_row) {
					//if this already exists, return the id for it
					$c2_id = $c2_row['c2_id'];
					return $c2_id;    
				} else {
					//else if this ip doesn't exist, insert the row and grab the id for it
					$c2_sql = "INSERT INTO 202_tracking_c2 SET c2='".$mysql['c2']."'";
					$c2_result = _mysqli_query($c2_sql) ; //($c2_sql);
					$c2_id = $db->insert_id;
					return $c2_id;    
				}

		}
	}

	//this returns the c3 id
	function get_c3_id($c3) {
		
		global $memcacheWorking, $memcache;

		//only grab the first 350 charactesr of c3
		$c3 = substr($c3, 0, 350);
		
		if ($memcacheWorking) {
				//get from memcached
				$getc3 = $memcache->get( md5("c3-id" . $c3 . systemHash()));
				if ($getc3) {
					return $getc3;

				} else {

					$database = DB::getInstance();
					$db = $database->getConnection();

					$mysql['c3'] = $db->real_escape_string($c3);

					$c3_sql = "SELECT c3_id FROM 202_tracking_c3 WHERE c3='".$mysql['c3']."'";
					$c3_result = _mysqli_query($c3_sql);
					$c3_row = $c3_result->fetch_assoc();
					if ($c3_row) {
						//if this already exists, return the id for it
						$c3_id = $c3_row['c3_id'];
						$setID = $memcache->set(md5("c3-id" . $c3 . systemHash()), $c3_id, false);
						return $c3_id;
					} else{

						$c3_sql = "INSERT INTO 202_tracking_c3 SET c3='".$mysql['c3']."'";
						$c3_result = _mysqli_query($c3_sql) ; //($c3_sql);
						$c3_id = $db->insert_id;
						$setID = $memcache->set(md5("c3-id" . $c3 . systemHash()), $c3_id, false);
						return $c3_id;
					} 

				}

		} else {

				$database = DB::getInstance();
				$db = $database->getConnection();

				$mysql['c3'] = $db->real_escape_string($c3);

				$c3_sql = "SELECT c3_id FROM 202_tracking_c3 WHERE c3='".$mysql['c3']."'";
				$c3_result = _mysqli_query($c3_sql);
				$c3_row = $c3_result->fetch_assoc();
				if ($c3_row) {
					//if this already exists, return the id for it
					$c3_id = $c3_row['c3_id'];
					return $c3_id;    
				} else {
					//else if this ip doesn't exist, insert the row and grab the id for it
					$c3_sql = "INSERT INTO 202_tracking_c3 SET c3='".$mysql['c3']."'";
					$c3_result = _mysqli_query($c3_sql) ; //($c3_sql);
					$c3_id = $db->insert_id;
					return $c3_id;    
				}

		}
	}
	
	//this returns the c4 id
	function get_c4_id($c4) {
		
		global $memcacheWorking, $memcache;

		//only grab the first 350 charactesr of c4
		$c4 = substr($c4, 0, 350);
		
		if ($memcacheWorking) {
				//get from memcached
				$getc4 = $memcache->get( md5("c4-id" . $c4 . systemHash()));
				if ($getc4) {
					return $getc4;

				} else {

					$database = DB::getInstance();
					$db = $database->getConnection();

					$mysql['c4'] = $db->real_escape_string($c4);

					$c4_sql = "SELECT c4_id FROM 202_tracking_c4 WHERE c4='".$mysql['c4']."'";
					$c4_result = _mysqli_query($c4_sql);
					$c4_row = $c4_result->fetch_assoc();
					if ($c4_row) {
						//if this already exists, return the id for it
						$c4_id = $c4_row['c4_id'];
						$setID = $memcache->set(md5("c4-id" . $c4 . systemHash()), $c4_id, false);
						return $c4_id;
					} else{

						$c4_sql = "INSERT INTO 202_tracking_c4 SET c4='".$mysql['c4']."'";
						$c4_result = _mysqli_query($c4_sql) ; //($c4_sql);
						$c4_id = $db->insert_id;
						$setID = $memcache->set(md5("c4-id" . $c4 . systemHash()), $c4_id, false);
						return $c4_id;
					} 

				}

		} else {

				$database = DB::getInstance();
				$db = $database->getConnection();

				$mysql['c4'] = $db->real_escape_string($c4);

				$c4_sql = "SELECT c4_id FROM 202_tracking_c4 WHERE c4='".$mysql['c4']."'";
				$c4_result = _mysqli_query($c4_sql);
				$c4_row = $c4_result->fetch_assoc();
				if ($c4_row) {
					//if this already exists, return the id for it
					$c4_id = $c4_row['c4_id'];
					return $c4_id;    
				} else {
					//else if this ip doesn't exist, insert the row and grab the id for it
					$c4_sql = "INSERT INTO 202_tracking_c4 SET c4='".$mysql['c4']."'";
					$c4_result = _mysqli_query($c4_sql) ; //($c4_sql);
					$c4_id = $db->insert_id;
					return $c4_id;    
				}

		}
	}
	
}


function showChart ($chart, $chartWidth, $chartHeight) {
	
	$database = DB::getInstance();
	$db = $database->getConnection();

	$reg_key = "C1XUW9CU8Y4L.NS5T4Q79KLYCK07EK";
	
	$chart_xml = SendChartData ( $chart );
	$mysql['chart_xml'] = $db->real_escape_string($chart_xml);

	$chart_sql = "INSERT INTO 202_charts SET chart_xml='".$mysql['chart_xml']."'";
	$chart_result = _mysqli_query($chart_sql) ; //($chart_sql);
	$chart_id = $db->insert_id;

	$url['chart_id'] = urlencode($chart_id);                                                                                                       
	echo InsertChart ( '/202-charts/charts.swf', 
					   '/202-charts/charts_library', 
					   '/202-charts/showChart.php?chart_id='.$url['chart_id'],
						$chartWidth, $chartHeight, 'FFFFFF', false, $reg_key );             
}


function runBreakdown($user_pref) {

	$database = DB::getInstance();
	$db = $database->getConnection();

	//grab time
		$time = grab_timeframe();    
	 
	 //get breakdown pref
		$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);   
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
		$user_result = _mysqli_query($user_sql) ; ; //($user_sql);
		$user_row = $user_result->fetch_assoc();

		if ($user_row['user_pref_show'] == 'all') { $click_flitered = ''; }
		if ($user_row['user_pref_show'] == 'real') { $click_filtered = " AND click_filtered='0' "; }
		if ($user_row['user_pref_show'] == 'filtered') { $click_filtered = " AND click_filtered='1' "; }
		if ($user_row['user_pref_show'] == 'filtered_bot') { $click_filtered = " AND click_bot='1' "; }
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
		$breakdown_result = _mysqli_query($breakdown_sql) ; //($breakdown_sql);
	
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
 
		$mysql['from'] = $db->real_escape_string($from);    
		$mysql['to'] = $db->real_escape_string($to);    

		//build query
		$command = "
			SELECT
			COUNT(*) AS clicks,
				AVG(2c.click_cpc) AS avg_cpc,
				SUM(2cr.click_out) AS click_throughs,
				SUM(2c.click_lead) AS leads,
				SUM(2c.click_payout*2c.click_lead) AS income
			FROM
				202_clicks AS 2c
				LEFT JOIN 202_clicks_record AS 2cr ON 2cr.click_id = 2c.click_id
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

		global $memcacheWorking, $memcache;

		if (!user_cache_time($mysql['user_id'])) {

			$click_result = _mysqli_query($click_sql) ; //($click_sql);
			$click_row = $click_result->fetch_assoc();

		} else {

			if ($memcacheWorking) {

				$getCache = $memcache->get(md5($click_sql . systemHash()));

				if ($getCache) {
					$click_row = unserialize($getCache);

				} else {
					$click_result = _mysqli_query( $click_sql );
					$fetchArray = $click_result->fetch_assoc();
					$setCache = $memcache->set( md5( $click_sql . systemHash()), serialize( $fetchArray ), false, user_cache_time($mysql['user_id'])  );
					$click_row = $fetchArray;

				}

			} else {

			$click_result = _mysqli_query($click_sql) ; //($click_sql);
			$click_row = $click_result->fetch_assoc();

			}
		}
				
		//for AVG Payouts
		$payouts_sql = "
			SELECT
				SUM(2c.click_payout) AS payout
			FROM 202_clicks AS 2c
			WHERE 2c.click_lead='1'
			AND 2c.click_time > ".$mysql['from'] ."
			AND 2c.click_time <= ".$mysql['to'] ."
		";
		$payout_result = _mysqli_query($payouts_sql) ; //($breakdown_sql);
		$payout_row = $payout_result->fetch_assoc();
		
		//get the stats
		$clicks = 0;  
		$clicks = $click_row['clicks'];

		$total_clicks = $total_clicks + $clicks;

		//click throughs
		$click_throughs = $click_row['click_throughs'];
		$total_click_throughs = $total_click_throughs + $click_throughs;

		//LP CTR
		$ctr = @round($click_throughs / $clicks * 100, 2);

		$total_ctr = @round($total_click_throughs / $total_clicks * 100, 2);        
	
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
		
		$payout = 0;

		//current payout
		if ($click_row['leads'] > 0) {
			$payout = $payout_row['payout']/$leads;
		}

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
		$mysql['clicks'] = $db->real_escape_string($clicks);
		$mysql['click_throughs'] = $db->real_escape_string($click_throughs);
		$mysql['ctr'] = $db->real_escape_string($ctr);
		$mysql['leads'] = $db->real_escape_string($leads);
		$mysql['su_ratio'] = $db->real_escape_string($su_ratio);
		$mysql['payout'] = $db->real_escape_string($payout);
		$mysql['epc'] = $db->real_escape_string($epc);
		$mysql['avg_cpc'] = $db->real_escape_string($avg_cpc);
		$mysql['income'] = $db->real_escape_string($income);
		$mysql['cost'] = $db->real_escape_string($cost);
		$mysql['net'] = $db->real_escape_string($net);
		$mysql['roi'] = $db->real_escape_string($roi);
		
		//insert chart
		$sort_breakdown_sql = "
			INSERT INTO
				202_sort_breakdowns
			SET
				sort_breakdown_from='".$mysql['from']."',
				sort_breakdown_to='".$mysql['to']."',
				user_id='".$mysql['user_id']."',
				sort_breakdown_clicks='".$mysql['clicks']."',
				sort_breakdown_click_throughs='".$mysql['click_throughs']."',
				sort_breakdown_ctr='".$mysql['ctr']."',
				sort_breakdown_leads='".$mysql['leads']."',
				sort_breakdown_su_ratio='".$mysql['su_ratio']."',
				sort_breakdown_payout='".$mysql['payout']."',
				sort_breakdown_epc='".$mysql['epc']."',
				sort_breakdown_avg_cpc='".$mysql['avg_cpc']."',
				sort_breakdown_income='".$mysql['income']."',
				sort_breakdown_cost='".$mysql['cost']."',
				sort_breakdown_net='".$mysql['net']."',
				sort_breakdown_roi='".$mysql['roi']."'
		";
		$sort_breakdown_result = _mysqli_query($sort_breakdown_sql) ; //($sort_breakdown_sql);
	}
	
	$breakdown_sql = "
		SELECT *
		FROM 202_sort_breakdowns
		WHERE user_id='".$mysql['user_id']."'
	";
	$breakdown_result = _mysqli_query($breakdown_sql) ; //($breakdown_sql);

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
		for ( $i=0; $i < $breakdown_result->num_rows; $i++ ) {

		   $breakdown_result->field_seek($breakdown_result->current_field);

		   //determine which column in the PHP array the current data belongs to
		   $row = $breakdown_result->fetch_assoc();
		   $col = $row['sort_breakdown_from'];

		   //populate the PHP array with the Year title
		   $date = $row['sort_breakdown_from'];
		   $date = date_chart($breakdown, $date);      
		  
		   $chart [ 'chart_data' ][ 0 ][ $col ] = $date;

		   //populate the PHP array with the revenue data
		   $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_income'];
		   $chart [ 'chart_data' ][ 2 ][ $col ] = $row['sort_breakdown_cost'];
		   $chart [ 'chart_data' ][ 3 ][ $col ] = $row['sort_breakdown_net'];
		}
		
		$chart[ 'series_color' ] = array (  "70CF40", "CF4040", "409CCF","000000"); 
		$chart[ 'series_gap' ] = array ( 'set_gap'=>40, 'bar_gap'=>-35 );
		$chart[ 'chart_grid_h' ] = array ( 'alpha'=>20, 'color'=>"000000", 'thickness'=>1, 'type'=>"solid" );
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
		for ( $i=0; $i < $breakdown_result->num_rows; $i++ ) {

			$breakdown_result->field_seek($breakdown_result->current_field);

		   //determine which column in the PHP array the current data belongs to
		   $row = $breakdown_result->fetch_assoc();
		   $col = $row['sort_breakdown_from'];

		   //populate the PHP array with the Year title
		   $date = $row['sort_breakdown_from'];
		   $date = date_chart($breakdown, $date);
		   
		   $chart [ 'chart_data' ][ 0 ][ $col ] = $date;

		   //populate the PHP array with the revenue data
		  
		   
			if ($pref_chart == 'clicks') {  $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_clicks'];  }
			elseif ($pref_chart == 'leads') {  $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_leads'];  }  
			elseif ($pref_chart == 'su_ratio') {  $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_su_ratio'];  }   
			elseif ($pref_chart == 'payout') {  $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_payout'];  }   
			elseif ($pref_chart == 'epc') { $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_epc'];  } 
			elseif ($pref_chart == 'cpc') {  $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_avg_cpc'];  } 
			elseif ($pref_chart == 'income') { $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_income'];  } 
			elseif ($pref_chart == 'cost') {  $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_cost'];  } 
			elseif ($pref_chart == 'net') {  $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_net'];  } 
			elseif ($pref_chart == 'roi') {  $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_roi'];  } 
		}

		//$chart[ 'series_color' ] = array (  "003399"); 
		$chart[ 'series_color' ] = array (  "000000"); 
		$chart[ 'chart_type' ] = "Line";                          
		//$chart[ 'chart_transition' ] = array ( 'type'=>"dissolve", 'delay'=>.5, 'duration'=>.5, 'order'=>"series" ); 
		$chart[ 'chart_grid_h' ] = array ( 'alpha'=>20, 'color'=>"000000", 'thickness'=>1, 'type'=>"solid" );
		   
	
	}    
	$chart[ 'chart_pref' ] = array ( 'line_thickness'=>1, 'point_shape'=>"circle", 'fill_shape'=>false );   
	$chart[ 'axis_value' ] = array (   'bold'             =>  false, 'size'             =>  10                   );                     
	$chart[ 'axis_category' ] = array (    'skip'          =>  $skip, 'bold'          =>  false, 'size'          =>  10); 
	$chart[ 'legend_label' ] = array (   'bold'    =>  true,   'size'    =>  12, ); 
	$chart[ 'chart_rect' ] = array (   'x'               =>  40,'y'               =>  20,'width'           =>  $chartWidth,'height'          =>  $chartHeight,);                                   

	showChart ($chart, $chartWidth, $chartHeight+40) ;

	
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

	$database = DB::getInstance();
	$db = $database->getConnection();

	//grab time
		$time = grab_timeframe();    
	 
	 //get breakdown pref
		$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);   
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
		$user_result = _mysqli_query($user_sql) ; ; //($user_sql);
		$user_row = $user_result->fetch_assoc();


		if ($user_row['user_pref_show'] == 'all') { $click_flitered = ''; }
		if ($user_row['user_pref_show'] == 'real') { $click_filtered = " AND click_filtered='0' "; }
		if ($user_row['user_pref_show'] == 'filtered') { $click_filtered = " AND click_filtered='1' "; }
		if ($user_row['user_pref_show'] == 'filtered_bot') { $click_filtered = " AND click_bot='1' "; }
		if ($user_row['user_pref_show'] == 'leads') { $click_filtered = " AND click_lead='1' "; } 

	//breakdown should be hour, day, month, or year.
		$pref_chart = $user_row['user_pref_chart'];
		
	//first delete old report
		$breakdown_sql = "
			DELETE
			FROM 202_sort_breakdowns
			WHERE user_id='".$mysql['user_id']."'
		";
		$breakdown_result = _mysqli_query($breakdown_sql) ; //($breakdown_sql);
	
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
		$end1 = $end + 3600; 

		$hour = date('G', $end1);
		
		$mysql['from'] = $db->real_escape_string($from);    
		$mysql['to'] = $db->real_escape_string($to);    

		//build query
		$command = "
			SELECT
				COUNT(*) AS clicks,
				SUM(2cr.click_out) AS click_throughs,
				SUM(click_cpc) AS cost,
				SUM(2c.click_lead) AS leads,
				SUM(2c.click_payout*2c.click_lead) AS income
			FROM
				202_clicks AS 2c
				LEFT JOIN 202_clicks_record AS 2cr ON 2cr.click_id = 2c.click_id
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

		global $memcacheWorking, $memcache;

		if (!user_cache_time($mysql['user_id'])) {

			$click_result = _mysqli_query($click_sql) ; //($click_sql);
			$click_row = $click_result->fetch_assoc();

		} else {

			if ($memcacheWorking) {

				$getCache = $memcache->get(md5($click_sql . systemHash()));

				if ($getCache) {
					$click_row = unserialize($getCache);

				} else {
					$click_result = _mysqli_query( $click_sql );
					$fetchArray = $click_result->fetch_assoc();
					$setCache = $memcache->set( md5( $click_sql . systemHash()), serialize( $fetchArray ), false, user_cache_time($mysql['user_id'])  );
					$click_row = $fetchArray;

				}

			} else {

			$click_result = _mysqli_query($click_sql) ; //($click_sql);
			$click_row = $click_result->fetch_assoc();

			}
		}

		//for AVG Payouts
		$payouts_sql = "
			SELECT
				SUM(2c.click_payout) AS payout
			FROM 202_clicks AS 2c
			WHERE 2c.click_lead='1'
			AND 2c.click_time > ".$mysql['from'] ."
			AND 2c.click_time <= ".$mysql['to'] ."
		";
		$payout_result = _mysqli_query($payouts_sql) ; //($breakdown_sql);
		$payout_row = $payout_result->fetch_assoc();
		
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

		//clicks out
		$click_throughs[$hour] = $click_row['click_throughs'] + $click_throughs[$hour];
		$total_click_throughs = $total_click_throughs + $click_row['click_throughs'];

		//CTR
		$ctr[$hour] = @round($click_throughs[$hour] / $clicks[$hour] * 100, 2);

		$total_ctr = @round($total_click_throughs / $total_clicks * 100, 2);

		//leads
		$leads[$hour] = $click_row['leads'] + $leads[$hour];
		
		$total_leads = $total_leads +  $click_row['leads'] ;

		//signup ratio
		$su_ratio[$hour] = @round($leads[$hour]/$clicks[$hour]*100,2);
		
		$total_su_ratio = @round($total_leads/$total_clicks*100,2);

		//current payout

		if ($click_row['leads'] > 0) {
			$payout[$hour] = $payout_row['payout']/$leads[$hour];
		}

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
		
		$mysql['from'] = $db->real_escape_string($from);
		$mysql['to'] = $db->real_escape_string($to);
		$mysql['clicks'] = $db->real_escape_string($clicks[$hour]);
		$mysql['click_throughs'] = $db->real_escape_string($click_throughs[$hour]);
		$mysql['ctr'] = $db->real_escape_string($ctr[$hour]);
		$mysql['leads'] = $db->real_escape_string($leads[$hour]);
		$mysql['su_ratio'] = $db->real_escape_string($su_ratio[$hour]);
		$mysql['payout'] = $db->real_escape_string($payout[$hour]);
		$mysql['epc'] = $db->real_escape_string($epc[$hour]);
		$mysql['avg_cpc'] = $db->real_escape_string($avg_cpc[$hour]);
		$mysql['income'] = $db->real_escape_string($income[$hour]);
		$mysql['cost'] = $db->real_escape_string($cost[$hour]);
		$mysql['net'] = $db->real_escape_string($net[$hour]);
		$mysql['roi'] = $db->real_escape_string($roi[$hour]);

		
		//insert chart
		$sort_breakdown_sql = "
			INSERT INTO 202_sort_breakdowns
			SET
				sort_breakdown_from='".$mysql['from']."',
				sort_breakdown_to='".$mysql['to']."',
				user_id='".$mysql['user_id']."',
				sort_breakdown_clicks='".$mysql['clicks']."',
				sort_breakdown_click_throughs='".$mysql['click_throughs']."',
				sort_breakdown_ctr='".$mysql['ctr']."',
				sort_breakdown_leads='".$mysql['leads']."',
				sort_breakdown_su_ratio='".$mysql['su_ratio']."',
				sort_breakdown_payout='".$mysql['payout']."',
				sort_breakdown_epc='".$mysql['epc']."',
				sort_breakdown_avg_cpc='".$mysql['avg_cpc']."',
				sort_breakdown_income='".$mysql['income']."',
				sort_breakdown_cost='".$mysql['cost']."',
				sort_breakdown_net='".$mysql['net']."',
				sort_breakdown_roi='".$mysql['roi']."'
		";
		$sort_breakdown_result = _mysqli_query($sort_breakdown_sql) ; //($sort_breakdown_sql);  
	}
	
	
	$breakdown_sql = "
		SELECT *
		FROM 202_sort_breakdowns
		WHERE user_id='".$mysql['user_id']."'
		ORDER BY sort_breakdown_from ASC
	";
	$breakdown_result = _mysqli_query($breakdown_sql) ; //($breakdown_sql);

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
		for ( $i=0; $i < $breakdown_result->num_rows; $i++ ) {

		   //determine which column in the PHP array the current data belongs to
			$breakdown_result->field_seek($breakdown_result->current_field);
			$row = $breakdown_result->fetch_assoc();
		    $col = $row['sort_breakdown_from'];
		    $col++;		   
		   
		   
		   //populate the PHP array with the Year title
		   $hour = $row['sort_breakdown_from'];
	
		   if ($hour == 0) { $hour = 'midnight'; } 
		   if (( $hour > 0) and ($hour < 12)) { $hour = $hour . 'am'; } 
		   if ($hour == 12) { $hour =  'noon'; }
		   if ($hour > 12) { $hour = ($hour - 12) . 'pm'; }
		  
		   $chart [ 'chart_data' ][ 0 ][ $col ] = $hour;

		   //populate the PHP array with the revenue data
		   $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_income'];
		   $chart [ 'chart_data' ][ 2 ][ $col ] = $row['sort_breakdown_cost'];
		   $chart [ 'chart_data' ][ 3 ][ $col ] = $row['sort_breakdown_net'];
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
		for ( $i=0; $i < $breakdown_result->num_rows; $i++ ) {

		   //determine which column in the PHP array the current data belongs to
		   $breakdown_result->field_seek($breakdown_result->current_field);
		   $row = $breakdown_result->fetch_assoc();
		   $col = $row['sort_breakdown_from'];
		   $col++;
		   
		    //populate the PHP array with the Year title
		   $hour = $row['sort_breakdown_from'];
	
		   if ($hour == 0) { $hour = 'midnight'; } 
		   if (( $hour > 0) and ($hour < 12)) { $hour = $hour . 'am'; } 
		   if ($hour == 12) { $hour =  'noon'; }
		   if ($hour > 12) { $hour = ($hour - 12) . 'pm'; }
		  
		   $chart [ 'chart_data' ][ 0 ][ $col ] = $hour;

		   //populate the PHP array with the revenue data
		  
		   
			if ($pref_chart == 'clicks') {  $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_clicks'];  }
			elseif ($pref_chart == 'leads') {  $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_leads'];  }  
			elseif ($pref_chart == 'su_ratio') {  $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_su_ratio'];  }   
			elseif ($pref_chart == 'payout') {  $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_payout'];  }   
			elseif ($pref_chart == 'epc') { $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_epc'];  } 
			elseif ($pref_chart == 'cpc') {  $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_avg_cpc'];  } 
			elseif ($pref_chart == 'income') { $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_income'];  } 
			elseif ($pref_chart == 'cost') {  $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_cost'];  } 
			elseif ($pref_chart == 'net') {  $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_net'];  } 
			elseif ($pref_chart == 'roi') {  $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_roi'];  } 
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

	$database = DB::getInstance();
	$db = $database->getConnection();

	//grab time
		$time = grab_timeframe();    
	 
	 //get breakdown pref
		$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);   
		$user_sql = "SELECT 	user_time_register,
								user_pref_breakdown,
								user_pref_chart,
								user_pref_show
					 FROM   202_users LEFT JOIN 202_users_pref USING (user_id) 
					 WHERE  202_users.user_id='".$mysql['user_id']."'";
		$user_result = _mysqli_query($user_sql) ; ; //($user_sql);
		$user_row = $user_result->fetch_assoc();

		if ($user_row['user_pref_show'] == 'all') { $click_flitered = ''; }
		if ($user_row['user_pref_show'] == 'real') { $click_filtered = " AND click_filtered='0' "; }
		if ($user_row['user_pref_show'] == 'filtered') { $click_filtered = " AND click_filtered='1' "; }
		if ($user_row['user_pref_show'] == 'filtered_bot') { $click_filtered = " AND click_bot='1' "; }
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
		$breakdown_result = _mysqli_query($breakdown_sql) ; //($breakdown_sql);
	
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
		$end1 = $end + 86400;

		
		$day =  date('D', $end1);
		switch ($day) { 
			case "Sun": $day = 1; break;
			case "Mon": $day = 2; break;
			case "Tue": $day = 3; break;
			case "Wed": $day = 4; break;
			case "Thu": $day = 5; break;
			case "Fri": $day = 6; break;
			case "Sat": $day = 7; break;
		}
		
		$mysql['from'] = $db->real_escape_string($from);    
		$mysql['to'] = $db->real_escape_string($to);    

		//build query
		$command = "
			SELECT
				COUNT(*) AS clicks,
				SUM(2cr.click_out) AS click_throughs,
				SUM(click_cpc) AS cost,
				SUM(2c.click_lead) AS leads,
				SUM(2c.click_payout*2c.click_lead) AS income
			FROM
				202_clicks AS 2c
				LEFT JOIN 202_clicks_record AS 2cr ON 2cr.click_id = 2c.click_id
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
		
		$click_result = _mysqli_query($click_sql) ; //($click_sql);
		$click_row = $click_result->fetch_assoc();

		//for AVG Payouts
		$payouts_sql = "
			SELECT
				SUM(2c.click_payout) AS payout
			FROM 202_clicks AS 2c
			WHERE 2c.click_lead='1'
			AND 2c.click_time > ".$mysql['from'] ."
			AND 2c.click_time <= ".$mysql['to'] ."
		";
		$payout_result = _mysqli_query($payouts_sql) ; //($breakdown_sql);
		$payout_row = $payout_result->fetch_assoc();
		
		
		//get the stats
		$clicks[$day] = $click_row['clicks'] + $clicks[$day];
		
		$total_clicks = $total_clicks + $click_row['clicks'];

		//click_throughs
		$click_throughs[$day] = $click_row['click_throughs'] + $click_throughs[$day];

		$total_click_throughs = $total_click_throughs + $click_row['click_throughs'];

		//ctr
		$ctr[$day] = @round($click_throughs[$day] / $clicks[$day] * 100, 2);

		$total_ctr = @round($total_click_throughs / $total_clicks * 100, 2);
	
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
		

		//current payout
		if ($click_row['leads'] > 0) {
			$payout[$day] = $payout_row['payout']/$leads[$day];
		}

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
		
		$mysql['from'] = $db->real_escape_string($from);
		$mysql['to'] = $db->real_escape_string($to);
		$mysql['clicks'] = $db->real_escape_string($clicks[$day]);
		$mysql['click_throughs'] = $db->real_escape_string($click_throughs[$day]);
		$mysql['ctr'] = $db->real_escape_string($ctr[$day]);
		$mysql['leads'] = $db->real_escape_string($leads[$day]);
		$mysql['su_ratio'] = $db->real_escape_string($su_ratio[$day]);
		$mysql['payout'] = $db->real_escape_string($payout[$day]);
		$mysql['epc'] = $db->real_escape_string($epc[$day]);
		$mysql['avg_cpc'] = $db->real_escape_string($avg_cpc[$day]);
		$mysql['income'] = $db->real_escape_string($income[$day]);
		$mysql['cost'] = $db->real_escape_string($cost[$day]);
		$mysql['net'] = $db->real_escape_string($net[$day]);
		$mysql['roi'] = $db->real_escape_string($roi[$day]);
		
		//insert chart
		$sort_breakdown_sql = "
			INSERT INTO 202_sort_breakdowns
			SET
				sort_breakdown_from='".$mysql['from']."',
				sort_breakdown_to='".$mysql['to']."',
				user_id='".$mysql['user_id']."',
				sort_breakdown_clicks='".$mysql['clicks']."',
				sort_breakdown_click_throughs='".$mysql['click_throughs']."',
				sort_breakdown_ctr='".$mysql['ctr']."',
				sort_breakdown_leads='".$mysql['leads']."',
				sort_breakdown_su_ratio='".$mysql['su_ratio']."',
				sort_breakdown_payout='".$mysql['payout']."',
				sort_breakdown_epc='".$mysql['epc']."',
				sort_breakdown_avg_cpc='".$mysql['avg_cpc']."',
				sort_breakdown_income='".$mysql['income']."',
				sort_breakdown_cost='".$mysql['cost']."',
				sort_breakdown_net='".$mysql['net']."',
				sort_breakdown_roi='".$mysql['roi']."'
		";
		$sort_breakdown_result = _mysqli_query($sort_breakdown_sql) ; #echo "<p>$sort_breakdown_sql</p>";
	}
	
	
	$breakdown_sql = "
		SELECT *
		FROM 202_sort_breakdowns
		WHERE user_id='".$mysql['user_id']."'
		ORDER BY sort_breakdown_from ASC
	";
	$breakdown_result = _mysqli_query($breakdown_sql) ; //($breakdown_sql);

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
		for ( $i=0; $i < $breakdown_result->num_rows; $i++ ) {

		   //determine which column in the PHP array the current data belongs to
		   $breakdown_result->field_seek($breakdown_result->current_field);
		   $row = $breakdown_result->fetch_assoc();
		   $col = $row['sort_breakdown_from'];
		   $col++;
		   
		   
		   //populate the PHP array with the Year title
		   $day = $row['sort_breakdown_from'];
		   
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
		   $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_income'];
		   $chart [ 'chart_data' ][ 2 ][ $col ] = $row['sort_breakdown_cost'];
		   $chart [ 'chart_data' ][ 3 ][ $col ] = $row['sort_breakdown_net'];
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
		for ( $i=0; $i < $breakdown_result->num_rows; $i++ ) {

		   //determine which column in the PHP array the current data belongs to
		   $breakdown_result->field_seek($breakdown_result->current_field);
		   $row = $breakdown_result->fetch_assoc();
		   $col = $row['sort_breakdown_from'];
		   $col++;
		   
		   
		    //populate the PHP array with the Year title
		   $day = $row['sort_breakdown_from'];
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
		  
		   
			if ($pref_chart == 'clicks') {  $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_clicks'];  }
			elseif ($pref_chart == 'leads') {  $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_leads'];  }  
			elseif ($pref_chart == 'su_ratio') {  $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_su_ratio'];  }   
			elseif ($pref_chart == 'payout') {  $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_payout'];  }   
			elseif ($pref_chart == 'epc') { $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_epc'];  } 
			elseif ($pref_chart == 'cpc') {  $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_avg_cpc'];  } 
			elseif ($pref_chart == 'income') { $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_income'];  } 
			elseif ($pref_chart == 'cost') {  $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_cost'];  } 
			elseif ($pref_chart == 'net') {  $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_net'];  } 
			elseif ($pref_chart == 'roi') {  $chart [ 'chart_data' ][ 1 ][ $col ] = $row['sort_breakdown_roi'];  } 
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
		
		$getCache = $memcache -> get(md5($user_id . systemHash()));
		
		$queries = explode(",",$getCache);
		
		if (!in_array( $sql, $queries ) ) {
		
			$queries[] = $sql;
		
		}
		
		$queries = implode(",", $queries);
		
		$setCache = $memcache -> set (md5($user_id, $queries . systemHash()), false);
		
	}	

}


function memcache_mysql_fetch_assoc( $sql, $allowCaching = 1, $minutes = 5 ) {
	
	global $memcacheWorking, $memcache;

	if ($memcacheWorking == false) { 
		
		$result = _mysqli_query($sql);
		$row = $result->fetch_assoc();
		return $row;
	} else {

		if( $allowCaching == 0 ) {
			$result = _mysqli_query($sql);
			$row = $result->fetch_assoc();
			return $row;
		} else {

			// Check if its set
			$getCache = $memcache->get( md5( $sql . systemHash()));
			
			if( $getCache === false ) {
				// cache this data
				$result = _mysqli_query( $sql );
				$fetchArray = $result->fetch_assoc();
				$setCache = $memcache->set( md5( $sql . systemHash()), serialize( $fetchArray ), false, 60*$minutes  );
				
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
		$result = _mysqli_query($sql) ; //($sql);
		while ($fetch = $result->fetch_assoc()) {
			$row[] = $fetch;
		}
		return $row;
	} else {

		if( $allowCaching == 0 ) {
			$row = array();
			$result = _mysqli_query($sql) ; //($sql);
			while ($fetch = $result->fetch_assoc()) {
				$row[] = $fetch;
			}
			return $row;
		} else {
			
			$getCache = $memcache->get( md5( $sql . systemHash()));
			if( $getCache === false ) { 
				//if data is NOT cache, cache this data
				$row = array();     
				$result = _mysqli_query($sql) ; //($sql);
				while ($fetch = $result->fetch_assoc()) {
					$row[] = $fetch;
				}
				$setCache = $memcache->set( md5( $sql . systemHash()), serialize( $row ), false, 60*5 );  
				
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
	
	$delayed_result = _mysqli_query($delayed_sql) ; //($delayed_sql);
}




function rotateTrackerUrl($tracker_row) { 
	
	if (!$tracker_row['aff_campaign_rotate']) return $tracker_row['aff_campaign_url'];
	
	$mysql['aff_campaign_id'] = $db->real_escape_string($tracker_row['aff_campaign_id']);
	$urls = array();
	array_push($urls, $tracker_row['aff_campaign_url']);

	
	if ($tracker_row['aff_campaign_url_2']) array_push($urls, $tracker_row['aff_campaign_url_2']);
	if ($tracker_row['aff_campaign_url_3']) array_push($urls, $tracker_row['aff_campaign_url_3']);
	if ($tracker_row['aff_campaign_url_4']) array_push($urls, $tracker_row['aff_campaign_url_4']);
	if ($tracker_row['aff_campaign_url_5']) array_push($urls, $tracker_row['aff_campaign_url_5']);
	
	$count = count($urls);
	
	$sql5 = "SELECT rotation_num FROM 202_rotations WHERE aff_campaign_id='".$mysql['aff_campaign_id']."'";
	$result5 = _mysqli_query($sql5);
	$row5 = $result5->fetch_assoc();
	if ($row5) { 
		
		$old_num = $row5['rotation_num'];
		if ($old_num >= ($count - 1))		$num = 0;
		else 						$num = $old_num + 1;
		
		$mysql['num'] = $db->real_escape_string($num);
		$sql5 = " UPDATE 202_rotations SET rotation_num='".$mysql['num']."' WHERE aff_campaign_id='".$mysql['aff_campaign_id']."'";
		$result5 = _mysqli_query($sql5);
	
	} else {
		//insert the rotation
		$num = 0;
		$mysql['num'] = $db->real_escape_string($num);
		$sql5 = " INSERT INTO 202_rotations SET aff_campaign_id='".$mysql['aff_campaign_id']."',  rotation_num='".$mysql['num']."' ";
		$result5 = _mysqli_query($sql5);
		$rotation_num = 0;
	}
	
	$url = $urls[$num];
	return $url;
}

function replaceTrackerPlaceholders($url,$click_id) {

	$database = DB::getInstance();
	$db = $database->getConnection();

	//get the tracker placeholder values
	$mysql['click_id'] = $db->real_escape_string($click_id);
	
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
		$click_result = _mysqli_query($click_sql);
		$click_row = $click_result->fetch_assoc();
		
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

function setPCIdCookie($click_id_public) {
	setcookie('tracking202pci',$click_id_public,0,'/', $_SERVER['SERVER_NAME']);
}

function setOutboundCookie($outbound_site_url) {
	setcookie('tracking202outbound',$outbound_site_url,0,'/', $_SERVER['SERVER_NAME']);
}

function user_cache_time($user_id)
{	
	$database = DB::getInstance();
	$db = $database->getConnection();

	$mysql['user_id'] = $db->real_escape_string($user_id);
	$sql = "SELECT cache_time FROM 202_users_pref WHERE user_id='".$mysql['user_id']."'";
	$result = _mysqli_query($sql);
	$row = $result->fetch_assoc();
	return $row['cache_time'];
}


function get_user_data_feedback($user_id){
	$database = DB::getInstance();
	$db = $database->getConnection();
	$mysql['user_id'] = $db->real_escape_string($user_id);
	$sql = "SELECT user_email, user_time_register, clickserver_api_key, install_hash, user_hash, modal_status, vip_perks_status FROM 202_users WHERE user_id='".$mysql['user_id']."'";
	$result = _mysqli_query($sql);
	$row = $result->fetch_assoc();

	return array(
      	'user_email' => $row['user_email'],
      	'time_stamp' => $row['user_time_register'],
      	'api_key' => $row['clickserver_api_key'],
      	'install_hash' => $row['install_hash'],
      	'user_hash' => $row['user_hash'],
      	'modal_status' => $row['modal_status'],
      	'vip_perks_status' => $row['vip_perks_status'],
   );
}


function clickserver_api_upgrade_url($key){

	//Initiate curl
	$ch = curl_init();
	// Disable SSL verification
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	// Will return the response, if false it print the response
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	// Set the url
	curl_setopt($ch, CURLOPT_URL, 'http://my.tracking202.com/api/v1/auth/?apiKey='.$key.'&clickserverId='.base64_encode($_SERVER['HTTP_HOST']));
	// Execute
	$result=curl_exec($ch);

	$data = json_decode($result, true);

		if ($data['isValidKey'] != 'true' || $data['isValidDomain'] != 'true') {
			return false;
			die();
		}

	$download_url = $data['downloadURL'];
	return $download_url;

	curl_close($ch);
}

function clickserver_api_key_validate($key){
	//Initiate curl
	$ch = curl_init();
	// Disable SSL verification
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	// Will return the response, if false it print the response
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	// Set the url
	curl_setopt($ch, CURLOPT_URL, 'http://my.tracking202.com/api/v1/auth/?apiKey='.$key.'&clickserverId='.base64_encode($_SERVER['HTTP_HOST']));
	// Execute
	$result=curl_exec($ch);

	$data = json_decode($result, true);

	if ($data['isValidKey'] != 'true' || $data['isValidDomain'] != 'true') {
		return false;
		die();
	}

	return true;

	curl_close($ch);
}

function getGeoData($ip){

	global $GEOIP_REGION_NAME;
	
	$gi = geoip_open($_SERVER['DOCUMENT_ROOT']. "/202-config/geo/GeoLite.dat", GEOIP_STANDARD);

	$record = geoip_record_by_addr($gi, $ip);

	$country = $record->country_name;
	$country_code = $record->country_code;
	$city = $record->city;
	$region = $GEOIP_REGION_NAME[$record->country_code][$record->region];

	if ($record != "null") {
		if ($country == null) {
			$country = "Unknown country";
			$country_code = "non";
		}

		if ($city == null) {
			$city = "Unknown city";
		}

		if ($region == null) {
			$region = "Unknown region";
		}
	}

	$geoData = array('country' => $country, 'country_code' => $country_code, 'region' => $region, 'city' => $city);

	geoip_close($gi);
	
	return $geoData;
}

function getIspData($ip){
	$giisp = geoip_open($_SERVER['DOCUMENT_ROOT']. "/202-config/geo/GeoIPISP.dat", GEOIP_STANDARD);
	$isp = geoip_org_by_addr($giisp, $ip);

	if (!$isp) {
		$isp = "Unknown ISP/Carrier";
	}

	geoip_close($giisp);

	return $isp;
}

function systemHash(){
  $hash = hash('ripemd160', $_SERVER['HTTP_HOST'] . $_SERVER['SERVER_ADDR']);
  return $hash;
}

function getBrowserIcon($name){
	switch ($name) {
		case 'Chrome':
			$icon = 'chrome';
			break;

		case 'Chrome Frame':
			$icon = 'chrome';
			break;	

		case 'Android':
			$icon = 'android';
			break;

		case 'Chrome Mobile':
			$icon = 'chrome';
			break;

		case 'Chrome Mobile iOS':
			$icon = 'chrome';
			break;
		
		case 'Firefox':
			$icon = 'firefox';
			break;
		
		case 'IE':
			$icon = 'ie';
			break;

		case 'Mobile Safari':
			$icon = 'safari';
			break;

		case 'Safari':
			$icon = 'safari';
			break;

		case 'Opera':
			$icon = 'opera';
			break;

		case 'Opera Tablet':
			$icon = 'opera';
			break;

		case 'Opera Mobile':
			$icon = 'opera';
			break;

		case 'WebKit Nightly':
			$icon = 'webkitnightly';
			break;

		default:
			$icon = 'other';
	}

	return $icon;
}

function getSurveyData($install_hash){

	//Initiate curl
	$ch = curl_init();
	// Disable SSL verification
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	// Will return the response, if false it print the response
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	// Set the url
	curl_setopt($ch, CURLOPT_URL, 'http://my.tracking202.com/api/v1/deep/survey/'.$install_hash);
	// Execute
	$result = curl_exec($ch);
	//close connection
	curl_close($ch);

	$data = json_decode($result, true);

	return $data;
}

function updateSurveyData($install_hash, $post){

	$fields = http_build_query($post);

	//Initiate curl
	$ch = curl_init();
	// Set the url
	curl_setopt($ch, CURLOPT_URL, 'http://my.tracking202.com/api/v1/deep/survey/'.$install_hash);
	// Disable SSL verification
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	// Will return the response, if false it print the response
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	//Set to post
	curl_setopt($ch, CURLOPT_POST, 1);
	//Set post fields
	curl_setopt($ch,CURLOPT_POSTFIELDS, $fields);
	// Execute
	$result = curl_exec($ch);

	$data = json_decode($result, true);

	//close connection
	curl_close($ch);

	return $data;

}

function intercomHash($install_hash){
	//Initiate curl
	$ch = curl_init();
	// Set the url
	curl_setopt($ch, CURLOPT_URL, 'http://my.tracking202.com/api/v1/hash/?h='.$install_hash);
	// Disable SSL verification
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	// Will return the response, if false it print the response
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	// Execute
	$result = curl_exec($ch);

	$data = json_decode($result, true);

	//close connection
	curl_close($ch);

	return $data['user_hash'];
}

function rotator_data($query, $type){
	//Initiate curl
	$ch = curl_init();
	// Set the url
	curl_setopt($ch, CURLOPT_URL, 'http://my.tracking202.com/api/v1/deep/rotator/'.$type.'/'.$query);
	// Disable SSL verification
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	// Will return the response, if false it print the response
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	// Execute
	$result = curl_exec($ch);

	//close connection
	curl_close($ch);

	return $result;
}

function changelog(){
	//Initiate curl
	$ch = curl_init();
	// Set the url
	curl_setopt($ch, CURLOPT_URL, 'http://my.tracking202.com/clickserver/currentversion/changelogs.php');
	// Disable SSL verification
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	// Will return the response, if false it print the response
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	// Execute
	$result = curl_exec($ch);

	//close connection
	curl_close($ch);

	return json_decode($result, true);
}


?>