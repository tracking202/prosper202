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
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) {
            // Get criteria
            $cStmt = $this->db->prepare('SELECT * FROM 202_rotator_rules_criteria WHERE rule_id = ?');
            $cStmt->bind_param('i', $r['id']);
            $cStmt->execute();
            $criteria = [];
            $cr = $cStmt->get_result();
            while ($c = $cr->fetch_assoc()) {
                $criteria[] = $c;
            }
            $cStmt->close();

            // Get redirects
            $rStmt = $this->db->prepare('SELECT * FROM 202_rotator_rules_redirects WHERE rule_id = ?');
            $rStmt->bind_param('i', $r['id']);
            $rStmt->execute();
            $redirects = [];
            $rr = $rStmt->get_result();
            while ($rd = $rr->fetch_assoc()) {
                $redirects[] = $rd;
            }
            $rStmt->close();

            $r['criteria'] = $criteria;
            $r['redirects'] = $redirects;
            $rules[] = $r;
        }
        $stmt->close();

        $row['rules'] = $rules;
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

        // Delete criteria, redirects, rules, then rotator
        $this->db->prepare('DELETE rc FROM 202_rotator_rules_criteria rc INNER JOIN 202_rotator_rules r ON rc.rule_id = r.id WHERE r.rotator_id = ?')
            ->bind_param('i', $id);
        $this->db->prepare('DELETE rd FROM 202_rotator_rules_redirects rd INNER JOIN 202_rotator_rules r ON rd.rule_id = r.id WHERE r.rotator_id = ?')
            ->bind_param('i', $id);

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
    }

    // --- Rules ---

    public function listRules(int $rotatorId): array
    {
        $this->get($rotatorId); // verify ownership
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
                $rWeight = $r['weight'] ?? '100';
                $rName = $r['name'] ?? '';
                $stmt->bind_param('isiiis', $ruleId, $rUrl, $rCampaign, $rLp, $rWeight, $rName);
                $stmt->execute();
                $stmt->close();
            }
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
