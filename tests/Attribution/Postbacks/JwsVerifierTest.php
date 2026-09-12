<?php

declare(strict_types=1);

namespace Tests\Attribution\Postbacks;

use Api\V3\Attribution\JwsVerifier;
use Api\V3\Attribution\SignatureState;
use Api\V3\Attribution\PostbackVerifier;
use Tests\TestCase;

/**
 * The JWS verifier against Apple's own example (a genuine development
 * signature), against forgeries derived from it, and against signatures
 * made here with a fresh P-256 key — the last exercising the R||S-to-DER
 * re-encoding on random r and s values that OpenSSL then has to accept.
 */
final class JwsVerifierTest extends TestCase
{
    /** @return array{header: array<string, mixed>, payload: array<string, mixed>, signature: string, signing_input: string} */
    private function decodedExample(): array
    {
        $decoded = JwsVerifier::decode(AdAttributionKitFixtures::EXAMPLE_JWS);
        $this->assertIsArray($decoded, is_string($decoded) ? $decoded : '');
        return $decoded;
    }

    /** The three segments of Apple's example, for re-assembly with one part changed. */
    private static function exampleSegments(): array
    {
        return explode('.', AdAttributionKitFixtures::EXAMPLE_JWS);
    }

    public function testTheProductionKeyIsTheSkadnetworkKey(): void
    {
        // Apple publishes one production key for both frameworks; the
        // constant is shared so the two verifiers cannot drift apart.
        $this->assertSame(PostbackVerifier::APPLE_PUBLIC_KEY_B64, JwsVerifier::APPLE_KEYS[JwsVerifier::PRODUCTION_KEY_ID]);
        foreach (JwsVerifier::DEVELOPMENT_KEY_IDS as $kid) {
            $this->assertArrayHasKey($kid, JwsVerifier::APPLE_KEYS);
            $this->assertNotSame(JwsVerifier::APPLE_KEYS[JwsVerifier::PRODUCTION_KEY_ID], JwsVerifier::APPLE_KEYS[$kid]);
        }
    }

    public function testApplesDocumentedExampleVerifiesAgainstTheDevelopmentKeyItNames(): void
    {
        $decoded = $this->decodedExample();
        $this->assertSame(['kid' => AdAttributionKitFixtures::EXAMPLE_KEY_ID, 'alg' => 'ES256'], $decoded['header']);
        $this->assertSame(AdAttributionKitFixtures::examplePayload(), $decoded['payload']);
        $this->assertSame(64, strlen($decoded['signature']), 'ES256 signatures are raw R||S');

        $this->assertSame(SignatureState::DEVELOPMENT, (new JwsVerifier())->verify($decoded));
    }

    public function testATamperedPayloadIsInvalid(): void
    {
        [$header, , $signature] = self::exampleSegments();
        $payload = AdAttributionKitFixtures::examplePayload();
        $payload['conversion-type'] = 'download';
        $tampered = $header . '.' . AdAttributionKitFixtures::base64Url((string)json_encode($payload)) . '.' . $signature;

        $decoded = JwsVerifier::decode($tampered);
        $this->assertIsArray($decoded);
        $this->assertSame(SignatureState::INVALID, (new JwsVerifier())->verify($decoded));
    }

    public function testADevelopmentSignatureCannotBeRelabelledAsProduction(): void
    {
        // Editing kid to the production key changes the signing input and
        // names a key that never made this signature: a forger cannot
        // promote a development-signed postback by renaming the key.
        [, $payload, $signature] = self::exampleSegments();
        $relabelled = AdAttributionKitFixtures::base64Url((string)json_encode([
            'kid' => JwsVerifier::PRODUCTION_KEY_ID,
            'alg' => 'ES256',
        ])) . '.' . $payload . '.' . $signature;

        $decoded = JwsVerifier::decode($relabelled);
        $this->assertIsArray($decoded);
        $this->assertSame(SignatureState::INVALID, (new JwsVerifier())->verify($decoded));
    }

    public function testAnUnknownKeyIdIsUnverifiableNotInvalid(): void
    {
        [, $payload, $signature] = self::exampleSegments();
        $unknown = AdAttributionKitFixtures::base64Url((string)json_encode([
            'kid' => 'apple-cas-identifier/9',
            'alg' => 'ES256',
        ])) . '.' . $payload . '.' . $signature;

        $decoded = JwsVerifier::decode($unknown);
        $this->assertIsArray($decoded);
        $this->assertSame(SignatureState::UNVERIFIABLE, (new JwsVerifier())->verify($decoded));
    }

    /**
     * @dataProvider forgedHeaders
     * @param array<string, mixed> $header
     */
    public function testTheHeaderNeverChoosesTheAlgorithm(array $header): void
    {
        // The classic JWS hole: honouring alg from the header lets "none"
        // or an HMAC over the public key pass. ES256 is required whatever
        // the header says, and a header that asks for anything else is a
        // forgery attempt — invalid, not "cannot judge".
        [, $payload] = self::exampleSegments();
        $jws = AdAttributionKitFixtures::base64Url((string)json_encode($header)) . '.' . $payload . '.AA';
        $decoded = JwsVerifier::decode($jws);
        $this->assertIsArray($decoded);
        $this->assertSame(SignatureState::INVALID, (new JwsVerifier())->verify($decoded));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function forgedHeaders(): array
    {
        return [
            'alg none' => [['kid' => AdAttributionKitFixtures::EXAMPLE_KEY_ID, 'alg' => 'none']],
            'alg HS256' => [['kid' => AdAttributionKitFixtures::EXAMPLE_KEY_ID, 'alg' => 'HS256']],
            'alg ES384' => [['kid' => AdAttributionKitFixtures::EXAMPLE_KEY_ID, 'alg' => 'ES384']],
            'crit extension' => [['kid' => AdAttributionKitFixtures::EXAMPLE_KEY_ID, 'alg' => 'ES256', 'crit' => ['exp']]],
        ];
    }

    public function testASignatureOfTheWrongLengthIsInvalid(): void
    {
        [$header, $payload] = self::exampleSegments();
        $decoded = JwsVerifier::decode($header . '.' . $payload . '.' . AdAttributionKitFixtures::base64Url('short'));
        $this->assertIsArray($decoded);
        $this->assertSame(SignatureState::INVALID, (new JwsVerifier())->verify($decoded));
    }

    public function testAKeyThatWillNotLoadOutranksASignatureOfTheWrongLength(): void
    {
        // Two facts hold at once here: the verification key is broken and
        // the signature segment is not 64 bytes. An installation that
        // cannot load its own key is in no position to call anything
        // forged, so the environment problem has to win — EcdsaP256 runs
        // its checks before it looks at the signature, and the not-64-bytes
        // rejection must stay behind them.
        [$header, $payload] = self::exampleSegments();
        $decoded = JwsVerifier::decode($header . '.' . $payload . '.' . AdAttributionKitFixtures::base64Url('short'));
        $this->assertIsArray($decoded);

        $brokenKey = new JwsVerifier([AdAttributionKitFixtures::EXAMPLE_KEY_ID => 'not-a-key'], []);
        $this->assertSame(SignatureState::UNVERIFIABLE, $brokenKey->verify($decoded));
    }

    /**
     * @dataProvider structuralGarbage
     */
    public function testDecodeNamesWhyAStringIsNotAJws(string $jws, string $expectedFragment): void
    {
        $result = JwsVerifier::decode($jws);
        $this->assertIsString($result, 'must be rejected: ' . $jws);
        $this->assertStringContainsString($expectedFragment, $result);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function structuralGarbage(): array
    {
        [$header, $payload, $signature] = self::exampleSegments();
        $b64 = [AdAttributionKitFixtures::class, 'base64Url'];
        return [
            'two segments' => ["$header.$payload", 'three'],
            'four segments' => ["$header.$payload.$signature.extra", 'three'],
            'empty segment' => [".$payload.$signature", 'Segment 1'],
            'padding characters' => ["$header=.$payload.$signature", 'Segment 1'],
            'plus and slash' => ["$header.ab+/cd.$signature", 'Segment 2'],
            'header is a list' => [$b64('[1,2]') . ".$payload.$signature", 'Header'],
            'header is a string' => [$b64('"x"') . ".$payload.$signature", 'Header'],
            'header without kid' => [$b64('{"alg":"ES256"}') . ".$payload.$signature", 'kid'],
            'header with numeric alg' => [$b64('{"alg":1,"kid":"k"}') . ".$payload.$signature", 'alg'],
            'payload not json' => ["$header." . $b64('not json') . ".$signature", 'Payload'],
            'payload is a list' => ["$header." . $b64('[]') . ".$signature", 'Payload'],
            'impossible base64 length' => ["$header.$payload.abcde", 'Signature'],
        ];
    }

    public function testASelfSignedJwsVerifiesAsValidOrDevelopmentDependingOnTheKeySet(): void
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $this->assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        $this->assertNotFalse($details);
        $publicKeyB64 = str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\n"], '', $details['key']);

        $asProduction = new JwsVerifier(['test/0' => $publicKeyB64], []);
        $asDevelopment = new JwsVerifier(['test/0' => $publicKeyB64], ['test/0']);
        $wrongKey = new JwsVerifier(['test/0' => JwsVerifier::APPLE_KEYS[JwsVerifier::PRODUCTION_KEY_ID]], []);

        $header = AdAttributionKitFixtures::base64Url('{"kid":"test/0","alg":"ES256"}');
        $payload = AdAttributionKitFixtures::base64Url((string)json_encode(AdAttributionKitFixtures::examplePayload()));
        $signingInput = "$header.$payload";

        // ECDSA signatures are randomized, so each iteration produces new
        // r and s — including ones with the top bit set or leading zero
        // bytes, the cases the DER re-encoding has to get right.
        for ($i = 0; $i < 12; $i++) {
            $der = '';
            $this->assertTrue(openssl_sign($signingInput, $der, $key, OPENSSL_ALGO_SHA256));
            $jws = $signingInput . '.' . AdAttributionKitFixtures::base64Url(self::derToRaw($der));

            $decoded = JwsVerifier::decode($jws);
            $this->assertIsArray($decoded);
            $this->assertSame(SignatureState::VALID, $asProduction->verify($decoded), "iteration $i");
            $this->assertSame(SignatureState::DEVELOPMENT, $asDevelopment->verify($decoded), "iteration $i");
            $this->assertSame(SignatureState::INVALID, $wrongKey->verify($decoded), "iteration $i");
        }
    }

    public function testRawSignatureToDerEncodesMinimalPositiveIntegers(): void
    {
        // r has its top bit set (needs a 0x00 prefix to stay positive); s
        // is 1 with 31 leading zero bytes (minimal encoding drops them).
        $r = "\x80" . str_repeat("\x01", 31);
        $s = str_repeat("\x00", 31) . "\x01";
        $der = JwsVerifier::rawSignatureToDer($r . $s);
        $this->assertNotNull($der);
        $this->assertSame(
            '30' . '26'
            . '02' . '21' . '00' . '80' . str_repeat('01', 31)
            . '02' . '01' . '01',
            bin2hex($der)
        );

        // All-zero s encodes as the single byte 0x00, not as nothing.
        $der = JwsVerifier::rawSignatureToDer(str_repeat("\x01", 32) . str_repeat("\x00", 32));
        $this->assertNotNull($der);
        $this->assertStringEndsWith('020100', bin2hex($der));

        $this->assertNull(JwsVerifier::rawSignatureToDer(str_repeat("\x01", 63)));
        $this->assertNull(JwsVerifier::rawSignatureToDer(str_repeat("\x01", 65)));
        $this->assertNull(JwsVerifier::rawSignatureToDer(''));
    }

    /**
     * DER ECDSA-Sig-Value → raw R||S (32 bytes each), the inverse of what
     * the verifier does. P-256 signatures always use short-form lengths.
     */
    private static function derToRaw(string $der): string
    {
        $offset = 2; // 0x30, sequence length
        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            if (ord($der[$offset]) !== 0x02) {
                throw new \RuntimeException('expected INTEGER');
            }
            $length = ord($der[$offset + 1]);
            $value = ltrim(substr($der, $offset + 2, $length), "\x00");
            $parts[] = str_pad($value, 32, "\x00", STR_PAD_LEFT);
            $offset += 2 + $length;
        }
        return $parts[0] . $parts[1];
    }
}
