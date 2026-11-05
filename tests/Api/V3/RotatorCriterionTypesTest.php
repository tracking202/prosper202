<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\RotatorsController;
use PHPUnit\Framework\TestCase;
use Prosper202\Rotator\CriterionMatcher;

/**
 * The v3 API takes every criterion type the redirects read: it refused the
 * paid 1.9.56 types (eu, fraud, iprange, visitor, c1-c4, t202kw, utm_*,
 * referer) as types that never match, so a rule set read back from an
 * upgraded install could not be written again.
 */
final class RotatorCriterionTypesTest extends TestCase
{
    public function testTheApiTakesTheTypesTheRedirectsRead(): void
    {
        $types = (new \ReflectionClassConstant(RotatorsController::class, 'CRITERIA_TYPES'))->getValue();
        self::assertSame(array_keys(CriterionMatcher::TYPES), $types);
    }

    /** @return iterable<string, array{array<string, string>, ?string}> criterion, refused field message */
    public static function criteria(): iterable
    {
        yield 'utm_source' => [['type' => 'utm_source', 'value' => 'newsletter'], null];
        yield 'an IP range' => [['type' => 'iprange', 'statement' => 'is_not', 'value' => '203.0.113.0/24'], null];
        yield 'not an IP range' => [['type' => 'iprange', 'value' => '203.0.113.0/40'], 'is not an IP range'];
        yield 'visitor' => [['type' => 'visitor', 'value' => 'unique'], null];
        yield 'visitor bot' => [['type' => 'visitor', 'value' => 'bot'], 'never matches a visitor rule'];
        yield 'referer' => [['type' => 'referer', 'value' => 'search\.example'], null];
        yield 'an unknown type' => [['type' => 'weekday', 'value' => 'Monday'], 'One of country, region, eu,'];
    }

    /**
     * @dataProvider criteria
     * @param array<string, string> $criterion
     */
    public function testACriterionIsTakenOrRefusedByItsType(array $criterion, ?string $refused): void
    {
        $errors = [];
        $method = new \ReflectionMethod(RotatorsController::class, 'criterion');
        $out = $method->invoke(null, $criterion, 'criteria.0', static function (string $message, array $fields) use (&$errors): void {
            $errors += $fields;
        });
        if ($refused === null) {
            self::assertSame([], $errors);
            self::assertSame($criterion['type'], $out['type'] ?? null);
            return;
        }
        self::assertNull($out);
        self::assertStringContainsString($refused, implode(' ', $errors));
    }
}
