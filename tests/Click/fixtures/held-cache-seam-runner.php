<?php

/**
 * Writes one rotator click as rtr.php does: RotatorClickWriter inside
 * p202WithHeldCacheWrites(), its redirect URL filled from the click's own
 * rows and looked up by connect2.php's INDEXES::get_site_url_id(), which
 * caches through connect2.php's setCache(), on the scratch database with a
 * cache that records what reaches it. A process of its own: connect2.php's
 * INDEXES, setCache() and _mysqli_query() are global names other suites
 * define for themselves, and connect2.php cannot be loaded alone.
 *
 * argv: <commit | rollback | rollback_unheld | lookup_fails> <click id> <host>
 *   commit           the click is written
 *   rollback         the site row is refused after the lookup, so the click
 *                    and the URL row the lookup inserted are rolled back
 *   rollback_unheld  the same, without the hold: what the test must be able
 *                    to see
 *   lookup_fails     the lookup's own INSERT into 202_site_urls is refused
 *                    (a table of this session's shadows it): connect2.php's
 *                    _mysqli_query() ends at record_mysql_error(), and never
 *                    answers false for the lookup to read insert_id 0 from
 *
 * Prints JSON: the URL looked up and the id it answered, the cache writes
 * made by the time the lookup returned, every cache write, and the failure.
 *
 * env: P202_TEST_DB_HOST/PORT/USER/PASS/NAME
 */

declare(strict_types=1);

[, $scenario, $clickId, $host] = $argv + [null, 'commit', '0', 'held.example'];
$clickId = (int) $clickId;

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/../../Config/fixtures/lift-function.php';

$connect2 = __DIR__ . '/../../../202-config/connect2.php';
foreach (
    [
        'setCache', 'p202WithHeldCacheWrites', '_mysqli_query', 'systemHash',
        'replaceTrackerPlaceholders', 'replaceClickPlaceholders', 'p202ClickUrlTokens', 'replaceTokens',
    ] as $name
) {
    eval(lift_function($connect2, $name));
}
eval(lift_class($connect2, 'INDEXES'));
// Evaluated, as the integration suites declare their stand-ins.
eval(<<<'PHP'
    function record_mysql_error($dbOrSql, $sql = null): never
    {
        throw new \RuntimeException('record_mysql_error(): ' . ($sql ?? (is_string($dbOrSql) ? $dbOrSql : '')));
    }
    final class DB
    {
        public static function getInstance(): self
        {
            return new self();
        }
        public function getConnection(): ?\mysqli
        {
            return $GLOBALS['db'] ?? null;
        }
    }
    // A cache that misses every read and records every write.
    final class RecordingCache
    {
        /** @var list<array{string, mixed}> */
        public array $written = [];

        public function get(string $key): bool
        {
            return false;
        }

        public function set(string $key, mixed $value, mixed $exp): bool
        {
            $this->written[] = [$key, $value];

            return true;
        }
    }
    PHP);

mysqli_report(MYSQLI_REPORT_STRICT);
$db = new mysqli(
    (string) getenv('P202_TEST_DB_HOST'),
    (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
    (string) (getenv('P202_TEST_DB_PASS') ?: ''),
    (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
    (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
);
// Strict, as a MySQL 8 server is: a negative id is refused, not clamped.
$db->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
$GLOBALS['db'] = $db;
$shadowed = $scenario !== 'lookup_fails' || $db->query(
    'CREATE TEMPORARY TABLE 202_site_urls (site_url_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
    . ' site_domain_id INT UNSIGNED NOT NULL, site_url_address VARCHAR(8) NOT NULL)'
);
if ($shadowed === false) {
    fwrite(STDERR, 'could not shadow 202_site_urls: ' . $db->error . "\n");
    exit(5);
}
$GLOBALS['whatCache'] = 'memcached';
$GLOBALS['memcacheWorking'] = true;
$GLOBALS['memcache'] = $cache = new RecordingCache();
$_SERVER['HTTP_HOST'] = 'tracking.example';

$click = new \Prosper202\Click\ClickRecord();
$click->clickId = $clickId;
$click->userId = 990302;
$click->ppcAccountId = 3;
$click->clickCpc = '0.25';
$click->clickPayout = '0';
$click->clickTime = time();
$click->clickIdPublic = '';
$click->clickIn = 1;
$click->clickOut = 1;
// Refused by its unsigned column: the site row, written after the lookup.
// Where the lookup is what fails, nothing else may.
$click->clickRefererSiteUrlId = in_array($scenario, ['rollback', 'rollback_unheld'], true) ? -1 : 31;
$click->clickOutboundSiteUrlId = 32;

// rtr.php's $rtrResolveRedirect, over the same calls.
$url = "https://$host/offer?s=[[subid]]";
$answered = null;
$cachedByThen = null;
$resolve = function () use ($db, &$url, $clickId, &$answered, &$cachedByThen, $cache): int {
    $url = replaceTrackerPlaceholders($db, $url, $clickId);
    $answered = (int) INDEXES::get_site_url_id($db, $url);
    $cachedByThen = count($cache->written);

    return $answered;
};

$writer = new \Prosper202\Click\RotatorClickWriter(new \Prosper202\Database\Connection($db));
$failure = null;
try {
    if ($scenario === 'rollback_unheld') {
        $writer->write($click, 7, 8, 9, false, $resolve);
    } else {
        p202WithHeldCacheWrites(static fn () => $writer->write($click, 7, 8, 9, false, $resolve));
    }
} catch (\Throwable $e) {
    $failure = get_class($e) . ': ' . $e->getMessage();
}

echo json_encode([
    'url' => $url,
    'answered' => $answered,
    'url_key' => md5('url-id' . $url . systemHash()),
    'cached_by_then' => $cachedByThen,
    'cached' => $cache->written,
    'failure' => $failure,
], JSON_THROW_ON_ERROR), "\n";
