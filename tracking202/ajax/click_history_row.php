<?php
/**
 * Renders a single <tr> for the click history table.
 * Expected variables in scope: $click_row, $html, $mysql, $x, $db (or $queryDb)
 * Used by click_history.php for incremental spy updates.
 */

$html['referer'] = htmlentities((string)($click_row['referer'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['referer_host'] = htmlentities((string)($click_row['referer_host'] ?? ''), ENT_QUOTES, 'UTF-8');

$html['landing'] = htmlentities((string)($click_row['landing'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['landing_host'] = htmlentities((string)($click_row['landing_host'] ?? ''), ENT_QUOTES, 'UTF-8');

$html['outbound'] = htmlentities((string)($click_row['outbound'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['outbound_host'] = htmlentities((string)($click_row['outbound_host'] ?? ''), ENT_QUOTES, 'UTF-8');

if ($click_row['click_cloaking']) {
	if (!$click_row['click_alp']) {
		$html['cloaking'] = htmlentities('http://' . $_SERVER['SERVER_NAME'] . get_absolute_url() . 'tracking202/redirect/cl.php?pci=' . $click_row['click_id_public']);
		$html['cloaking_host'] = htmlentities((string) $_SERVER['SERVER_NAME']);
	} else {
		$html['cloaking'] = htmlentities('http://' . $_SERVER['SERVER_NAME'] . get_absolute_url() . 'tracking202/redirect/off.php?acip=' . $click_row['aff_campaign_id_public'] . '&pci=' . $click_row['click_id_public']);
		$html['cloaking_host'] = htmlentities((string) $_SERVER['SERVER_NAME']);
	}
} else {
	$html['cloaking'] = '';
	$html['cloaking_host'] = '';
}

$html['redirect'] = htmlentities((string)($click_row['redirect'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['redirect_host'] = htmlentities((string)($click_row['redirect_host'] ?? ''), ENT_QUOTES, 'UTF-8');

$html['click_id'] = htmlentities((string)($click_row['click_id'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['click_time'] = date('m/d/y g:ia', (int)$click_row['click_time']);
$html['aff_campaign_id'] = htmlentities((string)($click_row['aff_campaign_id'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['landing_page_nickname'] = htmlentities((string)($click_row['landing_page_nickname'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['ppc_account_id'] = htmlentities((string)($click_row['ppc_account_id'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['text_ad_id'] = htmlentities((string)($click_row['text_ad_id'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['text_ad_name'] = htmlentities((string)($click_row['text_ad_name'] ?? ''), ENT_QUOTES, 'UTF-8');

if ($click_row['aff_campaign_name'] != null) {
	$html['aff_campaign_name'] = htmlentities((string)($click_row['aff_campaign_name'] ?? ''), ENT_QUOTES, 'UTF-8');
} else {
	$html['aff_campaign_name'] = "Redirector url";
}

$html['aff_network_name'] = htmlentities((string)($click_row['aff_network_name'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['ppc_network_name'] = htmlentities((string)($click_row['ppc_network_name'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['ppc_account_name'] = htmlentities((string)($click_row['ppc_account_name'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['ip_address'] = htmlentities((string)($click_row['ip_address'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['click_cpc'] = htmlentities((string) dollar_format($click_row['click_cpc'] ?? 0), ENT_QUOTES, 'UTF-8');
$html['keyword'] = htmlentities((string)($click_row['keyword'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['click_lead'] = htmlentities((string)($click_row['click_lead'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['click_filtered'] = htmlentities((string)($click_row['click_filtered'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['device_name'] = htmlentities((string)($click_row['device_name'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['browser_name'] = htmlentities((string)($click_row['browser_name'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['platform_name'] = htmlentities((string)($click_row['platform_name'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['country_code'] = htmlentities((string)($click_row['country_code'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['country_name'] = htmlentities((string)($click_row['country_name'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['region_name'] = htmlentities((string)($click_row['region_name'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['city_name'] = htmlentities((string)($click_row['city_name'] ?? ''), ENT_QUOTES, 'UTF-8');
$html['isp_name'] = htmlentities((string)($click_row['isp_name'] ?? ''), ENT_QUOTES, 'UTF-8');

if ($html['referer']) {
	$parsed = parse_url((string) $html['referer']);
	if (empty($parsed['scheme'])) {
		$html['referer'] = 'http://' . $html['referer'];
	}
}

$html['row_class'] = 'item';
if ($x == 0) {
	$html['row_class'] = 'item alt';
	$x = 1;
} else {
	$x--;
}

$ppc_network_icon = pcc_network_icon($click_row['ppc_network_name'], $click_row['ppc_account_name']);

if (!$click_row['type_name']) {
	$html['device_type'] = '<span id="device-tooltip"><span data-toggle="tooltip" title="Browser: ' . $html['browser_name'] . '<br/> Platform: ' . $html['platform_name'] . ' <br/>Device: ' . $html['device_name'] . '"><img title="' . $click_row['type_name'] . '" src="' . get_absolute_url() . '202-img/icons/platforms/other.png/></span></span>';
} else {
	$html['device_type'] = '<span id="device-tooltip"><span data-toggle="tooltip" title="Browser: ' . $html['browser_name'] . '<br/> Platform: ' . $html['platform_name'] . ' <br/>Device: ' . $html['device_name'] . '"><img title="' . $click_row['type_name'] . '" src="' . get_absolute_url() . '202-img/icons/platforms/' . $click_row['type_name'] . '.png"/></span></span> <img src="' . get_absolute_url() . '202-img/icons/browsers/' . getBrowserIcon($html['browser_name']) . '.png">';
}

if (!$html['country_code']) {
	$html['country_code'] = 'non';
}

if ($click_row['click_alp'] == 1) {
	$html['aff_campaign_name'] = $html['landing_page_nickname'];
}
?>
					<tr class="new-click" style="display:none;">
						<td id="<?php echo $html['click_id']; ?>"><?php printf('%s', $html['click_id']); ?></td>
						<td style="text-align:left; padding-left:10px;"><?php echo $html['click_time']; ?></td>
						<td class="device_info"><?php echo $html['device_type']; ?></td>
						<td class="geo"><span data-toggle="tooltip" <?php echo 'title="' . $html['country_name'] . ' (' . $html['country_code'] . '), ' . $html['city_name'] . ' (' . $html['region_name'] . ')"'; ?>><img src="<?php echo get_absolute_url(); ?>202-img/flags/<?php echo strtolower((string) $html['country_code']); ?>.png"></span></td>
						<td class="isp"><?php if ($html['isp_name']) echo $html['isp_name'];
						else echo "-" ?></td>
						<td class="filter">
							<?php if ($click_row['click_filtered'] == '1') { ?>
								<img style="margin-right: auto;" src="<?php echo get_absolute_url(); ?>202-img/icons/16x16/delete.png" alt="Filtered Out Click" title="filtered out click" />
							<?php } elseif ($click_row['click_lead'] == '1') { ?>
								<img style="margin-right: auto;" src="<?php echo get_absolute_url(); ?>202-img/icons/16x16/money_dollar.png" alt="Converted Click" title="converted click" width="16px" height="16px" />
							<?php } else { ?>
								<img style="margin-right: auto;" src="<?php echo get_absolute_url(); ?>202-img/icons/16x16/add.png" alt="Real Click" title="real click" />
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
							<?php if ($html['referer'] != '') {
								printf('<a href="%s" target="_new" ><img src="%s202-img/icons/16x16/control_end_blue.png" alt="Referer" title="Referer: %s"/></a></div>', $html['referer'], get_absolute_url(), $html['referer']);
							} ?>
							<?php if ($html['landing'] != '') {
								printf('<a href="%s" target="_new"><img src="%s202-img/icons/16x16/control_pause_blue.png" alt="Landing"  title="Landing Page: %s"/></a>', $html['landing'], get_absolute_url(), $html['landing']);
							} ?>
							<?php if (($html['outbound'] != '') and ($click_row['click_out'] == 1)) {
								printf('<a href="%s" target="_new"><img src="%s202-img/icons/16x16/control_play_blue.png" alt="Outbound" title="Outbound: %s"/></a>', $html['outbound'], get_absolute_url(), $html['outbound']);
							} ?>
							<?php if (($html['cloaking'] != '') and ($click_row['click_out'] == 1)) {
								printf('<a href="%s" target="_new"><img src="%s202-img/icons/16x16/control_equalizer_blue.png" alt="Cloaking" title="Cloaked Referer: %s"/></a>', $html['cloaking'], get_absolute_url(), $html['cloaking']);
							} ?>
							<?php if (($html['redirect'] != '') and ($click_row['click_out'] == 1)) {
								printf('<a href="%s" target="_new"><img src="%s202-img/icons/16x16/control_fastforward_blue.png" alt="Redirection" title="Redirect: %s"/></a>', $html['redirect'], get_absolute_url(), $html['redirect']);
							} ?>
						</td>
						<td class="keyword">
							<div style="text-overflow: ellipsis; overflow : hidden; white-space: nowrap; width: 250px;" title="<?php if ($html['keyword']) echo $html['keyword'];
						else echo "-";   ?>"><?php if ($html['keyword']) echo "<em>" . $html['keyword'] . "</em>";
								else echo "-"; ?></div>
						</td>
					</tr>
