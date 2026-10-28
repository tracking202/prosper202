<?php

declare(strict_types=1);

namespace Tests\Apps\Android;

use PHPUnit\Framework\TestCase;
use Prosper202\Notifications\NotificationOutbox;
use Prosper202\Notifications\PostbackSender;

/**
 * The outbox's URL resolution and retry schedule (plan §5.2 step 6, §5.5).
 */
final class NotificationOutboxTokensTest extends TestCase
{
    public function testKnownTokensAreFilledEncodedAndUnknownOnesLeftAlone(): void
    {
        $url = NotificationOutbox::replaceTokens(
            'https://ts.example/pb?s=[[SUBID]]&g=[[p202_goal]]&v=[[p202_goal_value]]&t=[[t202txid]]&x=[[transactionid]]&c1=[[c1]]&keep=[[not_ours]]',
            ['subid' => '42', 'p202_goal' => 'Level 3 & more', 'p202_goal_value' => '4.00000', 'transactionid' => 'a/b@c', 'c1' => null]
        );
        self::assertSame('https://ts.example/pb?s=42&g=Level%203%20%26%20more&v=4.00000&t=a%2Fb@c&x=a%2Fb@c&c1=&keep=[[not_ours]]', $url);
    }

    public function testTheBackoffDoublesFromAMinuteToSixHours(): void
    {
        self::assertSame([60, 120, 240, 480, 960, 1920, 3840, 7680, 15360, 21600, 21600], array_map(
            [NotificationOutbox::class, 'backoff'],
            range(1, 11)
        ));
    }

    public function testTheSenderRefusesWhatIsNotAWebUrlWithoutACall(): void
    {
        foreach (['', 'file:///etc/passwd', 'gopher://x', 'ftp://example.com/', 'javascript:alert(1)'] as $url) {
            self::assertFalse(PostbackSender::fetch($url), $url);
        }
        // "Without a call" is observed, not inferred from the answer (every
        // failed fetch answers false): a local listener must see no
        // connection for a non-web scheme aimed straight at it.
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($server, $errstr);
        $address = (string) stream_socket_get_name($server, false);
        try {
            foreach (['gopher://' . $address . '/x', 'dict://' . $address . '/x', 'telnet://' . $address] as $url) {
                self::assertFalse(PostbackSender::fetch($url), $url);
            }
            stream_set_blocking($server, false);
            self::assertFalse(@stream_socket_accept($server, 0), 'a non-web URL reached the network');
        } finally {
            fclose($server);
        }
        // The static endpoints' constant and the sender's are one value.
        $helpers = (string) file_get_contents(dirname(__DIR__, 3) . '/202-config/static-endpoint-helpers.php');
        self::assertStringContainsString('const P202_POSTBACK_USER_AGENT = \\Prosper202\\Conversion\\TrafficSourcePixels::POSTBACK_USER_AGENT;', $helpers);
        self::assertSame(PostbackSender::USER_AGENT, \Prosper202\Conversion\TrafficSourcePixels::POSTBACK_USER_AGENT);
    }
}
