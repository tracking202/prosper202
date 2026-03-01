<?php

declare(strict_types=1);

namespace Prosper202\Rotator;

use Prosper202\Database\Connection;
use RuntimeException;

final class MysqlRotatorRepository implements RotatorRepositoryInterface
{
    public function __construct(private Connection $conn)
    {
    }

    public function list(int $userId, int $offset, int $limit): array
    {
        $countStmt = $this->conn->prepareRead(
            'SELECT COUNT(*) AS total FROM 202_rotators WHERE user_id = ?'
        );
        $this->conn->bind($countStmt, 'i', [$userId]);
        $this->conn->execute($countStmt);
        $countRow = $this->conn->fetchOne($countStmt);
        $total = (int) ($countRow['total'] ?? 0);

        $stmt = $this->conn->prepareRead(
            'SELECT id, public_id, user_id, name, default_url, default_campaign, default_lp
             FROM 202_rotators WHERE user_id = ? ORDER BY id DESC LIMIT ? OFFSET ?'
        );
        $this->conn->bind($stmt, 'iii', [$userId, $limit, $offset]);
        $this->conn->execute($stmt);
        $rows = $this->conn->fetchAll($stmt);

        return ['rows' => $rows, 'total' => $total];
    }

    public function findById(int $id, int $userId): ?array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT id, public_id, user_id, name, default_url, default_campaign, default_lp
             FROM 202_rotators WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'ii', [$id, $userId]);
        $this->conn->execute($stmt);
        $row = $this->conn->fetchOne($stmt);

        if ($row === null) {
            return null;
        }

        // Fetch rules
        $ruleStmt = $this->conn->prepareRead(
            'SELECT id, rotator_id, rule_name, splittest, status
             FROM 202_rotator_rules WHERE rotator_id = ? ORDER BY id ASC'
        );
        $this->conn->bind($ruleStmt, 'i', [$id]);
        $this->conn->execute($ruleStmt);
        $ruleRows = $this->conn->fetchAll($ruleStmt);

        $rules = [];
        $ruleIds = [];
        foreach ($ruleRows as $r) {
            $r['criteria'] = [];
            $r['redirects'] = [];
            $rules[$r['id']] = $r;
            $ruleIds[] = $r['id'];
        }

        if (!empty($ruleIds)) {
            $placeholders = implode(',', array_fill(0, count($ruleIds), '?'));
            $types = str_repeat('i', count($ruleIds));

            // Fetch criteria
            $cStmt = $this->conn->prepareRead(
                "SELECT id, rotator_id, rule_id, type, statement, value
                 FROM 202_rotator_rules_criteria WHERE rule_id IN ($placeholders)"
            );
            $this->conn->bind($cStmt, $types, $ruleIds);
            $this->conn->execute($cStmt);
            foreach ($this->conn->fetchAll($cStmt) as $c) {
                $rules[$c['rule_id']]['criteria'][] = $c;
            }

            // Fetch redirects
            $rStmt = $this->conn->prepareRead(
                "SELECT id, rule_id, redirect_url, redirect_campaign, redirect_lp, weight, name
                 FROM 202_rotator_rules_redirects WHERE rule_id IN ($placeholders)"
            );
            $this->conn->bind($rStmt, $types, $ruleIds);
            $this->conn->execute($rStmt);
            foreach ($this->conn->fetchAll($rStmt) as $rd) {
                $rules[$rd['rule_id']]['redirects'][] = $rd;
            }
        }

        $row['rules'] = array_values($rules);

        return $row;
    }

    public function create(int $userId, array $data): int
    {
        $publicId = (int) ($data['public_id'] ?? random_int(100_000, 9_999_999));

        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_rotators (public_id, user_id, name, default_url, default_campaign, default_lp) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $this->conn->bind($stmt, 'iissii', [
            $publicId, $userId,
            $data['name'], $data['default_url'] ?? '',
            (int) ($data['default_campaign'] ?? 0), (int) ($data['default_lp'] ?? 0),
        ]);

        return $this->conn->executeInsert($stmt);
    }

    public function update(int $id, int $userId, array $data): void
    {
        $allowedFields = ['name' => 's', 'default_url' => 's', 'default_campaign' => 'i', 'default_lp' => 'i'];

        $sets = [];
        $values = [];
        $types = '';

        foreach ($allowedFields as $field => $type) {
            if (array_key_exists($field, $data)) {
                $sets[] = "$field = ?";
                $values[] = $data[$field];
                $types .= $type;
            }
        }

        if (empty($sets)) {
            throw new RuntimeException('No fields to update');
        }

        $values[] = $id;
        $types .= 'i';
        $values[] = $userId;
        $types .= 'i';

        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_rotators SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?'
        );
        $this->conn->bind($stmt, $types, $values);
        $this->conn->execute($stmt);
        $stmt->close();
    }

    public function delete(int $id, int $userId): void
    {
        $this->conn->transaction(function () use ($id, $userId): void {
            $stmt = $this->conn->prepareWrite(
                'DELETE FROM 202_rotator_rules_criteria WHERE rotator_id = ?'
            );
            $this->conn->bind($stmt, 'i', [$id]);
            $this->conn->execute($stmt);
            $stmt->close();

            $stmt = $this->conn->prepareWrite(
                'DELETE FROM 202_rotator_rules_redirects WHERE rule_id IN (SELECT id FROM 202_rotator_rules WHERE rotator_id = ?)'
            );
            $this->conn->bind($stmt, 'i', [$id]);
            $this->conn->execute($stmt);
            $stmt->close();

            $stmt = $this->conn->prepareWrite(
                'DELETE FROM 202_rotator_rules WHERE rotator_id = ?'
            );
            $this->conn->bind($stmt, 'i', [$id]);
            $this->conn->execute($stmt);
            $stmt->close();

            $stmt = $this->conn->prepareWrite(
                'DELETE FROM 202_rotators WHERE id = ? AND user_id = ?'
            );
            $this->conn->bind($stmt, 'ii', [$id, $userId]);
            $this->conn->execute($stmt);
            $stmt->close();
        });
    }

    public function createRule(int $rotatorId, array $data): int
    {
        return $this->conn->transaction(function () use ($rotatorId, $data): int {
            $stmt = $this->conn->prepareWrite(
                'INSERT INTO 202_rotator_rules (rotator_id, rule_name, splittest, status) VALUES (?, ?, ?, ?)'
            );
            $this->conn->bind($stmt, 'isii', [
                $rotatorId, $data['rule_name'],
                (int) ($data['splittest'] ?? 0), (int) ($data['status'] ?? 1),
            ]);
            $ruleId = $this->conn->executeInsert($stmt);
            $stmt->close();

            if (!empty($data['criteria']) && is_array($data['criteria'])) {
                $stmt = $this->conn->prepareWrite(
                    'INSERT INTO 202_rotator_rules_criteria (rotator_id, rule_id, type, statement, value) VALUES (?, ?, ?, ?, ?)'
                );
                foreach ($data['criteria'] as $c) {
                    $this->conn->bind($stmt, 'iisss', [
                        $rotatorId, $ruleId,
                        (string) ($c['type'] ?? ''), (string) ($c['statement'] ?? ''), (string) ($c['value'] ?? ''),
                    ]);
                    $this->conn->execute($stmt);
                }
                $stmt->close();
            }

            if (!empty($data['redirects']) && is_array($data['redirects'])) {
                $stmt = $this->conn->prepareWrite(
                    'INSERT INTO 202_rotator_rules_redirects (rule_id, redirect_url, redirect_campaign, redirect_lp, weight, name) VALUES (?, ?, ?, ?, ?, ?)'
                );
                foreach ($data['redirects'] as $r) {
                    $this->conn->bind($stmt, 'isiiis', [
                        $ruleId, (string) ($r['redirect_url'] ?? ''),
                        (int) ($r['redirect_campaign'] ?? 0), (int) ($r['redirect_lp'] ?? 0),
                        (int) ($r['weight'] ?? 100), (string) ($r['name'] ?? ''),
                    ]);
                    $this->conn->execute($stmt);
                }
                $stmt->close();
            }

            return $ruleId;
        });
    }

    public function updateRule(int $ruleId, int $rotatorId, array $data): void
    {
        $this->conn->transaction(function () use ($ruleId, $rotatorId, $data): void {
            // Update rule fields
            $sets = [];
            $values = [];
            $types = '';

            if (array_key_exists('rule_name', $data)) {
                $sets[] = 'rule_name = ?';
                $values[] = (string) $data['rule_name'];
                $types .= 's';
            }
            if (array_key_exists('splittest', $data)) {
                $sets[] = 'splittest = ?';
                $values[] = (int) $data['splittest'];
                $types .= 'i';
            }
            if (array_key_exists('status', $data)) {
                $sets[] = 'status = ?';
                $values[] = (int) $data['status'];
                $types .= 'i';
            }

            if (!empty($sets)) {
                $values[] = $ruleId;
                $types .= 'i';
                $stmt = $this->conn->prepareWrite(
                    'UPDATE 202_rotator_rules SET ' . implode(', ', $sets) . ' WHERE id = ?'
                );
                $this->conn->bind($stmt, $types, $values);
                $this->conn->execute($stmt);
                $stmt->close();
            }

            // Replace criteria
            if (array_key_exists('criteria', $data) && is_array($data['criteria'])) {
                $stmt = $this->conn->prepareWrite(
                    'DELETE FROM 202_rotator_rules_criteria WHERE rule_id = ?'
                );
                $this->conn->bind($stmt, 'i', [$ruleId]);
                $this->conn->execute($stmt);
                $stmt->close();

                if (!empty($data['criteria'])) {
                    $stmt = $this->conn->prepareWrite(
                        'INSERT INTO 202_rotator_rules_criteria (rotator_id, rule_id, type, statement, value) VALUES (?, ?, ?, ?, ?)'
                    );
                    foreach ($data['criteria'] as $c) {
                        if (!is_array($c)) {
                            continue;
                        }
                        $this->conn->bind($stmt, 'iisss', [
                            $rotatorId, $ruleId,
                            (string) ($c['type'] ?? ''), (string) ($c['statement'] ?? ''), (string) ($c['value'] ?? ''),
                        ]);
                        $this->conn->execute($stmt);
                    }
                    $stmt->close();
                }
            }

            // Replace redirects
            if (array_key_exists('redirects', $data) && is_array($data['redirects'])) {
                $stmt = $this->conn->prepareWrite(
                    'DELETE FROM 202_rotator_rules_redirects WHERE rule_id = ?'
                );
                $this->conn->bind($stmt, 'i', [$ruleId]);
                $this->conn->execute($stmt);
                $stmt->close();

                if (!empty($data['redirects'])) {
                    $stmt = $this->conn->prepareWrite(
                        'INSERT INTO 202_rotator_rules_redirects (rule_id, redirect_url, redirect_campaign, redirect_lp, weight, name) VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    foreach ($data['redirects'] as $r) {
                        if (!is_array($r)) {
                            continue;
                        }
                        $this->conn->bind($stmt, 'isiiis', [
                            $ruleId, (string) ($r['redirect_url'] ?? ''),
                            (int) ($r['redirect_campaign'] ?? 0), (int) ($r['redirect_lp'] ?? 0),
                            (int) ($r['weight'] ?? 100), (string) ($r['name'] ?? ''),
                        ]);
                        $this->conn->execute($stmt);
                    }
                    $stmt->close();
                }
            }
        });
    }

    public function deleteRule(int $ruleId, int $rotatorId): void
    {
        $this->conn->transaction(function () use ($ruleId, $rotatorId): void {
            $stmt = $this->conn->prepareWrite(
                'DELETE FROM 202_rotator_rules_criteria WHERE rule_id = ?'
            );
            $this->conn->bind($stmt, 'i', [$ruleId]);
            $this->conn->execute($stmt);
            $stmt->close();

            $stmt = $this->conn->prepareWrite(
                'DELETE FROM 202_rotator_rules_redirects WHERE rule_id = ?'
            );
            $this->conn->bind($stmt, 'i', [$ruleId]);
            $this->conn->execute($stmt);
            $stmt->close();

            $stmt = $this->conn->prepareWrite(
                'DELETE FROM 202_rotator_rules WHERE id = ? AND rotator_id = ?'
            );
            $this->conn->bind($stmt, 'ii', [$ruleId, $rotatorId]);
            $this->conn->execute($stmt);
            $stmt->close();
        });
    }
}
