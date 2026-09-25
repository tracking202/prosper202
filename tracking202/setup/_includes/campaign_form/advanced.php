<?php

declare(strict_types=1);

/**
 * Campaign form, last section: everything the common case leaves at its
 * default, under one closed "Advanced" disclosure (UI standard, rule 1).
 * It opens by itself when one of its settings is already away from the
 * default or was refused, so nothing set is folded out of sight.
 *
 * @var array<string, mixed> $campaignForm values, errors and lists, built by the page
 */

$values = $campaignForm['values'];
$errors = $campaignForm['errors'];
$rotationOffered = (bool) $campaignForm['rotation_offered'];
$rotating = (string) $values['aff_campaign_rotate'] === '1';
$advancedSet = (string) $values['aff_campaign_cloaking'] === '1'
	|| $rotating
	|| (string) ($values['attribution_model_id'] ?? '') !== ''
	|| p202_setup_invalid($errors, 'attribution_model_id', 'aff_campaign_url_2', 'aff_campaign_url_3', 'aff_campaign_url_4', 'aff_campaign_url_5') !== '';
?>
<details class="p202-disclosure mb-3" data-p202-remember="setup-campaigns-advanced"<?php echo $advancedSet ? ' open' : ''; ?>>
	<summary>Advanced <span class="p202-disclosure__hint">cloaking, attribution model<?php echo $rotationOffered ? ', URL rotation' : ''; ?></span></summary>
	<div class="p202-disclosure__body">
		<div class="mb-3">
			<label class="form-label" for="aff_campaign_cloaking">Cloaking</label>
			<select class="form-select" id="aff_campaign_cloaking" name="aff_campaign_cloaking">
				<?php echo p202_setup_options(['0' => 'Off by default', '1' => 'On by default'], (string) $values['aff_campaign_cloaking'] === '1' ? '1' : '0'); ?>
			</select>
			<div class="form-text">Off by default. On hides the referrer from the offer; a tracking link can still override it.</div>
		</div>

		<?php include __DIR__ . '/../attribution_model_field.php'; ?>

		<?php if ($rotationOffered) { ?>
			<fieldset class="mb-1">
				<legend class="form-label">Rotate URLs</legend>
				<div class="form-check form-check-inline">
					<input class="form-check-input" type="radio" name="aff_campaign_rotate" id="aff_campaign_rotate1" value="0"<?php echo $rotating ? '' : ' checked'; ?>>
					<label class="form-check-label" for="aff_campaign_rotate1">No</label>
				</div>
				<div class="form-check form-check-inline">
					<input class="form-check-input" type="radio" name="aff_campaign_rotate" id="aff_campaign_rotate2" value="1"<?php echo $rotating ? ' checked' : ''; ?>>
					<label class="form-check-label" for="aff_campaign_rotate2">Yes</label>
				</div>
				<div class="form-text">No by default: every click goes to the campaign URL. Yes sends clicks in turn to it and the URLs below.</div>
			</fieldset>
			<div data-p202-show-when="aff_campaign_rotate=1"<?php echo $rotating ? '' : ' hidden'; ?>>
				<?php foreach ([2, 3, 4, 5] as $n) {
					$field = 'aff_campaign_url_' . $n; ?>
					<div class="mb-2 mt-2">
						<label class="form-label" for="<?php echo $field; ?>">Rotate URL #<?php echo $n; ?></label>
						<input type="text" class="form-control font-monospace<?php echo p202_setup_invalid($errors, $field); ?>" id="<?php echo $field; ?>" name="<?php echo $field; ?>" value="<?php echo p202_setup_e($values[$field]); ?>" placeholder="https://">
						<?php echo p202_setup_feedback($errors, $field); ?>
					</div>
				<?php } ?>
			</div>
		<?php } ?>
	</div>
</details>
