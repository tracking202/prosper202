<?php
declare(strict_types=1);

namespace Tests\Config;

use Tests\TestCase;

/**
 * Tests for functions-db.php: the memcache_get()/memcache_set() shims, its
 * only functions. The database helpers it used to carry were guarded
 * copies of functions-tracking202.php's, which connect.php loads first, so
 * no page ran them and the cases that exercised them tested a stand-in.
 */
final class DatabaseFunctionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Set up global variables needed by the functions
        global $memcache, $memcacheWorking, $db;

        $this->mockDb = $this->createMockDb();
        $this->mockMemcache = $this->createMockMemcache();

        $db = $this->mockDb;
        $memcache = $this->mockMemcache;
        $memcacheWorking = true;

        // Include the functions file
        require_once __DIR__ . '/../../202-config/functions-db.php';
    }

    protected function tearDown(): void
    {
        global $memcache, $memcacheWorking, $db;
        $memcache = null;
        $memcacheWorking = false;
        $db = null;

        parent::tearDown();
    }

    public function testMemcacheGetReturnsCachedValue(): void
    {
        global $memcache;

        $memcache->addToCache('test_key', 'test_value');

        $result = memcache_get('test_key');

        $this->assertSame('test_value', $result);
    }

    public function testMemcacheGetReturnsFalseForMissingKey(): void
    {
        $result = memcache_get('nonexistent_key');

        $this->assertFalse($result);
    }

    public function testMemcacheSetReturnsFalseForNonStandardMemcache(): void
    {
        // The memcache_set function checks instanceof Memcache or Memcached
        // Our mock is neither, so it returns false
        $result = memcache_set('new_key', 'new_value');

        $this->assertFalse($result);
    }

    public function testMemcacheSetReturnsFalseWhenMemcacheNotWorking(): void
    {
        global $memcacheWorking;
        $memcacheWorking = false;

        $result = memcache_set('key', 'value');

        $this->assertFalse($result);
    }

    public function testMemcacheGetReturnsFalseWhenMemcacheNotWorking(): void
    {
        global $memcacheWorking, $memcache;

        $memcache->addToCache('test_key', 'test_value');
        $memcacheWorking = false;

        $result = memcache_get('test_key');

        $this->assertFalse($result);
    }

    public function testMemcacheSetWithExpirationReturnsFalse(): void
    {
        // The memcache_set function checks instanceof Memcache or Memcached
        // Our mock is neither, so it returns false
        $result = memcache_set('expire_key', 'value', 3600);

        $this->assertFalse($result);
    }

    public function testMemcacheGetWithDirectCacheAccess(): void
    {
        global $memcache;

        $complexData = [
            'nested' => [
                'array' => [1, 2, 3],
                'object' => (object)['key' => 'value'],
            ],
            'null' => null,
            'bool' => true,
            'int' => 42,
            'float' => 3.14,
        ];

        // Add directly to cache to bypass memcache_set
        $memcache->addToCache('complex_key', $complexData);

        $result = memcache_get('complex_key');

        $this->assertEquals($complexData, $result);
    }

}
