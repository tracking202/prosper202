<?php

// Stand-in for 202-config/connect2.php, copied beside a real dl.php, lp.php or off.php
// by FallbackRedirectScriptsTest: just enough of the bootstrap for the script
// to reach its fallback refresh, with every memcache read and write recorded.

declare(strict_types=1);

// A checkout served as the web root serves tests/ too (TestScriptsAreNotServedTest).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/harness-functions.php';

$p202Harness = json_decode((string) getenv('P202_HARNESS'), true, 512, JSON_THROW_ON_ERROR);

// connect2.php's constant, and the shipped p202OnTheClickPath() that reads
// it: the lookup factory caches on the click path alone.
define('P202_CLICK_PATH', true);
require $p202Harness['repo'] . '/tests/Config/fixtures/lift-function.php';
eval(lift_function($p202Harness['repo'] . '/202-config/functions-shared.php', 'p202OnTheClickPath'));

spl_autoload_register(static function (string $class) use ($p202Harness): void {
    if (str_starts_with($class, 'Prosper202\\')) {
        $file = $p202Harness['repo'] . '/202-config/' . str_replace('\\', '/', substr($class, 11)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

$memcacheWorking = true;
$memcache = new class ($p202Harness['cache']) {
    /** @var array<string, string> */
    public array $writes = [];

    /** @var list<string> */
    public array $reads = [];

    /** @param array<string, string> $entries */
    public function __construct(public array $entries)
    {
    }

    public function get(string $key): mixed
    {
        $this->reads[] = $key;

        return $this->entries[$key] ?? false;
    }
};

// A mysqli, because dl.php hands $db to code typed for one; never connected.
final class P202HarnessDb extends mysqli
{
    public function real_escape_string(string $string): string
    {
        // dl.php escapes the tracker's user_id first thing after its refresh;
        // its rows carry this as user_id so the run ends there.
        if ($string === 'p202-harness-stop') {
            exit;
        }

        return addslashes($string);
    }

    // Reads answer "nothing found"; a write fails, which ends the run.
    public function query(string $query, int $resultMode = MYSQLI_STORE_RESULT): mysqli_result|bool
    {
        return str_starts_with(ltrim($query), 'SELECT');
    }

    // The repositories' statements (off.php's next step after its refresh) end it too.
    public function prepare(string $query): mysqli_stmt|false
    {
        exit;
    }
}

$db = (new ReflectionClass(P202HarnessDb::class))->newInstanceWithoutConstructor();

register_shutdown_function(static function () use ($p202Harness): void {
    global $memcache;
    $result = ['reads' => $memcache->reads, 'writes' => (object) $memcache->writes];
    file_put_contents($p202Harness['out'], json_encode($result));
});
