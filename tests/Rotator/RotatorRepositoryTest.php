<?php

declare(strict_types=1);

namespace Tests\Rotator;

use PHPUnit\Framework\TestCase;
use Prosper202\Rotator\InMemoryRotatorRepository;
use RuntimeException;

final class RotatorRepositoryTest extends TestCase
{
    private function makeRepo(): InMemoryRotatorRepository
    {
        return new InMemoryRotatorRepository();
    }

    // --- Rotator CRUD ---

    public function testCreateReturnsSequentialIds(): void
    {
        $repo = $this->makeRepo();

        $id1 = $repo->create(1, ['name' => 'Rotator 1']);
        $id2 = $repo->create(1, ['name' => 'Rotator 2']);

        self::assertSame(1, $id1);
        self::assertSame(2, $id2);
    }

    public function testCreateStoresRotatorData(): void
    {
        $repo = $this->makeRepo();
        $id = $repo->create(1, [
            'name' => 'Test Rotator',
            'default_url' => 'https://example.com',
            'default_campaign' => 10,
            'default_lp' => 5,
        ]);

        $rotator = $repo->findById($id, 1);

        self::assertNotNull($rotator);
        self::assertSame('Test Rotator', $rotator['name']);
        self::assertSame('https://example.com', $rotator['default_url']);
        self::assertSame(10, $rotator['default_campaign']);
        self::assertSame(5, $rotator['default_lp']);
        self::assertSame(1, $rotator['user_id']);
        self::assertIsArray($rotator['rules']);
        self::assertEmpty($rotator['rules']);
    }

    public function testFindByIdReturnsNullForNonexistent(): void
    {
        $repo = $this->makeRepo();

        self::assertNull($repo->findById(999, 1));
    }

    public function testFindByIdReturnsNullForWrongUser(): void
    {
        $repo = $this->makeRepo();
        $id = $repo->create(1, ['name' => 'Rotator']);

        self::assertNull($repo->findById($id, 2));
    }

    public function testListReturnsRotatorsForUser(): void
    {
        $repo = $this->makeRepo();
        $repo->create(1, ['name' => 'R1']);
        $repo->create(1, ['name' => 'R2']);
        $repo->create(2, ['name' => 'R3']); // different user

        $result = $repo->list(1, 0, 10);

        self::assertSame(2, $result['total']);
        self::assertCount(2, $result['rows']);
    }

    public function testListRespectsPagination(): void
    {
        $repo = $this->makeRepo();
        $repo->create(1, ['name' => 'R1']);
        $repo->create(1, ['name' => 'R2']);
        $repo->create(1, ['name' => 'R3']);

        $result = $repo->list(1, 1, 1);

        self::assertSame(3, $result['total']);
        self::assertCount(1, $result['rows']);
    }

    public function testUpdateModifiesFields(): void
    {
        $repo = $this->makeRepo();
        $id = $repo->create(1, ['name' => 'Original']);

        $repo->update($id, 1, ['name' => 'Updated', 'default_url' => 'https://new.example.com']);

        $rotator = $repo->findById($id, 1);
        self::assertSame('Updated', $rotator['name']);
        self::assertSame('https://new.example.com', $rotator['default_url']);
    }

    public function testUpdateThrowsWhenNoFields(): void
    {
        $repo = $this->makeRepo();
        $id = $repo->create(1, ['name' => 'Rotator']);

        $this->expectException(RuntimeException::class);
        $repo->update($id, 1, []);
    }

    public function testUpdateThrowsForWrongUser(): void
    {
        $repo = $this->makeRepo();
        $id = $repo->create(1, ['name' => 'Rotator']);

        $this->expectException(RuntimeException::class);
        $repo->update($id, 2, ['name' => 'Hacked']);
    }

    // --- Cascade Delete ---

    public function testDeleteRemovesRotatorAndAllChildren(): void
    {
        $repo = $this->makeRepo();
        $rotatorId = $repo->create(1, ['name' => 'Rotator']);

        $ruleId = $repo->createRule($rotatorId, [
            'rule_name' => 'Rule 1',
            'criteria' => [['type' => 'country', 'statement' => 'is', 'value' => 'US']],
            'redirects' => [['redirect_url' => 'https://a.com', 'weight' => 100]],
        ]);

        // Verify everything exists
        $found = $repo->findById($rotatorId, 1);
        self::assertNotNull($found);
        self::assertCount(1, $found['rules']);
        self::assertCount(1, $found['rules'][0]['criteria']);
        self::assertCount(1, $found['rules'][0]['redirects']);

        // Delete rotator
        $repo->delete($rotatorId, 1);

        self::assertNull($repo->findById($rotatorId, 1));
        self::assertEmpty($repo->rules);
        self::assertEmpty($repo->criteria);
        self::assertEmpty($repo->redirects);
    }

    public function testDeleteWithWrongUserThrows(): void
    {
        $repo = $this->makeRepo();
        $rotatorId = $repo->create(1, ['name' => 'Rotator']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not owned by user');
        $repo->delete($rotatorId, 999);
    }

    public function testDeleteWithWrongUserLeavesChildrenIntact(): void
    {
        $repo = $this->makeRepo();
        $rotatorId = $repo->create(1, ['name' => 'Rotator']);
        $repo->createRule($rotatorId, [
            'rule_name' => 'Rule 1',
            'criteria' => [['type' => 'country', 'statement' => 'is', 'value' => 'US']],
            'redirects' => [['redirect_url' => 'https://a.com', 'weight' => 100]],
        ]);

        try {
            $repo->delete($rotatorId, 999);
            $this->fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('not owned by user', $e->getMessage());
        }

        $found = $repo->findById($rotatorId, 1);
        self::assertNotNull($found);
        self::assertCount(1, $found['rules']);
        self::assertNotEmpty($repo->criteria);
        self::assertNotEmpty($repo->redirects);
    }

    // --- Rule Management ---

    public function testCreateRuleWithCriteriaAndRedirects(): void
    {
        $repo = $this->makeRepo();
        $rotatorId = $repo->create(1, ['name' => 'Rotator']);

        $ruleId = $repo->createRule($rotatorId, [
            'rule_name' => 'US Traffic',
            'splittest' => 1,
            'status' => 1,
            'criteria' => [
                ['type' => 'country', 'statement' => 'is', 'value' => 'US'],
                ['type' => 'device', 'statement' => 'is', 'value' => 'mobile'],
            ],
            'redirects' => [
                ['redirect_url' => 'https://offer-a.com', 'weight' => 60, 'name' => 'Offer A'],
                ['redirect_url' => 'https://offer-b.com', 'weight' => 40, 'name' => 'Offer B'],
            ],
        ]);

        $rotator = $repo->findById($rotatorId, 1);
        self::assertCount(1, $rotator['rules']);

        $rule = $rotator['rules'][0];
        self::assertSame($ruleId, $rule['id']);
        self::assertSame('US Traffic', $rule['rule_name']);
        self::assertSame(1, $rule['splittest']);
        self::assertCount(2, $rule['criteria']);
        self::assertCount(2, $rule['redirects']);

        self::assertSame('country', $rule['criteria'][0]['type']);
        self::assertSame('https://offer-a.com', $rule['redirects'][0]['redirect_url']);
        self::assertSame(60, $rule['redirects'][0]['weight']);
    }

    public function testCreateRuleWithoutOptionalData(): void
    {
        $repo = $this->makeRepo();
        $rotatorId = $repo->create(1, ['name' => 'Rotator']);

        $ruleId = $repo->createRule($rotatorId, [
            'rule_name' => 'Simple Rule',
        ]);

        $rotator = $repo->findById($rotatorId, 1);
        $rule = $rotator['rules'][0];

        self::assertSame($ruleId, $rule['id']);
        self::assertSame('Simple Rule', $rule['rule_name']);
        self::assertSame(0, $rule['splittest']);
        self::assertSame(1, $rule['status']);
        self::assertEmpty($rule['criteria']);
        self::assertEmpty($rule['redirects']);
    }

    public function testUpdateRuleModifiesFields(): void
    {
        $repo = $this->makeRepo();
        $rotatorId = $repo->create(1, ['name' => 'Rotator']);
        $ruleId = $repo->createRule($rotatorId, ['rule_name' => 'Original']);

        $repo->updateRule($ruleId, $rotatorId, [
            'rule_name' => 'Updated',
            'splittest' => 1,
        ]);

        $rotator = $repo->findById($rotatorId, 1);
        self::assertSame('Updated', $rotator['rules'][0]['rule_name']);
        self::assertSame(1, $rotator['rules'][0]['splittest']);
    }

    public function testUpdateRuleReplacesCriteria(): void
    {
        $repo = $this->makeRepo();
        $rotatorId = $repo->create(1, ['name' => 'Rotator']);
        $ruleId = $repo->createRule($rotatorId, [
            'rule_name' => 'Rule',
            'criteria' => [
                ['type' => 'country', 'statement' => 'is', 'value' => 'US'],
            ],
        ]);

        $repo->updateRule($ruleId, $rotatorId, [
            'criteria' => [
                ['type' => 'device', 'statement' => 'is', 'value' => 'desktop'],
                ['type' => 'browser', 'statement' => 'is', 'value' => 'Chrome'],
            ],
        ]);

        $rotator = $repo->findById($rotatorId, 1);
        self::assertCount(2, $rotator['rules'][0]['criteria']);
        self::assertSame('device', $rotator['rules'][0]['criteria'][0]['type']);
    }

    public function testUpdateRuleReplacesRedirects(): void
    {
        $repo = $this->makeRepo();
        $rotatorId = $repo->create(1, ['name' => 'Rotator']);
        $ruleId = $repo->createRule($rotatorId, [
            'rule_name' => 'Rule',
            'redirects' => [
                ['redirect_url' => 'https://a.com', 'weight' => 100],
            ],
        ]);

        $repo->updateRule($ruleId, $rotatorId, [
            'redirects' => [
                ['redirect_url' => 'https://b.com', 'weight' => 50],
                ['redirect_url' => 'https://c.com', 'weight' => 50],
            ],
        ]);

        $rotator = $repo->findById($rotatorId, 1);
        self::assertCount(2, $rotator['rules'][0]['redirects']);
        self::assertSame('https://b.com', $rotator['rules'][0]['redirects'][0]['redirect_url']);
    }

    public function testUpdateRuleThrowsForWrongRotator(): void
    {
        $repo = $this->makeRepo();
        $rotatorId = $repo->create(1, ['name' => 'Rotator']);
        $ruleId = $repo->createRule($rotatorId, ['rule_name' => 'Rule']);

        $this->expectException(RuntimeException::class);
        $repo->updateRule($ruleId, 9999, ['rule_name' => 'Hacked']);
    }

    // --- Delete Rule ---

    public function testDeleteRuleRemovesRuleAndChildren(): void
    {
        $repo = $this->makeRepo();
        $rotatorId = $repo->create(1, ['name' => 'Rotator']);

        $rule1 = $repo->createRule($rotatorId, [
            'rule_name' => 'Rule 1',
            'criteria' => [['type' => 'c', 'statement' => 's', 'value' => 'v']],
            'redirects' => [['redirect_url' => 'https://a.com']],
        ]);
        $rule2 = $repo->createRule($rotatorId, [
            'rule_name' => 'Rule 2',
            'criteria' => [['type' => 'c2', 'statement' => 's2', 'value' => 'v2']],
        ]);

        $repo->deleteRule($rule1, $rotatorId);

        $rotator = $repo->findById($rotatorId, 1);
        self::assertCount(1, $rotator['rules']);
        self::assertSame('Rule 2', $rotator['rules'][0]['rule_name']);

        // Rule 1's criteria and redirects should be gone
        foreach ($repo->criteria as $c) {
            self::assertNotSame($rule1, $c['rule_id']);
        }
        foreach ($repo->redirects as $rd) {
            self::assertNotSame($rule1, $rd['rule_id']);
        }
    }

    // --- Multiple Rules Ordering ---

    public function testMultipleRulesAreOrderedByIdAsc(): void
    {
        $repo = $this->makeRepo();
        $rotatorId = $repo->create(1, ['name' => 'Rotator']);

        $repo->createRule($rotatorId, ['rule_name' => 'First']);
        $repo->createRule($rotatorId, ['rule_name' => 'Second']);
        $repo->createRule($rotatorId, ['rule_name' => 'Third']);

        $rotator = $repo->findById($rotatorId, 1);
        self::assertCount(3, $rotator['rules']);
        self::assertSame('First', $rotator['rules'][0]['rule_name']);
        self::assertSame('Second', $rotator['rules'][1]['rule_name']);
        self::assertSame('Third', $rotator['rules'][2]['rule_name']);
    }
}
