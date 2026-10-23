<?php

declare(strict_types=1);

namespace Tests\Click;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Prosper202\Click\FirstVisit;

/** FirstVisit puts what it is given into SQL, so it takes only a placeholder or a click id's digits. */
final class FirstVisitTest extends TestCase
{
    public function testItTakesAPlaceholderOrAClickIdsDigits(): void
    {
        self::assertStringContainsString('fv.click_id = ? ', FirstVisit::of('?'));
        self::assertStringContainsString('fv.click_id = 42 ', FirstVisit::of('42'));
        self::assertStringContainsString('ORDER BY fv.click_time ASC LIMIT 1', FirstVisit::of('?'));
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
        FirstVisit::of($id);
    }
}
