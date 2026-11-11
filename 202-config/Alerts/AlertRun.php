<?php

declare(strict_types=1);

namespace Prosper202\Alerts;

use Prosper202\Database\Connection;

/**
 * The cron job's hourly run of the money-loss alerts: every account with
 * alerts on (all of them unless one turned them off), each detector, each
 * alert sent once per incident.
 *
 * An alert is recorded as sent once a channel took it, or when the account
 * has nowhere to send it (no email, no Slack webhook, no subscribed
 * webhook), which is quiet. A channel's failure is logged; when every
 * channel tried failed, the next run tries again, up to MAX_ATTEMPTS runs
 * per incident, so a server that cannot send mail logs a few lines per
 * incident rather than one an hour. One account's failure is logged and
 * the others go on.
 *
 * Every alert is also written to the account's log (202_alert_log) before
 * any channel is tried, with what each channel then did, so Home and the
 * settings card show it whether or not a message arrived.
 */
final class AlertRun
{
    public const MAX_ATTEMPTS = 3;
    /** How long the account's log keeps an alert after its problem was last seen. */
    public const LOG_DAYS = 90;
    /** An alert is still going on when a run saw its problem this recently (the run is hourly). */
    public const ACTIVE_SECONDS = 2 * 3600 + 600;

    /** @var list<AlertDetector> */
    private array $detectors;

    /**
     * @param ?list<AlertDetector> $detectors the default set when null
     */
    public function __construct(
        private readonly Connection $conn,
        private readonly AlertNotifier $notifier,
        ?array $detectors = null,
    ) {
        $this->detectors = $detectors ?? self::detectors();
    }

    /**
     * The cron job's hourly run on the install's connection: the line to
     * print, or null when it found nothing to say (worthSaying()).
     *
     * @param array<string, mixed> $server normally $_SERVER, for the settings link (AlertNotifier::settingsUrl())
     */
    public static function hourly(\mysqli $db, array $server, string $installRoot): ?string
    {
        $conn = new Connection($db);
        $notifier = new AlertNotifier(
            $conn,
            new \Prosper202\Notifications\SlackWebhook(),
            null,
            AlertNotifier::settingsUrl($conn, $server, $installRoot)
        );
        $report = (new self($conn, $notifier))->run(time(), time() + 60);

        return self::worthSaying($report) ? self::summary($report) : null;
    }

    /** @return list<AlertDetector> */
    public static function detectors(): array
    {
        return [new ConversionsStoppedDetector(), new SpendWithoutConversionsDetector(), new PostbackRefusalSpikeDetector()];
    }

    /**
     * @return array{accounts: int, sent: int, failed: int, unsent: int, errors: int}
     */
    public function run(int $now, int $deadline): array
    {
        $report = ['accounts' => 0, 'sent' => 0, 'failed' => 0, 'unsent' => 0, 'errors' => 0];
        $store = new MysqlAlertStore($this->conn);
        $unreadable = [];
        $accounts = $store->enabledAccounts($unreadable);
        foreach ($unreadable as $userId => $why) {
            error_log('Money-loss alerts: account ' . $userId . ' skipped: ' . $why);
            $report['errors']++;
        }

        // Each run starts after the account the last one reached, so a run
        // that runs out of time does not leave the same accounts out every hour.
        $cursor = $store->cursor();
        $ids = array_keys($accounts);
        $after = array_values(array_filter($ids, static fn (int $id): bool => $id > $cursor));
        $ordered = [...$after, ...array_values(array_filter($ids, static fn (int $id): bool => $id <= $cursor))];
        $reached = null;
        foreach ($ordered as $userId) {
            $settings = $accounts[$userId];
            if (time() >= $deadline) {
                error_log('Money-loss alerts: the run\'s time was up with accounts left;'
                    . ' the next run starts with them');
                break;
            }
            $reached = $userId;
            $report['accounts']++;
            try {
                $this->runAccount($store, $userId, $settings, $now, $report);
            } catch (\Throwable $e) {
                $report['errors']++;
                error_log('Money-loss alerts: account ' . $userId . ' could not be checked: '
                    . get_class($e) . ': ' . $e->getMessage());
            }
        }
        if ($reached !== null) {
            $store->saveCursor($reached, $now);
        }

        return $report;
    }

    /** @param array{accounts: int, sent: int, failed: int, unsent: int, errors: int} $report */
    private function runAccount(
        MysqlAlertStore $store,
        int $userId,
        AlertSettings $settings,
        int $now,
        array &$report
    ): void {
        $activity = AccountActivity::read($this->conn, $userId, $now);
        $sent = $store->sentIncidents($userId);
        try {
            $store->pruneLog($userId, $now - self::LOG_DAYS * 86400);
        } catch (\Throwable $e) {
            error_log('Money-loss alerts: account ' . $userId . '\'s alert list could not be pruned: '
                . get_class($e) . ': ' . $e->getMessage());
        }
        $contact = null;
        foreach ($this->detectors as $detector) {
            foreach ($detector->detect($this->conn, $activity, $settings) as $alert) {
                $logged = self::logged($alert, static fn () => $store->logSeen($alert, $now));
                [$key, $delivered, $attempts] = $sent[MysqlAlertStore::incidentId($alert)] ?? [null, false, 0];
                $tries = $key === $alert->incidentKey ? $attempts : 0;
                if ($key === $alert->incidentKey && ($delivered || $tries >= self::MAX_ATTEMPTS)) {
                    continue;
                }
                $contact ??= $this->notifier->contact($userId);
                $result = $this->notifier->deliver($alert, $settings, $contact);
                foreach ($result['failed'] as $channel => $why) {
                    error_log('Money-loss alerts: the ' . $alert->kind->stateKey() . ' alert for campaign '
                        . $alert->campaignId . ' of account ' . $userId . ' was not sent by ' . $channel . ': ' . $why);
                }
                // What the channels did is recorded before the list hears of it:
                // the next run decides whether to send again by this alone.
                $failed = $result['taken'] === [] && $result['failed'] !== [];
                $report[$failed ? 'failed' : ($result['taken'] === [] ? 'unsent' : 'sent')]++;
                $store->recordAttempt($alert, !$failed, $tries + 1, $now);
                if ($logged) {
                    self::logged($alert, static fn () => $store->logDelivery($alert, $result, $tries + 1));
                }
            }
        }
    }

    /**
     * A write to the account's alert list, which never stops an alert from
     * being sent: a failure is logged and answered false.
     *
     * @param callable(): void $write
     */
    private static function logged(Alert $alert, callable $write): bool
    {
        try {
            $write();

            return true;
        } catch (\Throwable $e) {
            error_log('Money-loss alerts: the ' . $alert->kind->stateKey() . ' alert for campaign '
                . $alert->campaignId . ' of account ' . $alert->userId . ' could not be listed in the app: '
                . get_class($e) . ': ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Whether the run has anything to say: with alerts on for every account,
     * a run that found nothing prints nothing.
     *
     * @param array{accounts: int, sent: int, failed: int, unsent: int, errors: int} $report
     */
    public static function worthSaying(array $report): bool
    {
        return $report['sent'] + $report['failed'] + $report['errors'] > 0;
    }

    /** @param array{accounts: int, sent: int, failed: int, unsent: int, errors: int} $report */
    public static function summary(array $report): string
    {
        return $report['accounts'] . ' account(s) checked, ' . $report['sent'] . ' alert(s) sent'
            . ($report['failed'] > 0 ? ', ' . $report['failed'] . ' not sent' : '')
            . ($report['unsent'] > 0 ? ', ' . $report['unsent'] . ' with no channel set up' : '')
            . ($report['errors'] > 0 ? ', ' . $report['errors'] . ' account(s) failed' : '');
    }
}
