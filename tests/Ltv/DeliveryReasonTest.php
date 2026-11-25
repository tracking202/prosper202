<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;
use Prosper202\Ltv\DeliveryReason;

/**
 * What LTV Settings › Log and GET /ltv/webhooks/{id}/deliveries show of a
 * delivery's last response: credentials out, one line, cut.
 */
final class DeliveryReasonTest extends TestCase
{
    private const SECRET = 'whsec_0123456789abcdef';

    /** @return iterable<string, array{string, string}> */
    public static function credentials(): iterable
    {
        yield 'header line' => [
            "401\nAuthorization: Bearer abc.def\nX-Other: 1",
            "401\nAuthorization: [removed]\nX-Other: 1",
        ];
        yield 'json echo' => [
            '{"headers":{"authorization":"Basic dXNlcjpwYXNz","Cookie":"sid=1"}}',
            '{"headers":{"authorization":"[removed]","Cookie":"[removed]"}}',
        ];
        yield 'json key' => ['{"webhook_secret":"' . self::SECRET . 'x"}', '{"webhook_secret":"[removed]"}'];
        yield 'our signature' => ['X-P202-Signature: sha256=abc123', 'X-P202-Signature: [removed]'];
        yield 'query string' => ['bad request ?api_key=K123&x=1', 'bad request ?api_key=[removed]&x=1'];
        yield 'set-cookie keeps its attributes' => ['Set-Cookie: a=b; Path=/', 'Set-Cookie: [removed]; Path=/'];
        yield 'an x_token' => ['access_token=zz end', 'access_token=[removed]'];
        yield 'a bare bearer' => ['expected Bearer eyJhbGci.x-y_z here', 'expected Bearer [removed] here'];
        yield 'the webhook secret in prose' => [
            'signed with ' . self::SECRET . ' wrongly',
            'signed with [removed] wrongly',
        ];
    }

    /** @dataProvider credentials */
    public function testCredentialsAreRemoved(string $answer, string $shown): void
    {
        self::assertSame($shown, DeliveryReason::redact($answer, self::SECRET));
        self::assertSame($shown, DeliveryReason::redact($shown), 'read again, a stored answer reads the same');
    }

    /** @return iterable<string, array{string}> */
    public static function reasons(): iterable
    {
        yield 'curl' => ['curl: Could not resolve host: hooks.example'];
        yield 'blocked' => ['blocked: the connection went to 10.0.0.1 instead of the checked address 93.184.216.34'];
        yield 'a token that was refused' => ['invalid token: expired'];
        yield 'held' => [\Prosper202\Ltv\MysqlWebhookRepository::HELD_REASON];
        yield 'expired' => [\Prosper202\Ltv\WebhookDispatcher::EXPIRED_REASON];
        yield 'not UTF-8' => ["\xff\xfe" . str_repeat("\xc3\xa9", 10)];
    }

    /** @dataProvider reasons */
    public function testAReasonWithNoCredentialIsKeptAsItIs(string $reason): void
    {
        self::assertSame($reason, DeliveryReason::redact($reason, self::SECRET));
    }

    public function testAShortSecretIsNotSearchedFor(): void
    {
        self::assertSame('a b c', DeliveryReason::redact('a b c', 'b'), 'an empty or short secret matches prose');
        self::assertSame('a b c', DeliveryReason::redact('a b c'));
    }

    public function testTheLineIsOneLineOfValidTextCutWithAnEllipsis(): void
    {
        self::assertSame('', DeliveryReason::line(null));
        self::assertSame(
            '500 Internal <b>error</b> retry later',
            DeliveryReason::line("  500 Internal\r\n\t<b>error</b>\x01\x7f retry later\n")
        );
        self::assertSame('?? é', DeliveryReason::line("\xff\xfe \xc3\xa9"));

        $long = DeliveryReason::line(str_repeat('é', 300));
        self::assertSame(DeliveryReason::LINE_CHARS, mb_strlen($long, 'UTF-8'));
        self::assertStringEndsWith('é…', $long);
        self::assertSame(str_repeat('é', 300), DeliveryReason::line(str_repeat('é', 300), 1000));
    }

    public function testRedactSafelyHandlesAnAnswerOfAnySizeWithoutThrowing(): void
    {
        foreach ([str_repeat('a', 2_000_000), 'Bearer ' . str_repeat('A', 2_000_000), str_repeat("\xff", 100_000)] as $answer) {
            $out = DeliveryReason::redactSafely($answer, self::SECRET);
            self::assertLessThanOrEqual(DeliveryReason::SCAN_BYTES, strlen($out));
            self::assertStringNotContainsString(self::SECRET, $out);
        }
    }

    public function testRedactSafelyTakesTheSecretOutBeforeItCutsTheAnswer(): void
    {
        $answer = str_repeat('x', DeliveryReason::SCAN_BYTES - 5) . self::SECRET . ' tail';

        self::assertStringNotContainsString(substr(self::SECRET, 0, 4), DeliveryReason::redactSafely($answer, self::SECRET));
    }

    public function testRedactSafelyAgreesWithRedactOnAnOrdinaryAnswer(): void
    {
        $answer = 'invalid token: expired; Authorization: Bearer abc.def; api_key=zzz';

        self::assertSame(DeliveryReason::redact($answer), DeliveryReason::redactSafely($answer));
        self::assertSame(DeliveryReason::redactSafely($answer), DeliveryReason::redactSafely(DeliveryReason::redactSafely($answer)));
    }
}
