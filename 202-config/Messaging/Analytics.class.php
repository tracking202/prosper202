<?php
// 202-config/Messaging/Analytics.class.php
require_once __DIR__ . '/ConsentPolicy.class.php';
require_once __DIR__ . '/MessagingService.class.php';

final class Analytics
{
    /** Pure gate, delegates to ConsentPolicy. Exposed for testing. */
    public static function gate(string $stored, bool $isEu, string $tier): bool
    {
        return ConsentPolicy::decide($stored, $isEu, $tier);
    }

    public static function event(string $name, array $meta = [], string $tier = 'analytics'): void
    {
        self::guarded(function (mysqli $db, int $uid) use ($name, $meta, $tier) {
            if ($tier === 'analytics' && !ConsentPolicy::analyticsAllowed($db, $uid)) {
                return;
            }
            $service = new MessagingService($db, $uid, []);
            // MessagingService::recordEvent persists to 202_messaging_events; pass tier through.
            $service->recordEvent($name, $meta, $tier);
        });
    }

    public static function attr(array $attributes, string $tier = 'analytics'): void
    {
        self::guarded(function (mysqli $db, int $uid) use ($attributes, $tier) {
            if ($tier === 'analytics' && !ConsentPolicy::analyticsAllowed($db, $uid)) {
                return;
            }
            $service = new MessagingService($db, $uid, []);
            $service->updateAttributes($attributes);
        });
    }

    /** Thin testable wrapper: would a write of this tier be recorded for this user? */
    public static function wouldRecord(mysqli $db, int $userId, string $tier): bool
    {
        if ($tier !== 'analytics') {
            return true;
        }
        return ConsentPolicy::analyticsAllowed($db, $userId);
    }

    private static function guarded(callable $fn): void
    {
        try {
            $db = $GLOBALS['db'] ?? null;
            $uid = (int) ($_SESSION['user_id'] ?? 0);
            if (!($db instanceof mysqli) || $uid <= 0) { return; }
            $fn($db, $uid);
        } catch (\Throwable $e) {
            // Never let tracking break a host page. Log if a logger exists; otherwise swallow.
            if (function_exists('error_log')) {
                error_log('[Analytics] ' . $e->getMessage());
            }
        }
    }
}
