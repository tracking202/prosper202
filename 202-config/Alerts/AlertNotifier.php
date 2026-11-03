<?php

declare(strict_types=1);

namespace Prosper202\Alerts;

use Prosper202\Click\TrackingDomain;
use Prosper202\Database\Connection;
use Prosper202\Ltv\MysqlWebhookRepository;
use Prosper202\Notifications\SlackWebhook;
use Prosper202\User\PasswordResetLink;

/**
 * Sends an alert on the account's own channels: its Slack incoming webhook
 * (SlackWebhook, guarded), its email address (PHP's mail(), as the password
 * reset sends), and its webhooks subscribed to the alert's event (queued;
 * the cron's dispatcher signs and sends them). Each channel is read from the
 * alert's account, never another's.
 */
final class AlertNotifier
{
    public const SLACK = 'slack';
    public const EMAIL = 'email';
    public const WEBHOOK = 'webhook';

    /** Where the alerts are turned off, as every message names it. */
    public const SETTINGS_PLACE = 'Account › Personal settings › Money-loss alerts';
    public const SETTINGS_PAGE = '202-account/account.php#money-loss-alerts';

    /** @var callable(string, string, string, string): bool */
    private $mailer;

    /**
     * @param ?callable(string, string, string, string): bool $mailer to, subject, body, headers; mail() when null
     * @param ?string $settingsUrl the settings card's address (settingsUrl()); null names the place without a link
     */
    public function __construct(
        private readonly Connection $conn,
        private readonly SlackWebhook $slack = new SlackWebhook(),
        ?callable $mailer = null,
        private readonly ?string $settingsUrl = null,
    ) {
        $this->mailer = $mailer ?? static fn (string $to, string $subject, string $body, string $headers): bool
            => mail($to, $subject, $body, $headers);
    }

    /**
     * The settings card's address on this install, or null when it is not
     * known: the install's stored address (user 1's tracking domain, as the
     * password reset links it) and its path, which a run knows only when it
     * was started through the web server (a command-line run has no document
     * root, and a guessed path would link a subdirectory install to nothing).
     *
     * @param array<string, mixed> $server normally $_SERVER
     */
    public static function settingsUrl(Connection $conn, array $server, string $installRoot): ?string
    {
        $docRoot = is_scalar($server['DOCUMENT_ROOT'] ?? null) ? realpath((string) $server['DOCUMENT_ROOT']) : false;
        $root = realpath($installRoot);
        if ($docRoot === false || $root === false || !str_starts_with($root . '/', rtrim($docRoot, '/') . '/')) {
            return null;
        }
        $row = $conn->fetchOne(
            $conn->prepareRead('SELECT user_tracking_domain FROM 202_users_pref WHERE user_id = 1 LIMIT 1')
        );
        $base = PasswordResetLink::base((string) ($row['user_tracking_domain'] ?? ''), $server, $installRoot);

        return $base === null ? null : $base . self::SETTINGS_PAGE;
    }

    /** The line every message ends with: how to turn the alerts off. */
    public function optOutLine(): string
    {
        return 'To turn these alerts off: ' . self::SETTINGS_PLACE
            . ($this->settingsUrl !== null ? ' (' . $this->settingsUrl . ')' : '') . '.';
    }

    /**
     * Where the account's alerts go: its email, Slack webhook and tracking
     * domain (which names the sender), '' for each it has not set.
     *
     * @return array{email: string, slack: string, domain: string}
     */
    public function contact(int $userId): array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT u.user_email, p.user_slack_incoming_webhook, p.user_tracking_domain'
            . ' FROM 202_users u LEFT JOIN 202_users_pref p ON p.user_id = u.user_id WHERE u.user_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'i', [$userId]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            throw new \UnexpectedValueException("Account $userId has no user row");
        }
        $email = str_replace(["\r", "\n"], '', trim((string) ($row['user_email'] ?? '')));

        return [
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) === false ? '' : $email,
            'slack' => trim((string) ($row['user_slack_incoming_webhook'] ?? '')),
            'domain' => (string) preg_replace(
                '/:[0-9]+$/D',
                '',
                TrackingDomain::normalize((string) ($row['user_tracking_domain'] ?? ''))
            ),
        ];
    }

    /**
     * Sends the alert on every channel the settings turn on: the channels
     * that took it, why each that was tried did not, and why each was not
     * tried (the account has no Slack webhook, no email address, or no
     * webhook subscribed to the event).
     *
     * @param array{email: string, slack: string, domain: string} $contact
     * @return array{taken: list<string>, failed: array<string, string>, skipped: array<string, string>}
     */
    public function deliver(Alert $alert, AlertSettings $settings, array $contact): array
    {
        $result = ['taken' => [], 'failed' => [], 'skipped' => []];
        if ($settings->notifySlack) {
            if ($contact['slack'] === '') {
                $result['skipped'][self::SLACK] = 'no Slack webhook is set';
            } else {
                try {
                    $why = $this->slack->post($contact['slack'], $this->slackBody($alert, $contact['domain']));
                } catch (\Throwable $e) {
                    $why = 'it could not be sent: ' . $e->getMessage();
                }
                self::outcome($result, self::SLACK, $why);
            }
        }
        if ($settings->notifyEmail) {
            if ($contact['email'] === '') {
                $result['skipped'][self::EMAIL] = 'the account has no valid email address';
            } else {
                self::outcome($result, self::EMAIL, $this->email($alert, $contact));
            }
        }
        if ($settings->notifyWebhook) {
            try {
                $queued = (new MysqlWebhookRepository($this->conn))
                    ->enqueue($alert->userId, $alert->kind->value, $alert->payload());
                if ($queued === 0) {
                    $result['skipped'][self::WEBHOOK] = 'no webhook subscribes to ' . $alert->kind->value;
                } else {
                    $result['taken'][] = self::WEBHOOK;
                }
            } catch (\Throwable $e) {
                $result['failed'][self::WEBHOOK] = 'it could not be queued: ' . $e->getMessage();
            }
        }

        return $result;
    }

    /** @param array{taken: list<string>, failed: array<string, string>, skipped: array<string, string>} $result */
    private static function outcome(array &$result, string $channel, ?string $why): void
    {
        if ($why === null) {
            $result['taken'][] = $channel;
        } else {
            $result['failed'][$channel] = $why;
        }
    }

    /** The Slack body: a form field holding the JSON message. */
    public function slackBody(Alert $alert, string $domain): string
    {
        $escape = static fn (string $text): string => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
        $optOut = $this->settingsUrl !== null
            ? 'To turn these alerts off: <' . $escape($this->settingsUrl) . '|' . self::SETTINGS_PLACE . '>.'
            : $escape($this->optOutLine());
        $json = json_encode([
            'text' => '*' . $alert->kind->title() . '*: ' . $escape($alert->message) . "\n" . $optOut,
            'username' => $domain !== '' ? $domain : 'Prosper202',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new \RuntimeException('The Slack message could not be encoded: ' . json_last_error_msg());
        }

        return 'payload=' . rawurlencode($json);
    }

    /** @param array{email: string, slack: string, domain: string} $contact */
    private function email(Alert $alert, array $contact): ?string
    {
        $subject = str_replace(
            ["\r", "\n"],
            ' ',
            '[Prosper202] ' . $alert->kind->title() . ': ' . $alert->campaignName
        );
        $body = $alert->message . "\n\n"
            . 'Money-loss alerts are on for your Prosper202 account. ' . $this->optOutLine() . "\n";
        $headers = 'MIME-Version: 1.0' . "\r\n"
            . 'Content-Type: text/plain; charset=UTF-8' . "\r\n"
            . 'Content-Transfer-Encoding: 8bit';
        if ($contact['domain'] !== '' && preg_match('/\A[A-Za-z0-9.-]+\z/', $contact['domain']) === 1) {
            $headers .= "\r\n" . 'From: Prosper202 <prosper202@' . $contact['domain'] . '>';
        }

        try {
            $encoded = mb_encode_mimeheader($subject, 'UTF-8', 'Q', "\r\n");
            $sent = ($this->mailer)($contact['email'], $encoded, $body, $headers);
        } catch (\Throwable $e) {
            return 'mail() failed: ' . $e->getMessage();
        }

        return $sent ? null : 'mail() did not accept it (is this server set up to send email?)';
    }
}
