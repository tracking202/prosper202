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
    private const PUBLIC = '93.184.216.34';
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
        $guard = new WebhookGuard(static fn (string $host): array => $dns[$host] ?? [], '');
        $transport = function (array $options) use (&$answers): array {
            $this->sent[] = $options;
            $next = array_shift($answers);
            self::assertIsArray($next, 'no more transfers were expected');

            return $next + ['status' => 200, 'error' => null, 'timed_out' => false, 'primary_ip' => null,
                'redirect_url' => null, 'body' => '', 'truncated' => false];
        };

        return new LandingPageCheck($guard, $transport);
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
            ['shop.example.com' => [self::PUBLIC], 'www.shop.example.com' => ['2606:2800:220:1::248:1893']],
            [['status' => 301, 'redirect_url' => 'https://www.shop.example.com/spring'], ['body' => $html]]
        )->check('http://shop.example.com/spring', self::ID);

        self::assertSame(Result::PRESENT, $result->status, $result->message);
        self::assertCount(2, $this->sent);
        self::assertSame(['shop.example.com:80:' . self::PUBLIC], $this->sent[0][CURLOPT_RESOLVE]);
        self::assertSame(['www.shop.example.com:443:[2606:2800:220:1::248:1893]'], $this->sent[1][CURLOPT_RESOLVE]);
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
     * The real transfer against a listener on this machine: a page larger
     * than MAX_BYTES is cut there and reported as cut, not as a failure.
     */
    public function testTheTransportCutsOffALargePage(): void
    {
        $listener = <<<'PHP'
<?php
$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) { echo "ERR $errstr\n"; exit(1); }
$name = (string) stream_socket_get_name($server, false);
echo substr($name, strrpos($name, ':') + 1), "\n";
$conn = @stream_socket_accept($server, 20);
if ($conn === false) { exit(1); }
$request = '';
while (!str_contains($request, "\r\n\r\n") && !feof($conn)) { $request .= (string) fread($conn, 4096); }
fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nContent-Length: 4194304\r\nConnection: close\r\n\r\n");
for ($i = 0; $i < 1024 && @fwrite($conn, str_repeat('x', 4096)) !== false; $i++) {}
fclose($conn);
PHP;
        $script = tempnam(sys_get_temp_dir(), 'p202lp');
        self::assertIsString($script);
        file_put_contents($script, $listener);
        $proc = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($proc);
        try {
            $port = trim((string) fgets($pipes[1]));
            if (!ctype_digit($port)) {
                self::markTestSkipped('no local listener: ' . $port);
            }
            $answer = LandingPageCheck::curlTransport([
                CURLOPT_URL => 'http://127.0.0.1:' . $port . '/',
                CURLOPT_PROXY => '',
                CURLOPT_NOPROXY => '*',
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
            ]);
        } finally {
            fclose($pipes[1]);
            proc_terminate($proc);
            proc_close($proc);
            unlink($script);
        }

        self::assertNull($answer['error']);
        self::assertSame(200, $answer['status']);
        self::assertTrue($answer['truncated']);
        self::assertSame(LandingPageCheck::MAX_BYTES, strlen($answer['body']));
    }
}
