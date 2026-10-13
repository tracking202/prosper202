<?php

declare(strict_types=1);

namespace Tests\Rotator;

use PHPUnit\Framework\TestCase;

/**
 * getSplitTestValue(), the chooser both rotator entry points (rtr.php and
 * offrtr.php) take a rule's redirect from. It lives in connect2.php, which
 * needs a database and cannot be included here, so the function is extracted
 * from the real file and run in its own process.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SplitTestValueTest extends TestCase
{
    protected function setUp(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/202-config/connect2.php');
        $found = preg_match('/^function getSplitTestValue\(array \$values\)\n\{\n.*?^\}\n/ms', $src, $m);
        self::assertSame(1, $found, 'getSplitTestValue() not found in connect2.php');
        eval($m[0]);
    }

    /** @param array<int|string, array{weight: mixed}> $values @return array<string, int> */
    private static function draw(array $values, int $times = 4000): array
    {
        mt_srand(202);
        $seen = [];
        for ($i = 0; $i < $times; $i++) {
            $key = var_export(\getSplitTestValue($values), true);
            $seen[$key] = ($seen[$key] ?? 0) + 1;
        }
        ksort($seen);
        return $seen;
    }

    public function testEachEntryTakesItsWeightsShare(): void
    {
        $seen = self::draw([['weight' => '3'], ['weight' => '1']]);

        self::assertSame(['0', '1'], array_map('strval', array_keys($seen)));
        // 3000 and 1000 expected; the seed fixes the draw, the margin is ~6 sigma.
        self::assertGreaterThan(2830, $seen['0']);
        self::assertLessThan(3170, $seen['0']);
    }

    public function testAnEntryWeightedZeroIsNeverChosen(): void
    {
        self::assertSame(['1' => 4000], self::draw([['weight' => '0'], ['weight' => '5']]));
        self::assertSame(["'b'" => 4000], self::draw(['a' => ['weight' => 0], 'b' => ['weight' => 100]]));
    }

    public function testWithNoPositiveWeightTheFirstEntryTakesTheVisit(): void
    {
        // Returned null: the rule matched and the visitor was sent nowhere.
        self::assertSame(['0' => 4000], self::draw([['weight' => '0'], ['weight' => '0']]));
        self::assertSame(["'x'" => 4000], self::draw(['x' => ['weight' => '0'], 'y' => ['weight' => null]]));
    }

    public function testAWeightThatIsNotANumberTakesNoShare(): void
    {
        // A blank weight threw "Unsupported operand types" from the sum.
        self::assertSame(['1' => 4000], self::draw([['weight' => ''], ['weight' => '5']]));
        self::assertSame(['1' => 4000], self::draw([['weight' => 'abc'], ['weight' => '5'], ['weight' => '-5']]));
        self::assertSame(['1' => 4000], self::draw([[], ['weight' => '5']]));
        self::assertSame(['0' => 4000], self::draw([['weight' => ''], ['weight' => '']]));
    }

    public function testTheKeysAreTheCallersKeys(): void
    {
        $seen = self::draw([7 => ['weight' => '1'], 9 => ['weight' => '1']], 400);

        self::assertSame(['7', '9'], array_map('strval', array_keys($seen)));
    }
}
