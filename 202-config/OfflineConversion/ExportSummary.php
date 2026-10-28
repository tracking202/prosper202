<?php

declare(strict_types=1);

namespace Prosper202\OfflineConversion;

/**
 * What an offline conversion file holds and what it left out, by reason.
 * $conversions is every conversion in the window (reversals are not
 * conversions); it is $rows plus every count in $leftOut.
 */
final class ExportSummary
{
    /**
     * @param array<string, int> $leftOut every LeftOut value, 0 included
     */
    public function __construct(
        public readonly Format $format,
        public readonly int $from,
        public readonly int $to,
        public readonly string $zone,
        public readonly string $currency,
        public readonly int $conversions,
        public readonly int $rows,
        public readonly array $leftOut,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $reasons = [];
        foreach (LeftOut::cases() as $reason) {
            $reasons[$reason->value] = $this->leftOut[$reason->value] ?? 0;
        }

        return [
            'format' => $this->format->value,
            'time_from' => $this->from,
            'time_to' => $this->to,
            'timezone' => $this->zone,
            'currency' => $this->currency,
            'conversions' => $this->conversions,
            'rows' => $this->rows,
            'left_out' => $reasons,
            'left_out_total' => array_sum($reasons),
        ];
    }
}
