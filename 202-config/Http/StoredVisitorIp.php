<?php

declare(strict_types=1);

namespace Prosper202\Http;

use Prosper202\Database\Connection;

/**
 * The visitor's address as the click path stores it: VisitorIp's address,
 * masked when the owner's privacy setting holds back for this visitor
 * (PrivacyMode). connect2.php's p202StoredVisitorIp() is this with
 * trackingEnabled(), and every row that keeps a visitor's address — the
 * click (202_ips via dl.php, rtr.php and the landing-page recorders), the
 * conversion (202_conversion_logs.ip from the pixels and postbacks), the
 * error log — takes it from there (StoredVisitorIpSourceTest), as do the
 * pixels' "this address's last click" lookups, so a lookup compares like
 * with like. The app intakes, which do not load connect2.php, store their
 * sender's address through forAccount() (202_app_installs and
 * 202_app_postbacks.remote_ip); StoredAddressWritesTest holds every write
 * of an address column to one of the two, or to a listed reason.
 *
 * A comparison with an address someone else stored as it arrived (the
 * sign-in address, FILTER::checkUserIP) takes the visitor's address before
 * the mask, never after: a masked address equals no unmasked one, and
 * masking the other side instead would make a /24 one person.
 *
 * Privacy mode used to mask only the $ip_address global ipAddress() builds,
 * which no click storage read: every endpoint stored the address as it
 * arrived, whatever the setting said.
 */
final class StoredVisitorIp
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $server normally $_SERVER
     * @param bool $privacy true when the visitor is not tracked in full
     */
    public static function fromServer(array $server, bool $privacy): string
    {
        $address = VisitorIp::fromServer($server);

        return $privacy ? self::mask($address) : $address;
    }

    /**
     * The address an app intake stores for its sender (the device): the same
     * policy as the click path's — the address in canonical form, masked
     * when the privacy setting holds back for it (PrivacySetting, the
     * strictest of the install's and the owning account's; PrivacyMode;
     * EuropeanVisitor under 'eu') — for code that does not load
     * connect2.php. '' for anything that is not an address.
     *
     * Storage only. Whatever keys a decision on the sender — the intakes'
     * rate limits — keys on REMOTE_ADDR as it arrived (CLAUDE.md #16).
     *
     * @param ?int $ownerId the account the request names, null when none does
     *        (an unregistered app's postback): the install's setting alone
     * @param ?\Closure(string): bool $mayBeEuropean asked only under 'eu';
     *        EuropeanVisitor::mayBe() unless a test plants one
     */
    public static function forAccount(
        Connection $conn,
        string $address,
        ?int $ownerId,
        ?\Closure $mayBeEuropean = null
    ): string {
        $address = self::canonical($address);
        if ($address === '') {
            return '';
        }
        $mayBeEuropean ??= EuropeanVisitor::mayBe(...);
        $inFull = PrivacyMode::tracksInFull(
            PrivacySetting::forAccount($conn, $ownerId),
            static fn (): bool => $mayBeEuropean($address)
        );

        return $inFull ? $address : self::mask($address);
    }

    /** The address in canonical form (inet_ntop), or '' when it is not one. */
    private static function canonical(string $address): string
    {
        $address = trim($address);
        $packed = $address === '' || filter_var($address, FILTER_VALIDATE_IP) === false ? false : @inet_pton($address);
        $canonical = $packed === false ? false : inet_ntop($packed);

        return $canonical === false ? '' : $canonical;
    }

    /**
     * The address with its host part zeroed: an IPv4 address keeps its /24
     * (203.0.113.0), an IPv6 one its /48 (2001:db8:85a3::) — the widths
     * maskIpAddress() has always used. Done on the packed bytes: the string
     * version split compressed IPv6 on ':' and kept the wrong groups (`::1`
     * came back as `0:0:1::`). Anything that is not an address gives ''.
     */
    public static function mask(string $address): string
    {
        $packed = $address === '' ? false : @inet_pton($address);
        if ($packed === false) {
            return '';
        }
        $keep = strlen($packed) === 4 ? 3 : 6;
        $masked = inet_ntop(substr($packed, 0, $keep) . str_repeat("\0", strlen($packed) - $keep));

        return $masked === false ? '' : $masked;
    }
}
