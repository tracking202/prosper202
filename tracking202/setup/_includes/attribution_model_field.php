<?php
declare(strict_types=1);

/**
 * The campaign form's attribution model select (v2 markup), included by
 * _includes/campaign_form/advanced.php.
 *
 * Reads the selected model from $campaignForm['values']['attribution_model_id']
 * (the raw value; the classic include read the escaped $html copy). When the
 * attribution service cannot be built the field is left out, as before —
 * the handler treats an absent attribution_model_id as "use the default".
 */

if (!isset($db) || !isset($_SESSION['user_own_id'])) {
    return;
}

$currentAttributionModelId = null;
$attributionValue = (string) ($campaignForm['values']['attribution_model_id'] ?? '');
if ($attributionValue !== '') {
    $currentAttributionModelId = (int) $attributionValue;
}

try {
    // Path: _includes -> setup -> tracking202 -> root (3 levels up)
    require_once dirname(__DIR__, 3) . '/202-config/Attribution/ModelType.php';
    require_once dirname(__DIR__, 3) . '/202-config/Attribution/ModelDefinition.php';
    require_once dirname(__DIR__, 3) . '/202-config/Attribution/Repository/ModelRepositoryInterface.php';
    require_once dirname(__DIR__, 3) . '/202-config/Attribution/Repository/Mysql/MysqlModelRepository.php';
    require_once dirname(__DIR__, 3) . '/202-config/Attribution/AttributionIntegrationService.php';

    $modelRepository = new \Prosper202\Attribution\Repository\Mysql\MysqlModelRepository($db, $db);
    $integrationService = new \Prosper202\Attribution\AttributionIntegrationService($modelRepository, $db);

    $userId = (int)$_SESSION['user_own_id'];
    $modelOptions = $integrationService->getModelOptionsForUser($userId, $currentAttributionModelId);
    $defaultModelId = $integrationService->getDefaultModelIdForUser($userId);
} catch (Exception $e) {
    error_log('Attribution field error: ' . $e->getMessage());
    return;
}
?>
<div class="mb-3">
    <label class="form-label" for="attribution_model_id">Attribution model</label>
    <select class="form-select" name="attribution_model_id" id="attribution_model_id">
        <?php echo $modelOptions; ?>
    </select>
    <div class="form-text">
        <?php if ($defaultModelId) { ?>
            Your default model unless you choose another: how a conversion's credit is shared across the touchpoints before it.
            <a href="<?php echo htmlspecialchars(get_absolute_url() . 'tracking202/setup/attribution_models.php', ENT_QUOTES, 'UTF-8'); ?>">Manage models</a>
        <?php } else { ?>
            No attribution models are configured yet.
            <a href="<?php echo htmlspecialchars(get_absolute_url() . 'tracking202/setup/attribution_models.php', ENT_QUOTES, 'UTF-8'); ?>">Create a model</a>
        <?php } ?>
    </div>
</div>
