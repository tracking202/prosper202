<?php
declare(strict_types=1);
include_once(str_repeat("../", 1).'202-config/connect.php');

/**
 * The UI kit: every component of the Prosper202 standard in every state, on
 * the v2 (Bootstrap 5.3) shell. Admin-only. Reviewers check a change here,
 * Playwright takes its reference screenshots here, and an agent looks here
 * before inventing a class. When a component gains a state, add it here.
 *
 * The figures on this page are examples, not the install's data.
 */

AUTH::require_user();

if (!isset($userObj) || !$userObj->hasPermission('access_to_settings')) {
	header('location: ' . get_absolute_url() . '202-account/');
	exit;
}

$base = get_absolute_url();

template_top('UI Kit', ['ui' => 'v2']);

$sections = [
	'tokens' => 'Tokens',
	'type' => 'Type',
	'buttons' => 'Buttons',
	'forms' => 'Forms',
	'pills' => 'Pills',
	'tiles' => 'Tiles',
	'table' => 'Table',
	'panel' => 'Panel &amp; list',
	'states' => 'Empty &amp; flash',
	'code' => 'Code &amp; strip',
	'overlays' => 'Overlays',
];
?>

<div class="p202-page-header">
	<div class="p202-page-header__icon"><i class="bi bi-palette2"></i></div>
	<div class="p202-page-header__text">
		<h1 class="p202-page-header__title">UI kit</h1>
		<p class="p202-page-header__desc">Every component of the Prosper202 standard in every state, on the Bootstrap 5 shell. Use the account menu to switch the theme.</p>
	</div>
	<div class="p202-page-header__actions">
		<a class="btn btn-secondary" href="<?php echo $base; ?>202-account/docs.php?doc=ui-standard"><i class="bi bi-book"></i> Read the standard</a>
	</div>
</div>

<nav class="nav p202-tabs p202-tabs--compact" aria-label="Kit sections">
	<?php foreach ($sections as $anchor => $label) { ?>
		<a class="nav-link<?php echo $anchor === 'tokens' ? ' active' : ''; ?>" href="#<?php echo $anchor; ?>"><?php echo $label; ?></a>
	<?php } ?>
</nav>

<section class="p202-section" id="tokens">
	<h2 class="p202-section__title">Tokens</h2>
	<p class="text-secondary">The palette is Bootstrap's own variables, redefined in <code>202-css/p202-theme.css</code> for light and dark. Body content uses Bootstrap classes and the component layer; it never reads the tokens directly.</p>
	<div class="row g-3">
		<?php
		$swatches = [
			['Accent', 'var(--p202-accent)', '#fff'],
			['Accent soft', 'var(--bs-primary-bg-subtle)', 'var(--bs-primary-text-emphasis)'],
			['Ink', 'var(--bs-body-color)', 'var(--bs-body-bg)'],
			['Muted', 'var(--bs-secondary-color)', 'var(--bs-body-bg)'],
			['Hairline', 'var(--bs-border-color)', 'var(--bs-body-color)'],
			['Surface', 'var(--p202-surface)', 'var(--bs-body-color)'],
			['Ground', 'var(--bs-body-bg)', 'var(--bs-body-color)'],
			['Success', 'var(--bs-success)', '#fff'],
			['Warning', 'var(--bs-warning)', '#fff'],
			['Danger', 'var(--bs-danger)', '#fff'],
		];
		foreach ($swatches as [$name, $bg, $fg]) { ?>
			<div class="col-6 col-sm-4 col-md-3 col-lg-2">
				<div class="rounded-3 border p-3" style="background: <?php echo $bg; ?>; color: <?php echo $fg; ?>; min-height: 72px;">
					<strong><?php echo $name; ?></strong>
					<div class="small font-monospace opacity-75"><?php echo htmlspecialchars($bg, ENT_QUOTES, 'UTF-8'); ?></div>
				</div>
			</div>
		<?php } ?>
	</div>
</section>

<section class="p202-section" id="type">
	<h2 class="p202-section__title">Type</h2>
	<div class="row g-4">
		<div class="col-md-7">
			<h1>Heading one, Lato 700</h1>
			<h2>Heading two for a section</h2>
			<h3>Heading three for a card</h3>
			<h4>Heading four for a group</h4>
			<p>Body text at 15px. Prosper202 tracks every click, lead and sale so a campaign's real return is one report away. Links look <a href="#type">like this</a>, and <strong>strong</strong> text carries the weight.</p>
			<p class="text-secondary small">Secondary text at 13px, for hints, descriptions and metadata.</p>
			<p><code>code_like_a_field_name</code> and <kbd>Ctrl</kbd> + <kbd>C</kbd>.</p>
		</div>
		<div class="col-md-5">
			<div class="p202-panel">
				<div class="p202-panel__head"><h3 class="p202-panel__title">Numbers</h3></div>
				<div class="p202-panel__body">
					<p class="mb-1">Digits that line up use <code>tabular-nums</code>, right-aligned in tables and tiles:</p>
					<div class="font-monospace text-end" style="font-variant-numeric: tabular-nums;">1,412<br>$8,912.40<br>94.1%</div>
				</div>
			</div>
		</div>
	</div>
</section>

<section class="p202-section" id="buttons">
	<h2 class="p202-section__title">Buttons</h2>
	<p class="text-secondary">One primary button per form. Danger is reserved for destructive actions that confirm. Secondary is the quiet default.</p>
	<div class="p202-toolbar mb-3">
		<button type="button" class="btn btn-primary">Register app</button>
		<button type="button" class="btn btn-secondary">Cancel</button>
		<button type="button" class="btn btn-outline-primary">Preview schema</button>
		<button type="button" class="btn btn-danger">Delete</button>
		<button type="button" class="btn btn-outline-danger">Rotate token…</button>
		<button type="button" class="btn btn-link">Show account-wide defaults</button>
	</div>
	<div class="p202-toolbar mb-3">
		<button type="button" class="btn btn-primary btn-sm">Small primary</button>
		<button type="button" class="btn btn-secondary btn-sm"><i class="bi bi-download"></i> With icon</button>
		<button type="button" class="btn btn-primary" disabled>Disabled</button>
		<button type="button" class="btn btn-primary" disabled><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Saving…</button>
		<div class="btn-group" role="group" aria-label="Resolution">
			<button type="button" class="btn btn-outline-primary active">By day</button>
			<button type="button" class="btn btn-outline-primary">By hour</button>
		</div>
	</div>
</section>

<section class="p202-section" id="forms">
	<h2 class="p202-section__title">Forms</h2>
	<p class="text-secondary">Labels above controls, the hint below, the error under the hint in the API's own words.</p>
	<div class="row g-4">
		<div class="col-md-6">
			<form class="p202-panel" action="#forms" method="get" onsubmit="return false;">
				<div class="p202-panel__head"><h3 class="p202-panel__title">Register an app</h3></div>
				<div class="p202-panel__body">
					<div class="mb-3">
						<label class="form-label" for="kit-platform">Platform</label>
						<div class="d-flex gap-3" id="kit-platform">
							<div class="form-check"><input class="form-check-input" type="radio" name="kit_platform" id="kit-platform-ios" checked><label class="form-check-label" for="kit-platform-ios">iOS</label></div>
							<div class="form-check"><input class="form-check-input" type="radio" name="kit_platform" id="kit-platform-android" disabled><label class="form-check-label" for="kit-platform-android">Android · not supported yet</label></div>
						</div>
					</div>
					<div class="mb-3">
						<label class="form-label" for="kit-app-id">App Store ID <span class="text-danger">*</span></label>
						<input type="text" class="form-control" id="kit-app-id" value="1234567890">
						<div class="form-text">The number in the app's App Store URL.</div>
					</div>
					<div class="mb-3">
						<label class="form-label" for="kit-app-name">App name <span class="text-danger">*</span></label>
						<input type="text" class="form-control is-invalid" id="kit-app-name" value="">
						<div class="form-text">Shown in reports.</div>
						<div class="invalid-feedback">App name is required.</div>
					</div>
					<div class="mb-3">
						<label class="form-label" for="kit-notes">Notes</label>
						<textarea class="form-control" id="kit-notes" rows="2" placeholder="Optional, up to 500 characters"></textarea>
					</div>
					<div class="mb-3">
						<label class="form-label" for="kit-kind">Kind</label>
						<select class="form-select" id="kit-kind">
							<option>Fine value (0–63)</option>
							<option>Coarse value</option>
						</select>
					</div>
					<div class="form-check mb-2">
						<input class="form-check-input" type="checkbox" id="kit-dev" checked>
						<label class="form-check-label" for="kit-dev">Accept development postbacks</label>
						<div class="form-text">Applies to postbacks already stored, and is withdrawn when you turn it off.</div>
					</div>
					<div class="form-check form-switch mb-3">
						<input class="form-check-input" type="checkbox" role="switch" id="kit-switch" checked>
						<label class="form-check-label" for="kit-switch">Active</label>
					</div>
					<div class="input-group mb-3">
						<span class="input-group-text">Days</span>
						<input type="number" class="form-control" id="kit-days" value="30" min="0" max="3650">
						<button class="btn btn-secondary" type="button">Preview pruning</button>
					</div>
					<div class="p202-form-actions">
						<button type="button" class="btn btn-secondary">Cancel</button>
						<button type="submit" class="btn btn-primary">Register app</button>
					</div>
				</div>
			</form>
		</div>
		<div class="col-md-6">
			<div class="p202-panel">
				<div class="p202-panel__head"><h3 class="p202-panel__title">Filter row</h3><span class="p202-panel__sub">select menus and text filters in one line</span></div>
				<div class="p202-panel__body">
					<div class="row g-2">
						<div class="col-sm-4"><label class="form-label" for="kit-f-app">App</label><select class="form-select form-select-sm" id="kit-f-app"><option>All apps</option><option>Summit Run</option></select></div>
						<div class="col-sm-4"><label class="form-label" for="kit-f-sig">Signature</label><select class="form-select form-select-sm" id="kit-f-sig"><option>Verified only</option><option>All</option><option>Invalid</option></select></div>
						<div class="col-sm-4"><label class="form-label" for="kit-f-net">Ad network</label><input type="text" class="form-control form-control-sm" id="kit-f-net" placeholder="acme.skadnetwork"></div>
					</div>
					<div class="p202-form-actions">
						<button type="button" class="btn btn-secondary btn-sm">Reset</button>
						<button type="button" class="btn btn-primary btn-sm">Apply</button>
					</div>
				</div>
				<div class="p202-panel__body">
					<div class="mb-2"><span class="form-label d-block">Date range</span>
						<div class="p202-toolbar">
							<input type="date" class="form-control form-control-sm" id="kit-from" value="2026-09-04" style="max-width: 170px;">
							<span class="text-secondary">to</span>
							<input type="date" class="form-control form-control-sm" id="kit-to" value="2026-09-11" style="max-width: 170px;">
							<select class="form-select form-select-sm" id="kit-preset" style="max-width: 150px;"><option>Last 7 days</option><option>Today</option><option>Last 30 days</option></select>
						</div>
					</div>
					<div class="p202-skeleton mt-3" style="height: 14px; width: 60%;" aria-hidden="true"></div>
					<div class="p202-skeleton mt-2" style="height: 14px; width: 40%;" aria-hidden="true"></div>
					<div class="form-text mt-2">A loading state is a skeleton in place, never a spinner in a blank page.</div>
				</div>
			</div>
		</div>
	</div>
</section>

<section class="p202-section" id="pills">
	<h2 class="p202-section__title">Pills</h2>
	<p class="text-secondary">Status only, never decoration. Five tones with fixed meanings.</p>
	<div class="p202-toolbar">
		<span class="p202-pill">neutral · 990077001</span>
		<span class="p202-pill p202-pill--accent">accent · 5 rules</span>
		<span class="p202-pill p202-pill--good">good · valid</span>
		<span class="p202-pill p202-pill--warn">warning · development</span>
		<span class="p202-pill p202-pill--bad">danger · invalid</span>
		<span class="badge text-bg-primary rounded-pill">Bootstrap badge</span>
		<span class="badge text-bg-secondary rounded-pill">12</span>
	</div>
</section>

<section class="p202-section" id="tiles">
	<h2 class="p202-section__title">Tiles</h2>
	<div class="p202-tiles">
		<div class="p202-tile"><div class="p202-tile__label">Postbacks</div><div class="p202-tile__value">1,412</div><div class="p202-tile__sub">all signatures</div></div>
		<div class="p202-tile is-good"><div class="p202-tile__label">Installs</div><div class="p202-tile__value">1,204</div><div class="p202-tile__sub">verified</div></div>
		<div class="p202-tile"><div class="p202-tile__label">Re-downloads</div><div class="p202-tile__value">96</div></div>
		<div class="p202-tile is-bad"><div class="p202-tile__label">Losses</div><div class="p202-tile__value">58</div><div class="p202-tile__sub">did not win</div></div>
		<div class="p202-tile"><div class="p202-tile__label">Decoded revenue</div><div class="p202-tile__value">$8,912</div></div>
		<div class="p202-tile is-muted"><div class="p202-tile__label">Undecoded</div><div class="p202-tile__value">140</div><div class="p202-tile__sub">no matching rule</div></div>
	</div>
</section>

<section class="p202-section" id="table">
	<h2 class="p202-section__title">Table</h2>
	<div class="p202-table-toolbar">
		<div class="p202-toolbar">
			<span class="p202-pill p202-pill--accent">Day</span><span class="p202-pill">App</span><span class="p202-pill">Ad network</span><span class="p202-pill">Country</span>
		</div>
		<div class="p202-table-toolbar__aside">
			<a href="#table" class="btn btn-secondary btn-sm"><i class="bi bi-file-earmark-spreadsheet"></i> Download to excel</a>
		</div>
	</div>
	<div class="p202-table-wrap">
		<table class="table table-hover p202-table">
			<thead><tr><th>Day</th><th class="num">Postbacks</th><th class="num">Installs</th><th class="num">Re-downloads</th><th class="num">Losses</th><th class="num">Revenue</th><th>Signature</th></tr></thead>
			<tbody>
				<tr><td>2026-09-10</td><td class="num">212</td><td class="num">181</td><td class="num">14</td><td class="num">9</td><td class="num">$1,304.00</td><td><span class="p202-pill p202-pill--good">valid</span></td></tr>
				<tr class="p202-table__link-row"><td>2026-09-11</td><td class="num">98</td><td class="num">84</td><td class="num">7</td><td class="num">3</td><td class="num">$612.00</td><td><span class="p202-pill p202-pill--warn">development</span></td></tr>
				<tr><td>2026-09-12</td><td class="num">0</td><td class="num">0</td><td class="num">0</td><td class="num">0</td><td class="num">$0.00</td><td><span class="p202-pill">unverifiable</span></td></tr>
				<tr class="p202-table__totals"><td>Totals for report</td><td class="num">310</td><td class="num">265</td><td class="num">21</td><td class="num">12</td><td class="num">$1,916.00</td><td></td></tr>
			</tbody>
		</table>
	</div>
	<nav class="mt-3" aria-label="Pages">
		<ul class="pagination pagination-sm mb-0">
			<li class="page-item disabled"><a class="page-link" href="#table" tabindex="-1" aria-disabled="true">‹</a></li>
			<li class="page-item active" aria-current="page"><a class="page-link" href="#table">1</a></li>
			<li class="page-item"><a class="page-link" href="#table">2</a></li>
			<li class="page-item"><a class="page-link" href="#table">3</a></li>
			<li class="page-item"><a class="page-link" href="#table">›</a></li>
		</ul>
	</nav>
</section>

<section class="p202-section" id="panel">
	<h2 class="p202-section__title">Panel &amp; list</h2>
	<div class="row g-4">
		<div class="col-lg-6">
			<div class="p202-panel">
				<div class="p202-panel__head">
					<h3 class="p202-panel__title">Your apps</h3>
					<span class="p202-pill">2 apps</span>
					<div class="p202-panel__aside"><input type="search" class="form-control form-control-sm" id="kit-list-filter" placeholder="Filter apps…" aria-label="Filter apps"></div>
				</div>
				<div class="p202-panel__body">
					<ul class="p202-list">
						<li class="p202-list__item is-active">
							<span class="p202-list__name">Summit Run</span>
							<span class="p202-pill">iOS · 990077001</span>
							<span class="p202-pill p202-pill--accent">5 rules</span>
							<span class="p202-list__actions"><a class="p202-list__action" href="#panel">open</a><a class="p202-list__action" href="#panel">edit</a><a class="p202-list__action p202-list__action--danger" href="#panel">remove</a></span>
							<span class="p202-list__meta">last postback 2 h ago</span>
						</li>
						<li class="p202-list__item">
							<span class="p202-list__name">EVAL Offer Network</span>
							<span class="p202-pill">1 category</span>
							<span class="p202-list__actions"><a class="p202-list__action" href="#panel">edit</a><a class="p202-list__action p202-list__action--danger" href="#panel">remove</a></span>
							<ul class="p202-list__children">
								<li class="p202-list__item"><span class="p202-list__name">EVAL Campaign A</span><span class="p202-pill p202-pill--good">$12.50</span><span class="p202-list__actions"><a class="p202-list__action" href="#panel">link</a><a class="p202-list__action" href="#panel">edit</a><a class="p202-list__action" href="#panel">copy</a><a class="p202-list__action p202-list__action--danger" href="#panel">remove</a></span></li>
								<li class="p202-list__item"><span class="p202-list__name">EVAL Campaign B</span><span class="p202-pill p202-pill--good">$4.00</span><span class="p202-list__actions"><a class="p202-list__action" href="#panel">link</a><a class="p202-list__action" href="#panel">edit</a><a class="p202-list__action" href="#panel">copy</a><a class="p202-list__action p202-list__action--danger" href="#panel">remove</a></span></li>
							</ul>
						</li>
					</ul>
				</div>
			</div>
		</div>
		<div class="col-lg-6">
			<div class="p202-page-header p202-page-header--accent mb-3">
				<div class="p202-page-header__icon"><i class="bi bi-phone"></i></div>
				<div class="p202-page-header__text">
					<h2 class="p202-page-header__title">Mobile App Attribution</h2>
					<p class="p202-page-header__desc">The accent variant of the page header, for the Setup family.</p>
				</div>
			</div>
			<div class="p202-panel">
				<div class="p202-panel__head"><h3 class="p202-panel__title">Getting started</h3><span class="p202-panel__sub">a checklist panel</span></div>
				<div class="p202-panel__body">
					<div class="form-check"><input class="form-check-input" type="checkbox" id="kit-c1" checked disabled><label class="form-check-label text-decoration-line-through text-secondary" for="kit-c1">Both receivers answer over HTTPS</label></div>
					<div class="form-check"><input class="form-check-input" type="checkbox" id="kit-c2" checked disabled><label class="form-check-label text-decoration-line-through text-secondary" for="kit-c2">Register the app you advertise</label></div>
					<div class="form-check"><input class="form-check-input" type="checkbox" id="kit-c3" disabled><label class="form-check-label" for="kit-c3">Add conversion-value rules</label></div>
					<div class="form-check"><input class="form-check-input" type="checkbox" id="kit-c4" disabled><label class="form-check-label" for="kit-c4">Add the Info.plist keys and configure the SDK</label></div>
				</div>
			</div>
		</div>
	</div>
</section>

<section class="p202-section" id="states">
	<h2 class="p202-section__title">Empty states &amp; flashes</h2>
	<div class="row g-4">
		<div class="col-lg-5">
			<div class="p202-empty">
				<i class="bi bi-inbox p202-empty__icon"></i>
				<strong class="p202-empty__title">No apps registered yet</strong>
				<div>Register the app you advertise so its postbacks are claimed and decoded.</div>
				<div class="p202-empty__action"><button type="button" class="btn btn-primary btn-sm">Register an app</button></div>
			</div>
		</div>
		<div class="col-lg-7">
			<div class="alert alert-info p202-flash" role="status"><i class="bi bi-info-circle"></i><div class="p202-flash__body"><strong>No postbacks in this date range yet.</strong> Apple sends the first postback 24 to 48 hours after an install.</div></div>
			<div class="alert alert-success p202-flash" role="status"><i class="bi bi-check-circle"></i><div class="p202-flash__body">Registered. Postbacks that arrived before today have been claimed.</div></div>
			<div class="alert alert-warning p202-flash" role="status"><i class="bi bi-exclamation-triangle"></i><div class="p202-flash__body">Apple requires HTTPS on port 443. This install answers on <code>http://</code>.</div></div>
			<div class="alert alert-danger p202-flash alert-dismissible" role="alert"><i class="bi bi-x-circle"></i><div class="p202-flash__body">This App Store id is already registered.</div><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
		</div>
	</div>
</section>

<section class="p202-section" id="code">
	<h2 class="p202-section__title">Code &amp; strip</h2>
	<div class="row g-4">
		<div class="col-lg-6">
			<label class="form-label">Simple global postback URL</label>
			<div class="p202-code mb-3">
				<pre class="p202-code__value"><?php echo htmlspecialchars($base, ENT_QUOTES, 'UTF-8'); ?>tracking202/static/gpb.php?amount=&amp;subid=</pre>
				<button type="button" class="btn btn-secondary btn-sm p202-copy" data-p202-copy="<?php echo htmlspecialchars($base . 'tracking202/static/gpb.php?amount=&subid=', ENT_QUOTES, 'UTF-8'); ?>"><i class="bi bi-clipboard"></i> Copy</button>
			</div>
			<label class="form-label">Schema token</label>
			<div class="p202-code">
				<pre class="p202-code__value p202-code__value--masked" id="kit-token" data-p202-value="3f9a1c2e8b7d4f6a0e1c2b3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f6c21e">3f9a•••••••••••••••••••••••••••••••••••••••••••••••••••••••••c21e</pre>
				<button type="button" class="btn btn-secondary btn-sm" data-p202-reveal="#kit-token">Reveal</button>
				<button type="button" class="btn btn-secondary btn-sm p202-copy" data-p202-copy="3f9a1c2e8b7d4f6a0e1c2b3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f6c21e">Copy</button>
				<button type="button" class="btn btn-outline-danger btn-sm">Rotate…</button>
			</div>
		</div>
		<div class="col-lg-6">
			<div class="p202-strip">
				<div class="p202-strip__row"><span class="p202-pill p202-pill--good">Ready</span><span class="p202-strip__label">SKAdNetwork receiver</span><span class="p202-strip__value">https://track.example.com/.well-known/skadnetwork/report-attribution/</span><span class="p202-strip__aside"><button type="button" class="btn btn-secondary btn-sm p202-copy" data-p202-copy="https://track.example.com/.well-known/skadnetwork/report-attribution/">Copy</button></span></div>
				<div class="p202-strip__row"><span class="p202-pill p202-pill--bad">Not reachable</span><span class="p202-strip__label">AdAttributionKit receiver</span><span class="p202-strip__value">https://track.example.com/.well-known/appattribution/report-attribution/</span><span class="p202-strip__aside"><button type="button" class="btn btn-secondary btn-sm p202-copy" data-p202-copy="https://track.example.com/.well-known/appattribution/report-attribution/">Copy</button></span></div>
				<div class="p202-strip__row"><span class="p202-pill p202-pill--warn">Stale</span><span class="p202-strip__label">Attribution rebuild cron</span><span class="p202-strip__value">last ran 31 h ago</span></div>
				<div class="p202-strip__note">Checked from your browser just now · <a href="#code">Re-check</a></div>
			</div>
		</div>
	</div>
</section>

<section class="p202-section" id="overlays">
	<h2 class="p202-section__title">Overlays</h2>
	<p class="text-secondary">Bootstrap's own dropdown, modal, tooltip, popover and toast, with the theme applied.</p>
	<div class="p202-toolbar">
		<div class="dropdown">
			<button class="btn btn-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Grouped by: Day</button>
			<ul class="dropdown-menu">
				<li><a class="dropdown-item active" href="#overlays">Day</a></li>
				<li><a class="dropdown-item" href="#overlays">App</a></li>
				<li><a class="dropdown-item" href="#overlays">Ad network</a></li>
				<li><hr class="dropdown-divider"></li>
				<li><a class="dropdown-item" href="#overlays">Conversion type</a></li>
			</ul>
		</div>
		<button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#kit-modal">Remove app…</button>
		<span>Help icon <a href="#overlays" class="p202-help" data-bs-toggle="tooltip" title="Postbacks whose Apple signature verified."><i class="bi bi-question-circle"></i></a></span>
		<button type="button" class="btn btn-link" data-bs-toggle="popover" data-bs-placement="bottom" data-bs-title="Verified only" data-bs-content="Counts include only postbacks whose Apple signature verified. Change the Signature filter to see the rest.">Popover</button>
		<button type="button" class="btn btn-secondary" id="kit-toast-btn">Show toast</button>
	</div>

	<div class="modal fade" id="kit-modal" tabindex="-1" aria-labelledby="kit-modal-title" aria-hidden="true">
		<div class="modal-dialog">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title" id="kit-modal-title">Remove Summit Run?</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					<p>Postbacks already claimed by this app keep their owner. Development trust is withdrawn.</p>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Keep it</button>
					<button type="button" class="btn btn-danger" data-bs-dismiss="modal">Remove app</button>
				</div>
			</div>
		</div>
	</div>

	<div class="toast-container position-fixed bottom-0 start-0 p-3">
		<div id="kit-toast" class="toast" role="status" aria-live="polite" aria-atomic="true">
			<div class="toast-header"><i class="bi bi-check-circle text-success me-2"></i><strong class="me-auto">Registered</strong><button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button></div>
			<div class="toast-body">Summit Run is registered. Postbacks that arrived earlier have been claimed.</div>
		</div>
	</div>
	<script>
		document.getElementById('kit-toast-btn').addEventListener('click', function () {
			if (window.bootstrap) {
				window.bootstrap.Toast.getOrCreateInstance(document.getElementById('kit-toast')).show();
			}
		});
	</script>
</section>

<?php template_bottom(); ?>
