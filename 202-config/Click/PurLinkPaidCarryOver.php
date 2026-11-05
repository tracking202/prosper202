<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Conversion\Ledger\MysqlConversionLedger;
use Prosper202\Database\Connection;

/**
 * Paid 1.9.56 recorded a PurLink click-out in 202_clicks_purlink (purl.php:
 * click_id, lpip = the page's public id, acip = the offer's) and left the
 * click's campaign as it was; the offer got `<click>-<acip>` as its SubID.
 * Such a SubID now converts only a click already on that offer
 * (PurLinkLegacySubid, pb.php), so the upgrade records each click's latest
 * pending click-out as a PurLink exit is recorded now: the click routed to
 * the offer, and an exit-order row older than any browser report. The old
 * table is only read.
 */
final class PurLinkPaidCarryOver
{
    /** How long paid 1.9.56 meant to keep a pending row (its cron purge). */
    public const WINDOW = 90 * 24 * 60 * 60;

    private const BATCH = 500;

    private const COLUMNS = ['id', 'click_id', 'acip', 'lpip', 'click_lead', 'click_time'];

    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * Carry every pending row of the window. Run again, a carried click is
     * left as it is. Throws on a database failure; rows carried before it
     * stay carried.
     *
     * @return array<string, int> outcome => rows, only outcomes that occurred;
     *         ['unknown_shape' => 1] when the table is not paid 1.9.56's
     */
    public function carry(int $now): array
    {
        if (!$this->hasPaidShape()) {
            return ['unknown_shape' => 1];
        }
        $since = $now - self::WINDOW;
        $counts = [];
        $expired = $this->conn->prepareWrite(
            'SELECT COUNT(*) AS n FROM 202_clicks_purlink WHERE click_lead = 0 AND click_time < ?'
        );
        $this->conn->bind($expired, 'i', [$since]);
        $n = (int) ($this->conn->fetchOne($expired)['n'] ?? 0);
        if ($n > 0) {
            $counts['expired'] = $n;
        }

        // Walked by id: every row read leaves the next batch's selection (CLAUDE.md #37).
        $cursor = 0;
        do {
            $stmt = $this->conn->prepareWrite(
                'SELECT p.id, p.click_id, p.acip, p.lpip, p.click_time, EXISTS (SELECT 1 FROM 202_clicks_purlink AS n'
                    . ' WHERE n.click_id = p.click_id AND n.click_lead = 0 AND n.id > p.id) AS superseded'
                    . ' FROM 202_clicks_purlink AS p FORCE INDEX (PRIMARY)'
                    . ' WHERE p.id > ? AND p.click_lead = 0 AND p.click_time >= ? ORDER BY p.id LIMIT ' . self::BATCH
            );
            $this->conn->bind($stmt, 'ii', [$cursor, $since]);
            $rows = $this->conn->fetchAll($stmt);
            foreach ($rows as $row) {
                $cursor = (int) $row['id'];
                $outcome = (int) $row['superseded'] === 1 ? 'superseded' : $this->carryRow(
                    (int) $row['click_id'],
                    (int) $row['lpip'],
                    (int) $row['acip'],
                    (int) $row['click_time']
                );
                $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
            }
        } while (count($rows) === self::BATCH);

        return $counts;
    }

    private function hasPaidShape(): bool
    {
        $stmt = $this->conn->prepareWrite('SHOW COLUMNS FROM 202_clicks_purlink');
        $columns = [];
        foreach ($this->conn->fetchAll($stmt) as $column) {
            $columns[(string) $column['Field']] = (string) $column['Key'];
        }

        return ($columns['id'] ?? '') === 'PRI' && array_diff(self::COLUMNS, array_keys($columns)) === [];
    }

    private function carryRow(int $clickId, int $pagePublicId, int $offerPublicId, int $exitTime): string
    {
        if ($clickId < 1 || $pagePublicId < 1) {
            return 'unmappable';
        }
        $work = fn (): string => (new MysqlConversionLedger($this->conn))->clickTransaction(
            $clickId,
            null,
            fn (?array $click): string => $click === null
                ? 'no_click'
                : $this->carryLocked($click, $pagePublicId, $offerPublicId, $exitTime)
        );
        try {
            return $work();
        } catch (\Throwable $error) {
            if (!Connection::isRetryableLockError($error)) {
                throw $error;
            }
            return $work();
        }
    }

    /** @param array<string, mixed> $click the locked click's latest visit */
    private function carryLocked(array $click, int $pagePublicId, int $offerPublicId, int $exitTime): string
    {
        $clickId = (int) $click['click_id'];
        $userId = (int) $click['user_id'];
        if ((int) $click['click_lead'] === 1) {
            return 'converted';
        }
        // The row's page must be the click's own live page, of the click's account.
        $stmt = $this->conn->prepareWrite(
            'SELECT c.landing_page_id, lp.landing_page_type, lp.aff_campaign_id FROM ' . LatestVisit::of('?') . ' AS c'
                . ' JOIN 202_landing_pages AS lp ON lp.landing_page_id = c.landing_page_id AND lp.user_id = c.user_id'
                . ' AND lp.landing_page_deleted = 0 WHERE lp.landing_page_id_public = ?'
        );
        $this->conn->bind($stmt, 'ii', [$clickId, $pagePublicId]);
        $page = $this->conn->fetchOne($stmt);
        if ($page === null) {
            return 'other_page';
        }
        // An advanced page sends any live offer of its account; a simple page only its own.
        $advanced = (int) $page['landing_page_type'] === 1;
        if ($advanced && $offerPublicId < 1) {
            return 'no_offer';
        }
        $stmt = $this->conn->prepareWrite(
            'SELECT aff_campaign_id, aff_campaign_id_public, aff_campaign_payout FROM 202_aff_campaigns'
                . ' WHERE ' . ($advanced ? 'aff_campaign_id_public' : 'aff_campaign_id') . ' = ? AND user_id = ?'
                . ' AND aff_campaign_deleted = 0 LIMIT 1'
        );
        $this->conn->bind($stmt, 'ii', [$advanced ? $offerPublicId : (int) $page['aff_campaign_id'], $userId]);
        $offer = $this->conn->fetchOne($stmt);
        if ($offer === null || ($offerPublicId > 0 && (int) $offer['aff_campaign_id_public'] !== $offerPublicId)) {
            return 'no_offer';
        }

        $stmt = $this->conn->prepareWrite('SELECT sequence FROM 202_purlink_exit_order WHERE click_id = ?');
        $this->conn->bind($stmt, 'i', [$clickId]);
        $prior = $this->conn->fetchOne($stmt);
        if ($prior !== null) {
            return (int) $prior['sequence'] === 0 ? 'already_carried' : 'newer_exit';
        }

        $landingPageId = (int) $page['landing_page_id'];
        $campaignId = (int) $offer['aff_campaign_id'];
        $recorded = (new MysqlClickRepository($this->conn))->recordExitInTransaction(new ClickExit(
            $userId,
            $clickId,
            offer: new OfferRoute($campaignId, (string) $offer['aff_campaign_payout']),
            cloaking: 0,
            landingPageId: $landingPageId,
        ));
        if (!$recorded) {
            return 'refused';
        }
        // Sequence 0: any browser report (1 and up) for this click is newer.
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_purlink_exit_order (click_id,user_id,landing_page_id,sequence,campaign_id,expires_at)'
                . ' VALUES (?,?,?,0,?,?)'
        );
        $this->conn->bind($stmt, 'iiiii', [
            $clickId, $userId, $landingPageId, $campaignId, $exitTime + PurLinkProof::MAX_LIFETIME,
        ]);
        if ($this->conn->executeUpdate($stmt) !== 1) {
            throw new \UnexpectedValueException("Click {$clickId}'s carried exit order was not written");
        }

        return 'carried';
    }
}
