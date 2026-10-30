<?php

declare(strict_types=1);

namespace Prosper202\License;

/**
 * File-backed cache of CLI shell license checks, shared by web auth (which
 * warms and invalidates entries as it validates keys) and the v3 capabilities
 * endpoint (which reads and refreshes them).
 *
 * Entries live in a private 0700 directory under the system temp dir. The
 * directory and every entry must be owned by the current process user and
 * must not be symlinks; anything else is ignored. This prevents other local
 * users on a shared host from granting or revoking shell access by
 * pre-creating files at the predictable hashed paths.
 */
class ShellAccessCache
{
    /** Entries older than this require revalidation against ClickServer. */
    public const int TTL_SECONDS = 3600;

    /**
     * How long a refusal is believed. Shorter than an allow: a customer who
     * starts the trial after a "not paid" is served within this, not after
     * TTL_SECONDS, and nothing else invalidates the entry when they pay.
     */
    public const int DENIAL_TTL_SECONDS = 300;

    /**
     * The grace period: while ClickServer gives no answer, a key is licensed
     * only if its last answer was a yes given within this (validWithinGrace).
     */
    public const int GRACE_SECONDS = 30 * 86400;

    /** The questions a LicenceAnswerStore records an answer to. */
    public const string QUESTION_VALID = 'valid';
    public const string QUESTION_PAID = 'paid';

    /**
     * How long a result is kept for readStale(); prune() drops anything
     * older. The grace period reads the durable record, not these files.
     */
    public const int STALE_TTL_SECONDS = self::GRACE_SECONDS;

    /**
     * How long a claim on asking ClickServer about a key is honoured by the
     * other requests that want the same answer (see beginCheck).
     */
    public const int CHECK_LOCK_SECONDS = 15;

    /**
     * How long a check that got no usable answer holds off the next one.
     * An outage is not a verdict and is never written as one; this keeps
     * every request made during one from paying the connect timeout again.
     */
    public const int UNKNOWN_TTL_SECONDS = 60;

    public static function read(string $key, int $maxAgeSeconds = self::TTL_SECONDS): ?bool
    {
        $path = static::path($key);
        if ($path === null || !is_file($path) || is_link($path)) {
            return null;
        }
        if (fileowner($path) !== static::processUserId()) {
            return null;
        }
        $mtime = filemtime($path);
        // A negative age (an mtime in the future, after a clock step) would
        // never exceed any TTL; it is unknown, not a verdict that outlives
        // the clock error.
        if (
            $mtime === false
            || $mtime > time()
            || (time() - $mtime) > $maxAgeSeconds
        ) {
            return null;
        }
        $content = @file_get_contents($path);
        // Only an exact entry is a verdict. An empty or partial file (an
        // interrupted write, a full disk) is "unknown", so the caller
        // revalidates instead of treating it as a denial.
        if ($content === '1') {
            return true;
        }
        if ($content === '0') {
            return false;
        }
        return null;
    }

    /**
     * The verdict with a refusal expiring after DENIAL_TTL_SECONDS and an
     * allow after TTL_SECONDS; null once the one that applies has passed.
     */
    public static function readVerdict(string $key): ?bool
    {
        $verdict = static::read($key);
        if ($verdict === false && static::read($key, self::DENIAL_TTL_SECONDS) === null) {
            return null;
        }
        return $verdict;
    }

    /**
     * Last known result within STALE_TTL_SECONDS, a yes or a no. A gate
     * with no answer asks validWithinGrace() instead.
     */
    public static function readStale(string $key): ?bool
    {
        return self::read($key, self::STALE_TTL_SECONDS);
    }

    /**
     * Whether $key is licensed while ClickServer gives no answer: the durable
     * record's last answer to this cache's question (a valid key here, a paid
     * licence in CliAccessCache) was a yes, given at most GRACE_SECONDS ago.
     * Never checked, last answered no, answered longer ago, or a record that
     * cannot be read is not licensed. Every licence gate asks this, so the
     * rule can be replaced here alone.
     */
    public static function validWithinGrace(?LicenceAnswerStore $answers, string $key, ?int $now = null): bool
    {
        if ($key === '') {
            return false;
        }
        if ($answers === null) {
            error_log(static::class . ': no licence answer record to read; the grace period cannot apply');
            return false;
        }
        try {
            $last = $answers->lastAnswer(hash('sha256', $key), static::licenceQuestion());
        } catch (\Throwable $e) {
            error_log(static::class . ': ' . $e->getMessage() . '; the grace period cannot apply');
            return false;
        }
        if ($last === null) {
            return false;
        }
        [$yes, $at] = $last;
        $now ??= time();
        return $yes && $at <= $now && $now - $at <= self::GRACE_SECONDS;
    }

    /**
     * Record ClickServer's answer for $key: in this cache, and in the
     * durable record the grace period reads. A record that cannot be written
     * is logged; the answer still decides this request.
     */
    public static function recordAnswer(?LicenceAnswerStore $answers, string $key, bool $yes): void
    {
        static::write($key, $yes);
        if ($key === '') {
            return;
        }
        if ($answers === null) {
            error_log(static::class . ': no licence answer record to write; the grace period will not see this answer');
            return;
        }
        try {
            $answers->saveAnswer(hash('sha256', $key), static::licenceQuestion(), $yes, time());
        } catch (\Throwable $e) {
            error_log(static::class . ': ' . $e->getMessage());
        }
    }

    /**
     * Record a whole licence answer from ClickServerKeyValidator: the key's
     * validity here, its paid licence in CliAccessCache when the answer says.
     * No answer (null) records nothing. Every path that confirms a key calls
     * this or recordAnswer() (KeyEntryRecordsTheAnswerTest).
     *
     * @param array{valid: bool, paid: ?bool}|null $licence
     */
    public static function recordLicence(?LicenceAnswerStore $answers, string $key, ?array $licence): void
    {
        if ($licence === null) {
            return;
        }
        ShellAccessCache::recordAnswer($answers, $key, $licence['valid']);
        if ($licence['paid'] !== null) {
            CliAccessCache::recordAnswer($answers, $key, $licence['paid']);
        }
    }

    /** The question this cache's answers are to. */
    protected static function licenceQuestion(): string
    {
        return self::QUESTION_VALID;
    }

    public static function write(string $key, bool $valid): void
    {
        $path = static::path($key);
        if ($path === null) {
            return;
        }
        // A verdict ends the hold-off an unanswered check may have left.
        @unlink(self::unknownMarker($path));
        self::put($path, $valid ? '1' : '0');
        // One write in fifty sweeps the directory, so it stays bounded by the
        // keys seen in the stale window rather than by every key ever submitted.
        if (random_int(1, 50) === 1) {
            static::prune();
        }
    }

    /**
     * Record that the last check of this key got no usable answer, so the
     * next UNKNOWN_TTL_SECONDS of requests fall back on validWithinGrace()
     * instead of each asking ClickServer again. No verdict is written.
     */
    public static function markUnknown(string $key): void
    {
        $path = static::path($key);
        if ($path !== null) {
            self::put(self::unknownMarker($path), '?');
        }
    }

    /** Whether a check of this key went unanswered less than $maxAgeSeconds ago. */
    public static function recentlyUnknown(string $key, int $maxAgeSeconds = self::UNKNOWN_TTL_SECONDS): bool
    {
        $path = static::path($key);
        if ($path === null) {
            return false;
        }
        $marker = self::unknownMarker($path);
        if (!is_file($marker) || is_link($marker) || fileowner($marker) !== static::processUserId()) {
            return false;
        }
        $mtime = filemtime($marker);
        return $mtime !== false && $mtime <= time() && (time() - $mtime) <= $maxAgeSeconds;
    }

    /** A temp file this old was left by a write that never finished. */
    public const ORPHAN_TMP_SECONDS = 3600;

    /**
     * Drop entries and hold-off markers older than $olderThanSeconds. The web
     * sign-in writes an entry for every key a user submits and nothing else
     * removes one, so write() calls this now and then. The default is the
     * stale window (readStale()), not the fresh one. Returns how many files
     * went.
     */
    public static function prune(int $olderThanSeconds = self::STALE_TTL_SECONDS): int
    {
        $dir = static::dir();
        if ($dir === null) {
            return 0;
        }
        $removed = 0;
        $cutoff = time() - $olderThanSeconds;
        // Two globs, not GLOB_BRACE: the flag is undefined on some libcs
        // (Alpine's musl), and this runs inside write().
        $list = static function (string $pattern) use ($dir): array {
            $found = glob($dir . '/' . $pattern);
            if ($found === false) {
                error_log('ShellAccessCache: could not list ' . $pattern . ' in ' . $dir);
                return [];
            }
            return $found;
        };
        $files = array_merge($list('*.cache'), $list('*.unknown'), $list('*.checking'));
        // put()'s temp files, left by a process that stopped between the
        // write and the rename: an hour is far longer than any write takes,
        // whatever age the caller sweeps entries at.
        $orphanCutoff = time() - self::ORPHAN_TMP_SECONDS;
        $tmpFiles = $list('*.tmp');
        foreach ([[$files, $cutoff], [$tmpFiles, $orphanCutoff]] as [$batch, $before]) {
            foreach ($batch as $file) {
                if (is_link($file)) {
                    continue;
                }
                $mtime = @filemtime($file);
                if ($mtime !== false && $mtime < $before && @unlink($file)) {
                    $removed++;
                }
            }
        }
        return $removed;
    }

    public static function invalidate(string $key): void
    {
        $path = static::path($key);
        if ($path !== null) {
            @unlink($path);
            @unlink(self::unknownMarker($path));
            @unlink($path . '.checking');
        }
    }

    /**
     * Claim the right to ask ClickServer about $key. False when another
     * process claimed it less than CHECK_LOCK_SECONDS ago: the caller treats
     * that as unknown and the claimant's verdict lands in the cache for
     * everyone, so a burst of cold requests asks once rather than once per
     * worker. A claim older than that belonged to a worker that died
     * mid-check and is taken over. True when there is no cache directory, or
     * when the claim cannot be created (disk full, read-only temp): nothing to
     * coordinate on, so the caller asks rather than reads unknown. endCheck()
     * releases it.
     */
    public static function beginCheck(string $key): bool
    {
        $path = static::path($key);
        if ($path === null) {
            return true;
        }
        $lock = $path . '.checking';
        if (self::claim($lock)) {
            return true;
        }
        clearstatcache(true, $lock);
        $mtime = @filemtime($lock);
        if ($mtime === false) {
            return true;
        }
        if ($mtime <= time() && (time() - $mtime) < self::CHECK_LOCK_SECONDS) {
            return false;
        }
        @unlink($lock);
        if (self::claim($lock)) {
            return true;
        }
        clearstatcache(true, $lock);
        return !file_exists($lock);
    }

    /**
     * How long ago the check of $key was claimed, in seconds, or null when no
     * claim is held (beginCheck()).
     */
    public static function claimAge(string $key): ?int
    {
        $path = static::path($key);
        if ($path === null) {
            return null;
        }
        clearstatcache(true, $path . '.checking');
        $mtime = @filemtime($path . '.checking');
        return $mtime === false ? null : max(0, time() - $mtime);
    }

    /** Whether a check of $key is claimed and not yet released (beginCheck()). */
    public static function checkInProgress(string $key): bool
    {
        $path = static::path($key);
        if ($path === null) {
            return false;
        }
        clearstatcache(true, $path . '.checking');
        return file_exists($path . '.checking');
    }

    public static function endCheck(string $key): void
    {
        $path = static::path($key);
        if ($path !== null) {
            @unlink($path . '.checking');
        }
    }

    /** Create $lock if it does not exist, atomically (O_EXCL). */
    private static function claim(string $lock): bool
    {
        $fh = @fopen($lock, 'x');
        if ($fh === false) {
            return false;
        }
        fclose($fh);
        @chmod($lock, 0600);
        return true;
    }

    private static function unknownMarker(string $path): string
    {
        return $path . '.unknown';
    }

    /**
     * Write a private temp file and rename it into place, so a reader never
     * sees a half-written entry.
     */
    private static function put(string $path, string $content): void
    {
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (
            @file_put_contents($tmp, $content) === false
            || !@chmod($tmp, 0600)
            || !@rename($tmp, $path)
        ) {
            @unlink($tmp);
            // Not fatal (the next request just revalidates), but worth a trace
            // so repeated external validation calls can be diagnosed.
            error_log('ShellAccessCache: failed to write ' . $path);
        }
    }

    protected static function path(string $key): ?string
    {
        if ($key === '') {
            return null;
        }
        $dir = static::dir();
        if ($dir === null) {
            return null;
        }
        return $dir . '/' . hash('sha256', $key) . '.cache';
    }

    /** Directory name under the system temp dir; subclasses keep separate caches. */
    protected static function cacheDirName(): string
    {
        return 'p202-shell-access';
    }

    /**
     * Returns the private cache directory, or null if it cannot be created
     * with safe ownership (callers then skip caching entirely rather than
     * trust a directory another user may control).
     */
    protected static function dir(): ?string
    {
        $dir = sys_get_temp_dir() . '/' . static::cacheDirName();
        if (!is_dir($dir) && !@mkdir($dir, 0700) && !is_dir($dir)) {
            error_log(static::class . ': cannot create ' . $dir . '; nothing is cached');
            return null;
        }
        if (is_link($dir) || fileowner($dir) !== static::processUserId()) {
            // Refused rather than trusted, and said: without a cache every
            // request revalidates, the outage hold-off included.
            error_log(static::class . ': ' . $dir . ' is a symlink or owned by another user; nothing is cached');
            return null;
        }
        return $dir;
    }

    protected static function processUserId(): int
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : (int)getmyuid();
    }
}
