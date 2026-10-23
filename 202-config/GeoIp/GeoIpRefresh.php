<?php

declare(strict_types=1);

namespace Prosper202\GeoIp;

use Prosper202\Database\Connection;

/**
 * Keeps the GeoLite2 City database current with the operator's own MaxMind
 * account: the cron job's hourly tier runs it when due (RefreshState::dueAt(),
 * weekly), and Account › Settings on demand.
 *
 * A run asks MaxMind for the current archive's SHA-256 first. When that is
 * the archive this install last put in place and the file is still that
 * build, it stops there ("current"); otherwise it downloads the archive
 * beside the database and hands it to DatabaseInstaller, which verifies it
 * and swaps it in, or leaves the installed file alone. Every failure is
 * logged and recorded with its reason, which Settings and GET /system/info
 * show. One run at a time: a lock file beside the database, so the cron job
 * and a Settings click never unpack into the same directory together.
 */
final class GeoIpRefresh
{
    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): MaxMindDownload)|null $download built when a run needs it, so a bad test
     *        override fails that run
     * @param (\Closure(): int)|null $clock
     */
    public function __construct(
        private readonly RefreshStore $store,
        private readonly DatabaseInstaller $installer,
        private readonly ?\Closure $download = null,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public static function forConnection(Connection $conn): self
    {
        return new self(new RefreshStore($conn), new DatabaseInstaller());
    }

    /** The cron job's call: a run when one is due, else null. */
    public function runIfDue(): ?RefreshResult
    {
        $state = $this->store->read();
        $due = $state->dueAt(CityDatabase::status($this->installer->target(), ($this->clock)()));
        if ($due === null || ($this->clock)() < $due) {
            return null;
        }

        return $this->run();
    }

    /**
     * One refresh. A failure is recorded and answered, never thrown; a
     * database error reading or recording the row is thrown.
     */
    public function run(): RefreshResult
    {
        $credentials = $this->store->read()->credentials();
        if ($credentials === null) {
            return RefreshResult::notConfigured();
        }
        $startedAt = ($this->clock)();
        try {
            $lock = $this->lock();
        } catch (RefreshFailed $e) {
            return $this->failed($startedAt, $e->getMessage());
        }
        if ($lock === null) {
            return RefreshResult::busy();
        }
        try {
            return $this->refresh($credentials, $startedAt);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function refresh(Credentials $credentials, int $startedAt): RefreshResult
    {
        try {
            $this->installer->cleanLeftovers();
            $download = $this->download !== null ? ($this->download)() : MaxMindDownload::fromEnvironment();
            $published = $download->checksum($credentials);
            $state = $this->store->read();
            $installed = CityDatabase::status($this->installer->target(), ($this->clock)());
            if (
                $state->archiveSha256 !== null && hash_equals($state->archiveSha256, $published['sha256'])
                && $installed->builtAt !== null && $installed->builtAt === $state->installedBuildEpoch
            ) {
                $this->store->recordSuccess($startedAt, false, $published['sha256'], $installed->builtAt);

                return RefreshResult::current($installed);
            }

            [$archive, $handle] = $this->installer->createTemporary('tar.gz');
            try {
                try {
                    $download->archive($credentials, $handle);
                } finally {
                    fclose($handle);
                }
                $outcome = $this->installer->install($archive, $published['sha256']);
            } finally {
                @unlink($archive);
            }
        } catch (RefreshFailed $e) {
            return $this->failed($startedAt, $e->getMessage());
        } catch (\Throwable $e) {
            // Not one of the refresh's own refusals: said as it is, and the
            // installed file is still the one that was there.
            return $this->failed($startedAt, 'The refresh stopped on an unexpected error (' . get_class($e) . ': '
                . $e->getMessage() . ').');
        }

        $builtAt = $outcome->now->builtAt;
        if ($builtAt === null) {
            return $this->failed(
                $startedAt,
                'The database was replaced, and then could not be read back: ' . $outcome->now->reason
            );
        }
        $this->store->recordSuccess($startedAt, $outcome->replaced, $published['sha256'], $builtAt);

        return $outcome->replaced
            ? RefreshResult::refreshed($outcome->now, $outcome->before)
            : RefreshResult::current($outcome->now);
    }

    private function failed(int $at, string $reason): RefreshResult
    {
        error_log('Prosper202 GeoIP refresh failed: ' . $reason);
        $this->store->recordFailure($at, $reason);

        return RefreshResult::failed($reason);
    }

    /**
     * The refresh lock, or null when another run holds it.
     *
     * @return resource|null
     * @throws RefreshFailed when the lock file cannot be made, which is also why the database could not be replaced
     */
    private function lock()
    {
        $target = $this->installer->target();
        $path = dirname($target) . '/.' . basename($target) . '.lock';
        // Read-only is enough to lock: the cron job and the web server may be
        // different users, and the lock file is whichever made it first.
        $handle = @fopen($path, 'c');
        if ($handle === false && is_file($path)) {
            $handle = @fopen($path, 'r');
        }
        if ($handle === false) {
            throw new RefreshFailed('The refresh could not make its lock file ' . $path . '. '
                . DatabaseInstaller::whoNeedsAccess(dirname($target)));
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }
}
