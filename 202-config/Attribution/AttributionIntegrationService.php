<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

use Prosper202\Attribution\Repository\ModelRepositoryInterface;
use mysqli;

/**
 * Service for integrating attribution models with existing Prosper202 features
 */
class AttributionIntegrationService
{
    public function __construct(
        private readonly ModelRepositoryInterface $modelRepository,
        private readonly mysqli $db
    ) {
    }
    
    /**
     * Get attribution models for a user formatted for HTML select options
     */
    public function getModelOptionsForUser(int $userId, ?int $selectedModelId = null): string
    {
        $models = $this->modelRepository->findForUser($userId, null, true);
        $defaultModel = $this->modelRepository->findDefaultForUser($userId);
        
        $options = '<option value="">Use Default Attribution Model</option>';
        
        foreach ($models as $model) {
            $isSelected = '';
            
            // Select the model if it matches the provided ID, or if no ID provided and this is the default
            if ($selectedModelId !== null && $model->modelId === $selectedModelId) {
                $isSelected = 'selected';
            } elseif ($selectedModelId === null && $model->isDefault) {
                $isSelected = 'selected';
            }
            
            $label = htmlspecialchars($model->name);
            if ($model->isDefault) {
                $label .= ' (Default)';
            }
            
            $options .= sprintf(
                '<option value="%d" %s data-type="%s">%s</option>',
                $model->modelId,
                $isSelected,
                $model->type->value,
                $label
            );
        }
        
        return $options;
    }
    
    /**
     * Get default attribution model ID for a user
     */
    public function getDefaultModelIdForUser(int $userId): ?int
    {
        $defaultModel = $this->modelRepository->findDefaultForUser($userId);
        return $defaultModel ? $defaultModel->modelId : null;
    }
    
    /**
     * Get attribution model name by ID
     */
    public function getModelName(int $modelId): ?string
    {
        $model = $this->modelRepository->findById($modelId);
        return $model ? $model->name : null;
    }
    
    /**
     * Update campaign's attribution model
     */
    public function updateCampaignAttributionModel(int $campaignId, ?int $modelId, int $userId): bool
    {
        // Verify user owns the campaign
        $campaignSql = "SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_id = ? AND user_id = ? LIMIT 1";
        $stmt = $this->db->prepare($campaignSql);
        $stmt->bind_param('ii', $campaignId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if (!$result || $result->num_rows === 0) {
            return false;
        }
        $stmt->close();
        
        // Verify model ownership if model is specified
        if ($modelId !== null) {
            $model = $this->modelRepository->findById($modelId);
            if (!$model || $model->userId !== $userId) {
                return false;
            }
        }
        
        // Update the campaign
        $updateSql = "UPDATE 202_aff_campaigns SET attribution_model_id = ? WHERE aff_campaign_id = ? AND user_id = ? LIMIT 1";
        $stmt = $this->db->prepare($updateSql);
        $stmt->bind_param('iii', $modelId, $campaignId, $userId);
        $stmt->execute();
        $success = $stmt->affected_rows > 0;
        $stmt->close();
        
        return $success;
    }
    
    /**
     * Get campaigns using a specific attribution model
     */
    public function getCampaignsUsingModel(int $modelId, int $userId): array
    {
        $sql = "SELECT aff_campaign_id, aff_campaign_name 
                FROM 202_aff_campaigns 
                WHERE attribution_model_id = ? AND user_id = ? 
                ORDER BY aff_campaign_name";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ii', $modelId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $campaigns = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $campaigns[] = [
                    'id' => (int)$row['aff_campaign_id'],
                    'name' => (string)$row['aff_campaign_name']
                ];
            }
        }
        $stmt->close();
        
        return $campaigns;
    }
    
    /**
     * Get attribution model statistics for a user
     */
    public function getAttributionModelStats(int $userId): array
    {
        $sql = "SELECT 
                    am.model_id,
                    am.model_name,
                    am.model_type,
                    am.is_default,
                    COUNT(ac.aff_campaign_id) as campaign_count
                FROM 202_attribution_models am
                LEFT JOIN 202_aff_campaigns ac ON am.model_id = ac.attribution_model_id
                WHERE am.user_id = ? AND am.is_active = 1
                GROUP BY am.model_id, am.model_name, am.model_type, am.is_default
                ORDER BY am.is_default DESC, am.model_name ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $stats = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $stats[] = [
                    'model_id' => (int)$row['model_id'],
                    'name' => (string)$row['model_name'],
                    'type' => (string)$row['model_type'],
                    'is_default' => (bool)$row['is_default'],
                    'campaign_count' => (int)$row['campaign_count']
                ];
            }
        }
        $stmt->close();
        
        return $stats;
    }
    
    /**
     * Safely delete an attribution model (prevents deletion if in use)
     */
    public function safeDeleteModel(int $modelId, int $userId): array
    {
        $model = $this->modelRepository->findById($modelId);
        
        if (!$model || $model->userId !== $userId) {
            return ['success' => false, 'error' => 'Model not found or unauthorized'];
        }
        
        if ($model->isDefault) {
            return ['success' => false, 'error' => 'Cannot delete the default attribution model'];
        }
        
        // Check if any campaigns are using this model
        $campaigns = $this->getCampaignsUsingModel($modelId, $userId);
        
        if (!empty($campaigns)) {
            $campaignNames = array_column($campaigns, 'name');
            return [
                'success' => false, 
                'error' => 'Model is in use by campaigns: ' . implode(', ', $campaignNames),
                'campaigns' => $campaigns
            ];
        }
        
        // Safe to delete
        $success = $this->modelRepository->delete($modelId);
        
        return [
            'success' => $success,
            'error' => $success ? null : 'Failed to delete model'
        ];
    }
}