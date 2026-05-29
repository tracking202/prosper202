<?php

declare(strict_types=1);

namespace Tests\Attribution;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\ModelDefinition;
use Prosper202\Attribution\ModelType;

final class ModelDefinitionValidationTest extends TestCase
{
    public function testTimeDecayRequiresPositiveHalfLife(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('half_life_hours');

        new ModelDefinition(
            modelId: null,
            userId: 1,
            name: 'TD',
            slug: 'td',
            type: ModelType::TIME_DECAY,
            weightingConfig: ['half_life_hours' => 0],
            isActive: true,
            isDefault: false,
            createdAt: time(),
            updatedAt: time()
        );
    }

    public function testPositionBasedWeightsMustBeWithinRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sum of first and last touch weights');

        new ModelDefinition(
            modelId: null,
            userId: 1,
            name: 'PB',
            slug: 'pb',
            type: ModelType::POSITION_BASED,
            weightingConfig: ['first_touch_weight' => 0.8, 'last_touch_weight' => 0.5],
            isActive: true,
            isDefault: false,
            createdAt: time(),
            updatedAt: time()
        );
    }

    public function testAssistedRejectsWeightingConfig(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not accept weighting');

        new ModelDefinition(
            modelId: null,
            userId: 1,
            name: 'Assisted',
            slug: 'assisted',
            type: ModelType::ASSISTED,
            weightingConfig: ['extra' => 1],
            isActive: true,
            isDefault: false,
            createdAt: time(),
            updatedAt: time()
        );
    }

    public function testValidPositionBasedConfig(): void
    {
        $model = new ModelDefinition(
            modelId: null,
            userId: 2,
            name: 'PB Valid',
            slug: 'pb-valid',
            type: ModelType::POSITION_BASED,
            weightingConfig: ['first_touch_weight' => 0.4, 'last_touch_weight' => 0.4],
            isActive: true,
            isDefault: false,
            createdAt: time(),
            updatedAt: time()
        );

        self::assertSame('pb-valid', $model->slug);
    }
}
