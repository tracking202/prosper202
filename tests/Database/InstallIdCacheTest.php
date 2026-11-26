<?php

declare(strict_types=1);

namespace Tests\Database;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\InstallIdCache;

/**
 * The install id in the cache namespace, held in memcached so a request does
 * not read the database for it (InstallIdCache). What it answers: the held
 * id while it is fresh, or whenever there is no database to ask (the outage
 * fallback); the database's id once it is not fresh; and nothing (no cache)
 * when the id cannot be known, never the held one in its place.
 */
final class InstallIdCacheTest extends TestCase
{
    private const A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const NOW = 1_800_000_000;

    /** @var array<string, string> */
    private array $store = [];
    private int $reads = 0;

    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['memcache', 'whatCache', 'memcacheWorking', 'db'] as $name) {
            $this->saved[$name] = array_key_exists($name, $GLOBALS) ? $GLOBALS[$name] : '__unset__';
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            if ($value === '__unset__') {
                unset($GLOBALS[$name]);
            } else {
                $GLOBALS[$name] = $value;
            }
        }
    }

    public function testAFreshHeldIdIsAnsweredWithoutReadingTheDatabase(): void
    {
        $this->store['k'] = self::A . ':' . (self::NOW - InstallIdCache::VERIFY_EVERY_SECONDS + 1);

        self::assertSame(self::A, $this->resolve(self::B));
        self::assertSame(0, $this->reads);
    }

    public function testAHeldIdNoLongerFreshIsReadAgainAndRewritten(): void
    {
        $this->store['k'] = self::A . ':' . (self::NOW - InstallIdCache::VERIFY_EVERY_SECONDS);

        self::assertSame(self::B, $this->resolve(self::B), 'a reinstall the installer could not announce');
        self::assertSame(1, $this->reads);
        self::assertSame(self::B . ':' . self::NOW, $this->store['k']);
    }

    public function testAHeldIdFromTheFutureIsNotFresh(): void
    {
        $this->store['k'] = self::A . ':' . (self::NOW + 86400 * 365);

        self::assertSame(self::B, $this->resolve(self::B));
    }

    public function testWithNoDatabaseTheHeldIdIsAnsweredHoweverOld(): void
    {
        $this->store['k'] = self::A . ':0';

        self::assertSame(self::A, $this->resolve(null), 'the outage fallback reads its URL under it');
    }

    public function testWithNoDatabaseAndNothingHeldNothingIsAnswered(): void
    {
        self::assertNull($this->resolve(null));
        self::assertSame([], $this->store);
    }

    public function testAReadThatFailsIsNoAnswerEvenWithAnIdHeld(): void
    {
        $this->store['k'] = self::A . ':' . (self::NOW - 3600);
        $failed = function (): string {
            $this->reads++;
            throw new \RuntimeException('The install id could not be read');
        };

        $log = ini_set('error_log', '/dev/null');
        try {
            self::assertNull(InstallIdCache::resolve('k', $this->get(), $this->set(), $failed, self::NOW));
        } finally {
            ini_set('error_log', (string) $log);
        }
        self::assertSame(1, $this->reads);
        self::assertSame(self::A . ':' . (self::NOW - 3600), $this->store['k'], 'nothing is written');
    }

    /** @return list<array{0: mixed}> */
    public static function notAPointer(): array
    {
        return [
            [false],
            [''],
            [self::A],
            [self::A . ':'],
            [self::A . ':01'],
            [self::A . ':1.5'],
            [strtoupper(self::A) . ':1'],
            [substr(self::A, 1) . ':1'],
            [self::A . ':1' . "\n"],
            [' ' . self::A . ':1'],
            [1],
            [[self::A, 1]],
        ];
    }

    /** @dataProvider notAPointer */
    public function testAHeldValueThatIsNotAPointerIsReadAgain(mixed $held): void
    {
        $get = static fn (string $key): mixed => $held;

        $reads = 0;
        $read = static function () use (&$reads): string {
            $reads++;

            return self::B;
        };
        self::assertSame(self::B, InstallIdCache::resolve('k', $get, $this->set(), $read, self::NOW));
        self::assertSame(1, $reads);
        self::assertNull(InstallIdCache::resolve('k', $get, $this->set(), null, self::NOW), 'and no outage reads it');
    }

    public function testAGetOrSetThatThrowsIsAMissAndNotAFailure(): void
    {
        $throws = static function (): never {
            throw new \RuntimeException('memcached went away');
        };

        self::assertSame(self::B, InstallIdCache::resolve('k', $throws, $throws, static fn (): string => self::B, self::NOW));
        self::assertNull(InstallIdCache::resolve('k', $throws, $throws, null, self::NOW));
    }

    public function testEachPlaceIsItsOwnKey(): void
    {
        $keys = [
            InstallIdCache::pointerKey('web1', ['/var/www/p202/202-config', 'localhost', 'p202']),
            InstallIdCache::pointerKey('web2', ['/var/www/p202/202-config', 'localhost', 'p202']),
            InstallIdCache::pointerKey('web1', ['/var/www/other/202-config', 'localhost', 'p202']),
            InstallIdCache::pointerKey('web1', ['/var/www/p202/202-config', 'localhost', 'p202b']),
            InstallIdCache::pointerKey('web1', ['/var/www/p202/202-config', 'localhost_', 'b']),
            InstallIdCache::pointerKey('web1', ['/var/www/p202/202-config', 'localhost', '_b']),
            InstallIdCache::pointerKey('web1', ['/var/www/p202/202-config', '1:x', '']),
            InstallIdCache::pointerKey('web1', ['/var/www/p202/202-config', '', '1:x']),
        ];

        self::assertSame($keys, array_values(array_unique($keys)));
        self::assertSame($keys[0], InstallIdCache::pointerKey('web1', ['/var/www/p202/202-config', 'localhost', 'p202']));
    }

    /** memcache off: nothing to namespace, so no database read and no shared value. */
    public function testWithMemcacheOffNoIdIsReadAndNoNamespaceIsShared(): void
    {
        $GLOBALS['memcacheWorking'] = false;
        $GLOBALS['memcache'] = null;
        $GLOBALS['db'] = null;
        $place = ['/var/www/off-' . bin2hex(random_bytes(4)) . '/202-config', 'localhost', 'p202'];

        $id = InstallIdCache::forBootstrap($place);
        self::assertStringStartsWith('uncached:', $id);
        self::assertSame($id, InstallIdCache::forBootstrap($place), 'one answer a process');
        $other = ['/var/www/off-' . bin2hex(random_bytes(4)) . '/202-config', 'localhost', 'p202'];
        self::assertNotSame($id, InstallIdCache::forBootstrap($other));
    }

    /** The bootstrap's client, in an outage: the held id names the entries; with none, memcache goes off. */
    public function testTheBootstrapReadsTheHeldIdThroughItsClient(): void
    {
        $client = new class {
            /** @var array<string, mixed> */
            public array $kept = [];

            public function get(string $key): mixed
            {
                return $this->kept[$key] ?? false;
            }

            public function set(string $key, mixed $value, int $exp): bool
            {
                $this->kept[$key] = $value;

                return true;
            }
        };
        $GLOBALS['memcache'] = $client;
        $GLOBALS['whatCache'] = 'memcached';
        $GLOBALS['memcacheWorking'] = true;
        $GLOBALS['db'] = null;

        $held = ['/var/www/held-' . bin2hex(random_bytes(4)) . '/202-config', 'localhost', 'p202'];
        $client->kept[InstallIdCache::pointerKey((string) gethostname(), $held)] = self::A . ':1';
        self::assertSame(self::A, InstallIdCache::forBootstrap($held));
        self::assertTrue($GLOBALS['memcacheWorking']);

        $none = ['/var/www/none-' . bin2hex(random_bytes(4)) . '/202-config', 'localhost', 'p202'];
        self::assertStringStartsWith('uncached:', InstallIdCache::forBootstrap($none));
        self::assertFalse($GLOBALS['memcacheWorking'], 'the rest of the request caches nothing');
    }

    private function resolve(?string $databaseId): ?string
    {
        $read = $databaseId === null ? null : function () use ($databaseId): string {
            $this->reads++;

            return $databaseId;
        };

        return InstallIdCache::resolve('k', $this->get(), $this->set(), $read, self::NOW);
    }

    private function get(): \Closure
    {
        return fn (string $key): mixed => $this->store[$key] ?? false;
    }

    private function set(): \Closure
    {
        return function (string $key, string $value): bool {
            $this->store[$key] = $value;

            return true;
        };
    }
}
