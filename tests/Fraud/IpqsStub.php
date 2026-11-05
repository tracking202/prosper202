<?php

declare(strict_types=1);

namespace Tests\Fraud;

/**
 * A stand-in for IPQualityScore's IP lookup under `php -S` on a free loopback
 * port, for one test class. The router is written to a temporary directory,
 * never kept under tests/, where a served checkout would run it. Every
 * request is logged (path, query, headers); the answer is chosen by the key:
 * one starting 'refused' answers success false quoting it, 'down' HTTP 503,
 * 'slow' after 8 s, 'big' 100 KB, 'moved' a redirect, 'garbled' no JSON, and
 * any other key the score scores() gave the address (10 for one it did not).
 */
final class IpqsStub
{
    private const ROUTER = <<<'PHP'
<?php

declare(strict_types=1);
$dir = (string) getenv('IPQS_STUB_DIR');
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
file_put_contents($dir . '/requests.log', json_encode([
    'path' => $path,
    'query' => $_GET,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    'referer' => $_SERVER['HTTP_REFERER'] ?? null,
]) . "\n", FILE_APPEND | LOCK_EX);

if (preg_match('~\A/api/json/ip/([^/]+)/([^/]+)\z~', $path, $m) !== 1) {
    http_response_code(404);
    exit;
}
$key = rawurldecode($m[1]);
$ip = rawurldecode($m[2]);
header('Content-Type: application/json');
switch (str_starts_with($key, 'refused') ? 'refused' : $key) {
    case 'refused':
        $message = 'Invalid or unauthorized key ' . $key . '. Please check the API key.';
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    case 'down':
        http_response_code(503);
        echo '{}';
        exit;
    case 'slow':
        sleep(8);
        echo json_encode(['success' => true, 'fraud_score' => 1]);
        exit;
    case 'big':
        echo json_encode(['success' => true, 'fraud_score' => 1, 'pad' => str_repeat('x', 100000)]);
        exit;
    case 'moved':
        header('Location: http://127.0.0.1:1/elsewhere', true, 302);
        exit;
    case 'garbled':
        echo '<html>not json</html>';
        exit;
}
$scores = json_decode((string) @file_get_contents($dir . '/scores.json'), true);
$score = is_array($scores) && isset($scores[$ip]) ? (int) $scores[$ip] : 10;
echo json_encode(['success' => true, 'fraud_score' => $score, 'proxy' => $score >= 90, 'request_id' => 'stub']);
PHP;

    /** @var resource|null */
    private $proc = null;

    public readonly string $dir;
    public readonly string $origin;

    public function __construct()
    {
        $dir = sys_get_temp_dir() . '/ipqs-stub-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700)) {
            throw new \RuntimeException('could not make ' . $dir);
        }
        $this->dir = $dir;
        if (file_put_contents($dir . '/router.php', self::ROUTER) === false) {
            throw new \RuntimeException('could not write the router');
        }
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            throw new \RuntimeException("no free port: $errstr");
        }
        $name = (string) stream_socket_get_name($probe, false);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        fclose($probe);
        $this->origin = 'http://127.0.0.1:' . $port;
        $env = getenv();
        $env['IPQS_STUB_DIR'] = $dir;
        $proc = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $dir . '/router.php'],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $dir . '/server.log', 'a'],
                2 => ['file', $dir . '/server.log', 'a'],
            ],
            $pipes,
            null,
            $env
        );
        if (!is_resource($proc)) {
            throw new \RuntimeException('the stub did not start');
        }
        $this->proc = $proc;
        for ($i = 0; $i < 100; $i++) {
            $up = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($up !== false) {
                fclose($up);

                return;
            }
            usleep(50000);
        }
        throw new \RuntimeException('the stub did not answer on ' . $this->origin);
    }

    /** @param array<string, int> $scores address => fraud score */
    public function scores(array $scores): void
    {
        file_put_contents($this->dir . '/scores.json', json_encode($scores));
    }

    /** @return list<array{path: string, query: array<string, string>, user_agent: ?string, referer: ?string}> */
    public function requests(): array
    {
        $log = @file_get_contents($this->dir . '/requests.log');
        if ($log === false || $log === '') {
            return [];
        }

        return array_map(static fn (string $line): array => json_decode($line, true), explode("\n", trim($log)));
    }

    /** The addresses looked up, in order. */
    public function addresses(): array
    {
        return array_map(
            static fn (array $r): string
                => rawurldecode((string) preg_replace('~\A/api/json/ip/[^/]+/~', '', $r['path'])),
            $this->requests()
        );
    }

    public function forget(): void
    {
        @unlink($this->dir . '/requests.log');
    }

    public function stop(): void
    {
        if ($this->proc !== null) {
            proc_terminate($this->proc);
            proc_close($this->proc);
            $this->proc = null;
        }
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }
}
