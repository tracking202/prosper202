<?php
declare(strict_types=1);
header('Content-type: application/javascript');
header('P3P: CP="Prosper202 does not have a P3P policy"');
include_once(substr(__DIR__, 0,-19) . '/202-config/connect2.php'); 
 
// Whether the landing page is simple or advanced, to include the script for
// each. Its id exactly: compared as text, MySQL read 12abc as 12.
$recordLandingPageId = \Prosper202\Click\PublicId::parse($_GET['lpip'] ?? null);
$tracker_row = null;
if ($recordLandingPageId !== null) {
    try {
        $tracker_row = \Prosper202\Repository\LookupRepositoryFactory::reads($db)->landingPage($recordLandingPageId);
    } catch (\Throwable $recordReadError) {
        record_mysql_error($db, 'reading landing page ' . $recordLandingPageId, $recordReadError->getMessage());
    }
}

if ($tracker_row === null) {
    die();
}
if ($tracker_row['landing_page_type'] == 0) { 
	include_once(substr(__DIR__, 0,-19) .'/tracking202/static/record_simple.php'); die();
} elseif ($tracker_row['landing_page_type'] == 1){
	include_once(substr(__DIR__, 0,-19) .'/tracking202/static/record_adv.php'); die();
}
