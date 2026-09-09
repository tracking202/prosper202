<?php

declare(strict_types=1);

namespace Tests\Attribution\Postbacks;

use Api\V3\Attribution\AdAttributionKitProtocol;
use Api\V3\Attribution\JwsVerifier;
use Api\V3\Attribution\ParsedPostback;
use Api\V3\Attribution\PostbackReceiver;
use Api\V3\Attribution\SignatureState;
use Tests\TestCase;

/**
 * The AdAttributionKit protocol's contribution to the shared row: the
 * unsigned envelope and the signed payload validated under Apple's field
 * names, the verdict from the JWS verifier, and the mapping of Apple's
 * names onto the family's columns. The receiver's handling of the result
 * is covered by PostbackReceiverTest and the integration test.
 */
final class AdAttributionKitProtocolTest extends TestCase
{
    private function protocol(): AdAttributionKitProtocol
    {
        return new AdAttributionKitProtocol(new JwsVerifier());
    }

    /** @param array<string, mixed> $body */
    private function parsed(array $body): ParsedPostback
    {
        $result = $this->protocol()->parse($body);
        $this->assertInstanceOf(ParsedPostback::class, $result, json_encode($result) ?: '');
        return $result;
    }

    /** @param array<string, mixed> $body
     *  @return array<string, string> */
    private function errors(array $body): array
    {
        $result = $this->protocol()->parse($body);
        $this->assertIsArray($result, 'expected field errors');
        return $result;
    }

    private static function header(array $overrides = []): array
    {
        return $overrides + ['kid' => AdAttributionKitFixtures::EXAMPLE_KEY_ID, 'alg' => 'ES256'];
    }

    public function testNameAndProbeDescription(): void
    {
        $protocol = $this->protocol();
        $this->assertSame('adattributionkit', $protocol->name());
        $this->assertSame(AdAttributionKitProtocol::NAME, $protocol->name());
        $description = $protocol->describe();
        $this->assertSame('appattribution-report-attribution', $description['endpoint']);
        $this->assertStringContainsString('AdAttributionKit', $description['accepts']);
    }

    public function testApplesExampleNormalizesOntoTheSharedRow(): void
    {
        $parsed = $this->parsed(AdAttributionKitFixtures::exampleBody());

        $this->assertSame(AdAttributionKitFixtures::EXAMPLE_AD_NETWORK_ID, $parsed->adNetworkId);
        $this->assertSame(AdAttributionKitFixtures::EXAMPLE_POSTBACK_ID, $parsed->postbackId);
        $this->assertSame(AdAttributionKitFixtures::EXAMPLE_APP_ID, $parsed->appId);
        $this->assertSame(0, $parsed->sequenceIndex);
        $this->assertTrue($parsed->didWin);
        $this->assertSame(SignatureState::DEVELOPMENT, $parsed->signatureState);
        $this->assertSame(AdAttributionKitFixtures::EXAMPLE_KEY_ID, $parsed->keyId);

        $columns = $parsed->columns;
        $this->assertSame(['i', 24], $columns['conversion_value']);
        $this->assertSame(['s', null], $columns['coarse_conversion_value']);
        $this->assertSame(['s', 're-engagement'], $columns['conversion_type']);
        $this->assertSame(['s', 'click'], $columns['ad_interaction_type']);
        $this->assertSame(['s', '1234'], $columns['source_identifier']);
        $this->assertSame(['i', 0], $columns['source_app_id']);
        $this->assertSame(['s', 'com.apple.AppStore'], $columns['marketplace_id']);
        $this->assertSame(['s', 'US'], $columns['country_code']);
        $this->assertSame(['s', AdAttributionKitFixtures::EXAMPLE_JWS], $columns['attribution_signature']);

        // SKAdNetwork's own columns are left to the receiver's defaults.
        foreach (['version', 'campaign_id', 'redownload', 'fidelity_type', 'source_domain'] as $skadnetworkOnly) {
            $this->assertArrayNotHasKey($skadnetworkOnly, $columns);
        }
        $this->assertSame(
            [],
            array_intersect(array_keys($columns), PostbackReceiver::GENERIC_COLUMNS),
            'identity, ownership and trust columns are the receiver\'s to write'
        );
    }

    public function testTheUnsignedFieldsComeFromTheEnvelopeNotThePayload(): void
    {
        // Apple documents conversion-value, coarse-conversion-value,
        // ad-interaction-type and country-code beside the JWS, unsigned. A
        // payload carrying look-alike keys must not be where they are read
        // from — the row would then say what the JWS author wrote rather
        // than what the device reported.
        $payload = AdAttributionKitFixtures::examplePayload() + [
            'conversion-value' => 63,
            'country-code' => 'ZZ',
            'ad-interaction-type' => 'view',
        ];
        $body = AdAttributionKitFixtures::exampleBody([
            'jws-string' => AdAttributionKitFixtures::jws(self::header(), $payload),
        ]);
        $parsed = $this->parsed($body);
        $this->assertSame(['i', 24], $parsed->columns['conversion_value']);
        $this->assertSame(['s', 'US'], $parsed->columns['country_code']);
        $this->assertSame(['s', 'click'], $parsed->columns['ad_interaction_type']);
        $this->assertSame(SignatureState::INVALID, $parsed->signatureState, 'a re-encoded payload no longer carries Apple\'s signature');
    }

    public function testTierWithheldFieldsStoreAsNull(): void
    {
        $payload = AdAttributionKitFixtures::examplePayload();
        unset($payload['source-identifier'], $payload['publisher-item-identifier'], $payload['marketplace-identifier']);
        $parsed = $this->parsed([
            'jws-string' => AdAttributionKitFixtures::jws(self::header(), $payload),
            'ad-interaction-type' => 'view',
        ]);
        $this->assertSame(['s', null], $parsed->columns['source_identifier']);
        $this->assertSame(['i', null], $parsed->columns['source_app_id']);
        $this->assertSame(['s', null], $parsed->columns['marketplace_id']);
        $this->assertSame(['i', null], $parsed->columns['conversion_value']);
        $this->assertSame(['s', null], $parsed->columns['coarse_conversion_value']);
        $this->assertSame(['s', null], $parsed->columns['country_code']);
        $this->assertSame(['s', 'view'], $parsed->columns['ad_interaction_type']);
    }

    public function testAnUnknownKeyIsStoredUnverifiableWithTheKeyItNamed(): void
    {
        $parsed = $this->parsed(AdAttributionKitFixtures::exampleBody([
            'jws-string' => AdAttributionKitFixtures::jws(self::header(['kid' => 'apple-cas-identifier/7']), AdAttributionKitFixtures::examplePayload()),
        ]));
        $this->assertSame(SignatureState::UNVERIFIABLE, $parsed->signatureState);
        $this->assertSame('apple-cas-identifier/7', $parsed->keyId);
    }

    public function testEnvelopeErrorsUseApplesFieldNames(): void
    {
        $errors = $this->errors([]);
        $this->assertArrayHasKey('jws-string', $errors);
        $this->assertArrayHasKey('ad-interaction-type', $errors);
        $this->assertCount(2, $errors, 'nothing else is required outside the JWS');
    }

    /**
     * @dataProvider rejectedEnvelopeValues
     */
    public function testOutOfRangeEnvelopeValuesAreRejected(string $field, mixed $value): void
    {
        $body = AdAttributionKitFixtures::exampleBody();
        $body[$field] = $value;
        $errors = $this->errors($body);
        $this->assertArrayHasKey($field, $errors, "$field=" . var_export($value, true));
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function rejectedEnvelopeValues(): array
    {
        return [
            'conversion-value too large' => ['conversion-value', 64],
            'conversion-value as string' => ['conversion-value', '24'],
            'coarse value unknown' => ['coarse-conversion-value', 'huge'],
            'interaction type unknown' => ['ad-interaction-type', 'tap'],
            'interaction type as int' => ['ad-interaction-type', 1],
            'country empty' => ['country-code', ''],
            'country too long' => ['country-code', 'not-a-country'],
            'jws not a string' => ['jws-string', ['a' => 'b']],
            'jws oversized' => ['jws-string', str_repeat('a', 8193)],
        ];
    }

    /**
     * @dataProvider malformedJws
     */
    public function testAMalformedJwsIsAFieldErrorNotAStoredRow(string $jws, string $expectedFragment): void
    {
        $errors = $this->errors(AdAttributionKitFixtures::exampleBody(['jws-string' => $jws]));
        $this->assertArrayHasKey('jws-string', $errors);
        $this->assertStringContainsString($expectedFragment, $errors['jws-string']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function malformedJws(): array
    {
        return [
            'two segments' => ['a.b', 'three'],
            'not base64url' => ['a.b=.c', 'base64url'],
            'header not an object' => [AdAttributionKitFixtures::base64Url('[]') . '.' . AdAttributionKitFixtures::base64Url('{}') . '.AA', 'Header'],
            'kid oversized' => [
                AdAttributionKitFixtures::jws(self::header(['kid' => str_repeat('k', 65)]), AdAttributionKitFixtures::examplePayload()),
                'kid',
            ],
        ];
    }

    public function testPayloadErrorsUseApplesFieldNames(): void
    {
        $errors = $this->errors(AdAttributionKitFixtures::exampleBody([
            'jws-string' => AdAttributionKitFixtures::jws(self::header(), []),
        ]));
        foreach ([
            'postback-identifier', 'ad-network-identifier', 'advertised-item-identifier',
            'did-win', 'postback-sequence-index', 'conversion-type', 'impression-type',
        ] as $field) {
            $this->assertArrayHasKey($field, $errors, $field);
            $this->assertStringContainsString('JWS payload', $errors[$field]);
        }
        $this->assertArrayNotHasKey('jws-string', $errors, 'the JWS itself was well-formed');
    }

    /**
     * @dataProvider rejectedPayloadValues
     */
    public function testOutOfRangePayloadValuesAreRejected(string $field, mixed $value): void
    {
        $payload = AdAttributionKitFixtures::examplePayload();
        $payload[$field] = $value;
        $errors = $this->errors(AdAttributionKitFixtures::exampleBody([
            'jws-string' => AdAttributionKitFixtures::jws(self::header(), $payload),
        ]));
        $this->assertArrayHasKey($field, $errors, "$field=" . var_export($value, true));
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function rejectedPayloadValues(): array
    {
        return [
            'conversion-type unknown' => ['conversion-type', 'install'],
            'sequence index out of range' => ['postback-sequence-index', 3],
            'sequence index as string' => ['postback-sequence-index', '0'],
            'did-win as string' => ['did-win', 'yes'],
            'advertised item as string' => ['advertised-item-identifier', '10738027756'],
            'advertised item negative' => ['advertised-item-identifier', -1],
            'publisher item negative' => ['publisher-item-identifier', -1],
            'source-identifier too long' => ['source-identifier', '12345'],
            'source-identifier trailing newline' => ['source-identifier', "1234\n"],
            'marketplace empty' => ['marketplace-identifier', ''],
            'postback-identifier oversized' => ['postback-identifier', str_repeat('x', 65)],
            'ad-network-identifier empty' => ['ad-network-identifier', ''],
            'impression-type as int' => ['impression-type', 1],
        ];
    }
}
