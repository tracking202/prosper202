<?php

declare(strict_types=1);

namespace Prosper202\Notifications;

use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookSender;

/**
 * The Slack incoming webhook (user_slack_incoming_webhook): the one rule for
 * which URLs may be saved, and the one way a notice is posted to it.
 *
 * Account › API integrations saved any non-empty string, http:// and
 * internal hosts included, while PUT /users/{id}/preferences asked for
 * https://, and Slack.class.php posted with curl and no timeout, no address
 * check and no pin: each notice was a request from inside the operator's
 * network to wherever the field pointed, and a host that never answered
 * held the request that sent it (a failed sign-in, ClickBank's test). Both
 * writers ask accepted() now, and post() sends through the export and LTV
 * webhooks' sender (WebhookSender): WebhookGuard::check() when it is sent,
 * public addresses only (P202_WEBHOOK_ALLOW_NETWORKS admits an operator's
 * own network), pinned, no proxy, https only, no redirects, and TIMEOUT
 * seconds in all.
 */
final class SlackWebhook
{
    public const COLUMN = 'user_slack_incoming_webhook';

    /**
     * Seconds a notice may take, connecting included. A notice is sent while
     * a person or a network waits on the request (ClickBank gives its test 3
     * seconds); Slack answers in well under one.
     */
    public const TIMEOUT = 2;

    /** Characters of a refusing answer's body kept for the log. */
    private const ANSWER_CHARS = 200;

    public function __construct(private ?WebhookSender $sender = null)
    {
    }

    /**
     * The value to store for a submitted webhook URL, trimmed: '' (no
     * notices), or a URL WebhookGuard accepts without asking a resolver, as
     * an LTV webhook's save does, so a resolver that stalls cannot hold the
     * save; post() resolves the name and checks every address. An allowlist
     * that does not parse admits nothing here.
     *
     * @param string|null $allowNetworks the operator's allowlist; null reads P202_WEBHOOK_ALLOW_NETWORKS
     * @throws \InvalidArgumentException saying why, which never repeats the URL's path
     */
    public static function accepted(string $value, ?string $allowNetworks = null): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        try {
            $guard = new WebhookGuard(null, $allowNetworks);
        } catch (\UnexpectedValueException) {
            $guard = new WebhookGuard(null, '');
        }
        $why = $guard->webhookRefusal($value);
        if ($why !== null) {
            throw new \InvalidArgumentException($why);
        }

        return $value;
    }

    /**
     * POST a notice's body: null when the webhook answered 2xx, else why
     * not, for the log. The reason names a host or an address at most, never
     * the URL's path, which is the webhook's secret. Never throws.
     */
    public function post(string $url, string $body): ?string
    {
        try {
            $this->sender ??= new WebhookSender();
            $sent = $this->sender->post($url, $body, [
                'Content-Type: application/x-www-form-urlencoded',
                'User-Agent: Prosper202-Slack/1',
                'Expect:',
            ], [], self::TIMEOUT);
        } catch (\Throwable $e) {
            return self::withoutPath('it could not be sent: ' . get_class($e) . ': ' . $e->getMessage(), $url);
        }

        if ($sent['refused'] !== null) {
            $why = 'refused: ' . $sent['refused'];
        } elseif ($sent['strayed'] !== null) {
            $why = 'not delivered: the connection went to ' . $sent['strayed']
                . ' instead of the checked address ' . $sent['pinned'];
        } elseif ($sent['status'] === 0) {
            $why = 'no answer: ' . ($sent['error'] ?? 'the connection failed');
        } elseif ($sent['status'] >= 200 && $sent['status'] < 300) {
            return null;
        } elseif ($sent['status'] >= 300 && $sent['status'] < 400) {
            $why = 'the webhook answered ' . $sent['status'] . ', a redirect, which is not followed';
        } else {
            $answer = trim(mb_substr((string) preg_replace('/\s+/', ' ', $sent['body']), 0, self::ANSWER_CHARS));
            $why = 'the webhook answered ' . $sent['status'] . ($answer !== '' ? ': ' . $answer : '');
        }

        return self::withoutPath($why, $url);
    }

    /**
     * The reason on one line, with the URL's path and query, should any text
     * repeat them, cut to "/…".
     */
    private static function withoutPath(string $reason, string $url): string
    {
        $reason = (string) preg_replace('/\s+/', ' ', $reason);
        if (preg_match('~^[^:/?#]+://[^/?#]*([/?#].+)$~sD', $url, $m) !== 1 || strlen($m[1]) < 8) {
            return $reason;
        }

        return str_replace($m[1], '/…', $reason);
    }
}
