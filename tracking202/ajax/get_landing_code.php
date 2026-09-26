<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-17) . '/202-config/connect.php');
require_once dirname(__DIR__) . '/setup/_includes/setup_ui.php';

AUTH::require_user();

/*
 * The code for a simple landing page: Setup › Get LP Code (and the Dynamic
 * Smart Component page) post their form here and show the answer in place.
 * The answer is v2 markup: a refusal is a flash with the sentence this
 * endpoint always said, and each snippet is the kit's code box.
 *
 * It needs the session token like every Setup POST (error pattern #5): it
 * announces the generation to Slack, and nothing asked for a token before
 * U4. The pages post through jQuery, whose prefilter attaches it.
 */
if (!hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_POST['token'] ?? ''))) {
	http_response_code(403);
	die('Invalid token, please reload the page and try again.');
}

$slack = false;
$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
$mysql['user_own_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
$user_sql = "SELECT 2u.user_name as username, 2up.user_slack_incoming_webhook AS url FROM 202_users AS 2u INNER JOIN 202_users_pref AS 2up ON (2up.user_id = 1) WHERE 2u.user_id = '".$mysql['user_own_id']."'";
$user_results = $db->query($user_sql) or record_mysql_error($user_sql);
$user_row = $user_results->fetch_assoc();

if (!empty($user_row['url']))
	$slack = new Slack($user_row['url']);

// Initialize error array
$error = [];

//check variables
	if(empty($_POST['aff_network_id'])) { $error['aff_network_id'] = 'You have not selected an affiliate network.'; }
	if(empty($_POST['aff_campaign_id'])) { $error['aff_campaign_id'] = 'You have not selected an affiliate campaign.'; }
	if(empty($_POST['method_of_promotion'])) { $error['method_of_promotion'] = 'You have to select your method of promoting this affiliate link.'; }

	if ($error) {
		foreach ($error as $sentence) {
			echo p202_flash('bad', $sentence);
		}
		die();
	}

	//if they do a landing page, make sure they have one
	if ($_POST['method_of_promotion'] == 'landingpage' && empty($_POST['landing_page_id'])) {
		die(p202_flash('bad', 'You have not selected a landing page to use.'));
	}

//show tracking code

	$mysql['landing_page_id'] = $db->real_escape_string((string)$_POST['landing_page_id']);
	$landing_page_sql = "SELECT * FROM 202_landing_pages LEFT JOIN 202_aff_campaigns USING (aff_campaign_id) LEFT JOIN 202_aff_networks USING (aff_network_id) WHERE landing_page_id='".$mysql['landing_page_id']."' AND 202_landing_pages.user_id='".$mysql['user_id']."'";
	$landing_page_result = $db->query($landing_page_sql) or record_mysql_error($landing_page_sql);
	$landing_page_row = $landing_page_result->fetch_assoc();
	if (!is_array($landing_page_row)) {
		die(p202_flash('bad', 'That landing page is not yours, or it was removed.'));
	}

	if ($slack)
		$slack->push('simple_landing_page_code_generated', ['name' => $landing_page_row['landing_page_nickname'], 'campaign' => $landing_page_row['aff_campaign_name'], 'network' => $landing_page_row['aff_network_name'], 'user' => $user_row['username']]);

	if ($_POST['method_of_promotion'] == 'landingpage') {

	$affiliate_link = '//' . getTrackingDomain() . get_absolute_url().'tracking202/redirect/go.php?lpip=' . $landing_page_row['landing_page_id_public'];

	$javascript_code = generateTrackingLoaderSnippet((string) $landing_page_row['landing_page_id_public']);

	echo '<p class="form-text mt-0">Test every link yourself before you run traffic to it.</p>';

	echo '<h3 class="p202-section__title">1. Landing page code</h3>'
		. '<p>Put this right above the <code>&lt;/body&gt;</code> tag of <strong>only</strong> the page your visitors first arrive on, not in a template every page of the site includes.</p>';
	echo p202_setup_code_box($javascript_code, ['long' => true]);

	echo '<h3 class="p202-section__title">2. Link out to the offer</h3>'
		. '<p>Choose one of the three. The simplest is the outbound link: use it as the link to the offer on your page.</p>';
	echo p202_setup_code_box($affiliate_link, ['label' => 'Option 1: outbound redirect link']);

	$affiliate_link = '//' . getTrackingDomain() . get_absolute_url().'tracking202/redirect/lp.php?lpip=' . $landing_page_row['landing_page_id_public'];
	$html['affiliate_link'] = htmlentities($affiliate_link);

	$outbound_php = '<?php

  // -------------------------------------------------------------------
  //
  // Tracking202 PHP Redirection, created on ' . date('D M, Y',time()) .'
  //
  // This PHP code is to be used for the following landing page.
  // ' . $landing_page_row['landing_page_url'] . '
  //
  // -------------------------------------------------------------------

  if (isset($_COOKIE[\'tracking202outbound\'])) {
	$tracking202outbound = $_COOKIE[\'tracking202outbound\'];
  } else {
	$tracking202outbound = \''.$html['affiliate_link'].'&pci=\'.$_COOKIE[\'tracking202pci\'];
  }

  header(\'location: \'.$tracking202outbound);

?>';

	echo '<details class="p202-disclosure mb-3" data-p202-remember="setup-lp-code-php">'
		. '<summary>Option 2: outbound PHP redirect <span class="p202-disclosure__hint">cloaks your affiliate link; needs PHP on your server</span></summary>'
		. '<div class="p202-disclosure__body">'
		. '<p>Save this as a page on your site (for example <code>yourdomain.com/redirect.php</code>) and link to that page instead of the offer: the visitor is redirected on to your affiliate link, which never appears on your landing page.</p>'
		. p202_setup_code_box($outbound_php, ['long' => true])
		. '</div></details>';

	$outbound_javascript = '
<!DOCTYPE html>
<html>
<head>
	<title>GO</title>
</head>
<body>

<!-- PLACE OTHER LANDING PAGE CLICK THROUGH CONVERSION TRACKING PIXELS HERE -->

<!-- NOW THE TRACKING202 REDIRECTS OUT -->
<script type="text/javascript">
if (readCookie(\'tracking202outbound\') != \'\') {
	window.location=readCookie(\'tracking202outbound\');
} else {
	window.location=\'//'. getTrackingDomain() . get_absolute_url().'tracking202/redirect/lp.php?lpip=' . $landing_page_row['landing_page_id_public'] .'\';
}

function readCookie(name) {
	var nameEQ = name + "=";
	var ca = document.cookie.split(\';\');
	for(var i=0;i < ca.length;i++) {
		var c = ca[i];
		while (c.charAt(0)==\' \') c = c.substring(1,c.length);
		if (c.indexOf(nameEQ) == 0) return urldecode(c.substring(nameEQ.length,c.length));
	}
	return false;
}

function urldecode(url) {
	  return decodeURIComponent(url.replace(/\+/g, \' \'));
}
</script>
</body>
</html>';

	echo '<details class="p202-disclosure mb-3" data-p202-remember="setup-lp-code-js">'
		. '<summary>Option 3: outbound JavaScript redirect <span class="p202-disclosure__hint">lets other tracking tags fire first</span></summary>'
		. '<div class="p202-disclosure__body">'
		. '<p>A page that redirects with JavaScript instead of PHP, so tags such as a split-testing tool can fire before the visitor leaves.</p>'
		. p202_setup_code_box($outbound_javascript, ['long' => true])
		. '</div></details>';

	echo p202_setup_segment_help(getDynamicContentSegments());

}
