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

		// What to reset, checked and done by SubidBatch, which
		// POST /api/v3/conversions/subids/reset runs too: the category must be
		// the account's, and a campaign must be one of its campaigns.
		$batch = new \Prosper202\Update\SubidBatch($conn);
		$scope = $batch->resetScope($userId, $rawNetwork, $rawCampaign);
		$errors = $scope['errors'];
		$networkId = $scope['network_id'];
		$campaignId = $scope['campaign_id'];

		if ($errors === []) {
			set_time_limit(0);
			$cleared = $batch->reset($userId, $networkId, $campaignId);
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

template_top('Clear Subids');

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
