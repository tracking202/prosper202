<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -19) . '/202-config/connect.php');
include_once(substr(__DIR__, 0, -19) . '/202-config/class-dataengine-slim.php');
require_once __DIR__ . '/_includes/update_ui.php';

use Prosper202\Click\ClickId;
use Prosper202\Conversion\Ledger\ConversionSource;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Database\Connection;
AUTH::require_user();

if (!$userObj->hasPermission("access_to_update_section")) {
	header('location: ' . get_absolute_url() . 'tracking202/');
	die();
}

// Initialize variables to prevent undefined variable warnings
$success = false;
$subidError = '';
$fieldError = '';
$marked = 0;
$ignored = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	if (!AUTH::check_csrf_token()) {
		$subidError = P202_UPDATE_TOKEN_REFUSED;
	} elseif (p202_update_lines(is_string($_POST['subids'] ?? null) ? $_POST['subids'] : '') === []) {
		// An empty list used to answer "0 subid(s) marked", which reads as
		// "none of them matched"; the person sent nothing, so say that.
		$fieldError = 'Paste at least one subid, one per line.';
	} else {
		$userId = (int) $_SESSION['user_id'];

		// One subid per line, whatever line ending the browser sent.
		$lines = preg_split('/\R/', trim((string) ($_POST['subids'] ?? ''))) ?: [];

		// Conversion rows go through the single canonical writer, which
		// locks the click, records the row and derives the click's value
		// from its rows. once_per_click: marking a click that is already a
		// lead changes nothing (the page used to re-flag it).
		$conn = new Connection($db);
		$conversionRepo = new MysqlConversionRepository($conn);
		$de = new DataEngine();

		foreach ($lines as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}
			$clickId = ClickId::parse($line);
			if ($clickId === null) {
				$ignored[] = $line;
				continue;
			}

			$result = $conversionRepo->record(
				$userId,
				[
					'click_id'   => $clickId,
					'source'     => ConversionSource::SUBID_UPLOAD->value,
					'user_agent' => 'subid-upload',
					'pixel_type' => 0,
					'once_per_click' => true,
				],
				function (int $lockedClickId) use ($conn, $userId): void {
					// A converting click is never left filtered.
					foreach (['UPDATE 202_clicks SET click_filtered = 0 WHERE click_id = ? AND user_id = ?',
						'UPDATE 202_clicks_spy SET click_filtered = 0 WHERE click_id = ? AND user_id = ?'] as $sql) {
						$stmt = $conn->prepareWrite($sql);
						$conn->bind($stmt, 'ii', [$lockedClickId, $userId]);
						$conn->executeUpdate($stmt);
					}
				}
			);

			if (!$result['clickFound']) {
				$ignored[] = $line;
				continue;
			}
			if (!$result['duplicate']) {
				$marked++;
				$de->setDirtyHour((string) $clickId);
			}
		}

		$success = true;
	}
}

// What the form shows: a refused list is kept for another try; after a
// success the box is empty for the next report.
$typed = $success ? '' : (is_string($_POST['subids'] ?? null) ? $_POST['subids'] : '');
$base = get_absolute_url();

//show the template
template_top('Update Subids');

echo p202_update_header('bi-check2-square', 'Update subids', 'Mark clicks as converted by pasting the subids from your affiliate network\'s report.');

if ($success) {
	echo p202_flash('ok', $marked . ' subid(s) marked as converted. Your account income now reflects them.');
	if ($ignored !== []) {
		echo p202_flash('warn', 'Not found in your account, so not marked: ' . p202_update_list_sentence($ignored) . '.');
	}
} elseif ($subidError !== '') {
	echo p202_flash('bad', $subidError);
}
?>

<div class="row g-4">
	<div class="col-12 col-lg-7">
		<section class="p202-panel">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title">Converted subids</h2>
				<span class="p202-panel__sub">one per line</span>
			</div>
			<div class="p202-panel__body">
				<form method="post" action="<?php echo p202_setup_e($base . 'tracking202/update/subids.php'); ?>" id="update-subids">
					<?php echo p202_setup_token_field((string) ($_SESSION['token'] ?? '')); ?>
					<div class="mb-3">
						<label class="form-label" for="subids">Subids</label>
						<textarea class="form-control font-monospace<?php echo $fieldError !== '' ? ' is-invalid' : ''; ?>" rows="8" name="subids" id="subids" placeholder="Paste your subids, one per line" required><?php echo p202_setup_e($typed); ?></textarea>
						<div class="form-text">Each click is recorded as a conversion at its campaign's payout. A click that already converted is left as it is, so sending the same list twice changes nothing.</div>
						<?php if ($fieldError !== '') { ?><div class="invalid-feedback d-block"><?php echo p202_setup_e($fieldError); ?></div><?php } ?>
					</div>
					<div class="p202-form-actions">
						<button class="btn btn-primary" type="submit">Mark as converted</button>
					</div>
				</form>
			</div>
		</section>
	</div>
	<div class="col-12 col-lg-5">
		<section class="p202-panel">
			<div class="p202-panel__head"><h2 class="p202-panel__title">Other ways to record income</h2></div>
			<div class="p202-panel__body">
				<div class="list-group">
					<a class="list-group-item list-group-item-action" href="<?php echo p202_setup_e($base . 'tracking202/update/upload.php'); ?>">
						<strong class="d-block">Upload a revenue report</strong>
						<span class="small text-secondary">When your network reports an amount per subid, record the exact amounts from its CSV.</span>
					</a>
					<a class="list-group-item list-group-item-action" href="<?php echo p202_setup_e($base . 'tracking202/setup/get_postback.php'); ?>">
						<strong class="d-block">Set up a postback</strong>
						<span class="small text-secondary">Let the network tell Prosper202 about each conversion as it happens.</span>
					</a>
				</div>
			</div>
		</section>
	</div>
</div>

<?php template_bottom();
