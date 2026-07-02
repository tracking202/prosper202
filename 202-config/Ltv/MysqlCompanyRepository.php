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
 *  - Company strings on 202_customers are stored in CANONICAL form
 *    (whitespace-collapsed, see canonicalName()) and kept equal to the
 *    entity's name wherever a company_id is stamped, so the string-grouped
 *    ABM report and the entity-keyed views always agree.
 *  - A company's email domain enables auto-attach: a customer whose email
 *    matches and who has never been attached joins it automatically. An
 *    explicitly detached customer (company = '' marker) is never re-attached.
 */
final class MysqlCompanyRepository
{
    public function __construct(private Connection $conn)
    {
    }

    /**
     * Canonical display form: inner whitespace collapsed, trimmed, truncated
     * to the column length, original casing kept. Every write of a company
     * name — entity or customer string — goes through this so the two
     * representations compare equal.
     */
    public static function canonicalName(string $name): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', $name);
        $name = trim($collapsed !== null ? $collapsed : $name);

        return mb_substr($name, 0, 255);
    }

    /**
     * Canonical dedup form: canonicalName() lowercased and truncated to the
     * index-safe unique-key length.
     */
    public static function normalizeName(string $name): string
    {
        return mb_substr(mb_strtolower(self::canonicalName($name)), 0, 191);
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
        $name = self::canonicalName($name);
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
            $name,
            self::normalizeName($name),
            $now,
            $now,
        ]);

        return $this->conn->executeInsert($stmt);
    }

    /**
     * Strict create for the API surface: an existing company with the same
     * normalized name is a CONFLICT, never a silent mutation, and the whole
     * operation (row + optional domain) is one transaction — a rejected
     * domain must not leave the company row behind.
     */
    public function create(int $userId, string $name, ?string $domain = null): int
    {
        $name = self::canonicalName($name);
        if ($name === '') {
            throw new RuntimeException('Company name must not be empty');
        }
        $existing = $this->findByName($userId, $name);
        if ($existing !== null) {
            throw new RuntimeException(
                'Company "' . $name . '" already exists (#' . (int) $existing['company_id']
                . '); use PATCH /ltv/companies/' . (int) $existing['company_id'] . ' to modify it'
            );
        }

        $normalizedDomain = null;
        if ($domain !== null && trim($domain) !== '') {
            $normalizedDomain = self::normalizeDomain($domain);
            $owner = $this->findByDomain($userId, $normalizedDomain);
            if ($owner !== null) {
                throw new RuntimeException(
                    'Domain ' . $normalizedDomain . ' already belongs to company #' . (int) $owner['company_id']
                );
            }
        }

        return $this->conn->transaction(function () use ($userId, $name, $normalizedDomain): int {
            $companyId = $this->resolveOrCreate($userId, $name);
            if ($normalizedDomain !== null && $companyId > 0) {
                $stmt = $this->conn->prepareWrite(
                    'UPDATE 202_companies SET domain = ?, updated_at = ? WHERE company_id = ? AND user_id = ?'
                );
                $this->conn->bind($stmt, 'siii', [$normalizedDomain, time(), $companyId, $userId]);
                $this->conn->executeUpdate($stmt);
            }

            return $companyId;
        });
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
     * Lookup by (normalized) name.
     *
     * @return array<string, mixed>|null
     */
    public function findByName(int $userId, string $name): ?array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT company_id, name, normalized_name, domain FROM 202_companies
             WHERE user_id = ? AND normalized_name = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'is', [$userId, self::normalizeName($name)]);

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
     * Atomically apply a rename and/or a domain change. All validation runs
     * BEFORE the single transaction, so a rejected domain can never leave a
     * committed rename behind (and vice versa).
     *
     * @param array{name?: string, domain?: ?string} $changes 'name' renames
     *        (attached customers' legacy strings follow); 'domain' sets the
     *        auto-attach domain (null/'' clears it).
     */
    public function update(int $userId, int $companyId, array $changes): void
    {
        if (!array_key_exists('name', $changes) && !array_key_exists('domain', $changes)) {
            throw new RuntimeException('Nothing to update — supply name and/or domain');
        }
        if ($this->get($userId, $companyId) === null) {
            throw new RuntimeException('Company not found');
        }

        $newName = null;
        $normalized = null;
        if (array_key_exists('name', $changes)) {
            $newName = self::canonicalName((string) $changes['name']);
            if ($newName === '') {
                throw new RuntimeException('Company name must not be empty');
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
        }

        $setDomain = array_key_exists('domain', $changes);
        $normalizedDomain = null;
        if ($setDomain && $changes['domain'] !== null && trim((string) $changes['domain']) !== '') {
            $normalizedDomain = self::normalizeDomain((string) $changes['domain']);
            $dupStmt = $this->conn->prepareWrite(
                'SELECT company_id FROM 202_companies
                 WHERE user_id = ? AND domain = ? AND company_id <> ? LIMIT 1'
            );
            $this->conn->bind($dupStmt, 'isi', [$userId, $normalizedDomain, $companyId]);
            $duplicate = $this->conn->fetchOne($dupStmt);
            if ($duplicate !== null) {
                throw new RuntimeException(
                    'Domain ' . $normalizedDomain . ' already belongs to company #' . (int) $duplicate['company_id']
                );
            }
        }

        $now = time();
        $this->conn->transaction(function () use ($userId, $companyId, $newName, $normalized, $setDomain, $normalizedDomain, $now): void {
            $sets = [];
            $types = '';
            $binds = [];
            if ($newName !== null) {
                $sets[] = 'name = ?';
                $types .= 's';
                $binds[] = $newName;
                $sets[] = 'normalized_name = ?';
                $types .= 's';
                $binds[] = $normalized;
            }
            if ($setDomain) {
                $sets[] = 'domain = ?';
                $types .= 's';
                $binds[] = $normalizedDomain;
            }
            $sets[] = 'updated_at = ?';
            $types .= 'iii';
            $binds[] = $now;
            $binds[] = $companyId;
            $binds[] = $userId;

            $stmt = $this->conn->prepareWrite(
                'UPDATE 202_companies SET ' . implode(', ', $sets) . ' WHERE company_id = ? AND user_id = ?'
            );
            $this->conn->bind($stmt, $types, $binds);
            $this->conn->executeUpdate($stmt);

            if ($newName !== null) {
                $stmt = $this->conn->prepareWrite(
                    'UPDATE 202_customers SET company = ?, updated_at = ?
                     WHERE company_id = ? AND user_id = ?'
                );
                $this->conn->bind($stmt, 'siii', [$newName, $now, $companyId, $userId]);
                $this->conn->executeUpdate($stmt);
            }
        });
    }

    /**
     * Rename a company (see update() for the invariants).
     */
    public function rename(int $userId, int $companyId, string $newName): void
    {
        $this->update($userId, $companyId, ['name' => $newName]);
    }

    /**
     * Set (or clear, with null/'') the company's auto-attach email domain
     * (see update() for the invariants).
     */
    public function setDomain(int $userId, int $companyId, ?string $domain): void
    {
        $this->update($userId, $companyId, ['domain' => $domain]);
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
     * them would orphan company_id references. Customers whose company STRING
     * matches but who are not yet stamped (ingest rows awaiting the linking
     * sweep) also block deletion: removing the entity would only have the
     * sweep re-create it under a new id.
     */
    public function delete(int $userId, int $companyId): void
    {
        $company = $this->get($userId, $companyId);
        if ($company === null) {
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

        $strayStmt = $this->conn->prepareWrite(
            'SELECT COUNT(*) AS c FROM 202_customers
             WHERE user_id = ? AND company_id IS NULL AND company = ?
               AND merged_into_customer_id IS NULL'
        );
        $this->conn->bind($strayStmt, 'is', [$userId, (string) $company['name']]);
        $stray = $this->conn->fetchOne($strayStmt);
        if (((int) ($stray['c'] ?? 0)) > 0) {
            throw new RuntimeException(
                'Company has ' . (int) $stray['c'] . ' customer(s) pending entity linking;'
                . ' run the maintenance cron (202-cronjobs/ltv_maintenance.php) and retry'
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
     * company_id (rows written before the entity existed, or by paths that
     * only set the string). The string is rewritten to the canonical entity
     * name so string-grouped reports and entity views agree. Chunked and
     * idempotent. DB errors propagate — the caller decides how to surface
     * them; only genuinely-blank names are skipped.
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
            $canonical = self::canonicalName((string) $row['company']);
            if ($canonical === '') {
                continue; // whitespace-only string — nothing to link
            }
            $companyId = $this->resolveOrCreate($userId, $canonical);
            if ($companyId <= 0) {
                continue;
            }
            $update = $this->conn->prepareWrite(
                'UPDATE 202_customers SET company_id = ?, company = ?
                 WHERE customer_id = ? AND user_id = ? AND company_id IS NULL'
            );
            $this->conn->bind($update, 'isii', [$companyId, $canonical, (int) $row['customer_id'], $userId]);
            $linked += $this->conn->executeUpdate($update);
        }

        return $linked;
    }
}
