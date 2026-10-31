<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookSender;

/**
 * Delivery: the check runs again at send time and the connection goes to
 * the address it approved (DNS rebinding), with nothing left to the
 * environment — no proxy, https only, no redirects, bounded in time and
 * size — and the receiver's answer decides delivered / retry / give up.
 *
 * The last test is not a fake: it runs the real curl transport, with the
 * sandbox's own https_proxy in the environment, against a name that cannot
 * resolve (.invalid), pinned to a local listener, and shows the connection
 * landed on the pinned address.
 */
final class WebhookSenderTest extends TestCase
{
    /** @var list<array<int, mixed>> */
    private array $sent = [];

    /**
     * @param array{status?: int, error?: string|null, primary_ip?: string|null, location?: string|null, body?: string} $answer
     */
    private function sender(callable $resolve, array $answer = [], string $allow = ''): WebhookSender
    {
        $transport = function (array $options) use ($answer): array {
            $this->sent[] = $options;

            return $answer + ['status' => 200, 'error' => null, 'primary_ip' => null, 'location' => null, 'body' => ''];
        };

        return new WebhookSender(new WebhookGuard($resolve, $allow), $transport);
    }

    public function testTheConnectionIsPinnedToTheCheckedAddressAndNothingIsLeftToTheEnvironment(): void
    {
        $result = $this->sender(static fn (): array => ['93.184.216.34'], ['status' => 204, 'primary_ip' => '93.184.216.34'])
            ->send('https://hooks.example.com/p202', 'k3y-k3y-k3y-k3y-k3y', "a,b\n1,2\n", 7, 1, 1790000000);

        self::assertTrue($result->delivered);
        self::assertSame(204, $result->status);
        self::assertCount(1, $this->sent);
        $o = $this->sent[0];
        self::assertSame(['hooks.example.com:443:93.184.216.34'], $o[CURLOPT_RESOLVE]);
        self::assertSame('https://hooks.example.com/p202', $o[CURLOPT_URL]);
        self::assertFalse($o[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(0, $o[CURLOPT_MAXREDIRS]);
        self::assertSame(CURLPROTO_HTTPS, $o[CURLOPT_PROTOCOLS]);
        self::assertSame(CURLPROTO_HTTPS, $o[CURLOPT_REDIR_PROTOCOLS]);
        self::assertSame('', $o[CURLOPT_PROXY], 'an inherited https_proxy would resolve the name itself and undo the pin');
        self::assertSame('*', $o[CURLOPT_NOPROXY]);
        self::assertTrue($o[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $o[CURLOPT_SSL_VERIFYHOST]);
        self::assertSame(WebhookSender::CONNECT_TIMEOUT, $o[CURLOPT_CONNECTTIMEOUT]);
        self::assertSame(WebhookSender::TIMEOUT, $o[CURLOPT_TIMEOUT]);
        self::assertSame("a,b\n1,2\n", $o[CURLOPT_POSTFIELDS]);

        $headers = $o[CURLOPT_HTTPHEADER];
        self::assertContains('X-P202-Export-Id: 7', $headers);
        self::assertContains('X-P202-Timestamp: 1790000000', $headers);
        self::assertContains('X-P202-Signature: sha256=' . hash_hmac('sha256', "1790000000.a,b\n1,2\n", 'k3y-k3y-k3y-k3y-k3y'), $headers);
        self::assertContains('Content-Type: text/csv; charset=utf-8', $headers);
    }

    public function testAnIPv6AnswerIsPinnedInBrackets(): void
    {
        $this->sender(static fn (): array => ['2606:4700:4700::1111'], ['primary_ip' => '2606:4700:4700:0:0:0:0:1111'])
            ->send('https://v6.example.com:8443/h', 'secretsecretsecret', 'x', 1, 1);
        self::assertSame(['v6.example.com:8443:[2606:4700:4700::1111]'], $this->sent[0][CURLOPT_RESOLVE]);
    }

    public function testAnIpLiteralIsConnectedToAsWritten(): void
    {
        $this->sender(static fn (): array => [])->send('https://8.8.8.8/h', 'secretsecretsecret', 'x', 1, 1);
        self::assertArrayNotHasKey(CURLOPT_RESOLVE, $this->sent[0]);
    }

    /**
     * DNS rebinding: the name answered a public address when the export was
     * saved and answers a private one now. The send-time check refuses it
     * and nothing is sent.
     */
    public function testANameThatNowResolvesPrivatelyIsNotSentTo(): void
    {
        $answers = [['93.184.216.34'], ['169.254.169.254']];
        $resolve = static function () use (&$answers): array {
            return array_shift($answers) ?? [];
        };
        $guard = new WebhookGuard($resolve, '');
        $guard->check('https://rebind.example.com/'); // at save time: public
        $sent = 0;
        $sender = new WebhookSender($guard, static function () use (&$sent): array {
            $sent++;

            return ['status' => 200, 'error' => null, 'primary_ip' => null, 'location' => null, 'body' => ''];
        });

        $result = $sender->send('https://rebind.example.com/', 'secretsecretsecret', 'x', 1, 1);
        self::assertFalse($result->delivered);
        self::assertFalse($result->retryable, 'a refused destination is not retried');
        self::assertStringContainsString('resolves to 169.254.169.254', (string) $result->error);
        self::assertSame(0, $sent, 'no request was made');
    }

    public function testAConnectionThatLandedElsewhereIsNotDelivered(): void
    {
        $result = $this->sender(static fn (): array => ['93.184.216.34'], ['status' => 200, 'primary_ip' => '10.0.0.7'])
            ->send('https://hooks.example.com/', 'secretsecretsecret', 'x', 1, 1);
        self::assertFalse($result->delivered);
        self::assertStringContainsString('instead of the checked address 93.184.216.34', (string) $result->error);
    }

    public function testARedirectIsNeverFollowedAndNotRetried(): void
    {
        $result = $this->sender(static fn (): array => ['93.184.216.34'], ['status' => 302, 'location' => 'http://169.254.169.254/latest/meta-data/'])
            ->send('https://hooks.example.com/', 'secretsecretsecret', 'x', 1, 1);
        self::assertFalse($result->delivered);
        self::assertFalse($result->retryable);
        self::assertSame(302, $result->status);
        self::assertStringContainsString('Redirects are not followed', (string) $result->error);
        self::assertStringContainsString('169.254.169.254', (string) $result->error, 'the message says where it tried to go');
        self::assertCount(1, $this->sent, 'one request, no second one to the Location');
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: bool, 2: bool}> */
    public static function answers(): iterable
    {
        yield '200' => [['status' => 200], true, false];
        yield '202' => [['status' => 202], true, false];
        yield '500 retries' => [['status' => 500, 'body' => 'oops'], false, true];
        yield '503 retries' => [['status' => 503], false, true];
        yield '429 retries' => [['status' => 429], false, true];
        yield '408 retries' => [['status' => 408], false, true];
        yield '404 gives up' => [['status' => 404], false, false];
        yield '401 gives up' => [['status' => 401], false, false];
        yield 'no connection retries' => [['status' => 0, 'error' => 'Connection timed out after 5001 milliseconds'], false, true];
        yield 'a 2xx cut off at the size cap is delivered' => [['status' => 200, 'error' => 'Failure writing output to destination'], true, false];
    }

    /**
     * @dataProvider answers
     * @param array<string, mixed> $answer
     */
    public function testTheReceiversAnswerDecides(array $answer, bool $delivered, bool $retryable): void
    {
        $result = $this->sender(static fn (): array => ['93.184.216.34'], $answer)->send('https://hooks.example.com/', 'secretsecretsecret', 'x', 1, 1);
        self::assertSame($delivered, $result->delivered);
        self::assertSame($retryable, $result->retryable);
    }

    public function testABodyOverTheCapIsNotSent(): void
    {
        $result = $this->sender(static fn (): array => ['93.184.216.34'])
            ->send('https://hooks.example.com/', 'secretsecretsecret', str_repeat('x', WebhookSender::MAX_BODY_BYTES + 1), 1, 1);
        self::assertFalse($result->delivered);
        self::assertFalse($result->retryable);
        self::assertSame([], $this->sent);
    }

    public function testAMalformedAllowlistFailsTheDeliveryNotTheRun(): void
    {
        // The guard is built lazily from the constant when none is given; a
        // broken allowlist surfaces as this job's error, per delivery.
        $sender = new WebhookSender(null, function (array $options): array {
            $this->sent[] = $options;

            return ['status' => 200, 'error' => null, 'primary_ip' => null, 'location' => null, 'body' => ''];
        });
        $result = $sender->send('http://example.com/', 'secretsecretsecret', 'x', 1, 1);
        self::assertFalse($result->delivered);
        self::assertStringContainsString('https://', (string) $result->error);
        self::assertSame([], $this->sent);
    }

    /**
     * The real transport. A listener on 127.0.0.2 (allowlisted for this
     * guard), a URL whose name cannot resolve anywhere, the sandbox's
     * https_proxy in the environment: the connection still lands on the
     * pinned address, which only CURLOPT_RESOLVE with the proxy disabled can
     * do. The listener never speaks TLS, so the transfer times out after
     * the connect; what matters is where it connected.
     */
    public function testTheRealTransportConnectsToThePinnedAddress(): void
    {
        $server = @stream_socket_server('tcp://127.0.0.2:0', $errno, $errstr);
        if ($server === false) {
            self::markTestSkipped('cannot listen on 127.0.0.2: ' . $errstr);
        }
        $port = (int) substr((string) stream_socket_get_name($server, false), strrpos((string) stream_socket_get_name($server, false), ':') + 1);
        try {
            $guard = new WebhookGuard(static fn (string $host): array => $host === 'pin-check.invalid' ? ['127.0.0.2'] : [], '127.0.0.2/32');
            $target = $guard->check('https://pin-check.invalid:' . $port . '/hook');
            $options = WebhookSender::curlOptions($target, 'x', ['Expect:']);
            $options[CURLOPT_TIMEOUT] = 2;
            $answer = WebhookSender::curlTransport($options);

            self::assertSame('127.0.0.2', $answer['primary_ip'], 'curl connected to the pinned address: ' . (string) $answer['error']);
            self::assertSame(0, $answer['status'], 'no HTTP answer from a listener that never spoke');
            $accepted = @stream_socket_accept($server, 1);
            self::assertNotFalse($accepted, 'the listener received the connection');
        } finally {
            fclose($server);
        }
    }
}
