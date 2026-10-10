<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Database\Connection;

/**
 * Whether a user last signed in from this address: the "don't count my own
 * clicks" half of the click filter (FILTER::checkUserIP).
 *
 * The address is the click's as it arrived (VisitorIp), never the one the
 * click path stores. The sign-in address is an operator's record and is
 * stored as it arrived under every privacy setting (202-login.php), so the
 * two sides are comparable only before the mask: comparing the stored,
 * masked click address with it — what the filter did, through the click's
 * ip_id — never matched under privacy, and the owner's own clicks were
 * counted. Masking the sign-in address instead would make the two sides
 * equal, and wrong: a mask is many-to-one (CLAUDE.md #17), so every visitor
 * in the owner's /24 (/48 for IPv6) — an office, a mobile carrier's block —
 * would read as the owner and be filtered.
 *
 * A read: the click's unmasked address is looked up among the rows already
 * stored (StoredAddressIds), so nothing about it is written.
 */
final class SignedInFromAddress
{
    private function __construct()
    {
    }

    public static function any(Connection $conn, string $arrived): bool
    {
        $ipIds = StoredAddressIds::of($conn, $arrived);
        if ($ipIds === []) {
            return false;
        }

        $in = implode(',', array_fill(0, count($ipIds), '?'));
        $stmt = $conn->prepareRead(
            'SELECT user_id FROM 202_users WHERE user_last_login_ip_id IN (' . $in . ') LIMIT 1'
        );
        $conn->bind($stmt, str_repeat('i', count($ipIds)), $ipIds);

        return $conn->fetchOne($stmt) !== null;
    }
}
