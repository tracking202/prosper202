<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Prosper202\Http\HttpUrlPrefix;

/**
 * HttpUrlPrefix::complete() on the cases the CLI's copy of the rule is held
 * to as well (go-cli/cmd/testdata/http_url_prefix_cases.json,
 * TestCompleteHTTPURLMatchesTheServer): sync matches landing pages by their
 * completed URL on both sides, so the two must answer alike.
 */
final class HttpUrlPrefixTest extends TestCase
{
    /** @return list<array{0: string, 1: string}> */
    private static function cases(): array
    {
        $cases = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/go-cli/cmd/testdata/http_url_prefix_cases.json'), true, 4, JSON_THROW_ON_ERROR);
        self::assertGreaterThan(35, count($cases));

        return $cases;
    }

    public function testEveryCaseCompletesAsListed(): void
    {
        foreach (self::cases() as [$sent, $stored]) {
            self::assertSame($stored, HttpUrlPrefix::complete($sent), var_export($sent, true));
            self::assertSame($stored, HttpUrlPrefix::complete($stored), 'completing twice changes nothing: ' . var_export($sent, true));
        }
    }

    public function testACompletedUrlIsOneTheRuleAccepts(): void
    {
        $completed = 0;
        foreach (self::cases() as [$sent, $stored]) {
            if ($stored !== $sent) {
                $completed++;
                self::assertFalse(HttpUrlPrefix::accepts($sent), $sent);
                self::assertTrue(HttpUrlPrefix::accepts($stored), $stored);
                self::assertStringStartsWith(HttpUrlPrefix::ADDED, $stored);
            }
        }
        self::assertGreaterThan(10, $completed);
    }
}
