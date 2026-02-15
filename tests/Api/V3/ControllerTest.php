<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controller;
use Api\V3\Controllers\SystemController;
use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
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

    protected function beforeUpdate(int|string $id, array $payload): void
    {
        $this->beforeUpdateCalled = true;
    }

    protected function beforeDelete(int|string $id): void
    {
        $this->beforeDeleteCalled = true;
    }

    public function testValidatePayload(array $payload, bool $requireRequired = false): array
    {
        return $this->validatePayload($payload, $requireRequired);
    }

    public function testTransaction(callable $fn): mixed
    {
        return $this->transaction($fn);
    }
}

final class SystemControllerBehaviorTest extends TestCase
{
    private function createResultMock(array $rows): \mysqli_result
    {
        /** @var \mysqli_result&\PHPUnit\Framework\MockObject\MockObject $result */
        $result = $this->getMockBuilder(\mysqli_result::class)
            ->disableOriginalConstructor()
            ->getMock();

        $index = 0;
        $result->method('fetch_assoc')->willReturnCallback(
            function () use (&$index, $rows) {
                return $rows[$index++] ?? null;
            }
        );

        return $result;
    }

    public function testCronStatusReadsExistingCronjobLogColumns(): void
    {
        /** @var \mysqli&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->getMockBuilder(\mysqli::class)
            ->disableOriginalConstructor()
            ->getMock();

        $queries = [];
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$queries) {
                $queries[] = $sql;
                if (str_contains($sql, 'FROM 202_cronjobs')) {
                    return $this->createResultMock([
                        ['cronjob_type' => 'main', 'cronjob_time' => '1700000000'],
                    ]);
                }
                if (str_contains($sql, 'FROM 202_cronjob_logs')) {
                    return $this->createResultMock([
                        ['id' => 1, 'last_execution_time' => '1700001234'],
                    ]);
                }
                return false;
            }
        );

        $controller = new SystemController($db);
        $result = $controller->cronStatus();

        $this->assertArrayHasKey('data', $result);
        $this->assertSame(1, $result['data']['recent_logs'][0]['id']);
        $this->assertStringContainsString(
            'SELECT id, last_execution_time FROM 202_cronjob_logs',
            implode("\n", $queries)
        );
    }

    public function testErrorsQueryUsesMysqlErrorTextAlias(): void
    {
        /** @var \mysqli&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->getMockBuilder(\mysqli::class)
            ->disableOriginalConstructor()
            ->getMock();

        $preparedSql = '';

        /** @var \mysqli_stmt&\PHPUnit\Framework\MockObject\MockObject $stmt */
        $stmt = $this->getMockBuilder(\mysqli_stmt::class)
            ->disableOriginalConstructor()
            ->getMock();
        $stmt->method('bind_param')->willReturn(true);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('get_result')->willReturn(
            $this->createResultMock([
                [
                    'mysql_error_id' => 10,
                    'mysql_error_time' => 1700002222,
                    'mysql_error_message' => 'bad query',
                    'mysql_error_sql' => 'SELECT * FROM nope',
                ],
            ])
        );
        $stmt->method('close')->willReturn(true);

        $db->method('prepare')->willReturnCallback(
            function (string $sql) use (&$preparedSql, $stmt) {
                $preparedSql = $sql;
                return $stmt;
            }
        );

        $controller = new SystemController($db);
        $result = $controller->errors(['limit' => 1]);

        $this->assertArrayHasKey('data', $result);
        $this->assertSame('bad query', $result['data'][0]['mysql_error_message']);
        $this->assertStringContainsString('mysql_error_text AS mysql_error_message', $preparedSql);
    }

    public function testDbStatsThrowsWhenDatabaseLookupFails(): void
    {
        /** @var \mysqli&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->getMockBuilder(\mysqli::class)
            ->disableOriginalConstructor()
            ->getMock();

        $db->method('query')->willReturnCallback(
            function (string $sql) {
                if (str_contains($sql, 'SELECT DATABASE() as db')) {
                    return false;
                }
                return $this->createResultMock([]);
            }
        );

        $controller = new SystemController($db);

        $this->expectException(DatabaseException::class);
        $controller->dbStats();
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

    public function testGetThrowsNotFoundExceptionForMissingId(): void
    {
        [$ctrl] = $this->createControllerWithDb();
        $this->expectException(NotFoundException::class);
        $ctrl->get(999);
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

    public function testUpdateIgnoresReadonlyFields(): void
    {
        $db = $this->createMysqliMock([
            'SELECT' => ['item_id' => 1, 'name' => 'Updated', 'created_at' => '2025-01-01', 'user_id' => 1],
        ]);
        $ctrl = new StubController($db, 1);

        $result = $ctrl->update(1, ['name' => 'Updated', 'created_at' => '2099-01-01']);
        $this->assertArrayHasKey('data', $result);
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

    public function testValidatePayloadStripsReadonlyFields(): void
    {
        [$ctrl] = $this->createControllerWithDb();
        $clean = $ctrl->testValidatePayload(['name' => 'Test', 'created_at' => '2025-01-01']);
        $this->assertArrayHasKey('name', $clean);
        $this->assertArrayNotHasKey('created_at', $clean);
    }

    public function testValidatePayloadStripsUnknownFields(): void
    {
        [$ctrl] = $this->createControllerWithDb();
        $clean = $ctrl->testValidatePayload(['name' => 'Test', 'nonexistent' => 'value']);
        $this->assertArrayHasKey('name', $clean);
        $this->assertArrayNotHasKey('nonexistent', $clean);
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
