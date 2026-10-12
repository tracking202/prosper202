<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Prosper202\Integration\SlackCommand;

final class SlackCommandTest extends TestCase
{
    // docs.slack.dev, "Verifying requests from Slack": the worked example.
    private const SECRET = '8f742231b10e8888abcd99yyyzzz85a5';
    private const TIMESTAMP = '1531420618';
    private const BODY = 'token=xyzz0WbapA4vBCDEFasx0q6G&team_id=T1DC2JH3J&team_domain=testteamnow&channel_id=G8PSS9T3V'
        . '&channel_name=foobar&user_id=U2CERLKJA&user_name=roadrunner&command=%2Fwebhook-collect&text='
        . '&response_url=https%3A%2F%2Fhooks.slack.com%2Fcommands%2FT1DC2JH3J%2F397700885554%2F96rGlfmibIGlgcZRskXaIFfN'
        . '&trigger_id=398738663015.47445629121.803a0bc887a14d10d2c447fce8b6703c';
    private const SIGNATURE = 'v0=a2114d57b48eac39b9ad189dd8316235a7b4a8d21a10bd27519666489c69b503';

    private static function verify(
        string $body = self::BODY,
        string $timestamp = self::TIMESTAMP,
        string $signature = self::SIGNATURE,
        string $secret = self::SECRET,
        int $now = 1531420618
    ): bool {
        return SlackCommand::verify($body, $timestamp, $signature, $secret, $now);
    }

    public function testSlacksWorkedExampleVerifies(): void
    {
        self::assertTrue(self::verify(now: 1531420618 + 299));
    }

    public function testAnythingElseDoesNot(): void
    {
        self::assertFalse(self::verify(body: self::BODY . 'x'), 'body');
        self::assertFalse(self::verify(timestamp: '1531420619'), 'timestamp');
        self::assertFalse(self::verify(secret: 'another'), 'secret');
        self::assertFalse(self::verify(secret: ''), 'no secret');
        self::assertFalse(self::verify(signature: strtoupper(self::SIGNATURE)), 'case');
        self::assertFalse(self::verify(now: 1531420618 + 301), 'a replay after five minutes');
        self::assertFalse(self::verify(now: 1531420618 - 301), 'a timestamp from the future');
        self::assertFalse(self::verify(timestamp: ''), 'no timestamp');
    }

    /** @dataProvider commands */
    public function testTheCommandText(string $text, string $action, ?string $period): void
    {
        self::assertSame([$action, $period], SlackCommand::parse($text));
    }

    /** @return iterable<string, array{string, string, string|null}> */
    public static function commands(): iterable
    {
        yield 'nothing' => ['', 'help', null];
        yield 'help' => [' Help ', 'help', null];
        yield 'stats' => ['stats', 'stats', 'today'];
        yield 'stats yesterday' => ['stats yesterday', 'stats', 'yesterday'];
        yield 'stats 7d' => ['STATS 7d', 'stats', 'last7'];
        yield 'stats 30d' => ['stats   30d', 'stats', 'last30'];
    }

    /** @dataProvider refused */
    public function testWhatItDoesNotUnderstandIsSaid(string $text, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        SlackCommand::parse($text);
    }

    /** @return iterable<string, array{string, string}> */
    public static function refused(): iterable
    {
        yield 'another command' => ['delete everything', 'Not a command'];
        yield 'another period' => ['stats 90d', 'Not a period: "90d"'];
        yield 'too many words' => ['stats today please', 'Not a command'];
    }

    public function testTheStatsLine(): void
    {
        $text = SlackCommand::stats('last7', [
            'total_clicks' => 12345,
            'total_leads' => 67,
            'total_income' => 1234.5,
            'total_cost' => 234.5,
            'total_net' => 1000.0,
            'roi' => 426.4392,
        ], 'USD');
        self::assertSame(
            "*Prosper202, last 7 days*\nClicks: 12,345 · Conversions: 67 · Revenue: 1,234.50 USD · Cost: 234.50 USD"
            . ' · Profit: 1,000.00 USD · ROI: 426.4%',
            $text
        );
        self::assertStringStartsWith('*Prosper202, today*', SlackCommand::stats('today', [], 'EUR'));
    }
}
