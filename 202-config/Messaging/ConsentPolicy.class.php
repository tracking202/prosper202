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
        return self::decide($row['analytics_consent'], $row['is_eu'], 'analytics');
    }

    public static function emailMarketingAllowed(mysqli $db, int $userId): bool
    {
        $row = self::loadPref($db, $userId);
        // Marketing email never goes out on a bare default — require explicit grant.
        return $row['email_marketing_consent'] === 'granted';
    }

    public static function needsEuPrompt(mysqli $db, int $userId): bool
    {
        $row = self::loadPref($db, $userId);
        return $row['is_eu'] === true
            && $row['analytics_consent'] === 'unset'
            && $row['prompt_seen'] === false;
    }

    public static function record(mysqli $db, int $userId, string $flag, string $state, string $source): bool
    {
        if (!in_array($flag, ['analytics','email_marketing'], true)) { return false; }
        if (!in_array($state, ['granted','denied'], true)) { return false; }
        if ($flag === 'analytics') {
            $sql = "UPDATE `202_users_pref`
                       SET `analytics_consent` = ?, `analytics_consent_at` = NOW(),
                           `analytics_consent_source` = ?, `eu_consent_prompt_seen` = 1
                     WHERE `user_id` = ?";
            $stmt = $db->prepare($sql);
            if ($stmt === false) { return false; }
            $stmt->bind_param('ssi', $state, $source, $userId);
        } else {
            $sql = "UPDATE `202_users_pref`
                       SET `email_marketing_consent` = ?, `email_marketing_consent_at` = NOW()
                     WHERE `user_id` = ?";
            $stmt = $db->prepare($sql);
            if ($stmt === false) { return false; }
            $stmt->bind_param('si', $state, $userId);
        }
        $ok = $stmt->execute();
        $stmt->close();
        return (bool) $ok;
    }

    public static function rememberGeo(mysqli $db, int $userId, bool $isEu): bool
    {
        $stmt = $db->prepare("UPDATE `202_users_pref` SET `analytics_geo_is_eu` = ? WHERE `user_id` = ?");
        if ($stmt === false) { return false; }
        $v = $isEu ? 1 : 0;
        $stmt->bind_param('ii', $v, $userId);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool) $ok;
    }

    /** @return array{analytics_consent:string,email_marketing_consent:string,is_eu:bool,prompt_seen:bool} */
    private static function loadPref(mysqli $db, int $userId): array
    {
        $default = [
            'analytics_consent' => 'unset',
            'email_marketing_consent' => 'unset',
            'is_eu' => false,          // unknown geo → treat as non-EU (Global Constraints)
            'prompt_seen' => false,
        ];
        $stmt = $db->prepare(
            "SELECT `analytics_consent`, `email_marketing_consent`, `analytics_geo_is_eu`, `eu_consent_prompt_seen`
               FROM `202_users_pref` WHERE `user_id` = ? LIMIT 1"
        );
        if ($stmt === false) { return $default; }
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) { $stmt->close(); return $default; }
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
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
