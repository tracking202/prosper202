<?php

declare(strict_types=1);

namespace Prosper202\Goals;

use Prosper202\Conversion\TrafficSourcePixels;
use Prosper202\Database\Connection;

/**
 * Tells a click's traffic source about the goals it reached (plan §5.5,
 * "notify traffic source"), through the same sender gpb.php uses for a
 * plain conversion (TrafficSourcePixels::fire()), with the goal tokens
 * added:
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
 * Only notices of kind `reached` are sent (the engine decides the kind, see
 * OutcomeNotifier). Everything else is reported, never sent. Without a
 * notification outbox (PR 5) delivery is best-effort and immediate: a
 * process that dies between the commit and this call leaves the network
 * untold, exactly as gpb.php's own sender does.
 *
 * With $browser true (an image pixel or a page script called the intake,
 * so a browser renders the response) image, iframe, script and raw-code
 * pixels are rendered into markup() for the caller to echo; otherwise only
 * server-to-server postbacks go out and the rest are counted as skipped.
 */
final class TrafficSourceNotifier implements OutcomeNotifier
{
    private string $markup = '';

    /**
     * @param (callable(string): bool)|null $fetch the type-4 GET (tests inject one)
     */
    public function __construct(
        private Connection $conn,
        private bool $browser = false,
        private $fetch = null,
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
            $fired = TrafficSourcePixels::fire($this->conn, $click['ppc_account_id'], $tokens, $this->fetch, $this->browser);
            $this->markup .= $fired['markup'];
            $status = match (true) {
                $fired['types'] === [] => 'no_pixels',
                $fired['server_failures'] > 0 => 'failed',
                // Only browser pixels, and no browser on this path to load them.
                $fired['server_calls'] === 0 && $fired['markup'] === '' => 'browser_only',
                default => 'sent',
            };
            $out[] = $entry + [
                'status' => $status,
                'server_calls' => $fired['server_calls'],
                'server_failures' => $fired['server_failures'],
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
        if (preg_match('/^(-?\d+)(?:\.(\d*))?$/D', $amount, $m) !== 1) {
            return $amount;
        }
        $fraction = rtrim($m[2] ?? '', '0');

        return $m[1] . '.' . str_pad($fraction, 2, '0');
    }
}
