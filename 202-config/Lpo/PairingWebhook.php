<?php

declare(strict_types=1);

namespace Prosper202\Lpo;

use Prosper202\Ltv\MysqlWebhookRepository;

/**
 * Whether the Landing Page Optimizer receives events: the status of the
 * webhook its pairing made, which lpo_bridge_config names by id. The pairing
 * card read "Connected" from lpo_status alone, so a webhook marked dead after
 * MAX_ATTEMPTS failed sends, or one deleted in LTV Settings, still read as
 * connected while the optimizer got nothing.
 */
final class PairingWebhook
{
    /** The webhook is active: events are queued for it. */
    public const RECEIVING = 'receiving';
    /** A delivery failed MAX_ATTEMPTS times and the webhook was stopped. */
    public const DEAD = 'dead';
    /** The webhook is neither active nor dead (`disabled`). */
    public const OFF = 'off';
    /** No webhook of this account has the id the pairing names. */
    public const GONE = 'gone';
    /** The pairing record names no webhook, so which one is the optimizer's cannot be told. */
    public const UNNAMED = 'unnamed';

    private function __construct()
    {
    }

    /**
     * @param string $bridgeConfig the stored lpo_bridge_config value
     * @return array{state: string, webhook_id: int} webhook_id 0 when UNNAMED
     * @throws \RuntimeException when the webhook cannot be read: the caller
     *         says so, since "not known" is neither connected nor gone
     */
    public static function state(MysqlWebhookRepository $webhooks, int $userId, string $bridgeConfig): array
    {
        $webhookId = self::webhookId($bridgeConfig);
        if ($webhookId === null) {
            return ['state' => self::UNNAMED, 'webhook_id' => 0];
        }
        $webhook = $webhooks->get($userId, $webhookId);
        $state = match ($webhook['status'] ?? null) {
            null => self::GONE,
            'active' => self::RECEIVING,
            'dead' => self::DEAD,
            default => self::OFF,
        };

        return ['state' => $state, 'webhook_id' => $webhookId];
    }

    /**
     * What the pairing card says about it: a flash kind (`bad`, `warn`) and
     * its sentence, or ['', ''] when the optimizer receives events.
     *
     * @param array{state: string, webhook_id: int}|null $status state()'s
     *        answer, or null when it could not be read
     * @return array{0: string, 1: string}
     */
    public static function notice(?array $status): array
    {
        $notReceiving = 'The optimizer is not receiving events: its webhook (#' . ($status['webhook_id'] ?? 0) . ')';
        $pairAgain = ' Disconnect, then connect again to pair a new one.';

        return match ($status['state'] ?? null) {
            self::RECEIVING => ['', ''],
            self::DEAD => ['bad', $notReceiving . ' was stopped after a delivery to it failed '
                . MysqlWebhookRepository::MAX_ATTEMPTS . ' times.'
                . ' Fix the endpoint, then revive the webhook in LTV Settings.'],
            self::OFF => ['bad', $notReceiving . ' is switched off.' . $pairAgain],
            self::GONE => ['bad', $notReceiving . ' no longer exists on this install.' . $pairAgain],
            self::UNNAMED => ['warn', 'This install\'s pairing record names no webhook, so whether the optimizer'
                . ' receives events cannot be told.' . $pairAgain],
            default => ['warn', 'The status of the optimizer\'s webhook could not be read just now, so whether it'
                . ' receives events cannot be told. Reload the page to try again.'],
        };
    }

    /** The id the pairing wrote (an integer above 0), or null. */
    private static function webhookId(string $bridgeConfig): ?int
    {
        $state = json_decode($bridgeConfig, true);
        $id = is_array($state) ? ($state['webhook_id'] ?? null) : null;

        return is_int($id) && $id > 0 ? $id : null;
    }
}
