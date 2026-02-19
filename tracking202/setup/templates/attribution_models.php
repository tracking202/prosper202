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
<link rel="stylesheet" href="/202-css/design-system.css">


<div class="attribution-setup">
<!-- Page Header - Design System (Blue Gradient) -->
<div class="row" style="margin-bottom: 28px;">
    <div class="col-xs-12">
        <div class="setup-page-header">
            <div class="setup-page-header__icon">
                <span class="glyphicon glyphicon-stats"></span>
            </div>
            <div class="setup-page-header__text">
                <h1 class="setup-page-header__title">Attribution Models</h1>
                <p class="setup-page-header__subtitle">Configure how conversions are attributed to touchpoints</p>
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
                       value="<?php echo htmlspecialchars((string) $selected['model_name']); ?>" 
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
                            <?php echo htmlspecialchars((string) $type->label()); ?>
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
                              placeholder="Advanced configuration options..."><?php echo htmlspecialchars((string) $selected['algorithmic_config']); ?></textarea>
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
        <div class="well setup-card setup-card--editing setup-side-panel">
            <h6 style="color: #2c3e50; margin-bottom: 10px;">
                <span class="glyphicon glyphicon-edit" style="color: #3498db;"></span> Currently Editing
            </h6>
            <div style="margin: 12px 0;">
                <div style="font-weight: bold; font-size: 15px; color: #2c3e50; margin-bottom: 6px;">
                    <?php echo htmlspecialchars((string) $currentModel->name); ?>
                </div>
                <div style="color: #34495e; font-size: 13px; margin-bottom: 8px;">
                    <strong>Type:</strong> <?php echo htmlspecialchars((string) $currentModel->type->label()); ?>
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
        <div class="well setup-card setup-side-panel">
            <h6><span class="glyphicon glyphicon-list"></span> Your Attribution Models</h6>
            <?php if (empty($models)): ?>
                <div class="text-center" style="padding: 20px;">
                    <span class="glyphicon glyphicon-stats" style="font-size: 48px; color: #bdc3c7;"></span>
                    <p class="text-muted" style="margin-top: 10px;">No attribution models created yet.</p>
                    <small class="text-muted">Create your first model to get started with multi-touch attribution.</small>
                </div>
            <?php else: ?>
                <ul class="setup-list">
                    <?php foreach ($models as $model): ?>
                        <?php $isCurrentModel = ($editing && $currentModel && $model->modelId === $currentModel->modelId); ?>
                        <li class="setup-list-item<?php echo $isCurrentModel ? ' setup-list-item--active' : ''; ?>">
                            <span class="setup-list-name">
                                <span class="filter_model_name">
                                    <?php echo htmlspecialchars((string) $model->name); ?>
                                    <?php if ($model->isDefault): ?>
                                        <span class="label label-success" style="font-size: 10px; margin-left: 6px;">Default</span>
                                    <?php endif; ?>
                                    <div class="setup-list-meta">
                                        <strong><?php echo htmlspecialchars((string) $model->type->label()); ?></strong>
                                        <span class="setup-list-status">
                                            <?php if ($model->isActive): ?>
                                                <span style="color: #27ae60; font-weight: bold;">Active</span>
                                            <?php else: ?>
                                                <span style="color: #e74c3c; font-weight: bold;">Inactive</span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </span>
                            </span>
                            <span class="setup-list-actions">
                                <a href="?edit_model_id=<?php echo $model->modelId; ?>"
                                   class="action-edit"
                                   title="Edit Model">
                                    <span class="glyphicon glyphicon-edit"></span> edit
                                </a>

                                <?php if (!$model->isDefault): ?>
                                    <form method="post" class="setup-list-inline-form"
                                          onsubmit="return confirmSubmit('Are you sure you want to delete this model?');">
                                        <?php echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">'; ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="model_id" value="<?php echo $model->modelId; ?>">
                                        <button type="submit" class="action-remove"
                                                onclick="return confirmSubmit('Are you sure you want to delete this model?');">
                                            <span class="glyphicon glyphicon-trash"></span> remove
                                        </button>
                                    </form>

                                    <form method="post" class="setup-list-inline-form">
                                        <?php echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">'; ?>
                                        <input type="hidden" name="action" value="set_default">
                                        <input type="hidden" name="model_id" value="<?php echo $model->modelId; ?>">
                                        <button type="submit" class="action-default"
                                                title="Set as Default">
                                            <span class="glyphicon glyphicon-star"></span> default
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="well setup-card setup-side-panel">
            <h6>Quick Guide</h6>
            <ul class="setup-list">
                <li>
                    <span class="setup-list-name"><span class="guide-item">Last Touch</span></span>
                    <span class="setup-list-actions"><span class="guide-description">Best for direct response campaigns</span></span>
                </li>
                <li>
                    <span class="setup-list-name"><span class="guide-item">Time Decay</span></span>
                    <span class="setup-list-actions"><span class="guide-description">Good for longer sales cycles</span></span>
                </li>
                <li>
                    <span class="setup-list-name"><span class="guide-item">Position Based</span></span>
                    <span class="setup-list-actions"><span class="guide-description">Balances awareness and conversion</span></span>
                </li>
                <li>
                    <span class="setup-list-name"><span class="guide-item">Assisted</span></span>
                    <span class="setup-list-actions"><span class="guide-description">Shows support touchpoints</span></span>
                </li>
                <li>
                    <span class="setup-list-name"><span class="guide-item">Algorithmic</span></span>
                    <span class="setup-list-actions"><span class="guide-description">Data-driven optimization</span></span>
                </li>
            </ul>
        </div>
</div>
</div>
</div>

<script>
function confirmSubmit(message) {
    return confirm(message);
}

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
/* Setup Page Header */
.setup-page-header {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 24px;
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    border-radius: 12px;
    color: #fff;
    box-shadow: 0 4px 15px rgba(0, 123, 255, 0.2);
}
.setup-page-header__icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 56px;
    height: 56px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    flex-shrink: 0;
}
.setup-page-header__icon .glyphicon {
    font-size: 28px;
}
.setup-page-header__text {
    flex: 1;
}
.setup-page-header__title {
    margin: 0 0 4px 0;
    font-size: 24px;
    font-weight: 600;
    color: #fff;
}
.setup-page-header__subtitle {
    margin: 0;
    font-size: 14px;
    color: rgba(255, 255, 255, 0.85);
}

/* Enhanced Panel Styling */
.panel {
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    border: 1px solid #e2e8f0;
}
.panel-heading {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%) !important;
    border-bottom: 1px solid #e2e8f0;
    border-radius: 12px 12px 0 0 !important;
    padding: 16px 20px;
}
.panel-title {
    font-weight: 600;
    font-size: 15px;
    color: #1e293b;
}
.panel-body {
    padding: 24px;
}

/* Form Enhancements */
.form-control {
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    padding: 10px 14px;
    transition: all 0.2s ease;
}
.form-control:focus {
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.15);
}

/* Button Enhancements */
.btn-primary {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    border: none;
    border-radius: 8px;
    padding: 10px 20px;
    font-weight: 600;
    box-shadow: 0 4px 12px rgba(0, 123, 255, 0.25);
    transition: all 0.2s ease;
}
.btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(0, 123, 255, 0.35);
}
.btn-success {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    border: none;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
}
.btn-danger {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    border: none;
    border-radius: 8px;
}

/* Table Enhancements */
.table {
    border-radius: 8px;
    overflow: hidden;
}
.table > thead > tr > th {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-bottom: 2px solid #e2e8f0;
    font-weight: 600;
    color: #475569;
    text-transform: uppercase;
    font-size: 12px;
    letter-spacing: 0.5px;
    padding: 14px 16px;
}
.table > tbody > tr > td {
    padding: 14px 16px;
    vertical-align: middle;
    border-color: #f1f5f9;
}
.table > tbody > tr:hover {
    background-color: #f8fafc;
}

/* Setup List Styling */
.setup-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.setup-list-item {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    padding: 14px 16px;
    border-bottom: 1px solid #f1f5f9;
    transition: background-color 0.2s ease;
}

.setup-list-item:last-child {
    border-bottom: none;
}

.setup-list-item:hover {
    background-color: #f8fafc;
}

.setup-list-item--active {
    background-color: #e7f3ff;
    border-left: 4px solid #007bff;
    padding-left: 12px;
}

.setup-list-name {
    flex: 1;
    display: flex;
    align-items: center;
    min-width: 0;
}

.filter_model_name,
.guide-item {
    display: block;
    word-break: break-word;
    overflow-wrap: break-word;
}

.setup-list-meta {
    display: block;
    font-size: 12px;
    color: #64748b;
    margin-top: 4px;
}

.setup-list-status {
    display: inline-block;
    margin-left: 8px;
    font-weight: bold;
}

.setup-list-actions {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
    justify-content: flex-end;
    margin-left: 12px;
    flex-shrink: 0;
}

.action-edit,
.action-remove,
.action-default {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 500;
    text-decoration: none;
    cursor: pointer;
    border: 1px solid #d1d5db;
    background-color: #f3f4f6;
    color: #374151;
    transition: all 0.2s ease;
    white-space: nowrap;
}

.action-edit {
    border-color: #93c5fd;
    background-color: #eff6ff;
    color: #1e40af;
}

.action-edit:hover {
    background-color: #dbeafe;
    border-color: #60a5fa;
}

.action-remove {
    border-color: #fca5a5;
    background-color: #fef2f2;
    color: #991b1b;
}

.action-remove:hover {
    background-color: #fee2e2;
    border-color: #f87171;
}

.action-default {
    border-color: #fcd34d;
    background-color: #fffbeb;
    color: #92400e;
}

.action-default:hover {
    background-color: #fef3c7;
    border-color: #fbbf24;
}

.setup-list-inline-form {
    display: inline;
}

.guide-description {
    display: none;
}

/* Responsive */
@media (max-width: 768px) {
    .setup-page-header {
        flex-direction: column;
        text-align: center;
        padding: 20px;
    }
    .setup-page-header__title {
        font-size: 20px;
    }

    .setup-list-item {
        flex-direction: column;
        align-items: flex-start;
    }

    .setup-list-actions {
        width: 100%;
        justify-content: flex-start;
        margin-top: 10px;
        margin-left: 0;
    }

    .guide-description {
        display: inline;
        font-size: 12px;
        color: #64748b;
        margin-left: 6px;
    }
}
</style>

<?php template_bottom(); ?>
