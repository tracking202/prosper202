<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;

class RotatorsController
{
    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
    }

    public function list(array $params): array
    {
        $limit = max(1, min(500, (int)($params['limit'] ?? 50)));
        $offset = max(0, (int)($params['offset'] ?? 0));

        $stmt = $this->prepare('SELECT COUNT(*) as total FROM 202_rotators WHERE user_id = ?');
        $stmt->bind_param('i', $this->userId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Count query failed');
        }
        $total = (int)$stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();

        $stmt = $this->prepare('SELECT id, public_id, user_id, name, default_url, default_campaign, default_lp FROM 202_rotators WHERE user_id = ? ORDER BY id DESC LIMIT ? OFFSET ?');
        $stmt->bind_param('iii', $this->userId, $limit, $offset);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('List query failed');
        }
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
        $stmt = $this->prepare('SELECT id, public_id, user_id, name, default_url, default_campaign, default_lp FROM 202_rotators WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->bind_param('ii', $id, $this->userId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Query failed');
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new NotFoundException('Rotator not found');
        }

        // Batch-fetch rules, criteria, and redirects
        $stmt = $this->prepare('SELECT id, rotator_id, rule_name, splittest, status FROM 202_rotator_rules WHERE rotator_id = ? ORDER BY id ASC');
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Query failed');
        }
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
            $placeholders = implode(',', array_fill(0, count($ruleIds), '?'));
            $types = str_repeat('i', count($ruleIds));

            $cStmt = $this->prepare("SELECT id, rotator_id, rule_id, type, statement, value FROM 202_rotator_rules_criteria WHERE rule_id IN ($placeholders)");
            $cStmt->bind_param($types, ...$ruleIds);
            if (!$cStmt->execute()) {
                $cStmt->close();
                throw new DatabaseException('Query failed');
            }
            $cr = $cStmt->get_result();
            while ($c = $cr->fetch_assoc()) {
                $rules[$c['rule_id']]['criteria'][] = $c;
            }
            $cStmt->close();

            $rStmt = $this->prepare("SELECT id, rule_id, redirect_url, redirect_campaign, redirect_lp, weight, name FROM 202_rotator_rules_redirects WHERE rule_id IN ($placeholders)");
            $rStmt->bind_param($types, ...$ruleIds);
            if (!$rStmt->execute()) {
                $rStmt->close();
                throw new DatabaseException('Query failed');
            }
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
        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException('name is required', ['name' => 'Cannot be empty']);
        }

        $defaultUrl = (string)($payload['default_url'] ?? '');
        $defaultCampaign = (int)($payload['default_campaign'] ?? 0);
        $defaultLp = (int)($payload['default_lp'] ?? 0);
        $publicId = isset($payload['public_id']) && (int)$payload['public_id'] > 0
            ? (int)$payload['public_id']
            : random_int(100_000, 9_999_999);

        $stmt = $this->prepare('INSERT INTO 202_rotators (public_id, user_id, name, default_url, default_campaign, default_lp) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('iissii', $publicId, $this->userId, $name, $defaultUrl, $defaultCampaign, $defaultLp);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Create failed');
        }
        $id = $stmt->insert_id;
        $stmt->close();

        return $this->get($id);
    }

    public function update(int $id, array $payload): array
    {
        $this->get($id);

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
            throw new ValidationException('No fields to update');
        }

        $binds[] = $id;
        $types .= 'i';
        $binds[] = $this->userId;
        $types .= 'i';

        $stmt = $this->prepare('UPDATE 202_rotators SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?');
        $stmt->bind_param($types, ...$binds);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Update failed');
        }
        $stmt->close();

        return $this->get($id);
    }

    public function delete(int $id): void
    {
        $this->get($id);

        $this->db->begin_transaction();
        try {
            $stmt = $this->prepare('DELETE FROM 202_rotator_rules_criteria WHERE rotator_id = ?');
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) { $stmt->close(); throw new DatabaseException('Delete criteria failed'); }
            $stmt->close();

            $stmt = $this->prepare('DELETE FROM 202_rotator_rules_redirects WHERE rule_id IN (SELECT id FROM 202_rotator_rules WHERE rotator_id = ?)');
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) { $stmt->close(); throw new DatabaseException('Delete redirects failed'); }
            $stmt->close();

            $stmt = $this->prepare('DELETE FROM 202_rotator_rules WHERE rotator_id = ?');
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) { $stmt->close(); throw new DatabaseException('Delete rules failed'); }
            $stmt->close();

            $stmt = $this->prepare('DELETE FROM 202_rotators WHERE id = ? AND user_id = ?');
            $stmt->bind_param('ii', $id, $this->userId);
            if (!$stmt->execute()) { $stmt->close(); throw new DatabaseException('Delete rotator failed'); }
            $stmt->close();

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function listRules(int $rotatorId): array
    {
        $rotator = $this->get($rotatorId);
        return ['data' => $rotator['data']['rules']];
    }

    public function createRule(int $rotatorId, array $payload): array
    {
        $this->get($rotatorId);

        $ruleName = trim((string)($payload['rule_name'] ?? ''));
        if ($ruleName === '') {
            throw new ValidationException('rule_name is required', ['rule_name' => 'Cannot be empty']);
        }

        $splittest = (int)($payload['splittest'] ?? 0);
        $status = (int)($payload['status'] ?? 1);

        $this->db->begin_transaction();
        try {
            $stmt = $this->prepare('INSERT INTO 202_rotator_rules (rotator_id, rule_name, splittest, status) VALUES (?, ?, ?, ?)');
            $stmt->bind_param('isii', $rotatorId, $ruleName, $splittest, $status);
            if (!$stmt->execute()) { $stmt->close(); throw new DatabaseException('Failed to create rule'); }
            $ruleId = $stmt->insert_id;
            $stmt->close();

            if (!empty($payload['criteria']) && is_array($payload['criteria'])) {
                $insertCriteria = $this->prepare('INSERT INTO 202_rotator_rules_criteria (rotator_id, rule_id, type, statement, value) VALUES (?, ?, ?, ?, ?)');
                foreach ($payload['criteria'] as $c) {
                    $cType = (string)($c['type'] ?? '');
                    $cStatement = (string)($c['statement'] ?? '');
                    $cValue = (string)($c['value'] ?? '');
                    $insertCriteria->bind_param('iisss', $rotatorId, $ruleId, $cType, $cStatement, $cValue);
                    if (!$insertCriteria->execute()) { $insertCriteria->close(); throw new DatabaseException('Failed to insert criterion'); }
                }
                $insertCriteria->close();
            }

            if (!empty($payload['redirects']) && is_array($payload['redirects'])) {
                $insertRedirect = $this->prepare('INSERT INTO 202_rotator_rules_redirects (rule_id, redirect_url, redirect_campaign, redirect_lp, weight, name) VALUES (?, ?, ?, ?, ?, ?)');
                foreach ($payload['redirects'] as $r) {
                    $rUrl = (string)($r['redirect_url'] ?? '');
                    $rCampaign = (int)($r['redirect_campaign'] ?? 0);
                    $rLp = (int)($r['redirect_lp'] ?? 0);
                    $rWeight = (int)($r['weight'] ?? 100);
                    $rName = (string)($r['name'] ?? '');
                    $insertRedirect->bind_param('isiiis', $ruleId, $rUrl, $rCampaign, $rLp, $rWeight, $rName);
                    if (!$insertRedirect->execute()) { $insertRedirect->close(); throw new DatabaseException('Failed to insert redirect'); }
                }
                $insertRedirect->close();
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        return $this->get($rotatorId);
    }

    public function updateRule(int $rotatorId, int $ruleId, array $payload): array
    {
        $this->get($rotatorId);

        $allowed = ['rule_name' => true, 'splittest' => true, 'status' => true, 'criteria' => true, 'redirects' => true];
        foreach (array_keys($payload) as $field) {
            if (!isset($allowed[$field])) {
                throw new ValidationException('Unsupported field in rule update payload', [$field => 'Unsupported field']);
            }
        }

        $stmt = $this->prepare('SELECT id FROM 202_rotator_rules WHERE id = ? AND rotator_id = ? LIMIT 1');
        $stmt->bind_param('ii', $ruleId, $rotatorId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Rule lookup failed');
        }
        $rule = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$rule) {
            throw new NotFoundException('Rule not found for rotator');
        }

        $setParts = [];
        $binds = [];
        $types = '';

        if (array_key_exists('rule_name', $payload)) {
            $ruleName = trim((string)$payload['rule_name']);
            if ($ruleName === '') {
                throw new ValidationException('rule_name cannot be empty', ['rule_name' => 'Cannot be empty']);
            }
            $setParts[] = 'rule_name = ?';
            $binds[] = $ruleName;
            $types .= 's';
        }
        if (array_key_exists('splittest', $payload)) {
            $setParts[] = 'splittest = ?';
            $binds[] = (int)$payload['splittest'];
            $types .= 'i';
        }
        if (array_key_exists('status', $payload)) {
            $setParts[] = 'status = ?';
            $binds[] = (int)$payload['status'];
            $types .= 'i';
        }

        $hasCriteria = array_key_exists('criteria', $payload);
        $hasRedirects = array_key_exists('redirects', $payload);

        if ($hasCriteria && !is_array($payload['criteria'])) {
            throw new ValidationException('criteria must be an array', ['criteria' => 'Expected array']);
        }
        if ($hasRedirects && !is_array($payload['redirects'])) {
            throw new ValidationException('redirects must be an array', ['redirects' => 'Expected array']);
        }
        if (empty($setParts) && !$hasCriteria && !$hasRedirects) {
            throw new ValidationException('No fields to update');
        }

        $this->db->begin_transaction();
        try {
            if (!empty($setParts)) {
                $binds[] = $ruleId;
                $types .= 'i';
                $update = $this->prepare('UPDATE 202_rotator_rules SET ' . implode(', ', $setParts) . ' WHERE id = ?');
                $update->bind_param($types, ...$binds);
                if (!$update->execute()) {
                    $update->close();
                    throw new DatabaseException('Failed to update rule');
                }
                $update->close();
            }

            if ($hasCriteria) {
                $deleteCriteria = $this->prepare('DELETE FROM 202_rotator_rules_criteria WHERE rule_id = ?');
                $deleteCriteria->bind_param('i', $ruleId);
                if (!$deleteCriteria->execute()) {
                    $deleteCriteria->close();
                    throw new DatabaseException('Failed to clear criteria');
                }
                $deleteCriteria->close();

                $criteria = $payload['criteria'];
                if (!empty($criteria)) {
                    $insertCriteria = $this->prepare('INSERT INTO 202_rotator_rules_criteria (rotator_id, rule_id, type, statement, value) VALUES (?, ?, ?, ?, ?)');
                    foreach ($criteria as $criterion) {
                        if (!is_array($criterion)) {
                            continue;
                        }
                        $cType = (string)($criterion['type'] ?? '');
                        $cStatement = (string)($criterion['statement'] ?? '');
                        $cValue = (string)($criterion['value'] ?? '');
                        $insertCriteria->bind_param('iisss', $rotatorId, $ruleId, $cType, $cStatement, $cValue);
                        if (!$insertCriteria->execute()) {
                            $insertCriteria->close();
                            throw new DatabaseException('Failed to insert criterion');
                        }
                    }
                    $insertCriteria->close();
                }
            }

            if ($hasRedirects) {
                $deleteRedirects = $this->prepare('DELETE FROM 202_rotator_rules_redirects WHERE rule_id = ?');
                $deleteRedirects->bind_param('i', $ruleId);
                if (!$deleteRedirects->execute()) {
                    $deleteRedirects->close();
                    throw new DatabaseException('Failed to clear redirects');
                }
                $deleteRedirects->close();

                $redirects = $payload['redirects'];
                if (!empty($redirects)) {
                    $insertRedirect = $this->prepare('INSERT INTO 202_rotator_rules_redirects (rule_id, redirect_url, redirect_campaign, redirect_lp, weight, name) VALUES (?, ?, ?, ?, ?, ?)');
                    foreach ($redirects as $redirect) {
                        if (!is_array($redirect)) {
                            continue;
                        }
                        $rUrl = (string)($redirect['redirect_url'] ?? '');
                        $rCampaign = (int)($redirect['redirect_campaign'] ?? 0);
                        $rLp = (int)($redirect['redirect_lp'] ?? 0);
                        $rWeight = (int)($redirect['weight'] ?? 100);
                        $rName = (string)($redirect['name'] ?? '');
                        $insertRedirect->bind_param('isiiis', $ruleId, $rUrl, $rCampaign, $rLp, $rWeight, $rName);
                        if (!$insertRedirect->execute()) {
                            $insertRedirect->close();
                            throw new DatabaseException('Failed to insert redirect');
                        }
                    }
                    $insertRedirect->close();
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

        $this->db->begin_transaction();
        try {
            $stmt = $this->prepare('DELETE FROM 202_rotator_rules_criteria WHERE rule_id = ?');
            $stmt->bind_param('i', $ruleId);
            if (!$stmt->execute()) { $stmt->close(); throw new DatabaseException('Delete criteria failed'); }
            $stmt->close();

            $stmt = $this->prepare('DELETE FROM 202_rotator_rules_redirects WHERE rule_id = ?');
            $stmt->bind_param('i', $ruleId);
            if (!$stmt->execute()) { $stmt->close(); throw new DatabaseException('Delete redirects failed'); }
            $stmt->close();

            $stmt = $this->prepare('DELETE FROM 202_rotator_rules WHERE id = ? AND rotator_id = ?');
            $stmt->bind_param('ii', $ruleId, $rotatorId);
            if (!$stmt->execute()) { $stmt->close(); throw new DatabaseException('Delete rule failed'); }
            $stmt->close();

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    private function prepare(string $sql): \mysqli_stmt
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new DatabaseException('Prepare failed');
        }
        return $stmt;
    }
}
