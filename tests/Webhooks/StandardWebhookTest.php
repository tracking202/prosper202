<?php

declare(strict_types=1);

namespace Tests\Webhooks;

use PHPUnit\Framework\TestCase;
use Prosper202\Webhooks\StandardWebhook;

/**
 * The signature is the Standard Webhooks one, byte for byte: the vector is
 * the specification's own (its reference libraries' tests), so a receiver's
 * library verifies what this server signs.
 */
final class StandardWebhookTest extends TestCase
{
    private const SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';
    private const ID = 'msg_p5jXN8AQM9LWM0D4loKWxJek';
    private const TIMESTAMP = 1614265330;
    private const BODY = '{"test": 2432232314}';
    private const SIGNATURE = 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=';

    public function testTheSpecificationsVectorSignsAsTheSpecificationSays(): void
    {
        self::assertSame(self::SIGNATURE, StandardWebhook::sign(self::SECRET, self::ID, self::TIMESTAMP, self::BODY));
        self::assertSame([
            'webhook-id: ' . self::ID,
            'webhook-timestamp: ' . self::TIMESTAMP,
            'webhook-signature: ' . self::SIGNATURE,
        ], StandardWebhook::headers([self::SECRET], self::ID, self::TIMESTAMP, self::BODY));
        self::assertTrue(StandardWebhook::verify(
            self::SECRET,
            self::ID,
            (string) self::TIMESTAMP,
            self::SIGNATURE,
            self::BODY,
            self::TIMESTAMP
        ));
    }

    public function testVerificationRefusesAnythingElse(): void
    {
        $ok = static fn (string $id, string $ts, string $sig, string $body, int $now): bool
            => StandardWebhook::verify(self::SECRET, $id, $ts, $sig, $body, $now);
        $ts = (string) self::TIMESTAMP;

        self::assertFalse($ok(self::ID, $ts, self::SIGNATURE, self::BODY . ' ', self::TIMESTAMP), 'another body');
        self::assertFalse($ok('msg_other', $ts, self::SIGNATURE, self::BODY, self::TIMESTAMP), 'another id');
        self::assertFalse($ok(self::ID, (string) (self::TIMESTAMP + 1), self::SIGNATURE, self::BODY, self::TIMESTAMP), 'another time');
        self::assertFalse($ok(self::ID, $ts, 'v2,' . substr(self::SIGNATURE, 3), self::BODY, self::TIMESTAMP), 'another scheme');
        self::assertFalse($ok(self::ID, $ts, self::SIGNATURE, self::BODY, self::TIMESTAMP + 301), 'too old: a replay');
        self::assertFalse($ok(self::ID, $ts, self::SIGNATURE, self::BODY, self::TIMESTAMP - 301), 'from the future');
        self::assertTrue($ok(self::ID, $ts, self::SIGNATURE, self::BODY, self::TIMESTAMP + 300));
        self::assertFalse($ok(self::ID, $ts . 'x', self::SIGNATURE, self::BODY, self::TIMESTAMP), 'a timestamp that is not a number');
        self::assertTrue($ok(self::ID, $ts, 'v1,bm90IGl0 ' . self::SIGNATURE, self::BODY, self::TIMESTAMP), 'any one of several');
    }

    public function testDuringARotationEachSecretsSignatureIsSentAndEachVerifies(): void
    {
        $new = StandardWebhook::newSecret();
        $header = StandardWebhook::signatures([$new, self::SECRET], self::ID, self::TIMESTAMP, self::BODY);

        self::assertSame(StandardWebhook::sign($new, self::ID, self::TIMESTAMP, self::BODY) . ' ' . self::SIGNATURE, $header);
        foreach ([$new, self::SECRET] as $secret) {
            self::assertTrue(StandardWebhook::verify($secret, self::ID, (string) self::TIMESTAMP, $header, self::BODY, self::TIMESTAMP));
        }
        self::assertSame(self::SIGNATURE, StandardWebhook::signatures([self::SECRET, self::SECRET], self::ID, self::TIMESTAMP, self::BODY));
    }

    /**
     * A secret minted before 1.9.81 (hex, or an export's operator-chosen
     * text) is signed with its own bytes, so a library given whsec_ and its
     * base64 verifies it. It is never decoded as base64, though hex decodes.
     */
    public function testASecretWithoutThePrefixIsItsOwnKey(): void
    {
        $hex = bin2hex(random_bytes(24));
        self::assertSame($hex, StandardWebhook::keyFor($hex));
        self::assertSame('whsec_' . base64_encode($hex), StandardWebhook::libraryForm($hex));
        self::assertSame(self::SECRET, StandardWebhook::libraryForm(self::SECRET));

        $sig = StandardWebhook::sign($hex, self::ID, self::TIMESTAMP, self::BODY);
        self::assertTrue(StandardWebhook::verify(StandardWebhook::libraryForm($hex), self::ID, (string) self::TIMESTAMP, $sig, self::BODY, self::TIMESTAMP));
        self::assertSame($sig, StandardWebhook::sign(StandardWebhook::libraryForm($hex), self::ID, self::TIMESTAMP, self::BODY));
    }

    public function testANewSecretIsAWhsecSecretOf32Bytes(): void
    {
        $secret = StandardWebhook::newSecret();
        self::assertMatchesRegularExpression('/^whsec_[A-Za-z0-9+\/]{43}=$/D', $secret);
        self::assertSame(32, strlen(StandardWebhook::keyFor($secret)));
        self::assertNotSame($secret, StandardWebhook::newSecret());
        self::assertMatchesRegularExpression('/^msg_[0-9a-f]{32}$/D', StandardWebhook::newMessageId());
    }

    /** @return iterable<string, array{0: string}> */
    public static function unusableSecrets(): iterable
    {
        yield 'empty' => [''];
        yield 'prefix only' => ['whsec_'];
        yield 'not base64' => ['whsec_not base64!'];
        yield 'base64 with a stray character' => ['whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw*'];
    }

    /** @dataProvider unusableSecrets */
    public function testASecretWithNoKeyIsRefusedRatherThanSignedAsText(string $secret): void
    {
        self::assertFalse(StandardWebhook::isUsableSecret($secret));
        $this->expectException(\InvalidArgumentException::class);
        StandardWebhook::sign($secret, self::ID, self::TIMESTAMP, self::BODY);
    }

    /** @return iterable<string, array{0: string}> */
    public static function unusableIds(): iterable
    {
        yield 'empty' => [''];
        yield 'a dot, which splits what is signed' => ['msg.1'];
        yield 'a space' => ['msg 1'];
        yield 'a line break' => ["msg\r\nX-Injected: 1"];
        yield 'too long' => [str_repeat('a', 256)];
    }

    /** @dataProvider unusableIds */
    public function testAMessageIdThatCannotBeAHeaderIsRefused(string $id): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StandardWebhook::headers([self::SECRET], $id, self::TIMESTAMP, self::BODY);
    }

    public function testAnEventTypeIsVersioned(): void
    {
        self::assertSame('revenue.recorded.v1', StandardWebhook::versionedType('revenue.recorded'));
        self::assertSame('alert.test.v2', StandardWebhook::versionedType('alert.test', 2));
    }
}
