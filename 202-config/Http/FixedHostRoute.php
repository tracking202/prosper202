<?php

declare(strict_types=1);

namespace Prosper202\Http;

use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookTarget;

/**
 * Sends a request to a fixed host (my.tracking202.com, an operator's own
 * service, a vendor's API): every such curl_init() in the tree ends in
 * exec() instead of curl_exec() (OutboundCurlCallsAreListedTest), which
 * lays two things over the request's own options, last, so nothing it set
 * can loosen them:
 *
 * - a cap on the answer read (MAX_BYTES unless the caller names another),
 *   counted as it arrives, a body sent without a length included;
 * - when an egress proxy is set (EgressProxy::configured()), the route
 *   through it: this server looks the host up itself and the proxy is asked
 *   for a CONNECT tunnel to that address (EgressProxy::routeOptions()), never
 *   to look up a name. A host that resolves only to an address on the
 *   operator's own network, or a loopback override, is reached directly. A
 *   proxy setting that does not read, or a host this server cannot look up
 *   while a proxy is set, sends nothing (false, the reason logged).
 *
 * Without a proxy the request is made as it always was: the host's name is
 * fixed, so it is not pinned, and a proxy in the environment still applies.
 * The time limits are the request's own (OutboundCallsHaveTimeoutsTest).
 */
final class FixedHostRoute
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    /** @var (callable(string): list<string>)|null */
    private static $resolver = null;

    /** @param (callable(string): list<string>)|null $resolver host => addresses; null for the system's */
    public static function resolveWith(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    /** curl_exec() of a request whose options are all set, routed and capped. */
    public static function exec(\CurlHandle $ch, int $maxBytes = self::MAX_BYTES): string|bool
    {
        $options = self::options((string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL), $maxBytes);
        if (is_string($options)) {
            error_log('FixedHostRoute: not sent: ' . $options);

            return false;
        }
        if (!curl_setopt_array($ch, $options)) {
            error_log('FixedHostRoute: not sent: curl refused an option: ' . curl_error($ch));

            return false;
        }

        return curl_exec($ch);
    }

    /**
     * The options exec() lays over a request to $url, or why it must not be
     * sent. Pure but for the proxy setting and the lookup, so a test reads
     * what the request may do.
     *
     * @return array<int, mixed>|string
     */
    public static function options(string $url, int $maxBytes = self::MAX_BYTES): array|string
    {
        $options = [
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => static fn ($handle, int $total, int $received): int => $received > $maxBytes ? 1 : 0,
        ];
        try {
            $proxy = EgressProxy::configured();
        } catch (\UnexpectedValueException $e) {
            return $e->getMessage();
        }
        if ($proxy === null) {
            return $options;
        }
        $target = self::target($url);
        if (is_string($target)) {
            return $target;
        }

        return $options + EgressProxy::routeOptions($target, $proxy);
    }

    /**
     * The address the request goes to, looked up here: the host's first
     * answer, or the address the URL names.
     */
    private static function target(string $url): WebhookTarget|string
    {
        $shown = self::shown($url);
        if (preg_match('~^(https?)://([^/?#]+)~i', $url, $m) !== 1) {
            return 'the request\'s URL (' . $shown . ') could not be read, so it cannot be routed through the egress proxy.';
        }
        $authority = $m[2];
        if (str_contains($authority, '@')) {
            return $shown . ' carries a user name before its host; it is not routed through the egress proxy.';
        }
        $port = strtolower($m[1]) === 'https' ? 443 : 80;
        if (preg_match('/^\[([0-9a-f:.]+)\](?::([0-9]{1,5}))?$/iD', $authority, $v6) === 1) {
            $host = '[' . $v6[1] . ']';
            $addresses = [$v6[1]];
            $literal = true;
            $port = isset($v6[2]) ? (int) $v6[2] : $port;
        } elseif (preg_match('/^([a-z0-9.-]+)(?::([0-9]{1,5}))?$/iD', $authority, $named) === 1) {
            $host = strtolower($named[1]);
            $port = isset($named[2]) ? (int) $named[2] : $port;
            $literal = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
            $addresses = $literal ? [$host] : (self::$resolver ?? [WebhookGuard::class, 'systemResolve'])($host);
        } else {
            return 'the host of ' . $shown . ' could not be read, so it cannot be routed through the egress proxy.';
        }
        $addresses = array_values(array_filter($addresses, static fn ($a): bool => is_string($a) && @inet_pton($a) !== false));
        if ($addresses === []) {
            return $host . ' does not resolve from this server. With an egress proxy, this server still looks up every'
                . ' host itself; the proxy is never asked to.';
        }
        $canonical = (string) inet_ntop((string) inet_pton($addresses[0]));

        return new WebhookTarget($url, $host, $port, $literal, [$canonical]);
    }

    private static function shown(string $url): string
    {
        return (string) preg_replace('/[^\x21-\x7E]/', '?', mb_substr(substr($url, 0, strcspn($url, '?#')), 0, 200));
    }
}
