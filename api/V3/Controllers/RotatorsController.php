<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Bootstrap;

class RotatorsController
{
    private \mysqli $db;
    private int $userId;

    public function __construct()
    {
        $this->db = Bootstrap::db();
        $this->userId = Bootstrap::userId();
    }

    // --- Rotators ---

    public function list(array $params): array
    {
        $limit = max(1, min(500, (int)($params['limit'] ?? 50)));
        $offset = max(0, (int)($params['offset'] ?? 0));

        $stmt = $this->db->prepare('SELECT COUNT(*) as total FROM 202_rotators WHERE user_id = ?');
        $stmt->bind_param('i', $this->userId);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();

        $stmt = $this->db->prepare('SELECT * FROM 202_rotators WHERE user_id = ? ORDER BY id DESC LIMIT ? OFFSET ?');
        $stmt->bind_param('iii', $this->userId, $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return ['data' => $rows, 'pagination' => ['total' => $total, 'limit' => $limit, 'offset' => $offset]];
    }

    public function get(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM 202_rotators WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->bind_param('ii', $id, $this->userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new \RuntimeException('Rotator not found', 404);
        }

        // Fetch rules
        $stmt = $this->db->prepare('SELECT * FROM 202_rotator_rules WHERE rotator_id = ? ORDER BY id ASC');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $rules = [];
        $ruleIds = [];
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) {
            $r['criteria'] = [];
            $r['redirects'] = [];
            $rules[$r['id']] = $r;
            $ruleIds[] = $r['id'];
        }
        $stmt->close();

        if (!empty($ruleIds)) {
            // Batch-fetch criteria for all rules
            $placeholders = implode(',', array_fill(0, count($ruleIds), '?'));
            $types = str_repeat('i', count($ruleIds));

            $cStmt = $this->db->prepare("SELECT * FROM 202_rotator_rules_criteria WHERE rule_id IN ($placeholders)");
            $cStmt->bind_param($types, ...$ruleIds);
            $cStmt->execute();
            $cr = $cStmt->get_result();
            while ($c = $cr->fetch_assoc()) {
                $rules[$c['rule_id']]['criteria'][] = $c;
            }
            $cStmt->close();

            // Batch-fetch redirects for all rules
            $rStmt = $this->db->prepare("SELECT * FROM 202_rotator_rules_redirects WHERE rule_id IN ($placeholders)");
            $rStmt->bind_param($types, ...$ruleIds);
            $rStmt->execute();
            $rr = $rStmt->get_result();
            while ($rd = $rr->fetch_assoc()) {
                $rules[$rd['rule_id']]['redirects'][] = $rd;
            }
            $rStmt->close();
        }

        $row['rules'] = array_values($rules);
        return ['data' => $row];
    }

    public function create(array $payload): array
    {
        $name = $payload['name'] ?? '';
        if ($name === '') {
            throw new \RuntimeException('name is required', 422);
        }

        $defaultUrl = $payload['default_url'] ?? '';
        $defaultCampaign = (int)($payload['default_campaign'] ?? 0);
        $defaultLp = (int)($payload['default_lp'] ?? 0);
        $publicId = random_int(100000, 9999999);

        $stmt = $this->db->prepare('INSERT INTO 202_rotators (public_id, user_id, name, default_url, default_campaign, default_lp) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('iissii', $publicId, $this->userId, $name, $defaultUrl, $defaultCampaign, $defaultLp);
        if (!$stmt->execute()) {
            throw new \RuntimeException('Create failed: ' . $stmt->error, 500);
        }
        $id = $stmt->insert_id;
        $stmt->close();

        return $this->get($id);
    }

    public function update(int $id, array $payload): array
    {
        $this->get($id); // verify ownership

        $sets = [];
        $binds = [];
        $types = '';

        foreach (['name' => 's', 'default_url' => 's', 'default_campaign' => 'i', 'default_lp' => 'i'] as $field => $type) {
            if (array_key_exists($field, $payload)) {
                $sets[] = "$field = ?";
                $binds[] = $payload[$field];
                $types .= $type;
            }
        }

        if (empty($sets)) {
            throw new \RuntimeException('No fields to update', 422);
        }

        $binds[] = $id;
        $types .= 'i';
        $binds[] = $this->userId;
        $types .= 'i';

        $stmt = $this->db->prepare('UPDATE 202_rotators SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?');
        $stmt->bind_param($types, ...$binds);
        $stmt->execute();
        $stmt->close();

        return $this->get($id);
    }

    public function delete(int $id): void
    {
        $this->get($id);

        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare('DELETE FROM 202_rotator_rules_criteria WHERE rotator_id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            $stmt = $this->db->prepare('DELETE FROM 202_rotator_rules_redirects WHERE rule_id IN (SELECT id FROM 202_rotator_rules WHERE rotator_id = ?)');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            $stmt = $this->db->prepare('DELETE FROM 202_rotator_rules WHERE rotator_id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            $stmt = $this->db->prepare('DELETE FROM 202_rotators WHERE id = ? AND user_id = ?');
            $stmt->bind_param('ii', $id, $this->userId);
            $stmt->execute();
            $stmt->close();

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    // --- Rules ---

    public function listRules(int $rotatorId): array
    {
        $rotator = $this->get($rotatorId);
        return ['data' => $rotator['data']['rules']];
    }

    public function createRule(int $rotatorId, array $payload): array
    {
        $this->get($rotatorId);

        $ruleName = $payload['rule_name'] ?? '';
        if ($ruleName === '') {
            throw new \RuntimeException('rule_name is required', 422);
        }

        $splittest = (int)($payload['splittest'] ?? 0);
        $status = (int)($payload['status'] ?? 1);

        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare('INSERT INTO 202_rotator_rules (rotator_id, rule_name, splittest, status) VALUES (?, ?, ?, ?)');
            $stmt->bind_param('isii', $rotatorId, $ruleName, $splittest, $status);
            $stmt->execute();
            $ruleId = $stmt->insert_id;
            $stmt->close();

            // Add criteria if provided
            if (!empty($payload['criteria']) && is_array($payload['criteria'])) {
                foreach ($payload['criteria'] as $c) {
                    $stmt = $this->db->prepare('INSERT INTO 202_rotator_rules_criteria (rotator_id, rule_id, type, statement, value) VALUES (?, ?, ?, ?, ?)');
                    $cType = $c['type'] ?? '';
                    $cStatement = $c['statement'] ?? '';
                    $cValue = $c['value'] ?? '';
                    $stmt->bind_param('iisss', $rotatorId, $ruleId, $cType, $cStatement, $cValue);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            // Add redirects if provided
            if (!empty($payload['redirects']) && is_array($payload['redirects'])) {
                foreach ($payload['redirects'] as $r) {
                    $stmt = $this->db->prepare('INSERT INTO 202_rotator_rules_redirects (rule_id, redirect_url, redirect_campaign, redirect_lp, weight, name) VALUES (?, ?, ?, ?, ?, ?)');
                    $rUrl = $r['redirect_url'] ?? '';
                    $rCampaign = (int)($r['redirect_campaign'] ?? 0);
                    $rLp = (int)($r['redirect_lp'] ?? 0);
                    $rWeight = (int)($r['weight'] ?? '100');
                    $rName = $r['name'] ?? '';
                    $stmt->bind_param('isiiis', $ruleId, $rUrl, $rCampaign, $rLp, $rWeight, $rName);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        return $this->get($rotatorId);
    }

    public function deleteRule(int $rotatorId, int $ruleId): void
    {
        $this->get($rotatorId);

        $stmt = $this->db->prepare('DELETE FROM 202_rotator_rules_criteria WHERE rule_id = ?');
        $stmt->bind_param('i', $ruleId);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->db->prepare('DELETE FROM 202_rotator_rules_redirects WHERE rule_id = ?');
        $stmt->bind_param('i', $ruleId);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->db->prepare('DELETE FROM 202_rotator_rules WHERE id = ? AND rotator_id = ?');
        $stmt->bind_param('ii', $ruleId, $rotatorId);
        $stmt->execute();
        $stmt->close();
    }
}
