<?php

declare(strict_types=1);

namespace Prosper202\Ltv;

use RuntimeException;

/**
 * Query object for LTV reads. The time window applies to customer
 * acquisition (first_seen_time) for customer-level views, and to event
 * occurrence for product breakdowns.
 *
 * Custom-field filters are capped at 3 per query to bound the join fan-out
 * over 202_customer_field_values (each filter adds one join).
 */
final class LtvQuery
{
    public const MAX_CUSTOM_FIELD_FILTERS = 3;

    /** @var list<array{fieldId: int, column: string, op: string, value: string|float|int}> */
    public readonly array $customFieldFilters;

    /**
     * @param list<array{fieldId: int, column: string, op: string, value: string|float|int}> $customFieldFilters
     *        column is one of value_text|value_number|value_date; op is one of = >= <=
     */
    public function __construct(
        public readonly int $userId,
        public readonly ?int $timeFrom = null,
        public readonly ?int $timeTo = null,
        array $customFieldFilters = [],
    ) {
        if (count($customFieldFilters) > self::MAX_CUSTOM_FIELD_FILTERS) {
            throw new RuntimeException(
                'At most ' . self::MAX_CUSTOM_FIELD_FILTERS . ' custom-field filters are supported per query'
            );
        }
        foreach ($customFieldFilters as $filter) {
            if (!in_array($filter['column'] ?? '', ['value_text', 'value_number', 'value_date'], true)) {
                throw new RuntimeException('Invalid custom-field filter column');
            }
            if (!in_array($filter['op'] ?? '', ['=', '>=', '<='], true)) {
                throw new RuntimeException('Invalid custom-field filter operator');
            }
            if (!isset($filter['fieldId']) || (int) $filter['fieldId'] <= 0) {
                throw new RuntimeException('Custom-field filter requires a fieldId');
            }
        }
        $this->customFieldFilters = array_values($customFieldFilters);
    }
}
