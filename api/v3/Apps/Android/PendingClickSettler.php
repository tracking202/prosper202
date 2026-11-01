<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android;

use Api\V3\Exception\ValidationException;
use Prosper202\Database\Connection;
use Prosper202\Goals\GoalEngine;
use Prosper202\Goals\MysqlGoalRepository;
use Throwable;

/**
 * Settles installs left `pending_click` (plan §5.3): the token verified, but
 * the click it names had not been written yet — the redirect writes its
 * click row after sending the visitor on, and a store round trip can beat
 * it. Run by AndroidIntakeJob, from 202-cronjobs/index.php and app-installs.php.
 *
 * Each install settles in its own transaction, the same shape as the
 * intake's (plan §5.2): the install row is locked FOR UPDATE first, so a
 * concurrent replay of the same install waits for the settle instead of
 * reading a half-settled row; the click is re-read under its lock and the
 * install is classified exactly as the intake would have, from the stored
 * body; and a final state is written with its conversion and notifications
 * in one commit. A click that still does not exist 24 hours after receipt
 * settles as bad_token ("never recorded").
 *
 * The queue (202_app_installs.settle_next_at, settle_failures): a run takes
 * the installs whose turn has come, the ones not tried yet first, then the
 * one whose turn came first, and gives every install it takes its next
 * turn — now for one still waiting for its click, later for one whose
 * settle failed — so the next run starts with the installs this one did not
 * reach. Taken oldest-received first, as they were, a batch of installs that
 * could not settle was the head of every batch, and every other app's
 * installs waited behind it.
 *
 * A failure is retried, backing off, and only one whose cause is the stored
 * install itself ends it: a body that no longer reads as an install, or a
 * token the key that loaded no longer verifies (the key was replaced since
 * it arrived), gives the same answer on every run, so at the same 24 hours
 * a missing click has the install is settled as bad_token, saying why. The
 * day leaves time to put a replaced key back. Anything else — a key that
 * cannot be read, a database error, a lock — is the server's state, not the
 * install's, and is never a reason to end it (CLAUDE.md #11).
 */
final class PendingClickSettler
{
    /** A settle that wrote nothing for the report refresh to follow. */
    private const NOTHING = ['ledger' => [], 'clicks' => []];

    /** The wait after the second failure in a row, doubling with each one after it, up to MAX_BACKOFF. */
    public const FIRST_BACKOFF = 60;
    public const MAX_BACKOFF = 3600;
    /** 202_app_installs.settle_failures is a smallint unsigned; the count stops there rather than overflow. */
    public const FAILURES_CEILING = 65535;

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

    /** The wait for the next turn after the $failures-th failure in a row: the next run, then doubling. */
    public static function backoff(int $failures): int
    {
        if ($failures <= 1) {
            return 0;
        }

        return min(self::MAX_BACKOFF, self::FIRST_BACKOFF * (2 ** min(20, $failures - 2)));
    }

    /**
     * Settle up to $limit pending installs whose turn has come.
     *
     * @return array{examined: int, settled: array<string, int>, still_pending: int, failed: int, retired: int}
     */
    public function run(int $limit = 500): array
    {
        // Installs whose registration is gone can never settle here, and
        // nothing else would ever touch them: they are retired first
        // (OrphanedPendingClicks). The selection joins the registration as
        // settleOne() reads it, so one that appears after the sweep cannot
        // take a slot in this batch either. The order is settle_due's
        // (match_state, settle_next_at, then the primary key), so the batch
        // is read from the index rather than by sorting every pending install.
        $now = $this->now();
        (new OrphanedPendingClicks($this->conn))->settleOrphans($now);
        $stmt = $this->conn->prepareWrite(
            "SELECT i.install_row_id FROM 202_app_installs i
             JOIN 202_app_registrations r ON r.registration_id = i.registration_id
             WHERE i.match_state = 'pending_click' AND i.settle_next_at <= ?
             ORDER BY i.settle_next_at, i.install_row_id LIMIT ?"
        );
        $this->conn->bind($stmt, 'ii', [$now, max(1, $limit)]);
        $ids = array_map(static fn (array $r): int => (int) $r['install_row_id'], $this->conn->fetchAll($stmt));

        $out = ['examined' => count($ids), 'settled' => [], 'still_pending' => 0, 'failed' => 0, 'retired' => 0];
        foreach ($ids as $id) {
            try {
                $done = $this->attempt($id);
            } catch (Throwable $e) {
                // One bad row must not stop the others; it is tried again on its next turn.
                $out['failed']++;
                error_log('p202 android settle: install ' . $id . ' failed: ' . $e->getMessage());
                $this->recordFailure($id);
                continue;
            }
            $state = $done['state'];
            if ($state === null) {
                continue; // settled meanwhile by another run
            }
            if ($done['retired']) {
                $out['retired']++;
                continue;
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
        return $this->attempt($installRowId)['state'];
    }

    /**
     * settleOne(), and whether the install was retired as unreadable.
     *
     * @return array{state: ?MatchState, retired: bool}
     */
    private function attempt(int $installRowId): array
    {
        // The install-token key is read before the transaction: read inside
        // it, it would come before the click's lock, which is then refused
        // (MysqlConversionLedger::lockClick()). A key that does not read
        // stops only an install that still needs it.
        $keyFailure = null;
        try {
            $key = $this->key();
        } catch (Throwable $e) {
            $key = null;
            $keyFailure = $e;
        }
        $work = function () use ($installRowId, $key, $keyFailure): array {
            $none = ['state' => null, 'post' => self::NOTHING, 'user' => 0, 'customer' => null, 'retired' => null];
            $row = LockedInstall::read($this->conn, $installRowId);
            if ($row === null || (string) $row['match_state'] !== MatchState::PENDING_CLICK->value) {
                return $none;
            }
            $registration = LockedInstall::registration($row);
            $now = $this->now();
            $receivedAt = (int) $row['received_at'];
            $stored = self::stored((string) $row['raw_payload'], $key, $keyFailure);
            $payload = null;
            $retired = null;
            if (is_string($stored)) {
                if ($now - $receivedAt < InstallClassifier::PENDING_CLICK_TTL) {
                    throw new \RuntimeException('install ' . $installRowId . ' is pending_click but ' . $stored
                        . '; it is tried again until 24 hours after it arrived, then settled as bad_token');
                }
                $retired = 'Not settled within 24 hours: ' . $stored . '.';
                $classified = ['state' => MatchState::BAD_TOKEN, 'reason' => $retired, 'click_id' => null];
            } else {
                $payload = $stored['payload'];
                $classified = $this->intake->classifyWithClick(
                    $registration,
                    $payload,
                    $stored['click_id'],
                    $installRowId,
                    $receivedAt,
                    $now
                );
                if ($classified['state'] === MatchState::PENDING_CLICK) {
                    $this->nextTurn($installRowId, $now);

                    return ['state' => MatchState::PENDING_CLICK] + $none;
                }
            }
            $settled = $this->intake->settle(
                $registration,
                (int) $row['is_test'] === 1,
                $installRowId,
                $classified['state'],
                $classified['reason'],
                $classified['click_id'],
                $now
            );

            // The state written, not the one classified: under Play Integrity's
            // require an attributable install may be held (pending_integrity).
            return [
                'state' => $settled['state'],
                'post' => $settled['post'],
                'user' => $registration->userId,
                'customer' => $payload?->customer,
                'retired' => $retired,
            ];
        };
        try {
            $done = $this->conn->transaction($work);
        } catch (Throwable $e) {
            if (!Connection::isRetryableLockError($e)) {
                throw $e;
            }
            $done = $this->conn->transaction($work);
        }
        if ($done['retired'] !== null) {
            error_log('p202 android settle: install ' . $installRowId . ' settled as bad_token. ' . $done['retired']);
        }
        if ($done['user'] > 0) {
            try {
                $this->engine->finishCommitted($done['user'], $done['post']);
            } catch (Throwable $e) {
                error_log('p202 android settle: install ' . $installRowId . ' settled; its report refresh failed: '
                    . $e->getMessage());
            }
        }
        // The customer id the install body carried links once the install
        // has a proved click — which, for a pending one, is now.
        if ($done['customer'] instanceof CustomerClaim && $done['state'] === MatchState::ATTRIBUTED) {
            try {
                $this->intake->customer($this->intake->installRow($installRowId), $done['customer']);
            } catch (Throwable $e) {
                error_log('p202 android settle: install ' . $installRowId . ' settled; linking its customer failed: '
                    . $e->getMessage());
            }
        }

        return ['state' => $done['state'], 'retired' => $done['retired'] !== null];
    }

    /**
     * The stored install as the intake read it — its body and the click its
     * token names — or, when the row itself no longer reads so, why: an
     * answer every run would give again. A key that cannot be had is the
     * server's state and is thrown.
     *
     * @return array{payload: InstallPayload, click_id: int}|string
     */
    private static function stored(string $rawPayload, ?string $key, ?Throwable $keyFailure): array|string
    {
        try {
            $decoded = json_decode($rawPayload, true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            $payload = InstallPayload::fromDecoded($decoded);
        } catch (\JsonException $e) {
            return 'its stored body is not JSON (' . $e->getMessage() . ')';
        } catch (ValidationException $e) {
            $fields = implode(', ', array_keys($e->getFieldErrors()));

            return 'its stored body no longer reads as an install (' . $fields . ')';
        }
        if ($key === null) {
            throw $keyFailure ?? new MissingInstallKey();
        }
        $parsed = ReferrerParser::parse((string) $payload->installReferrer);
        $first = InstallClassifier::fromReferrer($payload, $parsed, $key);
        if ($first['state'] !== null || $first['click_id'] === null) {
            return 'its token verified when it arrived, and this server\'s install-token key now reads it as '
                . ($first['state'] ?? MatchState::BAD_TOKEN)->value . ' (' . $first['reason'] . ')';
        }

        return ['payload' => $payload, 'click_id' => $first['click_id']];
    }

    /** An install still waiting for its click goes behind every install whose turn came before now. */
    private function nextTurn(int $installRowId, int $now): void
    {
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_app_installs SET settle_failures = 0, settle_next_at = ? WHERE install_row_id = ?'
        );
        $this->conn->bind($stmt, 'ii', [$now, $installRowId]);
        $this->conn->executeUpdate($stmt);
    }

    /**
     * Count a failed settle (its transaction rolled back) and give the
     * install its next turn after backoff(). A failure to record it is
     * logged: the install then keeps the turn it had, and the next run takes
     * it first again.
     */
    private function recordFailure(int $installRowId): void
    {
        try {
            $this->conn->transaction(function () use ($installRowId): void {
                $read = $this->conn->prepareWrite(
                    "SELECT settle_failures FROM 202_app_installs
                     WHERE install_row_id = ? AND match_state = 'pending_click' FOR UPDATE"
                );
                $this->conn->bind($read, 'i', [$installRowId]);
                $row = $this->conn->fetchOne($read);
                if ($row === null) {
                    return; // settled meanwhile
                }
                $failures = min((int) $row['settle_failures'] + 1, self::FAILURES_CEILING);
                $write = $this->conn->prepareWrite(
                    'UPDATE 202_app_installs SET settle_failures = ?, settle_next_at = ? WHERE install_row_id = ?'
                );
                $this->conn->bind($write, 'iii', [$failures, $this->now() + self::backoff($failures), $installRowId]);
                $this->conn->executeUpdate($write);
            });
        } catch (Throwable $e) {
            error_log('p202 android settle: install ' . $installRowId
                . '\'s failure could not be recorded, so it keeps its turn: ' . $e->getMessage());
        }
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
