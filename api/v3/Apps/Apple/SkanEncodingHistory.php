<?php

declare(strict_types=1);

namespace Api\V3\Apps\Apple;

use Api\V3\Support\MysqliStatements;

/**
 * The write side of SKAN encoding versions (SkanEncodingTimeline): before an
 * encoding's meaning is replaced or removed, the meaning it had is copied to
 * 202_app_skan_encoding_history with the time it stopped applying.
 *
 * Every path that edits or removes an encoding goes through here — the
 * encodings API (update, delete), and deleting a registration, which removes
 * its encodings — so the history is complete; SkanEncodingHistoryWritersTest
 * holds the list.
 *
 * Each copy is one INSERT … SELECT: it copies the row as it stands when the
 * statement runs, not a value the request read earlier. It runs before the
 * statement that replaces the row, so a failure of that statement leaves the
 * history holding a meaning that is still current — the same meaning twice,
 * which decodes exactly as once — and never a replaced meaning with no
 * record.
 *
 * Each copy also records the App Store id of the registration the encoding
 * belonged to (`app_id`; 0 for the account-wide set), read in the same
 * statement: the report decodes by app, not by registration id, because a
 * deleted registration's postbacks lose their registration id and a new
 * registration of the same app gets another one (SkanEncodingTimeline). A
 * registration-scoped encoding whose registration cannot be read as an iOS
 * app stores NULL, which the report refuses to guess at — it logs the row
 * and lets it match no postback, rather than reading it as account-wide.
 */
final class SkanEncodingHistory
{
    use MysqliStatements;

    private const COPY = "INSERT INTO 202_app_skan_encoding_history
            (encoding_id, user_id, registration_id, app_id, fine_value, coarse_value, goal_id, revenue_override, effective_at, retired_at)
        SELECT e.encoding_id, e.user_id, e.registration_id,
            CASE WHEN e.registration_id = 0 THEN 0
                 WHEN r.app_key REGEXP '^[1-9][0-9]{0,18}$' THEN CAST(r.app_key AS UNSIGNED)
                 ELSE NULL END,
            e.fine_value, e.coarse_value, e.goal_id, e.revenue_override, e.effective_at, ?
        FROM 202_app_skan_encodings e
        LEFT JOIN 202_app_registrations r
            ON r.registration_id = e.registration_id AND r.user_id = e.user_id AND r.platform = 'ios'";

    public function __construct(private readonly \mysqli $db)
    {
    }

    /** Keep one encoding's current meaning, retired at $at. */
    public function retireEncoding(int $userId, int $encodingId, int $at): void
    {
        $stmt = $this->prepare(self::COPY . ' WHERE e.encoding_id = ? AND e.user_id = ?');
        $this->bind($stmt, 'iii', $at, $encodingId, $userId);
        $this->execute($stmt, 'Encoding history write failed');
        $stmt->close();
    }

    /** Keep the current meaning of every encoding of a registration. */
    public function retireRegistration(int $userId, int $registrationId, int $at): void
    {
        $stmt = $this->prepare(self::COPY . ' WHERE e.registration_id = ? AND e.user_id = ?');
        $this->bind($stmt, 'iii', $at, $registrationId, $userId);
        $this->execute($stmt, 'Encoding history write failed');
        $stmt->close();
    }
}
