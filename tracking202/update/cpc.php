<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -19) . '/202-config/connect.php');
require_once __DIR__ . '/_includes/update_ui.php';

AUTH::require_user();

if (!$userObj->hasPermission("access_to_update_section")) {
	header('location: ' . get_absolute_url() . 'tracking202/');
	die();
}

/*
 * Update CPC: set what a set of past clicks cost.
 *
 * Two steps, as before, but on the page itself rather than through two AJAX
 * fragments (tracking202/ajax/update_cpc.php drew the confirmation and
 * update_cpc2.php wrote — without asking for the session token). The form is
 * a GET, so "Check these clicks" is a link that can be sent; it answers with
 * what will change and how many clicks that is, counted with the same clause
 * the update runs. "Update N clicks" is a POST that carries the token, the
 * count N and the highest click id the check saw: the update is bounded by
 * that id and runs only if the bounded selection still counts N, so it never
 * changes a click the person did not count (a window that includes today
 * keeps gaining clicks). When the count moved, nothing is written and the
 * page shows the check again with the new count.
 *
 * The days are the account's days: the timezone is set before the dates are
 * read, as update_cpc2.php did.
 */

AUTH::set_timezone($_SESSION['user_timezone'] ?? date_default_timezone_get());

$userId = (int) $_SESSION['user_id'];
$conn = new \Prosper202\Database\Connection($db);
$base = get_absolute_url();
$self = $base . 'tracking202/update/cpc.php';

$isApply = $_SERVER['REQUEST_METHOD'] == 'POST';
$isPreview = !$isApply && isset($_GET['preview']);
$tokenRefused = false;
$errors = [];
$values = null;
$labels = [];
$matching = null;
$through = '0';
$updated = null;
// Set when a confirm was refused because it no longer names the clicks the
// person checked: ['confirmed' => the count they saw, or null when the
// confirm did not carry one].
$stale = null;

if ($isApply || $isPreview) {
	if ($isApply && !AUTH::check_csrf_token()) {
		$tokenRefused = true;
		$values = p202_update_cpc_parse($_POST)['values'];
	} else {
		$parsed = p202_update_cpc_parse($isApply ? $_POST : $_GET);
		$values = $parsed['values'];
		$errors = $parsed['errors'];
		if ($errors === []) {
			$labels = p202_update_cpc_labels($conn, $values, $userId, $errors);
		}
		if ($errors === [] && $isApply) {
			// The confirm names the clicks the check counted: at most the
			// highest id it saw, and exactly as many. Counted again under a
			// lock and written in the same transaction, so the number on the
			// button is the number that changes; when the selection moved (a
			// click edited into it, or recorded late below the boundary),
			// nothing is written and the page checks again.
			$snapshot = p202_update_cpc_snapshot($_POST);
			if ($snapshot === null) {
				$stale = ['confirmed' => null];
			} else {
				$scope = p202_update_cpc_scope($values, $userId, $snapshot['through']);
				$stale = $conn->transaction(static function () use ($conn, $scope, $values, $userId, $snapshot, &$updated): ?array {
					$stmt = $conn->prepareWrite('SELECT COUNT(DISTINCT 202_clicks.click_id) AS matching FROM 202_clicks' . $scope['joins'] . $scope['where'] . ' FOR UPDATE');
					$conn->bind($stmt, $scope['types'], $scope['params']);
					$row = $conn->fetchOne($stmt);
					if ($row === null) {
						// A COUNT always answers one row; none means the read failed.
						throw new \RuntimeException('Update CPC: counting the clicks returned no row');
					}
					if ((int) $row['matching'] !== $snapshot['count']) {
						return ['confirmed' => $snapshot['count']];
					}

					$stmt = $conn->prepareWrite('UPDATE 202_clicks' . $scope['joins'] . ' SET 202_clicks.click_cpc = ?' . $scope['where']);
					$conn->bind($stmt, 's' . $scope['types'], array_merge([(string) $values['cpc']], $scope['params']));
					$updated = $conn->executeUpdate($stmt);

					// The data engine rebuilds the hours these clicks fall in, for
					// the slice they were chosen by.
					$dirty = $conn->prepareWrite('INSERT IGNORE INTO 202_dirty_hours SET ppc_account_id = ?, aff_campaign_id = ?, user_id = ?, click_time_from = ?, click_time_to = ?, aff_network_id = ?, text_ad_id = ?, landing_page_id = ?, ppc_network_id = ?');
					$conn->bind($dirty, 'iiiiiiiii', [(int) $values['ppc_account_id'], (int) $values['aff_campaign_id'], $userId, (int) $values['from_time'], (int) $values['to_time'],
						(int) $values['aff_network_id'], (int) $values['text_ad_id'], (int) $values['landing_page_id'], (int) $values['ppc_network_id']]);
					$conn->executeUpdate($dirty);
					return null;
				});
			}
		}
		if ($errors === [] && ($isPreview || $stale !== null)) {
			// The check, and the check again after a refused confirm: how many
			// clicks match now, and the highest click id among them, which the
			// confirm form carries back.
			$scope = p202_update_cpc_scope($values, $userId);
			$stmt = $conn->prepareWrite('SELECT COUNT(DISTINCT 202_clicks.click_id) AS matching, COALESCE(MAX(202_clicks.click_id), 0) AS through_click_id FROM 202_clicks' . $scope['joins'] . $scope['where']);
			$conn->bind($stmt, $scope['types'], $scope['params']);
			$count = $conn->fetchOne($stmt);
			if ($count === null) {
				// A COUNT always answers one row; none means the read failed.
				throw new \RuntimeException('Update CPC: counting the clicks returned no row');
			}
			$matching = (int) $count['matching'];
			$through = (string) $count['through_click_id'];
		}
	}
}

// What the form shows: what was asked for, or the last seven days.
$form = $values ?? [
	'aff_network_id' => 0, 'aff_campaign_id' => 0, 'ppc_network_id' => 0, 'ppc_account_id' => 0,
	'landing_page_id' => 0, 'text_ad_id' => 0, 'method_of_promotion' => '',
	'from' => date('Y-m-d', strtotime('-6 days')), 'to' => date('Y-m-d'), 'cpc' => '',
];
$formValue = static fn (string $field): string => ((int) ($form[$field] ?? 0)) > 0 ? (string) $form[$field] : '';
$campaigns = p202_update_campaign_lists($db, $userId);
$traffic = p202_update_traffic_lists($db, $userId);
$landingPages = [];
foreach (p202_setup_rows($db, "SELECT landing_page_id, landing_page_nickname FROM 202_landing_pages WHERE user_id = '" . $userId . "' AND landing_page_deleted = 0 ORDER BY landing_page_nickname ASC") as $row) {
	$landingPages[(string) $row['landing_page_id']] = (string) $row['landing_page_nickname'];
}
$textAds = [];
foreach (p202_setup_rows($db, "SELECT text_ad_id, text_ad_name FROM 202_text_ads WHERE user_id = '" . $userId . "' AND text_ad_deleted = 0 ORDER BY text_ad_name ASC") as $row) {
	$textAds[(string) $row['text_ad_id']] = (string) $row['text_ad_name'];
}
$advancedSet = $formValue('landing_page_id') !== '' || $formValue('text_ad_id') !== '' || ($form['method_of_promotion'] ?? '') !== ''
	|| isset($errors['landing_page_id']) || isset($errors['text_ad_id']) || isset($errors['method_of_promotion']);
$fieldKeys = ['aff_network_id', 'aff_campaign_id', 'ppc_network_id', 'ppc_account_id', 'landing_page_id', 'text_ad_id', 'method_of_promotion', 'from', 'to', 'cpc'];

template_top('Update CPC', ['ui' => 'v2']);

echo p202_update_header('bi-currency-dollar', 'Update CPC', 'Prosper202 records the bid as each click\'s cost. Set what a set of past clicks really cost, so your reports show the real spend.');

if ($updated !== null) {
	echo p202_flash('ok', $updated . ($updated === 1 ? ' click' : ' clicks') . ' updated. Every click you checked now costs ' . p202_update_money((string) $values['cpc']) . '; clicks recorded since keep their own cost.');
}
if ($stale !== null) {
	echo p202_flash('warn', $stale['confirmed'] === null
		? 'This confirmation did not say which clicks you checked, so nothing was changed. Check the count below, then confirm again.'
		: 'The clicks in this selection changed after you checked them: you confirmed ' . $stale['confirmed'] . ' and ' . $matching . ' match now. Nothing was changed. Check the count below, then confirm again.');
}
if ($tokenRefused) {
	echo p202_flash('bad', P202_UPDATE_TOKEN_REFUSED);
}
if ($errors !== []) {
	echo p202_setup_error_flashes($errors, $fieldKeys);
}
?>

<div class="row g-4">
	<div class="col-12 col-lg-7">
		<section class="p202-panel">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title">Which clicks</h2>
				<span class="p202-panel__sub">nothing changes until you confirm</span>
			</div>
			<div class="p202-panel__body">
				<form method="get" action="<?php echo p202_setup_e($self); ?>" id="cpc_form">
					<input type="hidden" name="preview" value="1">
					<div class="row g-3 mb-3">
						<div class="col-sm-6">
							<label class="form-label" for="from">First day</label>
							<input type="date" class="form-control<?php echo p202_setup_invalid($errors, 'from'); ?>" id="from" name="from" value="<?php echo p202_setup_e($form['from'] ?? ''); ?>" required>
							<?php echo p202_setup_feedback($errors, 'from'); ?>
						</div>
						<div class="col-sm-6">
							<label class="form-label" for="to">Last day</label>
							<input type="date" class="form-control<?php echo p202_setup_invalid($errors, 'to'); ?>" id="to" name="to" value="<?php echo p202_setup_e($form['to'] ?? ''); ?>" required>
							<?php echo p202_setup_feedback($errors, 'to'); ?>
						</div>
						<div class="col-12 form-text mt-1">Whole days in your account's time zone. The form starts on the last seven days.</div>
					</div>
					<div class="row g-3 mb-3">
						<div class="col-sm-6">
							<label class="form-label" for="ppc_network_id">Traffic source</label>
							<select class="form-select<?php echo p202_setup_invalid($errors, 'ppc_network_id'); ?>" id="ppc_network_id" name="ppc_network_id">
								<?php echo p202_setup_options($traffic['networks'], $formValue('ppc_network_id'), 'Every traffic source', '0'); ?>
							</select>
							<?php echo p202_setup_feedback($errors, 'ppc_network_id'); ?>
						</div>
						<div class="col-sm-6">
							<label class="form-label" for="ppc_account_id">Account</label>
							<select class="form-select<?php echo p202_setup_invalid($errors, 'ppc_account_id'); ?>" id="ppc_account_id" name="ppc_account_id" data-p202-filter-by="#ppc_network_id" data-p202-filter-key="network">
								<?php echo p202_setup_options($traffic['accounts'], $formValue('ppc_account_id'), 'Every account', '0'); ?>
							</select>
							<?php echo p202_setup_feedback($errors, 'ppc_account_id'); ?>
						</div>
						<div class="col-sm-6">
							<label class="form-label" for="aff_network_id">Category</label>
							<select class="form-select<?php echo p202_setup_invalid($errors, 'aff_network_id'); ?>" id="aff_network_id" name="aff_network_id">
								<?php echo p202_setup_options($campaigns['networks'], $formValue('aff_network_id'), 'Every category', '0'); ?>
							</select>
							<?php echo p202_setup_feedback($errors, 'aff_network_id'); ?>
						</div>
						<div class="col-sm-6">
							<label class="form-label" for="aff_campaign_id">Campaign</label>
							<select class="form-select<?php echo p202_setup_invalid($errors, 'aff_campaign_id'); ?>" id="aff_campaign_id" name="aff_campaign_id" data-p202-filter-by="#aff_network_id" data-p202-filter-key="network">
								<?php echo p202_setup_options($campaigns['campaigns'], $formValue('aff_campaign_id'), 'Every campaign', '0'); ?>
							</select>
							<?php echo p202_setup_feedback($errors, 'aff_campaign_id'); ?>
						</div>
					</div>
					<details class="p202-disclosure mb-3" data-p202-remember="update-cpc-advanced"<?php echo $advancedSet ? ' open' : ''; ?>>
						<summary>Advanced <span class="p202-disclosure__hint">direct links or landing pages, one landing page, one text ad</span></summary>
						<div class="p202-disclosure__body">
							<div class="mb-3">
								<label class="form-label" for="method_of_promotion">Method of promotion</label>
								<select class="form-select<?php echo p202_setup_invalid($errors, 'method_of_promotion'); ?>" id="method_of_promotion" name="method_of_promotion">
									<?php echo p202_setup_options(['directlink' => 'Direct links only', 'landingpage' => 'Landing pages only'], (string) ($form['method_of_promotion'] ?? ''), 'Direct links and landing pages'); ?>
								</select>
								<?php echo p202_setup_feedback($errors, 'method_of_promotion'); ?>
							</div>
							<div class="mb-3">
								<label class="form-label" for="landing_page_id">Landing page</label>
								<select class="form-select<?php echo p202_setup_invalid($errors, 'landing_page_id'); ?>" id="landing_page_id" name="landing_page_id">
									<?php echo p202_setup_options($landingPages, $formValue('landing_page_id'), 'Every landing page', '0'); ?>
								</select>
								<?php echo p202_setup_feedback($errors, 'landing_page_id'); ?>
							</div>
							<div class="mb-1">
								<label class="form-label" for="text_ad_id">Text ad</label>
								<select class="form-select<?php echo p202_setup_invalid($errors, 'text_ad_id'); ?>" id="text_ad_id" name="text_ad_id">
									<?php echo p202_setup_options($textAds, $formValue('text_ad_id'), 'Every text ad', '0'); ?>
								</select>
								<?php echo p202_setup_feedback($errors, 'text_ad_id'); ?>
							</div>
						</div>
					</details>
					<div class="mb-3">
						<label class="form-label" for="cpc">New CPC</label>
						<div class="input-group">
							<span class="input-group-text">$</span>
							<input type="number" class="form-control<?php echo p202_setup_invalid($errors, 'cpc'); ?>" id="cpc" name="cpc" value="<?php echo p202_setup_e($form['cpc'] ?? ''); ?>" min="0" max="<?php echo P202_UPDATE_CPC_MAX; ?>" step="0.00001" placeholder="0.25" required>
						</div>
						<div class="form-text">What each of these clicks cost, down to 0.00001.</div>
						<?php echo p202_setup_feedback($errors, 'cpc'); ?>
					</div>
					<div class="p202-form-actions">
						<?php // One primary action: once the check has offered the update, that is it. ?>
						<button type="submit" class="btn <?php echo $matching !== null && $matching > 0 ? 'btn-secondary' : 'btn-primary'; ?>">Check these clicks</button>
					</div>
				</form>
			</div>
		</section>
	</div>
	<div class="col-12 col-lg-5">
		<section class="p202-panel" id="cpc-confirm">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title">Check before you update</h2>
				<?php if ($matching !== null) { ?><span class="p202-pill p202-pill--accent"><?php echo $matching . ' ' . ($matching === 1 ? 'click' : 'clicks'); ?></span><?php } ?>
			</div>
			<div class="p202-panel__body">
				<?php if ($matching === null) { ?>
					<p class="text-secondary mb-0">Choose the clicks and the CPC, then <strong>Check these clicks</strong>. This panel then says what will change and how many clicks that is, and nothing is written until you confirm.</p>
				<?php } elseif ($matching === 0) { ?>
					<div class="p202-empty">
						<i class="bi bi-inbox p202-empty__icon"></i>
						<strong class="p202-empty__title">No clicks match</strong>
						<div>Nothing to update in this selection. Widen the days, or choose every campaign.</div>
					</div>
				<?php } else { ?>
					<dl class="row small mb-3" id="cpc-summary">
						<dt class="col-5">Days</dt><dd class="col-7"><?php echo p202_setup_e(date('M j, Y', (int) $values['from_time']) . ' to ' . date('M j, Y', (int) $values['to_time'])); ?></dd>
						<dt class="col-5">Traffic source</dt><dd class="col-7"><?php echo p202_setup_e($labels['ppc_network_id'] . ' · ' . $labels['ppc_account_id']); ?></dd>
						<dt class="col-5">Campaign</dt><dd class="col-7"><?php echo p202_setup_e($labels['aff_network_id'] . ' · ' . $labels['aff_campaign_id']); ?></dd>
						<dt class="col-5">Promotion</dt><dd class="col-7"><?php echo p202_setup_e($labels['method_of_promotion']); ?></dd>
						<dt class="col-5">Landing page</dt><dd class="col-7"><?php echo p202_setup_e($labels['landing_page_id']); ?></dd>
						<dt class="col-5">Text ad</dt><dd class="col-7"><?php echo p202_setup_e($labels['text_ad_id']); ?></dd>
						<dt class="col-5">New CPC</dt><dd class="col-7"><strong><?php echo p202_setup_e(p202_update_money((string) $values['cpc'])); ?></strong></dd>
					</dl>
					<?php echo p202_flash('warn', ($matching === 1 ? 'This click will cost ' : 'Every one of these ' . $matching . ' clicks will cost ') . p202_update_money((string) $values['cpc']) . ', whatever it cost before. This cannot be undone.'); ?>
					<form method="post" action="<?php echo p202_setup_e($self); ?>" id="cpc-apply">
						<?php echo p202_setup_token_field((string) ($_SESSION['token'] ?? '')); ?>
						<?php foreach (['from', 'to', 'aff_network_id', 'aff_campaign_id', 'ppc_network_id', 'ppc_account_id', 'landing_page_id', 'text_ad_id', 'method_of_promotion', 'cpc'] as $field) { ?>
							<input type="hidden" name="<?php echo p202_setup_e($field); ?>" value="<?php echo p202_setup_e((string) $values[$field]); ?>">
						<?php } ?>
						<input type="hidden" name="expect_clicks" value="<?php echo $matching; ?>">
						<input type="hidden" name="through_click_id" value="<?php echo p202_setup_e($through); ?>">
						<div class="p202-form-actions">
							<button type="submit" class="btn btn-primary" id="update-cpc-confirm">Update <?php echo $matching . ' ' . ($matching === 1 ? 'click' : 'clicks'); ?></button>
						</div>
					</form>
				<?php } ?>
			</div>
		</section>
	</div>
</div>

<?php echo p202_setup_script_tag($base); ?>
<?php template_bottom();
