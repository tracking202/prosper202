<?php
declare(strict_types=1);
/**
 * Renders a single <tr> for the click history table.
 * Expected variables in scope:
 *   $click_row (assoc array from DB)
 *   $html (array, may contain prior values)
 *   $tr_attrs (string, attributes for the <tr> tag)
 * Included by click_history.php inside a while loop for both full and incremental renders.
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

$ppc_network_icon = pcc_network_icon($click_row['ppc_network_name'], $click_row['ppc_account_name']);
$html['type_name'] = htmlentities((string)($click_row['type_name'] ?? ''), ENT_QUOTES, 'UTF-8');

if (!$click_row['type_name']) {
	$html['device_type'] = '<span id="device-tooltip"><span data-toggle="tooltip" title="Browser: ' . $html['browser_name'] . '<br/> Platform: ' . $html['platform_name'] . ' <br/>Device: ' . $html['device_name'] . '"><img title="' . $html['type_name'] . '" src="' . get_absolute_url() . '202-img/icons/platforms/other.png"/></span></span>';
} else {
	$html['device_type'] = '<span id="device-tooltip"><span data-toggle="tooltip" title="Browser: ' . $html['browser_name'] . '<br/> Platform: ' . $html['platform_name'] . ' <br/>Device: ' . $html['device_name'] . '"><img title="' . $html['type_name'] . '" src="' . get_absolute_url() . '202-img/icons/platforms/' . urlencode((string)$click_row['type_name']) . '.png"/></span></span> <img src="' . get_absolute_url() . '202-img/icons/browsers/' . urlencode(getBrowserIcon($html['browser_name'])) . '.png">';
}

if (!$html['country_code']) {
	$html['country_code'] = 'non';
}

if ($click_row['click_alp'] == 1) {
	$html['aff_campaign_name'] = $html['landing_page_nickname'];
}
?>
					<tr <?php echo $tr_attrs ?? ''; ?>>
						<td id="<?php echo $html['click_id']; ?>"><?php printf('%s', $html['click_id']); ?></td>
						<td style="text-align:left; padding-left:10px;"><?php echo $html['click_time']; ?></td>
						<td class="device_info"><?php echo $html['device_type']; ?></td>
						<td class="geo"><span data-toggle="tooltip" <?php echo 'title="' . $html['country_name'] . ' (' . $html['country_code'] . '), ' . $html['city_name'] . ' (' . $html['region_name'] . ')"'; ?>><img src="<?php echo get_absolute_url(); ?>202-img/flags/<?php echo strtolower((string) $html['country_code']); ?>.png"></span></td>
						<td class="isp"><?php if ($html['isp_name']) echo $html['isp_name'];
						else echo "-" ?></td>
						<td class="filter">
							<?php if ($click_row['click_filtered'] == '1') { ?>
								<span class="label label-default" title="This click was filtered out (bot / rules)">Filtered</span>
							<?php } elseif ($click_row['click_lead'] == '1') { ?>
								<span class="label label-success" title="This click converted into a lead / sale">Lead</span>
							<?php } else { ?>
								<span class="label label-primary" title="A real (unfiltered) click">Real</span>
							<?php } ?>
						</td>
						<td class="ip"><?php echo $html['ip_address']; ?></td>
						<td class="ppc"><?php echo $ppc_network_icon; ?></td>
						<td class="aff"><?php echo $html['aff_campaign_name']; ?></td>
						<td class="referer_big">
							<div style="text-overflow: ellipsis; overflow : hidden; white-space: nowrap; width: 150px;" title="<?php if ($html['referer']) echo $html['referer'];
						else echo "-";   ?>"><?php
								printf('<a href="%s" target="_new" title="Referer">%s</a>', $html['referer'], $html['referer_host']); ?></div>
						</td>
						<td class="ad"><?php if ($html['text_ad_name']) echo $html['text_ad_name'];
						else echo "-"; ?></td>
						<td class="referer">
							<?php
							// Journey links: the visitor's referer → landing → outbound
							// → cloaked → redirect hops, as labelled Font Awesome icons
							// (scalable + on-palette) instead of opaque 16x16 PNGs.
							$journeyStyle = 'color:#2f6fdd; margin-right:6px;';
							if ($html['referer'] != '') {
								printf('<a href="%s" target="_new" style="%s" aria-label="Referer" title="Referer: %s"><i class="fa fa-sign-in"></i></a>', $html['referer'], $journeyStyle, $html['referer']);
							}
							if ($html['landing'] != '') {
								printf('<a href="%s" target="_new" style="%s" aria-label="Landing page" title="Landing Page: %s"><i class="fa fa-file-o"></i></a>', $html['landing'], $journeyStyle, $html['landing']);
							}
							if (($html['outbound'] != '') and ($click_row['click_out'] == 1)) {
								printf('<a href="%s" target="_new" style="%s" aria-label="Outbound" title="Outbound: %s"><i class="fa fa-external-link"></i></a>', $html['outbound'], $journeyStyle, $html['outbound']);
							}
							if (($html['cloaking'] != '') and ($click_row['click_out'] == 1)) {
								printf('<a href="%s" target="_new" style="%s" aria-label="Cloaked referer" title="Cloaked Referer: %s"><i class="fa fa-user-secret"></i></a>', $html['cloaking'], $journeyStyle, $html['cloaking']);
							}
							if (($html['redirect'] != '') and ($click_row['click_out'] == 1)) {
								printf('<a href="%s" target="_new" style="%s" aria-label="Redirect" title="Redirect: %s"><i class="fa fa-forward"></i></a>', $html['redirect'], $journeyStyle, $html['redirect']);
							}
							?>
						</td>
						<td class="keyword">
							<div style="text-overflow: ellipsis; overflow : hidden; white-space: nowrap; width: 250px;" title="<?php if ($html['keyword']) echo $html['keyword'];
						else echo "-";   ?>"><?php if ($html['keyword']) echo "<em>" . $html['keyword'] . "</em>";
								else echo "-"; ?></div>
						</td>
					</tr>
