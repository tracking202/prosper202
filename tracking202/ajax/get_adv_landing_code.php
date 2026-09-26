<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-17) . '/202-config/connect.php');
require_once dirname(__DIR__) . '/setup/_includes/setup_ui.php';

AUTH::require_user();

/*
 * The code for an advanced landing page: Setup › Get LP Code › Advanced
 * posts its form here and shows the answer in place, as v2 markup.
 *
 * The form posts landing_page_id, counter, and for each offer n = 1 …
 * counter + 1 the triple offer_typeN / aff_campaign_id_N / rotator_id_N —
 * the names the classic page posted. The v2 page numbers its offers without
 * gaps before every post, so a removed offer no longer leaves a hole this
 * loop reads as undefined.
 *
 * It needs the session token like every Setup POST (error pattern #5): it
 * announces the generation to Slack, and nothing asked for a token before
 * U4. The page posts through jQuery, whose prefilter attaches it.
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

$campaign_slack = '';
$offers = max(0, (int) ($_POST['counter'] ?? 0)) + 1;
$offer = static fn (string $name, int $n): string => (string) ($_POST[$name . $n] ?? '');

//make sure a landing page is selected
	if (empty($_POST['landing_page_id'])) {
		die(p202_flash('bad', 'You have not selected a landing page to use.'));
	}

//ok now run through all the offers to make sure at least one is chosen
	$success = false;
	for ($count = 1; $count <= $offers; $count++) {
		if ($offer('aff_campaign_id_', $count) != 0 || $offer('rotator_id_', $count) != 0) {
			$success = true;
		}
	}

	if ($success != true) {
		die(p202_flash('bad', 'Please select an affiliate campaign or rotator, and make sure no unused ones are there.'));
	}

//show tracking code
	$mysql['landing_page_id'] = $db->real_escape_string((string)$_POST['landing_page_id']);
	$landing_page_sql = "SELECT * FROM `202_landing_pages` WHERE `landing_page_id`='".$mysql['landing_page_id']."' AND `user_id`='".$mysql['user_id']."'";
	$landing_page_result = $db->query($landing_page_sql) or record_mysql_error($landing_page_sql);
	$landing_page_row = $landing_page_result->fetch_assoc();
	if (!is_array($landing_page_row)) {
		die(p202_flash('bad', 'That landing page is not yours, or it was removed.'));
	}

	echo '<p class="form-text mt-0">Test every link yourself before you run traffic to it.</p>';

	$javascript_code = generateTrackingLoaderSnippet((string) $landing_page_row['landing_page_id_public']);
	echo '<h3 class="p202-section__title">1. Landing page code</h3>'
		. '<p>Put this right above the <code>&lt;/body&gt;</code> tag of <strong>only</strong> the page your visitors first arrive on, not in a template every page of the site includes.</p>';
	echo p202_setup_code_box($javascript_code, ['long' => true]);

	echo '<h3 class="p202-section__title">2. Link out to each offer</h3>'
		. '<p>Each offer has its own outbound link. Use it as that offer\'s link on your page, or save its PHP redirect as a page on your site and link to that.</p>';

	for ($count = 1; $count <= $offers; $count++) {

		if ($offer('offer_type', $count) == 'campaign') {
			$aff_campaign_id = $offer('aff_campaign_id_', $count);

			if ($aff_campaign_id != 0) {

				$mysql['aff_campaign_id'] = $db->real_escape_string($aff_campaign_id);
				$aff_campaign_sql = "SELECT aff_campaign_id_public, aff_campaign_name FROM 202_aff_campaigns WHERE aff_campaign_id='".$mysql['aff_campaign_id']."' AND user_id='".$mysql['user_id']."'";
				$aff_campaign_result = $db->query($aff_campaign_sql) or record_mysql_error($aff_campaign_sql);
				$aff_campaign_row = $aff_campaign_result->fetch_assoc();
				if (!is_array($aff_campaign_row)) {
					echo p202_flash('bad', 'Offer ' . $count . ': that campaign is not yours, or it was removed.');
					continue;
				}

				if ($slack) {
					$campaign_slack .= $aff_campaign_row['aff_campaign_name'].'\n';
				}

				$outbound_go = '//' . getTrackingDomain() . get_absolute_url(). 'tracking202/redirect/go.php?acip=' . $aff_campaign_row['aff_campaign_id_public'];
				$outbound_php = '
<?php

// -------------------------------------------------------------------
//
// Tracking202 PHP Redirection, created on ' . date('D M, Y',time()) .'
//
// This PHP code is to be used for the following campaign:
// ' . $aff_campaign_row['aff_campaign_name'] . ' on ' . $landing_page_row['landing_page_url'] . '
//
// -------------------------------------------------------------------

$tracking202outbound = \'//'. getTrackingDomain() . get_absolute_url().'tracking202/redirect/off.php?acip='.$aff_campaign_row['aff_campaign_id_public'].'&pci=\'.$_COOKIE[\'tracking202pci\'];

header(\'location: \'.$tracking202outbound);

?>';
				echo '<h4 class="h6 mt-3">Offer ' . $count . ': campaign ' . p202_setup_e($aff_campaign_row['aff_campaign_name']) . '</h4>';
				echo p202_setup_code_box($outbound_go, ['label' => 'Outbound link']);
				echo '<details class="p202-disclosure mb-3"><summary>PHP redirect <span class="p202-disclosure__hint">cloaks the affiliate link</span></summary>'
					. '<div class="p202-disclosure__body">' . p202_setup_code_box($outbound_php, ['long' => true]) . '</div></details>';
			}

		} else if ($offer('offer_type', $count) == 'rotator') {
			$rotator_id = $offer('rotator_id_', $count);

			if ($rotator_id != 0) {
				$mysql['rotator_id'] = $db->real_escape_string($rotator_id);
				$rotator_sql = "SELECT public_id, name FROM 202_rotators WHERE id='".$mysql['rotator_id']."' AND user_id='".$mysql['user_id']."'";
				$rotator_result = $db->query($rotator_sql) or record_mysql_error($rotator_sql);
				$rotator_row = $rotator_result->fetch_assoc();
				if (!is_array($rotator_row)) {
					echo p202_flash('bad', 'Offer ' . $count . ': that redirector is not yours, or it was removed.');
					continue;
				}

				$outbound_go = '//' . getTrackingDomain() . get_absolute_url().'tracking202/redirect/go.php?rpi=' . $rotator_row['public_id'];
				$outbound_php = '
<?php

// -------------------------------------------------------------------
//
// Tracking202 PHP Redirection, created on ' . date('D M, Y',time()) .'
//
// This PHP code is to be used for the following campaign:
// ' . $rotator_row['name'] . ' on ' . $landing_page_row['landing_page_url'] . '
//
// -------------------------------------------------------------------

$tracking202outbound = \'//'. getTrackingDomain() . get_absolute_url().'tracking202/redirect/offrtr.php?rpi='.$rotator_row['public_id'].'\';

header(\'location: \'.$tracking202outbound);

?>';
				echo '<h4 class="h6 mt-3">Offer ' . $count . ': redirector ' . p202_setup_e($rotator_row['name']) . '</h4>';
				echo p202_setup_code_box($outbound_go, ['label' => 'Outbound link']);
				echo '<details class="p202-disclosure mb-3"><summary>PHP redirect <span class="p202-disclosure__hint">cloaks the destination</span></summary>'
					. '<div class="p202-disclosure__body">' . p202_setup_code_box($outbound_php, ['long' => true]) . '</div></details>';
			}
		}
	}

echo p202_setup_segment_help(getDynamicContentSegments());

if ($slack)
	$slack->push('advanced_landing_page_code_generated', ['name' => $landing_page_row['landing_page_nickname'], 'offers' => $campaign_slack, 'user' => $user_row['username']]);
