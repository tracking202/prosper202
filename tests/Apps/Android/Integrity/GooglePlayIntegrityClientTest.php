<?php

declare(strict_types=1);

namespace Tests\Apps\Android\Integrity;

use Api\V3\Apps\Android\Integrity\DecodeResult;
use Api\V3\Apps\Android\Integrity\GooglePlayIntegrityClient;
use PHPUnit\Framework\TestCase;

/**
 * The real Play Integrity client, over real TLS, against a local fake of
 * Google's two endpoints (tests/fixtures/play-integrity/fake_google.py):
 * the request it builds (the RS256 assertion is verified by the fake with
 * the openssl CLI against the service account's public key; the grant, the
 * path, the bearer header and the body are recorded and read back here),
 * how it parses each answer into decoded / retry / rejected, and the
 * transport rules — certificate verified, redirects not followed, a
 * timeout, a size cap, the loopback-only override.
 *
 * No request reaches Google: the sandbox and CI cannot, so the protocol is
 * what Google documents, and the fake is what is exercised.
 */
final class GooglePlayIntegrityClientTest extends TestCase
{
    private static ?FakeGoogle $google = null;
    private const PACKAGE = 'com.example.summit';

    public static function setUpBeforeClass(): void
    {
        self::$google = FakeGoogle::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$google?->stop();
        self::$google = null;
    }

    protected function setUp(): void
    {
        if (self::$google === null) {
            self::markTestSkipped('python3 and the openssl CLI are needed for the fake Google server');
        }
        self::$google->control('reset');
    }

    private function client(int $timeout = GooglePlayIntegrityClient::TIMEOUT, ?string $ca = null): GooglePlayIntegrityClient
    {
        return new GooglePlayIntegrityClient(self::$google->origin, $ca ?? self::$google->caFile, $timeout);
    }

    /** @return array<string, mixed> */
    private static function payload(): array
    {
        return [
            'requestDetails' => ['requestPackageName' => self::PACKAGE, 'requestHash' => str_repeat('ab', 32), 'timestampMillis' => '1727200100000'],
            'appIntegrity' => ['appRecognitionVerdict' => 'PLAY_RECOGNIZED', 'packageName' => self::PACKAGE, 'versionCode' => '42'],
            'deviceIntegrity' => ['deviceRecognitionVerdict' => ['MEETS_DEVICE_INTEGRITY']],
            'accountDetails' => ['appLicensingVerdict' => 'LICENSED'],
        ];
    }

    public function testADecodeSignsTheGrantThenPostsTheTokenWithTheBearer(): void
    {
        self::$google->scenario('tok-valid', [['status' => 200, 'payload' => self::payload()]]);
        $client = $this->client();
        $result = $client->decode(self::$google->credential, self::PACKAGE, 'tok-valid');
        self::assertSame(DecodeResult::DECODED, $result->kind, $result->reason);
        self::assertSame(self::payload(), $result->payload);

        $requests = self::$google->requests();
        self::assertCount(2, $requests);
        [$grant, $decode] = $requests;
        self::assertSame('/token', $grant['path']);
        self::assertSame('application/x-www-form-urlencoded', $grant['content_type']);
        self::assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $grant['grant_type']);
        self::assertNull($grant['jwt_problem'], 'the fake verified the RS256 signature and the claims with openssl');
        self::assertSame(self::$google->credential->clientEmail, $grant['jwt_claims']['iss']);
        self::assertSame('https://www.googleapis.com/auth/playintegrity', $grant['jwt_claims']['scope']);
        self::assertSame('https://oauth2.googleapis.com/token', $grant['jwt_claims']['aud'], 'the audience is Google\'s token endpoint, whatever origin served it');
        self::assertSame(3600, $grant['jwt_claims']['exp'] - $grant['jwt_claims']['iat']);

        self::assertSame('/v1/com.example.summit:decodeIntegrityToken', $decode['path']);
        self::assertSame('Bearer fake-access-1', $decode['authorization']);
        self::assertSame('application/json', $decode['content_type']);
        self::assertSame('tok-valid', $decode['integrity_token']);

        // The access token is reused until it nears expiry.
        $client->decode(self::$google->credential, self::PACKAGE, 'tok-valid');
        self::assertCount(1, array_filter(self::$google->requests(), static fn (array $r): bool => $r['path'] === '/token'));
    }

    public function testEachAnswerIsDecodedRetriedOrRejected(): void
    {
        $cases = [
            'unknown' => [null, DecodeResult::REJECTED, 400, 'could not decode'],
            'tok-503' => [[['status' => 503, 'json' => ['error' => ['code' => 503, 'message' => 'The service is currently unavailable.', 'status' => 'UNAVAILABLE']]]], DecodeResult::RETRY, 503, 'currently unavailable'],
            'tok-500' => [[['status' => 500, 'body' => 'oops']], DecodeResult::RETRY, 500, 'answered 500'],
            'tok-429' => [[['status' => 429, 'json' => ['error' => ['code' => 429, 'message' => 'Quota exceeded', 'status' => 'RESOURCE_EXHAUSTED']]]], DecodeResult::RETRY, 429, 'never waved through'],
            'tok-403' => [[['status' => 403, 'json' => ['error' => ['code' => 403, 'message' => 'caller does not have permission', 'status' => 'PERMISSION_DENIED']]]], DecodeResult::RETRY, 403, 'link the Google Cloud project'],
            'tok-empty' => [[['status' => 200, 'json' => ['somethingElse' => 1]]], DecodeResult::RETRY, 200, 'without a tokenPayloadExternal'],
            'tok-list' => [[['status' => 200, 'json' => ['tokenPayloadExternal' => [1, 2]]]], DecodeResult::RETRY, 200, 'without a tokenPayloadExternal'],
            'tok-html' => [[['status' => 200, 'body' => '<html>proxy</html>']], DecodeResult::RETRY, 200, 'without a tokenPayloadExternal'],
        ];
        $client = $this->client();
        foreach ($cases as $token => [$responses, $kind, $status, $says]) {
            if ($responses !== null) {
                self::$google->scenario($token, $responses);
            }
            $result = $client->decode(self::$google->credential, self::PACKAGE, $token);
            self::assertSame([$kind, $status], [$result->kind, $result->httpStatus], $token . ': ' . $result->reason);
            self::assertStringContainsString($says, $result->reason, $token);
        }
    }

    public function testARedirectIsNeverFollowed(): void
    {
        self::$google->scenario('tok-302', [['status' => 302, 'headers' => ['Location' => self::$google->origin . '/v1/elsewhere:decodeIntegrityToken'], 'json' => []]]);
        $result = $this->client()->decode(self::$google->credential, self::PACKAGE, 'tok-302');
        self::assertSame([DecodeResult::RETRY, 302], [$result->kind, $result->httpStatus]);
        self::assertStringContainsString('never followed', $result->reason);
        self::assertSame([], array_values(array_filter(self::$google->requests(), static fn (array $r): bool => str_contains($r['path'], 'elsewhere'))), 'nothing went to the Location');
    }

    public function testATimeoutIsARetry(): void
    {
        self::$google->scenario('tok-slow', [['status' => 200, 'payload' => self::payload(), 'delay' => 4]]);
        $started = microtime(true);
        $result = $this->client(2)->decode(self::$google->credential, self::PACKAGE, 'tok-slow');
        self::assertSame(DecodeResult::RETRY, $result->kind, $result->reason);
        self::assertStringContainsString('timed out', strtolower($result->reason));
        self::assertLessThan(3.9, microtime(true) - $started, 'the client gave up at its own timeout');
    }

    public function testAnOversizedAnswerIsARetryNotARead(): void
    {
        self::$google->scenario('tok-huge', [['status' => 200, 'body' => '{"tokenPayloadExternal": {"x": "' . str_repeat('a', 70000) . '"}}']]);
        $result = $this->client()->decode(self::$google->credential, self::PACKAGE, 'tok-huge');
        self::assertSame(DecodeResult::RETRY, $result->kind);
        self::assertStringContainsString('exceeded 65536 bytes', $result->reason);
    }

    public function testTheCertificateIsVerified(): void
    {
        // Another self-signed CA: the fake's certificate does not chain to it.
        [$pem] = FakeGoogle::rsaKey();
        $dir = sys_get_temp_dir() . '/p202-other-ca-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $key = openssl_pkey_get_private($pem);
        $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key);
        $cert = openssl_csr_sign($csr, null, $key, 1);
        openssl_x509_export($cert, $out);
        file_put_contents($dir . '/ca.pem', $out);

        self::$google->scenario('tok-valid', [['status' => 200, 'payload' => self::payload()]]);
        $result = $this->client(ca: $dir . '/ca.pem')->decode(self::$google->credential, self::PACKAGE, 'tok-valid');
        FakeGoogle::remove($dir);
        self::assertSame(DecodeResult::RETRY, $result->kind);
        self::assertMatchesRegularExpression('/certificate|SSL/i', $result->reason);
        self::assertSame([], self::$google->requests(), 'no request completed over the unverified connection');
    }

    public function testACredentialGoogleRefusesIsARetryNamingTheAccount(): void
    {
        $result = $this->client()->decode(FakeGoogle::strangerCredential(), self::PACKAGE, 'tok-valid');
        self::assertSame([DecodeResult::RETRY, 400], [$result->kind, $result->httpStatus]);
        self::assertStringContainsString('Google refused the service account integrity@p202-test-project.iam.gserviceaccount.com', $result->reason);
        self::assertStringContainsString('invalid_grant', $result->reason);
        self::assertSame('Invalid JWT Signature.', self::$google->requests()[0]['jwt_problem']);
    }

    public function testA401DropsTheCachedAccessTokenSoTheNextAttemptMintsANewOne(): void
    {
        self::$google->scenario('tok-401', [['status' => 401, 'json' => ['error' => ['code' => 401, 'message' => 'expired', 'status' => 'UNAUTHENTICATED']]],
            ['status' => 200, 'payload' => self::payload()]]);
        $client = $this->client();
        self::assertSame(DecodeResult::RETRY, $client->decode(self::$google->credential, self::PACKAGE, 'tok-401')->kind);
        self::assertSame(DecodeResult::DECODED, $client->decode(self::$google->credential, self::PACKAGE, 'tok-401')->kind);
        self::assertCount(2, array_filter(self::$google->requests(), static fn (array $r): bool => $r['path'] === '/token'));
    }

    public function testATokenEndpointOutageIsARetry(): void
    {
        self::$google->control('token-endpoint', ['responses' => [['status' => 503, 'body' => 'down']]]);
        $result = $this->client()->decode(self::$google->credential, self::PACKAGE, 'tok-valid');
        self::assertSame([DecodeResult::RETRY, 503], [$result->kind, $result->httpStatus]);
        self::assertCount(0, array_filter(self::$google->requests(), static fn (array $r): bool => str_contains($r['path'], 'decode')), 'no decode without an access token');
    }

    public function testANameThatIsNotAPackageIsNeverSentToGoogle(): void
    {
        $result = $this->client()->decode(self::$google->credential, '../../etc', 'tok-valid');
        self::assertSame(DecodeResult::REJECTED, $result->kind);
        self::assertSame([], self::$google->requests());
    }

    /** @dataProvider refusedOverrides */
    public function testTheOverrideIsLoopbackHttpsOnly(string $origin): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GooglePlayIntegrityClient($origin, null);
    }

    /** @return array<string, array{string}> */
    public static function refusedOverrides(): array
    {
        return [
            'another host' => ['https://evil.example:8241'],
            'plain http' => ['http://127.0.0.1:8241'],
            'a path' => ['https://127.0.0.1:8241/v1'],
            'credentials' => ['https://user:pw@127.0.0.1:8241'],
            'a private address' => ['https://10.0.0.5:8241'],
            'not a url' => ['127.0.0.1:8241'],
        ];
    }

    public function testACaFileWithoutTheOverrideIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GooglePlayIntegrityClient(null, '/tmp/ca.pem');
    }
}
