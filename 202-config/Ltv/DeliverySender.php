<?php

declare(strict_types=1);

namespace Prosper202\Ltv;

use Prosper202\Attribution\WebhookSender;

/**
 * One LTV webhook delivery's POST, made by the export webhooks' sender
 * (Attribution\WebhookSender::post()), so the two are one guard and one
 * request: WebhookGuard::check() when it is sent, which refuses every
 * spelling of a private, loopback, link-local or metadata address an
 * answer of the host's can take (IPv6 carrying one included) and admits
 * the operator's P202_WEBHOOK_ALLOW_NETWORKS, on port 443 or 8443 as an
 * LTV webhook has always been; then the connection pinned to the address
 * it approved, no proxy, https only, no redirects followed, TLS verified,
 * the time and the answer read capped.
 *
 * The answer is what the delivery's log records: whether the receiver
 * answered 2xx, its HTTP status (null without one), and its body, or
 * "blocked: …" when it was not sent to that address and "curl: …" when
 * nothing answered. It never throws: a delivery that cannot be sent fails
 * on its own row (CLAUDE.md #24).
 */
final class DeliverySender
{
    public function __construct(private ?WebhookSender $sender = null)
    {
    }

    /**
     * @param array<string, mixed> $delivery a MysqlWebhookRepository::duePending() row
     * @return array{0: bool, 1: ?int, 2: string}
     */
    public function post(array $delivery): array
    {
        try {
            $this->sender ??= new WebhookSender();
            $sent = $this->sender->post(
                (string) $delivery['webhook_url'],
                (string) $delivery['payload'],
                self::headers($delivery),
                MysqlWebhookRepository::PORTS
            );
        } catch (\Throwable $e) {
            return [false, null, 'error: ' . get_class($e) . ': ' . $e->getMessage()];
        }

        if ($sent['refused'] !== null) {
            return [false, null, 'blocked: ' . $sent['refused']];
        }
        if ($sent['strayed'] !== null) {
            return [false, null, 'blocked: the connection went to ' . $sent['strayed']
                . ' instead of the checked address ' . $sent['pinned']];
        }
        $status = $sent['status'];
        if ($status === 0) {
            return [false, null, 'curl: ' . ($sent['error'] ?? 'no answer')];
        }

        return [$status >= 200 && $status < 300, $status, $sent['body']];
    }

    /**
     * The request headers of a delivery: its signature over the exact body
     * with the webhook's secret, its event and its id.
     *
     * @param array<string, mixed> $delivery
     * @return list<string>
     */
    public static function headers(array $delivery): array
    {
        return [
            'Content-Type: application/json',
            'X-P202-Signature: ' . MysqlWebhookRepository::signature(
                (string) $delivery['payload'],
                (string) $delivery['webhook_secret']
            ),
            'X-P202-Event: ' . (string) $delivery['event_name'],
            'X-P202-Delivery: ' . (int) $delivery['delivery_id'],
            'User-Agent: Prosper202-LTV-Webhook/1.0',
        ];
    }
}
