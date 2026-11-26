<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookSender;
use Prosper202\Attribution\WebhookUnresolved;
use Prosper202\Http\DnsLookup;
use Prosper202\Http\DnsLookupFailed;
use Prosper202\Notifications\PostbackResult;
use Prosper202\Notifications\PostbackSender;
use Prosper202\Notifications\SlackWebhook;

/**
 * The outbound guard looks a host up inside the sender's own time (backlog
 * Y9Q3, D7NS). Each sender passes its deadline to WebhookGuard, the lookup
 * gets what is left of it (at most WebhookGuard::LOOKUP_SECONDS), and curl
 * gets what is left after the lookup, to the millisecond.
 *
 * The lookup ran outside every deadline: gethostbynamel() and
 * dns_get_record() take no timeout, so a nameserver that never answered
 * held a Slack notice (2 seconds), a postback (10) or a webhook (15) for
 * resolv.conf's timeout x attempts per nameserver and family, and only then
 * did the sender's own clock start.
 */
final class LookupWithinTheSendersTimeTest extends TestCase
{
    private const PUBLIC = '93.184.216.34';
    private const SECRET = '/services/T0000/B0000/s3cr3tPathXyz';
    private const PB_TIMED_OUT = 'pb.network.example was not looked up within 5 seconds: '
        . 'the resolver did not answer in time.';
    private const HOOK_TIMED_OUT = 'hooks.example.com was not looked up within 5 seconds: '
        . 'the resolver did not answer in time.';

    /** @var list<resource> */
    private array $sockets = [];
    private string $log = '';
    private string|false $savedLog = false;

    protected function setUp(): void
    {
        $this->log = (string) tempnam(sys_get_temp_dir(), 'p202-lookup-log-');
        $this->savedLog = ini_get('error_log');
        ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            fclose($socket);
        }
        ini_set('error_log', $this->savedLog === false ? '' : $this->savedLog);
        @unlink($this->log);
    }

    /**
     * A resolver that takes $takes seconds and answers PUBLIC, noting the
     * seconds each lookup was given.
     *
     * @param list<float> $given
     */
    private static function slowResolver(float $takes, array &$given): \Closure
    {
        return static function (string $host, float $seconds) use ($takes, &$given): array {
            $given[] = $seconds;
            usleep((int) ($takes * 1_000_000));

            return [self::PUBLIC];
        };
    }

    /** The real lookup, against a nameserver that never answers. */
    private function silentDns(): \Closure
    {
        $socket = stream_socket_server('udp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
        self::assertNotFalse($socket, $error);
        $this->sockets[] = $socket;
        $lookup = new DnsLookup([(string) stream_socket_get_name($socket, false)], [], 1, 30.0, 5, null);

        return static fn (string $host, float $seconds): array => $lookup->addresses($host, $seconds);
    }

    /** @return array<string, array{\Closure(WebhookGuard, ?float): mixed}> */
    public static function checks(): array
    {
        return [
            'an export or LTV webhook' => [
                static fn (WebhookGuard $g, ?float $d) => $g->check('https://hooks.example.com/x', [], $d),
            ],
            'a postback' => [
                static fn (WebhookGuard $g, ?float $d) => $g->checkPostback('http://hooks.example.com/pb', $d),
            ],
            'a landing page' => [
                static fn (WebhookGuard $g, ?float $d) => $g->checkPage('http://hooks.example.com/lp', $d),
            ],
            'a sync' => [
                static fn (WebhookGuard $g, ?float $d) => $g->checkSync('https://hooks.example.com/api/v3', [], $d),
            ],
        ];
    }

    /**
     * @dataProvider checks
     * @param \Closure(WebhookGuard, ?float): mixed $check
     */
    public function testTheLookupGetsWhatIsLeftOfTheDeadline(\Closure $check): void
    {
        $given = [];
        $guard = new WebhookGuard(static function (string $host, float $seconds) use (&$given): array {
            $given[] = $seconds;

            return [self::PUBLIC];
        }, '');

        $check($guard, microtime(true) + 1.5);
        $check($guard, microtime(true) + 60);
        $check($guard, null);

        self::assertCount(3, $given);
        self::assertGreaterThan(1.4, $given[0]);
        self::assertLessThanOrEqual(1.5, $given[0], 'never more than is left');
        self::assertSame(WebhookGuard::LOOKUP_SECONDS, $given[1], 'never more than LOOKUP_SECONDS');
        self::assertSame(WebhookGuard::LOOKUP_SECONDS, $given[2]);
    }

    /** @dataProvider checks */
    public function testALookupWithNoTimeLeftIsNotStartedAndIsTriedAgainLater(\Closure $check): void
    {
        $guard = new WebhookGuard(static fn (): array => self::fail('nothing is looked up after the deadline'), '');

        try {
            $check($guard, microtime(true) - 0.01);
            self::fail('a lapsed deadline let the check through');
        } catch (WebhookUnresolved $e) {
            self::assertStringStartsWith('hooks.example.com was not looked up: the ', $e->getMessage());
            self::assertStringEndsWith('\'s time had run out.', $e->getMessage());
        }
    }

    /** @dataProvider checks */
    public function testALookupThatRanOutIsUnresolvedNotRefused(\Closure $check): void
    {
        $failed = new DnsLookupFailed(
            'hooks.example.com was not looked up within 2 seconds: the resolver did not answer in time.'
        );
        $guard = new WebhookGuard(static fn (): never => throw $failed, '');

        try {
            $check($guard, microtime(true) + 2);
            self::fail('a lookup that ran out let the check through');
        } catch (WebhookUnresolved $e) {
            self::assertSame($failed->getMessage(), $e->getMessage());
            self::assertSame($failed, $e->getPrevious());
        }
    }

    /** A literal address is not looked up, so a lapsed deadline does not refuse it at the guard. */
    public function testAnAddressIsNotLookedUp(): void
    {
        $guard = new WebhookGuard(static fn (): array => self::fail('an address is not looked up'), '');
        $target = $guard->checkPostback('http://' . self::PUBLIC . '/pb', microtime(true) - 1);
        self::assertSame(self::PUBLIC, $target->host);
    }

    /**
     * A postback's lookups and its transfers share TIMEOUT: curl gets what
     * is left after each hop's lookup, to the millisecond, and a hop's
     * lookup gets what is left of the chain's. The first hop's answer takes
     * 5.2 of the 10 seconds, so the second hop's lookup has less than
     * LOOKUP_SECONDS.
     */
    public function testAPostbackGivesCurlWhatTheLookupsLeft(): void
    {
        $given = [];
        $sent = [];
        $answers = [
            [
                'status' => 302,
                'error' => null,
                'primary_ip' => self::PUBLIC,
                'redirect_url' => 'https://next.example.com/pb',
            ],
            ['status' => 200, 'error' => null, 'primary_ip' => self::PUBLIC, 'redirect_url' => null],
        ];
        $sender = new PostbackSender(
            new WebhookGuard(self::slowResolver(0.4, $given), ''),
            static function (array $options) use (&$sent, &$answers): array {
                $sent[] = $options;
                if (count($sent) === 1) {
                    usleep(5_200_000);
                }

                return array_shift($answers);
            }
        );

        self::assertTrue($sender->send('http://pb.network.example/pb')->isDelivered());

        self::assertCount(2, $given);
        self::assertSame(WebhookGuard::LOOKUP_SECONDS, $given[0]);
        self::assertLessThanOrEqual(4.4, $given[1], '10 - 0.4 - 5.2 left for the second hop\'s lookup');
        self::assertGreaterThan(4.0, $given[1]);
        self::assertCount(2, $sent);
        $first = $sent[0][CURLOPT_TIMEOUT_MS];
        $second = $sent[1][CURLOPT_TIMEOUT_MS];
        self::assertLessThanOrEqual(9600, $first, 'the first lookup took 0.4 s of the 10');
        self::assertGreaterThan(9200, $first);
        self::assertLessThanOrEqual(4000, $second, 'and the first hop 5.2 and the second lookup 0.4 more');
        self::assertGreaterThan(3600, $second);
        self::assertSame(
            10,
            $sent[0][CURLOPT_TIMEOUT],
            'whole seconds, rounded up: TIMEOUT_MS, set after it, is what curl keeps'
        );
        self::assertSame(4, $sent[1][CURLOPT_TIMEOUT]);
        self::assertSame(
            CURLOPT_TIMEOUT_MS,
            array_key_last($sent[0]),
            'set last, so nothing laid over the options brings the whole seconds back'
        );
    }

    /** A postback whose lookup ran out is kept to try again, not refused. */
    public function testAPostbackWhoseLookupRanOutIsTriedAgainLater(): void
    {
        $sender = new PostbackSender(
            new WebhookGuard(static fn (): never => throw new DnsLookupFailed(self::PB_TIMED_OUT), ''),
            static fn (): array => self::fail('nothing is sent')
        );

        $result = $sender->send('http://pb.network.example/pb');
        self::assertSame(PostbackResult::FAILED, $result->outcome);
        self::assertSame(self::PB_TIMED_OUT, $result->reason);
    }

    /** A webhook's lookup comes out of the timeout it is posted with: a Slack notice's 2 seconds. */
    public function testAWebhookGivesCurlWhatTheLookupLeft(): void
    {
        $given = [];
        $sent = [];
        $sender = new WebhookSender(
            new WebhookGuard(self::slowResolver(0.5, $given), ''),
            static function (array $options) use (&$sent): array {
                $sent[] = $options;

                return [
                    'status' => 200,
                    'error' => null,
                    'primary_ip' => self::PUBLIC,
                    'location' => null,
                    'body' => 'ok',
                ];
            }
        );

        $posted = $sender->post('https://hooks.slack.com' . self::SECRET, 'payload=x', [], [], SlackWebhook::TIMEOUT);

        self::assertNull($posted['refused']);
        self::assertCount(1, $given);
        self::assertGreaterThan(1.9, $given[0]);
        self::assertLessThanOrEqual(2.0, $given[0], 'the lookup is held to the 2 seconds');
        $o = $sent[0];
        self::assertLessThanOrEqual(1500, $o[CURLOPT_TIMEOUT_MS], 'the lookup took 0.5 s of them');
        self::assertGreaterThan(1300, $o[CURLOPT_TIMEOUT_MS]);
        self::assertSame(2, $o[CURLOPT_TIMEOUT]);
        self::assertSame(2, $o[CURLOPT_CONNECTTIMEOUT]);
        self::assertSame(CURLOPT_TIMEOUT_MS, array_key_last($o));

        $given = [];
        $sent = [];
        $sender->post('https://hooks.example.com/x', 'x', []);
        self::assertSame(WebhookGuard::LOOKUP_SECONDS, $given[0], 'TIMEOUT is 15: the lookup is capped at 5');
        self::assertLessThanOrEqual(14500, $sent[0][CURLOPT_TIMEOUT_MS]);
        self::assertGreaterThan(14000, $sent[0][CURLOPT_TIMEOUT_MS]);
    }

    /** A lookup that used every second leaves nothing to send in, and is tried again. */
    public function testAWebhookWhoseLookupTookAllItsTimeIsNotSent(): void
    {
        $given = [];
        $sender = new WebhookSender(
            new WebhookGuard(self::slowResolver(1.05, $given), ''),
            static fn (): array => self::fail('nothing is sent')
        );

        $posted = $sender->post('https://hooks.example.com/x', 'x', [], [], 1);

        self::assertSame(
            'looking up hooks.example.com took all of the 1 seconds the request may take',
            $posted['refused']
        );
        self::assertTrue($posted['retryable']);
    }

    /** One whose lookup ran out is tried again too. */
    public function testAWebhookWhoseLookupRanOutIsTriedAgain(): void
    {
        $sender = new WebhookSender(
            new WebhookGuard(static fn (): never => throw new DnsLookupFailed(self::HOOK_TIMED_OUT), ''),
            static fn (): array => self::fail('nothing is sent')
        );

        $posted = $sender->post('https://hooks.example.com/x', 'x', []);

        self::assertSame(self::HOOK_TIMED_OUT, $posted['refused']);
        self::assertTrue($posted['retryable']);
    }

    /**
     * The brief's case, end to end: a Slack notice to a host whose
     * nameserver never answers is given up on within the notice's 2
     * seconds, the lookup included, and the log says why.
     */
    public function testASlackNoticeWhoseNameserverNeverAnswersGivesUpInTwoSeconds(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/Slack.class.php';
        $transport = static fn (): array => self::fail('nothing is sent');
        $slack = new \Slack(
            'https://hooks.slack.com' . self::SECRET,
            new SlackWebhook(new WebhookSender(new WebhookGuard($this->silentDns(), ''), $transport))
        );

        $started = microtime(true);
        self::assertFalse($slack->push('cb_key_verified'));
        $took = microtime(true) - $started;

        self::assertGreaterThan(SlackWebhook::TIMEOUT - 0.2, $took, 'the lookup was given the notice\'s time');
        self::assertLessThan(SlackWebhook::TIMEOUT + 0.5, $took, 'and no more: it took ' . round($took, 2) . ' s');
        $log = (string) file_get_contents($this->log);
        self::assertStringContainsString(
            'Slack: the "cb_key_verified" notice was not sent: refused: hooks.slack.com was not looked up within ',
            $log
        );
        self::assertStringContainsString('the resolver did not answer in time.', $log);
        self::assertStringNotContainsString('s3cr3tPathXyz', $log);
    }

    /**
     * And a postback: the lookup has at most LOOKUP_SECONDS of the 10, and
     * the postback is kept to try again.
     */
    public function testAPostbackWhoseNameserverNeverAnswersFailsWithinItsTime(): void
    {
        $sender = new PostbackSender(
            new WebhookGuard($this->silentDns(), ''),
            static fn (): array => self::fail('nothing is sent')
        );

        $started = microtime(true);
        $result = $sender->send('http://pb.network.example/pb?key=s3cr3t');
        $took = microtime(true) - $started;

        self::assertSame(PostbackResult::FAILED, $result->outcome);
        self::assertSame(self::PB_TIMED_OUT, $result->reason);
        self::assertGreaterThan(WebhookGuard::LOOKUP_SECONDS - 0.2, $took);
        self::assertLessThan(WebhookGuard::LOOKUP_SECONDS + 0.5, $took, 'it took ' . round($took, 2) . ' s');
    }
}
