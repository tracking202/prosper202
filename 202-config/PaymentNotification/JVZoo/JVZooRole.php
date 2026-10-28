<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification\JVZoo;

/**
 * Whose notification the URL receives, which decides the field that names
 * the click. A JVZIPN carries both: `caffitid`, the tid on the affiliate's
 * link, and `cvendthru`, the variables on the vendor's buy link. Each is the
 * other party's choice to the one who did not set it (any affiliate picks
 * the tid a vendor's notification carries), so neither is read without
 * knowing which party this is. JVZoo itself asks the two in two places, the
 * affiliate's Affiliate IPN and the product's JVZIPN URL, so each gets its
 * own URL: tracking202/static/jvzoo.php?role=affiliate and ?role=vendor.
 */
enum JVZooRole: string
{
    /** The affiliate's Affiliate IPN: the click is `caffitid`, from ?tid=[[subid]] on the affiliate link. */
    case AFFILIATE = 'affiliate';
    /** The product's JVZIPN URL: the click is `subid` in `cvendthru`, from ?subid=[[subid]] on the buy link. */
    case VENDOR = 'vendor';

    public const SCRIPT = 'tracking202/static/jvzoo.php';

    /** The role a request's query names, or null for none or another. */
    public static function fromQuery(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }

    /** This role's URL on a tracking base ("https://track.example.com/"). */
    public function url(string $base): string
    {
        return $base . self::SCRIPT . '?role=' . $this->value;
    }
}
