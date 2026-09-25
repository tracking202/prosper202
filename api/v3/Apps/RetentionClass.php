<?php

declare(strict_types=1);

namespace Api\V3\Apps;

/**
 * One class of rows a public app intake lets strangers mint, and how long it
 * is kept. Each signal source registers its classes (plan §4.6); the pruner,
 * the resolved-policy report and the backlog count all read the same list,
 * so the numbers an operator is shown cannot drift from what is deleted.
 *
 * The predicate is a SQL fragment over the table's own columns, written by
 * the source as a constant — never built from input.
 */
final class RetentionClass
{
    public function __construct(
        /** The app table the class lives in, e.g. 202_app_postbacks. */
        public readonly string $table,
        /** The class name: unclaimed, refuted, unvouched. */
        public readonly string $label,
        /** WHERE fragment selecting the class. */
        public readonly string $predicate,
        /** The column holding the row's receipt time (unix seconds). */
        public readonly string $timeColumn,
        public readonly int $defaultDays,
    ) {
        if (!str_starts_with($table, '202_app_') || preg_match('/^[a-z0-9_]+$/D', $table) !== 1) {
            throw new \InvalidArgumentException('Retention applies to app tables only: ' . $table);
        }
        if (preg_match('/^[a-z_]+$/D', $label) !== 1 || preg_match('/^[a-z_]+$/D', $timeColumn) !== 1) {
            throw new \InvalidArgumentException('Retention class and column names are lower-case words');
        }
    }

    /**
     * P202_APP_RETENTION_DAYS_<TABLE>_<CLASS>, where <TABLE> is the table
     * name without its 202_app_ prefix: _POSTBACKS_UNCLAIMED, and from the
     * Android intake on _INSTALLS_REFUTED.
     */
    public function environmentVariable(): string
    {
        return 'P202_APP_RETENTION_DAYS_'
            . strtoupper(substr($this->table, strlen('202_app_'))) . '_'
            . strtoupper($this->label);
    }

    /** "postbacks/unclaimed" — how the cron and the logs name the class. */
    public function name(): string
    {
        return substr($this->table, strlen('202_app_')) . '/' . $this->label;
    }
}
