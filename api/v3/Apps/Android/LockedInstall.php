<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android;

use Api\V3\Apps\AppIdentity;
use Api\V3\Apps\AppPolicy;
use Api\V3\Apps\AppRegistration;
use Prosper202\Database\Connection;

/**
 * An install row locked for re-classification, with the registration it
 * belongs to — the one read both reclassification paths take
 * (PendingClickSettler::settleOne(), IntegrityVerifier::finish()).
 *
 * The row is the install's own columns (`i.*`, integrity_mode among them:
 * the mode the install arrived under) beside the registration's identity
 * and its policy columns under AppPolicy::REGISTRATION_PREFIX, so the
 * registration built from it carries the registration's LIVE policy. Read
 * unaliased, `integrity_mode` named the install's snapshot and the policy
 * silently held it (both columns share one domain, so nothing throws).
 */
final class LockedInstall
{
    public const SQL = 'SELECT i.*, r.platform, r.app_key, ' . AppPolicy::REGISTRATION_COLUMNS . '
         FROM 202_app_installs i JOIN 202_app_registrations r ON r.registration_id = i.registration_id
         WHERE i.install_row_id = ? LIMIT 1 FOR UPDATE';

    private function __construct()
    {
    }

    /** @return array<string, mixed>|null the locked row, or null when the install is gone */
    public static function read(Connection $conn, int $installRowId): ?array
    {
        $stmt = $conn->prepareWrite(self::SQL);
        $conn->bind($stmt, 'i', [$installRowId]);

        return $conn->fetchOne($stmt);
    }

    /** @param array<string, mixed> $row a row read(); its registration, with the registration's live policy */
    public static function registration(array $row): AppRegistration
    {
        return new AppRegistration(
            (int) $row['registration_id'],
            (int) $row['user_id'],
            AppIdentity::fromKey((string) $row['platform'], (string) $row['app_key']),
            AppPolicy::fromRow($row, AppPolicy::REGISTRATION_PREFIX),
        );
    }
}
