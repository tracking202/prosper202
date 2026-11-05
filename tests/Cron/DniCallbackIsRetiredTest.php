<?php

declare(strict_types=1);

namespace Tests\Cron;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * 202-cronjobs/dni.php selected 202_dni_networks by networkId alone, so with
 * two accounts on one network it could post the other account's API key to
 * the hosted offer cache. The hosted callers and that endpoint are gone
 * (backend-api, 2026-10-12); the file stays, inert, so an upgrade replaces it.
 */
final class DniCallbackIsRetiredTest extends TestCase
{
    public function testTheCallbackReadsAndSendsNothing(): void
    {
        $source = (string) file_get_contents(SourceScan::repoRoot() . '/202-cronjobs/dni.php');
        self::assertStringContainsString('http_response_code(410);', $source);
        self::assertStringNotContainsString('curl', $source, 'it sends nothing');
        self::assertStringNotContainsString('$_GET', $source, 'it reads no request');
        self::assertStringNotContainsString('apiKey', $source, 'it reads no key');
        self::assertDoesNotMatchRegularExpression('/\b(SELECT|UPDATE|query)\b/i', $source, 'it touches no table');
    }
}
