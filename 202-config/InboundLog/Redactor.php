<?php

declare(strict_types=1);

namespace Prosper202\InboundLog;

/**
 * What the log keeps of a request's fields: every name (unless it holds an
 * email or IP address), and a value only
 * when its endpoint is known to send that field (KEEP) and the value names
 * no person (email, IP address). Every other value is redacted, so a field a
 * provider adds later is hidden until it is listed here.
 */
final class Redactor
{
    public const REDACTED = '[redacted]';
    public const MAX_FIELDS = 40;
    public const MAX_VALUE = 120;
    public const MAX_BYTES = 4000;
    /** A longer value is not scanned, and not kept. */
    private const MAX_SCANNED = 2048;

    /** The classic postback and pixel parameters Prosper202 reads (pb, gpb, px, gpx, upx). */
    private const CLASSIC = [
        'acip', 'subid', 'sid', 'cid', 'amount', 'txid', 'transaction_id', 'transactionid', 'order_id', 'orderid',
        'oid', 't202txid', 'status', 'reversal_id', 'event', 'event_id', 'p13n_have', 'sku', 'product_id',
        'external_product_id', 'product_name', 'qty', 'quantity', 'unit_price', 'currency',
    ];

    /** An event's properties (event_props) longer than this are not kept. */
    public const MAX_EVENT_PROPS = 1000;

    /**
     * The parameters a classic postback or pixel row keeps the values of:
     * what its recorder reads.
     *
     * @return list<string>
     */
    public static function classicFields(): array
    {
        return [...self::CLASSIC, 'event_props'];
    }

    /**
     * An event's properties as sent, kept only whole: a JSON object no key
     * of which names a secret or a person and no value of which holds an
     * email or IP address. Anything else is redacted entire, never in part,
     * so what is kept is what the event said.
     */
    private static function eventProps(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > self::MAX_EVENT_PROPS) {
            return self::REDACTED;
        }
        $decoded = json_decode($value, true, 4);
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded)) || !self::plainProps($decoded)) {
            return self::REDACTED;
        }

        return $value;
    }

    /** @param array<array-key, mixed> $props */
    private static function plainProps(array $props): bool
    {
        foreach ($props as $key => $value) {
            $key = (string) $key;
            if (preg_match(self::SECRET_NAME, $key) !== 0 || preg_match(self::VISITOR_NAME, $key) !== 0 || self::namesAPerson($key)) {
                return false;
            }
            if (is_array($value) ? !self::plainProps($value) : (is_string($value) && self::namesAPerson($value))) {
                return false;
            }
        }

        return true;
    }

    /** Per endpoint, the fields whose values are kept. */
    private const KEEP = [
        'pb' => self::CLASSIC,
        'gpb' => self::CLASSIC,
        'px' => self::CLASSIC,
        'gpx' => self::CLASSIC,
        'upx' => self::CLASSIC,
        // ClickBank's INS is an encrypted body (notification, iv): nothing in it is kept.
        'clickbank' => [],
        'jvzoo' => [
            'ctransaction', 'ctransreceipt', 'ctransamount', 'ctranstime', 'ctranspaymentmethod', 'ctransvendor',
            'cproditem', 'cprodtitle', 'cprodtype', 'cupsellreceipt', 'caffitid', 'cvendthru',
        ],
        'paykickstart' => [
            'event', 'mode', 'payment_processor', 'is_rebill', 'amount', 'quantity', 'currency', 'transaction_id',
            'invoice_id', 'transaction_time', 'transaction_type', 'product_id', 'product_name', 'campaign_id',
            'campaign_name', 'custom_subid', 'affiliate_commission_amount', 'coupon_code', 'coupon_rate',
            'tax_amount', 'shipping_amount',
        ],
    ];

    /** Inside a kept field's nested values, names that are never kept. */
    private const SECRET_NAME = '/sig|secret|key|token|hash|hmac|verif|pass|^pw|auth|cred|nonce|cipher|licen|^iv$|^notification$/i';
    private const VISITOR_NAME = '/mail|name|phone|addr|street|city|zip|postal|card|birth|ssn|agent|refer|^cust$|customer|buyer|(^|[^a-z])ip($|[^a-z])/i';
    private const EMAIL_VALUE = '/[^@\s]@[^@\s]+\.[a-z]{2,}/i';
    /** A trailing full stop ends a sentence, not the address: "from 203.0.113.9." is one. */
    private const IPV4_VALUE = '/(^|[^0-9.])(\d{1,3}\.){3}\d{1,3}($|[^0-9.]|\.(?![0-9]))/';

    /**
     * Per endpoint, the free-form query-string fields (a vendor's buy-link
     * pass-through) and the pairs inside them whose values are kept. Every
     * other pair keeps its name, and its value is redacted.
     */
    private const PASS_THROUGH = [
        'jvzoo' => ['cvendthru' => ['subid']],
    ];

    private const PLAIN_TOKEN = '/^[A-Za-z0-9_.:-]{1,64}$/';

    private function __construct()
    {
    }

    /**
     * @param array<array-key, mixed> $fields
     * @param string $endpoint whose KEEP list applies; an unknown one keeps no value
     * @return array<string, mixed>
     */
    public static function fields(array $fields, string $endpoint): array
    {
        $keep = self::KEEP[$endpoint] ?? [];
        $out = [];
        $kept = 0;
        foreach ($fields as $name => $value) {
            if ($kept === self::MAX_FIELDS) {
                $out['_more_fields'] = count($fields) - $kept;
                break;
            }
            $name = (string) $name;
            $shown = self::namesAPerson($name) ? '_redacted_name_' . $kept : self::text($name, 64);
            $pairs = self::PASS_THROUGH[$endpoint][$name] ?? null;
            $out[$shown] = match (true) {
                // A blank names nothing and is kept as one, so "sent empty" is told from "sent and hidden".
                $value === '' => '',
                $pairs !== null => self::passThrough($value, $pairs),
                $name === 'event_props' && in_array('event', $keep, true) => self::eventProps($value),
                in_array($name, $keep, true) => self::value($value, 0),
                default => self::REDACTED,
            };
            $kept++;
        }

        return $out;
    }

    /** Stored when the fields could not be encoded, so a lost body never reads as none. */
    public const UNREADABLE = '{"_unreadable":true}';

    /** The fields as the log stores them: JSON, at most MAX_BYTES; null for none. */
    public static function encode(array $redacted): ?string
    {
        if ($redacted === []) {
            return null;
        }
        while (true) {
            $json = json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json === false) {
                return self::UNREADABLE;
            }
            if (strlen($json) <= self::MAX_BYTES || count($redacted) <= 1) {
                return strlen($json) <= self::MAX_BYTES ? $json : self::UNREADABLE;
            }
            $more = (int) ($redacted['_more_fields'] ?? 0);
            unset($redacted['_more_fields']);
            array_pop($redacted);
            $redacted['_more_fields'] = $more + 1;
        }
    }

    /** One value kept outside fields (the transaction id): redacted when it holds an email or IP. */
    public static function scalar(string $value): string
    {
        return self::namesAPerson($value) ? self::REDACTED : $value;
    }

    /**
     * A query string with only the listed pairs' values kept: "subid=12&phone=[redacted]".
     *
     * @param list<string> $pairs
     */
    private static function passThrough(mixed $value, array $pairs): string
    {
        if (!is_string($value) || strlen($value) > self::MAX_SCANNED) {
            return self::REDACTED;
        }
        $out = [];
        foreach (explode('&', ltrim($value, '?')) as $i => $pair) {
            if ($pair === '') {
                continue;
            }
            $parts = explode('=', $pair, 2);
            $name = urldecode($parts[0]);
            // Only a plain token is shown, as a name or a kept value: free text could name the buyer.
            $shown = preg_match(self::PLAIN_TOKEN, $name) === 1 ? $name : '_redacted_name_' . $i;
            $decoded = count($parts) === 2 ? urldecode($parts[1]) : '';
            $kept = $shown === $name && count($parts) === 2 && in_array($name, $pairs, true)
                && preg_match(self::PLAIN_TOKEN, $decoded) === 1 && !self::namesAPerson($decoded);
            $out[] = $shown . '=' . ($kept ? $decoded : self::REDACTED);
        }

        return self::text(implode('&', $out), self::MAX_VALUE * 2);
    }

    private static function value(mixed $value, int $depth): mixed
    {
        if (is_array($value)) {
            if ($depth >= 2) {
                return self::REDACTED;
            }
            $out = [];
            foreach (array_slice($value, 0, self::MAX_FIELDS, true) as $k => $v) {
                $k = (string) $k;
                $hidden = preg_match(self::SECRET_NAME, $k) !== 0 || preg_match(self::VISITOR_NAME, $k) !== 0;
                $shown = self::namesAPerson($k) ? '_redacted_name_' . count($out) : self::text($k, 64);
                $out[$shown] = $hidden || $shown !== self::text($k, 64) ? self::REDACTED : self::value($v, $depth + 1);
            }

            return $out;
        }
        if (!is_scalar($value) && $value !== null) {
            return self::REDACTED;
        }
        $text = (string) $value;

        return self::namesAPerson($text) ? self::REDACTED : self::text($text, self::MAX_VALUE);
    }

    /** An email or IP address in the value, as sent or URL-decoded; a value that cannot be scanned counts as one. */
    private static function namesAPerson(string $text): bool
    {
        if (strlen($text) > self::MAX_SCANNED) {
            return true;
        }
        $forms = [$text];
        for ($i = 0; $i < 2; $i++) {
            $decoded = urldecode($forms[count($forms) - 1]);
            if ($decoded === $forms[count($forms) - 1]) {
                break;
            }
            $forms[] = $decoded;
        }
        foreach ($forms as $form) {
            // preg_match answers false on a PCRE error: redact, never keep.
            if (preg_match(self::EMAIL_VALUE, $form) !== 0 || preg_match(self::IPV4_VALUE, $form) !== 0) {
                return true;
            }
            if (str_contains($form, ':')) {
                $tokens = preg_split('/[^0-9a-f:.\[\]]+/i', $form);
                if ($tokens === false) {
                    return true;
                }
                foreach ($tokens as $token) {
                    $token = trim($token, '[].');
                    if (substr_count($token, ':') >= 2 && filter_var($token, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function text(string $text, int $max): string
    {
        return mb_strlen($text, 'UTF-8') > $max ? mb_substr($text, 0, $max, 'UTF-8') . '…' : $text;
    }
}
