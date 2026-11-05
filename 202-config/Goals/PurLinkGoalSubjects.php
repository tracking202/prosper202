<?php

declare(strict_types=1);

namespace Prosper202\Goals;

use Prosper202\Click\LatestVisit;
use Prosper202\Conversion\Ledger\MysqlConversionLedger;
use Prosper202\Database\Connection;

/** Stable, isolated goal subjects for the offers on one signed PurLink click. */
final class PurLinkGoalSubjects
{
    public function __construct(private readonly Connection $conn)
    {
    }

    /** Find a retained offer subject and its click for goal re-evaluation. */
    public function find(int $userId, int $subjectId): ?GoalSubject
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT p.subject_id,p.click_id,p.campaign_id,c.click_time FROM 202_purlink_goal_subjects p'
            . ' JOIN 202_clicks c ON c.click_id=p.click_id AND c.user_id=p.user_id'
            . ' LEFT JOIN 202_clicks_rotator r ON r.click_id=c.click_id'
            . ' WHERE p.subject_id=? AND p.user_id=? ' . LatestVisit::orderBy('c', 'r') . ' LIMIT 1'
        );
        $this->conn->bind($stmt, 'ii', [$subjectId, $userId]);
        $row = $this->conn->fetchOne($stmt);
        return $row === null ? null : new GoalSubject(
            GoalSubject::PURLINK, (int) $row['subject_id'], (int) $row['click_time'], null, [],
            (int) $row['click_id'], (int) $row['campaign_id']
        );
    }

    /**
     * Find or create the subject for a validated offer in the caller's click
     * transaction. Locks the click before any reads and checks page, campaign
     * and account together. Its insert rolls back with the event and outcome.
     */
    public function forRouteInTransaction(int $userId, int $clickId, int $pageId, int $campaignId): ?GoalSubject
    {
        return $this->conn->joinTransaction(function () use ($userId, $clickId, $pageId, $campaignId): ?GoalSubject {
            if ($userId < 1 || $clickId < 1 || $pageId < 1 || $campaignId < 1) {
                return null;
            }
            $click = (new MysqlConversionLedger($this->conn))->lockClick($clickId, $userId);
            if ($click === null) {
                return null;
            }
            $stmt = $this->conn->prepareWrite(
                'SELECT lp.landing_page_type, lp.aff_campaign_id FROM ' . LatestVisit::of('?') . ' AS c'
                . ' JOIN 202_landing_pages lp ON lp.landing_page_id=c.landing_page_id AND lp.user_id=c.user_id'
                // A signed SubID proved this route: a page or campaign deleted
                // since still owns the postbacks its clicks bring.
                . ' JOIN 202_aff_campaigns ac ON ac.aff_campaign_id=? AND ac.user_id=c.user_id'
                . ' WHERE c.user_id=? AND lp.landing_page_id=?'
            );
            $this->conn->bind($stmt, 'iiii', [$clickId, $campaignId, $userId, $pageId]);
            $page = $this->conn->fetchOne($stmt);
            if ($page === null || ((int) $page['landing_page_type'] !== 1 && (int) $page['aff_campaign_id'] !== $campaignId)) {
                return null;
            }
            $stmt = $this->conn->prepareWrite(
                'INSERT INTO 202_purlink_goal_subjects (user_id,click_id,landing_page_id,campaign_id) VALUES (?,?,?,?)'
                . ' ON DUPLICATE KEY UPDATE subject_id=subject_id'
            );
            $this->conn->bind($stmt, 'iiii', [$userId, $clickId, $pageId, $campaignId]);
            $this->conn->executeUpdate($stmt);
            $stmt = $this->conn->prepareWrite(
                'SELECT subject_id,user_id FROM 202_purlink_goal_subjects WHERE click_id=? AND landing_page_id=? AND campaign_id=?'
            );
            $this->conn->bind($stmt, 'iii', [$clickId, $pageId, $campaignId]);
            $row = $this->conn->fetchOne($stmt);
            if ($row === null || (int) $row['user_id'] !== $userId || (int) $row['subject_id'] < 1) {
                throw new GoalEngineException('PurLink goal subject could not be owned', GoalEngineException::INTEGRITY);
            }
            return new GoalSubject(GoalSubject::PURLINK, (int) $row['subject_id'], (int) $click['click_time'], null, [], $clickId, $campaignId);
        });
    }
}
