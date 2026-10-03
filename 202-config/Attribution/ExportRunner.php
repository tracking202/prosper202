<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;

/**
 * Runs due export jobs (plan §6.3 "Exports"): builds the breakdown the job
 * names, writes it as CSV to the export directory, and delivers it to the
 * job's webhook if it has one.
 *
 * One job's problem stays that job's: a row naming a model that is gone or
 * inactive, a dimension the report does not have, or a range that makes no
 * sense fails that job with the reason and the run carries on. A database
 * error (QueryException) stops the run instead and charges nothing more,
 * like the attribution worker: the job it was on is left running and the
 * stale-run reclaim puts it back.
 *
 * Webhook delivery: a failure the receiver may recover from (no
 * connection, a timeout, 5xx, 408, 429) is retried with a doubling wait
 * (1 minute after the first run, 2 after the second, ...) until the job
 * has had MAX_ATTEMPTS runs in all — with 3, that is two retries; one it
 * cannot recover from (a refused destination, a redirect, another 4xx, a
 * body too large) fails the job at once. The file stays downloadable
 * either way.
 *
 * Ownership. Every write that ends a run is conditional on the job still
 * being `running`. When it matches nothing — the job was reclaimed as
 * stale by another run, or deleted with its account or model while this
 * run held it — the job is reported `lost`, not completed or failed, and
 * a file this run wrote that no row names is removed rather than left on
 * disk with nothing to clean it up.
 */
final class ExportRunner
{
    public const MAX_ATTEMPTS = 3;
    /** A job running longer than this was abandoned by a runner that died. */
    public const STALE_AFTER = 900;
    /** A breakdown with more groups than this fails rather than being cut. */
    public const MAX_ROWS = 50000;

    private ExportStore $store;
    private ExportFiles $files;
    private WebhookSender $sender;
    /** @var callable(): int */
    private $clock;

    /** @param (callable(): int)|null $clock */
    public function __construct(private Connection $conn, ?ExportFiles $files = null, ?WebhookSender $sender = null, ?callable $clock = null)
    {
        $this->store = new ExportStore($conn);
        $this->files = $files ?? new ExportFiles();
        $this->sender = $sender ?? new WebhookSender();
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Run what is due, within a time budget.
     *
     * @return array{completed: int, failed: int, retrying: int, reclaimed: int}
     */
    public function run(int $timeBudgetSeconds = 50, int $batch = 20): array
    {
        $report = ['completed' => 0, 'failed' => 0, 'retrying' => 0, 'lost' => 0, 'reclaimed' => 0];
        $deadline = ($this->clock)() + max(1, $timeBudgetSeconds);
        $report['reclaimed'] = $this->store->reclaimStale(($this->clock)(), self::STALE_AFTER, self::MAX_ATTEMPTS);

        while (($this->clock)() < $deadline) {
            $due = $this->store->due(($this->clock)(), $batch);
            if ($due === []) {
                break;
            }
            $took = 0;
            foreach ($due as $exportId) {
                if (($this->clock)() >= $deadline) {
                    break 2;
                }
                if (!$this->store->claim($exportId, ($this->clock)())) {
                    continue;
                }
                $took++;
                $report[$this->runClaimed($exportId)]++;
            }
            if ($took === 0) {
                break;
            }
        }

        return $report;
    }

    /**
     * Run one job this runner has claimed.
     *
     * @return 'completed'|'failed'|'retrying'|'lost'
     */
    public function runClaimed(int $exportId): string
    {
        $row = $this->store->rowById($exportId);
        if ($row === null || $row['status'] !== 'running') {
            return 'failed';
        }
        $now = ($this->clock)();

        try {
            $body = $this->build($row);
        } catch (QueryException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->ended($exportId, $this->store->fail($exportId, $e->getMessage(), null, $now), 'failed');
        }

        try {
            $name = $this->files->write((int) $row['user_id'], $exportId, $body);
        } catch (QueryException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->ended($exportId, $this->store->fail($exportId, $e->getMessage(), null, ($this->clock)()), 'failed');
        }
        try {
            $this->store->recordFile($exportId, $name, max(0, substr_count($body, "\n") - 1));
        } catch (QueryException $e) {
            // Whether the UPDATE landed is unknown (a connection lost after
            // the commit looks the same), so the file stays: if the row
            // names it, the reclaimed run's retry replaces and removes it.
            throw $e;
        } catch (\Throwable $e) {
            // The UPDATE matched no running job: no row names the file this
            // run just wrote. Remove it, or it stays on disk with nothing
            // that would ever clean it up.
            if (!$this->files->remove($name)) {
                error_log('p202 attribution export ' . $exportId . ': file ' . $name . ' names no job and could not be removed');
            }

            return $this->ended($exportId, false, 'failed');
        }
        // A retry wrote a fresh file; the one before it is no longer named.
        $previous = (string) ($row['file_path'] ?? '');
        if ($previous !== '' && $previous !== $name && ExportFiles::isName($previous)) {
            $this->files->remove($previous);
        }

        $url = (string) ($row['webhook_url'] ?? '');
        if ($url === '') {
            return $this->ended($exportId, $this->store->complete($exportId, null, ($this->clock)()), 'completed');
        }

        $attempt = (int) $row['attempts'];
        $result = $this->sender->send($url, (string) $row['webhook_secret'], $body, $exportId, $attempt);
        $now = ($this->clock)();
        if ($result->delivered) {
            return $this->ended($exportId, $this->store->complete($exportId, $result->status, $now), 'completed');
        }
        if ($result->retryable && $attempt < self::MAX_ATTEMPTS) {
            $landed = $this->store->deferRetry(
                $exportId,
                (string) $result->error . ' The file is ready; delivery is tried again (attempt ' . ($attempt + 1) . ' of ' . self::MAX_ATTEMPTS . ').',
                $result->status,
                $now + 60 * (2 ** ($attempt - 1)),
                $now
            );

            return $this->ended($exportId, $landed, 'retrying');
        }

        return $this->ended(
            $exportId,
            $this->store->fail($exportId, (string) $result->error . ' The file is ready to download.', $result->status, $now),
            'failed'
        );
    }

    /**
     * The outcome of a run's final write: what it recorded, or `lost` when
     * the write matched no running job (another run reclaimed it, or it was
     * deleted while this run held it).
     *
     * @param 'completed'|'failed'|'retrying' $outcome
     * @return 'completed'|'failed'|'retrying'|'lost'
     */
    private function ended(int $exportId, bool $landed, string $outcome): string
    {
        if ($landed) {
            return $outcome;
        }
        error_log('p202 attribution export ' . $exportId . ': no longer running when this run ended it (' . $outcome . '); another run or a delete took it');

        return 'lost';
    }

    /**
     * The job's CSV, or an exception saying why this job cannot be built.
     *
     * @param array<string, mixed> $row
     */
    private function build(array $row): string
    {
        $userId = (int) $row['user_id'];
        $groupBy = (string) $row['group_by'];
        if (!in_array($groupBy, AttributionReports::dimensions(), true)) {
            throw new \UnexpectedValueException('The export groups by "' . $groupBy . '", which is not a report dimension ('
                . implode(', ', AttributionReports::dimensions()) . ').');
        }
        $from = (int) $row['range_start'];
        $to = (int) $row['range_end'];
        if ($from > $to) {
            throw new \UnexpectedValueException('The export range starts after it ends.');
        }
        $models = new ModelRepository($this->conn);
        $modelId = (int) $row['model_id'];
        self::requireActive($models->row($userId, $modelId), $modelId);
        $compareId = $row['compare_model_id'] !== null ? (int) $row['compare_model_id'] : null;
        if ($compareId !== null) {
            self::requireActive($models->row($userId, $compareId), $compareId);
        }
        $default = $models->defaultRow($userId);

        $result = (new AttributionReports($this->conn))->breakdownAll(
            $userId,
            $modelId,
            $compareId,
            $default !== null ? (int) $default['model_id'] : $modelId,
            $groupBy,
            $from,
            $to
        );
        if (count($result['rows']) > self::MAX_ROWS) {
            throw new \UnexpectedValueException('The breakdown has ' . count($result['rows']) . ' groups, more than the '
                . self::MAX_ROWS . ' an export holds; narrow the range or group by a coarser dimension.');
        }

        return ExportCsv::build($result['rows'], $compareId !== null);
    }

    /** @param array<string, mixed>|null $model */
    private static function requireActive(?array $model, int $modelId): void
    {
        if ($model === null) {
            throw new \UnexpectedValueException('Model ' . $modelId . ' no longer exists in this account.');
        }
        if ((string) $model['status'] !== Model::STATUS_ACTIVE) {
            throw new \UnexpectedValueException('Model ' . $modelId . ' is ' . $model['status'] . ', so it has no credits to export.');
        }
    }
}
