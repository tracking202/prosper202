<?php

declare(strict_types=1);

namespace Prosper202\GeoIp;

use Prosper202\Database\Connection;

/**
 * Reads and writes the refresh's one row in 202_geoip_refresh
 * (SecretTables::geoipRefresh()). A failed read or write throws
 * (Connection): an unread row must not read as "no credentials", nor an
 * unrecorded run as one that never happened.
 */
final class RefreshStore
{
    private const ROW = 1;
    private const ERROR_CHARACTERS = 1000;

    public function __construct(private readonly Connection $conn)
    {
    }

    public function read(): RefreshState
    {
        $stmt = $this->conn->prepareRead(
            'SELECT account_id, license_key, last_attempt_at, last_outcome, last_error, last_checked_at,
                    last_refreshed_at, archive_sha256, installed_build_epoch
             FROM 202_geoip_refresh WHERE refresh_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'i', [self::ROW]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            return new RefreshState();
        }

        return new RefreshState(
            self::text($row['account_id'], 'account_id') ?? '',
            self::text($row['license_key'], 'license_key') ?? '',
            self::number($row['last_attempt_at'], 'last_attempt_at'),
            self::text($row['last_outcome'], 'last_outcome'),
            self::text($row['last_error'], 'last_error'),
            self::number($row['last_checked_at'], 'last_checked_at'),
            self::number($row['last_refreshed_at'], 'last_refreshed_at'),
            self::text($row['archive_sha256'], 'archive_sha256'),
            self::number($row['installed_build_epoch'], 'installed_build_epoch'),
        );
    }

    /** Stores what is given; a null leaves that credential as it is. */
    public function saveCredentials(?string $accountId, ?string $licenseKey): void
    {
        if ($accountId !== null && $licenseKey !== null) {
            $stmt = $this->conn->prepareWrite(
                'INSERT INTO 202_geoip_refresh (refresh_id, account_id, license_key) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE account_id = ?, license_key = ?'
            );
            $this->conn->bind($stmt, 'issss', [self::ROW, $accountId, $licenseKey, $accountId, $licenseKey]);
        } elseif ($accountId !== null) {
            $stmt = $this->conn->prepareWrite(
                'INSERT INTO 202_geoip_refresh (refresh_id, account_id) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE account_id = ?'
            );
            $this->conn->bind($stmt, 'iss', [self::ROW, $accountId, $accountId]);
        } elseif ($licenseKey !== null) {
            $stmt = $this->conn->prepareWrite(
                'INSERT INTO 202_geoip_refresh (refresh_id, license_key) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE license_key = ?'
            );
            $this->conn->bind($stmt, 'iss', [self::ROW, $licenseKey, $licenseKey]);
        } else {
            return;
        }
        $this->conn->executeUpdate($stmt);
    }

    public function forgetCredentials(): void
    {
        $stmt = $this->conn->prepareWrite(
            "UPDATE 202_geoip_refresh SET account_id = '', license_key = '' WHERE refresh_id = ?"
        );
        $this->conn->bind($stmt, 'i', [self::ROW]);
        $this->conn->executeUpdate($stmt);
    }

    public function recordFailure(int $at, string $error): void
    {
        $error = mb_strimwidth($error, 0, self::ERROR_CHARACTERS, '…');
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_geoip_refresh (refresh_id, last_attempt_at, last_outcome, last_error)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE last_attempt_at = ?, last_outcome = ?, last_error = ?'
        );
        $this->conn->bind(
            $stmt,
            'iississ',
            [self::ROW, $at, RefreshState::FAILED, $error, $at, RefreshState::FAILED, $error]
        );
        $this->conn->executeUpdate($stmt);
    }

    /** A run that got MaxMind's answer: the archive it names is installed, now or already. */
    public function recordSuccess(int $at, bool $replaced, string $archiveSha256, int $installedBuildEpoch): void
    {
        $outcome = $replaced ? RefreshState::REFRESHED : RefreshState::CURRENT;
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_geoip_refresh (refresh_id, last_attempt_at, last_outcome, last_error, last_checked_at,
                 last_refreshed_at, archive_sha256, installed_build_epoch)
             VALUES (?, ?, ?, NULL, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE last_attempt_at = ?, last_outcome = ?, last_error = NULL,
                 last_checked_at = ?, last_refreshed_at = COALESCE(?, last_refreshed_at), archive_sha256 = ?,
                 installed_build_epoch = ?'
        );
        $refreshedAt = $replaced ? $at : null;
        $this->conn->bind($stmt, 'iisiisiisiisi', [
            self::ROW, $at, $outcome, $at, $refreshedAt, $archiveSha256, $installedBuildEpoch,
            $at, $outcome, $at, $refreshedAt, $archiveSha256, $installedBuildEpoch,
        ]);
        $this->conn->executeUpdate($stmt);
    }

    /** A column's text; anything but text or NULL is an unreadable row, not an empty value. */
    private static function text(mixed $value, string $column): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }
        throw new \UnexpectedValueException('202_geoip_refresh.' . $column . ' was read as ' . get_debug_type($value)
            . ', not text');
    }

    /** A column's whole number, as mysqlnd answers it (an int, or its digits). */
    private static function number(mixed $value, string $column): ?int
    {
        if ($value === null || is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[0-9]{1,10}$/D', $value) === 1) {
            return (int) $value;
        }
        throw new \UnexpectedValueException('202_geoip_refresh.' . $column . ' was read as ' . get_debug_type($value)
            . ', not a number');
    }
}
