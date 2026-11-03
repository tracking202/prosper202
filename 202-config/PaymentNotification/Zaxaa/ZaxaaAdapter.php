<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification\Zaxaa;

use Prosper202\Click\ClickId;
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
use Prosper202\PaymentNotification\Verification;

/**
 * Zaxaa's ZPN (zaxaa.com/p/zpn, zaxaa.com/p/zpn-pdt-vars): a form post whose
 * hash_key is signed with the seller's API signature (ZaxaaSignature).
 *
 * SALE and FIRST_BILL are recorded under trans_receipt at trans_amount, REBILL
 * as a rebill; REFUND takes back trans_amount (none: all) of the sale its
 * receipt names; CANCELED moves no money; trans_testmode=1 is a test. The
 * click comes back in products[x][custom], from ?custom=[[subid]] on the order link.
 */
final class ZaxaaAdapter implements ProviderAdapter
{
    /** decimal(11,5): at most six whole digits and five decimals. */
    private const AMOUNT = '/^\d{1,6}(?:\.\d{1,5})?$/D';

    /** The conversion row's transaction_id is varchar(255). */
    private const RECEIPT = '/^[\x21-\x7E]{1,255}$/D';

    public function name(): string
    {
        return 'Zaxaa';
    }

    public function source(): ConversionSource
    {
        return ConversionSource::ZAXAA;
    }

    public function signatureField(): string
    {
        return 'hash_key';
    }

    public function clickField(): ClickField
    {
        return new ClickField('custom in products', 'the Zaxaa order link', '?custom=[[subid]]');
    }

    public function storedSecrets(Connection $conn): array
    {
        $secrets = [];
        foreach (AccountSecrets::stored($conn, ZaxaaSignature::COLUMN) as $accountId => $column) {
            $signature = ZaxaaSignature::stored($column);
            if ($signature !== null) {
                $secrets[$accountId] = [$signature];
            }
        }

        return $secrets;
    }

    public function verify(InboundRequest $request, array $secrets): ?Verification
    {
        $fields = $request->fields;
        if ($fields === []) {
            throw Refusal::of(
                OutcomeKind::MALFORMED,
                'The notification has no form fields: Zaxaa posts its ZPN as application/x-www-form-urlencoded.'
            );
        }
        $hash = $fields['hash_key'] ?? null;
        if (!is_string($hash) || $hash === '') {
            throw Refusal::of(
                OutcomeKind::UNVERIFIED,
                'The notification carries no hash_key, so it cannot be verified; nothing was recorded.'
            );
        }
        $signed = [];
        foreach (['seller_id', 'trans_receipt', 'trans_amount'] as $name) {
            $value = $fields[$name] ?? '';
            if (!is_string($value)) {
                throw self::malformed($name . ' arrived as a list; Zaxaa sends it as one value.');
            }
            $signed[] = $value;
        }
        if ($signed[0] === '') {
            throw Refusal::of(
                OutcomeKind::UNVERIFIED,
                'The notification carries no seller_id, so it cannot be verified; nothing was recorded.'
            );
        }

        $verified = [];
        foreach ($secrets as $accountId => $keys) {
            foreach (array_values($keys) as $index => $key) {
                if (hash_equals(ZaxaaSignature::sign($signed[0], $signed[1], $signed[2], $key), $hash)) {
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
        $type = self::text($fields, 'trans_type');
        if ($type === null) {
            throw self::malformed('The notification carries no trans_type.');
        }
        $shown = self::shown($type);
        $kind = match ($type) {
            'SALE', 'FIRST_BILL' => NotificationKind::SALE,
            'REBILL' => NotificationKind::REBILL,
            'REFUND' => NotificationKind::REFUND,
            'CANCELED' => NotificationKind::IGNORED,
            default => null,
        };
        if ($kind === null) {
            throw self::malformed(
                'trans_type is "' . $shown . '", which the ZPN does not send: SALE, FIRST_BILL, REBILL, CANCELED or'
                . ' REFUND.'
            );
        }
        $testMode = self::text($fields, 'trans_testmode');
        if ($testMode !== null && $testMode !== '0' && $testMode !== '1') {
            throw self::malformed('trans_testmode is "' . self::shown($testMode) . '"; Zaxaa sends 0 or 1.');
        }
        if ($testMode === '1') {
            return new Notification(NotificationKind::TEST, $shown);
        }
        if ($kind === NotificationKind::IGNORED) {
            return new Notification($kind, $shown);
        }

        $receipt = self::text($fields, 'trans_receipt');
        if ($receipt === null) {
            throw self::malformed('The ' . $shown . ' notification carries no trans_receipt.');
        }
        if (preg_match(self::RECEIPT, $receipt) !== 1) {
            throw self::malformed('trans_receipt is not a Zaxaa receipt number (1 to 255 letters, digits and punctuation).');
        }
        $amount = self::text($fields, 'trans_amount');
        if ($amount !== null && preg_match(self::AMOUNT, $amount) !== 1) {
            throw self::malformed(
                'trans_amount is "' . self::shown($amount) . '"; an amount is an exact decimal such as 47.00, not'
                . ' negative, at most 999999.99999.'
            );
        }
        $currency = self::text($fields, 'trans_currency');
        if ($currency !== null && preg_match('/^[A-Za-z]{3}$/D', $currency) !== 1) {
            throw self::malformed('trans_currency is "' . self::shown($currency) . '"; Zaxaa sends three letters (USD).');
        }
        $currency = $currency !== null ? strtoupper($currency) : null;
        $clickId = self::clickId($fields);
        $occurredAt = self::time($fields);

        if ($kind->records()) {
            if ($amount === null) {
                throw self::malformed('The ' . $shown . ' notification carries no trans_amount.');
            }

            return new Notification($kind, $shown, $receipt, null, $amount, $currency, $clickId, $occurredAt);
        }

        // A refund names the sale's receipt; an amount of 0 or none takes it all back.
        $taken = $amount !== null && preg_match('/^0+(?:\.0+)?$/D', $amount) !== 1 ? $amount : null;

        return new Notification($kind, $shown, $receipt, $receipt, $taken, $currency, $clickId, $occurredAt);
    }

    /**
     * The one click every product's custom names, or null when none names one.
     *
     * @param array<array-key, mixed> $fields
     */
    private static function clickId(array $fields): ?int
    {
        $products = $fields['products'] ?? [];
        if (!is_array($products)) {
            throw self::malformed('products arrived as one value; Zaxaa sends it as a list of products.');
        }
        $customs = [];
        foreach ($products as $product) {
            if (!is_array($product)) {
                throw self::malformed('products carries an entry that is not a product.');
            }
            $custom = self::text($product, 'custom');
            if ($custom !== null) {
                $customs[$custom] = true;
            }
        }
        if ($customs === []) {
            return null;
        }
        if (count($customs) > 1) {
            throw self::malformed('The products carry different custom values, so they name no one click.');
        }
        $custom = (string) array_key_first($customs);
        $clickId = ClickId::parse($custom);
        if ($clickId === null) {
            throw self::malformed(
                'products[x][custom] is "' . self::shown($custom) . '", which is not a click id. The order link'
                . ' carries ?custom=[[subid]], which a Prosper202 tracker fills with the click id; open the order'
                . ' link through the tracker.'
            );
        }

        return $clickId;
    }

    /**
     * trans_date ("2013-05-09 20:00:11 UTC") as a Unix time; one that cannot
     * be read is refused, never replaced by the time it arrived (CLAUDE.md #4).
     *
     * @param array<array-key, mixed> $fields
     */
    private static function time(array $fields): ?int
    {
        $date = self::text($fields, 'trans_date');
        if ($date === null) {
            return null;
        }
        $parsed = null;
        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?: UTC)?$/D', $date, $m) === 1) {
            $at = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $m[1], new \DateTimeZone('UTC'));
            if ($at !== false && $at->format('Y-m-d H:i:s') === $m[1]) {
                $parsed = $at->getTimestamp();
            }
        }
        if ($parsed === null || $parsed <= 0 || $parsed > Notification::LAST_TIME) {
            throw self::malformed(
                'trans_date is "' . self::shown($date) . '"; Zaxaa sends it in UTC, such as 2013-05-09 20:00:11 UTC.'
            );
        }

        return $parsed;
    }

    /**
     * A field's value, trimmed, or null when it is absent or empty.
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
            throw self::malformed($name . ' arrived as a list; Zaxaa sends it as one value.');
        }
        $value = trim($value);

        return $value === '' ? null : $value;
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
