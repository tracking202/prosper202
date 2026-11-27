<?php

/**
 * Usage data & communication disclosure.
 *
 * The "Full details" target for the consent surfaces (the Account Settings
 * box and the one-time question). Copy must stay truthful to what the code
 * sends (202-config/Messaging/CENTRAL-API.md, OfferProfile): nothing before
 * the user opts in; then usage including traffic stats, revenue numbers, and
 * campaign names + destination template URLs; visitors' PII and per-customer
 * LTV data never leave the install.
 *
 * Login is required, but NOT a valid license (like the messaging endpoints):
 * a privacy disclosure must stay reachable even when the license check fails.
 */

declare(strict_types=1);
include_once(str_repeat("../", 1) . '202-config/connect.php');

AUTH::require_user('', false);

template_top('Usage Data');

?>

<div class="mx-auto my-4" style="max-width: 860px;">
	<div class="p202-page-header">
		<h4>Sharing how you use Prosper202</h4>
		<p>Off until you turn it on. What you get, what is sent, what never is, and how to switch it off.</p>
	</div>

	<section class="card mb-3"><div class="card-body">
		<h5 class="card-title">What you get</h5>
		<p class="mb-0">When you turn it on, the tips, guides and announcements Tracking202 sends you are picked for
			how you use Prosper202 (your traffic, your setup and the features you reach for) instead of being the
			same for everyone. With it off, Prosper202 works exactly the same; you just get the general ones.</p>
	</div></section>

	<section class="card mb-3"><div class="card-body">
		<h5 class="card-title">What is sent to Tracking202, once you turn it on</h5>
		<ul>
			<li><strong>Who you are to Tracking202:</strong> your account email, the date you signed up, your
				install's identifier and licence key, and your user number on the install.</li>
			<li><strong>Traffic and results:</strong> clicks, conversions, income, cost, and net over the last
				30 days; top countries and device mix.</li>
			<li><strong>Your campaign setup:</strong> affiliate network names, traffic source names, campaign
				names, and the shape of the campaign destination (template) URLs you entered at setup: the address,
				path words and query names, with only tracking macros (such as {clickid}) and short numeric ids kept
				as values. A path part, query name or macro is kept only when it reads as short words holding at
				most six digits; tokens, hashes, dotted names (such as first.last, but not tracker macros such as
				{campaign.id}) and longer numbers, even when split up by dashes, dots or spaces, are taken out. If the
				path and query together hold more than six digits, every part with a digit in it is taken out, so a
				number cannot be spread across parts. Every other value, sign-in details and anything after # are
				taken out, a URL longer than 512 characters is cut short, and a URL that is not a plain http(s)
				address is not sent at all.</li>
			<li><strong>Offer performance:</strong> per-campaign payout, currency, EPC, conversion rate, and
				clicks.</li>
			<li><strong>Account-level revenue aggregates:</strong> if you use Customer LTV, only account totals
				(total revenue, MRR/ARR, customer count, average LTV, active subscriptions).</li>
			<li><strong>Product usage:</strong> pages viewed inside Prosper202, your sign-ins and how often you sign
				in, and setup milestones (e.g. campaign created, tracking link generated, integration connected, AI app
				connected, click filtering changed), whether each was made on the pages, with the CLI or through an AI
				app, and which of those you use (at most once a day each). Never what you asked an AI app or the data
				it read.</li>
			<li><strong>Your choices here:</strong> whether this is on, and whether you want offers &amp; tips by
				email, with when you chose.</li>
		</ul>
		<p class="mb-0">Only what is recorded after you turn it on is sent. Tracking202 does not share this data
			with third parties.</p>
	</div></section>

	<section class="card mb-3"><div class="card-body">
		<h5 class="card-title">What never leaves your install</h5>
		<ul>
			<li><strong>Your visitors' data.</strong> We track your product usage as the account holder — never
				your traffic's personal data. (Your visitors' click-data privacy is governed separately by the
				Privacy Option above it in Settings.)</li>
			<li><strong>Your customers' data.</strong> Customer LTV records — names, aliases, emails,
				per-customer revenue, custom fields — never sync. Only the account-level aggregates listed
				above do.</li>
			<li><strong>Anything, before you say yes.</strong> Until you turn it on, nothing about how you use
				Prosper202 is recorded for sending or sent, wherever you are.</li>
		</ul>
	</div></section>

	<section class="card mb-3"><div class="card-body">
		<h5 class="card-title">Your choice and the off switch</h5>
		<ul class="mb-0">
			<li>It is off until you turn it on. Prosper202 asks you once, with "Yes, share usage" and "No thanks"
				side by side, and never asks again whichever you pick or if you leave it. You can turn it on or off
				any time in <a href="<?php echo get_absolute_url(); ?>202-account/account.php#data-consent">Personal Settings › Data &amp; communication</a>.</li>
			<li>Switching it off stops collection and sending at once, and deletes the usage data on your install
				that has not been sent yet (queued events and the computed usage profile). Tracking202 is told you
				said no only if it has heard from your install before.</li>
			<li><strong>Money-making offers &amp; tips by email</strong> is a separate, opt-in choice. It is
				never inferred from this setting, and you can unsubscribe anytime.</li>
		</ul>
	</div></section>
</div>

<?php template_bottom(); ?>
