<?php

declare(strict_types=1);

namespace Prosper202\Click\Filter;

/**
 * What an account's click filter settings and address list accept: the one
 * rule Personal settings' Click filtering card and the API
 * (/users/{id}/click-filter) both ask, so the two save the same values.
 *
 * Each method answers the values read and the sentences for the fields it
 * refused, keyed by field, and reads only the keys it is given: a caller
 * refuses the keys it does not know first (PayloadKeys::refuseUnknown()).
 */
final class ClickFilterRules
{
    /** The most entries an account's list holds. */
    public const MAX_ENTRIES = 500;

    /** 202_click_filter_entries.note, in characters. */
    public const NOTE_CHARS = 255;

    public const SETTINGS_FIELDS = ['sign_in_addresses', 'crawler_ranges', 'repeat_visits', 'repeat_window'];

    public const ENTRY_FIELDS = ['address', 'action', 'note'];

    private function __construct()
    {
    }

    /**
     * The settings with each field $input names changed, and the refusals.
     *
     * @param array<array-key, mixed> $input
     * @return array{ClickFilterSettings, array<string, string>}
     */
    public static function settings(array $input, ClickFilterSettings $current): array
    {
        $errors = [];
        $values = $current->toArray();
        foreach (['sign_in_addresses', 'crawler_ranges', 'repeat_visits'] as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $flag = self::flag($input[$field]);
            if ($flag === null) {
                $errors[$field] = 'must be true or false (1 or 0)';
                continue;
            }
            $values[$field] = $flag;
        }
        if (array_key_exists('repeat_window', $input)) {
            $window = $input['repeat_window'];
            if (!is_string($window) || !array_key_exists($window, ClickFilterSettings::WINDOWS)) {
                $errors['repeat_window'] = 'must be one of ' . implode(', ', array_keys(ClickFilterSettings::WINDOWS));
            } else {
                $values['repeat_window'] = $window;
            }
        }

        return [
            new ClickFilterSettings(
                $values['sign_in_addresses'],
                $values['crawler_ranges'],
                $values['repeat_visits'],
                $values['repeat_window'],
            ),
            $errors,
        ];
    }

    /**
     * A new entry's range, action and note, and the refusals. The address
     * and the action are required.
     *
     * @param array<array-key, mixed> $input
     * @return array{?AddressRange, string, string, array<string, string>}
     */
    public static function newEntry(array $input): array
    {
        $errors = [];
        foreach (['address', 'action'] as $required) {
            if (!array_key_exists($required, $input) || $input[$required] === null || $input[$required] === '') {
                $errors[$required] = $required === 'address'
                    ? 'is required: an IP address (203.0.113.7) or a CIDR block (203.0.113.0/24)'
                    : 'is required: ' . implode(' or ', ClickFilterEntry::ACTIONS);
            }
        }
        [$range, $action, $note, $more] = self::entryFields($input, null);

        return [$range, $action ?? '', $note ?? '', $errors + $more];
    }

    /**
     * An entry with each field $input names changed, and the refusals.
     *
     * @param array<array-key, mixed> $input
     * @return array{AddressRange, string, string, array<string, string>}
     */
    public static function changedEntry(array $input, ClickFilterEntry $current): array
    {
        [$range, $action, $note, $errors] = self::entryFields($input, $current);

        return [$range ?? $current->range, $action ?? $current->action, $note ?? $current->note, $errors];
    }

    /**
     * An address to test: one address, not a block.
     *
     * @return array{?string, array<string, string>} the packed address
     */
    public static function testAddress(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [null, ['ip' => 'is required: the IPv4 or IPv6 address to test, such as 203.0.113.7']];
        }
        $text = trim($value);
        $packed = filter_var($text, FILTER_VALIDATE_IP) === false ? null : AddressRange::packedForMatch($text);
        if ($packed === null) {
            return [null, ['ip' => "\"$text\" is not an IPv4 or IPv6 address (test one address, not a block)"]];
        }

        return [$packed, []];
    }

    /**
     * @param array<array-key, mixed> $input
     * @return array{?AddressRange, ?string, ?string, array<string, string>}
     */
    private static function entryFields(array $input, ?ClickFilterEntry $current): array
    {
        $errors = [];
        $range = null;
        $action = null;
        $note = null;
        if (array_key_exists('address', $input) && $input['address'] !== null && $input['address'] !== '') {
            try {
                $range = AddressRange::parse($input['address']);
            } catch (\InvalidArgumentException $e) {
                $errors['address'] = $e->getMessage();
            }
        } elseif ($current !== null && array_key_exists('address', $input)) {
            $errors['address'] = 'cannot be emptied: remove the entry instead';
        }
        if (array_key_exists('action', $input) && $input['action'] !== null && $input['action'] !== '') {
            if (!is_string($input['action']) || !in_array($input['action'], ClickFilterEntry::ACTIONS, true)) {
                $errors['action'] = 'must be ' . implode(' (always count it) or ', ClickFilterEntry::ACTIONS) . ' (always filter it)';
            } else {
                $action = $input['action'];
            }
        } elseif ($current !== null && array_key_exists('action', $input)) {
            $errors['action'] = 'must be ' . implode(' or ', ClickFilterEntry::ACTIONS);
        }
        if (array_key_exists('note', $input)) {
            $why = self::noteRefusal($input['note']);
            if ($why !== null) {
                $errors['note'] = $why;
            } else {
                $note = trim((string) $input['note']);
            }
        }

        return [$range, $action, $note, $errors];
    }

    private static function noteRefusal(mixed $note): ?string
    {
        if ($note === null) {
            return null;
        }
        if (!is_string($note)) {
            return 'must be text';
        }
        if (preg_match('//u', $note) !== 1) {
            return 'must be UTF-8 text';
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $note) === 1) {
            return 'must be one line, with no control characters';
        }
        if (mb_strlen(trim($note), 'UTF-8') > self::NOTE_CHARS) {
            return 'must be at most ' . self::NOTE_CHARS . ' characters';
        }

        return null;
    }

    /** true, false, 1, 0 and their strings; anything else is no flag. */
    private static function flag(mixed $value): ?bool
    {
        return match (true) {
            $value === true, $value === 1, $value === '1', $value === 'true' => true,
            $value === false, $value === 0, $value === '0', $value === 'false' => false,
            default => null,
        };
    }
}
