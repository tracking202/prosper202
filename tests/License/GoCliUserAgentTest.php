<?php

declare(strict_types=1);

namespace Tests\License;

use Api\V3\Controllers\CapabilitiesController;
use PHPUnit\Framework\TestCase;

/**
 * The Pro gate applies to the Go CLI only; the legacy PHP CLI is exempt.
 */
final class GoCliUserAgentTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function agents(): array
    {
        return [
            'Go CLI (go-cli/internal/api/client.go)' => ['p202-cli/2.0 (Go)', true],
            'future Go CLI version' => ['p202-cli/3.1 (Go)', true],
            'legacy PHP CLI (cli/ApiClient.php)' => ['p202-cli/1.0', false],
            'browser' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0)', false],
            'curl' => ['curl/8.7.1', false],
            'empty' => ['', false],
            '(Go) without the CLI prefix' => ['some-tool/1.0 (Go)', false],
        ];
    }

    /**
     * @dataProvider agents
     */
    public function testOnlyTheGoCliIsGated(string $userAgent, bool $gated): void
    {
        self::assertSame($gated, CapabilitiesController::isGoCliUserAgent($userAgent));
    }

    public function testTheShippedClientsMatchTheirExpectedClass(): void
    {
        $root = dirname(__DIR__, 2);
        $php = (string)file_get_contents($root . '/cli/ApiClient.php');
        $go = (string)file_get_contents($root . '/go-cli/internal/api/client.go');
        self::assertMatchesRegularExpression('/User-Agent: (p202-cli\/[^\'"]+)/', $php);
        preg_match('/User-Agent: (p202-cli\/[^\'"]+)/', $php, $p);
        self::assertFalse(CapabilitiesController::isGoCliUserAgent($p[1] ?? ''), 'the PHP CLI must stay ungated');
        // Every request path in the Go client sets its own header; one that
        // sent another string would walk around the gate, so each is read.
        preg_match_all('/\.Header\.Set\("User-Agent", "([^"]+)"\)/', $go, $g);
        self::assertGreaterThanOrEqual(
            3,
            count($g[1]),
            'expected the Go client to set User-Agent on every request path'
        );
        foreach ($g[1] as $agent) {
            self::assertTrue(CapabilitiesController::isGoCliUserAgent($agent), "the Go CLI must be gated: $agent");
        }
        // A request that set no User-Agent would go out as Go's default agent,
        // ungated, and one that passed a variable would not be read above; so
        // every request the client package builds must set it, as a literal.
        $requests = preg_match_all('/http\.NewRequest(?:WithContext)?\(/', $go);
        self::assertSame(
            $requests,
            count($g[1]),
            'every http.NewRequest in go-cli/internal/api/client.go must set User-Agent with a string literal'
        );
        foreach (glob($root . '/go-cli/internal/api/*.go') ?: [] as $file) {
            if (str_ends_with($file, '_test.go') || str_ends_with($file, '/client.go')) {
                continue;
            }
            self::assertSame(
                0,
                preg_match_all('/http\.NewRequest(?:WithContext)?\(/', (string)file_get_contents($file)),
                basename($file) . ' builds requests outside client.go, where the User-Agent is set'
            );
        }
    }
}
