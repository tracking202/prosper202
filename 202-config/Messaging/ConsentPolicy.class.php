<?php
// 202-config/Messaging/ConsentPolicy.class.php
// The single source of truth for whether analytics/marketing may proceed.
// Nothing else reads the raw consent columns or EU status.
//
// Every consent change (and a sign-in that first places the account in the
// EU/UK) counts 202_users_pref.consent_version up in the same statement:
//  - the change is undelivered while consent_version > consent_acked_version,
//    and only the receiver's acknowledgement of a version moves the latter;
//  - analytics_revoked_version is the version at which analytics last
//    stopped, and analytics data captured under an older version is never
//    delivered, whether or not the purge that followed the stop succeeded.

final class ConsentPolicy
{
    /** Set at sign-in when the EU/UK location could not be stored. */
    public const SESSION_GEO_HELD = 'analytics_geo_held';

    /** Pure decision. No I/O. */
    public static function decide(string $stored, ?bool $isEu, string $tier): bool
    {
        if ($tier === 'essential') {
            return true;
        }
        // analytics tier
        if ($stored === 'granted') { return true; }
        if ($stored !== 'unset')   { return false; } // denied, or a value that is neither
        // unset: on by default only where the account is known to be outside
        // the EU/UK; held for the EU/UK and while the location is unknown.
        return $isEu === false;
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
        if (self::sessionHoldsAnalytics($userId)) {
            return ['allowed' => false, 'version' => 0];
        }
        $row = self::loadPref($db, $userId);
        if ($row === null) {
            return null;
        }
        return [
            'allowed' => self::decide($row['analytics_consent'], $row['is_eu'], 'analytics'),
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
     * unacknowledged, the version analytics last stopped at, and whether
     * analytics may leave now. A missing row is the all-unset block at
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
            "SELECT `analytics_consent`, `analytics_consent_source`, `analytics_consent_at`,
                    `email_marketing_consent`, `email_marketing_consent_at`, `analytics_geo_is_eu`,
                    `consent_version`, `consent_acked_version`, `analytics_revoked_version`
               FROM `202_users_pref` WHERE `user_id` = ? LIMIT 1"
        );
        $conn->bind($stmt, 'i', [$userId]);
        $row = $conn->fetchOne($stmt) ?? [];

        $states = ['granted', 'denied', 'unset'];
        $analytics = in_array($row['analytics_consent'] ?? '', $states, true) ? (string) $row['analytics_consent'] : 'unset';
        $isEu = !isset($row['analytics_geo_is_eu']) ? null : ((int) $row['analytics_geo_is_eu']) === 1;
        $version = (int) ($row['consent_version'] ?? 0);

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
            'dirty' => $version > (int) ($row['consent_acked_version'] ?? 0),
            'revoked' => (int) ($row['analytics_revoked_version'] ?? 0),
            'analytics_allowed' => !self::sessionHoldsAnalytics($userId) && self::decide($analytics, $isEu, 'analytics'),
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

    public static function needsEuPrompt(mysqli $db, int $userId): bool
    {
        $row = self::loadPref($db, $userId);
        // Lookup failure → no prompt (fail closed; analytics is held anyway).
        return $row !== null
            && $row['is_eu'] === true
            && $row['analytics_consent'] === 'unset'
            && $row['prompt_seen'] === false;
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
            // The choice, its version and (on a denial) the revocation mark land
            // in one statement. The revocation is assigned before the version
            // so it reads the old one under either assignment order.
            if ($flag === 'analytics') {
                $stmt = $conn->prepareWrite(
                    "UPDATE `202_users_pref`
                        SET `analytics_revoked_version` = IF(? = 'denied', `consent_version` + 1, `analytics_revoked_version`),
                            `consent_version` = `consent_version` + 1,
                            `analytics_consent` = ?, `analytics_consent_at` = ?,
                            `analytics_consent_source` = ?, `eu_consent_prompt_seen` = 1
                      WHERE `user_id` = ?"
                );
                $conn->bind($stmt, 'ssssi', [$state, $state, date('Y-m-d H:i:s'), $source, $userId]);
            } else {
                $stmt = $conn->prepareWrite(
                    "UPDATE `202_users_pref`
                        SET `consent_version` = `consent_version` + 1,
                            `email_marketing_consent` = ?, `email_marketing_consent_at` = ?
                      WHERE `user_id` = ?"
                );
                $conn->bind($stmt, 'ssi', [$state, date('Y-m-d H:i:s'), $userId]);
            }
            // The row exists (above), so a write that changed nothing is still
            // a success; a failed write throws.
            $conn->executeUpdate($stmt);
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

    public static function rememberGeo(mysqli $db, int $userId, bool $isEu): bool
    {
        try {
            $conn = new \Prosper202\Database\Connection($db);
            // Without a row the location would be dropped, and the account held
            // as unknown at every sign-in.
            $ensure = $conn->prepareWrite('INSERT IGNORE INTO `202_users_pref` (`user_id`) VALUES (?)');
            $conn->bind($ensure, 'i', [$userId]);
            $conn->executeUpdate($ensure);
            // Once seen in the EU/UK an account stays so: one sign-in from
            // elsewhere (travel, a VPN) must not turn its held analytics on.
            // First seen there, what was captured under the non-EU default is
            // revoked unless analytics was granted outright.
            $stmt = $conn->prepareWrite(
                "UPDATE `202_users_pref`
                    SET `analytics_revoked_version` = IF(? = 1 AND NOT (`analytics_geo_is_eu` <=> 1) AND `analytics_consent` <> 'granted',
                                                         `consent_version` + 1, `analytics_revoked_version`),
                        `consent_version` = `consent_version` + IF(? = 1 AND NOT (`analytics_geo_is_eu` <=> 1), 1, 0),
                        `analytics_geo_is_eu` = IF(`analytics_geo_is_eu` = 1, 1, ?)
                  WHERE `user_id` = ?"
            );
            $eu = $isEu ? 1 : 0;
            $conn->bind($stmt, 'iiii', [$eu, $eu, $eu, $userId]);
            $conn->executeUpdate($stmt);
            return true;
        } catch (\Throwable $e) {
            error_log('[ConsentPolicy] rememberGeo failed: ' . $e->getMessage());
            return false;
        }
    }

    /** True when this session's sign-in placed the account in the EU/UK but could not store it. */
    private static function sessionHoldsAnalytics(int $userId): bool
    {
        return isset($_SESSION)
            && ($_SESSION[self::SESSION_GEO_HELD] ?? false) === true
            && (int) ($_SESSION['user_id'] ?? 0) === $userId;
    }

    /**
     * Load the stored consent state. Returns NULL on any lookup failure so
     * callers fail CLOSED (spec §6: failure is never an accidental "granted").
     * A genuine missing row is not a failure: consent unset and the location
     * unknown, which holds analytics like any account not yet placed.
     *
     * @return array{analytics_consent:string,email_marketing_consent:string,is_eu:?bool,prompt_seen:bool,version:int}|null
     */
    private static function loadPref(mysqli $db, int $userId): ?array
    {
        $default = [
            'analytics_consent' => 'unset',
            'email_marketing_consent' => 'unset',
            'is_eu' => null,
            'prompt_seen' => false,
            'version' => 0,
        ];
        try {
            $conn = new \Prosper202\Database\Connection($db);
            $stmt = $conn->prepareRead(
                "SELECT `analytics_consent`, `email_marketing_consent`, `analytics_geo_is_eu`, `eu_consent_prompt_seen`,
                        `consent_version`
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
            'is_eu' => $row['analytics_geo_is_eu'] === null ? null : ((int) $row['analytics_geo_is_eu']) === 1,
            'prompt_seen' => ((int) ($row['eu_consent_prompt_seen'] ?? 0)) === 1,
            'version' => (int) ($row['consent_version'] ?? 0),
        ];
    }
}
