<?php

declare(strict_types=1);
include_once(substr(dirname(__FILE__), 0, -18) . '/202-config/connect.php');

AUTH::require_user();

// Initialize default variables to avoid undefined notices
$error = [];
$html  = [];
$edit_tracker_row = [];
$cpc_value = ['0', '00'];
$cpa_value = ['0', '00'];

if (!$userObj->hasPermission("access_to_setup_section")) {
	header('location: ' . get_absolute_url() . 'tracking202/');
	die();
}

$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
$editTrackerId = filter_input(INPUT_GET, 'edit_tracker_id', FILTER_SANITIZE_NUMBER_INT);
$mysql['tracker_id_public'] = $db->real_escape_string((string)($editTrackerId ?? ''));
$showEdit = !empty($editTrackerId);
if ($showEdit) {
	$edit_tracker_sql = "SELECT * FROM 202_trackers AS 2tr
						 LEFT JOIN 202_landing_pages AS 2lp ON (2tr.landing_page_id = 2lp.landing_page_id)
						 LEFT JOIN 202_aff_campaigns AS 2ac ON (2tr.aff_campaign_id = 2ac.aff_campaign_id)
						 LEFT JOIN 202_ppc_accounts AS 2pa ON (2tr.ppc_account_id = 2pa.ppc_account_id) 
						 WHERE 2tr.user_id = '" . $mysql['user_id'] . "' AND 2ac.aff_campaign_deleted='0' AND 2tr.tracker_id_public = '" . $mysql['tracker_id_public'] . "'";

	$edit_tracker_result = $db->query($edit_tracker_sql);
	$edit_tracker_row = $edit_tracker_result->fetch_assoc();
	$cpc_value = explode(".", $edit_tracker_row['click_cpc'], 2);
	$cpa_value = explode(".", $edit_tracker_row['click_cpa'], 2);

	if ($edit_tracker_result->num_rows == 0) {
		$showEdit = false;
	}
}

template_top('Get Trackers', NULL, NULL, NULL);  ?>

<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<h6>Get tracking links to be used in your campaigns</h6>
	</div>
	<div class="col-xs-12">
		<small>Please make sure to test your links.<br />If you are using a landing page, you should have already installed your landing page code prior to coming to this step.</small>
	</div>
</div>

<div class="row form_seperator" style="margin-bottom:15px;">
	<div class="col-xs-12"></div>
</div>

<div class="row">
	<div class="col-xs-7">
		<form method="post" id="tracking_form" class="form-horizontal" role="form" style="margin:0px 0px 0px 15px;">
			<?php if ($showEdit) { ?>
				<input type="hidden" name="edit_tracker" value="1">
				<input type="hidden" name="tracker_id" value="<?php echo htmlentities($editTrackerId, ENT_QUOTES, 'UTF-8'); ?>">
			<?php } ?>
			<div class="form-group <?php if (isset($error['landing_page_type']) && $error['landing_page_type']) echo 'has-error'; ?>" style="margin-bottom: 0px;" id="tracker-type">
				<label class="col-xs-4 control-label" style="text-align: left;" id="width-tooltip">Get Text Ad Code For:</label>

				<div class="col-xs-8" style="margin-top: 15px;">
					<label class="radio">
						<input type="radio" name="tracker_type" value="0" data-toggle="radio" <?php if (isset($edit_tracker_row['landing_page_type']) && $edit_tracker_row['landing_page_type'] == false || isset($edit_tracker_row['landing_page_id']) && $edit_tracker_row['landing_page_id'] == false) echo "checked"; ?> <?php if (!isset($showEdit) || !$showEdit) echo "checked"; ?>>
						Direct Link Setup, or Simple Landing Page Setup
					</label>
					<label class="radio">
						<input type="radio" name="tracker_type" value="1" data-toggle="radio" <?php if (isset($edit_tracker_row['landing_page_type']) && $edit_tracker_row['landing_page_type']) echo "checked"; ?>>
						Advanced Landing Page Setup
					</label>
					<label class="radio">
						<input type="radio" name="tracker_type" value="2" data-toggle="radio" <?php if (isset($edit_tracker_row['rotator_id']) && $edit_tracker_row['rotator_id']) echo "checked"; ?>>
						Smart Redirector
					</label>
				</div>
			</div>

			<div id="tracker_aff_network" class="form-group" style="margin-bottom: 0px;">
				<label for="aff_network_id" class="col-xs-4 control-label" style="text-align: left;">Category:</label>
				<div class="col-xs-6" style="margin-top: 10px;">
					<img id="aff_network_id_div_loading" class="loading" style="display: none;" src="<?php echo get_absolute_url(); ?>202-img/loader-small.gif" />
					<div id="aff_network_id_div"></div>
				</div>
			</div>

			<div id="tracker_aff_campaign" class="form-group" style="margin-bottom: 0px;">
				<label for="aff_campaign_id" class="col-xs-4 control-label" style="text-align: left;">Campaign:</label>
				<div class="col-xs-6" style="margin-top: 10px;">
					<img id="aff_campaign_id_div_loading" class="loading" src="<?php echo get_absolute_url(); ?>202-img/loader-small.gif" style="display: none;" />
					<div id="aff_campaign_id_div">
						<select class="form-control input-sm" id="aff_campaign_id" disabled="">
							<option>--</option>
						</select>
					</div>
				</div>
			</div>

			<div id="tracker_method_of_promotion" class="form-group" style="margin-bottom: 0px;">
				<label for="method_of_promotion" class="col-xs-4 control-label" style="text-align: left;">Method of Promotion:</label>
				<div class="col-xs-6" style="margin-top: 10px;">
					<img id="method_of_promotion_div_loading" class="loading" style="display: none;" src="<?php echo get_absolute_url(); ?>202-img/loader-small.gif" />
					<div id="method_of_promotion_div">
						<select class="form-control input-sm" id="method_of_promotion" disabled="">
							<option>--</option>
						</select>
					</div>
				</div>
			</div>

			<div id="tracker_lp" class="form-group" style="margin-bottom: 0px;">
				<label for="landing_page_id" class="col-xs-4 control-label" style="text-align: left;">Landing Page:</label>
				<div class="col-xs-6" style="margin-top: 10px;">
					<img id="landing_page_div_loading" class="loading" style="display: none;" src="<?php echo get_absolute_url(); ?>202-img/loader-small.gif" />
					<div id="landing_page_div">
						<select class="form-control input-sm" id="landing_page_id" disabled="">
							<option>--</option>
						</select>
					</div>
				</div>
			</div>

			<div id="tracker_ad_copy" class="form-group" style="margin-bottom: 0px;">
				<label for="text_ad_id" class="col-xs-4 control-label" style="text-align: left;">Ad Copy:</label>
				<div class="col-xs-6" style="margin-top: 10px;">
					<img id="text_ad_id_div_loading" class="loading" style="display: none;" src="<?php echo get_absolute_url(); ?>202-img/loader-small.gif" />
					<div id="text_ad_id_div">
						<select class="form-control input-sm" id="text_ad_id" disabled="">
							<option>--</option>
						</select>
					</div>
				</div>
			</div>

			<div id="tracker_ad_preview" class="form-group" style="margin-bottom: 0px;">
				<label class="col-xs-4 control-label" style="text-align: left;">Ad Preview </label>
				<div class="col-xs-6" style="margin-top: 10px;">
					<img id="ad_preview_div_loading" class="loading" style="display: none;" src="<?php echo get_absolute_url(); ?>202-img/loader-small.gif" />
					<div id="ad_preview_div">
						<div class="panel panel-default" style="opacity:0.5; border-color: #3498db; margin-bottom:0px">
							<div class="panel-body">
								<span id="ad-preview-headline"><?php if (isset($html['text_ad_headline']) && $html['text_ad_headline']) {
																	echo $html['text_ad_headline'];
																} else {
																	echo 'Luxury Cruise to Mars';
																} ?></span><br />
								<span id="ad-preview-body"><?php if (isset($html['text_ad_description']) && $html['text_ad_description']) {
																echo $html['text_ad_description'];
															} else {
																echo 'Visit the Red Planet in style. Low-gravity fun for everyone!';
															} ?></span><br />
								<span id="ad-preview-url"><?php if (isset($html['text_ad_display_url']) && $html['text_ad_display_url']) {
																echo $html['text_ad_display_url'];
															} else {
																echo 'www.example.com';
															} ?></span>
							</div>
						</div>
					</div>
				</div>
			</div>

			<div id="tracker_cloaking" class="form-group" style="margin-bottom: 0px;">
				<label for="click_cloaking" class="col-xs-4 control-label" style="text-align: left;">Cloaking:</label>
				<div class="col-xs-6" style="margin-top: 10px;">
					<select class="form-control input-sm" name="click_cloaking" id="click_cloaking">
						<option value="-1" <?php if (isset($edit_tracker_row['click_cloaking']) && $edit_tracker_row['click_cloaking'] == '-1') echo "selected"; ?>>Campaign Default On/Off</option>
						<option value="0" <?php if (isset($edit_tracker_row['click_cloaking']) && $edit_tracker_row['click_cloaking'] == '0') echo "selected"; ?>>Off - Overide Campaign Default</option>
						<option value="1" <?php if (isset($edit_tracker_row['click_cloaking']) && $edit_tracker_row['click_cloaking'] == '1') echo "selected"; ?>>On - Override Campaign Default</option>
					</select>
				</div>
			</div>

			<div id="tracker_rotator" class="form-group" style="display:none; margin-bottom: 0px;">
				<label for="tracker_rotator" class="col-xs-4 control-label" style="text-align: left;">Rotator:</label>
				<div class="col-xs-6" style="margin-top: 10px;">
					<img id="rotator_id_div_loading" class="loading" style="display: none;" src="<?php echo get_absolute_url(); ?>202-img/loader-small.gif" />
					<div id="rotator_id_div"></div>
				</div>
			</div>

			<div class="form-group" style="margin-bottom: 0px;">
				<label for="ppc_network_id" class="col-xs-4 control-label" style="text-align: left;">Traffic Source:</label>
				<div class="col-xs-6" style="margin-top: 10px;">
					<img id="ppc_network_id_div_loading" class="loading" style="display: none;" src="<?php echo get_absolute_url(); ?>202-img/loader-small.gif" />
					<div id="ppc_network_id_div"></div>
				</div>
			</div>

			<div class="form-group" style="margin-bottom: 0px;">
				<label for="ppc_account_id" class="col-xs-4 control-label" style="text-align: left;">Traffic Source Account:</label>
				<div class="col-xs-6" style="margin-top: 10px;">
					<img id="ppc_account_id_div_loading" class="loading" style="display: none;" src="<?php echo get_absolute_url(); ?>202-img/loader-small.gif" />
					<div id="ppc_account_id_div">
						<select class="form-control input-sm" id="ppc_account_id" disabled="">
							<option>--</option>
						</select>
					</div>
				</div>
			</div>

			<div class="form-group" style="margin-bottom: 0px;">
				<label class="col-xs-4 control-label" for="cost_type" style="text-align: left;">Cost Type:</label>
				<div class="col-xs-6">
					<label class="radio radio-inline">
						<input type="radio" name="cost_type" value="cpc" data-toggle="radio" <?php if (!isset($edit_tracker_row['click_cpa']) || $edit_tracker_row['click_cpa'] == NULL || !isset($showEdit) || !$showEdit) echo "checked"; ?>>CPC
					</label>
					<label class="radio radio-inline">
						<input type="radio" name="cost_type" value="cpa" data-toggle="radio" <?php if (isset($edit_tracker_row['click_cpa']) && $edit_tracker_row['click_cpa']) echo "checked"; ?>>CPA
					</label>
				</div>
			</div>

			<div class="form-group" id="cpc_costs" style="margin-bottom: 0px; <?php if (isset($edit_tracker_row['click_cpa']) && $edit_tracker_row['click_cpa']) echo "display:none"; ?>">
				<label class="col-xs-4 control-label" for="cpc_dollars" style="text-align: left;">Max CPC:</label>
				<div class="col-xs-6" style="margin-top: 10px;">
					<div class="input-group input-group-sm">
						<span class="input-group-addon">$</span>
						<input class="form-control" name="cpc_dollars" id="cpc_dollars" maxlength="2" type="text" value="<?php if (isset($showEdit) && $showEdit) echo $cpc_value[0];
																															else echo '0'; ?>">

						<span class="input-group-addon">&cent;</span>
						<input class="form-control" name="cpc_cents" maxlength="5" id="cpc_cents" type="text" value="<?php if ($showEdit) echo $cpc_value[1];
																														else echo '00'; ?>">
					</div>
					<span class="help-block" style="font-size: 11px;">you can enter cpc amounts as small as 0.00001</span>
				</div>
			</div>

			<div class="form-group" id="cpa_costs" style="margin-bottom: 0px; <?php if (!isset($edit_tracker_row['click_cpa']) || $edit_tracker_row['click_cpa'] == NULL || !isset($showEdit) || !$showEdit) echo "display:none"; ?>">
				<label class="col-xs-4 control-label" for="cpa_dollars" style="text-align: left;">Max CPA:</label>
				<div class="col-xs-6" style="margin-top: 10px;">
					<div class="input-group input-group-sm">
						<span class="input-group-addon">$</span>
						<input class="form-control" name="cpa_dollars" id="cpa_dollars" maxlength="2" type="text" value="<?php if ($showEdit && $cpa_value[0]) echo $cpa_value[0];
																															else echo '0'; ?>">

						<span class="input-group-addon">&cent;</span>
						<input class="form-control" name="cpa_cents" maxlength="5" id="cpa_cents" type="text" value="<?php if ($showEdit && $cpa_value[1]) echo $cpa_value[1];
																														else echo '00'; ?>">
					</div>
					<span class="help-block" style="font-size: 11px;">you can enter cpa amounts as small as 0.00001</span>
				</div>
			</div>

			<div class="form-group" style="margin-bottom: 0px;">
				<label class="col-xs-4 control-label" for="t202kw" style="text-align: left;">Keyword Token:</label>
				<div class="col-xs-6" style="margin-top: 10px;">
					<input class="form-control input-sm" type="text" name="t202kw" id="t202kw" />
					<span class="help-block" style="font-size: 10px;"><strong>Optional:</strong> If your traffic source supports a keyword token, add it here.</span>
				</div>
			</div>

			<div class="form-group" style="margin-bottom: 0px;">
				<label class="col-xs-4 control-label" for="t202b" style="text-align: left;">Dynamic CPC Token:</label>
				<div class="col-xs-6" style="margin-top: 10px;">
					<input class="form-control input-sm" type="text" name="t202b" id="t202b" />
					<span class="help-block" style="font-size: 10px;"><strong>Optional:</strong> If your traffic source supports a bid token, add it here for exact cost tracking.</span>
				</div>
			</div>

			<div class="form-group" style="margin-bottom: 0px;">
				<label class="col-xs-4 control-label" for="t202ref" style="text-align: left;">Custom Referer Token:</label>
				<div class="col-xs-6" style="margin-top: 10px;">
					<input class="form-control input-sm" type="text" name="t202ref" id="t202ref" />
					<span class="help-block" style="font-size: 10px;"><strong>Optional:</strong> This is used for cases where the real referer info is not useful, however the traffic source provide a token that can be used as a better referer value.</span>
				</div>
			</div>

			<div class="form-group" style="margin-bottom: 0px;">
				<label class="col-xs-4 control-label" for="c1" style="text-align: left;">Tracking ID c1:</label>
				<div class="col-xs-6" style="margin-top: 10px;">
					<input class="form-control input-sm" type="text" name="c1" id="c1" />
					<span class="help-block" style="font-size: 10px;"><strong>Optional:</strong> c1-c4 variables must be no longer than 350 characters.</span>
				</div>
			</div>

			<div class="form-group" style="margin-bottom: 0px;">
				<label class="col-xs-4 control-label" for="c2" style="text-align: left;">Tracking ID c2:</label>
				<div class="col-xs-6" style="margin-top: 10px;">
					<input class="form-control input-sm" type="text" name="c2" id="c2" />
				</div>
			</div>

			<div class="form-group" style="margin-bottom: 0px;">
				<label class="col-xs-4 control-label" for="c3" style="text-align: left;">Tracking ID c3:</label>
				<div class="col-xs-6" style="margin-top: 10px;">
					<input class="form-control input-sm" type="text" name="c3" id="c3" />
				</div>
			</div>

			<div class="form-group" style="margin-bottom: 0px;">
				<label class="col-xs-4 control-label" for="c4" style="text-align: left;">Tracking ID c4:</label>
				<div class="col-xs-6" style="margin-top: 10px;">
					<input class="form-control input-sm" type="text" name="c4" id="c4" />
				</div>
			</div>

			<div class="form-group">
				<div class="col-xs-10" style="margin-top: 10px;">
					<input type="button" id="get-links" class="btn btn-sm btn-p202 btn-block" value="<?php if ($showEdit) echo "Edit Tracking Link";
																										else echo "Generate Tracking Link"; ?>">
				</div>
			</div>

		</form>
	</div>

	<div class="col-xs-4 col-xs-offset-1">
		<div class="panel panel-default">
			<div class="panel-heading">My Tracking Links</div>
			<div class="panel-body pre-scrollable" style="max-height: 915px;">
				<div id="filterTrackers">
					<input class="form-control input-sm search" style="margin-bottom: 10px; height: 30px;" placeholder="Filter">
					<ul class="list">
						<?php
						$trackers_sql = "SELECT 
									 2tr.tracker_id,
									 2tr.tracker_id_public,
									 2tr.tracker_time,
									 2tr.rotator_id,
									 2lp.landing_page_id,
									 2lp.landing_page_url,
									 2ac.aff_campaign_name,
									 2lp.landing_page_nickname,
									 2r.name,
									 2pv.parameters, 
									 2pv.placeholders
					                 FROM 202_trackers AS 2tr 
					                 LEFT JOIN 202_landing_pages AS 2lp ON (2tr.landing_page_id = 2lp.landing_page_id) 
                                     LEFT JOIN 202_aff_campaigns AS 2ac ON (2tr.aff_campaign_id = 2ac.aff_campaign_id)
                                     LEFT JOIN 202_rotators AS 2r ON (2tr.rotator_id = 2r.id)
                                     LEFT JOIN 202_ppc_accounts AS 2ppc ON (2tr.ppc_account_id = 2ppc.ppc_account_id)
                                     LEFT JOIN (SELECT ppc_network_id, GROUP_CONCAT(parameter) AS parameters, GROUP_CONCAT(placeholder) AS placeholders FROM 202_ppc_network_variables GROUP BY ppc_network_id) AS 2pv ON (2ppc.ppc_network_id = 2pv.ppc_network_id)					                 
					                 WHERE 2tr.user_id ='" . $mysql['user_id'] . "'";

						$trackers_result = $db->query($trackers_sql);

						while ($tracker_row = $trackers_result->fetch_array(MYSQLI_ASSOC)) {

							$vars_query = '';

							$parameters = explode(',', $tracker_row['parameters'] ?? '');
							$placeholders = explode(',', $tracker_row['placeholders'] ?? '');

							foreach ($parameters as $key => $value) {
								if (isset($placeholders[$key])) {
									$vars_query .= '&' . $value . '=' . $placeholders[$key];
								}
							}

							if ($tracker_row['landing_page_id']) {
								$parsed_url = parse_url($tracker_row['landing_page_url']);
								$destination_url = $parsed_url['scheme'] . '://' . $parsed_url['host'] . $parsed_url['path'] . '?';
								if (!empty($parsed_url['query'])) {
									$destination_url .= $parsed_url['query'] . '&';;
								}
								$destination_url .= 't202id=' . $tracker_row['tracker_id_public'];
								if (!empty($parsed_url['fragment'])) {
									$destination_url .= '#' . $parsed_url['fragment'];
								}
								$destination_url .= 't202kw=';

								$display_name = $tracker_row['landing_page_nickname'];
							} else if ($tracker_row['rotator_id']) {
								$display_name = $tracker_row['name'];
							} else {
								$display_name = $tracker_row['aff_campaign_name'];
							}
						?>
							<li>
								<span class="small">Id:<?php echo $tracker_row['tracker_id']; ?> -
									(<?php echo '<span class="filter_tracker_display_name">' . $display_name . '</span> - ' . date('m/d/y', (int)$tracker_row['tracker_time']); ?>)</span> -
								<?php if ($tracker_row['landing_page_id'] != 0) { ?>
									<a href="<?php echo $destination_url; ?>">link</a> -
								<?php } else if ($tracker_row['rotator_id']) { ?>
									<a href="http://<?php echo getTrackingDomain() . get_absolute_url(); ?>tracking202/redirect/rtr.php?t202id=<?php echo $tracker_row['tracker_id_public']; ?>&t202kw=<?php echo $vars_query; ?>">link</a> -
								<?php } else { ?>
									<a href="http://<?php echo getTrackingDomain() . get_absolute_url(); ?>tracking202/redirect/dl.php?t202id=<?php echo $tracker_row['tracker_id_public']; ?>&t202kw=<?php echo $vars_query; ?>">link</a> -
								<?php } ?>
								<a href="<?php echo get_absolute_url(); ?>tracking202/setup/get_trackers.php?edit_tracker_id=<?php echo $tracker_row['tracker_id_public']; ?>"><i class="fa fa-pencil-square-o"></i></a>
								<?php if ($userObj->hasPermission("remove_tracker")) { ?>
									- <a href="#" class="delete_tracker" data-id="<?php echo $tracker_row['tracker_id']; ?>"><i class="fa fa-trash"></i></a>
								<?php } ?>
							</li>
						<?php } ?>
					</ul>
				</div>
			</div>
		</div>
	</div>

</div>

<div class="row form_seperator" style="margin-bottom:15px;">
	<div class="col-xs-12"></div>
</div>
<div class="row">
	<div class="col-xs-12">
		<div class="panel panel-default">
			<div class="panel-heading">
				<center>Tracking Links</center>
			</div>
			<div class="panel-body" id="tracking-links" style="opacity: 0.5;">
				<center><small>Click <em>"Generate Tracking Link"</em> to get tracking links.</small></center>
			</div>
		</div>
	</div>
</div>

<script type="text/javascript">
	$(document).ready(function() {

		var element1 = $('#tracker_aff_network');
		var element2 = $('#tracker_aff_campaign');
		var element3 = $('#tracker_method_of_promotion');
		var element4 = $('#tracker_lp');
		var element5 = $('#tracker_ad_copy');
		var element6 = $('#tracker_ad_preview');
		var element7 = $('#tracker_cloaking');
		var element8 = $('#tracker_rotator');

		<?php
		if ($showEdit) {
			if ($edit_tracker_row['landing_page_type'] == false && $edit_tracker_row['rotator_id'] == false) { ?>

				element1.show();
				element2.show();
				element3.show();
				element4.show();
				element5.show();
				element6.show();
				element7.show();
				element8.hide();

				load_aff_network_id(<?php echo $edit_tracker_row['aff_network_id']; ?>);
				load_aff_campaign_id(<?php echo $edit_tracker_row['aff_network_id']; ?>, <?php echo $edit_tracker_row['aff_campaign_id']; ?>);
				<?php if ($edit_tracker_row['landing_page_id'] == false) { ?>
					load_method_of_promotion('directlink');
				<?php } else { ?>
					load_method_of_promotion('landingpage');
					load_landing_page(<?php echo $edit_tracker_row['aff_campaign_id']; ?>, <?php echo $edit_tracker_row['landing_page_id']; ?>, 'landingpage');
				<?php } ?>

				<?php if ($edit_tracker_row['text_ad_id']) { ?>
					load_text_ad_id(<?php echo $edit_tracker_row['aff_campaign_id']; ?>, <?php echo $edit_tracker_row['text_ad_id']; ?>);
					load_ad_preview(<?php echo $edit_tracker_row['text_ad_id']; ?>);
				<?php } ?>
				load_ppc_network_id(<?php echo $edit_tracker_row['ppc_network_id']; ?>);
				load_ppc_account_id(<?php echo $edit_tracker_row['ppc_network_id']; ?>, <?php echo $edit_tracker_row['ppc_account_id']; ?>);
			<?php } ?>

			<?php if ($edit_tracker_row['landing_page_type']) { ?>

				element1.hide();
				element2.hide();
				element3.hide();
				element4.show();
				element5.show();
				element6.show();
				element7.show();
				element8.hide();

				load_landing_page(0, <?php echo $edit_tracker_row['landing_page_id']; ?>, 'advlandingpage');
				<?php if ($edit_tracker_row['text_ad_id']) { ?>
					load_adv_text_ad_id(<?php echo $edit_tracker_row['landing_page_id']; ?>, <?php echo $edit_tracker_row['text_ad_id']; ?>);
					load_ad_preview(<?php echo $edit_tracker_row['text_ad_id']; ?>);
				<?php } ?>
				load_ppc_network_id(<?php echo $edit_tracker_row['ppc_network_id']; ?>);
				load_ppc_account_id(<?php echo $edit_tracker_row['ppc_network_id']; ?>, <?php echo $edit_tracker_row['ppc_account_id']; ?>);

			<?php } ?>

			<?php if ($edit_tracker_row['rotator_id']) { ?>

				element1.hide();
				element2.hide();
				element3.hide();
				element4.hide();
				element5.hide();
				element6.hide();
				element7.hide();
				element8.show();
				load_rotator_id(<?php echo $edit_tracker_row['rotator_id']; ?>);
				load_ppc_network_id(<?php echo $edit_tracker_row['ppc_network_id']; ?>);
				load_ppc_account_id(<?php echo $edit_tracker_row['ppc_network_id']; ?>, <?php echo $edit_tracker_row['ppc_account_id']; ?>);

			<?php }
		} else { ?>

			load_aff_network_id(0);
			load_method_of_promotion('');
			load_ppc_network_id(0);

		<?php } ?>

		var trackerOptions = {
			valueNames: ['filter_tracker_display_name']
		};

		var filterTrackers = new List('filterTrackers', trackerOptions);

	});
</script>
<?php template_bottom();
