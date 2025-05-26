<?php

declare(strict_types=1);
include_once(substr(dirname(__FILE__), 0, -19) . '/202-config/connect.php');
include_once(substr(dirname(__FILE__), 0, -19) . '/202-config/class-dataengine-slim.php');

AUTH::require_user();

$success = false;

if (!$userObj->hasPermission("access_to_update_section") || !$userObj->hasPermission("delete_individual_subids")) {
	header('location: ' . get_absolute_url() . 'tracking202/');
	die();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

	$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);

	$subids = $_POST['subids'] ?? '';
	$subids = trim($subids);
	$subids = explode("\r", $subids);
	$subids = str_replace("\n", '', $subids);

	foreach ($subids as $key => $click_id) {
		$mysql['click_id'] = $db->real_escape_string($click_id);

		$click_sql = "
			SELECT 2c.click_id 
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

		$update_sql = "
			UPDATE 202_clicks
			SET
				click_lead='0',
				`click_filtered`='0'
			WHERE
				click_id='" . $mysql['click_id'] . "'
				AND user_id='" . $mysql['user_id'] . "'
		";
		try {
			$update_result = $db->query($update_sql);
		} catch (Exception $e) {
			error_log("Database query failed: " . $e->getMessage());
			$success = false;
			continue;
		}

		$update_sql = "
			UPDATE 202_clicks_spy
			SET
				click_lead='0',
				`click_filtered`='0'
			WHERE
				click_id='" . $mysql['click_id'] . "'
				AND user_id='" . $mysql['user_id'] . "'
		";
		$update_result = $db->query($update_sql) or die($db->error);

		$de = new DataEngine();
		$de->setDirtyHour($mysql['click_id']);
	}

	$success = true;
}

//show the template
template_top('Delete Subids', NULL, NULL, NULL); ?>

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
							<span class="fui-check-inverted"></span> Your submission was successful. Your account income now reflects the subids just deleted.
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
			<div class="form-group" style="margin:0px 0px 15px 0px;">
				<label for="subids">Subids</label>
				<textarea rows="5" name="subids" id="subids" placeholder="Add your subids..." class="form-control"><?php echo htmlspecialchars($_POST['subids'] ?? '', ENT_QUOTES); ?></textarea>
			</div>
			<button class="btn btn-sm btn-p202 btn-block" type="submit">Update Subids</button>
		</form>
	</div>
</div>
<script src="/202-js/flatui-fileinput.js"></script>
<?php template_bottom();
