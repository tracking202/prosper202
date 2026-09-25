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
 * connection, a timeout, 5xx, 408, 429) is retried after 1, 2 and then 4
 * minutes, up to MAX_ATTEMPTS runs in all; one it cannot (a refused
 * destination, a redirect, another 4xx, a body too large) fails the job
 * at once. The file stays downloadable either way.
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
        $report = ['completed' => 0, 'failed' => 0, 'retrying' => 0, 'reclaimed' => 0];
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
     * @return 'completed'|'failed'|'retrying'
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
            $this->store->fail($exportId, $e->getMessage(), null, $now);

            return 'failed';
        }

        try {
            $name = $row['file_path'] !== null && ExportFiles::isName((string) $row['file_path'])
                ? $this->rewrite((int) $row['user_id'], $exportId, (string) $row['file_path'], $body)
                : $this->files->write((int) $row['user_id'], $exportId, $body);
            $this->store->recordFile($exportId, $name, max(0, substr_count($body, "\n") - 1));
        } catch (QueryException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->store->fail($exportId, $e->getMessage(), null, ($this->clock)());

            return 'failed';
        }

        $url = (string) ($row['webhook_url'] ?? '');
        if ($url === '') {
            $this->store->complete($exportId, null, ($this->clock)());

            return 'completed';
        }

        $attempt = (int) $row['attempts'];
        $result = $this->sender->send($url, (string) $row['webhook_secret'], $body, $exportId, $attempt);
        $now = ($this->clock)();
        if ($result->delivered) {
            $this->store->complete($exportId, $result->status, $now);

            return 'completed';
        }
        if ($result->retryable && $attempt < self::MAX_ATTEMPTS) {
            $this->store->deferRetry(
                $exportId,
                (string) $result->error . ' The file is ready; delivery is tried again (attempt ' . ($attempt + 1) . ' of ' . self::MAX_ATTEMPTS . ').',
                $result->status,
                $now + 60 * (2 ** ($attempt - 1)),
                $now
            );

            return 'retrying';
        }
        $this->store->fail($exportId, (string) $result->error . ' The file is ready to download.', $result->status, $now);

        return 'failed';
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

    /** A retry writes a fresh file and removes the one before it. */
    private function rewrite(int $userId, int $exportId, string $previous, string $body): string
    {
        $name = $this->files->write($userId, $exportId, $body);
        $this->files->remove($previous);

        return $name;
    }
}
