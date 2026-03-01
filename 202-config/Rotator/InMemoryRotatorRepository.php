<?php

declare(strict_types=1);

namespace Prosper202\Rotator;

use RuntimeException;

final class InMemoryRotatorRepository implements RotatorRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $rotators = [];
    private int $nextRotatorId = 1;

    /** @var array<int, array<string, mixed>> */
    public array $rules = [];
    private int $nextRuleId = 1;

    /** @var array<int, array<string, mixed>> */
    public array $criteria = [];
    private int $nextCriteriaId = 1;

    /** @var array<int, array<string, mixed>> */
    public array $redirects = [];
    private int $nextRedirectId = 1;

    public function list(int $userId, int $offset, int $limit): array
    {
        $filtered = array_filter($this->rotators, fn(array $r) => $r['user_id'] === $userId);
        // Sort by id DESC
        usort($filtered, fn(array $a, array $b) => $b['id'] <=> $a['id']);
        $total = count($filtered);
        $rows = array_slice($filtered, $offset, $limit);

        return ['rows' => $rows, 'total' => $total];
    }

    public function findById(int $id, int $userId): ?array
    {
        $rotator = $this->rotators[$id] ?? null;
        if ($rotator === null || $rotator['user_id'] !== $userId) {
            return null;
        }

        $row = $rotator;
        $row['rules'] = [];

        $ruleRows = array_filter($this->rules, fn(array $r) => $r['rotator_id'] === $id);
        usort($ruleRows, fn(array $a, array $b) => $a['id'] <=> $b['id']);

        foreach ($ruleRows as $rule) {
            $ruleId = $rule['id'];
            $rule['criteria'] = array_values(array_filter(
                $this->criteria,
                fn(array $c) => $c['rule_id'] === $ruleId,
            ));
            $rule['redirects'] = array_values(array_filter(
                $this->redirects,
                fn(array $rd) => $rd['rule_id'] === $ruleId,
            ));
            $row['rules'][] = $rule;
        }

        return $row;
    }

    public function create(int $userId, array $data): int
    {
        $id = $this->nextRotatorId++;
        $this->rotators[$id] = [
            'id' => $id,
            'public_id' => $data['public_id'] ?? random_int(100_000, 9_999_999),
            'user_id' => $userId,
            'name' => $data['name'],
            'default_url' => $data['default_url'] ?? '',
            'default_campaign' => (int) ($data['default_campaign'] ?? 0),
            'default_lp' => (int) ($data['default_lp'] ?? 0),
        ];

        return $id;
    }

    public function update(int $id, int $userId, array $data): void
    {
        if (!isset($this->rotators[$id]) || $this->rotators[$id]['user_id'] !== $userId) {
            throw new RuntimeException("Rotator $id not found");
        }

        $allowedFields = ['name', 'default_url', 'default_campaign', 'default_lp'];
        $updated = false;

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $this->rotators[$id][$field] = $data[$field];
                $updated = true;
            }
        }

        if (!$updated) {
            throw new RuntimeException('No fields to update');
        }
    }

    public function delete(int $id, int $userId): void
    {
        // Cascade delete: criteria → redirects → rules → rotator
        $ruleIds = array_keys(array_filter(
            $this->rules,
            fn(array $r) => $r['rotator_id'] === $id,
        ));

        foreach ($ruleIds as $ruleId) {
            $this->criteria = array_filter($this->criteria, fn(array $c) => $c['rule_id'] !== $ruleId);
            $this->redirects = array_filter($this->redirects, fn(array $rd) => $rd['rule_id'] !== $ruleId);
            unset($this->rules[$ruleId]);
        }

        unset($this->rotators[$id]);
    }

    public function createRule(int $rotatorId, array $data): int
    {
        $ruleId = $this->nextRuleId++;
        $this->rules[$ruleId] = [
            'id' => $ruleId,
            'rotator_id' => $rotatorId,
            'rule_name' => $data['rule_name'],
            'splittest' => (int) ($data['splittest'] ?? 0),
            'status' => (int) ($data['status'] ?? 1),
        ];

        if (!empty($data['criteria']) && is_array($data['criteria'])) {
            foreach ($data['criteria'] as $c) {
                $cId = $this->nextCriteriaId++;
                $this->criteria[$cId] = [
                    'id' => $cId,
                    'rotator_id' => $rotatorId,
                    'rule_id' => $ruleId,
                    'type' => (string) ($c['type'] ?? ''),
                    'statement' => (string) ($c['statement'] ?? ''),
                    'value' => (string) ($c['value'] ?? ''),
                ];
            }
        }

        if (!empty($data['redirects']) && is_array($data['redirects'])) {
            foreach ($data['redirects'] as $r) {
                $rdId = $this->nextRedirectId++;
                $this->redirects[$rdId] = [
                    'id' => $rdId,
                    'rule_id' => $ruleId,
                    'redirect_url' => (string) ($r['redirect_url'] ?? ''),
                    'redirect_campaign' => (int) ($r['redirect_campaign'] ?? 0),
                    'redirect_lp' => (int) ($r['redirect_lp'] ?? 0),
                    'weight' => (int) ($r['weight'] ?? 100),
                    'name' => (string) ($r['name'] ?? ''),
                ];
            }
        }

        return $ruleId;
    }

    public function updateRule(int $ruleId, int $rotatorId, array $data): void
    {
        if (!isset($this->rules[$ruleId]) || $this->rules[$ruleId]['rotator_id'] !== $rotatorId) {
            throw new RuntimeException("Rule $ruleId not found");
        }

        if (array_key_exists('rule_name', $data)) {
            $this->rules[$ruleId]['rule_name'] = (string) $data['rule_name'];
        }
        if (array_key_exists('splittest', $data)) {
            $this->rules[$ruleId]['splittest'] = (int) $data['splittest'];
        }
        if (array_key_exists('status', $data)) {
            $this->rules[$ruleId]['status'] = (int) $data['status'];
        }

        // Replace criteria
        if (array_key_exists('criteria', $data) && is_array($data['criteria'])) {
            $this->criteria = array_filter($this->criteria, fn(array $c) => $c['rule_id'] !== $ruleId);
            foreach ($data['criteria'] as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $cId = $this->nextCriteriaId++;
                $this->criteria[$cId] = [
                    'id' => $cId,
                    'rotator_id' => $rotatorId,
                    'rule_id' => $ruleId,
                    'type' => (string) ($c['type'] ?? ''),
                    'statement' => (string) ($c['statement'] ?? ''),
                    'value' => (string) ($c['value'] ?? ''),
                ];
            }
        }

        // Replace redirects
        if (array_key_exists('redirects', $data) && is_array($data['redirects'])) {
            $this->redirects = array_filter($this->redirects, fn(array $rd) => $rd['rule_id'] !== $ruleId);
            foreach ($data['redirects'] as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $rdId = $this->nextRedirectId++;
                $this->redirects[$rdId] = [
                    'id' => $rdId,
                    'rule_id' => $ruleId,
                    'redirect_url' => (string) ($r['redirect_url'] ?? ''),
                    'redirect_campaign' => (int) ($r['redirect_campaign'] ?? 0),
                    'redirect_lp' => (int) ($r['redirect_lp'] ?? 0),
                    'weight' => (int) ($r['weight'] ?? 100),
                    'name' => (string) ($r['name'] ?? ''),
                ];
            }
        }
    }

    public function deleteRule(int $ruleId, int $rotatorId): void
    {
        $this->criteria = array_filter($this->criteria, fn(array $c) => $c['rule_id'] !== $ruleId);
        $this->redirects = array_filter($this->redirects, fn(array $rd) => $rd['rule_id'] !== $ruleId);
        unset($this->rules[$ruleId]);
    }
}
