<?php

declare(strict_types=1);

namespace Prosper202\Click\Filter;

/**
 * One entry of an account's address list: its addresses, and whether a
 * click from them is always counted or always filtered.
 */
final class ClickFilterEntry
{
    public const COUNT = 'count';
    public const FILTER = 'filter';

    public const ACTIONS = [self::COUNT, self::FILTER];

    public function __construct(
        public readonly int $id,
        public readonly AddressRange $range,
        public readonly string $action,
        public readonly string $note,
        public readonly int $createdAt,
    ) {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \UnexpectedValueException('Not a click filter entry action: ' . $action);
        }
    }

    /** @return array{entry_id: int, address: string, action: string, note: string, created_at: int} */
    public function toArray(): array
    {
        return [
            'entry_id' => $this->id,
            'address' => $this->range->text(),
            'action' => $this->action,
            'note' => $this->note,
            'created_at' => $this->createdAt,
        ];
    }
}
