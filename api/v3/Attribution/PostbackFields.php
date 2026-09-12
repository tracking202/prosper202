<?php

declare(strict_types=1);

namespace Api\V3\Attribution;

/**
 * The postback fields SKAdNetwork and AdAttributionKit share, and the rules
 * for them.
 *
 * Both families carry the same unsigned conversion fields under the same
 * hyphenated Apple names — source-identifier, conversion-value,
 * coarse-conversion-value, country-code — and both write them to the same
 * columns of the same table. A rule tightened in one protocol and not the
 * other would leave the pair disagreeing about what the column may hold, so
 * the rules live here and each protocol calls them where its own error order
 * puts them.
 *
 * The messages are a documented contract: they are what the 400 envelope
 * shows an operator, keyed by Apple's own field name so it can be matched
 * against "Identifying the parameters in a postback". Change one only when
 * the documentation changes with it.
 */
final class PostbackFields
{
    /**
     * Apple's coarse conversion value vocabulary. The `coarse_conversion_value`
     * column holds exactly these; the conversion-value controllers enforce the
     * same list on the values an operator configures.
     */
    public const COARSE_VALUES = ['low', 'medium', 'high'];

    /**
     * Check one shared field, returning either nothing or the single error
     * keyed by Apple's name — so a caller appends it with `$errors += ...`
     * and keeps its own field order.
     *
     * An absent field is never an error: every one of these is optional,
     * withheld by Apple's postback data tier. A present one is checked
     * strictly. A key this class does not know raises \UnhandledMatchError
     * rather than reporting "no problems": a validator must not answer for a
     * field it cannot judge.
     *
     * @param array<string, mixed> $fields
     * @return array<string, string>
     */
    public static function errorsFor(array $fields, string $key): array
    {
        if (!array_key_exists($key, $fields)) {
            return [];
        }

        $value = $fields[$key];
        $message = match ($key) {
            'source-identifier' => is_string($value) && preg_match('/^\d{1,4}$/D', $value) === 1
                ? null : 'Must be a string of 1-4 digits',
            'conversion-value' => is_int($value) && $value >= 0 && $value <= 63
                ? null : 'Must be an integer from 0 to 63',
            'coarse-conversion-value' => in_array($value, self::COARSE_VALUES, true)
                ? null : 'Must be one of: low, medium, high',
            'country-code' => is_string($value) && trim($value) !== '' && strlen($value) <= 8
                ? null : 'Must be a short country identifier string',
        };

        return $message === null ? [] : [$key => $message];
    }

    /**
     * The value of an optional field as a string, or null when it is absent.
     * Both protocols build their columns only once validation has passed, so
     * the cast is over a value already known to be the right shape.
     *
     * @param array<string, mixed> $fields
     */
    public static function optString(array $fields, string $key): ?string
    {
        return array_key_exists($key, $fields) ? (string)$fields[$key] : null;
    }

    /**
     * The value of an optional field as an int, or null when it is absent.
     *
     * @param array<string, mixed> $fields
     */
    public static function optInt(array $fields, string $key): ?int
    {
        return array_key_exists($key, $fields) ? (int)$fields[$key] : null;
    }
}
