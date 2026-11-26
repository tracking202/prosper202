<?php

declare(strict_types=1);

namespace Tests\Ltv;

use Api\V3\Controllers\LtvController;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;
use Prosper202\Ltv\LtvInputException;
use Prosper202\Ltv\MysqlWebhookRepository;
use Prosper202\Ltv\RecordNotFoundException;
use Prosper202\Ltv\WebhookDispatcher;
use Prosper202\Webhooks\StandardWebhook;

/**
 * Standard Webhooks signing end to end on a real server (NRS0): a new
 * webhook's whsec_ secret verifies what a receiver gets, under the id the
 * event was queued with on every attempt; a rotation signs with both
 * secrets until its overlap ends and then with the new one alone; a
 * webhook from before 1.9.81 verifies in a library's form of its secret;
 * the Landing Page Optimizer's webhook and another account's are refused,
 * nothing changed; and POST /ltv/webhooks/{id}/rotate-secret through the
 * controller.
 *
 * @group integration
 */
final class WebhookSecretRotationIntegrationTest extends TestCase
{
    private const USER = 990821;
    private const OTHER = 990822;

    private static ?\mysqli $db = null;
    private static ?LocalWebhookReceiver $receiver = null;

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
    }

    private static function receiver(): LocalWebhookReceiver
    {
        self::assertNotNull(self::$receiver);

        return self::$receiver;
    }

    private static function repo(): MysqlWebhookRepository
    {
        self::assertNotNull(self::$db);

        return new MysqlWebhookRepository(new Connection(self::$db));
    }

    private static function q(string $sql): void
    {
        self::assertNotNull(self::$db);
        self::assertTrue(self::$db->query($sql), self::$db->error . ' in ' . $sql);
    }

    /** @return array<string, string|null> */
    private static function row(int $webhookId): array
    {
        $result = self::$db?->query('SELECT webhook_secret, previous_secret, previous_secret_expires_at, updated_at'
            . ' FROM 202_ltv_webhooks WHERE webhook_id = ' . $webhookId);
        self::assertInstanceOf(\mysqli_result::class, $result);
        $row = $result->fetch_assoc();
        self::assertIsArray($row);

        return $row;
    }

    /** Queue one event and send it; the request the receiver got. */
    private static function sendOne(int $revenueEventId): array
    {
        self::receiver()->forget();
        self::repo()->enqueue(self::USER, 'revenue.recorded', ['revenue_event_id' => $revenueEventId]);
        $base = self::receiver()->base();
        $report = WebhookDispatcher::runExclusive(
            new Connection(self::$db ?? throw new \LogicException()),
            time() + 30,
            static fn (array $delivery): array => LocalWebhookReceiver::send($base, $delivery)
        );
        self::assertSame(1, $report['delivered'] ?? null);
        $requests = self::receiver()->requests();
        self::assertCount(1, $requests);

        return $requests[0];
    }

    private static function verifies(string $secret, array $request): bool
    {
        return StandardWebhook::verify(
            StandardWebhook::libraryForm($secret),
            (string) $request['webhook_id'],
            (string) $request['webhook_timestamp'],
            (string) $request['webhook_signature'],
            (string) $request['body'],
            time()
        );
    }

    public function testANewWebhooksDeliveriesVerifyUnderItsSecretWithTheEventsId(): void
    {
        $hook = self::repo()->create(self::USER, LocalWebhookReceiver::url(200), ['revenue.recorded']);
        self::assertTrue(str_starts_with($hook['secret'], 'whsec_'));

        $request = self::sendOne(3001);
        self::assertTrue(self::verifies($hook['secret'], $request));
        $body = json_decode((string) $request['body'], true, 8, JSON_THROW_ON_ERROR);
        self::assertSame('revenue.recorded.v1', $body['type']);
        self::assertSame($body['id'], $request['webhook_id'], 'the body names the message its headers sign');
        self::assertSame(['revenue_event_id' => 3001], $body['data']);
        self::assertSame('revenue.recorded', $body['event'], 'the older names stay while X-P202-Signature does');
        self::assertSame(
            MysqlWebhookRepository::signature((string) $request['body'], $hook['secret']),
            $request['signature']
        );
    }

    public function testARetryCarriesTheSameIdAndAPreUpgradeDeliveryIsNamedByItsId(): void
    {
        $hook = self::repo()->create(self::USER, LocalWebhookReceiver::url(503), ['revenue.recorded']);
        self::repo()->enqueue(self::USER, 'revenue.recorded', ['revenue_event_id' => 3002]);
        $base = self::receiver()->base();
        $send = static fn (array $delivery): array => LocalWebhookReceiver::send($base, $delivery);
        $conn = new Connection(self::$db ?? throw new \LogicException());
        WebhookDispatcher::runExclusive($conn, time() + 30, $send);
        self::q('UPDATE 202_ltv_webhook_deliveries SET next_attempt_at = 0');
        WebhookDispatcher::runExclusive($conn, time() + 30, $send);
        $ids = array_column(self::receiver()->requests(), 'webhook_id');
        self::assertCount(2, $ids);
        self::assertSame($ids[0], $ids[1], 'a retry is the same message');
        self::assertMatchesRegularExpression('/^msg_[0-9a-f]{32}$/D', (string) $ids[0]);

        // A delivery queued by 1.9.80, whose body has no id.
        self::receiver()->forget();
        self::q("UPDATE 202_ltv_webhooks SET webhook_url = '" . LocalWebhookReceiver::url(200) . "'");
        self::q("UPDATE 202_ltv_webhook_deliveries SET next_attempt_at = 0, payload = '"
            . '{"event":"revenue.recorded","occurred_at":1,"data":{}}' . "'");
        WebhookDispatcher::runExclusive($conn, time() + 30, $send);
        $request = self::receiver()->requests()[0] ?? null;
        self::assertIsArray($request);
        self::assertMatchesRegularExpression('/^msg_ltv_[1-9][0-9]*$/D', (string) $request['webhook_id']);
        self::assertSame('msg_ltv_' . $request['delivery'], $request['webhook_id']);
        self::assertTrue(self::verifies($hook['secret'], $request));
    }

    public function testARotationSignsWithBothSecretsUntilTheOverlapEnds(): void
    {
        $hook = self::repo()->create(self::USER, LocalWebhookReceiver::url(200), ['revenue.recorded']);
        $now = time();
        $rotated = self::repo()->rotateSecret(self::USER, $hook['webhookId'], 3600, $now);
        self::assertNotSame($hook['secret'], $rotated['secret']);
        self::assertTrue(str_starts_with($rotated['secret'], 'whsec_'));
        self::assertSame($now + 3600, $rotated['previous_secret_expires_at']);
        self::assertSame([
            'webhook_secret' => $rotated['secret'],
            'previous_secret' => $hook['secret'],
            'previous_secret_expires_at' => (string) ($now + 3600),
            'updated_at' => (string) $now,
        ], self::row($hook['webhookId']));

        $during = self::sendOne(3003);
        self::assertCount(2, explode(' ', (string) $during['webhook_signature']));
        self::assertTrue(self::verifies($rotated['secret'], $during), 'a receiver changed over already');
        self::assertTrue(self::verifies($hook['secret'], $during), 'a receiver not changed over yet');
        self::assertSame(
            MysqlWebhookRepository::signature((string) $during['body'], $rotated['secret']),
            $during['signature'],
            'X-P202-Signature carries one signature: the new secret\'s'
        );

        self::q('UPDATE 202_ltv_webhooks SET previous_secret_expires_at = ' . (time() - 1));
        $after = self::sendOne(3004);
        self::assertCount(1, explode(' ', (string) $after['webhook_signature']));
        self::assertTrue(self::verifies($rotated['secret'], $after));
        self::assertFalse(self::verifies($hook['secret'], $after), 'the old secret has stopped');

        // No overlap: the old one stops at once, and the one before it too.
        $again = self::repo()->rotateSecret(self::USER, $hook['webhookId'], 0, time());
        self::assertNull($again['previous_secret_expires_at']);
        $row = self::row($hook['webhookId']);
        self::assertSame([$again['secret'], null, null], [$row['webhook_secret'], $row['previous_secret'], $row['previous_secret_expires_at']]);
        $last = self::sendOne(3005);
        self::assertTrue(self::verifies($again['secret'], $last));
        self::assertFalse(self::verifies($rotated['secret'], $last));
    }

    public function testAWebhookFromBeforeTheChangeVerifiesInALibrarysFormOfItsSecret(): void
    {
        $legacy = self::repo()->create(self::USER, LocalWebhookReceiver::url(200), ['revenue.recorded'], false);
        self::assertMatchesRegularExpression('/^[0-9a-f]{48}$/D', $legacy['secret']);

        $request = self::sendOne(3006);
        self::assertTrue(self::verifies($legacy['secret'], $request));
        self::assertSame('sha256=' . hash_hmac('sha256', (string) $request['body'], $legacy['secret']), $request['signature']);
    }

    public function testTheOptimizersWebhookAndAnotherAccountsAreNotRotated(): void
    {
        $paired = self::repo()->create(self::USER, LocalWebhookReceiver::url(200), ['*'], false);
        $own = self::repo()->create(self::USER, LocalWebhookReceiver::url(200), ['*']);
        self::q('INSERT INTO 202_users_pref SET user_id = ' . self::USER . ", lpo_status = 'active',"
            . " lpo_bridge_config = '" . json_encode(['webhook_id' => $paired['webhookId'], 'ctx_key' => 'ab']) . "'");
        $before = self::row($paired['webhookId']);

        try {
            self::repo()->rotateSecret(self::USER, $paired['webhookId'], 3600, time());
            self::fail('the optimizer\'s webhook was rotated');
        } catch (LtvInputException $e) {
            self::assertSame('webhook_id', $e->field);
            self::assertStringContainsString('Landing Page Optimizer', $e->getMessage());
        }
        self::assertSame($before, self::row($paired['webhookId']), 'nothing changed');

        // Another webhook of the same account rotates.
        self::assertTrue(str_starts_with(self::repo()->rotateSecret(self::USER, $own['webhookId'], 0, time())['secret'], 'whsec_'));

        // A pairing record that cannot be read refuses: whose webhook it names cannot be told.
        self::q("UPDATE 202_users_pref SET lpo_bridge_config = '{not json' WHERE user_id = " . self::USER);
        try {
            self::repo()->rotateSecret(self::USER, $own['webhookId'], 0, time());
            self::fail('rotated beside an unreadable pairing record');
        } catch (LtvInputException $e) {
            self::assertSame('webhook_id', $e->field);
        }

        $before = self::row($own['webhookId']);
        try {
            self::repo()->rotateSecret(self::OTHER, $own['webhookId'], 0, time());
            self::fail('another account rotated the webhook');
        } catch (RecordNotFoundException) {
        }
        self::assertSame($before, self::row($own['webhookId']));
    }

    public function testRotateSecretThroughTheController(): void
    {
        $hook = self::repo()->create(self::USER, LocalWebhookReceiver::url(200), ['revenue.recorded']);
        $api = new LtvController(self::$db ?? throw new \LogicException(), self::USER);

        $answer = $api->rotateWebhookSecret($hook['webhookId'], [])['data'];
        self::assertSame($hook['webhookId'], $answer['webhook_id']);
        self::assertSame(24, $answer['overlap_hours']);
        self::assertSame(self::row($hook['webhookId'])['webhook_secret'], $answer['secret']);
        self::assertSame($hook['secret'], self::row($hook['webhookId'])['previous_secret']);
        self::assertIsInt($answer['previous_secret_expires_at']);
        self::assertEqualsWithDelta(time() + 86400, $answer['previous_secret_expires_at'], 5);

        $zero = $api->rotateWebhookSecret($hook['webhookId'], ['overlap_hours' => 0])['data'];
        self::assertNull($zero['previous_secret_expires_at']);

        foreach ([
            [['overlap_hours' => '24'], 'overlap_hours'],
            [['overlap_hours' => 169], 'overlap_hours'],
            [['overlap_hours' => -1], 'overlap_hours'],
            [['overlap_hours' => 1.5], 'overlap_hours'],
            [['overlap_hours' => true], 'overlap_hours'],
            [['secret' => 'whsec_chosen'], 'secret'],
        ] as [$body, $field]) {
            try {
                $api->rotateWebhookSecret($hook['webhookId'], $body);
                self::fail('accepted ' . json_encode($body));
            } catch (ValidationException $e) {
                self::assertArrayHasKey($field, $e->getFieldErrors(), (string) json_encode($body));
            }
        }
        self::assertSame($zero['secret'], self::row($hook['webhookId'])['webhook_secret'], 'a refusal changed nothing');

        try {
            (new LtvController(self::$db, self::OTHER))->rotateWebhookSecret($hook['webhookId'], []);
            self::fail('another account rotated the webhook');
        } catch (NotFoundException) {
        }

        self::assertArrayNotHasKey('webhook_secret', $api->listWebhooks()['data'][0]);
        self::assertArrayNotHasKey('previous_secret', $api->listWebhooks()['data'][0], 'neither secret is listed');
    }
}
