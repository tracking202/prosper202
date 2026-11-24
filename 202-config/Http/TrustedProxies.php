<?php

declare(strict_types=1);

namespace Prosper202\Http;

/**
 * The proxies this install believes when they say who the client is or that
 * the browser used HTTPS: the P202_TRUSTED_PROXIES environment variable, a
 * list of addresses and CIDR ranges separated by commas or spaces, where
 * `cloudflare` stands for Cloudflare's published ranges (CloudflareRanges).
 *
 * An environment variable, not a 202-config.php setting: the session cookie's
 * Secure flag is decided in connect.php before 202-config.php is loaded, and
 * the setup wizard runs with no 202-config.php at all, so a constant there
 * would be unseen by the first reader (CLAUDE.md #3). Apache's SetEnv, PHP-FPM's
 * env[] and a container's environment all reach getenv().
 *
 * Unset or empty trusts nothing: every forwarding header is the client's own
 * claim and the connecting peer (REMOTE_ADDR) is the client. One entry that is
 * not an address or a range with a prefix length of 1 or more also trusts
 * nothing, and is logged naming the entry (CLAUDE.md #11): a typo must not
 * widen trust, and dropping only the bad entry would hide it.
 */
final class TrustedProxies
{
    public const ENV = 'P202_TRUSTED_PROXIES';

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

    /** The setting as this process's environment holds it. */
    public static function fromEnvironment(): self
    {
        $raw = getenv(self::ENV);
        $raw = is_string($raw) ? $raw : '';
        if (!isset(self::$parsed[$raw])) {
            $trusted = self::parse($raw);
            if ($trusted->error !== null) {
                error_log('Prosper202: ' . $trusted->error);
            }
            self::$parsed[$raw] = $trusted;
        }

        return self::$parsed[$raw];
    }

    public static function parse(string $value): self
    {
        $cidrs = [];
        foreach (preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            if (strtolower($entry) === 'cloudflare') {
                array_push($cidrs, ...CloudflareRanges::CIDRS);
                continue;
            }
            $cidr = self::cidr($entry);
            if ($cidr === null) {
                return new self([], self::ENV . ' entry "' . substr($entry, 0, 64) . '" is not an IP address or a CIDR'
                    . ' range with a prefix length of 1 or more (or `cloudflare`); no proxy is trusted until it is fixed.');
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
