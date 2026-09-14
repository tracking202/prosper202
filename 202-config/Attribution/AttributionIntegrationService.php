<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

use Prosper202\Attribution\Repository\ModelRepositoryInterface;
use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;
use mysqli;

/**
 * Service for integrating attribution models with existing Prosper202 features
 */
class AttributionIntegrationService
{
    /**
     * Checked wrapper over $db. Every statement here used to run
     * prepare/bind/execute by hand with none of the three results checked,
     * which mattered most in getCampaignsUsingModel(): a failed execute left
     * get_result() false, the row loop never ran, and the method answered
     * "no campaigns use this model" — the answer safeDeleteModel() treats as
     * permission to delete it. Connection throws QueryException instead, so
     * "I could not find out" can no longer arrive as "nothing found".
     */
    private readonly Connection $conn;

    public function __construct(
        private readonly ModelRepositoryInterface $modelRepository,
        private readonly mysqli $db
    ) {
        $this->conn = new Connection($this->db);
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
        // Verify user owns the campaign. This is an authorization check, so a
        // failed statement must not resolve to either answer: the old code
        // returned false, which reads as "you do not own it" and is at least
        // fail-closed, but it made a database fault indistinguishable from a
        // denial for the caller too. QueryException says which.
        $campaignSql = "SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_id = ? AND user_id = ? LIMIT 1";
        $stmt = $this->conn->prepareRead($campaignSql);
        $this->conn->bind($stmt, 'ii', [$campaignId, $userId]);
        $owned = $this->conn->fetchOne($stmt);

        if ($owned === null) {
            return false;
        }

        // Verify model ownership if model is specified
        if ($modelId !== null) {
            $model = $this->modelRepository->findById($modelId);
            if (!$model || $model->userId !== $userId) {
                return false;
            }
        }

        // Update the campaign
        $updateSql = "UPDATE 202_aff_campaigns SET attribution_model_id = ? WHERE aff_campaign_id = ? AND user_id = ? LIMIT 1";
        $stmt = $this->conn->prepareWrite($updateSql);
        $this->conn->bind($stmt, 'iii', [$modelId, $campaignId, $userId]);

        // Still false when the row already held this model — zero affected
        // rows genuinely means "nothing changed". What it no longer means is
        // that the UPDATE failed: affected_rows was -1 in that case, and
        // -1 > 0 reported the same false.
        return $this->conn->executeUpdate($stmt) > 0;
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
        
        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, 'ii', [$modelId, $userId]);

        $campaigns = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $campaigns[] = [
                'id' => (int)$row['aff_campaign_id'],
                'name' => (string)$row['aff_campaign_name']
            ];
        }

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
        
        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, 'i', [$userId]);

        $stats = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $stats[] = [
                'model_id' => (int)$row['model_id'],
                'name' => (string)$row['model_name'],
                'type' => (string)$row['model_type'],
                'is_default' => (bool)$row['is_default'],
                'campaign_count' => (int)$row['campaign_count']
            ];
        }

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
        
        // Check if any campaigns are using this model. An unreadable answer is
        // not an empty one: before the lookup was checked, a failed statement
        // returned [] here and the model was deleted out from under the
        // campaigns still pointing at it. Refuse instead — this method's whole
        // promise is in its name.
        try {
            $campaigns = $this->getCampaignsUsingModel($modelId, $userId);
        } catch (QueryException $e) {
            error_log('Attribution: cannot check model ' . $modelId . ' for use before delete: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Could not check whether the model is in use; nothing was deleted'];
        }

        if (!empty($campaigns)) {
            $campaignNames = array_column($campaigns, 'name');
            return [
                'success' => false, 
                'error' => 'Model is in use by campaigns: ' . implode(', ', $campaignNames),
                'campaigns' => $campaigns
            ];
        }
        
        // Safe to delete
        try {
            $this->modelRepository->delete($modelId, $userId);
            return ['success' => true, 'error' => null];
        } catch (\Throwable) {
            return ['success' => false, 'error' => 'Failed to delete model'];
        }
    }
}
