<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -17) . '/202-config/connect.php');

AUTH::require_user();

$incremental = false;
$isSpy = (isset($_GET['spy']) && $_GET['spy'] == 1);

// Shared SQL — identical for spy and non-spy paths
$command = "SELECT 2c.click_id, 2c.click_time, 2c.click_alp, text_ad_name, aff_campaign_name, aff_campaign_id_public, landing_page_nickname, ppc_network_name, ppc_account_name, ip_address, keyword, 2c.click_out, click_lead, click_filtered, click_id_public, click_cloaking, 2c.click_referer_site_url_id, click_landing_site_url_id, click_outbound_site_url_id, click_cloaking_site_url_id, click_redirect_site_url_id,	2b.browser_name, 2p.platform_name, 2d.device_name, 202_device_types.type_name, 2cy.country_name, 2cy.country_code, 2rg.region_name, 202_locations_city.city_name, 2is.isp_name,
2su.site_url_address AS referer,2sd.site_domain_host AS referer_host,
2cl.site_url_address AS landing,2cld.site_domain_host AS landing_host,
2co.site_url_address AS outbound,2cod.site_domain_host AS outbound_host,
2cc.site_url_address AS cloaking,2ccd.site_domain_host AS cloaking_host,
2credir.site_url_address AS redirect,2credird.site_domain_host AS redirect_host
FROM 202_dataengine AS 2c
LEFT JOIN 202_clicks_record USING (click_id)
LEFT JOIN 202_clicks_site AS 2cs ON (2c.click_id = 2cs.click_id)
LEFT JOIN 202_aff_campaigns AS 2ac ON (2c.aff_campaign_id = 2ac.aff_campaign_id)
LEFT JOIN 202_ppc_accounts AS 2pa ON (2c.ppc_account_id = 2pa.ppc_account_id)
LEFT JOIN 202_ppc_networks AS 2pn ON (2pa.ppc_network_id = 2pn.ppc_network_id)
LEFT JOIN 202_landing_pages ON (202_landing_pages.landing_page_id = 2c.landing_page_id)
LEFT JOIN 202_text_ads AS 2ta ON (2c.text_ad_id = 2ta.text_ad_id)
LEFT JOIN 202_ips AS 2i ON (2c.ip_id = 2i.ip_id)
LEFT JOIN 202_keywords AS 2k ON (2c.keyword_id = 2k.keyword_id)
LEFT JOIN 202_browsers AS 2b ON (2c.browser_id = 2b.browser_id)
LEFT JOIN 202_platforms AS 2p ON (2c.platform_id = 2p.platform_id)
LEFT JOIN 202_device_models AS 2d ON (2c.device_id = 2d.device_id)
LEFT JOIN 202_device_types ON (202_device_types.type_id = 2d.device_type)
LEFT JOIN 202_locations_country AS 2cy ON (2c.country_id = 2cy.country_id)
LEFT JOIN 202_locations_region AS 2rg ON (2c.region_id = 2rg.region_id)
LEFT JOIN 202_locations_city ON (202_locations_city.city_id = 2c.city_id)
LEFT JOIN 202_locations_isp AS 2is ON (2c.isp_id = 2is.isp_id)
LEFT JOIN 202_site_urls AS 2su ON (2c.click_referer_site_url_id = 2su.site_url_id)
LEFT JOIN 202_site_urls as 2cl ON (click_landing_site_url_id = 2cl.site_url_id)
LEFT JOIN 202_site_urls as 2co ON (click_outbound_site_url_id = 2co.site_url_id)
LEFT JOIN 202_site_urls as 2cc ON (click_cloaking_site_url_id = 2cc.site_url_id)
LEFT JOIN 202_site_urls as 2credir ON (click_redirect_site_url_id = 2credir.site_url_id)
LEFT JOIN 202_site_domains AS 2sd ON (2su.site_domain_id = 2sd.site_domain_id)
LEFT JOIN 202_site_domains as 2cld ON (2cld.site_domain_id = 2cl.site_domain_id)
LEFT JOIN 202_site_domains as 2cod ON (2cod.site_domain_id = 2co.site_domain_id)
LEFT JOIN 202_site_domains as 2ccd ON (2ccd.site_domain_id = 2cc.site_domain_id)
LEFT JOIN 202_site_domains as 2credird ON (2credird.site_domain_id = 2credir.site_domain_id)
";

$db_table = "2c";

if ($isSpy) {
	// Detect incremental mode before calling query() so we can skip
	// the count query and LIMIT clause that are wasted for incremental
	$since = null;
	if (isset($_GET['since']) && is_numeric($_GET['since'])) {
		$since = (int)$_GET['since'];
	}
	$incremental = ($since !== null);

	// Incremental: skip count (result discarded) and limit (would silently
	// drop new clicks under high traffic since the since-filter already bounds rows)
	$spyCount = $incremental ? false : null;
	$spyLimit = $incremental ? false : null;

	$extra_where = ($since !== null) ? 'AND click_time >= ' . $since : null;
	$query = query($command, $db_table, null, null, null, null, null, $spyLimit, $spyCount, true, $extra_where);
} else {
	$offset = isset($_POST['offset']) && is_numeric($_POST['offset']) ? (int)$_POST['offset'] : 0;
	$order = isset($_POST['order']) ? $_POST['order'] : null;
	$query = query($command, $db_table, null, null, null, $order, $offset);
}


//run query — use read-only connection for spy view
$click_sql = $query['click_sql'];
$queryDb = ($isSpy && $dbro instanceof mysqli) ? $dbro : $db;
$click_result = $queryDb->query($click_sql) or record_mysql_error($click_sql);

// For incremental spy mode, output just the new rows and exit early
if (!empty($incremental)) {
	AUTH::set_timezone($_SESSION['user_timezone']);
	if ($click_result->num_rows == 0) {
		// No new rows — output just the time marker
		echo '<span id="spy-latest-time" data-time="' . (int)$since . '"></span>';
		exit;
	}
	$html = [];
	while ($click_row = $click_result->fetch_array(MYSQLI_ASSOC)) {
		$tr_attrs = 'class="new-click" style="display:none;" data-click-time="' . (int)$click_row['click_time'] . '" data-click-id="' . htmlentities((string)($click_row['click_id'] ?? ''), ENT_QUOTES, 'UTF-8') . '"';
		include __DIR__ . '/click_history_row.php';
	}
	exit;
}

$html['from'] = htmlentities((string)$query['from'], ENT_QUOTES, 'UTF-8');
$html['to'] = htmlentities((string)$query['to'], ENT_QUOTES, 'UTF-8');
$html['rows'] = htmlentities((string)$query['rows'], ENT_QUOTES, 'UTF-8');
$html['order'] = htmlentities((string)($_POST['order'] ?? ''), ENT_QUOTES, 'UTF-8');
?>
<div class="row" style="margin-top: 10px;">
	<div class="col-xs-6">
		<span class="infotext"><?php printf('<div class="results">Results <b>%s - %s</b> of <b>%s</b></div>', $html['from'], $html['to'], $html['rows']);  ?></span>
	</div>
	<div class="col-xs-6 text-right" style="top: -10px;">
		<img style="margin-bottom:2px;" src="<?php echo get_absolute_url(); ?>202-img/icons/16x16/page_white_excel.png" />
		<a style="font-size:12px;" target="_new" href="<?php echo get_absolute_url(); ?>tracking202/visitors/download/">
			<strong>Download to excel</strong>
		</a>
	</div>
</div>
<?php

//set the timezone for the user, to display dates in their timezone
AUTH::set_timezone($_SESSION['user_timezone']);

//start displaying the data
?>
<div class="row">
	<div class="col-xs-12" style="margin-top: 10px;">
		<table class="table table-bordered" id="stats-table">
			<thead>
				<tr style="background-color: #f2fbfa;">
					<td>Subid</td>
					<td style="text-align:left; padding-left:10px;">Date</td>
					<td>User Agent</td>
					<td>GEO</td>
					<td>ISP/Carrier</td>
					<td>Click</td>
					<td>IP</td>
					<td>PPC Account</td>
					<td>Offer / LP</td>
					<td>Referer</td>
					<td>Text Ad</td>
					<td>Links</td>
					<td>Keyword</td>
				</tr>
			</thead>
			<tbody>

				<?php

				$spyLatestTime = 0; // Track latest click_time for incremental fetching
				$new = true; // Initialize new clicks flag for spy animation

				//if there is no clicks to display let them know :(
				if ($click_result->num_rows == 0) {
				?>
				<tr><td colspan="13" style="text-align: center; font-size: 14px; border-bottom: 1px rgb(234,234,234) solid; padding: 10px;">You have no data to display with your above filters currently.</td></tr>
				<?php if ($isSpy) { ?>
				<tr><td colspan="13" style="text-align: center; font-size: 14px; border-bottom: 1px rgb(234,234,234) solid; padding: 10px;">The spy view only shows clicks activity within the past 24 hours.</td></tr>
				<?php }
				}

				//now display all the clicks — row rendering delegated to click_history_row.php
				while ($click_row = $click_result->fetch_array(MYSQLI_ASSOC)) {
					// Track latest time for spy incremental fetching
					if ((int)$click_row['click_time'] > $spyLatestTime) {
						$spyLatestTime = (int)$click_row['click_time'];
					}

					// Determine <tr> attributes for spy new-click animation
					$diff = time() - $click_row['click_time'];
					if (($diff > 5) and ($new == true)) {
						$new = false;
					}
					if (($diff <= 5) and ($new == true)) {
						$tr_attrs = 'class="new-click" style="display:none;"';
					} else {
						$tr_attrs = '';
					}

					include __DIR__ . '/click_history_row.php';
				}

				?>
			</tbody>
		</table>
		<script type="text/javascript">
			//tooltips int
			$("[data-toggle=tooltip]").tooltip({
				html: true
			});
		</script>
<?php if ($isSpy && $spyLatestTime > 0) { ?>
		<span id="spy-latest-time" data-time="<?php echo $spyLatestTime; ?>"></span>
<?php } ?>
	</div>
</div>

<?php if (($query['pages'] > 1) and (($_GET['spy'] ?? '') != 1)) { ?>
	<div class="row">
		<div class="col-xs-12 text-center">
			<div class="pagination" id="table-pages">
				<ul>
					<?php if ($query['offset'] > 0) {
						printf(' <li class="previous"><a class="fui-arrow-left" onclick="loadContent(\'%stracking202/ajax/click_history.php\',\'%s\',\'%s\');"></a></li>', get_absolute_url(), $query['offset'] - 1, $html['order'] ?? '');
					}

					for ($i = 0; $i < $query['pages']; $i++) {
						if (($i >= $query['offset'] - 10) and ($i < $query['offset'] + 11)) {
							$class = '';
							if ($query['offset'] == $i) {
								$class = 'class="active"';
							}
							printf(' <li %s><a onclick="loadContent(\'%stracking202/ajax/click_history.php\',\'%s\',\'%s\');">%s</a></li>', $class, get_absolute_url(), $i, $html['order'] ?? '', $i + 1);
						}
					}

					if ($query['offset'] < $query['pages'] - 1) {
						printf(' <li class="next"><a class="fui-arrow-right" onclick="loadContent(\'%stracking202/ajax/click_history.php\',\'%s\',\'%s\');"></a></li>', get_absolute_url(), $query['offset'] + 1, $html['order'] ?? '');
					}
					?>
				</ul>
			</div>
		</div>
	</div>
<?php } ?>
