<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Controller;

class CampaignsController extends Controller
{
    protected function tableName(): string { return '202_aff_campaigns'; }
    protected function primaryKey(): string { return 'aff_campaign_id'; }
    protected function deletedColumn(): ?string { return 'aff_campaign_deleted'; }

    protected function fields(): array
    {
        return [
            'aff_campaign_name'            => ['type' => 's', 'required' => true],
            'aff_campaign_url'             => ['type' => 's', 'required' => true],
            'aff_campaign_url_2'           => ['type' => 's', 'required' => false],
            'aff_campaign_url_3'           => ['type' => 's', 'required' => false],
            'aff_campaign_url_4'           => ['type' => 's', 'required' => false],
            'aff_campaign_url_5'           => ['type' => 's', 'required' => false],
            'aff_campaign_payout'          => ['type' => 'd', 'required' => false],
            'aff_campaign_currency'        => ['type' => 's', 'required' => false],
            'aff_campaign_foreign_payout'  => ['type' => 'd', 'required' => false],
            'aff_network_id'               => ['type' => 'i', 'required' => false],
            'aff_campaign_cloaking'        => ['type' => 'i', 'required' => false],
            'aff_campaign_rotate'          => ['type' => 'i', 'required' => false],
        ];
    }

    public function create(array $payload): array
    {
        // Auto-set timestamp and public ID
        $payload['aff_campaign_time'] = $payload['aff_campaign_time'] ?? time();
        $this->extraCreateFields = [
            'aff_campaign_time' => ['type' => 'i', 'value' => time()],
            'aff_campaign_id_public' => ['type' => 'i', 'value' => random_int(100000, 999999)],
        ];
        return $this->createWithExtras($payload);
    }

    protected array $extraCreateFields = [];

    protected function createWithExtras(array $payload): array
    {
        $fields = $this->fields();
        $columns = [];
        $placeholders = [];
        $binds = [];
        $types = '';

        foreach ($fields as $col => $def) {
            if (($def['required'] ?? false) && !isset($payload[$col])) {
                throw new \RuntimeException("Missing required field: $col", 422);
            }
        }

        foreach ($fields as $col => $def) {
            if ($def['readonly'] ?? false) {
                continue;
            }
            if (isset($payload[$col])) {
                $columns[] = $col;
                $placeholders[] = '?';
                $binds[] = $payload[$col];
                $types .= $def['type'];
            }
        }

        foreach ($this->extraCreateFields as $col => $info) {
            $columns[] = $col;
            $placeholders[] = '?';
            $binds[] = $info['value'];
            $types .= $info['type'];
        }

        if ($this->userIdColumn()) {
            $columns[] = $this->userIdColumn();
            $placeholders[] = '?';
            $binds[] = $this->userId;
            $types .= 'i';
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->tableName(),
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException('Database error: ' . $this->db->error, 500);
        }
        $stmt->bind_param($types, ...$binds);

        if (!$stmt->execute()) {
            throw new \RuntimeException('Insert failed: ' . $stmt->error, 500);
        }

        $insertId = $stmt->insert_id;
        $stmt->close();

        return $this->get($insertId);
    }
}
