<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\LtvController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;

/**
 * A subscription's, a subscription event's and a custom field's own values,
 * through the real repositories (LtvBody::subscription(),
 * subscriptionEvent(), field()).
 *
 * MysqlSubscriptionRepository and MysqlCustomerFieldRepository cast them:
 * `started_at: "2026-10-07"` started the subscription 2026 seconds into
 * 1970, `amount: "abc"` stored a free plan and zeroed the MRR of the
 * subscription it replaced, and `is_required: "false"` made the field
 * required. A period one interval made past 2106-02-07 was a strict-mode
 * "Out of range value", answered 500. StrictBodyFlagsTest and
 * StrictIntegerBodyFieldsTest hold the refusals without a database; this
 * holds that a refused body writes nothing and an accepted one is stored as
 * sent, numeric strings and the spellings of a flag included.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME);
 * it installs the schema there and writes user 5932's rows.
 *
 * @group integration
 */
final class LtvBodyValuesIntegrationTest extends TestCase
{
    private const USER = 5932;

    /** The account's tables the tests write, by user id. */
    private const USER_TABLES = [
        '202_revenue_events', '202_customers', '202_customer_aliases', '202_customer_fields', '202_subscriptions',
        '202_notification_pending',
    ];

    /** What a refused body must leave as it was. */
    private const WRITTEN = ['202_customers', '202_customer_aliases', '202_revenue_events', '202_subscriptions', '202_customer_fields'];

    private static ?\mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        // SchemaInstaller runs its statements through the app's helper.
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) '
                . '{ return $sql === null ? null : $dbOrSql->query($sql); }');
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
        (new SchemaInstaller($db))->install();
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
        }
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (set P202_TEST_DB_HOST).');
        }
        self::cleanUp();
        // Strict, as this server's own default is: a value past its column is
        // an error rather than a silent clamp, which is how the period past
        // 2106 surfaced as a 500.
        self::q("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
    }

    private static function cleanUp(): void
    {
        foreach (self::USER_TABLES as $table) {
            self::$db->query("DELETE FROM $table WHERE user_id = " . self::USER);
        }
    }

    private static function q(string $sql): void
    {
        self::assertTrue(self::$db->query($sql), self::$db->error . ' in ' . $sql);
    }

    /** @return list<array<string, mixed>> */
    private static function rows(string $sql): array
    {
        $result = self::$db->query($sql);
        self::assertInstanceOf(\mysqli_result::class, $result, $sql);

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /** @return array<string, int> how many rows of each write this user has */
    private static function written(): array
    {
        $counts = [];
        foreach (self::WRITTEN as $table) {
            $counts[$table] = (int) self::rows("SELECT COUNT(*) AS c FROM $table WHERE user_id = " . self::USER)[0]['c'];
        }

        return $counts;
    }

    /**
     * @param callable(): mixed $call
     * @return array<string, string> the 422's field errors, or ['' => message] when it named none
     */
    private static function refusedWritingNothing(callable $call, string $what): array
    {
        $before = self::written();
        try {
            $call();
            self::fail("$what was accepted");
        } catch (ValidationException $e) {
            $errors = $e->getFieldErrors() !== [] ? $e->getFieldErrors() : ['' => $e->getMessage()];
        }
        self::assertSame($before, self::written(), "$what: nothing was written");

        return $errors;
    }

    private function ltv(): LtvController
    {
        return new LtvController(self::$db, self::USER);
    }

    /** @return array<string, mixed> */
    private static function subscription(string $externalId): array
    {
        $rows = self::rows("SELECT * FROM 202_subscriptions WHERE user_id = " . self::USER
            . " AND external_sub_id = '" . self::$db->real_escape_string($externalId) . "'");
        self::assertCount(1, $rows, "subscription $externalId");

        return $rows[0];
    }

    public function testASubscriptionSentAsNumericStringsIsStoredAsSent(): void
    {
        $this->ltv()->upsertSubscription([
            'external_sub_id' => 'sub_q', 'customer_ref' => 'cust-q', 'amount' => '29.99', 'billing_interval' => 'month',
            'billing_interval_count' => '3', 'grace_days' => '5', 'started_at' => '1760000000',
            'current_period_start' => 1760000000,
        ]);
        $row = self::subscription('sub_q');
        self::assertSame('29.99000', $row['amount']);
        self::assertSame('3', (string) $row['billing_interval_count']);
        self::assertSame('5', (string) $row['grace_days']);
        self::assertSame('1760000000', (string) $row['started_at']);
        self::assertSame('9.99667', $row['mrr'], 'a quarter of 29.99, by the month');
        self::assertSame((string) strtotime('+3 month', 1760000000), (string) $row['current_period_end']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function unreadableSubscriptions(): iterable
    {
        yield 'a date for a unix time' => [['started_at' => '2026-10-07'], 'started_at'];
        yield 'an amount that is not one' => [['amount' => 'abc'], 'amount'];
        yield 'a fraction of an interval' => [['billing_interval_count' => '1.5'], 'billing_interval_count'];
        yield 'a period end past the column' => [['current_period_end' => 4294967296], 'current_period_end'];
        yield 'a plan name past its column' => [['plan_name' => str_repeat('p', 256)], 'plan_name'];
    }

    /**
     * @dataProvider unreadableSubscriptions
     * @param array<string, mixed> $value
     */
    public function testASubscriptionWhoseValuesCannotBeReadWritesNothing(array $value, string $field): void
    {
        $errors = self::refusedWritingNothing(
            fn () => $this->ltv()->upsertSubscription($value + ['external_sub_id' => 'sub_x', 'customer_ref' => 'cust-x', 'amount' => 9]),
            json_encode($value)
        );
        self::assertSame([$field], array_keys($errors));
    }

    /**
     * One yearly interval 81 times over, from 2025, ends past what the
     * columns hold: a 422 naming the count, not a 500 from the database.
     */
    public function testAPeriodThatWouldEndPast2106IsRefusedNamingTheCount(): void
    {
        $errors = self::refusedWritingNothing(
            fn () => $this->ltv()->upsertSubscription([
                'external_sub_id' => 'sub_long', 'customer_ref' => 'cust-long', 'amount' => 9,
                'billing_interval' => 'year', 'billing_interval_count' => 81, 'started_at' => 1760000000,
            ]),
            'an 81-year interval'
        );
        self::assertStringContainsString('2106-02-07', $errors['']);
        self::assertStringContainsString('lower billing_interval_count', $errors['']);
    }

    public function testARenewalWhoseValuesCannotBeReadWritesNothingAndOneThatCanIsStored(): void
    {
        $this->ltv()->upsertSubscription([
            'external_sub_id' => 'sub_r', 'customer_ref' => 'cust-r', 'amount' => 20, 'started_at' => 1760000000,
        ]);
        foreach ([['occurred_at' => '2026-10-07'], ['amount' => 'abc'], ['current_period_end' => '12abc']] as $value) {
            $errors = self::refusedWritingNothing(
                fn () => $this->ltv()->subscriptionEvent('sub_r', ['event_type' => 'renewal'] + $value),
                'a renewal with ' . json_encode($value)
            );
            self::assertSame(array_keys($value), array_keys($errors));
        }

        $this->ltv()->subscriptionEvent('sub_r', ['event_type' => 'renewal', 'amount' => '12.50', 'occurred_at' => '1765000000']);
        $events = self::rows('SELECT amount, occurred_at FROM 202_revenue_events WHERE user_id = ' . self::USER . " AND event_type = 'renewal'");
        self::assertSame([['amount' => '12.50000', 'occurred_at' => '1765000000']], array_map(
            static fn (array $e): array => ['amount' => (string) $e['amount'], 'occurred_at' => (string) $e['occurred_at']],
            $events
        ));
    }

    /**
     * A renewal advances the period from its end, and one past 2106 is
     * refused with the ledger row the same transaction wrote rolled back.
     */
    public function testARenewalThatWouldEndPast2106IsRefusedAndItsLedgerRowRolledBack(): void
    {
        $this->ltv()->upsertSubscription([
            'external_sub_id' => 'sub_edge', 'customer_ref' => 'cust-edge', 'amount' => 20, 'billing_interval' => 'year',
            'started_at' => 4200000000, 'current_period_end' => 4294000000,
        ]);
        $errors = self::refusedWritingNothing(
            fn () => $this->ltv()->subscriptionEvent('sub_edge', ['event_type' => 'renewal', 'occurred_at' => 4290000000]),
            'a renewal into 2107'
        );
        self::assertStringContainsString('2106-02-07', $errors['']);
        self::assertSame('4294000000', (string) self::subscription('sub_edge')['current_period_end']);
    }

    public function testAFieldIsStoredWithItsFlagAsSent(): void
    {
        $ltv = $this->ltv();
        $optional = $ltv->createField(['field_key' => 'tier', 'is_required' => 'false', 'sort_order' => '7'])['data']['field_id'];
        $required = $ltv->createField(['field_key' => 'plan', 'is_required' => 'true'])['data']['field_id'];
        $stored = static fn (int $id): array => self::rows('SELECT is_required, sort_order, label FROM 202_customer_fields WHERE field_id = ' . $id)[0];
        self::assertSame(['is_required' => '0', 'sort_order' => '7', 'label' => 'tier'], array_map('strval', $stored($optional)), '"false" is not required');
        self::assertSame('1', (string) $stored($required)['is_required']);

        $ltv->updateField($required, ['is_required' => 0]);
        self::assertSame('0', (string) $stored($required)['is_required']);
        $ltv->updateField($required, ['is_required' => '1', 'sort_order' => 2]);
        self::assertSame(['is_required' => '1', 'sort_order' => '2', 'label' => 'plan'], array_map('strval', $stored($required)));

        // null or '' is not given: the label stays, and a body of nothing else says what it takes.
        $errors = self::refusedWritingNothing(fn () => $ltv->updateField($required, ['label' => null, 'is_required' => '']), 'a PATCH of nulls');
        self::assertStringContainsString('No updatable properties', $errors['']);
        self::assertSame('plan', (string) $stored($required)['label']);

        $errors = self::refusedWritingNothing(fn () => $ltv->createField(['field_key' => 'x', 'is_required' => 'no']), 'is_required "no"');
        self::assertSame(['is_required'], array_keys($errors));
    }
}
