<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Calculation;

use Prosper202\Attribution\ModelDefinition;
use Prosper202\Attribution\Snapshot;
use Prosper202\Attribution\Touchpoint;

/**
 * Calculates attribution metrics for a cohort of conversions.
 */
interface AttributionStrategyInterface
{
    /**
     * @param ModelDefinition $model    The attribution model being executed.
     * @param ConversionBatch $batch    Conversions to process for a specific timeframe.
     *
     * @return CalculationResult        Aggregated snapshot data plus touchpoint records.
     */
    public function calculate(ModelDefinition $model, ConversionBatch $batch): CalculationResult;
}
