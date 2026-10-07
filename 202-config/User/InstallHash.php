<?php

declare(strict_types=1);

namespace Prosper202\User;

/**
 * Whether a request's `hash` is an account's install hash: the shared secret
 * the hosted service sends back when it calls this install (the DNI cache
 * callback, the daily email).
 *
 * The callbacks compared hash_equals($stored, $given), and hash_equals('', '')
 * is true. The upgrade that added 202_users.install_hash filled it for user 1
 * only, so on an install upgraded from before it every other account's hash
 * is '', and `dni.php?hash=&dni=<network>` passed for a network one of them
 * registered: it marked the network processed, or had the install post that
 * network's API key to the hosted service and print the answer. A stored hash
 * that is empty matches nothing (CLAUDE.md #11), and neither does a value
 * that is not a string.
 */
final class InstallHash
{
    private function __construct()
    {
    }

    public static function matches(mixed $stored, mixed $given): bool
    {
        if (!is_string($stored) || !is_string($given) || $stored === '' || $given === '') {
            return false;
        }

        return hash_equals($stored, $given);
    }
}
