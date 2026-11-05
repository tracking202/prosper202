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
    /** @var resource|null */
    private $server = null;

    private string $dir = '';

    public function testTheCallbackReadsAndSendsNothing(): void
    {
        $source = (string) file_get_contents(SourceScan::repoRoot() . '/202-cronjobs/dni.php');
        self::assertStringContainsString('http_response_code(410);', $source);
        self::assertStringNotContainsString('curl', $source, 'it sends nothing');
        self::assertStringNotContainsString('$_GET', $source, 'it reads no request');
        self::assertStringNotContainsString('apiKey', $source, 'it reads no key');
        self::assertDoesNotMatchRegularExpression('/\b(require|include)(_once)?\b/', $source, 'it loads nothing');
        self::assertDoesNotMatchRegularExpression('/\b(SELECT|UPDATE|query)\b/i', $source, 'it touches no table');
    }

    /** Served alone, with no 202-config/ beside it: a bootstrap would fail or redirect, not answer 410. */
    public function testServedWithNoInstallItAnswers410(): void
    {
        $this->dir = sys_get_temp_dir() . '/p202-dni-retired-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($this->dir . '/202-cronjobs', 0700, true));
        self::assertTrue(copy(SourceScan::repoRoot() . '/202-cronjobs/dni.php', $this->dir . '/202-cronjobs/dni.php'));

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($probe, 'a loopback port could be found');
        $name = (string) stream_socket_get_name($probe, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($probe);
        $this->server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $this->dir],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        $url = 'http://127.0.0.1:' . $port . '/202-cronjobs/dni.php?hash=x&dni=1&processed=false';
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 5]]);
        $body = false;
        for ($i = 0; $i < 50 && $body === false; $i++) {
            usleep(100000);
            $body = @file_get_contents($url, false, $context);
        }
        self::assertIsString($body, 'the loopback server answered');
        $headers = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : $http_response_header;
        self::assertStringContainsString(' 410 ', (string) ($headers[0] ?? ''), implode("\n", $headers));
        self::assertStringContainsString('retired', $body);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        if ($this->dir !== '') {
            @unlink($this->dir . '/202-cronjobs/dni.php');
            @rmdir($this->dir . '/202-cronjobs');
            @rmdir($this->dir);
        }
    }
}
