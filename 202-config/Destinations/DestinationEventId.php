<?php

declare(strict_types=1);

namespace Prosper202\Destinations;

use Prosper202\Database\Connection;

/**
 * The one name a conversion goes by on every channel that dedupes on an
 * event id: the destinations' server events and the traffic source's
 * browser pixels (TrafficSourcePixels::EVENT_ID), so a platform that gets
 * both counts the sale once. The conversion's transaction id, else
 * `P202-<conv id>`; a conversion that replaced others (the campaign pays the
 * latest one, or a newer revenue upload) goes by the first one it replaced,
 * which is the name the sale was first sent under.
 */
final class DestinationEventId
{
    private function __construct()
    {
    }

    /** The id for a conversion whose row is in hand and that replaced none. */
    public static function from(?string $transactionId, int $convId): string
    {
        $transactionId = trim((string) $transactionId);

        return $transactionId !== '' ? $transactionId : 'P202-' . $convId;
    }

    /**
     * The id of conversion $convId of click $clickId, read from the ledger
     * through the write handle (a caller may hold the transaction that wrote
     * it). $transactionId is the conversion's own, as recorded.
     */
    public static function of(Connection $conn, int $userId, int $clickId, int $convId, ?string $transactionId): string
    {
        // A recompute points every replaced row at the current winner, so the earliest is the sale's first send.
        $stmt = $conn->prepareWrite("SELECT conv_id, transaction_id FROM 202_conversion_logs
            WHERE click_id = ? AND user_id = ? AND superseded_by = ? AND superseded_reason IN ('replace', 'batch')
            ORDER BY conv_id LIMIT 1");
        $conn->bind($stmt, 'iii', [$clickId, $userId, $convId]);
        $first = $conn->fetchOne($stmt);
        if ($first !== null) {
            return self::from(isset($first['transaction_id']) ? (string) $first['transaction_id'] : null, (int) $first['conv_id']);
        }

        return self::from($transactionId, $convId);
    }
}
