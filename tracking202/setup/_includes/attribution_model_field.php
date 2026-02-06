<?php
declare(strict_types=1);

// Attribution Model Selection Field
// Include this in campaign forms to add attribution model selection

// Ensure we have the necessary dependencies
if (!isset($db) || !isset($_SESSION['user_own_id'])) {
    return;
}

// Get current attribution model for editing
$currentAttributionModelId = null;
if (isset($html['attribution_model_id']) && $html['attribution_model_id'] !== '') {
    $currentAttributionModelId = (int)$html['attribution_model_id'];
}

// Initialize attribution service
try {
    // Include required attribution classes
    // Path: _includes -> setup -> tracking202 -> root (3 levels up)
    require_once dirname(__DIR__, 3) . '/202-config/Attribution/ModelType.php';
    require_once dirname(__DIR__, 3) . '/202-config/Attribution/ModelDefinition.php';
    require_once dirname(__DIR__, 3) . '/202-config/Attribution/Repository/ModelRepositoryInterface.php';
    require_once dirname(__DIR__, 3) . '/202-config/Attribution/Repository/Mysql/MysqlModelRepository.php';
    require_once dirname(__DIR__, 3) . '/202-config/Attribution/AttributionIntegrationService.php';

    // Create the model repository and integration service directly
    $modelRepository = new \Prosper202\Attribution\Repository\Mysql\MysqlModelRepository($db, $db);
    $integrationService = new \Prosper202\Attribution\AttributionIntegrationService(
        $modelRepository,
        $db
    );

    $userId = (int)$_SESSION['user_own_id'];
    $modelOptions = $integrationService->getModelOptionsForUser($userId, $currentAttributionModelId);
    $defaultModelId = $integrationService->getDefaultModelIdForUser($userId);
    
} catch (Exception $e) {
    // If attribution system is not available, don't show the field
    error_log('Attribution field error: ' . $e->getMessage());
    return;
}
?>

<!-- Attribution Model Selection Field -->
<div class="form-group" style="margin-bottom: 0px;">
    <label for="attribution_model_id" class="col-xs-4 control-label" style="text-align: left;">
        Attribution Model 
        <span class="fui-info" data-toggle="tooltip" 
              title="How conversions will be attributed to different touchpoints in the customer journey"></span>
    </label>
    <div class="col-xs-6">
        <select class="form-control input-sm" name="attribution_model_id" id="attribution_model_id">
            <?php echo $modelOptions; ?>
        </select>
        <small class="help-block">
            <?php if ($defaultModelId): ?>
                Leave blank to use your default attribution model. 
                <a href="<?php echo get_absolute_url(); ?>tracking202/setup/attribution_models.php" target="_blank">
                    Manage Models
                </a>
            <?php else: ?>
                No attribution models configured. 
                <a href="<?php echo get_absolute_url(); ?>tracking202/setup/attribution_models.php" target="_blank">
                    Create your first model
                </a>
            <?php endif; ?>
        </small>
    </div>
</div>