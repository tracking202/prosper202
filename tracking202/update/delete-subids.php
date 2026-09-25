<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -19) . '/202-config/connect.php');
include_once(substr(__DIR__, 0, -19) . '/202-config/class-dataengine-slim.php');

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
$cleared = 0;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	if (!AUTH::check_csrf_token()) {
		$deleteError = 'Your session expired before the form was sent. Nothing was changed; please send it again.';
	} else {
		$userId = (int) $_SESSION['user_id'];
		$lines = preg_split('/\R/', trim((string) ($_POST['subids'] ?? ''))) ?: [];
		$clickIds = [];
		foreach ($lines as $line) {
			$clickId = ClickId::parse(trim($line));
			if ($clickId !== null) {
				$clickIds[] = $clickId;
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

//show the template
template_top('Delete Subids'); ?>

<div class="row" style="margin-bottom: 15px;">
	<div class="col-xs-12">
		<div class="row">
			<div class="col-xs-4">
				<h6>Delete Individual Subids</h6>
			</div>
			<div class="col-xs-8">
				<div class="success pull-right" style="margin-top: 20px;">
					<small>
						<?php if ($success == true) { ?>
							<span class="fui-check-inverted"></span> <?php echo (int) $cleared; ?> subid(s) cleared. Your account income now reflects the subids just deleted.
						<?php } elseif ($deleteError !== '') { ?>
							<span class="fui-alert"></span> <?php echo htmlspecialchars($deleteError, ENT_QUOTES); ?>
						<?php } ?>
					</small>
				</div>
			</div>
		</div>
	</div>
	<div class="col-xs-12">
		<small>Ok, just like the upload subids but in reverse, upload the subids you want to delete instead!</small>
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
				<textarea rows="5" name="subids" id="subids" placeholder="Add your subids..." class="form-control"><?php echo htmlspecialchars($_POST['subids'] ?? '', ENT_QUOTES); ?></textarea>
			</div>
			<button class="btn btn-sm btn-p202 btn-block" type="submit">Update Subids</button>
		</form>
	</div>
</div>
<?php template_bottom();
