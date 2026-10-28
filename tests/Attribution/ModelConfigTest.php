<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\InvalidModelConfig;
use Prosper202\Attribution\ModelConfig;
use Prosper202\Attribution\ModelType;

/**
 * The one validator for model definitions, on write and on load: defaults
 * filled in, unknown keys and wrong types refused with the field named, and
 * a stored definition that no longer validates reported, not thrown past.
 */
final class ModelConfigTest extends TestCase
{
    public function testDefaultsAreFilledIn(): void
    {
        self::assertSame([], ModelConfig::normalize(ModelType::LINEAR, null));
        self::assertSame([], ModelConfig::normalize(ModelType::LAST_TOUCH, []));
        self::assertSame(['half_life_hours' => 48.0], ModelConfig::normalize(ModelType::TIME_DECAY, []));
        self::assertSame(['first_weight' => 0.4, 'last_weight' => 0.4], ModelConfig::normalize(ModelType::POSITION_BASED, []));
        self::assertSame(['first_weight' => 0.6, 'last_weight' => 0.4], ModelConfig::normalize(ModelType::POSITION_BASED, ['first_weight' => 0.6]));
    }

    /** @return array<string, array{0: ModelType, 1: mixed, 2: string}> */
    public static function invalid(): array
    {
        return [
            'a list, not an object' => [ModelType::LINEAR, [1, 2], 'weighting_config'],
            'a string' => [ModelType::TIME_DECAY, '{"half_life_hours":4}', 'weighting_config'],
            'a key the type does not take' => [ModelType::LINEAR, ['half_life_hours' => 4], 'weighting_config.half_life_hours'],
            'a typo' => [ModelType::TIME_DECAY, ['half_life' => 4], 'weighting_config.half_life'],
            'a numeric string' => [ModelType::TIME_DECAY, ['half_life_hours' => '4'], 'weighting_config.half_life_hours'],
            'a boolean' => [ModelType::POSITION_BASED, ['first_weight' => true], 'weighting_config.first_weight'],
            'zero half-life' => [ModelType::TIME_DECAY, ['half_life_hours' => 0], 'weighting_config.half_life_hours'],
            'negative half-life' => [ModelType::TIME_DECAY, ['half_life_hours' => -1.5], 'weighting_config.half_life_hours'],
            'half-life over a year' => [ModelType::TIME_DECAY, ['half_life_hours' => 9000], 'weighting_config.half_life_hours'],
            'weight above one' => [ModelType::POSITION_BASED, ['first_weight' => 1.2, 'last_weight' => 0], 'weighting_config.first_weight'],
            'negative weight' => [ModelType::POSITION_BASED, ['last_weight' => -0.1], 'weighting_config.last_weight'],
            'weights over one together' => [ModelType::POSITION_BASED, ['first_weight' => 0.7, 'last_weight' => 0.4], 'weighting_config'],
        ];
    }

    /** @dataProvider invalid */
    public function testInvalidConfigsNameTheField(ModelType $type, mixed $config, string $field): void
    {
        try {
            ModelConfig::normalize($type, $config);
            self::fail('accepted an invalid config');
        } catch (InvalidModelConfig $e) {
            self::assertArrayHasKey($field, $e->fieldErrors(), $e->getMessage());
        }
    }

    public function testWeightsThatSumToExactlyOneAreAccepted(): void
    {
        self::assertSame(['first_weight' => 0.6, 'last_weight' => 0.4], ModelConfig::normalize(ModelType::POSITION_BASED, ['first_weight' => 0.6, 'last_weight' => 0.4]));
    }

    public function testLookback(): void
    {
        self::assertSame(30, ModelConfig::lookbackDays(null));
        self::assertSame(1, ModelConfig::lookbackDays(1));
        self::assertSame(365, ModelConfig::lookbackDays(365));
        foreach ([0, 366, '30', 30.0, -1, true] as $bad) {
            try {
                ModelConfig::lookbackDays($bad);
                self::fail('accepted lookback ' . var_export($bad, true));
            } catch (InvalidModelConfig $e) {
                self::assertArrayHasKey('lookback_days', $e->fieldErrors());
            }
        }
    }

    public function testTheLoadPathReportsWhatIsWrongWithAStoredRow(): void
    {
        $ok = ModelConfig::fromStored('time_decay', '{"half_life_hours":12}', '30');
        self::assertSame(ModelType::TIME_DECAY, $ok['type']);
        self::assertSame(['half_life_hours' => 12.0], $ok['config']);

        foreach ([
            ['algorithmic', '{}', 30, 'model_type'],
            ['', '{}', 30, 'model_type'],
            ['linear', '{"x":', 30, 'weighting_config'],
            ['linear', '{"x":1}', 30, 'weighting_config.x'],
            ['linear', '{}', 0, 'lookback_days'],
        ] as [$type, $json, $lookback, $field]) {
            try {
                ModelConfig::fromStored($type, $json, $lookback);
                self::fail("accepted stored $type $json");
            } catch (InvalidModelConfig $e) {
                self::assertArrayHasKey($field, $e->fieldErrors());
            }
        }
    }

    public function testAnEmptyConfigIsStoredAsAnObject(): void
    {
        self::assertSame('{}', ModelConfig::encode([]));
        self::assertSame('{"half_life_hours":48.0}', ModelConfig::encode(['half_life_hours' => 48.0]));
    }
}
