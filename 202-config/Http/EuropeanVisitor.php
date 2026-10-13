<?php

declare(strict_types=1);

namespace Prosper202\Http;

/**
 * Whether privacy 'eu' holds back for an address: unless GeoIP places it
 * outside Europe and outside the European Union, it does
 * (PrivacyMode::mayBeEuropean()).
 *
 * Holding back is also the answer whenever nobody can place the address: no
 * GeoIP library, a database that cannot be opened, an address the database
 * does not hold (a private or documentation range, a block allocated after
 * the shipped database was built — it is GeoLite2 of 2018-07-03, which also
 * still has the United Kingdom in the EU). Each of those masks more, never
 * less.
 *
 * One implementation for both places that ask: the click path
 * (connect2.php's p202VisitorMayBeInEu()) and the app intakes
 * (StoredVisitorIp::forAccount()), which do not load connect2.php.
 */
final class EuropeanVisitor
{
    /** The GeoIP database the click path's getGeoData() reads. */
    public const DATABASE = __DIR__ . '/../geo/GeoLite2-City.mmdb';

    private function __construct()
    {
    }

    public static function mayBe(string $address, string $database = self::DATABASE): bool
    {
        if ($address === '' || !class_exists(\GeoIp2\Database\Reader::class)) {
            return true;
        }
        try {
            $reader = new \GeoIp2\Database\Reader($database);
        } catch (\Throwable $e) {
            error_log('p202 privacy: the GeoIP database ' . $database . ' could not be opened (' . $e->getMessage()
                . '); every visitor is treated as possibly European');

            return true;
        }
        try {
            $record = $reader->city($address);
        } catch (\Throwable) {
            // Not in the database (AddressNotFoundException), or not an address.
            return true;
        } finally {
            $reader->close();
        }

        return PrivacyMode::mayBeEuropean($record->country->isInEuropeanUnion, $record->continent->code);
    }
}
