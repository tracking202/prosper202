<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\CreditCalculator;
use Prosper202\Attribution\Journey;
use Prosper202\Attribution\Model;
use Prosper202\Attribution\ModelConfig;
use Prosper202\Attribution\ModelType;
use Prosper202\Attribution\Touch;
use Prosper202\Conversion\Ledger\Amount;

/**
 * The credit arithmetic: every model's shares sum to exactly one conversion,
 * the revenue sums to exactly the amount, the remainder lands on the last
 * credited touch, and the one- and two-touch edge cases do what the plan
 * says (§6.3, §7.6 "Strategy unit tests").
 */
final class CreditCalculatorTest extends TestCase
{
    private const CONV = 1_700_000_000;
    private const DAY = 86400;

    /** @param array<string, float> $config */
    private static function model(ModelType $type, array $config = [], int $lookbackDays = 30): Model
    {
        return new Model(1, 1, 'm', 'm', $type, ModelConfig::normalize($type, $config), $lookbackDays, Model::STATUS_ACTIVE, false);
    }

    /** @param list<int> $agesInSeconds oldest first; the last is the converting click */
    private static function journey(array $agesInSeconds): Journey
    {
        $touches = [];
        foreach ($agesInSeconds as $i => $age) {
            $touches[] = new Touch($i, 1000 + $i, self::CONV - $age);
        }

        return new Journey($touches, 30, false, true);
    }

    /** @return array<string, array{0: ModelType, 1: array<string, float>}> */
    public static function models(): array
    {
        return [
            'last_touch' => [ModelType::LAST_TOUCH, []],
            'first_touch' => [ModelType::FIRST_TOUCH, []],
            'linear' => [ModelType::LINEAR, []],
            'time_decay' => [ModelType::TIME_DECAY, ['half_life_hours' => 7.0]],
            'position_based' => [ModelType::POSITION_BASED, ['first_weight' => 0.3, 'last_weight' => 0.5]],
        ];
    }

    /**
     * @dataProvider models
     * @param array<string, float> $config
     */
    public function testCreditsSumToOneAndRevenueToTheAmountExactly(ModelType $type, array $config): void
    {
        $amounts = ['10.00000', '0.00001', '0.00000', '999999.99999', '7.33333', '-3.00000', '1.00000'];
        foreach ([1, 2, 3, 7, 25] as $n) {
            $ages = [];
            for ($i = $n - 1; $i >= 0; $i--) {
                $ages[] = $i * 3 * 3600 + 17;
            }
            foreach ($amounts as $amount) {
                $units = Amount::toUnits($amount);
                $credits = CreditCalculator::credits(self::model($type, $config), self::journey($ages), self::CONV, $units);

                self::assertSame(CreditCalculator::CREDIT_SCALE, array_sum(array_map(static fn ($c) => $c->creditUnits, $credits)), "$type->value, $n touches, $amount: credit");
                self::assertSame($units, array_sum(array_map(static fn ($c) => $c->revenueUnits, $credits)), "$type->value, $n touches, $amount: revenue");
                foreach ($credits as $c) {
                    self::assertGreaterThan(0, $c->creditUnits, 'a credited touch has credit');
                    self::assertSame($units < 0 ? -1 : 1, $c->revenueUnits === 0 ? ($units < 0 ? -1 : 1) : $c->revenueUnits <=> 0, 'revenue carries the amount\'s sign');
                }
            }
        }
    }

    public function testLastAndFirstTouch(): void
    {
        $j = self::journey([3 * self::DAY, 2 * self::DAY, 60]);
        $last = CreditCalculator::credits(self::model(ModelType::LAST_TOUCH), $j, self::CONV, 1_000_000);
        self::assertCount(1, $last);
        self::assertSame(2, $last[0]->position);
        self::assertSame('1.00000000', $last[0]->creditDecimal());
        self::assertSame(1_000_000, $last[0]->revenueUnits);

        $first = CreditCalculator::credits(self::model(ModelType::FIRST_TOUCH), $j, self::CONV, 1_000_000);
        self::assertCount(1, $first);
        self::assertSame(0, $first[0]->position);
        self::assertSame(1000, $first[0]->clickId);
    }

    public function testLinearPutsTheRemainderOnTheLastTouch(): void
    {
        $credits = CreditCalculator::credits(self::model(ModelType::LINEAR), self::journey([300, 200, 100]), self::CONV, Amount::toUnits('10'));
        self::assertSame(['0.33333333', '0.33333333', '0.33333334'], array_map(static fn ($c) => $c->creditDecimal(), $credits));
        self::assertSame(['3.33333', '3.33333', '3.33334'], array_map(static fn ($c) => Amount::fromUnits($c->revenueUnits), $credits));
    }

    public function testTimeDecayHalvesPerHalfLife(): void
    {
        // Touches exactly one and two half-lives older than the newest.
        $h = 48 * 3600;
        $credits = CreditCalculator::credits(self::model(ModelType::TIME_DECAY), self::journey([2 * $h + 10, $h + 10, 10]), self::CONV, Amount::toUnits('7'));
        // Weights 1/4, 1/2, 1 over 7/4: 1/7, 2/7, 4/7.
        self::assertSame([14285714, 28571428, 57142858], array_map(static fn ($c) => $c->creditUnits, $credits));
        self::assertSame(['1.00000', '2.00000', '4.00000'], array_map(static fn ($c) => Amount::fromUnits($c->revenueUnits), $credits));
    }

    public function testTimeDecayOverAYearDoesNotUnderflowTheWholeJourney(): void
    {
        $credits = CreditCalculator::credits(
            self::model(ModelType::TIME_DECAY, ['half_life_hours' => 1.0]),
            self::journey([300 * self::DAY, 299 * self::DAY]),
            self::CONV,
            100
        );
        // Both touches are outside the 30-day window except the converting
        // click, which is always inside: it gets everything.
        self::assertCount(1, $credits);
        self::assertSame(CreditCalculator::CREDIT_SCALE, $credits[0]->creditUnits);

        $wide = new Model(1, 1, 'm', 'm', ModelType::TIME_DECAY, ['half_life_hours' => 1.0], 365, Model::STATUS_ACTIVE, false);
        $credits = CreditCalculator::credits($wide, self::journey([300 * self::DAY, 298 * self::DAY]), self::CONV, 100);
        // 48 half-lives apart: the older touch's share floors to zero and is left out.
        self::assertCount(1, $credits);
        self::assertSame(1, $credits[0]->position);
        self::assertSame(100, $credits[0]->revenueUnits);
    }

    public function testPositionBasedEdgeCases(): void
    {
        $m = self::model(ModelType::POSITION_BASED);
        // One touch: all of it.
        $one = CreditCalculator::credits($m, self::journey([10]), self::CONV, 500);
        self::assertSame([CreditCalculator::CREDIT_SCALE], array_map(static fn ($c) => $c->creditUnits, $one));
        // Two touches: the ends renormalise, 0.4/0.4 -> 0.5/0.5.
        $two = CreditCalculator::credits($m, self::journey([20, 10]), self::CONV, 500);
        self::assertSame([50_000_000, 50_000_000], array_map(static fn ($c) => $c->creditUnits, $two));
        // Four touches: 0.4, 0.1, 0.1, 0.4.
        $four = CreditCalculator::credits($m, self::journey([40, 30, 20, 10]), self::CONV, 1000);
        self::assertSame([40_000_000, 10_000_000, 10_000_000, 40_000_000], array_map(static fn ($c) => $c->creditUnits, $four));
        // Two touches with both ends weighted 0: an undefined ratio splits evenly, never "nobody".
        $zero = CreditCalculator::credits(self::model(ModelType::POSITION_BASED, ['first_weight' => 0, 'last_weight' => 0]), self::journey([20, 10]), self::CONV, 3);
        self::assertSame([50_000_000, 50_000_000], array_map(static fn ($c) => $c->creditUnits, $zero));
        self::assertSame(3, array_sum(array_map(static fn ($c) => $c->revenueUnits, $zero)));
    }

    public function testPositionBasedWithNoLastWeightNeverHandsRevenueToAnUncreditedTouch(): void
    {
        $m = self::model(ModelType::POSITION_BASED, ['first_weight' => 0.5, 'last_weight' => 0.0]);
        $credits = CreditCalculator::credits($m, self::journey([40, 30, 20, 10]), self::CONV, Amount::toUnits('1.00001'));
        self::assertSame([0, 1, 2], array_map(static fn ($c) => $c->position, $credits), 'the converting click has no credit and no row');
        self::assertSame(Amount::toUnits('1.00001'), array_sum(array_map(static fn ($c) => $c->revenueUnits, $credits)));
    }

    public function testRevenueRoundsHalfUpButNeverLeavesTheLastTouchNegative(): void
    {
        // 0.3 / 0.3 / 0.3 / 0.1 of 5 units: rounding the first three half up
        // (1.5 -> 2 each) would leave the converting click -1, so the split
        // falls back to flooring them.
        $m = self::model(ModelType::POSITION_BASED, ['first_weight' => 0.3, 'last_weight' => 0.1]);
        $credits = CreditCalculator::credits($m, self::journey([40, 30, 20, 10]), self::CONV, 5);
        self::assertSame([30_000_000, 30_000_000, 30_000_000, 10_000_000], array_map(static fn ($c) => $c->creditUnits, $credits));
        self::assertSame([1, 1, 1, 2], array_map(static fn ($c) => $c->revenueUnits, $credits));
    }

    public function testTiesAreBrokenByPositionNotByChance(): void
    {
        // Three touches in the same second: first_touch is position 0.
        $touches = [new Touch(0, 5, self::CONV - 10), new Touch(1, 6, self::CONV - 10), new Touch(2, 7, self::CONV - 10)];
        $j = new Journey($touches, 30, false, true);
        self::assertSame(5, CreditCalculator::credits(self::model(ModelType::FIRST_TOUCH), $j, self::CONV, 1)[0]->clickId);
        $td = CreditCalculator::credits(self::model(ModelType::TIME_DECAY), $j, self::CONV, 1);
        self::assertSame([33333333, 33333333, 33333334], array_map(static fn ($c) => $c->creditUnits, $td));
        self::assertSame([0, 0, 1], array_map(static fn ($c) => $c->revenueUnits, $td));
    }

    public function testTheWindowKeepsTheConvertingClickWhateverItsAge(): void
    {
        $j = self::journey([40 * self::DAY, 35 * self::DAY, 31 * self::DAY]);
        $credits = CreditCalculator::credits(self::model(ModelType::LINEAR, [], 30), $j, self::CONV, 90);
        self::assertSame([2], array_map(static fn ($c) => $c->position, $credits));

        $credits = CreditCalculator::credits(self::model(ModelType::LINEAR, [], 36), $j, self::CONV, 90);
        self::assertSame([1, 2], array_map(static fn ($c) => $c->position, $credits));
    }

    public function testMulDivFloorIsExactAtTheColumnsLimits(): void
    {
        // Expected values computed with arbitrary-precision integers; the
        // first three overflow a 64-bit product if multiplied directly.
        foreach ([
            [99999999999, 12345678, 12345677999],
            [99999999999, 100000000, 99999999999],
            [99999999999, 33333333, 33333332999],
            [123456789, 99999999, 123456787],
            [10, 50000000, 5],
            [3, 33333333, 0],
            [1, 99999999, 0],
        ] as [$a, $b, $expected]) {
            self::assertSame($expected, CreditCalculator::mulDivFloor($a, $b, CreditCalculator::CREDIT_SCALE), "$a x $b");
        }
    }

    public function testAnAmountTooLargeToSplitExactlyIsRefusedNotRounded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CreditCalculator::mulDivFloor(PHP_INT_MAX, CreditCalculator::CREDIT_SCALE, CreditCalculator::CREDIT_SCALE);
    }
}
