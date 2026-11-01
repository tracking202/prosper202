<?php

declare(strict_types=1);

namespace Tests\Notifications;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookSender;
use Prosper202\Notifications\SlackWebhook;

/**
 * A Slack notice is posted the way the export and LTV webhooks are
 * (WebhookSender): checked by WebhookGuard when it is sent, pinned to the
 * address it approved, no proxy, https only, no redirects, and over in
 * SlackWebhook::TIMEOUT seconds. One that is refused or not taken is logged
 * with why, never with the URL's path (the webhook's secret), and push()
 * answers false without throwing.
 *
 * It posted with curl and nothing else: any address the field named, http
 * included, and no timeout, so a host that never answered held the request
 * that sent the notice (a failed sign-in, ClickBank's test) for as long as
 * the server let it, and every failure was dropped without a word.
 */
final class SlackNoticeTest extends TestCase
{
    private const PUBLIC = '93.184.216.34';
    private const SECRET = '/services/T0000/B0000/s3cr3tPathXyz';

    /** @var list<array<int, mixed>> */
    private array $sent = [];
    private string $log = '';
    private string|false $savedLog = false;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/Slack.class.php';
    }

    protected function setUp(): void
    {
        $this->log = (string) tempnam(sys_get_temp_dir(), 'p202-slack-log-');
        $this->savedLog = ini_get('error_log');
        ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->savedLog === false ? '' : $this->savedLog);
        @unlink($this->log);
    }

    /**
     * @param array<string, mixed> $answer the transport's answer, over a 200 "ok"
     */
    private function slack(string $url, array $answer = [], string $allow = ''): \Slack
    {
        $transport = function (array $options) use ($answer): array {
            $this->sent[] = $options;

            return $answer
                + ['status' => 200, 'error' => null, 'primary_ip' => null, 'location' => null, 'body' => 'ok'];
        };
        $resolve = static fn (string $host): array => match ($host) {
            'hooks.slack.com' => [self::PUBLIC],
            'inside.example.com' => ['10.1.2.3'],
            'metadata.example.com' => ['169.254.169.254'],
            default => [],
        };

        return new \Slack($url, new SlackWebhook(new WebhookSender(new WebhookGuard($resolve, $allow), $transport)));
    }

    private function logged(): string
    {
        return (string) file_get_contents($this->log);
    }

    /** @return array<string, array{string, string}> URL => a word of the refusal */
    public static function refusedUrls(): array
    {
        return [
            'plain http' => ['http://hooks.slack.com' . self::SECRET, 'https://'],
            'a private address' => ['https://10.0.0.5' . self::SECRET, 'private'],
            'a name resolving to a private address' => ['https://inside.example.com' . self::SECRET, 'private'],
            'loopback' => ['https://127.0.0.1' . self::SECRET, 'loopback'],
            'IPv6 loopback' => ['https://[::1]' . self::SECRET, 'loopback'],
            'link-local, where cloud metadata answers' => ['https://169.254.169.254' . self::SECRET, 'link-local'],
            'a name resolving to the metadata address' => ['https://metadata.example.com' . self::SECRET, 'link-local'],
            'a single-label name' => ['https://localhost' . self::SECRET, 'single-label'],
            'another spelling of loopback' => [
                'https://0x7f.1' . self::SECRET,
                'Other spellings of an address are refused',
            ],
            'a name that does not resolve' => ['https://nowhere.example.com' . self::SECRET, 'does not resolve'],
            'not a URL' => ['hooks', 'https://'],
        ];
    }

    /** @dataProvider refusedUrls */
    public function testARefusedAddressIsNeverConnectedToAndTheRefusalIsLogged(string $url, string $why): void
    {
        self::assertFalse($this->slack($url)->push('cb_key_verified'));

        self::assertSame([], $this->sent, 'nothing was sent');
        $log = $this->logged();
        self::assertStringContainsString('Slack: the "cb_key_verified" notice was not sent: refused: ', $log);
        self::assertStringContainsString($why, $log);
        self::assertStringNotContainsString('s3cr3tPathXyz', $log, 'the webhook path is its secret');
    }

    public function testTheNoticeIsPinnedAndOverInTwoSeconds(): void
    {
        $slack = $this->slack('https://hooks.slack.com' . self::SECRET, ['primary_ip' => self::PUBLIC]);
        self::assertTrue($slack->push('failed_login', ['username' => 'admin', 'ip' => '198.51.100.7']));

        self::assertCount(1, $this->sent);
        $o = $this->sent[0];
        self::assertSame('https://hooks.slack.com' . self::SECRET, $o[CURLOPT_URL]);
        self::assertSame(['hooks.slack.com:443:' . self::PUBLIC], $o[CURLOPT_RESOLVE]);
        self::assertSame('', $o[CURLOPT_PROXY]);
        self::assertSame('*', $o[CURLOPT_NOPROXY]);
        self::assertSame(CURLPROTO_HTTPS, $o[CURLOPT_PROTOCOLS]);
        self::assertFalse($o[CURLOPT_FOLLOWLOCATION]);
        self::assertTrue($o[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, SlackWebhook::TIMEOUT);
        self::assertSame(SlackWebhook::TIMEOUT, $o[CURLOPT_CONNECTTIMEOUT]);
        self::assertSame(SlackWebhook::TIMEOUT, $o[CURLOPT_TIMEOUT]);
        self::assertStringStartsWith('payload=', $o[CURLOPT_POSTFIELDS]);
        self::assertStringContainsString('Failed+Login+Attempt', $o[CURLOPT_POSTFIELDS]);
        self::assertContains('Content-Type: application/x-www-form-urlencoded', $o[CURLOPT_HTTPHEADER]);
        self::assertSame('', $this->logged(), 'a notice the webhook took logs nothing');
    }

    /** The budget only shortens the webhooks' own; theirs is unchanged. */
    public function testTheExportWebhooksKeepTheirTimeouts(): void
    {
        $target = (new WebhookGuard(static fn (): array => [self::PUBLIC], ''))->check('https://hooks.example.com/x');
        $o = WebhookSender::curlOptions($target, 'x', []);
        self::assertSame(WebhookSender::CONNECT_TIMEOUT, $o[CURLOPT_CONNECTTIMEOUT]);
        self::assertSame(WebhookSender::TIMEOUT, $o[CURLOPT_TIMEOUT]);
        $o = WebhookSender::curlOptions($target, 'x', [], 600);
        self::assertSame(WebhookSender::TIMEOUT, $o[CURLOPT_TIMEOUT], 'a budget never lengthens the sender\'s');
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function failedAnswers(): array
    {
        return [
            'no answer in time' => [
                ['status' => 0, 'error' => 'Operation timed out after 2001 milliseconds with 0 bytes received'],
                'no answer: Operation timed out after 2001 milliseconds',
            ],
            'refused by Slack' => [['status' => 404, 'body' => "no_service\n"], 'the webhook answered 404: no_service'],
            'a redirect' => [
                ['status' => 302, 'location' => 'https://elsewhere.example/'],
                'a redirect, which is not followed',
            ],
            'landed elsewhere' => [
                ['primary_ip' => '10.9.9.9'],
                'went to 10.9.9.9 instead of the checked address ' . self::PUBLIC,
            ],
            'an answer repeating the path' => [
                ['status' => 400, 'body' => 'bad hook https://hooks.slack.com' . self::SECRET],
                'the webhook answered 400: bad hook https://hooks.slack.com/…',
            ],
        ];
    }

    /**
     * @dataProvider failedAnswers
     * @param array<string, mixed> $answer
     */
    public function testANoticeTheWebhookDidNotTakeIsLogged(array $answer, string $why): void
    {
        self::assertFalse($this->slack('https://hooks.slack.com' . self::SECRET, $answer)->push('cb_key_verified'));

        self::assertCount(1, $this->sent);
        $log = $this->logged();
        self::assertStringContainsString('Slack: the "cb_key_verified" notice was not sent: ', $log);
        self::assertStringContainsString($why, $log);
        self::assertStringNotContainsString('s3cr3tPathXyz', $log);
    }

    public function testANoticeWithNoMessageIsLogged(): void
    {
        self::assertFalse($this->slack('https://hooks.slack.com' . self::SECRET)->push('no_such_notice'));
        self::assertSame([], $this->sent);
        self::assertStringContainsString(
            'Slack: the "no_such_notice" notice was not sent: no message is defined for it',
            $this->logged()
        );
    }

    /** Whatever the transport does, the action that sent the notice goes on. */
    public function testAFailingTransportDoesNotThrow(): void
    {
        $sender = new WebhookSender(
            new WebhookGuard(static fn (): array => [self::PUBLIC], ''),
            static function (): array {
                throw new \RuntimeException('the transport broke');
            }
        );
        $slack = new \Slack('https://hooks.slack.com' . self::SECRET, new SlackWebhook($sender));

        self::assertFalse($slack->push('cb_key_verified'));
        self::assertStringContainsString(
            'it could not be sent: RuntimeException: the transport broke',
            $this->logged()
        );
    }

    /**
     * The real transport against a listener that takes the connection and
     * never answers, for 8 seconds: the notice gives up after TIMEOUT
     * seconds (it held the request until the listener went away), and says
     * so in the log. The listener is a process of its own, so a sender with
     * no timeout fails here rather than hanging the suite.
     */
    public function testANoticeToAHostThatNeverAnswersGivesUpInTwoSeconds(): void
    {
        if (!function_exists('curl_init') || !function_exists('proc_open')) {
            self::markTestSkipped('needs curl and proc_open');
        }
        $listener = '$s = @stream_socket_server("tcp://127.0.0.1:0", $no, $str);'
            . 'if ($s === false) { echo "ERR\n"; exit(1); }'
            . '$n = (string) stream_socket_get_name($s, false); echo substr($n, strrpos($n, ":") + 1), "\n";'
            . 'sleep(8);';
        $process = proc_open([PHP_BINARY, '-r', $listener], [1 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            self::markTestSkipped('could not start the listener');
        }
        try {
            $port = trim((string) fgets($pipes[1]));
            if (preg_match('/^[0-9]+$/D', $port) !== 1) {
                self::markTestSkipped('the listener could not listen on 127.0.0.1');
            }
            $guard = new WebhookGuard(null, '127.0.0.1/32');
            $webhook = new SlackWebhook(new WebhookSender($guard));
            $slack = new \Slack('https://127.0.0.1:' . $port . self::SECRET, $webhook);

            $started = microtime(true);
            $sent = $slack->push('cb_key_verified');
            $took = microtime(true) - $started;
        } finally {
            proc_terminate($process);
            proc_close($process);
        }

        self::assertFalse($sent);
        $held = 'the notice held the request for ' . round($took, 1) . ' s';
        self::assertLessThan(SlackWebhook::TIMEOUT + 1.5, $took, $held);
        $log = $this->logged();
        self::assertStringContainsString('Slack: the "cb_key_verified" notice was not sent: no answer: ', $log);
        self::assertStringNotContainsString('s3cr3tPathXyz', $log);
    }
}
