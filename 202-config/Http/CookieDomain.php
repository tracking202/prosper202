<?php

declare(strict_types=1);

namespace Prosper202\Http;

/**
 * The Domain attribute for a cookie set in answer to this request: the host
 * the browser asked for, without its port — or '' for no Domain attribute
 * at all (a host-only cookie), which is what setcookie() sends for an empty
 * domain. Every cookie in the served tree that carries a Domain takes it
 * from here (CookieDomainSourceTest refuses any other source).
 *
 * The click cookies used $_SERVER['HTTP_HOST'] verbatim, and go.php and
 * ipx.php used SERVER_NAME. HTTP_HOST carries the port whenever it is not
 * the scheme's default (`track.example.com:8443`), and a Domain attribute
 * with a port never domain-matches the request host (RFC 6265 §5.1.3), so
 * the browser drops the cookie whole (§5.3 step 6): no tracking202subid, no
 * click_out from lp.php, no cookie attribution from the pixels, on every
 * install served from a non-default port. SERVER_NAME is the server's
 * configured name, which need not be the host the browser asked for at all
 * (nginx's catch-all `_`, a canonical name behind an alias).
 *
 * The rule, measured in Chromium against a scratch server:
 *  - A host name with a dot keeps a Domain: `track.example.com:8443` gives
 *    `track.example.com`. That is what every install on a default port has
 *    always sent, and a browser keys the cookie on it (`.track.example.com`,
 *    shared with subdomains); answering host-only instead stores a SECOND
 *    cookie of the same name beside the old one, and both are sent back — a
 *    stale click id riding along for thirty days after an upgrade.
 *  - An IP literal (`127.0.0.1:8080`, `[::1]:8080`, with or without a port,
 *    and any host a browser's URL parser reads as IPv4: one whose last
 *    label is a number) gets no Domain. RFC 6265 lets a Domain equal to an IP host match only
 *    itself, and Chromium stores `Domain=127.0.0.1` as exactly the host-only
 *    cookie that omitting it gives (it replaces, not duplicates, one an old
 *    install set) — so omitting it changes nothing that worked, and needs no
 *    answer to how a browser parses a bracketed IPv6 Domain.
 *  - A host with no dot (`localhost`, an intranet name) gets no Domain, the
 *    rule AUTH::cookie_domain() already had for localhost: Chromium stores
 *    `Domain=localhost` and `Domain=tracker` as host-only cookies too.
 *  - Anything that is not a host name — no Host header, a non-numeric or
 *    out-of-range port, a trailing dot, a character setcookie() would refuse
 *    with a ValueError (`;`, `,`, whitespace) — gets no Domain. Whether the
 *    header names a host at all is RequestHost::parse()'s answer, shared
 *    with the install's own URLs (TrackingBaseUrl::forRequest()). A host-only cookie is accepted
 *    for every host a browser can reach; a wrong Domain is dropped, and a
 *    refused one throws out of the click path.
 *
 * The Host header is chosen by the client, and that is safe for what this
 * decides (CLAUDE.md error pattern #16): a browser keeps a cookie only if
 * its Domain matches the host it requested, so a forged Host header only
 * loses the forger their own cookie.
 */
final class CookieDomain
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $server normally $_SERVER
     */
    public static function fromServer(array $server): string
    {
        $host = RequestHost::fromServer($server);

        return $host === null ? '' : self::forHost($host);
    }

    /** The Domain for a request to `$host` (a Host header value), or '' for none. */
    public static function forHost(string $host): string
    {
        // What is a host at all, and what is an address, is RequestHost's
        // answer, the one this install's own URLs are built from too: a bare
        // IPv6 address (not valid Host syntax), a second or non-numeric port
        // and anything with a character no host has are not hosts.
        $parsed = RequestHost::parse($host);
        if ($parsed === null || $parsed['ip']) {
            return '';
        }
        $host = $parsed['name'];
        // A browser parses a host whose last label is a number (`10.1`,
        // `0x7f.1`) as an IPv4 address (the WHATWG URL host parser), so it is
        // an IP literal however filter_var() reads it.
        if (preg_match('/(?:^|\.)(?:[0-9]+|0x[0-9a-f]*)$/D', $host) === 1) {
            return '';
        }
        if (!str_contains($host, '.') || str_ends_with($host, '.')) {
            return '';
        }
        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return '';
        }

        return $host;
    }
}
