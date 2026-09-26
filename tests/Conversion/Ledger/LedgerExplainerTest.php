<?php

declare(strict_types=1);

namespace Tests\Conversion\Ledger;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\Ledger\Amount;
use Prosper202\Conversion\Ledger\ClickValueCalculator;
use Prosper202\Conversion\Ledger\ConversionSource;
use Prosper202\Conversion\Ledger\LedgerExplainer;
use Prosper202\Conversion\Ledger\LedgerRow;
use Prosper202\Conversion\Ledger\NotCountedReason;
use Prosper202\Conversion\Ledger\PayoutMode;
use Prosper202\Conversion\Ledger\SourceRef;
use Prosper202\Conversion\Ledger\SupersededReason;

/**
 * The breakdown's verdict per row: counted exactly when the calculator
 * counts it, and otherwise one reason, in a fixed order.
 */
final class LedgerExplainerTest extends TestCase
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

    /** @return array<int, ?string> conv_id => reason value (null = counted) */
    private static function reasons(array $rows, PayoutMode $mode): array
    {
        return array_map(static fn (array $v): ?string => $v['reason']?->value, LedgerExplainer::explain($rows, $mode)['rows']);
    }

    public function testCountedIsTheCalculatorsCountedSetInEveryShape(): void
    {
        $shapes = [
            [PayoutMode::REPLACE, [self::row(1, '5'), self::row(2, '10'), self::row(3, '-10', ['reverses' => 2])]],
            [PayoutMode::ACCUMULATE, [self::row(1, '5'), self::row(2, '3', ['deleted' => true]), self::row(3, '2', ['payable' => false]), self::row(4, '-5', ['reverses' => 1])]],
            [PayoutMode::REPLACE, [self::row(1, '9'), self::row(2, '1', ['source' => ConversionSource::REVENUE_UPLOAD, 'batch' => 1]), self::row(3, '4', ['source' => ConversionSource::REVENUE_UPLOAD, 'batch' => 2])]],
            [PayoutMode::REPLACE, [self::row(1, '12', ['reason' => SupersededReason::PRE_LEDGER, 'by' => 2]), self::row(2, '12', ['source' => ConversionSource::LEGACY_BASELINE]), self::row(3, '-4', ['reverses' => 1])]],
            [PayoutMode::ACCUMULATE, [self::row(1, '2', ['source' => ConversionSource::GOAL, 'reason' => SupersededReason::REPLAY, 'by' => 2]), self::row(2, '2', ['source' => ConversionSource::GOAL])]],
        ];
        foreach ($shapes as $i => [$mode, $rows]) {
            $explained = LedgerExplainer::explain($rows, $mode);
            $counted = array_keys(array_filter($explained['rows'], static fn (array $v): bool => $v['counted']));
            self::assertSame(array_keys(ClickValueCalculator::calculate($rows, $mode)->counted), $counted, "shape $i");
            foreach ($explained['rows'] as $convId => $v) {
                self::assertSame($v['counted'], $v['reason'] === null, "shape $i row $convId: a row counts or has a reason, never both or neither");
            }
        }
    }

    public function testEachReasonInItsOrder(): void
    {
        $rows = [
            self::row(1, '5'),                                             // superseded by 4 (replace)
            self::row(2, '3', ['deleted' => true, 'payable' => false]),    // deleted wins over unpaid
            self::row(3, '0', ['payable' => false]),                       // unpaid
            self::row(4, '10'),                                            // counts
            self::row(5, '-5', ['reverses' => 1]),                         // reverses a superseded sale
            self::row(6, '-2', ['reverses' => 4, 'deleted' => true]),      // deleted reversal
        ];
        self::assertSame([1 => 'superseded', 2 => 'deleted', 3 => 'unpaid', 4 => null, 5 => 'not_netted', 6 => 'deleted'], self::reasons($rows, PayoutMode::REPLACE));

        $v = LedgerExplainer::explain($rows, PayoutMode::REPLACE)['rows'];
        self::assertSame(SupersededReason::REPLACE, $v[1]['superseded_reason']);
        self::assertSame(4, $v[1]['superseded_by']);
    }

    /**
     * A row that is both a reversal and fixed-superseded is superseded:
     * the docblock's order puts superseded (3) before not_netted (4), and
     * the stored reason is what explains the row. No path writes such a
     * row today (GoalEngine makes no reversal; pre_ledger is set only on
     * legacy non-reversals), which is exactly why nothing else would catch
     * the order changing.
     */
    public function testAReversalThatWasSupersededIsReportedSuperseded(): void
    {
        foreach ([SupersededReason::PRE_LEDGER, SupersededReason::REPLAY, SupersededReason::REEVALUATION] as $reason) {
            foreach ([PayoutMode::REPLACE, PayoutMode::ACCUMULATE] as $mode) {
                $rows = [
                    self::row(1, '10', ['reason' => SupersededReason::REPLAY, 'by' => 3]),     // the sale, itself superseded: nothing to net
                    self::row(2, '-10', ['reverses' => 1, 'reason' => $reason, 'by' => 4]),   // a reversal AND superseded
                    self::row(3, '10', ['source' => ConversionSource::LEGACY_BASELINE]),
                    self::row(4, '-10', ['reverses' => 3]),
                ];
                $v = LedgerExplainer::explain($rows, $mode)['rows'][2];
                self::assertFalse($v['counted'], $reason->value . ' ' . $mode->value);
                self::assertSame(NotCountedReason::SUPERSEDED, $v['reason'], $reason->value . ' ' . $mode->value . ': superseded, not not_netted');
                self::assertSame($reason, $v['superseded_reason']);
                self::assertSame(4, $v['superseded_by']);
            }
        }
        // A reversal whose target does not count, and that nothing
        // superseded, is still not_netted.
        $plain = LedgerExplainer::explain([
            self::row(1, '10', ['reason' => SupersededReason::REPLAY, 'by' => 3]),
            self::row(2, '-10', ['reverses' => 1]),
            self::row(3, '10', ['source' => ConversionSource::LEGACY_BASELINE]),
        ], PayoutMode::ACCUMULATE)['rows'][2];
        self::assertSame(NotCountedReason::NOT_NETTED, $plain['reason']);
    }

    public function testAFixedSupersessionIsReportedWithTheReasonStoredOnTheRow(): void
    {
        $rows = [self::row(7, '2', ['source' => ConversionSource::GOAL, 'reason' => SupersededReason::REEVALUATION, 'by' => null])];
        $v = LedgerExplainer::explain($rows, PayoutMode::ACCUMULATE)['rows'][7];
        self::assertSame(NotCountedReason::SUPERSEDED, $v['reason']);
        self::assertSame(SupersededReason::REEVALUATION, $v['superseded_reason']);
        self::assertNull($v['superseded_by'], 'retired with no replacement');
    }

    public function testEveryReasonHasASentence(): void
    {
        foreach (NotCountedReason::cases() as $reason) {
            self::assertStringEndsWith('.', $reason->explanation());
        }
        foreach (SupersededReason::cases() as $reason) {
            self::assertStringEndsWith('.', $reason->explanation());
        }
    }

    public function testSourceRefsRoundTripAndNeverCollide(): void
    {
        self::assertSame(['kind' => 'goal', 'id' => 12, 'version' => 3, 'raw' => 'goal:12:3'], SourceRef::parse(SourceRef::goal(12, 3)));
        self::assertSame(['kind' => 'upload_batch', 'id' => 9, 'raw' => 'batch:9'], SourceRef::parse(SourceRef::uploadBatch(9)));
        self::assertSame(['kind' => 'conversion', 'id' => 4, 'raw' => 'conv:4'], SourceRef::parse(SourceRef::conversion(4)));
        $key = SourceRef::apiKey('abc');
        self::assertMatchesRegularExpression('/^apikey:[0-9a-f]{16}$/', $key);
        self::assertSame('api_key', SourceRef::parse($key)['kind']);
        self::assertStringNotContainsString('abc', $key);
        self::assertNotSame(SourceRef::apiKey('abc'), SourceRef::apiKey('abd'));
        self::assertSame(['kind' => 'unknown', 'raw' => 'goal:1'], SourceRef::parse('goal:1'));
        self::assertSame(['kind' => 'unknown', 'raw' => 'goal:01:1'], SourceRef::parse('goal:01:1'));
        self::assertSame(['kind' => 'unknown', 'raw' => "batch:1\n"], SourceRef::parse("batch:1\n"));
        self::assertNull(SourceRef::parse(null));
        self::assertNull(SourceRef::parse(''));
        $this->expectException(\InvalidArgumentException::class);
        SourceRef::goal(0, 1);
    }
}
