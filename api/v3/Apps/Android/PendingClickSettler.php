<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android;

use Api\V3\Apps\AppIdentity;
use Api\V3\Apps\AppPolicy;
use Api\V3\Apps\AppRegistration;
use Prosper202\Database\Connection;
use Prosper202\Goals\GoalEngine;
use Prosper202\Goals\MysqlGoalRepository;
use Throwable;

/**
 * Settles installs left `pending_click` (plan §5.3): the token verified, but
 * the click it names had not been written yet — the redirect writes its
 * click row after sending the visitor on, and a store round trip can beat
 * it. Run by 202-cronjobs/app-installs.php.
 *
 * Each install settles in its own transaction, the same shape as the
 * intake's (plan §5.2): the install row is locked FOR UPDATE first, so a
 * concurrent replay of the same install waits for the settle instead of
 * reading a half-settled row; the click is re-read under its lock and the
 * install is classified exactly as the intake would have, from the stored
 * body; and a final state is written with its conversion and notifications
 * in one commit. A click that still does not exist 24 hours after receipt
 * settles as bad_token ("never recorded").
 */
final class PendingClickSettler
{
    private Connection $conn;
    private GoalEngine $engine;
    private InstallIntake $intake;

    /** @param (callable(): int)|null $clock */
    public function __construct(private readonly \mysqli $db, private $clock = null)
    {
        $this->conn = new Connection($db);
        $this->engine = new GoalEngine($this->conn, new MysqlGoalRepository($this->conn), null, $clock);
        $this->intake = new InstallIntake($db, $clock, $this->engine);
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }

    /**
     * Settle up to $limit pending installs, oldest first.
     *
     * @return array{examined: int, settled: array<string, int>, still_pending: int, failed: int}
     */
    public function run(int $limit = 500): array
    {
        // Installs whose registration is gone can never settle here, and
        // nothing else would ever touch them: they are retired first
        // (OrphanedPendingClicks). The selection joins the registration as
        // settleOne() reads it, so one that appears after the sweep cannot
        // hold a slot in this oldest-first batch either — enough of them
        // would starve every other app's pending clicks.
        (new OrphanedPendingClicks($this->conn))->settleOrphans($this->now());
        $stmt = $this->conn->prepareWrite(
            "SELECT i.install_row_id FROM 202_app_installs i JOIN 202_app_registrations r ON r.registration_id = i.registration_id
             WHERE i.match_state = 'pending_click' ORDER BY i.received_at, i.install_row_id LIMIT ?"
        );
        $this->conn->bind($stmt, 'i', [max(1, $limit)]);
        $ids = array_map(static fn (array $r): int => (int) $r['install_row_id'], $this->conn->fetchAll($stmt));

        $out = ['examined' => count($ids), 'settled' => [], 'still_pending' => 0, 'failed' => 0];
        foreach ($ids as $id) {
            try {
                $state = $this->settleOne($id);
            } catch (Throwable $e) {
                // One bad row must not stop the others; it is retried next run.
                $out['failed']++;
                error_log('p202 android settle: install ' . $id . ' failed: ' . $e->getMessage());
                continue;
            }
            if ($state === null) {
                continue; // settled meanwhile by another run
            }
            if ($state === MatchState::PENDING_CLICK) {
                $out['still_pending']++;
                continue;
            }
            $out['settled'][$state->value] = ($out['settled'][$state->value] ?? 0) + 1;
        }

        return $out;
    }

    /** The state the install ended in, or null when it was no longer pending. */
    public function settleOne(int $installRowId): ?MatchState
    {
        $work = function () use ($installRowId): array {
            $lock = $this->conn->prepareWrite(
                'SELECT i.*, r.platform, r.app_key, r.accept_test_signals, r.attribution_window_days, r.trust_client_revenue
                 FROM 202_app_installs i JOIN 202_app_registrations r ON r.registration_id = i.registration_id
                 WHERE i.install_row_id = ? LIMIT 1 FOR UPDATE'
            );
            $this->conn->bind($lock, 'i', [$installRowId]);
            $row = $this->conn->fetchOne($lock);
            if ($row === null || (string) $row['match_state'] !== MatchState::PENDING_CLICK->value) {
                return ['state' => null, 'post' => ['ledger' => [], 'clicks' => []], 'user' => 0];
            }
            $registration = new AppRegistration(
                (int) $row['registration_id'],
                (int) $row['user_id'],
                AppIdentity::fromKey((string) $row['platform'], (string) $row['app_key']),
                AppPolicy::fromRow($row),
            );
            // The stored body is the one the intake validated; re-reading it
            // gives the classifier the same payload the intake had.
            $payload = InstallPayload::fromDecoded(json_decode((string) $row['raw_payload'], true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING));
            $parsed = ReferrerParser::parse((string) $payload->installReferrer);
            $first = InstallClassifier::fromReferrer($payload, $parsed, $this->key());
            if ($first['state'] !== null || $first['click_id'] === null) {
                throw new \RuntimeException('install ' . $installRowId . ' is pending_click but its stored referrer no longer names a verified click');
            }
            $now = $this->now();
            $classified = $this->intake->classifyWithClick(
                $registration,
                $payload,
                (int) $first['click_id'],
                $installRowId,
                (int) $row['received_at'],
                $now,
            );
            if ($classified['state'] === MatchState::PENDING_CLICK) {
                return ['state' => MatchState::PENDING_CLICK, 'post' => ['ledger' => [], 'clicks' => []], 'user' => 0];
            }
            $post = $this->intake->settle($registration, (int) $row['is_test'] === 1, $installRowId, $classified['state'], $classified['reason'], $classified['click_id'], $now);

            return ['state' => $classified['state'], 'post' => $post, 'user' => $registration->userId];
        };
        try {
            $done = $this->conn->transaction($work);
        } catch (Throwable $e) {
            if (!Connection::isRetryableLockError($e)) {
                throw $e;
            }
            $done = $this->conn->transaction($work);
        }
        if ($done['user'] > 0) {
            try {
                $this->engine->finishCommitted($done['user'], $done['post']);
            } catch (Throwable $e) {
                error_log('p202 android settle: install ' . $installRowId . ' settled; its report refresh failed: ' . $e->getMessage());
            }
        }

        return $done['state'];
    }

    private function key(): string
    {
        $key = InstallTokenKey::load($this->db);
        if ($key === null) {
            throw new MissingInstallKey();
        }

        return $key;
    }
}
