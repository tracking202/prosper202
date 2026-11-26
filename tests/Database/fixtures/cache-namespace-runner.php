<?php

/**
 * One request's cache namespace, in a process of its own as a click's is:
 * functions-shared.php's systemHash() over the scratch database
 * (P202_TEST_DB_*), with the memcache globals test-cache-client.php sets.
 * P202_TEST_OUTAGE=1 runs it with no database, as the outage fallback does.
 *
 * Prints {"namespace": systemHash(), "lookup_namespace":
 * LookupRepositoryFactory::cacheNamespace(), "memcache_working": bool,
 * "read": {what: [key, what memcache answered]}} for three keys a click reads.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
require $root . '/202-config/mysql-error-args.php';
require $root . '/202-config/functions-shared.php';
require __DIR__ . '/test-cache-client.php';

p202_test_cache_globals();
$GLOBALS['db'] = null;
if (getenv('P202_TEST_OUTAGE') !== '1') {
    mysqli_report(MYSQLI_REPORT_STRICT);
    $GLOBALS['db'] = mysqli_connect(
        (string) (getenv('P202_TEST_DB_HOST') ?: '127.0.0.1'),
        (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
        (string) (getenv('P202_TEST_DB_PASS') ?: ''),
        (string) getenv('P202_TEST_DB_NAME'),
        (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
    );
}
$_SERVER['HTTP_HOST'] = 'track.example';

$namespace = systemHash();
$lookupNamespace = \Prosper202\Repository\LookupRepositoryFactory::cacheNamespace();
// What a click reads, keyed as its readers key it: a lookup id
// (MemcacheIdCache), a tracker row by its SQL (p202CachedRead()), and
// dl.php's outage URL.
$read = [];
foreach ([
    'lookup' => md5('c1-id' . 'a keyword' . $lookupNamespace),
    'row' => md5("SELECT * FROM 202_trackers WHERE tracker_id_public = '1'" . $namespace),
    'url' => md5('url_' . '1' . $namespace),
] as $what => $key) {
    $read[$what] = [$key, $GLOBALS['memcacheWorking'] === true ? $GLOBALS['memcache']->get($key) : false];
}

echo json_encode([
    'namespace' => $namespace,
    'lookup_namespace' => $lookupNamespace,
    'memcache_working' => $GLOBALS['memcacheWorking'],
    'read' => $read,
], JSON_THROW_ON_ERROR);
