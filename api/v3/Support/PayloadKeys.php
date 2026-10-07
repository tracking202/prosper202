<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Api\V3\Exception\ValidationException;

/**
 * The keys of a request body, checked against the keys its handler reads.
 *
 * A handler that reads `$payload['name']` and nothing else answered 200 to
 * `{"nmae": "x"}` having done nothing, and 201 to a create carrying a field
 * it never writes: the caller was told the request succeeded while part of
 * it was dropped (CLAUDE.md #4). Every route that takes a body therefore
 * refuses, by name and with what it does accept, each key it does not read.
 * Controller::validatePayload() does this for the CRUD resources (it reads
 * their fields()); a handler that reads its body itself calls
 * refuseUnknown() before it reads anything else.
 * PayloadHandlersRefuseUnknownKeysTest holds every handler to one or the
 * other.
 *
 * A read-only key — one the server sets (an id, a public id, `version`) — is
 * accepted only with the value the record already holds, so a body read with
 * GET can be sent back as an update; any other value is refused rather than
 * ignored, since the caller who sent it believes it changed.
 */
final class PayloadKeys
{
    /**
     * Refuse every key of $payload that is not in $accepted: a 422 whose
     * field_errors name each one and list what is accepted.
     *
     * @param array<array-key, mixed> $payload
     * @param list<string> $accepted every key the handler reads
     * @param string $what what the body describes, as the message names it
     *                     ("a conversion", "this redirector")
     * @param array<string, string> $explain a message for a key that is a
     *     known mistake (the key is refused all the same)
     * @throws ValidationException
     */
    public static function refuseUnknown(array $payload, array $accepted, string $what, array $explain = []): void
    {
        $errors = self::unknown($payload, $accepted, $what, $explain);
        if ($errors !== []) {
            throw new ValidationException(count($errors) === 1 ? 'Unknown field' : 'Unknown fields', $errors);
        }
    }

    /**
     * refuseUnknown()'s field errors, for a caller that reports them together
     * with others.
     *
     * @param array<array-key, mixed> $payload
     * @param list<string> $accepted
     * @param array<string, string> $explain
     * @param list<string> $alsoAccepted keys accepted elsewhere (read before
     *     the payload got here), named in the message but not checked
     * @return array<string, string>
     */
    public static function unknown(array $payload, array $accepted, string $what, array $explain = [], array $alsoAccepted = []): array
    {
        $listed = array_values(array_unique([...$accepted, ...$alsoAccepted]));
        $errors = [];
        foreach (array_keys($payload) as $key) {
            $key = (string) $key;
            if (in_array($key, $accepted, true)) {
                continue;
            }
            $errors[$key] = $explain[$key]
                ?? 'is not a field of ' . $what . ' (accepted: ' . ($listed === [] ? 'none' : implode(', ', $listed)) . ')';
        }

        return $errors;
    }

    /**
     * The object a body field holds, read as refuseUnknown() reads the body
     * itself: each key not in $accepted is a field error named with the
     * field in front of it (`customer_crm.frist_name`). A nested object was
     * read by hand, for the keys the handler knew, and the rest dropped:
     * `{"customer_crm": {"frist_name": "Ann"}}` answered 201 with no first
     * name stored (CLAUDE.md #4).
     *
     * Absent or null is nothing to check: whether the field is required is
     * the handler's to say. Anything but an object is refused naming the
     * field. JSON's `{}` and `[]` decode alike, so an empty list reads as an
     * empty object; a list with entries is not one. $values checks the
     * values of the keys it is given, answering errors keyed by the object's
     * own key ('' for the object as a whole); they are reported with the
     * same prefix, beside the unknown keys, so one 422 names everything.
     *
     * @param array<array-key, mixed> $payload
     * @param list<string> $accepted
     * @param (callable(array<array-key, mixed>): array<string, string>)|null $values
     * @return array<string, string>
     */
    public static function objectErrors(
        array $payload,
        string $field,
        array $accepted,
        string $what,
        ?callable $values = null
    ): array {
        $value = $payload[$field] ?? null;
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            return [$field => 'must be an object (' . $what . ': ' . implode(', ', $accepted) . ')'];
        }

        return self::entryErrors($value, $field, $accepted, $what, $values);
    }

    /**
     * The objects of a list a body field holds, each read as objectErrors()
     * reads one, named by position: `items.0.unit_pirce` (the form a rule's
     * criteria and redirects are named in). Absent or null is nothing to
     * check; anything but a list is refused naming the field, and an entry
     * that is not an object naming the entry (`items.1`).
     *
     * @param array<array-key, mixed> $payload
     * @param list<string> $accepted
     * @param (callable(array<array-key, mixed>): array<string, string>)|null $values
     * @return array<string, string>
     */
    public static function listErrors(
        array $payload,
        string $field,
        array $accepted,
        string $what,
        ?callable $values = null
    ): array {
        $list = $payload[$field] ?? null;
        if ($list === null) {
            return [];
        }
        if (!is_array($list) || !array_is_list($list)) {
            return [$field => 'must be a list of objects (' . $what . ': ' . implode(', ', $accepted) . ')'];
        }
        $errors = [];
        foreach ($list as $i => $entry) {
            if (!is_array($entry) || ($entry !== [] && array_is_list($entry))) {
                $errors["$field.$i"] = 'must be an object (' . $what . ': ' . implode(', ', $accepted) . ')';
                continue;
            }
            $errors += self::entryErrors($entry, "$field.$i", $accepted, $what, $values);
        }

        return $errors;
    }

    /**
     * The values of a list a body field holds when its entries are not
     * objects (a field's select options, a webhook's event names): a list,
     * each entry checked by $value, which answers why it is refused or null.
     * Absent or null is nothing to check; anything but a list is refused
     * naming the field, an entry naming its position (`events.1`). There
     * are no keys to refuse, so this is the value half of listErrors().
     *
     * @param array<array-key, mixed> $payload
     * @param callable(mixed): ?string $value
     * @return array<string, string>
     */
    public static function valueListErrors(array $payload, string $field, string $what, callable $value): array
    {
        $list = $payload[$field] ?? null;
        if ($list === null) {
            return [];
        }
        if (!is_array($list) || !array_is_list($list)) {
            return [$field => 'must be a list of ' . $what];
        }
        $errors = [];
        foreach ($list as $i => $entry) {
            $why = $value($entry);
            if ($why !== null) {
                $errors["$field.$i"] = $why;
            }
        }

        return $errors;
    }

    /**
     * Refuse a body whose nested fields were refused (objectErrors(),
     * listErrors(), valueListErrors(), and the value checks beside them):
     * one 422 naming every field.
     *
     * @param array<string, string> $errors
     * @throws ValidationException
     */
    public static function refuse(array $errors): void
    {
        if ($errors !== []) {
            ksort($errors, SORT_NATURAL);
            throw new ValidationException('Invalid ' . implode(', ', array_keys($errors)), $errors);
        }
    }

    /**
     * @param array<array-key, mixed> $entry
     * @param list<string> $accepted
     * @param (callable(array<array-key, mixed>): array<string, string>)|null $values
     * @return array<string, string>
     */
    private static function entryErrors(
        array $entry,
        string $at,
        array $accepted,
        string $what,
        ?callable $values
    ): array {
        $errors = [];
        foreach (self::unknown($entry, $accepted, $what) as $key => $message) {
            $errors["$at.$key"] = $message;
        }
        if ($values !== null) {
            foreach ($values(array_intersect_key($entry, array_flip($accepted))) as $key => $message) {
                $errors[$key === '' ? $at : "$at.$key"] = $message;
            }
        }

        return $errors;
    }

    /**
     * The read-only keys of $payload that do not hold the record's value.
     * On a create ($current null) there is no record, so each is refused:
     * the server assigns it.
     *
     * @param array<array-key, mixed> $payload
     * @param list<string> $readOnly
     * @param array<string, mixed>|null $current the record an update changes
     * @return array<string, string>
     */
    public static function changedReadOnly(array $payload, array $readOnly, ?array $current): array
    {
        $errors = [];
        foreach ($readOnly as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            if ($current === null) {
                $errors[$key] = 'is set by the server: omit it when creating';
                continue;
            }
            // The held value is not repeated in the message: a read-only
            // field can be a credential (an app's token), and GET shows it.
            if (!self::same($payload[$key], $current[$key] ?? null)) {
                $errors[$key] = 'is read-only: send it only with the value the record holds (as GET returns it), or omit it';
            }
        }

        return $errors;
    }

    /**
     * Whether a value sent in JSON is the value the record holds, as GET
     * answered it. Scalars compare as their text, so 5 and "5" are the same
     * id; null is the same only as null; lists and objects compare element by
     * element. A float is never the same as an integer it merely rounds to.
     */
    public static function same(mixed $sent, mixed $held): bool
    {
        if ($sent === null || $held === null) {
            return $sent === $held;
        }
        if (is_array($sent) || is_array($held)) {
            if (!is_array($sent) || !is_array($held) || count($sent) !== count($held)) {
                return false;
            }
            foreach ($held as $key => $value) {
                if (!array_key_exists($key, $sent) || !self::same($sent[$key], $value)) {
                    return false;
                }
            }

            return true;
        }
        if (is_bool($sent) || is_bool($held) || !is_scalar($sent) || !is_scalar($held)) {
            return $sent === $held;
        }

        return self::text($sent) === self::text($held);
    }

    private static function text(int|float|string $value): string
    {
        if (is_float($value)) {
            // 5.0 is the integer 5; 5.5 is not any integer.
            return floor($value) === $value && abs($value) < 9.0E15 ? (string) (int) $value : (string) $value;
        }

        return (string) $value;
    }
}
