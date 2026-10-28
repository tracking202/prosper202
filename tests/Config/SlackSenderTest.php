<?php

declare(strict_types=1);

namespace Tests\Config;

use PHPUnit\Framework\TestCase;

/**
 * Who a Slack notice says it is from.
 *
 * The notice's username was $_SERVER['SERVER_NAME'], read raw. The cron job
 * sends one (a scheduled click deletion finishing) and runs from the PHP
 * CLI, where there is no request and no SERVER_NAME: every such notice
 * printed "Undefined array key" into the cron's output. A run with no
 * request now names the install by its tracking domain, and a neutral name
 * when none is set.
 */
final class SlackSenderTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/Slack.class.php';
    }

    /** @return array<string, array{0: array<string, mixed>, 1: ?string, 2: string}> */
    public static function senders(): array
    {
        // A provider runs before setUpBeforeClass().
        require_once dirname(__DIR__, 2) . '/202-config/Slack.class.php';

        return [
            'the host the request named' => [['SERVER_NAME' => 'track.example.com'], null, 'track.example.com'],
            'the request before the tracking domain' => [['SERVER_NAME' => 'a.example'], 'b.example', 'a.example'],
            'no request: the tracking domain' => [[], 'track.example.com', 'track.example.com'],
            'no request: a domain stored as a URL is its host' => [[], 'https://track.example.com/tracking202/', 'track.example.com'],
            'no request: a domain with its port' => [[], 'track.example.com:8443', 'track.example.com:8443'],
            'no request, no tracking domain' => [[], '', \Slack::NEUTRAL_SENDER],
            'no request, a domain that could not be read' => [[], null, \Slack::NEUTRAL_SENDER],
            'no request, a domain that names no host' => [[], 'not a host!', \Slack::NEUTRAL_SENDER],
            'an empty SERVER_NAME' => [['SERVER_NAME' => '  '], 'track.example.com', 'track.example.com'],
            'a SERVER_NAME that is not a string' => [['SERVER_NAME' => ['x']], 'track.example.com', 'track.example.com'],
        ];
    }

    /**
     * @dataProvider senders
     * @param array<string, mixed> $server
     */
    public function testTheSenderName(array $server, ?string $trackingDomain, string $expected): void
    {
        self::assertSame($expected, \Slack::senderName($server, $trackingDomain));
    }

    /**
     * A notice sent the way the cron job sends one from the PHP CLI: no
     * SERVER_NAME, warnings turned into errors. The webhook is a closed
     * local port, so the send fails at once; what is under test is that
     * building and sending it raised nothing, and what the body names.
     */
    public function testANoticeSentWithoutARequestRaisesNoWarning(): void
    {
        if (!function_exists('curl_init')) {
            self::markTestSkipped('the curl extension is not loaded');
        }
        $saved = $_SERVER;
        unset($_SERVER['SERVER_NAME']);
        set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $level, $file, $line);
        });
        try {
            $slack = new \Slack('http://127.0.0.1:9/');
            $sent = $slack->push('click_data_deleted', ['user' => 'cron', 'date' => '2026-09-08']);
            $payload = method_exists($slack, 'payload') ? $slack->payload('a notice') : null;
        } finally {
            restore_error_handler();
            $_SERVER = $saved;
        }

        self::assertFalse($sent, 'nothing listens on the closed port, so the send itself fails, quietly');
        self::assertIsString($payload);
        self::assertStringStartsWith('payload=', $payload);
        $body = json_decode(substr($payload, strlen('payload=')), true, 512, JSON_THROW_ON_ERROR);
        // With no install configuration loaded (no DB class) there is no
        // tracking domain to read: the neutral name.
        if (!class_exists('DB', false)) {
            self::assertSame(\Slack::NEUTRAL_SENDER, $body['username']);
        } else {
            self::assertIsString($body['username']);
            self::assertNotSame('', $body['username']);
        }
        self::assertSame('a+notice', $body['text']);
    }
}
