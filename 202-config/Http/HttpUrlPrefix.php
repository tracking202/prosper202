<?php

declare(strict_types=1);

namespace Prosper202\Http;

/**
 * The rule Setup › Landing Pages holds a landing page URL to, as the API
 * holds it ('format' => 'http_url'): it starts with http:// or https://.
 * One rule, so the API and the page cannot accept different values: POST
 * /landing-pages took '//lp.example.com/x' and 'lp.example.com/x', which
 * the page refused, and the tracker link built from the first came out
 * http:// behind a TLS proxy.
 *
 * A URL written without its scheme ('lp.example.com/x', '//lp.example.com/x')
 * is completed with https:// (complete()) by both, and each says what it
 * stored: the page in a flash, the API in its answer's meta.normalized.
 * Sync matches landing pages by their URL, so it compares the completed
 * form on both sides (SyncEngine, and the CLI's diff with its own copy of
 * the rule, held to this one by tests/fixtures/http-url-prefix-cases.json).
 */
final class HttpUrlPrefix
{
    public const MESSAGE = 'must start with http:// or https://';

    /** What complete() puts before a URL written without a scheme. */
    public const ADDED = 'https://';

    /**
     * A host name with a dot in it, or an IPv4 address, then an optional
     * port, and nothing or a path, query or fragment: 1 the '//' it may
     * start with, 2 the rest.
     */
    private const BARE_HOST = '~^(//)?((?:(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]|(?:\d{1,3}\.){3}\d{1,3})(?::\d{1,5})?(?:[/?#].*)?)$~Dis';

    private function __construct()
    {
    }

    public static function accepts(string $url): bool
    {
        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
    }

    /**
     * $url with https:// before it when it is a host written without a
     * scheme; any other value as given, for accepts() to judge.
     */
    public static function complete(string $url): string
    {
        if (self::accepts($url) || preg_match(self::BARE_HOST, $url, $m) !== 1) {
            return $url;
        }

        return self::ADDED . $m[2];
    }
}
