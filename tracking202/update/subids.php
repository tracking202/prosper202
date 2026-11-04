<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -19) . '/202-config/connect.php');
require_once __DIR__ . '/_includes/update_ui.php';

use Prosper202\Database\Connection;
use Prosper202\Report\AccountZone;
use Prosper202\Update\SubidBatch;
use Prosper202\Update\SubidLinesRefused;
AUTH::require_user();

if (!$userObj->hasPermission("access_to_update_section")) {
	header('location: ' . get_absolute_url() . 'tracking202/');
	die();
}

// Initialize variables to prevent undefined variable warnings
$success = false;
$subidError = '';
$fieldError = '';
$payoutError = '';
$zoneError = '';
$refusedLines = [];
$marked = 0;
$ignored = [];
$alreadyMarked = [];
$deletedEarlier = [];
$repeated = [];

$conn = new Connection($db);
$accountZone = AccountZone::read($conn, (int) $_SESSION['user_id']);
// A field posted as anything but text is refused below, not read as empty.
$postedPayout = is_string($_POST['subid_payout'] ?? null) ? trim($_POST['subid_payout']) : '';
$postedZone = is_string($_POST['subid_timezone'] ?? $accountZone) ? (string) ($_POST['subid_timezone'] ?? $accountZone) : '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	if (!AUTH::check_csrf_token()) {
		$subidError = P202_UPDATE_TOKEN_REFUSED;
	} elseif (p202_update_lines(is_string($_POST['subids'] ?? null) ? $_POST['subids'] : '') === []) {
		// An empty list used to answer "0 subid(s) marked", which reads as
		// "none of them matched"; the person sent nothing, so say that.
		$fieldError = 'Paste at least one subid, one per line.';
	} elseif (!is_string($_POST['subid_payout'] ?? '')) {
		$payoutError = 'Enter the payout as a number, such as 12.50.';
	} elseif ($postedPayout !== '' && ($payoutError = (string) SubidBatch::payoutError($postedPayout)) !== '') {
		$payoutError = ucfirst($payoutError) . '.';
	} elseif (!AccountZone::isZone($postedZone)) {
		$zoneError = 'Choose a time zone from the list.';
	} else {
		$userId = (int) $_SESSION['user_id'];

		// Each line is recorded through the single canonical writer
		// (SubidBatch::mark(), which POST /api/v3/conversions/subids runs
		// too). A line that cannot be read stops the whole list unwritten.
		try {
			$result = (new SubidBatch($conn))->mark(
				$userId,
				p202_update_lines((string) ($_POST['subids'] ?? '')),
				$postedPayout === '' ? null : $postedPayout,
				new DateTimeZone($postedZone)
			);
			$marked = $result['marked'];
			foreach ($result['lines'] as $line) {
				if ($line['status'] === SubidBatch::NOT_A_SUBID || $line['status'] === SubidBatch::NOT_FOUND) {
					$ignored[] = $line['subid'];
				} elseif ($line['status'] === SubidBatch::ALREADY_CONVERTED && ($line['deleted'] ?? false)) {
					$deletedEarlier[] = $line['subid'] . (isset($line['transaction_id']) ? ' (' . $line['transaction_id'] . ')' : '');
				} elseif ($line['status'] === SubidBatch::ALREADY_CONVERTED) {
					$alreadyMarked[] = $line['subid'] . (isset($line['transaction_id']) ? ' (' . $line['transaction_id'] . ')' : '');
				} elseif ($line['status'] === SubidBatch::DUPLICATE_IN_LIST) {
					$repeated[] = 'line ' . $line['line'];
				}
			}
			$success = true;
		} catch (SubidLinesRefused $e) {
			$refusedLines = $e->lines;
		}
	}
}

// What the form shows: a refused list is kept for another try; after a
// success the box is empty for the next report.
$typed = $success ? '' : (is_string($_POST['subids'] ?? null) ? $_POST['subids'] : '');
$base = get_absolute_url();

// The account's own zone is offered, selected, even when PHP lists it under
// another name (CLAUDE.md #4, the select that posts its first option).
$zoneOptions = DateTimeZone::listIdentifiers();
if (!in_array($accountZone, $zoneOptions, true)) {
	array_unshift($zoneOptions, $accountZone);
}

//show the template
template_top('Update Subids');

echo p202_update_header('bi-check2-square', 'Update subids', 'Mark clicks as converted by pasting the subids from your affiliate network\'s report.');

if ($success) {
	echo p202_flash('ok', $marked . ' subid(s) marked as converted. Your account income now reflects them.');
	if ($ignored !== []) {
		echo p202_flash('warn', 'Not found in your account, so not marked: ' . p202_update_list_sentence($ignored) . '.');
	}
	if ($alreadyMarked !== []) {
		echo p202_flash('info', 'Already recorded, so left as they were: ' . p202_update_list_sentence($alreadyMarked) . '.');
	}
	if ($deletedEarlier !== []) {
		echo p202_flash('warn', 'Recorded before and deleted since, so not recorded again: ' . p202_update_list_sentence($deletedEarlier)
			. '. A deleted sale keeps its transaction id; give the sale a new transaction id to record it.');
	}
	if ($repeated !== []) {
		echo p202_flash('info', 'Repeats of an earlier line, recorded once: ' . p202_update_list_sentence($repeated) . '.');
	}
} elseif ($subidError !== '') {
	echo p202_flash('bad', $subidError);
} elseif ($refusedLines !== []) {
	echo p202_flash('bad', 'Nothing was recorded: ' . count($refusedLines) . ' line(s) could not be read. Fix them and send the list again.');
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
						<textarea class="form-control font-monospace<?php echo $fieldError !== '' || $refusedLines !== [] ? ' is-invalid' : ''; ?>" rows="8" name="subids" id="subids" placeholder="SUBID, PAYOUT, TRANSACTION ID, TIMESTAMP" required><?php echo p202_setup_e($typed); ?></textarea>
						<div class="form-text">One per line: <code>SUBID, PAYOUT, TRANSACTION ID, TIMESTAMP</code>. Everything after the subid is optional; leave a field empty to skip it (<code>123,,order-9</code>). Times read like <code>11/03/2026 2:30:00 PM</code> or <code>2026-11-03 14:30:00</code>.</div>
						<div class="form-text">A line without a transaction id marks its click once: a click that already converted is left as it is. A line with one records that sale once, so a click can carry several sales and sending the same list twice changes nothing.</div>
						<?php if ($fieldError !== '') { ?><div class="invalid-feedback d-block"><?php echo p202_setup_e($fieldError); ?></div><?php } ?>
						<?php if ($refusedLines !== []) { ?>
							<div class="invalid-feedback d-block">
								<ul class="mb-0">
									<?php foreach (array_slice($refusedLines, 0, 50) as $refused) { ?>
										<li>Line <?php echo (int) $refused['line']; ?> (<?php echo p202_setup_e($refused['subid']); ?>): <?php echo p202_setup_e($refused['error']); ?>.</li>
									<?php } ?>
									<?php if (count($refusedLines) > 50) { ?><li>and <?php echo count($refusedLines) - 50; ?> more.</li><?php } ?>
								</ul>
							</div>
						<?php } ?>
					</div>
					<div class="row g-3 mb-3">
						<div class="col-12 col-md-5">
							<label class="form-label" for="subid_payout">Payout</label>
							<input type="text" inputmode="decimal" class="form-control<?php echo $payoutError !== '' ? ' is-invalid' : ''; ?>" id="subid_payout" name="subid_payout" value="<?php echo p202_setup_e($postedPayout); ?>" placeholder="campaign payout">
							<div class="form-text">For lines that give no payout. Empty: each campaign's payout.</div>
							<?php if ($payoutError !== '') { ?><div class="invalid-feedback d-block"><?php echo p202_setup_e($payoutError); ?></div><?php } ?>
						</div>
						<div class="col-12 col-md-7">
							<label class="form-label" for="subid_timezone">Time zone of the times</label>
							<select class="form-select<?php echo $zoneError !== '' ? ' is-invalid' : ''; ?>" id="subid_timezone" name="subid_timezone">
								<?php foreach ($zoneOptions as $zone) { ?>
									<option value="<?php echo p202_setup_e($zone); ?>"<?php echo $zone === $postedZone ? ' selected' : ''; ?>><?php echo p202_setup_e($zone . ($zone === $accountZone ? ' (your account)' : '')); ?></option>
								<?php } ?>
							</select>
							<div class="form-text">A line without a time is recorded now.</div>
							<?php if ($zoneError !== '') { ?><div class="invalid-feedback d-block"><?php echo p202_setup_e($zoneError); ?></div><?php } ?>
						</div>
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
