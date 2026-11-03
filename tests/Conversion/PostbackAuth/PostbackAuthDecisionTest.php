<?php

declare(strict_types=1);

namespace Tests\Conversion\PostbackAuth;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\PostbackAuth\PostbackAuthGate;
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
        self::assertSame(hash_hmac('sha256', $expected, self::TOKEN), PostbackSignature::sign('gpb', $p->pairs, self::TOKEN));
    }

    public function testASignatureIsBoundToItsEndpointItsParametersAndItsToken(): void
    {
        $query = PostbackSignature::signedQuery('gpb', ['subid' => '123', 'amount' => '9.99'], self::TOKEN, self::NOW);
        $p = PresentedCredentials::fromQueryString($query);
        self::assertTrue($p->isSigned());
        $sig = (string) $p->signature;
        self::assertTrue(PostbackSignature::matches('gpb', $p->pairs, $sig, self::TOKEN));
        self::assertTrue(PostbackSignature::matches('gpb', $p->pairs, strtoupper($sig), self::TOKEN), 'upper-case hex is the same signature');
        self::assertFalse(PostbackSignature::matches('upx', $p->pairs, $sig, self::TOKEN), 'another endpoint');
        self::assertFalse(PostbackSignature::matches('gpb', $p->pairs, $sig, self::OLD), 'another token');
        $changed = PresentedCredentials::fromQueryString(str_replace('amount=9.99', 'amount=99.9', $query));
        self::assertFalse(PostbackSignature::matches('gpb', $changed->pairs, $sig, self::TOKEN), 'another amount');
        $added = PresentedCredentials::fromQueryString($query . '&status=reversed');
        self::assertFalse(PostbackSignature::matches('gpb', $added->pairs, $sig, self::TOKEN), 'a parameter added');
        self::assertFalse(PostbackSignature::matches('gpb', $p->pairs, substr($sig, 1), self::TOKEN), 'not 64 hex characters');

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

    public function testNothingPresentedPassesOnlyWhereNothingIsRequired(): void
    {
        $nothing = PresentedCredentials::fromQueryString('subid=12');
        self::assertNull($this->decide(self::off(), $nothing));
        self::assertSame('token_required', $this->decide(self::on(), $nothing)?->reason);
        self::assertSame('token_required', $this->decide(PostbackAuthSettings::none(), $nothing)?->reason,
            'an account with no row (made from 1.9.80 on) requires it');
    }

    public function testAPresentedTokenIsCheckedWhetherOrNotItIsRequired(): void
    {
        foreach (['on' => self::on(), 'off' => self::off()] as $label => $settings) {
            self::assertNull($this->decide($settings, $this->query('p202_token=' . self::TOKEN)), $label);
            self::assertSame('token_wrong', $this->decide($settings, $this->query('p202_token=' . self::OLD))?->reason, $label);
            self::assertSame('token_wrong', $this->decide($settings, $this->query('p202_token=nope'))?->reason, $label);
        }
        self::assertSame('token_wrong', $this->decide(PostbackAuthSettings::none(), $this->query('p202_token=' . self::TOKEN))?->reason,
            'an account without a token refuses every one');
    }

    public function testTheReplacedTokenWorksUntilItsGracePeriodEnds(): void
    {
        $rotated = new PostbackAuthSettings(true, self::TOKEN, self::NOW, self::OLD, self::NOW + 60);
        self::assertSame([self::TOKEN, self::OLD], $rotated->validTokens(self::NOW));
        self::assertNull($this->decide($rotated, $this->query('p202_token=' . self::OLD)));
        self::assertSame('token_wrong', PostbackAuthGate::decide($rotated, $this->query('p202_token=' . self::OLD), 'gpb', self::NOW + 60)?->reason);
        self::assertSame(self::NOW + 60, $rotated->previousValidUntil(self::NOW));
        self::assertNull($rotated->previousValidUntil(self::NOW + 60));
    }

    public function testASignedRequestIsCheckedForItsWindowAndItsSignature(): void
    {
        $signed = PostbackSignature::signedQuery('gpb', ['subid' => '12'], self::TOKEN, self::NOW);
        self::assertNull($this->decide(self::on(), PresentedCredentials::fromQueryString($signed)));
        self::assertNull($this->decide(self::off(), PresentedCredentials::fromQueryString($signed)));

        $edge = PostbackSignature::signedQuery('gpb', ['subid' => '12'], self::TOKEN, self::NOW - PostbackSignature::WINDOW);
        self::assertNull($this->decide(self::on(), PresentedCredentials::fromQueryString($edge)), 'the window\'s edge is in it');
        $late = PostbackSignature::signedQuery('gpb', ['subid' => '12'], self::TOKEN, self::NOW - PostbackSignature::WINDOW - 1);
        self::assertSame('signature_expired', $this->decide(self::on(), PresentedCredentials::fromQueryString($late))?->reason);
        $early = PostbackSignature::signedQuery('gpb', ['subid' => '12'], self::TOKEN, self::NOW + PostbackSignature::WINDOW + 1);
        self::assertSame('signature_expired', $this->decide(self::on(), PresentedCredentials::fromQueryString($early))?->reason);

        $forged = PostbackSignature::signedQuery('gpb', ['subid' => '12'], self::OLD, self::NOW);
        self::assertSame('signature_wrong', $this->decide(self::off(), PresentedCredentials::fromQueryString($forged))?->reason);
        self::assertSame('signature_wrong', $this->decide(self::on(), PresentedCredentials::fromQueryString(str_replace('subid=12', 'subid=13', $signed)))?->reason);
        self::assertSame('signature_malformed', $this->decide(self::on(), $this->query('p202_ts=soon&p202_sig=' . str_repeat('a', 64)))?->reason);

        $rotated = new PostbackAuthSettings(true, self::OLD, self::NOW, self::TOKEN, self::NOW + 60);
        self::assertNull($this->decide($rotated, PresentedCredentials::fromQueryString($signed)), 'signed with the replaced token, in its grace');
    }

    public function testATokenAndASignatureTogetherMustBothHold(): void
    {
        $signed = PostbackSignature::signedQuery('gpb', ['subid' => '12', 'p202_token' => self::TOKEN], self::TOKEN, self::NOW);
        self::assertNull($this->decide(self::on(), PresentedCredentials::fromQueryString($signed)));
        $wrongToken = PostbackSignature::signedQuery('gpb', ['subid' => '12', 'p202_token' => self::OLD], self::TOKEN, self::NOW);
        self::assertSame('token_wrong', $this->decide(self::on(), PresentedCredentials::fromQueryString($wrongToken))?->reason);
    }

    public function testNoRefusalRepeatsAToken(): void
    {
        $cases = [
            [self::on(), 'p202_token=' . self::OLD],
            [self::on(), 'subid=1'],
            [self::on(), PostbackSignature::signedQuery('gpb', ['subid' => '1'], self::OLD, self::NOW)],
        ];
        foreach ($cases as [$settings, $query]) {
            $refusal = $this->decide($settings, $this->query($query));
            self::assertNotNull($refusal);
            self::assertStringNotContainsString(self::TOKEN, $refusal->message);
            self::assertStringNotContainsString(self::OLD, $refusal->message);
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

    private static function on(): PostbackAuthSettings
    {
        return new PostbackAuthSettings(true, self::TOKEN, self::NOW - 10, null, null);
    }

    private static function off(): PostbackAuthSettings
    {
        return new PostbackAuthSettings(false, self::TOKEN, self::NOW - 10, null, null);
    }
}
