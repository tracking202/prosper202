<?php

declare(strict_types=1);

namespace Prosper202\Update;

/**
 * A subid list with a line whose payout, transaction id or time cannot be
 * read. Thrown before anything is written, so the whole list can be fixed
 * and sent again.
 */
final class SubidLinesRefused extends \InvalidArgumentException
{
    /**
     * @param list<array{line: int, subid: string, error: string}> $lines the refused lines, in list order
     */
    public function __construct(public readonly array $lines)
    {
        parent::__construct(count($lines) . ' line(s) of the subid list could not be read; nothing was recorded');
    }
}
