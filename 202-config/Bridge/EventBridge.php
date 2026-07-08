<?php

declare(strict_types=1);

namespace Prosper202\Bridge;

use Prosper202\Database\Connection;
use Prosper202\Ltv\MysqlWebhookRepository;

/**
 * Generic outbound event bridge: wraps a payload in a versioned envelope and
 * enqueues it through the existing LTV webhook queue (plain DB insert; HTTP
 * happens only in the cron dispatcher). The envelope is the stability
 * contract with hosted consumers — `payload` carries all available
 * identifiers additive-only, `ext` is a reserved extension bag — so features
 * ship server-side without new Prosper202 releases, and new Prosper202
 * features just emit() a new event name.
 *
 * Delivered wire body stays the dispatcher's existing
 * {event, occurred_at, data} with data = this envelope.
 */
final class EventBridge
{
    public const BRIDGE_VERSION = '1.0';

    /**
     * Fire-and-forget: enqueue failures are logged, never fatal — an emit
     * must never break the write it rides on. A no-op unless a webhook
     * subscribes to the event (or to '*').
     *
     * @param array<string, mixed> $payload all available identifiers, additive-only
     * @param array<string, mixed> $ext reserved extension bag
     */
    public static function emit(Connection $conn, int $userId, string $event, array $payload, array $ext = []): void
    {
        try {
            $envelope = [
                'bridge_version' => self::BRIDGE_VERSION,
                'install' => self::installContext($conn, $userId),
                'payload' => $payload,
                'ext' => $ext === [] ? new \stdClass() : $ext,
            ];
            (new MysqlWebhookRepository($conn))->enqueue($userId, $event, $envelope);
        } catch (\Throwable $e) {
            error_log('EventBridge emit failed (' . $event . '): ' . $e->getMessage());
        }
    }

    /**
     * emit(), gated on the signed remote bridge config explicitly enabling
     * the event (directly or via '*'). Off by default: until the config
     * cron (202-cronjobs/bridge_config.php) has pulled a config that
     * enables it, nothing is emitted.
     */
    public static function emitIfEnabled(Connection $conn, int $userId, string $event, array $payload, array $ext = []): void
    {
        if (!self::isEventEnabled($conn, $userId, $event)) {
            return;
        }
        self::emit($conn, $userId, $event, $payload, $ext);
    }

    /**
     * Whether the applied remote bridge config enables an event. Fails
     * closed on every path — missing pairing, malformed config, DB errors,
     * and the deploy window before the pref column exists all read as
     * disabled; a gate check may never break the write it guards.
     */
    public static function isEventEnabled(Connection $conn, int $userId, string $event): bool
    {
        try {
            $enabled = self::readState($conn, $userId)['config']['enabled_events'] ?? null;
            if (!is_array($enabled)) {
                return false;
            }

            return in_array('*', $enabled, true) || in_array($event, $enabled, true);
        } catch (\Throwable $e) {
            if (!Connection::isMysqlError($e, 1054, 'Unknown column')) {
                error_log('EventBridge gate check failed (' . $event . '): ' . $e->getMessage());
            }

            return false;
        }
    }

    /**
     * The stored bridge state for a user: {webhook_id, config, fetched_at}
     * as written by the pairing panel and the config cron. [] when unpaired.
     * Malformed JSON is logged and treated as absent (the state is only
     * written by this codebase; corruption means direct DB edits).
     *
     * @return array<string, mixed>
     */
    public static function readState(Connection $conn, int $userId): array
    {
        $stmt = $conn->prepareRead(
            'SELECT bandit_bridge_config FROM 202_users_pref WHERE user_id = ? LIMIT 1'
        );
        $conn->bind($stmt, 'i', [$userId]);
        $row = $conn->fetchOne($stmt);

        $raw = trim((string) ($row['bandit_bridge_config'] ?? ''));
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            error_log('EventBridge: bandit_bridge_config for user ' . $userId . ' is not valid JSON; treating as absent');

            return [];
        }

        return $decoded;
    }

    /**
     * Install identification for the envelope. install_hash lives on
     * 202_users (install-wide values sit on user 1, so fall back there for
     * sub-users whose row has none).
     *
     * @return array{install_hash: string, p202_version: string, user_id: int}
     */
    private static function installContext(Connection $conn, int $userId): array
    {
        $installHash = '';
        foreach (array_unique([$userId, 1]) as $candidateId) {
            $stmt = $conn->prepareRead('SELECT install_hash FROM 202_users WHERE user_id = ? LIMIT 1');
            $conn->bind($stmt, 'i', [$candidateId]);
            $row = $conn->fetchOne($stmt);
            $installHash = trim((string) ($row['install_hash'] ?? ''));
            if ($installHash !== '') {
                break;
            }
        }

        return [
            'install_hash' => $installHash,
            'p202_version' => defined('PROSPER202_VERSION') ? (string) PROSPER202_VERSION : '',
            'user_id' => $userId,
        ];
    }
}
