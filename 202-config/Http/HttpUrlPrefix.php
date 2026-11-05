<?php

declare(strict_types=1);

namespace Prosper202\Http;

/**
 * The rule Setup › Landing Pages holds a landing page URL to, as the API
 * holds it ('format' => 'http_url'): it starts with http:// or https://,
 * written as is. One rule, so the API and the page cannot accept different
 * values: POST /landing-pages took '//lp.example.com/x' and
 * 'lp.example.com/x', which the page refused, and the tracker link built
 * from the first came out http:// behind a TLS proxy.
 */
final class HttpUrlPrefix
{
    public const MESSAGE = 'must start with http:// or https://';

    private function __construct()
    {
    }

    public static function accepts(string $url): bool
    {
        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
    }
}
