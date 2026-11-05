<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/202-config/functions-tracking202.php';

/**
 * Account › API integrations offers the DNI service's networks only when its
 * answer is a list of them; an error answer reads as "could not be loaded".
 */
final class DniNetworkListTest extends TestCase
{
    public function testAListOfNetworksIsKept(): void
    {
        $list = [['networkId' => 7, 'networkType' => 'CAKE', 'name' => 'Example']];
        self::assertSame($list, dniNetworkList($list));
        self::assertSame([], dniNetworkList([]));
    }

    /** @return iterable<string, array{mixed}> */
    public static function notLists(): iterable
    {
        yield 'unreachable or not JSON' => [null];
        yield 'an error answer' => [['code' => 400, 'msg' => 'Install ID not found!']];
        yield 'a list of strings' => [['a', 'b']];
        yield 'an entry without a network id' => [[['name' => 'x', 'networkType' => 'y']]];
    }

    /** @dataProvider notLists */
    public function testAnythingElseIsNotAList(mixed $answer): void
    {
        self::assertNull(dniNetworkList($answer));
    }
}
