<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification\Shopify;

use Prosper202\Click\ClickId;
use Prosper202\Conversion\Ledger\Amount;
use Prosper202\Conversion\Ledger\ConversionSource;
use Prosper202\Database\Connection;
use Prosper202\PaymentNotification\AccountSecrets;
use Prosper202\PaymentNotification\ClickField;
use Prosper202\PaymentNotification\InboundRequest;
use Prosper202\PaymentNotification\Notification;
use Prosper202\PaymentNotification\NotificationKind;
use Prosper202\PaymentNotification\OutcomeKind;
use Prosper202\PaymentNotification\ProviderAdapter;
use Prosper202\PaymentNotification\Refusal;
use Prosper202\PaymentNotification\Response;
use Prosper202\PaymentNotification\Verification;

/**
 * Shopify's order webhooks: a JSON body signed in X-Shopify-Hmac-Sha256
 * (base64 HMAC-SHA256 of the raw body) with the store's webhook secret,
 * which each account stores (ShopifyWebhookSecret). The topic header says
 * what the body is.
 *
 * orders/paid, and orders/create for an order already paid, record the
 * order under its id at its total_price, so a store sending both counts it once. The click is the
 * t202subid line item property (or _t202subid, which the checkout hides)
 * that paid 1.9.56's gpb.php read. refunds/create reverses the order by what
 * its successful refund transactions returned, on the click of a refunded
 * line item or, when it names none, the click the order was recorded on.
 * A test order and every other topic are acknowledged and record nothing.
 */
final class ShopifyAdapter implements ProviderAdapter
{
    public const HMAC_HEADER = 'X-Shopify-Hmac-Sha256';
    public const TOPIC_HEADER = 'X-Shopify-Topic';

    private const PROPERTIES = ['t202subid', '_t202subid'];

    /** The financial_status of an order that has been paid, refunds included. */
    private const PAID = ['paid', 'partially_refunded', 'refunded'];

    /** decimal(11,5): at most six whole digits and five decimals, read exactly. */
    private const AMOUNT = '/^\d{1,6}(?:\.\d{1,5})?$/D';

    /** A Shopify REST id: a positive integer. */
    private const ID = '/^[1-9]\d{0,19}$/D';

    /** The request verify() verified, and the accounts it verified for, which parse() reads. */
    private ?InboundRequest $verified = null;

    /** @var list<int> */
    private array $accounts = [];

    /** @param \mysqli|null $db where a refund that names no click finds its order's (null: it is not looked up) */
    public function __construct(private readonly ?\mysqli $db = null)
    {
    }

    public function name(): string
    {
        return 'Shopify';
    }

    public function source(): ConversionSource
    {
        return ConversionSource::SHOPIFY;
    }

    public function signatureField(): string
    {
        return self::HMAC_HEADER . ' header';
    }

    public function clickField(): ClickField
    {
        return new ClickField('t202subid', 'the cart', 'properties[t202subid]=[[subid]]');
    }

    public function storedSecrets(Connection $conn): array
    {
        $secrets = [];
        foreach (AccountSecrets::stored($conn, ShopifyWebhookSecret::COLUMN) as $accountId => $column) {
            $secret = ShopifyWebhookSecret::stored($column);
            if ($secret !== null) {
                $secrets[$accountId] = [$secret];
            } elseif (ShopifyWebhookSecret::unreadable($column)) {
                error_log(sprintf(
                    'Shopify notification: account %d\'s stored webhook secret is not a secret; it verifies nothing',
                    $accountId
                ));
            }
        }

        return $secrets;
    }

    public function verify(InboundRequest $request, array $secrets): ?Verification
    {
        $signature = trim((string) $request->header(self::HMAC_HEADER));
        if ($signature === '') {
            throw Refusal::of(
                OutcomeKind::UNVERIFIED,
                'The webhook carries no ' . self::HMAC_HEADER . ' header, so it cannot be verified; nothing was'
                . ' recorded.'
            );
        }
        if ($secrets === []) {
            throw Refusal::of(
                OutcomeKind::UNVERIFIED,
                'No Prosper202 account stores a Shopify webhook secret, so this webhook cannot be verified and'
                . ' nothing was recorded. Store the secret your Shopify webhooks are signed with under Account ›'
                . ' API integrations › Shopify.'
            );
        }

        $verified = [];
        foreach ($secrets as $accountId => $keys) {
            foreach (array_values($keys) as $index => $key) {
                if (hash_equals(self::sign($request->body, $key), $signature)) {
                    $verified[$accountId] = $index;
                    break;
                }
            }
        }
        if ($verified === []) {
            return null;
        }
        $this->verified = $request;
        $this->accounts = array_keys($verified);

        return new Verification($verified);
    }

    /**
     * The status Shopify is answered with. A refusal a retry cannot change
     * (400, 404 for a click, 409, 415, 422) is answered 200, the outcome
     * staying in the body ("error": true) and the log: Shopify retries a
     * non-2xx answer and removes an API-made subscription after 8 failures in
     * a row, which would lose every later order to one bad cart. A refund
     * whose sale is not on file yet keeps its 404, so Shopify's retry lands
     * once the order has arrived; every other status is kept.
     */
    public static function deliveryStatus(OutcomeKind $kind): int
    {
        $status = Response::status($kind);
        if ($kind === OutcomeKind::NOTHING_TO_REVERSE) {
            return $status;
        }

        return in_array($status, [400, 404, 409, 415, 422], true) ? 200 : $status;
    }

    /** What Shopify sends in X-Shopify-Hmac-Sha256 for a body signed with a secret. */
    public static function sign(string $body, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', $body, $secret, true));
    }

    public function parse(InboundRequest $request): Notification
    {
        if ($request !== $this->verified) {
            throw new \LogicException('ShopifyAdapter::parse() reads the request verify() verified');
        }
        $topic = trim((string) $request->header(self::TOPIC_HEADER));
        if ($topic === '') {
            throw self::malformed('The webhook carries no ' . self::TOPIC_HEADER . ' header naming its topic.');
        }
        $shown = self::shown($topic);
        if (!in_array($topic, ['orders/create', 'orders/paid', 'refunds/create'], true)) {
            return new Notification(NotificationKind::IGNORED, $shown);
        }
        $body = json_decode($request->body, true, 64, JSON_BIGINT_AS_STRING);
        if (!is_array($body) || array_is_list($body)) {
            throw self::malformed('The ' . $shown . ' webhook\'s body is not a JSON object.');
        }

        return $topic === 'refunds/create' ? $this->refund($body, $shown) : self::order($body, $shown);
    }

    /** @param array<string, mixed> $order */
    private static function order(array $order, string $topic): Notification
    {
        $test = $order['test'] ?? false;
        if (!is_bool($test)) {
            throw self::malformed('test is not true or false; Shopify sends it as a JSON boolean.');
        }
        if ($test) {
            return new Notification(NotificationKind::TEST, $topic);
        }
        // An order is created before it is paid (bank transfer, cash on delivery, a held card):
        // orders/create counts only one already paid, and orders/paid the rest.
        $status = $order['financial_status'] ?? null;
        if ($topic === 'orders/create' && !in_array($status, self::PAID, true)) {
            return new Notification(
                NotificationKind::IGNORED,
                $topic . ' (not paid yet: ' . (is_string($status) ? self::shown($status) : 'no financial_status')
                . '; send Order payment to record it when it is)'
            );
        }
        $orderId = self::id($order, 'id', 'The order');
        $amount = self::amount($order['total_price'] ?? null, 'total_price');
        if ($amount === null) {
            throw self::malformed('The order carries no total_price.');
        }
        $lineItems = $order['line_items'] ?? [];
        if (!is_array($lineItems) || !array_is_list($lineItems)) {
            throw self::malformed('line_items is not a list.');
        }

        return new Notification(
            NotificationKind::SALE,
            $topic,
            $orderId,
            null,
            $amount,
            self::currency($order['currency'] ?? null),
            self::clickOf($lineItems),
            self::time($order['processed_at'] ?? null, 'processed_at') ?? self::time($order['created_at'] ?? null, 'created_at')
        );
    }

    /** @param array<string, mixed> $refund */
    private function refund(array $refund, string $topic): Notification
    {
        $refundId = self::id($refund, 'id', 'The refund');
        $orderId = self::id($refund, 'order_id', 'The refund');
        $transactions = $refund['transactions'] ?? [];
        if (!is_array($transactions) || !array_is_list($transactions)) {
            throw self::malformed('transactions is not a list.');
        }
        $units = 0;
        $currency = null;
        foreach ($transactions as $transaction) {
            if (!is_array($transaction) || ($transaction['kind'] ?? null) !== 'refund'
                || ($transaction['status'] ?? null) !== 'success') {
                continue;
            }
            $amount = self::amount($transaction['amount'] ?? null, 'a refund transaction\'s amount');
            if ($amount === null) {
                throw self::malformed('A successful refund transaction carries no amount.');
            }
            $units += Amount::toUnits($amount);
            $its = self::currency($transaction['currency'] ?? null);
            if ($currency !== null && $its !== null && $its !== $currency) {
                throw self::malformed('The refund\'s transactions are in more than one currency.');
            }
            $currency ??= $its;
        }
        if ($units === 0) {
            // A restock or a refund still pending returns no money yet.
            return new Notification(NotificationKind::IGNORED, $topic . ' (no money refunded)');
        }

        $refunded = [];
        $items = $refund['refund_line_items'] ?? [];
        if (is_array($items) && array_is_list($items)) {
            foreach ($items as $item) {
                if (is_array($item) && is_array($item['line_item'] ?? null)) {
                    $refunded[] = $item['line_item'];
                }
            }
        }

        return new Notification(
            NotificationKind::REFUND,
            $topic,
            $refundId,
            $orderId,
            self::plain(Amount::fromUnits($units)),
            $currency,
            self::clickOf($refunded) ?? $this->clickOfOrder($orderId),
            self::time($refund['processed_at'] ?? null, 'processed_at') ?? self::time($refund['created_at'] ?? null, 'created_at')
        );
    }

    /**
     * The click the first line item carrying a t202subid property names, or
     * null when none carries one. A value that is not a click id is refused.
     *
     * @param array<array-key, mixed> $lineItems
     */
    private static function clickOf(array $lineItems): ?int
    {
        foreach ($lineItems as $item) {
            $properties = is_array($item) ? ($item['properties'] ?? []) : [];
            if (!is_array($properties)) {
                continue;
            }
            foreach ($properties as $property) {
                $name = is_array($property) ? ($property['name'] ?? null) : null;
                if (!is_string($name) || !in_array(strtolower(trim($name)), self::PROPERTIES, true)) {
                    continue;
                }
                $value = $property['value'] ?? null;
                $value = is_string($value) ? trim($value) : $value;
                if ($value === null || $value === '') {
                    continue;
                }
                $clickId = ClickId::parse($value);
                if ($clickId === null) {
                    throw self::malformed(
                        'The ' . self::shown($name) . ' line item property is "'
                        . self::shown(is_scalar($value) ? (string) $value : gettype($value)) . '", which is not a'
                        . ' click id. The cart carries properties[t202subid]=[[subid]], which a Prosper202 tracker'
                        . ' fills with the click id; send buyers through the tracker.'
                    );
                }

                return $clickId;
            }
        }

        return null;
    }

    /**
     * The click the order was recorded on, among the accounts whose secret
     * verified the refund; null when it is on none. On more than one click,
     * the refund cannot say which it takes back, and is refused.
     */
    private function clickOfOrder(string $orderId): ?int
    {
        if ($this->db === null || $this->accounts === []) {
            return null;
        }
        $conn = new Connection($this->db);
        $in = implode(', ', array_fill(0, count($this->accounts), '?'));
        $stmt = $conn->prepareRead(
            'SELECT DISTINCT click_id FROM 202_conversion_logs WHERE transaction_id = ? AND reverses_conv_id IS NULL'
            . ' AND deleted = 0 AND user_id IN (' . $in . ') LIMIT 2'
        );
        $conn->bind($stmt, 's' . str_repeat('i', count($this->accounts)), [$orderId, ...$this->accounts]);
        $rows = $conn->fetchAll($stmt);

        if (count($rows) > 1) {
            throw Refusal::of(
                OutcomeKind::CONFLICT,
                'Order ' . $orderId . ' is recorded on more than one click and the refund names no line item, so it'
                . ' cannot say which sale it takes back; nothing was recorded.'
            );
        }

        return $rows === [] ? null : (int) $rows[0]['click_id'];
    }

    /** @param array<string, mixed> $body */
    private static function id(array $body, string $field, string $what): string
    {
        $raw = $body[$field] ?? null;
        $text = is_int($raw) ? (string) $raw : (is_string($raw) ? $raw : null);
        if ($text === null || preg_match(self::ID, $text) !== 1) {
            throw self::malformed($what . ' carries no ' . $field . ' that is a Shopify id.');
        }

        return $text;
    }

    /** An amount as an exact decimal string, or null when absent. */
    private static function amount(mixed $raw, string $field): ?string
    {
        if ($raw === null) {
            return null;
        }
        $text = is_int($raw) ? (string) $raw : (is_string($raw) ? trim($raw) : null);
        if ($text === null || preg_match(self::AMOUNT, $text) !== 1) {
            throw self::malformed(
                $field . ' is ' . (is_scalar($raw) ? '"' . self::shown((string) $raw) . '"' : gettype($raw))
                . '; Shopify sends an amount such as "199.00", at most 999999.99999.'
            );
        }

        return $text;
    }

    private static function currency(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw) || preg_match('/^[A-Za-z]{3}$/D', $raw) !== 1) {
            throw self::malformed('currency is not an ISO 4217 code such as USD.');
        }

        return strtoupper($raw);
    }

    /**
     * A Shopify timestamp (2026-11-01T10:15:00-05:00) as a Unix time, or null
     * when absent. One that cannot be read is refused, never replaced by the
     * time it arrived (CLAUDE.md #4).
     */
    private static function time(mixed $raw, string $field): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_string($raw)
            && preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.\d+)?(Z|[+-]\d{2}:\d{2})$/D', $raw, $m) === 1) {
            $text = $m[1] . ($m[2] === 'Z' ? '+00:00' : $m[2]);
            $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $text);
            if ($time !== false && $time->format('Y-m-d\TH:i:sP') === $text) {
                $at = $time->getTimestamp();
                if ($at > 0 && $at <= Notification::LAST_TIME) {
                    return $at;
                }
            }
        }

        throw self::malformed(
            $field . ' is ' . (is_string($raw) ? '"' . self::shown($raw) . '"' : gettype($raw)) . '; Shopify sends a'
            . ' time with its offset, such as 2026-11-01T10:15:00-05:00.'
        );
    }

    /** A stored decimal without its trailing zeros: 12.50000 reads 12.5. */
    private static function plain(string $amount): string
    {
        return str_contains($amount, '.') ? rtrim(rtrim($amount, '0'), '.') : $amount;
    }

    /** A value of the request as an answer or the log may show it. */
    private static function shown(string $value): string
    {
        return substr((string) preg_replace('/[^\x20-\x7E]/', '?', $value), 0, 64);
    }

    private static function malformed(string $message): Refusal
    {
        return Refusal::of(OutcomeKind::MALFORMED, $message);
    }
}
