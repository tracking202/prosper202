<?php

declare(strict_types=1);

namespace Prosper202\Click;

use UAParser\Parser;

/**
 * Whether a click came from a bot: the one decision behind click_bot (and the
 * click_filtered it implies) wherever a click is recorded — dl.php, rtr.php,
 * static/record_simple.php and static/record_adv.php. A bot is still
 * redirected like anyone else; only what is recorded differs.
 *
 * Any one signal is enough: device type 4 from PLATFORMS::parseUserAgentInfo()
 * (the old per-file rule), a signature below, or ua-parser's "Spider" family.
 * The old rule alone missed nearly every bot: it looks for a family of "Bot",
 * which ua-parser never returns.
 */
final class ClickBotDetector
{
    /** The device_type parseUserAgentInfo() gives a bot. */
    public const DEVICE_TYPE_BOT = '4';

    /** ua-parser's device family for crawlers. */
    public const SPIDER_FAMILY = 'Spider';

    /**
     * Matched anywhere in the user agent, ignoring case. These are the agents
     * ua-parser does not reliably call "Spider" (HeadlessChrome and Applebot
     * parse as ordinary devices) plus the ones a report reader asks about by
     * name. To extend: add the token the agent always sends, and a real user
     * agent carrying it to tests/fixtures/click-bot/user-agents.json ("bots").
     */
    private const TOKENS = [
        // Search and ad crawlers
        'AdsBot-Google', 'Googlebot', 'Google-InspectionTool', 'Mediapartners-Google',
        'Storebot-Google', 'GoogleOther', 'APIs-Google', 'FeedFetcher-Google',
        'Google-Read-Aloud', 'Chrome-Lighthouse',
        'bingbot', 'BingPreview', 'adidxbot', 'Applebot', 'YandexBot', 'Baiduspider', 'DuckDuckBot',
        // Link previews
        'facebookexternalhit', 'facebookcatalog', 'meta-externalagent', 'Twitterbot', 'LinkedInBot',
        'Slackbot', 'Discordbot', 'TelegramBot', 'SkypeUriPreview', 'redditbot', 'Pinterestbot', 'Embedly',
        // Headless browsers and automation (Puppeteer and Playwright send
        // HeadlessChrome unless told otherwise; their own names when they are)
        'HeadlessChrome', 'PhantomJS', 'Puppeteer', 'Playwright',
        // Uptime monitors
        'UptimeRobot', 'Pingdom', 'StatusCake',
    ];

    /**
     * Matched only at the start of the user agent, ignoring case, where a
     * preview fetcher puts its own name: a WebView that merely mentions one
     * (an Android WebView ending in "WhatsApp/2.24") is not flagged.
     *
     * HTTP libraries (curl, Wget, python-requests, Go-http-client, okhttp,
     * Java and the like) are deliberately absent. A tracker legitimately
     * records clicks a server makes: server-to-server setups and pass-through
     * redirects send the click with their library's agent, and the bots seen
     * on real installs were crawlers, headless browsers and Google proxies,
     * never libraries.
     */
    private const PREFIXES = [
        'WhatsApp/',
    ];

    /**
     * HTTP libraries, matched at the start of the user agent, ignoring case. Not
     * bots on their own (see PREFIXES), even where ua-parser calls one "Spider"
     * (it does for Python-urllib and Java). Crawler frameworks such as Scrapy are
     * not in this list and stay flagged by the Spider rule.
     */
    private const LIBRARY_PREFIXES = [
        'curl/', 'Wget/', 'python-requests/', 'Python-urllib/', 'Python/', 'Go-http-client/',
        'okhttp/', 'Java/', 'Apache-HttpClient/', 'libwww-perl/', 'axios/', 'node-fetch/',
        'PostmanRuntime/', 'GuzzleHttp/',
    ];

    private static ?string $pattern = null;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $deviceInfo PLATFORMS::get_device_info()'s
     *                                         result for the same user agent
     */
    public static function isBot(string $userAgent, array $deviceInfo): bool
    {
        if ((string) ($deviceInfo['type'] ?? '') === self::DEVICE_TYPE_BOT) {
            return true;
        }
        if (self::matchesSignature($userAgent)) {
            return true;
        }
        if (self::isHttpLibrary($userAgent)) {
            return false;
        }

        // get_device_info() carries the family ua-parser gave, from the parse it
        // already did; parse here only for a caller that has none.
        $family = array_key_exists('ua_device_family', $deviceInfo)
            ? (string) $deviceInfo['ua_device_family']
            : self::deviceFamily($userAgent);

        return $family === self::SPIDER_FAMILY;
    }

    public static function matchesSignature(string $userAgent): bool
    {
        if ($userAgent === '') {
            return false;
        }
        if (self::$pattern === null) {
            $alternatives = array_merge(
                array_map(static fn(string $t): string => preg_quote($t, '/'), self::TOKENS),
                array_map(static fn(string $c): string => '^' . preg_quote($c, '/'), self::PREFIXES),
            );
            self::$pattern = '/(?:' . implode('|', $alternatives) . ')/i';
        }

        return preg_match(self::$pattern, $userAgent) === 1;
    }

    public static function isHttpLibrary(string $userAgent): bool
    {
        foreach (self::LIBRARY_PREFIXES as $prefix) {
            if (stripos($userAgent, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    /** ua-parser's device family, or '' when the parser cannot run. */
    private static function deviceFamily(string $userAgent): string
    {
        try {
            return (string) Parser::create()->parse($userAgent)->device->family;
        } catch (\Throwable) {
            return '';
        }
    }
}
