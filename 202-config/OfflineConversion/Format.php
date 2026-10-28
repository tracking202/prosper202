<?php

declare(strict_types=1);

namespace Prosper202\OfflineConversion;

use Prosper202\Conversion\Ledger\Amount;

/**
 * The offline conversion import files Prosper202 writes, each in the layout
 * its platform's help documents (documentation/features/offline-conversions.md
 * names the pages):
 *
 * - GOOGLE: Google Ads Data Manager's offline conversion import, the method
 *   Google now recommends. Column headers on the first line, as a Data
 *   Manager file source requires; GCLID, GBRAID and WBRAID each in a column
 *   of its own, every id the click came with written. The time carries its
 *   offset as Data Manager's examples spell it (2012-08-14T17:01:54-07:00).
 * - GOOGLE_UPLOADS: the template Google Ads documents for Goals › Conversions
 *   › Uploads, by file or on a schedule from HTTPS: a Parameters:TimeZone line
 *   naming the account's zone, then Google Click ID and the conversion
 *   columns. The template has no GBRAID or WBRAID column, so a click with
 *   only those is left out of it (LeftOut::GBRAID_WBRAID_ONLY).
 * - MICROSOFT: Microsoft Advertising's offline conversion template, its
 *   Parameters line and its seven columns, the two hashed contact columns
 *   empty as the template says for conversions that are not enhanced. Its
 *   time zones are Microsoft's own names, not the account's, so the times
 *   are UTC, stated as +0000 on the Parameters line and on every row.
 *   Microsoft takes at most 100,000 rows an upload and three decimals of a
 *   value; it has no order id and no consent columns.
 */
enum Format: string
{
    case GOOGLE = 'google';
    case GOOGLE_UPLOADS = 'google_uploads';
    case MICROSOFT = 'microsoft';

    /** Microsoft's limit on the rows of one upload. */
    public const MICROSOFT_ROW_LIMIT = 100000;

    /** The platform the conversion names are kept for (ConversionNames). */
    public function platform(): string
    {
        return $this === self::MICROSOFT ? ConversionNames::MICROSOFT : ConversionNames::GOOGLE;
    }

    public function label(): string
    {
        return match ($this) {
            self::GOOGLE => 'Google Ads (Data Manager): gclid, gbraid and wbraid',
            self::GOOGLE_UPLOADS => 'Google Ads (Conversions › Uploads template): gclid only',
            self::MICROSOFT => 'Microsoft Advertising: msclkid',
        };
    }

    /** @return list<string> the column headers, in order */
    public function header(): array
    {
        return match ($this) {
            self::GOOGLE => [
                'Google Click ID', 'GBRAID', 'WBRAID', 'Conversion Name', 'Conversion Time', 'Conversion Value',
                'Conversion Currency', 'Order ID', 'Ad User Data', 'Ad Personalization',
            ],
            self::GOOGLE_UPLOADS => [
                'Google Click ID', 'Conversion Name', 'Conversion Time', 'Conversion Value', 'Conversion Currency',
                'Order ID', 'Ad User Data', 'Ad Personalization',
            ],
            self::MICROSOFT => [
                'Conversion Name', 'Conversion Time', 'Conversion Value', 'Conversion Currency', 'Microsoft Click ID',
                'Hashed Email Address', 'Hashed Phone Number',
            ],
        };
    }

    /** The zone the file's times are written in. */
    public function zone(string $accountZone): string
    {
        return $this === self::MICROSOFT ? 'UTC' : $accountZone;
    }

    /**
     * The line above the headers, when the layout has one, as the
     * platform's template writes it: padded to the header's width.
     *
     * @return list<string>|null
     */
    public function preamble(string $accountZone): ?array
    {
        $line = match ($this) {
            self::GOOGLE => null,
            self::GOOGLE_UPLOADS => 'Parameters:TimeZone=' . $accountZone,
            self::MICROSOFT => 'Parameters:TimeZone=+0000',
        };

        return $line === null ? null : array_pad([$line], count($this->header()), '');
    }

    /** A conversion time: the instant in $zone, with the offset in force at that instant. */
    public function time(int $timestamp, string $zone): string
    {
        $local = (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone($zone));

        return $local->format($this === self::GOOGLE ? 'Y-m-d\TH:i:sP' : 'Y-m-d\TH:i:sO');
    }

    /**
     * A conversion value from ledger units (Amount): as recorded, without
     * trailing zeros, or for Microsoft, which takes three decimals, rounded
     * half up to three. Never negative (LeftOut::NEGATIVE_VALUE).
     */
    public function value(int $units): string
    {
        if ($units < 0) {
            throw new \InvalidArgumentException(
                'a conversion value is never negative here, got ' . Amount::fromUnits($units)
            );
        }
        if ($this === self::MICROSOFT) {
            $thousandths = intdiv($units + 50, 100);
            $text = intdiv($thousandths, 1000) . '.' . str_pad((string) ($thousandths % 1000), 3, '0', STR_PAD_LEFT);
        } else {
            $text = Amount::fromUnits($units);
        }

        return rtrim(rtrim($text, '0'), '.');
    }

    /** @return list<string> the 202_google columns this layout writes, in its order */
    public function idColumns(): array
    {
        return match ($this) {
            self::GOOGLE => ['gclid', 'gbraid', 'wbraid'],
            self::GOOGLE_UPLOADS => ['gclid'],
            self::MICROSOFT => ['msclkid'],
        };
    }

    public function takesConsent(): bool
    {
        return $this !== self::MICROSOFT;
    }

    public function rowLimit(): ?int
    {
        return $this === self::MICROSOFT ? self::MICROSOFT_ROW_LIMIT : null;
    }

    /**
     * One conversion's line.
     *
     * @param array<string, string> $ids the click's ids, by 202_google column
     * @return list<string>
     */
    public function cells(
        array $ids,
        string $conversionName,
        string $time,
        string $value,
        string $currency,
        int $convId,
        ?Consent $adUserData,
        ?Consent $adPersonalization
    ): array {
        $orderId = self::orderId($convId);
        $userData = $adUserData?->cell() ?? '';
        $personalization = $adPersonalization?->cell() ?? '';

        return match ($this) {
            self::GOOGLE => [
                $ids['gclid'] ?? '', $ids['gbraid'] ?? '', $ids['wbraid'] ?? '', $conversionName, $time, $value,
                $currency, $orderId, $userData, $personalization,
            ],
            self::GOOGLE_UPLOADS => [
                $ids['gclid'] ?? '', $conversionName, $time, $value, $currency, $orderId, $userData, $personalization,
            ],
            self::MICROSOFT => [$conversionName, $time, $value, $currency, $ids['msclkid'] ?? '', '', ''],
        };
    }

    /**
     * The Order ID a conversion is sent with: its ledger id, the same on
     * every fetch, so Google counts a conversion fetched twice once. Not the
     * network's transaction id, which is unique only on its click.
     */
    public static function orderId(int $convId): string
    {
        return 'P202-' . $convId;
    }

    /**
     * Whether a stored click id has the shape the platforms issue (base64url
     * characters; Microsoft's is 32 hex digits). Anything else did not come
     * from an ad click, cannot match one, and is not written: a value that
     * began with = or @ would run as a formula in a spreadsheet.
     */
    public static function wellFormedId(string $id): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]{1,150}\z/D', $id) === 1;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $f): string => $f->value, self::cases());
    }
}
