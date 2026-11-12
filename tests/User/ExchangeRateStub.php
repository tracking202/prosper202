<?php

declare(strict_types=1);

namespace Tests\User;

/**
 * A stand-in for the hosted exchange-rate service under `php -S` on a free
 * loopback port, for one test class (as Tests\Fraud\IpqsStub is for
 * IPQualityScore): no test asks the real service. The router is written to
 * a temporary directory, never kept under tests/, where a served checkout
 * would run it. Every request is logged whole: its method, path, form
 * fields and every header. The answer is chosen by the pair, from
 * answers(): a number is the rate, and 'down' is HTTP 503, 'garbled' a body
 * that is not JSON, 'moved' a redirect to an address that would answer a
 * rate, 'zero' a payout of 0, 'huge' a payout no rate gives, 'text' a payout
 * that is not a number.
 */
final class ExchangeRateStub
{
    private const ROUTER = <<<'PHP'
<?php

declare(strict_types=1);
$dir = (string) getenv('RATE_STUB_DIR');
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$headers = [];
foreach ($_SERVER as $name => $value) {
    if (str_starts_with($name, 'HTTP_') || in_array($name, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
        $headers[strtolower(str_replace('_', '-', preg_replace('/^HTTP_/', '', $name)))] = $value;
    }
}
ksort($headers);
file_put_contents($dir . '/requests.log', json_encode([
    'method' => $_SERVER['REQUEST_METHOD'], 'path' => $path, 'query' => $_GET, 'fields' => $_POST, 'headers' => $headers,
]) . "\n", FILE_APPEND | LOCK_EX);

header('Content-Type: application/json');
if ($path === '/elsewhere') {
    echo json_encode(['code' => 200, 'exchange_payout' => '777777.00']);
    exit;
}
if ($path !== '/api/v2/get-foreign-payout') {
    http_response_code(404);
    exit;
}
$answers = json_decode((string) @file_get_contents($dir . '/answers.json'), true);
$pair = ($_POST['currency'] ?? '') . '>' . ($_POST['payout_currency'] ?? '');
$answer = is_array($answers) ? ($answers[$pair] ?? 'down') : 'down';
switch ($answer) {
    case 'down':
        http_response_code(503);
        echo '{}';
        exit;
    case 'garbled':
        echo '<html>not json</html>';
        exit;
    case 'moved':
        header('Location: /elsewhere', true, 302);
        exit;
    case 'zero':
        echo json_encode(['code' => 200, 'exchange_payout' => '0.00']);
        exit;
    case 'huge':
        echo json_encode(['code' => 200, 'exchange_payout' => '900000000000000.00']);
        exit;
    case 'text':
        echo json_encode(['code' => 200, 'exchange_payout' => 'about a dollar']);
        exit;
}
echo json_encode(['code' => 200, 'exchange_payout' => number_format((float) ($_POST['payout'] ?? 0) * (float) $answer, 2, '.', '')]);
PHP;

    /** @var resource|null */
    private $proc = null;

    public readonly string $dir;
    public readonly string $origin;

    public function __construct()
    {
        $dir = sys_get_temp_dir() . '/rate-stub-' . bin2hex(random_bytes(6));
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
        $env['RATE_STUB_DIR'] = $dir;
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

    /** @param array<string, string> $answers "FROM>TO" => a rate, or one of the failures above */
    public function answers(array $answers): void
    {
        file_put_contents($this->dir . '/answers.json', json_encode($answers));
    }

    /** @return list<array{method: string, path: string, query: array<string, string>, fields: array<string, string>, headers: array<string, string>}> */
    public function requests(): array
    {
        $log = @file_get_contents($this->dir . '/requests.log');
        if ($log === false || $log === '') {
            return [];
        }

        return array_map(static fn (string $line): array => json_decode($line, true), explode("\n", trim($log)));
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
