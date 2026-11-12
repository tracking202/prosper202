<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\ConflictException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\PayloadKeys;
use Prosper202\Http\PostedId;
use Prosper202\PaymentNotification\InboundRequest;
use Prosper202\PaymentNotification\OutcomeKind;
use Prosper202\PaymentNotification\Shopify\RefundsToSettle;

/**
 * The account's Shopify refunds that are not recorded while their order's
 * sale is, and recording or closing one: the panel above Setup › Inbound
 * Log, through the same RefundsToSettle.
 *
 *   GET  /conversions/shopify-refunds
 *   POST /conversions/shopify-refunds/{id}/record   {amount, click_id?}
 *   POST /conversions/shopify-refunds/{id}/close
 */
final class ShopifyRefundsController
{
    private readonly RefundsToSettle $refunds;

    public function __construct(\mysqli $db, private readonly int $userId)
    {
        $this->refunds = new RefundsToSettle($db);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function list(array $params): array
    {
        if ($params !== []) {
            $errors = array_fill_keys(array_map('strval', array_keys($params)), 'this list takes no filter');
            throw new ValidationException('Unknown parameter', $errors);
        }
        $listed = $this->refunds->list($this->userId);
        $data = [];
        foreach ($listed['refunds'] as $refund) {
            $data[] = [
                'refund_id' => $refund['refund_id'],
                'order_id' => $refund['order_id'],
                'refunded_at' => $refund['occurred_at'] > 0 ? $refund['occurred_at'] : null,
                'state' => $refund['waiting'] ? 'waiting' : 'refused',
                'stale' => $refund['stale'],
                'reason' => $refund['waiting'] ? null : $refund['reason'],
                'amount' => (float) $refund['amount'],
                'currency' => $refund['currency'] !== '' ? $refund['currency'] : null,
                'other_currency' => $refund['other_currency'],
                'amount_currency' => $refund['amount_currency'],
                'click_ids' => $refund['clicks'],
            ];
        }

        return [
            'data' => $data,
            'more' => $listed['more'],
            'transaction_webhook_seen' => $listed['transaction_webhook_seen'],
            'account_currency' => $listed['account_currency'],
        ];
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return array<string, mixed>
     */
    public function takeBack(int $refundId, array $payload): array
    {
        PayloadKeys::refuseUnknown($payload, ['amount', 'click_id', 'currency'], 'a Shopify refund to record');
        $errors = [];
        $amount = $payload['amount'] ?? null;
        // A decimal string or a JSON number with at most the five decimals a
        // payout holds: a sixth is refused, never rounded away (#4).
        if (is_int($amount) || (is_float($amount) && round($amount, 5) == $amount)) {
            $amount = rtrim(rtrim(number_format((float) $amount, 5, '.', ''), '0'), '.');
        }
        if (!is_string($amount) || preg_match('/^\d{1,6}(?:\.\d{1,5})?$/D', $amount) !== 1 || (float) $amount <= 0) {
            $errors['amount'] = 'Required: what the refund returned, in the account\'s currency, such as 12.50:'
                . ' more than 0, at most 999999.99999';
        }
        $clickId = null;
        if (array_key_exists('click_id', $payload) && $payload['click_id'] !== null) {
            $raw = $payload['click_id'];
            $clickId = PostedId::read(is_int($raw) ? (string) $raw : $raw);
            if ($clickId === null) {
                $errors['click_id'] = 'A click id: the click of the order\'s sale this refund takes back from';
            }
        }
        $currency = $payload['currency'] ?? null;
        if ($currency !== null && (!is_string($currency) || preg_match('/^[A-Za-z]{3}$/D', $currency) !== 1)) {
            $errors['currency'] = 'The currency the amount is stated in, such as USD: the account\'s currency';
            $currency = null;
        }
        PayloadKeys::refuse($errors);
        $this->mustBeListed($refundId);

        $outcome = $this->refunds->takeBack(
            $this->userId,
            (string) $refundId,
            (string) $amount,
            $clickId,
            new InboundRequest('POST', '', [], '', '', 'Prosper202 API'),
            RefundsToSettle::THE_API,
            $currency
        );
        if (in_array($outcome->kind, [OutcomeKind::REVERSED, OutcomeKind::DUPLICATE], true)) {
            return ['data' => [
                'refund_id' => (string) $refundId,
                'outcome' => $outcome->kind->value,
                'conversion_id' => $outcome->conversionId,
                'message' => $outcome->message,
            ]];
        }
        if ($outcome->kind === OutcomeKind::CURRENCY_MISMATCH) {
            throw new ValidationException('Invalid currency', ['currency' => $outcome->message]);
        }
        if ($outcome->kind === OutcomeKind::MALFORMED) {
            // Only the click is left to be wrong: the order is on more than one.
            throw new ValidationException('Invalid click_id', ['click_id' => $outcome->message]);
        }
        if ($outcome->kind === OutcomeKind::FAILED || $outcome->kind === OutcomeKind::UPGRADE_PENDING) {
            throw new \RuntimeException('Shopify refund ' . $refundId . ' was not recorded: ' . $outcome->message);
        }

        throw new ConflictException($outcome->message, ['outcome' => $outcome->kind->value]);
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return array<string, mixed>
     */
    public function close(int $refundId, array $payload): array
    {
        PayloadKeys::refuseUnknown($payload, [], 'closing a Shopify refund');
        $this->mustBeListed($refundId);
        if (!$this->refunds->close($this->userId, (string) $refundId, RefundsToSettle::THE_API)) {
            $refusal = $this->refunds->closeRefusal($this->userId, (string) $refundId);
            if ($refusal !== null) {
                throw new ConflictException($refusal, ['outcome' => 'waiting_for_order']);
            }
            throw new NotFoundException('Shopify refund ' . $refundId . ' was recorded or closed already.');
        }

        return ['data' => ['refund_id' => (string) $refundId, 'closed' => true]];
    }

    private function mustBeListed(int $refundId): void
    {
        if ($this->refunds->listed($this->userId, (string) $refundId) === null) {
            throw new NotFoundException(
                'No Shopify refund ' . $refundId . ' is waiting to be recorded for this account:'
                . ' GET /conversions/shopify-refunds lists the ones that are.'
            );
        }
    }
}
