<?php

declare(strict_types=1);

namespace Prosper202\Click\Filter;

/**
 * Whether a click is filtered, and why: the rule that decided it, and the
 * list entry when an entry did (an "always count" entry decides a counted
 * click; it is never recorded, since only filtered clicks have a reason).
 */
final class ClickFilterVerdict
{
    public const SIGN_IN_ADDRESS = 'sign_in_address';
    public const CRAWLER_RANGE = 'crawler_range';
    public const REPEAT_VISIT = 'repeat_visit';
    public const FILTER_LIST = 'filter_list';

    /** Every reason a filtered click is recorded with. */
    public const REASONS = [self::SIGN_IN_ADDRESS, self::CRAWLER_RANGE, self::REPEAT_VISIT, self::FILTER_LIST];

    private function __construct(
        public readonly bool $filtered,
        public readonly ?string $reason,
        public readonly ?int $entryId,
    ) {
    }

    public static function counted(?int $byEntry = null): self
    {
        return new self(false, null, $byEntry);
    }

    public static function filtered(string $reason, ?int $entryId = null): self
    {
        if (!in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException('Not a click filter reason: ' . $reason);
        }
        if (($reason === self::FILTER_LIST) !== ($entryId !== null)) {
            throw new \InvalidArgumentException('A list entry decides filter_list, and only it');
        }

        return new self(true, $reason, $entryId);
    }

    /** 202_clicks.click_filtered. */
    public function flag(): int
    {
        return $this->filtered ? 1 : 0;
    }
}
