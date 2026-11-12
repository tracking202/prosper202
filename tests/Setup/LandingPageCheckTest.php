<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Setup\LandingPageCheck;
use Prosper202\Setup\LandingPageCheckResult as Result;
use Prosper202\Setup\LandingPageCode;

/**
 * Check my page (Setup › Get LP Code): what it finds in a page's HTML, and
 * how it fetches the page — through the postback guard, pinned, redirects
 * checked hop by hop — with the resolver and the transfer stubbed.
 */
final class LandingPageCheckTest extends TestCase
{
    /** Documentation addresses, which the guard takes only because checker() allows their networks. Never connected to. */
    private const PUBLIC = '203.0.113.34';
    private const ALLOW = '203.0.113.0/24,2001:db8::/32';
    private const ID = '4242';

    /** @var list<array<int, mixed>> the options of each transfer made */
    private array $sent = [];

    /** The paid 1.9.56 snippet, as its Get LP Code handed it out. */
    private static function legacySnippet(string $id): string
    {
        return '<script src="//track.example.com/tracking202/static/landing.js?lpip=' . $id . '&defpixel=1" id="t202js" async></script>';
    }

    private static function page(string $body): string
    {
        return "<!DOCTYPE html><html><head><title>Spring sale</title></head><body><h1>Spring sale</h1>\n" . $body . "\n</body></html>";
    }

    /**
     * @param array<string, list<string>> $dns
     * @param list<array<string, mixed>> $answers one per transfer, in order
     */
    private function checker(array $dns, array $answers): LandingPageCheck
    {
        $guard = new WebhookGuard(static fn (string $host): array => $dns[$host] ?? [], self::ALLOW);
        $transport = function (array $options) use (&$answers): array {
            $this->sent[] = $options;
            $next = array_shift($answers);
            self::assertIsArray($next, 'no more transfers were expected');

            return $next + ['status' => 200, 'error' => null, 'timed_out' => false, 'primary_ip' => self::pinnedIn($options),
                'redirect_url' => null, 'body' => '', 'truncated' => false];
        };

        return new LandingPageCheck($guard, $transport);
    }

    /** The address a transfer was pinned to, as curl would report it connected to. */
    private static function pinnedIn(array $options): ?string
    {
        $entry = $options[CURLOPT_RESOLVE][0] ?? null;

        return is_string($entry) ? trim(substr($entry, strpos($entry, ':', strpos($entry, ':') + 1) + 1), '[]') : null;
    }

    public function testTheCurrentCodeForThisPageIsFound(): void
    {
        $html = self::page(LandingPageCode::loader('//track.example.com/', self::ID));
        $result = $this->checker(['shop.example.com' => [self::PUBLIC]], [['body' => $html]])
            ->check('https://shop.example.com/spring', self::ID);

        self::assertSame(Result::PRESENT, $result->status, $result->message);
        self::assertSame('ok', $result->flashKind());
        self::assertStringContainsString('https://shop.example.com', $result->message);
    }

    public function testThePaidSnippetForThisPageIsFoundAsLegacy(): void
    {
        $result = LandingPageCheck::inspect(self::page(self::legacySnippet(self::ID)), self::ID);

        self::assertSame(Result::LEGACY, $result->status, $result->message);
        self::assertSame('ok', $result->flashKind(), 'landing.js still loads in this version');
        self::assertStringContainsString('older landing.js code', $result->message);
    }

    public function testBothVersionsForThisPageReadAsPresent(): void
    {
        $html = self::page(self::legacySnippet(self::ID) . LandingPageCode::loader('//track.example.com/', self::ID));

        self::assertSame(Result::PRESENT, LandingPageCheck::inspect($html, self::ID)->status);
    }

    public function testAnotherLandingPagesCodeIsTheWrongId(): void
    {
        $result = LandingPageCheck::inspect(self::page(LandingPageCode::loader('//track.example.com/', '77')), self::ID);

        self::assertSame(Result::WRONG_ID, $result->status);
        self::assertSame(['77'], $result->otherIds);
        self::assertSame('bad', $result->flashKind());
        self::assertStringContainsString('lpip=77', $result->message);
        self::assertStringContainsString('not this one (lpip=' . self::ID . ')', $result->message);

        $legacy = LandingPageCheck::inspect(self::page(self::legacySnippet('77')), self::ID);
        self::assertSame(Result::WRONG_ID, $legacy->status, 'an old snippet with another id is the wrong id too');
    }

    public function testThisIdLoadedFromAnotherTrackerIsNotFound(): void
    {
        $html = self::page(LandingPageCode::loader('//staging-track.example.com/', self::ID));
        $result = LandingPageCheck::inspect($html, self::ID, false, 'https://shop.example.com', 'track.example.com');

        self::assertSame(Result::OTHER_TRACKER, $result->status, $result->message);
        self::assertSame('warn', $result->flashKind());
        self::assertStringContainsString('loads from staging-track.example.com', $result->message);
        self::assertStringContainsString('(track.example.com)', $result->message);

        $ours = self::page(LandingPageCode::loader('//Track.Example.com/p202/', self::ID));
        self::assertSame(Result::PRESENT, LandingPageCheck::inspect($ours, self::ID, false, '', 'track.example.com')->status,
            'the host is compared without case, the install path aside');
        $escaped = '<script>var u = "https:\\/\\/track.example.com\\/tracking202\\/static\\/landing.php?lpip=' . self::ID . '";</script>';
        self::assertSame(Result::PRESENT, LandingPageCheck::inspect($escaped, self::ID, false, '', 'track.example.com')->status,
            'a JSON-escaped address names its host too');
        $both = self::page(LandingPageCode::loader('//staging-track.example.com/', self::ID) . LandingPageCode::loader('//track.example.com/', self::ID));
        self::assertSame(Result::PRESENT, LandingPageCheck::inspect($both, self::ID, false, '', 'track.example.com')->status);
        self::assertSame(Result::PRESENT, LandingPageCheck::inspect($html, self::ID)->status, 'no tracking domain known: the host is not judged');
    }

    public function testCommentOpenersInsideAScriptDoNotHideTheCode(): void
    {
        $html = '<script>var marker = "<!--";</script><p>Sale</p>' . LandingPageCode::loader('//t.example/', self::ID);

        self::assertSame(Result::PRESENT, LandingPageCheck::inspect($html, self::ID)->status);
    }

    public function testCodeWithNoIdIsNamedAsSuch(): void
    {
        $result = LandingPageCheck::inspect('<script src="//t.example/tracking202/static/landing.js?lpip=" async></script>', self::ID);

        self::assertSame(Result::WRONG_ID, $result->status);
        self::assertSame([''], $result->otherIds);
        self::assertStringContainsString('(an lpip= with no id)', $result->message);
    }

    public function testAnIdThatOnlyStartsLikeThisOneIsAnotherId(): void
    {
        $result = LandingPageCheck::inspect(self::page(LandingPageCode::loader('//t.example/', self::ID . '1')), self::ID);

        self::assertSame(Result::WRONG_ID, $result->status);
    }

    public function testThisPagesCodeBesideAnotherIsMixed(): void
    {
        $html = self::page(LandingPageCode::loader('//t.example/', '77') . LandingPageCode::loader('//t.example/', self::ID));
        $result = LandingPageCheck::inspect($html, self::ID);

        self::assertSame(Result::MIXED, $result->status);
        self::assertSame('warn', $result->flashKind());
        self::assertSame(['77'], $result->otherIds);
    }

    /**
     * A check answered from 202_landing_page_checks has its message alone:
     * the other ids read back from it are the ones the check found, for every
     * status, an id-less snippet, several ids, and a URL that names an lpip.
     */
    public function testTheOtherIdsAreReadBackFromAStoredMessage(): void
    {
        $code = static fn (string $id): string => LandingPageCode::loader('//t.example/', $id);
        $noId = '<script src="//t.example/tracking202/static/landing.js?lpip=" async></script>';
        $pages = [
            'mixed' => self::page($code('77') . $code(self::ID) . $code('9001')),
            'mixed, no id' => self::page($code(self::ID) . $noId),
            'wrong id' => self::page($code('77') . $code('78')),
            'wrong id, no id' => $noId,
            'present' => self::page($code(self::ID)),
            'absent' => self::page('<p>Sale</p>'),
        ];
        $wheres = ['', 'https://shop.example.com/?lpip=5) and also code for lpip=6. Only one of them',
            'https://shop.example.com/sale'];
        foreach ($pages as $name => $html) {
            foreach ($wheres as $where) {
                $result = LandingPageCheck::inspect($html, self::ID, false, $where);
                self::assertSame(
                    $result->otherIds,
                    LandingPageCheck::otherIdsNamedIn($result->status, $result->message),
                    "$name at '$where': " . $result->message
                );
            }
        }
        $found = LandingPageCheck::inspect($pages['mixed'], self::ID)->otherIds;
        self::assertSame(['77', '9001'], $found, 'sanity: the ids are found');
    }

    public function testCodeOnlyInsideACommentDoesNotCount(): void
    {
        $result = LandingPageCheck::inspect(self::page('<!-- ' . LandingPageCode::loader('//t.example/', self::ID) . ' -->'), self::ID);

        self::assertSame(Result::COMMENTED, $result->status);
        self::assertSame('bad', $result->flashKind());

        $unclosed = LandingPageCheck::inspect('<p>x</p><!-- ' . self::legacySnippet(self::ID), self::ID);
        self::assertSame(Result::COMMENTED, $unclosed->status, 'a comment never closed runs to the end of the page');

        $after = LandingPageCheck::inspect('<!-- old --><p>x</p>' . LandingPageCode::loader('//t.example/', self::ID), self::ID);
        self::assertSame(Result::PRESENT, $after->status, 'code after a closed comment counts');
    }

    public function testAPageWithoutTheCodeIsAbsent(): void
    {
        $result = LandingPageCheck::inspect(self::page('<p>No tracking here. landing.php is just a word.</p>'), self::ID);

        self::assertSame(Result::ABSENT, $result->status);
        self::assertStringContainsString('tag manager', $result->message);

        $cut = LandingPageCheck::inspect(str_repeat('x', 100), self::ID, true);
        self::assertSame(Result::ABSENT, $cut->status);
        self::assertStringContainsString('first 2 MB', $cut->message);
    }

    public function testPlaceholdersAreDroppedAndTheConnectionIsPinned(): void
    {
        $this->checker(['shop.example.com' => [self::PUBLIC]], [['body' => '']])
            ->check('https://shop.example.com/spring?kw=[[t202kw]]&c1=[[c1]]', self::ID);

        self::assertCount(1, $this->sent);
        $options = $this->sent[0];
        self::assertSame('https://shop.example.com/spring?kw=&c1=', $options[CURLOPT_URL]);
        self::assertSame(['shop.example.com:443:' . self::PUBLIC], $options[CURLOPT_RESOLVE]);
        self::assertFalse($options[CURLOPT_FOLLOWLOCATION], 'redirects are followed by the check, never by curl');
        self::assertSame(0, $options[CURLOPT_MAXREDIRS]);
        self::assertSame('', $options[CURLOPT_PROXY]);
        self::assertSame('*', $options[CURLOPT_NOPROXY]);
        self::assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
        self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        self::assertLessThanOrEqual(LandingPageCheck::TIMEOUT, $options[CURLOPT_TIMEOUT]);
        self::assertSame(LandingPageCheck::USER_AGENT, $options[CURLOPT_USERAGENT]);
    }

    public function testARedirectIsCheckedAndFollowed(): void
    {
        $html = self::page(LandingPageCode::loader('//t.example/', self::ID));
        $result = $this->checker(
            ['shop.example.com' => [self::PUBLIC], 'www.shop.example.com' => ['2001:db8:220:1::248:1893']],
            [['status' => 301, 'redirect_url' => 'https://www.shop.example.com/spring'], ['body' => $html]]
        )->check('http://shop.example.com/spring', self::ID);

        self::assertSame(Result::PRESENT, $result->status, $result->message);
        self::assertCount(2, $this->sent);
        self::assertSame(['shop.example.com:80:' . self::PUBLIC], $this->sent[0][CURLOPT_RESOLVE]);
        self::assertSame(['www.shop.example.com:443:[2001:db8:220:1::248:1893]'], $this->sent[1][CURLOPT_RESOLVE]);
        self::assertStringContainsString('https://www.shop.example.com', $result->message, 'the page named is the one read');
    }

    public function testARedirectToAnInternalAddressIsNotFollowed(): void
    {
        $result = $this->checker(
            ['shop.example.com' => [self::PUBLIC], 'admin.shop.example.com' => ['10.0.0.5']],
            [['status' => 302, 'redirect_url' => 'http://admin.shop.example.com/']]
        )->check('https://shop.example.com/', self::ID);

        self::assertSame(Result::ERROR, $result->status);
        self::assertCount(1, $this->sent, 'the internal address is never connected to');
        self::assertStringContainsString('redirected to http://admin.shop.example.com, which was not followed', $result->message);
        self::assertStringContainsString('10.0.0.5, which is a private address', $result->message);
        self::assertStringContainsString('Landing pages are only fetched from public addresses.', $result->message);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function refusedUrls(): iterable
    {
        yield 'loopback' => ['http://127.0.0.1/lp', '127.0.0.1, which is a loopback address. Landing pages are only fetched from public addresses.'];
        yield 'metadata' => ['http://169.254.169.254/latest/meta-data/', 'link-local address'];
        yield 'local name' => ['http://localhost/lp', 'localhost is a single-label name'];
        yield 'name to a private address' => ['https://lp.internal-dns.example.com/', '192.168.1.20, which is a private address'];
        yield 'not http' => ['ftp://shop.example.com/lp', 'The landing page URL must start with http:// or https://.'];
        yield 'credentials' => ['https://user:pw@shop.example.com/', 'user name or password'];
        yield 'number spelling' => ['http://2130706433/', 'not an IPv4 address in dotted form'];
    }

    /** @dataProvider refusedUrls */
    public function testAddressesThatAreNotPublicAreNeverFetched(string $url, string $why): void
    {
        $result = $this->checker(['shop.example.com' => [self::PUBLIC], 'lp.internal-dns.example.com' => ['192.168.1.20']], [])
            ->check($url, self::ID);

        self::assertSame(Result::ERROR, $result->status);
        self::assertSame([], $this->sent, 'nothing is fetched');
        self::assertStringStartsWith('Your page was not fetched: ', $result->message);
        self::assertStringContainsString($why, $result->message);
        self::assertStringNotContainsString('ostback', $result->message, 'the sentence is about the landing page');
    }

    public function testFetchFailuresAreNamed(): void
    {
        $dns = ['shop.example.com' => [self::PUBLIC]];
        $run = fn (array $answer): Result => $this->checker($dns, [$answer])->check('https://shop.example.com/', self::ID);

        self::assertStringContainsString('answered HTTP 404', $run(['status' => 404])->message);
        self::assertStringContainsString('answered HTTP 503', $run(['status' => 503])->message);
        self::assertStringContainsString('did not answer within 10 seconds',
            $run(['status' => 0, 'timed_out' => true, 'error' => 'Operation timed out'])->message);
        self::assertStringContainsString('could not be fetched: SSL certificate problem: certificate has expired.',
            $run(['status' => 0, 'error' => 'SSL certificate problem: certificate has expired'])->message);
        self::assertStringContainsString('without saying where to go', $run(['status' => 302])->message);
        self::assertStringContainsString('instead of the checked address',
            $run(['primary_ip' => '10.0.0.9', 'body' => LandingPageCode::loader('//t.example/', self::ID)])->message,
            'a connection that went elsewhere is not read');
        $unconfirmed = $run(['primary_ip' => null, 'body' => LandingPageCode::loader('//t.example/', self::ID)]);
        self::assertSame(Result::ERROR, $unconfirmed->status, 'nor one whose address curl did not report');
        self::assertStringContainsString('an address curl did not report', $unconfirmed->message);

        $unresolved = $this->checker([], [])->check('https://gone.example.com/', self::ID);
        self::assertSame(Result::ERROR, $unresolved->status);
        self::assertStringContainsString('gone.example.com does not resolve to an address from this server.', $unresolved->message);
    }

    public function testRedirectsStopAfterTheLimit(): void
    {
        $answers = array_fill(0, LandingPageCheck::MAX_REDIRECTS + 1, ['status' => 302, 'redirect_url' => 'https://shop.example.com/again']);
        $result = $this->checker(['shop.example.com' => [self::PUBLIC]], $answers)->check('https://shop.example.com/', self::ID);

        self::assertSame(Result::ERROR, $result->status);
        self::assertCount(LandingPageCheck::MAX_REDIRECTS + 1, $this->sent);
        self::assertStringContainsString('redirected more than ' . LandingPageCheck::MAX_REDIRECTS . ' times', $result->message);
    }

    public function testNothingIsFetchedWithoutAnIdOrAUrl(): void
    {
        $checker = $this->checker(['shop.example.com' => [self::PUBLIC]], []);

        self::assertStringContainsString('no public id', $checker->check('https://shop.example.com/', '')->message);
        self::assertStringContainsString('no URL to check', $checker->check('[[c1]]', self::ID)->message);
        self::assertSame([], $this->sent);
    }

    public function testTheOperatorsAllowlistAdmitsTheirOwnNetwork(): void
    {
        $guard = new WebhookGuard(static fn (): array => ['10.20.0.7'], '10.20.0.0/16');
        $sent = [];
        $result = (new LandingPageCheck($guard, static function (array $options) use (&$sent): array {
            $sent[] = $options;

            return ['status' => 200, 'error' => null, 'timed_out' => false, 'primary_ip' => '10.20.0.7', 'redirect_url' => null,
                'body' => LandingPageCode::loader('//t.example/', self::ID), 'truncated' => false];
        }))->check('https://lp.corp.example.com/', self::ID);

        self::assertSame(Result::PRESENT, $result->status, $result->message);
        self::assertSame(['lp.corp.example.com:443:10.20.0.7'], $sent[0][CURLOPT_RESOLVE]);
    }

    /**
     * A listener on 127.0.0.1 that answers one request: 'big' sends 4 MB,
     * anything else is sent as the page. Returns its port, or skips.
     *
     * @return array{0: resource, 1: resource, 2: string, 3: string}
     */
    private function listen(string $mode): array
    {
        $listener = <<<'PHP'
<?php
[, $mode] = $argv;
$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) { echo "ERR $errstr\n"; exit(1); }
$name = (string) stream_socket_get_name($server, false);
echo substr($name, strrpos($name, ':') + 1), "\n";
$conn = @stream_socket_accept($server, 20);
if ($conn === false) { exit(1); }
$request = '';
while (!str_contains($request, "\r\n\r\n") && !feof($conn)) { $request .= (string) fread($conn, 4096); }
if ($mode === 'big') {
    fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nContent-Length: 4194304\r\nConnection: close\r\n\r\n");
    for ($i = 0; $i < 1024 && @fwrite($conn, str_repeat('x', 4096)) !== false; $i++) {}
} else {
    fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nContent-Length: " . strlen($mode) . "\r\nConnection: close\r\n\r\n" . $mode);
}
fclose($conn);
PHP;
        $script = tempnam(sys_get_temp_dir(), 'p202lp');
        self::assertIsString($script);
        file_put_contents($script, $listener);
        $proc = proc_open([PHP_BINARY, $script, $mode], [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($proc);
        $port = trim((string) fgets($pipes[1]));
        $this->listeners[] = [$proc, $pipes[1], $script];
        if (!ctype_digit($port)) {
            self::markTestSkipped('no local listener: ' . $port);
        }

        return [$proc, $pipes[1], $script, $port];
    }

    /** @var list<array{0: resource, 1: resource, 2: string}> */
    private array $listeners = [];

    protected function tearDown(): void
    {
        foreach ($this->listeners as [$proc, $pipe, $script]) {
            fclose($pipe);
            proc_terminate($proc);
            proc_close($proc);
            unlink($script);
        }
        $this->listeners = [];
    }

    /**
     * The real transfer against a listener on this machine: a page larger
     * than MAX_BYTES is cut there and reported as cut, not as a failure.
     */
    public function testTheTransportCutsOffALargePage(): void
    {
        $port = $this->listen('big')[3];
        $answer = LandingPageCheck::curlTransport([
            CURLOPT_URL => 'http://127.0.0.1:' . $port . '/',
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ]);

        self::assertNull($answer['error']);
        self::assertSame(200, $answer['status']);
        self::assertTrue($answer['truncated']);
        self::assertSame(LandingPageCheck::MAX_BYTES, strlen($answer['body']));
    }

    /**
     * The whole check with the real transport: a host name the guard
     * resolves (to loopback, which only the operator's allowlist admits) is
     * pinned through PostbackSender's options, curl reports the address it
     * reached, and the page it read carries the code.
     */
    public function testTheRealFetchIsPinnedAndReadsThePage(): void
    {
        $port = $this->listen('<html><body>' . LandingPageCode::loader('//track.example.com/', self::ID) . '</body></html>')[3];
        $guard = new WebhookGuard(static fn (string $host): array => $host === 'lp.shop.example.com' ? ['127.0.0.1'] : [], '127.0.0.0/8');
        $result = (new LandingPageCheck($guard))->check('http://lp.shop.example.com:' . $port . '/spring', self::ID, 'track.example.com');

        self::assertSame(Result::PRESENT, $result->status, $result->message);
        self::assertStringContainsString('http://lp.shop.example.com:' . $port, $result->message);
    }
}
