<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

/**
 * The one validator for a model's definition, run on write (the API) and on
 * load (the worker and the reports). A definition that fails on load marks
 * that model invalid with the reason; it never throws through other models.
 *
 * Weighting configs, by type:
 *
 * - last_touch, first_touch, linear: `{}` — they take no parameters;
 * - time_decay: `{"half_life_hours": h}`, 0 < h <= 8760, default 48;
 * - position_based: `{"first_weight": f, "last_weight": l}`, each in
 *   [0, 1] with f + l <= 1, defaults 0.4 / 0.4.
 *
 * An unknown key is refused rather than ignored: a typo such as
 * `half_life` would otherwise compute the default and look like it worked
 * (CLAUDE.md error pattern #4). Values must be JSON numbers; a numeric
 * string is refused rather than cast (error pattern #18).
 *
 * The lookback is a column, not a config key: 1–365 days, default 30. The
 * 25-touch journey cap is fixed (plan §7.3) and no model can ask for more,
 * so there is no per-model touch parameter to validate.
 */
final class ModelConfig
{
    public const DEFAULT_LOOKBACK_DAYS = 30;
    public const MAX_LOOKBACK_DAYS = 365;
    public const DEFAULT_HALF_LIFE_HOURS = 48.0;
    public const MAX_HALF_LIFE_HOURS = 8760.0;
    public const DEFAULT_FIRST_WEIGHT = 0.4;
    public const DEFAULT_LAST_WEIGHT = 0.4;

    private function __construct()
    {
    }

    /**
     * Validate a decoded weighting config and return it with defaults filled in.
     *
     * @return array<string, float>
     * @throws InvalidModelConfig
     */
    public static function normalize(ModelType $type, mixed $config): array
    {
        if ($config === null) {
            $config = [];
        }
        if (!is_array($config) || ($config !== [] && array_is_list($config))) {
            throw new InvalidModelConfig(['weighting_config' => 'must be a JSON object']);
        }

        $allowed = match ($type) {
            ModelType::TIME_DECAY => ['half_life_hours'],
            ModelType::POSITION_BASED => ['first_weight', 'last_weight'],
            default => [],
        };
        $errors = [];
        foreach (array_keys($config) as $key) {
            if (!in_array($key, $allowed, true)) {
                $errors['weighting_config.' . $key] = $allowed === []
                    ? 'unknown key; ' . $type->value . ' takes no parameters'
                    : 'unknown key; ' . $type->value . ' takes ' . implode(', ', $allowed);
            }
        }
        foreach ($allowed as $key) {
            if (array_key_exists($key, $config) && !is_int($config[$key]) && !is_float($config[$key])) {
                $errors['weighting_config.' . $key] = 'must be a number';
            }
        }
        if ($errors !== []) {
            throw new InvalidModelConfig($errors);
        }

        if ($type === ModelType::TIME_DECAY) {
            $half = (float) ($config['half_life_hours'] ?? self::DEFAULT_HALF_LIFE_HOURS);
            if (!is_finite($half) || $half <= 0.0 || $half > self::MAX_HALF_LIFE_HOURS) {
                throw new InvalidModelConfig([
                    'weighting_config.half_life_hours' => 'must be greater than 0 and at most ' . (int) self::MAX_HALF_LIFE_HOURS,
                ]);
            }

            return ['half_life_hours' => $half];
        }

        if ($type === ModelType::POSITION_BASED) {
            $first = (float) ($config['first_weight'] ?? self::DEFAULT_FIRST_WEIGHT);
            $last = (float) ($config['last_weight'] ?? self::DEFAULT_LAST_WEIGHT);
            foreach (['first_weight' => $first, 'last_weight' => $last] as $key => $value) {
                if (!is_finite($value) || $value < 0.0 || $value > 1.0) {
                    $errors['weighting_config.' . $key] = 'must be between 0 and 1';
                }
            }
            // A small tolerance: 0.6 + 0.4 is 1.0000000000000002 in binary.
            if ($errors === [] && $first + $last > 1.0 + 1e-9) {
                $errors['weighting_config'] = 'first_weight + last_weight must be at most 1';
            }
            if ($errors !== []) {
                throw new InvalidModelConfig($errors);
            }

            return ['first_weight' => $first, 'last_weight' => $last];
        }

        return [];
    }

    /**
     * Validate a lookback in whole days.
     *
     * @throws InvalidModelConfig
     */
    public static function lookbackDays(mixed $value): int
    {
        if ($value === null) {
            return self::DEFAULT_LOOKBACK_DAYS;
        }
        if (!is_int($value) || $value < 1 || $value > self::MAX_LOOKBACK_DAYS) {
            throw new InvalidModelConfig([
                'lookback_days' => 'must be a whole number of days from 1 to ' . self::MAX_LOOKBACK_DAYS,
            ]);
        }

        return $value;
    }

    /**
     * Load a stored definition: the type column and the JSON config column.
     *
     * @return array{type: ModelType, config: array<string, float>}
     * @throws InvalidModelConfig
     */
    public static function fromStored(string $type, string $json, mixed $lookbackDays): array
    {
        $modelType = ModelType::tryFrom($type);
        if ($modelType === null) {
            throw new InvalidModelConfig([
                'model_type' => '"' . $type . '" is not one of ' . implode(', ', ModelType::values()),
            ]);
        }
        try {
            $decoded = json_decode($json === '' ? '{}' : $json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidModelConfig(['weighting_config' => 'is not valid JSON (' . $e->getMessage() . ')']);
        }
        self::lookbackDays(is_numeric($lookbackDays) ? (int) $lookbackDays : $lookbackDays);

        return ['type' => $modelType, 'config' => self::normalize($modelType, $decoded)];
    }

    /**
     * The config as the JSON the column stores.
     *
     * @param array<string, float> $config
     */
    public static function encode(array $config): string
    {
        // An empty PHP array must be stored as an object, not `[]`.
        return json_encode($config === [] ? new \stdClass() : $config, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
