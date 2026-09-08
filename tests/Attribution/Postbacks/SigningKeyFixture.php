<?php

declare(strict_types=1);

namespace Tests\Attribution\Postbacks;

use Api\V3\Attribution\PostbackVerifier;

/**
 * A throwaway P-256 key pair for tests that need a SKAdNetwork postback
 * whose signature VERIFIES: fixtures are signed with the private half and
 * the verifier under test is constructed with the public half in place of
 * Apple's key. Generated once per test class.
 */
trait SigningKeyFixture
{
    private static ?\OpenSSLAsymmetricKey $signingKey = null;
    private static string $publicKeyB64 = '';

    private static function generateSigningKey(): void
    {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        if ($key === false) {
            self::fail('Could not generate a P-256 key');
        }
        self::$signingKey = $key;
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

    /** A verifier that trusts the fixture key instead of Apple's. */
    private static function fixtureVerifier(): PostbackVerifier
    {
        return new PostbackVerifier(self::$publicKeyB64);
    }

    /**
     * Sign a postback (without its attribution-signature) with the fixture
     * key, returning it with the signature attached.
     *
     * @param array<string, mixed> $postback
     * @return array<string, mixed>
     */
    private function signPostback(array $postback): array
    {
        $message = self::fixtureVerifier()->buildSignedMessage($postback);
        $this->assertNotNull($message, 'the fixture must carry every field its version signs over');
        $signature = '';
        $this->assertNotNull(self::$signingKey);
        $this->assertTrue(openssl_sign($message, $signature, self::$signingKey, OPENSSL_ALGO_SHA256));
        $postback['attribution-signature'] = base64_encode($signature);
        return $postback;
    }
}
