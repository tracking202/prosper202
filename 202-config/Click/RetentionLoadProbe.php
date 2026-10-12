<?php

declare(strict_types=1);

namespace Prosper202\Click;

/**
 * Reads how busy the host and the database are, cheaply enough to ask before
 * every deletion batch: the host's one-minute load average per CPU, and the
 * queries MySQL is running for others right now (Threads_running, which needs
 * no privilege, so it works on shared hosting too).
 *
 * A signal that cannot be read (no sys_getloadavg() on Windows, the status
 * query refused) is left out rather than guessed. That is safe here: the
 * pacer also sizes every batch by how long the last one took, so a server it
 * cannot read is still not pushed past what it visibly handles.
 */
final class RetentionLoadProbe
{
    private ?int $cpus = null;

    public function __construct(private readonly \mysqli $db)
    {
    }

    public function pressure(): RetentionPressure
    {
        return self::classify($this->hostLoadPerCpu(), $this->otherQueriesRunning(), $this->cpus());
    }

    /**
     * The pressure two readings mean, the worse of the two.
     *
     * Host load per CPU: under 0.75 is idle enough to go fast; to 1.5 is
     * busy; to 3 is saturated; past 3 work is queueing. Queries running for
     * others, per CPU: under 1 is quiet; to 2 is busy; to 4 is contended;
     * past 4 the database is the bottleneck.
     */
    public static function classify(?float $loadPerCpu, ?int $otherQueriesRunning, int $cpus): RetentionPressure
    {
        $level = RetentionPressure::NONE;
        if ($loadPerCpu !== null) {
            $level = $level->worse(match (true) {
                $loadPerCpu < 0.75 => RetentionPressure::NONE,
                $loadPerCpu < 1.5 => RetentionPressure::MODERATE,
                $loadPerCpu < 3.0 => RetentionPressure::HIGH,
                default => RetentionPressure::SEVERE,
            });
        }
        if ($otherQueriesRunning !== null) {
            $perCpu = $otherQueriesRunning / max(1, $cpus);
            $level = $level->worse(match (true) {
                $perCpu < 1.0 => RetentionPressure::NONE,
                $perCpu < 2.0 => RetentionPressure::MODERATE,
                $perCpu < 4.0 => RetentionPressure::HIGH,
                default => RetentionPressure::SEVERE,
            });
        }

        return $level;
    }

    private function hostLoadPerCpu(): ?float
    {
        if (!function_exists('sys_getloadavg')) {
            return null;
        }
        $load = @sys_getloadavg();
        if (!is_array($load) || !isset($load[0]) || !is_numeric($load[0])) {
            return null;
        }

        return (float) $load[0] / $this->cpus();
    }

    /** Threads_running less this connection's own status query. */
    private function otherQueriesRunning(): ?int
    {
        try {
            $result = $this->db->query("SHOW GLOBAL STATUS LIKE 'Threads_running'");
        } catch (\Throwable) {
            return null;
        }
        if (!$result instanceof \mysqli_result) {
            return null;
        }
        $row = $result->fetch_row();
        $result->free();
        if (!is_array($row) || !isset($row[1]) || !is_numeric($row[1])) {
            return null;
        }

        return max(0, (int) $row[1] - 1);
    }

    private function cpus(): int
    {
        if ($this->cpus !== null) {
            return $this->cpus;
        }
        $count = 0;
        $info = @file_get_contents('/proc/cpuinfo');
        if (is_string($info)) {
            $count = preg_match_all('/^processor\s*:/m', $info);
        }
        if ($count < 1) {
            $env = getenv('NUMBER_OF_PROCESSORS');
            $count = is_string($env) && ctype_digit($env) ? (int) $env : 1;
        }

        return $this->cpus = max(1, (int) $count);
    }
}
