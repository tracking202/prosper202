<?php

declare(strict_types=1);

namespace Tests\Database;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\InstallIdCache;

/**
 * A reinstall in place (the database dropped, created again under its name
 * and installed again) is a new cache namespace: the directory and database
 * the namespace named stay, and lookup ids, tracker rows and outage URLs the
 * old install kept in memcached for up to 30 days were read by the new one.
 * The install id is in the namespace now (InstallIdCache).
 *
 * Each install and each request is a process of its own, as on a server:
 * the installer (tests/Api/V3/fixtures/install-runner.php, which runs
 * INSTALL::install_databases()) and a request's systemHash()
 * (fixtures/cache-namespace-runner.php), over the scratch database, sharing a
 * file that stands in for memcached and records every key read. With
 * P202_TEST_MEMCACHED=host:port and the memcached extension, the installer's
 * announcement is also run against a real memcached.
 *
 * @group integration
 */
final class InstallIdCacheIntegrationTest extends TestCase
{
    /** tests/live/guard.sh's P202_SCRATCH_RE: a name that reads as disposable. */
    private const SCRATCH_RE
        = '/(^|[_-])(test|tests|scratch|tmp|temp|ci|eval|evals|fixture|sandbox|w[0-9]+|probe|check)([_-]|$)/';

    private static ?\mysqli $server = null;
    private static string $name = '';
    private string $cacheFile = '';

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        self::$name = (string) (getenv('P202_TEST_DB_NAME') ?: 'p202_integration_test');
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        self::$server = new \mysqli(
            $host,
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            '',
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->close();
        self::$server = null;
    }

    protected function setUp(): void
    {
        if (self::$server === null) {
            self::markTestSkipped('No test database (P202_TEST_DB_HOST).');
        }
        self::assertMatchesRegularExpression(self::SCRATCH_RE, self::$name, 'the database dropped here is scratch');
        $this->cacheFile = (string) tempnam(sys_get_temp_dir(), 'p202-cache-');
        unlink($this->cacheFile);
    }

    protected function tearDown(): void
    {
        foreach ([$this->cacheFile, $this->cacheFile . '.reads'] as $file) {
            if ($file !== '' && is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testAReinstallInPlaceIsANewNamespaceAndNoOldKeyIsRead(): void
    {
        $env = ['P202_TEST_CACHE_FILE' => $this->cacheFile];
        $first = $this->install($env);
        self::assertSame($first . ':', substr($this->pointer(), 0, 65), 'the installer announced its id');

        $old = $this->request($env);
        self::assertTrue($old['memcache_working']);
        $oldKeys = array_column($old['read'], 0);
        foreach ($oldKeys as $key) {
            $this->put($key, 'the first install\'s');
        }
        $again = $this->request($env);
        self::assertSame($old['namespace'], $again['namespace'], 'one install is one namespace across requests');
        self::assertSame(
            array_fill(0, 3, 'the first install\'s'),
            array_column($again['read'], 1),
            'its own entries are read: the harness sees a hit'
        );

        $second = $this->install($env);
        self::assertNotSame($first, $second);
        self::assertSame($second . ':', substr($this->pointer(), 0, 65), 'the reinstall announced its id');

        file_put_contents($this->cacheFile . '.reads', '');
        $new = $this->request($env);
        self::assertTrue($new['memcache_working']);
        self::assertNotSame($old['namespace'], $new['namespace']);
        self::assertNotSame($old['lookup_namespace'], $new['lookup_namespace']);
        self::assertSame([false, false, false], array_column($new['read'], 1), 'nothing of the first install\'s');
        self::assertCount(4, $this->reads(), 'the held id and the three keys');
        self::assertSame([], array_values(array_intersect($this->reads(), $oldKeys)), 'no old key is read');

        $outage = $this->request($env + ['P202_TEST_OUTAGE' => '1']);
        self::assertSame($new['namespace'], $outage['namespace'], 'the outage fallback reads the new install\'s namespace');
    }

    /**
     * The installer could not tell memcached (it was not running for it):
     * requests read the id again once the held one is VERIFY_EVERY_SECONDS
     * old, and a request that cannot read it caches nothing.
     */
    public function testAReinstallMemcachedWasNotToldOfIsSeenWhenTheHeldIdIsNextChecked(): void
    {
        $env = ['P202_TEST_CACHE_FILE' => $this->cacheFile];
        $this->install($env);
        $old = $this->request($env);

        $second = $this->install([]);
        self::assertSame($old, $this->request($env), 'within the window the held id still answers');

        $this->agePointer(InstallIdCache::VERIFY_EVERY_SECONDS);
        $new = $this->request($env);
        self::assertTrue($new['memcache_working']);
        self::assertNotSame($old['namespace'], $new['namespace']);
        self::assertSame($second . ':', substr($this->pointer(), 0, 65), 'and the id read is held');

        self::$server->query('DROP TABLE `' . self::$name . '`.`202_deployment_secrets`');
        $this->agePointer(InstallIdCache::VERIFY_EVERY_SECONDS);
        $unknown = $this->request($env);
        self::assertFalse($unknown['memcache_working'], 'an id that cannot be read: nothing is cached');
        self::assertNotSame($new['namespace'], $unknown['namespace']);
        self::assertNotSame($old['namespace'], $unknown['namespace']);
        self::assertNotSame($unknown, $this->request($env), 'and no namespace is shared by two such requests');
    }

    /** The installer's announcement, on a real memcached. */
    public function testTheInstallerTellsARealMemcachedTheNewId(): void
    {
        $server = (string) getenv('P202_TEST_MEMCACHED');
        if ($server === '' || !class_exists(\Memcached::class)) {
            self::markTestSkipped('No memcached (P202_TEST_MEMCACHED=host:port and the memcached extension).');
        }
        [$host, $port] = explode(':', $server, 2);
        $client = new \Memcached();
        $client->addServer($host, (int) $port);
        $env = ['P202_TEST_MEMCACHED' => $server];

        $first = $this->install($env);
        $old = $this->request($env);
        self::assertTrue($old['memcache_working']);
        foreach (array_column($old['read'], 0) as $key) {
            self::assertTrue($client->set($key, 'the first install\'s', 0));
        }
        self::assertSame(array_fill(0, 3, 'the first install\'s'), array_column($this->request($env)['read'], 1));

        $second = $this->install($env);
        self::assertNotSame($first, $second);
        $new = $this->request($env);
        self::assertTrue($new['memcache_working']);
        self::assertSame([false, false, false], array_column($new['read'], 1), 'nothing of the first install\'s');
        foreach (array_column($old['read'], 0) as $key) {
            self::assertSame('the first install\'s', $client->get($key), 'the old entries are there, unread');
        }
    }

    /**
     * @param array<string, string> $env
     * @return string the install id the installer stored
     */
    private function install(array $env): string
    {
        self::$server->query('DROP DATABASE IF EXISTS `' . self::$name . '`');
        self::$server->query('CREATE DATABASE `' . self::$name . '`');
        $this->runFixture(dirname(__DIR__) . '/Api/V3/fixtures/install-runner.php', $env);
        $rows = self::$server->query('SELECT secret_value FROM `' . self::$name . '`.`202_deployment_secrets`'
            . " WHERE secret_name = 'install_id'")->fetch_all(MYSQLI_ASSOC);
        self::assertCount(1, $rows, 'the installer minted the install id');

        return (string) $rows[0]['secret_value'];
    }

    /**
     * @param array<string, string> $env
     * @return array{namespace: string, lookup_namespace: string, memcache_working: bool}
     */
    private function request(array $env): array
    {
        $out = $this->runFixture(__DIR__ . '/fixtures/cache-namespace-runner.php', $env);
        $answer = json_decode($out, true, 4, JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);

        return $answer;
    }

    /** @param array<string, string> $env */
    private function runFixture(string $script, array $env): string
    {
        $base = getenv();
        unset($base['P202_TEST_MEMCACHED'], $base['P202_TEST_CACHE_FILE'], $base['P202_TEST_OUTAGE']);
        $proc = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + $base);
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($proc), basename($script) . " failed: $err");

        return $out;
    }

    private function put(string $key, string $value): void
    {
        $all = json_decode((string) file_get_contents($this->cacheFile), true, 16, JSON_THROW_ON_ERROR);
        $all[$key] = $value;
        file_put_contents($this->cacheFile, json_encode($all, JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    private function reads(): array
    {
        $file = $this->cacheFile . '.reads';

        return is_file($file) ? array_values(array_filter(explode("\n", (string) file_get_contents($file)))) : [];
    }

    private function pointer(): string
    {
        $all = json_decode((string) file_get_contents($this->cacheFile), true, 16, JSON_THROW_ON_ERROR);
        $pointers = array_values(array_filter(
            $all,
            static fn (string $key): bool => str_starts_with($key, 'p202:install-id:v1:'),
            ARRAY_FILTER_USE_KEY
        ));
        self::assertCount(1, $pointers, 'one place, one held id');

        return (string) $pointers[0];
    }

    private function agePointer(int $seconds): void
    {
        $all = json_decode((string) file_get_contents($this->cacheFile), true, 16, JSON_THROW_ON_ERROR);
        foreach ($all as $key => $value) {
            if (str_starts_with($key, 'p202:install-id:v1:')) {
                [$id, $at] = explode(':', (string) $value, 2);
                $all[$key] = $id . ':' . ((int) $at - $seconds);
            }
        }
        file_put_contents($this->cacheFile, json_encode($all, JSON_THROW_ON_ERROR));
    }
}
