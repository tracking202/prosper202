<?php

declare(strict_types=1);

namespace Api\V3\Apps;

use Api\V3\Apps\Apple\PostbackReceiver;
use Api\V3\Exception\DatabaseException;
use Api\V3\Support\MysqliStatements;

/**
 * Retention for the rows the public app intakes let anyone mint.
 *
 * Retention is data each signal source registers (RetentionClass), not a
 * list kept here: the Apple source registers its postback classes and the
 * Android source its install classes (Android\InstallRetention). Only a row
 * that is trusted and belongs to a registration is operator data kept
 * forever; every other class has a window.
 *
 * Windows are overridable per class through
 * P202_APP_RETENTION_DAYS_<TABLE>_<CLASS> (RetentionClass::
 * environmentVariable()); 0 disables that class. An unset variable means the
 * default. A value that is not a whole number of days prunes NOTHING for
 * that class and is named in the log — falling back to the default would
 * delete on a window the operator never chose (CLAUDE.md #4 and #11).
 *
 * The overrides are read from the environment of whichever process prunes,
 * so the cron (202-cronjobs/app-retention.php) and php-fpm (the receiver's
 * opportunistic pass) are configured separately; the cron prints the
 * windows it resolved for exactly that reason.
 */
final class AppRetention
{
    use MysqliStatements;

    /**
     * Rows one pass deletes per class. Bounded so a pass stays cheap on the
     * request path; the cron loops passes until the backlog drains.
     */
    public const PRUNE_BATCH_LIMIT = 500;

    /**
     * @param list<RetentionClass> $classes
     */
    public function __construct(private readonly \mysqli $db, private readonly array $classes)
    {
    }

    /**
     * Every class every signal source registers.
     *
     * @return list<RetentionClass>
     */
    public static function registeredClasses(): array
    {
        return [...PostbackReceiver::retentionClasses(), ...Android\InstallRetention::retentionClasses()];
    }

    public static function forRegisteredSources(\mysqli $db): self
    {
        return new self($db, self::registeredClasses());
    }

    /** @return list<RetentionClass> */
    public function classes(): array
    {
        return $this->classes;
    }

    /**
     * The tables the classes live in, each once.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        return array_values(array_unique(array_map(
            static fn (RetentionClass $class): string => $class->table,
            $this->classes
        )));
    }

    /**
     * The windows in force for THIS process: class name => {days, cutoff}.
     * A class that prunes nothing — 0 days, or an override this process
     * refused to guess at — reports 0 days and a null cutoff.
     *
     * @return array<string, array{days: int, cutoff: int|null}>
     */
    public function policy(int $now): array
    {
        $policy = [];
        foreach ($this->classes as $class) {
            $days = self::retentionDays($class);
            $cutoff = null;
            if ($days > 0) {
                // A window longer than the epoch is old keeps everything; the
                // multiplication would otherwise overflow into a float.
                $cutoff = $days > intdiv($now, 86400) ? 0 : $now - ($days * 86400);
            }
            $policy[$class->name()] = ['days' => $days, 'cutoff' => $cutoff];
        }
        return $policy;
    }

    /**
     * One bounded pass: delete up to PRUNE_BATCH_LIMIT aged rows per class.
     */
    public function prune(int $now): void
    {
        $policy = $this->policy($now);
        foreach ($this->classes as $class) {
            $cutoff = $policy[$class->name()]['cutoff'];
            if ($cutoff === null) {
                continue; // disabled, or a value we refused to guess at
            }
            $stmt = $this->prepare(
                'DELETE FROM ' . $class->table . ' WHERE ' . $class->predicate
                . ' AND ' . $class->timeColumn . ' < ? LIMIT ' . self::PRUNE_BATCH_LIMIT
            );
            $this->bind($stmt, 'i', $cutoff);
            $this->execute($stmt, 'Retention delete failed for ' . $class->name());
            $stmt->close();
        }
    }

    /**
     * How many rows each class would delete if it ran until it drained:
     * class name => rows already past its window. A class that prunes
     * nothing reports 0.
     *
     * @return array<string, int>
     */
    public function backlog(int $now): array
    {
        $policy = $this->policy($now);
        $backlog = [];
        foreach ($this->classes as $class) {
            $cutoff = $policy[$class->name()]['cutoff'];
            if ($cutoff === null) {
                $backlog[$class->name()] = 0;
                continue;
            }
            $stmt = $this->prepare(
                'SELECT COUNT(*) AS aged FROM ' . $class->table . ' WHERE ' . $class->predicate
                . ' AND ' . $class->timeColumn . ' < ?'
            );
            $this->bind($stmt, 'i', $cutoff);
            $this->execute($stmt, 'Retention backlog count failed for ' . $class->name());
            $row = $this->result($stmt)->fetch_assoc();
            $stmt->close();
            if (!is_array($row) || !isset($row['aged'])) {
                // COUNT(*) always answers with exactly one row, so no row is
                // a transport failure — never "nothing to prune" (CLAUDE.md #1).
                throw new DatabaseException('Retention backlog count for ' . $class->name() . ' returned no row');
            }
            $backlog[$class->name()] = (int)$row['aged'];
        }
        return $backlog;
    }

    /**
     * Days to retain one class, or 0 to prune nothing.
     */
    private static function retentionDays(RetentionClass $class): int
    {
        $envName = $class->environmentVariable();
        $raw = getenv($envName);
        if ($raw === false || trim($raw) === '') {
            return $class->defaultDays;
        }
        $raw = trim($raw);
        if (preg_match('/^\d+$/D', $raw) !== 1) {
            // Once per process per (variable, value): the policy is resolved
            // on every pass, and the cron makes hundreds. Only the warning is
            // suppressed; the value is re-refused every time.
            static $warned = [];
            $key = $envName . '=' . $raw;
            if (!isset($warned[$key])) {
                $warned[$key] = true;
                error_log(sprintf(
                    'p202 apps: ignoring malformed %s (%s); expected a whole number of days, 0 to disable. Nothing pruned for this class.',
                    $envName,
                    $raw
                ));
            }
            return 0;
        }
        return (int)$raw;
    }
}
