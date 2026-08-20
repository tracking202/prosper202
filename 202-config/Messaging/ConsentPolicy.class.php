<?php
// 202-config/Messaging/ConsentPolicy.class.php
// The single source of truth for whether analytics/marketing may proceed.
// Nothing else reads the raw consent columns or EU status.

final class ConsentPolicy
{
    /** Pure decision. No I/O. */
    public static function decide(string $stored, bool $isEu, string $tier): bool
    {
        if ($tier === 'essential') {
            return true;
        }
        // analytics tier
        if ($stored === 'granted') { return true; }
        if ($stored === 'denied')  { return false; }
        // unset → on-by-default for non-EU, held for EU
        return !$isEu;
    }

    public static function analyticsAllowed(mysqli $db, int $userId): bool
    {
        $row = self::loadPref($db, $userId);
        if ($row === null) {
            // Fail CLOSED (spec §6): a lookup FAILURE must never resolve to
            // "granted" — the stored state we could not read might be EU or an
            // explicit denial. Only a genuine no-row/unknown-geo state gets the
            // non-EU on-by-default treatment (handled inside loadPref).
            return false;
        }
        return self::decide($row['analytics_consent'], $row['is_eu'], 'analytics');
    }

    public static function emailMarketingAllowed(mysqli $db, int $userId): bool
    {
        $row = self::loadPref($db, $userId);
        // Marketing email never goes out on a bare default — require explicit
        // grant. A lookup failure ($row === null) therefore also denies.
        return $row !== null && $row['email_marketing_consent'] === 'granted';
    }

    /**
     * Consent state as transmitted to the central server (receiver spec §6
     * CONTRACT DELTA). Operational bookkeeping — exported for ALL users so
     * the server learns denials too. On any lookup failure OR a genuine
     * missing row the all-unset shape (nulls for source/timestamps) is
     * returned: absence must read as "no grant" server-side (fail closed),
     * so a failure can never masquerade as a grant on the wire.
     *
     * Failure and missing-row take the same fail-closed shape but stay
     * distinguished below, mirroring loadPref's raw-mysqli checks: a false
     * get_result() on a SELECT is a fetch FAILURE (server gone away
     * mid-fetch, mysqlnd OOM) and is logged as one — a successful SELECT
     * always yields a mysqli_result, even with zero rows.
     *
     * @return array{analytics:string,analytics_source:?string,analytics_at:?string,email_marketing:string,email_marketing_at:?string}
     */
    public static function exportForSync(mysqli $db, int $userId): array
    {
        $unset = [
            'analytics'          => 'unset',
            'analytics_source'   => null,
            'analytics_at'       => null,
            'email_marketing'    => 'unset',
            'email_marketing_at' => null,
        ];
        try {
            $stmt = $db->prepare(
                "SELECT `analytics_consent`, `analytics_consent_source`, `analytics_consent_at`,
                        `email_marketing_consent`, `email_marketing_consent_at`
                   FROM `202_users_pref` WHERE `user_id` = ? LIMIT 1"
            );
            if ($stmt === false) {
                error_log('[ConsentPolicy] exportForSync prepare failed: ' . $db->error);
                return $unset;
            }
            $stmt->bind_param('i', $userId);
            if (!$stmt->execute()) {
                error_log('[ConsentPolicy] exportForSync execute failed: ' . $stmt->error);
                $stmt->close();
                return $unset;
            }
            $res = $stmt->get_result();
            if ($res === false) {
                // Fetch FAILURE, not a missing row (see docblock).
                error_log('[ConsentPolicy] exportForSync get_result failed: ' . $stmt->error);
                $stmt->close();
                return $unset;
            }
            $row = $res->fetch_assoc();
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('[ConsentPolicy] exportForSync failed: ' . $e->getMessage());
            return $unset;
        }
        // Genuine missing row: the user truly never answered — same
        // fail-closed all-unset shape, via the no-row path.
        if (!$row) { return $unset; }

        $states = ['granted', 'denied', 'unset'];
        return [
            'analytics'          => in_array($row['analytics_consent'] ?? '', $states, true)
                ? (string) $row['analytics_consent'] : 'unset',
            'analytics_source'   => isset($row['analytics_consent_source']) ? (string) $row['analytics_consent_source'] : null,
            'analytics_at'       => isset($row['analytics_consent_at']) ? (string) $row['analytics_consent_at'] : null,
            'email_marketing'    => in_array($row['email_marketing_consent'] ?? '', $states, true)
                ? (string) $row['email_marketing_consent'] : 'unset',
            'email_marketing_at' => isset($row['email_marketing_consent_at']) ? (string) $row['email_marketing_consent_at'] : null,
        ];
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
            if ($flag === 'analytics') {
                $sql = "UPDATE `202_users_pref`
                           SET `analytics_consent` = ?, `analytics_consent_at` = NOW(),
                               `analytics_consent_source` = ?, `eu_consent_prompt_seen` = 1
                         WHERE `user_id` = ?";
                $stmt = $db->prepare($sql);
                if ($stmt === false) {
                    error_log('[ConsentPolicy] record prepare failed: ' . $db->error);
                    return false;
                }
                $stmt->bind_param('ssi', $state, $source, $userId);
            } else {
                $sql = "UPDATE `202_users_pref`
                           SET `email_marketing_consent` = ?, `email_marketing_consent_at` = NOW()
                         WHERE `user_id` = ?";
                $stmt = $db->prepare($sql);
                if ($stmt === false) {
                    error_log('[ConsentPolicy] record prepare failed: ' . $db->error);
                    return false;
                }
                $stmt->bind_param('si', $state, $userId);
            }
            $ok = $stmt->execute();
            if (!$ok) {
                error_log('[ConsentPolicy] record execute failed: ' . $stmt->error);
            }
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('[ConsentPolicy] record failed: ' . $e->getMessage());
            return false;
        }

        if ($ok && $flag === 'analytics' && $state === 'denied') {
            // Revocation must also stop previously collected analytics data
            // from ever leaving the install (spec §9): purge undelivered
            // analytics-tier events and the attribute snapshot. Best-effort —
            // the transport boundary re-checks consent regardless.
            try {
                require_once __DIR__ . '/MessagingService.class.php';
                if (!MessagingService::purgeAnalyticsData($db, $userId)) {
                    error_log('[ConsentPolicy] analytics purge on denial incomplete for user ' . $userId);
                }
            } catch (\Throwable $e) {
                error_log('[ConsentPolicy] analytics purge on denial failed: ' . $e->getMessage());
            }
        }

        return (bool) $ok;
    }

    public static function rememberGeo(mysqli $db, int $userId, bool $isEu): bool
    {
        try {
            $stmt = $db->prepare("UPDATE `202_users_pref` SET `analytics_geo_is_eu` = ? WHERE `user_id` = ?");
            if ($stmt === false) {
                error_log('[ConsentPolicy] rememberGeo prepare failed: ' . $db->error);
                return false;
            }
            $v = $isEu ? 1 : 0;
            $stmt->bind_param('ii', $v, $userId);
            $ok = $stmt->execute();
            if (!$ok) {
                error_log('[ConsentPolicy] rememberGeo execute failed: ' . $stmt->error);
            }
            $stmt->close();
            return (bool) $ok;
        } catch (\Throwable $e) {
            error_log('[ConsentPolicy] rememberGeo failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Load the stored consent state. Returns NULL on any lookup failure so
     * callers fail CLOSED (spec §6: failure is never an accidental "granted").
     * A genuine missing row is NOT a failure: it returns the documented
     * defaults (consent unset, unknown geo → non-EU).
     *
     * @return array{analytics_consent:string,email_marketing_consent:string,is_eu:bool,prompt_seen:bool}|null
     */
    private static function loadPref(mysqli $db, int $userId): ?array
    {
        $default = [
            'analytics_consent' => 'unset',
            'email_marketing_consent' => 'unset',
            'is_eu' => false,          // unknown geo → treat as non-EU (Global Constraints)
            'prompt_seen' => false,
        ];
        try {
            $stmt = $db->prepare(
                "SELECT `analytics_consent`, `email_marketing_consent`, `analytics_geo_is_eu`, `eu_consent_prompt_seen`
                   FROM `202_users_pref` WHERE `user_id` = ? LIMIT 1"
            );
            if ($stmt === false) {
                error_log('[ConsentPolicy] loadPref prepare failed: ' . $db->error);
                return null;
            }
            $stmt->bind_param('i', $userId);
            if (!$stmt->execute()) {
                error_log('[ConsentPolicy] loadPref execute failed: ' . $stmt->error);
                $stmt->close();
                return null;
            }
            $res = $stmt->get_result();
            if ($res === false) {
                error_log('[ConsentPolicy] loadPref get_result failed: ' . $stmt->error);
                $stmt->close();
                return null;
            }
            $row = $res->fetch_assoc();
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('[ConsentPolicy] loadPref failed: ' . $e->getMessage());
            return null;
        }
        if (!$row) { return $default; }
        return [
            'analytics_consent' => $row['analytics_consent'] ?? 'unset',
            'email_marketing_consent' => $row['email_marketing_consent'] ?? 'unset',
            // NULL geo (unknown) → false per Global Constraints
            'is_eu' => ((int) ($row['analytics_geo_is_eu'] ?? 0)) === 1,
            'prompt_seen' => ((int) ($row['eu_consent_prompt_seen'] ?? 0)) === 1,
        ];
    }
}
