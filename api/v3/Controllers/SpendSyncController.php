<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\TooManyRequestsException;
use Api\V3\Exception\UpstreamUnavailableException;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\PayloadKeys;
use Prosper202\AdPlatforms\AdConnectionStore;
use Prosper202\AdPlatforms\AdPlatformUnavailable;
use Prosper202\Attribution\WebhookSender;
use Prosper202\Database\Connection;
use Prosper202\Spend\OpenAi\AdConnectionSpendConnections;
use Prosper202\Spend\OpenAi\SpendSync;

/**
 * /spend-ledger/sync: whether each ChatGPT Ads connection's spend is
 * pulled into the spend ledger (off until turned on), its last run, and a
 * run now.
 */
final class SpendSyncController
{
    /** How long a run asked for here reads. */
    private const int RUN_SECONDS = 30;

    /** Time left after the last page for its write and the run's record. */
    private const int WRITE_SECONDS = 15;

    /** PHP's max_execution_time when nothing sets it: assumed when it cannot be read. */
    private const int DEFAULT_LIMIT = 30;

    private readonly SpendSync $sync;
    private readonly AdConnectionSpendConnections $connections;

    public function __construct(\mysqli $db, private readonly int $userId)
    {
        $conn = new Connection($db);
        $this->connections = new AdConnectionSpendConnections(new AdConnectionStore($conn));
        $this->sync = new SpendSync($conn, $this->connections);
    }

    /** @return array<string, mixed> */
    public function list(): array
    {
        return ['data' => $this->sync->status($this->userId)];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $connectionId, array $payload): array
    {
        PayloadKeys::refuseUnknown($payload, ['enabled'], 'a spend sync');
        $enabled = $payload['enabled'] ?? null;
        if (!is_bool($enabled)) {
            throw new ValidationException('Invalid enabled', ['enabled' => 'is required: true to pull this connection\'s spend every hour, false to stop']);
        }
        if (!$this->sync->setEnabled($this->userId, $connectionId, $enabled)) {
            throw new NotFoundException('No ChatGPT Ads connection ' . $connectionId . ' (GET /ad-connections lists them)');
        }

        return ['data' => $this->one($connectionId)];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function run(int $connectionId, array $payload): array
    {
        PayloadKeys::refuseUnknown($payload, [], 'a spend sync run (it takes no body)');
        $state = $this->one($connectionId);
        if ($state['enabled'] !== true) {
            throw new ValidationException('Spend sync is off', [
                'enabled' => 'is false for connection ' . $connectionId . ': turn it on first (PUT /spend-ledger/sync/' . $connectionId . ' {"enabled": true})',
            ]);
        }
        $connection = $this->connections->find($this->userId, $connectionId);
        if ($connection === null) {
            throw new NotFoundException('No ChatGPT Ads connection ' . $connectionId . ' (GET /ad-connections lists them)');
        }
        $result = $this->sync->run($connection, self::deadline());
        // A failed run is answered as one (CLAUDE.md #1), as the ad
        // connections answer OpenAI (AdConnectionsController::refusing()):
        // refused here, a refused key or a request OpenAI refused is not
        // fixed by sending it again (422); OpenAI's limit 429, OpenAI not
        // reached 503, its answer unusable 502. The run is recorded either
        // way (GET /spend-ledger/sync), and what it wrote first stands
        // (CLAUDE.md #13): rows_written says how much.
        if ($result['error'] !== null) {
            $wrote = $result['rows'] === 0 ? '' : '; ' . $result['rows'] . ' hour row(s) were written before it stopped, and they stand'
                . ' (the ledger replaces each hour, so running it again adds nothing twice)';
            $said = 'Spend sync run for connection ' . $connectionId . ' failed: ' . $result['error'] . $wrote;
            $details = ['upstream' => SpendSync::PLATFORM, 'connection_id' => $connectionId, 'rows_written' => $result['rows']];
            switch ($result['outcome']) {
                case SpendSync::REFUSED:
                    throw new ValidationException('Spend sync refused' . $wrote, ['connection' => $result['error']]);
                case SpendSync::PAGE_REFUSED:
                    throw new ValidationException('Spend sync stopped at a page the spend ledger refused' . $wrote, ['spend' => $result['error']]);
                case SpendSync::KEY_REFUSED:
                    throw new ValidationException('OpenAI refused connection ' . $connectionId . '\'s Advertiser API key' . $wrote, [
                        'api_key' => $result['error'] . '. Replace the key of connection ' . $connectionId . ' (PATCH /ad-connections/' . $connectionId
                            . ' {"api_key": ...}); the hourly run waits until then or until retry_after',
                    ]);
                case SpendSync::REQUEST_REFUSED:
                    throw new ValidationException('OpenAI refused the spend sync\'s request for connection ' . $connectionId . $wrote, [
                        'request' => $result['error'] . '. Sending the same request again gets the same answer; the ad account or OpenAI\'s API has to change first',
                    ]);
                case SpendSync::RATE_LIMITED:
                    // OpenAI's wait is this request's; the hourly run's longer back-off is in details.retry_after.
                    $wait = $result['wait'] === null || $result['wait'] < 1 ? UpstreamUnavailableException::RETRY_AFTER : min($result['wait'], 7 * 86400);

                    throw new TooManyRequestsException($said . '; a run asked for here may go again after ' . $wait . ' seconds, the hourly run waits until retry_after', $wait,
                        ['details' => $details + ['reason' => AdPlatformUnavailable::RATE_LIMITED, 'retry_after' => $result['retry_after']]]);
                case SpendSync::UNAVAILABLE:
                    $wait = $result['wait'] === null || $result['wait'] < 1 ? UpstreamUnavailableException::RETRY_AFTER : min($result['wait'], 3600);

                    throw new UpstreamUnavailableException($said, $wait, $details + ['reason' => AdPlatformUnavailable::UNREACHABLE]);
                default:
                    throw new UpstreamUnavailableException($said, null, $details + ['reason' => AdPlatformUnavailable::UNREADABLE]);
            }
        }
        $run = ['rows' => $result['rows'], 'from' => $result['from'], 'to' => $result['to'], 'complete' => $result['complete'],
            'error' => null, 'note' => $result['note']];

        return ['data' => ['run' => $run] + $this->one($connectionId)];
    }

    /**
     * When a run asked for here stops reading. The deadline is checked
     * between pages, so the last page may still take a whole request
     * (WebhookSender::TIMEOUT) and its write: PHP's time limit is raised to
     * cover that where it is lower and may be raised, else the run's time is
     * what the limit leaves after it. The run also records how far it read
     * after each day (each hour when its time is short), so a limit that
     * still ends it keeps that.
     */
    private static function deadline(): int
    {
        $after = WebhookSender::TIMEOUT + self::WRITE_SECONDS;
        $limit = self::timeLimit();
        if ($limit === 0 || ($limit !== null && $limit >= self::RUN_SECONDS + $after)) {
            return time() + self::RUN_SECONDS;
        }
        if (function_exists('set_time_limit')) {
            // Its answer is no guide: Linux's PHP 8.3 CLI answers false and raises the limit all
            // the same. What decides is the limit now in force.
            set_time_limit(self::RUN_SECONDS + $after);
            $raised = self::timeLimit();
            if ($raised === 0 || ($raised !== null && $raised >= self::RUN_SECONDS + $after)) {
                return time() + self::RUN_SECONDS;
            }
        }

        // A limit that cannot be read is not "none" (CLAUDE.md #11): PHP's default is assumed.
        return time() + max(1, ($limit ?? self::DEFAULT_LIMIT) - $after);
    }

    /** max_execution_time in seconds (0: none), or null when it cannot be read. */
    private static function timeLimit(): ?int
    {
        $raw = function_exists('ini_get') ? ini_get('max_execution_time') : false;

        return is_string($raw) && preg_match('/\A[0-9]+\z/D', $raw) === 1 ? (int) $raw : null;
    }

    /** @return array<string, mixed> */
    private function one(int $connectionId): array
    {
        foreach ($this->sync->status($this->userId) as $state) {
            if ($state['connection_id'] === $connectionId) {
                return $state;
            }
        }
        throw new NotFoundException('No ChatGPT Ads connection ' . $connectionId . ' (GET /ad-connections lists them)');
    }
}
