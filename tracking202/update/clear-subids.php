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
 * Reset Campaign Subids: clear every conversion in one category, or in one
 * campaign of it, so a report uploaded by mistake can be uploaded again.
 *
 * Until U5 this form posted through jQuery to tracking202/ajax/clear_subids.php
 * and put the fragment it answered beside the form; the page now takes its own
 * POST and says what it did in a flash. The work is the one PR 1 made it:
 * each converted click is cleared through the ledger
 * (MysqlConversionRepository::clearClicks), so its rows are soft-deleted and
 * its value recomputed from what is left.
 */

$userId = (int) $_SESSION['user_id'];
$conn = new \Prosper202\Database\Connection($db);
$errors = [];
$notice = '';
$networkId = 0;
$campaignId = 0;
$cleared = null;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	if (!AUTH::check_csrf_token()) {
		$notice = P202_UPDATE_TOKEN_REFUSED;
	} else {
		$rawNetwork = is_string($_POST['aff_network_id'] ?? null) ? trim($_POST['aff_network_id']) : '';
		$rawCampaign = is_string($_POST['aff_campaign_id'] ?? null) ? trim($_POST['aff_campaign_id']) : '';

		if ($rawNetwork === '' || $rawNetwork === '0') {
			$errors['aff_network_id'] = 'You have to at least select an affiliate network to clear out.';
		} elseif (!ctype_digit($rawNetwork) || p202_update_owned_row($conn, '202_aff_networks', 'aff_network_id', (int) $rawNetwork, $userId) === null) {
			$errors['aff_network_id'] = 'Choose one of your categories from the list.';
		} else {
			$networkId = (int) $rawNetwork;
		}

		if ($rawCampaign !== '' && $rawCampaign !== '0') {
			$campaign = ctype_digit($rawCampaign) ? p202_update_owned_row($conn, '202_aff_campaigns', 'aff_campaign_id', (int) $rawCampaign, $userId) : null;
			if ($campaign === null) {
				$errors['aff_campaign_id'] = 'Choose one of your campaigns from the list.';
			} elseif ($networkId > 0 && (int) $campaign['aff_network_id'] !== $networkId) {
				// Without the page script the campaign list is not narrowed to
				// the category; a campaign from another one is refused rather
				// than cleared under a category it is not in.
				$errors['aff_campaign_id'] = 'That campaign is not in the category you chose.';
			} else {
				$campaignId = (int) $campaign['aff_campaign_id'];
			}
		}

		if ($errors === []) {
			set_time_limit(0);
			if ($campaignId > 0) {
				$select = "SELECT c.click_id, c.click_time FROM 202_clicks AS c
					WHERE c.user_id = ? AND c.aff_campaign_id = ?
					AND (c.click_lead = 1 OR EXISTS (SELECT 1 FROM 202_conversion_logs AS cl WHERE cl.click_id = c.click_id AND cl.deleted = 0))";
				$scopeId = $campaignId;
			} else {
				$select = "SELECT c.click_id, c.click_time FROM 202_clicks AS c
					INNER JOIN 202_aff_campaigns AS ac ON ac.aff_campaign_id = c.aff_campaign_id
					WHERE c.user_id = ? AND ac.aff_network_id = ?
					AND (c.click_lead = 1 OR EXISTS (SELECT 1 FROM 202_conversion_logs AS cl WHERE cl.click_id = c.click_id AND cl.deleted = 0))";
				$scopeId = $networkId;
			}
			$stmt = $conn->prepareWrite($select);
			$conn->bind($stmt, 'ii', [$userId, $scopeId]);
			$rows = $conn->fetchAll($stmt);
			$clickIds = array_map(static fn (array $row): int => (int) $row['click_id'], $rows);

			$repo = new \Prosper202\Conversion\MysqlConversionRepository($conn);
			$cleared = 0;
			foreach (array_chunk($clickIds, 500) as $chunk) {
				$cleared += $repo->clearClicks($userId, $chunk);
			}

			if ($cleared > 0) {
				// The data engine rebuilds the hours these clicks fall in. The
				// window starts at the earliest cleared click; the page used to
				// take whichever click the database returned first, which could
				// leave earlier hours showing the income just cleared.
				$times = array_map(static fn (array $row): int => (int) $row['click_time'], $rows);
				$dirty = $conn->prepareWrite('INSERT IGNORE INTO 202_dirty_hours SET ppc_account_id = 0, aff_campaign_id = ?, aff_network_id = ?, landing_page_id = 0, user_id = ?, click_time_from = ?, click_time_to = ?');
				$conn->bind($dirty, 'iiiii', [$campaignId, $networkId, $userId, min($times), time()]);
				$conn->executeUpdate($dirty);
			}
		}
	}
}

$base = get_absolute_url();
$lists = p202_update_campaign_lists($db, $userId);
$chosenNetwork = is_string($_POST['aff_network_id'] ?? null) ? $_POST['aff_network_id'] : '';
$chosenCampaign = is_string($_POST['aff_campaign_id'] ?? null) ? $_POST['aff_campaign_id'] : '';
if ($cleared !== null) {
	// After a reset the form starts over rather than offering the same
	// click again.
	$chosenNetwork = '';
	$chosenCampaign = '';
}
if ($chosenNetwork === '') {
	// One category is the only answer, so it is chosen (UI standard, rule 3).
	$chosenNetwork = p202_setup_only_option($lists['networks']) ?? '';
}

template_top('Clear Subids', ['ui' => 'v2']);

echo p202_update_header('bi-arrow-counterclockwise', 'Reset campaign subids', 'Clear every conversion in a category or one of its campaigns, then upload the right subids again.');

if ($cleared !== null) {
	echo p202_flash('ok', 'You have reset ' . $cleared . ' subids. You can now re-upload your subids.');
}
if ($notice !== '') {
	echo p202_flash('bad', $notice);
}
if ($errors !== []) {
	echo p202_setup_error_flashes($errors, ['aff_network_id', 'aff_campaign_id']);
}
?>

<div class="row g-4">
	<div class="col-12 col-lg-7">
		<section class="p202-panel">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title">What to reset</h2>
				<span class="p202-panel__sub">conversions only; clicks stay</span>
			</div>
			<div class="p202-panel__body">
				<?php if ($lists['networks'] === []) { ?>
					<div class="p202-empty">
						<i class="bi bi-grid p202-empty__icon"></i>
						<strong class="p202-empty__title">No categories yet</strong>
						<div>There is nothing to reset until a campaign has recorded conversions.</div>
						<div class="p202-empty__action"><a class="btn btn-secondary btn-sm" href="<?php echo p202_setup_e($base . 'tracking202/setup/aff_networks.php'); ?>">Add a category</a></div>
					</div>
				<?php } else { ?>
				<form method="post" action="<?php echo p202_setup_e($base . 'tracking202/update/clear-subids.php'); ?>" id="clear_subids_form" data-p202-confirm="Clear every conversion in this selection? The clicks stay; the income their conversions added comes off your reports until you upload the subids again.">
					<?php echo p202_setup_token_field((string) ($_SESSION['token'] ?? '')); ?>
					<div class="mb-3">
						<label class="form-label" for="aff_network_id">Category</label>
						<select class="form-select<?php echo p202_setup_invalid($errors, 'aff_network_id'); ?>" id="aff_network_id" name="aff_network_id" required>
							<?php echo p202_setup_options($lists['networks'], $chosenNetwork, 'Choose a category'); ?>
						</select>
						<?php echo p202_setup_feedback($errors, 'aff_network_id'); ?>
					</div>
					<div class="mb-3">
						<label class="form-label" for="aff_campaign_id">Campaign</label>
						<select class="form-select<?php echo p202_setup_invalid($errors, 'aff_campaign_id'); ?>" id="aff_campaign_id" name="aff_campaign_id" data-p202-filter-by="#aff_network_id" data-p202-filter-key="network">
							<?php echo p202_setup_options($lists['campaigns'], $chosenCampaign, 'Every campaign in the category', '0'); ?>
						</select>
						<div class="form-text">Leave it on every campaign to reset the whole category.</div>
						<?php echo p202_setup_feedback($errors, 'aff_campaign_id'); ?>
					</div>
					<div class="p202-form-actions">
						<button type="submit" class="btn btn-danger" id="clear-subids">Reset subids</button>
					</div>
				</form>
				<?php } ?>
			</div>
		</section>
	</div>
	<div class="col-12 col-lg-5">
		<section class="p202-panel">
			<div class="p202-panel__head"><h2 class="p202-panel__title">Only a few subids?</h2></div>
			<div class="p202-panel__body">
				<p class="mb-3">To clear particular conversions and keep the rest of the campaign's, paste their subids instead.</p>
				<?php if ($userObj->hasPermission('delete_individual_subids')) { ?>
					<a class="btn btn-secondary btn-sm" href="<?php echo p202_setup_e($base . 'tracking202/update/delete-subids.php'); ?>">Delete individual subids</a>
				<?php } else { ?>
					<p class="small text-secondary mb-0">Deleting individual subids needs a permission your account does not have.</p>
				<?php } ?>
			</div>
		</section>
	</div>
</div>

<?php echo p202_setup_script_tag($base); ?>
<?php template_bottom();
