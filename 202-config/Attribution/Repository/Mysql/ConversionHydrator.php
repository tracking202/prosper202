<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository\Mysql;

use Prosper202\Attribution\Calculation\ConversionRecord;
use Prosper202\Attribution\Calculation\ConversionTouchpoint;

/**
 * Transforms raw conversion and journey rows into domain value objects.
 */
final class ConversionHydrator
{
    /**
     * @param list<array<string, mixed>> $conversionRows
     * @param array<int, list<array{click_id: int, click_time: int}>> $journeysByConversion
     * @return ConversionRecord[]
     */
    public function hydrate(array $conversionRows, array $journeysByConversion): array
    {
        $records = [];

        foreach ($conversionRows as $row) {
            $conversionId = (int) $row['conv_id'];
            $journeyRows = $journeysByConversion[$conversionId] ?? [];
            $journeyTouches = [];

            foreach ($journeyRows as $touchRow) {
                $journeyTouches[] = new ConversionTouchpoint(
                    (int) $touchRow['click_id'],
                    (int) $touchRow['click_time']
                );
            }

            $records[] = new ConversionRecord(
                conversionId: $conversionId,
                clickId: (int) $row['click_id'],
                userId: (int) $row['user_id'],
                campaignId: (int) $row['campaign_id'],
                ppcAccountId: (int) $row['ppc_account_id'],
                convTime: (int) $row['conv_time'],
                clickTime: (int) $row['click_time'],
                clickPayout: (float) $row['click_payout'],
                clickCost: (float) $row['click_cpc'],
                journey: $journeyTouches
            );
        }

        return $records;
    }
}
