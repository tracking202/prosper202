<?php

declare(strict_types=1);

namespace Tests\Messaging;

/**
 * A `php -S` on a free loopback port that records every request it gets
 * (method, the request target as received, body) and answers JSON: a chat
 * pass for a path ending in /chat/pass, {"ok":true} for anything else. It
 * stands in for the portal, and for a proxy (a proxied request arrives with
 * an absolute target, http://host:port/path).
 *
 * Between hold() and release() a request is recorded and then not answered,
 * for eight seconds at most: under MessagingClient's ten-second timeout, so
 * a held request is answered, never retried. It serves four at a time, so a
 * held request does not keep the next one from being recorded.
 */
final class LocalHttpRecorder
{
    public const PASS = 'cht1.eyJ2IjoxLCJ0eXAiOiJjdXN0b21lciJ9.c2lnbmF0dXJl';

    /** @var resource */
    private $process;
    private string $dir;
    private string $log;
    private int $port;

    private function __construct()
    {
    }

    public static function start(): self
    {
        $recorder = new self();
        $dir = sys_get_temp_dir() . '/p202-http-recorder-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700)) {
            throw new \RuntimeException("could not make $dir");
        }
        $recorder->dir = $dir;
        $recorder->log = $dir . '/requests.log';
        $router = $dir . '/router.php';
        $code = <<<'PHP'
            <?php
            $entry = json_encode([
                'method' => $_SERVER['REQUEST_METHOD'],
                'target' => $_SERVER['REQUEST_URI'],
                'body' => (string) file_get_contents('php://input'),
            ]);
            file_put_contents(__DIR__ . '/requests.log', $entry . "\n", FILE_APPEND | LOCK_EX);
            $answerBy = microtime(true) + 8;
            while (is_file(__DIR__ . '/hold') && microtime(true) < $answerBy) {
                usleep(20000);
                clearstatcache();
            }
            header('Content-Type: application/json');
            if (str_ends_with((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/chat/pass')) {
                echo json_encode(['ok' => true, 'pass' => '%PASS%', 'expires_at_s' => time() + 86400, 'chat_url' => 'https://chat.example.test']);
                return true;
            }
            echo '{"ok":true}';
            return true;
            PHP;
        file_put_contents($router, str_replace('%PASS%', self::PASS, $code));

        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($probe === false) {
            throw new \RuntimeException("no free port: $error");
        }
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $recorder->port = (int) substr($name, strrpos($name, ':') + 1);

        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $recorder->port, $router],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['PHP_CLI_SERVER_WORKERS' => '4'] + getenv()
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('php -S did not start');
        }
        $recorder->process = $process;
        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $recorder->port, $errno, $error, 0.1);
            if ($socket !== false) {
                fclose($socket);
                return $recorder;
            }
            usleep(50000);
        }
        $recorder->stop();
        throw new \RuntimeException('php -S did not answer on port ' . $recorder->port);
    }

    public function url(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    /** @return list<array{method: string, target: string, body: string}> */
    public function requests(): array
    {
        if (!is_file($this->log)) {
            return [];
        }
        $requests = [];
        foreach (file($this->log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $requests[] = json_decode($line, true, 4, JSON_THROW_ON_ERROR);
        }

        return $requests;
    }

    public function hold(): void
    {
        if (!touch($this->dir . '/hold')) {
            throw new \RuntimeException('could not hold the recorder\'s answers');
        }
    }

    public function release(): void
    {
        if (is_file($this->dir . '/hold') && !unlink($this->dir . '/hold')) {
            throw new \RuntimeException('could not release the recorder\'s answers');
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
}
