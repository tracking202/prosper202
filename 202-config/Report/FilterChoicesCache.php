<?php

declare(strict_types=1);

namespace Prosper202\Report;

use Closure;
use Throwable;

/** Optional dropdown choices, isolated by install, account and exact report window. */
final class FilterChoicesCache
{
    public const TTL_SECONDS = 300;

    /**
     * @param Closure(string): mixed $get
     * @param Closure(string, array<int|string, string>, int): mixed $set
     */
    public function __construct(private Closure $get, private Closure $set, private string $database)
    {
    }

    /** Use either PHP Memcache client already connected by the app's bootstrap. */
    public static function fromGlobals(): ?self
    {
        $client = $GLOBALS['memcache'] ?? null;
        $backend = $GLOBALS['whatCache'] ?? '';
        $host = $GLOBALS['dbhost'] ?? '';
        $database = $GLOBALS['dbname'] ?? '';
        if (
            empty($GLOBALS['memcacheWorking']) || !is_object($client)
            || !method_exists($client, 'get') || !method_exists($client, 'set')
            || !in_array($backend, ['memcache', 'memcached'], true)
            || !is_string($host) || $host === '' || !is_string($database) || $database === ''
            || !function_exists('systemHash')
        ) {
            return null;
        }

        return new self(
            static fn (string $key): mixed => @$client->get($key),
            static fn (string $key, array $value, int $ttl): mixed => $backend === 'memcache'
                ? @$client->set($key, $value, 0, $ttl)
                : @$client->set($key, $value, $ttl),
            \systemHash()
        );
    }

    /**
     * Cache successful base choices only: the current selection is added by the caller.
     *
     * @param Closure(): array<int|string, string> $load
     * @return array<int|string, string>
     */
    public function remember(string $name, ?int $user, int $from, int $to, int $limit, Closure $load): array
    {
        $key = 'p202:overview-choices:v1:' . hash('sha256', json_encode([
            $this->database, $name, $user, $from, $to, max(1, $limit),
        ], JSON_THROW_ON_ERROR));
        try {
            $cached = ($this->get)($key);
        } catch (Throwable) {
            $cached = false;
        }
        if (is_array($cached)) {
            $valid = true;
            foreach ($cached as $id => $label) {
                if (!is_string($label) || preg_match('/^(?:0|[1-9]\d{0,18})$/D', (string) $id) !== 1) {
                    $valid = false;
                    break;
                }
            }
            if ($valid) {
                return $cached;
            }
        }

        // A database failure propagates: it must never become a cached empty list.
        $list = $load();
        try {
            ($this->set)($key, $list, self::TTL_SECONDS);
        } catch (Throwable) {
            // The cache is optional; the successfully read choices still stand.
        }

        return $list;
    }
}
