<?php

// Stand-in for 202-config/connect2.php, copied beside a real dl.php, lp.php or off.php
// by FallbackRedirectScriptsTest: just enough of the bootstrap for the script
// to reach its fallback refresh, with every memcache read and write recorded.

declare(strict_types=1);

require __DIR__ . '/harness-functions.php';

$p202Harness = json_decode((string) getenv('P202_HARNESS'), true, 512, JSON_THROW_ON_ERROR);

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
}

$db = (new ReflectionClass(P202HarnessDb::class))->newInstanceWithoutConstructor();

register_shutdown_function(static function () use ($p202Harness): void {
    global $memcache;
    $result = ['reads' => $memcache->reads, 'writes' => (object) $memcache->writes];
    file_put_contents($p202Harness['out'], json_encode($result));
});
