<?php

declare(strict_types=1);

namespace Prosper202\Setup;

use Prosper202\Database\Connection;

/**
 * This account's trackers that name a landing page, and the lock that holds
 * the page still while a write decides by them.
 *
 * A tracker's link is the page's: deleting an advanced page left every
 * tracker built for it pointing at a page that no longer exists, with the
 * delete answered as a success. Setup › Landing Pages and DELETE
 * /landing-pages/{id} ask this, with AdvancedPageTextAds, before an
 * advanced page is removed, and refuse while any remain.
 *
 * The reads are made on the primary: they decide a write.
 */
final class LandingPageTrackers
{
    /** How many trackers a refusal names before it counts the rest. */
    private const NAMED = 5;

    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * Lock the page's row for the rest of the caller's transaction, so no
     * other write of the page lands between the reads that decide a write
     * and the write. The page's type, or null when the account has no live
     * page of that id.
     *
     * @throws \LogicException outside a transaction, where the lock would end with the statement
     * @throws \Prosper202\Database\Exceptions\QueryException when the read fails
     */
    public function lockPage(int $userId, int $landingPageId): ?int
    {
        if (!$this->conn->inTransaction()) {
            throw new \LogicException('A landing page is locked for a write inside the transaction that makes it.');
        }
        $stmt = $this->conn->prepareWrite(
            'SELECT landing_page_type, landing_page_deleted FROM 202_landing_pages
             WHERE landing_page_id = ? AND user_id = ? FOR UPDATE'
        );
        $this->conn->bind($stmt, 'ii', [$landingPageId, $userId]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null || (int) ($row['landing_page_deleted'] ?? 0) !== 0) {
            return null;
        }

        return (int) $row['landing_page_type'];
    }

    /**
     * @return list<int> the trackers' ids, ascending
     * @throws \Prosper202\Database\Exceptions\QueryException when the read fails
     */
    public function naming(int $userId, int $landingPageId): array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT tracker_id FROM 202_trackers WHERE user_id = ? AND landing_page_id = ? ORDER BY tracker_id'
        );
        $this->conn->bind($stmt, 'ii', [$userId, $landingPageId]);

        return array_map(static fn (array $row): int => (int) $row['tracker_id'], $this->conn->fetchAll($stmt));
    }

    /**
     * The trackers' ids for a sentence: the first few and a count of the rest.
     *
     * @param non-empty-list<int> $trackers
     */
    public static function names(array $trackers): string
    {
        $named = array_slice($trackers, 0, self::NAMED);
        $rest = count($trackers) - count($named);

        return implode(', ', $named) . ($rest > 0 ? " and $rest more" : '');
    }
}
