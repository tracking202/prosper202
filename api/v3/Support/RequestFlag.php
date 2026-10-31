<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Api\V3\Exception\ValidationException;

/**
 * A true/false value a request names, read strictly: a flag of a hand-read
 * body (a sync job's dry_run, a custom field's is_required).
 *
 * These were read as `(bool) ($payload['force_update'] ?? false)` and
 * `!empty($payload['is_required'])`, and both make every non-empty string
 * true: `"force_update": "false"` ran a sync that overwrote every target
 * record differing from its source (left alone without the flag),
 * `"skip_errors": "false"` carried on past the errors it was asked to stop
 * at, and `"is_required": "false"` made the field required
 * (CLAUDE.md #4). A flag is a JSON true or false, or the spellings a form or
 * a shell hands over for one (1 and 0, "true" and "false", "1" and "0");
 * anything else is a 422 naming the field.
 */
final class RequestFlag
{
    public const ACCEPTED = 'must be true or false (1 and 0, and "true" and "false" as strings, are read as those)';

    private function __construct()
    {
    }

    /**
     * $name from $params as a flag; absent, null or '' is $default.
     *
     * @param array<string, mixed> $params
     */
    public static function param(array $params, string $name, bool $default): bool
    {
        if (!array_key_exists($name, $params) || $params[$name] === null || $params[$name] === '') {
            return $default;
        }
        $flag = self::read($params[$name]);
        if ($flag === null) {
            throw new ValidationException('Invalid ' . $name, [$name => self::ACCEPTED]);
        }

        return $flag;
    }

    /** $value as a flag, or null when it is not one. */
    public static function read(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === 0) {
            return $value === 1;
        }
        if (!is_string($value)) {
            return null;
        }

        return match (strtolower($value)) {
            'true', '1' => true,
            'false', '0' => false,
            default => null,
        };
    }
}
