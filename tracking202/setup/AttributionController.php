<?php

declare(strict_types=1);

namespace Tracking202\Setup;

require_once __DIR__ . '/_base/SetupController.php';

// Include database connection and configuration
require_once dirname(__DIR__, 2) . '/202-config/connect.php';

// Include attribution system classes - order matters for dependencies
require_once dirname(__DIR__, 2) . '/202-config/Attribution/ModelType.php';
require_once dirname(__DIR__, 2) . '/202-config/Attribution/ModelDefinition.php';

// Repository interfaces
require_once dirname(__DIR__, 2) . '/202-config/Attribution/Repository/ModelRepositoryInterface.php';
require_once dirname(__DIR__, 2) . '/202-config/Attribution/Repository/SnapshotRepositoryInterface.php';
require_once dirname(__DIR__, 2) . '/202-config/Attribution/Repository/TouchpointRepositoryInterface.php';
require_once dirname(__DIR__, 2) . '/202-config/Attribution/Repository/ConversionRepositoryInterface.php';
require_once dirname(__DIR__, 2) . '/202-config/Attribution/Repository/AuditRepositoryInterface.php';

// MySQL implementations
require_once dirname(__DIR__, 2) . '/202-config/Attribution/Repository/Mysql/MysqlModelRepository.php';
require_once dirname(__DIR__, 2) . '/202-config/Attribution/Repository/Mysql/MysqlSnapshotRepository.php';
require_once dirname(__DIR__, 2) . '/202-config/Attribution/Repository/Mysql/MysqlTouchpointRepository.php';
require_once dirname(__DIR__, 2) . '/202-config/Attribution/Repository/Mysql/MysqlConversionRepository.php';
require_once dirname(__DIR__, 2) . '/202-config/Attribution/Repository/Mysql/MysqlAuditRepository.php';

// Null implementations
require_once dirname(__DIR__, 2) . '/202-config/Attribution/Repository/NullModelRepository.php';
require_once dirname(__DIR__, 2) . '/202-config/Attribution/Repository/NullSnapshotRepository.php';
require_once dirname(__DIR__, 2) . '/202-config/Attribution/Repository/NullTouchpointRepository.php';
require_once dirname(__DIR__, 2) . '/202-config/Attribution/Repository/NullConversionRepository.php';
require_once dirname(__DIR__, 2) . '/202-config/Attribution/Repository/NullAuditRepository.php';

// Services
require_once dirname(__DIR__, 2) . '/202-config/Attribution/AttributionJobRunner.php';
require_once dirname(__DIR__, 2) . '/202-config/Attribution/AttributionService.php';
require_once dirname(__DIR__, 2) . '/202-config/Attribution/AttributionServiceFactory.php';

// Validation
require_once dirname(__DIR__, 2) . '/202-config/Validation/ValidationResult.php';
require_once dirname(__DIR__, 2) . '/202-config/Validation/ValidationException.php';
require_once dirname(__DIR__, 2) . '/202-config/Validation/SetupFormValidator.php';

use Prosper202\Attribution\AttributionService;
use Prosper202\Attribution\AttributionServiceFactory;
use Prosper202\Attribution\ModelDefinition;
use Prosper202\Attribution\ModelType;
use Prosper202\Attribution\Repository\ModelRepositoryInterface;
use Prosper202\Validation\SetupFormValidator;
use Prosper202\Validation\ValidationException;

/**
 * Controller for managing attribution models in the setup section.
 */
class AttributionController extends SetupController
{
    private readonly AttributionService $attributionService;
    private ModelRepositoryInterface $modelRepository;
    private SetupFormValidator $validator;
    
    private bool $editing = false;
    private bool $copying = false;
    private ?ModelDefinition $currentModel = null;
    
    public function __construct()
    {
        parent::__construct();
        
        global $db;
        
        // Initialize services
        $this->attributionService = AttributionServiceFactory::create();
        
        // Get model repository from factory directly since AttributionService doesn't expose it
        $db = \DB::getInstance();
        $writeConnection = $db?->getConnection();
        $readConnection = $db?->getConnectionro();
        
        if ($writeConnection instanceof \mysqli) {
            $this->modelRepository = new \Prosper202\Attribution\Repository\Mysql\MysqlModelRepository($writeConnection, $readConnection);
            $this->validator = new SetupFormValidator($writeConnection);
        } else {
            $this->modelRepository = new \Prosper202\Attribution\Repository\NullModelRepository();
            // Fallback to global $db for validator if DB connection fails
            global $db;
            $this->validator = new SetupFormValidator($db instanceof \mysqli ? $db : new \mysqli());
        }
        
        // Check if we're editing or copying
        $this->checkEditingState();
    }
    
    protected function handleGet(): void
    {
        if ($this->editing || $this->copying) {
            $this->loadModelForEditing();
        }
    }
    
    protected function handlePost(): void
    {
        try {
            if (isset($_POST['action'])) {
                match ($_POST['action']) {
                    'save' => $this->saveModel(),
                    'delete' => $this->deleteModel(),
                    'activate' => $this->activateModel(),
                    'deactivate' => $this->deactivateModel(),
                    'set_default' => $this->setDefaultModel(),
                    default => throw new \InvalidArgumentException('Invalid action')
                };
            } else {
                $this->saveModel();
            }
        } catch (ValidationException $e) {
            foreach ($e->getErrors() as $field => $error) {
                $this->addError($field, $error);
            }
        }
    }
    
    protected function render(): void
    {
        $this->renderPage();
    }
    
    /**
     * Check if we're in editing or copying mode
     */
    private function checkEditingState(): void
    {
        if (!empty($_GET['edit_model_id'])) {
            $this->editing = true;
        } elseif (!empty($_GET['copy_model_id'])) {
            $this->copying = true;
        }
    }
    
    /**
     * Load model for editing or copying
     */
    private function loadModelForEditing(): void
    {
        $modelId = (int)($_GET['edit_model_id'] ?? $_GET['copy_model_id'] ?? 0);
        
        if ($modelId <= 0) {
            $this->addError('general', 'Invalid model ID');
            return;
        }
        
        $this->currentModel = $this->modelRepository->findById($modelId);
        
        if (!$this->currentModel) {
            $this->addError('general', 'Model not found');
            return;
        }
        
        // Verify ownership
        if ($this->currentModel->userId !== $this->getUserId()) {
            $this->addError('general', 'You are not authorized to access this model');
            $this->currentModel = null;
            return;
        }
    }
    
    /**
     * Save attribution model
     */
    private function saveModel(): void
    {
        $validatedData = $this->validateModelData($_POST);
        
        if ($this->hasErrors()) {
            return;
        }
        
        // Create or update model
        $model = $this->buildModelFromData($validatedData);
        $savedModel = $this->modelRepository->save($model);
        
        // Send Slack notification
        $action = $this->editing ? 'updated' : 'created';
        $this->sendSlackNotification('attribution_model_' . $action, [
            'model_name' => $savedModel->name,
            'model_type' => $savedModel->type->label()
        ]);
        
        $this->addSuccess("Attribution model {$action} successfully");
        
        // Redirect to avoid duplicate submission
        $this->redirect('tracking202/setup/attribution_models.php');
    }
    
    /**
     * Validate model data from POST
     */
    private function validateModelData(array $data): array
    {
        $rules = [
            'model_name' => ['type' => 'string', 'name' => 'Model name', 'min' => 1, 'max' => 100],
            'model_type' => ['type' => 'required', 'name' => 'Model type'],
            'is_active' => ['type' => 'integer', 'name' => 'Active status', 'min' => 0, 'max' => 1],
            'is_default' => ['type' => 'integer', 'name' => 'Default status', 'min' => 0, 'max' => 1]
        ];
        
        try {
            $validated = $this->validator->validateArray($data, $rules);
            
            // Validate model type
            $modelType = $this->validateModelType($validated['model_type']);
            $validated['model_type'] = $modelType;
            
            // Generate slug from name
            $slug = $this->generateSlug($validated['model_name']);
            $validated['model_slug'] = $slug;
            
            // Validate slug uniqueness
            $excludeId = $this->editing ? $this->currentModel?->modelId : null;
            $slugResult = $this->validator->validateUniqueSlug(
                $this->getUserId(), 
                '202_attribution_models', 
                $slug, 
                $excludeId, 
                'Model name'
            );
            
            if (!$slugResult->isValid) {
                throw new ValidationException('Validation failed', ['model_name' => $slugResult->getErrorMessage()]);
            }
            
            // Validate weighting configuration
            $validated['weighting_config'] = $this->validateWeightingConfig($modelType, $data);
            
            return $validated;
            
        } catch (ValidationException $e) {
            throw $e;
        }
    }
    
    /**
     * Validate model type
     */
    private function validateModelType(string $type): ModelType
    {
        try {
            return ModelType::from($type);
        } catch (\ValueError) {
            throw new ValidationException('Validation failed', ['model_type' => 'Invalid model type']);
        }
    }
    
    /**
     * Generate URL-safe slug from name
     */
    private function generateSlug(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', (string) $slug);
        return trim((string) $slug, '-');
    }
    
    /**
     * Validate weighting configuration based on model type
     */
    private function validateWeightingConfig(ModelType $type, array $data): array
    {
        $config = [];
        
        switch ($type) {
            case ModelType::TIME_DECAY:
                $halfLife = $this->validator->validateInteger(
                    $data['half_life_hours'] ?? '', 
                    'Half-life hours', 
                    1, 
                    8760 // Max 1 year
                );
                
                if (!$halfLife->isValid) {
                    throw new ValidationException('Validation failed', ['half_life_hours' => $halfLife->getErrorMessage()]);
                }
                
                $config['half_life_hours'] = $halfLife->getSanitizedValue();
                break;
                
            case ModelType::POSITION_BASED:
                $firstWeight = $this->validator->validateNumeric(
                    $data['first_touch_weight'] ?? '', 
                    'First touch weight', 
                    0.0, 
                    1.0
                );
                $lastWeight = $this->validator->validateNumeric(
                    $data['last_touch_weight'] ?? '', 
                    'Last touch weight', 
                    0.0, 
                    1.0
                );
                
                if (!$firstWeight->isValid) {
                    throw new ValidationException('Validation failed', ['first_touch_weight' => $firstWeight->getErrorMessage()]);
                }
                
                if (!$lastWeight->isValid) {
                    throw new ValidationException('Validation failed', ['last_touch_weight' => $lastWeight->getErrorMessage()]);
                }
                
                $firstValue = $firstWeight->getSanitizedValue();
                $lastValue = $lastWeight->getSanitizedValue();
                
                if (($firstValue + $lastValue) > 1.0) {
                    throw new ValidationException('Validation failed', ['first_touch_weight' => 'Combined weights cannot exceed 1.0']);
                }
                
                $config['first_touch_weight'] = $firstValue;
                $config['last_touch_weight'] = $lastValue;
                break;
                
            case ModelType::ALGORITHMIC:
                // For now, just store any additional config data
                if (!empty($data['algorithmic_config'])) {
                    $config['config'] = $data['algorithmic_config'];
                }
                break;
                
            case ModelType::LAST_TOUCH:
            case ModelType::ASSISTED:
                // No configuration needed
                break;
        }
        
        return $config;
    }
    
    /**
     * Build ModelDefinition from validated data
     */
    private function buildModelFromData(array $data): ModelDefinition
    {
        $now = time();
        
        return new ModelDefinition(
            modelId: $this->editing ? $this->currentModel?->modelId : null,
            userId: $this->getUserId(),
            name: $data['model_name'],
            slug: $data['model_slug'],
            type: $data['model_type'],
            weightingConfig: $data['weighting_config'],
            isActive: (bool)$data['is_active'],
            isDefault: (bool)$data['is_default'],
            createdAt: $this->editing ? $this->currentModel?->createdAt ?? $now : $now,
            updatedAt: $now
        );
    }
    
    /**
     * Delete model
     */
    private function deleteModel(): void
    {
        $modelId = (int)($_POST['model_id'] ?? 0);
        
        if ($modelId <= 0) {
            $this->addError('general', 'Invalid model ID');
            return;
        }
        
        $model = $this->modelRepository->findById($modelId);
        
        if (!$model || $model->userId !== $this->getUserId()) {
            $this->addError('general', 'Model not found or unauthorized');
            return;
        }
        
        if ($model->isDefault) {
            $this->addError('general', 'Cannot delete the default model');
            return;
        }
        
        $success = $this->modelRepository->delete($modelId);
        
        if ($success) {
            $this->sendSlackNotification('attribution_model_deleted', [
                'model_name' => $model->name
            ]);
            
            $this->addSuccess('Attribution model deleted successfully');
        } else {
            $this->addError('general', 'Failed to delete model');
        }
    }
    
    /**
     * Activate model
     */
    private function activateModel(): void
    {
        $this->toggleModelStatus(true);
    }
    
    /**
     * Deactivate model
     */
    private function deactivateModel(): void
    {
        $this->toggleModelStatus(false);
    }
    
    /**
     * Toggle model active status
     */
    private function toggleModelStatus(bool $active): void
    {
        $modelId = (int)($_POST['model_id'] ?? 0);
        
        if ($modelId <= 0) {
            $this->addError('general', 'Invalid model ID');
            return;
        }
        
        $model = $this->modelRepository->findById($modelId);
        
        if (!$model || $model->userId !== $this->getUserId()) {
            $this->addError('general', 'Model not found or unauthorized');
            return;
        }
        
        $updatedModel = new ModelDefinition(
            modelId: $model->modelId,
            userId: $model->userId,
            name: $model->name,
            slug: $model->slug,
            type: $model->type,
            weightingConfig: $model->weightingConfig,
            isActive: $active,
            isDefault: $model->isDefault,
            createdAt: $model->createdAt,
            updatedAt: time()
        );
        
        $this->modelRepository->save($updatedModel);
        
        $status = $active ? 'activated' : 'deactivated';
        $this->addSuccess("Attribution model {$status} successfully");
    }
    
    /**
     * Set model as default
     */
    private function setDefaultModel(): void
    {
        $modelId = (int)($_POST['model_id'] ?? 0);
        
        if ($modelId <= 0) {
            $this->addError('general', 'Invalid model ID');
            return;
        }
        
        $model = $this->modelRepository->findById($modelId);
        
        if (!$model || $model->userId !== $this->getUserId()) {
            $this->addError('general', 'Model not found or unauthorized');
            return;
        }
        
        $success = $this->modelRepository->setAsDefault($this->getUserId(), $modelId);
        
        if ($success) {
            $this->addSuccess('Default attribution model updated successfully');
        } else {
            $this->addError('general', 'Failed to update default model');
        }
    }
    
    /**
     * Render the attribution models page
     */
    private function renderPage(): void
    {
        // Get all models for this user
        $models = $this->modelRepository->findForUser($this->getUserId(), null, false);
        
        // Get available model types
        $modelTypes = ModelType::cases();
        
        // Prepare data for the template
        $pageData = [
            'models' => $models,
            'modelTypes' => $modelTypes,
            'editing' => $this->editing,
            'copying' => $this->copying,
            'currentModel' => $this->currentModel,
            'errors' => $this->errors,
            'successMessages' => $this->successMessages,
            'csrfToken' => $this->getCsrfToken(),
            'user' => $this->user,
            'userData' => $this->getUserData()
        ];
        
        // Include the template
        include __DIR__ . '/templates/attribution_models.php';
    }
}