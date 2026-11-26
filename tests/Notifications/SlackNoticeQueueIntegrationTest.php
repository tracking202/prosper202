<?php

declare(strict_types=1);

namespace Tests\Notifications;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookSender;
use Prosper202\Database\Connection;
use Prosper202\Notifications\NotificationOutbox;
use Prosper202\Notifications\SlackNoticeQueue;
use Prosper202\Notifications\SlackWebhook;

/**
 * YS8E: a Slack notice is queued by the request and sent by the cron job,
 * to the account's webhook as it is when sent, retried with the outbox's
 * backoff, and deleted once it is settled. The transport is a fake; the
 * queue's SQL runs on the scratch server.
 *
 * @group integration
 */
final class SlackNoticeQueueIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const ACCOUNT = 990081;
    private const OTHER = 990082;
    private const NONE = 990083;
    private const NOW = 1_800_000_000;
    private const HOOK = 'https://hooks.slack.com/services/T0/B0/queue-secret-path';
    private const OTHER_HOOK = 'https://other.slack.example/services/T0/B0/other-secret';

    /** @var list<array<int, mixed>> */
    private array $sent = [];

    /** @var list<int> statuses the fake transport answers in turn; 200 once they run out */
    private array $answers = [];

    private int $now = self::NOW;
    private string $log = '';
    private string|false $savedLog = false;

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
        foreach ([self::ACCOUNT => self::HOOK, self::OTHER => self::OTHER_HOOK, self::NONE => ''] as $user => $hook) {
            self::user($user);
            self::row('202_users_pref', ['user_id' => $user, 'user_slack_incoming_webhook' => $hook]);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
        }
    }

    private static function cleanUp(): void
    {
        foreach ([self::ACCOUNT, self::OTHER, self::NONE] as $user) {
            foreach (['202_slack_notices', '202_users_pref', '202_users'] as $table) {
                self::q("DELETE FROM $table WHERE user_id = $user");
            }
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::q('DELETE FROM 202_slack_notices WHERE user_id IN ('
            . self::ACCOUNT . ', ' . self::OTHER . ', ' . self::NONE . ')');
        self::q('UPDATE 202_users_pref SET user_slack_incoming_webhook = ' . self::str(self::HOOK)
            . ' WHERE user_id = ' . self::ACCOUNT);
        $this->log = (string) tempnam(sys_get_temp_dir(), 'p202-slack-queue-');
        $this->savedLog = ini_get('error_log');
        ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->savedLog === false ? '' : $this->savedLog);
        @unlink($this->log);
    }

    private function queue(): SlackNoticeQueue
    {
        $transport = function (array $options): array {
            $this->sent[] = $options;
            $status = array_shift($this->answers) ?? 200;

            return ['status' => $status, 'error' => $status === 0 ? 'Operation timed out' : null,
                'primary_ip' => null, 'location' => null, 'body' => $status === 404 ? 'no_service' : 'ok'];
        };
        $resolve = static fn (string $host): array => match ($host) {
            'hooks.slack.com' => ['93.184.216.34'],
            'other.slack.example' => ['93.184.216.35'],
            default => [],
        };

        return new SlackNoticeQueue(
            new Connection(self::requireScratch()),
            fn (): int => $this->now,
            new SlackWebhook(new WebhookSender(new WebhookGuard($resolve, ''), $transport))
        );
    }

    /** @return array<string, int|string|null>|null */
    private function stored(int $id): ?array
    {
        $found = self::requireScratch()->query('SELECT * FROM 202_slack_notices WHERE notice_id = ' . $id);
        self::assertInstanceOf(\mysqli_result::class, $found);

        return $found->fetch_assoc();
    }

    private function logged(): string
    {
        return (string) file_get_contents($this->log);
    }

    public function testADeliveredNoticeIsPostedToTheAccountsWebhookAndDeleted(): void
    {
        $id = $this->queue()->queue(self::ACCOUNT, 'failed_login', 'payload={"text":"hi"}');
        $row = $this->stored($id);
        self::assertNotNull($row);
        self::assertSame(
            ['0', (string) self::NOW, (string) self::NOW],
            [$row['attempts'], $row['next_attempt_at'], $row['created_at']]
        );

        self::assertSame(['sent' => 1, 'failed' => 0, 'retrying' => 0], $this->queue()->sendDue(10));
        self::assertCount(1, $this->sent);
        self::assertSame(self::HOOK, $this->sent[0][CURLOPT_URL]);
        self::assertSame('payload={"text":"hi"}', $this->sent[0][CURLOPT_POSTFIELDS]);
        self::assertSame(SlackNoticeQueue::SEND_TIMEOUT, $this->sent[0][CURLOPT_TIMEOUT], 'nobody waits on the cron');
        self::assertNull($this->stored($id), 'a sent notice is not kept');
        self::assertSame('', $this->logged());
    }

    public function testTheWebhookIsTheAccountsAsItIsWhenSent(): void
    {
        $id = $this->queue()->queue(self::ACCOUNT, 'cb_key_verified', 'payload={}');
        self::q('UPDATE 202_users_pref SET user_slack_incoming_webhook = ' . self::str(self::OTHER_HOOK)
            . ' WHERE user_id = ' . self::ACCOUNT);

        self::assertSame(['sent' => 1, 'failed' => 0, 'retrying' => 0], $this->queue()->sendDue(10));
        self::assertSame(self::OTHER_HOOK, $this->sent[0][CURLOPT_URL]);
        self::assertNull($this->stored($id));
    }

    public function testANoticeThatGetsAnErrorIsRetriedWithTheOutboxBackoffThenDropped(): void
    {
        $id = $this->queue()->queue(self::ACCOUNT, 'click_data_deleted', 'payload={}');
        $this->answers = array_fill(0, SlackNoticeQueue::MAX_ATTEMPTS, 503);

        for ($try = 1; $try < SlackNoticeQueue::MAX_ATTEMPTS; $try++) {
            self::assertSame(['sent' => 0, 'failed' => 0, 'retrying' => 1], $this->queue()->sendDue(10), "try $try");
            $row = $this->stored($id);
            self::assertNotNull($row);
            self::assertSame((string) $try, (string) $row['attempts']);
            $due = $this->now + NotificationOutbox::backoff($try);
            self::assertSame((string) $due, (string) $row['next_attempt_at']);
            self::assertStringContainsString('the webhook answered 503', (string) $row['last_error']);
            self::assertStringNotContainsString('queue-secret-path', (string) $row['last_error']);

            $this->now += NotificationOutbox::backoff($try) - 1;
            self::assertSame(['sent' => 0, 'failed' => 0, 'retrying' => 0], $this->queue()->sendDue(10), 'not due yet');
            $this->now += 1;
        }
        self::assertCount(SlackNoticeQueue::MAX_ATTEMPTS - 1, $this->sent);
        self::assertSame('', $this->logged(), 'a notice still being tried is not logged');

        self::assertSame(['sent' => 0, 'failed' => 1, 'retrying' => 0], $this->queue()->sendDue(10));
        self::assertNull($this->stored($id), 'out of attempts, so dropped');
        self::assertStringContainsString('Slack: the "click_data_deleted" notice for account ' . self::ACCOUNT
            . ' was not sent: the webhook answered 503', $this->logged());
        self::assertStringContainsString('after ' . SlackNoticeQueue::MAX_ATTEMPTS . ' tries', $this->logged());
        self::assertStringNotContainsString('queue-secret-path', $this->logged());
    }

    public function testARefusedNoticeIsDroppedAtOnceAndLogged(): void
    {
        $id = $this->queue()->queue(self::ACCOUNT, 'failed_login', 'payload={}');
        $this->answers = [404];

        self::assertSame(['sent' => 0, 'failed' => 1, 'retrying' => 0], $this->queue()->sendDue(10));
        self::assertNull($this->stored($id));
        self::assertStringContainsString(
            'notice for account ' . self::ACCOUNT . ' was not sent: the webhook answered 404: no_service',
            $this->logged()
        );
    }

    public function testANoticeADayOldIsDroppedUnsent(): void
    {
        $id = $this->queue()->queue(self::ACCOUNT, 'failed_login', 'payload={}');
        $this->now += SlackNoticeQueue::MAX_AGE + 1;

        self::assertSame(['sent' => 0, 'failed' => 1, 'retrying' => 0], $this->queue()->sendDue(10));
        self::assertSame([], $this->sent);
        self::assertNull($this->stored($id));
        self::assertStringContainsString('waited more than a day', $this->logged());
    }

    public function testANoticeForAnAccountWithNoWebhookIsDroppedUnsent(): void
    {
        $id = $this->queue()->queue(self::NONE, 'failed_login', 'payload={}');

        self::assertSame(['sent' => 0, 'failed' => 1, 'retrying' => 0], $this->queue()->sendDue(10));
        self::assertSame([], $this->sent);
        self::assertNull($this->stored($id));
        self::assertStringContainsString(
            'notice for account ' . self::NONE . ' was not sent: the account has no Slack webhook now',
            $this->logged()
        );
    }

    public function testAHostThatGaveNoAnswerIsNotAskedAgainThisRunAndTheNextRowsStillGo(): void
    {
        $first = $this->queue()->queue(self::ACCOUNT, 'a', 'payload={}');
        $second = $this->queue()->queue(self::ACCOUNT, 'b', 'payload={}');
        $other = $this->queue()->queue(self::OTHER, 'c', 'payload={}');
        $this->answers = [0];

        // A page of two: the second row of the silent host is passed over and
        // the next page still reaches the other account's (CLAUDE.md #37).
        self::assertSame(['sent' => 1, 'failed' => 0, 'retrying' => 1], $this->queue()->sendDue(2));
        self::assertSame([self::HOOK, self::OTHER_HOOK], array_column($this->sent, CURLOPT_URL));
        self::assertSame('1', (string) ($this->stored($first)['attempts'] ?? ''));
        self::assertSame('0', (string) ($this->stored($second)['attempts'] ?? ''), 'not tried, not counted');
        self::assertNull($this->stored($other));
    }

    public function testOnlyTheNamedNoticesAreSent(): void
    {
        $mine = $this->queue()->queue(self::ACCOUNT, 'a', 'payload={}');
        $theirs = $this->queue()->queue(self::OTHER, 'b', 'payload={}');

        self::assertSame(['sent' => 0, 'failed' => 0, 'retrying' => 0], $this->queue()->sendDue(10, []));
        self::assertSame(['sent' => 1, 'failed' => 0, 'retrying' => 0], $this->queue()->sendDue(10, [$mine]));
        self::assertSame([self::HOOK], array_column($this->sent, CURLOPT_URL));
        self::assertNotNull($this->stored($theirs));
    }

    public function testARowAnotherWorkerClaimedMeanwhileIsNotSentAgain(): void
    {
        $first = $this->queue()->queue(self::ACCOUNT, 'a', 'payload={"n":1}');
        $second = $this->queue()->queue(self::OTHER, 'b', 'payload={"n":2}');
        $resolve = static fn (string $host): array => ['93.184.216.34'];
        $nested = false;
        $other = null;
        // While this worker sends the first row, a second one runs: the first
        // row is claimed, so it tries the second, which gets a 503.
        $transport = function (array $options) use (&$nested, &$other): array {
            $this->sent[] = $options;
            if (!$nested) {
                $nested = true;
                $this->answers = [503];
                // A few seconds on, so its claim writes values this worker's would not.
                $this->now += 5;
                ($other)()->sendDue(10);
            }
            $status = array_shift($this->answers) ?? 200;

            return ['status' => $status, 'error' => null, 'primary_ip' => null, 'location' => null, 'body' => 'ok'];
        };
        $make = fn (): SlackNoticeQueue => new SlackNoticeQueue(
            new Connection(self::requireScratch()),
            fn (): int => $this->now,
            new SlackWebhook(new WebhookSender(new WebhookGuard($resolve, ''), $transport))
        );
        $other = $make;

        // This worker read both rows before the other claimed the second.
        self::assertSame(['sent' => 1, 'failed' => 0, 'retrying' => 0], $make()->sendDue(10));
        self::assertSame(
            ['payload={"n":1}', 'payload={"n":2}'],
            array_column($this->sent, CURLOPT_POSTFIELDS),
            'the second row went out once, from the worker that claimed it'
        );
        self::assertNull($this->stored($first));
        self::assertSame('1', (string) ($this->stored($second)['attempts'] ?? ''));
    }

    public function testAnAccountsNotifierQueuesItsNoticesAndSendsNothingInTheRequest(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/Slack.class.php';
        $savedName = $_SERVER['SERVER_NAME'] ?? null;
        $_SERVER['SERVER_NAME'] = 'tracker.example.com';
        try {
            $slack = \Slack::forAccount(self::requireScratch(), self::ACCOUNT);
            self::assertNotNull($slack);
            self::assertTrue($slack->push('failed_login', ['username' => 'admin', 'ip' => '198.51.100.7']));
            self::assertNull(\Slack::forAccount(self::requireScratch(), self::NONE), 'no webhook, no notifier');
        } finally {
            if ($savedName === null) {
                unset($_SERVER['SERVER_NAME']);
            } else {
                $_SERVER['SERVER_NAME'] = $savedName;
            }
        }
        $found = self::requireScratch()->query(
            'SELECT notice, body FROM 202_slack_notices WHERE user_id = ' . self::ACCOUNT
        );
        self::assertInstanceOf(\mysqli_result::class, $found);
        $rows = $found->fetch_all(MYSQLI_ASSOC);
        self::assertCount(1, $rows);
        self::assertSame('failed_login', $rows[0]['notice']);
        self::assertStringStartsWith('payload=', $rows[0]['body']);
        self::assertStringContainsString('Failed+Login+Attempt', $rows[0]['body']);
        self::assertStringContainsString('"username":"tracker.example.com"', $rows[0]['body'], 'named by the request');
        self::assertStringNotContainsString('hooks.slack.com', $rows[0]['body']);
        self::assertSame('', $this->logged());

        $this->now = time();
        self::assertSame(['sent' => 1, 'failed' => 0, 'retrying' => 0], $this->queue()->sendDue(10));
        self::assertSame($rows[0]['body'], $this->sent[0][CURLOPT_POSTFIELDS]);
    }

    /** Between a deploy and its upgrade the table is not there: the notice goes as it did. */
    public function testANoticeTheQueueCannotTakeIsSentAtOnce(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/Slack.class.php';
        $transport = function (array $options): array {
            $this->sent[] = $options;

            return ['status' => 200, 'error' => null, 'primary_ip' => null, 'location' => null, 'body' => 'ok'];
        };
        $guard = new WebhookGuard(static fn (): array => ['93.184.216.34'], '');
        $webhook = new SlackWebhook(new WebhookSender($guard, $transport));
        $conn = new Connection(self::requireScratch());
        $slack = new \Slack(self::HOOK, $webhook, new SlackNoticeQueue($conn), self::ACCOUNT, $conn);

        self::q('RENAME TABLE 202_slack_notices TO 202_slack_notices_away');
        try {
            self::assertTrue($slack->push('cb_key_verified'));
        } finally {
            self::q('RENAME TABLE 202_slack_notices_away TO 202_slack_notices');
        }
        self::assertSame([self::HOOK], array_column($this->sent, CURLOPT_URL));
        self::assertSame(SlackWebhook::TIMEOUT, $this->sent[0][CURLOPT_TIMEOUT], 'in the request, so its two seconds');
        self::assertStringContainsString(
            'Slack: the "cb_key_verified" notice could not be queued, so it is sent now',
            $this->logged()
        );
        self::assertStringNotContainsString('queue-secret-path', $this->logged());
    }

    public function testAQueuedNoticeNamesAnAccount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->queue()->queue(0, 'failed_login', 'payload={}');
    }

    public function testTheNoticeNameIsCutToItsColumn(): void
    {
        $id = $this->queue()->queue(self::ACCOUNT, str_repeat('é', 70), 'payload={}');
        self::assertSame(str_repeat('é', 64), $this->stored($id)['notice'] ?? null);
    }
}
