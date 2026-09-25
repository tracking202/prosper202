<?php

declare(strict_types=1);

namespace Tests\Identity;

use PHPUnit\Framework\TestCase;
use Prosper202\Identity\ClickIdentity;
use Prosper202\Identity\CustomerId;
use Prosper202\Identity\IdentityGraph;
use Prosper202\Identity\IdentitySignal;
use Prosper202\Identity\RequestSignals;
use Prosper202\Identity\SignalType;

/**
 * The pure half of identity capture: what a request is allowed to say about
 * who is clicking, and what is refused. The graph's writes are proved against
 * a real database in IdentityGraphIntegrationTest.
 */
final class IdentitySignalsTest extends TestCase
{
    private const LINK = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';
    private const VID = '0123456789abcdef0123456789abcdef';
    private const LPID = 'fedcba9876543210fedcba9876543210';

    private const DIGEST = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    public function testCustomerIdCanonicalCarriesItsTypeAndFoldsDigestsOnly(): void
    {
        self::assertSame('email_sha256:' . self::DIGEST, CustomerId::canonical(' ' . strtoupper(self::DIGEST) . ' ', 'EMAIL_SHA256'));
        self::assertSame('email_md5:' . substr(self::DIGEST, 0, 32), CustomerId::canonical(strtoupper(substr(self::DIGEST, 0, 32)), 'email_md5'));
        self::assertSame('custom:Acct-42', CustomerId::canonical(' Acct-42 '), 'no type is custom, as in the LTV ledger');
        self::assertSame('merchant_id:Acct-42', CustomerId::canonical('Acct-42', 'merchant_id'));
        self::assertNotSame(CustomerId::canonical('abc', 'esp_id'), CustomerId::canonical('abc', 'merchant_id'), 'two namespaces, two people');
    }

    public function testAnIdThatIsNotOneHasNoCanonicalForm(): void
    {
        self::assertNull(CustomerId::canonical('ana@example.com', 'email'), 'not an LTV type');
        self::assertNull(CustomerId::canonical('ana@example.com', 'email_sha256'), 'not a digest');
        self::assertNull(CustomerId::canonical(substr(self::DIGEST, 1), 'email_sha256'));
        self::assertNull(CustomerId::canonical('   ', 'custom'));
        self::assertNull(CustomerId::canonical('x', 'custom:x'));
    }

    public function testASignatureVerifiesOnlyForTheIdItSigned(): void
    {
        $canonical = (string) CustomerId::canonical(self::DIGEST, 'email_sha256');
        $sig = CustomerId::sign(self::LINK, $canonical);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $sig);
        self::assertSame(hash_hmac('sha256', 'email_sha256:' . self::DIGEST, (string) hex2bin(self::LINK)), $sig, 'the documented construction');
        self::assertTrue(CustomerId::verify(self::LINK, $canonical, $sig));
        self::assertTrue(CustomerId::verify(self::LINK, $canonical, strtoupper($sig)), 'hex case is not significant');
        self::assertFalse(CustomerId::verify(self::LINK, 'custom:' . self::DIGEST, $sig), 'the same value in another namespace');
        self::assertFalse(CustomerId::verify(str_repeat('ab', 32), $canonical, $sig), 'another account\'s key');
        self::assertFalse(CustomerId::verify(self::LINK, '', CustomerId::sign(self::LINK, '')), 'an empty id is never valid');
        self::assertFalse(CustomerId::verify(self::LINK, $canonical, substr($sig, 0, 63)));
        self::assertFalse(CustomerId::verify(self::LINK, $canonical, ''));
    }

    public function testAMalformedKeyIsRefusedNotUsed(): void
    {
        foreach (['not-hex', 'abc', str_repeat('A', 64), str_repeat('a', 62), str_repeat('a', 64) . "\n"] as $bad) {
            try {
                CustomerId::sign($bad, 'x');
                self::fail('linking key accepted: ' . var_export($bad, true));
            } catch (\InvalidArgumentException) {
            }
            try {
                IdentityGraph::hash($bad, new IdentitySignal(SignalType::CUSTOMER, 'x'));
                self::fail('hashing key accepted: ' . var_export($bad, true));
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertTrue(true);
    }

    public function testConsentIsOneSwitch(): void
    {
        self::assertTrue(RequestSignals::consentGiven([]));
        self::assertTrue(RequestSignals::consentGiven(['p202_consent' => '1']));
        self::assertFalse(RequestSignals::consentGiven(['p202_consent' => '0']));
        self::assertFalse(RequestSignals::consentGiven(['p202_consent' => ' 0 ']));
        self::assertFalse(RequestSignals::consentGiven([], false), 'a campaign with capture off');
    }

    public function testCampaignSettingFailsClosedOnAnUnreadableValue(): void
    {
        self::assertTrue(RequestSignals::campaignAllows(null), 'no campaign');
        self::assertTrue(RequestSignals::campaignAllows('1'));
        self::assertTrue(RequestSignals::campaignAllows(1));
        self::assertFalse(RequestSignals::campaignAllows('0'));
        self::assertFalse(RequestSignals::campaignAllows(0));
        foreach (['', '2', 'yes', ' 1', true, 1.0, []] as $garbage) {
            self::assertFalse(RequestSignals::campaignAllows($garbage), var_export($garbage, true));
        }
    }

    public function testBrowserIdsMustBeExactlyWhatTheTrackerMints(): void
    {
        self::assertSame(self::VID, RequestSignals::visitorCookie(['p202vid' => self::VID]));
        foreach ([strtoupper(self::VID), self::VID . "\n", substr(self::VID, 1), self::VID . '0', 'x', ['a'], 12] as $bad) {
            self::assertNull(RequestSignals::visitorCookie(['p202vid' => $bad]), var_export($bad, true));
            self::assertNull(RequestSignals::landingPageId(['p202lpid' => $bad]), var_export($bad, true));
        }
        self::assertSame(self::LPID, RequestSignals::landingPageId(['p202lpid' => self::LPID]));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', RequestSignals::mintVisitorId());
        self::assertNotSame(RequestSignals::mintVisitorId(), RequestSignals::mintVisitorId());
    }

    public function testOnlyASignedCustomerIdIsASignal(): void
    {
        $sig = CustomerId::sign(self::LINK, 'email_sha256:' . self::DIGEST);
        $signal = RequestSignals::signedCustomer(['cust' => strtoupper(self::DIGEST), 'cust_type' => 'email_sha256', 'cust_sig' => $sig], self::LINK);
        self::assertNotNull($signal);
        self::assertSame(SignalType::CUSTOMER, $signal->type);
        self::assertSame('email_sha256:' . self::DIGEST, $signal->value);

        self::assertNotNull(RequestSignals::signedCustomer(['customer_ref' => self::DIGEST, 'customer_ref_type' => 'email_sha256', 'cust_sig' => $sig], self::LINK), 'the long names too');
        self::assertNull(RequestSignals::signedCustomer(['cust' => self::DIGEST, 'cust_type' => 'email_sha256'], self::LINK), 'unsigned');
        self::assertNull(RequestSignals::signedCustomer(['cust' => self::DIGEST, 'cust_sig' => $sig], self::LINK), 'the same value sent as custom is another id');
        self::assertNull(RequestSignals::signedCustomer(['cust' => str_repeat('0', 64), 'cust_type' => 'email_sha256', 'cust_sig' => $sig], self::LINK));
        self::assertNull(RequestSignals::signedCustomer(['cust' => self::DIGEST, 'cust_type' => 'bogus', 'cust_sig' => $sig], self::LINK));
        self::assertNull(RequestSignals::signedCustomer(['cust_sig' => $sig], self::LINK));
        self::assertNull(RequestSignals::signedCustomer(['cust' => ['a'], 'cust_sig' => $sig], self::LINK));
    }

    public function testHttpsDetection(): void
    {
        self::assertTrue(RequestSignals::requestIsHttps(['HTTPS' => 'on']));
        self::assertFalse(RequestSignals::requestIsHttps(['HTTPS' => 'off']));
        self::assertFalse(RequestSignals::requestIsHttps([]));
        self::assertTrue(RequestSignals::requestIsHttps(['HTTP_X_FORWARDED_PROTO' => 'https']));
    }

    public function testTheSignalTypeIsInsideTheHash(): void
    {
        $key = str_repeat('11', 32);
        $asVid = IdentityGraph::hash($key, new IdentitySignal(SignalType::VISITOR_COOKIE, self::VID));
        $asLpid = IdentityGraph::hash($key, new IdentitySignal(SignalType::LANDING_PAGE, self::VID));
        self::assertNotSame($asVid, $asLpid);
        self::assertSame($asVid, IdentityGraph::hash($key, new IdentitySignal(SignalType::VISITOR_COOKIE, self::VID)));
        self::assertNotSame($asVid, IdentityGraph::hash(str_repeat('22', 32), new IdentitySignal(SignalType::VISITOR_COOKIE, self::VID)));
        self::assertStringNotContainsString(self::VID, $asVid, 'the raw value is never what is stored');
    }

    public function testAnEmptySignalIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IdentitySignal(SignalType::CUSTOMER, '');
    }

    public function testCaptureReusesTheCookieOrMintsOne(): void
    {
        $kept = ClickIdentity::fromRequest(['p202lpid' => self::LPID], ['p202vid' => self::VID], true);
        self::assertSame(self::VID, $kept->cookieValue, 'the existing cookie is refreshed, not replaced');
        self::assertSame(['vid:' . self::VID, 'lpid:' . self::LPID], self::describe($kept));

        $minted = ClickIdentity::fromRequest([], [], true);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', (string) $minted->cookieValue);
        self::assertSame(['vid:' . $minted->cookieValue], self::describe($minted));

        $garbage = ClickIdentity::fromRequest([], ['p202vid' => 'forged'], true);
        self::assertNotSame('forged', $garbage->cookieValue, 'a cookie we did not mint is replaced');
    }

    public function testACrossSiteBeaconDoesNotMint(): void
    {
        $noCookie = ClickIdentity::fromRequest(['p202lpid' => self::LPID], [], true, false);
        self::assertNull($noCookie->cookieValue);
        self::assertSame(['lpid:' . self::LPID], self::describe($noCookie));

        $withCookie = ClickIdentity::fromRequest([], ['p202vid' => self::VID], true, false);
        self::assertSame(['vid:' . self::VID], self::describe($withCookie), 'a cookie the browser did send is still used');
    }

    public function testWithheldConsentCapturesNothing(): void
    {
        $get = ['p202lpid' => self::LPID, 'cust' => 'a', 'cust_sig' => str_repeat('a', 64)];
        foreach ([
            ClickIdentity::fromRequest($get + ['p202_consent' => '0'], ['p202vid' => self::VID], true),
            ClickIdentity::fromRequest($get, ['p202vid' => self::VID], false),
            ClickIdentity::customerOnly($get + ['p202_consent' => '0']),
        ] as $identity) {
            self::assertTrue($identity->isEmpty());
            self::assertNull($identity->cookieValue);
            self::assertSame([], $identity->customerParams);
        }
    }

    public function testAConversionContributesOnlyItsCustomer(): void
    {
        $identity = ClickIdentity::customerOnly(['p202lpid' => self::LPID, 'cust' => 'a', 'cust_sig' => 'b', 'other' => 'x']);
        self::assertSame([], $identity->signals);
        self::assertNull($identity->cookieValue);
        self::assertSame(['cust' => 'a', 'cust_sig' => 'b'], $identity->customerParams);
        self::assertFalse($identity->isEmpty());
        self::assertTrue(ClickIdentity::customerOnly(['cust' => 'a'])->isEmpty(), 'unsigned: nothing to link');
    }

    public function testAnApiCallersCustomerNeedsNoSignature(): void
    {
        $identity = ClickIdentity::trustedCustomer(' ' . strtoupper(self::DIGEST) . ' ', 'email_sha256');
        self::assertSame(['cust:email_sha256:' . self::DIGEST], self::describe($identity));
        self::assertSame(['cust:custom:Acct-7'], self::describe(ClickIdentity::trustedCustomer('Acct-7')));
        self::assertTrue(ClickIdentity::trustedCustomer('   ')->isEmpty());
        self::assertTrue(ClickIdentity::trustedCustomer('x', 'email')->isEmpty());
    }

    /** @return list<string> */
    private static function describe(ClickIdentity $identity): array
    {
        return array_map(static fn (IdentitySignal $s): string => $s->type->value . ':' . $s->value, $identity->signals);
    }
}
