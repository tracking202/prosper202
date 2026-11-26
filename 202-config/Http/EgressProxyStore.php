<?php

declare(strict_types=1);

namespace Prosper202\Http;

use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;

/**
 * The egress proxy saved on Account › Settings: the one row of
 * 202_egress_proxy (SecretTables::egressProxy()). The install's, not an
 * account's. A failed read throws: an unread row must not read as "no
 * proxy", which would send around the proxy the operator set. A database
 * whose upgrade has not made the table yet holds no saved proxy, and reads
 * as none.
 *
 * The user name and password are a secret: written here, read by
 * EgressProxy to send, and never served back (StoredEgressProxy says only
 * whether they are stored). They are stored as the GeoIP licence key is,
 * not yet in a vault.
 */
final class EgressProxyStore
{
    private const ROW = 1;
    public const MAX_CREDENTIAL_CHARACTERS = 255;

    public function __construct(private readonly Connection $conn)
    {
    }

    public function read(): ?StoredEgressProxy
    {
        try {
            $stmt = $this->conn->prepareRead(
                'SELECT proxy_url, proxy_username, proxy_password, updated_at FROM ' . TableRegistry::EGRESS_PROXY
                . ' WHERE proxy_id = ? LIMIT 1'
            );
        } catch (\Throwable $e) {
            if (Connection::isMysqlError($e, 1146, "doesn't exist")) {
                return null;
            }
            throw $e;
        }
        $this->conn->bind($stmt, 'i', [self::ROW]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            return null;
        }
        $url = self::text($row['proxy_url'], 'proxy_url');
        if ($url === '') {
            return null;
        }
        $updated = $row['updated_at'];
        if ($updated !== null && !is_int($updated) && !(is_string($updated) && ctype_digit($updated))) {
            throw new \UnexpectedValueException(TableRegistry::EGRESS_PROXY . '.updated_at was read as '
                . get_debug_type($updated) . ', not a number');
        }

        return new StoredEgressProxy(
            $url,
            self::text($row['proxy_username'], 'proxy_username'),
            self::text($row['proxy_password'], 'proxy_password'),
            $updated === null ? null : (int) $updated,
        );
    }

    /**
     * Saves the URL (http(s)://host:port, no credentials) and, when given,
     * the user name and password; null keeps the stored ones, and
     * $clearCredentials removes them.
     */
    public function save(string $url, ?string $username, ?string $password, bool $clearCredentials, int $by, int $at): void
    {
        if ($clearCredentials) {
            $username = '';
            $password = '';
        }
        if ($username === null || $password === null) {
            $stmt = $this->conn->prepareWrite(
                'INSERT INTO ' . TableRegistry::EGRESS_PROXY . ' (proxy_id, proxy_url, updated_at, updated_by)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE proxy_url = ?, updated_at = ?, updated_by = ?'
            );
            $this->conn->bind($stmt, 'isiisii', [self::ROW, $url, $at, $by, $url, $at, $by]);
        } else {
            $stmt = $this->conn->prepareWrite(
                'INSERT INTO ' . TableRegistry::EGRESS_PROXY
                . ' (proxy_id, proxy_url, proxy_username, proxy_password, updated_at, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE proxy_url = ?, proxy_username = ?, proxy_password = ?, updated_at = ?,
                     updated_by = ?'
            );
            $this->conn->bind($stmt, 'isssiisssii', [
                self::ROW, $url, $username, $password, $at, $by, $url, $username, $password, $at, $by,
            ]);
        }
        $this->conn->executeUpdate($stmt);
    }

    /**
     * No proxy: the URL and the credentials are removed. Written as save()
     * writes the install's one row, so it holds whether or not the row exists.
     */
    public function forget(int $by, int $at): void
    {
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO ' . TableRegistry::EGRESS_PROXY
            . " (proxy_id, proxy_url, proxy_username, proxy_password, updated_at, updated_by)
             VALUES (?, '', '', '', ?, ?)
             ON DUPLICATE KEY UPDATE proxy_url = '', proxy_username = '', proxy_password = '', updated_at = ?,
                 updated_by = ?"
        );
        $this->conn->bind($stmt, 'iiiii', [self::ROW, $at, $by, $at, $by]);
        $this->conn->executeUpdate($stmt);
    }

    /** Why a user name or password typed on the page is refused, or null. */
    public static function credentialError(string $value, string $what, bool $isUsername): ?string
    {
        if (mb_strlen($value) > self::MAX_CREDENTIAL_CHARACTERS) {
            return 'The proxy ' . $what . ' is longer than ' . self::MAX_CREDENTIAL_CHARACTERS . ' characters.';
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1 || !mb_check_encoding($value, 'UTF-8')) {
            return 'The proxy ' . $what . ' has a control character or is not text.';
        }
        if ($isUsername && str_contains($value, ':')) {
            return 'The proxy user name has a colon, which a proxy reads as the start of the password.';
        }

        return null;
    }

    private static function text(mixed $value, string $column): string
    {
        if (is_string($value)) {
            return $value;
        }
        throw new \UnexpectedValueException(TableRegistry::EGRESS_PROXY . '.' . $column . ' was read as '
            . get_debug_type($value) . ', not text');
    }
}
