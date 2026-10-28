<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookSender;
use Prosper202\Ltv\DeliverySender;
use Prosper202\Ltv\MysqlWebhookRepository;

/**
 * An LTV webhook delivery is sent the way an export webhook is (J0F7): the
 * export webhooks' guard when it is sent, whatever spelling of a private,
 * loopback, link-local or metadata address the host answers with (the old
 * guard approved 168.63.129.16, ::ffff:169.254.169.254, ::ffff:127.0.0.1
 * and 64:ff9b::a9fe:a9fe, and pinned the request to them), and the export
 * webhooks' request: pinned, no proxy, no redirects, https, TLS verified,
 * its time and its answer capped. The names resolve through a stubbed
 * resolver, so an AAAA answer is checked as a name's answer, not only as a
 * literal. The last two tests run the real transport against listeners on
 * this machine.
 */
final class DeliverySenderTest extends TestCase
{
    private const PUBLIC = '93.184.216.34';

    /** @var list<array<int, mixed>> */
    private array $sent = [];

    /** @return array<string, mixed> */
    private static function delivery(string $url): array
    {
        return [
            'delivery_id' => '42',
            'webhook_id' => '7',
            'event_name' => 'revenue.recorded',
            'payload' => '{"event":"revenue.recorded","occurred_at":1,"data":{"amount":49}}',
            'webhook_url' => $url,
            'webhook_secret' => 'the-secret',
            'webhook_status' => 'active',
        ];
    }

    /**
     * @param array<string, list<string>> $dns
     * @param array<string, mixed> $answer the transport's answer, over status 200 and body 'ok'
     */
    private function sender(array $dns, array $answer = [], string $allow = ''): DeliverySender
    {
        $transport = function (array $options) use ($answer): array {
            $this->sent[] = $options;

            $default = ['status' => 200, 'error' => null, 'primary_ip' => null, 'location' => null, 'body' => 'ok'];

            return $answer + $default;
        };

        return new DeliverySender(new WebhookSender(
            new WebhookGuard(static fn (string $host): array => $dns[$host] ?? [], $allow),
            $transport
        ));
    }

    /** @dataProvider \Tests\Attribution\WebhookGuardTest::nonPublicAnswers */
    public function testEverySpellingTheHostCanResolveToIsBlockedBeforeAnyRequest(
        string $address,
        string $why,
        bool $never
    ): void {
        $canonical = (string) inet_ntop((string) inet_pton($address));
        foreach ([[$address], [self::PUBLIC, $address], [$address, self::PUBLIC]] as $answers) {
            [$ok, $status, $logged] = $this->sender(['hooks.example.com' => $answers])
                ->post(self::delivery('https://hooks.example.com/p202'));
            self::assertFalse($ok);
            self::assertNull($status);
            self::assertStringStartsWith('blocked: hooks.example.com resolves to ' . $canonical . ', ', $logged);
            self::assertStringContainsString($why, $logged);
        }
        $literal = str_contains($address, ':') ? '[' . $address . ']' : $address;
        [$ok, , $logged] = $this->sender([])->post(self::delivery('https://' . $literal . ':8443/p202'));
        self::assertFalse($ok);
        self::assertStringContainsString($why, $logged);
        self::assertSame([], $this->sent, 'no request was made');

        // A webhook is saved under the same rules, without the lookup.
        $saved = (string) MysqlWebhookRepository::urlRefusal('https://' . $literal . '/p202');
        self::assertStringContainsString($why, $saved);

        if ($never) {
            [$ok, , $logged] = $this->sender(['hooks.example.com' => [$address]], [], '0.0.0.0/0, ::/0')
                ->post(self::delivery('https://hooks.example.com/p202'));
            self::assertFalse($ok, 'no allowlist admits it');
            self::assertStringContainsString($why, $logged);
            self::assertSame([], $this->sent);
        }
    }

    public function testADeliveryIsTheExportWebhooksRequest(): void
    {
        $delivery = self::delivery('https://hooks.example.com/p202');
        $answer = $this->sender(['hooks.example.com' => [self::PUBLIC, '2606:2800:220:1:248:1893:25c8:1946']], [
            'status' => 202, 'primary_ip' => self::PUBLIC, 'body' => 'queued',
        ])->post($delivery);

        self::assertSame([true, 202, 'queued'], $answer);
        self::assertCount(1, $this->sent);
        $o = $this->sent[0];
        self::assertSame(['hooks.example.com:443:' . self::PUBLIC], $o[CURLOPT_RESOLVE]);
        self::assertSame('https://hooks.example.com/p202', $o[CURLOPT_URL]);
        self::assertSame('', $o[CURLOPT_PROXY], 'a proxy would resolve the name itself and undo the pin');
        self::assertSame('*', $o[CURLOPT_NOPROXY]);
        self::assertFalse($o[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(0, $o[CURLOPT_MAXREDIRS]);
        self::assertSame(CURLPROTO_HTTPS, $o[CURLOPT_PROTOCOLS]);
        self::assertSame(CURLPROTO_HTTPS, $o[CURLOPT_REDIR_PROTOCOLS]);
        self::assertTrue($o[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $o[CURLOPT_SSL_VERIFYHOST]);
        self::assertSame(WebhookSender::CONNECT_TIMEOUT, $o[CURLOPT_CONNECTTIMEOUT]);
        self::assertSame(WebhookSender::TIMEOUT, $o[CURLOPT_TIMEOUT]);
        self::assertTrue($o[CURLOPT_POST]);
        self::assertSame($delivery['payload'], $o[CURLOPT_POSTFIELDS]);
        self::assertSame(DeliverySender::headers($delivery), $o[CURLOPT_HTTPHEADER]);
        self::assertSame(WebhookSender::curlOptions(
            (new WebhookGuard(static fn (): array => [self::PUBLIC], ''))->check('https://hooks.example.com/p202'),
            (string) $delivery['payload'],
            DeliverySender::headers($delivery)
        ), $o, 'every option is the export webhooks\' set');
    }

    public function testAQueryStraightAfterTheHostIsSentToTheRootPath(): void
    {
        // Saved before 1.9.79, when the LTV check did not ask for a path.
        $answer = $this->sender(['hooks.example.com' => [self::PUBLIC]], ['primary_ip' => self::PUBLIC])
            ->post(self::delivery('https://hooks.example.com?token=x'));
        self::assertSame([true, 200, 'ok'], $answer);
        self::assertSame('https://hooks.example.com/?token=x', $this->sent[0][CURLOPT_URL]);
        self::assertSame(['hooks.example.com:443:' . self::PUBLIC], $this->sent[0][CURLOPT_RESOLVE]);

        // The host is the one before the "?", whatever the query holds.
        $this->sent = [];
        $answer = $this->sender(['hooks.example.com' => [self::PUBLIC]], ['primary_ip' => self::PUBLIC])
            ->post(self::delivery('https://hooks.example.com?@10.0.0.1/'));
        self::assertTrue($answer[0]);
        self::assertSame('https://hooks.example.com/?@10.0.0.1/', $this->sent[0][CURLOPT_URL]);
        self::assertSame(['hooks.example.com:443:' . self::PUBLIC], $this->sent[0][CURLOPT_RESOLVE]);
    }

    public function testTheHeadersSignTheExactBody(): void
    {
        $delivery = self::delivery('https://hooks.example.com/p202');
        self::assertSame([
            'Content-Type: application/json',
            'X-P202-Signature: sha256=' . hash_hmac('sha256', (string) $delivery['payload'], 'the-secret'),
            'X-P202-Event: revenue.recorded',
            'X-P202-Delivery: 42',
            'User-Agent: Prosper202-LTV-Webhook/1.0',
        ], DeliverySender::headers($delivery));
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: array{0: bool, 1: ?int, 2: string}}> */
    public static function answers(): iterable
    {
        yield '200' => [['status' => 200, 'body' => 'ok'], [true, 200, 'ok']];
        yield 'a 2xx cut off at the size cap' => [
            ['status' => 200, 'error' => 'Failure writing output to destination', 'body' => 'x'],
            [true, 200, 'x'],
        ];
        yield '500' => [['status' => 500, 'body' => 'oops'], [false, 500, 'oops']];
        yield '404' => [['status' => 404, 'body' => ''], [false, 404, '']];
        yield 'a redirect is not followed' => [
            ['status' => 302, 'location' => 'http://169.254.169.254/'],
            [false, 302, 'ok'],
        ];
        yield 'no answer' => [
            ['status' => 0, 'error' => 'Connection timed out after 5001 milliseconds'],
            [false, null, 'curl: Connection timed out after 5001 milliseconds'],
        ];
        yield 'the pin did not hold' => [
            ['status' => 200, 'primary_ip' => '10.0.0.7'],
            [false, null, 'blocked: the connection went to 10.0.0.7 instead of the checked address ' . self::PUBLIC],
        ];
    }

    /**
     * @dataProvider answers
     * @param array<string, mixed> $answer
     * @param array{0: bool, 1: ?int, 2: string} $logged
     */
    public function testTheReceiversAnswerIsWhatTheLogRecords(array $answer, array $logged): void
    {
        $sender = $this->sender(['hooks.example.com' => [self::PUBLIC]], $answer);
        self::assertSame($logged, $sender->post(self::delivery('https://hooks.example.com/p202')));
        self::assertCount(1, $this->sent, 'one request, never a second to a Location');
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function refusedUrls(): iterable
    {
        yield 'plain http' => ['http://hooks.example.com/p202', 'must start with https://'];
        yield 'a user name hiding the host' => ['https://hooks.example.com@127.0.0.1/p202', 'not in the form'];
        yield 'a decimal loopback' => ['https://2130706433/p202', 'dotted form'];
        yield 'a local name' => ['https://metadata.google.internal/computeMetadata/v1/', 'local name'];
        yield 'a single label' => ['https://localhost/p202', 'single-label'];
        yield 'nothing to resolve to' => ['https://gone.example.com/p202', 'does not resolve'];
        yield 'a port an LTV webhook is not sent to' => [
            'https://hooks.example.com:8080/p202',
            'only sent to port 443 or 8443',
        ];
    }

    /** @dataProvider refusedUrls */
    public function testWhatTheGuardRefusesIsBlocked(string $url, string $why): void
    {
        [$ok, $status, $logged] = $this->sender(['hooks.example.com' => [self::PUBLIC]])->post(self::delivery($url));
        self::assertFalse($ok);
        self::assertNull($status);
        self::assertStringStartsWith('blocked: ', $logged);
        self::assertStringContainsString($why, $logged);
        self::assertSame([], $this->sent);
    }

    public function testTheOperatorsAllowlistAdmitsTheirReceiver(): void
    {
        $sender = $this->sender(['hooks.corp.example' => ['10.20.0.5']], ['primary_ip' => '10.20.0.5'], '10.20.0.0/16');
        self::assertSame([true, 200, 'ok'], $sender->post(self::delivery('https://hooks.corp.example/p202')));
        self::assertSame(['hooks.corp.example:443:10.20.0.5'], $this->sent[0][CURLOPT_RESOLVE]);

        [$ok, , $logged] = $this->sender(['hooks.corp.example' => ['10.21.0.5']], [], '10.20.0.0/16')
            ->post(self::delivery('https://hooks.corp.example/p202'));
        self::assertFalse($ok);
        self::assertStringContainsString('private', $logged);

        [$ok, , $logged] = (new DeliverySender(new WebhookSender(null, function (array $o): array {
            $this->sent[] = $o;

            return ['status' => 200, 'error' => null, 'primary_ip' => null, 'location' => null, 'body' => ''];
        })))->post(self::delivery('https://8.8.8.8/p202'));
        self::assertTrue($ok, 'with no allowlist defined, a public address is sent to');
    }

    public function testABodyOverTheCapIsNotSent(): void
    {
        $delivery = ['payload' => str_repeat('x', WebhookSender::MAX_BODY_BYTES + 1)]
            + self::delivery('https://hooks.example.com/p202');
        [$ok, , $logged] = $this->sender(['hooks.example.com' => [self::PUBLIC]])->post($delivery);
        self::assertFalse($ok);
        self::assertStringStartsWith('blocked: the body is ', $logged);
        self::assertSame([], $this->sent);
    }

    /**
     * The transport both senders use reads at most MAX_RESPONSE_BYTES of an
     * answer and keeps its status: a receiver that answers a megabyte is cut
     * off (plain http here, the one change to the delivery's own options, so
     * a listener needs no certificate).
     */
    public function testTheAnswerReadIsCapped(): void
    {
        $listener = <<<'PHP'
            $s = @stream_socket_server('tcp://127.0.73.3:0', $errno, $errstr);
            if ($s === false) { echo "no: $errstr\n"; exit; }
            $name = stream_socket_get_name($s, false);
            echo substr($name, strrpos($name, ':') + 1), "\n";
            $c = @stream_socket_accept($s, 10);
            if ($c === false) { exit; }
            fread($c, 65536);
            fwrite($c, "HTTP/1.1 200 OK\r\nContent-Length: 1048576\r\n\r\n");
            for ($i = 0; $i < 64 && @fwrite($c, str_repeat('y', 16384)) !== false; $i++) {}
            PHP;
        $process = proc_open([PHP_BINARY, '-r', $listener], [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        try {
            $port = trim((string) fgets($pipes[1]));
            if (!ctype_digit($port)) {
                self::markTestSkipped('no listener on 127.0.73.3 here: ' . $port);
            }
            $target = (new WebhookGuard(static fn (): array => ['127.0.73.3'], '127.0.73.3/32'))
                ->check('https://big.invalid:' . $port . '/p202');
            $options = WebhookSender::curlOptions($target, '{}', ['Expect:']);
            $options[CURLOPT_URL] = 'http://big.invalid:' . $port . '/p202';
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP;
            $answer = WebhookSender::curlTransport($options);

            self::assertSame(200, $answer['status']);
            self::assertSame('127.0.73.3', $answer['primary_ip'], 'connected through the pin');
            self::assertStringContainsString('Failure writing output', (string) $answer['error'], 'cut off');
            self::assertSame(1000, strlen($answer['body']), 'the first 1,000 bytes kept for the log');
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }

    public function testASendThatThrowsFailsThatDeliveryAlone(): void
    {
        $sender = new DeliverySender(new WebhookSender(
            new WebhookGuard(static fn (): array => [self::PUBLIC], ''),
            static function (): array {
                throw new \RuntimeException('the transport broke');
            }
        ));
        self::assertSame(
            [false, null, 'error: RuntimeException: the transport broke'],
            $sender->post(self::delivery('https://hooks.example.com/p202'))
        );
    }

    /**
     * The real transport, with this machine's https_proxy in the
     * environment: a name that resolves to ::ffff:127.0.73.1 (as an AAAA
     * answer would) is not connected to — the listener there, which a
     * request to that address reaches, hears nothing — while the same
     * delivery to an allowlisted listener reaches it, through the pin. Both
     * listen on 8443, a port an LTV webhook is sent to, on loopback
     * addresses of their own.
     */
    public function testAMappedLoopbackAnswerNeverReachesTheListener(): void
    {
        $listener = self::listen('127.0.73.1');
        $control = self::listen('127.0.73.2');
        try {
            $mapped = new DeliverySender(new WebhookSender(new WebhookGuard(
                static fn (string $host): array => $host === 'mapped.invalid' ? ['::ffff:127.0.73.1'] : [],
                ''
            )));
            [$ok, , $logged] = $mapped->post(self::delivery('https://mapped.invalid:8443/p202'));
            self::assertFalse($ok);
            self::assertStringContainsString(
                'blocked: mapped.invalid resolves to ::ffff:127.0.73.1, which is a loopback address',
                $logged
            );
            self::assertFalse(@stream_socket_accept($listener, 1), 'the listener on 127.0.73.1 heard nothing');

            $allowed = new DeliverySender(new WebhookSender(new WebhookGuard(
                static fn (string $host): array => $host === 'allowed.invalid' ? ['127.0.73.2'] : [],
                '127.0.73.2/32'
            ), static function (array $options): array {
                $options[CURLOPT_TIMEOUT] = 2;

                return WebhookSender::curlTransport($options);
            }));
            [$ok, , $logged] = $allowed->post(self::delivery('https://allowed.invalid:8443/p202'));
            self::assertFalse($ok, 'the control listener speaks no TLS');
            self::assertStringStartsWith('curl: ', $logged);
            self::assertNotFalse(@stream_socket_accept($control, 1), 'the control listener was reached');
        } finally {
            fclose($listener);
            fclose($control);
        }
    }

    /** @return resource */
    private static function listen(string $address)
    {
        $socket = @stream_socket_server('tcp://' . $address . ':8443', $errno, $errstr);
        if ($socket === false) {
            self::markTestSkipped('cannot listen on ' . $address . ':8443: ' . $errstr);
        }

        return $socket;
    }
}
