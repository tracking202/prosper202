<?php
use UAParser\Parser;

// This function will return true, if a user is logged in correctly, and false, if they are not.
function record_mysql_error($sql)
{
    $database = DB::getInstance();
    $db = $database->getConnection();
    
    global $server_row;
    
    // record the mysql error
    $clean['mysql_error_text'] = mysqli_error($db);
    
    // if on dev server, echo the error
    
    echo $sql . '<br/><br/>' . $clean['mysql_error_text'] . '<br/><br/>';
    die();
    
    $ip_id = INDEXES::get_ip_id($_SERVER['HTTP_X_FORWARDED_FOR']);
    $mysql['ip_id'] = $db->real_escape_string($ip_id);
    
    $site_url = 'http://' . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
    $site_id = INDEXES::get_site_url_id($site_url);
    $mysql['site_id'] = $db->real_escape_string($site_id);
    
    $mysql['user_id'] = $db->real_escape_string(strip_tags($_SESSION['user_id']));
    $mysql['mysql_error_text'] = $db->real_escape_string($clean['mysql_error_text']);
    $mysql['mysql_error_sql'] = $db->real_escape_string($sql);
    $mysql['script_url'] = $db->real_escape_string(strip_tags($_SERVER['SCRIPT_URL']));
    $mysql['server_name'] = $db->real_escape_string(strip_tags($_SERVER['SERVER_NAME']));
    $mysql['mysql_error_time'] = time();
    
    $report_sql = "INSERT     INTO  202_mysql_errors
								SET     mysql_error_text='" . $mysql['mysql_error_text'] . "',
										mysql_error_sql='" . $mysql['mysql_error_sql'] . "',
										user_id='" . $mysql['user_id'] . "',
										ip_id='" . $mysql['ip_id'] . "',
										site_id='" . $mysql['site_id'] . "',
										mysql_error_time='" . $mysql['mysql_error_time'] . "'";
    $report_query = _mysqli_query($report_sql);
    
    // email administration of the error
    $to = $_SERVER['SERVER_ADMIN'];
    $subject = 'mysql error reported - ' . $site_url;
    $message = '<b>A mysql error has been reported</b><br/><br/>
		
					time: ' . date('r', time()) . '<br/>
					server_name: ' . $_SERVER['SERVER_NAME'] . '<br/><br/>
					
					user_id: ' . $_SESSION['user_id'] . '<br/>
					script_url: ' . $site_url . '<br/>
					$_SERVER: ' . serialize($_SERVER) . '<br/><br/>
					
					. . . . . . . . <br/><br/>
												 
					_mysqli_query: ' . $sql . '<br/><br/>
					 
					mysql_error: ' . $clean['mysql_error_text'];
    $from = $_SERVER['SERVER_ADMIN'];
    $type = 3; // type 3 is mysql_error
               
    // send_email($to,$subject,$message,$from,$type);
               
    // report error to user and end page    ?>
<div class="warning" style="margin: 40px auto; width: 450px;">
	<div>
		<h3>A database error has occured, the webmaster has been notified</h3>
		<p>If this error persists, you may email us directly: <?php printf('<a href="mailto:%s">%s</a>',$_SERVER['SERVER_ADMIN'],$_SERVER['SERVER_ADMIN']); ?></p>
	</div>
</div>


<?php
    
template_bottom($server_row);
    die();
}

function dollar_format($amount, $currency = null, $cpv = false)
{
    setlocale(LC_MONETARY, 'en_US.UTF-8');
    
    if ($cpv == true) {
        $decimals = 5;
    } else {
        $decimals = 2;
    }
    
    if ($currency == null) {
    	$currency = 'USD';
    }

    $currency_before = '';
    $currency_after = '';
 	
 	if ($currency == 'USD') $currency_before = '$';
 	if ($currency == 'BRL') $currency_before = 'R$'; 
 	if ($currency == 'CZK') $currency_after = 'Kč'; 
 	if ($currency == 'DKK') $currency_before = 'kr.'; 
 	if ($currency == 'EUR') $currency_before = '€';
 	if ($currency == 'HUF') $currency_after = 'Ft'; 
 	if ($currency == 'ILS') $currency_before = '₪'; 
 	if ($currency == 'JPY') $currency_before = '¥'; 
 	if ($currency == 'MYR') $currency_before = 'RM';
 	if ($currency == 'NOK') $currency_before = 'kr'; 
 	if ($currency == 'PHP') $currency_before = '₱'; 
 	if ($currency == 'PLN') $currency_before = 'zł'; 
 	if ($currency == 'GBP') $currency_before = '£';
 	if ($currency == 'SEK') $currency_before = 'kr'; 
 	if ($currency == 'CHF') $currency_before = 'SFr.'; 
 	if ($currency == 'TWD') $currency_before = 'NT$'; 
 	if ($currency == 'THB') $currency_before = '฿';
 	if ($currency == 'TRY') $currency_after = '₺';
 	if ($currency == 'CNY') $currency_before = '¥';
 	if ($currency == 'INR') $currency_before = '₹';
 	if ($currency == 'RUB') $currency_before = '₽';

 	if ($currency_before == '' && $currency_after == '') $currency_before = $currency;

 	if ($amount !== '') {
 		if ($amount >= 0) {
	        $new_amount = $currency_before . number_format($amount, $decimals) . $currency_after;
	    } else {
	        $new_amount = $currency_before . number_format($amount, $decimals) . $currency_after;
	        $new_amount = '(' . $new_amount . ')';
	    }
 	} else {
 		$new_amount = $currency_before . $currency_after;
 	}
    return $new_amount;
}

function display_calendar($page, $show_time, $show_adv, $show_bottom, $show_limit, $show_breakdown, $show_type, $show_cpc_or_cpv = true, $show_adv_breakdown = false)
{
    global $navigation;
    $database = DB::getInstance();
    $db = $database->getConnection();
    AUTH::set_timezone($_SESSION['user_timezone']);
    
    $mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
    $user_sql = "SELECT * FROM 202_users_pref WHERE user_id=" . $mysql['user_id'];
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
    $html['from'] = date('m/d/Y', $time['from']);
    $html['to'] = date('m/d/Y', $time['to']);
    $html['ip'] = htmlentities($user_row['user_pref_ip'], ENT_QUOTES, 'UTF-8');
    if($user_row['user_pref_subid'] != '0' && !empty($user_row['user_pref_subid'])){
        $html['subid'] = htmlentities($user_row['user_pref_subid'], ENT_QUOTES, 'UTF-8');
    }
    else{
        $html['subid'] = '';
    }
    $html['user_pref_country_id'] = htmlentities($user_row['user_pref_country_id'], ENT_QUOTES, 'UTF-8');
    $html['user_pref_region_id'] = htmlentities($user_row['user_pref_region_id'], ENT_QUOTES, 'UTF-8');
    $html['user_pref_isp_id'] = htmlentities($user_row['user_pref_isp_id'], ENT_QUOTES, 'UTF-8');
    $html['referer'] = htmlentities($user_row['user_pref_referer'], ENT_QUOTES, 'UTF-8');
    $html['keyword'] = htmlentities($user_row['user_pref_keyword'], ENT_QUOTES, 'UTF-8');
    $html['page'] = htmlentities($page, ENT_QUOTES, 'UTF-8');
    $html['user_pref_device_id'] = htmlentities($user_row['user_pref_device_id'], ENT_QUOTES, 'UTF-8');
    $html['user_pref_browser_id'] = htmlentities($user_row['user_pref_browser_id'], ENT_QUOTES, 'UTF-8');
    $html['user_pref_platform_id'] = htmlentities($user_row['user_pref_platform_id'], ENT_QUOTES, 'UTF-8');
    ?>

<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<div id="preferences-wrapper">
			<span style="position: absolute; font-size: 12px;"><span
				class="fui-search"></span> Refine your search: </span>
			<form id="user_prefs" onsubmit="return false;"
				class="form-inline text-right" role="form">
				<div class="row">
					<div class="col-xs-12">
						<label for="from">Start date: </label>
						<div class="form-group datepicker" style="margin-right: 5px;">
							<input type="text" class="form-control input-sm" name="from"
								id="from" value="<?php echo $html['from']; ?>">
						</div>

						<label for="to">End date: </label>
						<div class="form-group datepicker">
							<input type="text" class="form-control input-sm" name="to"
								id="to" value="<?php echo $html['to']; ?>">
						</div>

						<div class="form-group">
							<label class="sr-only" for="user_pref_time_predefined">Date</label>
							<select class="form-control input-sm"
								name="user_pref_time_predefined" id="user_pref_time_predefined"
								onchange="set_user_pref_time_predefined();">
								<option value="">Custom Date</option>
								<option
									<?php if ($time['user_pref_time_predefined'] == 'today') { echo 'selected=""'; } ?>
									value="today">Today</option>
								<option
									<?php if ($time['user_pref_time_predefined'] == 'yesterday') { echo 'selected=""'; } ?>
									value="yesterday">Yesterday</option>
								<option
									<?php if ($time['user_pref_time_predefined'] == 'last7') { echo 'selected=""'; } ?>
									value="last7">Last 7 Days</option>
								<option
									<?php if ($time['user_pref_time_predefined'] == 'last14') { echo 'selected=""'; } ?>
									value="last14">Last 14 Days</option>
								<option
									<?php if ($time['user_pref_time_predefined'] == 'last30') { echo 'selected=""'; } ?>
									value="last30">Last 30 Days</option>
								<option
									<?php if ($time['user_pref_time_predefined'] == 'thismonth') { echo 'selected=""'; } ?>
									value="thismonth">This Month</option>
								<option
									<?php if ($time['user_pref_time_predefined'] == 'lastmonth') { echo 'selected=""'; } ?>
									value="lastmonth">Last Month</option>
								<option
									<?php if ($time['user_pref_time_predefined'] == 'thisyear') { echo 'selected=""'; } ?>
									value="thisyear">This Year</option>
								<option
									<?php if ($time['user_pref_time_predefined'] == 'lastyear') { echo 'selected=""'; } ?>
									value="lastyear">Last Year</option>
								<option
									<?php if ($time['user_pref_time_predefined'] == 'alltime') { echo 'selected=""'; } ?>
									value="alltime">All Time</option>
							</select>
						</div>
					</div>
				</div>

				<div class="form_seperator" style="margin: 5px 0px; padding: 1px">
					<div class="col-xs-12"></div>
				</div>

		<?php if ($navigation[1] == 'tracking202') { ?>
		<div class="row" style="text-align:left; <?php if ($show_adv == false) { echo 'display:none;'; } ?>">
					<div class="col-xs-12" style="margin-top: 5px;">
						<div class="row">
						<?php if(!$_SESSION['publisher']){ ?>
							<div class="col-xs-6">
								<label>Traffic Source/Account: </label>

								<div class="form-group">
									<img id="ppc_network_id_div_loading" class="loading"
										style="display: none;"
										src="<?php echo get_absolute_url();?>202-img/loader-small.gif" />
									<div style="margin-left: 2px;" id="ppc_network_id_div"></div>
								</div>

								<div class="form-group">
									<div id="ppc_account_id_div"></div>
								</div>
							</div>
                    <?php } //end publisher check?>
							<div class="col-xs-6" style="text-align: right">
								<div class="row">
									<div class="col-xs-6">
										<label>Subid: </label>
										<div class="form-group">
											<input type="text" class="form-control input-sm" name="subid"
												id="subid" value="<?php echo $html['subid']; ?>" />
										</div>
									</div>
									<div class="col-xs-6">
										<label>Visitor IP: </label>
										<div class="form-group">
											<input type="text" class="form-control input-sm" name="ip"
												id="ip" value="<?php echo $html['ip']; ?>" />
										</div>
									</div>
								</div>
							</div>
                    <?php if(!$_SESSION['publisher']){ ?>
							<div class="col-xs-6">
								<label>Category/Campaign: </label>
								<div class="form-group">
									<img id="aff_network_id_div_loading" class="loading"
										style="display: none;"
										src="<?php echo get_absolute_url();?>202-img/loader-small.gif" />
									<div id="aff_network_id_div"></div>
								</div>

								<div class="form-group">
									<div id="aff_campaign_id_div"></div>
								</div>
							</div>
                    <?php } //end publisher check?>
							<div class="col-xs-6" style="text-align: right">
								<div class="row">
									<div class="col-xs-6">
										<label>Keyword: </label>
										<div class="form-group">
											<input name="keyword" id="keyword" type="text"
												class="form-control input-sm"
												value="<?php echo $html['keyword']; ?>" />
										</div>
									</div>
									<div class="col-xs-6">
										<label>Referer: </label>
										<div class="form-group">
											<input name="referer" id="referer" type="text"
												class="form-control input-sm"
												value="<?php echo $html['referer']; ?>" />
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
				<div id="more-options" style="margin-bottom: 5px; height: 87px; <?php if (($user_row['user_pref_adv'] != '1') or ($show_adv == false)) { echo 'display: none;'; } ?>">
					<div class="row" style="text-align: left;">
						<div class="col-xs-12" style="margin-top: 5px;">
							<div class="row">
							 <?php if(!$_SESSION['publisher']){ ?>
								<div class="col-xs-6">
									<label>Text Ad: </label>

									<div class="form-group">
										<img id="text_ad_id_div_loading" class="loading"
											style="display: none;"
											src="<?php echo get_absolute_url();?>202-img/loader-small.gif" />
										<div id="text_ad_id_div" style="margin-left: 69px;"></div>
									</div>

									<div class="form-group">
										<img id="ad_preview_div_loading" class="loading"
											style="display: none;"
											src="<?php echo get_absolute_url();?>202-img/loader-small.gif" />
										<div id="ad_preview_div"
											style="position: absolute; top: -12px; font-size: 10px;"></div>
									</div>
								</div>
                                <?php } //end publisher check?>
								<div class="col-xs-6" style="text-align: right">
									<div class="row">
										<div class="col-xs-6">
											<label>Device type: </label>
											<div class="form-group">
												<img id="device_id_div_loading" class="loading"
													style="right: 0px; left: 5px;"
													src="<?php echo get_absolute_url();?>202-img/loader-small.gif" />
												<div id="device_id_div" style="top: -12px; font-size: 10px;">
													<select class="form-control input-sm" name="device_id"
														id="device_id">
														<option value="0">--</option>
													</select>
												</div>
											</div>
										</div>

										<div class="col-xs-6">
											<label>Country: </label>
											<div class="form-group">
												<img id="country_id_div_loading" class="loading"
													style="right: 0px; left: 5px;"
													src="<?php echo get_absolute_url();?>202-img/loader-small.gif" />
												<div id="country_id_div"
													style="top: -12px; font-size: 10px;">
													<select class="form-control input-sm" name="country_id"
														id="country_id">
														<option value="0">--</option>
													</select>
												</div>
											</div>
										</div>
									</div>
								</div>
							</div>
							<div class="row">
							<?php if(!$_SESSION['publisher']){ ?>
								<div class="col-xs-6">
									<label>Method of Promotion: </label>
									<div class="form-group">
										<img id="method_of_promotion_div_loading" class="loading"
											style="display: none;"
											src="<?php echo get_absolute_url();?>202-img/loader-small.gif" />
										<div id="method_of_promotion_div" style="margin-left: 9px;"></div>
									</div>
								</div>
                                <?php } //end publisher check?>
								<div class="col-xs-6" style="text-align: right">
									<div class="row">
										<div class="col-xs-6">
											<label>Browser: </label>
											<div class="form-group">
												<img id="browser_id_div_loading" class="loading"
													style="right: 0px; left: 5px;"
													src="<?php echo get_absolute_url();?>202-img/loader-small.gif" />
												<div id="browser_id_div"
													style="top: -12px; font-size: 10px;">
													<select class="form-control input-sm" name="browser_id"
														id="browser_id">
														<option value="0">--</option>
													</select>
												</div>
											</div>
										</div>
										<div class="col-xs-6">
											<label>Region: </label>
											<div class="form-group">
												<img id="region_id_div_loading" class="loading"
													style="right: 0px; left: 5px;"
													src="<?php echo get_absolute_url();?>202-img/loader-small.gif" />
												<div id="region_id_div" style="top: -12px; font-size: 10px;">
													<select class="form-control input-sm" name="region_id"
														id="region_id">
														<option value="0">--</option>
													</select>
												</div>
											</div>
										</div>
									</div>
								</div>
							</div>
							<div class="row">
							<?php if(!$_SESSION['publisher']){ ?>
								<div class="col-xs-6">
									<label>Landing Page: </label>
									<div class="form-group">
										<img id="landing_page_div_loading" class="loading"
											style="display: none;"
											src="<?php echo get_absolute_url();?>202-img/loader-small.gif" />
										<div id="landing_page_div" style="margin-left: 45px;"></div>
									</div>
								</div>
                                <?php } //end publisher check?>
								<div class="col-xs-6" style="text-align: right">
									<div class="row">
										<div class="col-xs-6">
											<label>Platforms: </label>
											<div class="form-group">
												<img id="platform_id_div_loading" class="loading"
													style="right: 0px; left: 5px;"
													src="<?php echo get_absolute_url();?>202-img/loader-small.gif" />
												<div id="platform_id_div"
													style="top: -12px; font-size: 10px;">
													<select class="form-control input-sm" name="platform_id"
														id="platform_id">
														<option value="0">--</option>
													</select>
												</div>
											</div>
										</div>
										<div class="col-xs-6">
											<label>ISP/Carrier: </label>
											<div class="form-group">
												<img id="isp_id_div_loading" class="loading"
													style="right: 0px; left: 5px;"
													src="<?php echo get_absolute_url();?>202-img/loader-small.gif" />
												<div id="isp_id_div" style="top: -12px; font-size: 10px;">
													<select class="form-control input-sm" name="isp_id"
														id="isp_id">
														<option value="0">--</option>
													</select>
												</div>
											</div>
										</div>
									</div>
								</div>
							</div>

						</div>
					</div>

					<div class="form_seperator" style="margin: 5px 0px; padding: 1px;">
						<div class="col-xs-12"></div>
					</div>
				</div>

		<?php } ?>
		<?php if($show_adv_breakdown==true) { ?>
		<div class="row">
					<div class="col-xs-12" style="margin-top:5px; <?php if ($show_adv != false) { echo 'text-align:left;'; } ?> <?php if ($show_bottom == false) { echo 'display:none;'; } ?>">
						<label>Group By: </label>
						<div class="form-group">
							<label class="sr-only" for="user_pref_limit">Date</label> <select
								class="form-control input-sm" name="details[]">
						<?php foreach(ReportSummaryForm::getDetailArray() AS $detail_item) { ?>
							<option value="<?php echo $detail_item ?>"
									<?php echo $html['user_pref_group_1']==$detail_item ? 'selected="selected"' : ''; ?>><?php echo ReportBasicForm::translateDetailLevelById($detail_item); ?></option>
						<?php } ?>
					</select>
						</div>

						<label>Then Group By: </label>
						<div class="form-group">
							<label class="sr-only" for="user_pref_breakdown">Date</label> <select
								class="form-control input-sm" name="details[]">
								<option
									value="<?php echo ReportBasicForm::DETAIL_LEVEL_NONE; ?>"
									<?php echo $html['user_pref_group_1']==ReportBasicForm::DETAIL_LEVEL_NONE ? 'selected="selected"' : ''; ?>><?php echo ReportBasicForm::translateDetailLevelById(ReportBasicForm::DETAIL_LEVEL_NONE); ?></option>
						<?php foreach(ReportSummaryForm::getDetailArray() AS $detail_item) { ?>
							<option value="<?php echo $detail_item ?>"
									<?php echo $html['user_pref_group_2']==$detail_item ? 'selected="selected"' : ''; ?>><?php echo ReportBasicForm::translateDetailLevelById($detail_item); ?></option>
						<?php } ?>
					</select>
						</div>

						<label>Then Group By: </label>
						<div class="form-group">
							<label class="sr-only" for="user_pref_chart">Date</label> <select
								class="form-control input-sm" name="details[]">
								<option
									value="<?php echo ReportBasicForm::DETAIL_LEVEL_NONE; ?>"
									<?php echo $html['user_pref_group_1']==ReportBasicForm::DETAIL_LEVEL_NONE ? 'selected="selected"' : ''; ?>><?php echo ReportBasicForm::translateDetailLevelById(ReportBasicForm::DETAIL_LEVEL_NONE); ?></option>
						<?php foreach(ReportSummaryForm::getDetailArray() AS $detail_item) { ?>
							<option value="<?php echo $detail_item ?>"
									<?php echo $html['user_pref_group_3']==$detail_item ? 'selected="selected"' : ''; ?>><?php echo ReportBasicForm::translateDetailLevelById($detail_item); ?></option>
						<?php } ?>
					</select>
						</div>

						<label>Then Group By: </label>
						<div class="form-group">
							<label class="sr-only" for="user_pref_show">Date</label> <select
								class="form-control input-sm" name="details[]">
								<option
									value="<?php echo ReportBasicForm::DETAIL_LEVEL_NONE; ?>"
									<?php echo $html['user_pref_group_1']==ReportBasicForm::DETAIL_LEVEL_NONE ? 'selected="selected"' : ''; ?>><?php echo ReportBasicForm::translateDetailLevelById(ReportBasicForm::DETAIL_LEVEL_NONE); ?></option>
						<?php foreach(ReportBasicForm::getDetailArray() AS $detail_item) { ?>
							<option value="<?php echo $detail_item ?>"
									<?php echo $html['user_pref_group_4']==$detail_item ? 'selected="selected"' : ''; ?>><?php echo ReportBasicForm::translateDetailLevelById($detail_item); ?></option>
						<?php } ?>
					</select>
						</div>

					</div>
				</div>

				<div class="form_seperator" style="margin: 5px 0px; padding: 1px;">
					<div class="col-xs-12"></div>
				</div>

		<?php } ?>
		<div class="row">
					<div class="col-xs-12" style="margin-top:5px; <?php if ($show_adv != false) { echo 'text-align:left;'; } ?> <?php if ($show_bottom == false) { echo 'display:none;'; } ?>">
						<label>Display: </label>
						<div class="form-group">
							<label class="sr-only" for="user_pref_limit">Date</label> <select class="form-control input-sm" name="user_pref_limit" id="user_pref_limit" style="width: auto; <?php if ($show_limit == false) { echo 'display:none;'; } ?>">
								<option
									<?php if ($user_row['user_pref_limit'] == '10') { echo 'SELECTED'; } ?>
									value="10">10</option>
								<option
									<?php if ($user_row['user_pref_limit'] == '25') { echo 'SELECTED'; } ?>
									value="25">25</option>
								<option
									<?php if ($user_row['user_pref_limit'] == '50') { echo 'SELECTED'; } ?>
									value="50">50</option>
								<option
									<?php if ($user_row['user_pref_limit'] == '75') { echo 'SELECTED'; } ?>
									value="75">75</option>
								<option
									<?php if ($user_row['user_pref_limit'] == '100') { echo 'SELECTED'; } ?>
									value="100">100</option>
								<option
									<?php if ($user_row['user_pref_limit'] == '150') { echo 'SELECTED'; } ?>
									value="150">150</option>
								<option
									<?php if ($user_row['user_pref_limit'] == '200') { echo 'SELECTED'; } ?>
									value="200">200</option>
							</select>
						</div>

						<div class="form-group">
							<label class="sr-only" for="user_pref_breakdown">Date</label> <select
								class="form-control input-sm" name="user_pref_breakdown"
								id="user_pref_breakdown"
								<?php if ($show_breakdown == false) { echo 'style="display:none;"'; } ?>>
								<option
									<?php if ($user_row['user_pref_breakdown'] == 'hour') { echo 'SELECTED'; } ?>
									value="hour">By Hour</option>
								<option
									<?php if ($user_row['user_pref_breakdown'] == 'day') { echo 'SELECTED'; } ?>
									value="day">By Day</option>
								<option
									<?php if ($user_row['user_pref_breakdown'] == 'month') { echo 'SELECTED'; } ?>
									value="month">By Month</option>
								<option
									<?php if ($user_row['user_pref_breakdown'] == 'year') { echo 'SELECTED'; } ?>
									value="year">By Year</option>
							</select>
						</div>

						<div class="form-group">
							<label class="sr-only" for="user_pref_show">Date</label> <select
								style="width: 155px;" class="form-control input-sm"
								name="user_pref_show" id="user_pref_show"
								<?php if ($show_type == false) { echo 'style="display:none;"'; } ?>>
								<option
									<?php if ($user_row['user_pref_show'] == 'all') { echo 'SELECTED'; } ?>
									value="all">Show All Clicks</option>
								<option
									<?php if ($user_row['user_pref_show'] == 'real') { echo 'SELECTED'; } ?>
									value="real">Show Real Clicks</option>
								<option
									<?php if ($user_row['user_pref_show'] == 'filtered') { echo 'SELECTED'; } ?>
									value="filtered">Show Filtered Out Clicks</option>
								<option
									<?php if ($user_row['user_pref_show'] == 'filtered_bot') { echo 'SELECTED'; } ?>
									value="filtered_bot">Show Filtered Out Bot Clicks</option>
								<option
									<?php if ($user_row['user_pref_show'] == 'leads') { echo 'SELECTED'; } ?>
									value="leads">Show Converted Clicks</option>
							</select>
						</div>

						<div class="form-group">
							<label class="sr-only" for="user_cpc_or_cpv">Date</label> <select
								class="form-control input-sm" name="user_cpc_or_cpv"
								id="user_cpc_or_cpv"
								<?php if ($show_cpc_or_cpv == false) { echo 'style="display:none;"'; } ?>>
								<option
									<?php if ($user_row['user_cpc_or_cpv'] == 'cpc') { echo 'SELECTED'; } ?>
									value="cpc">CPC Costs</option>
								<option
									<?php if ($user_row['user_cpc_or_cpv'] == 'cpv') { echo 'SELECTED'; } ?>
									value="cpv">CPV Costs</option>
							</select>
						</div>
						<button id="s-search" style="<?php if ($show_adv != false) { echo 'float:right;'; } ?>" type="submit" class="btn btn-xs btn-info" onclick="set_user_prefs('<?php echo $html['page']; ?>');">Set
							Preferences</button>
						<button id="s-toogleAdv" style="margin-right: 5px; float:right; <?php if ($show_adv == false) { echo 'display:none;'; } ?>" type="submit" class="btn btn-xs btn-default">More
							Options</button>
					</div>
				</div>

			</form>
		</div>
	</div>
</div>

<div class="row">
	<div class="col-xs-12">
		<div id="m-content">
			<div class="loading-stats">
				<span class="infotext">Loading stats...</span> <img
					src="<?php echo get_absolute_url();?>202-img/loader-small.gif">
			</div>
		</div>
	</div>
</div>

<script type="text/javascript">
		
		/* TIME SETTING FUNCTION */ 
		function set_user_pref_time_predefined() {

			var element = $('#user_pref_time_predefined');

			if (element.val() == 'today') {
				<?php
    
$time['from'] = mktime(0, 0, 0, date('m', time()), date('d', time()), date('Y', time()));
    $time['to'] = mktime(23, 59, 59, date('m', time()), date('d', time()), date('Y', time()));
    ?>

				$('#from').val('<?php echo date('m/d/y',$time['from']); ?>');
				$('#to').val('<?php echo date('m/d/y',$time['to']); ?>');
			}

			if (element.val() == 'yesterday') {
				<?php
    
$time['from'] = mktime(0, 0, 0, date('m', time() - 86400), date('d', time() - 86400), date('Y', time() - 86400));
    $time['to'] = mktime(23, 59, 59, date('m', time() - 86400), date('d', time() - 86400), date('Y', time() - 86400));
    ?>

				$('#from').val('<?php echo date('m/d/y',$time['from']); ?>');
				$('#to').val('<?php echo date('m/d/y',$time['to']); ?>');
			}

			if (element.val() == 'last7') {
				<?php
    
$time['from'] = mktime(0, 0, 0, date('m', time() - 86400 * 7), date('d', time() - 86400 * 7), date('Y', time() - 86400 * 7));
    $time['to'] = mktime(23, 59, 59, date('m', time()), date('d', time()), date('Y', time()));
    ?>

				$('#from').val('<?php echo date('m/d/y',$time['from']); ?>');
				$('#to').val('<?php echo date('m/d/y',$time['to']); ?>');
			}

			if (element.val() == 'last14') {
				<?php
    
$time['from'] = mktime(0, 0, 0, date('m', time() - 86400 * 14), date('d', time() - 86400 * 14), date('Y', time() - 86400 * 14));
    $time['to'] = mktime(23, 59, 59, date('m', time()), date('d', time()), date('Y', time()));
    ?>

				$('#from').val('<?php echo date('m/d/y',$time['from']); ?>');
				$('#to').val('<?php echo date('m/d/y',$time['to']); ?>');
			}

			if (element.val() == 'last30') {
				<?php
    
$time['from'] = mktime(0, 0, 0, date('m', time() - 86400 * 30), date('d', time() - 86400 * 30), date('Y', time() - 86400 * 30));
    $time['to'] = mktime(23, 59, 59, date('m', time()), date('d', time()), date('Y', time()));
    ?>

				$('#from').val('<?php echo date('m/d/y',$time['from']); ?>');
				$('#to').val('<?php echo date('m/d/y',$time['to']); ?>');
			}

			if (element.val() == 'thismonth') {
				<?php
    
$time['from'] = mktime(0, 0, 0, date('m', time()), 1, date('Y', time()));
    $time['to'] = mktime(23, 59, 59, date('m', time()), date('d', time()), date('Y', time()));
    ?>

				$('#from').val('<?php echo date('m/d/y',$time['from']); ?>');
				$('#to').val('<?php echo date('m/d/y',$time['to']); ?>');
			}

			if (element.val() == 'lastmonth') {
				<?php
    
$time['from'] = mktime(0, 0, 0, date('m', time() - 2629743), 1, date('Y', time() - 2629743));
    $time['to'] = mktime(23, 59, 59, date('m', time() - 2629743), getLastDayOfMonth(date('m', time() - 2629743), date('Y', time() - 2629743)), date('Y', time() - 2629743));
    ?>

				$('#from').val('<?php echo date('m/d/y',$time['from']); ?>');
				$('#to').val('<?php echo date('m/d/y',$time['to']); ?>');
			}

			if (element.val() == 'thisyear') {
				<?php
    
$time['from'] = mktime(0, 0, 0, 1, 1, date('Y', time()));
    $time['to'] = mktime(23, 59, 59, date('m', time()), date('d', time()), date('Y', time()));
    ?>

				$('#from').val('<?php echo date('m/d/y',$time['from']); ?>');
				$('#to').val('<?php echo date('m/d/y',$time['to']); ?>');
			}

			if (element.val() == 'lastyear') {
				<?php
    
$time['from'] = mktime(0, 0, 0, 1, 1, date('Y', time() - 31556926));
    $time['to'] = mktime(0, 0, 0, 12, getLastDayOfMonth(date('m', time() - 31556926), date('Y', time() - 31556926)), date('Y', time() - 31556926));
    ?>

				$('#from').val('<?php echo date('m/d/y',$time['from']); ?>');
				$('#to').val('<?php echo date('m/d/y',$time['to']); ?>');
			}
			
			if (element.val() == 'alltime') {
				<?php
    // for the time from, do something special select the exact date this user was registered and use that :)
    $mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
    $user_sql = "SELECT user_time_register FROM 202_users WHERE user_id='" . $mysql['user_id'] . "'";
    $user_result = $db->query($user_sql) or record_mysql_error($user_sql);
    $user_row = $user_result->fetch_assoc();
    $time['from'] = $user_row['user_time_register'];
    
    $time['from'] = mktime(0, 0, 0, date('m', $time['from']), date('d', $time['from']), date('Y', $time['from']));
    $time['to'] = mktime(23, 59, 59, date('m', time()), date('d', time()), date('Y', time()));
    ?>

				$('#from').val('<?php echo date('m/d/y',$time['from']); ?>');
				$('#to').val('<?php echo date('m/d/y',$time['to']); ?>');
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
		
	    //pass in 'refine' to the function to flag that we are on the refine pages
		load_method_of_promotion('<?php echo $html['user_pref_method_of_promotion']; ?>','refine');
		
		<?php if ($html['user_pref_landing_page_id'] != '') { ?>
			load_landing_page('<?php echo $html['user_pref_aff_campaign_id']; ?>', '<?php echo $html['user_pref_landing_page_id']; ?>', '<?php echo $html['user_pref_method_of_promotion']; ?>s');
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
<?php

}

function display_calendar2($page, $show_time, $show_adv, $show_bottom, $show_limit, $show_breakdown, $show_type, $show_cpc_or_cpv = true, $show_adv_breakdown = false)
{
    global $navigation;
    $database = DB::getInstance();
    $db = $database->getConnection();
    AUTH::set_timezone($_SESSION['user_timezone']);
    $filterEngine = new FilterEngine;

    $mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
    $user_sql = "SELECT * FROM 202_users_pref WHERE user_id=" . $mysql['user_id'];
    $user_result = _mysqli_query($user_sql);
    $user_row = $user_result->fetch_assoc();
    // print_r($user_row);
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
    $html['from'] = date('m/d/Y', $time['from']);
    $html['to'] = date('m/d/Y', $time['to']);
    $html['ip'] = htmlentities($user_row['user_pref_ip'], ENT_QUOTES, 'UTF-8');
    if($user_row['user_pref_subid'] != '0' && !empty($user_row['user_pref_subid'])){
        $html['subid'] = htmlentities($user_row['user_pref_subid'], ENT_QUOTES, 'UTF-8');
    }
    else{
        $html['subid'] = '';
    }

     
    $html['filter_name1'] = htmlentities($filterEngine->getFilter('filter_name',1), ENT_QUOTES, 'UTF-8');
    $html['filter_name2'] = htmlentities($filterEngine->getFilter('filter_name',2), ENT_QUOTES, 'UTF-8');
    $html['filter_name3'] = htmlentities($filterEngine->getFilter('filter_name',3), ENT_QUOTES, 'UTF-8');
    $html['filter_condition1'] = htmlentities($filterEngine->getFilter('filter_condition',1), ENT_QUOTES, 'UTF-8');
    $html['filter_condition2'] = htmlentities($filterEngine->getFilter('filter_condition',2), ENT_QUOTES, 'UTF-8');
    $html['filter_condition3'] = htmlentities($filterEngine->getFilter('filter_condition',3), ENT_QUOTES, 'UTF-8');
    $html['filter_value1'] = htmlentities($filterEngine->getFilter('filter_value',1), ENT_QUOTES, 'UTF-8');
    $html['filter_value2'] = htmlentities($filterEngine->getFilter('filter_value',2), ENT_QUOTES, 'UTF-8');
    $html['filter_value3'] = htmlentities($filterEngine->getFilter('filter_value',3), ENT_QUOTES, 'UTF-8');

    $html['user_pref_country_id'] = htmlentities($user_row['user_pref_country_id'], ENT_QUOTES, 'UTF-8');
    $html['user_pref_region_id'] = htmlentities($user_row['user_pref_region_id'], ENT_QUOTES, 'UTF-8');
    $html['user_pref_isp_id'] = htmlentities($user_row['user_pref_isp_id'], ENT_QUOTES, 'UTF-8');
    $html['referer'] = htmlentities($user_row['user_pref_referer'], ENT_QUOTES, 'UTF-8');
    $html['keyword'] = htmlentities($user_row['user_pref_keyword'], ENT_QUOTES, 'UTF-8');
    $html['page'] = htmlentities($page, ENT_QUOTES, 'UTF-8');
    $html['user_pref_device_id'] = htmlentities($user_row['user_pref_device_id'], ENT_QUOTES, 'UTF-8');
    $html['user_pref_browser_id'] = htmlentities($user_row['user_pref_browser_id'], ENT_QUOTES, 'UTF-8');
    $html['user_pref_platform_id'] = htmlentities($user_row['user_pref_platform_id'], ENT_QUOTES, 'UTF-8');
    ?>

<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<div id="preferences-wrapper">
			<span style="position: absolute; font-size: 12px;"><span
				class="fui-search"></span> Refine your search: </span>
			<form id="user_prefs" onsubmit="return false;"
				class="form-inline text-right" role="form">
				<div class="row">
					<div class="col-xs-12">
						<label for="from">Start date: </label>
						<div class="form-group datepicker" style="margin-right: 5px;">
							<input type="text" class="form-control input-sm" name="from"
								id="from" value="<?php echo $html['from']; ?>">
						</div>

						<label for="to">End date: </label>
						<div class="form-group datepicker">
							<input type="text" class="form-control input-sm" name="to"
								id="to" value="<?php echo $html['to']; ?>">
						</div>

						<div class="form-group">
							<label class="sr-only" for="user_pref_time_predefined">Date</label>
							<select class="form-control input-sm"
								name="user_pref_time_predefined" id="user_pref_time_predefined"
								onchange="set_user_pref_time_predefined();">
								<option value="">Custom Date</option>
								<option
									<?php if ($time['user_pref_time_predefined'] == 'today') { echo 'selected=""'; } ?>
									value="today">Today</option>
								<option
									<?php if ($time['user_pref_time_predefined'] == 'yesterday') { echo 'selected=""'; } ?>
									value="yesterday">Yesterday</option>
								<option
									<?php if ($time['user_pref_time_predefined'] == 'last7') { echo 'selected=""'; } ?>
									value="last7">Last 7 Days</option>
								<option
									<?php if ($time['user_pref_time_predefined'] == 'last14') { echo 'selected=""'; } ?>
									value="last14">Last 14 Days</option>
								<option
									<?php if ($time['user_pref_time_predefined'] == 'last30') { echo 'selected=""'; } ?>
									value="last30">Last 30 Days</option>
								<option
									<?php if ($time['user_pref_time_predefined'] == 'thismonth') { echo 'selected=""'; } ?>
									value="thismonth">This Month</option>
								<option
									<?php if ($time['user_pref_time_predefined'] == 'lastmonth') { echo 'selected=""'; } ?>
									value="lastmonth">Last Month</option>
								<option
									<?php if ($time['user_pref_time_predefined'] == 'thisyear') { echo 'selected=""'; } ?>
									value="thisyear">This Year</option>
								<option
									<?php if ($time['user_pref_time_predefined'] == 'lastyear') { echo 'selected=""'; } ?>
									value="lastyear">Last Year</option>
								<option
									<?php if ($time['user_pref_time_predefined'] == 'alltime') { echo 'selected=""'; } ?>
									value="alltime">All Time</option>
							</select>
						</div>
					</div>
				</div>

				<div class="form_seperator" style="margin: 5px 0px; padding: 1px">
					<div class="col-xs-12"></div>
				</div>

		<?php if ($navigation[1] == 'tracking202') { ?>
		<div class="row" style="text-align:left; <?php if ($show_adv == false) { echo 'display:none;'; } ?>">
					<div class="col-xs-12" style="margin-top: 5px;">
						<div class="row">
							<div class="col-xs-6">
								<label>Traffic Source/Account: </label>

								<div class="form-group">
									<img id="ppc_network_id_div_loading" class="loading"
										style="display: none;"
										src="<?php echo get_absolute_url();?>202-img/loader-small.gif" />
									<div style="margin-left: 2px;" id="ppc_network_id_div"></div>
								</div>

								<div class="form-group">
									<div id="ppc_account_id_div"></div>
								</div>
							</div>

							<div class="col-xs-6" style="text-align: right">
								<div class="row">
									<div class="col-xs-6">
										<label>Subid: </label>
										<div class="form-group">
											<input type="text" class="form-control input-sm" name="subid"
												id="subid" value="<?php echo $html['subid']; ?>" />
										</div>
									</div>
									<div class="col-xs-6">
										<label>Visitor IP: </label>
										<div class="form-group">
											<input type="text" class="form-control input-sm" name="ip"
												id="ip" value="<?php echo $html['ip']; ?>" />
										</div>
									</div>
								</div>
							</div>

							<div class="col-xs-6">
								<label>Category/Campaign: </label>
								<div class="form-group">
									<img id="aff_network_id_div_loading" class="loading"
										style="display: none;"
										src="<?php echo get_absolute_url();?>202-img/loader-small.gif" />
									<div id="aff_network_id_div"></div>
								</div>

								<div class="form-group">
									<div id="aff_campaign_id_div"></div>
								</div>
							</div>

							<div class="col-xs-6" style="text-align: right">
								<div class="row">
									<div class="col-xs-6">
										<label>Keyword: </label>
										<div class="form-group">
											<input name="keyword" id="keyword" type="text"
												class="form-control input-sm"
												value="<?php echo $html['keyword']; ?>" />
										</div>
									</div>
									<div class="col-xs-6">
										<label>Referer: </label>
										<div class="form-group">
											<input name="referer" id="referer" type="text"
												class="form-control input-sm"
												value="<?php echo $html['referer']; ?>" />
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
				<div id="more-options" style="margin-bottom: 5px; height: 87px; <?php if (($user_row['user_pref_adv'] != '1') or ($show_adv == false)) { echo 'display: none;'; } ?>">
					<div class="row" style="text-align: left;">
						<div class="col-xs-12" style="margin-top: 5px;">
							<div class="row">
								<div class="col-xs-6">
									<label>Text Ad: </label>

									<div class="form-group">
										<img id="text_ad_id_div_loading" class="loading"
											style="display: none;"
											src="<?php echo get_absolute_url();?>202-img/loader-small.gif" />
										<div id="text_ad_id_div" style="margin-left: 69px;"></div>
									</div>

									<div class="form-group">
										<img id="ad_preview_div_loading" class="loading"
											style="display: none;"
											src="<?php echo get_absolute_url();?>202-img/loader-small.gif" />
										<div id="ad_preview_div"
											style="position: absolute; top: -12px; font-size: 10px;"></div>
									</div>
								</div>

								<div class="col-xs-6" style="text-align: right">
									<div class="row">
										<div class="col-xs-6">
											<label>Device type: </label>
											<div class="form-group">
												<img id="device_id_div_loading" class="loading"
													style="right: 0px; left: 5px;"
													src="<?php echo get_absolute_url();?>202-img/loader-small.gif" />
												<div id="device_id_div" style="top: -12px; font-size: 10px;">
													<select class="form-control input-sm" name="device_id"
														id="device_id">
														<option value="0">--</option>
													</select>
												</div>
											</div>
										</div>

										<div class="col-xs-6">
											<label>Country: </label>
											<div class="form-group">
												<img id="country_id_div_loading" class="loading"
													style="right: 0px; left: 5px;"
													src="<?php echo get_absolute_url();?>202-img/loader-small.gif" />
												<div id="country_id_div"
													style="top: -12px; font-size: 10px;">
													<select class="form-control input-sm" name="country_id"
														id="country_id">
														<option value="0">--</option>
													</select>
												</div>
											</div>
										</div>
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-xs-6">
									<label>Method of Promotion: </label>
									<div class="form-group">
										<img id="method_of_promotion_div_loading" class="loading"
											style="display: none;"
											src="<?php echo get_absolute_url();?>202-img/loader-small.gif" />
										<div id="method_of_promotion_div" style="margin-left: 9px;"></div>
									</div>
								</div>

								<div class="col-xs-6" style="text-align: right">
									<div class="row">
										<div class="col-xs-6">
											<label>Browser: </label>
											<div class="form-group">
												<img id="browser_id_div_loading" class="loading"
													style="right: 0px; left: 5px;"
													src="<?php echo get_absolute_url();?>202-img/loader-small.gif" />
												<div id="browser_id_div"
													style="top: -12px; font-size: 10px;">
													<select class="form-control input-sm" name="browser_id"
														id="browser_id">
														<option value="0">--</option>
													</select>
												</div>
											</div>
										</div>
										<div class="col-xs-6">
											<label>Region: </label>
											<div class="form-group">
												<img id="region_id_div_loading" class="loading"
													style="right: 0px; left: 5px;"
													src="<?php echo get_absolute_url();?>202-img/loader-small.gif" />
												<div id="region_id_div" style="top: -12px; font-size: 10px;">
													<select class="form-control input-sm" name="region_id"
														id="region_id">
														<option value="0">--</option>
													</select>
												</div>
											</div>
										</div>
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-xs-6">
									<label>Landing Page: </label>
									<div class="form-group">
										<img id="landing_page_div_loading" class="loading"
											style="display: none;"
											src="<?php echo get_absolute_url();?>202-img/loader-small.gif" />
										<div id="landing_page_div" style="margin-left: 45px;"></div>
									</div>
								</div>

								<div class="col-xs-6" style="text-align: right">
									<div class="row">
										<div class="col-xs-6">
											<label>Platforms: </label>
											<div class="form-group">
												<img id="platform_id_div_loading" class="loading"
													style="right: 0px; left: 5px;"
													src="<?php echo get_absolute_url();?>202-img/loader-small.gif" />
												<div id="platform_id_div"
													style="top: -12px; font-size: 10px;">
													<select class="form-control input-sm" name="platform_id"
														id="platform_id">
														<option value="0">--</option>
													</select>
												</div>
											</div>
										</div>
										<div class="col-xs-6">
											<label>ISP/Carrier: </label>
											<div class="form-group">
												<img id="isp_id_div_loading" class="loading"
													style="right: 0px; left: 5px;"
													src="<?php echo get_absolute_url();?>202-img/loader-small.gif" />
												<div id="isp_id_div" style="top: -12px; font-size: 10px;">
													<select class="form-control input-sm" name="isp_id"
														id="isp_id">
														<option value="0">--</option>
													</select>
												</div>
											</div>
										</div>
									</div>
								</div>
							</div>

						</div>
					</div>

					<div class="form_seperator" style="margin: 5px 0px; padding: 1px;">
						<div class="col-xs-12"></div>
					</div>
				</div>

		<?php } ?>
		<?php if($show_adv_breakdown==true) { ?>
		<div class="row">
					<div class="col-xs-12" style="margin-top:5px; <?php if ($show_adv != false) { echo 'text-align:left;'; } ?> <?php if ($show_bottom == false) { echo 'display:none;'; } ?>">
						<label>Group By: </label>
						<div class="form-group">
							<label class="sr-only" for="user_pref_limit">Date</label> <select
								class="form-control input-sm" name="details[]">
						<?php foreach(ReportSummaryForm::getDetailArray() AS $detail_item) { ?>
							<option value="<?php echo $detail_item ?>"
									<?php echo $html['user_pref_group_1']==$detail_item ? 'selected="selected"' : ''; ?>><?php echo ReportBasicForm::translateDetailLevelById($detail_item); ?></option>
						<?php } ?>
					</select>
						</div>

						<label>Then Group By: </label>
						<div class="form-group">
							<label class="sr-only" for="user_pref_breakdown">Date</label> <select
								class="form-control input-sm" name="details[]">
								<option
									value="<?php echo ReportBasicForm::DETAIL_LEVEL_NONE; ?>"
									<?php echo $html['user_pref_group_1']==ReportBasicForm::DETAIL_LEVEL_NONE ? 'selected="selected"' : ''; ?>><?php echo ReportBasicForm::translateDetailLevelById(ReportBasicForm::DETAIL_LEVEL_NONE); ?></option>
						<?php foreach(ReportSummaryForm::getDetailArray() AS $detail_item) { ?>
							<option value="<?php echo $detail_item ?>"
									<?php echo $html['user_pref_group_2']==$detail_item ? 'selected="selected"' : ''; ?>><?php echo ReportBasicForm::translateDetailLevelById($detail_item); ?></option>
						<?php } ?>
					</select>
						</div>

						<label>Then Group By: </label>
						<div class="form-group">
							<label class="sr-only" for="user_pref_chart">Date</label> <select
								class="form-control input-sm" name="details[]">
								<option
									value="<?php echo ReportBasicForm::DETAIL_LEVEL_NONE; ?>"
									<?php echo $html['user_pref_group_1']==ReportBasicForm::DETAIL_LEVEL_NONE ? 'selected="selected"' : ''; ?>><?php echo ReportBasicForm::translateDetailLevelById(ReportBasicForm::DETAIL_LEVEL_NONE); ?></option>
						<?php foreach(ReportSummaryForm::getDetailArray() AS $detail_item) { ?>
							<option value="<?php echo $detail_item ?>"
									<?php echo $html['user_pref_group_3']==$detail_item ? 'selected="selected"' : ''; ?>><?php echo ReportBasicForm::translateDetailLevelById($detail_item); ?></option>
						<?php } ?>
					</select>
						</div>

						<label>Then Group By: </label>
						<div class="form-group">
							<label class="sr-only" for="user_pref_show">Date</label> <select
								class="form-control input-sm" name="details[]">
								<option
									value="<?php echo ReportBasicForm::DETAIL_LEVEL_NONE; ?>"
									<?php echo $html['user_pref_group_1']==ReportBasicForm::DETAIL_LEVEL_NONE ? 'selected="selected"' : ''; ?>><?php echo ReportBasicForm::translateDetailLevelById(ReportBasicForm::DETAIL_LEVEL_NONE); ?></option>
						<?php foreach(ReportBasicForm::getDetailArray() AS $detail_item) { ?>
							<option value="<?php echo $detail_item ?>"
									<?php echo $html['user_pref_group_4']==$detail_item ? 'selected="selected"' : ''; ?>><?php echo ReportBasicForm::translateDetailLevelById($detail_item); ?></option>
						<?php } ?>
					</select>
						</div>

					</div>
				</div>

				<div class="row">
					<div class="col-xs-12" style="margin-top:5px; <?php if ($show_adv != false) { echo 'text-align:left;'; } ?> <?php if ($show_bottom == false) { echo 'display:none;'; } ?>">
						<label>Filter: </label>
						<div class="form-group">
							<label class="sr-only" for="user_pref_show">Date</label> 
					<?php $filterEngine->getFilterNames('filter_name',1);?>
					<?php $filterEngine->getFilterNames('filter_condition',1);?>
					<input name="filter_value_1" id="" filter_value"" type="text"
								class="form-control input-sm"
								value="<?php echo $html['filter_value1']; ?>" />

						</div>





					</div>
				</div>
				<div class="row">
					<div class="col-xs-12" style="margin-top:5px; <?php if ($show_adv != false) { echo 'text-align:left;'; } ?> <?php if ($show_bottom == false) { echo 'display:none;'; } ?>">
						<label>Filter: </label>
						<div class="form-group">
							<label class="sr-only" for="user_pref_show">Date</label> 
					<?php $filterEngine->getFilterNames('filter_name',2);?>
					<?php $filterEngine->getFilterNames('filter_condition',2);?>
					<input name="filter_value_2" id="" filter_value"" type="text"
								class="form-control input-sm"
								value="<?php echo $html['filter_value2']; ?>" />

						</div>





					</div>
				</div>
				<div class="row">
					<div class="col-xs-12" style="margin-top:5px; <?php if ($show_adv != false) { echo 'text-align:left;'; } ?> <?php if ($show_bottom == false) { echo 'display:none;'; } ?>">
						<label>Filter: </label>
						<div class="form-group">
							<label class="sr-only" for="user_pref_show">Date</label> 
					<?php $filterEngine->getFilterNames('filter_name',3);?>
					<?php $filterEngine->getFilterNames('filter_condition',3);?>
					<input name="filter_value_3" id="" filter_value"" type="text"
								class="form-control input-sm"
								value="<?php echo $html['filter_value3']; ?>" />

						</div>





					</div>
				</div>
				<div class="row">
					<div class="col-xs-12" style="margin-top:5px; <?php if ($show_adv != false) { echo 'text-align:left;'; } ?> <?php if ($show_bottom == false) { echo 'display:none;'; } ?>">
						<label>Avg CPC: </label>
						<div class="form-group">
							<label class="sr-only" for="user_pref_show">Date</label> <input
								name="avg_cpc" id="avg_cpc" type="text"
								class="form-control input-sm"
								value="<?php echo $_SESSION['avg_cpc'];?>" />

						</div>





					</div>
				</div>
				<div class="form_seperator" style="margin: 5px 0px; padding: 1px;">
					<div class="col-xs-12"></div>
				</div>

		<?php } ?>
		<div class="row">
					<div class="col-xs-12" style="margin-top:5px; <?php if ($show_adv != false) { echo 'text-align:left;'; } ?> <?php if ($show_bottom == false) { echo 'display:none;'; } ?>">
						<label>Display: </label>
						<div class="form-group">
							<label class="sr-only" for="user_pref_limit">Date</label> <select class="form-control input-sm" name="user_pref_limit" id="user_pref_limit" style="width: auto; <?php if ($show_limit == false) { echo 'display:none;'; } ?>">
								<option
									<?php if ($user_row['user_pref_limit'] == '10') { echo 'SELECTED'; } ?>
									value="10">10</option>
								<option
									<?php if ($user_row['user_pref_limit'] == '25') { echo 'SELECTED'; } ?>
									value="25">25</option>
								<option
									<?php if ($user_row['user_pref_limit'] == '50') { echo 'SELECTED'; } ?>
									value="50">50</option>
								<option
									<?php if ($user_row['user_pref_limit'] == '75') { echo 'SELECTED'; } ?>
									value="75">75</option>
								<option
									<?php if ($user_row['user_pref_limit'] == '100') { echo 'SELECTED'; } ?>
									value="100">100</option>
								<option
									<?php if ($user_row['user_pref_limit'] == '150') { echo 'SELECTED'; } ?>
									value="150">150</option>
								<option
									<?php if ($user_row['user_pref_limit'] == '200') { echo 'SELECTED'; } ?>
									value="200">200</option>
							</select>
						</div>

						<div class="form-group">
							<label class="sr-only" for="user_pref_breakdown">Date</label> <select
								class="form-control input-sm" name="user_pref_breakdown"
								id="user_pref_breakdown"
								<?php if ($show_breakdown == false) { echo 'style="display:none;"'; } ?>>
								<option
									<?php if ($user_row['user_pref_breakdown'] == 'hour') { echo 'SELECTED'; } ?>
									value="hour">By Hour</option>
								<option
									<?php if ($user_row['user_pref_breakdown'] == 'day') { echo 'SELECTED'; } ?>
									value="day">By Day</option>
								<option
									<?php if ($user_row['user_pref_breakdown'] == 'month') { echo 'SELECTED'; } ?>
									value="month">By Month</option>
								<option
									<?php if ($user_row['user_pref_breakdown'] == 'year') { echo 'SELECTED'; } ?>
									value="year">By Year</option>
							</select>
						</div>

						<div class="form-group">
							<label class="sr-only" for="user_pref_show">Date</label> <select
								style="width: 155px;" class="form-control input-sm"
								name="user_pref_show" id="user_pref_show"
								<?php if ($show_type == false) { echo 'style="display:none;"'; } ?>>
								<option
									<?php if ($user_row['user_pref_show'] == 'all') { echo 'SELECTED'; } ?>
									value="all">Show All Clicks</option>
								<option
									<?php if ($user_row['user_pref_show'] == 'real') { echo 'SELECTED'; } ?>
									value="real">Show Real Clicks</option>
								<option
									<?php if ($user_row['user_pref_show'] == 'filtered') { echo 'SELECTED'; } ?>
									value="filtered">Show Filtered Out Clicks</option>
								<option
									<?php if ($user_row['user_pref_show'] == 'filtered_bot') { echo 'SELECTED'; } ?>
									value="filtered_bot">Show Filtered Out Bot Clicks</option>
								<option
									<?php if ($user_row['user_pref_show'] == 'leads') { echo 'SELECTED'; } ?>
									value="leads">Show Converted Clicks</option>
							</select>
						</div>

						<div class="form-group">
							<label class="sr-only" for="user_cpc_or_cpv">Date</label> <select
								class="form-control input-sm" name="user_cpc_or_cpv"
								id="user_cpc_or_cpv"
								<?php if ($show_cpc_or_cpv == false) { echo 'style="display:none;"'; } ?>>
								<option
									<?php if ($user_row['user_cpc_or_cpv'] == 'cpc') { echo 'SELECTED'; } ?>
									value="cpc">CPC Costs</option>
								<option
									<?php if ($user_row['user_cpc_or_cpv'] == 'cpv') { echo 'SELECTED'; } ?>
									value="cpv">CPV Costs</option>
							</select>
						</div>
						<button id="s-search" style="<?php if ($show_adv != false) { echo 'float:right;'; } ?>" type="submit" class="btn btn-xs btn-info" onclick="set_user_prefs('<?php echo $html['page']; ?>');">Set
							Preferences</button>
						<button id="s-toogleAdv" style="margin-right: 5px; float:right; <?php if ($show_adv == false) { echo 'display:none;'; } ?>" type="submit" class="btn btn-xs btn-default">More
							Options</button>
					</div>
				</div>

			</form>
		</div>
	</div>
</div>

<div class="row">
	<div class="col-xs-12">
		<div id="m-content">
			<div class="loading-stats">
				<span class="infotext">Loading stats...</span> <img
					src="<?php echo get_absolute_url();?>202-img/loader-small.gif">
			</div>
		</div>
	</div>
</div>

<script type="text/javascript">
		
		/* TIME SETTING FUNCTION */ 
		function set_user_pref_time_predefined() {

			var element = $('#user_pref_time_predefined');

			if (element.val() == 'today') {
				<?php
    
$time['from'] = mktime(0, 0, 0, date('m', time()), date('d', time()), date('Y', time()));
    $time['to'] = mktime(23, 59, 59, date('m', time()), date('d', time()), date('Y', time()));
    ?>

				$('#from').val('<?php echo date('m/d/y',$time['from']); ?>');
				$('#to').val('<?php echo date('m/d/y',$time['to']); ?>');
			}

			if (element.val() == 'yesterday') {
				<?php
    
$time['from'] = mktime(0, 0, 0, date('m', time() - 86400), date('d', time() - 86400), date('Y', time() - 86400));
    $time['to'] = mktime(23, 59, 59, date('m', time() - 86400), date('d', time() - 86400), date('Y', time() - 86400));
    ?>

				$('#from').val('<?php echo date('m/d/y',$time['from']); ?>');
				$('#to').val('<?php echo date('m/d/y',$time['to']); ?>');
			}

			if (element.val() == 'last7') {
				<?php
    
$time['from'] = mktime(0, 0, 0, date('m', time() - 86400 * 7), date('d', time() - 86400 * 7), date('Y', time() - 86400 * 7));
    $time['to'] = mktime(23, 59, 59, date('m', time()), date('d', time()), date('Y', time()));
    ?>

				$('#from').val('<?php echo date('m/d/y',$time['from']); ?>');
				$('#to').val('<?php echo date('m/d/y',$time['to']); ?>');
			}

			if (element.val() == 'last14') {
				<?php
    
$time['from'] = mktime(0, 0, 0, date('m', time() - 86400 * 14), date('d', time() - 86400 * 14), date('Y', time() - 86400 * 14));
    $time['to'] = mktime(23, 59, 59, date('m', time()), date('d', time()), date('Y', time()));
    ?>

				$('#from').val('<?php echo date('m/d/y',$time['from']); ?>');
				$('#to').val('<?php echo date('m/d/y',$time['to']); ?>');
			}

			if (element.val() == 'last30') {
				<?php
    
$time['from'] = mktime(0, 0, 0, date('m', time() - 86400 * 30), date('d', time() - 86400 * 30), date('Y', time() - 86400 * 30));
    $time['to'] = mktime(23, 59, 59, date('m', time()), date('d', time()), date('Y', time()));
    ?>

				$('#from').val('<?php echo date('m/d/y',$time['from']); ?>');
				$('#to').val('<?php echo date('m/d/y',$time['to']); ?>');
			}

			if (element.val() == 'thismonth') {
				<?php
    
$time['from'] = mktime(0, 0, 0, date('m', time()), 1, date('Y', time()));
    $time['to'] = mktime(23, 59, 59, date('m', time()), date('d', time()), date('Y', time()));
    ?>

				$('#from').val('<?php echo date('m/d/y',$time['from']); ?>');
				$('#to').val('<?php echo date('m/d/y',$time['to']); ?>');
			}

			if (element.val() == 'lastmonth') {
				<?php
    
$time['from'] = mktime(0, 0, 0, date('m', time() - 2629743), 1, date('Y', time() - 2629743));
    $time['to'] = mktime(23, 59, 59, date('m', time() - 2629743), getLastDayOfMonth(date('m', time() - 2629743), date('Y', time() - 2629743)), date('Y', time() - 2629743));
    ?>

				$('#from').val('<?php echo date('m/d/y',$time['from']); ?>');
				$('#to').val('<?php echo date('m/d/y',$time['to']); ?>');
			}

			if (element.val() == 'thisyear') {
				<?php
    
$time['from'] = mktime(0, 0, 0, 1, 1, date('Y', time()));
    $time['to'] = mktime(23, 59, 59, date('m', time()), date('d', time()), date('Y', time()));
    ?>

				$('#from').val('<?php echo date('m/d/y',$time['from']); ?>');
				$('#to').val('<?php echo date('m/d/y',$time['to']); ?>');
			}

			if (element.val() == 'lastyear') {
				<?php
    
$time['from'] = mktime(0, 0, 0, 1, 1, date('Y', time() - 31556926));
    $time['to'] = mktime(0, 0, 0, 12, getLastDayOfMonth(date('m', time() - 31556926), date('Y', time() - 31556926)), date('Y', time() - 31556926));
    ?>

				$('#from').val('<?php echo date('m/d/y',$time['from']); ?>');
				$('#to').val('<?php echo date('m/d/y',$time['to']); ?>');
			}
			
			if (element.val() == 'alltime') {
				<?php
    // for the time from, do something special select the exact date this user was registered and use that :)
    $mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
    $user_sql = "SELECT user_time_register FROM 202_users WHERE user_id='" . $mysql['user_id'] . "'";
    $user_result = $db->query($user_sql) or record_mysql_error($user_sql);
    $user_row = $user_result->fetch_assoc();
    $time['from'] = $user_row['user_time_register'];
    
    $time['from'] = mktime(0, 0, 0, date('m', $time['from']), date('d', $time['from']), date('Y', $time['from']));
    $time['to'] = mktime(23, 59, 59, date('m', time()), date('d', time()), date('Y', time()));
    ?>

				$('#from').val('<?php echo date('m/d/y',$time['from']); ?>');
				$('#to').val('<?php echo date('m/d/y',$time['to']); ?>');
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
		
	    //pass in 'refine' to the function to flag that we are on the refine pages
		load_method_of_promotion('<?php echo $html['user_pref_method_of_promotion']; ?>','refine');
		
		<?php if ($html['user_pref_landing_page_id'] != '') { ?>
			load_landing_page('<?php echo $html['user_pref_aff_campaign_id']; ?>', '<?php echo $html['user_pref_landing_page_id']; ?>', '<?php echo $html['user_pref_method_of_promotion']; ?>s');
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
<?php

}
function grab_timeframe()
{
    AUTH::set_timezone($_SESSION['user_timezone']);
    
    $database = DB::getInstance();
    $db = $database->getConnection();
    
    $mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
    $user_sql = "SELECT user_pref_time_predefined, user_pref_time_from, user_pref_time_to FROM 202_users_pref WHERE user_id='" . $mysql['user_id'] . "'";
    $user_result = _mysqli_query($user_sql);
    ; // ($user_sql);
    $user_row = $user_result->fetch_assoc();
    
    if (($user_row['user_pref_time_predefined'] == 'today') or ($user_row['pref_time_from'] != '')) {
        $time['from'] = mktime(0, 0, 0, date('m', time()), date('d', time()), date('Y', time()));
        $time['to'] = mktime(23, 59, 59, date('m', time()), date('d', time()), date('Y', time()));
    }
    
    if ($user_row['user_pref_time_predefined'] == 'yesterday') {
        $time['from'] = mktime(0, 0, 0, date('m', time() - 86400), date('d', time() - 86400), date('Y', time() - 86400));
        $time['to'] = mktime(23, 59, 59, date('m', time() - 86400), date('d', time() - 86400), date('Y', time() - 86400));
    }
    
    if ($user_row['user_pref_time_predefined'] == 'last7') {
        $time['from'] = mktime(0, 0, 0, date('m', time() - 86400 * 7), date('d', time() - 86400 * 7), date('Y', time() - 86400 * 7));
        $time['to'] = mktime(23, 59, 59, date('m', time()), date('d', time()), date('Y', time()));
    }
    
    if ($user_row['user_pref_time_predefined'] == 'last14') {
        $time['from'] = mktime(0, 0, 0, date('m', time() - 86400 * 14), date('d', time() - 86400 * 14), date('Y', time() - 86400 * 14));
        $time['to'] = mktime(23, 59, 59, date('m', time()), date('d', time()), date('Y', time()));
    }
    
    if ($user_row['user_pref_time_predefined'] == 'last30') {
        $time['from'] = mktime(0, 0, 0, date('m', time() - 86400 * 30), date('d', time() - 86400 * 30), date('Y', time() - 86400 * 30));
        $time['to'] = mktime(23, 59, 59, date('m', time()), date('d', time()), date('Y', time()));
    }
    
    if ($user_row['user_pref_time_predefined'] == 'thismonth') {
        $time['from'] = mktime(0, 0, 0, date('m', time()), 1, date('Y', time()));
        $time['to'] = mktime(23, 59, 59, date('m', time()), date('d', time()), date('Y', time()));
    }
    
    if ($user_row['user_pref_time_predefined'] == 'lastmonth') {
        $time['from'] = mktime(0, 0, 0, date('m', time() - 2629743), 1, date('Y', time() - 2629743));
        $time['to'] = mktime(23, 59, 59, date('m', time() - 2629743), getLastDayOfMonth(date('m', time() - 2629743), date('Y', time() - 2629743)), date('Y', time() - 2629743));
    }
    
    if ($user_row['user_pref_time_predefined'] == 'thisyear') {
        $time['from'] = mktime(0, 0, 0, 1, 1, date('Y', time()));
        $time['to'] = mktime(23, 59, 59, date('m', time()), date('d', time()), date('Y', time()));
    }
    
    if ($user_row['user_pref_time_predefined'] == 'lastyear') {
        $time['from'] = mktime(0, 0, 0, 1, 1, date('Y', time() - 31556926));
        $time['to'] = mktime(0, 0, 0, 12, getLastDayOfMonth(date('m', time() - 31556926), date('Y', time() - 31556926)), date('Y', time() - 31556926));
    }
    
    if ($user_row['user_pref_time_predefined'] == 'alltime') {
        
        // for the time from, do something special select the exact date this user was registered and use that :)
        $mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
        $user2_sql = "SELECT user_time_register FROM 202_users WHERE user_id='" . $mysql['user_id'] . "'";
        $user2_result = $db->query($user2_sql) or record_mysql_error($user2_sql);
        $user2_row = $user2_result->fetch_assoc();
        $time['from'] = $user2_row['user_time_register'];
        
        $time['from'] = mktime(0, 0, 0, date('m', $time['from']), date('d', $time['from']), date('Y', $time['from']));
        $time['to'] = mktime(23, 59, 59, date('m', time()), date('d', time()), date('Y', time()));
    }
    
    if ($user_row['user_pref_time_predefined'] == '') {
        $time['from'] = $user_row['user_pref_time_from'];
        $time['to'] = $user_row['user_pref_time_to'];
    }
    
    $time['user_pref_time_predefined'] = $user_row['user_pref_time_predefined'];
    return $time;
}

function getLastDayOfMonth($month, $year)
{
    return date("d", mktime(0, 0, 0, $month + 1, 0, $year));
}

function getTrackingDomain()
{
    $database = DB::getInstance();
    $db = $database->getConnection();
    $tracking_domain_sql = "
		SELECT
			`user_tracking_domain`
		FROM
			`202_users_pref`
		WHERE
			`user_id`='" . $db->real_escape_string($_SESSION['user_id']) . "'
	";
    $tracking_domain_result = _mysqli_query($tracking_domain_sql); // ($user_sql);
    $tracking_domain_row = $tracking_domain_result->fetch_assoc();
    $tracking_domain = $_SERVER['SERVER_NAME'];
    if (strlen($tracking_domain_row['user_tracking_domain']) > 0) {
        $tracking_domain = $tracking_domain_row['user_tracking_domain'];
    }
    return $tracking_domain;
}

// the above, if true, are options to turn on specific filtering techniques.
function query($command, $db_table, $pref_time, $pref_adv, $pref_show, $pref_order, $offset, $pref_limit, $count, $isspy = false)
{
    $database = DB::getInstance();
    if($isspy){
        $db = $database->getConnectionro();
    }
    else{
        $db = $database->getConnection();
    }
    
    
    // grab user preferences
    $mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
    $user_sql = "SELECT * FROM 202_users_pref WHERE user_id='" . $mysql['user_id'] . "'";
    $user_result = _mysqli_query($user_sql); // ($user_sql);
    $user_row = $user_result->fetch_assoc();
    
    $count_sql = "SELECT count(*) AS count FROM 202_dataengine AS 2c ";
    $theWheres = '';
    
    // do extra joins if advance selector is enabled
    if ($pref_adv == true) {
        
        // if Traffic Source lookup with no individual Traffic Source account lookup do this
        if ($user_row['user_pref_ppc_network_id'] and ! ($user_row['user_pref_ppc_account_id'])) {
            
            if (! preg_match('/202_ppc_accounts/', $command)) {
                $command .= " LEFT JOIN 202_ppc_accounts AS 2pa ON (2c.ppc_account_id = 2pa.ppc_account_id) ";
            }
            
            if (! preg_match('/202_ppc_networks/', $command)) {
                $command .= " LEFT JOIN 202_ppc_networks AS 2pn ON (2pa.ppc_network_id = 2pn.ppc_network_id) ";
            }
            
            $theWheres .= " LEFT JOIN 202_ppc_accounts AS 2pa ON (2c.ppc_account_id = 2pa.ppc_account_id) ";
            $theWheres .= " LEFT JOIN 202_ppc_networks AS 2pn ON (2pa.ppc_network_id = 2pn.ppc_network_id) ";
        }
        
        // if Category lookup with no individual  campaign lookup do this
        if ($user_row['user_pref_aff_network_id'] and ! ($user_row['user_pref_aff_campaign_id'])) {
            
            if (! preg_match('/202_aff_campaigns/', $command)) {
                $command .= " LEFT JOIN 202_aff_campaigns AS 2ac ON (2c.aff_campaign_id = 2ac.aff_campaign_id) ";
            }
            
            if (! preg_match('/202_aff_networks/', $command)) {
                $command .= " LEFT JOIN 202_aff_networks AS 2an ON (2ac.aff_network_id = 2an.aff_network_id) ";
            }
            
            $theWheres .= " LEFT JOIN 202_aff_campaigns AS 2ac ON (2c.aff_campaign_id = 2ac.aff_campaign_id) ";
            $theWheres .= " LEFT JOIN 202_aff_networks AS 2an ON (2ac.aff_network_id = 2an.aff_network_id) ";
        }
        
        // if domain lookup
        if ($user_row['user_pref_referer']) {
            
            if (! preg_match('/202_clicks_site/', $command)) {
                $command .= " LEFT JOIN 202_clicks_site AS 2cs ON (2c.click_id = 2cs.click_id) ";
            }
            
            if (! preg_match('/202_site_urls/', $command)) {
                $command .= " LEFT JOIN 202_site_urls AS 2su ON (2cs.click_referer_site_url_id = 2su.site_url_id) ";
            }
            
            if (! preg_match('/202_site_domains/', $command)) {
                $command .= " LEFT JOIN 202_site_domains AS 2sd ON (2su.site_domain_id = 2sd.site_domain_id) ";
            }
            $count_sql .= " LEFT JOIN 202_clicks_site AS 2cs ON (2c.click_id = 2cs.click_id) ";
            $count_sql .= " LEFT JOIN 202_site_urls AS 2su ON (2cs.click_referer_site_url_id = 2su.site_url_id) ";
            // $count_sql .= " LEFT JOIN 202_site_domains AS 2sd ON (2su.site_domain_id = 2sd.site_domain_id) ";
            
            $theWheres .= " LEFT JOIN 202_clicks_site AS 2cs ON (2c.click_id = 2cs.click_id) ";
            $theWheres .= " LEFT JOIN 202_site_urls AS 2su ON (2cs.click_referer_site_url_id = 2su.site_url_id) ";
            // $theWheres .= " LEFT JOIN 202_site_domains AS 2sd ON (2su.site_domain_id = 2sd.site_domain_id) ";
        }
        
        // if there is a keyword lookup, and we have not joined the 202 keywords table. do so now
        if ($user_row['user_pref_keyword']) {
            if (! preg_match('/202_keywords/', $command)) {
                $command .= " LEFT JOIN 202_keywords AS 2k ON (2ca.keyword_id = 2k.keyword_id) ";
            }
            $count_sql .= " LEFT JOIN 202_keywords AS 2k ON (2c.keyword_id = 2k.keyword_id) ";
        }
        
        // if there is a ip lookup, and we have not joined the 202 ip table. do so now
        if ($user_row['user_pref_ip']) {
            if (! preg_match('/202_ips/', $command)) {
                $command .= " LEFT JOIN 202_ips AS 2i ON (2ca.ip_id = 2i.ip_id) ";
            }
            $count_sql .= " LEFT JOIN 202_ips AS 2i ON (2c.ip_id = 2i.ip_id) ";
        }
        
        // if there is a country lookup, and we have not joined the 202 country table. do so now
        if ($user_row['user_pref_country_id'] and ! preg_match('/202_locations_country/', $command)) {
            $command .= " LEFT JOIN 202_locations_country AS 2cy ON (2ca.country_id = 2cy.country_id) ";
        }
        
        // if there is a region lookup, and we have not joined the 202 region table. do so now
        if ($user_row['user_pref_region_id'] and ! preg_match('/202_locations_region/', $command)) {
            $command .= " LEFT JOIN 202_locations_region AS 2rg ON (2ca.region_id = 2rg.region_id) ";
        }
        
        // if there is a isp lookup, and we have not joined the 202 isp table. do so now
        if ($user_row['user_pref_isp_id'] and ! preg_match('/202_locations_isp/', $command)) {
            $command .= " LEFT JOIN 202_locations_isp AS 2is ON (2ca.isp_id = 2is.isp_id) ";
        }
        
        // if there is a device lookup, and we have not joined the 202 device table. do so now
        if ($user_row['user_pref_device_id']) {
            if (! preg_match('/202_device_models/', $command)) {
                $command .= " LEFT JOIN 202_device_models AS 2d ON (2ca.device_id = 2d.device_id) ";
            }
            
            $count_sql .= " LEFT JOIN 202_device_models AS 2d ON (2c.device_id = 2d.device_id) ";
        }
        
        // if there is a browser lookup, and we have not joined the 202 browser table. do so now
        if ($user_row['user_pref_browser_id'] and ! preg_match('/202_browsers/', $command)) {
            $command .= " LEFT JOIN 202_browsers AS 2b ON (2ca.browser_id = 2b.browser_id) ";
        }
        
        // if there is a platform lookup, and we have not joined the 202 platform table. do so now
        if ($user_row['user_pref_platform_id'] and ! preg_match('/202_platforms/', $command)) {
            $command .= " LEFT JOIN 202_platforms AS 2p ON (2ca.platform_id = 2p.platform_id) ";
        }
    }
    
    if($_SESSION['publisher']==false){ //user is able to see all camapigns
    $click_sql = $command . " WHERE $db_table.user_id!='0' ";
    $count_where .= " WHERE $db_table.user_id!='0' ";
    }
    else{
        $click_sql = $command . " WHERE $db_table.user_id='" . $_SESSION['user_own_id'] . "' "; //user can only see thier campaigns
        $count_where .= " WHERE $db_table.user_id='" .$_SESSION['user_own_id'] . "' ";
        
    }
    if ($user_row['user_pref_subid']) {
        $mysql['user_landing_subid'] = $db->real_escape_string($user_row['user_pref_subid']);
        $click_sql .= " AND      2c.click_id='" . $mysql['user_landing_subid'] . "'";
    }
    
    // set show preferences
    if ($pref_show == true) {
        if ($user_row['user_pref_show'] == 'filtered') {
            $click_sql .= " AND click_filtered='1' ";
            $count_where .= " AND click_filtered='1' ";
        } elseif ($user_row['user_pref_show'] == 'real') {
            $click_sql .= " AND click_filtered='0' ";
            $count_where .= " AND click_filtered='0' ";
        } elseif ($user_row['user_pref_show'] == 'leads') {
            $click_sql .= " AND click_filtered='0' AND click_lead='1' ";
            $count_where .= " AND click_filtered='0' AND click_lead='1' ";
        } elseif ($user_row['user_pref_show'] == 'filtered_bot') {
            $click_sql .= " AND click_bot='1'";
            $count_where .= " AND click_bot='1'";
        }
    }
    
    // set advanced preferences
    if ($pref_adv == true) {
        if ($user_row['user_pref_ppc_network_id'] and ! ($user_row['user_pref_ppc_account_id'])) {
            $mysql['user_pref_ppc_network_id'] = $db->real_escape_string($user_row['user_pref_ppc_network_id']);
            if ($user_row['user_pref_ppc_network_id'] == '16777215') {
            	$click_sql .= "  AND      2pn.ppc_network_id IS NULL";
            	$count_where .= "  AND      ppc_network_id IS NULL";
            } else {
	            $click_sql .= "  AND      2pn.ppc_network_id='" . $mysql['user_pref_ppc_network_id'] . "'";
	            $count_where .= "  AND      ppc_network_id='" . $mysql['user_pref_ppc_network_id'] . "'";
	        }
        }
        
        if ($user_row['user_pref_ppc_account_id']) {
            $mysql['user_pref_ppc_account_id'] = $db->real_escape_string($user_row['user_pref_ppc_account_id']);
            $click_sql .= " AND      2c.ppc_account_id='" . $mysql['user_pref_ppc_account_id'] . "'";
            $count_where .= " AND      2c.ppc_account_id='" . $mysql['user_pref_ppc_account_id'] . "'";
        }
        
        if ($user_row['user_pref_aff_network_id'] and ! $user_row['user_pref_aff_campaign_id']) {
            
            $mysql['user_pref_aff_network_id'] = $db->real_escape_string($user_row['user_pref_aff_network_id']);
            $click_sql .= "  AND      2an.aff_network_id='" . $mysql['user_pref_aff_network_id'] . "'";
            $count_where .= "  AND      2c.aff_network_id='" . $mysql['user_pref_aff_network_id'] . "'";
        }
        
        if ($user_row['user_pref_aff_campaign_id']) {
            $mysql['user_pref_aff_campaign_id'] = $db->real_escape_string($user_row['user_pref_aff_campaign_id']);
            $click_sql .= " AND      2c.aff_campaign_id='" . $mysql['user_pref_aff_campaign_id'] . "'";
            $count_where .= " AND      2c.aff_campaign_id='" . $mysql['user_pref_aff_campaign_id'] . "'";
        }
        if ($user_row['user_pref_text_ad_id']) {
            $mysql['user_pref_text_ad_id'] = $db->real_escape_string($user_row['user_pref_text_ad_id']);
            $click_sql .= " AND      2ca.text_ad_id='" . $mysql['user_pref_text_ad_id'] . "'";
            $count_where .= " AND      2c.text_ad_id='" . $mysql['user_pref_text_ad_id'] . "'";
        }
        if ($user_row['user_pref_method_of_promotion'] != '0') {
            if ($user_row['user_pref_method_of_promotion'] == 'directlink') {
                $click_sql .= " AND      2c.landing_page_id=''";
                $count_where .= " AND      2c.landing_page_id=''";
            } elseif ($user_row['user_pref_method_of_promotion'] == 'landingpage') {
                $click_sql .= " AND      2c.landing_page_id!=''";
                $count_where .= " AND      2c.landing_page_id!=''";
            }
        }
        
        if ($user_row['user_pref_landing_page_id']) {
            $mysql['user_landing_page_id'] = $db->real_escape_string($user_row['user_pref_landing_page_id']);
            $click_sql .= " AND      2c.landing_page_id='" . $mysql['user_landing_page_id'] . "'";
            $count_where .= " AND      2c.landing_page_id='" . $mysql['user_landing_page_id'] . "'";
        }
        
        if ($user_row['user_pref_country_id']) {
            $mysql['user_pref_country_id'] = $db->real_escape_string($user_row['user_pref_country_id']);
            $click_sql .= " AND      2ca.country_id=" . $mysql['user_pref_country_id'];
            $count_where .= " AND      2c.country_id=" . $mysql['user_pref_country_id'];
        }
        
        if ($user_row['user_pref_region_id']) {
            $mysql['user_pref_region_id'] = $db->real_escape_string($user_row['user_pref_region_id']);
            $click_sql .= " AND      2ca.region_id=" . $mysql['user_pref_region_id'];
            $count_where .= " AND      2c.region_id=" . $mysql['user_pref_region_id'];
        }
        
        if ($user_row['user_pref_isp_id']) {
            $mysql['user_pref_isp_id'] = $db->real_escape_string($user_row['user_pref_isp_id']);
            $click_sql .= " AND      2is.isp_id=" . $mysql['user_pref_isp_id'];
            $count_where .= " AND      2c.isp_id=" . $mysql['user_pref_isp_id'];
        }
        
        if ($user_row['user_pref_referer']) {
            $mysql['user_pref_referer'] = $db->real_escape_string($user_row['user_pref_referer']);
            $click_sql .= " AND 2sd.site_domain_host LIKE '%" . $mysql['user_pref_referer'] . "%'";
            $count_where .= " AND 2su.site_url_id in (select site_url_id from 202_site_urls where site_url_address like '%" . $mysql['user_pref_referer'] . "%')";
            // $count_where .= " AND 2su.site_url_id in (SELECT distinct 2de.click_referer_site_url_id FROM 202_dataengine as 2de LEFT JOIN 202_site_urls ON (2de.click_referer_site_url_id = site_url_id) WHERE site_url_address LIKE '%".$mysql['user_pref_referer']."%')";
        }
        
        if ($user_row['user_pref_keyword']) {
            $mysql['user_pref_keyword'] = $db->real_escape_string($user_row['user_pref_keyword']);
            $click_sql .= " AND 2k.keyword_id in (SELECT keyword_id from 202_keywords where keyword LIKE CONVERT( _utf8 '%" . $mysql['user_pref_keyword'] . "%' USING utf8 )
							COLLATE utf8_general_ci) ";
            $count_where .= " AND 2k.keyword_id in (SELECT keyword_id from 202_keywords where keyword LIKE CONVERT( _utf8 '%" . $mysql['user_pref_keyword'] . "%' USING utf8 )
							COLLATE utf8_general_ci) ";
        }
        
        if ($user_row['user_pref_ip']) {
            $mysql['user_pref_ip'] = $db->real_escape_string($user_row['user_pref_ip']);
            $click_sql .= " AND 2i.ip_address LIKE '%" . $mysql['user_pref_ip'] . "%'";
            $count_where .= " AND 2i.ip_address LIKE '%" . $mysql['user_pref_ip'] . "%'";
        }
        
        if ($user_row['user_pref_device_id']) {
            $mysql['user_pref_device_id'] = $db->real_escape_string($user_row['user_pref_device_id']);
            $click_sql .= " AND      2d.device_type=" . $mysql['user_pref_device_id'];
            $count_where .= " AND      2d.device_type=" . $mysql['user_pref_device_id'];
        }
        
        if ($user_row['user_pref_browser_id']) {
            $mysql['user_pref_browser_id'] = $db->real_escape_string($user_row['user_pref_browser_id']);
            $click_sql .= " AND      2b.browser_id=" . $mysql['user_pref_browser_id'];
            $count_where .= " AND      2c.browser_id=" . $mysql['user_pref_browser_id'];
        }
        
        if ($user_row['user_pref_platform_id']) {
            $mysql['user_pref_platform_id'] = $db->real_escape_string($user_row['user_pref_platform_id']);
            $click_sql .= " AND      2p.platform_id=" . $mysql['user_pref_platform_id'];
            $count_where .= " AND      2c.platform_id=" . $mysql['user_pref_platform_id'];
        }
    }
    
    // set time preferences
    if ($pref_time == true) {
        $time = grab_timeframe();
        
        $mysql['from'] = $db->real_escape_string($time['from']);
        $mysql['to'] = $db->real_escape_string($time['to']);
        if ($mysql['from'] != '') {
            $click_sql .= " AND click_time > " . $mysql['from'] . " ";
            $count_where .= " AND click_time > " . $mysql['from'] . " ";
        }
        if ($mysql['to'] != '') {
            $click_sql .= " AND click_time < " . $mysql['to'] . " ";
            $count_where .= " AND click_time < " . $mysql['to'] . " ";
        }
    }
    
    if ($isspy) {
        $from = time() - 86400;
        $click_sql .= " AND click_time > " . $from . " ";
    }
    // set limit preferences
    if ($pref_order == true) {
        $click_sql .= $pref_order;
    }
    
    // only if we want to count stuff like the click history clicks do we need to do any of the stuff below.
    if ($count == true) {
        if ($isspy)
            $count_sql = "select count(*) as count from 202_clicks_spy";
        else
            $count_sql = $count_sql . $count_where;
        
        if ($mysql['user_landing_subid']) {
            $join= " AND 2c.";
            if($isspy){
                $join = " WHERE ";
            }
                
            $count_sql .= $join."click_id='" . $mysql['user_landing_subid'] . "'";
        }
        
        if ($pref_limit == true) {
            $count_sql .= " LIMIT " . $pref_limit;
        }
        
        // before it limits, we want to know the TOTAL number of rows
        $count_result = _mysqli_query($count_sql);
        $count_row = $count_result->fetch_assoc();
        $rows = $count_row['count'];
        // $rows=1;
        
        // only if there is a limit set, run this code
        if ($pref_limit == true) {
            
            // rows is the total count of rows in this query.
            $query['rows'] = $rows;
            $query['offset'] = $offset;
            
            if ((is_numeric($offset) and ($pref_limit == true)) or ($pref_limit == true)) {
                $click_sql .= " LIMIT ";
            }
            
            if (is_numeric($offset) and ($pref_limit == true)) {
                $mysql['offset'] = $db->real_escape_string($offset * $user_row['user_pref_limit']);
                $click_sql .= $mysql['offset'] . ",";
                
                // declare starting row number
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
                
                // declare the number of pages
                $query['pages'] = ceil($query['rows'] / $user_row['user_pref_limit']) + 1;
                
                // declare end starting row number
                $query['to'] = ($query['from'] + $mysql['user_pref_limit']) - 1;
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
    } else {
        // only if there is a limit set, run this code
        if ($pref_limit != false) {
            
            // rows is the total count of rows in this query.
            $query['rows'] = $rows;
            $query['offset'] = $offset;
            
            if ((is_numeric($offset) and ($pref_limit == true)) or ($pref_limit == true)) {
                $click_sql .= " LIMIT ";
            }
            
            if (is_numeric($offset) and ($pref_limit == true)) {
                $mysql['offset'] = $db->real_escape_string($offset * $user_row['user_pref_limit']);
                $click_sql .= $mysql['offset'] . ",";
                
                // declare starting row number
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
                
                // declare the number of pages
                $query['pages'] = ceil($query['rows'] / $user_row['user_pref_limit']) + 1;
                
                // declare end starting row number
                $query['to'] = ($query['from'] + $user_row['user_pref_limit']) - 1;
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
    
    // check if using dataengine
    
    if (stripos($click_sql, "202_dataengine")) {
        $click_sql = str_replace("2ca.", "2c.", $click_sql);
    }
    $query['click_sql'] = $click_sql;
     
    return $query;
}

function pcc_network_icon($ppc_network_name, $ppc_account_name)
{
    // 7search
    if ((preg_match("/7search/i", $ppc_network_name)) or (preg_match("/7 search/i", $ppc_network_name))) {
        $ppc_network_icon = '7search.ico';
    }
    
    // adbrite
    if (preg_match("/adbrite/i", $ppc_network_name)) {
        $ppc_network_icon = 'adbrite.ico';
    }
    
    // adoori
    if (preg_match("/adoori/i", $ppc_network_name)) {
        $ppc_network_icon = 'adoori.ico';
    }
    
    // adTegrity
    if ((preg_match("/adtegrity/i", $ppc_network_name)) or (preg_match("/ad tegrity/i", $ppc_network_name))) {
        $ppc_network_icon = 'adtegrity.png';
    }
    
    // ask
    if (preg_match("/ask/i", $ppc_network_name)) {
        $ppc_network_icon = 'ask.ico';
    }
    
    // adblade
    if ((preg_match("/adblade/i", $ppc_network_name)) or (preg_match("/ad blade/i", $ppc_network_name))) {
        $ppc_network_icon = 'adblade.ico';
    }
    
    // adsonar
    if ((preg_match("/adsonar/i", $ppc_network_name)) or (preg_match("/ad sonar/i", $ppc_network_name)) or (preg_match("/quigo/i", $ppc_network_name))) {
        $ppc_network_icon = 'adsonar.png';
    }
    
    // marchex
    if ((preg_match("/marchex/i", $ppc_network_name)) or (preg_match("/goclick/i", $ppc_network_name))) {
        $ppc_network_icon = 'marchex.png';
    }
    
    // bidvertiser
    if (preg_match("/bidvertiser/i", $ppc_network_name)) {
        $ppc_network_icon = 'bidvertiser.gif';
    }
    
    // enhance
    if (preg_match("/enhance/i", $ppc_network_name)) {
        $ppc_network_icon = 'enhance.ico';
    }
    
    // facebook
    if ((preg_match("/facebook/i", $ppc_network_name)) or (preg_match("/fb/i", $ppc_network_name))) {
        $ppc_network_icon = 'facebook.ico';
    }
    
    // findology
    if (preg_match("/findology/i", $ppc_network_name)) {
        $ppc_network_icon = 'findology.png';
    }
    
    // google
    if ((preg_match("/google/i", $ppc_network_name)) or (preg_match("/adwords/i", $ppc_network_name))) {
        $ppc_network_icon = 'google.ico';
    }

    // instagram
    if (preg_match("/instagram/i", $ppc_network_name)) {
        $ppc_network_icon = 'instagram.ico';
    }
    
    // kanoodle
    if (preg_match("/kanoodle/i", $ppc_network_name)) {
        $ppc_network_icon = 'kanoodle.ico';
    }
    
    // looksmart
    if (preg_match("/looksmart/i", $ppc_network_name)) {
        $ppc_network_icon = 'looksmart.gif';
    }
    
    // hi5
    if ((preg_match("/hi5/i", $ppc_network_name)) or (preg_match("/hi 5/i", $ppc_network_name))) {
        $ppc_network_icon = 'hi5.ico';
    }
    
    // miva
    if ((preg_match("/miva/i", $ppc_network_name)) or (preg_match("/searchfeed/i", $ppc_network_name))) {
        $ppc_network_icon = 'miva.ico';
    }

    // mailchimp
    if ((preg_match("/mailchimp/i", $ppc_network_name)) or (preg_match("/searchfeed/i", $ppc_network_name))) {
        $ppc_network_icon = 'mailchimp.ico';
    }
    
    // msn
    if ((preg_match("/microsoft/i", $ppc_network_name)) or (preg_match("/MSN/i", $ppc_network_name)) or (preg_match("/bing/i", $ppc_network_name)) or (preg_match("/adcenter/i", $ppc_network_name))) {
        $ppc_network_icon = 'msn.ico';
    }
    
    // pinterest
    if ((preg_match("/pinterest/i", $ppc_network_name))) {
        $ppc_network_icon = 'pinterest.ico';
    }
    
    // pulse360
    if ((preg_match("/pulse360/i", $ppc_network_name)) or (preg_match("/pulse 360/i", $ppc_network_name))) {
        $ppc_network_icon = 'pulse360.ico';
    }
    
    // quora
    if (preg_match("/quora/i", $ppc_network_name)) {
        $ppc_network_icon = 'quora.ico';
    }

    // snapchat
    if (preg_match("/snapchat/i", $ppc_network_name)) {
        $ppc_network_icon = 'snapchat.ico';
    }
    // search123
    if ((preg_match("/search123/i", $ppc_network_name)) or (preg_match("/search 123/i", $ppc_network_name))) {
        $ppc_network_icon = 'google.ico';
    }
    
    // searchfeed
    if (preg_match("/searchfeed/i", $ppc_network_name)) {
        $ppc_network_icon = 'searchfeed.gif';
    }
    
    // yahoo
    if ((preg_match("/yahoo/i", $ppc_network_name)) or (preg_match("/YSM/i", $ppc_network_name))) {
        $ppc_network_icon = 'yahoo.ico';
    }
    
    // mediatraffic
    if ((preg_match("/mediatraffic/i", $ppc_network_name)) or (preg_match("/media traffic/i", $ppc_network_name))) {
        $ppc_network_icon = 'mediatraffic.png';
    }
    
    // mochi
    if ((preg_match("/mochi/i", $ppc_network_name)) or (preg_match("/mochimedia/i", $ppc_network_name)) or (preg_match("/mochi media/i", $ppc_network_name))) {
        $ppc_network_icon = 'mochi.ico';
    }
    
    // myspace
    if ((preg_match("/myspace/i", $ppc_network_name)) or (preg_match("/my space/i", $ppc_network_name)) or (preg_match("/myads/i", $ppc_network_name)) or (preg_match("/my ads/i", $ppc_network_name))) {
        $ppc_network_icon = 'myspace.ico';
    }
    
    // fox audience network
    if (preg_match("/fox/i", $ppc_network_name)) {
        $ppc_network_icon = 'foxnetwork.ico';
    }
    
    // adsdaq
    if (preg_match("/adsdaq/i", $ppc_network_name)) {
        $ppc_network_icon = 'adsdaq.png';
    }
    
    // twitter
    if (preg_match("/twitter/i", $ppc_network_name)) {
        $ppc_network_icon = 'twitter.ico';
    }
    
    // amazon
    if (preg_match("/amazon/i", $ppc_network_name)) {
        $ppc_network_icon = 'amazon.ico';
    }
    
    // adengage
    if ((preg_match("/adengage/i", $ppc_network_name)) or (preg_match("/ad engage/i", $ppc_network_name))) {
        $ppc_network_icon = 'adengage.ico';
    }
    
    // adtoll
    if ((preg_match("/adtoll/i", $ppc_network_name)) or (preg_match("/ad toll/i", $ppc_network_name))) {
        $ppc_network_icon = 'adtoll.ico';
    }
    
    // ezanga
    if ((preg_match("/ezangag/i", $ppc_network_name)) or (preg_match("/e zanga/i", $ppc_network_name))) {
        $ppc_network_icon = 'ezanga.ico';
    }
    
    // aol
    if ((preg_match("/aol/i", $ppc_network_name)) or (preg_match("/quigo/i", $ppc_network_name))) {
        $ppc_network_icon = 'aol.ico';
    }
    
    // aol
    if ((preg_match("/revtwt/i", $ppc_network_name)) or (preg_match("/rev twt/i", $ppc_network_name))) {
        $ppc_network_icon = 'revtwt.ico';
    }
    
    // advertising.com
    if (preg_match("/advertising.com/i", $ppc_network_name)) {
        $ppc_network_icon = 'advertising.com.ico';
    }
    
    // advertise.com
    if (preg_match("/advertise.com/i", $ppc_network_name)) {
        $ppc_network_icon = 'advertise.com.gif';
    }
    
    // adready
    if ((preg_match("/adready/i", $ppc_network_name)) or (preg_match("/ad ready/i", $ppc_network_name))) {
        $ppc_network_icon = 'adready.ico';
    }
    
    // abc search
    if ((preg_match("/abcsearch/i", $ppc_network_name)) or (preg_match("/abc search/i", $ppc_network_name))) {
        $ppc_network_icon = 'abcsearch.png';
    }
    
    // abc search
    if ((preg_match("/megaclick/i", $ppc_network_name)) or (preg_match("/mega click/i", $ppc_network_name))) {
        $ppc_network_icon = 'megaclick.ico';
    }
    
    // etology
    if (preg_match("/etology/i", $ppc_network_name)) {
        $ppc_network_icon = 'etology.ico';
    }
    
    // youtube
    if ((preg_match("/youtube/i", $ppc_network_name)) or (preg_match("/you tube/i", $ppc_network_name))) {
        $ppc_network_icon = 'youtube.ico';
    }
    
    // social media
    if ((preg_match("/socialmedia/i", $ppc_network_name)) or (preg_match("/social media/i", $ppc_network_name))) {
        $ppc_network_icon = 'socialmedia.ico';
    }
    
    // zango
    if ((preg_match("/zango/i", $ppc_network_name)) or (preg_match("/leadimpact/i", $ppc_network_name)) or (preg_match("/lead impact/i", $ppc_network_name))) {
        $ppc_network_icon = 'zango.ico';
    }
    
    // jema media
    if ((preg_match("/jema media/i", $ppc_network_name)) or (preg_match("/jemamedia/i", $ppc_network_name))) {
        $ppc_network_icon = 'jemamedia.png';
    }
    
    // direct cpv
    if ((preg_match("/directcpv/i", $ppc_network_name)) or (preg_match("/direct cpv/i", $ppc_network_name))) {
        $ppc_network_icon = 'directcpv.png';
    }
    
    // linksador
    if ((preg_match("/linksador/i", $ppc_network_name))) {
        $ppc_network_icon = 'linksador.png';
    }
    
    // adon network
    if ((preg_match("/adonnetwork/i", $ppc_network_name)) or (preg_match("/adon network/i", $ppc_network_name)) or (preg_match("/Adon/i", $ppc_network_name)) or (preg_match("/ad-on/i", $ppc_network_name))) {
        $ppc_network_icon = 'adonnetwork.ico';
    }
    
    // plenty of fish
    if ((preg_match("/plentyoffish/i", $ppc_network_name)) or (preg_match("/plenty of fish/i", $ppc_network_name)) or (preg_match("/pof/i", $ppc_network_name))) {
        $ppc_network_icon = 'plentyoffish.ico';
    }
    
    // clicksor
    if (preg_match("/clicksor/i", $ppc_network_name)) {
        $ppc_network_icon = 'clicksor.ico';
    }
    
    // traffic vance
    if ((preg_match("/trafficvance/i", $ppc_network_name)) or (preg_match("/traffic vance/i", $ppc_network_name))) {
        $ppc_network_icon = 'trafficvance.ico';
    }
    
    // adknowledge
    if ((preg_match("/adknowledge/i", $ppc_network_name)) or (preg_match("/bidsystem/i", $ppc_network_name)) or (preg_match("/bid system/i", $ppc_network_name)) or (preg_match("/cubics/i", $ppc_network_name))) {
        $ppc_network_icon = 'adknowledge.ico';
    }
    
    //admob
    if ((preg_match("/admob/i", $ppc_network_name)) or (preg_match("/ad mob/i", $ppc_network_name))) {
        $ppc_network_icon = 'admob.ico';
    }
    
    //adside
    if ((preg_match("/adside/i", $ppc_network_name)) or (preg_match("/ad side/i", $ppc_network_name))) {
        $ppc_network_icon = 'adside.ico';
    }
    
    //linkedin
    if ((preg_match("/linkedin/i", $ppc_network_name)) or (preg_match("/ad side/i", $ppc_network_name))) {
        $ppc_network_icon = 'linkedin.ico';
    }

    // unknown
    if (! isset($ppc_network_icon)) {
        $ppc_network_icon = 'unknown.gif';
    }
    
    $html['ppc_network_icon'] = '<img src="' . get_absolute_url() . '202-img/icons/ppc/' . $ppc_network_icon . '" width="16" height="16" alt="' . $ppc_network_name . '" title="' . $ppc_network_name . ': ' . $ppc_account_name . '"/>';
    
    return $html['ppc_network_icon'];
}

class INDEXES
{
    
    // this returns the location_country_id, when a Country Code is given
    function get_country_id($country_name, $country_code)
    {
        global $memcacheWorking, $memcache;
        
        if ($memcacheWorking) {
            $time = 2592000; // 30 days in sec
                             // get from memcached
            $getID = $memcache->get(md5("country-id" . $country_name . systemHash()));
            
            if ($getID) {
                $country_id = $getID;
                return $country_id;
            } else {
                
                $database = DB::getInstance();
                $db = $database->getConnection();
                
                $mysql['country_name'] = $db->real_escape_string($country_name);
                $mysql['country_code'] = $db->real_escape_string($country_code);
                
                $country_sql = "SELECT country_id FROM 202_locations_country WHERE country_code='" . $mysql['country_code'] . "'";
                $country_result = _mysqli_query($country_sql);
                $country_row = $country_result->fetch_assoc();
                if ($country_row) {
                    // if this ip_id already exists, return the ip_id for it.
                    $country_id = $country_row['country_id'];
                    // add to memcached
                    $setID = setCache(md5("country-id" . $country_name . systemHash()), $country_id, $time);
                    return $country_id;
                } else {
                    // else if this doesn't exist, insert the new iprow, and return the_id for this new row we found
                    $country_sql = "INSERT INTO 202_locations_country SET country_code='" . $mysql['country_code'] . "', country_name='" . $mysql['country_name'] . "'";
                    $country_result = _mysqli_query($country_sql); // ($ip_sql);
                    $country_id = $db->insert_id;
                    // add to memcached
                    $setID = setCache(md5("country-id" . $country_name . systemHash()), $country_id, $time);
                    return $country_id;
                }
            }
        } else {
            $database = DB::getInstance();
            $db = $database->getConnection();
            
            $mysql['country_name'] = $db->real_escape_string($country_name);
            $mysql['country_code'] = $db->real_escape_string($country_code);
            
            $country_sql = "SELECT country_id FROM 202_locations_country WHERE country_code='" . $mysql['country_code'] . "'";
            $country_result = _mysqli_query($country_sql);
            $country_row = $country_result->fetch_assoc();
            if ($country_row) {
                // if this country already exists, return the location_country_id for it.
                $country_id = $country_row['country_id'];
                
                return $country_id;
            } else {
                // else if this doesn't exist, insert the new countryrow, and return the_id for this new row we found
                $country_sql = "INSERT INTO 202_locations_country SET country_code='" . $mysql['country_code'] . "', country_name='" . $mysql['country_name'] . "'";
                $country_result = _mysqli_query($country_sql); // ($ip_sql);
                $country_id = $db->insert_id;
                
                return $country_id;
            }
        }
    }
    
    // this returns the location_city_id, when a City name is given
    function get_city_id($city_name, $country_id)
    {
        global $memcacheWorking, $memcache;
        
        if ($memcacheWorking) {
            $time = 2592000; // 30 days in sec
                             // get from memcached
            $getID = $memcache->get(md5("city-id" . $city_name . $country_id . systemHash()));
            
            if ($getID) {
                $city_id = $getID;
                return $city_id;
            } else {
                
                $database = DB::getInstance();
                $db = $database->getConnection();
                
                $mysql['city_name'] = $db->real_escape_string($city_name);
                $mysql['country_id'] = $db->real_escape_string($country_id);
                
                $city_sql = "SELECT city_id FROM 202_locations_city WHERE city_name='" . $mysql['city_name'] . "'";
                $city_result = _mysqli_query($city_sql);
                $city_row = $city_result->fetch_assoc();
                if ($city_row) {
                    // if this ip_id already exists, return the ip_id for it.
                    $city_id = $city_row['city_id'];
                    // add to memcached
                    $setID = setCache(md5("city-id" . $city_name . $country_id . systemHash()), $city_id, $time);
                    return $city_id;
                } else {
                    // else if this doesn't exist, insert the new iprow, and return the_id for this new row we found
                    $city_sql = "INSERT INTO 202_locations_city SET city_name='" . $mysql['city_name'] . "', main_country_id='" . $mysql['country_id'] . "'";
                    $city_result = _mysqli_query($city_sql); // ($ip_sql);
                    $city_id = $db->insert_id;
                    // add to memcached
                    $setID = setCache(md5("city-id" . $city_name . $country_id . systemHash()), $city_id, $time);
                    return $city_id;
                }
            }
        } else {
            
            $database = DB::getInstance();
            $db = $database->getConnection();
            
            $mysql['city_name'] = $db->real_escape_string($city_name);
            $mysql['country_id'] = $db->real_escape_string($country_id);
            
            $city_sql = "SELECT city_id FROM 202_locations_city WHERE city_name='" . $mysql['city_name'] . "'";
            $city_result = _mysqli_query($city_sql);
            $city_row = $city_result->fetch_assoc();
            if ($city_row) {
                // if this country already exists, return the location_country_id for it.
                $city_id = $city_row['city_id'];
                
                return $city_id;
            } else {
                // else if this doesn't exist, insert the new cityrow, and return the_id for this new row we found
                $city_sql = "INSERT INTO 202_locations_city SET city_name='" . $mysql['city_name'] . "', main_country_id='" . $mysql['country_id'] . "'";
                $city_result = _mysqli_query($city_sql); // ($ip_sql);
                $city_id = $db->insert_id;
                
                return $city_id;
            }
        }
    }
    
    // this returns the isp_id, when a isp name is given
    function get_isp_id($isp)
    {
        global $memcacheWorking, $memcache;
        
        if ($memcacheWorking) {
            $time = 604800; // 7 days in sec
                            // get from memcached
            $getID = $memcache->get(md5("isp-id" . $isp . systemHash()));
            
            if ($getID) {
                $isp_id = $getID;
                return $isp_id;
            } else {
                
                $database = DB::getInstance();
                $db = $database->getConnection();
                
                $mysql['isp'] = $db->real_escape_string($isp);
                
                $isp_sql = "SELECT isp_id FROM 202_locations_isp WHERE isp_name='" . $mysql['isp'] . "'";
                $isp_result = _mysqli_query($isp_sql);
                $isp_row = $isp_result->fetch_assoc();
                if ($isp_row) {
                    // if this ip_id already exists, return the ip_id for it.
                    $isp_id = $isp_row['isp_id'];
                    // add to memcached
                    $setID = setCache(md5("isp-id" . $isp . systemHash()), $isp_id, $time);
                    return $isp_id;
                } else {
                    // else if this doesn't exist, insert the new iprow, and return the_id for this new row we found
                    $isp_sql = "INSERT INTO 202_locations_isp SET isp_name='" . $mysql['isp'] . "'";
                    $isp_result = _mysqli_query($isp_sql); // ($isp_sql);
                    $isp_id = $db->insert_id;
                    // add to memcached
                    $setID = setCache(md5("isp-id" . $isp . systemHash()), $isp_id, $time);
                    return $isp_id;
                }
            }
        } else {
            
            $database = DB::getInstance();
            $db = $database->getConnection();
            
            $mysql['isp'] = $db->real_escape_string($isp);
            
            $isp_sql = "SELECT isp_id FROM 202_locations_isp WHERE isp_name='" . $mysql['isp'] . "'";
            $isp_result = _mysqli_query($isp_sql);
            $isp_row = $isp_result->fetch_assoc();
            if ($isp_row) {
                // if this isp already exists, return the isp_id for it.
                $isp_id = $isp_row['isp_id'];
                
                return $isp_id;
            } else {
                // else if this doesn't exist, insert the new isp row, and return the_id for this new row we found
                $isp_sql = "INSERT INTO 202_locations_isp SET isp_name='" . $mysql['isp'] . "'";
                $isp_result = _mysqli_query($isp_sql); // ($isp_sql);
                $isp_id = $db->insert_id;
                
                return $isp_id;
            }
        }
    }
    
    // this returns the ip_id, when a ip_address is given
    public static function get_ip_id($ip)
    {
        global $db,$memcacheWorking, $memcache;
        
        $mysql['ip_address'] = $db->real_escape_string($ip->address);
        
        if($inet6_ntoa == '' && $ip->type == 'ipv6'){
            $mysql['ip_address'] = inet6_aton($mysql['ip_address']); //encode for db check
        }
        
        if ($ip->type==='ipv6') {
            $ip_sql='SELECT  202_ips.ip_id FROM 202_ips_v6  INNER JOIN 202_ips on (202_ips_v6.ip_id = 202_ips.ip_address COLLATE utf8mb4_general_ci) WHERE 202_ips_v6.ip_address= '.$inet6_aton.'("' . $mysql['ip_address'] . '") order by 202_ips.ip_id DESC limit 1';
        }
        else{
            $ip_sql = "SELECT ip_id FROM 202_ips WHERE ip_address='" . $mysql['ip_address'] . "'";
        }

        if ($memcacheWorking) {
            $time = 2592000; // 7 days in sec
            // get from memcached
            $getID = $memcache->get(md5("ip-id" . $mysql['ip_address'] . systemHash()));
        
            if ($getID) {
                $ip_id = $getID;
            } else {
        
                $ip_result = _mysqli_query($ip_sql);
                $ip_row = $ip_result->fetch_assoc();
                if ($ip_row) {
                    // if this ip_id already exists, return the ip_id for it.
                    $ip_id = $ip_row['ip_id'];
                    // add to memcached
                    $setID = setCache(md5("ip-id" . $mysql['ip_address'] . systemHash()), $ip_id, $time);
                } else {
                    //insert ip
                    $ip_id = INDEXES::insert_ip($db,$ip);
                    // add to memcached
                    $setID = setCache(md5("ip-id" . $mysql['ip_address'] . systemHash()), $ip_id, $time);
                }
            }
        } else {
            $ip_result = _mysqli_query($ip_sql);
            $ip_row = $ip_result->fetch_assoc();
            if ($ip_row['ip_id']) {
                // if this ip already exists, return the ip_id for it.
                $ip_id = $ip_row['ip_id'];
            } else {
                //insert ip
                $ip_id = INDEXES::insert_ip($db,$ip);
            }
        }
        
        //return the ip_id
        return $ip_id;
            }
    
    public static function insert_ip($db,$ip){
    
        $mysql['ip_address'] = $db->real_escape_string($ip->address);
        
        if($inet6_ntoa == '' && $ip->type == 'ipv6'){
            $mysql['ip_address'] = inet6_aton($mysql['ip_address']); //encode for db check
        }
    
        if ($ip->type==='ipv6') {
            //insert the ipv6 ip address and get the ipv6_id
            $ip_sql = 'INSERT INTO 202_ips_v6 SET ip_address='.$inet6_aton.'("'.$mysql['ip_address'].'")';
            $ip_result = _mysqli_query($db, $ip_sql); // ($ip_sql);
            $ipv6_id = $db->insert_id;
    
            //insert the ipv6_id as the ipv4 address for referencing later on
            $ip_sql = "INSERT INTO 202_ips SET ip_address='" . $ipv6_id . "'";
            $ip_result = _mysqli_query($db, $ip_sql); // ($ip_sql);
            $ip_id = $db->insert_id;
            return $ip_id;
        }
        else{
            $ip_sql = "INSERT INTO 202_ips SET ip_address='" . $mysql['ip_address'] . "'";
            $ip_result = _mysqli_query($db, $ip_sql); // ($ip_sql);
            $ip_id = $db->insert_id;
            return $ip_id;
        }
    
    
    }    
    
    // this returns the site_domain_id, when a site_url_address is given
    function get_site_domain_id($site_url_address)
    {
        global $memcacheWorking, $memcache;
        
        $parsed_url = @parse_url($site_url_address);
        $site_domain_host = $parsed_url['host'];
        $site_domain_host = str_replace('www.', '', $site_domain_host);
        
        // if a cached key is found for this lpip, redirect to that url
        if ($memcacheWorking) {
            $time = 2592000; // 30 days in sec
                             // get from memcached
            $getID = $memcache->get(md5("domain-id" . $site_domain_host . systemHash()));
            
            if ($getID) {
                $site_domain_id = $getID;
                return $site_domain_id;
            } else {
                
                $database = DB::getInstance();
                $db = $database->getConnection();
                
                $mysql['site_domain_host'] = $db->real_escape_string($site_domain_host);
                
                $site_domain_sql = "SELECT site_domain_id FROM 202_site_domains WHERE site_domain_host='" . $mysql['site_domain_host'] . "'";
                $site_domain_result = _mysqli_query($site_domain_sql);
                $site_domain_row = $site_domain_result->fetch_assoc();
                if ($site_domain_row) {
                    // if this site_domain_id already exists, return the site_domain_id for it.
                    $site_domain_id = $site_domain_row['site_domain_id'];
                    // add to memcached
                    $setID = setCache(md5("domain-id" . $site_domain_host . systemHash()), $site_domain_id, $time);
                    return $site_domain_id;
                } else {
                    // else if this doesn't exist, insert the new iprow, and return the_id for this new row we found
                    $site_domain_sql = "INSERT INTO 202_site_domains SET site_domain_host='" . $mysql['site_domain_host'] . "'";
                    $site_domain_result = _mysqli_query($site_domain_sql); // ($site_domain_sql);
                    $site_domain_id = $db->insert_id;
                    // add to memcached
                    $setID = setCache(md5("domain-id" . $site_domain_host . systemHash()), $site_domain_id, $time);
                    return $site_domain_id;
                }
            }
        } else {
            
            $database = DB::getInstance();
            $db = $database->getConnection();
            
            $mysql['site_domain_host'] = $db->real_escape_string($site_domain_host);
            
            $site_domain_sql = "SELECT site_domain_id FROM 202_site_domains WHERE site_domain_host='" . $mysql['site_domain_host'] . "'";
            $site_domain_result = _mysqli_query($site_domain_sql);
            $site_domain_row = $site_domain_result->fetch_assoc();
            if ($site_domain_row) {
                // if this site_domain_id already exists, return the site_domain_id for it.
                $site_domain_id = $site_domain_row['site_domain_id'];
                // add to memcached
                return $site_domain_id;
            } else {
                // else if this doesn't exist, insert the new iprow, and return the_id for this new row we found
                $site_domain_sql = "INSERT INTO 202_site_domains SET site_domain_host='" . $mysql['site_domain_host'] . "'";
                $site_domain_result = _mysqli_query($site_domain_sql); // ($site_domain_sql);
                $site_domain_id = $db->insert_id;
                return $site_domain_id;
            }
        }
    }
    
    // this returns the site_url_id, when a site_url_address is given
    function get_site_url_id($site_url_address)
    {
        global $memcacheWorking, $memcache;
        
        $site_domain_id = INDEXES::get_site_domain_id($site_url_address);
        
        if ($memcacheWorking) {
            $time = 604800; // 7 days in sec
                            // get from memcached
            $getURL = $memcache->get(md5("url-id" . $site_url_address . systemHash()));
            if ($getURL) {
                return $getURL;
            } else {
                
                $database = DB::getInstance();
                $db = $database->getConnection();
                
                $mysql['site_url_address'] = $db->real_escape_string($site_url_address);
                $mysql['site_domain_id'] = $db->real_escape_string($site_domain_id);
                
                $site_url_sql = "SELECT site_url_id FROM 202_site_urls WHERE site_domain_id='" . $mysql['site_domain_id'] . "' and site_url_address='" . $mysql['site_url_address'] . "' limit 1";
                $site_url_result = _mysqli_query($site_url_sql);
                $site_url_row = $site_url_result->fetch_assoc();
                if ($site_url_row) {
                    // if this site_url_id already exists, return the site_url_id for it.
                    $site_url_id = $site_url_row['site_url_id'];
                    $setID = setCache(md5("url-id" . $site_url_address . systemHash()), $site_url_id, $time);
                    return $site_url_id;
                } else {
                    
                    $site_url_sql = "INSERT INTO 202_site_urls SET site_domain_id='" . $mysql['site_domain_id'] . "', site_url_address='" . $mysql['site_url_address'] . "'";
                    $site_url_result = _mysqli_query($site_url_sql); // ($site_url_sql);
                    $site_url_id = $db->insert_id;
                    $setID = setCache(md5("url-id" . $site_url_address . systemHash()), $site_url_id, $time);
                    return $site_url_id;
                }
            }
        } else {
            
            $database = DB::getInstance();
            $db = $database->getConnection();
            
            $mysql['site_url_address'] = $db->real_escape_string($site_url_address);
            $mysql['site_domain_id'] = $db->real_escape_string($site_domain_id);
            
            $site_url_sql = "SELECT site_url_id FROM 202_site_urls WHERE site_domain_id='" . $mysql['site_domain_id'] . "' and site_url_address='" . $mysql['site_url_address'] . "' limit 1";
            $site_url_result = _mysqli_query($site_url_sql);
            $site_url_row = $site_url_result->fetch_assoc();
            if ($site_url_row) {
                // if this site_url_id already exists, return the site_url_id for it.
                $site_url_id = $site_url_row['site_url_id'];
                return $site_url_id;
            } else {
                
                $site_url_sql = "INSERT INTO 202_site_urls SET site_domain_id='" . $mysql['site_domain_id'] . "', site_url_address='" . $mysql['site_url_address'] . "'";
                $site_url_result = _mysqli_query($site_url_sql); // ($site_url_sql);
                $site_url_id = $db->insert_id;
                return $site_url_id;
            }
        }
    }
    
    // this returns the keyword_id
    function get_keyword_id($keyword)
    {
        global $memcacheWorking, $memcache;
        
        // only grab the first 255 charactesr of keyword
        // $keyword = substr($keyword, 0, 255);
        
        if ($memcacheWorking) {
            // get from memcached
            $getKeyword = $memcache->get(md5("keyword-id" . $keyword . systemHash()));
            if ($getKeyword) {
                return $getKeyword;
            } else {
                
                $database = DB::getInstance();
                $db = $database->getConnection();
                
                $mysql['keyword'] = $db->real_escape_string($keyword);
                
                $keyword_sql = "SELECT keyword_id FROM 202_keywords WHERE keyword='" . $mysql['keyword'] . "'";
                $keyword_result = _mysqli_query($keyword_sql);
                $keyword_row = $keyword_result->fetch_assoc();
                if ($keyword_row) {
                    // if this already exists, return the id for it
                    $keyword_id = $keyword_row['keyword_id'];
                    $setID = setCache(md5("keyword-id" . $keyword . systemHash()), $keyword_id, 0);
                    return $keyword_id;
                } else {
                    
                    $keyword_sql = "INSERT INTO 202_keywords SET keyword='" . $mysql['keyword'] . "'";
                    $keyword_result = _mysqli_query($keyword_sql); // ($keyword_sql);
                    $keyword_id = $db->insert_id;
                    $setID = setCache(md5("keyword-id" . $keyword . systemHash()), $keyword_id, 0);
                    return $keyword_id;
                }
            }
        } else {
            $database = DB::getInstance();
            $db = $database->getConnection();
            
            $mysql['keyword'] = $db->real_escape_string($keyword);
            
            $keyword_sql = "SELECT keyword_id FROM 202_keywords WHERE keyword='" . $mysql['keyword'] . "'";
            $keyword_result = _mysqli_query($keyword_sql);
            $keyword_row = $keyword_result->fetch_assoc();
            if ($keyword_row) {
                // if this already exists, return the id for it
                $keyword_id = $keyword_row['keyword_id'];
                return $keyword_id;
            } else {
                // else if this ip doesn't exist, insert the row and grab the id for it
                $keyword_sql = "INSERT INTO 202_keywords SET keyword='" . $mysql['keyword'] . "'";
                $keyword_result = _mysqli_query($keyword_sql); // ($keyword_sql);
                $keyword_id = $db->insert_id;
                return $keyword_id;
            }
        }
    }
    
    // this returns the c1 id
    function get_c1_id($c1)
    {
        global $memcacheWorking, $memcache;
        
        // only grab the first 350 charactesr of c1
        $c1 = substr($c1, 0, 350);
        
        if ($memcacheWorking) {
            // get from memcached
            $getc1 = $memcache->get(md5("c1-id" . $c1 . systemHash()));
            if ($getc1) {
                return $getc1;
            } else {
                
                $database = DB::getInstance();
                $db = $database->getConnection();
                
                $mysql['c1'] = $db->real_escape_string($c1);
                
                $c1_sql = "SELECT c1_id FROM 202_tracking_c1 WHERE c1='" . $mysql['c1'] . "'";
                $c1_result = _mysqli_query($c1_sql);
                $c1_row = $c1_result->fetch_assoc();
                if ($c1_row) {
                    // if this already exists, return the id for it
                    $c1_id = $c1_row['c1_id'];
                    $setID = setCache(md5("c1-id" . $c1 . systemHash()), $c1_id, 0);
                    return $c1_id;
                } else {
                    
                    $c1_sql = "INSERT INTO 202_tracking_c1 SET c1='" . $mysql['c1'] . "'";
                    $c1_result = _mysqli_query($c1_sql); // ($c1_sql);
                    $c1_id = $db->insert_id;
                    $setID = setCache(md5("c1-id" . $c1 . systemHash()), $c1_id, 0);
                    return $c1_id;
                }
            }
        } else {
            
            $database = DB::getInstance();
            $db = $database->getConnection();
            
            $mysql['c1'] = $db->real_escape_string($c1);
            
            $c1_sql = "SELECT c1_id FROM 202_tracking_c1 WHERE c1='" . $mysql['c1'] . "'";
            $c1_result = _mysqli_query($c1_sql);
            $c1_row = $c1_result->fetch_assoc();
            if ($c1_row) {
                // if this already exists, return the id for it
                $c1_id = $c1_row['c1_id'];
                return $c1_id;
            } else {
                // else if this ip doesn't exist, insert the row and grab the id for it
                $c1_sql = "INSERT INTO 202_tracking_c1 SET c1='" . $mysql['c1'] . "'";
                $c1_result = _mysqli_query($c1_sql); // ($c1_sql);
                $c1_id = $db->insert_id;
                return $c1_id;
            }
        }
    }
    
    // this returns the c2 id
    function get_c2_id($c2)
    {
        global $memcacheWorking, $memcache;
        
        // only grab the first 350 charactesr of c2
        $c2 = substr($c2, 0, 350);
        
        if ($memcacheWorking) {
            // get from memcached
            $getc2 = $memcache->get(md5("c2-id" . $c2 . systemHash()));
            if ($getc2) {
                return $getc2;
            } else {
                
                $database = DB::getInstance();
                $db = $database->getConnection();
                
                $mysql['c2'] = $db->real_escape_string($c2);
                
                $c2_sql = "SELECT c2_id FROM 202_tracking_c2 WHERE c2='" . $mysql['c2'] . "'";
                $c2_result = _mysqli_query($c2_sql);
                $c2_row = $c2_result->fetch_assoc();
                if ($c2_row) {
                    // if this already exists, return the id for it
                    $c2_id = $c2_row['c2_id'];
                    $setID = setCache(md5("c2-id" . $c2 . systemHash()), $c2_id, 0);
                    return $c2_id;
                } else {
                    
                    $c2_sql = "INSERT INTO 202_tracking_c2 SET c2='" . $mysql['c2'] . "'";
                    $c2_result = _mysqli_query($c2_sql); // ($c2_sql);
                    $c2_id = $db->insert_id;
                    $setID = setCache(md5("c2-id" . $c2 . systemHash()), $c2_id, 0);
                    return $c2_id;
                }
            }
        } else {
            
            $database = DB::getInstance();
            $db = $database->getConnection();
            
            $mysql['c2'] = $db->real_escape_string($c2);
            
            $c2_sql = "SELECT c2_id FROM 202_tracking_c2 WHERE c2='" . $mysql['c2'] . "'";
            $c2_result = _mysqli_query($c2_sql);
            $c2_row = $c2_result->fetch_assoc();
            if ($c2_row) {
                // if this already exists, return the id for it
                $c2_id = $c2_row['c2_id'];
                return $c2_id;
            } else {
                // else if this ip doesn't exist, insert the row and grab the id for it
                $c2_sql = "INSERT INTO 202_tracking_c2 SET c2='" . $mysql['c2'] . "'";
                $c2_result = _mysqli_query($c2_sql); // ($c2_sql);
                $c2_id = $db->insert_id;
                return $c2_id;
            }
        }
    }
    
    // this returns the c3 id
    function get_c3_id($c3)
    {
        global $memcacheWorking, $memcache;
        
        // only grab the first 350 charactesr of c3
        $c3 = substr($c3, 0, 350);
        
        if ($memcacheWorking) {
            // get from memcached
            $getc3 = $memcache->get(md5("c3-id" . $c3 . systemHash()));
            if ($getc3) {
                return $getc3;
            } else {
                
                $database = DB::getInstance();
                $db = $database->getConnection();
                
                $mysql['c3'] = $db->real_escape_string($c3);
                
                $c3_sql = "SELECT c3_id FROM 202_tracking_c3 WHERE c3='" . $mysql['c3'] . "'";
                $c3_result = _mysqli_query($c3_sql);
                $c3_row = $c3_result->fetch_assoc();
                if ($c3_row) {
                    // if this already exists, return the id for it
                    $c3_id = $c3_row['c3_id'];
                    $setID = setCache(md5("c3-id" . $c3 . systemHash()), $c3_id, 0);
                    return $c3_id;
                } else {
                    
                    $c3_sql = "INSERT INTO 202_tracking_c3 SET c3='" . $mysql['c3'] . "'";
                    $c3_result = _mysqli_query($c3_sql); // ($c3_sql);
                    $c3_id = $db->insert_id;
                    $setID = setCache(md5("c3-id" . $c3 . systemHash()), $c3_id, 0);
                    return $c3_id;
                }
            }
        } else {
            
            $database = DB::getInstance();
            $db = $database->getConnection();
            
            $mysql['c3'] = $db->real_escape_string($c3);
            
            $c3_sql = "SELECT c3_id FROM 202_tracking_c3 WHERE c3='" . $mysql['c3'] . "'";
            $c3_result = _mysqli_query($c3_sql);
            $c3_row = $c3_result->fetch_assoc();
            if ($c3_row) {
                // if this already exists, return the id for it
                $c3_id = $c3_row['c3_id'];
                return $c3_id;
            } else {
                // else if this ip doesn't exist, insert the row and grab the id for it
                $c3_sql = "INSERT INTO 202_tracking_c3 SET c3='" . $mysql['c3'] . "'";
                $c3_result = _mysqli_query($c3_sql); // ($c3_sql);
                $c3_id = $db->insert_id;
                return $c3_id;
            }
        }
    }
    
    // this returns the c4 id
    function get_c4_id($c4)
    {
        global $memcacheWorking, $memcache;
        
        // only grab the first 350 charactesr of c4
        $c4 = substr($c4, 0, 350);
        
        if ($memcacheWorking) {
            // get from memcached
            $getc4 = $memcache->get(md5("c4-id" . $c4 . systemHash()));
            if ($getc4) {
                return $getc4;
            } else {
                
                $database = DB::getInstance();
                $db = $database->getConnection();
                
                $mysql['c4'] = $db->real_escape_string($c4);
                
                $c4_sql = "SELECT c4_id FROM 202_tracking_c4 WHERE c4='" . $mysql['c4'] . "'";
                $c4_result = _mysqli_query($c4_sql);
                $c4_row = $c4_result->fetch_assoc();
                if ($c4_row) {
                    // if this already exists, return the id for it
                    $c4_id = $c4_row['c4_id'];
                    $setID = setCache(md5("c4-id" . $c4 . systemHash()), $c4_id, 0);
                    return $c4_id;
                } else {
                    
                    $c4_sql = "INSERT INTO 202_tracking_c4 SET c4='" . $mysql['c4'] . "'";
                    $c4_result = _mysqli_query($c4_sql); // ($c4_sql);
                    $c4_id = $db->insert_id;
                    $setID = setCache(md5("c4-id" . $c4 . systemHash()), $c4_id, 0);
                    return $c4_id;
                }
            }
        } else {
            
            $database = DB::getInstance();
            $db = $database->getConnection();
            
            $mysql['c4'] = $db->real_escape_string($c4);
            
            $c4_sql = "SELECT c4_id FROM 202_tracking_c4 WHERE c4='" . $mysql['c4'] . "'";
            $c4_result = _mysqli_query($c4_sql);
            $c4_row = $c4_result->fetch_assoc();
            if ($c4_row) {
                // if this already exists, return the id for it
                $c4_id = $c4_row['c4_id'];
                return $c4_id;
            } else {
                // else if this ip doesn't exist, insert the row and grab the id for it
                $c4_sql = "INSERT INTO 202_tracking_c4 SET c4='" . $mysql['c4'] . "'";
                $c4_result = _mysqli_query($c4_sql); // ($c4_sql);
                $c4_id = $db->insert_id;
                return $c4_id;
            }
        }
    }
}

function runHourly($user_pref)
{}

function runWeekly($user_pref)
{}



function memcache_mysql_fetch_assoc($sql, $allowCaching = 1, $minutes = 5)
{
    global $memcacheWorking, $memcache;
    
    if ($memcacheWorking == false) {
        $result = _mysqli_query($sql);
        $row = $result->fetch_assoc();
        return $row;
    } else {
        
        if ($allowCaching == 0) {
            $result = _mysqli_query($sql);
            $row = $result->fetch_assoc();
            return $row;
        } else {
            
            // Check if its set
            $getCache = $memcache->get(md5($sql . systemHash()));
            
            if ($getCache === false) {
                // cache this data
                
                $result = _mysqli_query($sql);
                $fetchArray = $result->fetch_assoc();
                //$setCache = $memcache->set(md5($sql . systemHash()), serialize($fetchArray), false, 60 * $minutes);
                setCache(md5($sql . systemHash()), serialize($fetchArray),  60 * $minutes);                
                
                return $fetchArray;
            } else {
                
                // Data Cached
                return unserialize($getCache);
            }
        }
    }
}

function foreach_memcache_mysql_fetch_assoc($sql, $allowCaching = 1)
{
    global $memcacheWorking, $memcache;
    
    if ($memcacheWorking == false) {
        $row = array();
        $result = _mysqli_query($sql); // ($sql);
        while ($fetch = $result->fetch_assoc()) {
            $row[] = $fetch;
        }
        return $row;
    } else {
        
        if ($allowCaching == 0) {
            $row = array();
            $result = _mysqli_query($sql); // ($sql);
            while ($fetch = $result->fetch_assoc()) {
                $row[] = $fetch;
            }
            return $row;
        } else {
            
            $getCache = $memcache->get(md5($sql . systemHash()));
            if ($getCache === false) {
                // if data is NOT cache, cache this data
                $row = array();
                $result = _mysqli_query($sql); // ($sql);
                while ($fetch = $result->fetch_assoc()) {
                    $row[] = $fetch;
                }
                $setCache = setCache(md5($sql . systemHash()), serialize($row), 60 * 5);                
                
                return $row;
            } else {
                // if data is cached, returned the cache data Data Cached
                return unserialize($getCache);
            }
        }
    }
}

// this function delays an SQL statement, puts iy in a mysql table, to be cronjobed out every 5 minutes
function delay_sql($delayed_sql)
{
    if (is_string($delayed_sql))
        $mysql['delayed_sql'] = str_replace("'", "''", $delayed_sql);
    else
        return false;
    $mysql['delayed_time'] = time();
    
    $delayed_sql = "INSERT INTO  202_delayed_sqls 
					
					(
						delayed_sql ,
						delayed_time
					)
					
					VALUES 
					(
						'" . $mysql['delayed_sql'] . "',
						'" . $mysql['delayed_time'] . "'
					);";
    
    $delayed_result = _mysqli_query($delayed_sql); // ($delayed_sql);
}

function user_cache_time($user_id)
{
    $database = DB::getInstance();
    $db = $database->getConnection();
    
    $mysql['user_id'] = $db->real_escape_string($user_id);
    $sql = "SELECT cache_time FROM 202_users_pref WHERE user_id='" . $mysql['user_id'] . "'";
    $result = _mysqli_query($sql);
    $row = $result->fetch_assoc();
    return $row['cache_time'];
}

function get_user_data_feedback($user_id)
{
    $database = DB::getInstance();
    $db = $database->getConnection();
    $mysql['user_id'] = $db->real_escape_string($user_id);
    $sql = "SELECT user_email, user_time_register, p202_customer_api_key, install_hash, user_hash, modal_status, vip_perks_status FROM 202_users WHERE user_id='" . $mysql['user_id'] . "'";

    $result = _mysqli_query($sql);
    $row = $result->fetch_assoc();
    
    return array(
        'user_email' => $row['user_email'],
        'time_stamp' => $row['user_time_register'],
        'api_key' => $row['p202_customer_api_key'],
        'install_hash' => $row['install_hash'],
        'user_hash' => $row['user_hash'],
        'modal_status' => $row['modal_status'],
        'vip_perks_status' => $row['vip_perks_status']
    );
}

function clickserver_api_upgrade_url($key)
{
    
    // Initiate curl
    $ch = curl_init();
    // Disable SSL verification
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // Will return the response, if false it print the response
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Set the url
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v1/auth/?apiKey=' . $key . '&clickserverId=' . base64_encode($_SERVER['HTTP_HOST']));
    // Execute
    $result = curl_exec($ch);
    
    $data = json_decode($result, true);
    
    if ($data['isValidKey'] != 'true' || $data['isValidDomain'] != 'true') {
        return false;
        die();
    }
    
    $download_url = $data['downloadURL'];
    return $download_url;
    
    curl_close($ch);
}

function clickserver_api_key_validate($key)
{
    // Initiate curl
    $ch = curl_init();
    // Disable SSL verification
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // Will return the response, if false it print the response
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Set the url
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v1/auth/?apiKey=' . $key . '&clickserverId=' . base64_encode($_SERVER['HTTP_HOST']));
    // Execute
    $result = curl_exec($ch);
    
    $data = json_decode($result, true);
    
    if ($data['isValidKey'] != 'true' || $data['isValidDomain'] != 'true') {
        return false;
        die();
    }
    
    return true;
    
    curl_close($ch);
}

function api_key_validate($key)
{
    $post = array();
    $post['key'] = $key;
    $fields = http_build_query($post);

    // Initiate curl
    $ch = curl_init();
    // Set the url
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/validate-customers-key');
    // Disable SSL verification
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // Will return the response, if false it print the response
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Set to post
    curl_setopt($ch, CURLOPT_POST, 1);
    // Set post fields
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    // Execute
    $result = curl_exec($ch);

    if (curl_errno($ch)) {
        // echo 'error:' . curl_error($c);
    }
    // close connection
    curl_close($ch);

    $data = json_decode($result, true);
    return $result;
}

function systemHash()
{
    $hash = hash('ripemd160', $_SERVER['HTTP_HOST'] . $_SERVER['SERVER_ADDR']);
    return $hash;
}

function getBrowserIcon($name)
{
    switch ($name) {
        case 'Chrome':
            $icon = 'chrome';
            break;
        
        case 'Chrome Frame':
            $icon = 'chrome';
            break; 
        
        case 'Edge':
            $icon = 'edge';
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

function getSurveyData($install_hash)
{
    
    // Initiate curl
    $ch = curl_init();
    // Disable SSL verification
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // Will return the response, if false it print the response
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Set the url
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v1/deep/survey/' . $install_hash);
    // Execute
    $result = curl_exec($ch);
    // close connection
    curl_close($ch);
    
    $data = json_decode($result, true);
    
    return $data;
}

function updateSurveyData($install_hash, $post)
{
    $fields = http_build_query($post);
    
    // Initiate curl
    $ch = curl_init();
    // Set the url
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v1/deep/survey/' . $install_hash);
    // Disable SSL verification
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // Will return the response, if false it print the response
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Set to post
    curl_setopt($ch, CURLOPT_POST, 1);
    // Set post fields
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    // Execute
    $result = curl_exec($ch);
    
    $data = json_decode($result, true);
    
    // close connection
    curl_close($ch);
    
    return $data;
}

function intercomHash($install_hash)
{
    // Initiate curl
    $ch = curl_init();
    // Set the url
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v1/hash/?h=' . $install_hash);
    // Disable SSL verification
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // Will return the response, if false it print the response
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Execute
    $result = curl_exec($ch);
    
    $data = json_decode($result, true);
    
    // close connection
    curl_close($ch);
    
    return $data['user_hash'];
}

function rotator_data($query, $type)
{
    // Initiate curl
    $ch = curl_init();
    // Set the url
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v1/deep/rotator/' . $type . '/' . $query);
    // Disable SSL verification
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // Will return the response, if false it print the response
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Execute
    $result = curl_exec($ch);
    
    // close connection
    curl_close($ch);
    
    return $result;
}

function changelog()
{
    // Initiate curl
    $ch = curl_init();
    // Set the url
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/clickserver/currentversion/paid/changelogs.php');
    // Disable SSL verification
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // Will return the response, if false it print the response
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Execute
    $result = curl_exec($ch);
    
    // close connection
    curl_close($ch);
    
    return json_decode($result, true);
}

function changelogPremium()
{
    // Initiate curl
    $ch = curl_init();
    // Set the url
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/clickserver/currentversion/paid/changelogs.php');
    // Disable SSL verification
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // Will return the response, if false it print the response
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Execute
    $result = curl_exec($ch);
    
    // close connection
    curl_close($ch);
    
    return json_decode($result, true);
}

function callAutoCron($endpoint)
{
    $protocol = stripos($_SERVER['SERVER_PROTOCOL'], 'https') === true ? 'https://' : 'http://';
    $domain = $protocol . '' . getTrackingDomain(). get_absolute_url();
    $domain = base64_encode($domain);
    
    // Initiate curl
    $ch = curl_init();
    // Set the url
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/autocron/' . $endpoint . '/' . $domain);
    // Disable SSL verification
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // Will return the response, if false it print the response
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Execute
    $result = curl_exec($ch);
    
    // close connection
    curl_close($ch);
    
    return json_decode($result, true);
}

function registerDailyEmail($time, $timezone, $hash)
{
    $protocol = stripos($_SERVER['SERVER_PROTOCOL'], 'https') === true ? 'https://' : 'http://';
    $domain = rtrim($protocol . '' . getTrackingDomain(). get_absolute_url(), '/');
    $domain = base64_encode($domain);
    
    if ($time) {
        $date = new DateTime($time . ':00:00', new DateTimeZone($timezone));
        $date->setTimezone(new DateTimeZone('UTC'));
        $set_time = $date->format('H');
    } else {
        $set_time = 'NA';
    }
    
    // Initiate curl
    $ch = curl_init();
    // Set the url
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/' . $hash . '/' . $domain . '/' . $set_time);
    // Disable SSL verification
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // Will return the response, if false it print the response
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Execute
    $result = curl_exec($ch);
    
    // close connection
    curl_close($ch);
}

function tagUserByNetwork($install_hash, $type, $network)
{
    $post = array();
    $post['network'] = $network;
    $fields = http_build_query($post);
    
    // Initiate curl
    $ch = curl_init();
    // Set the url
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/tag/user/' . $install_hash . '/' . $type);
    // Disable SSL verification
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // Will return the response, if false it print the response
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Set to post
    curl_setopt($ch, CURLOPT_POST, 1);
    // Set post fields
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    // Execute
    $result = curl_exec($ch);
    
    if (curl_errno($ch)) {
        echo 'error:' . curl_error($c);
    }
    // close connection
    curl_close($ch);
}

function getDNIHost()
{
    $protocol = stripos($_SERVER['SERVER_PROTOCOL'], 'https') === true ? 'https://' : 'http://';
    $domain = rtrim($protocol . '' . getTrackingDomain(). get_absolute_url(), '/');
    return base64_encode($domain);
}

function getAllDniNetworks($install_hash)
{
    // Initiate curl
    $ch = curl_init();
    // Set the url
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/dni/' . $install_hash . '/networks/all');
    // Disable SSL verification
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // Will return the response, if false it print the response
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Execute
    $result = curl_exec($ch);
    
    curl_close($ch);
    
    return json_decode($result, true);
}

function authDniNetworks($hash, $network, $key, $affId)
{
    $fields = array(
        'api_key' => $key,
        'affiliate_id' => $affId,
        'host' => getDNIHost()
    );
    $fields = http_build_query($fields);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/dni/' . $hash . '/auth/' . $network);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    $result = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpcode !== 200) {
        return array(
            'auth' => false
        );
    } else {
        $json = json_decode($result, true);
        return array(
            'auth' => true,
            'processed' => $json['processed'],
            'currency' => $json['currency']
        );
    }
}

function getDniOffers($hash, $network, $key, $affId, $currency, $offset, $limit, $sort_by, $filter_by)
{
    $fields = array(
        'api_key' => $key,
        'affiliate_id' => $affId,
        'currency' => $currency,
        'host' => getDNIHost(),
        'sort' => $sort_by,
        'filter' => $filter_by
    );
    $fields = http_build_query($fields);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/dni/' . $hash . '/offers/' . $network . '/all/' . $offset . '/' . $limit);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

function getDniOfferById($hash, $network, $key, $affId, $id)
{
    $fields = array(
        'api_key' => $key,
        'affiliate_id' => $affId,
        'host' => getDNIHost()
    );
    $fields = http_build_query($fields);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/dni/' . $hash . '/offers/' . $network . '/id/' . $id);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

function requestDniOfferAccess($hash, $network, $key, $affId, $id, $type)
{
    $fields = array(
        'api_key' => $key,
        'affiliate_id' => $affId,
        'host' => getDNIHost()
    );
    $fields = http_build_query($fields);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/dni/' . $hash . '/offers/' . $network . '/' . $type . '/' . $id);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

function submitDniOfferAnswers($hash, $network, $api_key, $affId, $id, $answers)
{
    $fields = array(
        'api_key' => $api_key,
        'affiliate_id' => $affId,
        'host' => getDNIHost(),
        'answers' => $answers
    );
    $fields = http_build_query($fields);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/dni/' . $hash . '/offers/' . $network . '/answers/' . $id);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

function setupDniOffer($hash, $network, $key, $affId, $currency, $id, $ddlci)
{
    $fields = array(
        'api_key' => $key,
        'affiliate_id' => $affId,
        'currency' => $currency,
        'host' => getDNIHost(),
        'ddlci' => $ddlci
    );
    $fields = http_build_query($fields);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/dni/' . $hash . '/offers/' . $network . '/setup/' . $id);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

function getDNICacheProgress($hash, $data)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/dni/' . $hash . '/cache/progress');
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Content-type: application/json"
    ));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, true));
    $results = curl_exec($ch);
    curl_close($ch);
	return $results;
}

function setupDniOfferTrack($hash, $network, $key, $affId, $id, $ddlci = false)
{
    $fields = array(
        'api_key' => $key,
        'affiliate_id' => $affId,
        'host' => getDNIHost(),
        'ddlci' => $ddlci
    );

    $url = 'https://my.tracking202.com/api/v2/dni/' . $hash . '/offers/' . $network . '/setup/track/';

    if ($ddlci) {
    	$url .= 'dl/';
    }

    $fields = http_build_query($fields);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . $id);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

function getForeignPayout($currency, $payout_currency, $payout)
{
    $fields = array(
        'currency' => $currency,
        'payout_currency' => $payout_currency,
        'payout' => $payout
    );

    $fields = http_build_query($fields);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/get-foreign-payout');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT ,0); 
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $result = curl_exec($ch);
    curl_close($ch);
    $result = json_decode($result, true);
    return $result;
}

function validateCustomersApiKey($key)
{	
	$fields = array(
        'key' => $key
    );

    $fields = http_build_query($fields);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/validate-customers-key');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT ,0); 
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $result = curl_exec($ch);
    curl_close($ch);
    $result = json_decode($result, true);
    return $result;
}

function validateRevContentCredentials($id, $secret)
{	
	$fields = array(
        'id' => $id,
        'secret' => $secret
    );

    $fields = http_build_query($fields);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/premium-p202/validate-revcontent-credentials');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT ,0); 
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $result = curl_exec($ch);
    curl_close($ch);
    $result = json_decode($result, true);
    return $result;
}

function pushToRevContent($id, $secret, $boost, $boost_id, array $ads)
{	
	$fields = array(
        'id' => $id,
        'secret' => $secret,
        'boost' => $boost,
        'boost_id' => $boost_id,
        'ads' => $ads
    );

    $fields = http_build_query($fields);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/premium-p202/push-revcontent');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $result = curl_exec($ch);
    curl_close($ch);
    $result = json_decode($result, true);
    return $result;
}

function pushToFacebook($api_key, $group, $ad_set_id, array $ads)
{
    $fields = array(
        'campaign_name' => $group,
        'ad_set_id' => $ad_set_id,
        'ads' => $ads
    );

    $fields = http_build_query($fields);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/premium-p202/facebook-ads/push-facebook/'.$api_key);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT ,0); 
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $result = curl_exec($ch);
    curl_close($ch);
    $result = json_decode($result, true);
    return $result;
}

function getFacebookCampaignsAndAdSets($api_key)
{	
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/premium-p202/facebook-ads/get-ad-sets/'.$api_key);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT ,0); 
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $result = curl_exec($ch);
    curl_close($ch);
    $result = json_decode($result, true);
    return $result;
}

function getData($url)
{
if(function_exists('curl_version')){
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT ,0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}
else{
    if( ini_get('allow_url_fopen') ) {
        return file_get_contents($url);
    }else{
        return false;
    }
    
}    
}

function showHelp($page)
{
    switch ($page) {
        case 'step1':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=7158893&t202kw=";
            break;
        case 'step2':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=7158909&t202kw=";
            break;
        case 'step3':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=3158915&t202kw=";
            break;
        case 'step4':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=3158922&t202kw=";
            break;
        case 'step5':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=2158936&t202kw=";
            break;
        case 'step6':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=6158942&t202kw=";
            break;
        case 'step7':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=5158952&t202kw=";
            break;
            case 'slp':
                $url = "http://click202.com/tracking202/redirect/dl.php?t202id=5158884&t202kw=";
                break;
                case 'alp':
                    $url = "http://click202.com/tracking202/redirect/dl.php?t202id=3158798&t202kw=";
                    break;
        case 'step8':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=8158965&t202kw=";
            break;
        case 'step9':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=4158973&t202kw=";
            break;
        case 'overview':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=5158862&t202kw=";
            break;
            case 'groupoverview':
                $url = "http://click202.com/tracking202/redirect/dl.php?t202id=4158853&t202kw=";
                break;
        case 'breakdown':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=3158819&t202kw=";
            break;
        case 'dayparting':
        case 'weekparting':            
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=1158832&t202kw=";
            break;
        case 'analyze':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=8158803&t202kw=";
            break;
        case 'visitor':
            case 'spy':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=1158987&t202kw=";
            break;
        case 'dni':
            $url = "http://click202.com/tracking202/redirect/dl.php?t202id=3158846&t202kw=";
            break;
            case 'clickbank':
                $url = "http://click202.com/tracking202/redirect/dl.php?t202id=4158829&t202kw=";
                break;
            case 'slack':
                $url = "http://click202.com/tracking202/redirect/dl.php?t202id=1158876&t202kw=";
                break;
                case 'update':
                    $url = "http://click202.com/tracking202/redirect/dl.php?t202id=5158996&t202kw=";
                    break;
    }

    if ($url){
        echo '<a href="'.$url.'helpdocs" class="btn btn-info btn-xs" target="_blank"><span class="glyphicon glyphicon-question-sign" aria-hidden="true" title="Get Help"></span></a>';
    }
    
}

function createId($length){
    return bin2hex( random_bytes($length));
}

function createPublisherIds(){
    if(function_exists('random_bytes')){
         $sql ="SELECT user_id FROM 202_users WHERE TRIM(COALESCE(`user_public_publisher_id`, '')) = ''";
        $update_query='UPDATE';
        $user_result = _mysqli_query($sql);
   
         if($user_result){ //loop if not empty
        //loop over all empty publisher ids and set them
             while( $user_row = $user_result->fetch_assoc()) {
            $query = "UPDATE `202_users` SET `user_public_publisher_id` = '".createId(5)."' WHERE `user_id` = '".$user_row['user_id']."'";
            _mysqli_query($query);
            }
        }
    }
}

function getDashEmail()
{
    
    global $db; 
    //get the main users dash email and api key from the db
    $sql = "SELECT user_dash_email,p202_customer_api_key FROM 202_users WHERE user_id ='".$_SESSION['user_id']."'";
    $user_result = _mysqli_query($sql);
    $user_row = $user_result->fetch_assoc();
    
    //if found we are done return the email for usage
    if ($user_row['user_dash_email']) {
        return $user_row['user_dash_email'];
    } else {
        //email not found, try and set it from api call to the server
        if ($user_row['p202_customer_api_key']) {
            $dashEmail = getSetDashEmail($user_row['p202_customer_api_key']);
            if ($dashEmail['code'] == 200 && $dashEmail['email'] != '') {
                //found it save to DB and return
                $mysql['email'] = $db->real_escape_string($dashEmail['email']);
                $sql = "UPDATE `202_users` SET user_dash_email='".$mysql['email']."' WHERE user_id ='".$_SESSION['user_id']."'";
                $user_result = _mysqli_query($sql);
                return ($mysql['email']);
            }
        }
    }
}

function getSetDashEmail($key)
{	
	$fields = array(
        'key' => $key
    );

    $fields = http_build_query($fields);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v2/get-customers-email');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT ,0); 
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $result = curl_exec($ch);
    curl_close($ch);
    $result = json_decode($result, true);
    return $result;
}

function  upgrade_config(){

    //check to see if the sample config file exists
    if (!file_exists('202-config-sample.php')) {
        //_die('Sorry, I need a 202-config-sample.php file to work from. Please re-upload this file from your Prosper202 installation.');
        return;
    }


    //lets make a new config file
    $configFile = file('202-config-sample.php');


    //check to see if the directory is writable
    if ( !is_writable(substr(dirname( __FILE__ ), 0,-10) . '/')) {
       // _die("Sorry your 202-config.php needs upgrading but I can't update it automatically because I can't write to the directory.<br>You'll have to either change the permissions on your Prosper202 directory or create your 202-config.php manually by copying from 202-config-sample.php.");
       return;
    }


    // Check if 202-config.php has been created
    if (file_exists('202-config.php')) {
        $re = '/(\$\w*) = \'(.*)\';/i';
        $handle = fopen('202-config.php', 'r');
        $old = [];
        while(!feof($handle)) {
            $line = fgets($handle);
            preg_match($re, $line, $matches);
            switch ($matches[1]) {
                case '$dbname':
                    $odbname=$matches[2];
                    echo $odbname;
                    break;
                case '$dbuser':
                    $odbuser=$matches[2];
                    echo $odbuser;
                    break;
                case '$dbpass':
                    $odbpass=$matches[2];
                    echo $odbpass;
                    break;
                case '$dbhost':
                    $odbhost=$matches[2];
                    echo $odbhost;
                    break;
                case '$dbhostro':
                    $odbhostro=$matches[2];
                    break;
                case '$mchost':
                    $omchost=$matches[2];
                    break;
            }
        }
        fclose($file);
    }


    $dbname  = trim($odbname);
    $dbuser   = trim($odbuser);
    $dbpass = trim($odbpass);

    if(isset($odbhost) && $odbhost != ''){
        $dbhost  = trim($odbhost);
    }else{
        $dbhost  = 'localhost';
    }
    if(isset($odbhostro) && $odbhostro != ''){
        $dbhostro  = trim($odbhostro);
    }else{
        $dbhostro  = $dbhost;
    }

    if(isset($omchost) && $omchost != ''){
        $mchost  = trim($omchost);
    }else{
        $mchost  = 'localhost';
    }
    	
    //regex to find values in the config file
    $re = '/(\$\w*) = \'(\w*)\';/i';

    $handle = fopen('202-config.php', 'w');

    foreach ($configFile as $line_num => $line) {
        preg_match($re, $line, $matches);
        switch ($matches[1]) {
            case '$dbname':
                fwrite($handle, str_replace("putyourdbnamehere", $dbname, $line));
                break;
            case '$dbuser':
                fwrite($handle, str_replace("'usernamehere'", "'$dbuser'", $line));
                break;
            case '$dbpass':
                fwrite($handle, str_replace("'yourpasswordhere'", "'$dbpass'", $line));
                break;
            case '$dbhost':
                fwrite($handle, str_replace("localhost", $dbhost, $line));
                break;
            case '$dbhostro':
                fwrite($handle, str_replace("localhost", $dbhostro, $line));
                break;
            case '$mchost':
                fwrite($handle, str_replace("localhost", $mchost, $line));
                break;
            default:
                fwrite($handle, $line);
        }
    }
    fclose($handle);

    chmod('202-config.php', 0666);
}

function getSecureStatus()
{
    $secure = false;
    if (
        ( ! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ( ! empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https')
        || ( ! empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] == 'on')
        || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PORT']) && $_SERVER['HTTP_X_FORWARDED_PORT'] == 443)
        || (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] == 'https')
    ) {
        $secure = true;
    } 

    return $secure;

}
?>