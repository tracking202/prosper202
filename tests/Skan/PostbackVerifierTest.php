<?php

declare(strict_types=1);

namespace Tests\Skan;

use Api\V3\Skan\PostbackVerifier;
use Tests\TestCase;

/**
 * The verifier is checked against real ECDSA P-256 signatures: each test
 * signs the exact message Apple documents for the version under test with a
 * locally generated key and asserts the verifier reconstructs the same bytes
 * (message equality) and accepts/rejects accordingly (signature validity).
 * Apple's own published key is asserted to parse as P-256, and to reject
 * fixtures signed with any other key.
 */
final class PostbackVerifierTest extends TestCase
{
    private const SEP = "\u{2063}";

    private static ?\OpenSSLAsymmetricKey $key = null;
    private static string $publicKeyB64 = '';

    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        if ($key === false) {
            self::fail('Could not generate a P-256 key: ' . (string)openssl_error_string());
        }
        self::$key = $key;
        $details = openssl_pkey_get_details($key);
        if ($details === false) {
            self::fail('Could not read generated key details');
        }
        self::$publicKeyB64 = str_replace(
            ['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\n"],
            '',
            $details['key']
        );
    }

    private function verifier(): PostbackVerifier
    {
        return new PostbackVerifier(self::$publicKeyB64);
    }

    private function sign(string $message): string
    {
        $signature = '';
        $this->assertNotNull(self::$key);
        $this->assertTrue(openssl_sign($message, $signature, self::$key, OPENSSL_ALGO_SHA256), 'signing failed');
        return base64_encode($signature);
    }

    /** @return array<string, mixed> */
    private function v4WinningPostback(): array
    {
        return [
            'version' => '4.0',
            'ad-network-id' => 'example123.skadnetwork',
            'source-identifier' => '5239',
            'app-id' => 525463029,
            'transaction-id' => '6aafb7a5-0170-41b5-bbe4-fe71dedf1e28',
            'redownload' => false,
            'source-app-id' => 1234567891,
            'fidelity-type' => 1,
            'did-win' => true,
            'conversion-value' => 63,
            'postback-sequence-index' => 0,
        ];
    }

    private function v4WinningMessage(): string
    {
        // Hand-built from Apple's documented example string for version 4.0:
        // version, ad-network-id, source-identifier, app-id, transaction-id,
        // redownload, source-app-id, fidelity-type, did-win,
        // postback-sequence-index — joined with U+2063.
        return implode(self::SEP, [
            '4.0', 'example123.skadnetwork', '5239', '525463029',
            '6aafb7a5-0170-41b5-bbe4-fe71dedf1e28', 'false', '1234567891', '1', 'true', '0',
        ]);
    }

    public function testSeparatorIsTheInvisibleSeparator(): void
    {
        // U+2063 INVISIBLE SEPARATOR is the UTF-8 bytes E2 81 A3. A wrong
        // constant here would make every real Apple postback verify invalid.
        $this->assertSame('e281a3', bin2hex(self::SEP));
    }

    public function testV4MessageMatchesAppleDocumentedComposition(): void
    {
        $this->assertSame(
            $this->v4WinningMessage(),
            $this->verifier()->buildSignedMessage($this->v4WinningPostback())
        );
    }

    public function testV4WinningPostbackVerifies(): void
    {
        $postback = $this->v4WinningPostback();
        $postback['attribution-signature'] = $this->sign($this->v4WinningMessage());
        $this->assertSame(PostbackVerifier::RESULT_VALID, $this->verifier()->verify($postback));
    }

    public function testV4WebAdUsesSourceDomainInTheMessage(): void
    {
        $postback = $this->v4WinningPostback();
        unset($postback['source-app-id']);
        $postback['source-domain'] = 'example.com';

        $message = implode(self::SEP, [
            '4.0', 'example123.skadnetwork', '5239', '525463029',
            '6aafb7a5-0170-41b5-bbe4-fe71dedf1e28', 'false', 'example.com', '1', 'true', '0',
        ]);
        $this->assertSame($message, $this->verifier()->buildSignedMessage($postback));

        $postback['attribution-signature'] = $this->sign($message);
        $this->assertSame(PostbackVerifier::RESULT_VALID, $this->verifier()->verify($postback));
    }

    public function testV4LosingPostbackWithoutSourceVerifies(): void
    {
        // Losing postbacks omit source-app-id/source-domain and conversion
        // values; the message simply skips the source slot.
        $postback = [
            'version' => '4.0',
            'ad-network-id' => 'loser.skadnetwork',
            'source-identifier' => '42',
            'app-id' => 525463029,
            'transaction-id' => 'aaaa-bbbb',
            'redownload' => true,
            'fidelity-type' => 0,
            'did-win' => false,
            'postback-sequence-index' => 2,
        ];
        $message = implode(self::SEP, [
            '4.0', 'loser.skadnetwork', '42', '525463029', 'aaaa-bbbb', 'true', '0', 'false', '2',
        ]);
        $this->assertSame($message, $this->verifier()->buildSignedMessage($postback));

        $postback['attribution-signature'] = $this->sign($message);
        $this->assertSame(PostbackVerifier::RESULT_VALID, $this->verifier()->verify($postback));
    }

    public function testTamperingWithASignedFieldInvalidatesThePostback(): void
    {
        $postback = $this->v4WinningPostback();
        $postback['attribution-signature'] = $this->sign($this->v4WinningMessage());

        foreach ([
            'source-identifier' => '9999',
            'app-id' => 999999999,
            'did-win' => false,
            'redownload' => true,
            'postback-sequence-index' => 1,
            'ad-network-id' => 'attacker.skadnetwork',
        ] as $field => $value) {
            $tampered = $postback;
            $tampered[$field] = $value;
            $this->assertSame(
                PostbackVerifier::RESULT_INVALID,
                $this->verifier()->verify($tampered),
                "tampered $field must not verify"
            );
        }
    }

    public function testConversionValuesAreNotCoveredByTheSignature(): void
    {
        // A documented SKAN property, pinned so nobody "fixes" the message
        // composition by adding the conversion value to it: Apple does not
        // sign conversion-value or coarse-conversion-value in any version.
        $postback = $this->v4WinningPostback();
        $postback['attribution-signature'] = $this->sign($this->v4WinningMessage());

        $postback['conversion-value'] = 1;
        $this->assertSame(PostbackVerifier::RESULT_VALID, $this->verifier()->verify($postback));

        unset($postback['conversion-value']);
        $postback['coarse-conversion-value'] = 'high';
        $this->assertSame(PostbackVerifier::RESULT_VALID, $this->verifier()->verify($postback));
    }

    public function testV3CompositionWithAndWithoutSourceAppId(): void
    {
        $withSource = [
            'version' => '3.0',
            'ad-network-id' => 'example123.skadnetwork',
            'campaign-id' => 42,
            'app-id' => 525463029,
            'transaction-id' => 'tx-3',
            'redownload' => true,
            'source-app-id' => 1234567891,
            'fidelity-type' => 1,
            'did-win' => true,
        ];
        $message = implode(self::SEP, [
            '3.0', 'example123.skadnetwork', '42', '525463029', 'tx-3', 'true', '1234567891', '1', 'true',
        ]);
        $this->assertSame($message, $this->verifier()->buildSignedMessage($withSource));
        $withSource['attribution-signature'] = $this->sign($message);
        $this->assertSame(PostbackVerifier::RESULT_VALID, $this->verifier()->verify($withSource));

        $withoutSource = $withSource;
        unset($withoutSource['source-app-id'], $withoutSource['attribution-signature']);
        $withoutSource['did-win'] = false;
        $message = implode(self::SEP, [
            '3.0', 'example123.skadnetwork', '42', '525463029', 'tx-3', 'true', '1', 'false',
        ]);
        $this->assertSame($message, $this->verifier()->buildSignedMessage($withoutSource));
        $withoutSource['attribution-signature'] = $this->sign($message);
        $this->assertSame(PostbackVerifier::RESULT_VALID, $this->verifier()->verify($withoutSource));
    }

    public function testV21AndV22Composition(): void
    {
        $v21 = [
            'version' => '2.1',
            'ad-network-id' => 'n.skadnetwork',
            'campaign-id' => 7,
            'app-id' => 1,
            'transaction-id' => 't21',
            'redownload' => false,
        ];
        $message21 = implode(self::SEP, ['2.1', 'n.skadnetwork', '7', '1', 't21', 'false']);
        $this->assertSame($message21, $this->verifier()->buildSignedMessage($v21));
        $v21['attribution-signature'] = $this->sign($message21);
        $this->assertSame(PostbackVerifier::RESULT_VALID, $this->verifier()->verify($v21));

        // 2.2 appends fidelity-type to the 2.1 composition.
        $v22 = $v21;
        unset($v22['attribution-signature']);
        $v22['version'] = '2.2';
        $v22['fidelity-type'] = 0;
        $message22 = implode(self::SEP, ['2.2', 'n.skadnetwork', '7', '1', 't21', 'false', '0']);
        $this->assertSame($message22, $this->verifier()->buildSignedMessage($v22));
        $v22['attribution-signature'] = $this->sign($message22);
        $this->assertSame(PostbackVerifier::RESULT_VALID, $this->verifier()->verify($v22));
    }

    public function testUnknownAndRetiredVersionsAreUnverifiableNotInvalid(): void
    {
        // 1.0/2.0 used a retired P-192 key; versions beyond 4.0 have an
        // unknown composition. Both must read as "cannot judge", never as
        // a definite verdict in either direction.
        foreach (['1.0', '2.0', '5.0'] as $version) {
            $postback = $this->v4WinningPostback();
            $postback['version'] = $version;
            $postback['attribution-signature'] = 'AA==';
            $this->assertSame(
                PostbackVerifier::RESULT_UNVERIFIABLE,
                $this->verifier()->verify($postback),
                "version $version"
            );
        }

        $noVersion = $this->v4WinningPostback();
        unset($noVersion['version']);
        $noVersion['attribution-signature'] = 'AA==';
        $this->assertSame(PostbackVerifier::RESULT_UNVERIFIABLE, $this->verifier()->verify($noVersion));
    }

    public function testClaimedVersionMissingItsSignedFieldsIsInvalid(): void
    {
        // Apple always sends the signed fields, so a payload claiming 4.0
        // without them cannot be genuine.
        foreach (['did-win', 'postback-sequence-index', 'fidelity-type', 'redownload', 'source-identifier'] as $field) {
            $postback = $this->v4WinningPostback();
            $postback['attribution-signature'] = $this->sign($this->v4WinningMessage());
            unset($postback[$field]);
            $this->assertSame(
                PostbackVerifier::RESULT_INVALID,
                $this->verifier()->verify($postback),
                "missing $field"
            );
        }
    }

    public function testMistypedFieldsVoidTheMessageInsteadOfCoercing(): void
    {
        // "app-id": "525463029" (string) must not silently verify as the
        // integer message — coercion would let a payload verify while
        // reading differently than what was signed.
        $postback = $this->v4WinningPostback();
        $postback['app-id'] = '525463029';
        $this->assertNull($this->verifier()->buildSignedMessage($postback));

        $postback = $this->v4WinningPostback();
        $postback['did-win'] = 'true';
        $this->assertNull($this->verifier()->buildSignedMessage($postback));
    }

    public function testMalformedSignatureIsInvalid(): void
    {
        $postback = $this->v4WinningPostback();

        $postback['attribution-signature'] = '!!!not-base64!!!';
        $this->assertSame(PostbackVerifier::RESULT_INVALID, $this->verifier()->verify($postback));

        $postback['attribution-signature'] = '';
        $this->assertSame(PostbackVerifier::RESULT_INVALID, $this->verifier()->verify($postback));

        unset($postback['attribution-signature']);
        $this->assertSame(PostbackVerifier::RESULT_INVALID, $this->verifier()->verify($postback));
    }

    public function testTheDefaultVerifierUsesApplesKeyNotOurs(): void
    {
        // A fixture signed with the test key must fail against the default
        // (Apple-key) verifier — proof the configured key participates.
        $postback = $this->v4WinningPostback();
        $postback['attribution-signature'] = $this->sign($this->v4WinningMessage());
        $this->assertSame(PostbackVerifier::RESULT_INVALID, (new PostbackVerifier())->verify($postback));
    }

    public function testApplesPublishedKeyParsesAsP256(): void
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(PostbackVerifier::APPLE_PUBLIC_KEY_B64, 64, "\n")
            . "-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        $this->assertNotFalse($key, 'Apple key must parse: ' . (string)openssl_error_string());
        $details = openssl_pkey_get_details($key);
        $this->assertNotFalse($details);
        $this->assertSame(256, $details['bits']);
        $this->assertSame('prime256v1', $details['ec']['curve_name'] ?? null);
    }
}
