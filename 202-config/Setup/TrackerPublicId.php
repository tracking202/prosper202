<?php

declare(strict_types=1);

namespace Prosper202\Setup;

use Prosper202\Database\Connection;

/**
 * A tracker's public id: the t202id in its link, the only thing a click
 * carries to say which tracker it came through. The click endpoints look a
 * tracker up by it alone (dl.php, the static endpoints, the pixels), so two
 * trackers sharing one make every click on either link an arbitrary pick
 * between them -- another account's campaign, payout and cost included.
 *
 * Nothing kept them apart. Get Links writes a random digit, the tracker's
 * own id and another random digit, which cannot collide with another such
 * id but can with the API's, a random number from 10,000,000 up; the API
 * stored any public id a caller sent, taken or not, on create and on
 * update. Both now go through here: a sent id that another tracker holds is
 * refused, and a generated one is drawn until it is free. Rows that already
 * share an id are left as they are -- their links are in the wild.
 */
final class TrackerPublicId
{
    /** The API's range: above every id Get Links writes for its first 99,999 trackers. */
    public const int MIN_GENERATED = 10_000_000;
    public const int MAX_GENERATED = 999_999_999;

    /** Draws before giving up; at any realistic fill a free id comes first. */
    private const int ATTEMPTS = 20;

    /**
     * Whether a tracker other than $exceptTrackerId holds $publicId, in any
     * account. A failed query throws (Connection's QueryException), never
     * answers "not taken": answering "free" when the check could not run is
     * how the duplicate gets made (CLAUDE.md #11).
     */
    public static function isTaken(\mysqli $db, int $publicId, ?int $exceptTrackerId = null): bool
    {
        // The primary, not a replica: a lagging replica would not yet have
        // the tracker made a moment ago, and would answer "free".
        $conn = new Connection($db);
        $stmt = $conn->prepareWrite('SELECT 1 FROM 202_trackers WHERE tracker_id_public = ? AND tracker_id <> ? LIMIT 1');
        $conn->bind($stmt, 'ii', [$publicId, $exceptTrackerId ?? 0]);

        return $conn->fetchOne($stmt) !== null;
    }

    /**
     * A free id for a tracker the API makes.
     *
     * @param ?callable(int, int): int $random random_int's shape, for tests
     */
    public static function generate(\mysqli $db, ?callable $random = null): int
    {
        $random ??= random_int(...);
        for ($i = 0; $i < self::ATTEMPTS; $i++) {
            $id = $random(self::MIN_GENERATED, self::MAX_GENERATED);
            if (!self::isTaken($db, $id)) {
                return $id;
            }
        }

        throw new \RuntimeException('No free tracker public id after ' . self::ATTEMPTS . ' draws');
    }

    /**
     * A free id for a tracker Get Links makes, in its shape: a digit from 1
     * to 9, the tracker's id, another such digit (it is what every existing
     * link looks like). When every draw is taken -- only possible once API
     * ids fill that shape's numbers -- it falls back to generate().
     *
     * @param ?callable(int, int): int $random random_int's shape, for tests
     */
    public static function forPage(\mysqli $db, int $trackerId, ?callable $random = null): int
    {
        $random ??= random_int(...);
        for ($i = 0; $i < self::ATTEMPTS; $i++) {
            $id = (int) ($random(1, 9) . $trackerId . $random(1, 9));
            if (!self::isTaken($db, $id, $trackerId)) {
                return $id;
            }
        }

        return self::generate($db, $random);
    }
}
