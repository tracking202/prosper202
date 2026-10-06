<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickBotDetector;
use UAParser\Parser;

/**
 * The bot verdict every click entry point records (ClickBotEntryPointsTest
 * pins that they all ask this class). Real browsers, mobile and in-app
 * included, must never be flagged: a false positive moves a paying visitor
 * out of the attribution report's clicks and the "Real clicks" view.
 *
 * The agents are real ones, in tests/fixtures/click-bot/user-agents.json.
 */
final class ClickBotDetectorTest extends TestCase
{
    /** What get_device_info() says for an ordinary desktop agent. */
    private const ORDINARY_DEVICE = ['type' => '1', 'ua_device_family' => 'Other'];

    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';

    private const SEMRUSH = 'Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)';

    /** @return array<string, array{string}> one real agent per signature */
    public static function botUserAgents(): array
    {
        return self::fixture('bots');
    }

    /** @return array<string, array{string}> */
    public static function humanUserAgents(): array
    {
        return self::fixture('humans');
    }

    /** @return array<string, array{string}> HTTP libraries */
    public static function serverUserAgents(): array
    {
        return self::fixture('servers');
    }

    /** @dataProvider botUserAgents */
    public function testASignatureAloneFlagsTheClick(string $userAgent): void
    {
        self::assertTrue(ClickBotDetector::matchesSignature($userAgent), $userAgent);
        self::assertTrue(ClickBotDetector::isBot($userAgent, self::ORDINARY_DEVICE), $userAgent);
    }

    /** @dataProvider humanUserAgents */
    public function testARealBrowserIsNotABot(string $userAgent): void
    {
        $family = (string) Parser::create()->parse($userAgent)->device->family;
        self::assertNotSame(ClickBotDetector::SPIDER_FAMILY, $family, 'ua-parser itself calls this browser a spider');
        self::assertFalse(ClickBotDetector::matchesSignature($userAgent), $userAgent);
        $device = ['type' => '2', 'ua_device_family' => $family];
        self::assertFalse(ClickBotDetector::isBot($userAgent, $device), $userAgent);
        // A caller without the family: the detector's own parse agrees.
        self::assertFalse(ClickBotDetector::isBot($userAgent, ['type' => '2']), $userAgent);
    }

    /**
     * Server-to-server and pass-through setups record real clicks with their
     * HTTP library's agent; the agent evals record theirs with curl.
     *
     * @dataProvider serverUserAgents
     */
    public function testAnHttpLibraryIsNotABot(string $userAgent): void
    {
        $family = (string) Parser::create()->parse($userAgent)->device->family;
        self::assertFalse(ClickBotDetector::matchesSignature($userAgent), $userAgent);
        $device = ['type' => '1', 'ua_device_family' => $family];
        self::assertFalse(ClickBotDetector::isBot($userAgent, $device), $userAgent);
        self::assertFalse(ClickBotDetector::isBot($userAgent, ['type' => '1']), $userAgent);
    }

    public function testACrawlerFrameworkStaysFlaggedThroughTheSpiderFamily(): void
    {
        $ua = 'Scrapy/2.11.0 (+https://scrapy.org)';
        $family = (string) Parser::create()->parse($ua)->device->family;
        self::assertSame(ClickBotDetector::SPIDER_FAMILY, $family);
        self::assertTrue(ClickBotDetector::isBot($ua, ['type' => '1', 'ua_device_family' => $family]));
    }

    public function testCurlAndGoClicksAreRecordedAsHuman(): void
    {
        foreach (['curl/8.4.0', 'Go-http-client/1.1'] as $userAgent) {
            self::assertFalse(ClickBotDetector::isBot($userAgent, self::ORDINARY_DEVICE), $userAgent);
            self::assertFalse(ClickBotDetector::isBot($userAgent, ['type' => '1']), $userAgent);
        }
    }

    public function testDeviceTypeFourIsABotWhateverTheAgent(): void
    {
        self::assertTrue(ClickBotDetector::isBot(self::CHROME, ['type' => '4', 'ua_device_family' => 'Other']));
        // get_device_info() hands the type back as a DB string or a PHP int.
        self::assertTrue(ClickBotDetector::isBot(self::CHROME, ['type' => 4, 'ua_device_family' => 'Other']));
        self::assertFalse(ClickBotDetector::isBot(self::CHROME, ['type' => '1', 'ua_device_family' => 'Other']));
        self::assertFalse(ClickBotDetector::isBot(self::CHROME, ['type' => '3', 'ua_device_family' => 'Other']));
    }

    public function testTheSpiderFamilyFlagsAnAgentNoSignatureNames(): void
    {
        self::assertFalse(
            ClickBotDetector::matchesSignature(self::SEMRUSH),
            'precondition: only the family can flag it'
        );

        self::assertTrue(ClickBotDetector::isBot(self::SEMRUSH, ['type' => '1', 'ua_device_family' => 'Spider']));
        self::assertFalse(
            ClickBotDetector::isBot(self::SEMRUSH, ['type' => '1', 'ua_device_family' => 'Other']),
            'the family decides, not the agent'
        );
        // No family supplied: the detector asks ua-parser itself.
        self::assertTrue(ClickBotDetector::isBot(self::SEMRUSH, ['type' => '1']));
    }

    /**
     * Why the old rule missed: parseUserAgentInfo() waited for a family of
     * "Bot", and ua-parser calls crawlers "Spider".
     */
    public function testUaParserCallsCrawlersSpiderNotBot(): void
    {
        $parser = Parser::create();
        foreach (self::fixture('bots') as $name => [$ua]) {
            if (in_array($name, ['Googlebot', 'AdsBot-Google'], true)) {
                self::assertSame('Spider', (string) $parser->parse($ua)->device->family, $ua);
            }
        }
    }

    public function testAnEmptyAgentIsNotFlaggedByASignature(): void
    {
        self::assertFalse(ClickBotDetector::matchesSignature(''));
    }

    /** Every signature in the class has an agent in the fixture that carries it. */
    public function testEverySignatureIsExercised(): void
    {
        $class = new \ReflectionClass(ClickBotDetector::class);
        $tokens = $class->getConstant('TOKENS');
        $prefixes = $class->getConstant('PREFIXES');
        self::assertIsArray($tokens);
        self::assertIsArray($prefixes);
        self::assertNotEmpty($tokens);
        self::assertNotEmpty($prefixes);

        $agents = array_map(static fn(array $row): string => $row[0], self::fixture('bots'));
        foreach ($tokens as $token) {
            $hits = array_filter($agents, static fn(string $ua): bool => stripos($ua, $token) !== false);
            self::assertNotEmpty($hits, "no test agent carries the signature '$token'");
        }
        foreach ($prefixes as $prefix) {
            $hits = array_filter($agents, static fn(string $ua): bool => stripos($ua, $prefix) === 0);
            self::assertNotEmpty($hits, "no test agent starts with the prefix '$prefix'");
        }
    }

    /** @return array<string, array{string}> */
    private static function fixture(string $group): array
    {
        $path = dirname(__DIR__) . '/fixtures/click-bot/user-agents.json';
        $doc = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($doc) || !is_array($doc[$group] ?? null) || $doc[$group] === []) {
            throw new \RuntimeException("$path has no '$group' agents");
        }

        $rows = [];
        foreach ($doc[$group] as $name => $ua) {
            $rows[(string) $name] = [(string) $ua];
        }

        return $rows;
    }
}
