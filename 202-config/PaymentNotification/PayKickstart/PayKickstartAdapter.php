<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification\PayKickstart;

use Prosper202\Click\ClickId;
use Prosper202\Conversion\Ledger\ConversionSource;
use Prosper202\Database\Connection;
use Prosper202\PaymentNotification\InboundRequest;
use Prosper202\PaymentNotification\Notification;
use Prosper202\PaymentNotification\NotificationKind;
use Prosper202\PaymentNotification\OutcomeKind;
use Prosper202\PaymentNotification\ProviderAdapter;
use Prosper202\PaymentNotification\Refusal;
use Prosper202\PaymentNotification\Verification;

/**
 * PayKickstart's product (vendor) IPN: a form post a vendor sets per product
 * under its IPN Integration tab, signed with the campaign's IPN secret key
 * (PayKickstartSignature). The click is the checkout link's `subid`, which
 * PayKickstart hands back as `custom_subid` (every query variable of the
 * checkout link comes back with `custom_` in front).
 *
 * `sales` fires for every successful transaction, rebills included, and is
 * the one recorded; `subscription-payment` fires for the same subscription
 * transactions, so it is acknowledged and not recorded again. `refund`
 * reverses the sale its transaction_id names. Every other event, and any
 * event in test mode, is acknowledged.
 *
 * PayKickstart's Affiliate IPN is another notification: JSON, unsigned, with
 * PayKickstart's own tracking-link id and no custom variables, so nothing in
 * it names a click. It is answered 415, saying so.
 */
final class PayKickstartAdapter implements ProviderAdapter
{
    /** decimal(11,5): at most six whole digits and five decimals, read exactly. */
    private const AMOUNT = '/^(-?)(\d{1,6}(?:\.\d{1,5})?)$/D';

    /** The conversion row's transaction_id is varchar(255). */
    private const TRANSACTION_ID = '/^[\x21-\x7E]{1,255}$/D';

    public function name(): string
    {
        return 'PayKickstart';
    }

    public function source(): ConversionSource
    {
        return ConversionSource::PAYKICKSTART;
    }

    public function signatureField(): string
    {
        return 'hash';
    }

    public function storedSecrets(Connection $conn): array
    {
        $stmt = $conn->prepareRead(
            'SELECT user_id, ' . PayKickstartKeys::COLUMN . ' AS secret_keys FROM 202_users_pref'
            . ' WHERE ' . PayKickstartKeys::COLUMN . " <> '' ORDER BY user_id"
        );
        $secrets = [];
        foreach ($conn->fetchAll($stmt) as $row) {
            $stored = PayKickstartKeys::stored(is_string($row['secret_keys']) ? $row['secret_keys'] : null);
            if ($stored['unreadable'] > 0) {
                error_log(sprintf(
                    'PayKickstart notification: account %d has %d stored IPN secret key(s) that are not keys;'
                    . ' they verify nothing',
                    (int) $row['user_id'],
                    $stored['unreadable']
                ));
            }
            if ($stored['keys'] !== []) {
                $secrets[(int) $row['user_id']] = $stored['keys'];
            }
        }

        return $secrets;
    }

    public function verify(InboundRequest $request, array $secrets): ?Verification
    {
        if ($request->fields === []) {
            if (stripos($request->contentType, 'json') !== false) {
                throw Refusal::of(
                    OutcomeKind::UNSUPPORTED_BODY,
                    'This URL takes PayKickstart\'s product IPN, a signed form post set under each product\'s IPN'
                    . ' Integration tab. PayKickstart\'s Affiliate IPN (JSON) carries no click and no signature, so it'
                    . ' cannot update conversions; take this URL out of the Affiliate IPN setting.'
                );
            }
            throw Refusal::of(
                OutcomeKind::MALFORMED,
                'The notification has no form fields: PayKickstart posts its IPN as application/x-www-form-urlencoded.'
            );
        }
        $hash = $request->fields['hash'] ?? null;
        if (!is_string($hash) || $hash === '') {
            throw Refusal::of(
                OutcomeKind::UNVERIFIED,
                'The notification carries no hash, so it cannot be verified; nothing was recorded. (The product IPN'
                . ' is signed; PayKickstart\'s Affiliate IPN is not, and cannot update conversions.)'
            );
        }

        $message = PayKickstartSignature::message($request->fields);
        $verified = [];
        foreach ($secrets as $accountId => $keys) {
            foreach (array_values($keys) as $index => $key) {
                if (hash_equals(PayKickstartSignature::sign($message, $key), $hash)) {
                    $verified[$accountId] = $index;
                    break;
                }
            }
        }

        return $verified === [] ? null : new Verification($verified);
    }

    public function parse(InboundRequest $request): Notification
    {
        $fields = $request->fields;
        $event = self::text($fields, 'event');
        if ($event === null) {
            throw self::malformed('The notification carries no event.');
        }
        $shown = self::shown($event);
        $mode = self::text($fields, 'mode');
        if ($mode === 'test') {
            return new Notification(NotificationKind::TEST, $shown);
        }
        $kind = match ($event) {
            'sales' => NotificationKind::SALE,
            'refund' => NotificationKind::REFUND,
            default => NotificationKind::IGNORED,
        };
        if ($kind === NotificationKind::IGNORED) {
            return new Notification(NotificationKind::IGNORED, $shown);
        }
        if ($mode !== 'live') {
            throw self::malformed($mode === null
                ? 'The notification carries no mode (live or test).'
                : 'mode is "' . self::shown($mode) . '"; PayKickstart sends live or test.');
        }

        $transactionId = self::text($fields, 'transaction_id');
        if ($transactionId === null) {
            throw self::malformed('The ' . $event . ' notification carries no transaction_id.');
        }
        if (preg_match(self::TRANSACTION_ID, $transactionId) !== 1) {
            throw self::malformed(
                'transaction_id is not a PayKickstart transaction id (1 to 255 letters, digits and punctuation).'
            );
        }
        $clickId = self::clickId($fields);
        // When it happened, which dates the row: a time that cannot be read is
        // refused, never replaced by the time it arrived (CLAUDE.md #4).
        $time = self::text($fields, 'transaction_time');
        if ($time !== null && (preg_match('/^[1-9]\d{0,9}$/D', $time) !== 1 || (int) $time > Notification::LAST_TIME)) {
            throw self::malformed(
                'transaction_time is "' . self::shown($time) . '"; PayKickstart sends it as a Unix time (seconds since'
                . ' 1970, such as 1469014598).'
            );
        }
        $occurredAt = $time !== null ? (int) $time : null;

        if ($kind === NotificationKind::SALE) {
            $rebill = self::text($fields, 'is_rebill');
            if ($rebill !== null && $rebill !== '0' && $rebill !== '1') {
                throw self::malformed('is_rebill is "' . self::shown($rebill) . '"; PayKickstart sends 0 or 1.');
            }
            $amount = self::amount($fields, false);
            if ($amount === null) {
                throw self::malformed('The sale carries no amount.');
            }

            return new Notification(
                $rebill === '1' ? NotificationKind::REBILL : NotificationKind::SALE,
                $shown,
                $transactionId,
                null,
                $amount,
                null,
                $clickId,
                $occurredAt
            );
        }

        // PayKickstart does not say which amount a refund carries: a positive
        // one is taken back, and none (or 0) takes the whole sale back.
        $amount = self::amount($fields, true);

        return new Notification(
            NotificationKind::REFUND,
            $shown,
            $transactionId,
            $transactionId,
            $amount !== null && preg_match('/^0+(?:\.0+)?$/D', $amount) === 1 ? null : $amount,
            null,
            $clickId,
            $occurredAt
        );
    }

    /**
     * A field's value, trimmed as the signature trims it, or null when it is
     * absent or empty. A list where one value belongs is refused.
     *
     * @param array<array-key, mixed> $fields
     */
    private static function text(array $fields, string $name): ?string
    {
        if (!array_key_exists($name, $fields)) {
            return null;
        }
        $value = $fields[$name];
        if (!is_string($value)) {
            throw self::malformed($name . ' arrived as a list; PayKickstart sends it as one value.');
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** @param array<array-key, mixed> $fields */
    private static function clickId(array $fields): ?int
    {
        $raw = self::text($fields, 'custom_subid');
        if ($raw === null) {
            return null;
        }
        $clickId = ClickId::parse($raw);
        if ($clickId === null) {
            throw self::malformed(
                'custom_subid is "' . self::shown($raw) . '", which is not a click id. The checkout link carries'
                . ' subid=[[subid]], which a Prosper202 tracker fills with the click id; open the checkout through'
                . ' the tracker.'
            );
        }

        return $clickId;
    }

    /**
     * The amount as an exact decimal string, or null when absent. A refund's
     * may carry a minus sign, which is dropped: a reversal always takes back.
     *
     * @param array<array-key, mixed> $fields
     */
    private static function amount(array $fields, bool $signed): ?string
    {
        $raw = self::text($fields, 'amount');
        if ($raw === null) {
            return null;
        }
        if (preg_match(self::AMOUNT, $raw, $m) !== 1 || ($m[1] === '-' && !$signed)) {
            throw self::malformed(
                'amount is "' . self::shown($raw) . '"; an amount is an exact decimal such as 47 or 47.00,'
                . ($signed ? '' : ' not negative,') . ' at most 999999.99999.'
            );
        }

        return $m[2];
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
