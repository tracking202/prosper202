<?php

declare(strict_types=1);

namespace Tests\Conversion\PostbackAuth;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\PostbackAuth\PostbackAuthMode;

/**
 * Home's warning while an account's postbacks and pixels need no token
 * (202-config/functions-postback-auth-ui.php), rendered and read back as a
 * DOM. PostbackAuthHomeNoticeIntegrationTest holds the reads behind it.
 */
final class PostbackAuthHomeNoticeTest extends TestCase
{
    private const BASE = 'https://t.example/';
    private const SINCE = 1_800_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3) . '/202-config/functions-postback-auth-ui.php';
    }

    /** @return array{requests: int, unread: int, without_credentials: int, since: int, covers_since: int} */
    private static function ready(int $requests, int $without, int $unread = 0, ?int $coversSince = null): array
    {
        return ['requests' => $requests, 'unread' => $unread, 'without_credentials' => $without,
            'since' => self::SINCE, 'covers_since' => $coversSince ?? self::SINCE];
    }

    public function testAStricterModeShowsNothingWhateverArrived(): void
    {
        foreach ([PostbackAuthMode::TOKEN, PostbackAuthMode::SIGNED] as $mode) {
            foreach ([self::ready(10, 3), self::ready(10, 0), self::ready(0, 0), null] as $ready) {
                self::assertSame('', p202_postback_auth_off_notice($mode, $ready, self::BASE), $mode->value);
            }
        }
    }

    public function testOffWithRequestsWithoutTheTokenSaysHowManyAndIsNotSafe(): void
    {
        $notice = $this->notice(self::ready(1250, 37));
        self::assertSame('no', $notice['state']);
        self::assertStringContainsString('accepted without your postback token', $notice['text']);
        self::assertStringContainsString(
            'Anyone who guesses a click id can add conversions, which are then sent to your ad platforms.',
            $notice['text']
        );
        self::assertStringContainsString(
            '37 of the 1,250 that arrived in the last 7 days came without it',
            $notice['text']
        );
        self::assertStringNotContainsString('safe to require', $notice['text']);
        self::assertSame(self::BASE . '202-account/api-integrations.php#postback-auth', $notice['href']);
        self::assertSame('See who sent them in Account › API integrations', $notice['link']);
    }

    public function testOffWithEveryRequestCarryingTheTokenIsSafeToRequire(): void
    {
        $notice = $this->notice(self::ready(12, 0));
        self::assertSame('yes', $notice['state']);
        self::assertStringContainsString(
            'All 12 that arrived in the last 7 days carried it or a signature, so it is safe to require.',
            $notice['text']
        );
        self::assertSame('Require the token in Account › API integrations', $notice['link']);

        self::assertStringContainsString('The one that arrived', $this->notice(self::ready(1, 0))['text']);
    }

    public function testSafeIsNotSaidOfWhatWasNotSeen(): void
    {
        $cases = [
            'nothing arrived' => [self::ready(0, 0), 'No postback or pixel has arrived in the last 7 days'],
            'some unread' => [self::ready(9, 0, 2), 'for 2 of 9 what they carried was not read'],
            'readiness unreadable' => [null, 'could not be counted just now'],
        ];
        foreach ($cases as $case => [$ready, $says]) {
            $notice = $this->notice($ready);
            self::assertSame('unknown', $notice['state'], $case);
            $warned = 'accepted without your postback token';
            self::assertStringContainsString($warned, $notice['text'], $case . ': still warned');
            self::assertStringContainsString($says, $notice['text'], $case);
            self::assertStringNotContainsString('safe to require', $notice['text'], $case);
            self::assertSame(self::BASE . '202-account/api-integrations.php#postback-auth', $notice['href'], $case);
        }
    }

    public function testAShorterWindowThanTheLogsIsNamed(): void
    {
        $covers = self::SINCE + 5 * 86400;
        $notice = $this->notice(self::ready(4, 1, 0, $covers));
        $since = '1 of the 4 that arrived since ' . date('M j, g:i a', $covers);
        self::assertStringContainsString($since, $notice['text']);
        self::assertStringNotContainsString('last 7 days', $notice['text']);
    }

    public function testTheBaseIsEscaped(): void
    {
        $base = 'https://t.example/"><script>/';
        $html = p202_postback_auth_off_notice(PostbackAuthMode::OFF, self::ready(1, 1), $base);
        self::assertStringNotContainsString('"><script>', $html);
    }

    public function testTheReaderShowsNothingWithoutTheRoleAConnectionOrAnAccount(): void
    {
        self::assertSame('', p202_postback_auth_home_notice(null, 5, true, self::BASE), 'no connection');
        $unconnected = mysqli_init();
        self::assertInstanceOf(\mysqli::class, $unconnected);
        // Neither of these reaches the connection, which would throw if asked.
        $notice = p202_postback_auth_home_notice($unconnected, 5, false, self::BASE);
        self::assertSame('', $notice, 'a role that may not change it');
        self::assertSame('', p202_postback_auth_home_notice($unconnected, 0, true, self::BASE), 'no account');
    }

    /**
     * @param array{requests: int, unread: int, without_credentials: int, since: int, covers_since: int}|null $ready
     * @return array{state: string, text: string, href: string, link: string}
     */
    private function notice(?array $ready): array
    {
        $html = p202_postback_auth_off_notice(PostbackAuthMode::OFF, $ready, self::BASE);
        self::assertNotSame('', $html);
        $dom = new \DOMDocument();
        self::assertTrue(@$dom->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>'));
        $xpath = new \DOMXPath($dom);
        $box = $xpath->query('//div[@id="postback-auth-off"]');
        self::assertNotFalse($box);
        self::assertSame(1, $box->length);
        $el = $box->item(0);
        self::assertInstanceOf(\DOMElement::class, $el);
        self::assertSame('alert alert-warning p202-flash', $el->getAttribute('class'), 'the kit\'s warning flash');
        $links = $xpath->query('.//div[@class="p202-flash__body"]/a', $el);
        self::assertNotFalse($links);
        self::assertSame(1, $links->length);
        $a = $links->item(0);
        self::assertInstanceOf(\DOMElement::class, $a);

        return [
            'state' => $el->getAttribute('data-postback-ready'),
            'text' => (string) preg_replace('/\s+/', ' ', $el->textContent),
            'href' => $a->getAttribute('href'),
            'link' => $a->textContent,
        ];
    }
}
