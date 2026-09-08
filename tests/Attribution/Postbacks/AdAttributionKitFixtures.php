<?php

declare(strict_types=1);

namespace Tests\Attribution\Postbacks;

/**
 * AdAttributionKit fixtures shared by the verifier, protocol and receiver
 * tests.
 *
 * EXAMPLE_JWS is the postback Apple prints in "Identifying the parameters
 * in a postback" (AdAttributionKit), copied verbatim. It is a genuine
 * signature made with Apple's apple-development-identifier/1 key — the one
 * postbacks generated from a device's Developer settings carry — so it is
 * the one fixture whose verification proves the real key, the real
 * signing-input rule and the real R||S-to-DER re-encoding all agree with
 * Apple, not just with this code's own signer.
 */
final class AdAttributionKitFixtures
{
    public const EXAMPLE_JWS = 'eyJraWQiOiJhcHBsZS1kZXZlbG9wbWVudC1pZGVudGlmaWVyXC8xIiwiYWxnIjoiRVMyNTYifQ.'
        . 'eyJwb3N0YmFjay1pZGVudGlmaWVyIjoiODU1NDZFQjctRkQzOS00NEJDLTg5OTAtQzk4QTRBQzM2QTQ5IiwicHVibGlzaGVyLWl0ZW0taWRlbnRpZmllciI6MCwibWFya2V0cGxhY2UtaWRlbnRpZmllciI6ImNvbS5hcHBsZS5BcHBTdG9yZSIsImltcHJlc3Npb24tdHlwZSI6ImFwcC1pbXByZXNzaW9uIiwiYWQtbmV0d29yay1pZGVudGlmaWVyIjoiZGV2ZWxvcG1lbnQuYWRhdHRyaWJ1dGlvbmtpdCIsImRpZC13aW4iOnRydWUsInBvc3RiYWNrLXNlcXVlbmNlLWluZGV4IjowLCJjb252ZXJzaW9uLXR5cGUiOiJyZS1lbmdhZ2VtZW50Iiwic291cmNlLWlkZW50aWZpZXIiOiIxMjM0IiwiYWR2ZXJ0aXNlZC1pdGVtLWlkZW50aWZpZXIiOjEwNzM4MDI3NzU2fQ.'
        . 'bAdNwKd6OfHK9tofvjjua4X_JPcFTxXPQSspD9gZkinw97pY7R1aI-LSjl-oxZZF3_K2H5JK5TSEBee4_1U4oQ';

    public const EXAMPLE_KEY_ID = 'apple-development-identifier/1';
    public const EXAMPLE_POSTBACK_ID = '85546EB7-FD39-44BC-8990-C98A4AC36A49';
    public const EXAMPLE_AD_NETWORK_ID = 'development.adattributionkit';
    public const EXAMPLE_APP_ID = 10738027756;

    /**
     * The whole documented envelope: the JWS plus the unsigned fields.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function exampleBody(array $overrides = []): array
    {
        return $overrides + [
            'jws-string' => self::EXAMPLE_JWS,
            'conversion-value' => 24,
            'ad-interaction-type' => 'click',
            'country-code' => 'US',
        ];
    }

    /** The decoded payload of EXAMPLE_JWS, as Apple documents it. */
    public static function examplePayload(): array
    {
        return [
            'postback-identifier' => self::EXAMPLE_POSTBACK_ID,
            'publisher-item-identifier' => 0,
            'marketplace-identifier' => 'com.apple.AppStore',
            'impression-type' => 'app-impression',
            'ad-network-identifier' => self::EXAMPLE_AD_NETWORK_ID,
            'did-win' => true,
            'postback-sequence-index' => 0,
            'conversion-type' => 're-engagement',
            'source-identifier' => '1234',
            'advertised-item-identifier' => self::EXAMPLE_APP_ID,
        ];
    }

    public static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Assemble a compact JWS from arbitrary parts. The default signature
     * segment decodes to one byte, so unless a test signs the input itself
     * the result is structurally a JWS whose signature cannot verify.
     *
     * @param array<string, mixed> $header
     * @param array<string, mixed> $payload
     */
    public static function jws(array $header, array $payload, string $signatureSegment = 'AA'): string
    {
        // Cast to objects so an empty header or payload encodes as "{}"
        // (a JSON object with nothing in it), never as the list "[]".
        return self::base64Url((string)json_encode((object)$header)) . '.'
            . self::base64Url((string)json_encode((object)$payload)) . '.'
            . $signatureSegment;
    }
}
