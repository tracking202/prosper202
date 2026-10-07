<?php

declare(strict_types=1);

namespace Prosper202\Http;

/**
 * The visitor's address as the click path stores it: VisitorIp's address,
 * masked when the owner's privacy setting holds back for this visitor
 * (PrivacyMode). connect2.php's p202StoredVisitorIp() is this with
 * trackingEnabled(), and every row that keeps a visitor's address — the
 * click (202_ips via dl.php, rtr.php and the landing-page recorders), the
 * conversion (202_conversion_logs.ip from the pixels and postbacks), the
 * error log — takes it from there (StoredVisitorIpSourceTest), as do the
 * pixels' "this address's last click" lookups, so a lookup compares like
 * with like.
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
