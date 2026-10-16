<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-21) . '/202-config/connect2.php');
include_once(substr(__DIR__, 0,-21) . '/202-config/class-dataengine-slim.php');

// Speculative requests (browser prefetch/prerender, link-preview scanners, HEAD
// probes) must not record a click — otherwise the speculative hit plus the real
// navigation double-count the same visit (see p202IsSpeculativeRequest).
if (p202IsSpeculativeRequest()) {
	p202DeclineSpeculativeRequest();
}


// The click's public id exactly: compared as text, MySQL read 1e3 as 1000,
// and 0, a click with none, matched every click stored without one.
$pciPublicId = \Prosper202\Click\ClickId::parse($_GET['pci'] ?? null);
if ($pciPublicId === null) {
    die();
}

$click_sql = "
	SELECT
		pc.click_id,
		pc.user_id,
		pc.aff_campaign_id,
		click_cloaking,
		click_cloaking_site_url_id,
		click_redirect_site_url_id
	FROM
		202_clicks AS pc
		LEFT JOIN 202_clicks_record USING (click_id) 
		LEFT JOIN 202_clicks_site   USING (click_id)
		LEFT JOIN 202_clicks_rotator AS cro ON cro.click_id = pc.click_id
	WHERE
		click_id_public=" . $pciPublicId . "
	" . \Prosper202\Click\LatestVisit::orderBy('pc', 'cro') . "
	LIMIT 1
";
// Not cached: a rotator re-click rewrites the click's destination and keeps
// its public id, and a cached row sent the visitor to the one before.
$click_row = memcache_mysql_fetch_assoc($db, $click_sql, 0);

// Guard BEFORE dereferencing: an unknown/expired public click id must not crash
// the cloaked confirmation redirect.
if (!$click_row || !isset($click_row['click_id'])) {
	die();
}

$click_id = $click_row['click_id'];
// The privacy setting in force for this visitor: the stricter of the
// install's and this click's account's (p202ApplyOwnerPrivacy()), before
// the address is stored or a cookie set.
p202ApplyOwnerPrivacy($click_row['user_id'] ?? null);
$aff_campaign_id = $click_row['aff_campaign_id'];
$mysql['click_id'] = $db->real_escape_string((string) $click_id);
$mysql['aff_campaign_id'] = $db->real_escape_string((string) $aff_campaign_id);

// The click's own account: its public id names the click and nothing else.
$pciRecorded = false;
try {
    $pciClicks = new \Prosper202\Click\MysqlClickRepository(
        \Prosper202\Repository\LookupRepositoryFactory::connection($db)
    );
    $pciRecorded = $pciClicks->recordExit(
        new \Prosper202\Click\ClickExit((int) $click_row['user_id'], (int) $click_id)
    );
} catch (\Throwable $pciWriteError) {
    record_mysql_error(
        $db,
        'recording click ' . $mysql['click_id'] . ' leaving its cloaked link',
        $pciWriteError->getMessage()
    );
}
if (!$pciRecorded) {
    die();
}


//see if cloaking was turned on
if ($click_row['click_cloaking'] == 1) { 
	$cloaking_on = true;
	$mysql['site_url_id'] = $db->real_escape_string((string) $click_row['click_cloaking_site_url_id']);
	$site_url_sql = "SELECT site_url_address FROM 202_site_urls WHERE site_url_id='".$mysql['site_url_id']."' limit 1";
	$site_url_row = memcache_mysql_fetch_assoc($db, $site_url_sql);
	$cloaking_site_url = $site_url_row['site_url_address'] ?? '';
} else {
	$cloaking_on = false;
	$mysql['site_url_id'] = $db->real_escape_string((string) $click_row['click_redirect_site_url_id']);
	$site_url_sql = "SELECT site_url_address FROM 202_site_urls WHERE site_url_id='".$mysql['site_url_id']."' limit 1";
	$site_url_row = memcache_mysql_fetch_assoc($db, $site_url_sql);
	$redirect_site_url = $site_url_row['site_url_address'] ?? '';
}


//set the cookie
setClickIdCookie($mysql['click_id'],$mysql['aff_campaign_id']);

//set dirty hour
$de = new DataEngine();
$data=($de->setDirtyHour($mysql['click_id']));
	
//now we've updated, lets redirect
$target = ($cloaking_on == true) ? ($cloaking_site_url ?? '') : ($redirect_site_url ?? '');
if ($target === '') {
	// No destination url on file for this click; don't emit a broken redirect.
	die();
}
header('location: ' . $target);
