<?php

declare(strict_types=1);

namespace Tests\Conversion\Ledger;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\Ledger\Amount;
use Prosper202\Conversion\Ledger\ClickValueCalculator;
use Prosper202\Conversion\Ledger\ConversionSource;
use Prosper202\Conversion\Ledger\LedgerRow;
use Prosper202\Conversion\Ledger\PayoutMode;
use Prosper202\Conversion\Ledger\SupersededReason;

/**
 * The one definition of a click's value, rule by rule (see the class
 * docblock of ClickValueCalculator for the rules these name).
 */
final class ClickValueCalculatorTest extends TestCase
{
    private static function row(int $id, string $amount, array $o = []): LedgerRow
    {
        return new LedgerRow(
            convId: $id,
            amountUnits: Amount::toUnits($amount),
            payable: $o['payable'] ?? true,
            deleted: $o['deleted'] ?? false,
            reversesConvId: $o['reverses'] ?? null,
            source: $o['source'] ?? ConversionSource::POSTBACK,
            batchId: $o['batch'] ?? null,
            supersededReason: $o['reason'] ?? null,
            supersededBy: $o['by'] ?? null,
        );
    }

    private static function value(array $rows, PayoutMode $mode): ?string
    {
        $v = ClickValueCalculator::calculate($rows, $mode);

        return $v->valueUnits === null ? null : Amount::fromUnits($v->valueUnits);
    }

    public function testNoRowsIsNotALeadAndHasNoValue(): void
    {
        $v = ClickValueCalculator::calculate([], PayoutMode::REPLACE);
        self::assertFalse($v->lead);
        self::assertNull($v->valueUnits);
        self::assertSame([], $v->counted);
    }

    public function testReplaceTakesTheLatestRowAndSupersedesTheRest(): void
    {
        $rows = [self::row(1, '5'), self::row(2, '10'), self::row(3, '2')];
        $v = ClickValueCalculator::calculate($rows, PayoutMode::REPLACE);

        self::assertTrue($v->lead);
        self::assertSame('2.00000', Amount::fromUnits((int) $v->valueUnits));
        self::assertSame([
            1 => ['by' => 3, 'reason' => SupersededReason::REPLACE],
            2 => ['by' => 3, 'reason' => SupersededReason::REPLACE],
        ], $v->derivedSupersessions);
        self::assertSame([3 => true], $v->counted);
    }

    public function testLatestIsInsertionOrderNotTheOrderRowsArriveIn(): void
    {
        $rows = [self::row(3, '2'), self::row(1, '5'), self::row(2, '10')];
        self::assertSame('2.00000', self::value($rows, PayoutMode::REPLACE));
    }

    public function testAccumulateSumsEveryPayableRowExactly(): void
    {
        $rows = [self::row(1, '0.1'), self::row(2, '0.2'), self::row(3, '4.70001')];
        $v = ClickValueCalculator::calculate($rows, PayoutMode::ACCUMULATE);

        self::assertSame('5.00001', Amount::fromUnits((int) $v->valueUnits), 'no float drift: 0.1 + 0.2 is 0.3');
        self::assertSame([], $v->derivedSupersessions);
    }

    public function testDeletedAndUnpaidRowsNeverCount(): void
    {
        $rows = [
            self::row(1, '5'),
            self::row(2, '100', ['deleted' => true]),
            self::row(3, '50', ['payable' => false]),
        ];
        self::assertSame('5.00000', self::value($rows, PayoutMode::REPLACE), 'the latest counted row, not a deleted or unpaid one');
        self::assertSame('5.00000', self::value($rows, PayoutMode::ACCUMULATE));
    }

    public function testOnlyUnpaidRowsIsNotALead(): void
    {
        $v = ClickValueCalculator::calculate([self::row(1, '0', ['payable' => false])], PayoutMode::ACCUMULATE);
        self::assertFalse($v->lead);
        self::assertNull($v->valueUnits);
    }

    public function testDeletingTheLatestRowRestoresThePreviousOne(): void
    {
        $rows = [self::row(1, '5'), self::row(2, '10', ['deleted' => true, 'reason' => SupersededReason::REPLACE, 'by' => 9])];
        $v = ClickValueCalculator::calculate($rows, PayoutMode::REPLACE);

        self::assertSame('5.00000', Amount::fromUnits((int) $v->valueUnits));
        self::assertArrayNotHasKey(1, $v->derivedSupersessions, 'row 1 is no longer superseded');
    }

    public function testAReversalNetsAgainstItsTargetInBothModes(): void
    {
        $rows = [self::row(1, '3'), self::row(2, '-3', ['reverses' => 1])];
        self::assertSame('0.00000', self::value($rows, PayoutMode::REPLACE), 'a $3 sale reversed is $0, not -$3');
        self::assertSame('0.00000', self::value($rows, PayoutMode::ACCUMULATE));

        $v = ClickValueCalculator::calculate($rows, PayoutMode::REPLACE);
        self::assertTrue($v->lead, 'a reversed sale is still a converting click');
        self::assertSame([1 => true, 2 => true], $v->counted);
    }

    public function testAReversalNeverCompetesForLatest(): void
    {
        // Sale A $5, sale B $7 (latest, wins), then a reversal of A.
        $rows = [self::row(1, '5'), self::row(2, '7'), self::row(3, '-5', ['reverses' => 1])];
        self::assertSame('7.00000', self::value($rows, PayoutMode::REPLACE), 'reversing a superseded row changes nothing');
        self::assertSame('7.00000', self::value($rows, PayoutMode::ACCUMULATE), '5 + 7 - 5');
    }

    public function testAPartialReversalNets(): void
    {
        $rows = [self::row(1, '10'), self::row(2, '-4', ['reverses' => 1])];
        self::assertSame('6.00000', self::value($rows, PayoutMode::REPLACE));
    }

    public function testAReversalOfADeletedRowDoesNotCount(): void
    {
        $rows = [self::row(1, '10', ['deleted' => true]), self::row(2, '-10', ['reverses' => 1]), self::row(3, '4')];
        $v = ClickValueCalculator::calculate($rows, PayoutMode::ACCUMULATE);
        self::assertSame('4.00000', Amount::fromUnits((int) $v->valueUnits));
        self::assertArrayNotHasKey(2, $v->counted);
    }

    public function testTheNewestUploadBatchIsSummedAndReplacesOlderBatchesAndEarlierRows(): void
    {
        $upload = ConversionSource::REVENUE_UPLOAD;
        $rows = [
            self::row(1, '9'),                                                    // a postback before any upload
            self::row(2, '1', ['source' => $upload, 'batch' => 7]),
            self::row(3, '2', ['source' => $upload, 'batch' => 7]),
            self::row(4, '3', ['source' => $upload, 'batch' => 8]),               // the newer file: two lines
            self::row(5, '4', ['source' => $upload, 'batch' => 8]),
        ];
        foreach ([PayoutMode::REPLACE, PayoutMode::ACCUMULATE] as $mode) {
            $v = ClickValueCalculator::calculate($rows, $mode);
            self::assertSame('7.00000', Amount::fromUnits((int) $v->valueUnits), $mode->value . ': sum within the newest file');
            self::assertSame([
                1 => ['by' => 4, 'reason' => SupersededReason::BATCH],
                2 => ['by' => 4, 'reason' => SupersededReason::BATCH],
                3 => ['by' => 4, 'reason' => SupersededReason::BATCH],
            ], $v->derivedSupersessions, $mode->value);
        }
    }

    public function testAConversionAfterTheUploadWinsInReplaceAndAddsInAccumulate(): void
    {
        $upload = ConversionSource::REVENUE_UPLOAD;
        $rows = [
            self::row(1, '3', ['source' => $upload, 'batch' => 8]),
            self::row(2, '4', ['source' => $upload, 'batch' => 8]),
            self::row(3, '10'),
        ];
        self::assertSame('10.00000', self::value($rows, PayoutMode::REPLACE));
        self::assertSame('17.00000', self::value($rows, PayoutMode::ACCUMULATE));

        $v = ClickValueCalculator::calculate($rows, PayoutMode::REPLACE);
        self::assertSame(['by' => 3, 'reason' => SupersededReason::REPLACE], $v->derivedSupersessions[1]);
        self::assertSame(['by' => 3, 'reason' => SupersededReason::REPLACE], $v->derivedSupersessions[2]);
    }

    public function testFixedSupersessionsAreNeitherCountedNorRewritten(): void
    {
        $rows = [
            self::row(1, '50', ['reason' => SupersededReason::PRE_LEDGER, 'by' => 3]),
            self::row(2, '20', ['reason' => SupersededReason::REPLAY, 'by' => 4]),
            self::row(3, '8', ['source' => ConversionSource::LEGACY_BASELINE]),
            self::row(4, '1', ['source' => ConversionSource::GOAL]),
        ];
        $v = ClickValueCalculator::calculate($rows, PayoutMode::ACCUMULATE);
        self::assertSame('9.00000', Amount::fromUnits((int) $v->valueUnits), 'the baseline plus the replacement goal row');
        self::assertArrayNotHasKey(1, $v->derivedSupersessions, 'a fixed reason is not the recompute\'s to touch');
        self::assertArrayNotHasKey(2, $v->derivedSupersessions);
    }

    public function testReversingASaleFromBeforeTheLedgerNetsAgainstTheBaseline(): void
    {
        // Pre-ledger sale $12 (row 1), carried in as the $12 baseline (row 2),
        // then reversed (row 3).
        $rows = [
            self::row(1, '12', ['reason' => SupersededReason::PRE_LEDGER, 'by' => 2]),
            self::row(2, '12', ['source' => ConversionSource::LEGACY_BASELINE]),
            self::row(3, '-12', ['reverses' => 1]),
        ];
        self::assertSame('0.00000', self::value($rows, PayoutMode::REPLACE));

        // Once a new conversion replaces the baseline, the old sale's
        // reversal has nothing left to net against.
        $rows[] = self::row(4, '5');
        self::assertSame('5.00000', self::value($rows, PayoutMode::REPLACE));
    }

    /**
     * countedAsStored() is the "before" the recompute diffs against to find
     * reversals whose counted state flipped. On a state the last recompute
     * wrote, it must agree with calculate() exactly.
     */
    public function testCountedAsStoredAgreesWithCalculateOnAStateItWrote(): void
    {
        $cases = [
            'sale and its reversal' => [[self::row(1, '5'), self::row(2, '-5', ['reverses' => 1])], PayoutMode::REPLACE],
            'superseded sale, reversal, later sale' => [[
                self::row(1, '5', ['reason' => SupersededReason::REPLACE, 'by' => 3]),
                self::row(2, '-5', ['reverses' => 1]),
                self::row(3, '10'),
            ], PayoutMode::REPLACE],
            'accumulate with a deleted row' => [[self::row(1, '5'), self::row(2, '3', ['deleted' => true]), self::row(3, '-5', ['reverses' => 1])], PayoutMode::ACCUMULATE],
            'pre-ledger sale reversed against the baseline' => [[
                self::row(1, '5', ['reason' => SupersededReason::PRE_LEDGER, 'by' => 3]),
                self::row(2, '-5', ['reverses' => 1]),
                self::row(3, '5', ['source' => ConversionSource::LEGACY_BASELINE]),
            ], PayoutMode::REPLACE],
        ];
        foreach ($cases as $name => [$rows, $mode]) {
            self::assertSame(ClickValueCalculator::calculate($rows, $mode)->counted, ClickValueCalculator::countedAsStored($rows), $name);
        }
    }

    public function testAReversalStopsCountingWhenALaterSaleSupersedesItsSale(): void
    {
        // As stored before the recompute: sale 1 counts, 2 reverses it, 3 is new.
        $rows = [self::row(1, '5'), self::row(2, '-5', ['reverses' => 1]), self::row(3, '10')];
        $before = ClickValueCalculator::countedAsStored($rows);
        $after = ClickValueCalculator::calculate($rows, PayoutMode::REPLACE)->counted;

        self::assertArrayHasKey(2, $before, 'the reversal netted before');
        self::assertArrayNotHasKey(2, $after, 'and does not once 3 supersedes its sale');
    }

    public function testAPreLedgerClickWithOnlyItsOldRowsIsNotALead(): void
    {
        // Cleared before the upgrade: old rows survive, marked pre_ledger;
        // they must not bring the conversion back.
        $rows = [self::row(1, '50', ['reason' => SupersededReason::PRE_LEDGER])];
        self::assertFalse(ClickValueCalculator::calculate($rows, PayoutMode::REPLACE)->lead);
    }
}
