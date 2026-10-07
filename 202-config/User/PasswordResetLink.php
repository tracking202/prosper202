<?php

declare(strict_types=1);

namespace Prosper202\User;

use Prosper202\Click\TrackingBaseUrl;
use Prosper202\Click\TrackingDomain;

/**
 * Where a password-reset email sends its reader: this install's stored
 * address, never the host of the request that asked for the email.
 *
 * The reset page built its link from SERVER_NAME, which under Apache's
 * default UseCanonicalName Off is the Host header, and connect.php copies
 * the Host header into it on nginx's catch-all `server_name _`. Anyone who
 * knew an account's username and email could ask for its reset with
 * `Host: attacker.example`, and the account's owner received a genuine
 * email whose link carried the genuine key to the attacker's host. The
 * request's Host is the requester's to choose, and this email goes to
 * someone else (RequestHost's docblock draws that line).
 *
 * The stored address is user 1's user_tracking_domain, which only the
 * account settings write (the installer leaves it empty, measured on a fresh
 * install), read the way every tracking link reads it (TrackingBaseUrl::build:
 * a stored scheme kept, otherwise the request's). When none is stored there
 * is no address to trust, and the email carries the key without a link
 * (base() answers null).
 */
final class PasswordResetLink
{
    public const PAGE = '202-pass-reset.php';

    private function __construct()
    {
    }

    /**
     * `scheme://host[:port]/<install path>/`, or null when no address is stored.
     *
     * @param string $storedDomain user 1's user_tracking_domain
     * @param array<string, mixed> $server normally $_SERVER (the scheme when none
     *     is stored, and the install path; never the host)
     */
    public static function base(string $storedDomain, array $server, string $installRoot): ?string
    {
        if (TrackingDomain::normalize($storedDomain) === '') {
            return null;
        }

        return TrackingBaseUrl::build($storedDomain, $server, $installRoot);
    }

    /** The host of base(), without a port: for the subject and the From address. */
    public static function host(string $base): string
    {
        $host = parse_url($base, PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }
}
