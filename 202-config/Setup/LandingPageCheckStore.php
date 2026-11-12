<?php

declare(strict_types=1);

namespace Prosper202\Setup;

use Prosper202\Database\Connection;

/**
 * The landing page code check, kept: run() checks one of the account's
 * landing pages (LandingPageCheck) and stores what it found
 * (202_landing_page_checks), so Setup › Landing Pages, Get LP Code and the
 * API show it without asking again.
 *
 * A stored result answers for the URL it fetched. Once the page's URL
 * changes it reads as unchecked: nothing has to clear it at each writer of
 * the URL.
 *
 * recheckDue() is the cron job's: the pages never checked, checked for an
 * older URL, or last checked more than RECHECK_SECONDS ago, the longest
 * waiting first, a few a run.
 */
final class LandingPageCheckStore
{
    public const RECHECK_SECONDS = 86400;
    /** Pages one cron run checks at most; each takes up to LandingPageCheck::TIMEOUT seconds. */
    public const PAGES_PER_RUN = 5;

    /** @var callable(): int */
    private $clock;

    /** @param (callable(): int)|null $clock */
    public function __construct(
        private readonly Connection $conn,
        private readonly ?LandingPageCheck $checker = null,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Check the account's landing page $landingPageId now and store the
     * answer. Null when it is not the account's, or was removed.
     *
     * @param string $trackerHost this install's tracking host[:port]; '' when unknown
     */
    public function run(int $userId, int $landingPageId, string $trackerHost): ?LandingPageCheckResult
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT landing_page_url, landing_page_id_public FROM 202_landing_pages'
            . ' WHERE landing_page_id = ? AND user_id = ? AND landing_page_deleted = 0'
        );
        $this->conn->bind($stmt, 'ii', [$landingPageId, $userId]);
        $page = $this->conn->fetchOne($stmt);
        if ($page === null) {
            return null;
        }
        $url = (string) $page['landing_page_url'];
        $result = ($this->checker ?? new LandingPageCheck())->check($url, (string) ($page['landing_page_id_public'] ?? ''), $trackerHost);
        $this->save($userId, $landingPageId, $url, $result);

        return $result;
    }

    private function save(int $userId, int $landingPageId, string $url, LandingPageCheckResult $result): void
    {
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_landing_page_checks (landing_page_id, user_id, checked_url, check_status, check_message, checked_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), checked_url = VALUES(checked_url),'
            . ' check_status = VALUES(check_status), check_message = VALUES(check_message), checked_at = VALUES(checked_at)'
        );
        $this->conn->bind($stmt, 'iisssi', [
            $landingPageId, $userId, mb_substr($url, 0, 255), $result->status, mb_substr($result->message, 0, 2000), ($this->clock)(),
        ]);
        $this->conn->executeUpdate($stmt);
    }

    /**
     * The stored result of each of the account's live landing pages that
     * has one for its present URL: landing page id => result.
     *
     * @return array<int, array{status: string, message: string, checked_at: int}>
     */
    public function forAccount(int $userId): array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT k.landing_page_id, k.check_status, k.check_message, k.checked_at'
            . ' FROM 202_landing_page_checks AS k'
            . ' JOIN 202_landing_pages AS lp ON lp.landing_page_id = k.landing_page_id AND lp.user_id = k.user_id'
            . ' WHERE k.user_id = ? AND lp.landing_page_deleted = 0 AND k.checked_url = lp.landing_page_url'
        );
        $this->conn->bind($stmt, 'i', [$userId]);
        $results = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $results[(int) $row['landing_page_id']] = [
                'status' => (string) $row['check_status'],
                'message' => (string) $row['check_message'],
                'checked_at' => (int) $row['checked_at'],
            ];
        }

        return $results;
    }

    /**
     * The stored result of one of the account's landing pages, for its
     * present URL; null when there is none.
     *
     * @return array{status: string, message: string, checked_at: int}|null
     */
    public function forPage(int $userId, int $landingPageId): ?array
    {
        // One row by its primary key: the API's cooldown asks this on every check.
        $stmt = $this->conn->prepareRead(
            'SELECT k.check_status, k.check_message, k.checked_at'
            . ' FROM 202_landing_page_checks AS k'
            . ' JOIN 202_landing_pages AS lp ON lp.landing_page_id = k.landing_page_id AND lp.user_id = k.user_id'
            . ' WHERE k.landing_page_id = ? AND k.user_id = ? AND lp.landing_page_deleted = 0'
            . ' AND k.checked_url = lp.landing_page_url'
        );
        $this->conn->bind($stmt, 'ii', [$landingPageId, $userId]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            return null;
        }

        return [
            'status' => (string) $row['check_status'],
            'message' => (string) $row['check_message'],
            'checked_at' => (int) $row['checked_at'],
        ];
    }

    /**
     * Check the pages that are due, until $deadline (unix seconds) or
     * PAGES_PER_RUN, each against its own account's saved tracking domain.
     * A page whose check throws is logged and passed over; its turn comes
     * round again.
     *
     * @return array{checked: int, failed: int}
     */
    public function recheckDue(int $deadline): array
    {
        $now = ($this->clock)();
        $stmt = $this->conn->prepareWrite(
            'SELECT lp.landing_page_id, lp.user_id, p.user_tracking_domain'
            . ' FROM 202_landing_pages AS lp'
            . ' JOIN 202_users AS u ON u.user_id = lp.user_id AND u.user_deleted = 0 AND u.user_active = 1'
            . ' LEFT JOIN 202_users_pref AS p ON p.user_id = lp.user_id'
            . ' LEFT JOIN 202_landing_page_checks AS k ON k.landing_page_id = lp.landing_page_id AND k.user_id = lp.user_id'
            . " WHERE lp.landing_page_deleted = 0 AND lp.landing_page_url <> ''"
            . ' AND (k.landing_page_id IS NULL OR k.checked_at <= ? OR k.checked_url <> lp.landing_page_url)'
            . ' ORDER BY COALESCE(k.checked_at, 0), lp.landing_page_id LIMIT ' . self::PAGES_PER_RUN
        );
        $this->conn->bind($stmt, 'i', [$now - self::RECHECK_SECONDS]);
        $report = ['checked' => 0, 'failed' => 0];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            if (($this->clock)() >= $deadline) {
                break;
            }
            try {
                $this->run((int) $row['user_id'], (int) $row['landing_page_id'], self::hostOf((string) ($row['user_tracking_domain'] ?? '')));
                $report['checked']++;
            } catch (\Throwable $e) {
                error_log('Landing page code check: page ' . (int) $row['landing_page_id'] . ' could not be checked: ' . $e->getMessage());
                $report['failed']++;
            }
        }

        return $report;
    }

    /** A saved tracking domain's host[:port], lower-cased; '' when none is saved. */
    public static function hostOf(string $trackingDomain): string
    {
        $domain = strtolower(trim($trackingDomain));
        $domain = (string) preg_replace('~^[a-z][a-z0-9+.-]*://~', '', $domain);

        return (string) preg_replace('~[/?#].*$~s', '', $domain);
    }

    /**
     * How a status is shown in a list: the pill's classes and its words.
     *
     * @return array{0: string, 1: string}
     */
    public static function pill(?string $status): array
    {
        return match ($status) {
            LandingPageCheckResult::PRESENT => ['p202-pill p202-pill--good', 'Code found'],
            LandingPageCheckResult::LEGACY => ['p202-pill p202-pill--good', 'Older code found'],
            LandingPageCheckResult::MIXED => ['p202-pill p202-pill--warn', 'Two codes'],
            LandingPageCheckResult::OTHER_TRACKER => ['p202-pill p202-pill--warn', 'Code from another tracker'],
            LandingPageCheckResult::WRONG_ID => ['p202-pill p202-pill--bad', 'Another page\'s code'],
            LandingPageCheckResult::COMMENTED => ['p202-pill p202-pill--bad', 'Code commented out'],
            LandingPageCheckResult::ABSENT => ['p202-pill p202-pill--bad', 'Code not found'],
            LandingPageCheckResult::ERROR => ['p202-pill p202-pill--warn', 'Could not be checked'],
            default => ['p202-pill', 'Not checked yet'],
        };
    }
}
