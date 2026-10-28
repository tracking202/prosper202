<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Exception\SyncException;
use Api\V3\Support\RemoteApiClient;
use Api\V3\Support\ServerStateStore;
use Api\V3\Support\SyncEngine;
use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\WebhookGuard;

/**
 * The client a sync calls its two remotes with: each request checked by the
 * guard and pinned to what it approved, no proxy and no redirect of curl's
 * own, an answer, a list and a whole sync bounded, every failure naming the
 * remote by its origin alone. Against listeners on this machine: a redirect
 * elsewhere is not followed (the key with it), a proxy in the environment is
 * not used, and an answer larger than the cap is not read to the end.
 *
 * @phpstan-type Answer array{
 *     status: int, error: string|null, primary_ip: string|null, redirect_url: string|null, body: string, capped: bool
 * }
 */
final class RemoteApiClientTest extends TestCase
{
    private const PUBLIC = '93.184.216.34';
    private const KEY = 'remote-key-0123456789abcdef';
    private const SYNC = 'https://sync.example.com';
    private const TARGET = 'the sync target ' . self::SYNC;

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
        $body = '{"data":[],"pagination":{"total":0}}';
        $head = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n";
        fwrite($conn, $head . $close . $body);
    } elseif ($mode === 'big') {
        $size = 9 * 1024 * 1024;
        fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Length: $size\r\n" . $close);
        for ($i = 0; $i < $size / 65536 && @fwrite($conn, str_repeat(' ', 65536)) !== false; $i++) {
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
    private static function guard(array $dns = ['sync.example.com' => [self::PUBLIC]], string $allow = ''): WebhookGuard
    {
        return new WebhookGuard(static fn (string $host): array => $dns[$host] ?? [], $allow);
    }

    /**
     * An answer as the transport gives one.
     *
     * @param array<string, mixed> $more
     * @return Answer
     */
    private static function answer(int $status, string $body = '{"data":{}}', array $more = []): array
    {
        $none = ['error' => null, 'primary_ip' => null, 'redirect_url' => null, 'capped' => false];

        return $more + ['status' => $status, 'body' => $body] + $none;
    }

    /**
     * A client whose transport answers from $answers in turn (the last one
     * again once they run out) and records the options of every call.
     *
     * @param list<Answer> $answers
     * @param list<array<int, mixed>> $calls
     */
    private static function client(
        string $url,
        array $answers,
        array &$calls,
        ?WebhookGuard $guard = null,
        ?float $deadline = null
    ): RemoteApiClient {
        $transport = static function (array $options) use (&$answers, &$calls): array {
            $calls[] = $options;

            return count($answers) > 1 ? array_shift($answers) : $answers[0];
        };

        return new RemoteApiClient($url, self::KEY, $deadline, 'target', $guard ?? self::guard(), $transport);
    }

    private static function failure(callable $call): SyncException
    {
        try {
            $call();
        } catch (SyncException $e) {
            return $e;
        }
        self::fail('the call did not fail');
    }

    private static function assertNamesOnlyTheOrigin(SyncException $e): void
    {
        self::assertStringNotContainsString(self::KEY, $e->getMessage());
        self::assertStringNotContainsString('offset=', $e->getMessage(), 'no query');
        self::assertStringNotContainsString('pw@', $e->getMessage(), 'no user info');
    }

    public function testEveryRequestIsPinnedWithNoProxyAndNoRedirectOfCurlsOwn(): void
    {
        $calls = [];
        $client = self::client(self::SYNC, [self::answer(200)], $calls);
        $client->put('campaigns/7', ['aff_campaign_name' => 'A']);

        self::assertCount(1, $calls);
        $o = $calls[0];
        self::assertSame(self::SYNC . '/api/v3/campaigns/7', $o[CURLOPT_URL]);
        self::assertSame('PUT', $o[CURLOPT_CUSTOMREQUEST]);
        self::assertSame('{"aff_campaign_name":"A"}', $o[CURLOPT_POSTFIELDS]);
        self::assertSame(['sync.example.com:443:' . self::PUBLIC], $o[CURLOPT_RESOLVE]);
        self::assertSame('', $o[CURLOPT_PROXY]);
        self::assertSame('*', $o[CURLOPT_NOPROXY]);
        self::assertFalse($o[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(0, $o[CURLOPT_MAXREDIRS]);
        self::assertSame(CURLPROTO_HTTPS, $o[CURLOPT_PROTOCOLS]);
        self::assertSame(CURLPROTO_HTTPS, $o[CURLOPT_REDIR_PROTOCOLS]);
        self::assertTrue($o[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $o[CURLOPT_SSL_VERIFYHOST]);
        self::assertSame(RemoteApiClient::CONNECT_TIMEOUT, $o[CURLOPT_CONNECTTIMEOUT]);
        self::assertSame(RemoteApiClient::REQUEST_TIMEOUT, $o[CURLOPT_TIMEOUT]);
        self::assertContains('Authorization: Bearer ' . self::KEY, $o[CURLOPT_HTTPHEADER]);

        // http:// to an address the operator admits: http only, pinned on
        // the URL's port.
        $calls = [];
        $admitted = self::guard(['staging.corp.example' => ['10.20.0.5']], '10.20.0.0/16');
        $local = self::client('http://staging.corp.example:8001', [self::answer(200)], $calls, $admitted);
        $local->get('campaigns');
        self::assertSame(['staging.corp.example:8001:10.20.0.5'], $calls[0][CURLOPT_RESOLVE]);
        self::assertSame(CURLPROTO_HTTP, $calls[0][CURLOPT_PROTOCOLS]);
    }

    /**
     * A profile url, why it is refused, and whether a job's save can tell
     * without a lookup (a name's address cannot be).
     *
     * @return iterable<string, array{0: string, 1: string, 2: bool}>
     */
    public static function refusedProfiles(): iterable
    {
        yield 'cloud metadata' => ['http://169.254.169.254/latest/meta-data', 'link-local', true];
        yield 'metadata by name' => ['https://meta.example.com', 'link-local', false];
        yield 'loopback' => ['https://127.0.0.1:8001', 'loopback', true];
        yield 'private' => ['https://10.0.0.5', 'private', true];
        yield 'private by name' => ['https://intranet.example.com', 'private', false];
        yield 'localhost' => ['http://localhost:8001', 'names this server', true];
        yield 'plain http to a public install' => ['http://sync.example.com', 'plain text', true];
        yield 'user info' => ['https://user:pw@sync.example.com', 'user name or password', true];
        yield 'a query' => ['https://sync.example.com/?x=1', 'not in the form', true];
        yield 'not http' => ['gopher://sync.example.com', 'must start with https://', true];
    }

    /** @dataProvider refusedProfiles */
    public function testAUrlTheGuardRefusesIsNeverHandedToTheTransport(string $url, string $why, bool $atSave): void
    {
        $calls = [];
        $guard = self::guard([
            'sync.example.com' => [self::PUBLIC],
            'meta.example.com' => ['169.254.169.254'],
            'intranet.example.com' => ['10.1.2.3'],
        ]);
        $client = self::client($url, [self::answer(200)], $calls, $guard);

        $e = self::failure(static fn () => $client->fetchAllRows('campaigns'));
        self::assertSame([], $calls, 'nothing was sent');
        self::assertSame(SyncException::URL_REFUSED, $e->errorCode);
        self::assertTrue($e->stopsSync);
        self::assertFalse($e->retryable, 'refused again the next time');
        self::assertStringContainsString('Not sent to the sync target ', $e->getMessage());
        self::assertStringContainsString($why, $e->getMessage());
        self::assertNamesOnlyTheOrigin($e);
        $save = RemoteApiClient::profileUrlRefusal($url, $guard);
        if ($atSave) {
            self::assertStringContainsString($why, (string) $save, 'a save refuses it without a lookup');
        } else {
            self::assertNull($save, 'a save cannot tell a name\'s address');
        }
    }

    public function testAHostThatDoesNotResolveIsTriedAgain(): void
    {
        $calls = [];
        $client = self::client('https://gone.example.com', [self::answer(200)], $calls);
        $e = self::failure(static fn () => $client->get('campaigns'));
        self::assertSame(SyncException::URL_UNRESOLVED, $e->errorCode);
        self::assertTrue($e->stopsSync);
        self::assertTrue($e->retryable);
        self::assertSame([], $calls);
    }

    public function testARedirectIsNotFollowedAndNamesOnlyWhereItPoints(): void
    {
        $calls = [];
        $redirect = self::answer(302, '', ['redirect_url' => 'https://other.example.net/steal?key=x']);
        $client = self::client(self::SYNC, [$redirect], $calls);
        $e = self::failure(static fn () => $client->get('campaigns', ['limit' => 1]));
        self::assertCount(1, $calls, 'one request, to the URL named');
        self::assertSame(SyncException::URL_REFUSED, $e->errorCode);
        $where = 'a redirect to https://other.example.net, which a sync does not follow';
        self::assertStringContainsString($where, $e->getMessage());
        self::assertStringNotContainsString('/steal', $e->getMessage());
        self::assertNamesOnlyTheOrigin($e);
    }

    public function testAConnectionThatLeftThePinIsNotRead(): void
    {
        $calls = [];
        $client = self::client(self::SYNC, [self::answer(200, '{"data":[]}', ['primary_ip' => '10.0.0.9'])], $calls);
        $e = self::failure(static fn () => $client->get('campaigns'));
        self::assertSame(SyncException::URL_REFUSED, $e->errorCode);
        $strayed = 'went to 10.0.0.9 instead of the checked address ' . self::PUBLIC;
        self::assertStringContainsString($strayed, $e->getMessage());
    }

    public function testAnAnswerLargerThanTheCapStopsTheSync(): void
    {
        $calls = [];
        $client = self::client(self::SYNC, [self::answer(200, str_repeat(' ', 10), ['capped' => true])], $calls);
        $e = self::failure(static fn () => $client->fetchAllRows('campaigns'));
        self::assertSame(SyncException::LIMIT, $e->errorCode);
        self::assertTrue($e->stopsSync);
        self::assertFalse($e->retryable);
        $message = $e->getMessage();
        self::assertStringContainsString('GET campaigns from ' . self::TARGET, $message);
        self::assertStringContainsString('larger than ' . RemoteApiClient::MAX_ANSWER_BYTES . ' bytes', $message);
        self::assertStringContainsString('RemoteApiClient::MAX_ANSWER_BYTES', $message);
        self::assertNamesOnlyTheOrigin($e);
    }

    /** A list that never ends is read to MAX_PAGES, then refused: never a shorter list. */
    public function testAListLongerThanTheCapStopsTheSync(): void
    {
        $page = (string) json_encode(['data' => array_fill(0, RemoteApiClient::PAGE_ROWS, ['aff_campaign_id' => 1])]);
        $calls = [];
        $client = self::client(self::SYNC, [self::answer(200, $page)], $calls);
        $e = self::failure(static fn () => $client->fetchAllRows('campaigns'));
        self::assertCount(RemoteApiClient::MAX_PAGES, $calls);
        self::assertSame(SyncException::LIMIT, $e->errorCode);
        $pages = 'more than ' . RemoteApiClient::MAX_PAGES . ' pages of ' . RemoteApiClient::PAGE_ROWS;
        self::assertStringContainsString($pages, $e->getMessage());
        self::assertStringContainsString('RemoteApiClient::MAX_PAGES', $e->getMessage());
        self::assertStringContainsString(self::SYNC, $e->getMessage());
        self::assertNamesOnlyTheOrigin($e);

        // The list's last page ends it, whether short or as its total says.
        $calls = [];
        $rows = array_fill(0, RemoteApiClient::PAGE_ROWS, ['id' => 1]);
        $full = (string) json_encode(['data' => $rows, 'pagination' => ['total' => '1000']]);
        $client = self::client(self::SYNC, [self::answer(200, $full)], $calls);
        self::assertCount(1000, $client->fetchAllRows('campaigns'));
        self::assertCount(2, $calls);
        self::assertStringContainsString('limit=500&offset=500', $calls[1][CURLOPT_URL]);
    }

    public function testASyncOutOfTimeSendsNothingMore(): void
    {
        $calls = [];
        $client = self::client(self::SYNC, [self::answer(200)], $calls, null, microtime(true) - 1);
        $e = self::failure(static fn () => $client->post('campaigns', ['a' => 1]));
        self::assertSame([], $calls);
        self::assertSame(SyncException::LIMIT, $e->errorCode);
        $message = $e->getMessage();
        self::assertStringContainsString('ran out of time at POST campaigns from ' . self::TARGET, $message);
        self::assertStringContainsString(RemoteApiClient::MAX_SECONDS . ' seconds in all', $message);
        self::assertStringContainsString('(RemoteApiClient::MAX_SECONDS)', $message);

        // A request still running at the deadline is the time cap too.
        $calls = [];
        $transport = static function (array $options) use (&$calls): array {
            $calls[] = $options;
            usleep(1_200_000);

            return self::answer(0, '', ['error' => 'Operation timed out']);
        };
        $slow = new RemoteApiClient(self::SYNC, self::KEY, microtime(true) + 1.1, 'source', self::guard(), $transport);
        $e = self::failure(static fn () => $slow->get('campaigns'));
        self::assertSame(1, $calls[0][CURLOPT_TIMEOUT], 'the request had what was left');
        self::assertSame(SyncException::LIMIT, $e->errorCode);
    }

    /** One SyncEngine run's two remotes share one deadline. */
    public function testBothRemotesOfASyncShareItsDeadline(): void
    {
        $dir = sys_get_temp_dir() . '/p202-sync-deadline-' . bin2hex(random_bytes(4));
        $engine = new class (new ServerStateStore($dir)) extends SyncEngine {
            /** @return array{0: RemoteApiClient, 1: RemoteApiClient} */
            public function clients(): array
            {
                $a = ['url' => 'https://a.example.com', 'api_key' => 'k'];
                $b = ['url' => 'https://b.example.com', 'api_key' => 'k'];

                return $this->buildClients($a, $b);
            }
        };
        $before = microtime(true);
        [$source, $target] = $engine->clients();
        $deadline = new \ReflectionProperty(RemoteApiClient::class, 'deadline');
        $side = new \ReflectionProperty(RemoteApiClient::class, 'side');
        self::assertSame($deadline->getValue($source), $deadline->getValue($target));
        self::assertEqualsWithDelta($before + RemoteApiClient::MAX_SECONDS, $deadline->getValue($source), 5);
        self::assertSame(['source', 'target'], [$side->getValue($source), $side->getValue($target)]);
        exec('rm -rf ' . escapeshellarg($dir));
    }

    public function testNoAnswerFailsTheRequestAlone(): void
    {
        $calls = [];
        $client = self::client(self::SYNC, [self::answer(0, '', ['error' => 'Connection refused'])], $calls);
        $e = self::failure(static fn () => $client->delete('campaigns/3'));
        self::assertSame(SyncException::REMOTE_FAILED, $e->errorCode);
        self::assertFalse($e->stopsSync, 'one record, as a failed write always was');
        self::assertTrue($e->retryable);
        $said = 'DELETE campaigns/3 from ' . self::TARGET . ': no answer (Connection refused)';
        self::assertStringContainsString($said, $e->getMessage());
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function notAPage(): iterable
    {
        $tooMany = (string) json_encode(['data' => array_fill(0, RemoteApiClient::PAGE_ROWS + 1, ['id' => 1])]);
        yield 'no data' => ['{"pagination":{"total":0}}', 'without a list of records'];
        yield 'data an object' => ['{"data":{"a":1}}', 'without a list of records'];
        yield 'a record not an object' => ['{"data":[1]}', 'not an object'];
        yield 'more than asked' => [$tooMany, 'to a request for at most'];
        yield 'a total that is not a number' => ['{"data":[],"pagination":{"total":"lots"}}', 'not a whole number'];
        yield 'not JSON' => ['<html>maintenance</html>', 'not a JSON object'];
    }

    /** @dataProvider notAPage */
    public function testAnAnswerThatIsNotAPageIsNeverReadAsAShortList(string $body, string $why): void
    {
        $calls = [];
        $client = self::client(self::SYNC, [self::answer(200, $body)], $calls);
        $e = self::failure(static fn () => $client->fetchAllRows('campaigns'));
        self::assertSame(SyncException::REMOTE_FAILED, $e->errorCode);
        self::assertStringContainsString($why, $e->getMessage());
        self::assertStringContainsString(self::SYNC, $e->getMessage());
    }

    public function testTheTransportSaysWhenCurlRefusesAnOption(): void
    {
        $answer = RemoteApiClient::curlTransport([CURLOPT_URL => 'http://127.0.0.1:9/', CURLOPT_SSLVERSION => 99]);
        self::assertSame(0, $answer['status']);
        self::assertStringContainsString('curl refused an option', (string) $answer['error']);
    }

    /**
     * A listener on this machine answering in $mode: ok, big (9 MB), or
     * redirect:<url>. Each request's first line is appended to the log.
     *
     * @return array{0: int, 1: string} port, log path
     */
    private function listener(string $host, string $mode): array
    {
        if ($this->dir === '') {
            $this->dir = sys_get_temp_dir() . '/p202-sync-client-' . bin2hex(random_bytes(6));
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

    private static function allowing(?callable $resolver = null): WebhookGuard
    {
        return new WebhookGuard($resolver ?? static fn (): array => [], '127.0.0.2/32');
    }

    public function testARedirectToAnotherHostIsNotFollowedSoTheKeyGoesNowhereElse(): void
    {
        [$elsewhere, $elsewhereLog] = $this->listener('127.0.0.2', 'ok');
        $location = 'http://127.0.0.2:' . $elsewhere . '/api/v3/campaigns';
        [$remote, $remoteLog] = $this->listener('127.0.0.2', 'redirect:' . $location);
        $client = new RemoteApiClient('http://127.0.0.2:' . $remote, self::KEY, null, 'target', self::allowing());

        $e = self::failure(static fn () => $client->get('campaigns'));
        self::assertSame(SyncException::URL_REFUSED, $e->errorCode);
        self::assertStringContainsString('302, a redirect to http://127.0.0.2:' . $elsewhere, $e->getMessage());
        self::assertSame("GET /api/v3/campaigns HTTP/1.1\n", (string) file_get_contents($remoteLog));
        self::assertSame('', (string) file_get_contents($elsewhereLog), 'where it pointed was not called');
    }

    public function testAProxyInTheEnvironmentIsNotUsedAndThePinHolds(): void
    {
        [$proxy, $proxyLog] = $this->listener('127.0.0.1', 'ok');
        [$remote, $remoteLog] = $this->listener('127.0.0.2', 'ok');
        $saved = [];
        $names = ['http_proxy', 'HTTP_PROXY', 'https_proxy', 'HTTPS_PROXY', 'all_proxy', 'ALL_PROXY'];
        foreach ([...$names, 'no_proxy', 'NO_PROXY'] as $name) {
            $saved[$name] = getenv($name);
            putenv(str_contains(strtolower($name), 'no_proxy') ? $name : $name . '=http://127.0.0.1:' . $proxy);
        }
        try {
            // A name nothing resolves but the guard's resolver: curl reaches
            // the listener only through the pin.
            $resolver = static fn (string $host): array => $host === 'sync.remote.test' ? ['127.0.0.2'] : [];
            $url = 'http://sync.remote.test:' . $remote;
            $client = new RemoteApiClient($url, self::KEY, null, 'source', self::allowing($resolver));
            $rows = $client->fetchAllRows('campaigns');
        } finally {
            foreach ($saved as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
        self::assertSame([], $rows);
        self::assertSame("GET /api/v3/campaigns?limit=500&offset=0 HTTP/1.1\n", (string) file_get_contents($remoteLog));
        self::assertSame('', (string) file_get_contents($proxyLog), 'the proxy in the environment was not used');
    }

    public function testALargeAnswerIsNotReadToTheEnd(): void
    {
        [$remote] = $this->listener('127.0.0.2', 'big');
        $client = new RemoteApiClient('http://127.0.0.2:' . $remote, self::KEY, null, 'source', self::allowing());
        $e = self::failure(static fn () => $client->get('campaigns'));
        self::assertSame(SyncException::LIMIT, $e->errorCode);
        $cap = 'larger than ' . RemoteApiClient::MAX_ANSWER_BYTES . ' bytes';
        self::assertStringContainsString($cap, $e->getMessage());
    }
}
