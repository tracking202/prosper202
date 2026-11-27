<?php

declare(strict_types=1);

namespace Tests\Attribution;

use Api\V3\Controllers\AttributionController;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\ModelConfig;
use Prosper202\Attribution\ModelRepository;
use Prosper202\Attribution\ModelStatus;
use Prosper202\Attribution\ModelType;
use Tests\Attribution\Support\AttributionDatabase;

/**
 * An attribution model's status moves only as ModelStatus says, in
 * ModelRepository: a model is marked invalid for the definition that was
 * read and from active alone, an invalid model has no credits, a default
 * model computes or every read that leans on it says why not, and an edit
 * makes a model active only with a definition that validates.
 *
 * @group integration
 */
final class ModelStatusIntegrationTest extends TestCase
{
    use AttributionDatabase;

    private function repo(): ModelRepository
    {
        return new ModelRepository($this->conn);
    }

    private static function status(int $model): string
    {
        return (string) self::scalar("SELECT status FROM 202_attribution_models WHERE model_id = $model");
    }

    private static function credits(int $model): int
    {
        return (int) self::scalar("SELECT COUNT(*) FROM 202_attribution_credits WHERE model_id = $model");
    }

    /** A sale credited under every model the account has. */
    private function sale(): void
    {
        $this->campaign(1);
        $now = time();
        $this->click(10, 1, $now - 3600);
        $this->click(11, 1, $now - 600);
        $this->convert(11, '5.00', 'sale');
        $this->work();
    }

    private static function breakDefinition(int $model): void
    {
        self::fixture("UPDATE 202_attribution_models SET weighting_config = '{\"half_life\": 3}' WHERE model_id = $model");
    }

    public function testAModelMarkedInvalidKeepsNoCredits(): void
    {
        $model = $this->addModel('Linear', ModelType::LINEAR);
        $this->sale();
        self::assertGreaterThan(0, self::credits($model));
        self::breakDefinition($model);

        $computable = $this->repo()->activeModels(1);

        self::assertSame('invalid', self::status($model));
        self::assertNotContains($model, array_map(static fn ($m): int => $m->id, $computable));
        self::assertSame(0, self::credits($model), 'a model that is not computed has no credits to read');
    }

    public function testAnInvalidDefaultIsSaidByTheReportThatLeansOnIt(): void
    {
        $this->sale();
        $default = $this->defaultModelId();
        self::breakDefinition($default);
        $this->repo()->activeModels(1);
        self::assertSame(['invalid', '1'], [self::status($default), (string) self::scalar("SELECT is_default FROM 202_attribution_models WHERE model_id = $default")]);

        try {
            (new AttributionController(self::$db, 1))->breakdown(['group_by' => 'campaign']);
            self::fail('the report read the credits of a default model that is no longer computed');
        } catch (ConflictException $e) {
            self::assertStringContainsString('default', $e->getMessage());
            self::assertStringContainsString('invalid', $e->getMessage());
            self::assertStringContainsString('half_life', $e->getMessage(), 'the reason is given');
        }
    }

    public function testAReportOfAnotherModelDoesNotNeedTheDefault(): void
    {
        $linear = $this->addModel('Linear', ModelType::LINEAR);
        $this->sale();
        $default = $this->defaultModelId();
        self::breakDefinition($default);
        $this->repo()->activeModels(1);

        $out = (new AttributionController(self::$db, 1))->breakdown(['group_by' => 'campaign', 'model_id' => (string) $linear]);

        self::assertSame('5.00000', $out['totals']['attributed_revenue']);
    }

    public function testADefinitionRepairedSinceItWasReadIsNotMarkedInvalid(): void
    {
        $model = $this->addModel('Decay', ModelType::TIME_DECAY, ['half_life_hours' => 12.0]);
        self::breakDefinition($model);
        $read = $this->repo()->row(1, $model);
        // Another request repairs it between the worker's read and its mark.
        self::fixture("UPDATE 202_attribution_models SET weighting_config = '{\"half_life_hours\": 24.0}' WHERE model_id = $model");

        $this->repo()->markInvalid(1, $model, 'weighting_config.half_life: unknown key', $read);

        self::assertSame('active', self::status($model), 'the mark was for a definition the model no longer has');
    }

    public function testOnlyAnActiveModelIsMarkedInvalid(): void
    {
        $model = $this->addModel('Linear', ModelType::LINEAR);
        self::fixture("UPDATE 202_attribution_models SET status = 'inactive' WHERE model_id = $model");

        $this->repo()->markInvalid(1, $model, 'read as active before it was switched off');

        self::assertSame('inactive', self::status($model));
    }

    public function testAnEditThatLeavesAnInvalidLookbackDoesNotMakeTheModelActive(): void
    {
        $model = $this->addModel('Linear', ModelType::LINEAR);
        self::fixture("UPDATE 202_attribution_models SET lookback_days = 0 WHERE model_id = $model");
        $this->repo()->activeModels(1);
        self::assertSame('invalid', self::status($model));

        try {
            (new AttributionController(self::$db, 1))->updateModel($model, ['model_name' => 'Renamed']);
            self::fail('an edit that validated no lookback made the model active');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('lookback_days', $e->getFieldErrors());
        }
        self::assertSame('invalid', self::status($model));

        $fixed = (new AttributionController(self::$db, 1))->updateModel($model, ['lookback_days' => 14])['data'];
        self::assertSame(['active', 14, true], [$fixed['status'], $fixed['lookback_days'], $fixed['recompute_pending']]);
    }

    public function testTheRepositoryRefusesADefaultThatIsNotActive(): void
    {
        $config = ModelConfig::normalize(ModelType::LINEAR, []);
        $default = $this->defaultModelId();
        try {
            $this->repo()->insert(1, 'Off', 'off', ModelType::LINEAR, $config, 30, ModelStatus::Inactive, true);
            self::fail('an inactive default was inserted');
        } catch (\DomainException) {
        }
        try {
            $this->repo()->update(1, $default, 'Last touch', 'last-touch', ModelType::LAST_TOUCH, [], 30, ModelStatus::Inactive, true, false);
            self::fail('the default was switched off');
        } catch (\DomainException) {
        }

        self::assertSame([['model_id' => (string) $default, 'status' => 'active']], self::all('SELECT model_id, status FROM 202_attribution_models WHERE is_default = 1'));
    }

    public function testAnInvalidModelBecomesActiveByAnEditThatStoresAValidDefinition(): void
    {
        $model = $this->addModel('Decay', ModelType::TIME_DECAY, ['half_life_hours' => 12.0]);
        self::breakDefinition($model);
        $this->repo()->activeModels(1);
        $api = new AttributionController(self::$db, 1);

        try {
            $api->updateModel($model, ['model_name' => 'Decay 2']);
            self::fail('an edit that kept the stored config made the model active');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('weighting_config.half_life', $e->getFieldErrors());
        }
        self::assertSame('invalid', self::status($model));

        $fixed = $api->updateModel($model, ['weighting_config' => ['half_life_hours' => 24]])['data'];
        self::assertSame(['active', null, true], [$fixed['status'], $fixed['status_reason'], $fixed['recompute_pending']]);
    }

    public function testAnUpdateFromAStateTheModelHasLeftChangesNothing(): void
    {
        $model = $this->addModel('Linear', ModelType::LINEAR);
        self::fixture("UPDATE 202_attribution_models SET status = 'inactive' WHERE model_id = $model");

        // Decided on a row read as active; another request switched it off since.
        $written = $this->repo()->update(1, $model, 'Renamed', 'renamed', ModelType::LINEAR, [], 30, ModelStatus::Active, false, true, ModelStatus::Active);

        self::assertFalse($written);
        self::assertSame(
            [['model_name' => 'Linear', 'status' => 'inactive']],
            self::all("SELECT model_name, status FROM 202_attribution_models WHERE model_id = $model")
        );
    }

    public function testAModelThatDoesNotTakeTheDefaultLeavesTheOldOneTheDefault(): void
    {
        $model = $this->addModel('Linear', ModelType::LINEAR);
        $default = $this->defaultModelId();
        self::fixture("UPDATE 202_attribution_models SET status = 'inactive' WHERE model_id = $model");

        $written = $this->conn->transaction(fn (): bool => $this->repo()->update(
            1, $model, 'Linear', 'linear', ModelType::LINEAR, [], 30, ModelStatus::Active, true, true, ModelStatus::Active
        ));

        self::assertFalse($written);
        self::assertSame($default, $this->defaultModelId(), 'the account keeps its default');

        $written = $this->conn->transaction(fn (): bool => $this->repo()->update(
            1, $model, 'Linear', 'linear', ModelType::LINEAR, [], 30, ModelStatus::Active, true, true, ModelStatus::Inactive
        ));
        self::assertTrue($written);
        self::assertSame($model, $this->defaultModelId());
        self::assertSame(1, (int) self::scalar('SELECT COUNT(*) FROM 202_attribution_models WHERE user_id = 1 AND is_default = 1'));
    }

    public function testTheDefaultStopsBeingItOnlyWhenAnotherModelBecomesIt(): void
    {
        $default = $this->defaultModelId();

        self::assertFalse($this->repo()->update(1, $default, 'Last touch', 'last-touch', ModelType::LAST_TOUCH, [], 30, ModelStatus::Active, false, false));
        self::assertFalse($this->repo()->update(1, $default, 'Last touch', 'last-touch', ModelType::LAST_TOUCH, [], 30, ModelStatus::Inactive, false, false));

        self::assertSame($default, $this->defaultModelId());
        self::assertSame('active', self::status($default));
    }

    public function testTheSameEditAgainWithinTheSecondIsWritten(): void
    {
        $model = $this->addModel('Linear', ModelType::LINEAR);
        $edit = fn (): bool => $this->repo()->update(1, $model, 'Linear', 'linear', ModelType::LINEAR, [], 30, ModelStatus::Active, false, false);

        self::assertSame([true, true, true], [$edit(), $edit(), $edit()], 'a row that already holds what was written is not a refused move');
    }

    public function testNoWriteAsksForInvalidOrForAnInvalidDefinition(): void
    {
        $model = $this->addModel('Linear', ModelType::LINEAR);
        $refused = [];
        foreach ([
            'created invalid' => fn () => $this->repo()->insert(1, 'Bad', 'bad', ModelType::LINEAR, [], 30, ModelStatus::Invalid, false),
            'edited to invalid' => fn () => $this->repo()->update(1, $model, 'Linear', 'linear', ModelType::LINEAR, [], 30, ModelStatus::Invalid, false, false),
            'a status that is not one' => fn () => $this->repo()->update(1, $model, 'Linear', 'linear', ModelType::LINEAR, [], 30, 'paused', false, false),
            'a lookback out of range' => fn () => $this->repo()->update(1, $model, 'Linear', 'linear', ModelType::LINEAR, [], 0, ModelStatus::Active, false, false),
            'a config its type does not take' => fn () => $this->repo()->insert(1, 'Bad', 'bad', ModelType::LINEAR, ['half_life_hours' => 2.0], 30, ModelStatus::Active, false),
        ] as $what => $write) {
            try {
                $write();
            } catch (\DomainException | \UnexpectedValueException | \InvalidArgumentException) {
                $refused[] = $what;
            }
        }

        self::assertSame(['created invalid', 'edited to invalid', 'a status that is not one', 'a lookback out of range', 'a config its type does not take'], $refused);
        self::assertSame(
            [['model_name' => 'Last touch', 'status' => 'active', 'lookback_days' => '30'], ['model_name' => 'Linear', 'status' => 'active', 'lookback_days' => '30']],
            self::all('SELECT model_name, status, lookback_days FROM 202_attribution_models ORDER BY model_id')
        );
    }
}
