<?php

declare(strict_types=1);

namespace Tests\Conversion\Ledger;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\Ledger\Amount;
use Prosper202\Conversion\Ledger\ConversionSource;
use Prosper202\Conversion\Ledger\DedupeKey;
use Prosper202\Conversion\Ledger\PayoutMode;

final class AmountAndKeysTest extends TestCase
{
    /** @dataProvider amounts */
    public function testAmountsRoundTripExactly(int|float|string $in, string $out): void
    {
        self::assertSame($out, Amount::fromUnits(Amount::toUnits($in)));
    }

    /** @return iterable<string, array{int|float|string, string}> */
    public static function amounts(): iterable
    {
        yield 'decimal column' => ['2.75000', '2.75000'];
        yield 'int' => [3, '3.00000'];
        yield 'float that is not exact in binary' => [0.1, '0.10000'];
        yield 'negative' => ['-3', '-3.00000'];
        yield 'negative fraction' => ['-0.5', '-0.50000'];
        yield 'rounds half away from zero at the sixth place' => ['0.123455', '0.12346'];
        yield 'negative rounds away from zero' => ['-0.123455', '-0.12346'];
        yield 'rounding carries into the whole part' => ['0.999995', '1.00000'];
        yield 'leading zeros' => ['007.5', '7.50000'];
        yield 'zero' => ['0', '0.00000'];
    }

    /** @dataProvider badAmounts */
    public function testAnUnreadableAmountIsRefusedNeverZero(mixed $in): void
    {
        $this->expectException(InvalidArgumentException::class);
        Amount::toUnits($in);
    }

    /** @return iterable<string, array{mixed}> */
    public static function badAmounts(): iterable
    {
        yield 'empty' => [''];
        yield 'exponent' => ['1e3'];
        yield 'thousands separator' => ['1,000'];
        yield 'currency sign' => ['$5'];
        yield 'plus sign' => ['+5'];
        yield 'infinite' => [INF];
        yield 'not a number' => [NAN];
        yield 'too large' => ['99999999999999'];
    }

    public function testEveryKeyIsInItsOwnNamespace(): void
    {
        $keys = [
            DedupeKey::transaction('A-7731'),
            DedupeKey::goal(12, 2, 3, 'e1'),
            DedupeKey::install(),
            DedupeKey::event('install', 5, 'e1'),
            DedupeKey::reversal(9, '1'),
            DedupeKey::legacy(),
            DedupeKey::upload(4, 17),
            DedupeKey::plainConversion(),
            DedupeKey::row(9),
        ];
        self::assertSame(
            ['tx:A-7731', 'goal:12:2:3:e1', 'install', 'evt:install:5:e1', 'rev:9:1', 'legacy', 'up:4:17', 'conversion', 'row:9'],
            $keys
        );
    }

    /**
     * Keys whose free text could, with a bare delimiter, collide with a
     * different key (CLAUDE.md #17): a network id that looks like another
     * namespace's key, an id containing the separator.
     */
    public function testNoTwoDifferentInputsShareAKey(): void
    {
        $pairs = [
            [DedupeKey::transaction('install'), DedupeKey::install()],
            [DedupeKey::transaction('row:9'), DedupeKey::row(9)],
            [DedupeKey::transaction('goal:1:1:1:e'), DedupeKey::goal(1, 1, 1, 'e')],
            [DedupeKey::goal(1, 1, 12, 'e'), DedupeKey::goal(1, 11, 2, 'e')],
            [DedupeKey::goal(1, 1, 1, '2:e'), DedupeKey::goal(1, 1, 12, 'e')],
            [DedupeKey::reversal(1, '2:3'), DedupeKey::reversal(12, '3')],
            [DedupeKey::event('click', 1, '2:x'), DedupeKey::event('click', 12, 'x')],
        ];
        foreach ($pairs as [$a, $b]) {
            self::assertNotSame($a, $b);
        }
    }

    public function testEveryKeyFitsTheColumn(): void
    {
        $long = str_repeat('x', 255);
        foreach ([
            DedupeKey::transaction($long),
            DedupeKey::goal(PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, $long),
            DedupeKey::event('install', PHP_INT_MAX, $long),
            DedupeKey::reversal(PHP_INT_MAX, $long),
            DedupeKey::upload(PHP_INT_MAX, PHP_INT_MAX),
        ] as $key) {
            self::assertLessThanOrEqual(DedupeKey::MAX_LENGTH, strlen($key));
        }
    }

    public function testKeyInputsAreChecked(): void
    {
        foreach ([
            static fn () => DedupeKey::transaction(''),
            static fn () => DedupeKey::transaction(str_repeat('x', 256)),
            static fn () => DedupeKey::goal(0, 1, 1, 'e'),
            static fn () => DedupeKey::event('visitor', 1, 'e'),
            static fn () => DedupeKey::row(0),
        ] as $i => $build) {
            try {
                $build();
                self::fail('builder ' . $i . ' accepted a bad input');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testThePlaceholderIsUniqueAndNeverARealKey(): void
    {
        $a = DedupeKey::rowPlaceholder();
        self::assertNotSame($a, DedupeKey::rowPlaceholder());
        self::assertStringStartsWith('row-pending:', $a);
    }

    public function testAPayoutModeThatIsNeitherIsRefusedByName(): void
    {
        self::assertSame(PayoutMode::ACCUMULATE, PayoutMode::fromStored('accumulate'));
        $this->expectExceptionMessage('"sum" is neither replace nor accumulate');
        PayoutMode::fromStored('sum');
    }

    /**
     * The upgrade's backfill maps a pre-ledger row's pixel_type and user
     * agent to a source in SQL; ConversionSource::fromLegacyRow() maps them
     * in PHP. The two must agree on every case.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheUpgradeBackfillMapsSourcesLikeTheEnum(): void
    {
        require_once dirname(__DIR__, 3) . '/202-config/functions-upgrade.php';
        $sql = _upgrade_conversion_ledger_backfill_sql();
        foreach ([[1, ''], [2, ''], [3, ''], [0, 'subid-upload'], [0, ''], [0, 'Mozilla']] as [$pixel, $ua]) {
            $expected = ConversionSource::fromLegacyRow($pixel, $ua)->value;
            $sqlSays = match (true) {
                $pixel > 0 && preg_match("/WHEN `pixel_type` = $pixel THEN '([a-z_]+)'/", $sql, $m) === 1 => $m[1],
                $pixel === 0 && $ua === 'subid-upload' && preg_match("/WHEN `user_agent` = 'subid-upload' THEN '([a-z_]+)'/", $sql, $m) === 1 => $m[1],
                preg_match("/ELSE '([a-z_]+)' END/", $sql, $m) === 1 => $m[1],
                default => null,
            };
            self::assertSame($expected, $sqlSays, "pixel_type $pixel, user agent '$ua'");
        }
        self::assertStringContainsString("WHERE `dedupe_key` IS NULL", $sql, 'the backfill must only touch rows that have no key yet');
        self::assertStringContainsString("`superseded_reason` = 'pre_ledger'", $sql);
    }
}
