<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Exception\ConflictException;
use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Api\V3\HttpException;
use Tests\TestCase;

/**
 * Tests for the API v3 exception hierarchy.
 */
final class ExceptionTest extends TestCase
{
    // ─── ValidationException ────────────────────────────────────────

    public function testValidationExceptionHas422Status(): void
    {
        $e = new ValidationException('Validation failed', ['name' => 'Required']);

        $this->assertSame(422, $e->getHttpStatus());
        $this->assertSame(422, $e->getCode());
    }

    public function testValidationExceptionFieldErrors(): void
    {
        $fieldErrors = [
            'name' => 'Required',
            'email' => 'Invalid email format',
        ];

        $e = new ValidationException('Validation failed', $fieldErrors);

        $this->assertSame($fieldErrors, $e->getFieldErrors());
    }

    public function testValidationExceptionFieldErrorsDefaultsToEmpty(): void
    {
        $e = new ValidationException('Validation failed');

        $this->assertSame([], $e->getFieldErrors());
    }

    public function testValidationExceptionToArrayContainsAllInfo(): void
    {
        $fieldErrors = ['name' => 'Required'];

        $e = new ValidationException('Validation failed', $fieldErrors);
        $arr = $e->toArray();

        $this->assertTrue($arr['error']);
        $this->assertSame('Validation failed', $arr['message']);
        $this->assertSame(422, $arr['status']);
        $this->assertSame($fieldErrors, $arr['field_errors']);
    }

    public function testValidationExceptionToArrayOmitsFieldErrorsWhenEmpty(): void
    {
        $e = new ValidationException('Validation failed');
        $arr = $e->toArray();

        $this->assertTrue($arr['error']);
        $this->assertSame('Validation failed', $arr['message']);
        $this->assertSame(422, $arr['status']);
        $this->assertArrayNotHasKey('field_errors', $arr);
    }

    public function testValidationExceptionMessage(): void
    {
        $e = new ValidationException('Custom validation message');

        $this->assertSame('Custom validation message', $e->getMessage());
    }

    public function testValidationExceptionIsInstanceOfHttpException(): void
    {
        $e = new ValidationException();

        $this->assertInstanceOf(HttpException::class, $e);
    }

    // ─── NotFoundException ──────────────────────────────────────────

    public function testNotFoundExceptionHas404Status(): void
    {
        $e = new NotFoundException();

        $this->assertSame(404, $e->getHttpStatus());
        $this->assertSame(404, $e->getCode());
    }

    public function testNotFoundExceptionDefaultMessage(): void
    {
        $e = new NotFoundException();

        $this->assertSame('Not found', $e->getMessage());
    }

    public function testNotFoundExceptionCustomMessage(): void
    {
        $e = new NotFoundException('User not found');

        $this->assertSame('User not found', $e->getMessage());
    }

    public function testNotFoundExceptionIsInstanceOfHttpException(): void
    {
        $e = new NotFoundException();

        $this->assertInstanceOf(HttpException::class, $e);
    }

    public function testNotFoundExceptionAcceptsPreviousException(): void
    {
        $prev = new \RuntimeException('original');
        $e = new NotFoundException('Not found', $prev);

        $this->assertSame($prev, $e->getPrevious());
    }

    // ─── DatabaseException ──────────────────────────────────────────

    public function testDatabaseExceptionHas500Status(): void
    {
        $e = new DatabaseException('SELECT failed');

        $this->assertSame(500, $e->getHttpStatus());
        $this->assertSame(500, $e->getCode());
    }

    public function testDatabaseExceptionHidesInternalDetail(): void
    {
        $e = new DatabaseException('Column foo_bar does not exist in table xyz');

        // The client-facing message should never expose internal DB details
        $this->assertSame('Internal server error', $e->getMessage());
        $this->assertStringNotContainsString('foo_bar', $e->getMessage());
        $this->assertStringNotContainsString('xyz', $e->getMessage());
    }

    public function testDatabaseExceptionPreservesPreviousExceptionForLogging(): void
    {
        $dbError = new \mysqli_sql_exception('Deadlock found');
        $e = new DatabaseException('Query failed', $dbError);

        $this->assertSame($dbError, $e->getPrevious());
        // Previous exception retains the actual error for internal logging
        $this->assertSame('Deadlock found', $e->getPrevious()->getMessage());
    }

    public function testDatabaseExceptionIsInstanceOfHttpException(): void
    {
        $e = new DatabaseException('anything');

        $this->assertInstanceOf(HttpException::class, $e);
    }

    // ─── ConflictException ──────────────────────────────────────────

    public function testConflictExceptionHas409Status(): void
    {
        $e = new ConflictException();

        $this->assertSame(409, $e->getHttpStatus());
        $this->assertSame(409, $e->getCode());
    }

    public function testConflictExceptionDefaultMessage(): void
    {
        $e = new ConflictException();

        $this->assertSame('Conflict', $e->getMessage());
    }

    public function testConflictExceptionCustomMessage(): void
    {
        $e = new ConflictException('Username already exists');

        $this->assertSame('Username already exists', $e->getMessage());
    }

    public function testConflictExceptionIsInstanceOfHttpException(): void
    {
        $e = new ConflictException();

        $this->assertInstanceOf(HttpException::class, $e);
    }

    public function testConflictExceptionAcceptsPreviousException(): void
    {
        $prev = new \RuntimeException('duplicate key');
        $e = new ConflictException('Conflict', $prev);

        $this->assertSame($prev, $e->getPrevious());
    }

    // ─── HttpException base ─────────────────────────────────────────

    public function testHttpExceptionWithCustomStatusCode(): void
    {
        $e = new HttpException('Rate limit exceeded', 429);

        $this->assertSame(429, $e->getHttpStatus());
        $this->assertSame(429, $e->getCode());
        $this->assertSame('Rate limit exceeded', $e->getMessage());
    }

    public function testHttpExceptionDefaultsTo500(): void
    {
        $e = new HttpException('Server error');

        $this->assertSame(500, $e->getHttpStatus());
    }

    public function testHttpExceptionAcceptsPreviousException(): void
    {
        $prev = new \RuntimeException('original');
        $e = new HttpException('Wrapped', 500, $prev);

        $this->assertSame($prev, $e->getPrevious());
    }

    public function testHttpExceptionIsRuntimeException(): void
    {
        $e = new HttpException('error');

        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    public function testHttpExceptionGetHttpStatusReturnsCode(): void
    {
        $e = new HttpException('Unauthorized', 401);

        $this->assertSame($e->getCode(), $e->getHttpStatus());
    }
}
