<?php

/**
 * The bootstrap's memcache globals ($memcache, $whatCache, $memcacheWorking)
 * for a fixture process, from its environment:
 *   P202_TEST_MEMCACHED=host:port  a real memcached (the memcached extension)
 *   P202_TEST_CACHE_FILE=path      a JSON file standing in for one, shared by
 *                                  the processes of a test; every key read is
 *                                  appended to path.reads
 * Neither set: memcache is off, as on a server without it. Also sets the
 * database settings the namespace names ($dbhost, $dbname) as 202-config.php
 * does.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

final class P202FileCache
{
    public function __construct(private string $path)
    {
    }

    public function get(string $key): mixed
    {
        file_put_contents($this->path . '.reads', $key . "\n", FILE_APPEND | LOCK_EX);

        return $this->all()[$key] ?? false;
    }

    public function set(string $key, mixed $value, int $exp = 0): bool
    {
        $all = $this->all();
        $all[$key] = $value;

        return file_put_contents($this->path, json_encode($all, JSON_THROW_ON_ERROR), LOCK_EX) !== false;
    }

    /** @return array<string, mixed> */
    private function all(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $all = json_decode((string) file_get_contents($this->path), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($all)) {
            throw new RuntimeException('the cache file is not an object');
        }

        return $all;
    }
}

function p202_test_cache_globals(): void
{
    $GLOBALS['dbhost'] = (string) (getenv('P202_TEST_DB_HOST') ?: '127.0.0.1');
    $GLOBALS['dbname'] = (string) getenv('P202_TEST_DB_NAME');
    $GLOBALS['memcache'] = null;
    $GLOBALS['whatCache'] = false;
    $GLOBALS['memcacheWorking'] = false;

    $server = (string) getenv('P202_TEST_MEMCACHED');
    $file = (string) getenv('P202_TEST_CACHE_FILE');
    if ($server !== '') {
        if (!class_exists('Memcached') || preg_match('/^([^:]+):(\d+)$/D', $server, $m) !== 1) {
            fwrite(STDERR, "P202_TEST_MEMCACHED needs the memcached extension and host:port\n");
            exit(2);
        }
        $client = new Memcached();
        $client->addServer($m[1], (int) $m[2]);
        $GLOBALS['memcache'] = $client;
        $GLOBALS['whatCache'] = 'memcached';
        $GLOBALS['memcacheWorking'] = true;
    } elseif ($file !== '') {
        $GLOBALS['memcache'] = new P202FileCache($file);
        $GLOBALS['whatCache'] = 'memcached';
        $GLOBALS['memcacheWorking'] = true;
    }
}
