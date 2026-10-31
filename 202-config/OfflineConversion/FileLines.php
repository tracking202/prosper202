<?php

declare(strict_types=1);

namespace Prosper202\OfflineConversion;

use Prosper202\Conversion\Ledger\Amount;

/**
 * One file's lines, written a conversion at a time as OfflineExport::query()
 * reads them, and the count of every conversion left out and why.
 */
final class FileLines
{
    /** @var array<string, int> */
    private array $leftOut;
    private int $conversions = 0;
    private int $rows = 0;
    private ClickSeconds $seconds;

    /**
     * @param resource|null $handle null writes nothing and only counts
     */
    public function __construct(
        private ExportOptions $options,
        private string $zone,
        private string $currency,
        private $handle
    ) {
        $this->leftOut = array_fill_keys(LeftOut::values(), 0);
        $this->seconds = new ClickSeconds();
    }

    /** @param array<string, mixed> $row */
    public function add(array $row): void
    {
        $format = $this->options->format;
        $convId = StoredRow::int($row, 'conv_id');
        $time = StoredRow::int($row, 'conv_time');
        // Before the name filter: the platforms' API references key a
        // duplicate by click and second alone, so a URL for one name leaves
        // the other names' seconds to them.
        $key = $format->secondKey(OfflineExport::ids($row, $format));
        if ($key !== null) {
            $time = $this->seconds->take($key, $time, $convId);
        }
        $stored = StoredRow::nullableText($row, 'conversion_name');
        $name = $this->options->conversionName ?? ($stored !== '' ? $stored : null);
        if ($this->options->onlyConversionName !== null && $name !== $this->options->onlyConversionName) {
            return;
        }
        $this->conversions++;
        $reason = OfflineExport::reasonFor($row, $this->options, $name);
        $limit = $format->rowLimit();
        if ($reason === null && $limit !== null && $this->rows >= $limit) {
            $reason = LeftOut::OVER_ROW_LIMIT;
        }
        if ($reason !== null) {
            $this->leftOut[$reason->value]++;
            return;
        }
        if ($this->handle !== null && $name !== null) {
            OfflineExport::put($this->handle, $format->cells(
                OfflineExport::ids($row, $format),
                $name,
                $format->time($time, $this->zone),
                $format->value(Amount::toUnits(StoredRow::text($row, 'counted_amount'))),
                $this->currency,
                $convId,
                $this->options->adUserData,
                $this->options->adPersonalization
            ));
        }
        $this->rows++;
    }

    public function summary(): ExportSummary
    {
        return new ExportSummary(
            $this->options->format,
            $this->options->from(),
            $this->options->now,
            $this->zone,
            $this->currency,
            $this->conversions,
            $this->rows,
            $this->leftOut
        );
    }
}
