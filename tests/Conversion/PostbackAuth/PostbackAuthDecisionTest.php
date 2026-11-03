<?php

declare(strict_types=1);

namespace Tests\Conversion\PostbackAuth;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\PostbackAuth\PostbackAuthGate;
use Prosper202\Conversion\PostbackAuth\PostbackAuthMode;
use Prosper202\Conversion\PostbackAuth\PostbackSigningSecret;
use Prosper202\Conversion\PostbackAuth\PostbackAuthSettings;
use Prosper202\Conversion\PostbackAuth\PostbackSignature;
use Prosper202\Conversion\PostbackAuth\PostbackToken;
use Prosper202\Conversion\PostbackAuth\PresentedCredentials;

/**
 * Postback authentication's rules without a database: the token's shape,
 * what a request presents (read from its raw query string), the signed
 * form's canonical string, and the gate's decision for each setting.
 * PostbackAuthIntegrationTest holds the store and the five endpoints.
 */
final class PostbackAuthDecisionTest extends TestCase
{
    private const TOKEN = '0123456789abcdef0123456789abcdef';
    private const OLD = 'fedcba9876543210fedcba9876543210';
    private const SECRET = '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';
    private const OLD_SECRET = 'ffeeddccbbaa99887766554433221100ffeeddccbbaa99887766554433221100';
    private const NOW = 1_800_000_000;

    public function testATokenIs128RandomBitsInHex(): void
    {
        $a = PostbackToken::generate();
        self::assertTrue(PostbackToken::isWellFormed($a));
        self::assertNotSame($a, PostbackToken::generate());
        foreach (['', strtoupper(self::TOKEN), self::TOKEN . 'a', substr(self::TOKEN, 1), self::TOKEN . "\n"] as $bad) {
            self::assertFalse(PostbackToken::isWellFormed($bad), json_encode($bad));
        }
        self::assertSame('&p202_token=' . self::TOKEN, PostbackToken::queryPart(self::TOKEN));
        self::assertSame('', PostbackToken::queryPart(''));
    }

    public function testCredentialsAreReadFromTheRawQueryWithoutCase(): void
    {
        $p = PresentedCredentials::fromQueryString('subid=12&P202_Token=' . self::TOKEN . '&amount=1');
        self::assertSame(self::TOKEN, $p->token);
        self::assertNull($p->problem);
        self::assertFalse($p->isSigned());

        $p = PresentedCredentials::fromQueryString('subid=12&p202_token=');
        self::assertTrue($p->presentsNothing(), 'an empty token is no token, as an unfilled macro is');

        $p = PresentedCredentials::fromQueryString('subid=12&p202_token=' . self::TOKEN . '&p202_token=' . self::OLD);
        self::assertStringContainsString('p202_token is sent 2 times', (string) $p->problem);

        $p = PresentedCredentials::fromQueryString('subid=12&p202_ts=' . self::NOW);
        self::assertStringContainsString('carries only p202_ts', (string) $p->problem);
        $p = PresentedCredentials::fromQueryString('subid=12&p202_sig=ab');
        self::assertStringContainsString('carries only p202_sig', (string) $p->problem);

        $p = PresentedCredentials::fromQueryString('subid=12&p202_ts=5&p202_sig=ab&x');
        self::assertSame([['subid', '12'], ['p202_ts', '5'], ['x', '']], $p->pairs, 'every pair but the signature, decoded');
    }

    /** The canonical string as the documentation spells it, byte for byte. */
    public function testTheCanonicalStringSortsEncodedPairsAsBytes(): void
    {
        $query = 'subid=123&amount=9.99&txid=A+B%2B1&p202_ts=' . self::NOW . '&b=10&b=9&%7Eu=x%20y&p202_sig=ff';
        $p = PresentedCredentials::fromQueryString($query);
        $expected = "P202-POSTBACK-V1\ngpb\n"
            . 'amount=9.99&b=10&b=9&p202_ts=' . self::NOW . '&subid=123&txid=A%20B%2B1&~u=x%20y';
        self::assertSame($expected, PostbackSignature::stringToSign('gpb', $p->pairs));
        self::assertSame(hash_hmac('sha256', $expected, self::SECRET), PostbackSignature::sign('gpb', $p->pairs, self::SECRET));
    }

    public function testASignatureIsBoundToItsEndpointItsParametersAndItsSecret(): void
    {
        $query = PostbackSignature::signedQuery('gpb', ['subid' => '123', 'amount' => '9.99'], self::SECRET, self::NOW);
        $p = PresentedCredentials::fromQueryString($query);
        self::assertTrue($p->isSigned());
        $sig = (string) $p->signature;
        self::assertTrue(PostbackSignature::matches('gpb', $p->pairs, $sig, self::SECRET));
        self::assertTrue(PostbackSignature::matches('gpb', $p->pairs, strtoupper($sig), self::SECRET), 'upper-case hex is the same signature');
        self::assertFalse(PostbackSignature::matches('upx', $p->pairs, $sig, self::SECRET), 'another endpoint');
        self::assertFalse(PostbackSignature::matches('gpb', $p->pairs, $sig, self::OLD_SECRET), 'another secret');
        $changed = PresentedCredentials::fromQueryString(str_replace('amount=9.99', 'amount=99.9', $query));
        self::assertFalse(PostbackSignature::matches('gpb', $changed->pairs, $sig, self::SECRET), 'another amount');
        $added = PresentedCredentials::fromQueryString($query . '&status=reversed');
        self::assertFalse(PostbackSignature::matches('gpb', $added->pairs, $sig, self::SECRET), 'a parameter added');
        self::assertFalse(PostbackSignature::matches('gpb', $p->pairs, substr($sig, 1), self::SECRET), 'not 64 hex characters');

        $this->expectException(\InvalidArgumentException::class);
        PostbackSignature::stringToSign('cb202', []);
    }

    public function testTimestampsAreDigitsOnly(): void
    {
        self::assertSame(self::NOW, PostbackSignature::timestamp((string) self::NOW));
        foreach (['1.8e9', ' 1800000000', '-5', '', '0x10', '1800000000.0'] as $bad) {
            self::assertNull(PostbackSignature::timestamp($bad), $bad);
        }
    }

    public function testASigningSecretIs256RandomBitsInHex(): void
    {
        $a = PostbackSigningSecret::generate();
        self::assertTrue(PostbackSigningSecret::isWellFormed($a));
        self::assertNotSame($a, PostbackSigningSecret::generate());
        self::assertFalse(PostbackSigningSecret::isWellFormed(self::TOKEN), 'a token is not a secret');
    }

    public function testNothingPresentedPassesOnlyWhenTheModeIsOff(): void
    {
        $nothing = PresentedCredentials::fromQueryString('subid=12');
        self::assertNull($this->decide(self::mode(PostbackAuthMode::OFF), $nothing));
        self::assertSame('token_required', $this->decide(self::mode(PostbackAuthMode::TOKEN), $nothing)?->reason);
        self::assertSame('signature_required', $this->decide(self::mode(PostbackAuthMode::SIGNED), $nothing)?->reason);
        self::assertSame('token_required', $this->decide(PostbackAuthSettings::none(), $nothing)?->reason,
            'an account with no row (made from 1.9.80 on) needs the token or a signature');
    }

    public function testAPresentedTokenIsCheckedInEveryMode(): void
    {
        foreach (PostbackAuthMode::cases() as $mode) {
            $settings = self::mode($mode);
            self::assertSame('token_wrong', $this->decide($settings, $this->query('p202_token=' . self::OLD))?->reason, $mode->value);
            self::assertSame('token_wrong', $this->decide($settings, $this->query('p202_token=nope'))?->reason, $mode->value);
        }
        self::assertNull($this->decide(self::mode(PostbackAuthMode::OFF), $this->query('p202_token=' . self::TOKEN)));
        self::assertNull($this->decide(self::mode(PostbackAuthMode::TOKEN), $this->query('p202_token=' . self::TOKEN)));
        self::assertSame('token_wrong', $this->decide(PostbackAuthSettings::none(), $this->query('p202_token=' . self::TOKEN))?->reason,
            'an account without a token refuses every one');
    }

    public function testSignedOnlyRefusesTheTokenAloneAndEveryUnsignedPixel(): void
    {
        $signedOnly = self::mode(PostbackAuthMode::SIGNED);
        $refusal = $this->decide($signedOnly, $this->query('subid=12&p202_token=' . self::TOKEN));
        self::assertSame('signature_required', $refusal?->reason, 'the right token alone');
        self::assertStringContainsString('only signed postbacks', (string) $refusal?->message);
        self::assertStringContainsString('signing secret', (string) $refusal?->message);
        foreach (['px', 'gpx', 'upx'] as $pixel) {
            $refusal = PostbackAuthGate::decide($signedOnly, $this->query('subid=12&p202_token=' . self::TOKEN), $pixel, self::NOW);
            self::assertSame('signature_required', $refusal?->reason, $pixel);
            self::assertStringContainsString('cannot sign, so ' . $pixel . '.php is refused', (string) $refusal?->message);
        }
        $signed = PostbackSignature::signedQuery('gpb', ['subid' => '12'], self::SECRET, self::NOW);
        self::assertNull($this->decide($signedOnly, PresentedCredentials::fromQueryString($signed)));
        $signedWithToken = PostbackSignature::signedQuery('gpb', ['subid' => '12', 'p202_token' => self::OLD], self::SECRET, self::NOW);
        self::assertSame('token_wrong', $this->decide($signedOnly, PresentedCredentials::fromQueryString($signedWithToken))?->reason,
            'a token sent beside a signature is still checked');
    }

    public function testTheReplacedTokenWorksUntilItsGracePeriodEnds(): void
    {
        $rotated = new PostbackAuthSettings(PostbackAuthMode::TOKEN, self::TOKEN, self::NOW, self::OLD, self::NOW + 60, self::SECRET);
        self::assertSame([self::TOKEN, self::OLD], $rotated->validTokens(self::NOW));
        self::assertNull($this->decide($rotated, $this->query('p202_token=' . self::OLD)));
        self::assertSame('token_wrong', PostbackAuthGate::decide($rotated, $this->query('p202_token=' . self::OLD), 'gpb', self::NOW + 60)?->reason);
        self::assertSame(self::NOW + 60, $rotated->previousTokenValidUntil(self::NOW));
        self::assertNull($rotated->previousTokenValidUntil(self::NOW + 60));
    }

    public function testASignatureIsCheckedAgainstTheSigningSecretNotTheToken(): void
    {
        foreach (PostbackAuthMode::cases() as $mode) {
            $settings = self::mode($mode);
            $signed = PostbackSignature::signedQuery('gpb', ['subid' => '12'], self::SECRET, self::NOW);
            self::assertNull($this->decide($settings, PresentedCredentials::fromQueryString($signed)), $mode->value);
            $withToken = PostbackSignature::signedQuery('gpb', ['subid' => '12'], self::TOKEN, self::NOW);
            self::assertSame('signature_wrong', $this->decide($settings, PresentedCredentials::fromQueryString($withToken))?->reason,
                $mode->value . ': keyed with the URL token');
        }
        $settings = self::mode(PostbackAuthMode::TOKEN);
        $edge = PostbackSignature::signedQuery('gpb', ['subid' => '12'], self::SECRET, self::NOW - PostbackSignature::WINDOW);
        self::assertNull($this->decide($settings, PresentedCredentials::fromQueryString($edge)), 'the window\'s edge is in it');
        $late = PostbackSignature::signedQuery('gpb', ['subid' => '12'], self::SECRET, self::NOW - PostbackSignature::WINDOW - 1);
        self::assertSame('signature_expired', $this->decide($settings, PresentedCredentials::fromQueryString($late))?->reason);
        $early = PostbackSignature::signedQuery('gpb', ['subid' => '12'], self::SECRET, self::NOW + PostbackSignature::WINDOW + 1);
        self::assertSame('signature_expired', $this->decide($settings, PresentedCredentials::fromQueryString($early))?->reason);
        $signed = PostbackSignature::signedQuery('gpb', ['subid' => '12'], self::SECRET, self::NOW);
        self::assertSame('signature_wrong', $this->decide($settings, PresentedCredentials::fromQueryString(str_replace('subid=12', 'subid=13', $signed)))?->reason);
        self::assertSame('signature_malformed', $this->decide($settings, $this->query('p202_ts=soon&p202_sig=' . str_repeat('a', 64)))?->reason);
        self::assertSame('signature_wrong', $this->decide(new PostbackAuthSettings(PostbackAuthMode::TOKEN, self::TOKEN, 1, null, null),
            PresentedCredentials::fromQueryString($signed))?->reason, 'an account without a secret refuses every signature');

        $rotated = new PostbackAuthSettings(PostbackAuthMode::SIGNED, self::TOKEN, 1, null, null, self::OLD_SECRET, self::NOW, self::SECRET, self::NOW + 60);
        self::assertNull($this->decide($rotated, PresentedCredentials::fromQueryString($signed)), 'signed with the replaced secret, in its grace');
        self::assertSame('signature_wrong', PostbackAuthGate::decide($rotated, PresentedCredentials::fromQueryString($signed), 'gpb', self::NOW + 60)?->reason,
            'and not after');
        self::assertSame(self::NOW + 60, $rotated->previousSecretValidUntil(self::NOW));
    }

    public function testNoRefusalRepeatsACredential(): void
    {
        $cases = [
            [self::mode(PostbackAuthMode::TOKEN), 'p202_token=' . self::OLD],
            [self::mode(PostbackAuthMode::TOKEN), 'subid=1'],
            [self::mode(PostbackAuthMode::SIGNED), 'p202_token=' . self::TOKEN],
            [self::mode(PostbackAuthMode::TOKEN), PostbackSignature::signedQuery('gpb', ['subid' => '1'], self::OLD_SECRET, self::NOW)],
        ];
        foreach ($cases as [$settings, $query]) {
            $refusal = $this->decide($settings, $this->query($query));
            self::assertNotNull($refusal);
            foreach ([self::TOKEN, self::OLD, self::SECRET, self::OLD_SECRET] as $credential) {
                self::assertStringNotContainsString($credential, $refusal->message);
            }
        }
    }

    private function decide(PostbackAuthSettings $settings, PresentedCredentials $presented): ?\Prosper202\Conversion\PostbackAuth\PostbackAuthRefusal
    {
        return PostbackAuthGate::decide($settings, $presented, 'gpb', self::NOW);
    }

    private function query(string $query): PresentedCredentials
    {
        return PresentedCredentials::fromQueryString($query);
    }

    private static function mode(PostbackAuthMode $mode): PostbackAuthSettings
    {
        return new PostbackAuthSettings($mode, self::TOKEN, self::NOW - 10, null, null, self::SECRET, self::NOW - 10);
    }
}
