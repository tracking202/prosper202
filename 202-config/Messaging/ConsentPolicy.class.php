<?php
// 202-config/Messaging/ConsentPolicy.class.php
// The single source of truth for whether analytics/marketing may proceed.
// Nothing else reads the raw consent columns.
//
// Usage data is opt-in: nothing is recorded or sent for a user until they
// grant analytics themselves, wherever they are. An unset choice is off.
//
// Every consent change moves 202_users_pref.consent_version up in the same
// statement, to at least the current Unix time, so a database restored from a
// backup never reuses a version the receiver already holds:
//  - the change is undelivered while consent_version > consent_acked_version,
//    and only the receiver's acknowledgement of a version moves the latter;
//  - analytics_revoked_version is the version at which analytics last
//    stopped or started, and data captured under an older version is never
//    delivered, whether or not the purge that followed a stop succeeded.

final class ConsentPolicy
{
    /** The next consent version: above the stored one, and at least the current Unix time. */
    private const NEXT_VERSION = 'GREATEST(`consent_version` + 1, UNIX_TIMESTAMP())';

    /** SQL true when a user's usage data may leave: they said yes. Over `202_users_pref` as `p`; no row is false. */
    public const ANALYTICS_ON_SQL = "p.`analytics_consent` = 'granted'";

    /**
     * SQL true when a user's consent block may go to the portal: something is
     * granted, or the portal may already hold something of theirs (so a later
     * "no" reaches it): it acknowledged a block, or a call was made for them
     * (202_messaging_sync is written before each one, so a yes whose
     * acknowledgement was lost still counts). Over `202_users_pref` as `p`; a
     * missing row is NULL, which is false.
     */
    public const BLOCK_SHAREABLE_SQL = "(p.`analytics_consent` = 'granted' OR p.`email_marketing_consent` = 'granted'"
        . ' OR p.`consent_acked_version` > 0'
        . ' OR EXISTS (SELECT 1 FROM `202_messaging_sync` s WHERE s.`user_id` = p.`user_id` AND s.`last_sync` IS NOT NULL))';

    /** Pure decision. No I/O. Only an explicit grant allows, whatever the tier. */
    public static function decide(string $stored): bool
    {
        return $stored === 'granted';
    }

    public static function analyticsAllowed(mysqli $db, int $userId): bool
    {
        $fence = self::captureFence($db, $userId);
        return $fence !== null && $fence['allowed'];
    }

    /**
     * Whether analytics may be captured now, and the consent version to stamp
     * the capture with, from one read. Null when the read failed (fail closed).
     *
     * @return array{allowed:bool,version:int}|null
     */
    public static function captureFence(mysqli $db, int $userId): ?array
    {
        $row = self::loadPref($db, $userId);
        if ($row === null) {
            return null;
        }
        return [
            'allowed' => self::decide($row['analytics_consent']),
            'version' => $row['version'],
        ];
    }

    public static function emailMarketingAllowed(mysqli $db, int $userId): bool
    {
        $row = self::loadPref($db, $userId);
        // Marketing email never goes out on a bare default — require explicit
        // grant. A lookup failure ($row === null) therefore also denies.
        return $row !== null && $row['email_marketing_consent'] === 'granted';
    }

    /**
     * What a delivery sends and checks, from one read: the consent block
     * (receiver spec §6), its version, whether that version is still
     * unacknowledged and may be shared (BLOCK_SHAREABLE_SQL: a user who never
     * opted in to anything, and for whom no call was ever made, is not
     * announced), the version analytics last stopped or started at, and
     * whether analytics may leave now. A missing row is the all-unset block at
     * version 0; a failed read throws, so a delivery never sends (or
     * acknowledges) a stand-in for a choice it could not read.
     *
     * @return array{consent:array{analytics:string,analytics_source:?string,analytics_at:?string,email_marketing:string,email_marketing_at:?string},version:int,dirty:bool,revoked:int,analytics_allowed:bool}
     * @throws \Throwable when the preferences cannot be read
     */
    public static function deliveryState(mysqli $db, int $userId): array
    {
        $conn = new \Prosper202\Database\Connection($db);
        $stmt = $conn->prepareRead(
            "SELECT p.`analytics_consent`, p.`analytics_consent_source`, p.`analytics_consent_at`,
                    p.`email_marketing_consent`, p.`email_marketing_consent_at`,
                    p.`consent_version`, p.`consent_acked_version`, p.`analytics_revoked_version`,
                    " . self::BLOCK_SHAREABLE_SQL . " AS `shareable`
               FROM `202_users_pref` p WHERE p.`user_id` = ? LIMIT 1"
        );
        $conn->bind($stmt, 'i', [$userId]);
        $row = $conn->fetchOne($stmt) ?? [];

        $states = ['granted', 'denied', 'unset'];
        // A value that is none of the three (an enum write the server
        // blanked) holds analytics, as decide() does; the block says unset.
        $stored = $row === [] ? 'unset' : (string) ($row['analytics_consent'] ?? 'unset');
        $analytics = in_array($stored, $states, true) ? $stored : 'unset';
        $version = (int) ($row['consent_version'] ?? 0);
        $shareable = ((int) ($row['shareable'] ?? 0)) === 1;

        return [
            'consent' => [
                'analytics'          => $analytics,
                'analytics_source'   => isset($row['analytics_consent_source']) ? (string) $row['analytics_consent_source'] : null,
                'analytics_at'       => isset($row['analytics_consent_at']) ? (string) $row['analytics_consent_at'] : null,
                'email_marketing'    => in_array($row['email_marketing_consent'] ?? '', $states, true)
                    ? (string) $row['email_marketing_consent'] : 'unset',
                'email_marketing_at' => isset($row['email_marketing_consent_at']) ? (string) $row['email_marketing_consent_at'] : null,
            ],
            'version' => $version,
            'dirty' => $shareable && $version > (int) ($row['consent_acked_version'] ?? 0),
            'revoked' => (int) ($row['analytics_revoked_version'] ?? 0),
            'analytics_allowed' => self::decide($stored),
        ];
    }

    /**
     * Record that the receiver stored consent $version. A newer change made
     * meanwhile stays unacknowledged.
     *
     * @throws \Throwable when the write fails
     */
    public static function acknowledgeDelivery(mysqli $db, int $userId, int $version): void
    {
        $conn = new \Prosper202\Database\Connection($db);
        $stmt = $conn->prepareWrite(
            'UPDATE `202_users_pref` SET `consent_acked_version` = GREATEST(`consent_acked_version`, ?) WHERE `user_id` = ?'
        );
        $conn->bind($stmt, 'ii', [$version, $userId]);
        $conn->executeUpdate($stmt);
    }

    /**
     * Whether to show this user the one-time usage-data question now: they
     * have made no analytics choice and have not been asked before. True at
     * most once per user: the question is marked asked in the same statement
     * that finds it unasked, so it never comes back, answered or not (the
     * choice stays in Account Settings). Any failure is false, no question.
     * (`eu_consent_prompt_seen` is named for when only EU/UK accounts were asked.)
     */
    public static function takePrompt(mysqli $db, int $userId): bool
    {
        try {
            $conn = new \Prosper202\Database\Connection($db);
            // Read first, so the pages of a user already asked write nothing.
            $read = $conn->prepareRead(
                'SELECT `analytics_consent`, `eu_consent_prompt_seen` FROM `202_users_pref` WHERE `user_id` = ? LIMIT 1'
            );
            $conn->bind($read, 'i', [$userId]);
            $row = $conn->fetchOne($read);
            if ($row !== null
                && ((int) $row['eu_consent_prompt_seen'] === 1 || $row['analytics_consent'] !== 'unset')) {
                return false;
            }
            if ($row === null) {
                $ensure = $conn->prepareWrite('INSERT IGNORE INTO `202_users_pref` (`user_id`) VALUES (?)');
                $conn->bind($ensure, 'i', [$userId]);
                $conn->executeUpdate($ensure);
            }
            $stmt = $conn->prepareWrite(
                "UPDATE `202_users_pref` SET `eu_consent_prompt_seen` = 1
                  WHERE `user_id` = ? AND `eu_consent_prompt_seen` = 0 AND `analytics_consent` = 'unset'"
            );
            $conn->bind($stmt, 'i', [$userId]);

            return $conn->executeUpdate($stmt) === 1;
        } catch (\Throwable $e) {
            error_log('[ConsentPolicy] takePrompt failed: ' . $e->getMessage());
            return false;
        }
    }

    public static function record(mysqli $db, int $userId, string $flag, string $state, string $source): bool
    {
        if (!in_array($flag, ['analytics','email_marketing'], true)) { return false; }
        if (!in_array($state, ['granted','denied'], true)) { return false; }
        try {
            $conn = new \Prosper202\Database\Connection($db);
            // An account without a preferences row gets one, so its choice is
            // stored rather than lost.
            $ensure = $conn->prepareWrite('INSERT IGNORE INTO `202_users_pref` (`user_id`) VALUES (?)');
            $conn->bind($ensure, 'i', [$userId]);
            $conn->executeUpdate($ensure);
            // The choice, its version and the fence land in one statement. A
            // denial, and a grant that turns analytics on, move the fence to
            // the new version: nothing captured before the choice is ever
            // delivered. The fence is assigned before the version and the
            // choice, so it reads the old ones under either assignment order.
            if ($flag === 'analytics') {
                $stmt = $conn->prepareWrite(
                    "UPDATE `202_users_pref`
                        SET `analytics_revoked_version` = IF(? = 'denied' OR `analytics_consent` <> 'granted', " . self::NEXT_VERSION . ", `analytics_revoked_version`),
                            `consent_version` = " . self::NEXT_VERSION . ",
                            `analytics_consent` = ?, `analytics_consent_at` = ?,
                            `analytics_consent_source` = ?, `eu_consent_prompt_seen` = 1
                      WHERE `user_id` = ?"
                );
                $conn->bind($stmt, 'ssssi', [$state, $state, date('Y-m-d H:i:s'), $source, $userId]);
            } else {
                $stmt = $conn->prepareWrite(
                    "UPDATE `202_users_pref`
                        SET `consent_version` = " . self::NEXT_VERSION . ",
                            `email_marketing_consent` = ?, `email_marketing_consent_at` = ?
                      WHERE `user_id` = ?"
                );
                $conn->bind($stmt, 'ssi', [$state, date('Y-m-d H:i:s'), $userId]);
            }
            // The row exists (above) and the version always moves, so a write
            // that changed no row means the choice was not stored.
            if ($conn->executeUpdate($stmt) !== 1) {
                error_log('[ConsentPolicy] record changed no row for user ' . $userId);
                return false;
            }
        } catch (\Throwable $e) {
            error_log('[ConsentPolicy] record failed: ' . $e->getMessage());
            return false;
        }

        if ($flag === 'analytics' && $state === 'denied') {
            // Spec §9: delete the analytics data that has not left the install.
            // Best effort: what a failed purge leaves is older than the
            // revocation mark, so no delivery sends it.
            try {
                require_once __DIR__ . '/MessagingService.class.php';
                if (!MessagingService::purgeAnalyticsData($db, $userId)) {
                    error_log('[ConsentPolicy] analytics purge on denial incomplete for user ' . $userId);
                }
            } catch (\Throwable $e) {
                error_log('[ConsentPolicy] analytics purge on denial failed: ' . $e->getMessage());
            }
        }

        return true;
    }

    /**
     * Load the stored consent state. Returns NULL on any lookup failure so
     * callers fail CLOSED (spec §6: failure is never an accidental "granted").
     * A genuine missing row is not a failure: every choice unset, which is off.
     *
     * @return array{analytics_consent:string,email_marketing_consent:string,version:int}|null
     */
    private static function loadPref(mysqli $db, int $userId): ?array
    {
        $default = [
            'analytics_consent' => 'unset',
            'email_marketing_consent' => 'unset',
            'version' => 0,
        ];
        try {
            $conn = new \Prosper202\Database\Connection($db);
            $stmt = $conn->prepareRead(
                "SELECT `analytics_consent`, `email_marketing_consent`, `consent_version`
                   FROM `202_users_pref` WHERE `user_id` = ? LIMIT 1"
            );
            $conn->bind($stmt, 'i', [$userId]);
            $row = $conn->fetchOne($stmt);
        } catch (\Throwable $e) {
            error_log('[ConsentPolicy] loadPref failed: ' . $e->getMessage());
            return null;
        }
        if (!$row) { return $default; }
        return [
            'analytics_consent' => $row['analytics_consent'] ?? 'unset',
            'email_marketing_consent' => $row['email_marketing_consent'] ?? 'unset',
            'version' => (int) ($row['consent_version'] ?? 0),
        ];
    }
}
