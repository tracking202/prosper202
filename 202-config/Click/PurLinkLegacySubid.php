<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Database\Connection;

/** Resolve an unsigned historical click-campaign SubID without changing its route. */
final class PurLinkLegacySubid
{
    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * Only the click's latest visit's own campaign can be credited. An old
     * SubID is not proof that another campaign may be assigned to the click.
     *
     * @return array{click_id: int, user_id: int, campaign_id: int}|null
     */
    public function resolve(string $subid): ?array
    {
        $ids = PurLinkSubid::legacy($subid);
        if ($ids === null) {
            return null;
        }
        $stmt = $this->conn->prepareWrite(
            'SELECT c.click_id, c.user_id, ac.aff_campaign_id FROM ' . LatestVisit::of('?') . ' AS c'
                . ' JOIN 202_aff_campaigns AS ac ON ac.aff_campaign_id = c.aff_campaign_id'
                . ' AND ac.user_id = c.user_id WHERE ac.aff_campaign_id_public = ?'
        );
        $this->conn->bind($stmt, 'ii', [$ids['click_id'], $ids['campaign_public_id']]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            return null;
        }

        return [
            'click_id' => (int) $row['click_id'],
            'user_id' => (int) $row['user_id'],
            'campaign_id' => (int) $row['aff_campaign_id'],
        ];
    }
}
