<?php

declare(strict_types=1);

namespace Prosper202\Conversion;

/**
 * A conversion carried LTV data — product line items, CRM fields — and no
 * customer resolved for it: no customer_id or customer_ref was sent, the
 * click is linked to no customer, and the account's customer c-param names
 * none. Line items hang off the customer's revenue event and CRM fields off
 * the customer record, so with no customer they had nowhere to go, and the
 * conversion was recorded without them, answered 201 (CLAUDE.md #4).
 *
 * Thrown inside record()'s transaction, before the conversion row is
 * written, for a caller that asked for it (`ltv_requires_customer`): a
 * caller that can be answered. A pixel cannot, and keeps the conversion
 * (the result's `ltvDropped` names what was not stored).
 */
final class LtvDataWithoutCustomer extends \RuntimeException
{
    /** @param list<string> $fields the body fields that had nowhere to go: items, customer_crm */
    public function __construct(public readonly array $fields)
    {
        parent::__construct(
            'No customer is linked to this conversion, so its ' . implode(' and ', $fields)
            . ' would be stored nowhere: nothing was recorded'
        );
    }

    /**
     * Each field's refusal, saying which identity to send.
     *
     * @return array<string, string>
     */
    public function fieldErrors(): array
    {
        $what = [
            'items' => 'line items are stored on the customer\'s revenue event',
            'customer_crm' => 'CRM fields are stored on the customer record',
        ];
        $errors = [];
        foreach ($this->fields as $field) {
            $errors[$field] = ($what[$field] ?? 'this belongs to a customer') . ', and no customer is linked to this'
                . ' conversion (the click has none, and none was named): send customer_ref (your id for the'
                . ' customer, with customer_ref_type) or customer_id (`p202 ltv customers`) with it.'
                . ' Nothing was recorded.';
        }

        return $errors;
    }
}
