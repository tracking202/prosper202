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
        preg_match('/"User-Agent", "(p202-cli\/[^"]+)"/', $go, $g);
        self::assertFalse(CapabilitiesController::isGoCliUserAgent($p[1] ?? ''), 'the PHP CLI must stay ungated');
        self::assertTrue(CapabilitiesController::isGoCliUserAgent($g[1] ?? ''), 'the Go CLI must be gated');
    }
}
