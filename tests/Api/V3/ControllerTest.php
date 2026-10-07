<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controller;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\NothingToUpdateException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Api\V3\RequestContext;
use Tests\TestCase;

/**
 * Concrete stub of the abstract Controller for testing.
 */
class StubController extends Controller
{
    public bool $beforeCreateCalled = false;
    public bool $beforeUpdateCalled = false;
    public bool $beforeDeleteCalled = false;
    public array $beforeCreatePayload = [];

    private ?string $deletedCol;

    public function __construct(\mysqli $db, int $userId, ?string $deletedCol = null)
    {
        parent::__construct($db, $userId);
        $this->deletedCol = $deletedCol;
    }

    protected function tableName(): string
    {
        return 'test_items';
    }

    protected function primaryKey(): string
    {
        return 'item_id';
    }

    protected function deletedColumn(): ?string
    {
        return $this->deletedCol;
    }

    protected function fields(): array
    {
        return [
            'name'        => ['type' => 's', 'required' => true, 'max_length' => 100],
            'description' => ['type' => 's', 'max_length' => 500],
            'amount'      => ['type' => 'd'],
            'priority'    => ['type' => 'i'],
            'status'      => ['type' => 's', 'allowed' => ['open', 'closed']],
            'note'        => ['type' => 's', 'nullable' => true, 'max_length' => 20],
            'rank'        => ['type' => 'i', 'range' => [0, 255]],
            'ratio'       => ['type' => 'd', 'range' => [-99.99, 99.99]],
            'created_at'  => ['type' => 's', 'readonly' => true],
        ];
    }

    protected function beforeCreate(array $payload): array
    {
        $this->beforeCreateCalled = true;
        $this->beforeCreatePayload = $payload;
        return [
            'created_at' => ['type' => 's', 'value' => '2025-01-01 00:00:00'],
        ];
    }

    protected function beforeUpdate(int|string $id, array $payload): array
    {
        $this->beforeUpdateCalled = true;
        return [];
    }

    protected function beforeDelete(int|string $id): void
    {
        $this->beforeDeleteCalled = true;
    }

    public function testValidatePayload(array $payload, bool $requireRequired = false, ?array $current = null): array
    {
        return $this->validatePayload($payload, $requireRequired, $current);
    }

    public function testTransaction(callable $fn): mixed
    {
        return $this->transaction($fn);
    }
}

final class ControllerTest extends TestCase
{
    private function createControllerWithDb(array $queryResults = [], ?string $deletedCol = null): array
    {
        $db = $this->createMysqliMock($queryResults);
        $controller = new StubController($db, 1, $deletedCol);
        return [$controller, $db];
    }

    // ─── list() ─────────────────────────────────────────────────────

    public function testListReturnsPaginatedResults(): void
    {
        [$ctrl] = $this->createControllerWithDb([
            'COUNT(*)' => ['total' => 2],
            'SELECT' => [
                ['item_id' => 1, 'name' => 'Item 1', 'user_id' => 1],
                ['item_id' => 2, 'name' => 'Item 2', 'user_id' => 1],
            ],
        ]);

        $result = $ctrl->list([]);

        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('pagination', $result);
        $this->assertSame(2, $result['pagination']['total']);
        $this->assertSame(50, $result['pagination']['limit']);
        $this->assertSame(0, $result['pagination']['offset']);
    }

    public function testListRespectsLimitAndOffsetParams(): void
    {
        [$ctrl] = $this->createControllerWithDb([
            'COUNT(*)' => ['total' => 100],
            'SELECT' => [['item_id' => 11, 'name' => 'Item 11', 'user_id' => 1]],
        ]);

        $result = $ctrl->list(['limit' => 10, 'offset' => 20]);

        $this->assertSame(10, $result['pagination']['limit']);
        $this->assertSame(20, $result['pagination']['offset']);
    }

    public function testListClampsLimitToMax500(): void
    {
        [$ctrl] = $this->createControllerWithDb([
            'COUNT(*)' => ['total' => 0],
            'SELECT' => [],
        ]);
        $result = $ctrl->list(['limit' => 9999]);
        $this->assertSame(500, $result['pagination']['limit']);
    }

    public function testListClampsLimitToMin1(): void
    {
        [$ctrl] = $this->createControllerWithDb([
            'COUNT(*)' => ['total' => 0],
            'SELECT' => [],
        ]);
        $result = $ctrl->list(['limit' => -5]);
        $this->assertSame(1, $result['pagination']['limit']);
    }

    public function testListClampsOffsetToMin0(): void
    {
        [$ctrl] = $this->createControllerWithDb([
            'COUNT(*)' => ['total' => 0],
            'SELECT' => [],
        ]);
        $result = $ctrl->list(['offset' => -10]);
        $this->assertSame(0, $result['pagination']['offset']);
    }

    // ─── get() ──────────────────────────────────────────────────────

    public function testGetReturnsSingleRecord(): void
    {
        [$ctrl] = $this->createControllerWithDb([
            'SELECT' => ['item_id' => 5, 'name' => 'Test Item', 'user_id' => 1],
        ]);

        $result = $ctrl->get(5);

        $this->assertArrayHasKey('data', $result);
        $this->assertSame(5, $result['data']['item_id']);
        $this->assertSame('Test Item', $result['data']['name']);
    }

    public function testGetAddsVersionAndEtagMetadata(): void
    {
        [$ctrl] = $this->createControllerWithDb([
            'SELECT' => ['item_id' => 7, 'name' => 'Versioned', 'user_id' => 1],
        ]);

        $result = $ctrl->get(7);

        $this->assertArrayHasKey('version', $result['data']);
        $this->assertArrayHasKey('etag', $result['data']);
        $this->assertStringStartsWith('"', (string)$result['data']['etag']);
    }

    public function testGetThrowsNotFoundExceptionForMissingId(): void
    {
        [$ctrl] = $this->createControllerWithDb();
        $this->expectException(NotFoundException::class);
        $ctrl->get(999);
    }

    public function testUpdateWithMismatchedIfMatchThrowsConflict(): void
    {
        RequestContext::setHeaders(['If-Match' => '"stale-version"']);
        [$ctrl] = $this->createControllerWithDb([
            'SELECT' => ['item_id' => 5, 'name' => 'Current', 'user_id' => 1],
        ]);

        $this->expectException(ConflictException::class);
        try {
            $ctrl->update(5, ['name' => 'Updated']);
        } finally {
            RequestContext::reset();
        }
    }

    public function testUpdateConflictIncludesExpectedAndCurrentVersions(): void
    {
        RequestContext::setHeaders(['If-Match' => '"stale-version"']);
        [$ctrl] = $this->createControllerWithDb([
            'SELECT' => ['item_id' => 5, 'name' => 'Current', 'user_id' => 1],
        ]);

        try {
            $ctrl->update(5, ['name' => 'Updated']);
            $this->fail('Expected conflict exception was not thrown');
        } catch (ConflictException $e) {
            $details = $e->getDetails();
            $this->assertArrayHasKey('expected_version', $details);
            $this->assertArrayHasKey('current_version', $details);
            $this->assertArrayHasKey('diff_hint', $details);
            $this->assertNotSame((string)$details['expected_version'], (string)$details['current_version']);
        } finally {
            RequestContext::reset();
        }
    }

    public function testBulkUpsertRequiresIdempotencyHeader(): void
    {
        [$ctrl] = $this->createControllerWithDb();
        RequestContext::setHeaders([]);

        $this->expectException(ValidationException::class);
        try {
            $ctrl->bulkUpsert(['rows' => []]);
        } finally {
            RequestContext::reset();
        }
    }

    public function testBulkUpsertRefusesAKeyReusedForDifferentRows(): void
    {
        // The row hash used to be part of the storage scope, so a key reused
        // for a changed batch read a different file, found nothing, and
        // re-applied the whole batch — the duplicate the key was sent to
        // prevent. It is now a fingerprint recorded beside the response, and
        // a changed batch under the same key is refused.
        $stateDir = sys_get_temp_dir() . '/p202-bulk-upsert-state-' . bin2hex(random_bytes(4));
        mkdir($stateDir, 0700, true);
        putenv('P202_SERVER_STATE_DIR=' . $stateDir);

        [$ctrl] = $this->createControllerWithDb();
        RequestContext::setHeaders(['Idempotency-Key' => 'bulk-request-hash-1']);

        try {
            $first = $ctrl->bulkUpsert(['rows' => [[]]]);
            $this->assertFalse((bool)$first['idempotent_replay']);
            $this->assertSame(1, $first['summary']['skipped']);

            $second = $ctrl->bulkUpsert(['rows' => [[]]]);
            $this->assertTrue((bool)$second['idempotent_replay']);

            try {
                $ctrl->bulkUpsert(['rows' => [[], []]]);
                $this->fail('expected a reused key with different rows to be refused');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('idempotency_key', $e->getFieldErrors());
            }

            // A fresh key for the changed batch is the caller's way out.
            RequestContext::setHeaders(['Idempotency-Key' => 'bulk-request-hash-2']);
            $fourth = $ctrl->bulkUpsert(['rows' => [[], []]]);
            $this->assertFalse((bool)$fourth['idempotent_replay']);
            $this->assertSame(2, $fourth['summary']['skipped']);
        } finally {
            RequestContext::reset();
            putenv('P202_SERVER_STATE_DIR');
        }
    }

    public function testBulkUpsertReturnsPerRowErrorsAndSkipsWithoutSilentDrops(): void
    {
        [$ctrl] = $this->createControllerWithDb();
        RequestContext::setHeaders(['Idempotency-Key' => 'bulk-rows-' . bin2hex(random_bytes(6))]);

        try {
            $result = $ctrl->bulkUpsert([
                'rows' => [
                    [],
                    'bad-row',
                    ['name' => str_repeat('a', 101)],
                ],
            ]);
        } finally {
            RequestContext::reset();
        }

        $this->assertSame(1, $result['summary']['skipped']);
        $this->assertSame(2, $result['summary']['error']);
        $this->assertCount(3, $result['data']);
        $this->assertSame('skipped', $result['data'][0]['status']);
        $this->assertSame('error', $result['data'][1]['status']);
        $this->assertSame('error', $result['data'][2]['status']);
    }

    public function testBulkUpsertHonorsConfigurableMaxRowsEnvLimit(): void
    {
        [$ctrl] = $this->createControllerWithDb();
        putenv('P202_MAX_BULK_ROWS=1');
        RequestContext::setHeaders(['Idempotency-Key' => 'bulk-limit-' . bin2hex(random_bytes(4))]);

        $this->expectException(ValidationException::class);
        try {
            $ctrl->bulkUpsert(['rows' => [[], []]]);
        } finally {
            RequestContext::reset();
            putenv('P202_MAX_BULK_ROWS');
        }
    }

    // ─── create() ───────────────────────────────────────────────────

    public function testCreatePassesValidationWithRequiredFields(): void
    {
        $db = $this->createMysqliMock([
            'SELECT' => ['item_id' => 1, 'name' => 'New Item', 'user_id' => 1],
        ]);
        $ctrl = new StubController($db, 1);

        // create() will pass validation and call beforeCreate, then fail on
        // insert_id (C-backed property inaccessible on mock). Catching the
        // Error proves validation succeeded — a ValidationException would
        // propagate instead.
        try {
            $ctrl->create(['name' => 'New Item']);
        } catch (\Error $e) {
            // Expected: mysqli_stmt mock can't expose insert_id
        }
        $this->assertTrue($ctrl->beforeCreateCalled);
    }

    public function testCreateThrowsValidationExceptionOnMissingRequiredField(): void
    {
        [$ctrl] = $this->createControllerWithDb();

        $this->expectException(ValidationException::class);
        $ctrl->create(['description' => 'Only optional']);
    }

    public function testCreateTypeCoercionInt(): void
    {
        $db = $this->createMysqliMock([]);
        $ctrl = new StubController($db, 1);

        // beforeCreate runs before the INSERT, so coerced payload is
        // available even though insert_id access will fail on the mock.
        try {
            $ctrl->create(['name' => 'Test', 'priority' => '5']);
        } catch (\Error $e) {
            // Expected: mock stmt insert_id inaccessible
        }
        $this->assertTrue($ctrl->beforeCreateCalled);
        $this->assertSame(5, $ctrl->beforeCreatePayload['priority']);
    }

    public function testCreateTypeCoercionFloat(): void
    {
        $db = $this->createMysqliMock([]);
        $ctrl = new StubController($db, 1);

        try {
            $ctrl->create(['name' => 'Test', 'amount' => '9.99']);
        } catch (\Error $e) {
            // Expected: mock stmt insert_id inaccessible
        }
        $this->assertTrue($ctrl->beforeCreateCalled);
        $this->assertSame(9.99, $ctrl->beforeCreatePayload['amount']);
    }

    public function testCreateTypeCoercionString(): void
    {
        $db = $this->createMysqliMock([]);
        $ctrl = new StubController($db, 1);

        try {
            $ctrl->create(['name' => 42]);
        } catch (\Error $e) {
            // Expected: mock stmt insert_id inaccessible
        }
        $this->assertTrue($ctrl->beforeCreateCalled);
        $this->assertSame('42', $ctrl->beforeCreatePayload['name']);
    }

    public function testCreateMaxLengthValidation(): void
    {
        [$ctrl] = $this->createControllerWithDb();
        $this->expectException(ValidationException::class);
        $ctrl->create(['name' => str_repeat('a', 101)]);
    }

    public function testCreateCallsBeforeCreateHook(): void
    {
        $db = $this->createMysqliMock([]);
        $ctrl = new StubController($db, 1);

        try {
            $ctrl->create(['name' => 'Test']);
        } catch (\Error $e) {
            // Expected: mock stmt insert_id inaccessible
        }
        $this->assertTrue($ctrl->beforeCreateCalled);
    }

    // ─── update() ───────────────────────────────────────────────────

    public function testUpdateAcceptsReadonlyFieldsHoldingTheRecordsValues(): void
    {
        // A GET body sent back whole: the id, the owner, a read-only field
        // and the version are the record's own, so they change nothing and
        // are accepted alongside the change.
        $row = ['item_id' => 1, 'name' => 'Updated', 'created_at' => '2025-01-01', 'user_id' => 1];
        $db = $this->createMysqliMock(['SELECT' => $row]);
        $ctrl = new StubController($db, 1);
        $read = $ctrl->get(1)['data'];
        $this->assertArrayHasKey('version', $read);

        $result = $ctrl->update(1, ['name' => 'Updated'] + $read);
        $this->assertArrayHasKey('data', $result);
        $this->assertTrue($ctrl->beforeUpdateCalled);
    }

    public function testUpdateRefusesAReadonlyFieldWithAnotherValue(): void
    {
        // The old behaviour dropped it and answered 200: the caller believed
        // created_at had changed.
        $db = $this->createMysqliMock([
            'SELECT' => ['item_id' => 1, 'name' => 'Updated', 'created_at' => '2025-01-01', 'user_id' => 1],
        ]);
        $ctrl = new StubController($db, 1);

        foreach ([['created_at' => '2099-01-01'], ['item_id' => 2], ['user_id' => 7]] as $changed) {
            try {
                $ctrl->update(1, ['name' => 'Updated'] + $changed);
                $this->fail('expected ' . key($changed) . ' with another value to be refused');
            } catch (ValidationException $e) {
                $this->assertSame([key($changed)], array_keys($e->getFieldErrors()));
                $this->assertStringContainsString('read-only', $e->getFieldErrors()[key($changed)]);
            }
        }
        $this->assertFalse($ctrl->beforeUpdateCalled, 'a refused update reaches no write');
    }

    public function testUpdateWithABodyReadFromAnOlderVersionIsAConflict(): void
    {
        $db = $this->createMysqliMock([
            'SELECT' => ['item_id' => 1, 'name' => 'Now', 'user_id' => 1],
        ]);
        $ctrl = new StubController($db, 1);

        foreach (['version' => 'stale0', 'etag' => '"stale0"'] as $key => $stale) {
            try {
                $ctrl->update(1, ['name' => 'Mine', $key => $stale]);
                $this->fail("expected a stale $key to be a conflict");
            } catch (ConflictException $e) {
                $this->assertSame('Version mismatch', $e->getMessage());
                $this->assertSame('stale0', $e->getDetails()['expected_version']);
            }
        }
        $this->assertFalse($ctrl->beforeUpdateCalled);
    }

    public function testUpdateOfOnlyUnchangedReadonlyFieldsHasNothingToUpdate(): void
    {
        $db = $this->createMysqliMock([
            'SELECT' => ['item_id' => 1, 'name' => 'Existing', 'user_id' => 1],
        ]);
        $ctrl = new StubController($db, 1);

        $this->expectException(NothingToUpdateException::class);
        $ctrl->update(1, ['item_id' => 1, 'user_id' => 1]);
    }

    public function testUpdateThrowsNotFoundExceptionForMissingId(): void
    {
        [$ctrl] = $this->createControllerWithDb();
        $this->expectException(NotFoundException::class);
        $ctrl->update(999, ['name' => 'Updated']);
    }

    public function testUpdateRequiresAtLeastOneField(): void
    {
        $db = $this->createMysqliMock([
            'SELECT' => ['item_id' => 1, 'name' => 'Existing', 'user_id' => 1],
        ]);
        $ctrl = new StubController($db, 1);

        $this->expectException(ValidationException::class);
        $ctrl->update(1, ['created_at' => '2099-01-01']);
    }

    public function testUpdateOnlyUnknownFieldsThrowsValidation(): void
    {
        $db = $this->createMysqliMock([
            'SELECT' => ['item_id' => 1, 'name' => 'Existing', 'user_id' => 1],
        ]);
        $ctrl = new StubController($db, 1);

        $this->expectException(ValidationException::class);
        $ctrl->update(1, ['nonexistent_field' => 'value']);
    }

    // ─── delete() ───────────────────────────────────────────────────

    public function testDeleteSoftDeletesWhenDeletedColumnIsSet(): void
    {
        $db = $this->createMysqliMock([
            'SELECT' => ['item_id' => 1, 'name' => 'Test', 'user_id' => 1],
        ]);
        $ctrl = new StubController($db, 1, 'item_deleted');

        $ctrl->delete(1);
        $this->assertTrue($ctrl->beforeDeleteCalled);
    }

    public function testDeleteHardDeletesWhenDeletedColumnIsNull(): void
    {
        $db = $this->createMysqliMock([
            'SELECT' => ['item_id' => 1, 'name' => 'Test', 'user_id' => 1],
        ]);
        $ctrl = new StubController($db, 1, null);

        $ctrl->delete(1);
        $this->assertTrue($ctrl->beforeDeleteCalled);
    }

    public function testDeleteThrowsNotFoundExceptionForMissingId(): void
    {
        [$ctrl] = $this->createControllerWithDb();
        $this->expectException(NotFoundException::class);
        $ctrl->delete(999);
    }

    // ─── validatePayload() ──────────────────────────────────────────

    public function testValidatePayloadCoercesIntType(): void
    {
        [$ctrl] = $this->createControllerWithDb();
        $clean = $ctrl->testValidatePayload(['priority' => '42']);
        $this->assertSame(42, $clean['priority']);
    }

    public function testValidatePayloadCoercesFloatType(): void
    {
        [$ctrl] = $this->createControllerWithDb();
        $clean = $ctrl->testValidatePayload(['amount' => '3.14']);
        $this->assertSame(3.14, $clean['amount']);
    }

    public function testValidatePayloadCoercesStringType(): void
    {
        [$ctrl] = $this->createControllerWithDb();
        $clean = $ctrl->testValidatePayload(['name' => 123]);
        $this->assertSame('123', $clean['name']);
    }

    public function testValidatePayloadRejectsNonNumericInt(): void
    {
        [$ctrl] = $this->createControllerWithDb();
        $this->expectException(ValidationException::class);
        $ctrl->testValidatePayload(['priority' => 'not_a_number']);
    }

    public function testValidatePayloadRejectsNonNumericFloat(): void
    {
        [$ctrl] = $this->createControllerWithDb();
        $this->expectException(ValidationException::class);
        $ctrl->testValidatePayload(['amount' => 'not_a_number']);
    }

    public function testValidatePayloadMaxLengthEnforced(): void
    {
        [$ctrl] = $this->createControllerWithDb();
        $this->expectException(ValidationException::class);
        $ctrl->testValidatePayload(['description' => str_repeat('x', 501)]);
    }

    public function testValidatePayloadMaxLengthPassesAtExactLimit(): void
    {
        [$ctrl] = $this->createControllerWithDb();
        $clean = $ctrl->testValidatePayload(['description' => str_repeat('x', 500)]);
        $this->assertSame(str_repeat('x', 500), $clean['description']);
    }

    public function testValidatePayloadRefusesReadonlyFieldsOnCreate(): void
    {
        // There is no record yet, so no value of a read-only field can be
        // "the one it holds": the server assigns each.
        [$ctrl] = $this->createControllerWithDb();
        try {
            $ctrl->testValidatePayload(['name' => 'Test', 'created_at' => '2025-01-01', 'item_id' => 9, 'user_id' => 1, 'version' => 'v', 'etag' => '"v"'], true);
            $this->fail('expected read-only fields on a create to be refused');
        } catch (ValidationException $e) {
            $errors = $e->getFieldErrors();
            ksort($errors);
            $this->assertSame(['created_at', 'etag', 'item_id', 'user_id', 'version'], array_keys($errors));
            $this->assertStringContainsString('set by the server', $errors['item_id']);
        }
    }

    public function testValidatePayloadRefusesUnknownFieldsByName(): void
    {
        // They used to be dropped with a 200: a typo saved nothing and said
        // nothing.
        [$ctrl] = $this->createControllerWithDb();
        try {
            $ctrl->testValidatePayload(['name' => 'Test', 'nmae' => 'typo', 'nonexistent' => 'value']);
            $this->fail('expected unknown fields to be refused');
        } catch (ValidationException $e) {
            $errors = $e->getFieldErrors();
            $this->assertSame(['nmae', 'nonexistent'], array_keys($errors));
            $this->assertStringContainsString('is not a field of test_items', $errors['nmae']);
            $this->assertStringContainsString('name, description, amount, priority, status, note, rank, ratio', $errors['nmae'], 'the writable fields are listed');
            $this->assertStringNotContainsString('created_at', $errors['nmae'], 'a read-only field is not offered as writable');
        }
    }

    public function testValidatePayloadNullClearsANullableFieldAndIsRefusedOtherwise(): void
    {
        [$ctrl] = $this->createControllerWithDb();
        $clean = $ctrl->testValidatePayload(['note' => null]);
        $this->assertArrayHasKey('note', $clean, 'a null for a nullable field is a write of NULL, not a skip');
        $this->assertNull($clean['note']);

        foreach (['description', 'priority', 'amount', 'status', 'name'] as $field) {
            try {
                $ctrl->testValidatePayload([$field => null]);
                $this->fail("expected null for $field to be refused");
            } catch (ValidationException $e) {
                $this->assertSame([$field], array_keys($e->getFieldErrors()));
                $this->assertStringContainsString('cannot be null', $e->getFieldErrors()[$field]);
            }
        }
    }

    /** @return iterable<string, array{mixed, int}> */
    public static function wholeNumbers(): iterable
    {
        yield 'JSON integer' => [7, 7];
        yield 'digits' => ['42', 42];
        yield 'signed digits' => ['-3', -3];
        yield 'plus sign' => ['+5', 5];
        yield 'leading zeros' => ['007', 7];
        yield 'integral JSON number' => [5.0, 5];
        yield 'integral exponent JSON number' => [1e2, 100];
        yield 'largest int' => ['9223372036854775807', PHP_INT_MAX];
        yield 'smallest int' => ['-9223372036854775808', PHP_INT_MIN];
    }

    /** @dataProvider wholeNumbers */
    public function testValidatePayloadAcceptsAWholeNumberAsItsValue(mixed $sent, int $stored): void
    {
        [$ctrl] = $this->createControllerWithDb();
        $this->assertSame($stored, $ctrl->testValidatePayload(['priority' => $sent])['priority']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function notWholeNumbers(): iterable
    {
        // Each of these used to be cast to some other integer and stored.
        yield 'fraction string' => ['1.5'];
        yield 'fraction' => [1.5];
        yield 'exponent string' => ['1e3'];
        yield 'past PHP_INT_MAX' => ['99999999999999999999'];
        yield 'below PHP_INT_MIN' => ['-9223372036854775809'];
        yield 'float past the int range' => [1e19];
        yield 'leading space' => [' 7'];
        yield 'trailing space' => ['7 '];
        yield 'hex' => ['0x1A'];
        yield 'empty' => [''];
        yield 'bool' => [true];
        yield 'list' => [[1]];
        yield 'two signs' => ['--1'];
    }

    /** @dataProvider notWholeNumbers */
    public function testValidatePayloadRefusesWhatIsNotAWholeNumber(mixed $sent): void
    {
        [$ctrl] = $this->createControllerWithDb();
        try {
            $ctrl->testValidatePayload(['priority' => $sent]);
            $this->fail('expected ' . var_export($sent, true) . ' to be refused');
        } catch (ValidationException $e) {
            $this->assertSame(['priority' => "Field 'priority' must be a whole number"], $e->getFieldErrors());
        }
    }

    public function testValidatePayloadHoldsAWholeNumberToItsColumnsRange(): void
    {
        [$ctrl] = $this->createControllerWithDb();
        $this->assertSame(255, $ctrl->testValidatePayload(['rank' => '255'])['rank']);
        $this->assertSame(0, $ctrl->testValidatePayload(['rank' => 0])['rank']);
        // A value that is no whole number at all is refused with the range
        // too: a 20-digit string is past PHP's int range before it is past
        // the column's, and "must be a whole number" alone left the caller
        // guessing which numbers would do.
        foreach (['256', -1, 1000.0, '99999999999999999999', 1e20, '1.5', 'abc', ''] as $sent) {
            try {
                $ctrl->testValidatePayload(['rank' => $sent]);
                $this->fail('expected ' . var_export($sent, true) . ' to be out of range');
            } catch (ValidationException $e) {
                $this->assertSame(['rank' => "Field 'rank' must be a whole number from 0 to 255"], $e->getFieldErrors());
            }
        }
    }

    public function testValidatePayloadNamesADecimalFieldsRangeForAnyRefusedValue(): void
    {
        [$ctrl] = $this->createControllerWithDb();
        foreach (['100', '1e400', 'abc', true] as $sent) {
            try {
                $ctrl->testValidatePayload(['ratio' => $sent]);
                $this->fail('expected ' . var_export($sent, true) . ' to be refused');
            } catch (ValidationException $e) {
                $this->assertSame(
                    ['ratio' => "Field 'ratio' must be a number from -99.99 to 99.99"],
                    $e->getFieldErrors()
                );
            }
        }
    }

    public function testValidatePayloadRefusesANumberThatIsNotFiniteOrOutOfRange(): void
    {
        [$ctrl] = $this->createControllerWithDb();
        $this->assertSame(1000.0, $ctrl->testValidatePayload(['amount' => '1e3'])['amount'], 'an exponent is a decimal\'s own notation');
        $this->assertSame(-99.99, $ctrl->testValidatePayload(['ratio' => '-99.99'])['ratio']);
        foreach (['amount' => '1e400', 'ratio' => '100'] as $field => $sent) {
            try {
                $ctrl->testValidatePayload([$field => $sent]);
                $this->fail("expected $field = $sent to be refused");
            } catch (ValidationException $e) {
                $this->assertSame([$field], array_keys($e->getFieldErrors()));
            }
        }
        foreach ([true, [1], 'abc'] as $sent) {
            try {
                $ctrl->testValidatePayload(['amount' => $sent]);
                $this->fail('expected ' . var_export($sent, true) . ' to be refused');
            } catch (ValidationException $e) {
                $this->assertSame(['amount' => "Field 'amount' must be a finite number"], $e->getFieldErrors());
            }
        }
    }

    public function testValidatePayloadRefusesAStringFieldGivenABoolAFractionOrAList(): void
    {
        // (string) true is "1" and (string) [] is "Array": each was stored.
        [$ctrl] = $this->createControllerWithDb();
        foreach ([true, false, 1.5, ['a'], ['k' => 'v']] as $sent) {
            try {
                $ctrl->testValidatePayload(['description' => $sent]);
                $this->fail('expected ' . var_export($sent, true) . ' to be refused');
            } catch (ValidationException $e) {
                $this->assertSame(['description' => "Field 'description' must be a string"], $e->getFieldErrors());
            }
        }
    }

    public function testValidatePayloadRequiredFieldsWhenFlagTrue(): void
    {
        [$ctrl] = $this->createControllerWithDb();
        $this->expectException(ValidationException::class);
        $ctrl->testValidatePayload(['description' => 'Only optional'], true);
    }

    public function testValidatePayloadRequiredFieldsNotCheckedWhenFlagFalse(): void
    {
        [$ctrl] = $this->createControllerWithDb();
        $clean = $ctrl->testValidatePayload(['description' => 'Only optional'], false);
        $this->assertArrayHasKey('description', $clean);
    }

    public function testValidatePayloadAllowedValuePasses(): void
    {
        [$ctrl] = $this->createControllerWithDb();
        $clean = $ctrl->testValidatePayload(['status' => 'closed']);
        $this->assertSame('closed', $clean['status']);
    }

    public function testValidatePayloadRejectsDisallowedValue(): void
    {
        [$ctrl] = $this->createControllerWithDb();
        $this->expectException(ValidationException::class);
        $ctrl->testValidatePayload(['status' => 'archived']);
    }

    public function testValidatePayloadAllowedNotCheckedWhenAbsent(): void
    {
        [$ctrl] = $this->createControllerWithDb();
        $clean = $ctrl->testValidatePayload(['name' => 'Test']);
        $this->assertArrayNotHasKey('status', $clean);
    }

    public function testValidatePayloadCollectsMultipleErrors(): void
    {
        [$ctrl] = $this->createControllerWithDb();

        try {
            $ctrl->testValidatePayload([
                'priority' => 'not_int',
                'amount' => 'not_float',
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->getFieldErrors();
            $this->assertArrayHasKey('priority', $errors);
            $this->assertArrayHasKey('amount', $errors);
        }
    }

    // ─── transaction() ──────────────────────────────────────────────

    public function testTransactionCommitsOnSuccess(): void
    {
        /** @var \mysqli&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->getMockBuilder(\mysqli::class)
            ->disableOriginalConstructor()
            ->getMock();

        $db->expects($this->once())->method('begin_transaction')->willReturn(true);
        $db->expects($this->once())->method('commit')->willReturn(true);
        $db->expects($this->never())->method('rollback');

        $ctrl = new StubController($db, 1);
        $result = $ctrl->testTransaction(fn() => 'success');
        $this->assertSame('success', $result);
    }

    public function testTransactionRollsBackOnException(): void
    {
        /** @var \mysqli&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->getMockBuilder(\mysqli::class)
            ->disableOriginalConstructor()
            ->getMock();

        $db->expects($this->once())->method('begin_transaction')->willReturn(true);
        $db->expects($this->never())->method('commit');
        $db->expects($this->once())->method('rollback')->willReturn(true);

        $ctrl = new StubController($db, 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Boom');

        $ctrl->testTransaction(function () {
            throw new \RuntimeException('Boom');
        });
    }
}
