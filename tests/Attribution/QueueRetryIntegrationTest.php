<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\AttributionReports;
use Prosper202\Attribution\AttributionWorker;
use Prosper202\Attribution\ModelType;
use Prosper202\Attribution\QueueRetry;
use Tests\Attribution\Support\AttributionDatabase;

/**
 * The Attribution page's "Try again now" (QueueRetry): the account's failed
 * conversions, merges and model changes are due at once, another account's
 * wait their turn, and the page's panel says why each failed.
 *
 * @group integration
 */
final class QueueRetryIntegrationTest extends TestCase
{
    use AttributionDatabase {
        setUpBeforeClass as private connectForAttribution;
    }

    private const LATER = 4_000_000_000;
    private const PAGE = 'https://shop.example/202-account/attribution.php';

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/functions-ui-partials.php';
        require_once dirname(__DIR__, 2) . '/202-config/functions-attribution-ui.php';
        self::connectForAttribution();
    }

    public function testOnlyTheAccountsFailuresAreMadeDue(): void
    {
        $this->campaign(1);
        $now = time();
        $this->click(10, 1, $now - 600);
        $this->click(11, 1, $now - 500);
        $mine = $this->convert(10, '5.00', 'mine');
        $theirs = $this->convert(11, '5.00', 'theirs');
        $this->work();
        self::fixture('UPDATE 202_conversion_logs SET user_id = 2 WHERE conv_id = ' . $theirs);
        foreach ([$mine, $theirs] as $conv) {
            self::fixture("INSERT INTO 202_attribution_pending
                (conv_id, enqueued_at, reason, attempts, last_error, retry_at)
                VALUES ($conv, 1, 'recorded', 3, 'Lock wait timeout exceeded', " . self::LATER . ')');
        }
        $model = $this->addModel('Linear', ModelType::LINEAR);
        self::fixture('UPDATE 202_attribution_models SET recompute_requested_at = NULL');
        self::fixture("UPDATE 202_attribution_models SET recompute_requested_at = 1, recompute_failures = 4,
            recompute_error = 'Deadlock found', recompute_retry_at = " . self::LATER . " WHERE model_id = $model");
        foreach ([1, 2] as $user) {
            self::fixture("INSERT INTO 202_identity_merges
                (user_id, from_key, into_key, click_id, signal_type, signal_hash, merged_at, requeue_failures,
                requeue_retry_at, requeue_error)
                VALUES ($user, 1, 2, 10, 'vid', REPEAT('a', 64), 1700000000, 2, " . self::LATER . ",
                'Table is read only')");
        }
        self::fixture("INSERT INTO 202_identity_merges
            (user_id, from_key, into_key, click_id, signal_type, signal_hash, merged_at, requeue_retired_at)
            VALUES (1, 3, 4, 10, 'vid', REPEAT('b', 64), 1700000000, 5)");

        $queue = (new AttributionReports($this->conn))->queue(1, 20);
        $panel = p202_attr_failures($queue, self::PAGE, 'report', $now, '<input name="token">');
        self::assertStringContainsString('Lock wait timeout exceeded', $panel);
        self::assertStringContainsString('Deadlock found', $panel);
        self::assertStringContainsString('Table is read only', $panel);
        self::assertStringContainsString('Set aside by an earlier version', $panel);
        self::assertStringContainsString('view=journey&amp;conv_id=' . $mine . '"', $panel);
        self::assertStringNotContainsString('conv_id=' . $theirs . '"', $panel, 'another account\'s is not listed');
        self::assertStringContainsString('name="action" value="retry_failed"', $panel);

        self::assertSame(['conversions' => 1, 'merges' => 2, 'models' => 1], QueueRetry::makeDue($this->conn, 1));

        $retryAt = 'SELECT retry_at FROM 202_attribution_pending WHERE conv_id = ';
        self::assertSame(0, (int) self::scalar($retryAt . $mine));
        self::assertSame(self::LATER, (int) self::scalar($retryAt . $theirs));
        self::assertSame(
            0,
            (int) self::scalar('SELECT recompute_retry_at FROM 202_attribution_models WHERE model_id = ' . $model)
        );
        self::assertSame(
            [
                ['user_id' => '1', 'due' => '0', 'retired' => '0'],
                ['user_id' => '2', 'due' => (string) self::LATER, 'retired' => '0'],
            ],
            self::all('SELECT user_id, MAX(requeue_retry_at) AS due, SUM(requeue_retired_at IS NOT NULL) AS retired
                FROM 202_identity_merges GROUP BY user_id ORDER BY user_id')
        );
        $none = ['conversions' => 0, 'merges' => 0, 'models' => 0];
        self::assertSame($none, QueueRetry::makeDue($this->conn, 1), 'nothing left to make due');

        // The run the page makes next takes what was made due: the conversion is credited.
        $report = AttributionWorker::runExclusive($this->conn, 20);
        self::assertNotNull($report);
        $queued = 'SELECT COUNT(*) FROM 202_attribution_pending WHERE conv_id = ';
        self::assertSame(0, (int) self::scalar($queued . $mine));
        self::assertSame(1, (int) self::scalar($queued . $theirs));
    }

    public function testNothingFailingShowsNoPanel(): void
    {
        $queue = (new AttributionReports($this->conn))->queue(1, 20);
        self::assertSame('', p202_attr_failures($queue, self::PAGE, 'report', time(), ''));
    }
}
