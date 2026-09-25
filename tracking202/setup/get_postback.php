<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-18) . '/202-config/connect.php');

AUTH::require_user();

if (!$userObj->hasPermission("access_to_setup_section")) {
	header('location: '.get_absolute_url().'tracking202/');
	die();
}

require_once __DIR__ . '/_includes/setup_ui.php';

/*
 * Setup › Postback / Pixel: the conversion pixels and postback URLs, built
 * in the browser from three choices (p202-setup.js, "postback builder").
 * Nothing here posts. The page renders every snippet for the defaults, so it
 * is complete without its script; the script only rewrites them as the
 * amount, sub id, campaign or protocol change.
 *
 * The protocol is decided, not asked (UI standard, rule 3): HTTPS when this
 * page was served over HTTPS, since the tracking domain is this install's.
 * The choice stays under Advanced for an install whose tracking domain
 * answers differently.
 */

$base = get_absolute_url();
$domain = getTrackingDomain();
$secure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
	|| strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
$scheme = $secure ? 'https' : 'http';
$campaignOptions = p202_setup_campaign_options($db, (int) $_SESSION['user_id']);

/** The snippets for the defaults: no amount, no campaign, no sub id. */
$root = $scheme . '://' . $domain . $base . 'tracking202/static/';
$snippets = [
	'simple_pixel' => '<img height="1" width="1" border="0" style="display: none;" src="' . $root . 'gpx.php?amount=&subid=" />',
	'simple_postback' => $root . 'gpb.php?amount=&subid=',
	'advanced_pixel' => '<img height="1" width="1" border="0" style="display: none;" src="' . $root . 'gpx.php?amount=&cid=&subid=" />',
	'advanced_postback' => $root . 'gpb.php?amount=&cid=&subid=',
	'universal_js' => "<script>\n var vars202={amount:\"\",cid:\"\",subid:\"\"};(function(d, s) {\n \tvar js, upxf = d.getElementsByTagName(s)[0], load = function(url, id) {\n \t\tif (d.getElementById(id)) {return;}\n \t\tif202 = d.createElement(\"iframe\");if202.src = url;if202.id = id;if202.height = 1;if202.width = 0;if202.frameBorder = 1;if202.scrolling = \"no\";if202.noResize = true;\n \t\tupxf.parentNode.insertBefore(if202, upxf);\n \t};\n \tload(\"" . $root . "upx.php?amount=\"+vars202['amount']+\"&cid=\"+vars202['cid']+\"&subid=\"+vars202['subid'], \"upxif\");\n }(document, \"script\"));</script>\n<noscript>\n \t<iframe height=\"1\" width=\"1\" border=\"0\" style=\"display: none;\" frameborder=\"0\" scrolling=\"no\" src=\"" . $root . "upx.php?amount=&cid=&subid=\" seamless></iframe>\n</noscript>",
	'universal_iframe' => '<iframe height="1" width="1" border="0" style="display: none;" frameborder="0" scrolling="no" src="' . $root . 'upx.php?amount=&subid=" seamless></iframe>',
];

template_top('Pixel And Postback URLs', ['ui' => 'v2']);
?>

<div class="p202-page-header p202-page-header--accent">
	<div class="p202-page-header__icon"><i class="bi bi-arrow-left-right"></i></div>
	<div class="p202-page-header__text">
		<h1 class="p202-page-header__title">Postback / Pixel</h1>
		<p class="p202-page-header__desc">Report conversions back to Prosper202: a pixel on the thank-you page, or a postback URL the network calls.</p>
	</div>
</div>

<div class="row g-4" data-postback-builder data-root-path="<?php echo p202_setup_e($domain . $base . 'tracking202/static/'); ?>">
	<div class="col-12 col-lg-5">
		<section class="p202-panel">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title">Your pixel</h2>
				<p class="p202-panel__sub">The simple pixel suits most campaigns.</p>
			</div>
			<div class="p202-panel__body">
				<form id="tracking_form" onsubmit="return false;">
					<fieldset class="mb-3" id="pixel-type">
						<legend class="form-label">Pixel type</legend>
						<div class="form-check">
							<input class="form-check-input" type="radio" name="pixel_type" id="pixel_type0" value="0" checked>
							<label class="form-check-label" for="pixel_type0">Simple: one click tracked at a time</label>
						</div>
						<div class="form-check">
							<input class="form-check-input" type="radio" name="pixel_type" id="pixel_type1" value="1">
							<label class="form-check-label" for="pixel_type1">Advanced: several clicks at once, per campaign</label>
						</div>
						<div class="form-check">
							<input class="form-check-input" type="radio" name="pixel_type" id="pixel_type2" value="2">
							<label class="form-check-label" for="pixel_type2">Universal smart pixel: also fires your traffic sources' pixels</label>
						</div>
					</fieldset>

					<div class="mb-3" data-p202-show-when="pixel_type=1" hidden>
						<label class="form-label" for="aff_campaign_id">Campaign</label>
						<select class="form-select" id="aff_campaign_id" name="aff_campaign_id">
							<?php echo p202_setup_options($campaignOptions, null, 'Choose a campaign', ''); ?>
						</select>
						<div class="form-text">Fills the <code>cid</code> the advanced pixel reports under.</div>
					</div>

					<div class="mb-3">
						<label class="form-label" for="subid_value">Sub id placeholder</label>
						<input class="form-control" type="text" name="subid_value" id="subid_value" placeholder="{aff_sub}" list="subid-formats">
						<datalist id="subid-formats"><option value="{aff_sub}">HasOffers</option><option value="#s2#">Cake</option><option value="xxC1xx">HitPath</option><option value="[=SID=]">LinkTrust</option><option value="%subid1%"></option><option value="#s1#"></option></datalist>
						<div class="form-text">Your network's token for the sub id, such as <code>{aff_sub}</code> or <code>#s1#</code>; empty leaves <code>subid=</code> for you to fill.</div>
					</div>

					<details class="p202-disclosure" data-p202-remember="setup-postback-advanced">
						<summary>Advanced <span class="p202-disclosure__hint">amount, protocol</span></summary>
						<div class="p202-disclosure__body">
							<div class="mb-3">
								<label class="form-label" for="amount_value">Amount</label>
								<input class="form-control" type="text" inputmode="decimal" name="amount_value" id="amount_value">
								<div class="form-text">Empty by default, so each conversion pays the campaign's payout. An amount here overrides it.</div>
							</div>
							<fieldset id="secure-pixels">
								<legend class="form-label">Protocol</legend>
								<div class="form-check form-check-inline">
									<input class="form-check-input" type="radio" name="secure_type" id="secure_type0" value="0"<?php echo $secure ? '' : ' checked'; ?>>
									<label class="form-check-label" for="secure_type0">http://</label>
								</div>
								<div class="form-check form-check-inline">
									<input class="form-check-input" type="radio" name="secure_type" id="secure_type1" value="1"<?php echo $secure ? ' checked' : ''; ?>>
									<label class="form-check-label" for="secure_type1">https://</label>
								</div>
								<div class="form-text">Use https:// only when your tracking domain has a certificate.</div>
							</fieldset>
						</div>
					</details>
					<p class="p202-decided mt-2 mb-0"><i class="bi bi-check2-circle"></i> <span data-postback-decided><?php echo $secure ? 'Links use https://, because this page was served over HTTPS.' : 'Links use http://, because this page was served over HTTP.'; ?></span> <a href="#secure-pixels" data-postback-change>change</a></p>
				</form>
			</div>
		</section>
	</div>

	<div class="col-12 col-lg-7">
		<section class="p202-panel" data-p202-show-when="pixel_type=0">
			<div class="p202-panel__head"><h2 class="p202-panel__title">Simple pixel and postback</h2></div>
			<div class="p202-panel__body">
				<p>Put the pixel on your conversion or thank-you page. It records the conversion whenever it loads.</p>
				<?php echo p202_setup_code_box($snippets['simple_pixel'], ['label' => 'Global tracking pixel', 'id' => 'unsecure_pixel']); ?>
				<p>Or give the network this postback URL for server-to-server tracking: it calls it with the sub id when a conversion happens. If the network only supports <code>sid</code>, change <code>?subid=</code> to <code>?sid=</code>.</p>
				<?php echo p202_setup_code_box($snippets['simple_postback'], ['label' => 'Global postback URL', 'id' => 'unsecure_postback']); ?>
			</div>
		</section>
		<section class="p202-panel" data-p202-show-when="pixel_type=1" hidden>
			<div class="p202-panel__head"><h2 class="p202-panel__title">Advanced pixel and postback</h2></div>
			<div class="p202-panel__body">
				<p>For several clicks at once: the campaign you choose fills <code>cid</code>, so each conversion lands on its own campaign.</p>
				<?php echo p202_setup_code_box($snippets['advanced_pixel'], ['label' => 'Advanced global tracking pixel', 'id' => 'unsecure_pixel_2']); ?>
				<?php echo p202_setup_code_box($snippets['advanced_postback'], ['label' => 'Advanced global postback URL', 'id' => 'unsecure_postback_2']); ?>
			</div>
		</section>
		<section class="p202-panel" data-p202-show-when="pixel_type=2" hidden>
			<div class="p202-panel__head"><h2 class="p202-panel__title">Universal smart pixel</h2></div>
			<div class="p202-panel__body">
				<p>Records the conversion and fires your traffic sources' pixels too. The JavaScript version carries a fallback for browsers without JavaScript.</p>
				<?php echo p202_setup_code_box($snippets['universal_js'], ['label' => 'JavaScript universal smart pixel', 'id' => 'unsecure_universal_pixel_js', 'long' => true]); ?>
				<?php echo p202_setup_code_box($snippets['universal_iframe'], ['label' => 'Iframe universal smart pixel', 'id' => 'unsecure_universal_pixel']); ?>
			</div>
		</section>
	</div>
</div>

<?php echo p202_setup_script_tag($base); ?>
<?php template_bottom();
