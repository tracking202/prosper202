<?php

declare(strict_types=1);

namespace Tests\Database;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\ConnectionFactory;

/**
 * What the click path asks of a 202-config.php DB class it did not write:
 * releaseReadConnection() and adoptPrimary() answer false for a class they
 * cannot read, and never throw (every click runs them).
 * RetainedConfigClickIntegrationTest runs both on a real server.
 */
final class ConnectionFactoryRetainedConfigTest extends TestCase
{
    private static function unconnected(): \mysqli
    {
        return (new \ReflectionClass(\mysqli::class))->newInstanceWithoutConstructor();
    }

    /** A class named for this test alone, from a body with `NAME` in place of its name. */
    private static function declare(string $body): string
    {
        $name = 'P202RetainedDb' . bin2hex(random_bytes(4));
        eval(str_replace('NAME', $name, $body));

        return $name;
    }

    public function testAnUninitializedTypedReadSlotIsNotReleasedAndDoesNotThrow(): void
    {
        $class = self::declare('final class NAME { private \mysqli $_connectionro; }');

        self::assertFalse(ConnectionFactory::releaseReadConnection(new $class(), self::unconnected()));
    }

    public function testTheSamplesShapeIsAdoptedForBothConnections(): void
    {
        $class = self::declare('class NAME {
            private $_connection, $_connectionro;
            private static $_instance;
            public static function getInstance() {
                if (!self::$_instance) { self::$_instance = new self(); }
                return self::$_instance;
            }
            private function __construct() { throw new \LogicException("the constructor must not run"); }
            public function getConnection() { return $this->_connection; }
            public function getConnectionro() { return $this->_connectionro; }
        }');
        $primary = self::unconnected();

        self::assertTrue(ConnectionFactory::adoptPrimary($class, $primary));
        self::assertSame($primary, $class::getInstance()->getConnection());
        self::assertSame($primary, $class::getInstance()->getConnectionro());
        self::assertFalse(
            ConnectionFactory::adoptPrimary($class, self::unconnected()),
            'a singleton already set is kept'
        );
        self::assertSame($primary, $class::getInstance()->getConnection());
    }

    public function testAClassOfAnotherShapeIsLeftAlone(): void
    {
        $noInstance = self::declare('class NAME { private $_connection;
            public static function getInstance() {} public function getConnection() {} }');
        $typedStatic = self::declare('class NAME { private $_connection; private static NAME $_instance;
            public static function getInstance() { return self::$_instance; }
            public function getConnection() { return $this->_connection; } }');

        self::assertFalse(ConnectionFactory::adoptPrimary($noInstance, self::unconnected()));
        self::assertFalse(
            ConnectionFactory::adoptPrimary($typedStatic, self::unconnected()),
            'an uninitialized typed static'
        );
        self::assertFalse(ConnectionFactory::adoptPrimary('P202NoSuchDbClass', self::unconnected()));
    }
}
