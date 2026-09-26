<?php
declare(strict_types=1);
/**
 * Renders a single <tr> of the click history table (Visitors and Spy, on the
 * v2 shell).
 *
 * Expected variables in scope:
 *   $click_row (assoc array from DB)
 *   $html      (array, may contain prior values)
 *   $tr_attrs  (string, extra attributes for the <tr> tag)
 * Included by click_history.php inside a while loop for both full and
 * incremental renders. A click with conversion rows gets a button that opens
 * their breakdown (data-p202-breakdown). Every row carries data-click-id and
 * data-click-time, which 202-js/p202-overview.js uses to put new Spy rows on
 * top without repeating one it already shows.
 */

$html['referer'] = htmlentities(safe_url((string)($click_row['referer'] ?? '')), ENT_QUOTES, 'UTF-8');
$html['referer_host'] = htmlentities((string)($click_row['referer_host'] ?? ''), ENT_QUOTES, 'UTF-8');

$html['landing'] = htmlentities(safe_url((string)($click_row['landing'] ?? '')), ENT_QUOTES, 'UTF-8');

$html['outbound'] = htmlentities(safe_url((string)($click_row['outbound'] ?? '')), ENT_QUOTES, 'UTF-8');

if ($click_row['click_cloaking']) {
	if (!$click_row['click_alp']) {
		$html['cloaking'] = htmlentities('http://' . $_SERVER['SERVER_NAME'] . get_absolute_url() . 'tracking202/redirect/cl.php?pci=' . $click_row['click_id_public']);
	} else {
		$html['cloaking'] = htmlentities('http://' . $_SERVER['SERVER_NAME'] . get_absolute_url() . 'tracking202/redirect/off.php?acip=' . $click_row['aff_campaign_id_public'] . '&pci=' . $click_row['click_id_public']);
	}
} else {
	$html['cloaking'] = '';
}

$html['redirect'] = htmlentities(safe_url((string)($click_row['redirect'] ?? '')), ENT_QUOTES, 'UTF-8');

$html['click_id'] = htmlentities((string)($click_row['click_id'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['click_time'] = date('m/d/y g:ia', (int)$click_row['click_time']);
$html['landing_page_nickname'] = htmlentities((string)($click_row['landing_page_nickname'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['text_ad_name'] = htmlentities((string)($click_row['text_ad_name'] ?? ''), ENT_QUOTES, 'UTF-8');

if (!empty($click_row['aff_campaign_name'])) {
	$html['aff_campaign_name'] = htmlentities((string)$click_row['aff_campaign_name'], ENT_QUOTES, 'UTF-8');
} else {
	$html['aff_campaign_name'] = "Redirector url";
}

$html['ip_address'] = htmlentities((string)($click_row['ip_address'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['keyword'] = htmlentities((string)($click_row['keyword'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['device_name'] = htmlentities((string)($click_row['device_name'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['browser_name'] = htmlentities((string)($click_row['browser_name'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['platform_name'] = htmlentities((string)($click_row['platform_name'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['country_code'] = htmlentities((string)($click_row['country_code'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['country_name'] = htmlentities((string)($click_row['country_name'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['region_name'] = htmlentities((string)($click_row['region_name'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['city_name'] = htmlentities((string)($click_row['city_name'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['isp_name'] = htmlentities((string)($click_row['isp_name'] ?? ''), ENT_QUOTES, 'UTF-8');

if ($html['referer']) {
	// Parse the raw URL before HTML encoding — htmlentities breaks & into &amp;
	$raw_referer = safe_url((string)($click_row['referer'] ?? ''));
	$parsed = parse_url($raw_referer);
	if ($parsed !== false && empty($parsed['scheme'])) {
		$html['referer'] = htmlentities('http://' . $raw_referer, ENT_QUOTES, 'UTF-8');
	}
}

// The traffic source's names are escaped before the icon helper puts them in
// an attribute, which it does as given.
$ppc_network_icon = pcc_network_icon(
	htmlentities((string)($click_row['ppc_network_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
	htmlentities((string)($click_row['ppc_account_name'] ?? ''), ENT_QUOTES, 'UTF-8')
);
$html['type_name'] = htmlentities((string)($click_row['type_name'] ?? ''), ENT_QUOTES, 'UTF-8');

// The device, browser and platform in one tooltip over the device-type icon,
// as the classic table had them. Bootstrap's tooltip sanitises the HTML.
$deviceTip = 'Browser: ' . $html['browser_name'] . '<br>Platform: ' . $html['platform_name'] . '<br>Device: ' . $html['device_name'];
$deviceIcon = $click_row['type_name'] ? urlencode((string)$click_row['type_name']) : 'other';
$html['device_type'] = '<span data-bs-toggle="tooltip" data-bs-html="true" tabindex="0" title="' . htmlspecialchars($deviceTip, ENT_QUOTES, 'UTF-8') . '">'
	. '<img alt="' . ($html['type_name'] !== '' ? $html['type_name'] : 'Other device') . '" src="' . get_absolute_url() . '202-img/icons/platforms/' . $deviceIcon . '.png"></span>';
if ($click_row['type_name']) {
	$html['device_type'] .= ' <img alt="' . $html['browser_name'] . '" src="' . get_absolute_url() . '202-img/icons/browsers/' . urlencode(getBrowserIcon($html['browser_name'])) . '.png">';
}

if (!$html['country_code']) {
	$html['country_code'] = 'non';
}

if ($click_row['click_alp'] == 1) {
	$html['aff_campaign_name'] = $html['landing_page_nickname'];
}

$geoTip = $html['country_name'] . ' (' . $html['country_code'] . '), ' . $html['city_name'] . ' (' . $html['region_name'] . ')';
?>
					<tr data-click-id="<?php echo $html['click_id']; ?>" data-click-time="<?php echo (int)$click_row['click_time']; ?>" <?php echo $tr_attrs ?? ''; ?>>
						<td class="num"><?php echo $html['click_id']; ?></td>
						<td class="text-nowrap"><?php echo $html['click_time']; ?></td>
						<td class="text-nowrap"><?php echo $html['device_type']; ?></td>
						<td><span data-bs-toggle="tooltip" tabindex="0" title="<?php echo $geoTip; ?>"><img alt="<?php echo $html['country_code']; ?>" src="<?php echo get_absolute_url(); ?>202-img/flags/<?php echo strtolower((string) $html['country_code']); ?>.png"></span></td>
						<td><?php echo $html['isp_name'] !== '' ? $html['isp_name'] : '-'; ?></td>
						<td>
							<?php if ($click_row['click_filtered'] == '1') { ?>
								<span class="p202-pill" title="This click was filtered out (bot / rules)">Filtered</span>
							<?php } elseif ($click_row['click_lead'] == '1') { ?>
								<span class="p202-pill p202-pill--good" title="This click converted into a lead / sale">Lead</span>
							<?php } else { ?>
								<span class="p202-pill p202-pill--accent" title="A real (unfiltered) click">Real</span>
							<?php } ?>
							<?php $conversionRows = (int) ($click_row['conversion_rows'] ?? 0); ?>
							<?php if ($conversionRows > 0) { ?>
								<?php $conversionLabel = $conversionRows === 1 ? '1 conversion' : $conversionRows . ' conversions'; ?>
								<button type="button" class="btn btn-link btn-sm p-0 ms-1 align-baseline text-nowrap" data-p202-breakdown="<?php echo htmlspecialchars(get_absolute_url() . 'tracking202/ajax/click_conversions.php?click_id=' . (int) $click_row['click_id'], ENT_QUOTES, 'UTF-8'); ?>" data-p202-breakdown-title="<?php echo 'Conversions on click ' . $html['click_id']; ?>" aria-label="<?php echo $conversionLabel . ' on click ' . $html['click_id']; ?>"><?php echo $conversionLabel; ?></button>
							<?php } ?>
						</td>
						<td class="text-nowrap"><?php echo $html['ip_address']; ?></td>
						<td><?php echo $ppc_network_icon; ?></td>
						<td><?php echo $html['aff_campaign_name']; ?></td>
						<td>
							<?php if ($html['referer'] !== '') { ?>
								<a class="d-inline-block text-truncate align-bottom" style="max-width: 150px;" href="<?php echo $html['referer']; ?>" target="_blank" rel="noopener noreferrer" title="<?php echo $html['referer']; ?>"><?php echo $html['referer_host'] !== '' ? $html['referer_host'] : $html['referer']; ?></a>
							<?php } else { ?>
								-
							<?php } ?>
						</td>
						<td><?php echo $html['text_ad_name'] !== '' ? $html['text_ad_name'] : '-'; ?></td>
						<td class="text-nowrap">
							<?php
							// The visitor's journey: referer → landing → outbound →
							// cloaked → redirect, one labelled icon per hop it made.
							$hops = [
								['referer', 'Referer', 'bi-box-arrow-in-right', $html['referer'] !== ''],
								['landing', 'Landing page', 'bi-file-earmark', $html['landing'] !== ''],
								['outbound', 'Outbound', 'bi-box-arrow-up-right', $html['outbound'] !== '' && $click_row['click_out'] == 1],
								['cloaking', 'Cloaked referer', 'bi-incognito', $html['cloaking'] !== '' && $click_row['click_out'] == 1],
								['redirect', 'Redirect', 'bi-skip-forward', $html['redirect'] !== '' && $click_row['click_out'] == 1],
							];
							foreach ($hops as [$key, $label, $icon, $show]) {
								if ($show) {
									printf('<a class="me-2" href="%s" target="_blank" rel="noopener noreferrer" aria-label="%s" title="%s: %s"><i class="bi %s"></i></a>', $html[$key], $label, $label, $html[$key], $icon);
								}
							}
							?>
						</td>
						<td>
							<?php if ($html['keyword'] !== '') { ?>
								<em class="d-inline-block text-truncate align-bottom" style="max-width: 250px;" title="<?php echo $html['keyword']; ?>"><?php echo $html['keyword']; ?></em>
							<?php } else { ?>
								-
							<?php } ?>
						</td>
					</tr>
