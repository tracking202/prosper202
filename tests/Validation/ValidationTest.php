<?php

declare(strict_types=1);

namespace Tests\Validation;

use Tests\TestCase;
use Prosper202\Validation\ValidationResult;
use Prosper202\Validation\ValidationException;
use Prosper202\Validation\SetupFormValidator;

final class ValidationTest extends TestCase
{
    // ---------------------------------------------------------------
    // ValidationResult
    // ---------------------------------------------------------------

    public function testSuccessResult(): void
    {
        $result = ValidationResult::success('clean_value');

        $this->assertTrue($result->isValid);
        $this->assertSame('clean_value', $result->getSanitizedValue());
        $this->assertNull($result->errorMessage);
    }

    public function testFailureResult(): void
    {
        $result = ValidationResult::failure('Something went wrong');

        $this->assertFalse($result->isValid);
        $this->assertSame('Something went wrong', $result->errorMessage);
    }

    public function testGetErrorMessageOnValidThrows(): void
    {
        $result = ValidationResult::success('value');

        $this->expectException(\LogicException::class);
        $result->getErrorMessage();
    }

    public function testGetSanitizedValueOnInvalidThrows(): void
    {
        $result = ValidationResult::failure('bad input');

        $this->expectException(\LogicException::class);
        $result->getSanitizedValue();
    }

    // ---------------------------------------------------------------
    // ValidationException
    // ---------------------------------------------------------------

    public function testExceptionContainsErrors(): void
    {
        $errors = ['name' => 'Name is required', 'email' => 'Email is invalid'];
        $exception = new ValidationException('Validation failed', $errors);

        $this->assertSame($errors, $exception->getErrors());
    }

    public function testHasError(): void
    {
        $exception = new ValidationException('Validation failed', [
            'name' => 'Name is required',
        ]);

        $this->assertTrue($exception->hasError('name'));
        $this->assertFalse($exception->hasError('email'));
    }

    public function testGetErrorReturnsMessage(): void
    {
        $exception = new ValidationException('Validation failed', [
            'email' => 'Email is invalid',
        ]);

        $this->assertSame('Email is invalid', $exception->getError('email'));
    }

    public function testGetErrorReturnsNullForMissing(): void
    {
        $exception = new ValidationException('Validation failed', [
            'name' => 'Name is required',
        ]);

        $this->assertNull($exception->getError('phone'));
    }

    // ---------------------------------------------------------------
    // SetupFormValidator — validateRequired
    // ---------------------------------------------------------------

    public function testValidateRequiredWithNull(): void
    {
        $validator = new SetupFormValidator($this->createMysqliMock());
        $result = $validator->validateRequired(null, 'username');

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('required', $result->errorMessage);
    }

    public function testValidateRequiredWithEmptyString(): void
    {
        $validator = new SetupFormValidator($this->createMysqliMock());
        $result = $validator->validateRequired('', 'username');

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('required', $result->errorMessage);
    }

    public function testValidateRequiredWithWhitespace(): void
    {
        $validator = new SetupFormValidator($this->createMysqliMock());
        $result = $validator->validateRequired('   ', 'username');

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('empty', $result->errorMessage);
    }

    public function testValidateRequiredWithValidValue(): void
    {
        $validator = new SetupFormValidator($this->createMysqliMock());
        $result = $validator->validateRequired('  hello  ', 'username');

        $this->assertTrue($result->isValid);
        $this->assertSame('hello', $result->getSanitizedValue());
    }

    // ---------------------------------------------------------------
    // SetupFormValidator — validateUrl
    // ---------------------------------------------------------------

    public function testValidateUrlWithHttps(): void
    {
        $validator = new SetupFormValidator($this->createMysqliMock());
        $result = $validator->validateUrl('https://example.com/path', 'Website');

        $this->assertTrue($result->isValid);
        $this->assertSame('https://example.com/path', $result->getSanitizedValue());
    }

    public function testValidateUrlWithoutScheme(): void
    {
        $validator = new SetupFormValidator($this->createMysqliMock());
        $result = $validator->validateUrl('example.com/path', 'Website');

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('http', $result->errorMessage);
    }

    public function testValidateUrlWithInvalidFormat(): void
    {
        $validator = new SetupFormValidator($this->createMysqliMock());
        $result = $validator->validateUrl('http://<>not valid', 'Website');

        $this->assertFalse($result->isValid);
    }

    // ---------------------------------------------------------------
    // SetupFormValidator — validateNumeric
    // ---------------------------------------------------------------

    public function testValidateNumericWithString(): void
    {
        $validator = new SetupFormValidator($this->createMysqliMock());
        $result = $validator->validateNumeric('abc', 'amount');

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('number', $result->errorMessage);
    }

    public function testValidateNumericWithMin(): void
    {
        $validator = new SetupFormValidator($this->createMysqliMock());
        $result = $validator->validateNumeric('3', 'amount', min: 5.0);

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('at least', $result->errorMessage);
    }

    public function testValidateNumericWithMax(): void
    {
        $validator = new SetupFormValidator($this->createMysqliMock());
        $result = $validator->validateNumeric('200', 'amount', max: 100.0);

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('at most', $result->errorMessage);
    }

    public function testValidateNumericInRange(): void
    {
        $validator = new SetupFormValidator($this->createMysqliMock());
        $result = $validator->validateNumeric('50', 'amount', min: 1.0, max: 100.0);

        $this->assertTrue($result->isValid);
        $this->assertSame(50.0, $result->getSanitizedValue());
    }

    // ---------------------------------------------------------------
    // SetupFormValidator — validateInteger
    // ---------------------------------------------------------------

    public function testValidateIntegerValid(): void
    {
        $validator = new SetupFormValidator($this->createMysqliMock());
        $result = $validator->validateInteger('42', 'count');

        $this->assertTrue($result->isValid);
        $this->assertSame(42, $result->getSanitizedValue());
    }

    public function testValidateIntegerWithFloat(): void
    {
        $validator = new SetupFormValidator($this->createMysqliMock());
        $result = $validator->validateInteger('3.14', 'count');

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('integer', $result->errorMessage);
    }

    // ---------------------------------------------------------------
    // SetupFormValidator — validateString
    // ---------------------------------------------------------------

    public function testValidateStringTooShort(): void
    {
        $validator = new SetupFormValidator($this->createMysqliMock());
        $result = $validator->validateString('', 'name', minLength: 3);

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('at least 3', $result->errorMessage);
    }

    public function testValidateStringTooLong(): void
    {
        $validator = new SetupFormValidator($this->createMysqliMock());
        $longValue = str_repeat('a', 300);
        $result = $validator->validateString($longValue, 'name', maxLength: 255);

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('at most 255', $result->errorMessage);
    }

    public function testValidateStringXssSanitized(): void
    {
        $validator = new SetupFormValidator($this->createMysqliMock());
        $result = $validator->validateString('<script>alert("xss")</script>', 'name');

        $this->assertTrue($result->isValid);
        $sanitized = $result->getSanitizedValue();
        $this->assertStringNotContainsString('<script>', $sanitized);
        $this->assertStringContainsString('&lt;script&gt;', $sanitized);
    }

    // ---------------------------------------------------------------
    // SetupFormValidator — validateEmail
    // ---------------------------------------------------------------

    public function testValidateEmailValid(): void
    {
        $validator = new SetupFormValidator($this->createMysqliMock());
        $result = $validator->validateEmail('user@example.com', 'email');

        $this->assertTrue($result->isValid);
        $this->assertSame('user@example.com', $result->getSanitizedValue());
    }

    public function testValidateEmailInvalid(): void
    {
        $validator = new SetupFormValidator($this->createMysqliMock());
        $result = $validator->validateEmail('not-an-email', 'email');

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('valid email', $result->errorMessage);
    }

    // ---------------------------------------------------------------
    // SetupFormValidator — validateArray
    // ---------------------------------------------------------------

    public function testValidateArraySuccess(): void
    {
        $validator = new SetupFormValidator($this->createMysqliMock());

        $data = [
            'name' => 'John',
            'age' => '25',
        ];
        $rules = [
            'name' => ['type' => 'required', 'name' => 'Name'],
            'age' => ['type' => 'integer', 'name' => 'Age', 'min' => 1, 'max' => 150],
        ];

        $results = $validator->validateArray($data, $rules);

        $this->assertSame('John', $results['name']);
        $this->assertSame(25, $results['age']);
    }

    public function testValidateArrayThrowsOnFailure(): void
    {
        $validator = new SetupFormValidator($this->createMysqliMock());

        $data = [
            'name' => '',
            'email' => 'bad-email',
        ];
        $rules = [
            'name' => ['type' => 'required', 'name' => 'Name'],
            'email' => ['type' => 'email', 'name' => 'Email'],
        ];

        try {
            $validator->validateArray($data, $rules);
            $this->fail('Expected ValidationException was not thrown');
        } catch (ValidationException $e) {
            $this->assertTrue($e->hasError('name'));
            $this->assertTrue($e->hasError('email'));
            $this->assertNotNull($e->getError('name'));
            $this->assertNotNull($e->getError('email'));
        }
    }
}
