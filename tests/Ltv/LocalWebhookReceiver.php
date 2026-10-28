<?php

declare(strict_types=1);

namespace Tests\Ltv;

use Prosper202\Ltv\WebhookDispatcher;

/**
 * A webhook receiver on a free loopback port (`php -S`, four workers): it
 * records every request it gets (path, the X-P202-* headers, body) and
 * answers as its path says, /hook/{status}/{milliseconds to wait first}
 * (with /bytes after it, a body that is not UTF-8), or 503 to everything
 * while down() holds.
 *
 * The dispatcher's own POST (WebhookDispatcher::post()) refuses loopback and
 * plain http, as it must, so a test reaches this receiver through send(): the
 * dispatcher's headers and body, posted to the receiver at the path of the
 * delivery's https://receiver.test/… URL. post() itself is run where its
 * refusals are the subject, and end to end on a live instance.
 */
final class LocalWebhookReceiver
{
    public const HOST = 'https://receiver.test';

    /** @var resource */
    private $process;
    private string $dir;
    private int $port;

    private function __construct()
    {
    }

    public static function start(): self
    {
        $receiver = new self();
        $dir = sys_get_temp_dir() . '/p202-ltv-receiver-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700)) {
            throw new \RuntimeException("could not make $dir");
        }
        $receiver->dir = $dir;
        $code = <<<'PHP'
            <?php
            $path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            $entry = json_encode([
                'path' => $path,
                'delivery' => $_SERVER['HTTP_X_P202_DELIVERY'] ?? null,
                'event' => $_SERVER['HTTP_X_P202_EVENT'] ?? null,
                'signature' => $_SERVER['HTTP_X_P202_SIGNATURE'] ?? null,
                'body' => (string) file_get_contents('php://input'),
                'at' => microtime(true),
            ]);
            file_put_contents(__DIR__ . '/requests.log', $entry . "\n", FILE_APPEND | LOCK_EX);
            if (is_file(__DIR__ . '/down')) {
                http_response_code(503);
                echo 'receiver down';
                return true;
            }
            if (preg_match('#^/hook/([1-5][0-9][0-9])/([0-9]{1,5})(/bytes)?$#', $path, $m) !== 1) {
                http_response_code(404);
                echo 'no such hook';
                return true;
            }
            usleep((int) $m[2] * 1000);
            http_response_code((int) $m[1]);
            // /bytes: a body that is not UTF-8, and longer than the log keeps.
            echo isset($m[3]) ? "\xff\xfe" . str_repeat("\xc3\xa9", 600) : 'answered ' . $m[1];
            return true;
            PHP;
        file_put_contents($dir . '/router.php', $code);

        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($probe === false) {
            throw new \RuntimeException("no free port: $error");
        }
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $receiver->port = (int) substr($name, strrpos($name, ':') + 1);

        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $receiver->port, $dir . '/router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['PHP_CLI_SERVER_WORKERS' => '4'] + getenv()
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('php -S did not start');
        }
        $receiver->process = $process;
        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $receiver->port, $errno, $error, 0.1);
            if ($socket !== false) {
                fclose($socket);

                return $receiver;
            }
            usleep(50000);
        }
        $receiver->stop();
        throw new \RuntimeException('php -S did not answer on port ' . $receiver->port);
    }

    /** Where the receiver listens, for send(). */
    public function base(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    /** The URL a webhook registers to be answered $status after $waitMs. */
    public static function url(int $status = 200, int $waitMs = 0): string
    {
        return self::HOST . '/hook/' . $status . '/' . $waitMs;
    }

    public function down(bool $down): void
    {
        if ($down) {
            touch($this->dir . '/down');
        } elseif (is_file($this->dir . '/down')) {
            unlink($this->dir . '/down');
        }
    }

    /**
     * A WebhookDispatcher send: the dispatcher's headers and body, posted to
     * the receiver at $base.
     *
     * @param array<string, mixed> $delivery
     * @return array{0: bool, 1: ?int, 2: string}
     */
    public static function send(string $base, array $delivery): array
    {
        $url = (string) $delivery['webhook_url'];
        if (!str_starts_with($url, self::HOST . '/')) {
            throw new \LogicException('not a receiver URL: ' . $url);
        }
        $ch = curl_init($base . substr($url, strlen(self::HOST)));
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => (string) $delivery['payload'],
            CURLOPT_HTTPHEADER => WebhookDispatcher::headers($delivery),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            is_string($body) && $status >= 200 && $status < 300,
            $status > 0 ? $status : null,
            is_string($body) ? $body : 'curl: ' . $error,
        ];
    }

    /**
     * @return list<array{
     *     path: string, delivery: ?string, event: ?string, signature: ?string, body: string, at: float
     * }>
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
        if (is_file($this->dir . '/requests.log')) {
            unlink($this->dir . '/requests.log');
        }
        $this->down(false);
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
}
