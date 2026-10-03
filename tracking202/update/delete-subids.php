<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -19) . '/202-config/connect.php');
include_once(substr(__DIR__, 0, -19) . '/202-config/class-dataengine-slim.php');
require_once __DIR__ . '/_includes/update_ui.php';

use Prosper202\Click\ClickId;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Database\Connection;

AUTH::require_user();

$success = false;

if (!$userObj->hasPermission("access_to_update_section") || !$userObj->hasPermission("delete_individual_subids")) {
	header('location: ' . get_absolute_url() . 'tracking202/');
	die();
}

$deleteError = '';
$fieldError = '';
$cleared = 0;
$unreadable = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	if (!AUTH::check_csrf_token()) {
		$deleteError = P202_UPDATE_TOKEN_REFUSED;
	} elseif (p202_update_lines(is_string($_POST['subids'] ?? null) ? $_POST['subids'] : '') === []) {
		$fieldError = 'Paste at least one subid, one per line.';
	} else {
		$userId = (int) $_SESSION['user_id'];
		$lines = preg_split('/\R/', trim((string) ($_POST['subids'] ?? ''))) ?: [];
		$clickIds = [];
		foreach ($lines as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}
			$clickId = ClickId::parse($line);
			if ($clickId !== null) {
				$clickIds[] = $clickId;
			} else {
				// Said, not dropped: a line that is not a subid clears nothing.
				$unreadable[] = $line;
			}
		}

		// Clearing a subid deletes its conversions through the ledger, so the
		// rows and the click agree; the page used to reset click_lead and
		// leave every row counting.
		$conn = new Connection($db);
		$conversionRepo = new MysqlConversionRepository($conn);
		$cleared = $conversionRepo->clearClicks($userId, $clickIds);

		$de = new DataEngine();
		foreach (array_unique($clickIds) as $clickId) {
			foreach (['UPDATE 202_clicks SET click_filtered = 0 WHERE click_id = ? AND user_id = ?',
				'UPDATE 202_clicks_spy SET click_filtered = 0 WHERE click_id = ? AND user_id = ?'] as $sql) {
				try {
					$stmt = $conn->prepareWrite($sql);
					$conn->bind($stmt, 'ii', [$clickId, $userId]);
					$conn->executeUpdate($stmt);
				} catch (\Prosper202\Database\Exceptions\QueryException $e) {
					error_log('delete-subids: clearing the filtered flag failed for click ' . $clickId . ': ' . $e->getMessage());
				}
			}
			$de->setDirtyHour((string) $clickId);
		}

		$success = true;
	}
}

$typed = $success ? '' : (is_string($_POST['subids'] ?? null) ? $_POST['subids'] : '');
$base = get_absolute_url();

//show the template
template_top('Delete Subids');

echo p202_update_header('bi-eraser', 'Delete subids', 'The reverse of Update Subids: paste the subids whose conversions should no longer count.');

if ($success) {
	echo p202_flash('ok', $cleared . ' subid(s) cleared. Your account income now reflects the subids just deleted.');
	if ($unreadable !== []) {
		echo p202_flash('warn', 'Not subids, so nothing was cleared for them: ' . p202_update_list_sentence($unreadable) . '.');
	}
} elseif ($deleteError !== '') {
	echo p202_flash('bad', $deleteError);
}
?>

<div class="row g-4">
	<div class="col-12 col-lg-7">
		<section class="p202-panel">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title">Subids to clear</h2>
				<span class="p202-panel__sub">one per line</span>
			</div>
			<div class="p202-panel__body">
				<form method="post" action="<?php echo p202_setup_e($base . 'tracking202/update/delete-subids.php'); ?>" id="delete-subids" data-p202-confirm="Delete every conversion recorded on these subids? Their clicks stay; only the conversions go, and the income they added comes off your reports.">
					<?php echo p202_setup_token_field((string) ($_SESSION['token'] ?? '')); ?>
					<div class="mb-3">
						<label class="form-label" for="subids">Subids</label>
						<textarea class="form-control font-monospace<?php echo $fieldError !== '' ? ' is-invalid' : ''; ?>" rows="8" name="subids" id="subids" placeholder="Paste your subids, one per line" required><?php echo p202_setup_e($typed); ?></textarea>
						<div class="form-text">Each click keeps its visit; its conversions are deleted and the click is no longer a lead. Subids in another account are left alone.</div>
						<?php if ($fieldError !== '') { ?><div class="invalid-feedback d-block"><?php echo p202_setup_e($fieldError); ?></div><?php } ?>
					</div>
					<div class="p202-form-actions">
						<button class="btn btn-danger" type="submit">Delete conversions</button>
					</div>
				</form>
			</div>
		</section>
	</div>
	<div class="col-12 col-lg-5">
		<section class="p202-panel">
			<div class="p202-panel__head"><h2 class="p202-panel__title">Clearing a whole campaign?</h2></div>
			<div class="p202-panel__body">
				<p class="mb-3">If you uploaded every subid of a campaign by mistake, reset the campaign in one step instead.</p>
				<a class="btn btn-secondary btn-sm" href="<?php echo p202_setup_e($base . 'tracking202/update/clear-subids.php'); ?>">Reset campaign subids</a>
			</div>
		</section>
	</div>
</div>

<?php template_bottom();
