<?php

/**
 * Product analytics & communication disclosure.
 *
 * The "Learn more" target for the consent surfaces (account settings toggles,
 * EU consent prompt). Copy must stay truthful to the spec's D6/D7 bounds:
 * analytics sends usage including traffic stats, revenue numbers, and campaign
 * names + destination template URLs; marketing is money/workflow/offer-matching
 * only; visitors' PII and per-customer LTV data never leave the install.
 *
 * Login is required, but NOT a valid license (like the messaging endpoints):
 * a privacy disclosure must stay reachable even when the license check fails.
 */

declare(strict_types=1);
include_once(str_repeat("../", 1) . '202-config/connect.php');

AUTH::require_user('', false);

template_top('Product Analytics Disclosure');

?>

<div style="max-width: 860px; margin: 24px auto 48px; padding: 0 16px;">
	<div class="page-header">
		<h4>Product analytics &amp; personalized help</h4>
		<p>What leaves your install, what never does, and how to switch it off.</p>
	</div>

	<div class="card-modern" style="padding: 24px 28px; margin-bottom: 16px;">
		<h5>Why we collect this</h5>
		<p>When product analytics is on, we use your Prosper202 usage to help you <strong>earn more</strong> —
			surfacing workflow tips and matching you with specially-sourced, higher-paying offers relevant to
			what you actually promote. Everything we send you based on this data is bounded to
			money-making, workflow, and offer-matching. Never generic promotion.</p>
	</div>

	<div class="card-modern" style="padding: 24px 28px; margin-bottom: 16px;">
		<h5>What is sent to Prosper202</h5>
		<ul>
			<li><strong>Traffic and results:</strong> clicks, conversions, income, cost, and net over the last
				30 days; top countries and device mix.</li>
			<li><strong>Your campaign setup:</strong> affiliate network names, traffic source names, campaign
				names, and the campaign destination (template) URLs you entered at setup — with any email or
				phone values redacted before sending.</li>
			<li><strong>Offer performance:</strong> per-campaign payout, currency, EPC, conversion rate, and
				clicks.</li>
			<li><strong>Account-level revenue aggregates:</strong> if you use Customer LTV, only account totals
				(total revenue, MRR/ARR, customer count, average LTV, active subscriptions).</li>
			<li><strong>Product usage:</strong> pages viewed inside Prosper202 and setup milestones (e.g.
				campaign created, tracking link generated, integration connected).</li>
		</ul>
		<p>We never share this data with third parties.</p>
	</div>

	<div class="card-modern" style="padding: 24px 28px; margin-bottom: 16px;">
		<h5>What never leaves your install</h5>
		<ul>
			<li><strong>Your visitors' data.</strong> We track your product usage as the account holder — never
				your traffic's personal data. (Your visitors' click-data privacy is governed separately by the
				Privacy Option above it in Settings.)</li>
			<li><strong>Your customers' data.</strong> Customer LTV records — names, aliases, emails,
				per-customer revenue, custom fields — never sync. Only the account-level aggregates listed
				above do.</li>
		</ul>
	</div>

	<div class="card-modern" style="padding: 24px 28px; margin-bottom: 16px;">
		<h5>Consent and the off switch</h5>
		<ul>
			<li>Product analytics is on by default outside the EU/UK, and held behind a one-time consent
				prompt for EU/UK account holders. You can turn it off (or back on) anytime in
				<a href="<?php echo get_absolute_url(); ?>202-account/account.php">Account Settings</a>.</li>
			<li>Switching it off stops collection and syncing immediately, and deletes the analytics data on
				your install that has not yet been delivered (queued events and the computed usage profile).</li>
			<li>A small <strong>essential</strong> tier stays on for operational use (account lifecycle, login,
				and support messaging delivery) — it carries no campaign or revenue analytics.</li>
			<li><strong>Money-making offers &amp; tips by email</strong> is a separate, opt-in consent. It is
				never inferred from the analytics setting, and you can unsubscribe anytime.</li>
		</ul>
	</div>
</div>

<?php template_bottom(); ?>
