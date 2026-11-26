<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Api\V3\Exception\ValidationException;
use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;
use Prosper202\Setup\LandingPageCheckStore;

/**
 * Where the account's Library records are used: for an offer, source,
 * landing page, network or text ad, the account's live records that point
 * at it (links, landing pages, text ads, rotators, offers, traffic source
 * accounts, ad connections), counted for a list (`?with=usage`) and named for
 * one record (`GET /<resource>/{id}/uses`, and a delete's dry run). A record
 * still in use keeps working after a soft delete removes what it points at
 * from the Library, so the preview names them.
 *
 * Every read is the account's: each table is tied to the account in its own
 * WHERE, and a rotator's redirects through their rotator (CLAUDE.md #27).
 */
final class LibraryUsage
{
    use StatementHelpers;

    /** The records one kind of use names at most; `count` says how many there are. */
    public const int NAMED_PER_KIND = 25;

    /** The `with=` values a Library list takes, by resource. */
    private const WITH = [
        'campaigns' => ['usage'],
        'aff-networks' => ['usage'],
        'ppc-networks' => ['usage'],
        'ppc-accounts' => ['usage'],
        'landing-pages' => ['usage', 'health', 'last_seen'],
        'text-ads' => ['usage'],
    ];

    /**
     * The kinds of use of each resource, in the order they are answered:
     * kind => the column of that kind's records holding the resource's id.
     */
    private const USES = [
        'campaigns' => [
            'trackers' => 'aff_campaign_id',
            'landing_pages' => 'aff_campaign_id',
            'text_ads' => 'aff_campaign_id',
            'rotators' => 'campaign',
        ],
        'aff-networks' => ['campaigns' => 'aff_network_id'],
        'ppc-networks' => ['ppc_accounts' => 'ppc_network_id'],
        'ppc-accounts' => ['trackers' => 'ppc_account_id', 'ad_connections' => 'ppc_account_id'],
        'landing-pages' => ['trackers' => 'landing_page_id', 'text_ads' => 'landing_page_id', 'rotators' => 'lp'],
        'text-ads' => ['trackers' => 'text_ad_id'],
    ];

    /**
     * Each kind's table: its id, its name (null: the kind has none), its
     * live-row condition (null: deletes remove the row) and where the API
     * serves one. Rotators are read by their own queries (a rotator names a
     * record by default and in its rules' redirects).
     */
    private const KINDS = [
        'trackers' => [
            'table' => '202_trackers', 'id' => 'tracker_id', 'name' => null,
            'live' => null, 'path' => '/trackers/',
        ],
        'landing_pages' => [
            'table' => '202_landing_pages', 'id' => 'landing_page_id', 'name' => 'landing_page_nickname',
            'live' => 'landing_page_deleted = 0', 'path' => '/landing-pages/',
        ],
        'text_ads' => [
            'table' => '202_text_ads', 'id' => 'text_ad_id', 'name' => 'text_ad_name',
            'live' => 'text_ad_deleted = 0', 'path' => '/text-ads/',
        ],
        'campaigns' => [
            'table' => '202_aff_campaigns', 'id' => 'aff_campaign_id', 'name' => 'aff_campaign_name',
            'live' => 'aff_campaign_deleted = 0', 'path' => '/campaigns/',
        ],
        'ppc_accounts' => [
            'table' => '202_ppc_accounts', 'id' => 'ppc_account_id', 'name' => 'ppc_account_name',
            'live' => 'ppc_account_deleted = 0', 'path' => '/ppc-accounts/',
        ],
        'ad_connections' => [
            'table' => '202_ad_connections', 'id' => 'connection_id', 'name' => 'name',
            'live' => 'deleted_at IS NULL', 'path' => '/ad-connections/',
        ],
        'rotators' => [
            'table' => '202_rotators', 'id' => 'id', 'name' => 'name',
            'live' => null, 'path' => '/rotators/',
        ],
    ];

    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
    }

    public static function covers(string $resource): bool
    {
        return isset(self::USES[$resource]);
    }

    /**
     * The `with` a list request names: absent is none; otherwise a comma list
     * of what this resource offers, each once. Anything else is a 422 naming
     * `with` and what it takes (#4).
     *
     * @param array<string, mixed> $params
     * @return list<string>
     */
    public static function withOf(string $resource, array $params): array
    {
        if (!array_key_exists('with', $params)) {
            return [];
        }
        $offered = self::WITH[$resource] ?? [];
        $takes = $offered === []
            ? 'This list takes no with.'
            : 'A comma list of: ' . implode(', ', $offered) . '.';
        $raw = $params['with'];
        $asked = is_string($raw) ? explode(',', $raw) : [];
        $refused = $asked === [] || in_array('', $asked, true)
            || count($asked) !== count(array_unique($asked)) || array_diff($asked, $offered) !== [];
        if ($refused) {
            throw new ValidationException('Invalid with', ['with' => $takes]);
        }

        return array_values($asked);
    }

    /**
     * $rows (a list page) with what $with asks for added to each row:
     * `usage` (kind => count, and `total`) and, for landing pages, `health`
     * and `last_seen_at` (when the page last had a click, to within
     * LandingPageLastSeen::WINDOW seconds; null: none since the 1.9.81
     * upgrade).
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string> $with
     * @return list<array<string, mixed>>
     */
    public function addTo(string $resource, string $idColumn, array $rows, array $with): array
    {
        if ($with === [] || $rows === []) {
            return $rows;
        }
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row[$idColumn];
        }
        $counts = in_array('usage', $with, true) ? $this->counts($resource, $ids) : [];
        $health = in_array('health', $with, true) ? $this->health() : [];
        $seen = in_array('last_seen', $with, true) ? $this->lastSeen($ids) : null;
        foreach ($rows as $i => $row) {
            $id = (int) $row[$idColumn];
            if ($counts !== []) {
                $rows[$i]['usage'] = $counts[$id];
            }
            if (in_array('health', $with, true)) {
                $rows[$i]['health'] = self::healthOf($health[$id] ?? null);
            }
            if ($seen !== null) {
                $rows[$i]['last_seen_at'] = $seen[$id] ?? null;
            }
        }

        return $rows;
    }

    /**
     * How many of the account's live records of each kind use each of $ids.
     *
     * @param list<int> $ids
     * @return array<int, array<string, int>> id => kind => count, then `total`
     */
    public function counts(string $resource, array $ids): array
    {
        $uses = self::USES[$resource] ?? throw new \LogicException("$resource has no Library uses");
        $counts = [];
        foreach ($ids as $id) {
            $counts[$id] = array_fill_keys(array_keys($uses), 0);
        }
        if ($ids === []) {
            return $counts;
        }
        foreach ($uses as $kind => $column) {
            [$sql, $types, $binds] = $this->countSql($kind, $column, $ids);
            $stmt = $this->prepare($sql);
            $this->bind($stmt, $types, ...$binds);
            $this->execute($stmt, 'Library usage could not be read');
            $result = $this->resultOf($stmt, 'Library usage could not be read');
            while ($row = $result->fetch_assoc()) {
                $counts[(int) $row['k']][$kind] = (int) $row['n'];
            }
            $stmt->close();
        }
        foreach ($counts as $id => $byKind) {
            $counts[$id]['total'] = array_sum($byKind);
        }

        return $counts;
    }

    /**
     * The uses of one record: for each kind, how many and the first
     * NAMED_PER_KIND of them (id, name, path), lowest id first.
     *
     * @return array<string, array{count: int, records: list<array{id: int, name: ?string, path: string}>}>
     */
    public function named(string $resource, int $id): array
    {
        $uses = self::USES[$resource] ?? throw new \LogicException("$resource has no Library uses");
        $counts = $this->counts($resource, [$id])[$id];
        $named = [];
        foreach ($uses as $kind => $column) {
            $records = [];
            if ($counts[$kind] > 0) {
                [$sql, $types, $binds] = $this->recordsSql($kind, $column, $id);
                $stmt = $this->prepare($sql);
                $this->bind($stmt, $types, ...$binds);
                $this->execute($stmt, 'Library usage could not be read');
                $result = $this->resultOf($stmt, 'Library usage could not be read');
                while ($row = $result->fetch_assoc()) {
                    $records[] = [
                        'id' => (int) $row['id'],
                        'name' => $row['name'] === null ? null : (string) $row['name'],
                        'path' => self::KINDS[$kind]['path'] . (int) $row['id'],
                    ];
                }
                $stmt->close();
            }
            $named[$kind] = ['count' => $counts[$kind], 'records' => $records];
        }

        return $named;
    }

    /**
     * A landing page's code check as a list answers it: the stored result
     * for its present URL, or `unchecked`, with the words the Library shows.
     *
     * @param array{status: string, message: string, checked_at: int}|null $stored
     * @return array{status: string, level: string, label: string, message: ?string, checked_at: ?int}
     */
    public static function healthOf(?array $stored): array
    {
        $status = $stored['status'] ?? null;

        return [
            'status' => $status ?? 'unchecked',
            'level' => LandingPageCheckStore::level($status),
            'label' => LandingPageCheckStore::pill($status)[1],
            'message' => $stored['message'] ?? null,
            'checked_at' => $stored['checked_at'] ?? null,
        ];
    }

    /** @return array<int, array{status: string, message: string, checked_at: int}> */
    private function health(): array
    {
        return (new LandingPageCheckStore(new Connection($this->db)))->forAccount($this->userId);
    }

    /**
     * When each of $ids, the account's landing pages, last had a click.
     *
     * @param list<int> $ids
     * @return array<int, int> id => unix time; a page with no row has no key
     */
    private function lastSeen(array $ids): array
    {
        $in = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $this->prepare(
            'SELECT landing_page_id, last_click_at FROM ' . TableRegistry::LANDING_PAGE_LAST_SEEN
                . " WHERE user_id = ? AND landing_page_id IN ($in)"
        );
        $this->bind($stmt, 'i' . str_repeat('i', count($ids)), $this->userId, ...$ids);
        $this->execute($stmt, 'Landing page last seen times could not be read');
        $result = $this->resultOf($stmt, 'Landing page last seen times could not be read');
        $seen = [];
        while ($row = $result->fetch_assoc()) {
            $seen[(int) $row['landing_page_id']] = (int) $row['last_click_at'];
        }
        $stmt->close();

        return $seen;
    }

    /**
     * @param list<int> $ids
     * @return array{string, string, list<int>}
     */
    private function countSql(string $kind, string $column, array $ids): array
    {
        $in = implode(', ', array_fill(0, count($ids), '?'));
        if ($kind === 'rotators') {
            [$default, $redirect] = self::rotatorColumns($column);
            // A rotator counts once however many of its redirects name the record.
            // The derived table is the account's rotators alone; the records are picked outside it.
            $sql = "SELECT u.k, COUNT(DISTINCT u.rotator_id) AS n FROM ("
                . " SELECT r.id AS rotator_id, r.$default AS k FROM 202_rotators AS r WHERE r.user_id = ?"
                . " UNION ALL SELECT r.id, d.$redirect FROM 202_rotator_rules_redirects AS d"
                . ' JOIN 202_rotator_rules AS ru ON ru.id = d.rule_id'
                . ' JOIN 202_rotators AS r ON r.id = ru.rotator_id AND r.user_id = ?'
                . ") AS u WHERE u.k IN ($in) GROUP BY u.k";

            return [$sql, 'ii' . str_repeat('i', count($ids)), array_merge([$this->userId, $this->userId], $ids)];
        }
        $k = self::KINDS[$kind];
        $live = $k['live'] === null ? '' : ' AND ' . $k['live'];
        $sql = "SELECT $column AS k, COUNT(*) AS n FROM {$k['table']}"
            . " WHERE user_id = ?$live AND $column IN ($in) GROUP BY $column";

        return [$sql, 'i' . str_repeat('i', count($ids)), array_merge([$this->userId], $ids)];
    }

    /** @return array{string, string, list<int>} */
    private function recordsSql(string $kind, string $column, int $id): array
    {
        $limit = self::NAMED_PER_KIND;
        if ($kind === 'rotators') {
            [$default, $redirect] = self::rotatorColumns($column);
            $sql = 'SELECT r.id, r.name FROM 202_rotators AS r WHERE r.user_id = ? AND (r.' . $default . ' = ?'
                . ' OR EXISTS (SELECT 1 FROM 202_rotator_rules AS ru'
                . ' JOIN 202_rotator_rules_redirects AS d ON d.rule_id = ru.id'
                . " WHERE ru.rotator_id = r.id AND d.$redirect = ?))"
                . " ORDER BY r.id LIMIT $limit";

            return [$sql, 'iii', [$this->userId, $id, $id]];
        }
        $k = self::KINDS[$kind];
        $live = $k['live'] === null ? '' : ' AND ' . $k['live'];
        $name = $k['name'] ?? 'NULL';
        $sql = "SELECT {$k['id']} AS id, $name AS name FROM {$k['table']} WHERE user_id = ?$live AND $column = ?"
            . " ORDER BY {$k['id']} LIMIT $limit";

        return [$sql, 'ii', [$this->userId, $id]];
    }

    /** @return array{string, string} the rotator's default column and its redirects' column */
    private static function rotatorColumns(string $target): array
    {
        return match ($target) {
            'campaign' => ['default_campaign', 'redirect_campaign'],
            'lp' => ['default_lp', 'redirect_lp'],
            default => throw new \LogicException("A rotator names no $target"),
        };
    }
}
