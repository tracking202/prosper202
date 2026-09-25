<?php

declare(strict_types=1);

/**
 * The click history: Visitors (a page of the window, POSTed with an offset)
 * and Spy (?spy=1: the last 24 hours; with since/since_id, only the clicks
 * newer than the newest one shown, as bare rows). Drawn on the v2 shell by
 * 202-js/p202-overview.js; the filters are the user's report preferences,
 * which the page applied from its URL. A row with conversions opens their
 * breakdown (click_conversions.php) in the modal drawn below the table.
 */
include_once(substr(__DIR__, 0, -17) . '/202-config/connect.php');
require_once(substr(__DIR__, 0, -17) . '/202-config/functions-ui-overview.php');

AUTH::require_user();

// Draw the view the page rendered, not whatever the stored filters say by
// now (ReportView); a request that carries none reads the stored ones.
$reportView = p202_report_view_begin();

$incremental = false;
$isSpy = (isset($_GET['spy']) && $_GET['spy'] == 1);

// Shared SQL — identical for spy and non-spy paths
$command = "SELECT 2c.click_id, 2c.click_time, 2c.click_alp, text_ad_name, aff_campaign_name, aff_campaign_id_public, landing_page_nickname, ppc_network_name, ppc_account_name, ip_address, keyword, 2c.click_out, click_lead, click_filtered, click_id_public, click_cloaking, 2c.click_referer_site_url_id, click_landing_site_url_id, click_outbound_site_url_id, click_cloaking_site_url_id, click_redirect_site_url_id,	2b.browser_name, 2p.platform_name, 2d.device_name, 202_device_types.type_name, 2cy.country_name, 2cy.country_code, 2rg.region_name, 202_locations_city.city_name, 2is.isp_name,
2su.site_url_address AS referer,2sd.site_domain_host AS referer_host,
2cl.site_url_address AS landing,2cld.site_domain_host AS landing_host,
2co.site_url_address AS outbound,2cod.site_domain_host AS outbound_host,
2cc.site_url_address AS cloaking,2ccd.site_domain_host AS cloaking_host,
2credir.site_url_address AS redirect,2credird.site_domain_host AS redirect_host,
(SELECT COUNT(*) FROM 202_conversion_logs AS cvl WHERE cvl.click_id = 2c.click_id) AS conversion_rows
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
	$since_id = null;
	if (isset($_GET['since']) && is_numeric($_GET['since'])) {
		$since = (int)$_GET['since'];
	}
	if (isset($_GET['since_id']) && is_numeric($_GET['since_id'])) {
		$since_id = (int)$_GET['since_id'];
	}
	$incremental = ($since !== null);

	// Incremental: skip count (result discarded) and limit (would silently
	// drop new clicks under high traffic since the since-filter already bounds rows)
	$spyCount = $incremental ? false : null;
	$spyLimit = $incremental ? false : null;

	if ($since !== null && $since_id !== null) {
		$extra_where = 'AND (click_time > ' . $since . ' OR (click_time = ' . $since . ' AND 2c.click_id > ' . $since_id . '))';
	} elseif ($since !== null) {
		$extra_where = 'AND click_time > ' . $since;
	} else {
		$extra_where = null;
	}
	// Spy view uses its own 24-hour time window (applied inside query() when
	// $isspy=true), so skip the calendar time preference — otherwise a saved
	// date range like "yesterday" or "last month" silently excludes all recent
	// clicks because the ranges don't overlap.
	$use_ro = ($dbro instanceof mysqli);
	$query = query($command, $db_table, false, null, null, null, null, $spyLimit, $spyCount, $use_ro, $extra_where);
} else {
	$offset = isset($_POST['offset']) && is_numeric($_POST['offset']) ? (int)$_POST['offset'] : 0;
	// ORDER BY is an identifier context - map the request value through an
	// allowlist of permitted sort expressions. Unknown values fall back to the
	// query() default (click_time DESC); never pass raw input through.
	$allowed_orders = [
		'click_time DESC' => 'click_time DESC',
		'click_time ASC'  => 'click_time ASC',
	];
	$requested_order = isset($_POST['order']) ? trim((string)$_POST['order']) : '';
	$order = ($requested_order !== '' && isset($allowed_orders[$requested_order]))
		? $allowed_orders[$requested_order]
		: null;
	$query = query($command, $db_table, null, null, null, $order, $offset);
}


//run query — use read-only connection for spy view
$click_sql = $query['click_sql'];
$query_db = ($isSpy && $dbro instanceof mysqli) ? $dbro : $db;
$click_result = $query_db->query($click_sql) or record_mysql_error($click_sql);

// For incremental spy mode, output just the new rows and exit early. The
// newest-click marker is a hidden row, so a table body parses it.
if ($incremental) {
	AUTH::set_timezone($_SESSION['user_timezone']);
	$html = [];
	$latestTime = (int)$since;
	$latestId = (int)($since_id ?? 0);
	while ($click_row = $click_result->fetch_array(MYSQLI_ASSOC)) {
		$ct = (int)$click_row['click_time'];
		$ci = (int)$click_row['click_id'];
		if ($ct > $latestTime || ($ct === $latestTime && $ci > $latestId)) {
			$latestTime = $ct;
			$latestId = $ci;
		}
		$tr_attrs = '';
		include __DIR__ . '/click_history_row.php';
	}
	echo '<tr hidden data-p202-spy-latest data-time="' . $latestTime . '" data-id="' . $latestId . '"></tr>';
	exit;
}

//set the timezone for the user, to display dates in their timezone
AUTH::set_timezone($_SESSION['user_timezone']);

$empty = $isSpy
	? ['icon' => 'bi-broadcast', 'title' => 'No clicks in the last 24 hours', 'body' => 'New clicks appear here within a few seconds of arriving. Send traffic through a tracking link to see them.', 'action' => 'Get tracking links', 'href' => get_absolute_url() . 'tracking202/setup/get_trackers.php']
	: p202_overview_empty(get_absolute_url(), 'No clicks match these filters');

if ($click_result->num_rows == 0) {
	echo '<div class="p202-empty">'
		. '<i class="bi ' . $empty['icon'] . ' p202-empty__icon"></i>'
		. '<strong class="p202-empty__title">' . htmlspecialchars($empty['title'], ENT_QUOTES, 'UTF-8') . '</strong>'
		. '<div>' . htmlspecialchars($empty['body'], ENT_QUOTES, 'UTF-8') . '</div>'
		. '<div class="p202-empty__action"><a class="btn btn-primary btn-sm" href="' . htmlspecialchars($empty['href'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($empty['action'], ENT_QUOTES, 'UTF-8') . '</a></div>'
		. '</div>';
	exit;
}

if (!$isSpy) {
	printf(
		'<p class="text-secondary small mb-2">Clicks <strong>%s&ndash;%s</strong> of <strong>%s</strong>, newest first</p>',
		htmlentities((string)$query['from'], ENT_QUOTES, 'UTF-8'),
		htmlentities((string)$query['to'], ENT_QUOTES, 'UTF-8'),
		htmlentities(number_format((int)$query['rows']), ENT_QUOTES, 'UTF-8')
	);
}
?>
<div class="p202-table-wrap">
	<table class="table table-hover p202-table" id="stats-table">
		<caption class="visually-hidden"><?php echo $isSpy ? 'Clicks from the last 24 hours, newest first' : 'Clicks in the chosen window, newest first'; ?></caption>
		<thead>
			<tr>
				<th scope="col" class="num">Subid</th>
				<th scope="col">Date</th>
				<th scope="col">User agent</th>
				<th scope="col">Geo</th>
				<th scope="col">ISP/Carrier</th>
				<th scope="col">Click</th>
				<th scope="col">IP</th>
				<th scope="col">Traffic source</th>
				<th scope="col">Offer / LP</th>
				<th scope="col">Referer</th>
				<th scope="col">Text ad</th>
				<th scope="col">Links</th>
				<th scope="col">Keyword</th>
			</tr>
		</thead>
		<tbody>
			<?php
			$spyLatestTime = 0; // the newest click shown, for Spy's next poll
			$spyLatestId = 0;
			$html = [];

			// Row rendering is shared with the incremental Spy path.
			while ($click_row = $click_result->fetch_array(MYSQLI_ASSOC)) {
				$ct = (int)$click_row['click_time'];
				$ci = (int)$click_row['click_id'];
				if ($ct > $spyLatestTime || ($ct === $spyLatestTime && $ci > $spyLatestId)) {
					$spyLatestTime = $ct;
					$spyLatestId = $ci;
				}
				$tr_attrs = '';
				include __DIR__ . '/click_history_row.php';
			}
			?>
		</tbody>
	</table>
</div>
<div class="modal fade" id="p202-click-conversions" tabindex="-1" aria-labelledby="p202-click-conversions-title" aria-hidden="true">
	<div class="modal-dialog modal-xl modal-dialog-scrollable">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="p202-click-conversions-title">Conversions on this click</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body" data-p202-breakdown-body aria-live="polite"></div>
		</div>
	</div>
</div>
<?php if ($isSpy && $spyLatestTime > 0) { ?>
	<div hidden data-p202-spy-latest data-time="<?php echo $spyLatestTime; ?>" data-id="<?php echo $spyLatestId; ?>"></div>
<?php } ?>
<?php
// Server-side pages: the table holds one page of the window, so it is not
// sorted in the browser (that would reorder this page and nothing else).
if (!$isSpy) {
	echo p202_overview_pagination((int)$query['pages'], (int)$query['offset'], 'Pages of clicks');
}
