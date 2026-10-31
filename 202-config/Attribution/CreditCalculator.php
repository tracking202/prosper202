<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

/**
 * Distributes one conversion over its journey under one model. Pure: no
 * database, no clock.
 *
 * Exactness is the contract, not a best effort. Credits are integer units
 * of 1e-8 and always sum to exactly 100,000,000 (one conversion); revenue
 * is integer units of 1e-5 and always sums to exactly the conversion's
 * counted amount. Each touch is given the floor of its share and the
 * rounding remainder goes to the last touch that has a share at all (the
 * converting click, unless the model gives it nothing, as first_touch does),
 * so no row is ever handed revenue without credit.
 *
 * The model's window: touches older than conv_time minus the model's
 * lookback are outside it; the converting click is always inside, because
 * the conversion happened through it whatever its age.
 */
final class CreditCalculator
{
    public const CREDIT_SCALE = 100_000_000;

    private function __construct()
    {
    }

    /**
     * @return list<Credit> touches with a non-zero credit, oldest first
     */
    public static function credits(Model $model, Journey $journey, int $convTime, int $amountUnits): array
    {
        $touches = self::window($journey, $convTime, $model->lookbackSeconds());
        $weights = self::weights($model->type, $model->config, $touches, $convTime);

        $creditUnits = self::apportion($weights, self::CREDIT_SCALE);
        $revenueUnits = self::split($amountUnits, $creditUnits);

        $credits = [];
        foreach ($touches as $i => $touch) {
            if ($creditUnits[$i] === 0) {
                continue;
            }
            $credits[] = new Credit($touch->position, $touch->clickId, $creditUnits[$i], $revenueUnits[$i]);
        }

        return $credits;
    }

    /**
     * The touches inside the model's lookback, converting click included.
     *
     * @return list<Touch>
     */
    public static function window(Journey $journey, int $convTime, int $lookbackSeconds): array
    {
        $from = $convTime - $lookbackSeconds;
        $last = count($journey->touches) - 1;
        $inside = [];
        foreach ($journey->touches as $i => $touch) {
            if ($i === $last || $touch->clickTime >= $from) {
                $inside[] = $touch;
            }
        }

        return $inside;
    }

    /**
     * Raw, unnormalised weights, one per touch, oldest first.
     *
     * @param array<string, float> $config
     * @param list<Touch> $touches
     * @return list<float>
     */
    public static function weights(ModelType $type, array $config, array $touches, int $convTime): array
    {
        $n = count($touches);
        if ($n === 0) {
            throw new \InvalidArgumentException('no touches to credit');
        }

        switch ($type) {
            case ModelType::LAST_TOUCH:
                $w = array_fill(0, $n, 0.0);
                $w[$n - 1] = 1.0;
                return $w;

            case ModelType::FIRST_TOUCH:
                $w = array_fill(0, $n, 0.0);
                $w[0] = 1.0;
                return $w;

            case ModelType::LINEAR:
                return array_fill(0, $n, 1.0);

            case ModelType::TIME_DECAY:
                $halfLife = ($config['half_life_hours'] ?? ModelConfig::DEFAULT_HALF_LIFE_HOURS) * 3600.0;
                // Relative to the most recent touch, so the newest weighs 1 and
                // a touch a year old underflows towards 0 rather than every
                // weight underflowing together and dividing 0 by 0.
                $ages = [];
                foreach ($touches as $t) {
                    $ages[] = max(0, $convTime - $t->clickTime);
                }
                $youngest = min($ages);
                return array_map(static fn (int $age): float => 2.0 ** (-($age - $youngest) / $halfLife), $ages);

            case ModelType::POSITION_BASED:
                $first = $config['first_weight'] ?? ModelConfig::DEFAULT_FIRST_WEIGHT;
                $last = $config['last_weight'] ?? ModelConfig::DEFAULT_LAST_WEIGHT;
                if ($n === 1) {
                    return [1.0];
                }
                if ($n === 2) {
                    // The middle share has nowhere to go; the two ends split
                    // the credit in the ratio of their weights.
                    return [$first, $last];
                }
                $middle = max(0.0, 1.0 - $first - $last) / ($n - 2);
                $w = array_fill(0, $n, $middle);
                $w[0] = $first;
                $w[$n - 1] = $last;
                return $w;
        }

        throw new \LogicException('unhandled model type ' . $type->value);
    }

    /**
     * Turn weights into integer units summing exactly to $total.
     *
     * Every weight gets the floor of its proportional share; the remainder
     * goes to the last touch with a positive weight. Weights that are all
     * zero (a position-based model with both ends at 0 over two touches)
     * split evenly: an undefined ratio must not become "nobody gets credit",
     * which would drop the conversion from every report.
     *
     * @param list<float> $weights
     * @return list<int>
     */
    public static function apportion(array $weights, int $total): array
    {
        $sum = array_sum($weights);
        if (!($sum > 0.0) || !is_finite($sum)) {
            $weights = array_fill(0, count($weights), 1.0);
            $sum = (float) count($weights);
        }

        $units = [];
        $given = 0;
        $receiver = 0;
        foreach ($weights as $i => $w) {
            // Rounded to 1e-6 of a unit before the floor, so binary noise
            // (0.2 / 2 is 0.09999999999999998) does not cost a share a
            // whole unit; the remainder settles what is left either way.
            $u = (int) floor(round($w / $sum * $total, 6));
            $units[] = $u;
            $given += $u;
            if ($w > 0.0) {
                $receiver = $i;
            }
        }
        $remainder = $total - $given;
        if ($remainder < 0) {
            // Float division can overshoot a share by an ulp; take the excess
            // from the largest share, which is the one it came from.
            $receiver = (int) array_search(max($units), $units, true);
        }
        $units[$receiver] += $remainder;

        return $units;
    }

    /**
     * Split an amount by credit units. Every touch but the last credited one
     * gets amount × credit_i / scale rounded half up (so 2/7 of $7 is $2.00000,
     * not the $1.99999 its eight-digit credit floors to), and the last
     * credited one takes what is left, so the parts add up to the amount
     * exactly. When rounding up the others would leave the last touch a
     * negative share (a touch with a sliver of credit on a tiny amount), the
     * split falls back to flooring, which cannot. Works on the absolute
     * value so a negative amount is split symmetrically.
     *
     * @param list<int> $creditUnits
     * @return list<int>
     */
    public static function split(int $amountUnits, array $creditUnits): array
    {
        $sign = $amountUnits < 0 ? -1 : 1;
        $abs = abs($amountUnits);

        $receiver = null;
        foreach ($creditUnits as $i => $c) {
            if ($c > 0) {
                $receiver = $i;
            }
        }
        if ($receiver === null) {
            throw new \InvalidArgumentException('no touch has credit to carry the revenue');
        }

        foreach ([true, false] as $roundHalfUp) {
            $parts = [];
            $given = 0;
            foreach ($creditUnits as $i => $c) {
                $p = ($c > 0 && $i !== $receiver) ? self::mulDivFloor($abs, $c, self::CREDIT_SCALE, $roundHalfUp) : 0;
                $parts[] = $p;
                $given += $p;
            }
            $parts[$receiver] = $abs - $given;
            if ($parts[$receiver] >= 0) {
                break;
            }
        }

        return array_map(static fn (int $p): int => $sign * $p, $parts);
    }

    /**
     * floor(a × b / d) — or, rounding half up, floor((a × b + d/2) / d) — for
     * non-negative a, b and d = 1e8 without overflowing
     * 64 bits: a is at most a decimal(11,5) amount in 1e-5 units (< 1e11)
     * and b at most 1e8, whose product exceeds PHP_INT_MAX. Split a at 1e4
     * so each partial product stays below 1e15.
     */
    public static function mulDivFloor(int $a, int $b, int $d, bool $roundHalfUp = false): int
    {
        if ($a < 0 || $b < 0 || $d !== self::CREDIT_SCALE) {
            throw new \InvalidArgumentException('mulDivFloor takes non-negative operands over the credit scale');
        }
        $hi = intdiv($a, 10_000);
        $lo = $a % 10_000;
        if ($b > 0 && $hi > intdiv(PHP_INT_MAX, $b)) {
            // PHP turns an overflowing int product into a float silently;
            // an amount this large is not a decimal(11,5) value at all.
            throw new \InvalidArgumentException('amount of ' . $a . ' units is too large to split exactly');
        }
        $hiProduct = $hi * $b;                 // < 1e7 × 1e8 = 1e15
        $q = intdiv($hiProduct, 10_000);       // (hi × b × 1e4) / 1e8
        $r = $hiProduct % 10_000;

        return $q + intdiv($r * 10_000 + $lo * $b + ($roundHalfUp ? intdiv($d, 2) : 0), $d);
    }
}
