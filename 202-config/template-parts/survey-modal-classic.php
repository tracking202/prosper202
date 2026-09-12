<?php

declare(strict_types=1);

/**
 * The VIP Perks survey modal, rendered by template_bottom() on the classic
 * shell only. Bootstrap 3 modal markup driven by 202-js/custom.php; when the
 * account pages move to the v2 shell this gets a Bootstrap 5 counterpart and
 * the classic one is deleted with the shell.
 */

$user_data = get_user_data_feedback($_SESSION['user_id'] ?? 0);

if (empty($user_data['modal_status'])) {
	$data = getSurveyData($user_data['install_hash']); ?>

	<script type="text/javascript">
		$(window).load(function() {
			$('#survey-modal').modal({
				backdrop: 'static',
				show: true,
			})
		});
	</script>

	<!-- Start survey modal -->
	<div id="survey-modal" class="modal fade" role="dialog" aria-hidden="true">
		<div class="modal-dialog">
			<div class="modal-content">
				<div class="modal-header">
					<h4 class="modal-title">Prosper202 VIP Perks</h4>
				</div>
				<div class="modal-body">
					<span class="infotext">Wouldn't you love to have new campaign opportunities, private campaigns, business relationships, discounts and special offers and more handed to you? Now you can with the Prosper202 VIP Perk program.<br></br>
						Fill out your profile information to customize your Prosper202 VIP Perks experience. The information will be used to uniquely match you up with coupons, discounts, or exclusive offers.</span>
					<span id="perks-error" class="small error" style="display:none; position:absolute; right: 23px; margin-top: 39px;"><span class="fui-alert"></span> Whoops! Looks like you forget to answer some questions.</span>

					<form class="form-horizontal" role="form" id="survey-form">
						<?php $count_groups = [];
						foreach ($data['questions'] as $question) {

							if (empty($question['answer'])) {
								if (!array_key_exists($question['group_id'], $count_groups)) { ?>
									<?php foreach ($data['question_groups'] as $group) {
										if ($group['id'] == $question['group_id']) {
											echo "<h6>" . $group['title'] . "</h6>";
										}
									} ?>

									<div class="row form_seperator">
										<div class="col-xs-12"></div>
									</div>
								<?php }

								$count_groups[$question['group_id']] = true;

								$highlighted = false;

								$answer = $question['answer'] ?? '';

								if ($question['highlighted']) {
									$highlighted = true;
								}
								?>
								<div class="form-group">
									<label for="<?php echo $question['id']; ?>" class="col-sm-8 control-label"><?php echo $question['name']; ?> <?php if ($highlighted) echo '<span class="label label-important">New!</span>'; ?></label>
									<div class="col-sm-4">
										<label class="radio radio-inline">
											<input type="radio" name="<?php echo $question['id']; ?>" value="Yes" data-toggle="radio" required <?php if ($answer == 'Yes') echo "checked"; ?>>
											Yes
										</label>
										<label class="radio radio-inline">
											<input type="radio" name="<?php echo $question['id']; ?>" value="No" data-toggle="radio" <?php if ($answer == 'No') echo "checked"; ?>>
											No
										</label>
									</div>
								</div>

							<?php } ?>

						<?php } ?>

				</div>
				<div class="modal-footer">
					<img style="display:none;left: -25px; top: 12px;" id="perks-loading" src="<?php echo get_absolute_url(); ?>202-img/loader-small.gif">
					<a href="#" class="btn btn-link" id="survey-form-skip">Skip</a>
					<a href="#" class="btn btn-wide btn-p202" id="survey-form-submit">Submit answers</a>
				</div>
				</form>
			</div>
		</div>
	</div>
	<!-- End survey modal -->
<?php } ?>
