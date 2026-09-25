<?php
declare(strict_types=1);

/**
 * The campaign form's attribution model override (plan §6.3), v2 markup,
 * included by _includes/campaign_form/advanced.php.
 *
 * Blank means the account default; the override is what the attribution
 * reports' effective mode reads for this campaign's conversions.
 * aff_campaigns.php refuses an id that is not one of the account's own
 * models, keyed on the same $_SESSION['user_id'] this list reads.
 *
 * Reads the selected model from $campaignForm['values']['attribution_model_id']
 * and the handler's sentence from $campaignForm['errors'].
 */

if (!isset($db) || !isset($_SESSION['user_id'])) {
    return;
}

$attributionValue = (string) ($campaignForm['values']['attribution_model_id'] ?? '');
$currentAttributionModelId = $attributionValue !== '' ? (int) $attributionValue : null;
$attributionErrors = (array) ($campaignForm['errors'] ?? []);
$attributionPageUrl = get_absolute_url() . '202-account/attribution.php';

try {
    $attributionModelRows = (new \Prosper202\Attribution\ModelRepository(new \Prosper202\Database\Connection($db)))
        ->rows((int) $_SESSION['user_id']);
} catch (\Throwable $e) {
    // The rest of the campaign form must still work; say why the field is missing.
    error_log('Attribution model field: ' . $e->getMessage());
    echo '<div class="mb-3"><div class="form-text">Attribution models could not be loaded, so this campaign keeps its current model.</div></div>';
    return;
}

$attributionDefaultName = '';
foreach ($attributionModelRows as $attributionModelRow) {
    if ((int) ($attributionModelRow['is_default'] ?? 0) === 1) {
        $attributionDefaultName = (string) $attributionModelRow['model_name'];
    }
}
?>
<div class="mb-3">
    <label class="form-label" for="attribution_model_id">Attribution model</label>
    <select class="form-select<?php echo p202_setup_invalid($attributionErrors, 'attribution_model_id'); ?>" name="attribution_model_id" id="attribution_model_id">
        <option value="">Account default<?php echo $attributionDefaultName !== '' ? ' (' . htmlspecialchars($attributionDefaultName, ENT_QUOTES, 'UTF-8') . ')' : ''; ?></option>
        <?php foreach ($attributionModelRows as $attributionModelRow) {
            $attributionModelId = (int) $attributionModelRow['model_id']; ?>
            <option value="<?php echo $attributionModelId; ?>"<?php echo $attributionModelId === $currentAttributionModelId ? ' selected' : ''; ?>><?php
                echo htmlspecialchars((string) $attributionModelRow['model_name'] . ' — ' . (string) $attributionModelRow['model_type']
                    . ((string) $attributionModelRow['status'] !== 'active' ? ' (' . (string) $attributionModelRow['status'] . ')' : ''), ENT_QUOTES, 'UTF-8'); ?></option>
        <?php } ?>
    </select>
    <?php echo p202_setup_feedback($attributionErrors, 'attribution_model_id'); ?>
    <div class="form-text">
        Leave on the account default unless this campaign needs its own model: how a conversion's credit is shared across the touchpoints before it.
        <a href="<?php echo htmlspecialchars($attributionPageUrl, ENT_QUOTES, 'UTF-8'); ?>">Manage models</a>
    </div>
</div>
