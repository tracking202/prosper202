<?php

/**
 * Writes one rotator click as rtr.php does: MysqlClickRepository inside
 * p202WithHeldCacheWrites(), its redirect URL filled from the click's own
 * rows and looked up by LookupRepositoryFactory's location repository, which
 * caches through getCache() and setCache() (functions-shared.php), on the
 * scratch database with a cache that records what reaches it. A process of
 * its own: setCache(), getCache() and _mysqli_query() are global names other
 * suites define for themselves, and connect2.php cannot be loaded alone.
 *
 * argv: <commit | rollback | rollback_unheld | lookup_fails> <click id> <host> [<click | cached>]
 *   commit           the click is written
 *   rollback         the site row is refused after the lookup (a table of
 *                    this session's without its columns shadows
 *                    202_clicks_site), so the click and the URL row the
 *                    lookup inserted are rolled back
 *   rollback_unheld  the same, without the hold: what the test must be able
 *                    to see
 *   lookup_fails     the lookup's own INSERT into 202_site_urls is refused
 *                    (a table of this session's shadows it): the repository
 *                    throws, and never answers the insert_id 0 a failed
 *                    INSERT leaves
 *
 * The lookup is rtr.php's, findOrCreateClickSiteUrl() (click), or the
 * cached findOrCreateSiteUrl() (cached), which shows the hold at work.
 *
 * Prints JSON: the URL looked up and the id it answered, the cache writes
 * made by the time the lookup returned, every cache write, and the failure.
 *
 * env: P202_TEST_DB_HOST/PORT/USER/PASS/NAME
 */

declare(strict_types=1);

[, $scenario, $clickId, $host, $lookup] = $argv + [null, 'commit', '0', 'held.example', 'click'];
$clickId = (int) $clickId;

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/../../../202-config/mysql-error-args.php';
require __DIR__ . '/../../Config/fixtures/lift-function.php';

$shared = __DIR__ . '/../../../202-config/functions-shared.php';
// rtr.php's bootstrap: the lookup factory caches on the click path alone.
define('P202_CLICK_PATH', true);
$lifted = [
    'p202OnTheClickPath', 'setCache', 'getCache', 'p202WithHeldCacheWrites',
    '_mysqli_query', 'p202LogFailedQuery', 'p202Mysqli', 'systemHash',
];
foreach ($lifted as $name) {
    eval(lift_function($shared, $name));
}
$connect2 = __DIR__ . '/../../../202-config/connect2.php';
foreach (['replaceTrackerPlaceholders', 'replaceClickPlaceholders', 'p202ClickUrlTokens', 'replaceTokens'] as $name) {
    eval(lift_function($connect2, $name));
}
// Evaluated, as the integration suites declare their stand-ins.
eval(<<<'PHP'
    function record_mysql_error($dbOrSql, $sql = null): never
    {
        throw new \RuntimeException('record_mysql_error(): ' . ($sql ?? (is_string($dbOrSql) ? $dbOrSql : '')));
    }
    // A cache that records every write. Every read misses: getCache() asks
    // only a Memcache or a Memcached.
    final class RecordingCache
    {
        /** @var list<array{string, mixed}> */
        public array $written = [];

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
$shadowed = match ($scenario) {
    'lookup_fails' => $db->query(
        'CREATE TEMPORARY TABLE 202_site_urls (site_url_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
        . ' site_domain_id INT UNSIGNED NOT NULL, site_url_address VARCHAR(8) NOT NULL)'
    ),
    // Where the lookup is what fails, nothing else may.
    'rollback', 'rollback_unheld' => $db->query(
        'CREATE TEMPORARY TABLE 202_clicks_site (click_id BIGINT UNSIGNED NOT NULL)'
    ),
    default => true,
};
if ($shadowed === false) {
    fwrite(STDERR, 'could not shadow a table: ' . $db->error . "\n");
    exit(5);
}
$GLOBALS['whatCache'] = 'memcached';
$GLOBALS['memcacheWorking'] = true;
$GLOBALS['memcache'] = $cache = new RecordingCache();
$_SERVER['HTTP_HOST'] = 'tracking.example';

$click = new \Prosper202\Click\ClickRecord(
    clickId: $clickId,
    userId: 990302,
    clickTime: time(),
    ppcAccountId: 3,
    clickCpc: '0.25',
    recordsCampaignRows: false,
    clickOut: 1,
    clickRefererSiteUrlId: 31,
    clickOutboundSiteUrlId: 32,
    rotator: new \Prosper202\Click\RotatorRoute(7, 8, 9),
);

// rtr.php's $rtrResolveRedirect, over the same calls.
$url = "https://$host/offer?s=[[subid]]";
$answered = null;
$cachedByThen = null;
$ownerId = $click->userId;
$resolve = function () use ($db, &$url, $clickId, $ownerId, &$answered, &$cachedByThen, $cache, $lookup): int {
    $url = replaceTrackerPlaceholders($db, $url, $clickId, $ownerId);
    $locations = \Prosper202\Repository\LookupRepositoryFactory::location($db);
    $answered = $lookup === 'cached'
        ? $locations->findOrCreateSiteUrl((string) $url)
        : $locations->findOrCreateClickSiteUrl((string) $url);
    $cachedByThen = count($cache->written);

    return $answered;
};

$clickRepo = new \Prosper202\Click\MysqlClickRepository(new \Prosper202\Database\Connection($db));
$failure = null;
try {
    if ($scenario === 'rollback_unheld') {
        $clickRepo->recordClick($click, $resolve);
    } else {
        p202WithHeldCacheWrites(static fn () => $clickRepo->recordClick($click, $resolve));
    }
} catch (\Throwable $e) {
    $failure = get_class($e) . ': ' . $e->getMessage();
}

echo json_encode([
    'url' => $url,
    'answered' => $answered,
    'url_key' => md5('url-id' . $url . systemHash()),
    'domain_key' => md5('domain-id' . \Prosper202\Repository\Mysql\MysqlLocationRepository::storedDomainHost((string) $url)
        . systemHash()),
    'cached_by_then' => $cachedByThen,
    'cached' => $cache->written,
    'failure' => $failure,
], JSON_THROW_ON_ERROR), "\n";
