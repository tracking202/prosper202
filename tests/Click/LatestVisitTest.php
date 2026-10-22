<?php

declare(strict_types=1);

namespace Tests\Click;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Prosper202\Click\LatestVisit;

/**
 * LatestVisit puts what it is given into SQL, so it takes only a placeholder
 * or a click id's digits, and only a table alias.
 */
final class LatestVisitTest extends TestCase
{
    public function testItTakesAPlaceholderOrAClickIdsDigits(): void
    {
        self::assertStringContainsString('lv.click_id = ? ', LatestVisit::of('?'));
        self::assertStringContainsString('lv.click_id = 42 ', LatestVisit::of('42'));
    }

    /** @return iterable<string, array{string}> */
    public static function notAClickId(): iterable
    {
        $ids = ['', '0', '01', '-1', '1e3', '12abc', ' 1', '1 ', "1\n", '1 OR 1=1', '??', '123456789012345678901'];
        foreach ($ids as $id) {
            yield var_export($id, true) => [$id];
        }
    }

    /** @dataProvider notAClickId */
    public function testItRefusesAnythingElse(string $id): void
    {
        $this->expectException(InvalidArgumentException::class);
        LatestVisit::of($id);
    }

    /** @return iterable<string, array{string}> */
    public static function notAnAlias(): iterable
    {
        foreach (['', 'c d', 'c;', '`c`', 'c.d', 'c--', "c\n"] as $alias) {
            yield var_export($alias, true) => [$alias];
        }
    }

    /** @dataProvider notAnAlias */
    public function testItRefusesAnAliasThatIsNotOne(string $alias): void
    {
        $this->expectException(InvalidArgumentException::class);
        LatestVisit::orderBy($alias, 'r');
    }

    /** @dataProvider notAnAlias */
    public function testItRefusesARouteAliasThatIsNotOne(string $alias): void
    {
        $this->expectException(InvalidArgumentException::class);
        LatestVisit::orderBy('c', $alias);
    }
}
