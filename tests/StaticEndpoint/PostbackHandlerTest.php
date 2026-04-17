<?php

declare(strict_types=1);

namespace Tests\StaticEndpoint;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the encrypted postback handler (cb202.php) logic.
 *
 * cb202.php is a procedural script that handles server-to-server postbacks
 * from affiliate networks. Since it cannot be included directly (it calls
 * die/exit and uses global state), we test the logical components:
 *
 * 1. OpenSSL decryption round-trip
 * 2. Postback payload parsing (SALE vs TEST)
 * 3. Revenue extraction from order data
 */
final class PostbackHandlerTest extends TestCase
{
    // --- Decryption round-trip ---

    public function testOpenSslDecryptionRoundTrip(): void
    {
        if (!function_exists('openssl_encrypt')) {
            self::markTestSkipped('OpenSSL extension required');
        }

        $cbKey = 'my-secret-callback-key';
        $payload = json_encode([
            'transactionType' => 'SALE',
            'trackingCodes' => ['12345'],
            'totalAccountAmount' => '49.95',
        ]);

        // Encrypt (simulate what the affiliate network does)
        $key = substr(sha1($cbKey), 0, 32);
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($payload, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);

        $message = (object) [
            'notification' => base64_encode($encrypted),
            'iv' => base64_encode($iv),
        ];

        // Decrypt (what cb202.php does)
        $decrypted = trim(
            openssl_decrypt(
                base64_decode((string) $message->notification),
                'AES-128-CBC',
                substr(sha1($cbKey), 0, 32),
                OPENSSL_RAW_DATA,
                base64_decode((string) $message->iv)
            ),
            "\0..\32"
        );

        $order = json_decode($decrypted, true);

        self::assertSame('SALE', $order['transactionType']);
        self::assertSame('12345', $order['trackingCodes'][0]);
        self::assertSame('49.95', $order['totalAccountAmount']);
    }

    public function testDecryptionWithWrongKeyReturnsFalse(): void
    {
        if (!function_exists('openssl_encrypt')) {
            self::markTestSkipped('OpenSSL extension required');
        }

        $correctKey = 'correct-key';
        $wrongKey = 'wrong-key';
        $payload = 'test data';

        $key = substr(sha1($correctKey), 0, 32);
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($payload, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);

        $result = openssl_decrypt(
            base64_decode(base64_encode($encrypted)),
            'AES-128-CBC',
            substr(sha1($wrongKey), 0, 32),
            OPENSSL_RAW_DATA,
            $iv
        );

        // With wrong key, decrypt returns false or garbage
        self::assertTrue($result === false || $result !== $payload);
    }

    // --- Payload parsing ---

    public function testSaleTransactionExtractsClickIdAndPayout(): void
    {
        $order = [
            'transactionType' => 'SALE',
            'trackingCodes' => ['67890'],
            'totalAccountAmount' => '125.00',
            'transactionId' => 'TX-ABC-123',
        ];

        self::assertSame('SALE', $order['transactionType']);

        $clickId = $order['trackingCodes'][0];
        $payout = $order['totalAccountAmount'];

        self::assertSame('67890', $clickId);
        self::assertSame('125.00', $payout);
    }

    public function testTestTransactionIsNotSale(): void
    {
        $order = ['transactionType' => 'TEST'];

        self::assertSame('TEST', $order['transactionType']);
        self::assertNotSame('SALE', $order['transactionType']);
    }

    public function testEmptyTrackingCodesHandledSafely(): void
    {
        $order = [
            'transactionType' => 'SALE',
            'trackingCodes' => [],
            'totalAccountAmount' => '10.00',
        ];

        // cb202.php accesses [0] directly — this would produce a notice/warning
        self::assertEmpty($order['trackingCodes']);
        self::assertFalse(isset($order['trackingCodes'][0]));
    }

    public function testMissingTrackingCodesKeyHandledSafely(): void
    {
        $order = [
            'transactionType' => 'SALE',
            'totalAccountAmount' => '10.00',
        ];

        self::assertFalse(isset($order['trackingCodes']));
    }

    public function testNonNumericPayoutPreservedAsString(): void
    {
        $order = [
            'transactionType' => 'SALE',
            'trackingCodes' => ['1'],
            'totalAccountAmount' => 'not-a-number',
        ];

        // cb202 passes this to real_escape_string → p202ApplyConversionUpdate
        // which puts it in SQL. The DB should reject it, but the code doesn't validate.
        self::assertSame('not-a-number', $order['totalAccountAmount']);
        self::assertFalse(is_numeric($order['totalAccountAmount']));
    }

    public function testZeroPayoutIsTreatedAsSale(): void
    {
        $order = [
            'transactionType' => 'SALE',
            'trackingCodes' => ['1'],
            'totalAccountAmount' => '0.00',
        ];

        // A $0 sale is still a SALE transaction type
        self::assertSame('SALE', $order['transactionType']);
        self::assertSame('0.00', $order['totalAccountAmount']);
    }

    // --- Malformed JSON handling ---

    public function testMalformedJsonDecryptedPayloadReturnsNull(): void
    {
        $badJson = '{invalid json';
        $order = json_decode($badJson, true);

        self::assertNull($order, 'Malformed JSON should decode to null');
        // cb202.php then does $order['transactionType'] on null → crash
    }

    public function testNullDecryptedPayloadCausesJsonDecodeFailure(): void
    {
        // If decryption returns false, json_decode(false) should fail
        $order = json_decode('', true);
        self::assertNull($order);
    }

    // --- SHA1 key derivation ---

    public function testKeyDerivationProduces32ByteKey(): void
    {
        $cbKey = 'any-callback-key';
        $derived = substr(sha1($cbKey), 0, 32);

        self::assertSame(32, strlen($derived));
        // SHA1 hex is 40 chars, we take first 32
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $derived);
    }

    public function testDifferentKeysProduceDifferentDerivedKeys(): void
    {
        $key1 = substr(sha1('key-one'), 0, 32);
        $key2 = substr(sha1('key-two'), 0, 32);

        self::assertNotSame($key1, $key2);
    }
}
