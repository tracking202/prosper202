<?php

declare(strict_types=1);

namespace Tests\PaymentNotification;

use PHPUnit\Framework\TestCase;
use Prosper202\PaymentNotification\ClickBank\ClickBankAdapter;
use Prosper202\PaymentNotification\InboundRequest;
use Prosper202\PaymentNotification\Notification;
use Prosper202\PaymentNotification\NotificationKind;
use Prosper202\PaymentNotification\OutcomeKind;
use Prosper202\PaymentNotification\Refusal;

/**
 * How ClickBankAdapter decrypts a notification and reads it. The database
 * half (whose key it is, what it records) is ClickBankInsIntegrationTest's.
 * Notifications are encrypted here as ClickBank's INS article describes,
 * not through the code under test.
 */
final class ClickBankAdapterTest extends TestCase
{
    private const KEY_A = 'ACCOUNTTHREE0001';
    private const KEY_OTHER = 'ACCOUNTSEVEN0001';

    /**
     * The openssl command line's encryption of an INS 8.0 sale under secret
     * VECTORKEY0000001 and IV 00..0f (AES-256-CBC, key the first 32 hex digits
     * of the secret's SHA-1); Python's cryptography, keyed as ClickBank's
     * Python sample keys it, decrypts it to the same order.
     */
    private const VECTOR_SECRET = 'VECTORKEY0000001';
    private const VECTOR = '{"notification":"HnK6oiVyQ0IWChabcRdWmJE5CTLmgiMLOQbrrkuJrRqPMNvIMg1f+9iAIBelJwRf'
        . 'T6hQu8splOMb6pfphEeNN'
        . 'jP5+7gZDEzpnseAMRG0ROmsrXHXwyXX4HuqHldcp72bDXusTIBDG2IxQzk6pjZHVlVcMj8L5vZ2AdoPKIjOr1FrIfW2Mx2oGpspzBEripF'
        . 'Wx6K2RqzC98EJiiokdL8aqLkLQPSCGnnPdHXMYX0unB0P49olS8+G1XYNJQvyHlqkXqM+BdNxPEA1Wb0FaHgIWbSYpZ/neWA/aFDS2tgqr4'
        . 'a0lMMLg6JHF9o0qnupdCJ7zBFMo0VOBe9RJsXbgsi5+4fhEsqcQL2+Tx9DnsoDXs8=","iv":"AAECAwQFBgcICQoLDA0ODw=="}';

    private const AMOUNT_RULE = 'an amount is an exact decimal such as 47 or 47.00, not negative,'
        . ' at most 999999.99999.';

    /**
     * An order as ClickBank sends it: encrypted under the secret, the IV
     * beside it, both base64, in a JSON body.
     */
    public static function encrypted(string $plain, string $secret, string $cipher = 'AES-256-CBC'): string
    {
        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt($plain, $cipher, substr(sha1($secret), 0, 32), OPENSSL_RAW_DATA, $iv);
        self::assertIsString($ciphertext);

        return (string) json_encode(['notification' => base64_encode($ciphertext), 'iv' => base64_encode($iv)]);
    }

    /**
     * @param array<string, mixed> $order
     */
    public static function body(array $order, string $secret): string
    {
        return self::encrypted((string) json_encode($order), $secret);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function sale(array $overrides = []): array
    {
        return $overrides + [
            'transactionTime' => '2026-10-23T13:47:51-06:00', 'receipt' => 'CWOGBZLN', 'transactionType' => 'SALE',
            'vendor' => 'testacct', 'affiliate' => 'affiliate1', 'role' => 'AFFILIATE', 'totalAccountAmount' => '37.62',
            'paymentMethod' => 'VISA', 'totalOrderAmount' => '47.00', 'currency' => 'EUR', 'trackingCodes' => ['42'],
            'customer' => ['billing' => ['fullName' => 'Test Guy', 'email' => 'buyer@example.com']],
            'version' => '8', 'attemptCount' => 1,
        ];
    }

    /** @param array<array-key, mixed> $fields */
    private static function request(string $body, array $fields = []): InboundRequest
    {
        $peer = '203.0.113.9';

        return new InboundRequest('POST', 'application/json', $fields, $peer, $peer, 'ClickBank', $body);
    }

    /**
     * @param array<int, list<string>> $secrets
     * @return array<int, int>|null
     */
    private static function verified(string $body, array $secrets): ?array
    {
        return (new ClickBankAdapter())->verify(self::request($body), $secrets)?->secretByAccount;
    }

    /** @param array<string, mixed> $order */
    private static function parsed(array $order): Notification
    {
        $adapter = new ClickBankAdapter();
        $request = self::request(self::body($order, self::KEY_A));
        self::assertNotNull($adapter->verify($request, [3 => [self::KEY_A]]));

        return $adapter->parse($request);
    }

    private static function refusal(callable $call): Refusal
    {
        try {
            $call();
        } catch (Refusal $refusal) {
            return $refusal;
        }
        self::fail('expected a refusal');
    }

    /** @param array<string, mixed> $order */
    private static function refused(array $order, OutcomeKind $kind = OutcomeKind::MALFORMED): string
    {
        $refusal = self::refusal(static fn () => self::parsed($order));
        self::assertSame($kind, $refusal->outcome->kind, $refusal->getMessage());

        return $refusal->getMessage();
    }

    /** @param array<string, mixed> $order */
    private static function assertNoClick(array $order): void
    {
        self::assertSame(
            'Verified. The ' . $order['transactionType'] . ' of receipt ' . $order['receipt'] . ' carries no tracking'
            . ' code (the HopLink had no tid=[[subid]]), so it names no click; nothing was recorded.',
            self::refused($order, OutcomeKind::NO_CLICK)
        );
    }

    public function testANotificationEncryptedByTheOpensslCommandLineDecryptsAndReads(): void
    {
        $adapter = new ClickBankAdapter();
        $request = self::request(self::VECTOR);

        self::assertSame([3 => 0], $adapter->verify($request, [3 => [self::VECTOR_SECRET]])?->secretByAccount);
        $n = $adapter->parse($request);
        self::assertSame(
            [NotificationKind::SALE, 'SALE', 'KNOWNANS', null, '37.62', 'USD', 4242, null],
            [$n->kind, $n->event, $n->transactionId, $n->originalTransactionId, $n->amount, $n->currency, $n->clickId,
                $n->occurredAt]
        );
    }

    /** cb202.php's cipher until 1.9.79: it reads 16 of the key's 32 bytes, so no INS notification decrypted. */
    public function testTheAes128CipherCb202UsedDecryptsNothingClickBankSends(): void
    {
        $plain = (string) json_encode(self::sale());
        self::assertNull(self::verified(self::encrypted($plain, self::KEY_A, 'AES-128-CBC'), [3 => [self::KEY_A]]));
        self::assertSame([3 => 0], self::verified(self::encrypted($plain, self::KEY_A), [3 => [self::KEY_A]]));
        self::assertNull(self::verified(self::VECTOR, [3 => [substr(sha1(self::VECTOR_SECRET), 0, 16)]]));
    }

    public function testTheAccountIsTheOneWhoseKeyDecryptsIt(): void
    {
        $secrets = [3 => [self::KEY_A], 7 => [self::KEY_OTHER], 9 => ['ACCOUNTNINE00001']];

        self::assertSame([3 => 0], self::verified(self::body(self::sale(), self::KEY_A), $secrets));
        self::assertSame([7 => 0], self::verified(self::body(self::sale(), self::KEY_OTHER), $secrets));
        self::assertNull(self::verified(self::body(self::sale(), 'NOBODYSTORESTHIS'), $secrets));
        self::assertNull(self::verified(self::body(self::sale(), self::KEY_A), []));
    }

    /**
     * A ciphertext the right key decrypts to something other than an order
     * answers as one no key decrypts: the answer must not say which padding
     * was good, or it is an oracle that lets a stranger build a notification.
     */
    public function testWhatIsNotAnOrderReadsAsAWrongKey(): void
    {
        $secrets = [3 => [self::KEY_A]];
        foreach (['not json', '[1,2]', '{}', '{"receipt":"X"}', '{"transactionType":7}', '"SALE"'] as $plain) {
            self::assertNull(self::verified(self::encrypted($plain, self::KEY_A), $secrets), $plain);
        }
        // Trailing control bytes are trimmed, as ClickBank's samples trim them.
        $padded = (string) json_encode(self::sale()) . "\0\0\x05";
        self::assertSame([3 => 0], self::verified(self::encrypted($padded, self::KEY_A), $secrets));
    }

    public function testAKeyTwoAccountsStoreIsRefusedNamingThemInTheLogOnly(): void
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'p202-cb-log');
        $previous = (string) ini_get('error_log');
        ini_set('error_log', $log);
        try {
            $refusal = self::refusal(static fn () => self::verified(
                self::body(self::sale(), self::KEY_A),
                [7 => [self::KEY_A], 3 => [self::KEY_A], 9 => [self::KEY_OTHER]]
            ));
            $logged = (string) file_get_contents($log);
        } finally {
            ini_set('error_log', $previous);
            @unlink($log);
        }

        self::assertSame(OutcomeKind::AMBIGUOUS_ACCOUNT, $refusal->outcome->kind);
        self::assertDoesNotMatchRegularExpression('/\b[37]\b/', $refusal->getMessage());
        self::assertStringContainsString('ClickBank notification: accounts 3, 7 store the secret key', $logged);
        self::assertStringNotContainsString(self::KEY_A, $logged);
    }

    public function testTheEnvelopeIsReadBeforeAnyKey(): void
    {
        $legacy = self::refusal(static fn () => (new ClickBankAdapter())->verify(
            self::request('ctransaction=SALE&cverify=1A2B3C4D', ['ctransaction' => 'SALE', 'cverify' => '1A2B3C4D']),
            [3 => [self::KEY_A]]
        ));
        self::assertSame(OutcomeKind::UNSUPPORTED_BODY, $legacy->outcome->kind);
        self::assertStringContainsString('set the Instant Notification version to 8.0', $legacy->getMessage());

        $iv = base64_encode(random_bytes(16));
        $cases = [
            '' => 'not ClickBank\'s',
            'not json' => 'not ClickBank\'s',
            '{"notification":"QUJD"}' => 'not ClickBank\'s',
            '{"notification":["QUJD"],"iv":"' . $iv . '"}' => 'not ClickBank\'s',
            '{"notification":"*not base64*","iv":"' . $iv . '"}' => 'notification is not',
            '{"notification":"","iv":"' . $iv . '"}' => 'notification is not',
            '{"notification":"QUJD","iv":"' . $iv . '"}' => 'notification is not',
            '{"notification":"' . base64_encode(random_bytes(32)) . '","iv":"QUJD"}' => 'iv is not',
        ];
        foreach ($cases as $body => $says) {
            foreach ([[], [3 => [self::KEY_A]]] as $secrets) {
                $refusal = self::refusal(static fn () => self::verified((string) $body, $secrets));
                self::assertSame(OutcomeKind::MALFORMED, $refusal->outcome->kind, (string) $body);
                self::assertStringContainsString($says, $refusal->getMessage(), (string) $body);
            }
        }
    }

    public function testASaleIsReadExactly(): void
    {
        $n = self::parsed(self::sale());
        self::assertSame(
            [NotificationKind::SALE, 'SALE', 'CWOGBZLN', '37.62', 'USD', 42],
            [$n->kind, $n->event, $n->transactionId, $n->amount, $n->currency, $n->clickId]
        );

        // 8.0 sends amounts as strings, 7.0 as numbers.
        $amounts = ['47' => '47', '47.00' => '47.00', '0' => '0'];
        foreach ($amounts as $sent => $read) {
            self::assertSame($read, self::parsed(self::sale(['totalAccountAmount' => (string) $sent]))->amount);
        }
        $numbers = [[47, '47'], [49.95, '49.95'], [0.0, '0'], [100.0, '100'], [999999.99999, '999999.99999']];
        foreach ($numbers as [$sent, $read]) {
            self::assertSame($read, self::parsed(self::sale(['totalAccountAmount' => $sent]))->amount);
        }
    }

    public function testAnAmountThatIsNotExactlyOneIsRefused(): void
    {
        $refused = ['-1', '-1.00', '1,000.00', '1e3', ' 47', '47 ', '1234567', '1.123456', 'lots', ''];
        foreach ($refused as $amount) {
            self::assertStringEndsWith(
                self::AMOUNT_RULE,
                self::refused(self::sale(['totalAccountAmount' => $amount])),
                $amount
            );
        }
        foreach ([-1, -0.5, 1.0E+25, 0.1 + 0.2, 1234567.0, true, ['47']] as $amount) {
            self::assertStringEndsWith(self::AMOUNT_RULE, self::refused(self::sale(['totalAccountAmount' => $amount])));
        }
        $order = self::sale();
        unset($order['totalAccountAmount']);
        self::assertSame('The SALE carries no totalAccountAmount.', self::refused($order));
        self::assertSame(
            'The SALE carries no totalAccountAmount.',
            self::refused(self::sale(['totalAccountAmount' => null]))
        );
    }

    public function testTheReceiptIsRequiredAndReadAsSent(): void
    {
        $order = self::sale();
        unset($order['receipt']);
        self::assertSame('The SALE carries no receipt.', self::refused($order));
        self::assertSame('The SALE carries no receipt.', self::refused(self::sale(['receipt' => ''])));
        self::assertSame('The SALE carries no receipt.', self::refused(self::sale(['receipt' => 12345678])));
        foreach (['CWOG BZLN', "CWOGBZLN\n", str_repeat('A', 256), 'CWOGBZL' . "\u{00e9}"] as $receipt) {
            self::assertStringStartsWith(
                'receipt is not a ClickBank receipt',
                self::refused(self::sale(['receipt' => $receipt]))
            );
        }
        self::assertSame('CWOGBZLN-0001', self::parsed(self::sale(['receipt' => 'CWOGBZLN-0001']))->transactionId);
    }

    public function testTheClickIsTheFirstTrackingCode(): void
    {
        $none = self::sale();
        unset($none['trackingCodes']);
        self::assertNoClick($none);
        self::assertNoClick(self::sale(['trackingCodes' => []]));
        self::assertNoClick(self::sale(['trackingCodes' => ['']]));
        self::assertSame(42, self::parsed(self::sale(['trackingCodes' => ['42', 'other']]))->clickId);
        self::assertSame(42, self::parsed(self::sale(['trackingCodes' => [42]]))->clickId);

        foreach (['42.9', ' 42', '042', 'abc', '0', '-42'] as $code) {
            self::assertStringStartsWith(
                'The first tracking code is "' . $code . '", which is not a click id.',
                self::refused(self::sale(['trackingCodes' => [$code]]))
            );
        }
        self::assertStringStartsWith(
            'trackingCodes is not a list',
            self::refused(self::sale(['trackingCodes' => '42']))
        );
        self::assertNoClick(self::sale(['trackingCodes' => null]));
        self::assertStringStartsWith(
            'trackingCodes is not a list',
            self::refused(self::sale(['trackingCodes' => ['a' => '42']]))
        );
    }

    /** Version 8.0 sends an affiliate the HopLink's extclid; a tracking code, where there is one, decides. */
    public function testWithNoTrackingCodeTheClickIsTheAffiliatesExtclid(): void
    {
        $extclid = static fn (mixed $value, array $codes = []): array => self::sale([
            'trackingCodes' => $codes,
            'affiliateTrackingParameters' => ['extclid' => $value, 'affSub1' => 'x'],
        ]);

        self::assertSame(42, self::parsed($extclid('42'))->clickId);
        self::assertSame(7, self::parsed($extclid('42', ['7']))->clickId);
        self::assertNoClick($extclid(''));
        $none = self::sale(['trackingCodes' => [], 'affiliateTrackingParameters' => []]);
        self::assertNoClick($none);
        self::assertSame(
            'extclid is "abc", which is not a click id. The HopLink carries extclid=[[subid]], which a Prosper202'
            . ' tracker fills with the click id; send buyers through the tracker.',
            self::refused($extclid('abc'))
        );
        self::assertSame(
            'affiliateTrackingParameters is not an object.',
            self::refused(self::sale(['trackingCodes' => [], 'affiliateTrackingParameters' => '42']))
        );
    }

    public function testATestIsATestAndEveryOtherTypeIsAcknowledged(): void
    {
        $test = self::parsed(['transactionType' => 'TEST', 'receipt' => '********']);
        self::assertSame([NotificationKind::TEST, 'TEST'], [$test->kind, $test->event]);

        foreach (['ABANDONED_ORDER', 'CUSTOMER_EMAIL_UPDATE', 'SOMETHING-NEW', "WEIRD\u{00e9}"] as $type) {
            $n = self::parsed(['transactionType' => $type]);
            self::assertSame(NotificationKind::IGNORED, $n->kind, $type);
        }
        self::assertSame('WEIRD??', self::parsed(['transactionType' => "WEIRD\u{00e9}"])->event);
    }

    public function testABuyerNameInAnotherEncodingDoesNotMakeTheOrderUnreadable(): void
    {
        $plain = str_replace('Test Guy', "Jos\xe9", (string) json_encode(self::sale()));
        self::assertFalse(mb_check_encoding($plain, 'UTF-8'));
        $adapter = new ClickBankAdapter();
        $request = self::request(self::encrypted($plain, self::KEY_A));

        self::assertNotNull($adapter->verify($request, [3 => [self::KEY_A]]));
        self::assertSame('CWOGBZLN', $adapter->parse($request)->transactionId);
    }

    public function testParseReadsOnlyTheRequestVerifyDecrypted(): void
    {
        $adapter = new ClickBankAdapter();
        $this->expectException(\LogicException::class);
        $adapter->parse(self::request(self::body(self::sale(), self::KEY_A)));
    }
}
