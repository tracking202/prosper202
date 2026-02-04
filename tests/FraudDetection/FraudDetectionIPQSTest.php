<?php
declare(strict_types=1);

namespace Tests\FraudDetection;

use PHPUnit\Framework\TestCase;
use FraudDetectionIPQS;

/**
 * Tests for FraudDetectionIPQS.class.php
 */
final class FraudDetectionIPQSTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Include the class
        require_once __DIR__ . '/../../202-config/FraudDetectionIPQS.class.php';
    }

    public function testConstructorSetsProperties(): void
    {
        $config = [
            'key' => 'test_api_key',
            'ip' => (object)['address' => '192.168.1.1'],
            'user_agent' => 'Mozilla/5.0',
            'language' => 'en-US',
        ];

        $detector = new FraudDetectionIPQS($config);

        // Test that object was created
        $this->assertInstanceOf(FraudDetectionIPQS::class, $detector);
    }

    public function testImplementsFraudDetectionInterface(): void
    {
        $config = [
            'key' => 'test_api_key',
            'ip' => (object)['address' => '192.168.1.1'],
            'user_agent' => 'Mozilla/5.0',
            'language' => 'en-US',
        ];

        $detector = new FraudDetectionIPQS($config);

        $this->assertInstanceOf(\FraudDetectionInterface::class, $detector);
    }

    public function testHasRequiredMethods(): void
    {
        $this->assertTrue(method_exists(FraudDetectionIPQS::class, 'verifyKey'));
        $this->assertTrue(method_exists(FraudDetectionIPQS::class, 'isFraud'));
        $this->assertTrue(method_exists(FraudDetectionIPQS::class, 'get_IPQ_URL'));
    }

    public function testFraudDetectionIPQSClassExists(): void
    {
        $this->assertTrue(class_exists('FraudDetectionIPQS'));
    }

    public function testFraudDetectionInterfaceExists(): void
    {
        $this->assertTrue(interface_exists('FraudDetectionInterface'));
    }
}

/**
 * Extended tests using a mock version of FraudDetectionIPQS
 */
final class FraudDetectionIPQSLogicTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../../202-config/FraudDetectionIPQS.class.php';
    }

    public function testVerifyKeyWithValidResponse(): void
    {
        $detector = new class(['key' => 'valid_key', 'ip' => (object)['address' => '127.0.0.1'], 'user_agent' => 'test', 'language' => 'en']) extends FraudDetectionIPQS {
            public function get_IPQ_URL($url)
            {
                return json_encode(['success' => true]);
            }
        };

        $result = $detector->verifyKey();

        $this->assertTrue($result);
    }

    public function testVerifyKeyWithInvalidKeyResponse(): void
    {
        $detector = new class(['key' => 'invalid_key', 'ip' => (object)['address' => '127.0.0.1'], 'user_agent' => 'test', 'language' => 'en']) extends FraudDetectionIPQS {
            public function get_IPQ_URL($url)
            {
                return json_encode(['success' => false, 'message' => 'Invalid API key']);
            }
        };

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid API key');

        $detector->verifyKey();
    }

    public function testVerifyKeyWithNullResponse(): void
    {
        $detector = new class(['key' => 'key', 'ip' => (object)['address' => '127.0.0.1'], 'user_agent' => 'test', 'language' => 'en']) extends FraudDetectionIPQS {
            public function get_IPQ_URL($url)
            {
                return null;
            }
        };

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid or unauthorized key');

        $detector->verifyKey();
    }

    public function testIsFraudReturnsTrueForHighScore(): void
    {
        $detector = new class(['key' => 'key', 'ip' => (object)['address' => '127.0.0.1'], 'user_agent' => 'test', 'language' => 'en']) extends FraudDetectionIPQS {
            public function get_IPQ_URL($url)
            {
                return json_encode(['fraud_score' => 85]);
            }
        };

        $result = $detector->isFraud((object)['address' => '1.2.3.4']);

        $this->assertTrue($result);
    }

    public function testIsFraudReturnsFalseForLowScore(): void
    {
        $detector = new class(['key' => 'key', 'ip' => (object)['address' => '127.0.0.1'], 'user_agent' => 'test', 'language' => 'en']) extends FraudDetectionIPQS {
            public function get_IPQ_URL($url)
            {
                return json_encode(['fraud_score' => 50]);
            }
        };

        $result = $detector->isFraud((object)['address' => '1.2.3.4']);

        $this->assertFalse($result);
    }

    public function testIsFraudReturnsFalseForScoreAt79(): void
    {
        $detector = new class(['key' => 'key', 'ip' => (object)['address' => '127.0.0.1'], 'user_agent' => 'test', 'language' => 'en']) extends FraudDetectionIPQS {
            public function get_IPQ_URL($url)
            {
                return json_encode(['fraud_score' => 79]);
            }
        };

        $result = $detector->isFraud((object)['address' => '1.2.3.4']);

        $this->assertFalse($result);
    }

    public function testIsFraudReturnsTrueForScoreAt80(): void
    {
        $detector = new class(['key' => 'key', 'ip' => (object)['address' => '127.0.0.1'], 'user_agent' => 'test', 'language' => 'en']) extends FraudDetectionIPQS {
            public function get_IPQ_URL($url)
            {
                return json_encode(['fraud_score' => 80]);
            }
        };

        $result = $detector->isFraud((object)['address' => '1.2.3.4']);

        $this->assertTrue($result);
    }

    public function testIsFraudThrowsExceptionForErrorResponse(): void
    {
        $detector = new class(['key' => 'key', 'ip' => (object)['address' => '127.0.0.1'], 'user_agent' => 'test', 'language' => 'en']) extends FraudDetectionIPQS {
            public function get_IPQ_URL($url)
            {
                return json_encode(['message' => 'Rate limit exceeded']);
            }
        };

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Rate limit exceeded');

        $detector->isFraud((object)['address' => '1.2.3.4']);
    }

    public function testIsFraudThrowsExceptionForNullResponse(): void
    {
        $detector = new class(['key' => 'key', 'ip' => (object)['address' => '127.0.0.1'], 'user_agent' => 'test', 'language' => 'en']) extends FraudDetectionIPQS {
            public function get_IPQ_URL($url)
            {
                return null;
            }
        };

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No Response');

        $detector->isFraud((object)['address' => '1.2.3.4']);
    }

    public function testIsFraudReturnsFalseForZeroScore(): void
    {
        $detector = new class(['key' => 'key', 'ip' => (object)['address' => '127.0.0.1'], 'user_agent' => 'test', 'language' => 'en']) extends FraudDetectionIPQS {
            public function get_IPQ_URL($url)
            {
                return json_encode(['fraud_score' => 0]);
            }
        };

        $result = $detector->isFraud((object)['address' => '1.2.3.4']);

        $this->assertFalse($result);
    }

    public function testIsFraudReturnsTrueForScoreAt100(): void
    {
        $detector = new class(['key' => 'key', 'ip' => (object)['address' => '127.0.0.1'], 'user_agent' => 'test', 'language' => 'en']) extends FraudDetectionIPQS {
            public function get_IPQ_URL($url)
            {
                return json_encode(['fraud_score' => 100]);
            }
        };

        $result = $detector->isFraud((object)['address' => '1.2.3.4']);

        $this->assertTrue($result);
    }
}
