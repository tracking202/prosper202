<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\LtvController;
use Api\V3\Controllers\SyncController;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\LtvBody;
use Api\V3\Support\RequestFlag;
use Api\V3\Support\ServerStateStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeMysqliConnection;

/**
 * The true/false fields of the hand-read bodies, and a subscription's
 * amount.
 *
 * A sync job's flags were `(bool) ($payload['force_update'] ?? false)` and a
 * custom field's is_required `!empty($payload['is_required'])`; both make
 * every non-empty string true, so `"force_update": "false"` queued a job
 * that overwrote the target's differing records and `"is_required": "false"`
 * made the field required. Each is now read by RequestFlag: a JSON true or
 * false, or 1/0 and "true"/"false"/"1"/"0", and anything else is a 422
 * naming the field before anything is read or written.
 */
final class StrictBodyFlagsTest extends TestCase
{
    private const NOT_A_FLAG = ['no', 'yes', 'off', 'on', 'abc', ' true', 'false ', 'tru', 2, -1, 1.0, 0.0, [true], [], ['x' => 1]];

    /** @var list<string> */
    private array $scratch = [];

    protected function tearDown(): void
    {
        foreach ($this->scratch as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        parent::tearDown();
    }

    public function testRequestFlagReadsTheSpellingsOfTrueAndFalseAndNothingElse(): void
    {
        $true = [true, 1, '1', 'true', 'TRUE', 'True'];
        $false = [false, 0, '0', 'false', 'FALSE', 'False'];
        foreach ($true as $value) {
            self::assertTrue(RequestFlag::read($value), var_export($value, true));
            self::assertTrue(RequestFlag::param(['f' => $value], 'f', false), var_export($value, true));
        }
        foreach ($false as $value) {
            self::assertFalse(RequestFlag::read($value), var_export($value, true));
            self::assertFalse(RequestFlag::param(['f' => $value], 'f', true), var_export($value, true));
        }
        foreach ([...self::NOT_A_FLAG, null, ''] as $value) {
            self::assertNull(RequestFlag::read($value), var_export($value, true) . ' is not a flag');
        }
        // Absent, null and '' are the default, whichever it is.
        foreach ([[], ['f' => null], ['f' => '']] as $params) {
            self::assertFalse(RequestFlag::param($params, 'f', false));
            self::assertTrue(RequestFlag::param($params, 'f', true));
        }
        try {
            RequestFlag::param(['f' => 'no'], 'f', false);
            self::fail('"no" was read as a flag');
        } catch (ValidationException $e) {
            self::assertSame(['f' => RequestFlag::ACCEPTED], $e->getFieldErrors());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function flags(): iterable
    {
        foreach (['dry_run', 'skip_errors', 'force_update', 'incremental', 'prune', 'prune_preview'] as $flag) {
            yield "sync job $flag" => ['syncJob', $flag];
        }
        yield 'sync plan prune' => ['syncPlan', 'prune'];
        yield 'sync plan prune_preview' => ['syncPlan', 'prune_preview'];
        yield 'field is_required' => ['field', 'is_required'];
        yield 'field update is_required' => ['fieldUpdate', 'is_required'];
    }

    /** @dataProvider flags */
    public function testAFlagThatIsNotTrueOrFalseIsRefusedNamingIt(string $handler, string $flag): void
    {
        foreach (self::NOT_A_FLAG as $value) {
            $conn = new FakeMysqliConnection();
            $shown = var_export($value, true);
            try {
                $this->send($handler, $conn, [$flag => $value]);
                self::fail("$handler($flag=$shown) answered instead of refusing");
            } catch (ValidationException $e) {
                self::assertSame([$flag => RequestFlag::ACCEPTED], $e->getFieldErrors(), "$handler($flag=$shown)");
            }
            self::assertSame([], $conn->preparedSql, "$handler($flag=$shown): nothing was read or written");
        }
    }

    /**
     * The flag a job is queued with is the one sent: "false" is false. It
     * was true, and a dry run that was not asked for skipped every write, a
     * force_update that was refused overwrote the target.
     */
    public function testASyncJobIsQueuedWithTheFlagsAsSent(): void
    {
        $store = $this->store();
        $sync = new SyncController(new FakeMysqliConnection(), 7, $store);
        $sent = ['dry_run' => 'false', 'skip_errors' => 0, 'force_update' => 'false', 'incremental' => 'true'];
        $answer = $sync->createJob($this->job() + $sent);
        $job = $store->getJob((string) $answer['data']['job_id']);
        self::assertIsArray($job);
        $options = $job['request']['options'];
        self::assertFalse($options['dry_run']);
        self::assertFalse($options['skip_errors']);
        self::assertFalse($options['force_update']);
        self::assertTrue($options['incremental']);
        self::assertFalse($options['prune'], 'left out: false');
    }

    /** @return iterable<string, array{mixed}> */
    public static function notAnAmount(): iterable
    {
        foreach (['abc', '', '12abc', '-1', -0.01, 1000000000, '1e400', true, [9], ['amount' => 9]] as $value) {
            yield var_export($value, true) => [$value];
        }
    }

    /**
     * @dataProvider notAnAmount
     * The repository cast it: "abc" stored a free plan, and the upsert
     * replaced the amount (and the MRR) of the subscription it named.
     */
    public function testASubscriptionAmountThatIsNotOneIsRefused(mixed $amount): void
    {
        $conn = new FakeMysqliConnection();
        try {
            (new LtvController($conn, 7))->upsertSubscription(['external_sub_id' => 's1', 'customer_ref' => 'c1', 'amount' => $amount]);
            self::fail('amount ' . var_export($amount, true) . ' was accepted');
        } catch (ValidationException $e) {
            self::assertSame(['amount'], array_keys($e->getFieldErrors()));
            self::assertStringContainsString('0 to 999999999.99999', $e->getFieldErrors()['amount']);
        }
        self::assertSame([], $conn->preparedSql, 'nothing was read or written');
    }

    public function testASubscriptionWithoutAnAmountIsRefusedSayingItIsRequired(): void
    {
        foreach ([[], ['amount' => null]] as $amount) {
            $conn = new FakeMysqliConnection();
            try {
                (new LtvController($conn, 7))->upsertSubscription(['external_sub_id' => 's1', 'customer_ref' => 'c1'] + $amount);
                self::fail('a subscription without an amount was accepted');
            } catch (ValidationException $e) {
                self::assertStringStartsWith('is required', $e->getFieldErrors()['amount'] ?? '');
            }
            self::assertSame([], $conn->preparedSql);
        }
    }

    public function testTheValuesTheRepositoriesReadAreAccepted(): void
    {
        $subscriptions = [
            ['external_sub_id' => 's1', 'amount' => 0],
            ['external_sub_id' => 's1', 'amount' => '29.99', 'billing_interval_count' => '3', 'grace_days' => 0],
            ['external_sub_id' => 's1', 'amount' => 999999999.99999, 'started_at' => 0, 'current_period_end' => 4294967295],
            ['external_sub_id' => 's1', 'amount' => 9, 'started_at' => null, 'plan_name' => null, 'status' => 'trialing'],
            ['external_sub_id' => str_repeat('s', 191), 'amount' => 9, 'plan_name' => str_repeat('p', 255)],
        ];
        foreach ($subscriptions as $sub) {
            self::assertSame([], LtvBody::subscription($sub), 'accepted: ' . json_encode($sub));
        }
        $events = [
            [],
            ['amount' => '12.50', 'occurred_at' => '1760000000', 'transaction_id' => str_repeat('t', 255)],
            ['amount' => null, 'occurred_at' => null, 'current_period_end' => 4294967295, 'idempotency_key' => 'k1'],
        ];
        foreach ($events as $event) {
            self::assertSame([], LtvBody::subscriptionEvent($event), 'accepted: ' . json_encode($event));
        }
        $fields = [
            ['field_key' => 'tier'],
            ['field_key' => 'tier', 'is_required' => 'false', 'sort_order' => '4'],
            ['label' => str_repeat('l', 255), 'is_required' => 1, 'sort_order' => 4294967295],
            ['is_required' => null, 'sort_order' => null, 'label' => null],
        ];
        foreach ($fields as $field) {
            self::assertSame([], LtvBody::field($field), 'accepted: ' . json_encode($field));
        }
    }

    public function testATextValueThatIsNotTextOrDoesNotFitIsRefused(): void
    {
        self::assertSame(['plan_name'], array_keys(LtvBody::subscription(['amount' => 1, 'plan_name' => str_repeat('p', 256)])));
        self::assertSame(['external_sub_id'], array_keys(LtvBody::subscription(['amount' => 1, 'external_sub_id' => str_repeat('s', 192)])));
        self::assertSame(['status'], array_keys(LtvBody::subscription(['amount' => 1, 'status' => ['active']])));
        self::assertSame(['transaction_id'], array_keys(LtvBody::subscriptionEvent(['transaction_id' => str_repeat('t', 256)])));
        self::assertSame(['label'], array_keys(LtvBody::field(['label' => str_repeat('l', 256)])));
        self::assertSame(['field_key'], array_keys(LtvBody::field(['field_key' => ['tier']])), '["tier"] was the field "array"');
    }

    /** @param array<string, mixed> $payload */
    private function send(string $handler, FakeMysqliConnection $conn, array $payload): void
    {
        match ($handler) {
            'syncJob' => (new SyncController($conn, 7, $this->store()))->createJob($this->job() + $payload),
            'syncPlan' => (new SyncController($conn, 7, $this->store()))->plan($this->job() + $payload),
            'field' => (new LtvController($conn, 7))->createField(['field_key' => 'tier'] + $payload),
            'fieldUpdate' => (new LtvController($conn, 7))->updateField(3, $payload),
        };
    }

    /** @return array<string, mixed> */
    private function job(): array
    {
        return [
            'entity' => 'campaigns',
            'source' => ['url' => 'https://a.example.com', 'api_key' => 'ka'],
            'target' => ['url' => 'https://b.example.com', 'api_key' => 'kb'],
        ];
    }

    private function store(): ServerStateStore
    {
        $dir = sys_get_temp_dir() . '/p202-flag-body-' . bin2hex(random_bytes(6));
        $this->scratch[] = $dir;

        return new ServerStateStore($dir, 'strict-flag-body-test');
    }
}
