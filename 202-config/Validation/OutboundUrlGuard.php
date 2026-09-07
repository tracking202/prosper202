<?php

declare(strict_types=1);

namespace Prosper202\Validation;

/**
 * Shared SSRF guard for every outbound webhook/callback URL the install
 * dispatches to. Two checks, for two moments:
 *
 *  - assertWellFormed() at the WRITE boundary (API create/update, config
 *    sync). Syntactic only -- https, a host, an allowed port, and if the host
 *    is an IP literal, an allowed one. No DNS: a resolver stall or outage in a
 *    request handler would block a PHP-FPM worker for the resolver timeout and
 *    then surface as a 422 "invalid input" for a perfectly valid URL, and a
 *    hostname's addresses can change between write and delivery anyway.
 *  - assertAllowed() at DISPATCH (the crons). The full check: everything
 *    above plus resolution, with every resolved address vetted. It returns the
 *    validated addresses, and the caller MUST pin curl to one of them with
 *    curlOptions() -- without pinning, a DNS-rebinding host can answer the
 *    guard with a public IP and hand curl's own lookup a private one.
 *
 * PHP's FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE misses several ranges that are
 * routable-looking but reach infrastructure: RFC 6598 carrier-grade NAT
 * (100.64.0.0/10, used by Tailscale and the EKS VPC CNI), IETF protocol
 * assignments (192.0.0.0/24), benchmarking (198.18.0.0/15) and multicast
 * (224.0.0.0/4). Those are checked explicitly here.
 *
 * This is the only implementation. MysqlWebhookRepository::assertUrlAllowed()
 * delegates here; a second copy is how the checks drift apart.
 */
final class OutboundUrlGuard
{
    public const array DEFAULT_PORTS = [443, 8443];

    /** Extra CIDRs PHP's filter flags do not cover, as [network, prefix bits]. */
    private const array EXTRA_DENY_V4 = [
        ['100.64.0.0', 10],   // RFC 6598 carrier-grade NAT
        ['192.0.0.0', 24],    // RFC 6890 IETF protocol assignments
        ['198.18.0.0', 15],   // RFC 2544 benchmarking
        ['224.0.0.0', 4],     // multicast
        ['240.0.0.0', 4],     // reserved / future use
    ];

    /**
     * The write-boundary check. No network I/O.
     *
     * @param list<int> $allowedPorts
     * @throws OutboundUrlException with the reason when the URL is not allowed
     */
    public static function assertWellFormed(string $url, string $label = 'url', array $allowedPorts = self::DEFAULT_PORTS): void
    {
        $host = self::parseHost($url, $label, $allowedPorts);
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            self::assertIpAllowed($host, $label);
        }
    }

    /**
     * The dispatch-time check: assertWellFormed() plus resolution.
     *
     * @param list<int> $allowedPorts
     * @return list<string> the validated IPs for the URL's host -- pass to curlOptions()
     * @throws OutboundUrlException with the reason when the URL is not allowed
     */
    public static function assertAllowed(string $url, string $label = 'url', array $allowedPorts = self::DEFAULT_PORTS): array
    {
        $host = self::parseHost($url, $label, $allowedPorts);

        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips = [$host];
        } else {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (!empty($record['ip'])) {
                        $ips[] = (string) $record['ip'];
                    }
                    if (!empty($record['ipv6'])) {
                        $ips[] = (string) $record['ipv6'];
                    }
                }
            }
        }
        if ($ips === []) {
            throw new OutboundUrlException($label . ' host does not resolve');
        }

        foreach ($ips as $ip) {
            self::assertIpAllowed($ip, $label);
        }

        return array_values($ips);
    }

    /**
     * @throws OutboundUrlException
     */
    public static function assertIpAllowed(string $ip, string $label = 'url'): void
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new OutboundUrlException($label . ' resolves to a private or reserved address');
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            foreach (self::EXTRA_DENY_V4 as [$network, $bits]) {
                if (self::ipv4InCidr($ip, $network, $bits)) {
                    throw new OutboundUrlException($label . ' resolves to a reserved address range (' . $network . '/' . $bits . ')');
                }
            }
        }
    }

    /**
     * curl options that make a delivery to $url safe, given the addresses
     * assertAllowed() just approved for it. Every dispatcher uses this same
     * set, so none can drop one of them by accident:
     *
     *  - CURLOPT_RESOLVE pins the connection to an approved address, so curl
     *    does not resolve the host a second time and pick up a rebound answer.
     *    An IPv4 literal is preferred; an IPv6 one is bracketed, the form curl
     *    documents -- unbracketed, its extra colons make the entry unparseable
     *    and curl silently drops the pin.
     *  - No redirects, and https only, including for any redirect curl might
     *    otherwise follow.
     *  - TLS verification stays on; SNI and certificate checks use the URL's
     *    hostname, which CURLOPT_RESOLVE preserves.
     *
     * @param list<string> $validatedIps the return value of assertAllowed()
     * @return array<int, mixed> for curl_setopt_array()
     * @throws OutboundUrlException when there is no address to pin to -- a caller bug, never a
     *         reason to send unpinned
     */
    public static function curlOptions(string $url, array $validatedIps): array
    {
        return [
            CURLOPT_RESOLVE => [self::curlResolveEntry($url, $validatedIps)],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ];
    }

    /**
     * The CURLOPT_RESOLVE entry for pinning $url to one of $validatedIps.
     *
     * @param list<string> $validatedIps
     * @throws OutboundUrlException when nothing can be pinned; see curlOptions()
     */
    public static function curlResolveEntry(string $url, array $validatedIps): string
    {
        $parts = parse_url($url);
        if ($validatedIps === [] || !is_array($parts) || empty($parts['host'])) {
            throw new OutboundUrlException('Refusing to send unpinned: no validated address for ' . $url);
        }

        $v4 = array_values(array_filter(
            $validatedIps,
            static fn(string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
        ));
        $pinned = $v4[0] ?? $validatedIps[0];
        if (str_contains($pinned, ':')) {
            $pinned = '[' . $pinned . ']';
        }

        return $parts['host'] . ':' . (int) ($parts['port'] ?? 443) . ':' . $pinned;
    }

    /**
     * @param list<int> $allowedPorts
     * @throws OutboundUrlException
     */
    private static function parseHost(string $url, string $label, array $allowedPorts): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new OutboundUrlException($label . ' must be a valid https:// URL');
        }
        if (isset($parts['port']) && $allowedPorts !== [] && !in_array((int) $parts['port'], $allowedPorts, true)) {
            throw new OutboundUrlException($label . ' port must be one of: ' . implode(', ', $allowedPorts));
        }

        return (string) $parts['host'];
    }

    private static function ipv4InCidr(string $ip, string $network, int $bits): bool
    {
        $ipLong = ip2long($ip);
        $netLong = ip2long($network);
        if ($ipLong === false || $netLong === false) {
            return false;
        }
        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;
        return (($ipLong & $mask) === ($netLong & $mask));
    }
}
