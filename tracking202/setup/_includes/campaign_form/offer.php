<?php

declare(strict_types=1);

/**
 * Campaign form, section 1: the offer. The common case is this whole section
 * — which category, what it is called, where a click goes, what it pays.
 *
 * Rendered inside the one campaign <form> by tracking202/setup/aff_campaigns.php,
 * which lists its sections in order. Every name posted here is one the
 * handler at the top of that page reads.
 *
 * @var array<string, mixed> $campaignForm values, errors and lists, built by the page
 */

$values = $campaignForm['values'];
$errors = $campaignForm['errors'];
?>
<div class="mb-3">
	<label class="form-label" for="aff_network_id">Category</label>
	<select class="form-select<?php echo p202_setup_invalid($errors, 'aff_network_id'); ?>" id="aff_network_id" name="aff_network_id" required>
		<?php echo p202_setup_options($campaignForm['categories'], $values['aff_network_id'], 'Choose a category'); ?>
	</select>
	<?php if (count($campaignForm['categories']) === 1) { ?>
		<div class="form-text">Your only category, so it is chosen for you.</div>
	<?php } ?>
	<?php echo p202_setup_feedback($errors, 'aff_network_id'); ?>
</div>

<div class="mb-3">
	<label class="form-label" for="aff_campaign_name">Campaign name</label>
	<input type="text" class="form-control<?php echo p202_setup_invalid($errors, 'aff_campaign_name'); ?>" id="aff_campaign_name" name="aff_campaign_name" value="<?php echo p202_setup_e($values['aff_campaign_name']); ?>" maxlength="255" required>
	<?php echo p202_setup_feedback($errors, 'aff_campaign_name'); ?>
</div>

<div class="mb-3">
	<label class="form-label" for="aff_campaign_url">Campaign URL</label>
	<textarea class="form-control font-monospace<?php echo p202_setup_invalid($errors, 'aff_campaign_url'); ?>" id="aff_campaign_url" name="aff_campaign_url" rows="3" placeholder="https://" required><?php echo p202_setup_e($values['aff_campaign_url']); ?></textarea>
	<div class="form-text">Where a click on your tracking link is sent: for an affiliate offer, your affiliate link. Put <code>[[subid]]</code> where the network takes a sub id.</div>
	<?php echo p202_setup_feedback($errors, 'aff_campaign_url'); ?>
	<?php echo p202_setup_placeholders('aff_campaign_url', 'setup-campaigns-placeholders'); ?>
</div>

<div class="mb-3">
	<label class="form-label" for="aff_campaign_payout">Payout</label>
	<div class="input-group">
		<span class="input-group-text">$</span>
		<input type="text" inputmode="decimal" class="form-control<?php echo p202_setup_invalid($errors, 'aff_campaign_payout'); ?>" id="aff_campaign_payout" name="aff_campaign_payout" value="<?php echo p202_setup_e($values['aff_campaign_payout']); ?>" required>
	</div>
	<div class="form-text">What one conversion earns. A postback that reports its own amount overrides it.</div>
	<?php echo p202_setup_feedback($errors, 'aff_campaign_payout'); ?>
</div>
