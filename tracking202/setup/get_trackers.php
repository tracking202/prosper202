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
						 WHERE 2tr.user_id = '" . $mysql['user_id'] . "' AND (2ac.aff_campaign_deleted='0' OR 2tr.aff_campaign_id = 0) AND 2tr.tracker_id_public = '" . $mysql['tracker_id_public'] . "'";
	// An advanced-landing-page or redirector link has no campaign, so the
	// campaign join is empty for it; before U4 the deleted-campaign filter
	// above excluded exactly those links, and they could not be edited.

	$edit_tracker_result = $db->query($edit_tracker_sql) or record_mysql_error($edit_tracker_sql);
	$edit_tracker_row = $edit_tracker_result->fetch_assoc() ?? [];

	if ($edit_tracker_row === []) {
		$showEdit = false;
	} else {
		$cpc_value = explode(".", (string) $edit_tracker_row['click_cpc'], 2);
		$cpa_value = explode(".", (string) $edit_tracker_row['click_cpa'], 2);
	}
}




require_once __DIR__ . '/_includes/setup_ui.php';

$base = get_absolute_url();
$self = $base . 'tracking202/setup/get_trackers.php';
$token = (string) ($_SESSION['token'] ?? '');
$uid = (int) $_SESSION['user_id'];

// Every list the form offers, rendered once: the v2 page filters them in the
// browser (p202-setup.js) instead of fetching a select per choice.
$campaignOptions = p202_setup_campaign_options($db, $uid);
$simplePageOptions = [];
foreach (p202_setup_rows($db, "SELECT lp.landing_page_id, lp.landing_page_nickname, lp.aff_campaign_id FROM 202_landing_pages AS lp INNER JOIN 202_aff_campaigns AS ac ON (ac.aff_campaign_id = lp.aff_campaign_id) WHERE lp.user_id = '" . $uid . "' AND lp.landing_page_type = '0' AND lp.landing_page_deleted = '0' AND ac.aff_campaign_deleted = '0' ORDER BY lp.landing_page_nickname ASC") as $page) {
	$simplePageOptions[(string) $page['landing_page_id']] = ['label' => (string) $page['landing_page_nickname'], 'data' => ['campaign' => (string) $page['aff_campaign_id']]];
}
$advancedPageOptions = [];
foreach (p202_setup_rows($db, "SELECT landing_page_id, landing_page_nickname FROM 202_landing_pages WHERE user_id = '" . $uid . "' AND landing_page_type = '1' AND landing_page_deleted = '0' ORDER BY landing_page_nickname ASC") as $page) {
	$advancedPageOptions[(string) $page['landing_page_id']] = (string) $page['landing_page_nickname'];
}
$campaignAdOptions = [];
$pageAdOptions = [];
foreach (p202_setup_rows($db, "SELECT text_ad_id, text_ad_name, text_ad_headline, text_ad_description, text_ad_display_url, aff_campaign_id, landing_page_id, text_ad_type FROM 202_text_ads WHERE user_id = '" . $uid . "' AND text_ad_deleted = '0' ORDER BY text_ad_name ASC") as $ad) {
	$entry = ['label' => (string) $ad['text_ad_name'], 'data' => [
		'headline' => (string) $ad['text_ad_headline'],
		'description' => (string) $ad['text_ad_description'],
		'display' => (string) $ad['text_ad_display_url'],
	]];
	if ((string) $ad['text_ad_type'] === '1') {
		$entry['data']['lp'] = (string) $ad['landing_page_id'];
		$pageAdOptions[(string) $ad['text_ad_id']] = $entry;
	} else {
		$entry['data']['campaign'] = (string) $ad['aff_campaign_id'];
		$campaignAdOptions[(string) $ad['text_ad_id']] = $entry;
	}
}
$rotatorOptions = [];
foreach (p202_setup_rows($db, "SELECT id, name FROM 202_rotators WHERE user_id = '" . $uid . "' ORDER BY name ASC") as $rotator) {
	$rotatorOptions[(string) $rotator['id']] = (string) $rotator['name'];
}
$accountOptions = [];
foreach (p202_setup_rows($db, "SELECT pa.ppc_account_id, pa.ppc_account_name, pn.ppc_network_id, pn.ppc_network_name FROM 202_ppc_accounts AS pa INNER JOIN 202_ppc_networks AS pn ON (pn.ppc_network_id = pa.ppc_network_id) WHERE pa.user_id = '" . $uid . "' AND pa.ppc_account_deleted = '0' AND pn.ppc_network_deleted = '0' ORDER BY pn.ppc_network_name ASC, pa.ppc_account_name ASC") as $account) {
	$group = 's' . $account['ppc_network_id'];
	$accountOptions[$group]['label'] = (string) $account['ppc_network_name'];
	$accountOptions[$group]['options'][(string) $account['ppc_account_id']] = ['label' => (string) $account['ppc_account_name'], 'data' => ['source' => (string) $account['ppc_network_id']]];
}

// The form's starting values: a tracker being edited, or the common case.
$row = $showEdit ? $edit_tracker_row : [];
$trackerType = '0';
if ($showEdit && !empty($row['rotator_id'])) {
	$trackerType = '2';
} elseif ($showEdit && (string) ($row['landing_page_type'] ?? '') === '1') {
	$trackerType = '1';
}
$method = $showEdit && $trackerType === '0' && !empty($row['landing_page_id']) ? 'landingpage' : 'directlink';
$costType = $showEdit && isset($row['click_cpa']) && (float) $row['click_cpa'] > 0 ? 'cpa' : 'cpc';
$cloaking = (string) ($row['click_cloaking'] ?? '-1');
$pick = static fn (string $key): string => (string) ($row[$key] ?? '');

// The tracking links already made, with the link each one answers to.
$trackers_sql = "SELECT
	tr.tracker_id, tr.tracker_id_public, tr.tracker_time, tr.rotator_id,
	lp.landing_page_id, lp.landing_page_url, ac.aff_campaign_name, lp.landing_page_nickname, ro.name,
	pv.parameters, pv.placeholders
	FROM 202_trackers AS tr
	LEFT JOIN 202_landing_pages AS lp ON (tr.landing_page_id = lp.landing_page_id)
	LEFT JOIN 202_aff_campaigns AS ac ON (tr.aff_campaign_id = ac.aff_campaign_id)
	LEFT JOIN 202_rotators AS ro ON (tr.rotator_id = ro.id)
	LEFT JOIN 202_ppc_accounts AS ppc ON (tr.ppc_account_id = ppc.ppc_account_id)
	LEFT JOIN (SELECT ppc_network_id, GROUP_CONCAT(parameter) AS parameters, GROUP_CONCAT(placeholder) AS placeholders FROM 202_ppc_network_variables GROUP BY ppc_network_id) AS pv ON (ppc.ppc_network_id = pv.ppc_network_id)
	WHERE tr.user_id ='" . $mysql['user_id'] . "'
	ORDER BY tr.tracker_id DESC";
$trackers = p202_setup_rows($db, $trackers_sql);
$trackerLink = static function (array $tracker) use ($base): array {
	$vars_query = '';
	$parameters = explode(',', $tracker['parameters'] ?? '');
	$placeholders = explode(',', $tracker['placeholders'] ?? '');
	foreach ($parameters as $key => $value) {
		if (isset($placeholders[$key])) {
			$vars_query .= '&' . $value . '=' . $placeholders[$key];
		}
	}
	if ($tracker['landing_page_id']) {
		$parsed_url = parse_url((string) $tracker['landing_page_url']);
		$destination_url = ($parsed_url['scheme'] ?? 'http') . '://' . ($parsed_url['host'] ?? '') . ($parsed_url['path'] ?? '') . '?';
		if (!empty($parsed_url['query'])) {
			$destination_url .= $parsed_url['query'] . '&';
		}
		$destination_url .= 't202id=' . $tracker['tracker_id_public'];
		if (!empty($parsed_url['fragment'])) {
			$destination_url .= '#' . $parsed_url['fragment'];
		}
		$destination_url .= 't202kw=';
		return [(string) $tracker['landing_page_nickname'], 'landing page', $destination_url];
	}
	if ($tracker['rotator_id']) {
		return [(string) $tracker['name'], 'redirector', 'http://' . getTrackingDomain() . $base . 'tracking202/redirect/rtr.php?t202id=' . $tracker['tracker_id_public'] . '&t202kw=' . $vars_query];
	}
	return [(string) $tracker['aff_campaign_name'], 'direct link', 'http://' . getTrackingDomain() . $base . 'tracking202/redirect/dl.php?t202id=' . $tracker['tracker_id_public'] . '&t202kw=' . $vars_query];
};

template_top('Get Trackers', ['ui' => 'v2']); ?>

<div class="p202-page-header p202-page-header--accent">
	<div class="p202-page-header__icon"><i class="bi bi-link-45deg"></i></div>
	<div class="p202-page-header__text">
		<h1 class="p202-page-header__title">Get Links</h1>
		<p class="p202-page-header__desc">A tracking link for each place you buy traffic: it records the source, the cost and the ad before sending the visitor on.</p>
	</div>
</div>

<?php $nothingToLink = $campaignOptions === [] && $advancedPageOptions === [] && $rotatorOptions === []; ?>

<div class="row g-4">
	<div class="col-12 col-lg-6">
		<?php if ($nothingToLink) { ?>
			<div class="p202-empty mb-4">
				<i class="bi bi-link-45deg p202-empty__icon"></i>
				<strong class="p202-empty__title">Nothing to link to yet</strong>
				<div>A tracking link sends visitors to a campaign, a landing page or a redirector. Add a campaign first.</div>
				<div class="p202-empty__action"><a class="btn btn-primary btn-sm" href="<?php echo p202_setup_e($base . 'tracking202/setup/aff_campaigns.php'); ?>">Add a campaign</a></div>
			</div>
		<?php } else { ?>
		<section class="p202-panel">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title"><?php echo $showEdit ? 'Edit tracking link' : 'Get a tracking link'; ?></h2>
				<p class="p202-panel__sub"><?php echo $showEdit ? 'The link stays the same; what it records changes.' : 'Test a new link yourself before you run traffic to it.'; ?></p>
			</div>
			<div class="p202-panel__body">
				<form method="post" id="tracking_form" action="<?php echo p202_setup_e($base . 'tracking202/ajax/generate_tracking_link.php'); ?>" data-p202-ajax-target="#tracking-links">
					<?php echo p202_setup_token_field($token); ?>
					<?php if ($showEdit) { ?>
						<input type="hidden" name="edit_tracker" value="1">
						<input type="hidden" name="tracker_id" value="<?php echo p202_setup_e($editTrackerId); ?>">
					<?php } ?>

					<fieldset class="mb-3" id="tracker-type">
						<legend class="form-label">Send visitors to</legend>
						<?php foreach (['0' => 'A campaign, directly or through a simple landing page', '1' => 'An advanced landing page', '2' => 'A redirector'] as $typeValue => $typeLabel) { ?>
							<div class="form-check">
								<input class="form-check-input" type="radio" name="tracker_type" id="tracker_type<?php echo $typeValue; ?>" value="<?php echo $typeValue; ?>"<?php echo $trackerType === (string) $typeValue ? ' checked' : ''; ?>>
								<label class="form-check-label" for="tracker_type<?php echo $typeValue; ?>"><?php echo p202_setup_e($typeLabel); ?></label>
							</div>
						<?php } ?>
					</fieldset>

					<div data-p202-show-when="tracker_type=0" data-p202-disable-hidden<?php echo $trackerType === '0' ? '' : ' hidden'; ?>>
						<div class="mb-3">
							<label class="form-label" for="aff_campaign_id">Campaign</label>
							<select class="form-select" id="aff_campaign_id" name="aff_campaign_id" required>
								<?php echo p202_setup_options($campaignOptions, $pick('aff_campaign_id') !== '' ? $pick('aff_campaign_id') : p202_setup_only_option($campaignOptions), $campaignOptions === [] ? 'No campaigns yet' : 'Choose a campaign'); ?>
							</select>
							<input type="hidden" name="aff_network_id" value="<?php echo p202_setup_e($pick('aff_network_id')); ?>" data-p202-sync-from="#aff_campaign_id" data-p202-sync-attr="network">
						</div>
						<div class="mb-3">
							<label class="form-label" for="method_of_promotion">Visitors land on</label>
							<select class="form-select" id="method_of_promotion" name="method_of_promotion">
								<?php echo p202_setup_options(['directlink' => 'The offer, directly', 'landingpage' => 'A landing page first'], $method); ?>
							</select>
							<div class="form-text">Directly by default: the link goes straight to the campaign URL.</div>
						</div>
						<div class="mb-3" data-p202-show-when="method_of_promotion=landingpage" data-p202-disable-hidden<?php echo $method === 'landingpage' ? '' : ' hidden'; ?>>
							<label class="form-label" for="landing_page_id">Landing page</label>
							<select class="form-select" id="landing_page_id" name="landing_page_id" required data-p202-filter-by="#aff_campaign_id" data-p202-filter-key="campaign" data-p202-filter-empty="No landing pages for this campaign" data-p202-filter-choose="Choose a landing page">
								<?php echo p202_setup_options($simplePageOptions, $trackerType === '0' ? $pick('landing_page_id') : '', 'Choose a landing page'); ?>
							</select>
						</div>
						<div class="mb-3">
							<label class="form-label" for="text_ad_id">Ad copy</label>
							<select class="form-select" id="text_ad_id" name="text_ad_id" data-p202-filter-by="#aff_campaign_id" data-p202-filter-key="campaign" data-p202-ad-preview="#ad-preview">
								<?php echo p202_setup_options($campaignAdOptions, $trackerType === '0' ? $pick('text_ad_id') : '', 'None', '0'); ?>
							</select>
							<div class="form-text">None by default. Choose the ad this link runs behind, and reports split by ad.</div>
						</div>
					</div>

					<div data-p202-show-when="tracker_type=1" data-p202-disable-hidden<?php echo $trackerType === '1' ? '' : ' hidden'; ?>>
						<div class="mb-3">
							<label class="form-label" for="adv_landing_page_id">Advanced landing page</label>
							<select class="form-select" id="adv_landing_page_id" name="landing_page_id" required>
								<?php echo p202_setup_options($advancedPageOptions, $trackerType === '1' ? $pick('landing_page_id') : p202_setup_only_option($advancedPageOptions), $advancedPageOptions === [] ? 'No advanced landing pages yet' : 'Choose a landing page'); ?>
							</select>
						</div>
						<div class="mb-3">
							<label class="form-label" for="adv_text_ad_id">Ad copy</label>
							<select class="form-select" id="adv_text_ad_id" name="text_ad_id" data-p202-filter-by="#adv_landing_page_id" data-p202-filter-key="lp" data-p202-ad-preview="#ad-preview">
								<?php echo p202_setup_options($pageAdOptions, $trackerType === '1' ? $pick('text_ad_id') : '', 'None', '0'); ?>
							</select>
						</div>
					</div>

					<div class="mb-3" data-p202-show-when="tracker_type=2" data-p202-disable-hidden<?php echo $trackerType === '2' ? '' : ' hidden'; ?>>
						<label class="form-label" for="tracker_rotator">Redirector</label>
						<select class="form-select" id="tracker_rotator" name="tracker_rotator" required>
							<?php echo p202_setup_options($rotatorOptions, $pick('rotator_id') !== '' ? $pick('rotator_id') : p202_setup_only_option($rotatorOptions), $rotatorOptions === [] ? 'No redirectors yet' : 'Choose a redirector'); ?>
						</select>
					</div>

					<div class="mb-3" data-p202-show-when="tracker_type=0,1"<?php echo $trackerType === '2' ? ' hidden' : ''; ?>>
						<span class="form-label d-block">Ad preview</span>
						<div class="card" id="ad-preview">
							<div class="card-body py-2">
								<div class="fw-bold text-primary" data-ad="headline">Luxury Cruise to Mars</div>
								<div class="small" data-ad="description">Visit the Red Planet in style. Low-gravity fun for everyone!</div>
								<div class="small text-success" data-ad="display">www.example.com</div>
							</div>
						</div>
					</div>

					<div class="mb-3">
						<label class="form-label" for="ppc_account_id">Traffic source account</label>
						<select class="form-select" id="ppc_account_id" name="ppc_account_id">
							<?php echo p202_setup_options($accountOptions, $pick('ppc_account_id'), 'No traffic source', ''); ?>
						</select>
						<input type="hidden" name="ppc_network_id" value="<?php echo p202_setup_e($pick('ppc_network_id')); ?>" data-p202-sync-from="#ppc_account_id" data-p202-sync-attr="source">
						<div class="form-text">Grouped by traffic source. Without one, clicks on this link report under no source.</div>
					</div>

					<fieldset class="mb-3">
						<legend class="form-label">Cost</legend>
						<div class="form-check form-check-inline">
							<input class="form-check-input" type="radio" name="cost_type" id="cost_type_cpc" value="cpc"<?php echo $costType === 'cpc' ? ' checked' : ''; ?>>
							<label class="form-check-label" for="cost_type_cpc">Per click (CPC)</label>
						</div>
						<div class="form-check form-check-inline">
							<input class="form-check-input" type="radio" name="cost_type" id="cost_type_cpa" value="cpa"<?php echo $costType === 'cpa' ? ' checked' : ''; ?>>
							<label class="form-check-label" for="cost_type_cpa">Per conversion (CPA)</label>
						</div>
						<?php foreach (['cpc' => $cpc_value, 'cpa' => $cpa_value] as $kind => $parts) {
							$dollars = $showEdit && $costType === $kind ? (string) ($parts[0] ?? '0') : '0';
							$cents = $showEdit && $costType === $kind ? (string) ($parts[1] ?? '00') : '00'; ?>
							<div class="mt-2" data-p202-show-when="cost_type=<?php echo $kind; ?>"<?php echo $costType === $kind ? '' : ' hidden'; ?>>
								<div class="input-group" style="max-width: 18rem;">
									<span class="input-group-text">$</span>
									<input class="form-control" type="text" inputmode="numeric" name="<?php echo $kind; ?>_dollars" id="<?php echo $kind; ?>_dollars" maxlength="2" value="<?php echo p202_setup_e($dollars); ?>" aria-label="<?php echo strtoupper($kind); ?> dollars">
									<span class="input-group-text">.</span>
									<input class="form-control" type="text" inputmode="numeric" name="<?php echo $kind; ?>_cents" id="<?php echo $kind; ?>_cents" maxlength="5" value="<?php echo p202_setup_e($cents); ?>" aria-label="<?php echo strtoupper($kind); ?> cents">
								</div>
								<div class="form-text">Dollars, then cents: as small as 0.00001.</div>
							</div>
						<?php } ?>
					</fieldset>

					<details class="p202-disclosure mb-3" data-p202-remember="setup-get-links-advanced"<?php echo $showEdit && $cloaking !== '-1' ? ' open' : ''; ?>>
						<summary>Advanced <span class="p202-disclosure__hint">cloaking, keyword, bid and referrer tokens, c1–c4</span></summary>
						<div class="p202-disclosure__body">
							<div class="mb-3" data-p202-show-when="tracker_type=0,1"<?php echo $trackerType === '2' ? ' hidden' : ''; ?>>
								<label class="form-label" for="click_cloaking">Cloaking</label>
								<select class="form-select" id="click_cloaking" name="click_cloaking">
									<?php echo p202_setup_options(['-1' => "The campaign's setting", '0' => 'Off for this link', '1' => 'On for this link'], $cloaking); ?>
								</select>
								<div class="form-text">The campaign decides by default.</div>
							</div>
							<?php foreach ([
								't202kw' => ['Keyword token', 'If the traffic source can insert the keyword, its token: {keyword} on Google Ads, {QueryString} on Bing.'],
								't202b' => ['Bid (dynamic CPC) token', 'If the traffic source can insert the bid, its token, for exact cost per click.'],
								't202ref' => ['Referrer token', 'When the real referrer says little, a token the traffic source fills with a better one.'],
								'c1' => ['Tracking ID c1', 'c1 to c4 carry anything you want to report on, up to 350 characters each.'],
								'c2' => ['Tracking ID c2', ''],
								'c3' => ['Tracking ID c3', ''],
								'c4' => ['Tracking ID c4', ''],
							] as $name => [$label, $hint]) { ?>
								<div class="mb-3">
									<label class="form-label" for="<?php echo $name; ?>"><?php echo p202_setup_e($label); ?></label>
									<input class="form-control" type="text" name="<?php echo $name; ?>" id="<?php echo $name; ?>">
									<?php if ($hint !== '') { ?><div class="form-text"><?php echo p202_setup_e($hint); ?></div><?php } ?>
								</div>
							<?php } ?>
						</div>
					</details>

					<div class="p202-form-actions">
						<?php if ($showEdit) { ?>
							<a class="btn btn-link" href="<?php echo p202_setup_e($self); ?>">Cancel</a>
						<?php } ?>
						<button type="submit" class="btn btn-primary" id="get-links"><?php echo $showEdit ? 'Update tracking link' : 'Get tracking link'; ?></button>
					</div>
				</form>
			</div>
		</section>

		<section class="p202-panel mt-4" aria-live="polite">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title">Your link</h2>
			</div>
			<div class="p202-panel__body" id="tracking-links">
				<p class="text-body-secondary mb-0">Choose where visitors go, then Get tracking link: the link appears here, ready to copy.</p>
			</div>
		</section>
		<?php } ?>
	</div>

	<div class="col-12 col-lg-6">
		<section class="p202-panel">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title">Your tracking links</h2>
				<span class="p202-pill p202-pill--accent"><?php echo count($trackers); ?></span>
				<?php if (count($trackers) > 5) { ?>
					<div class="p202-panel__aside"><?php echo p202_setup_list_filter('tracker-list', 'Filter links…'); ?></div>
				<?php } ?>
			</div>
			<div class="p202-panel__body">
				<?php if ($trackers === []) { ?>
					<p class="text-body-secondary mb-0">None yet. The links you get are kept here, to copy or change later.</p>
				<?php } else { ?>
					<ul class="p202-list" id="tracker-list" data-delete-url="<?php echo p202_setup_e($base . 'tracking202/ajax/delete_tracker.php'); ?>">
						<?php foreach ($trackers as $tracker) {
							[$name, $kind, $link] = $trackerLink($tracker);
							$tid = (int) $tracker['tracker_id']; ?>
							<li class="p202-list__item" data-p202-filter-text="<?php echo p202_setup_e($name); ?>" data-tracker-id="<?php echo $tid; ?>">
								<span class="p202-list__name"><?php echo p202_setup_e($name !== '' ? $name : 'Untitled'); ?></span>
								<span class="p202-pill"><?php echo p202_setup_e($kind); ?></span>
								<span class="p202-list__actions">
									<button type="button" class="p202-list__action p202-copy" data-p202-copy="<?php echo p202_setup_e($link); ?>">copy</button>
									<a class="p202-list__action" href="<?php echo p202_setup_e($self . '?edit_tracker_id=' . $tracker['tracker_id_public']); ?>">edit</a>
									<?php if ($userObj->hasPermission("remove_tracker")) { ?>
										<button type="button" class="p202-list__action p202-list__action--danger" data-delete-tracker="<?php echo $tid; ?>">remove</button>
									<?php } ?>
								</span>
								<span class="p202-list__meta">ID <?php echo $tid; ?> · made <?php echo p202_setup_e(date('M j, Y', (int) $tracker['tracker_time'])); ?></span>
							</li>
						<?php } ?>
					</ul>
				<?php } ?>
			</div>
		</section>
	</div>
</div>

<?php echo p202_setup_script_tag($base); ?>
<?php template_bottom();
