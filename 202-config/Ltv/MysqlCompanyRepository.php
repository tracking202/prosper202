<?php

declare(strict_types=1);

namespace Prosper202\Ltv;

use Prosper202\Database\Connection;
use RuntimeException;

/**
 * First-class company (ABM account) records: 202_companies plus the
 * 202_customers.company_id attachment.
 *
 * Invariants:
 *  - One company per (user, normalized name). resolveOrCreate() is race-safe
 *    via INSERT ... ON DUPLICATE KEY UPDATE, so concurrent ingest for the
 *    same new company converges on one row.
 *  - The legacy free-text 202_customers.company column stays in sync with the
 *    entity (renames and merges rewrite it) so the string-grouped ABM report
 *    keeps working, including for rows created before the entity existed.
 *  - A company's email domain enables auto-attach: a new customer whose email
 *    matches and who has no company yet joins it automatically.
 */
final class MysqlCompanyRepository
{
    public function __construct(private Connection $conn)
    {
    }

    /**
     * Canonical dedup form: lowercase, inner whitespace collapsed, trimmed,
     * truncated to the index-safe column length.
     */
    public static function normalizeName(string $name): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', $name);
        $name = trim($collapsed !== null ? $collapsed : $name);

        return mb_substr(mb_strtolower($name), 0, 191);
    }

    /**
     * Validate + canonicalize a company email domain ("Example.COM/" ->
     * "example.com"). Rejects anything that is not a bare hostname.
     */
    public static function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $stripped = preg_replace('#^https?://#', '', $domain);
        $domain = $stripped !== null ? $stripped : $domain;
        $domain = rtrim(explode('/', $domain, 2)[0], '.');

        if ($domain === '' || strlen($domain) > 191
            || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain) !== 1) {
            throw new RuntimeException('domain must be a bare hostname like example.com');
        }

        return $domain;
    }

    public static function domainFromEmail(string $email): ?string
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return null;
        }
        $domain = strtolower(trim(substr($email, $at + 1)));

        return $domain !== '' ? $domain : null;
    }

    /**
     * Find-or-create by normalized name; returns the company_id either way.
     * Safe under concurrency: the unique key + ODKU LAST_INSERT_ID trick makes
     * both racers land on the same row.
     */
    public function resolveOrCreate(int $userId, string $name, ?int $now = null): int
    {
        $collapsed = preg_replace('/\s+/u', ' ', $name);
        $name = trim($collapsed !== null ? $collapsed : $name);
        if ($name === '') {
            throw new RuntimeException('Company name must not be empty');
        }
        $now = $now ?? time();

        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_companies (user_id, name, normalized_name, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE company_id = LAST_INSERT_ID(company_id)'
        );
        $this->conn->bind($stmt, 'issii', [
            $userId,
            mb_substr($name, 0, 255),
            self::normalizeName($name),
            $now,
            $now,
        ]);

        return $this->conn->executeInsert($stmt);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $userId, int $companyId): ?array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT company_id, name, normalized_name, domain, created_at, updated_at
             FROM 202_companies WHERE company_id = ? AND user_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'ii', [$companyId, $userId]);

        return $this->conn->fetchOne($stmt);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByDomain(int $userId, string $domain): ?array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT company_id, name, domain FROM 202_companies
             WHERE user_id = ? AND domain = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'is', [$userId, strtolower(trim($domain))]);

        return $this->conn->fetchOne($stmt);
    }

    /**
     * Companies with contact/revenue rollups aggregated live from attached
     * customers (merged customer records excluded).
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function listWithRollups(int $userId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT co.company_id, co.name, co.domain, co.created_at,
                    COUNT(cu.customer_id) AS contacts,
                    COALESCE(SUM(cu.order_count), 0) AS order_count,
                    COALESCE(SUM(cu.total_revenue), 0) AS total_revenue,
                    COALESCE(SUM(cu.mrr), 0) AS mrr,
                    COALESCE(MAX(cu.last_activity_time), 0) AS last_activity_time
             FROM 202_companies co
             LEFT JOIN 202_customers cu ON cu.company_id = co.company_id
                AND cu.user_id = co.user_id AND cu.merged_into_customer_id IS NULL
             WHERE co.user_id = ?
             GROUP BY co.company_id, co.name, co.domain, co.created_at
             ORDER BY total_revenue DESC, contacts DESC, co.company_id ASC
             LIMIT ? OFFSET ?'
        );
        $this->conn->bind($stmt, 'iii', [$userId, max(1, $limit), max(0, $offset)]);
        $rows = $this->conn->fetchAll($stmt);

        $countStmt = $this->conn->prepareRead(
            'SELECT COUNT(*) AS total FROM 202_companies WHERE user_id = ?'
        );
        $this->conn->bind($countStmt, 'i', [$userId]);
        $count = $this->conn->fetchOne($countStmt);

        return ['rows' => $rows, 'total' => (int) ($count['total'] ?? 0)];
    }

    /**
     * Rename a company. Rejects a rename that would collide with another
     * company's normalized name (merge them instead — a silent collision
     * would strand one row unreachable). Attached customers' legacy company
     * string is rewritten so string-grouped reports follow the rename.
     */
    public function rename(int $userId, int $companyId, string $newName): void
    {
        $collapsed = preg_replace('/\s+/u', ' ', $newName);
        $newName = trim($collapsed !== null ? $collapsed : $newName);
        if ($newName === '') {
            throw new RuntimeException('Company name must not be empty');
        }
        if ($this->get($userId, $companyId) === null) {
            throw new RuntimeException('Company not found');
        }

        $normalized = self::normalizeName($newName);
        $dupStmt = $this->conn->prepareWrite(
            'SELECT company_id FROM 202_companies
             WHERE user_id = ? AND normalized_name = ? AND company_id <> ? LIMIT 1'
        );
        $this->conn->bind($dupStmt, 'isi', [$userId, $normalized, $companyId]);
        $duplicate = $this->conn->fetchOne($dupStmt);
        if ($duplicate !== null) {
            throw new RuntimeException(
                'Another company already uses that name (#' . (int) $duplicate['company_id']
                . '); merge the two companies instead of renaming'
            );
        }

        $now = time();
        $shortName = mb_substr($newName, 0, 255);
        $this->conn->transaction(function () use ($userId, $companyId, $shortName, $normalized, $now): void {
            $stmt = $this->conn->prepareWrite(
                'UPDATE 202_companies SET name = ?, normalized_name = ?, updated_at = ?
                 WHERE company_id = ? AND user_id = ?'
            );
            $this->conn->bind($stmt, 'ssiii', [$shortName, $normalized, $now, $companyId, $userId]);
            $this->conn->executeUpdate($stmt);

            $stmt = $this->conn->prepareWrite(
                'UPDATE 202_customers SET company = ?, updated_at = ?
                 WHERE company_id = ? AND user_id = ?'
            );
            $this->conn->bind($stmt, 'siii', [$shortName, $now, $companyId, $userId]);
            $this->conn->executeUpdate($stmt);
        });
    }

    /**
     * Set (or clear, with null/'') the company's email domain used for
     * customer auto-attach. One company per domain per account — an ambiguous
     * domain would make auto-attach a coin flip.
     */
    public function setDomain(int $userId, int $companyId, ?string $domain): void
    {
        if ($this->get($userId, $companyId) === null) {
            throw new RuntimeException('Company not found');
        }

        $normalized = null;
        if ($domain !== null && trim($domain) !== '') {
            $normalized = self::normalizeDomain($domain);
            $dupStmt = $this->conn->prepareWrite(
                'SELECT company_id FROM 202_companies
                 WHERE user_id = ? AND domain = ? AND company_id <> ? LIMIT 1'
            );
            $this->conn->bind($dupStmt, 'isi', [$userId, $normalized, $companyId]);
            $duplicate = $this->conn->fetchOne($dupStmt);
            if ($duplicate !== null) {
                throw new RuntimeException(
                    'Domain ' . $normalized . ' already belongs to company #' . (int) $duplicate['company_id']
                );
            }
        }

        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_companies SET domain = ?, updated_at = ? WHERE company_id = ? AND user_id = ?'
        );
        $this->conn->bind($stmt, 'siii', [$normalized, time(), $companyId, $userId]);
        $this->conn->executeUpdate($stmt);
    }

    /**
     * Merge source into target: attached customers repoint (their legacy
     * company string follows the target's name), the target inherits the
     * source's domain if it has none, and the source row is deleted — nothing
     * else references companies, so deletion is clean.
     */
    public function merge(int $userId, int $sourceCompanyId, int $targetCompanyId): void
    {
        if ($sourceCompanyId === $targetCompanyId) {
            throw new RuntimeException('Cannot merge a company into itself');
        }
        $source = $this->get($userId, $sourceCompanyId);
        $target = $this->get($userId, $targetCompanyId);
        if ($source === null || $target === null) {
            throw new RuntimeException('Both companies must exist and belong to this account');
        }

        $now = time();
        $targetName = (string) $target['name'];
        $this->conn->transaction(function () use ($userId, $sourceCompanyId, $targetCompanyId, $targetName, $source, $target, $now): void {
            $stmt = $this->conn->prepareWrite(
                'UPDATE 202_customers SET company_id = ?, company = ?, updated_at = ?
                 WHERE company_id = ? AND user_id = ?'
            );
            $this->conn->bind($stmt, 'isiii', [$targetCompanyId, $targetName, $now, $sourceCompanyId, $userId]);
            $this->conn->executeUpdate($stmt);

            if (($target['domain'] ?? null) === null && ($source['domain'] ?? null) !== null) {
                $stmt = $this->conn->prepareWrite(
                    'UPDATE 202_companies SET domain = ?, updated_at = ? WHERE company_id = ? AND user_id = ?'
                );
                $this->conn->bind($stmt, 'siii', [(string) $source['domain'], $now, $targetCompanyId, $userId]);
                $this->conn->executeUpdate($stmt);
            }

            $stmt = $this->conn->prepareWrite(
                'DELETE FROM 202_companies WHERE company_id = ? AND user_id = ?'
            );
            $this->conn->bind($stmt, 'ii', [$sourceCompanyId, $userId]);
            $this->conn->executeUpdate($stmt);
        });
    }

    /**
     * Delete a company that has no attached customers. With attachments the
     * right operation is merge (or detach customers first) — deleting under
     * them would orphan company_id references.
     */
    public function delete(int $userId, int $companyId): void
    {
        if ($this->get($userId, $companyId) === null) {
            throw new RuntimeException('Company not found');
        }

        $countStmt = $this->conn->prepareWrite(
            'SELECT COUNT(*) AS c FROM 202_customers WHERE company_id = ? AND user_id = ?'
        );
        $this->conn->bind($countStmt, 'ii', [$companyId, $userId]);
        $count = $this->conn->fetchOne($countStmt);
        if (((int) ($count['c'] ?? 0)) > 0) {
            throw new RuntimeException(
                'Company still has ' . (int) $count['c'] . ' attached customer(s); merge it into another company instead'
            );
        }

        $stmt = $this->conn->prepareWrite(
            'DELETE FROM 202_companies WHERE company_id = ? AND user_id = ?'
        );
        $this->conn->bind($stmt, 'ii', [$companyId, $userId]);
        $this->conn->executeUpdate($stmt);
    }

    /**
     * Maintenance sweep: attach customers that carry a company string but no
     * company_id (rows written before the entity existed, or by ingest paths
     * that only set the string). Chunked and idempotent.
     *
     * @return int customers linked this pass
     */
    public function linkUnlinkedCustomers(int $userId, int $limit = 500): int
    {
        $stmt = $this->conn->prepareWrite(
            "SELECT customer_id, company FROM 202_customers
             WHERE user_id = ? AND company IS NOT NULL AND company <> ''
               AND company_id IS NULL AND merged_into_customer_id IS NULL
             LIMIT ?"
        );
        $this->conn->bind($stmt, 'ii', [$userId, max(1, $limit)]);
        $pending = $this->conn->fetchAll($stmt);

        $linked = 0;
        foreach ($pending as $row) {
            try {
                $companyId = $this->resolveOrCreate($userId, (string) $row['company']);
            } catch (RuntimeException) {
                continue; // whitespace-only name — nothing to link
            }
            $update = $this->conn->prepareWrite(
                'UPDATE 202_customers SET company_id = ?
                 WHERE customer_id = ? AND user_id = ? AND company_id IS NULL'
            );
            $this->conn->bind($update, 'iii', [$companyId, (int) $row['customer_id'], $userId]);
            $linked += $this->conn->executeUpdate($update);
        }

        return $linked;
    }
}
