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
     * normalized name is a CompanyConflictException, never a silent mutation.
     * A single plain INSERT (no ODKU) carries the validated domain, so the
     * unique key — not a racy pre-check — is what enforces the contract:
     * a concurrent duplicate surfaces as a conflict, and a rejected domain
     * can never leave a company row behind.
     */
    public function create(int $userId, string $name, ?string $domain = null): int
    {
        $name = self::canonicalName($name);
        if ($name === '') {
            throw new RuntimeException('Company name must not be empty');
        }
        // Friendly fast-path message with the existing id; the unique key
        // below still catches the race this check cannot.
        $existing = $this->findByName($userId, $name);
        if ($existing !== null) {
            throw new CompanyConflictException(
                'Company "' . $name . '" already exists (#' . (int) $existing['company_id']
                . '); use PATCH /ltv/companies/' . (int) $existing['company_id'] . ' to modify it'
            );
        }

        $normalizedDomain = null;
        if ($domain !== null && trim($domain) !== '') {
            $normalizedDomain = self::normalizeDomain($domain);
            $owner = $this->findByDomain($userId, $normalizedDomain);
            if ($owner !== null) {
                // A duplicate domain is a CONFLICT (409), the same as a
                // duplicate name above and the same as the concurrent
                // uniq_user_domain race below — not a 422 validation error.
                throw new CompanyConflictException(
                    'Domain ' . $normalizedDomain . ' already belongs to company #' . (int) $owner['company_id']
                    . '; use PATCH /ltv/companies/' . (int) $owner['company_id'] . ' to modify it'
                );
            }
        }

        $now = time();
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_companies (user_id, name, normalized_name, domain, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $this->conn->bind($stmt, 'isssii', [
            $userId,
            $name,
            self::normalizeName($name),
            $normalizedDomain,
            $now,
            $now,
        ]);

        try {
            return $this->conn->executeInsert($stmt);
        } catch (RuntimeException $e) {
            // Locale-independent duplicate-key detection (one of the unique
            // keys firing under a concurrent create). The key name in the
            // error tells us which contract was violated.
            if (Connection::isMysqlError($e, 1062, 'Duplicate entry')) {
                throw new CompanyConflictException(
                    str_contains($e->getMessage(), 'uniq_user_domain')
                        ? 'Domain ' . (string) $normalizedDomain . ' already belongs to another company'
                        : 'Company "' . $name . '" already exists; use PATCH to modify it'
                );
            }
            throw $e;
        }
    }

    /**
     * Attach one customer to its company — THE single implementation shared
     * by CRM saves and ingest-time creation, so the two paths cannot drift:
     *  - a non-empty name resolves/creates the entity and stamps company_id
     *    plus the ENTITY's stored name (not the caller's raw string), keeping
     *    string-grouped reports and entity views equal;
     *  - otherwise an email's domain auto-attaches ONLY a strictly-NULL
     *    company (never attached, never explicitly detached).
     * Failure policy (swallow vs propagate) belongs to the caller.
     */
    public function attachCustomer(int $userId, int $customerId, string $companyName, string $email, int $now): void
    {
        $companyName = self::canonicalName($companyName);

        if ($companyName !== '') {
            $entity = $this->findByName($userId, $companyName);
            if ($entity === null) {
                $companyId = $this->resolveOrCreate($userId, $companyName, $now);
                $entityName = $companyName;
            } else {
                $companyId = (int) $entity['company_id'];
                $entityName = (string) $entity['name'];
            }
            if ($companyId <= 0) {
                return;
            }
            // Serialize against delete(): lock the company row before stamping
            // it onto the customer. If a concurrent delete already removed it,
            // re-create the entity so the customer never points at a dead id
            // (the just-created row is safe within this transaction). The lock
            // also blocks a delete from removing the row until we commit, at
            // which point delete's in-lock count check sees this customer.
            $locked = $this->lockCompanyRow($userId, $companyId);
            if ($locked === null) {
                $companyId = $this->resolveOrCreate($userId, $companyName, $now);
                if ($companyId <= 0) {
                    return;
                }
            } else {
                $entityName = (string) $locked['name'];
            }
            $stmt = $this->conn->prepareWrite(
                'UPDATE 202_customers SET company_id = ?, company = ?, updated_at = ?
                 WHERE customer_id = ? AND user_id = ?'
            );
            $this->conn->bind($stmt, 'isiii', [$companyId, $entityName, $now, $customerId, $userId]);
            $this->conn->executeUpdate($stmt);
            return;
        }

        $email = trim($email);
        if ($email === '') {
            return;
        }
        $domain = self::domainFromEmail($email);
        if ($domain === null) {
            return;
        }
        $company = $this->findByDomain($userId, $domain);
        if ($company === null) {
            return;
        }
        // Lock the row before the domain auto-attach so a concurrent delete
        // cannot remove it between the lookup and the stamp; if it is already
        // gone, skip — domain attach is best-effort (a NULL company stays NULL).
        $locked = $this->lockCompanyRow($userId, (int) $company['company_id']);
        if ($locked === null) {
            return;
        }
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_customers SET company_id = ?, company = ?, updated_at = ?
             WHERE customer_id = ? AND user_id = ? AND company_id IS NULL
               AND company IS NULL'
        );
        $this->conn->bind($stmt, 'isiii', [
            (int) $locked['company_id'],
            (string) $locked['name'],
            $now,
            $customerId,
            $userId,
        ]);
        $this->conn->executeUpdate($stmt);
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
    /**
     * Type-ahead search for the merge picker: match name or domain
     * (LIKE-escaped), excluding one id (the merge target itself), with the
     * attached-contact count for display.
     *
     * @return list<array<string, mixed>>
     */
    public function search(int $userId, string $q, int $excludeId = 0, int $limit = 8): array
    {
        $term = '%' . addcslashes(trim($q), '%_\\') . '%';
        $stmt = $this->conn->prepareRead(
            'SELECT co.company_id, co.name, co.domain,
                    COUNT(cu.customer_id) AS contacts
             FROM 202_companies co
             LEFT JOIN 202_customers cu ON cu.company_id = co.company_id
                AND cu.user_id = co.user_id AND cu.merged_into_customer_id IS NULL
             WHERE co.user_id = ? AND co.company_id <> ?
               AND (co.name LIKE ? OR co.domain LIKE ?)
             GROUP BY co.company_id, co.name, co.domain
             ORDER BY contacts DESC, co.name ASC
             LIMIT ?'
        );
        $this->conn->bind($stmt, 'iissi', [$userId, $excludeId, $term, $term, max(1, min(25, $limit))]);

        return $this->conn->fetchAll($stmt);
    }

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
                // Same class as the create-path name clash: a duplicate is a
                // CONFLICT (409), not a 422 validation error.
                throw new CompanyConflictException(
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
                throw new CompanyConflictException(
                    'Domain ' . $normalizedDomain . ' already belongs to company #' . (int) $duplicate['company_id']
                );
            }
        }

        $now = time();
        try {
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
        } catch (RuntimeException $e) {
            // A concurrent create/patch can slip past the preflight checks;
            // the unique keys (name, domain) are the real arbiter.
            if (Connection::isMysqlError($e, 1062, 'Duplicate entry')) {
                // The unique key fired under a concurrent create/patch — a
                // conflict (409), matching the create path's race handling.
                throw new CompanyConflictException(
                    str_contains($e->getMessage(), 'uniq_user_domain')
                        ? 'Domain ' . (string) $normalizedDomain . ' already belongs to another company'
                        : 'Another company already uses that name; merge the two companies instead of renaming'
                );
            }
            throw $e;
        }
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
        $this->conn->transaction(function () use ($userId, $sourceCompanyId, $targetCompanyId, $now): void {
            // Lock both rows in ascending id order and re-read them: two
            // concurrent merges of the same pair (either direction) would
            // otherwise both pass the prechecks, each delete its own source,
            // and repoint contacts at a row the other merge just removed.
            $lockIds = [$sourceCompanyId, $targetCompanyId];
            sort($lockIds);
            $lockStmt = $this->conn->prepareWrite(
                'SELECT company_id, name, domain FROM 202_companies
                 WHERE company_id IN (?, ?) AND user_id = ?
                 ORDER BY company_id FOR UPDATE'
            );
            $this->conn->bind($lockStmt, 'iii', [$lockIds[0], $lockIds[1], $userId]);
            $locked = [];
            foreach ($this->conn->fetchAll($lockStmt) as $row) {
                $locked[(int) $row['company_id']] = $row;
            }
            if (!isset($locked[$sourceCompanyId], $locked[$targetCompanyId])) {
                throw new RuntimeException('Company was merged or deleted concurrently; retry the merge');
            }
            $targetName = (string) $locked[$targetCompanyId]['name'];

            $stmt = $this->conn->prepareWrite(
                'UPDATE 202_customers SET company_id = ?, company = ?, updated_at = ?
                 WHERE company_id = ? AND user_id = ?'
            );
            $this->conn->bind($stmt, 'isiii', [$targetCompanyId, $targetName, $now, $sourceCompanyId, $userId]);
            $this->conn->executeUpdate($stmt);

            // Delete the source BEFORE the target inherits its domain: the
            // unique (user_id, domain) key would otherwise fire while both
            // rows momentarily hold the same domain.
            $stmt = $this->conn->prepareWrite(
                'DELETE FROM 202_companies WHERE company_id = ? AND user_id = ?'
            );
            $this->conn->bind($stmt, 'ii', [$sourceCompanyId, $userId]);
            $this->conn->executeUpdate($stmt);

            if ($locked[$targetCompanyId]['domain'] === null && $locked[$sourceCompanyId]['domain'] !== null) {
                $stmt = $this->conn->prepareWrite(
                    'UPDATE 202_companies SET domain = ?, updated_at = ? WHERE company_id = ? AND user_id = ?'
                );
                $this->conn->bind($stmt, 'siii', [(string) $locked[$sourceCompanyId]['domain'], $now, $targetCompanyId, $userId]);
                $this->conn->executeUpdate($stmt);
            }
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
        // One transaction with the company row LOCKED so this serializes
        // against attachCustomer() (CRM saves + ingest, which lock the same
        // row before stamping company_id): whichever takes the lock first
        // wins. An attach that beats the delete makes the in-lock count check
        // below see the new customer and abort; an attach that loses re-reads
        // the row as gone and re-creates the entity, so no customer is ever
        // left pointing at a deleted company id.
        $this->conn->transaction(function () use ($userId, $companyId): void {
        $company = $this->lockCompanyRow($userId, $companyId);
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

        // Unstamped legacy rows may hold UNCANONICAL strings (pre-fix
        // writers only trimmed), so exact SQL equality would miss them —
        // compare the normalized forms in PHP over the distinct strings.
        $strayStmt = $this->conn->prepareWrite(
            "SELECT company, COUNT(*) AS c FROM 202_customers
             WHERE user_id = ? AND company_id IS NULL
               AND company IS NOT NULL AND company <> ''
               AND merged_into_customer_id IS NULL
             GROUP BY company"
        );
        $this->conn->bind($strayStmt, 'i', [$userId]);
        $targetNormalized = self::normalizeName((string) $company['name']);
        $strayCount = 0;
        foreach ($this->conn->fetchAll($strayStmt) as $strayRow) {
            if (self::normalizeName((string) $strayRow['company']) === $targetNormalized) {
                $strayCount += (int) $strayRow['c'];
            }
        }
        if ($strayCount > 0) {
            throw new RuntimeException(
                'Company has ' . $strayCount . ' customer(s) pending entity linking;'
                . ' run the maintenance cron (202-cronjobs/ltv_maintenance.php) and retry'
            );
        }

        $stmt = $this->conn->prepareWrite(
            'DELETE FROM 202_companies WHERE company_id = ? AND user_id = ?'
        );
        $this->conn->bind($stmt, 'ii', [$companyId, $userId]);
        $this->conn->executeUpdate($stmt);
        });
    }

    /**
     * Lock a company row FOR UPDATE (must run inside a transaction). Returns
     * the row, or null if it no longer exists — used by attachCustomer() to
     * serialize stamping against delete().
     *
     * @return array<string, mixed>|null
     */
    private function lockCompanyRow(int $userId, int $companyId): ?array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT company_id, name, domain FROM 202_companies WHERE company_id = ? AND user_id = ? FOR UPDATE'
        );
        $this->conn->bind($stmt, 'ii', [$companyId, $userId]);

        return $this->conn->fetchOne($stmt);
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
        $entities = [];
        foreach ($pending as $row) {
            $canonical = self::canonicalName((string) $row['company']);
            if ($canonical === '') {
                continue; // whitespace-only string — nothing to link
            }
            // Resolve once per distinct name; stamp the ENTITY's stored name
            // so case/truncation variants of one company converge on a single
            // string for the string-grouped reports.
            $normalized = self::normalizeName($canonical);
            if (!isset($entities[$normalized])) {
                $entity = $this->findByName($userId, $canonical);
                if ($entity === null) {
                    $entities[$normalized] = ['id' => $this->resolveOrCreate($userId, $canonical), 'name' => $canonical];
                } else {
                    $entities[$normalized] = ['id' => (int) $entity['company_id'], 'name' => (string) $entity['name']];
                }
            }
            if ($entities[$normalized]['id'] <= 0) {
                continue;
            }
            $update = $this->conn->prepareWrite(
                'UPDATE 202_customers SET company_id = ?, company = ?
                 WHERE customer_id = ? AND user_id = ? AND company_id IS NULL'
            );
            $this->conn->bind($update, 'isii', [
                $entities[$normalized]['id'],
                $entities[$normalized]['name'],
                (int) $row['customer_id'],
                $userId,
            ]);
            $linked += $this->conn->executeUpdate($update);
        }

        return $linked;
    }

    /**
     * Converge attached customers' legacy company strings onto their entity's
     * stored name — repairs rows stamped before string canonicalization
     * existed and any drift a partial write left behind (including a NULL
     * string next to a set company_id). One set-based statement, safe to run
     * every maintenance pass.
     *
     * @return int rows corrected
     */
    public function reconcileAttachedStrings(): int
    {
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_customers c
             JOIN 202_companies co ON co.company_id = c.company_id AND co.user_id = c.user_id
             SET c.company = co.name, c.updated_at = ?
             WHERE c.company IS NULL OR c.company <> co.name'
        );
        $this->conn->bind($stmt, 'i', [time()]);

        return $this->conn->executeUpdate($stmt);
    }
}
