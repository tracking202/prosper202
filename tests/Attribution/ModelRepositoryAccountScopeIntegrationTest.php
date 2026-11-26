<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\ModelConfig;
use Prosper202\Attribution\ModelRepository;
use Prosper202\Attribution\ModelType;
use Tests\Attribution\Support\AttributionDatabase;

/**
 * ModelRepository's writes take the account they are given at its word: a
 * model id of another account changes nothing of that account's.
 *
 * delete() and update() removed the model's credits and report rollup rows
 * by model id alone, before (delete) or whatever (update) the account-tied
 * write of the model found, and markInvalid() marked any model by id
 * (CLAUDE.md #27; AccountScopedWriteTest reads these writes).
 *
 * @group integration
 */
final class ModelRepositoryAccountScopeIntegrationTest extends TestCase
{
    use AttributionDatabase;

    private const OTHER = 2;

    public function testAnotherAccountsDeleteLeavesTheModelAndItsCredits(): void
    {
        [$model, $credits, $rollup] = $this->modelWithCredits();

        (new ModelRepository($this->conn))->delete(self::OTHER, $model);

        self::assertSame(1, (int) self::scalar("SELECT COUNT(*) FROM 202_attribution_models WHERE model_id = $model"));
        self::assertSame($credits, $this->creditRows($model), 'the credits of account 1\'s model are kept');
        self::assertSame($rollup, $this->rollupRows($model), 'its report rollup rows are kept');
    }

    public function testAnotherAccountsDeactivationLeavesTheCredits(): void
    {
        [$model, $credits, $rollup] = $this->modelWithCredits();

        (new ModelRepository($this->conn))->update(
            self::OTHER, $model, 'Taken', 'taken', ModelType::LINEAR, ModelConfig::normalize(ModelType::LINEAR, []),
            30, 'inactive', false, false
        );

        self::assertSame('active', (string) self::scalar("SELECT status FROM 202_attribution_models WHERE model_id = $model"));
        self::assertSame($credits, $this->creditRows($model));
        self::assertSame($rollup, $this->rollupRows($model));
    }

    public function testAnotherAccountCannotMarkTheModelInvalid(): void
    {
        [$model] = $this->modelWithCredits();

        (new ModelRepository($this->conn))->markInvalid(self::OTHER, $model, 'not yours');

        self::assertSame('active', (string) self::scalar("SELECT status FROM 202_attribution_models WHERE model_id = $model"));
    }

    public function testTheOwnersDeleteStillRemovesThem(): void
    {
        [$model] = $this->modelWithCredits();

        (new ModelRepository($this->conn))->delete(1, $model);

        self::assertSame(0, (int) self::scalar("SELECT COUNT(*) FROM 202_attribution_models WHERE model_id = $model"));
        self::assertSame(0, $this->creditRows($model));
        self::assertSame(0, $this->rollupRows($model));
    }

    /** @return array{0: int, 1: int, 2: int} the model, its credit rows and its rollup rows */
    private function modelWithCredits(): array
    {
        self::fixture("INSERT INTO 202_users SET user_id=2, user_name='other', user_email='x@example.test', user_time_register=1, user_deleted=0, user_active=1");
        $this->campaign(1);
        $model = $this->addModel('Linear', ModelType::LINEAR);
        $now = time();
        $this->click(10, 1, $now - 3600);
        $this->click(11, 1, $now - 600);
        $this->convert(11, '5.00', 'sale');
        $this->work();
        self::fixture('INSERT INTO 202_attribution_rollup (user_id, part, dim, model_id, grain, bucket, key_null, dim_key, n, credit, revenue, cost)'
            . ' VALUES (1, ' . \Prosper202\Attribution\AttributionRollup::PART_CREDITS . ', 0, ' . $model . ', 0, 0, 0, 0, 1, 0, 0, 0)'
            . ' ON DUPLICATE KEY UPDATE n = n');
        $credits = $this->creditRows($model);
        $rollup = $this->rollupRows($model);
        self::assertGreaterThan(0, $credits, 'the worker credited the model');
        self::assertGreaterThan(0, $rollup, 'the model has rollup rows');

        return [$model, $credits, $rollup];
    }

    private function creditRows(int $model): int
    {
        return (int) self::scalar("SELECT COUNT(*) FROM 202_attribution_credits WHERE model_id = $model");
    }

    private function rollupRows(int $model): int
    {
        return (int) self::scalar("SELECT COUNT(*) FROM 202_attribution_rollup WHERE model_id = $model AND part IN ("
            . \Prosper202\Attribution\AttributionRollup::PART_CREDITS . ', ' . \Prosper202\Attribution\AttributionRollup::PART_TOTALS . ')');
    }
}
