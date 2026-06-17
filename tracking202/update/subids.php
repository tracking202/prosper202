<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -19) . '/202-config/connect.php');
include_once(substr(__DIR__, 0, -19) . '/202-config/class-dataengine-slim.php');

use Prosper202\Attribution\AttributionServiceFactory;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Database\Connection;
AUTH::require_user();

if (!$userObj->hasPermission("access_to_update_section")) {
	header('location: ' . get_absolute_url() . 'tracking202/');
	die();
}

// Initialize variables to prevent undefined variable warnings
$success = false;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
	$mysql['click_update_type'] = 'upload';
	$mysql['click_update_time'] = time();

	$subids = $_POST['subids'] ?? '';
	$subids = trim((string) $subids);
	$subids = explode("\r", $subids);
	$subids = str_replace("\n", '', $subids);

	// Conversion rows go through the single canonical writer.
	$conversionRepo = new MysqlConversionRepository(new Connection($db));

	foreach ($subids as $click_id) {
		$mysql['click_id'] = $db->real_escape_string($click_id);

		$click_sql = "
			SELECT 2c.click_id, 2c.click_lead, 2c.aff_campaign_id, 2c.click_time, 2c.click_payout
			FROM
				202_clicks AS 2c
			WHERE
				2c.click_id ='" . $mysql['click_id'] . "'
				AND 2c.user_id='" . $mysql['user_id'] . "'
		";
		$click_result = $db->query($click_sql) or record_mysql_error($click_sql);
		$click_row = $click_result->fetch_assoc();

		// Check if click_row exists and click_id is not null before processing
		if ($click_row && isset($click_row['click_id']) && $click_row['click_id'] !== null) {
			$mysql['click_id'] = $db->real_escape_string((string)$click_row['click_id']);
		} else {
			// Skip this iteration if no valid click found
			continue;
		}

		if (is_numeric($mysql['click_id'])) {
			$clickId = (int) $mysql['click_id'];
			$userId = (int) $mysql['user_id'];

			// Flag the click as a lead and clear any filtering, on both the clicks
			// and spy tables. When a conversion row is recorded this runs inside the
			// repository transaction (below) so the flag and the audit row commit or
			// roll back together.
			$applyClickUpdate = function () use ($db, $clickId, $userId): void {
				foreach (['202_clicks', '202_clicks_spy'] as $table) {
					$update_sql = "UPDATE " . $table . " SET click_lead='1', `click_filtered`='0'"
						. " WHERE click_id='" . $clickId . "' AND user_id='" . $userId . "'";
					if (!$db->query($update_sql)) {
						throw new RuntimeException('subids: failed to update ' . $table . ' for click ' . $clickId);
					}
				}
			};

			// Click-level dedup (subid uploads carry no transaction id): only record
			// a conversion when this click has no non-deleted conversion yet.
			$check_sql = "SELECT conv_id FROM 202_conversion_logs WHERE click_id = '" . $clickId . "' AND user_id = '" . $userId . "' AND deleted = 0 LIMIT 1";
			$check_result = $db->query($check_sql);

			if ($check_result && $check_result->num_rows === 0) {
				$conv_time = time();
				$click_time = (int) $click_row['click_time'];
				$diff = (new DateTime(date('Y-m-d H:i:s', $click_time)))->diff(new DateTime(date('Y-m-d H:i:s', $conv_time)));

				$conversionRepo->record(
					$userId,
					[
						'click_id'        => $clickId,
						'transaction_id'  => '',
						'campaign_id'     => (int) $click_row['aff_campaign_id'],
						'payout'          => (float) $click_row['click_payout'],
						'click_time'      => $click_time,
						'conv_time'       => $conv_time,
						'time_difference' => $diff->d . ' days, ' . $diff->h . ' hours, ' . $diff->i . ' min and ' . $diff->s . ' sec',
						'ip'              => '',
						'pixel_type'      => 0,
						'user_agent'      => 'subid-upload',
					],
					function (int $lockedClickId, float $payout) use ($applyClickUpdate): void {
						$applyClickUpdate();
					}
				);
			} else {
				// Already has a conversion logged; just (re)apply the click flag.
				$applyClickUpdate();
			}

			$de = new DataEngine();
			$de->setDirtyHour((string) $clickId);
		}
	}

	// Rebuild attribution snapshots so the attribution page reflects changes immediately
	try {
		$jobRunner = AttributionServiceFactory::createJobRunner();
		$userId = (int) $_SESSION['user_id'];
		$endTime = time();
		$startTime = $endTime - 86400;
		$jobRunner->runForUser($userId, $startTime, $endTime);
	} catch (Throwable $e) {
		error_log('Attribution rebuild after subid upload failed: ' . $e->getMessage());
	}

	$success = true;
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
							<span class="fui-check-inverted"></span> Your submission was successful. Your account income now reflects the subids just uploaded.
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
			<div class="form-group" style="margin:0px 0px 15px 0px;">
				<label for="subids">Subids</label>
				<textarea rows="5" name="subids" id="subids" placeholder="Add your subids..." class="form-control"></textarea>
			</div>
			<button class="btn btn-sm btn-p202 btn-block" type="submit">Update Subids</button>
		</form>
	</div>
</div>

<?php template_bottom();
