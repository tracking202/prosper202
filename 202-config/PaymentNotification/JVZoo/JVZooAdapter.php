<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification\JVZoo;

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
 * JVZoo's JVZIPN (v1), as support.jvzoo.com documents it: a form post signed
 * with the receiving JVZoo account's JVZIPN secret key (JVZooSignature),
 * sent to an affiliate's Affiliate IPN or a product's JVZIPN URL. The URL
 * says which (JVZooRole), and that decides the field naming the click.
 *
 * `ctransaction` decides what is done: SALE and BILL (a rebill, under its
 * own receipt) are recorded at `ctransamount`, the amount paid to the party
 * receiving the notification, and every money event is dated by
 * `ctranstime`; RFND, CGBK and INSF (an eCheck chargeback)
 * reverse the whole sale `ctransreceipt` names, since JVZoo refunds a
 * payment whole and sends the refund under the sale's receipt.
 * CANCEL-REBILL and UNCANCEL-REBILL move no money. JVZoo lists tests among
 * the reasons it posts and names no test type; JVZIPN follows ClickBank's
 * INS, whose test is `TEST`, so that is read as one. Any other type is
 * refused by name.
 *
 * Only the fields JVZIPN v1 documents are taken. The signature joins values
 * without their names, so a field added or removed moves values between
 * names without changing it. With no field added, text moves only between
 * neighbours in name order, across a "|": a field that gains it gains the
 * "|", which none read here accepts (the receipt, the amount, the type and
 * the tid are each read to their documented shape), and the one read here
 * that can hold a "|" of its own, cvendthru, carries the buy link's
 * variables, which the buyer could have chosen anyway (CLAUDE.md #17).
 *
 * JVZIPN v2 is not taken: its cverify covers five fields, neither the
 * amount nor the tid, so anyone holding one notification could change both.
 */
final class JVZooAdapter implements ProviderAdapter
{
    /** Every field JVZIPN v1 posts (it always sends all of them; cupsellreceipt has a value for an upsell). */
    public const FIELDS = [
        'caffitid', 'ccustcc', 'ccustemail', 'ccustname', 'ccuststate', 'cproditem', 'cprodtitle', 'cprodtype',
        'ctransaction', 'ctransaffiliate', 'ctransamount', 'ctranspaymentmethod', 'ctransreceipt', 'ctranstime',
        'ctransvendor', 'cupsellreceipt', 'cvendthru', 'cverify',
    ];

    /** decimal(11,5): at most six whole digits and five decimals, with the point JVZoo writes (47.00). */
    private const AMOUNT = '/^\d{1,6}\.\d{1,5}$/D';

    /** A JVZoo payment id (5CA16795AA047184W; a rebill's ends -B002). */
    private const RECEIPT = '/^[A-Za-z0-9][A-Za-z0-9-]{0,63}$/D';

    /** The vendor's pass-through variable on the buy link. */
    public const VENDOR_VARIABLE = 'subid';

    public function __construct(private readonly ?JVZooRole $role)
    {
    }

    public function name(): string
    {
        return 'JVZoo';
    }

    public function source(): ConversionSource
    {
        return ConversionSource::JVZOO;
    }

    public function signatureField(): string
    {
        return 'cverify';
    }

    public function storedSecrets(Connection $conn): array
    {
        $stmt = $conn->prepareRead(
            'SELECT user_id, ' . JVZooKey::COLUMN . ' AS secret_key FROM 202_users_pref'
            . ' WHERE ' . JVZooKey::COLUMN . " <> '' ORDER BY user_id"
        );
        $secrets = [];
        foreach ($conn->fetchAll($stmt) as $row) {
            $column = is_string($row['secret_key']) ? $row['secret_key'] : null;
            $key = JVZooKey::stored($column);
            if ($key !== null) {
                $secrets[(int) $row['user_id']] = [$key];
            } elseif (JVZooKey::unreadable($column)) {
                error_log(sprintf(
                    'JVZoo notification: account %d\'s stored JVZIPN secret key is not a key; it verifies nothing',
                    (int) $row['user_id']
                ));
            }
        }

        return $secrets;
    }

    public function verify(InboundRequest $request, array $secrets): ?Verification
    {
        $fields = $request->fields;
        if ($fields === []) {
            if (stripos($request->contentType, 'json') !== false) {
                throw Refusal::of(
                    OutcomeKind::UNSUPPORTED_BODY,
                    'This URL takes JVZoo\'s JVZIPN, a signed form post; a JSON body is not one.'
                );
            }
            throw Refusal::of(
                OutcomeKind::MALFORMED,
                'The notification has no form fields: JVZoo posts JVZIPN as application/x-www-form-urlencoded.'
            );
        }
        if (!array_key_exists('ctransaction', $fields) && array_key_exists('transaction_type', $fields)) {
            throw Refusal::of(
                OutcomeKind::UNSUPPORTED_BODY,
                'This is a JVZIPN v2 notification. Its cverify covers five fields and neither the amount nor the'
                . ' tid, so it cannot update conversions; paste this URL into the JVZIPN v1 URL instead.'
            );
        }
        $cverify = $fields['cverify'] ?? null;
        if (!is_string($cverify) || $cverify === '') {
            throw Refusal::of(
                OutcomeKind::UNVERIFIED,
                'The notification carries no cverify, so it cannot be verified; nothing was recorded.'
            );
        }
        $message = JVZooSignature::message($fields);
        if ($message === null) {
            throw self::malformed(
                'A field arrived as a list or as text that is not UTF-8; JVZoo signs UTF-8 values, so the'
                . ' notification cannot be verified.'
            );
        }

        $verified = [];
        foreach ($secrets as $accountId => $keys) {
            foreach (array_values($keys) as $index => $key) {
                if (hash_equals(JVZooSignature::sign($message, $key), $cverify)) {
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
        if ($this->role === null) {
            throw self::malformed(
                'This URL names no role. Paste the URL Account > API integrations > JVZoo gives: the one ending'
                . ' ?role=affiliate into your Affiliate IPN, the one ending ?role=vendor into your product\'s JVZIPN'
                . ' URL.'
            );
        }
        $unknown = array_diff(array_map('strval', array_keys($fields)), self::FIELDS);
        if ($unknown !== []) {
            sort($unknown, SORT_STRING);
            throw self::malformed(
                'The notification carries ' . (count($unknown) === 1 ? 'a field' : 'fields') . ' JVZIPN v1 does not'
                . ' post (' . implode(', ', array_map(self::shown(...), array_slice($unknown, 0, 5)))
                . '); nothing was recorded.'
            );
        }

        $type = self::text($fields, 'ctransaction');
        if ($type === null) {
            throw self::malformed('The notification carries no ctransaction.');
        }
        $kind = match ($type) {
            'SALE' => NotificationKind::SALE,
            'BILL' => NotificationKind::REBILL,
            'RFND' => NotificationKind::REFUND,
            'CGBK', 'INSF' => NotificationKind::CHARGEBACK,
            'CANCEL-REBILL', 'UNCANCEL-REBILL' => NotificationKind::IGNORED,
            'TEST' => NotificationKind::TEST,
            default => null,
        };
        if ($kind === null) {
            throw self::malformed(
                'ctransaction is "' . self::shown($type) . '", which JVZIPN does not send: SALE, BILL, RFND, CGBK,'
                . ' INSF, CANCEL-REBILL or UNCANCEL-REBILL.'
            );
        }
        if ($kind === NotificationKind::TEST || $kind === NotificationKind::IGNORED) {
            return new Notification($kind, $type);
        }

        $receipt = self::text($fields, 'ctransreceipt');
        if ($receipt === null) {
            throw self::malformed('The ' . $type . ' notification carries no ctransreceipt.');
        }
        if (preg_match(self::RECEIPT, $receipt) !== 1) {
            throw self::malformed(
                'ctransreceipt is "' . self::shown($receipt) . '", which is not a JVZoo payment id (letters, digits'
                . ' and "-", at most 64).'
            );
        }
        // When it happened, which dates the row: a time that cannot be read is
        // refused, never replaced by the time it arrived (CLAUDE.md #4).
        $time = self::text($fields, 'ctranstime');
        if ($time !== null && (preg_match('/^[1-9]\d{0,9}$/D', $time) !== 1 || (int) $time > Notification::LAST_TIME)) {
            throw self::malformed(
                'ctranstime is "' . self::shown($time) . '"; JVZoo sends it as a Unix time (seconds since 1970,'
                . ' such as 1613419768).'
            );
        }
        $occurredAt = $time !== null ? (int) $time : null;
        if ($kind->records()) {
            $amount = self::amount($fields);

            return new Notification(
                $kind,
                $type,
                $receipt,
                null,
                $amount,
                null,
                $this->clickId($fields, $type, $receipt),
                $occurredAt
            );
        }

        // A refund or chargeback takes back the whole payment its receipt names.
        return new Notification(
            $kind,
            $type,
            $receipt,
            $receipt,
            null,
            null,
            $this->clickId($fields, $type, $receipt),
            $occurredAt
        );
    }

    /**
     * The click this role's field names. None is answered as no_click
     * (200), saying which field carried nothing.
     *
     * @param array<array-key, mixed> $fields
     */
    private function clickId(array $fields, string $type, string $receipt): int
    {
        $what = 'Verified. The ' . $type . ' of receipt ' . $receipt;
        if ($this->role === JVZooRole::AFFILIATE) {
            $tid = self::text($fields, 'caffitid');
            $clickId = $tid !== null ? ClickId::parse($tid) : null;
            if ($clickId !== null) {
                return $clickId;
            }
            throw Refusal::of(OutcomeKind::NO_CLICK, $what . ($tid === null
                ? ' carries no tid (the affiliate link had no ?tid=[[subid]]), so it names no click; nothing was'
                    . ' recorded.'
                : ' carries tid "' . self::shown($tid) . '", which is not a Prosper202 click id, so nothing was'
                    . ' recorded. A tracker fills ?tid=[[subid]] on the affiliate link with the click id.'));
        }

        $subids = self::vendorSubids(self::text($fields, 'cvendthru') ?? '');
        if ($subids === []) {
            throw Refusal::of(
                OutcomeKind::NO_CLICK,
                $what . ' carries no subid in cvendthru (the buy link had no ?subid=[[subid]]), so it names no'
                . ' click; nothing was recorded.'
            );
        }
        if (count($subids) > 1) {
            throw self::malformed('cvendthru carries subid more than once, with different values.');
        }
        $clickId = ClickId::parse($subids[0]);
        if ($clickId === null) {
            throw self::malformed(
                'cvendthru carries subid "' . self::shown($subids[0]) . '", which is not a click id. The buy link'
                . ' carries subid=[[subid]], which a Prosper202 tracker fills with the click id; open the buy link'
                . ' through the tracker.'
            );
        }

        return $clickId;
    }

    /**
     * The distinct values of `subid` among the buy link's variables, which
     * JVZoo hands back in cvendthru as a query string ("subid=42&x=y").
     *
     * @return list<string>
     */
    public static function vendorSubids(string $cvendthru): array
    {
        $values = [];
        foreach (explode('&', ltrim($cvendthru, '?')) as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2 && urldecode($parts[0]) === self::VENDOR_VARIABLE) {
                $values[] = urldecode($parts[1]);
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * A field's value, or null when it is absent or empty. A list where one
     * value belongs is refused.
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
            throw self::malformed($name . ' arrived as a list; JVZoo sends it as one value.');
        }

        return $value === '' ? null : $value;
    }

    /**
     * ctransamount as an exact decimal string. JVZoo's field table calls it
     * pennies (1000 = $10.00) and its sample and its notifications state
     * dollars (10.00, 0.10), so one without a decimal point is not read either
     * way.
     *
     * @param array<array-key, mixed> $fields
     */
    private static function amount(array $fields): string
    {
        $raw = self::text($fields, 'ctransamount');
        if ($raw === null) {
            throw self::malformed('The sale carries no ctransamount.');
        }
        if (preg_match('/^\d+$/D', $raw) === 1) {
            throw self::malformed(
                'ctransamount is "' . self::shown($raw) . '", a whole number. JVZoo\'s documentation calls the'
                . ' field pennies (1000 = $10.00) while its notifications state dollars (10.00), so it is not read'
                . ' either way; nothing was recorded.'
            );
        }
        if (preg_match(self::AMOUNT, $raw) !== 1) {
            throw self::malformed(
                'ctransamount is "' . self::shown($raw) . '"; an amount is an exact decimal such as 47.00, not'
                . ' negative, at most 999999.99999.'
            );
        }

        return $raw;
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
