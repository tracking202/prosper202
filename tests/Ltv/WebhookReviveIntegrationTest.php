<?php

declare(strict_types=1);

namespace Tests\Ltv;

use Api\V3\Controllers\LtvController;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Bridge\EventBridge;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;
use Prosper202\Lpo\CtxToken;
use Prosper202\Ltv\MysqlWebhookRepository;
use Prosper202\Ltv\RecordNotFoundException;
use Prosper202\Ltv\WebhookDispatcher;

/**
 * Reviving a dead LTV webhook, on a real server with a receiver on loopback
 * (LocalWebhookReceiver): what a dead webhook's deliveries become (held,
 * never tried), what a revival sends (what it missed in the last 7 days,
 * once, and nothing it delivered), the failure count it resets, a revival
 * landing between the dispatcher's read and its hold, PUT /ltv/webhooks/{id}
 * through the controller, and the Landing Page Optimizer's pairing webhook
 * going on without pairing again.
 *
 * The dispatcher reads every account's deliveries, so the tables are emptied
 * for each test.
 *
 * @group integration
 */
final class WebhookReviveIntegrationTest extends TestCase
{
    private const USER = 990811;
    private const OTHER = 990812;

    private static ?\mysqli $db = null;
    private static ?LocalWebhookReceiver $receiver = null;

    private string $log = '';
    private string $errorLogBefore = '';

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) {'
                . ' return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        mysqli_report(MYSQLI_REPORT_STRICT);
        try {
            $db = @mysqli_connect(
                $host,
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
        } catch (\Throwable) {
            return;
        }
        if (!$db) {
            return;
        }
        $db->query("SET SESSION sql_mode=''");
        (new SchemaInstaller($db))->install();
        $db->query("SET SESSION sql_mode='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,"
            . "NO_ENGINE_SUBSTITUTION'");
        self::$db = $db;
        self::$receiver = LocalWebhookReceiver::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$receiver?->stop();
        self::$receiver = null;
        if (self::$db !== null) {
            self::$db->query('TRUNCATE TABLE 202_ltv_webhooks');
            self::$db->query('TRUNCATE TABLE 202_ltv_webhook_deliveries');
            self::$db->query('DELETE FROM 202_users_pref WHERE user_id IN (' . self::USER . ', ' . self::OTHER . ')');
            self::$db->close();
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        self::q('TRUNCATE TABLE 202_ltv_webhooks');
        self::q('TRUNCATE TABLE 202_ltv_webhook_deliveries');
        self::q('DELETE FROM 202_users_pref WHERE user_id IN (' . self::USER . ', ' . self::OTHER . ')');
        self::receiver()->forget();
        $log = tempnam(sys_get_temp_dir(), 'p202-ltv-revive-log');
        self::assertIsString($log);
        $this->log = $log;
        $this->errorLogBefore = (string) ini_get('error_log');
        ini_set('error_log', $log);
    }

    protected function tearDown(): void
    {
        if ($this->log !== '') {
            ini_set('error_log', $this->errorLogBefore);
            if (is_file($this->log)) {
                unlink($this->log);
            }
        }
    }

    private static function receiver(): LocalWebhookReceiver
    {
        self::assertNotNull(self::$receiver);

        return self::$receiver;
    }

    private static function conn(): Connection
    {
        self::assertNotNull(self::$db);

        return new Connection(self::$db);
    }

    private static function repo(): MysqlWebhookRepository
    {
        return new MysqlWebhookRepository(self::conn());
    }

    /** @return array{delivered: int, failed: int, held: int, expired: int, deferred: int, taken: int, more: bool} */
    private static function dispatch(?callable $clock = null): array
    {
        $base = self::receiver()->base();
        $report = WebhookDispatcher::runExclusive(
            self::conn(),
            time() + 30,
            static fn (array $delivery): array => LocalWebhookReceiver::send($base, $delivery),
            $clock
        );
        self::assertNotNull($report, 'no other run holds the lock');

        return $report;
    }

    /** @return array{webhookId: int, secret: string} */
    private static function hook(int $user = self::USER, array $events = ['revenue.recorded']): array
    {
        return self::repo()->create($user, LocalWebhookReceiver::url(200), $events);
    }

    private static function queue(int $count = 1, int $user = self::USER): void
    {
        for ($i = 0; $i < $count; $i++) {
            self::repo()->enqueue($user, 'revenue.recorded', ['revenue_event_id' => 2000 + $i]);
        }
    }

    /** @return array<string, array<string, string|null>> by delivery id */
    private static function deliveries(): array
    {
        $result = self::$db?->query('SELECT * FROM 202_ltv_webhook_deliveries ORDER BY delivery_id');
        self::assertInstanceOf(\mysqli_result::class, $result);
        $rows = [];
        foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
            $rows[(string) $row['delivery_id']] = $row;
        }

        return $rows;
    }

    private static function status(int $webhookId): ?string
    {
        return self::repo()->get(self::USER, $webhookId)['status'] ?? null;
    }

    private static function q(string $sql): void
    {
        self::assertNotNull(self::$db);
        self::assertTrue(self::$db->query($sql), self::$db->error . ' in ' . $sql);
    }

    /**
     * The receiver's 2xx-answered requests' delivery ids, in order.
     *
     * @return list<?string>
     */
    private static function received(): array
    {
        return array_column(self::receiver()->requests(), 'delivery');
    }

    /** Kill the webhook the way the dispatcher does: a delivery's last attempt fails. */
    private static function kill(int $webhookId): string
    {
        self::receiver()->down(true);
        self::queue();
        $last = (string) array_key_last(self::deliveries());
        self::q('UPDATE 202_ltv_webhook_deliveries SET attempts = ' . (MysqlWebhookRepository::MAX_ATTEMPTS - 1)
            . ' WHERE delivery_id = ' . $last);
        self::dispatch();
        self::assertSame('dead', self::status($webhookId), 'the last attempt failed and marked it dead');
        self::receiver()->down(false);
        self::receiver()->forget();

        return $last;
    }

    public function testADeadWebhookHoldsItsDeliveriesAndARevivalSendsWhatItMissedOnce(): void
    {
        $hook = self::hook();
        self::queue();
        self::assertSame(1, self::dispatch()['delivered']);
        $delivered = (string) array_key_first(self::deliveries());
        $killer = self::kill($hook['webhookId']);

        // While dead: queued, then held at the next run, never tried.
        self::queue(2);
        $report = self::dispatch();
        self::assertSame(
            [2, 0, 0],
            [$report['held'], $report['delivered'], $report['failed']],
            WebhookDispatcher::summary($report)
        );
        self::assertStringContainsString('2 held for a dead webhook', WebhookDispatcher::summary($report));
        $held = array_map(strval(...), array_slice(array_keys(self::deliveries()), 2));
        foreach ($held as $id) {
            $row = self::deliveries()[$id];
            self::assertSame(
                ['failed', '0', MysqlWebhookRepository::HELD_REASON],
                [$row['status'], $row['attempts'], $row['last_response_body']]
            );
        }
        self::assertSame([], self::received(), 'nothing is sent to a dead webhook');
        self::assertSame([$hook['webhookId'] => 3], self::repo()->missedCounts(self::USER, time()));

        $revival = self::repo()->revive(self::USER, $hook['webhookId'], true, time());
        self::assertSame(['revived' => true, 'requeued' => 3, 'attempts_reset' => 0], $revival);
        self::assertSame('active', self::status($hook['webhookId']));
        foreach ([$killer, ...$held] as $id) {
            $row = self::deliveries()[$id];
            self::assertSame('pending/0', $row['status'] . '/' . $row['attempts']);
        }

        $report = self::dispatch();
        self::assertSame(3, $report['delivered'], WebhookDispatcher::summary($report));
        self::assertSame([$killer, ...$held], self::received(), 'each missed delivery once, oldest first');
        self::assertNotContains($delivered, self::received(), 'a delivered one is not sent again');
        self::assertFalse(WebhookDispatcher::didWork(self::dispatch()));
        self::assertCount(3, self::received());
    }

    public function testWithoutRequeueOnlyNewEventsAreSent(): void
    {
        $hook = self::hook();
        $killer = self::kill($hook['webhookId']);

        self::assertSame(
            ['revived' => true, 'requeued' => 0, 'attempts_reset' => 0],
            self::repo()->revive(self::USER, $hook['webhookId'], false, time())
        );
        self::queue();
        $new = (string) array_key_last(self::deliveries());
        self::assertSame(1, self::dispatch()['delivered']);
        self::assertSame([$new], self::received());
        self::assertSame('failed', self::deliveries()[$killer]['status'], 'the missed one stays failed');
    }

    public function testARevivalStartsTheFailureCountAgain(): void
    {
        $hook = self::hook();
        self::queue(2);
        self::q('UPDATE 202_ltv_webhook_deliveries SET attempts = ' . (MysqlWebhookRepository::MAX_ATTEMPTS - 1));
        self::q("UPDATE 202_ltv_webhooks SET status = 'dead' WHERE webhook_id = " . $hook['webhookId']);

        self::assertSame(2, self::repo()->revive(self::USER, $hook['webhookId'], false, time())['attempts_reset']);
        self::receiver()->down(true);
        $report = self::dispatch();
        self::assertSame(2, $report['failed'], WebhookDispatcher::summary($report));
        foreach (self::deliveries() as $row) {
            self::assertSame('pending/1', $row['status'] . '/' . $row['attempts']);
        }
        self::assertSame('active', self::status($hook['webhookId']), 'one more failure does not kill it again');
    }

    public function testOnlyTheLastSevenDaysAreSentAgain(): void
    {
        $hook = self::hook();
        $killer = self::kill($hook['webhookId']);
        self::queue();
        $old = (string) array_key_last(self::deliveries());
        self::dispatch();
        $weekAgo = time() - WebhookDispatcher::MAX_AGE_SECONDS - 60;
        self::q('UPDATE 202_ltv_webhook_deliveries SET created_at = ' . $weekAgo . ' WHERE delivery_id = ' . $old);

        self::assertSame(1, self::repo()->revive(self::USER, $hook['webhookId'], true, time())['requeued']);
        self::assertSame('pending', self::deliveries()[$killer]['status']);
        self::assertSame('failed', self::deliveries()[$old]['status'], 'older than 7 days: not sent');
    }

    public function testAnActiveWebhookIsLeftAsItIsAndAnotherAccountsIsNotFound(): void
    {
        $hook = self::hook();
        $killer = self::kill($hook['webhookId']);
        self::q("UPDATE 202_ltv_webhooks SET status = 'active' WHERE webhook_id = " . $hook['webhookId']);

        self::assertSame(
            ['revived' => false, 'requeued' => 0, 'attempts_reset' => 0],
            self::repo()->revive(self::USER, $hook['webhookId'], true, time())
        );
        self::assertSame('failed', self::deliveries()[$killer]['status']);

        self::q("UPDATE 202_ltv_webhooks SET status = 'dead' WHERE webhook_id = " . $hook['webhookId']);
        try {
            self::repo()->revive(self::OTHER, $hook['webhookId'], true, time());
            self::fail('another account revived the webhook');
        } catch (RecordNotFoundException) {
        }
        self::assertSame('dead', self::status($hook['webhookId']));
        self::assertSame('failed', self::deliveries()[$killer]['status']);
    }

    /**
     * The dispatcher read the delivery while its webhook was dead, and the
     * webhook was revived before the hold: the hold asks again, finds it
     * active, and the delivery is sent.
     */
    public function testARevivalBetweenTheDispatchersReadAndItsHoldIsSent(): void
    {
        $other = self::hook(self::OTHER);
        $hook = self::hook();
        self::repo()->enqueue(self::OTHER, 'revenue.recorded', ['revenue_event_id' => 1]);
        self::queue();
        self::q("UPDATE 202_ltv_webhooks SET status = 'dead' WHERE webhook_id = " . $hook['webhookId']);
        $mine = (string) array_key_last(self::deliveries());

        $revived = false;
        $clock = function () use (&$revived, $hook): int {
            // Asked before the second delivery: after the read, before the hold.
            if (!$revived && count(self::received()) === 1) {
                $revived = true;
                self::repo()->revive(self::USER, $hook['webhookId'], true, time());
            }

            return time();
        };
        $report = self::dispatch($clock);

        self::assertTrue($revived, 'the revival landed mid-run');
        self::assertSame([2, 0], [$report['delivered'], $report['held']], WebhookDispatcher::summary($report));
        self::assertSame($mine, self::received()[1]);
        self::assertSame('delivered', self::deliveries()[$mine]['status']);
        self::assertSame('active', self::repo()->get(self::OTHER, $other['webhookId'])['status'] ?? null);
    }

    public function testPutRevivesThroughTheController(): void
    {
        $hook = self::hook();
        self::kill($hook['webhookId']);
        $api = new LtvController(self::$db ?? throw new \LogicException(), self::USER);

        $answer = $api->updateWebhook($hook['webhookId'], ['status' => 'active']);
        self::assertSame(true, $answer['data']['revived']);
        self::assertSame(1, $answer['data']['requeued']);
        self::assertSame(true, $answer['data']['requeue']);
        self::assertSame('active', $answer['data']['status']);
        self::assertArrayNotHasKey('webhook_secret', $answer['data']);
        $again = $api->updateWebhook($hook['webhookId'], ['status' => 'active', 'requeue' => 'false'])['data'];
        self::assertSame([false, false], [$again['revived'], $again['requeue']], 'already active: nothing changed');

        $killer = self::kill($hook['webhookId']);
        $without = $api->updateWebhook($hook['webhookId'], ['status' => 'active', 'requeue' => false])['data'];
        self::assertSame([true, 0], [$without['revived'], $without['requeued']]);
        self::assertSame('failed', self::deliveries()[$killer]['status'], 'requeue false sends nothing again');

        $refusals = [
            [[], 'status'],
            [['status' => 'dead'], 'status'],
            [['status' => 'disabled'], 'status'],
            [['status' => 'active', 'requeue' => 'maybe'], 'requeue'],
            [['status' => 'active', 'url' => 'https://elsewhere.example/'], 'url'],
        ];
        foreach ($refusals as [$body, $field]) {
            try {
                $api->updateWebhook($hook['webhookId'], $body);
                self::fail('accepted ' . json_encode($body));
            } catch (ValidationException $e) {
                self::assertArrayHasKey($field, $e->getFieldErrors(), json_encode($body));
            }
        }
        try {
            (new LtvController(self::$db, self::OTHER))->updateWebhook($hook['webhookId'], ['status' => 'active']);
            self::fail('another account revived the webhook');
        } catch (NotFoundException) {
        }
    }

    /**
     * The Landing Page Optimizer pairs once: its webhook's id, secret and the
     * t202ctx key derived from the secret are stored with the pairing
     * (202-account/api-integrations.php). A revival keeps all three, so the
     * events the pairing missed while the webhook was dead reach it signed
     * with the secret the optimizer holds, and new ones follow, with no
     * pairing again.
     */
    public function testARevivedLpoPairingWebhookGoesOnWithoutPairingAgain(): void
    {
        $hook = self::hook(self::USER, ['*']);
        $state = json_encode([
            'webhook_id' => $hook['webhookId'],
            'ctx_key' => bin2hex(CtxToken::deriveKey($hook['secret'])),
            'config' => ['enabled_events' => ['*']],
        ]);
        self::q('INSERT INTO 202_users_pref SET user_id = ' . self::USER . ", lpo_status = 'active',"
            . " lpo_bridge_config = '" . self::$db?->real_escape_string((string) $state) . "'");
        self::q("UPDATE 202_ltv_webhooks SET status = 'dead' WHERE webhook_id = " . $hook['webhookId']);

        EventBridge::emit(self::conn(), self::USER, 'conversion.recorded', ['click_id' => 77, 'payout' => 1.5]);
        self::assertSame(1, self::dispatch()['held'], 'the optimizer\'s event is held while its webhook is dead');

        $answer = (new LtvController(self::$db ?? throw new \LogicException(), self::USER))
            ->updateWebhook($hook['webhookId'], ['status' => 'active']);
        self::assertSame([true, 1], [$answer['data']['revived'], $answer['data']['requeued']]);

        self::assertSame(1, self::dispatch()['delivered']);
        EventBridge::emit(self::conn(), self::USER, 'conversion.recorded', ['click_id' => 78, 'payout' => 2.0]);
        self::assertSame(1, self::dispatch()['delivered'], 'a new event goes as it did before the webhook died');

        $requests = self::receiver()->requests();
        self::assertCount(2, $requests);
        foreach ($requests as $i => $request) {
            self::assertSame('conversion.recorded', $request['event']);
            self::assertSame(
                'sha256=' . hash_hmac('sha256', $request['body'], $hook['secret']),
                $request['signature'],
                'signed with the secret the pairing handed over'
            );
            $body = json_decode($request['body'], true, 8, JSON_THROW_ON_ERROR);
            self::assertSame(77 + $i, $body['data']['payload']['click_id']);
        }

        // The pairing's own record still names this webhook and its key.
        $read = self::$db?->query('SELECT w.webhook_id, w.webhook_secret, p.lpo_bridge_config, p.lpo_status'
            . ' FROM 202_ltv_webhooks w JOIN 202_users_pref p ON p.user_id = w.user_id'
            . ' WHERE w.webhook_id = ' . $hook['webhookId']);
        self::assertInstanceOf(\mysqli_result::class, $read);
        $row = $read->fetch_assoc();
        self::assertIsArray($row);
        self::assertSame($hook['secret'], $row['webhook_secret']);
        self::assertSame('active', $row['lpo_status']);
        $stored = json_decode((string) $row['lpo_bridge_config'], true, 8, JSON_THROW_ON_ERROR);
        self::assertSame($hook['webhookId'], $stored['webhook_id']);
        self::assertSame(bin2hex(CtxToken::deriveKey((string) $row['webhook_secret'])), $stored['ctx_key']);
        self::assertSame($hook['webhookId'], EventBridge::readState(self::conn(), self::USER)['webhook_id']);
    }
}
