<?php

declare(strict_types=1);

namespace Tests\Notifications;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Notifications\PostbackResult;
use Prosper202\Notifications\PostbackSender;

/**
 * The postback sender: what it lets curl do (the address pinned, no proxy,
 * no redirect curl follows itself), what it does with each answer, and,
 * against listeners on this machine, that a refused address is never
 * connected to, a redirect to one is not followed, a proxy in the
 * environment is not used and a large answer is not read to the end.
 */
final class PostbackSenderTest extends TestCase
{
    private const PUBLIC = '93.184.216.34';

    /** A listener: prints its port, then logs each request line and answers per its mode. */
    private const LISTENER = <<<'PHP'
<?php
[, $host, $mode, $log] = $argv;
$server = @stream_socket_server("tcp://$host:0", $errno, $errstr);
if ($server === false) {
    echo "ERR $errstr\n";
    exit(1);
}
$name = (string) stream_socket_get_name($server, false);
echo substr($name, strrpos($name, ':') + 1), "\n";
$close = "Connection: close\r\n\r\n";
$until = time() + 30;
while (time() < $until) {
    $conn = @stream_socket_accept($server, 1);
    if ($conn === false) {
        continue;
    }
    $request = '';
    while (!str_contains($request, "\r\n\r\n") && !feof($conn)) {
        $request .= (string) fread($conn, 4096);
    }
    file_put_contents($log, strtok($request, "\r\n") . "\n", FILE_APPEND);
    if ($mode === 'ok') {
        fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n" . $close . 'ok');
    } elseif ($mode === 'big') {
        fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Length: 1048576\r\n" . $close);
        for ($i = 0; $i < 256 && @fwrite($conn, str_repeat('x', 4096)) !== false; $i++) {
        }
    } else {
        fwrite($conn, "HTTP/1.1 302 Found\r\nLocation: " . substr($mode, 9) . "\r\nContent-Length: 0\r\n" . $close);
    }
    fclose($conn);
}
PHP;

    /** @var list<resource> */
    private array $servers = [];
    private string $dir = '';

    protected function tearDown(): void
    {
        foreach ($this->servers as $process) {
            proc_terminate($process);
            proc_close($process);
        }
        $this->servers = [];
        if ($this->dir !== '') {
            array_map('unlink', glob($this->dir . '/*') ?: []);
            rmdir($this->dir);
        }
    }

    /** @param array<string, list<string>> $dns */
    private static function guard(array $dns = [], string $allow = ''): WebhookGuard
    {
        return new WebhookGuard(static fn (string $host): array => $dns[$host] ?? [], $allow);
    }

    /**
     * A sender whose transport answers from $answers in turn and records
     * the options of every call.
     *
     * @param list<array<string, mixed>> $answers
     * @param list<array<int, mixed>> $calls
     */
    private static function sender(WebhookGuard $guard, array $answers, array &$calls): PostbackSender
    {
        return new PostbackSender($guard, static function (array $options) use (&$answers, &$calls): array {
            $calls[] = $options;
            $answer = array_shift($answers) ?? ['status' => 0, 'error' => 'no more answers'];

            return $answer + ['error' => null, 'primary_ip' => null, 'redirect_url' => null];
        });
    }

    public function testTheConnectionIsPinnedWithNoProxyAndNoRedirectsOfCurlsOwn(): void
    {
        $guard = self::guard(['pb.network.example' => [self::PUBLIC]]);
        $opts = PostbackSender::curlOptions($guard->checkPostback('http://pb.network.example/pb?s=1'));

        self::assertSame('http://pb.network.example/pb?s=1', $opts[CURLOPT_URL]);
        $pin = ['pb.network.example:80:' . self::PUBLIC];
        self::assertSame($pin, $opts[CURLOPT_RESOLVE], 'pinned on the port an http URL connects to');
        // Through a proxy the proxy resolves the host and the pin is ignored.
        self::assertSame('', $opts[CURLOPT_PROXY]);
        self::assertSame('*', $opts[CURLOPT_NOPROXY]);
        self::assertFalse($opts[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(0, $opts[CURLOPT_MAXREDIRS]);
        self::assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $opts[CURLOPT_PROTOCOLS]);
        self::assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $opts[CURLOPT_REDIR_PROTOCOLS]);
        self::assertTrue($opts[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $opts[CURLOPT_SSL_VERIFYHOST]);
        self::assertSame(PostbackSender::CONNECT_TIMEOUT, $opts[CURLOPT_CONNECTTIMEOUT]);
        self::assertSame(PostbackSender::TIMEOUT, $opts[CURLOPT_TIMEOUT]);
        self::assertSame(PostbackSender::USER_AGENT, $opts[CURLOPT_USERAGENT]);

        $v6 = self::guard(['pb.network.example' => ['2606:4700::1111']]);
        $tls = PostbackSender::curlOptions($v6->checkPostback('https://pb.network.example:8443/pb'), 3);
        self::assertSame(['pb.network.example:8443:[2606:4700::1111]'], $tls[CURLOPT_RESOLVE]);
        $times = [$tls[CURLOPT_CONNECTTIMEOUT], $tls[CURLOPT_TIMEOUT]];
        self::assertSame([3, 3], $times, 'a later hop gets what is left of the time');

        $literal = PostbackSender::curlOptions(self::guard()->checkPostback('http://8.8.8.8/pb'));
        self::assertArrayNotHasKey(CURLOPT_RESOLVE, $literal, 'an address is connected to as written');
        self::assertSame('', $literal[CURLOPT_PROXY]);
    }

    public function testARefusedUrlIsNeverHandedToCurl(): void
    {
        $calls = [];
        $urls = [
            'http://127.0.0.1:8122/pb?key=SECRET',
            'http://169.254.169.254/latest/meta-data/?key=SECRET',
            'http://user:SECRET@pb.network.example/',
            'gopher://pb.network.example/',
            'http://localhost/pb?key=SECRET',
        ];
        foreach ($urls as $url) {
            $result = self::sender(self::guard(), [], $calls)->send($url);
            self::assertTrue($result->isRefused(), $url);
            self::assertStringStartsWith('not sent: ', $result->reason);
            self::assertStringNotContainsString('SECRET', $result->reason, 'the reason names hosts, never queries');
        }
        self::assertSame([], $calls);

        $guard = self::guard(['pb.network.example' => ['10.0.0.8']]);
        $private = self::sender($guard, [], $calls)->send('http://pb.network.example/pb');
        self::assertTrue($private->isRefused());
        $why = 'pb.network.example resolves to 10.0.0.8, which is a private address';
        self::assertStringContainsString($why, $private->reason);
        self::assertSame([], $calls);
    }

    public function testAHostThatDoesNotResolveIsTriedAgainLater(): void
    {
        $calls = [];
        $result = self::sender(self::guard(), [], $calls)->send('http://pb.network.example/pb');
        self::assertSame(PostbackResult::FAILED, $result->outcome);
        self::assertStringContainsString('does not resolve', $result->reason);
        self::assertSame([], $calls);
    }

    public function testEachAnswerIsReadAsBefore(): void
    {
        $guard = self::guard(['pb.network.example' => [self::PUBLIC]]);
        $cases = [
            'a 200 is delivered' => [['status' => 200, 'primary_ip' => self::PUBLIC], PostbackResult::DELIVERED],
            'a 3xx with nowhere to go is delivered, as before' => [['status' => 304], PostbackResult::DELIVERED],
            'a 500 is tried again' => [['status' => 500], PostbackResult::FAILED],
            'a 404 is tried again' => [['status' => 404], PostbackResult::FAILED],
            'no answer is tried again' => [['status' => 0, 'error' => 'Connection timed out'], PostbackResult::FAILED],
            'a connection off the pin' => [['status' => 200, 'primary_ip' => '10.9.9.9'], PostbackResult::REFUSED],
        ];
        foreach ($cases as $name => [$answer, $outcome]) {
            $calls = [];
            $result = self::sender($guard, [$answer], $calls)->send('http://pb.network.example/pb');
            self::assertSame($outcome, $result->outcome, $name . ': ' . $result->reason);
            self::assertCount(1, $calls, $name);
        }
    }

    public function testEveryRedirectIsCheckedAndPinnedAsTheFirstUrlWas(): void
    {
        $guard = self::guard([
            'old.network.example' => [self::PUBLIC],
            'new.network.example' => ['93.184.216.35'],
            'inside.network.example' => ['10.1.1.1'],
        ]);

        $calls = [];
        $moved = self::sender($guard, [
            ['status' => 301, 'redirect_url' => 'https://new.network.example/pb?s=1'],
            ['status' => 200],
        ], $calls)->send('http://old.network.example/pb?s=1');
        self::assertTrue($moved->isDelivered(), $moved->reason);
        self::assertSame(['old.network.example:80:' . self::PUBLIC], $calls[0][CURLOPT_RESOLVE]);
        self::assertSame('https://new.network.example/pb?s=1', $calls[1][CURLOPT_URL]);
        $pin = ['new.network.example:443:93.184.216.35'];
        self::assertSame($pin, $calls[1][CURLOPT_RESOLVE], 'the new host is resolved by the guard and pinned');
        self::assertFalse($calls[1][CURLOPT_FOLLOWLOCATION]);

        $locations = [
            'http://127.0.0.1:8122/internal?token=SECRET' => 'loopback',
            'http://inside.network.example/x?token=SECRET' => 'private',
            'http://169.254.169.254/latest/meta-data/' => 'link-local',
            'file:///etc/passwd' => 'must start with http',
        ];
        foreach ($locations as $location => $why) {
            $calls = [];
            $answers = [['status' => 302, 'redirect_url' => $location]];
            $result = self::sender($guard, $answers, $calls)->send('http://old.network.example/pb');
            self::assertTrue($result->isRefused(), $location);
            self::assertStringStartsWith('redirected to ', $result->reason);
            self::assertStringContainsString($why, $result->reason);
            self::assertStringNotContainsString('SECRET', $result->reason);
            self::assertCount(1, $calls, $location . ' was not called');
        }

        $calls = [];
        $again = array_fill(0, 10, ['status' => 302, 'redirect_url' => 'http://old.network.example/again']);
        $loop = self::sender($guard, $again, $calls)->send('http://old.network.example/pb');
        self::assertSame(PostbackResult::FAILED, $loop->outcome);
        self::assertStringContainsString('more than ' . PostbackSender::MAX_REDIRECTS . ' redirects', $loop->reason);
        self::assertCount(PostbackSender::MAX_REDIRECTS + 1, $calls);
    }

    public function testASenderThatFailsFailsOnePostback(): void
    {
        $sender = new PostbackSender(self::guard(['pb.network.example' => [self::PUBLIC]]), static function (): array {
            throw new \RuntimeException('transport exploded');
        });
        $result = $sender->send('http://pb.network.example/pb');
        self::assertSame(PostbackResult::FAILED, $result->outcome);
        self::assertStringContainsString('transport exploded', $result->reason);
    }

    public function testAnAllowlistThatDoesNotParseSendsNothingAndKeepsThePostback(): void
    {
        $script = 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . "define('P202_WEBHOOK_ALLOW_NETWORKS', '10.0.0.0');"
            . '$t = static function (): array { echo "CALLED"; return ["status" => 200, "error" => null,'
            . ' "primary_ip" => null, "redirect_url" => null]; };'
            . '$r = (new Prosper202\Notifications\PostbackSender(null, $t))->send("http://8.8.8.8/pb");'
            . 'echo $r->outcome, "|", $r->reason;';
        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1');
        self::assertStringStartsWith('failed|not sent: P202_WEBHOOK_ALLOW_NETWORKS entry "10.0.0.0"', $out);
        self::assertStringNotContainsString('CALLED', $out);
    }

    public function testTheOriginNamesNoPathQueryOrPassword(): void
    {
        $origin = PostbackSender::origin('HTTP://pb.network.example:8080/k/SECRET?key=SECRET#x');
        self::assertSame('http://pb.network.example:8080', $origin);
        $origin = PostbackSender::origin('https://user:SECRET@pb.network.example?x=1');
        self::assertSame('https://pb.network.example', $origin);
        self::assertSame('http://evil.example?Injected', PostbackSender::origin("http://evil.example\nInjected/"));
        self::assertSame('(not a URL)', PostbackSender::origin('javascript:alert(1)'));
    }

    /**
     * A listener on this machine, answering in $mode: ok, big (a 1 MB
     * body), or redirect:<url>. Each request's first line is appended to
     * the returned log.
     *
     * @return array{0: int, 1: string} port, log path
     */
    private function listener(string $host, string $mode): array
    {
        if ($this->dir === '') {
            $this->dir = sys_get_temp_dir() . '/p202-postback-' . bin2hex(random_bytes(6));
            mkdir($this->dir, 0700);
            file_put_contents($this->dir . '/server.php', self::LISTENER);
        }
        $log = $this->dir . '/' . bin2hex(random_bytes(4)) . '.log';
        touch($log);
        $command = [PHP_BINARY, $this->dir . '/server.php', $host, $mode, $log];
        $process = proc_open($command, [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $this->servers[] = $process;
        $line = trim((string) fgets($pipes[1]));
        if (!ctype_digit($line)) {
            self::markTestSkipped("no listener on $host here: $line");
        }

        return [(int) $line, $log];
    }

    /** @return WebhookGuard admitting the listeners on 127.0.0.2 */
    private static function allowing(?callable $resolver = null): WebhookGuard
    {
        return new WebhookGuard($resolver ?? static fn (): array => [], '127.0.0.2/32');
    }

    public function testARedirectToAnInternalAddressIsNotFollowed(): void
    {
        [$internal, $internalLog] = $this->listener('127.0.0.1', 'ok');
        $location = 'http://127.0.0.1:' . $internal . '/admin?token=SECRET';
        [$network, $networkLog] = $this->listener('127.0.0.2', 'redirect:' . $location);
        $sender = new PostbackSender(self::allowing());

        $result = $sender->send('http://127.0.0.2:' . $network . '/pb?s=1');

        self::assertTrue($result->isRefused(), $result->outcome . ': ' . $result->reason);
        $why = 'redirected to http://127.0.0.1:' . $internal . ', which was not followed';
        self::assertStringContainsString($why, $result->reason);
        self::assertSame("GET /pb?s=1 HTTP/1.1\n", (string) file_get_contents($networkLog), 'the network was called');
        self::assertSame('', (string) file_get_contents($internalLog), 'and where it redirected was not');
        $straight = $sender->send('http://127.0.0.1:' . $internal . '/pb');
        self::assertTrue($straight->isRefused(), 'nor called straight');
        self::assertSame('', (string) file_get_contents($internalLog));
    }

    public function testAProxyInTheEnvironmentIsNotUsedAndThePinHolds(): void
    {
        [$proxy, $proxyLog] = $this->listener('127.0.0.1', 'ok');
        [$network, $networkLog] = $this->listener('127.0.0.2', 'ok');
        $saved = [];
        $names = ['http_proxy', 'HTTP_PROXY', 'https_proxy', 'HTTPS_PROXY', 'all_proxy', 'ALL_PROXY'];
        $names = [...$names, 'no_proxy', 'NO_PROXY'];
        foreach ($names as $name) {
            $saved[$name] = getenv($name);
            putenv(str_contains(strtolower($name), 'no_proxy') ? $name : $name . '=http://127.0.0.1:' . $proxy);
        }
        try {
            // A name nothing resolves but the guard's resolver: curl can only
            // reach the listener through the pin.
            $resolver = static fn (string $host): array => $host === 'pb.network.test' ? ['127.0.0.2'] : [];
            $sender = new PostbackSender(self::allowing($resolver));
            $result = $sender->send('http://pb.network.test:' . $network . '/pb?s=2');
        } finally {
            foreach ($saved as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
        self::assertTrue($result->isDelivered(), $result->outcome . ': ' . $result->reason);
        self::assertSame("GET /pb?s=2 HTTP/1.1\n", (string) file_get_contents($networkLog));
        self::assertSame('', (string) file_get_contents($proxyLog), 'the proxy in the environment was not used');
    }

    public function testALargeAnswerIsNotReadToTheEnd(): void
    {
        [$network] = $this->listener('127.0.0.2', 'big');
        $url = 'http://127.0.0.2:' . $network . '/pb';
        $options = PostbackSender::curlOptions(self::allowing()->checkPostback($url));
        $response = PostbackSender::curlTransport($options);
        self::assertSame(200, $response['status']);
        self::assertNotNull($response['error'], 'cut off at ' . PostbackSender::MAX_RESPONSE_BYTES . ' bytes');
        $result = (new PostbackSender(self::allowing()))->send($url);
        self::assertTrue($result->isDelivered(), 'and its status still counts');
    }
}
