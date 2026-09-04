<?php

declare(strict_types=1);

namespace Prosper202\Validation;

use RuntimeException;

/**
 * Shared SSRF guard for every outbound webhook/callback URL the install
 * dispatches to.
 *
 * PHP's FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE misses several ranges that are
 * routable-looking but reach infrastructure: RFC 6598 carrier-grade NAT
 * (100.64.0.0/10, used by Tailscale and the EKS VPC CNI), IETF protocol
 * assignments (192.0.0.0/24), benchmarking (198.18.0.0/15) and multicast
 * (224.0.0.0/4). Those are checked explicitly here.
 *
 * Returns the validated addresses so the caller can pin its connection to one
 * of them (CURLOPT_RESOLVE) — without pinning, a DNS-rebinding host can answer
 * the guard with a public IP and hand curl's own lookup a private one.
 */
final class OutboundUrlGuard
{
    /** Extra CIDRs PHP's filter flags do not cover, as [network, prefix bits]. */
    private const array EXTRA_DENY_V4 = [
        ['100.64.0.0', 10],   // RFC 6598 carrier-grade NAT
        ['192.0.0.0', 24],    // RFC 6890 IETF protocol assignments
        ['198.18.0.0', 15],   // RFC 2544 benchmarking
        ['224.0.0.0', 4],     // multicast
        ['240.0.0.0', 4],     // reserved / future use
    ];

    /**
     * @param list<int> $allowedPorts
     * @return list<string> the validated IPs for the URL's host
     * @throws RuntimeException with the reason when the URL is not allowed
     */
    public static function assertAllowed(string $url, string $label = 'url', array $allowedPorts = [443, 8443]): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new RuntimeException($label . ' must be a valid https:// URL');
        }
        if (isset($parts['port']) && $allowedPorts !== [] && !in_array((int) $parts['port'], $allowedPorts, true)) {
            throw new RuntimeException($label . ' port must be one of: ' . implode(', ', $allowedPorts));
        }

        $host = (string) $parts['host'];
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
            throw new RuntimeException($label . ' host does not resolve');
        }

        foreach ($ips as $ip) {
            self::assertIpAllowed($ip, $label);
        }

        return array_values($ips);
    }

    /**
     * Build the CURLOPT_RESOLVE entry that pins a request to one of the
     * addresses assertAllowed() approved, so curl does not resolve the host a
     * second time and pick up a rebound answer.
     *
     * Prefers an IPv4 literal because it needs no escaping; an IPv6 address is
     * bracketed, which is the form curl documents (`example.com:443:[2001:db8::1]`)
     * and the form a bare `::1` would silently break — the extra colons make the
     * entry unparseable and curl drops the pin, quietly restoring the
     * DNS-rebinding hole the pin exists to close.
     *
     * @param list<string> $validatedIps the return value of assertAllowed()
     * @return string|null null when there is nothing safe to pin
     */
    public static function curlResolveEntry(string $url, array $validatedIps): ?string
    {
        if ($validatedIps === []) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $host = (string) $parts['host'];
        $port = (int) ($parts['port'] ?? 443);

        $pinned = null;
        foreach ($validatedIps as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $pinned = $candidate;
                break;
            }
        }
        if ($pinned === null) {
            $pinned = (string) $validatedIps[0];
            if (filter_var($pinned, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                $pinned = '[' . $pinned . ']';
            }
        }

        return $host . ':' . $port . ':' . $pinned;
    }

    public static function assertIpAllowed(string $ip, string $label = 'url'): void
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new RuntimeException($label . ' resolves to a private or reserved address');
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            foreach (self::EXTRA_DENY_V4 as [$network, $bits]) {
                if (self::ipv4InCidr($ip, $network, $bits)) {
                    throw new RuntimeException($label . ' resolves to a reserved address range (' . $network . '/' . $bits . ')');
                }
            }
        }
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
