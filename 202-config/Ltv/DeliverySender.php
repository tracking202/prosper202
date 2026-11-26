<?php

declare(strict_types=1);

namespace Prosper202\Ltv;

use Prosper202\Attribution\WebhookSender;
use Prosper202\Webhooks\StandardWebhook;

/**
 * One LTV webhook delivery's POST, made by the export webhooks' sender
 * (Attribution\WebhookSender::post()), so the two are one guard and one
 * request: WebhookGuard::check() when it is sent, which refuses every
 * spelling of a private, loopback, link-local or metadata address an
 * answer of the host's can take (IPv6 carrying one included) and admits
 * the operator's P202_WEBHOOK_ALLOW_NETWORKS, on port 443 or 8443 as an
 * LTV webhook has always been; then the connection pinned to the address
 * it approved, through no proxy but the egress proxy, https only, no redirects followed, TLS verified,
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
    /**
     * @param (\Closure(): int)|null $clock the attempt's time (webhook-timestamp); time() when null
     */
    public function __construct(private ?WebhookSender $sender = null, private ?\Closure $clock = null)
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
                MysqlWebhookRepository::sendableUrl((string) $delivery['webhook_url']),
                (string) $delivery['payload'],
                self::headers($delivery, $this->clock !== null ? ($this->clock)() : time()),
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
     * The request headers of a delivery at $now (time() when null): the
     * Standard Webhooks three (StandardWebhook::headers()), the id the
     * event was queued with, the attempt's time, and a signature by the
     * webhook's secret and by the one a rotation replaced until it expires;
     * then the headers sent before 1.9.81, X-P202-Signature over the body
     * alone by the current secret, X-P202-Event and X-P202-Delivery, which
     * stay through StandardWebhook::LEGACY_SIGNATURE_LAST_RELEASE.
     *
     * A secret StandardWebhook::keyFor() refuses throws, and post() logs the
     * delivery failed with why: a signature its receiver cannot match is
     * not sent.
     *
     * @param array<string, mixed> $delivery a MysqlWebhookRepository::duePending() row
     * @return list<string>
     */
    public static function headers(array $delivery, ?int $now = null): array
    {
        $now ??= time();
        $body = (string) $delivery['payload'];

        return [
            'Content-Type: application/json',
            'X-P202-Signature: ' . MysqlWebhookRepository::signature(
                $body,
                (string) $delivery['webhook_secret']
            ),
            'X-P202-Event: ' . (string) $delivery['event_name'],
            'X-P202-Delivery: ' . (int) $delivery['delivery_id'],
            'User-Agent: Prosper202-LTV-Webhook/1.0',
            ...StandardWebhook::headers(
                MysqlWebhookRepository::signingSecrets($delivery, $now),
                MysqlWebhookRepository::messageId($delivery),
                $now,
                $body
            ),
        ];
    }
}
