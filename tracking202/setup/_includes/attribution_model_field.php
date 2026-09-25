<?php
declare(strict_types=1);

// The campaign's attribution model override (plan §6.3). Blank means the
// account default; the override is what the attribution reports' effective
// mode reads for this campaign's conversions. aff_campaigns.php refuses an
// id that is not one of the account's own models.

if (!isset($db) || !isset($_SESSION['user_id'])) {
    return;
}

$currentAttributionModelId = null;
if (isset($html['attribution_model_id']) && $html['attribution_model_id'] !== '') {
    $currentAttributionModelId = (int) $html['attribution_model_id'];
}

try {
    $attributionModelRows = (new \Prosper202\Attribution\ModelRepository(new \Prosper202\Database\Connection($db)))
        ->rows((int) $_SESSION['user_id']);
} catch (\Throwable $e) {
    // The rest of the campaign form must still work; say why the field is missing.
    error_log('Attribution model field: ' . $e->getMessage());
    echo '<div class="form-group"><div class="col-xs-10"><small class="help-block">Attribution models could not be loaded, so this campaign keeps its current model.</small></div></div>';
    return;
}

$attributionDefaultName = '';
foreach ($attributionModelRows as $attributionModelRow) {
    if ((int) ($attributionModelRow['is_default'] ?? 0) === 1) {
        $attributionDefaultName = (string) $attributionModelRow['model_name'];
    }
}
?>

<!-- Attribution Model Selection Field -->
<div class="form-group" style="margin-bottom: 0px;">
    <label for="attribution_model_id" class="col-xs-4 control-label" style="text-align: left;">
        Attribution Model
        <span class="fui-info" data-toggle="tooltip"
              title="Which model the attribution reports use for this campaign's conversions"></span>
    </label>
    <div class="col-xs-6">
        <select class="form-control input-sm" name="attribution_model_id" id="attribution_model_id">
            <option value="">Account default<?php echo $attributionDefaultName !== '' ? ' (' . htmlspecialchars($attributionDefaultName, ENT_QUOTES, 'UTF-8') . ')' : ''; ?></option>
            <?php foreach ($attributionModelRows as $attributionModelRow) {
                $attributionModelId = (int) $attributionModelRow['model_id']; ?>
                <option value="<?php echo $attributionModelId; ?>"<?php echo $attributionModelId === $currentAttributionModelId ? ' selected' : ''; ?>>
                    <?php echo htmlspecialchars((string) $attributionModelRow['model_name'] . ' — ' . (string) $attributionModelRow['model_type']
                        . ((string) $attributionModelRow['status'] !== 'active' ? ' (' . (string) $attributionModelRow['status'] . ')' : ''), ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php } ?>
        </select>
        <?php echo $error['attribution_model_id'] ?? ''; ?>
        <small class="help-block">
            Leave on the account default unless this campaign needs its own model.
            <a href="<?php echo get_absolute_url(); ?>202-account/attribution.php" target="_blank">Attribution</a>
        </small>
    </div>
</div>
