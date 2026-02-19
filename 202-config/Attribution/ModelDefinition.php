<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

use InvalidArgumentException;

/**
 * Immutable value object describing an attribution model configuration.
 */
final readonly class ModelDefinition
{
    public function __construct(
        public ?int $modelId,
        public int $userId,
        public string $name,
        public string $slug,
        public ModelType $type,
        public array $weightingConfig,
        public bool $isActive,
        public bool $isDefault,
        public int $createdAt,
        public int $updatedAt
    ) {
        if ($this->name === '') {
            throw new InvalidArgumentException('Model name cannot be empty.');
        }

        if ($this->slug === '') {
            throw new InvalidArgumentException('Model slug cannot be empty.');
        }

        if ($this->type->requiresWeighting() && empty($this->weightingConfig)) {
            throw new InvalidArgumentException('Weighting configuration required for the selected model type.');
        }

        $this->assertValidWeightingConfig();
    }

    /**
     * Hydrates a model definition from database row data.
     *
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            modelId: isset($row['model_id']) ? (int) $row['model_id'] : null,
            userId: (int) $row['user_id'],
            name: (string) $row['model_name'],
            slug: (string) $row['model_slug'],
            type: ModelType::from((string) $row['model_type']),
            weightingConfig: self::decodeConfig($row['weighting_config'] ?? null),
            isActive: (bool) $row['is_active'],
            isDefault: (bool) $row['is_default'],
            createdAt: (int) $row['created_at'],
            updatedAt: (int) $row['updated_at']
        );
    }

    /**
     * Serialises the model definition for persistence.
     *
     * @return array<string, mixed>
     */
    public function toDatabaseRow(): array
    {
        return [
            'model_id' => $this->modelId,
            'user_id' => $this->userId,
            'model_name' => $this->name,
            'model_slug' => $this->slug,
            'model_type' => $this->type->value,
            'weighting_config' => $this->encodeConfig(),
            'is_active' => (int) $this->isActive,
            'is_default' => (int) $this->isDefault,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * @param array<string, mixed>|null $config
     *
     * @return array<string, mixed>
     */
    private static function decodeConfig(array|string|null $config): array
    {
        if (is_array($config)) {
            return $config;
        }

        if (is_string($config) && $config !== '') {
            $decoded = json_decode($config, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new InvalidArgumentException('Invalid weighting configuration payload.');
            }

            return $decoded;
        }

        return [];
    }

    /**
     * @return string|null
     */
    private function encodeConfig(): ?string
    {
        if (empty($this->weightingConfig)) {
            return null;
        }

        return json_encode($this->weightingConfig, JSON_THROW_ON_ERROR);
    }

    private function assertValidWeightingConfig(): void
    {
        switch ($this->type) {
            case ModelType::TIME_DECAY:
                $halfLife = $this->weightingConfig['half_life_hours'] ?? null;
                if (!is_numeric($halfLife) || (int) $halfLife <= 0) {
                    throw new InvalidArgumentException('Time decay models require a positive integer "half_life_hours" value.');
                }
                break;

            case ModelType::POSITION_BASED:
                $first = $this->weightingConfig['first_touch_weight'] ?? null;
                $last = $this->weightingConfig['last_touch_weight'] ?? null;
                if (!is_numeric($first) || !is_numeric($last)) {
                    throw new InvalidArgumentException('Position based models require numeric "first_touch_weight" and "last_touch_weight" values.');
                }
                $first = (float) $first;
                $last = (float) $last;
                if ($first < 0 || $last < 0 || $first > 1 || $last > 1) {
                    throw new InvalidArgumentException('Position based weights must be between 0 and 1.');
                }
                if (($first + $last) > 1.0) {
                    throw new InvalidArgumentException('The sum of first and last touch weights must not exceed 1.');
                }
                break;

            case ModelType::ALGORITHMIC:
                if (empty($this->weightingConfig)) {
                    throw new InvalidArgumentException('Algorithmic models must include configuration details.');
                }
                break;

            case ModelType::ASSISTED:
            case ModelType::LAST_TOUCH:
                if (!empty($this->weightingConfig)) {
                    throw new InvalidArgumentException('This attribution model does not accept weighting parameters.');
                }
                break;
        }
    }
}
