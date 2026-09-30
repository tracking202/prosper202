<?php

declare(strict_types=1);

namespace Tests\Identity;

use PHPUnit\Framework\TestCase;
use Prosper202\Identity\CustomerId;

/**
 * The signed customer id against the cross-language vectors in
 * tests/fixtures/app-sdk-contract/customer-id.json — the same file the iOS
 * SDK's setCustomerId() is tested against (GoalVectorsTests.swift,
 * ContractVectorsTests), so the device canonicalises an id exactly as the
 * server does and a signature the operator computed over what the device
 * sends verifies here. The signatures were computed with Python's hmac,
 * not with this class.
 */
final class CustomerIdVectorsTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function vectors(): array
    {
        $raw = file_get_contents(__DIR__ . '/../fixtures/app-sdk-contract/customer-id.json');
        self::assertIsString($raw);
        $doc = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(1, $doc['format_version'] ?? null, 'this suite reads format_version 1');

        return $doc;
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function canonicalCases(): array
    {
        $out = [];
        foreach (self::vectors()['cases'] as $case) {
            $out[$case['name']] = [$case];
        }

        return $out;
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function signatureCases(): array
    {
        $out = [];
        foreach (self::vectors()['signatures'] as $case) {
            $out[$case['name']] = [$case];
        }

        return $out;
    }

    public function testTheVectorsHoldEnoughCasesToMeanSomething(): void
    {
        self::assertGreaterThanOrEqual(10, count(self::canonicalCases()));
        self::assertGreaterThanOrEqual(10, count(self::signatureCases()));
        $refused = array_filter(self::canonicalCases(), static fn (array $c): bool => $c[0]['expect'] === null);
        self::assertGreaterThanOrEqual(4, count($refused), 'the refusals are half the contract');
    }

    /**
     * @dataProvider canonicalCases
     * @param array<string, mixed> $case
     */
    public function testCanonicalForm(array $case): void
    {
        self::assertSame($case['expect'], CustomerId::canonical((string) $case['id'], $case['type']));
    }

    /**
     * @dataProvider signatureCases
     * @param array<string, mixed> $case
     */
    public function testSignature(array $case): void
    {
        $key = (string) self::vectors()['linking_key'];
        self::assertSame($case['valid'], CustomerId::verify($key, (string) $case['canonical'], (string) $case['signature']));
        if ($case['valid']) {
            self::assertSame(strtolower((string) $case['signature']), CustomerId::sign($key, (string) $case['canonical']));
        }
        // What an SDK can check without the key: the shape.
        self::assertSame($case['well_formed'], preg_match('/^[0-9a-f]{64}$/D', strtolower((string) $case['signature'])) === 1);
    }
}
