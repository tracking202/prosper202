<?php

declare(strict_types=1);

namespace Tests\Rotator;

use PHPUnit\Framework\TestCase;
use Prosper202\Rotator\FraudScore;

/**
 * The "High fraud score" rule's IPQualityScore check. Nothing called
 * isFraud() after 1.9.56, and the URL it built carried the visitor's user
 * agent unencoded, which libcurl refuses ("URL rejected: Bad query").
 */
final class FraudScoreTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/FraudDetectionIPQS.class.php';
    }

    public function testNoKeyIsNotFraudAndAsksNobody(): void
    {
        $asked = false;
        $detector = static function () use (&$asked): \FraudDetectionInterface {
            $asked = true;
            throw new \LogicException('not to be asked');
        };
        self::assertFalse(FraudScore::isHigh('', '203.0.113.9', [], $detector));
        self::assertFalse(FraudScore::isHigh('  ', '203.0.113.9', [], $detector));
        self::assertFalse($asked);
    }

    public function testAScoreOf80OrMoreIsFraud(): void
    {
        foreach ([79 => false, 80 => true, 99 => true] as $score => $fraud) {
            $detector = static fn (array $config): \FraudDetectionInterface => new class ($config, $score) extends \FraudDetectionIPQS {
                public function __construct(array $config, private int $score)
                {
                    parent::__construct($config);
                }

                public function get_IPQ_URL($url)
                {
                    return json_encode(['fraud_score' => $this->score]);
                }
            };
            self::assertSame($fraud, FraudScore::isHigh('k', '203.0.113.9', [], $detector), "score $score");
        }
    }

    public function testNoAnswerIsNotFraud(): void
    {
        $detector = static fn (array $config): \FraudDetectionInterface => new class ($config) extends \FraudDetectionIPQS {
            public function get_IPQ_URL($url)
            {
                return false;
            }
        };
        $log = ini_set('error_log', '/dev/null');
        try {
            self::assertFalse(FraudScore::isHigh('k', '203.0.113.9', [], $detector));
        } finally {
            ini_set('error_log', (string) $log);
        }
    }

    public function testTheRequestUrlIsEncoded(): void
    {
        $urls = [];
        $detector = static function (array $config) use (&$urls): \FraudDetectionInterface {
            return new class ($config, $urls) extends \FraudDetectionIPQS {
                /** @param list<string> $urls */
                public function __construct(array $config, private array &$urls)
                {
                    parent::__construct($config);
                }

                public function get_IPQ_URL($url)
                {
                    $this->urls[] = $url;
                    return json_encode(['fraud_score' => 0]);
                }
            };
        };
        FraudScore::isHigh('key/with space', '2001:db8::5', [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) Chrome/120.0',
            'HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9',
        ], $detector);
        self::assertCount(1, $urls);
        self::assertStringStartsWith('https://www.ipqualityscore.com/api/json/ip/key%2Fwith%20space/2001%3Adb8%3A%3A5?', $urls[0]);
        self::assertStringNotContainsString(' ', $urls[0]);
        parse_str((string) parse_url($urls[0], PHP_URL_QUERY), $query);
        self::assertSame('Mozilla/5.0 (X11; Linux x86_64) Chrome/120.0', $query['user_agent']);
        self::assertSame('de-DE,de;q=0.9', $query['user_language']);
        self::assertSame('0', $query['strictness']);
    }
}
