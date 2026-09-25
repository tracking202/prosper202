<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

/**
 * A loaded, validated attribution model. Only ModelRepository builds these,
 * and only from a row whose definition passed ModelConfig; a row that did
 * not is reported as invalid instead of becoming one.
 */
final class Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_INVALID = 'invalid';

    /** @param array<string, float> $config */
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly string $name,
        public readonly string $slug,
        public readonly ModelType $type,
        public readonly array $config,
        public readonly int $lookbackDays,
        public readonly string $status,
        public readonly bool $isDefault,
    ) {
    }

    /** The widest window, in seconds, this model credits touches from. */
    public function lookbackSeconds(): int
    {
        return $this->lookbackDays * 86400;
    }
}
