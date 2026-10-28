<?php

declare(strict_types=1);

namespace Prosper202\Click;

/**
 * The tracking domain as every URL builder uses it: `host[:port]`, which the
 * callers prefix with a scheme and suffix with a path.
 *
 * The stored preference is free text, and the REST API and the account page
 * accept a full URL there. Returned verbatim, `http://track.example.com/`
 * became `http://http://track.example.com//tracking202/...` in every
 * landing-page snippet, pixel and link the app printed, so a customer who
 * pasted their domain the way a browser shows it broke all of them. Reading
 * it here, once, makes both getTrackingDomain() copies agree and repairs rows
 * already stored that way.
 */
final class TrackingDomain
{
    private function __construct()
    {
    }

    /** The host and optional port of a stored value, or '' when it names none. */
    public static function normalize(string $stored): string
    {
        $value = trim($stored);
        // A scheme, or the scheme-relative '//' a snippet might be pasted from.
        $value = (string) preg_replace('#^(?:[a-z][a-z0-9+.\-]*:)?//#i', '', $value);
        // Anything from the first path, query or fragment separator on is
        // not part of the host.
        $value = (string) preg_replace('#[/?\#].*$#s', '', $value);
        // Credentials never belong in a tracking link.
        $at = strrpos($value, '@');
        if ($at !== false) {
            $value = substr($value, $at + 1);
        }
        // What is left must look like a host: the same character class the
        // SERVER_NAME fallback is held to (plus IPv6 brackets).
        return preg_match('/^[a-zA-Z0-9.\-:\[\]]+$/D', $value) === 1 ? $value : '';
    }
}
