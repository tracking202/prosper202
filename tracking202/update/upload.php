<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-19) . '/202-config/connect.php');
include_once(substr(__DIR__, 0,-19) . '/202-config/class-dataengine-slim.php');
require_once __DIR__ . '/_includes/update_ui.php';

AUTH::require_user();

if (!$userObj->hasPermission("access_to_update_section")) {
	header('location: '.get_absolute_url().'tracking202/');
	die();
}

/*
 * Upload Revenue Reports, in three steps: upload a CSV (a token-carrying
 * POST that keeps the file under a random name), choose its subid and
 * commission columns (case=1), and apply it (case=2, a token-carrying POST;
 * RevenueUploadImporter records every line as a ledger row of this upload's
 * batch and lists the lines it skipped). The request handling is PR 1's; U5
 * moved the page to the v2 shell and made each refusal say why.
 */

$upload_dir = __DIR__ . '/reports/';
$self = get_absolute_url() . 'tracking202/update/upload.php';

/** The page, up to its content. */
function p202_upload_top(): void
{
	template_top('Upload Revenue Reports', ['ui' => 'v2']);
	echo p202_update_header('bi-file-earmark-arrow-up', 'Upload revenue reports', 'Record the exact amount each subid earned, from your affiliate network\'s CSV report. Useful when you are paid a percentage, or earn more than once per click.');
}

/** A refusal with its sentence and the one step that recovers. */
function p202_upload_stop(string $sentence, string $actionLabel, string $actionHref): never
{
	p202_upload_top();
	echo p202_flash('bad', $sentence);
	echo '<p><a class="btn btn-secondary" href="' . p202_setup_e($actionHref) . '">' . p202_setup_e($actionLabel) . '</a></p>';
	template_bottom();
	die();
}

$case = isset($_GET['case']) ? (int)$_GET['case'] : 0;

switch ($case) {

	case 1:

		#else it worked ok, save the csv to file, and then ask them what fields are what
		// scope filename to a basename inside the reports dir
		$name = basename((string)($_GET['file'] ?? ''));
		if ($name === '' || $name !== ($_GET['file'] ?? '')) {
			p202_upload_stop('This file does not exist that you are trying to import, or you have already successfully uploaded it.', 'Upload a report', $self);
		}
		$file = $upload_dir . $name;
		$real_file = realpath($file);
		$real_dir  = realpath($upload_dir);
		if (!file_exists($file) || $real_file === false || $real_dir === false || !str_starts_with($real_file, $real_dir . DIRECTORY_SEPARATOR)) {
			p202_upload_stop('This file does not exist that you are trying to import, or you have already successfully uploaded it.', 'Upload a report', $self);
		}

		$handle = fopen($real_file, 'rb');
		if ($handle === false) {
			p202_upload_stop('The uploaded report could not be read. Nothing was changed; please upload it again.', 'Upload a report', $self);
		}
		$row = fgetcsv($handle, 100000, ",", escape: '\\');
		// The first data line, so each column shows what it holds.
		$sample = $row === false ? false : fgetcsv($handle, 100000, ",", escape: '\\');
		fclose($handle);
		if (!is_array($row) || $row === [null]) {
			p202_upload_stop('The report has no header line to choose the columns from. Nothing was changed.', 'Upload another report', $self);
		}
		$sample = is_array($sample) ? $sample : [];
		$guess = p202_update_guess_columns(array_map('strval', $row));

		p202_upload_top(); ?>
		<section class="p202-panel">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title">Which columns are which?</h2>
				<span class="p202-panel__sub">step 2 of 3 · nothing is recorded until you apply</span>
			</div>
			<div class="p202-panel__body">
				<form method="post" action="<?php echo p202_setup_e($self . '?case=2'); ?>" id="upload-columns">
					<?php echo p202_setup_token_field((string) ($_SESSION['token'] ?? '')); ?>
					<input type="hidden" name="file" value="<?php echo p202_setup_e($name); ?>">
					<?php if ($guess['subid'] !== null || $guess['amount'] !== null) { ?>
						<div class="p202-decided mb-3"><i class="bi bi-check2-circle"></i> Chosen from the column names; change them below if they are wrong.</div>
					<?php } ?>
					<div class="p202-table-wrap">
						<table class="table table-hover p202-table" id="stats-table">
							<thead><tr><th>Column</th><th>First line</th><th class="text-center">Subid</th><th class="text-center">Commission</th></tr></thead>
							<tbody>
							<?php foreach ($row as $x => $column) {
								$x = (int) $x; ?>
								<tr>
									<td><strong><?php echo p202_setup_e((string) $column); ?></strong></td>
									<td class="text-secondary"><?php echo p202_setup_e((string) ($sample[$x] ?? '')); ?></td>
									<td class="text-center"><input class="form-check-input" type="radio" name="click_id" value="<?php echo $x; ?>" aria-label="<?php echo p202_setup_e((string) $column); ?> holds the subid"<?php echo $guess['subid'] === $x ? ' checked' : ''; ?> required></td>
									<td class="text-center"><input class="form-check-input" type="radio" name="click_payout" value="<?php echo $x; ?>" aria-label="<?php echo p202_setup_e((string) $column); ?> holds the commission"<?php echo $guess['amount'] === $x ? ' checked' : ''; ?> required></td>
								</tr>
							<?php } ?>
							</tbody>
						</table>
					</div>
					<div class="form-text">Each line's commission is recorded on its subid's click. A subid on several lines gets their sum, and it replaces what earlier uploads set for that click.</div>
					<div class="p202-form-actions">
						<a class="btn btn-link" href="<?php echo p202_setup_e($self); ?>">Upload a different file</a>
						<button class="btn btn-primary" type="submit">Apply the report</button>
					</div>
				</form>
			</div>
		</section>
		<?php
		template_bottom();
		break;

	case 2:

		// Applying a report writes conversions, so it is a POST that carries
		// the session token; it used to run from a GET link.
		$name = basename((string)($_POST['file'] ?? ''));
		if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !AUTH::check_csrf_token()) {
			p202_upload_stop('Your session expired before the report was applied. Nothing was changed; upload it again.', 'Upload it again', $self);
		}

		// scope filename to a basename inside the reports dir
		if ($name === '' || $name !== ($_POST['file'] ?? '')) {
			p202_upload_stop('This file does not exist that you are trying to import, or you have already successfully uploaded it.', 'Upload a report', $self);
		}

		// The two radio buttons carry column indexes (0, 1, ...).
		$subidColumn = isset($_POST['click_id']) && is_string($_POST['click_id']) && ctype_digit($_POST['click_id']) ? (int) $_POST['click_id'] : null;
		$amountColumn = isset($_POST['click_payout']) && is_string($_POST['click_payout']) && ctype_digit($_POST['click_payout']) ? (int) $_POST['click_payout'] : null;
		if ($subidColumn === null || $amountColumn === null) {
			p202_upload_stop('You forgot to check the subid and the commission column. Nothing was changed.', 'Choose the columns', $self . '?case=1&file=' . rawurlencode($name));
		}

		$file = $upload_dir . $name;
		$real_file = realpath($file);
		$real_dir  = realpath($upload_dir);
		if (!file_exists($file) || $real_file === false || $real_dir === false || !str_starts_with($real_file, $real_dir . DIRECTORY_SEPARATOR)) {
			p202_upload_stop('This file does not exist that you are trying to import, or you have already successfully uploaded it.', 'Upload a report', $self);
		}

		$handle = fopen($real_file, 'rb');
		if ($handle === false) {
			p202_upload_stop('The uploaded report could not be read. Nothing was changed; please upload it again.', 'Upload a report', $self);
		}

		// Every line becomes a ledger row of this upload's batch; the newest
		// batch's lines for a click are summed and replace what earlier
		// uploads and conversions set (RevenueUploadImporter).
		$conn = new \Prosper202\Database\Connection($db);
		$importer = new \Prosper202\Conversion\RevenueUploadImporter($conn, new \Prosper202\Conversion\MysqlConversionRepository($conn));
		try {
			$import = $importer->import((int) $_SESSION['user_id'], $name, $handle, $subidColumn, $amountColumn);
		} catch (\Throwable $importError) {
			fclose($handle);
			error_log('upload: revenue import failed: ' . $importError->getMessage());
			p202_upload_stop('The report could not be applied: ' . $importError->getMessage() . '. Lines before the failure were recorded; uploading the file again records the rest without repeating them.', 'Upload it again', $self);
		}
		fclose($handle);

		$de = new DataEngine();
		foreach (array_keys($import['totals']) as $convertedClickId) {
			$de->setDirtyHour((string) $convertedClickId);
		}

		#update is now complete, delete the .csv
		// only remove a file confirmed inside the reports dir
		if (str_starts_with($real_file, $real_dir . DIRECTORY_SEPARATOR)) {
			unlink($real_file);
		}

		$skippedLines = array_values(array_filter($import['lines'], static fn (array $l): bool => $l['status'] === 'skipped'));
		$totalRows = [];
		foreach ($import['totals'] as $key => $total) {
			$totalRows[] = ['subid' => (string) (int) $key, 'total' => ['text' => p202_update_money((string) $total), 'sort' => (float) $total]];
		}
		$skippedRows = [];
		foreach ($skippedLines as $l) {
			$skippedRows[] = ['line' => ['text' => (string) (int) $l['line'], 'sort' => (int) $l['line']], 'subid' => (string) $l['subid'], 'amount' => (string) $l['amount'], 'reason' => (string) $l['reason']];
		}

		p202_upload_top();
		echo p202_flash('ok', 'Your report has been uploaded: '.(int) $import['recorded'].' line(s) recorded'.($import['skipped'] > 0 ? ', '.(int) $import['skipped'].' skipped (listed below)' : '').'.');
		foreach ($import['lines'] as $l) {
			if ($l['status'] === 'header') {
				// Said out loud: a file with no header row whose first
				// subid is malformed would otherwise lose that line.
				echo p202_flash('info', sprintf('Line %d was read as the header row (subid column: “%s”) and not recorded.', (int) $l['line'], (string) $l['subid']));
			}
		}
		?>
		<div class="row g-4">
			<div class="col-12<?php echo $skippedRows !== [] ? ' col-lg-6' : ''; ?>">
				<section class="p202-panel">
					<div class="p202-panel__head">
						<h2 class="p202-panel__title">Income recorded</h2>
						<span class="p202-pill p202-pill--good"><?php echo count($totalRows) . ' ' . (count($totalRows) === 1 ? 'subid' : 'subids'); ?></span>
					</div>
					<div class="p202-panel__body">
						<p class="form-text mt-0">Each subid's income is now the sum of its lines in this report.</p>
						<?php echo p202_data_table(
							[['key' => 'subid', 'label' => 'Subid'], ['key' => 'total', 'label' => 'Commission', 'num' => true]],
							$totalRows,
							['id' => 'upload-totals', 'caption' => 'Income per subid from this report', 'sortable' => true,
								'empty' => ['icon' => 'bi-inbox', 'title' => 'No line was recorded', 'body' => 'Every line was skipped; the reasons are listed.', 'action' => 'Upload another report', 'href' => $self]]
						); ?>
					</div>
				</section>
			</div>
			<?php if ($skippedRows !== []) { ?>
			<div class="col-12 col-lg-6">
				<section class="p202-panel">
					<div class="p202-panel__head">
						<h2 class="p202-panel__title">Lines not recorded</h2>
						<span class="p202-pill p202-pill--warn"><?php echo count($skippedRows) . ' skipped'; ?></span>
					</div>
					<div class="p202-panel__body">
						<?php echo p202_data_table(
							[['key' => 'line', 'label' => 'Line', 'num' => true], ['key' => 'subid', 'label' => 'Subid'], ['key' => 'amount', 'label' => 'Commission'], ['key' => 'reason', 'label' => 'Why']],
							$skippedRows,
							['id' => 'upload-skipped', 'caption' => 'Lines of the report that were not recorded, and why']
						); ?>
					</div>
				</section>
			</div>
			<?php } ?>
		</div>
		<p class="mt-4"><a class="btn btn-secondary" href="<?php echo p202_setup_e($self); ?>">Upload another report</a></p>
		<?php
		template_bottom();

		break;

	default:

		$uploadError = '';
		$fieldError = '';
		if ($_SERVER['REQUEST_METHOD'] == 'POST') {

			// Initialize error variable
			$error = !AUTH::check_csrf_token();
			if ($error) {
				$uploadError = P202_UPDATE_TOKEN_REFUSED;
			}

			// Check if file was uploaded properly
			if (!$error && (!isset($_FILES['csv']) || !isset($_FILES['csv']['tmp_name']) || empty($_FILES['csv']['tmp_name']))) {
				$error = true;
				$fieldError = 'Choose the CSV report to upload. If you did, it may be larger than this server accepts (upload_max_filesize).';
			}

			if (!$error) {
				//get file extension, checks to see if the image file, if not, do not allow to upload the file
				$pext = getFileExtension($_FILES['csv']['name']);
				$pext = strtolower((string) $pext);
				if (($pext != "txt") and ($pext !="csv")) {
					$error = true;
					$fieldError = 'Upload the report as a .csv or .txt file; export it from your network as CSV.';
				}
			}

			$handle = false;
			if (!$error) {
				//open the tmp file, that was uploaded, the csv
				$tmp_name = $_FILES['csv']['tmp_name'];
				$handle = fopen($tmp_name, "rb");
				if ($handle === false) {
					$error = true;
					$uploadError = 'The uploaded report could not be read. Nothing was changed; please upload it again.';
				}
			}

			if (!$error && $handle !== false) {
				//this counter, will help us determine the first row of the array
				$row = @fgetcsv($handle, 100000, ",", escape: '\\');

				#if there was no row detected, an error occured on this uploaded
				if (!$row) {
					$error = true;
					$uploadError = 'The file is empty. Nothing was changed.';
				}

				// Close the handle since we're done with the initial check
				fclose($handle);
			}

			if (!$error) {

				#now write the csv to the reports folder — all of it. This
				#used to copy the first 100,000 bytes, so a larger report
				#lost its later lines without a word.
				$file = bin2hex(random_bytes(8)) . '.csv';
				if (move_uploaded_file($tmp_name, $upload_dir . $file)) {
					header('location: '.get_absolute_url().'tracking202/update/upload.php?case=1&file='.$file); die();
				}
				$error = true;
				$uploadError = 'The report could not be kept on the server for the next step. Nothing was changed; please upload it again.';
			}
		}

		p202_upload_top();

		//check to see if the directory is writable
		if ( !is_writable(  $upload_dir )) {
			echo p202_flash('bad', "Sorry, I can't write to the directory: " . $upload_dir . ". In order to upload revenue reports we need to be able to write to this directory; you'll need to modify its permissions.");
			template_bottom(); die();
		}
		if ($uploadError !== '') {
			echo p202_flash('bad', $uploadError);
		} elseif ($fieldError !== '') {
			echo p202_flash('bad', 'Nothing was uploaded. The sentence under the field says why.');
		} ?>

		<div class="row g-4">
			<div class="col-12 col-lg-7">
				<section class="p202-panel">
					<div class="p202-panel__head">
						<h2 class="p202-panel__title">Upload a report</h2>
						<span class="p202-panel__sub">step 1 of 3</span>
					</div>
					<div class="p202-panel__body">
						<form method="post" enctype="multipart/form-data" action="<?php echo p202_setup_e($self); ?>" id="upload-report">
							<?php echo p202_setup_token_field((string) ($_SESSION['token'] ?? '')); ?>
							<div class="mb-3">
								<label class="form-label" for="csv">Commission report</label>
								<input class="form-control<?php echo $fieldError !== '' ? ' is-invalid' : ''; ?>" type="file" name="csv" id="csv" accept=".csv,.txt,text/csv,text/plain" required>
								<div class="form-text">A CSV with a header line, one line per sale. You choose the subid and commission columns next.</div>
								<?php if ($fieldError !== '') { ?><div class="invalid-feedback d-block"><?php echo p202_setup_e($fieldError); ?></div><?php } ?>
							</div>
							<div class="p202-form-actions">
								<button class="btn btn-primary" type="submit">Upload report</button>
							</div>
						</form>
					</div>
				</section>
			</div>
			<div class="col-12 col-lg-5">
				<section class="p202-panel">
					<div class="p202-panel__head"><h2 class="p202-panel__title">How a report is applied</h2></div>
					<div class="p202-panel__body">
						<ul class="small mb-0">
							<li>Every line is recorded as a conversion on its subid's click, and the lines that cannot be are listed with the reason.</li>
							<li>A subid on several lines earns their sum.</li>
							<li>The newest report replaces what earlier reports set for the same click, so uploading a corrected report fixes the old one.</li>
						</ul>
					</div>
				</section>
			</div>
		</div>
		<?php template_bottom();
		break;
}
