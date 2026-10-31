<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\ConflictException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\NothingToUpdateException;
use Api\V3\Exception\ValidationException;
use Api\V3\Exception\WriteCommittedException;
use Api\V3\Support\PayloadKeys;
use Prosper202\Click\Filter\ClickFilterEntry;
use Prosper202\Click\Filter\ClickFilterListRefusal;
use Prosper202\Click\Filter\ClickFilterRules;
use Prosper202\Click\Filter\ClickFilterSettings;
use Prosper202\Click\Filter\ClickFilterTester;
use Prosper202\Click\Filter\MysqlClickFilterStore;
use Prosper202\Database\Connection;

/**
 * /users/{id}/click-filter: an account's click filter, as Personal settings'
 * Click filtering card shows and changes it, through the same rule
 * (ClickFilterRules) and store. The routes ask what the page asks
 * (Auth::requirePersonalSettingsOf()).
 */
final class ClickFilterController
{
    /** What GET answers beside the settings, accepted back unchanged by PUT. */
    private const READ_ONLY = ['entries', 'limits'];

    private const ENTRY_READ_ONLY = ['entry_id', 'created_at'];

    private readonly MysqlClickFilterStore $store;

    public function __construct(private readonly \mysqli $db)
    {
        $this->store = new MysqlClickFilterStore(new Connection($db));
    }

    /** @return array{data: array<string, mixed>} */
    public function get(int $userId): array
    {
        return ['data' => [
            ...$this->store->settings($userId)->toArray(),
            'entries' => array_map(
                static fn (ClickFilterEntry $entry): array => $entry->toArray(),
                $this->store->entries($userId)
            ),
            'limits' => self::limits(),
        ]];
    }

    /**
     * Change the switches and the window. A key GET answers that this does
     * not write (the entries, the limits) is accepted with GET's value, so a
     * GET body goes back whole, and refused with any other.
     *
     * @param array<array-key, mixed> $payload
     * @return array{data: array<string, mixed>}
     */
    public function update(int $userId, array $payload): array
    {
        PayloadKeys::refuseUnknown(
            $payload,
            [...ClickFilterRules::SETTINGS_FIELDS, ...self::READ_ONLY],
            'the click filter settings'
        );
        $current = $this->get($userId)['data'];
        $errors = [];
        foreach (self::READ_ONLY as $key) {
            if (array_key_exists($key, $payload) && !PayloadKeys::same($payload[$key], $current[$key])) {
                $errors[$key] = $key === 'entries'
                    ? 'is changed one entry at a time: POST, PUT or DELETE /users/{id}/click-filter/entries'
                    : 'is read-only';
            }
        }
        $settings = array_intersect_key($payload, array_flip(ClickFilterRules::SETTINGS_FIELDS));
        [$next, $ruleErrors] = ClickFilterRules::settings($settings, $this->store->settings($userId));
        PayloadKeys::refuse($errors + $ruleErrors);
        if ($settings === []) {
            throw new NothingToUpdateException('No settings to update: send sign_in_addresses, crawler_ranges, repeat_visits or repeat_window');
        }

        $this->store->saveSettings($userId, $next);

        try {
            return $this->get($userId);
        } catch (\Throwable $e) {
            throw new WriteCommittedException('click filter settings', $e);
        }
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return array{_status: int, data: array<string, mixed>}
     */
    public function addEntry(int $userId, array $payload): array
    {
        PayloadKeys::refuseUnknown($payload, ClickFilterRules::ENTRY_FIELDS, 'a click filter entry');
        [$range, $action, $note, $errors] = ClickFilterRules::newEntry($payload);
        PayloadKeys::refuse($errors);
        if ($range === null) {
            throw new ValidationException('Invalid address', ['address' => 'is required']);
        }
        try {
            $entry = $this->store->addEntry($userId, $range, $action, $note);
        } catch (ClickFilterListRefusal $refusal) {
            throw self::refusal($refusal);
        }

        return ['_status' => 201, 'data' => $entry->toArray()];
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return array{data: array<string, mixed>}
     */
    public function updateEntry(int $userId, int $entryId, array $payload): array
    {
        PayloadKeys::refuseUnknown(
            $payload,
            [...ClickFilterRules::ENTRY_FIELDS, ...self::ENTRY_READ_ONLY],
            'a click filter entry'
        );
        $current = $this->entryOf($userId, $entryId);
        $held = $current->toArray();
        $errors = [];
        foreach (self::ENTRY_READ_ONLY as $key) {
            if (array_key_exists($key, $payload) && !PayloadKeys::same($payload[$key], $held[$key])) {
                $errors[$key] = 'is read-only';
            }
        }
        $writes = array_intersect_key($payload, array_flip(ClickFilterRules::ENTRY_FIELDS));
        [$range, $action, $note, $ruleErrors] = ClickFilterRules::changedEntry($writes, $current);
        PayloadKeys::refuse($errors + $ruleErrors);
        if ($writes === []) {
            throw new NothingToUpdateException('No fields to update: send address, action or note');
        }
        $next = new ClickFilterEntry($entryId, $range, $action, $note, $current->createdAt);
        try {
            if (!$this->store->updateEntry($userId, $next)) {
                throw new NotFoundException('Click filter entry not found');
            }
        } catch (ClickFilterListRefusal $refusal) {
            throw self::refusal($refusal);
        }

        try {
            return ['data' => $this->entryOf($userId, $entryId)->toArray()];
        } catch (\Throwable $e) {
            throw new WriteCommittedException('click filter entry', $e);
        }
    }

    /** @return array{data: array<string, mixed>} */
    public function deleteEntryPreview(int $userId, int $entryId): array
    {
        return ['data' => [
            'dry_run' => true,
            'action' => 'delete',
            'resource' => 'click-filter-entries',
            'mode' => 'hard',
            'record' => $this->entryOf($userId, $entryId)->toArray(),
            'cascade' => [],
        ]];
    }

    public function deleteEntry(int $userId, int $entryId): void
    {
        if (!$this->store->removeEntry($userId, $entryId)) {
            throw new NotFoundException('Click filter entry not found');
        }
    }

    /**
     * Would a click from this address be filtered, and by which rule? A
     * read (GET ?ip=): nothing is stored (ClickFilterTester).
     *
     * @param array<array-key, mixed> $queryParams
     * @return array{data: array<string, mixed>}
     */
    public function test(int $userId, array $queryParams): array
    {
        PayloadKeys::refuseUnknown($queryParams, ['ip'], 'an address test');
        [$packed, $errors] = ClickFilterRules::testAddress($queryParams['ip'] ?? null);
        PayloadKeys::refuse($errors);
        if ($packed === null) {
            throw new ValidationException('Invalid ip', ['ip' => 'is required']);
        }

        return ['data' => (new ClickFilterTester(new Connection($this->db)))->test($userId, $packed)];
    }

    private function entryOf(int $userId, int $entryId): ClickFilterEntry
    {
        return $this->store->entry($userId, $entryId) ?? throw new NotFoundException('Click filter entry not found');
    }

    /** @return array<string, mixed> */
    private static function limits(): array
    {
        return [
            'max_entries' => ClickFilterRules::MAX_ENTRIES,
            'note_chars' => ClickFilterRules::NOTE_CHARS,
            'repeat_windows' => array_keys(ClickFilterSettings::WINDOWS),
            'defaults' => ClickFilterSettings::defaults()->toArray(),
        ];
    }

    private static function refusal(ClickFilterListRefusal $refusal): \Throwable
    {
        if ($refusal->existingEntryId !== null) {
            return new ConflictException($refusal->getMessage(), ['entry_id' => $refusal->existingEntryId]);
        }

        return new ValidationException('Invalid ' . $refusal->field, [$refusal->field => $refusal->getMessage()]);
    }
}
