<?php

declare(strict_types=1);

namespace Prosper202\Goals;

use Prosper202\Conversion\TrafficSourcePixels;
use Prosper202\Database\Connection;
use Prosper202\Notifications\NotificationOutbox;

/**
 * What only the request can do about the goals a click reached (plan §5.5,
 * "notify traffic source"): render the traffic source's browser pixels
 * (image, iframe, script, raw code) into the response of an intake a
 * browser loads, and report what happened to each written outcome.
 *
 * The server-to-server postbacks are not sent here. The goal engine queues
 * them in the notification outbox inside the transaction that records the
 * outcome (Prosper202\Notifications\NotificationOutbox, plan §5.8, §5.10),
 * and the worker sends them with retries — one path for web and app goals,
 * where a replay's replacement is announced only if what it replaces never
 * went out. A notice of kind `reached` reports `queued` (its rows waiting in
 * the outbox). The goal tokens, in the queued postbacks and the browser
 * pixels alike, are TrafficSourcePixels':
 *
 *   [[p202_goal]]        the goal's name
 *   [[p202_goal_id]]     its id
 *   [[p202_goal_value]]  what this outcome pays on the campaign
 *   [[payout]]           the same amount (what gpb.php sends for a sale)
 *   [[transactionid]]    the reaching event's transaction id, or the ledger
 *                        row's dedupe key when it had none, so a network
 *                        told about several goals of one click can tell
 *                        them apart and dedupe its side.
 *
 * Only notices of kind `reached` render browser pixels (the engine decides
 * the kind, see OutcomeNotifier). With $browser false (an API call, a
 * server-to-server intake) nothing is rendered and the browser pixels are
 * counted as skipped.
 */
final class TrafficSourceNotifier implements OutcomeNotifier
{
    private string $markup = '';

    public function __construct(
        private Connection $conn,
        private bool $browser = false,
    ) {
    }

    /** The browser pixels rendered by the notifications so far. */
    public function markup(): string
    {
        return $this->markup;
    }

    public function notify(int $userId, GoalSubject $subject, array $notices): array
    {
        $out = [];
        $click = null;
        $names = [];
        foreach ($notices as $notice) {
            $entry = [
                'goal_id' => (int) $notice['goal_id'],
                'n' => (int) $notice['n'],
                'outcome_id' => (int) $notice['outcome_id'],
                'kind' => (string) $notice['kind'],
            ];
            if ($notice['kind'] !== 'reached') {
                $out[] = $entry + ['status' => 'not_sent', 'reason' => $notice['reason'] ?? null];
                continue;
            }
            if ($subject->clickId === null) {
                $out[] = $entry + ['status' => 'not_sent', 'reason' => 'no_click'];
                continue;
            }
            $click ??= TrafficSourcePixels::clickTokens($this->conn, $userId, $subject->clickId);
            if ($click === null || $click['ppc_account_id'] <= 0) {
                $out[] = $entry + ['status' => 'not_sent', 'reason' => 'no_traffic_source'];
                continue;
            }
            $goalId = (int) $notice['goal_id'];
            if (!isset($names[$goalId])) {
                $stmt = $this->conn->prepareRead('SELECT name FROM 202_goals WHERE goal_id = ? AND user_id = ? LIMIT 1');
                $this->conn->bind($stmt, 'ii', [$goalId, $userId]);
                $names[$goalId] = (string) ($this->conn->fetchOne($stmt)['name'] ?? '');
            }
            $amount = self::money((string) ($notice['amount'] ?? '0'));
            $tx = $notice['transaction_id'] ?? null;
            $tokens = $click['tokens'] + [
                'payout' => $amount,
                'transactionid' => $tx !== null && $tx !== '' ? (string) $tx : (string) ($notice['dedupe_key'] ?? ''),
                'p202_goal' => $names[$goalId],
                'p202_goal_id' => $goalId,
                'p202_goal_value' => $amount,
            ];
            // Browser pixels only: the server postbacks are the outbox's.
            $fired = TrafficSourcePixels::fire($this->conn, $click['ppc_account_id'], $tokens, null, $this->browser, false);
            $this->markup .= $fired['markup'];
            $queued = (int) ($notice['queued'] ?? 0);
            $status = match (true) {
                $fired['types'] === [] => 'no_pixels',
                $queued > 0 => 'queued',
                $fired['markup'] !== '' => 'rendered',
                // Only browser pixels, and no browser on this path to load them.
                default => 'browser_only',
            };
            $out[] = $entry + [
                'status' => $status,
                'queued' => $queued,
                'browser_pixels' => $this->browser ? count(array_intersect($fired['types'], [1, 2, 3, 5])) : 0,
                'browser_skipped' => $fired['browser_skipped'],
            ];
        }

        return $out;
    }

    /**
     * A ledger amount ("12.50000") as a network reads money: at least two
     * decimals, and no trailing zeros past them ("12.50", "0.12345").
     */
    public static function money(string $amount): string
    {
        return NotificationOutbox::money($amount);
    }
}
