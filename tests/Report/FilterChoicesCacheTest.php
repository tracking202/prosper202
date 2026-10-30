<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Report\FilterChoicesCache;
use RuntimeException;
use Tests\Support\FakeMysqliConnection;

final class FilterChoicesCacheTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/functions-ui-overview.php';
    }

    public function testCacheHitsSkipTheScanAndDoNotShareTheExtraSelectedValue(): void
    {
        $values = [];
        $cache = new FilterChoicesCache(
            static function (string $key) use (&$values): mixed {
                return $values[$key] ?? false;
            },
            static function (string $key, array $value, int $ttl) use (&$values): void {
                self::assertSame(300, $ttl);
                $values[$key] = $value;
            },
            'database-a'
        );
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows('FROM 202_dataengine', [['id' => 9, 'label' => 'Zeta']]);
        $db->whenQueryContainsReturnRows('WHERE l.isp_id = ?', [['label' => 'Chosen']]);
        $conn = new Connection($db);
        $first = p202_overview_seen_list($conn, 'isp_id', 7, 1000, 2000, '55', 300, $cache);
        $second = p202_overview_seen_list($conn, 'isp_id', 7, 1000, 2000, '56', 300, $cache);

        self::assertSame([55 => 'Chosen', 9 => 'Zeta'], $first);
        self::assertSame([56 => 'Chosen', 9 => 'Zeta'], $second);
        self::assertCount(1, $db->statementsContaining('202_dataengine'));
        self::assertSame([[9 => 'Zeta']], array_values($values));
    }

    public function testEveryScopeAndWindowComponentSeparatesEntries(): void
    {
        $values = [];
        $get = static function (string $key) use (&$values): mixed {
            return $values[$key] ?? false;
        };
        $set = static function (string $key, array $value, int $ttl) use (&$values): void {
            $values[$key] = $value;
        };
        $cache = new FilterChoicesCache($get, $set, 'database-a');
        $other = new FilterChoicesCache($get, $set, 'database-b');
        $reads = 0;
        $load = static function () use (&$reads): array {
            return [++$reads => 'Read'];
        };
        $scopes = [
            ['region_id', 7, 1000, 2000, 300], ['region_id', 8, 1000, 2000, 300],
            ['region_id', null, 1000, 2000, 300], ['region_id', 7, 1001, 2000, 300],
            ['region_id', 7, 1000, 2001, 300], ['isp_id', 7, 1000, 2000, 300],
            ['region_id', 7, 1000, 2000, 1],
        ];
        foreach ($scopes as [$name, $user, $from, $to, $limit]) {
            $cache->remember($name, $user, $from, $to, $limit, $load);
        }
        $other->remember('region_id', 7, 1000, 2000, 300, $load);
        self::assertSame([1 => 'Read'], $cache->remember('region_id', 7, 1000, 2000, 300, $load));
        self::assertSame(8, $reads);
        self::assertCount(8, $values);
    }

    public function testAnEmptySuccessfulResultIsACacheHit(): void
    {
        $cache = new FilterChoicesCache(static fn (): array => [], static fn () => null, 'db');
        self::assertSame([], $cache->remember('isp_id', 7, 1, 2, 300, static function (): array {
            self::fail('A cached empty list must not be read again.');
        }));
    }

    public function testCacheFailureFallsBackButDatabaseFailureIsNotCached(): void
    {
        $writes = 0;
        $cache = new FilterChoicesCache(
            static function (): never {
                throw new RuntimeException('cache offline');
            },
            static function () use (&$writes): never {
                $writes++;
                throw new RuntimeException('cache offline');
            },
            'db'
        );
        self::assertSame(
            [9 => 'Present'],
            $cache->remember('isp_id', 7, 1, 2, 300, static fn (): array => [9 => 'Present'])
        );
        self::assertSame(1, $writes);
        try {
            $cache->remember('isp_id', 7, 1, 2, 300, static function (): never {
                throw new RuntimeException('database offline');
            });
            self::fail('The database error must propagate.');
        } catch (RuntimeException $error) {
            self::assertSame('database offline', $error->getMessage());
        }
        self::assertSame(1, $writes, 'the failed read was not written as an empty result');
    }

    public function testBothNativeClientArgumentConventionsAreSupported(): void
    {
        $globals = [];
        foreach (['memcache', 'whatCache', 'memcacheWorking', 'dbhost', 'dbname'] as $name) {
            $globals[$name] = [array_key_exists($name, $GLOBALS), $GLOBALS[$name] ?? null];
        }
        try {
            foreach (['memcache', 'memcached'] as $backend) {
                $client = new class {
                    public array $arguments = [];
                    public function get(string $key): bool
                    {
                        return false;
                    }
                    public function set(mixed ...$arguments): bool
                    {
                        $this->arguments = $arguments;
                        return true;
                    }
                };
                $GLOBALS['memcache'] = $client;
                $GLOBALS['whatCache'] = $backend;
                $GLOBALS['memcacheWorking'] = true;
                $GLOBALS['dbhost'] = 'localhost';
                $GLOBALS['dbname'] = 'test-database';
                $cache = FilterChoicesCache::fromGlobals();
                self::assertNotNull($cache);
                $cache->remember('isp_id', 7, 1, 2, 300, static fn (): array => [9 => 'Present']);
                self::assertCount($backend === 'memcache' ? 4 : 3, $client->arguments);
                self::assertSame(300, $client->arguments[count($client->arguments) - 1]);
            }
            $GLOBALS['memcacheWorking'] = false;
            self::assertNull(FilterChoicesCache::fromGlobals());
        } finally {
            foreach ($globals as $name => [$existed, $value]) {
                if ($existed) {
                    $GLOBALS[$name] = $value;
                } else {
                    unset($GLOBALS[$name]);
                }
            }
        }
    }
}
