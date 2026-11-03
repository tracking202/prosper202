<?php

declare(strict_types=1);

namespace Prosper202\Alerts;

use Prosper202\Database\Connection;

/**
 * The cron job's hourly run of the money-loss alerts: every account with
 * alerts on, each detector, each alert sent once per incident.
 *
 * An alert is recorded as sent once a channel took it, or when no channel
 * could be tried (nothing set up to send to); a channel's failure is
 * logged. When every channel tried failed, nothing is recorded and the next
 * run sends it again. One account's failure is logged and the others go on.
 */
final class AlertRun
{
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

    /** @return list<AlertDetector> */
    public static function detectors(): array
    {
        return [new ConversionsStoppedDetector(), new SpendWithoutConversionsDetector()];
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

        foreach ($accounts as $userId => $settings) {
            if (time() >= $deadline) {
                error_log('Money-loss alerts: the run\'s time was up with accounts left; they are checked next hour');
                break;
            }
            $report['accounts']++;
            try {
                $this->runAccount($store, $userId, $settings, $now, $report);
            } catch (\Throwable $e) {
                $report['errors']++;
                error_log('Money-loss alerts: account ' . $userId . ' could not be checked: '
                    . get_class($e) . ': ' . $e->getMessage());
            }
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
        $contact = null;
        foreach ($this->detectors as $detector) {
            foreach ($detector->detect($this->conn, $activity, $settings) as $alert) {
                if (($sent[MysqlAlertStore::incidentId($alert)] ?? null) === $alert->incidentKey) {
                    continue;
                }
                $contact ??= $this->notifier->contact($userId);
                $result = $this->notifier->deliver($alert, $settings, $contact);
                foreach ($result['failed'] as $channel => $why) {
                    error_log('Money-loss alerts: the ' . $alert->kind->stateKey() . ' alert for campaign '
                        . $alert->campaignId . ' of account ' . $userId . ' was not sent by ' . $channel . ': ' . $why);
                }
                if ($result['taken'] === [] && $result['failed'] !== []) {
                    $report['failed']++;
                    continue;
                }
                if ($result['taken'] === []) {
                    $report['unsent']++;
                    error_log('Money-loss alerts: the ' . $alert->kind->stateKey() . ' alert for campaign '
                        . $alert->campaignId . ' of account ' . $userId . ' had nowhere to go: '
                        . implode('; ', $result['skipped']));
                } else {
                    $report['sent']++;
                }
                $store->recordSent($alert, $now);
            }
        }
    }

    /** @param array{accounts: int, sent: int, failed: int, unsent: int, errors: int} $report */
    public static function summary(array $report): string
    {
        return $report['accounts'] . ' account(s) checked, ' . $report['sent'] . ' alert(s) sent'
            . ($report['failed'] > 0 ? ', ' . $report['failed'] . ' not sent (tried again next hour)' : '')
            . ($report['unsent'] > 0 ? ', ' . $report['unsent'] . ' with no channel set up' : '')
            . ($report['errors'] > 0 ? ', ' . $report['errors'] . ' account(s) failed' : '');
    }
}
