<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\UsersController;
use Api\V3\Exception\ValidationException;
use Tests\TestCase;

/**
 * Scope normalization for API-key creation. The rule these pin down: an
 * absent scope means "full access" (the pre-scoping default), but a scope
 * that was supplied and turns out to be empty is malformed input. Treating
 * the second like the first silently mints a `*` key for a caller who asked
 * for a restriction.
 */
final class ApiKeyScopeInputTest extends TestCase
{
    private function normalize(mixed $raw): ?array
    {
        $controller = (new \ReflectionClass(UsersController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(UsersController::class, 'normalizeRequestedScope');
        $method->setAccessible(true);
        return $method->invoke($controller, $raw);
    }

    public function testAbsentScopeMeansFullAccess(): void
    {
        $this->assertNull($this->normalize(null));
    }

    /** @dataProvider emptyScopeInputs */
    public function testSuppliedButEmptyScopeIsRejected(mixed $raw): void
    {
        $this->expectException(ValidationException::class);
        $this->normalize($raw);
    }

    /** @return array<string, array{mixed}> */
    public static function emptyScopeInputs(): array
    {
        return [
            'empty string' => [''],
            'whitespace' => ['   '],
            'commas only' => [',,'],
            'comma and space' => [' , '],
            'empty array' => [[]],
            'array of blanks' => [['', '  ']],
        ];
    }

    public function testRealTokensAreNormalized(): void
    {
        $this->assertSame(['read', 'stage'], $this->normalize(' Read , STAGE '));
        $this->assertSame(['campaigns:write'], $this->normalize(['Campaigns:Write']));
    }
}
