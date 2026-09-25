<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-19) . '/202-config/connect.php'); 
include_once(substr(__DIR__, 0,-19) . '/202-config/class-dataengine-slim.php');

AUTH::require_user();

if (!$userObj->hasPermission("access_to_update_section")) {
	header('location: '.get_absolute_url().'tracking202/');
	die();
}

function about_revenue_upload() { 

	echo '<div class="row">
			<div class="col-xs-12">
				<h6>Upload Revenue Report</h6>
				<small>This area allows you to upload the revenue reports from your affiliate networks.  You can now upload the exact sale amount that each subid generated, unlike before were T202 asummed the flat-payout on each item, you can now upload the exact revenue that was generated per subid.  This is specifcally helpfull if you are receiving a commission of a percentage based or if you constantly get more than one lead for each subid.</small>
			</div>
		</div>

		<div class="row form_seperator" style="margin-bottom:15px; margin-top:15px;">
			<div class="col-xs-12"></div>
		</div>
	';
}


$upload_dir = __DIR__ . '/reports/';

$case = isset($_GET['case']) ? (int)$_GET['case'] : 0;

switch ($case) {

	case 1:

		#else it worked ok, save the csv to file, and then ask them what fields are what
		// scope filename to a basename inside the reports dir
		$name = basename((string)($_GET['file'] ?? ''));
		if ($name === '' || $name !== ($_GET['file'] ?? '')) {
			template_top('Upload Revenue Reports');
			about_revenue_upload();
			echo '<div class="error"><small><span class="fui-alert"></span>This file does not exist that you are trying to import<br/>or you have already successfully uploaded it.</small></div>';
			template_bottom();
			die();
		}
		$file = $upload_dir . $name;
		$real_file = realpath($file);
		$real_dir  = realpath($upload_dir);
		if (!file_exists($file) || $real_file === false || $real_dir === false || !str_starts_with($real_file, $real_dir . DIRECTORY_SEPARATOR)) {
			template_top('Upload Revenue Reports'); 
			about_revenue_upload();
			echo '<div class="error"><small><span class="fui-alert"></span>This file does not exist that you are trying to import<br/>or you have already successfully uploaded it.</small></div>';
			template_bottom();
			die();
		}
		
		
		template_top('Upload Revenue Reports'); 
		about_revenue_upload();
			echo '<div class="row">
					<div class="col-xs-12">
						<form enctype="application/x-www-form-urlencoded" action="'.get_absolute_url().'tracking202/update/upload.php?case=2" method="post">';
					echo '<input type="hidden" name="token" value="'.htmlspecialchars((string) ($_SESSION['token'] ?? ''), ENT_QUOTES).'"/>';
					echo '<input type="hidden" name="file" value="'.htmlspecialchars($name, ENT_QUOTES).'"/>';
				echo '<table class="table table-bordered" id="stats-table">';
				echo '<tr>';
					echo '<th>Column Name</th>';
					echo '<th>Subid Column</th>';
					echo '<th>Commission Column</th>';
				echo '<tr/>';
			 
			
		$handle = fopen($file, 'rb'); 	
		$row = @fgetcsv($handle, 100000, ",", escape: '\\');
		for ($x = 0; $x < count($row); $x++) { 
			$html = array_map(htmlentities(...), $row);
			echo '<tr>';	
				echo '<td>'.$html[$x].'</td>';
				echo '<td><label class="radio" style="display: inline;"><input type="radio" data-toggle="radio" name="click_id" value="'.$x.'"/></label</td>';
				echo '<td><label class="radio" style="display: inline;"><input type="radio" data-toggle="radio" name="click_payout" value="'.$x.'"/></label></td>';
			echo '</tr>';
		}
		echo '</table>';
		echo '</div></div>';
		echo '<div class="row"><div class="col-xs-12">';
		echo '<div class="col-xs-5 col-xs-offset-7">
				<button class="btn btn-p202 btn-block" type="submit">Next <span class="fui-arrow-right pull-right"></span></button>
			  </div>';
		echo '</form>';
		echo '</div></div>';
		template_bottom();
		break;
		 
	case 2:

		// Applying a report writes conversions, so it is a POST that carries
		// the session token; it used to run from a GET link.
		$name = basename((string)($_POST['file'] ?? ''));
		if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !AUTH::check_csrf_token()) {
			template_top('Upload Revenue Reports');
			about_revenue_upload();
			echo '<div class="error"><small><span class="fui-alert"></span>Your session expired before the report was applied. Nothing was changed; <a href="'.get_absolute_url().'tracking202/update/upload.php">upload it again</a>.</small></div>';
			template_bottom();
			die();
		}

		// scope filename to a basename inside the reports dir
		if ($name === '' || $name !== ($_POST['file'] ?? '')) {
			template_top('Upload Revenue Reports');
			about_revenue_upload();
			echo '<div class="error"><small><span class="fui-alert"></span>This file does not exist that you are trying to import or you have already successfully uploaded it.</small></div>';
			template_bottom();
			die();
		}

		// The two radio buttons carry column indexes (0, 1, ...).
		$subidColumn = isset($_POST['click_id']) && is_string($_POST['click_id']) && ctype_digit($_POST['click_id']) ? (int) $_POST['click_id'] : null;
		$amountColumn = isset($_POST['click_payout']) && is_string($_POST['click_payout']) && ctype_digit($_POST['click_payout']) ? (int) $_POST['click_payout'] : null;
		if ($subidColumn === null || $amountColumn === null) {

			template_top('Upload Revenue Reports');
			about_revenue_upload();
			echo '<div class="error"><small><span class="fui-alert"></span>You forgot to check the subid and the commission column, <a href="'.get_absolute_url().'tracking202/update/upload.php?case=1&file='.rawurlencode($name).'">please try again</a></small></div>';
			template_bottom();
			die();

		}

		$file = $upload_dir . $name;
		$real_file = realpath($file);
		$real_dir  = realpath($upload_dir);
		if (!file_exists($file) || $real_file === false || $real_dir === false || !str_starts_with($real_file, $real_dir . DIRECTORY_SEPARATOR)) {
			template_top('Upload Revenue Reports');
			about_revenue_upload();
			echo '<div class="error"><small><span class="fui-alert"></span>This file does not exist that you are trying to import or you have already successfully uploaded it.</small></div>';
			template_bottom();
			die();
		}

		$handle = fopen($real_file, 'rb');
		if ($handle === false) {
			template_top('Upload Revenue Reports');
			about_revenue_upload();
			echo '<div class="error"><small><span class="fui-alert"></span>The uploaded report could not be read. Nothing was changed; please upload it again.</small></div>';
			template_bottom();
			die();
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
			template_top('Upload Revenue Reports');
			about_revenue_upload();
			echo '<div class="error"><small><span class="fui-alert"></span>The report could not be applied: '.htmlspecialchars($importError->getMessage(), ENT_QUOTES).'. Lines before the failure were recorded; uploading the file again records the rest without repeating them.</small></div>';
			template_bottom();
			die();
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

		template_top('Upload Revenue Reports');
		about_revenue_upload();
			echo '<div class="row">
					<div class="col-xs-12">
					<div class="success"><small><span class="fui-check-inverted"></span>Your report has been uploaded: '.(int) $import['recorded'].' line(s) recorded'.($import['skipped'] > 0 ? ', '.(int) $import['skipped'].' skipped (listed below)' : '').'.</small></div><br/>
					<small>Each subid\'s income is now the sum of its lines in this report:</small>

					<table class="table table-bordered" id="stats-table">
					<tr>
						<th>SUBID</th>
						<th>COMMISSION</th>
					</tr>';
			foreach ($import['totals'] as $key => $total) {
				printf("<tr>
							<td>%s</td>
							<td>$%s</td>
					     </tr>", (int) $key, htmlspecialchars((string) $total, ENT_QUOTES));
			}
			echo '</table>';
			$skippedLines = array_filter($import['lines'], static fn (array $l): bool => $l['status'] === 'skipped');
			if ($skippedLines !== []) {
				echo '<small>Lines not recorded:</small><table class="table table-bordered"><tr><th>LINE</th><th>SUBID</th><th>COMMISSION</th><th>WHY</th></tr>';
				foreach ($skippedLines as $l) {
					printf('<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td></tr>', (int) $l['line'],
						htmlspecialchars($l['subid'], ENT_QUOTES), htmlspecialchars($l['amount'], ENT_QUOTES), htmlspecialchars($l['reason'], ENT_QUOTES));
				}
				echo '</table>';
			}
			echo '</div></div>';
		template_bottom();

		break;

	default:
		
		if ($_SERVER['REQUEST_METHOD'] == 'POST') { 
			
			// Initialize error variable
			$error = !AUTH::check_csrf_token();
			
			// Check if file was uploaded properly
			if (!isset($_FILES['csv']) || !isset($_FILES['csv']['tmp_name']) || empty($_FILES['csv']['tmp_name'])) {
				$error = true;
			}
			
			if (!$error) {
				//get file extension, checks to see if the image file, if not, do not allow to upload the file
				$pext = getFileExtension($_FILES['csv']['name']);
				$pext = strtolower((string) $pext); 
				if (($pext != "txt") and ($pext !="csv")) $error = true;
			}
			
			$handle = false;
			if (!$error) {
				//open the tmp file, that was uploaded, the csv
				$tmp_name = $_FILES['csv']['tmp_name'];
				$handle = fopen($tmp_name, "rb");
				if ($handle === false) {
					$error = true;
				}
			}
			
			if (!$error && $handle !== false) {
				//this counter, will help us determine the first row of the array
				$row = @fgetcsv($handle, 100000, ",", escape: '\\');
				
				#if there was no row detected, an error occured on this uploaded
				if (!$row) $error = true;
				
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
			}
		}
		
		template_top('Upload Revenue Reports'); 
		about_revenue_upload();
			
		//check to see if the directory is writable
		if ( !is_writable(  $upload_dir )) {
			
			echo '<table cellspacing="1" cellpadding="4" class="upload-table"><tr><td>'. "<div class='error'>Sorry, I can't write to the directory: ". $upload_dir  ." <br/>In order to upload Revenue reports we need to be able to write to this directory, you'll need to modify the permissions.</div></td></tr></table>";
			template_bottom(); die();
		} ?>
		 
		<div class="row">
			<div class="col-xs-12">
				<form enctype="multipart/form-data" action="<?php echo get_absolute_url();?>tracking202/update/upload.php" method="post" class="form-horizontal" role="form">
					<input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>" /> 
					<div class="col-xs-3">
						<label for="csv">Upload Commission Report:</label>
					</div>

					<div class="col-xs-8" style="margin-left: 20px;">
						<div class="form-group">
				          <div class="fileinput fileinput-new" data-provides="fileinput">
								<span class="btn btn-default btn-embossed btn-file">					  	
								<span class="fileinput-new"><span class="fui-upload"></span>&nbsp;&nbsp;Attach File</span>
								<span class="fileinput-exists"><span class="fui-gear"></span>&nbsp;&nbsp;Change</span>
								<input type="file" name="csv" id="csv">
								</span>
								<span class="fileinput-filename"></span>
								<a href="#" class="close fileinput-exists" data-dismiss="fileinput" style="float: none">&times;</a>
						  </div>
			          </div>
					</div>

					
			          <div class="col-xs-5">
						<button class="btn btn-sm btn-p202 btn-block" type="submit">Upload Report</button>
					</div>
			          
				</form>
			</div>
		</div>
		<?php template_bottom();
		break;
}
