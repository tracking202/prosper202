<?php

declare(strict_types=1);

namespace Prosper202\Http;

/**
 * The proxies this install believes when they say who the client is or that
 * the browser used HTTPS: the P202_TRUSTED_PROXIES environment variable, a
 * list of addresses and CIDR ranges separated by commas or spaces, where
 * `cloudflare` stands for Cloudflare's published ranges (CloudflareRanges).
 *
 *  - Unset: `cloudflare`, so an install behind Cloudflare records its
 *    visitors with nothing set. Cloudflare's edge appends the address it
 *    was reached from to X-Forwarded-For, and the walk (VisitorIp) reads
 *    that hop, never one a client sent.
 *  - `none`, alone: nothing is trusted.
 *  - Set but empty: nothing is trusted. PHP-FPM's `env[X] = $X` hands an
 *    unset variable on as an empty one, and an empty list is a list of
 *    nothing; a request from an unlisted proxy then shows the admin warning
 *    (UnlistedProxySighting).
 *  - One entry that is not an address, a range with a prefix length of 1 or
 *    more, `cloudflare` or a lone `none`: nothing is trusted, and the error
 *    log says which entry, at most once an hour (CLAUDE.md #11). A typo must
 *    not widen trust, and dropping only the bad entry would hide it.
 *
 * An environment variable, not a 202-config.php setting: the session cookie's
 * Secure flag is decided in connect.php before 202-config.php is loaded, and
 * the setup wizard runs with no 202-config.php at all, so a constant there
 * would be unseen by the first reader (CLAUDE.md #3). Apache's SetEnv, PHP-FPM's
 * env[] and a container's environment all reach getenv().
 */
final class TrustedProxies
{
    public const ENV = 'P202_TRUSTED_PROXIES';

    /** What an unset variable means. */
    public const DEFAULT = 'cloudflare';

    /** How often a malformed setting is logged, per install and value. */
    public const LOG_EVERY_SECONDS = 3600;

    /** @var array<string, self> parsed settings by their raw text */
    private static array $parsed = [];

    /** @param list<string> $cidrs */
    private function __construct(private readonly array $cidrs, private readonly ?string $error)
    {
    }

    public static function none(): self
    {
        return new self([], null);
    }

    /**
     * The setting as this process's environment holds it.
     *
     * @param ?string $stateDir where the hourly log mark is kept: this
     *        install's 202-config/temp/ when not given
     */
    public static function fromEnvironment(?string $stateDir = null): self
    {
        $raw = getenv(self::ENV);
        $raw = is_string($raw) ? $raw : self::DEFAULT;
        if (!isset(self::$parsed[$raw])) {
            $trusted = self::parse($raw);
            if ($trusted->error !== null) {
                self::logAtMostHourly($trusted->error, $raw, $stateDir ?? dirname(__DIR__) . '/temp');
            }
            self::$parsed[$raw] = $trusted;
        }

        return self::$parsed[$raw];
    }

    /**
     * What Settings shows for the setting: whether it is set, what it holds
     * (addresses and ranges an admin wrote, no secret), and why it trusts
     * nothing when it is malformed.
     */
    public static function summary(): string
    {
        $raw = getenv(self::ENV);
        if (!is_string($raw)) {
            return self::ENV . ' is not set: Cloudflare\'s published ranges';
        }
        $parsed = self::parse($raw);
        if ($parsed->error !== null) {
            return $parsed->error;
        }
        if ($parsed->isEmpty()) {
            return self::ENV . (trim($raw) === '' ? ' is set but empty' : ' is none') . ': no proxy is trusted';
        }

        return self::ENV . ' = ' . substr(trim($raw), 0, 200);
    }

    public static function parse(string $value): self
    {
        $entries = preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($entries) === 1 && strtolower($entries[0]) === 'none') {
            return self::none();
        }
        $cidrs = [];
        foreach ($entries as $entry) {
            if (strtolower($entry) === 'cloudflare') {
                array_push($cidrs, ...CloudflareRanges::CIDRS);
                continue;
            }
            $cidr = self::cidr($entry);
            if ($cidr === null) {
                $why = strtolower($entry) === 'none' ? ' (`none` stands alone)' : '';

                return new self([], self::ENV . ' entry "' . substr($entry, 0, 64) . '" is not an IP address or a CIDR'
                    . ' range with a prefix length of 1 or more, `cloudflare` or `none`' . $why
                    . '; no proxy is trusted until it is fixed.');
            }
            $cidrs[] = $cidr;
        }

        return new self($cidrs, null);
    }

    /** Whether $address, as REMOTE_ADDR or a hop gives it, is one of these proxies. */
    public function trusts(string $address): bool
    {
        if ($address === '' || filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        foreach ($this->cidrs as $cidr) {
            if (CloudflareRanges::inCidr($address, $cidr)) {
                return true;
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->cidrs === [];
    }

    /** Why the setting trusts nothing although it is set, or null. */
    public function error(): ?string
    {
        return $this->error;
    }

    /**
     * Logs $error unless this value was logged in the past hour, by the
     * mtime of a mark in $stateDir named by the value's hash. The directory
     * is the install's own (not a shared temp directory, CLAUDE.md #16), so
     * the mark is this install's. A mark that cannot be read or written
     * logs anyway: a log line too many is better than a setting that fails
     * silently.
     */
    private static function logAtMostHourly(string $error, string $raw, string $stateDir, ?int $now = null): void
    {
        $now ??= time();
        $mark = rtrim($stateDir, '/') . '/trusted-proxies-logged-' . substr(hash('sha256', $raw), 0, 16);
        clearstatcache(true, $mark);
        $at = @filemtime($mark);
        if ($at !== false && $now - $at >= 0 && $now - $at < self::LOG_EVERY_SECONDS) {
            return;
        }
        // Written, not touch()ed: the mark's mtime is the time it was logged.
        $kept = @file_put_contents($mark, (string) $now) !== false;
        error_log('Prosper202: ' . $error . ($kept ? ' (logged at most hourly)' : ''));
    }

    /** An address becomes its /32 or /128; a range keeps its prefix length. */
    private static function cidr(string $entry): ?string
    {
        $parts = explode('/', $entry);
        if (count($parts) > 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $width = str_contains($parts[0], ':') ? 128 : 32;
        if (!isset($parts[1])) {
            return $parts[0] . '/' . $width;
        }
        if (preg_match('/^[0-9]{1,3}$/D', $parts[1]) !== 1 || (int) $parts[1] < 1 || (int) $parts[1] > $width) {
            return null;
        }

        return $parts[0] . '/' . (int) $parts[1];
    }
}
