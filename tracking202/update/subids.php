<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -19) . '/202-config/connect.php');
include_once(substr(__DIR__, 0, -19) . '/202-config/class-dataengine-slim.php');

use Prosper202\Attribution\AttributionServiceFactory;
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
$marked = 0;
$ignored = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	if (!AUTH::check_csrf_token()) {
		$subidError = 'Your session expired before the form was sent. Nothing was changed; please send it again.';
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

		// Rebuild attribution snapshots so the attribution page reflects changes immediately
		try {
			$jobRunner = AttributionServiceFactory::createJobRunner();
			$endTime = time();
			$startTime = $endTime - 86400;
			$jobRunner->runForUser($userId, $startTime, $endTime);
		} catch (Throwable $e) {
			error_log('Attribution rebuild after subid upload failed: ' . $e->getMessage());
		}

		$success = true;
	}
}

//show the template
template_top('Update Subids'); ?>
<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<div class="row">
			<div class="col-xs-4">
				<h6>Update Your Subids</h6>
			</div>
			<div class="col-xs-8">
				<div class="success pull-right" style="margin-top: 20px;">
					<small>
						<?php if ($success == true) { ?>
							<span class="fui-check-inverted"></span> <?php echo (int) $marked; ?> subid(s) marked as converted. Your account income now reflects them.
							<?php if ($ignored !== []) { ?><br/>Not found in your account, so not marked: <?php echo htmlspecialchars(implode(', ', array_slice($ignored, 0, 20)), ENT_QUOTES); ?><?php if (count($ignored) > 20) { echo ' and ' . (count($ignored) - 20) . ' more'; } ?><?php } ?>
						<?php } elseif ($subidError !== '') { ?>
							<span class="fui-alert"></span> <?php echo htmlspecialchars($subidError, ENT_QUOTES); ?>
						<?php } ?>
					</small>
				</div>
			</div>
		</div>
	</div>
	<div class="col-xs-12">
		<small>Here is where you can update your income for Prosper202, by importing your subids from your affiliate marketing reports.</small>
	</div>
</div>

<div class="row form_seperator">
	<div class="col-xs-12"></div>
</div>

<div class="row">
	<div class="col-xs-12">
		<form method="post" action="" class="form-horizontal" role="form">
			<input type="hidden" name="token" value="<?php echo htmlspecialchars((string) ($_SESSION['token'] ?? ''), ENT_QUOTES); ?>" />
			<div class="form-group" style="margin:0px 0px 15px 0px;">
				<label for="subids">Subids</label>
				<textarea rows="5" name="subids" id="subids" placeholder="Add your subids..." class="form-control"></textarea>
			</div>
			<button class="btn btn-sm btn-p202 btn-block" type="submit">Update Subids</button>
		</form>
	</div>
</div>

<?php template_bottom();
