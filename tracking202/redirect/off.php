<?php
error_reporting(E_ALL);
ini_set("display_errors", true);
ob_start();
// only allow numeric acip's

$urlvarslist = $_GET;
$acip = $_GET['acip'];

    


// Creat blank #pci and save it with either a pci from the get var or the cookie
$pci = '';
if (isset($_GET['pci']))
    $pci = $_GET['pci'];
elseif (isset($_COOKIE['tracking202pci']))
    $pci = $_COOKIE['tracking202pci'];

if (! is_numeric($acip))
    die();
include_once (substr(dirname( __FILE__ ), 0,-21) . '/202-config/connect2.php');
include_once(substr(dirname( __FILE__ ), 0,-21) . '/202-config/class-dataengine-slim.php');

if(isset($_COOKIE['tracking202subid'])) { //if there's a cookie use it
    $click_id = $_COOKIE['tracking202subid'];
}

else { //if not find the list clicks id of the ip within a 30 day range
    $mysql['user_id'] = 1;
    $mysql['ip_address'] = $db->real_escape_string($_SERVER['REMOTE_ADDR']);
    $daysago = time() - 86400; // 24 hours
    $click_sql1 = "	SELECT 	202_clicks.click_id,ppc_account_id,click_id_public 
					FROM 		202_clicks
					LEFT JOIN	202_clicks_advance USING (click_id)
					LEFT JOIN 	202_ips USING (ip_id)
                    LEFT JOIN	202_clicks_record USING (click_id)
					WHERE 	202_ips.ip_address='".$mysql['ip_address']."'
					AND		202_clicks.user_id='".$mysql['user_id']."'
					AND		202_clicks.click_time >= '".$daysago."'
					ORDER BY 	202_clicks.click_id DESC
					LIMIT 		1";

    $click_result1 = $db->query($click_sql1) or record_mysql_error($click_sql1);
    $click_row1 = $click_result1->fetch_assoc();
    $mysql['click_id'] = $db->real_escape_string($click_row1['click_id']);
    $click_id = $mysql['click_id'];
    $mysql['ppc_account_id'] = $db->real_escape_string($click_row1['ppc_account_id']);
    $mysql['click_id_public'] = $db->real_escape_string($click_row1['click_id_public']);
    $pci=$mysql['click_id_public'];

}

$usedCachedRedirect = false;
if (! $db)
    $usedCachedRedirect = true;
    
    // the mysql server is down, use the txt cached redirect
if ($usedCachedRedirect == true) {
    
    $acip = $_GET['acip'];
    
    // if a cached key is found for this acip, redirect to that url
    if ($memcacheWorking) {
        $getUrl = $memcache->get(md5('ac_' . $acip . systemHash()));
        if ($getUrl) {
            
            $new_url = str_replace("[[subid]]", "p202", $getUrl);
            
            // c1 sring replace for cached redirect
            if (isset($_GET['c1']) && $_GET['c1'] != '') {
                $new_url = str_replace("[[c1]]", $_GET['c1'], $new_url);
            } else {
                $new_url = str_replace("[[c1]]", "p202c1", $new_url);
            }
            
            // c2 sring replace for cached redirect
            if (isset($_GET['c2']) && $_GET['c2'] != '') {
                $new_url = str_replace("[[c2]]", $_GET['c2'], $new_url);
            } else {
                $new_url = str_replace("[[c2]]", "p202c2", $new_url);
            }
            
            // c3 sring replace for cached redirect
            if (isset($_GET['c3']) && $_GET['c3'] != '') {
                $new_url = str_replace("[[c3]]", $_GET['c3'], $new_url);
            } else {
                $new_url = str_replace("[[c3]]", "p202c3", $new_url);
            }
            
            // c4 sring replace for cached redirect
            if (isset($_GET['c4']) && $_GET['c4'] != '') {
                $new_url = str_replace("[[c4]]", $_GET['c4'], $new_url);
            } else {
                $new_url = str_replace("[[c4]]", "p202c4", $new_url);
            }
            
            $urlvars = getPrePopVars($urlvarslist);
            
            $new_url = setPrePopVars($urlvars, $redirect_site_url, false);
       
            header('location: ' . $new_url);
            die();
        }
    }
    
    die("<h2>Error establishing a database connection - please contact the webhost</h2>");
}

/* OK FIRST IF THERE IS NO PUBLIC CLICK_ID, JUST REDIRECT TO THE NORMAL CAMPAIGN */
if ($vars[1] == '' && $pci == '') {
    $mysql['aff_campaign_id_public'] = $db->real_escape_string($acip);
 $aff_campaign_sql = "SELECT   aff_campaign_rotate, 
									aff_campaign_url, 
									aff_campaign_url_2, 
									aff_campaign_url_3, 
									aff_campaign_url_4, 
									aff_campaign_url_5, 
									aff_campaign_name, 
									aff_campaign_cloaking 
						    FROM 	202_aff_campaigns 
						    WHERE 	aff_campaign_id_public='" . $mysql['aff_campaign_id_public'] . "'
						    AND 202_aff_campaigns.user_id=1";
    $aff_campaign_row = memcache_mysql_fetch_assoc($db, $aff_campaign_sql);
    if (empty($aff_campaign_row['aff_campaign_url'])) {
        die();
    } // if there is no aff_url to redirect to DIE!
    
    $redirect_site_url = rotateTrackerUrl($db, $aff_campaign_row);
    
    $redirect_site_url = replaceTrackerPlaceholders($db, $redirect_site_url, $click_id);
    $urlvars = getPrePopVars($urlvarslist);
    if (isset($urlvars)) {
        $redirect_site_url = setPrePopVars($urlvars, $redirect_site_url, false);
    }
    // ok if there is a url that exists, if redirect php style, or if its cloaked, redirect meta refresh style.
    if ($aff_campaign_row['aff_campaign_cloaking'] == 0) {
        
        // cloaking OFF, so do a php header redirect

        header('location: ' . $redirect_site_url);
        die();
    } else {
        
        // cloaking ON, so do a meta REFRESH
        $html['aff_campaign_name'] = $aff_campaign_row['aff_campaign_name'];
        ?>

<html>
<head>
<title><?php echo $html['aff_campaign_name']; ?></title>
<meta name="robots" content="noindex">
<meta http-equiv="refresh"
	content="1; url=<?php echo $redirect_site_url; ?>">
</head>
<body>

	<form name="form1" id="form1" method="get"
		action="/tracking202/redirect/cl2.php">
		<input type="hidden" name="q"
			value="<?php echo $redirect_site_url; ?>" />
	</form>
	<script type="text/javascript">
					document.form1.submit();
				</script>

	<div style="padding: 30px; text-align: center;">
					You are being automatically redirected to <?php echo $html['aff_campaign_name']; ?>.<br />
		<br /> Page Stuck? <a href="<?php echo $redirect_site_url; ?>">Click
			Here</a>.
	</div>
</body>
</html>

<?php
    
}
    
    // terminate this script, this is the end, if there was no public_click_id
    die();
}

/* ------------------------------------------------------- */
/* ------------------------------------------------------- */
/* ------------------------------------------------------- */
//
//
//
// ANYTHING BELOW THIS ASSUMES THERE IS A PUBLIC CLICK ID
//
//
/* ------------------------------------------------------- */
/* ------------------------------------------------------- */
/* ------------------------------------------------------- */

$mysql['aff_campaign_id_public'] = $db->real_escape_string($_GET['acip']);
$mysql['click_id_public'] = $db->real_escape_string($pci);

$info_sql = "
	SELECT
		2c.click_id,
		2c.user_id,
		click_filtered,
		landing_page_id,
		click_cloaking,
		click_cloaking_site_url_id,
		click_redirect_site_url_id,
		2ac.aff_campaign_id,
		aff_campaign_rotate, 
		aff_campaign_url, 
		aff_campaign_url_2, 
		aff_campaign_url_3, 
		aff_campaign_url_4, 
		aff_campaign_url_5, 
		aff_campaign_name, 
		aff_campaign_cloaking,
		aff_campaign_payout
	FROM
		202_aff_campaigns AS 2ac,
		202_clicks_record AS 2cr
		LEFT JOIN 202_clicks AS 2c ON (2c.click_id = 2cr.click_id)
		LEFT JOIN 202_clicks_site AS 2cs ON (2cs.click_id = 2cr.click_id)
	WHERE
		2ac.aff_campaign_id_public='" . $mysql['aff_campaign_id_public'] . "'
		AND 2cr.click_id_public='" . $mysql['click_id_public'] . "'
";

$info_row = memcache_mysql_fetch_assoc($db, $info_sql);
// cache the url for later use if db is down
if ($memcacheWorking) {
    
    $url = $tracker_row['aff_campaign_url'] . "&subid=p202";
    $tid = $acip;
    
    $getKey = $memcache->get(md5('ac_' . $tid . systemHash()));
    if ($getKey === false) {
        $setUrl = $memcache->set(md5('ac_' . $tid . systemHash()), $url, false, 0);
    }
}

$click_id = $info_row['click_id'];

$mysql['click_id'] = $db->real_escape_string($click_id);

$mysql['aff_campaign_id'] = $db->real_escape_string($info_row['aff_campaign_id']);
$mysql['click_payout'] = $db->real_escape_string($info_row['aff_campaign_payout']);

$update_sql = "
	UPDATE
		202_clicks AS 2c
		LEFT JOIN 202_clicks_spy AS 2cs ON (2c.click_id = 2cs.click_id)
	SET
		2c.aff_campaign_id='" . $mysql['aff_campaign_id'] . "',
		2cs.aff_campaign_id='" . $mysql['aff_campaign_id'] . "',
		2c.click_payout='" . $mysql['click_payout'] . "',
		2cs.click_payout='" . $mysql['click_payout'] . "'
	WHERE
		2c.click_id='" . $mysql['click_id'] . "'
";
// this function delays the sql, because UPDATING is very very slow
//delay_sql($db, $update_sql);
$click_result = $db->query($update_sql) or record_mysql_error($db, $update_sql);

$mysql['click_out'] = 1;

if (($info_row['click_cloaking'] == 1) or // if tracker has overrided cloaking on
(($info_row['click_cloaking'] == - 1) and ($info_row['aff_campaign_cloaking'] == 1)) or ((! isset($info_row['click_cloaking'])) and ($info_row['aff_campaign_cloaking'] == 1))) // if no tracker but but by default campaign has cloaking on
{
    $cloaking_on = true;
    $mysql['click_cloaking'] = 1;
    // if cloaking is on, add in a click_id_public, because we will be forwarding them to a cloaked /cl/xxxx link
} else {
    $mysql['click_cloaking'] = 0;
}

$update_sql = "
	UPDATE
		202_clicks_record
	SET
		click_out='" . $mysql['click_out'] . "',
		click_cloaking='" . $mysql['click_cloaking'] . "'
	WHERE
		click_id='" . $mysql['click_id'] . "'
";
//delay_sql($db, $update_sql);
$click_result = $db->query($update_sql) or record_mysql_error($db, $update_sql);

$outbound_site_url = 'http://' . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
$click_outbound_site_url_id = INDEXES::get_site_url_id($db, $outbound_site_url);
$mysql['click_outbound_site_url_id'] = $db->real_escape_string($click_outbound_site_url_id);

if ($cloaking_on == true) {
    $cloaking_site_url = 'http://' . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
}

$redirect_site_url = rotateTrackerUrl($db, $info_row);

$redirect_site_url = replaceTrackerPlaceholders($db, $redirect_site_url, $click_id);

$click_redirect_site_url_id = INDEXES::get_site_url_id($db, $redirect_site_url);
$mysql['click_redirect_site_url_id'] = $db->real_escape_string($click_redirect_site_url_id);

$update_sql = "
	UPDATE
		202_clicks_site
	SET
		click_outbound_site_url_id='" . $mysql['click_outbound_site_url_id'] . "',
		click_redirect_site_url_id='" . $mysql['click_redirect_site_url_id'] . "'
	WHERE
		click_id='" . $mysql['click_id'] . "'
";
//delay_sql($db, $update_sql);
$click_result = $db->query($update_sql) or record_mysql_error($db, $update_sql);

// alright now the updates,
// WE WANT TO DELAY THESES UPDATES, in a MYSQL DATBASES? Or else the UPDATES lag the server, the UPDATES have to wait until it locks to update the server
// so what happens is if there not delayed, if someone is pulling MASSIVE queries on the t202 website, it'll wait till they load before our update runs,
// and that means if this update wasn't delayed it'd wait untill their queries were done on the site before moving forward. Massive slowness, so we update delays theses in cronjobs a at a lter time.

// ADD TO CLICK SUMMARY TABLE?

// update the click summary table if this is a 'real click'
// if ($info_row['click_filtered'] == 0) {

$mysql['landing_page_id'] = $db->real_escape_string($info_row['landing_page_id']);
$mysql['user_id'] = $db->real_escape_string($info_row['user_id']);

// set timezone correctly
$user_sql = "SELECT user_timezone FROM 202_users WHERE user_id='" . $mysql['user_id'] . "'";
$user_row = memcache_mysql_fetch_assoc($db, $user_sql);
AUTH::set_timezone($user_row['user_timezone']);

$now = time();

$today_day = date('j', time());
$today_month = date('n', time());
$today_year = date('Y', time());

// the click_time is recorded in the middle of the day
$click_time = mktime(12, 0, 0, $today_month, $today_day, $today_year);
$mysql['click_time'] = $db->real_escape_string($click_time);
// check to make sure this click_summary doesn't already exist
$check_sql = "SELECT  *
				  FROM    202_summary_overview
				  WHERE user_id='" . $mysql['user_id'] . "'
				  AND     landing_page_id='" . $mysql['landing_page_id'] . "'
				  AND     aff_campaign_id='" . $mysql['aff_campaign_id'] . "'
				  AND     click_time='" . $mysql['click_time'] . "'";
$check_result = $db->query($check_sql) or record_mysql_error($check_sql);
$check_count = $check_result->num_rows;

// if this click summary hasn't been recorded do this now
if ($check_count == 0) {
    
    $insert_sql = "INSERT INTO 202_summary_overview
					   	SET         user_id='" . $mysql['user_id'] . "',
								   landing_page_id='" . $mysql['landing_page_id'] . "',
								   aff_campaign_id='" . $mysql['aff_campaign_id'] . "',
								   click_time='" . $mysql['click_time'] . "'";
    $insert_result = $db->query($insert_sql);
}
// }

// set the cookie
setClickIdCookie($mysql['click_id'], $mysql['aff_campaign_id']);
// NOW LETS REDIRECT
$urlvars = getPrePopVars($urlvarslist);

$redirect_site_url = setPrePopVars($urlvars, $redirect_site_url, false);


//set dirty hour
$de = new DataEngine();
$data=($de->setDirtyHour($mysql['click_id']));

if ($cloaking_on == true) {
    
    // if cloaking is turned on, meta refresh out
    
    ?>
<html>
<head>
<title><?php echo $html['aff_campaign_name']; ?></title>
<meta name="robots" content="noindex">
<meta http-equiv="refresh"
	content="1; url=<?php echo $redirect_site_url; ?>">
</head>
<body>

	<form name="form1" id="form1" method="get"
		action="/tracking202/redirect/cl2.php">
		<input type="hidden" name="q"
			value="<?php echo $redirect_site_url; ?>" />
	</form>
	<script type="text/javascript">
			document.form1.submit();
		</script>

	<div style="padding: 30px; text-align: center;">
			You are being automatically redirected to <?php echo $html['aff_campaign_name']; ?>.<br />
		<br /> Page Stuck? <a href="<?php echo $redirect_site_url; ?>">Click
			Here</a>.
	</div>
</body>
</html>
<?php

} else {
    
    // if cloaking is turned off, php header redirect out
   
    header('location: ' . $redirect_site_url);
    die();
}

