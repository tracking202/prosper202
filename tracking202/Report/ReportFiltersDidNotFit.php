<?php

declare(strict_types=1);

namespace Tracking202\Report;

/**
 * A report filter that came back from 202_users_pref other than it was
 * written: thrown inside ReportPrefsStore::save()'s transaction so the whole
 * write rolls back, and caught there to name what did not fit.
 */
final class ReportFiltersDidNotFit extends \RuntimeException
{
    /** @param array<string, string|int|null> $refused URL name => the value that did not fit */
    public function __construct(public readonly array $refused)
    {
        parent::__construct('A report filter did not fit the column it is kept in.');
    }
}
