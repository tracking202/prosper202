<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification\ClickBank;

use Prosper202\Click\ClickId;
use Prosper202\Conversion\Ledger\ConversionSource;
use Prosper202\Database\Connection;
use Prosper202\PaymentNotification\ConfirmsTestNotifications;
use Prosper202\PaymentNotification\FingerprintsNotifications;
use Prosper202\PaymentNotification\InboundRequest;
use Prosper202\PaymentNotification\Notification;
use Prosper202\PaymentNotification\NotificationKind;
use Prosper202\PaymentNotification\OutcomeKind;
use Prosper202\PaymentNotification\ProviderAdapter;
use Prosper202\PaymentNotification\Refusal;
use Prosper202\PaymentNotification\Verification;

/**
 * ClickBank's Instant Notification Service, version 6.0 and later (8.0 is
 * current): a JSON body {"notification", "iv"} whose notification is the
 * order, encrypted with the secret key of the ClickBank account it is for
 * (ClickBankCipher). Each Prosper202 account stores its own key
 * (202_users_pref.cb_key); the account is the one whose key decrypts the
 * notification, and a key two accounts store is refused rather than credited
 * to either. The click is the order's first tracking code, which a HopLink's
 * tid=[[subid]] fills with the click id (or, in 8.0, an affiliate's extclid);
 * the receipt is the transaction id.
 *
 * ClickBank's amounts are in USD (totalAccountAmount: "Total you received for
 * the transaction in USD"), so they are stated as USD and an account that
 * counts in another currency is refused (Processor).
 *
 * What each transactionType does is TYPES. A refund, chargeback or returned
 * eCheck reverses the conversion under its receipt by what its
 * totalAccountAmount says the account lost, since ClickBank refunds part of
 * a payment too (a subscription's product change refunds "a portion of the
 * old product's last recurring payment"), and the whole of it when it states
 * none or 0.
 *
 * The legacy versions (4.0 and earlier: a form signed with an 8-digit
 * `cverify`) are answered 415, saying which version to set.
 */
final class ClickBankAdapter implements ProviderAdapter, ConfirmsTestNotifications, FingerprintsNotifications
{
    /**
     * Every transactionType ClickBank's INS article lists, and what each asks
     * of the ledger. One it does not list is refused by name rather than
     * acknowledged, since a type that moves money would be lost unseen.
     */
    private const TYPES = [
        // A purchase, or a subscription's first payment.
        'SALE' => NotificationKind::SALE,
        // A subscription's rebill, under its own receipt (the first one's, with -0001 after it).
        'BILL' => NotificationKind::REBILL,
        // A refund, of the payment its receipt names.
        'RFND' => NotificationKind::REFUND,
        // A chargeback, and an eCheck the buyer's bank returned unpaid.
        'CGBK' => NotificationKind::CHARGEBACK,
        'INSF' => NotificationKind::CHARGEBACK,
        // A subscription cancelled, and a cancellation undone: no money moves.
        'CANCEL-REBILL' => NotificationKind::IGNORED,
        'UNCANCEL-REBILL' => NotificationKind::IGNORED,
        // A subscription's product changed (the article describes it by its old and new products).
        'SUBSCRIPTION-CHG' => NotificationKind::IGNORED,
        // A cart left, a rebill's card declined, the buyer's details changed or the receipt resent.
        'ABANDONED_ORDER' => NotificationKind::IGNORED,
        'CUSTOMER_AUTH_FAILURE' => NotificationKind::IGNORED,
        'CUSTOMER_EMAIL_UPDATE' => NotificationKind::IGNORED,
        'CUSTOMER_UPDATE_CC_NOTIFICATION' => NotificationKind::IGNORED,
        'PURCHASE_DETAILS_EMAIL_RESPONSE' => NotificationKind::IGNORED,
        // The Test URL button's, and the test versions of the others.
        'TEST' => NotificationKind::TEST,
        'TEST_SALE' => NotificationKind::TEST,
        'TEST_BILL' => NotificationKind::TEST,
        'TEST_RFND' => NotificationKind::TEST,
        'CANCEL-TEST-REBILL' => NotificationKind::TEST,
        'UNCANCEL-TEST-REBILL' => NotificationKind::TEST,
    ];

    /** decimal(11,5): at most six whole digits and five decimals, read exactly. */
    private const AMOUNT = '/^(-?)(\d{1,6}(?:\.\d{1,5})?)$/D';

    /** The conversion row's transaction_id is varchar(255); ClickBank's receipts are 8 to 21 characters. */
    private const RECEIPT = '/^[\x21-\x7E]{1,255}$/D';

    private const VERSION_HINT = 'set the Instant Notification version to 8.0 in ClickBank (Vendor Settings > My Site'
        . ' > Advanced Tools)';

    /** The request verify() decrypted, and its order, which parse() reads. */
    private ?InboundRequest $decrypted = null;

    /** @var array<string, mixed> */
    private array $order = [];

    public function name(): string
    {
        return 'ClickBank';
    }

    public function source(): ConversionSource
    {
        return ConversionSource::CLICKBANK;
    }

    /** ClickBank encrypts the order rather than signing it: what does not verify is the encryption. */
    public function signatureField(): string
    {
        return 'encryption';
    }

    public function storedSecrets(Connection $conn): array
    {
        $stmt = $conn->prepareRead(
            "SELECT user_id, cb_key FROM 202_users_pref WHERE cb_key IS NOT NULL AND cb_key <> '' ORDER BY user_id"
        );
        $secrets = [];
        foreach ($conn->fetchAll($stmt) as $row) {
            // A ClickBank key is capital letters and digits: space around one is the paste's.
            $key = is_string($row['cb_key']) ? trim($row['cb_key']) : '';
            if ($key !== '') {
                $secrets[(int) $row['user_id']] = [$key];
            }
        }

        return $secrets;
    }

    public function verify(InboundRequest $request, array $secrets): ?Verification
    {
        if (!function_exists('openssl_decrypt')) {
            throw Refusal::of(
                OutcomeKind::FAILED,
                'This server\'s PHP has no OpenSSL extension, which decrypts ClickBank\'s notifications; nothing'
                . ' was recorded.'
            );
        }
        [$ciphertext, $iv] = self::envelope($request);

        $holders = [];
        foreach ($secrets as $accountId => $keys) {
            foreach (array_values($keys) as $index => $key) {
                $holders[$key][$accountId] = $index;
            }
        }
        $verified = [];
        $order = null;
        foreach ($holders as $key => $accounts) {
            $decrypted = ClickBankCipher::decrypt($ciphertext, $iv, (string) $key);
            if ($decrypted !== null) {
                $verified += $accounts;
                $order ??= $decrypted;
            }
        }
        if ($order === null) {
            return null;
        }
        if (count($verified) > 1) {
            ksort($verified);
            error_log(sprintf(
                'ClickBank notification: accounts %s store the secret key it decrypts with, so it is refused;'
                . ' clear the key from every account but the one whose ClickBank account it is',
                implode(', ', array_keys($verified))
            ));

            throw Refusal::of(
                OutcomeKind::AMBIGUOUS_ACCOUNT,
                'The notification decrypts with a ClickBank secret key that more than one Prosper202 account'
                . ' stores, so it cannot say whose sale it is; nothing was recorded. Keep the key in the one account'
                . ' it belongs to (Account > API integrations > ClickBank).'
            );
        }
        $this->decrypted = $request;
        $this->order = $order;

        return new Verification($verified);
    }

    public function parse(InboundRequest $request): Notification
    {
        if ($request !== $this->decrypted) {
            throw new \LogicException('ClickBankAdapter::parse() reads the order verify() decrypted from this request');
        }
        $order = $this->order;
        $type = (string) $order['transactionType'];
        $shown = self::shown($type);
        $kind = self::TYPES[$type] ?? null;
        if ($kind === null) {
            throw self::malformed(
                'transactionType is "' . $shown . '", which ClickBank\'s Instant Notification Service article does'
                . ' not list; nothing was recorded.'
            );
        }
        if (!$kind->records() && !$kind->reverses()) {
            return new Notification($kind, $shown);
        }

        $receipt = self::receipt($order, $type);
        if ($kind->records()) {
            $amount = self::amount($order, false);
            if ($amount === null) {
                throw self::malformed('The ' . $shown . ' carries no totalAccountAmount.');
            }

            return new Notification(
                $kind,
                $shown,
                $receipt,
                null,
                $amount,
                'USD',
                self::clickId($order, $shown, $receipt),
                self::occurredAt($order)
            );
        }

        // What the account lost by it is taken back; none, or 0, takes back the whole payment.
        $amount = self::amount($order, true);

        return new Notification(
            $kind,
            $shown,
            $receipt,
            $receipt,
            $amount === null || preg_match('/^0+(?:\.0+)?$/D', $amount) === 1 ? null : $amount,
            'USD',
            self::clickId($order, $shown, $receipt),
            self::occurredAt($order)
        );
    }

    public function confirmTest(Connection $conn, int $accountId): void
    {
        $stmt = $conn->prepareWrite('UPDATE 202_users_pref SET cb_verified = 1 WHERE user_id = ?');
        $conn->bind($stmt, 'i', [$accountId]);
        $conn->executeUpdate($stmt);
    }

    /**
     * The decrypted order, keys in order, without attemptCount: ClickBank
     * raises that on each retry of the same notification, and the ciphertext
     * and IV of a retry need not be the ones sent before.
     */
    public function fingerprint(InboundRequest $request): string
    {
        if ($request !== $this->decrypted) {
            throw new \LogicException(
                'ClickBankAdapter::fingerprint() reads the order verify() decrypted from this request'
            );
        }
        $order = $this->order;
        unset($order['attemptCount']);

        return hash('sha256', serialize(self::byName($order)));
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function byName(array $value): array
    {
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::byName($item);
            }
        }

        return $value;
    }

    /** The account's own Slack webhook, or null when it has none. */
    public static function slackWebhook(Connection $conn, int $accountId): ?string
    {
        $stmt = $conn->prepareRead(
            'SELECT user_slack_incoming_webhook FROM 202_users_pref WHERE user_id = ? LIMIT 1'
        );
        $conn->bind($stmt, 'i', [$accountId]);
        $url = $conn->fetchOne($stmt)['user_slack_incoming_webhook'] ?? null;

        return is_string($url) && trim($url) !== '' ? trim($url) : null;
    }

    /**
     * The ciphertext and IV the body carries. Each check reads only the
     * envelope, never a key, so what it says is the same whoever sent it.
     *
     * @return array{0: string, 1: string}
     */
    private static function envelope(InboundRequest $request): array
    {
        if (array_key_exists('cverify', $request->fields)) {
            throw Refusal::of(
                OutcomeKind::UNSUPPORTED_BODY,
                'This is a legacy ClickBank instant notification (a form signed with cverify, version 4.0 or'
                . ' earlier). This URL takes the encrypted notification of version 6.0 or later: '
                . self::VERSION_HINT . '.'
            );
        }
        $body = trim($request->body);
        $envelope = $body === '' ? null : json_decode($body, true, 8);
        $notification = is_array($envelope) ? ($envelope['notification'] ?? null) : null;
        $iv = is_array($envelope) ? ($envelope['iv'] ?? null) : null;
        if (!is_string($notification) || !is_string($iv)) {
            throw self::malformed(
                'The notification is not ClickBank\'s: version 6.0 and later post a JSON body with notification'
                . ' and iv; ' . self::VERSION_HINT . '.'
            );
        }
        $ciphertext = base64_decode($notification, true);
        if ($ciphertext === false || $ciphertext === '' || strlen($ciphertext) % 16 !== 0) {
            throw self::malformed('notification is not the base64 of an AES ciphertext.');
        }
        $vector = base64_decode($iv, true);
        if ($vector === false || strlen($vector) !== 16) {
            throw self::malformed('iv is not the base64 of a 16-byte initialization vector.');
        }

        return [$ciphertext, $vector];
    }

    /** @param array<string, mixed> $order */
    private static function receipt(array $order, string $type): string
    {
        $receipt = $order['receipt'] ?? null;
        if (!is_string($receipt) || $receipt === '') {
            throw self::malformed('The ' . self::shown($type) . ' carries no receipt.');
        }
        if (preg_match(self::RECEIPT, $receipt) !== 1) {
            throw self::malformed('receipt is not a ClickBank receipt (1 to 255 letters, digits and punctuation).');
        }

        return $receipt;
    }

    /**
     * The click the order names: its first tracking code (a HopLink's
     * tid=[[subid]]), or where it has none the extclid that version 8.0
     * sends an affiliate (extclid=[[subid]]). One that names neither is
     * answered as no_click (200), saying so; a value that is not a click id
     * is refused rather than read as none.
     *
     * @param array<string, mixed> $order
     */
    private static function clickId(array $order, string $type, string $receipt): int
    {
        $clickId = self::namedClick($order);
        if ($clickId === null) {
            throw Refusal::of(
                OutcomeKind::NO_CLICK,
                'Verified. The ' . $type . ' of receipt ' . $receipt . ' carries no tracking code (the HopLink had'
                . ' no tid=[[subid]]), so it names no click; nothing was recorded.'
            );
        }

        return $clickId;
    }

    /** @param array<string, mixed> $order */
    private static function namedClick(array $order): ?int
    {
        $codes = $order['trackingCodes'] ?? [];
        if (!is_array($codes) || !array_is_list($codes)) {
            throw self::malformed(
                'trackingCodes is not a list; ClickBank sends the order form\'s tracking codes as one.'
            );
        }
        $first = $codes[0] ?? null;
        if ($first !== null && $first !== '') {
            return self::named('The first tracking code', $first, 'tid');
        }
        $affiliate = $order['affiliateTrackingParameters'] ?? [];
        if (!is_array($affiliate)) {
            throw self::malformed('affiliateTrackingParameters is not an object.');
        }
        $extclid = $affiliate['extclid'] ?? null;
        if ($extclid !== null && $extclid !== '') {
            return self::named('extclid', $extclid, 'extclid');
        }

        return null;
    }

    private static function named(string $what, mixed $value, string $parameter): int
    {
        $clickId = ClickId::parse($value);
        if ($clickId === null) {
            throw self::malformed(
                $what . ' is "' . self::shown(is_scalar($value) ? (string) $value : gettype($value)) . '", which is'
                . ' not a click id. The HopLink carries ' . $parameter . '=[[subid]], which a Prosper202 tracker'
                . ' fills with the click id; send buyers through the tracker.'
            );
        }

        return $clickId;
    }

    /**
     * totalAccountAmount as an exact decimal string, or null when absent.
     * Version 8.0 sends it as a string, 7.0 as a number; a number is read at
     * the five decimals the column keeps, and one that needs more is refused.
     * A reversal's may carry a minus sign, which is dropped: a reversal
     * always takes back.
     *
     * @param array<string, mixed> $order
     */
    private static function amount(array $order, bool $signed): ?string
    {
        $raw = $order['totalAccountAmount'] ?? null;
        if ($raw === null) {
            return null;
        }
        $text = match (true) {
            is_string($raw) => $raw,
            is_int($raw) => (string) $raw,
            is_float($raw) && is_finite($raw) => self::decimal($raw),
            default => null,
        };
        if ($text === null || preg_match(self::AMOUNT, $text, $m) !== 1 || ($m[1] === '-' && !$signed)) {
            $shown = is_scalar($raw) ? self::shown(var_export($raw, true)) : gettype($raw);
            throw self::malformed(
                'totalAccountAmount is ' . $shown . '; an amount is an exact decimal such as 47 or 47.00,'
                . ($signed ? '' : ' not negative,') . ' at most 999999.99999.'
            );
        }

        return $m[2];
    }

    /** A JSON number at the column's five decimals, or null when that would change it. */
    private static function decimal(float $value): ?string
    {
        $text = sprintf('%.5F', $value);
        if ((float) $text !== $value) {
            return null;
        }

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /**
     * transactionTime as a Unix time, read with the offset it carries, or
     * null when the order states none (the row is then dated on arrival).
     * 6.0 and 8.0 send RFC 3339 (2023-10-05T13:47:51-06:00), 7.0's notes ISO
     * 8601's basic form (20200819T144359-0700); a time that is neither, or
     * names no real moment (a 13th month), is refused rather than replaced
     * by the time it arrived (CLAUDE.md #4).
     *
     * @param array<string, mixed> $order
     */
    private static function occurredAt(array $order): ?int
    {
        $raw = $order['transactionTime'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        $formats = [
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D' => ['!Y-m-d\TH:i:sP', '+00:00'],
            '/^\d{8}T\d{6}(?:Z|[+-]\d{4})$/D' => ['!Ymd\THisO', '+0000'],
        ];
        foreach ($formats as $shape => [$format, $utc]) {
            if (!is_string($raw) || preg_match($shape, $raw) !== 1) {
                continue;
            }
            // UTC, however it is written, as the format writes it back.
            $text = (string) preg_replace('/(?:Z|-00:00|-0000)$/D', $utc, $raw);
            $time = \DateTimeImmutable::createFromFormat($format, $text);
            if ($time !== false && $time->format(substr($format, 1)) === $text) {
                $at = $time->getTimestamp();
                if ($at > 0 && $at <= Notification::LAST_TIME) {
                    return $at;
                }
            }
            break;
        }

        throw self::malformed(
            'transactionTime is ' . (is_string($raw) ? '"' . self::shown($raw) . '"' : gettype($raw)) . '; ClickBank'
            . ' sends a time with its offset, such as 2023-10-05T13:47:51-06:00 (or 20231005T134751-0600).'
        );
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
