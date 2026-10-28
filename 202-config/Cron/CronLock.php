<?php

declare(strict_types=1);

namespace Prosper202\Cron;

/**
 * One run at a time of a cron job's work: an flock() on a file in
 * 202-cronjobs/, held until the run ends, however it ends. FILE is
 * 202-cronjobs/index.php's, for the whole run; ANALYTICS_DELIVERY_FILE is the
 * analytics delivery's, which index.php's hourly tier and
 * 202-cronjobs/sync-messaging.php both make
 * (MessagingService::deliverPendingExclusive()). It is
 * released when this object goes (at the latest when the request or the
 * process ends, an uncaught error or a fatal one included), and the kernel
 * releases it when the process dies, so nothing removes the file and no age
 * makes the lock stale: a run that
 * takes an hour keeps the next ones out for the hour. The file stays where it
 * is, because a run that removed it while another held it would let a third
 * lock a new file beside it.
 *
 * Every way the cron is run reaches index.php: a signed-in page's ping, a
 * scheduler's curl or `php` line, the Docker cron services, AutoCron. They
 * can be different users (a crontab's `php` as root, the pings as the web
 * server), so the run that makes the file leaves it readable by every user,
 * and a run that may not write it locks it read-only, which flock() allows.
 */
final class CronLock
{
    public const FILE = 'cron-run.lock';

    public const ANALYTICS_DELIVERY_FILE = 'analytics-delivery.lock';

    /**
     * @param resource $handle
     */
    private function __construct(private $handle)
    {
    }

    public function __destruct()
    {
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
    }

    /** @param self::FILE|self::ANALYTICS_DELIVERY_FILE $file */
    public static function path(string $file = self::FILE): string
    {
        return dirname(__DIR__, 2) . '/202-cronjobs/' . $file;
    }

    /**
     * The lock, held while the object is, or null while another run holds it.
     *
     * @throws CronLockFailed when the file cannot be opened or locked: read
     *         as "another run holds it", every run would skip with nothing
     *         recorded.
     */
    public static function take(string $path): ?self
    {
        $handle = self::open($path);
        $wouldBlock = 0;
        // A stream that cannot lock is refused before flock(), which on one
        // reports $wouldBlock from whatever errno an earlier call left.
        if (stream_supports_lock($handle) && flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            return new self($handle);
        }
        fclose($handle);
        if ($wouldBlock === 1) {
            return null;
        }
        throw new CronLockFailed('the cron job could not lock ' . $path . ', so it ran nothing: the filesystem'
            . ' it is on may not support locks (flock).');
    }

    /**
     * Whether a run holds the lock now, or null when that cannot be read. It
     * takes a shared lock for an instant: a run starting in that instant finds
     * the lock held and skips, which the next minute's run makes good.
     */
    public static function isHeld(string $path): ?bool
    {
        if (!file_exists($path)) {
            return false;
        }
        $handle = @fopen($path, 're');
        if ($handle === false) {
            return null;
        }
        try {
            $wouldBlock = 0;
            if (stream_supports_lock($handle) && flock($handle, LOCK_SH | LOCK_NB, $wouldBlock)) {
                flock($handle, LOCK_UN);

                return false;
            }

            return $wouldBlock === 1 ? true : null;
        } finally {
            fclose($handle);
        }
    }

    /**
     * 'e' throughout: a process the run starts does not inherit the lock and
     * hold it past the run.
     *
     * @return resource
     * @throws CronLockFailed
     */
    private static function open(string $path)
    {
        $handle = @fopen($path, 'xe');
        if ($handle !== false) {
            if (!@chmod($path, 0644)) {
                error_log('Cron job: ' . $path . ' could not be made readable by every user; a run made as another'
                    . ' user may not be able to lock it: ' . (error_get_last()['message'] ?? 'chmod failed'));
            }

            return $handle;
        }
        $handle = @fopen($path, 'ce');
        if ($handle !== false) {
            return $handle;
        }
        if (!is_file($path)) {
            throw new CronLockFailed('the cron job could not make its lock file ' . $path . ', so it ran nothing:'
                . ' make ' . dirname($path) . ' writable by the user the web server runs as, or make the file'
                . ' there, readable by every user that runs the cron job.');
        }
        $handle = @fopen($path, 're');
        if ($handle === false) {
            throw new CronLockFailed('the cron job could not open its lock file ' . $path . ', so it ran nothing:'
                . ' make it readable by every user that runs the cron job (chmod 644).');
        }

        return $handle;
    }
}
