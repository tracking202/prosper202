<?php

declare(strict_types=1);

namespace Tests\Connect;

/**
 * Tracking202's MCP server as the install sees it, on a free loopback port
 * (`php -S`): it records every request (method, URI, Authorization,
 * Content-Type, body) and answers each "METHOD /path" as answer() set it,
 * after waiting the milliseconds given; anything unset is the plain 404 a
 * server that predates watchers gives.
 */
final class StubRelay
{
    /** @var resource */
    private $process;
    private string $dir;
    private int $port;

    private function __construct()
    {
    }

    public static function start(): self
    {
        $relay = new self();
        $dir = sys_get_temp_dir() . '/p202-stub-relay-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700)) {
            throw new \RuntimeException("could not make $dir");
        }
        $relay->dir = $dir;
        $code = <<<'PHP'
            <?php
            $method = $_SERVER['REQUEST_METHOD'];
            $path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            $headers = array_change_key_case(getallheaders(), CASE_LOWER);
            file_put_contents(__DIR__ . '/requests.log', json_encode([
                'method' => $method,
                'uri' => $_SERVER['REQUEST_URI'],
                'authorization' => $headers['authorization'] ?? null,
                'content_type' => $headers['content-type'] ?? null,
                'body' => (string) file_get_contents('php://input'),
            ]) . "\n", FILE_APPEND | LOCK_EX);
            $answers = is_file(__DIR__ . '/answers.json')
                ? json_decode((string) file_get_contents(__DIR__ . '/answers.json'), true) : [];
            $none = ['status' => 404, 'body' => "Not found\n", 'waitMs' => 0, 'headers' => []];
            $answer = $answers[$method . ' ' . $path] ?? $none;
            usleep($answer['waitMs'] * 1000);
            http_response_code($answer['status']);
            foreach ($answer['headers'] as $header) {
                header($header);
            }
            echo is_string($answer['body']) ? $answer['body'] : json_encode($answer['body']);
            return true;
            PHP;
        file_put_contents($dir . '/router.php', $code);

        $relay->port = self::freePort();
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $relay->port, $dir . '/router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['PHP_CLI_SERVER_WORKERS' => '4'] + getenv()
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('php -S did not start');
        }
        $relay->process = $process;
        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $relay->port, $errno, $error, 0.1);
            if ($socket !== false) {
                fclose($socket);

                return $relay;
            }
            usleep(50000);
        }
        $relay->stop();
        throw new \RuntimeException('php -S did not answer on port ' . $relay->port);
    }

    /** A loopback port nothing listens on. */
    public static function freePort(): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($probe === false) {
            throw new \RuntimeException("no free port: $error");
        }
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    public function origin(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    /**
     * How "METHOD /path" is answered from now on.
     *
     * @param mixed $body a string is sent as it is, anything else as JSON
     * @param list<string> $headers
     */
    public function answer(string $route, int $status, mixed $body, int $waitMs = 0, array $headers = []): void
    {
        $file = $this->dir . '/answers.json';
        $answers = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
        $answers[$route] = ['status' => $status, 'body' => $body, 'waitMs' => $waitMs, 'headers' => $headers];
        file_put_contents($file, json_encode($answers), LOCK_EX);
    }

    /**
     * @return list<array{method: string, uri: string, authorization: ?string, content_type: ?string, body: string}>
     */
    public function requests(): array
    {
        $log = $this->dir . '/requests.log';
        if (!is_file($log)) {
            return [];
        }
        $requests = [];
        foreach (file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $requests[] = json_decode($line, true, 4, JSON_THROW_ON_ERROR);
        }

        return $requests;
    }

    public function forget(): void
    {
        foreach (['requests.log', 'answers.json'] as $file) {
            if (is_file($this->dir . '/' . $file)) {
                unlink($this->dir . '/' . $file);
            }
        }
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /**
     * A watcher as the server lists it.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function watcher(string $id, int $campaignId, array $overrides = []): array
    {
        return $overrides + [
            'id' => $id,
            'campaignId' => $campaignId,
            'breakdown' => 'landing_page',
            'window' => 'since_start',
            'rule' => [
                'metric' => 'epc', 'comparator' => null, 'threshold' => 30, 'thresholdKind' => 'pctBelowBreakeven',
                'minClicks' => 500, 'action' => 'notify', 'checkEveryMinutes' => 60,
            ],
            'horizonHours' => 72,
            'createdAt' => 1762160000000,
            'endsAt' => 1762419200000,
            'status' => 'running',
            'statusReason' => '',
            'client' => 'Claude',
            'checks' => 3,
            'lastCheck' => ['at' => 1762170000000, 'outcome' => 'ok'],
            'alerts' => 1,
            'lastAlert' => 'landing_page lp one (1): EPC 0.6 is 30% or more below breakeven after 600 clicks',
        ];
    }
}
