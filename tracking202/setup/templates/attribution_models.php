<?php
declare(strict_types=1);

// Extract template data
$models = $pageData['models'] ?? [];
$modelTypes = $pageData['modelTypes'] ?? [];
$editing = $pageData['editing'] ?? false;
$copying = $pageData['copying'] ?? false;
$currentModel = $pageData['currentModel'] ?? null;
$errors = $pageData['errors'] ?? [];
$successMessages = $pageData['successMessages'] ?? [];
$csrfToken = $pageData['csrfToken'] ?? '';

// Set up selected values for form
$selected = [];
if ($currentModel) {
    $selected['model_name'] = $currentModel->name;
    $selected['model_type'] = $currentModel->type->value;
    $selected['is_active'] = $currentModel->isActive ? '1' : '0';
    $selected['is_default'] = $currentModel->isDefault ? '1' : '0';
    
    // Extract weighting config
    $config = $currentModel->weightingConfig;
    $selected['half_life_hours'] = $config['half_life_hours'] ?? '';
    $selected['first_touch_weight'] = $config['first_touch_weight'] ?? '';
    $selected['last_touch_weight'] = $config['last_touch_weight'] ?? '';
    $selected['algorithmic_config'] = $config['config'] ?? '';
} else {
    // Populate from POST if there were validation errors
    $selected['model_name'] = $_POST['model_name'] ?? '';
    $selected['model_type'] = $_POST['model_type'] ?? '';
    $selected['is_active'] = $_POST['is_active'] ?? '1';
    $selected['is_default'] = $_POST['is_default'] ?? '0';
    $selected['half_life_hours'] = $_POST['half_life_hours'] ?? '';
    $selected['first_touch_weight'] = $_POST['first_touch_weight'] ?? '';
    $selected['last_touch_weight'] = $_POST['last_touch_weight'] ?? '';
    $selected['algorithmic_config'] = $_POST['algorithmic_config'] ?? '';
}

template_top('Attribution Models - Setup');
?>

<?php include_once dirname(__DIR__) . '/_config/setup_nav.php'; ?>

<div class="attribution-setup">
<div class="row setup-header-row">
    <div class="col-xs-12">
        <div class="setup-page-header">
            <div class="setup-page-header__icon">
                <span class="glyphicon glyphicon-<?php echo $editing ? 'edit' : ($copying ? 'copy' : 'plus'); ?>"></span>
            </div>
            <div class="setup-page-header__text">
                <h1 class="setup-page-header__title">
                    <?php echo $editing ? 'Edit' : ($copying ? 'Copy' : 'Add'); ?> Attribution Model
                </h1>
                <p class="setup-page-header__subtitle">
                    Configure how conversions are attributed to different touchpoints in the customer journey.
                </p>
            </div>
        </div>
    </div>
</div>

<?php
// Display success messages
foreach ($successMessages as $message) {
    echo '<div class="alert alert-success">' . $message . '</div>';
}

// Display general errors
if (isset($errors['general'])) {
    echo '<div class="alert alert-danger">' . $errors['general'] . '</div>';
}
?>

<div class="row setup-content-row">
    <div class="col-xs-12 col-md-8">
        <div class="well setup-card">
            <form method="post" id="attribution-model-form">
                <?php echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">'; ?>
                <input type="hidden" name="action" value="save">
                
                <?php if ($editing && $currentModel): ?>
                    <input type="hidden" name="model_id" value="<?php echo $currentModel->modelId; ?>">
                <?php endif; ?>

            <div class="form-group">
                <label for="model_name">Model Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="model_name" name="model_name" 
                       value="<?php echo htmlspecialchars($selected['model_name']); ?>" 
                       placeholder="e.g., Time Decay - 7 Days" required
                       autocomplete="off" autocapitalize="words">
                <?php if (isset($errors['model_name'])): ?>
                    <div class="alert alert-danger" style="margin-top: 8px; padding: 8px 12px; font-size: 13px;">
                        <?php echo $errors['model_name']; ?>
                    </div>
                <?php endif; ?>
                <small class="form-text text-muted">A descriptive name for your attribution model</small>
            </div>

            <div class="form-group">
                <label for="model_type">Attribution Type <span class="text-danger">*</span></label>
                <select class="form-control" id="model_type" name="model_type" required onchange="toggleConfigFields()" 
                        aria-describedby="model-type-help">
                    <option value="">Select attribution type...</option>
                    <?php foreach ($modelTypes as $type): ?>
                        <option value="<?php echo $type->value; ?>" 
                                <?php echo $selected['model_type'] === $type->value ? 'selected' : ''; ?>
                                data-requires-config="<?php echo $type->requiresWeighting() ? 'true' : 'false'; ?>">
                            <?php echo htmlspecialchars($type->label()); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['model_type'])): ?>
                    <div class="alert alert-danger" style="margin-top: 8px; padding: 8px 12px; font-size: 13px;" role="alert">
                        <?php echo $errors['model_type']; ?>
                    </div>
                <?php endif; ?>
                <small id="model-type-help" class="form-text text-muted">Choose how you want to attribute conversions across touchpoints</small>
            </div>

            <!-- Model Type Descriptions -->
            <div id="model-descriptions">
                <div class="model-description" data-type="last_touch" style="display: none;">
                    <div class="alert alert-info">
                        <strong>Last Touch:</strong> 100% credit goes to the final touchpoint before conversion. Simple and commonly used.
                    </div>
                </div>
                
                <div class="model-description" data-type="assisted" style="display: none;">
                    <div class="alert alert-info">
                        <strong>Assisted Conversions:</strong> Shows the impact of all touchpoints that assisted in conversions, excluding the final converting touch.
                    </div>
                </div>
                
                <div class="model-description" data-type="time_decay" style="display: none;">
                    <div class="alert alert-info">
                        <strong>Time Decay:</strong> More recent touchpoints receive more credit. Credit decreases exponentially based on the half-life you specify.
                    </div>
                </div>
                
                <div class="model-description" data-type="position_based" style="display: none;">
                    <div class="alert alert-info">
                        <strong>Position Based:</strong> First and last touchpoints receive the weights you specify, remaining credit is distributed evenly among middle touches.
                    </div>
                </div>
                
                <div class="model-description" data-type="algorithmic" style="display: none;">
                    <div class="alert alert-info">
                        <strong>Algorithmic:</strong> Uses machine learning to determine the optimal credit distribution based on your conversion patterns.
                    </div>
                </div>
            </div>

            <!-- Time Decay Configuration -->
            <div id="time-decay-config" class="config-section" style="display: none;">
                <h6>Time Decay Configuration</h6>
                <div class="form-group">
                    <label for="half_life_hours">Half-life (Hours) <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" id="half_life_hours" name="half_life_hours" 
                           value="<?php echo htmlspecialchars($selected['half_life_hours']); ?>" 
                           min="1" max="8760" placeholder="168" 
                           inputmode="numeric" pattern="[0-9]*">
                    <?php if (isset($errors['half_life_hours'])): ?>
                        <div class="alert alert-danger" style="margin-top: 8px; padding: 8px 12px; font-size: 13px;">
                            <?php echo $errors['half_life_hours']; ?>
                        </div>
                    <?php endif; ?>
                    <small class="form-text text-muted">Time it takes for a touchpoint to lose half its attribution weight (e.g., 168 = 7 days)</small>
                </div>
            </div>

            <!-- Position Based Configuration -->
            <div id="position-based-config" class="config-section" style="display: none;">
                <h6>Position Based Configuration</h6>
                <div class="row">
                    <div class="col-xs-12 col-sm-6">
                        <div class="form-group">
                            <label for="first_touch_weight">First Touch Weight <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="first_touch_weight" name="first_touch_weight" 
                                   value="<?php echo htmlspecialchars($selected['first_touch_weight']); ?>" 
                                   min="0" max="1" step="0.01" placeholder="0.4"
                                   inputmode="decimal">
                            <?php if (isset($errors['first_touch_weight'])): ?>
                                <div class="alert alert-danger" style="margin-top: 8px; padding: 8px 12px; font-size: 13px;">
                                    <?php echo $errors['first_touch_weight']; ?>
                                </div>
                            <?php endif; ?>
                            <small class="form-text text-muted">Weight for first touchpoint (0.0 - 1.0)</small>
                        </div>
                    </div>
                    <div class="col-xs-12 col-sm-6">
                        <div class="form-group">
                            <label for="last_touch_weight">Last Touch Weight <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="last_touch_weight" name="last_touch_weight" 
                                   value="<?php echo htmlspecialchars($selected['last_touch_weight']); ?>" 
                                   min="0" max="1" step="0.01" placeholder="0.4"
                                   inputmode="decimal">
                            <?php if (isset($errors['last_touch_weight'])): ?>
                                <div class="alert alert-danger" style="margin-top: 8px; padding: 8px 12px; font-size: 13px;">
                                    <?php echo $errors['last_touch_weight']; ?>
                                </div>
                            <?php endif; ?>
                            <small class="form-text text-muted">Weight for last touchpoint (0.0 - 1.0)</small>
                        </div>
                    </div>
                </div>
                <div class="alert alert-info">
                    <small><strong>Note:</strong> Middle touchpoints will share the remaining credit equally. Combined weights must not exceed 1.0.</small>
                </div>
            </div>

            <!-- Algorithmic Configuration -->
            <div id="algorithmic-config" class="config-section" style="display: none;">
                <h6>Algorithmic Configuration</h6>
                <div class="form-group">
                    <label for="algorithmic_config">Configuration Parameters</label>
                    <textarea class="form-control" id="algorithmic_config" name="algorithmic_config" rows="3"
                              placeholder="Advanced configuration options..."><?php echo htmlspecialchars($selected['algorithmic_config']); ?></textarea>
                    <small class="form-text text-muted">Advanced configuration for algorithmic attribution (contact support for details)</small>
                </div>
            </div>

            <div class="row">
                <div class="col-xs-12 col-sm-6">
                    <div class="form-group">
                        <div class="checkbox">
                            <label>
                                <input type="checkbox" name="is_active" value="1" 
                                       <?php echo $selected['is_active'] === '1' ? 'checked' : ''; ?>>
                                <span class="checkbox-label">Active</span>
                            </label>
                        </div>
                        <small class="form-text text-muted">Active models can be used for attribution calculations</small>
                    </div>
                </div>
                <div class="col-xs-12 col-sm-6">
                    <div class="form-group">
                        <div class="checkbox">
                            <label>
                                <input type="checkbox" name="is_default" value="1" 
                                       <?php echo $selected['is_default'] === '1' ? 'checked' : ''; ?>>
                                <span class="checkbox-label">Set as Default</span>
                            </label>
                        </div>
                        <small class="form-text text-muted">Default model is used for new campaigns</small>
                    </div>
                </div>
            </div>

                <div class="form-group" style="margin-top: 25px; padding-top: 20px; border-top: 1px solid #ecf0f1;">
                    <div class="hidden-xs">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <span class="glyphicon glyphicon-<?php echo $editing ? 'floppy-disk' : 'plus'; ?>"></span>
                            <?php echo $editing ? 'Update' : 'Create'; ?> Attribution Model
                        </button>
                        <a href="<?php echo get_absolute_url(); ?>tracking202/setup/attribution_models.php" class="btn btn-default btn-lg" style="margin-left: 10px;">
                            <span class="glyphicon glyphicon-remove"></span>
                            Cancel
                        </a>
                    </div>
                    
                    <!-- Mobile button layout -->
                    <div class="visible-xs">
                        <div style="margin-bottom: 10px;">
                            <button type="submit" class="btn btn-primary btn-block btn-lg">
                                <span class="glyphicon glyphicon-<?php echo $editing ? 'floppy-disk' : 'plus'; ?>"></span>
                                <?php echo $editing ? 'Update' : 'Create'; ?> Attribution Model
                            </button>
                        </div>
                        <a href="<?php echo get_absolute_url(); ?>tracking202/setup/attribution_models.php" class="btn btn-default btn-block btn-lg">
                            <span class="glyphicon glyphicon-remove"></span>
                            Cancel
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="col-xs-12 col-md-4">
        <!-- Current Model Info (if editing) -->
        <?php if ($editing && $currentModel): ?>
        <div class="well setup-card setup-card--editing">
            <h6 style="color: #2c3e50; margin-bottom: 10px;">
                <span class="glyphicon glyphicon-edit" style="color: #3498db;"></span> Currently Editing
            </h6>
            <div style="margin: 12px 0;">
                <div style="font-weight: bold; font-size: 15px; color: #2c3e50; margin-bottom: 6px;">
                    <?php echo htmlspecialchars($currentModel->name); ?>
                </div>
                <div style="color: #34495e; font-size: 13px; margin-bottom: 8px;">
                    <strong>Type:</strong> <?php echo htmlspecialchars($currentModel->type->label()); ?>
                </div>
                <div style="margin-bottom: 4px;">
                    <?php if ($currentModel->isDefault): ?>
                        <span class="label label-success" style="font-size: 10px; margin-right: 4px;">Default</span>
                    <?php endif; ?>
                    <?php if ($currentModel->isActive): ?>
                        <span class="label label-success" style="font-size: 10px;">Active</span>
                    <?php else: ?>
                        <span class="label label-danger" style="font-size: 10px;">Inactive</span>
                    <?php endif; ?>
                </div>
            </div>
            <div style="border-top: 1px solid #ddd; padding-top: 10px;">
                <a href="?copy_model_id=<?php echo $currentModel->modelId; ?>" class="btn btn-sm btn-info">
                    <span class="glyphicon glyphicon-copy"></span> Copy This Model
                </a>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Model List -->
        <div class="well setup-card">
            <h6><span class="glyphicon glyphicon-list"></span> Your Attribution Models</h6>
            <?php if (empty($models)): ?>
                <div class="text-center" style="padding: 20px;">
                    <span class="glyphicon glyphicon-stats" style="font-size: 48px; color: #bdc3c7;"></span>
                    <p class="text-muted" style="margin-top: 10px;">No attribution models created yet.</p>
                    <small class="text-muted">Create your first model to get started with multi-touch attribution.</small>
                </div>
            <?php else: ?>
                <div class="model-list">
                    <?php foreach ($models as $model): ?>
                        <?php $isCurrentModel = ($editing && $currentModel && $model->modelId === $currentModel->modelId); ?>
                        <div class="model-item<?php echo $isCurrentModel ? ' model-item--active' : ''; ?>">
                            <div class="model-item__header">
                                <div class="model-item__title">
                                    <?php echo htmlspecialchars($model->name); ?>
                                    <?php if ($model->isDefault): ?>
                                        <span class="label label-success" style="font-size: 10px; vertical-align: middle; margin-left: 6px;">Default</span>
                                    <?php endif; ?>
                                </div>
                                <div class="model-item__type">
                                    <strong><?php echo htmlspecialchars($model->type->label()); ?></strong>
                                </div>
                                <div class="model-item__status">
                                    Status: 
                                    <?php if ($model->isActive): ?>
                                        <span style="color: #27ae60; font-weight: bold;">Active</span>
                                    <?php else: ?>
                                        <span style="color: #e74c3c; font-weight: bold;">Inactive</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="model-item__actions">
                                <div class="btn-group btn-group-xs hidden-xs">
                                    <a href="?edit_model_id=<?php echo $model->modelId; ?>" 
                                       class="btn <?php echo ($editing && $currentModel && $model->modelId === $currentModel->modelId) ? 'btn-primary' : 'btn-default'; ?> btn-sm" 
                                       title="Edit Model" style="margin-right: 4px;">
                                        <span class="glyphicon glyphicon-edit"></span> Edit
                                    </a>
                                    
                                    <?php if (!$model->isDefault): ?>
                                        <form method="post" style="display: inline-block; margin-right: 4px;" 
                                              onsubmit="return confirm('Are you sure you want to delete this model?');">
                                            <?php echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">'; ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="model_id" value="<?php echo $model->modelId; ?>">
                                            <button type="submit" class="btn btn-danger btn-sm" title="Delete Model">
                                                <span class="glyphicon glyphicon-trash"></span>
                                            </button>
                                        </form>
                                        
                                        <form method="post" style="display: inline-block;">
                                            <?php echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">'; ?>
                                            <input type="hidden" name="action" value="set_default">
                                            <input type="hidden" name="model_id" value="<?php echo $model->modelId; ?>">
                                            <button type="submit" class="btn btn-warning btn-sm" title="Set as Default">
                                                <span class="glyphicon glyphicon-star"></span>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Mobile button layout -->
                                <div class="visible-xs">
                                    <div style="margin-bottom: 8px;">
                                        <a href="?edit_model_id=<?php echo $model->modelId; ?>" 
                                           class="btn <?php echo ($editing && $currentModel && $model->modelId === $currentModel->modelId) ? 'btn-primary' : 'btn-default'; ?> btn-block btn-sm">
                                            <span class="glyphicon glyphicon-edit"></span> Edit Model
                                        </a>
                                    </div>
                                    
                                    <?php if (!$model->isDefault): ?>
                                        <div class="row">
                                            <div class="col-xs-6">
                                                <form method="post" onsubmit="return confirm('Are you sure you want to delete this model?');">
                                                    <?php echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">'; ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="model_id" value="<?php echo $model->modelId; ?>">
                                                    <button type="submit" class="btn btn-danger btn-block btn-sm">
                                                        <span class="glyphicon glyphicon-trash"></span> Delete
                                                    </button>
                                                </form>
                                            </div>
                                            <div class="col-xs-6">
                                                <form method="post">
                                                    <?php echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">'; ?>
                                                    <input type="hidden" name="action" value="set_default">
                                                    <input type="hidden" name="model_id" value="<?php echo $model->modelId; ?>">
                                                    <button type="submit" class="btn btn-warning btn-block btn-sm">
                                                        <span class="glyphicon glyphicon-star"></span> Default
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="well setup-card">
            <h6>Quick Guide</h6>
            <ul>
                <li><strong>Last Touch:</strong> Best for direct response campaigns</li>
                <li><strong>Time Decay:</strong> Good for longer sales cycles</li>
                <li><strong>Position Based:</strong> Balances awareness and conversion</li>
                <li><strong>Assisted:</strong> Shows support touchpoints</li>
                <li><strong>Algorithmic:</strong> Data-driven optimization</li>
            </ul>
        </div>
</div>
</div>
</div>

<script>
function toggleConfigFields() {
    const modelType = document.getElementById('model_type').value;
    
    // Hide all config sections
    document.querySelectorAll('.config-section').forEach(section => {
        section.style.display = 'none';
    });
    
    // Hide all descriptions
    document.querySelectorAll('.model-description').forEach(desc => {
        desc.style.display = 'none';
    });
    
    // Show relevant sections
    if (modelType) {
        // Show description
        const desc = document.querySelector(`[data-type="${modelType}"]`);
        if (desc) {
            desc.style.display = 'block';
        }
        
        // Show config if needed
        if (modelType === 'time_decay') {
            document.getElementById('time-decay-config').style.display = 'block';
        } else if (modelType === 'position_based') {
            document.getElementById('position-based-config').style.display = 'block';
        } else if (modelType === 'algorithmic') {
            document.getElementById('algorithmic-config').style.display = 'block';
        }
    }
}

// Initialize form on page load
document.addEventListener('DOMContentLoaded', function() {
    toggleConfigFields();
    
    // Add mobile-friendly touch feedback
    const buttons = document.querySelectorAll('.btn');
    buttons.forEach(button => {
        button.addEventListener('touchstart', function() {
            this.style.opacity = '0.7';
        });
        button.addEventListener('touchend', function() {
            this.style.opacity = '1';
        });
    });
    
    // Improve select dropdown on mobile
    const modelTypeSelect = document.getElementById('model_type');
    if (modelTypeSelect && /Mobi|Android/i.test(navigator.userAgent)) {
        modelTypeSelect.setAttribute('size', '1');
    }
});

// Prevent zoom on iOS when focusing form inputs
if (/iPad|iPhone|iPod/.test(navigator.userAgent)) {
    document.addEventListener('DOMContentLoaded', function() {
        const inputs = document.querySelectorAll('input, select, textarea');
        inputs.forEach(input => {
            if (parseFloat(getComputedStyle(input).fontSize) < 16) {
                input.style.fontSize = '16px';
            }
        });
    });
}

// Add real-time validation for position-based weights
document.getElementById('first_touch_weight')?.addEventListener('input', validatePositionWeights);
document.getElementById('last_touch_weight')?.addEventListener('input', validatePositionWeights);

function validatePositionWeights() {
    const firstWeight = parseFloat(document.getElementById('first_touch_weight').value) || 0;
    const lastWeight = parseFloat(document.getElementById('last_touch_weight').value) || 0;
    const total = firstWeight + lastWeight;
    
    const warningDiv = document.getElementById('weight-warning');
    if (warningDiv) {
        warningDiv.remove();
    }
    
    if (total > 1.0) {
        const warning = document.createElement('div');
        warning.id = 'weight-warning';
        warning.className = 'alert alert-warning';
        warning.style.marginTop = '8px';
        warning.innerHTML = '<small><strong>Warning:</strong> Combined weights exceed 1.0. Remaining weight for middle touches will be negative.</small>';
        document.getElementById('position-based-config').appendChild(warning);
    } else if (total < 1.0 && (firstWeight > 0 || lastWeight > 0)) {
        const info = document.createElement('div');
        info.id = 'weight-warning';
        info.className = 'alert alert-success';
        info.style.marginTop = '8px';
        info.innerHTML = `<small><strong>Info:</strong> Middle touches will share ${(1.0 - total).toFixed(2)} credit equally.</small>`;
        document.getElementById('position-based-config').appendChild(info);
    }
}
</script>

<style>
/* Attribution Models - Mobile Responsive Styles */

/* Base styles for all devices */
.attribution-setup .setup-page-header {
    display: flex;
    align-items: center;
    gap: 20px;
    border-radius: 10px;
    border: 1px solid #dfe6ee;
    background: linear-gradient(135deg, #ffffff 0%, #f6fbff 100%);
    padding: 22px 26px;
    box-shadow: 0 6px 16px rgba(46, 134, 222, 0.08);
    margin-bottom: 25px;
}

.attribution-setup .setup-page-header__icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: linear-gradient(135deg, rgba(52, 152, 219, 0.18) 0%, rgba(41, 128, 185, 0.28) 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #2c82c9;
    font-size: 26px;
    flex-shrink: 0;
}

.attribution-setup .setup-page-header__text {
    flex: 1 1 auto;
}

.attribution-setup .setup-page-header__title {
    margin: 0;
    font-size: 26px;
    font-weight: 600;
    color: #1f3b57;
}

.attribution-setup .setup-page-header__subtitle {
    margin: 10px 0 0;
    font-size: 15px;
    color: #61738c;
    line-height: 1.5;
}

.attribution-setup .setup-card {
    border-radius: 6px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border: 1px solid #e1e8ed;
}

.attribution-setup .model-item {
    border: 1px solid #e1e8ed;
    border-radius: 4px;
    padding: 15px;
    margin-bottom: 12px;
    background: #fff;
    transition: all 0.2s ease;
}

.attribution-setup .model-item:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    border-color: #3498db;
}

.attribution-setup .model-item--active {
    border-color: #3498db;
    background: #f8f9fa;
}

.attribution-setup .model-item__header {
    margin-bottom: 12px;
}

.attribution-setup .model-item__title {
    font-size: 16px;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 4px;
}

.attribution-setup .model-item__type {
    font-size: 13px;
    color: #7f8c8d;
    margin-bottom: 6px;
}

.attribution-setup .model-item__status {
    font-size: 12px;
    color: #95a5a6;
}

/* Navigation improvements */
.nav-pills > li > a {
    border-radius: 4px;
    margin-right: 4px;
    margin-bottom: 8px;
    transition: all 0.2s ease;
}

/* Form improvements */
.form-control {
    border-radius: 4px;
    border: 1px solid #d1d9e0;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.form-control:focus {
    border-color: #3498db;
    box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.1);
}

/* Alert improvements */
.alert {
    border-radius: 4px;
    border: none;
}

/* Mobile optimizations */
@media (max-width: 767px) {
    /* Override fixed container width for mobile */
    body .container {
        width: 100% !important;
        min-width: auto !important;
        max-width: 100% !important;
        padding-left: 15px;
        padding-right: 15px;
    }
    
    /* Navigation responsive */
    .nav-pills {
        margin-bottom: 15px;
    }
    
    .nav-pills > li {
        float: none;
        display: block;
        width: 100%;
        margin-bottom: 5px;
    }
    
    .nav-pills > li > a {
        display: block;
        text-align: center;
        margin-right: 0;
        padding: 12px 15px;
        font-size: 14px;
    }
    
    /* Page header improvements */
    .attribution-setup .setup-page-header {
        flex-direction: column;
        align-items: center;
        text-align: center;
        padding: 20px 18px;
        gap: 14px;
        margin-bottom: 18px;
    }

    .attribution-setup .setup-page-header__icon {
        width: 56px;
        height: 56px;
        font-size: 22px;
    }

    .attribution-setup .setup-page-header__title {
        font-size: 22px;
        line-height: 1.35;
    }

    .attribution-setup .setup-page-header__subtitle {
        font-size: 14px;
        line-height: 1.45;
        margin-top: 4px;
    }
    
    /* Form improvements for mobile */
    .attribution-setup .well {
        margin-bottom: 20px;
        padding: 20px 15px;
    }
    
    .attribution-setup .form-control {
        min-height: 44px; /* Touch-friendly */
        font-size: 16px; /* Prevents iOS zoom */
        padding: 12px 15px;
    }
    
    .attribution-setup .form-group {
        margin-bottom: 20px;
    }
    
    .attribution-setup .form-group label {
        font-size: 14px;
        font-weight: 600;
        margin-bottom: 8px;
        display: block;
    }
    
    .attribution-setup .form-text {
        margin-top: 8px;
        font-size: 13px;
        line-height: 1.4;
    }
    
    /* Button improvements */
    .attribution-setup .btn {
        min-height: 44px;
        font-size: 14px;
        font-weight: 500;
        border-radius: 4px;
    }
    
    .attribution-setup .btn-block + .btn-block {
        margin-top: 10px;
    }
    
    /* Model list optimizations */
    .attribution-setup .model-item {
        margin-bottom: 20px;
        padding: 15px;
    }
    
    .attribution-setup .model-item__title {
        font-size: 15px;
        margin-bottom: 6px;
    }
    
    .attribution-setup .model-item__type {
        font-size: 13px;
        margin-bottom: 8px;
    }
    
    .attribution-setup .model-item__status {
        font-size: 12px;
        margin-bottom: 12px;
    }
    
    .attribution-setup .model-item__actions {
        margin-top: 12px;
    }
    
    /* Configuration sections */
    .attribution-setup .config-section {
        margin-top: 20px;
        padding-top: 20px;
        border-top: 1px solid #e1e8ed;
    }
    
    .attribution-setup .config-section h6 {
        font-size: 15px;
        font-weight: 600;
        color: #2c3e50;
        margin-bottom: 15px;
    }
    
    /* Alert adjustments */
    .attribution-setup .alert {
        padding: 12px 15px;
        margin-bottom: 15px;
        font-size: 13px;
        line-height: 1.4;
    }
    
    /* Checkbox and radio improvements */
    .attribution-setup input[type="checkbox"],
    .attribution-setup input[type="radio"] {
        transform: scale(1.3);
        margin-right: 10px;
        margin-top: 2px;
    }
    
    .attribution-setup .checkbox label {
        display: flex;
        align-items: flex-start;
        min-height: 44px;
        padding: 8px 0;
        font-size: 14px;
        font-weight: 500;
    }
    
    .attribution-setup .checkbox-label {
        margin-left: 8px;
        line-height: 1.4;
    }
    
    /* Improve touch targets for mobile */
    .attribution-setup .checkbox {
        margin-bottom: 8px;
    }
    
    /* Better error display on mobile */
    .attribution-setup .alert[role="alert"] {
        border-left: 4px solid #e74c3c;
    }
}

/* Small mobile devices (phones in portrait) */
@media (max-width: 480px) {
    .attribution-setup .well {
        padding: 15px 12px;
        margin-left: -5px;
        margin-right: -5px;
    }
    
    .attribution-setup .setup-page-header__title {
        font-size: 20px;
    }
    
    .attribution-setup .setup-page-header__subtitle {
        font-size: 13px;
    }
    
    .attribution-setup .model-item {
        padding: 12px;
        margin-left: -3px;
        margin-right: -3px;
    }
    
    .attribution-setup .btn-sm {
        font-size: 12px;
        padding: 6px 10px;
        min-height: 36px;
    }
    
    .attribution-setup .form-control {
        font-size: 16px; /* Maintain to prevent zoom */
        padding: 10px 12px;
    }
    
    /* Tighter spacing for very small screens */
    .attribution-setup .form-group {
        margin-bottom: 18px;
    }
    
    .attribution-setup .config-section {
        margin-top: 18px;
        padding-top: 18px;
    }
}

/* Landscape mobile phones */
@media (max-width: 767px) and (orientation: landscape) {
    .attribution-setup .nav-pills > li {
        display: inline-block;
        width: auto;
        float: left;
    }
    
    .attribution-setup .nav-pills > li > a {
        padding: 10px 12px;
        font-size: 13px;
        margin-right: 5px;
    }
}

/* Tablet adjustments */
@media (min-width: 768px) and (max-width: 991px) {
    body .container {
        width: 100% !important;
        min-width: auto !important;
        max-width: 100% !important;
        padding-left: 20px;
        padding-right: 20px;
    }
    
    .attribution-setup .col-md-8 {
        width: 66.66666667%;
    }
    
    .attribution-setup .col-md-4 {
        width: 33.33333333%;
    }
    
    .attribution-setup .form-control {
        font-size: 14px;
    }
    
    .attribution-setup .btn {
        font-size: 13px;
    }
}

/* Large tablets and small desktops */
@media (min-width: 992px) and (max-width: 1199px) {
    body .container {
        width: 100% !important;
        min-width: auto !important;
        max-width: 1170px !important;
        margin: 0 auto;
        padding-left: 15px;
        padding-right: 15px;
    }
}

/* Desktop improvements */
@media (min-width: 1200px) {
    body .container {
        width: 100% !important;
        min-width: auto !important;
        max-width: 1400px !important;
        margin: 0 auto;
        padding-left: 15px;
        padding-right: 15px;
    }
    
    .attribution-setup .model-item:hover {
        transform: translateY(-1px);
    }
}

/* Print styles */
@media print {
    .attribution-setup .btn,
    .attribution-setup .model-item__actions {
        display: none;
    }
    
    .attribution-setup .well {
        box-shadow: none;
        border: 1px solid #ccc;
    }
}
</style>

<?php template_bottom(); ?>
