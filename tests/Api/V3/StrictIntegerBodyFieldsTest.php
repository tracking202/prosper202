<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\ConversionsController;
use Api\V3\Controllers\LtvController;
use Api\V3\Controllers\RotatorsController;
use Api\V3\Controllers\SyncController;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\ServerStateStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeMysqliConnection;

/**
 * Whole-number fields of the request bodies the handlers read themselves.
 *
 * Each was an `(int)` cast, which keeps a string's leading digits and makes
 * anything else 0: `click_id: "12abc"` recorded the conversion on click 12,
 * `conv_time: "2026-10-07"` dated it 2026 seconds into 1970,
 * `source_customer_id: "12abc"` merged customer 12, and `public_id: "abc"`
 * quietly took a generated id. Each is now a 422 naming the field and its
 * range (QueryInt), before anything is read or written.
 */
final class StrictIntegerBodyFieldsTest extends TestCase
{
    private const NOT_WHOLE = ['abc', '12abc', '2026-10-07', '-1', '1.5', '1e2', ' 5', [5], true, 1.5];

    /** @var list<string> */
    private array $scratch = [];

    protected function tearDown(): void
    {
        foreach ($this->scratch as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{string, string, array<string, mixed>, list<mixed>}> */
    public static function fields(): iterable
    {
        yield 'conversion click_id' => ['conversion', 'click_id', [], ['0']];
        yield 'conversion conv_time' => ['conversion', 'conv_time', ['click_id' => 5], ['2147483648']];
        yield 'customer merge source_customer_id' => ['mergeCustomer', 'source_customer_id', [], ['0']];
        yield 'company merge source_company_id' => ['mergeCompany', 'source_company_id', [], ['0']];
        yield 'rotator public_id' => ['rotator', 'public_id', ['name' => 'R'], ['0', '2147483648']];
        yield 'sync worker limit' => ['worker', 'limit', [], ['0', '101']];

        // MysqlSubscriptionRepository and MysqlCustomerFieldRepository cast
        // these: started_at "2026-10-07" began the subscription in 1970.
        $sub = ['external_sub_id' => 's1', 'amount' => 9, 'customer_ref' => 'c1'];
        $uintPast = '4294967296';
        yield 'subscription billing_interval_count' => ['subscription', 'billing_interval_count', $sub, ['0', $uintPast]];
        yield 'subscription grace_days' => ['subscription', 'grace_days', $sub, [$uintPast]];
        yield 'subscription started_at' => ['subscription', 'started_at', $sub, [$uintPast]];
        yield 'subscription current_period_start' => ['subscription', 'current_period_start', $sub, [$uintPast]];
        yield 'subscription current_period_end' => ['subscription', 'current_period_end', $sub, [$uintPast]];
        yield 'subscription event occurred_at' => ['subscriptionEvent', 'occurred_at', ['event_type' => 'renewal'], [$uintPast]];
        yield 'subscription event current_period_end' => ['subscriptionEvent', 'current_period_end', ['event_type' => 'renewal'], [$uintPast]];
        yield 'field sort_order' => ['field', 'sort_order', ['field_key' => 'tier'], [$uintPast]];
        yield 'field update sort_order' => ['fieldUpdate', 'sort_order', [], [$uintPast]];
    }

    /**
     * @dataProvider fields
     * @param array<string, mixed> $rest the other fields the body needs
     * @param list<mixed> $outOfRange
     */
    public function testAFieldThatIsNotAWholeNumberInRangeIsRefusedNamingIt(string $handler, string $field, array $rest, array $outOfRange): void
    {
        foreach ([...self::NOT_WHOLE, ...$outOfRange] as $value) {
            $conn = new FakeMysqliConnection();
            $shown = var_export($value, true);
            try {
                $this->send($handler, $conn, [$field => $value] + $rest);
                self::fail("$handler($field=$shown) answered instead of refusing");
            } catch (ValidationException $e) {
                self::assertSame([$field], array_keys($e->getFieldErrors()), "$handler($field=$shown): the 422 names $field");
                self::assertStringContainsString('whole number', $e->getFieldErrors()[$field]);
            }
            self::assertSame([], $conn->preparedSql, "$handler($field=$shown): nothing was read or written");
        }
    }

    public function testAConversionWithoutAClickSaysTheClickIsRequired(): void
    {
        try {
            $this->send('conversion', new FakeMysqliConnection(), ['transaction_id' => 'T1']);
            self::fail('a conversion with no click_id was accepted');
        } catch (ValidationException $e) {
            self::assertSame('click_id is required', $e->getMessage());
            self::assertStringStartsWith('Required: a whole number, 1 or more', $e->getFieldErrors()['click_id']);
        }
    }

    /** @param array<string, mixed> $payload */
    private function send(string $handler, FakeMysqliConnection $conn, array $payload): void
    {
        match ($handler) {
            'conversion' => (new ConversionsController($conn, 7))->create($payload),
            'mergeCustomer' => (new LtvController($conn, 7))->mergeCustomer(3, $payload),
            'mergeCompany' => (new LtvController($conn, 7))->mergeCompany(3, $payload),
            'rotator' => (new RotatorsController($conn, 7))->create($payload),
            'subscription' => (new LtvController($conn, 7))->upsertSubscription($payload),
            'subscriptionEvent' => (new LtvController($conn, 7))->subscriptionEvent('s1', $payload),
            'field' => (new LtvController($conn, 7))->createField($payload),
            'fieldUpdate' => (new LtvController($conn, 7))->updateField(3, $payload),
            'worker' => $this->sync($conn)->runWorker($payload),
        };
    }

    private function sync(FakeMysqliConnection $conn): SyncController
    {
        $dir = sys_get_temp_dir() . '/p202-int-body-' . bin2hex(random_bytes(6));
        $this->scratch[] = $dir;

        return new SyncController($conn, 7, new ServerStateStore($dir, 'strict-int-body-test'));
    }
}
