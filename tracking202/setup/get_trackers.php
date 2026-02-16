<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -18) . '/202-config/connect.php');

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
	$cpc_value = explode(".", (string) $edit_tracker_row['click_cpc'], 2);
	$cpa_value = explode(".", (string) $edit_tracker_row['click_cpa'], 2);

	if ($edit_tracker_result->num_rows == 0) {
		$showEdit = false;
	}
}

template_top('Get Trackers');  ?>

<link rel="stylesheet" href="/202-css/design-system.css">

<!-- Page Header - Design System -->
<div class="row" style="margin-bottom: 28px;">
	<div class="col-xs-12">
		<div class="setup-page-header">
			<div class="setup-page-header__icon">
				<span class="glyphicon glyphicon-link"></span>
			</div>
			<div class="setup-page-header__text">
				<h1 class="setup-page-header__title">Get Links</h1>
				<p class="setup-page-header__subtitle">Generate tracking links for your campaigns</p>
			</div>
		</div>
	</div>
</div>

<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<div class="alert alert-info">
			<i class="fa fa-info-circle"></i>
			<strong>Tip:</strong> Make sure to test your links. If using a landing page, install your landing page code first.
		</div>
	</div>
</div>

<div class="row form_seperator" style="margin-bottom:15px;">
	<div class="col-xs-12"></div>
</div>

<div class="row">
	<div class="col-md-6">
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

		<div class="col-md-6">
			<div class="panel panel-default setup-side-panel">
			<div class="panel-heading">My Tracking Links</div>
			<div class="panel-body pre-scrollable" style="max-height: 915px;">
				<div id="filterTrackers">
					<input class="form-control input-sm search" style="margin-bottom: 10px; height: 30px;" placeholder="Filter">
					<ul class="list setup-list">
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
								$parsed_url = parse_url((string) $tracker_row['landing_page_url']);
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
								<span class="filter_tracker_display_name"><?php echo $display_name; ?></span>
								<span class="filter_tracker_meta">Id:<?php echo $tracker_row['tracker_id']; ?> &bullet; <?php echo date('m/d/y', (int)$tracker_row['tracker_time']); ?></span>
								<?php if ($tracker_row['landing_page_id'] != 0) { ?>
									<a href="<?php echo $destination_url; ?>" class="list-action" title="Open tracking link">link</a>
								<?php } else if ($tracker_row['rotator_id']) { ?>
									<a href="http://<?php echo getTrackingDomain() . get_absolute_url(); ?>tracking202/redirect/rtr.php?t202id=<?php echo $tracker_row['tracker_id_public']; ?>&t202kw=<?php echo $vars_query; ?>" class="list-action" title="Open tracking link">link</a>
								<?php } else { ?>
									<a href="http://<?php echo getTrackingDomain() . get_absolute_url(); ?>tracking202/redirect/dl.php?t202id=<?php echo $tracker_row['tracker_id_public']; ?>&t202kw=<?php echo $vars_query; ?>" class="list-action" title="Open tracking link">link</a>
								<?php } ?>
								<a href="<?php echo get_absolute_url(); ?>tracking202/setup/get_trackers.php?edit_tracker_id=<?php echo $tracker_row['tracker_id_public']; ?>" class="list-action" title="Edit this tracker"><i class="fa fa-pencil-square-o"></i> edit</a>
								<?php if ($userObj->hasPermission("remove_tracker")) { ?>
									<a href="#" class="delete_tracker list-action list-action-danger" data-id="<?php echo $tracker_row['tracker_id']; ?>" title="Delete this tracker" onclick="return confirmSubmit('Are you sure you want to delete this tracker?');"><i class="fa fa-trash"></i> remove</a>
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
<style>
/* Setup Page Header */
.setup-page-header {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 24px;
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    border-radius: 12px;
    color: #fff;
    box-shadow: 0 4px 15px rgba(0, 123, 255, 0.2);
}
.setup-page-header__icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 56px;
    height: 56px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    flex-shrink: 0;
}
.setup-page-header__icon .glyphicon {
    font-size: 28px;
}
.setup-page-header__text {
    flex: 1;
}
.setup-page-header__title {
    margin: 0 0 4px 0;
    font-size: 24px;
    font-weight: 600;
    color: #fff;
}
.setup-page-header__subtitle {
    margin: 0;
    font-size: 14px;
    color: rgba(255, 255, 255, 0.85);
}

/* Enhanced Panel Styling */
.panel {
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    border: 1px solid #e2e8f0;
}
.panel-heading {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%) !important;
    border-bottom: 1px solid #e2e8f0;
    border-radius: 12px 12px 0 0 !important;
    padding: 16px 20px;
}
.panel-title {
    font-weight: 600;
    font-size: 15px;
    color: #1e293b;
}
.panel-body {
    padding: 24px;
}

/* Form Enhancements */
.form-control {
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    padding: 10px 14px;
    transition: all 0.2s ease;
}
.form-control:focus {
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.15);
}

/* Button Enhancements */
.btn-primary {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    border: none;
    border-radius: 8px;
    padding: 10px 20px;
    font-weight: 600;
    box-shadow: 0 4px 12px rgba(0, 123, 255, 0.25);
    transition: all 0.2s ease;
}
.btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(0, 123, 255, 0.35);
}
.btn-success {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    border: none;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
}

/* Setup List Styling */
.setup-list {
    list-style: none;
    padding: 0;
    margin: 0;
}
.setup-list li {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    padding: 12px 0;
    border-bottom: 1px solid #f0f0f0;
    transition: background-color 0.2s ease;
}
.setup-list li:last-child {
    border-bottom: none;
}
.setup-list li:hover {
    background-color: #f9fafb;
}

.filter_tracker_display_name {
    font-weight: 600;
    color: #1e293b;
    word-break: break-word;
    font-size: 14px;
    flex: 1 1 100%;
}

.filter_tracker_meta {
    font-size: 12px;
    color: #64748b;
    flex: 1 1 100%;
    margin-top: -8px;
}

.list-action {
    text-decoration: none;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 13px;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    color: #007bff;
    background-color: rgba(0, 123, 255, 0.08);
    white-space: nowrap;
}

.list-action:hover {
    color: #0056b3;
    background-color: rgba(0, 123, 255, 0.15);
}

.list-action-danger {
    color: #ef4444;
    background-color: rgba(239, 68, 68, 0.08);
}

.list-action-danger:hover {
    color: #dc2626;
    background-color: rgba(239, 68, 68, 0.15);
}

.empty-state {
    text-align: center;
    padding: 24px 16px;
    color: #9ca3af;
    border: 1px dashed #e5e7eb;
    border-radius: 8px;
    font-size: 14px;
}

/* Responsive */
@media (max-width: 768px) {
    .setup-page-header {
        flex-direction: column;
        text-align: center;
        padding: 20px;
    }
    .setup-page-header__title {
        font-size: 20px;
    }

    .setup-list li {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }

    .filter_tracker_display_name {
        flex-basis: 100%;
        margin-bottom: 4px;
    }

    .filter_tracker_meta {
        flex-basis: 100%;
        margin-top: 0;
        margin-bottom: 4px;
    }

    .list-action {
        width: 100%;
        justify-content: center;
        flex-basis: 100%;
    }
}
</style>

<?php template_bottom();
