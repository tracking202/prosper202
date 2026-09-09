<?php

declare(strict_types=1);

namespace Tests\Attribution\Postbacks;

use Api\V3\Attribution\ParsedPostback;
use Api\V3\Attribution\PostbackReceiver;
use Api\V3\Attribution\SignatureState;
use Api\V3\Attribution\SkadnetworkProtocol;
use Tests\TestCase;

/**
 * The SKAdNetwork protocol's contribution to the shared row: strict
 * validation keyed by Apple's field names, the signature verdict, and the
 * normalization of SKAdNetwork's own flags into the family's generic
 * dimensions (conversion_type, ad_interaction_type). The receiver's
 * handling of the result is covered by PostbackReceiverTest.
 */
final class SkadnetworkProtocolTest extends TestCase
{
    use SigningKeyFixture;

    public static function setUpBeforeClass(): void
    {
        self::generateSigningKey();
    }

    private function protocol(): SkadnetworkProtocol
    {
        return new SkadnetworkProtocol(self::fixtureVerifier());
    }

    /** @return array<string, mixed> */
    private function unsignedV4(): array
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
            'country-code' => 'US',
            'postback-sequence-index' => 0,
        ];
    }

    /** @param array<string, mixed> $body */
    private function parsed(array $body): ParsedPostback
    {
        $result = $this->protocol()->parse($body);
        $this->assertInstanceOf(ParsedPostback::class, $result, json_encode($result) ?: '');
        return $result;
    }

    public function testNameAndProbeDescription(): void
    {
        $protocol = $this->protocol();
        $this->assertSame('skadnetwork', $protocol->name());
        $this->assertSame(SkadnetworkProtocol::NAME, $protocol->name());
        $description = $protocol->describe();
        $this->assertSame('skadnetwork-report-attribution', $description['endpoint']);
        $this->assertStringContainsString('SKAdNetwork', $description['accepts']);
    }

    public function testASignedPostbackNormalizesOntoTheSharedRow(): void
    {
        $parsed = $this->parsed($this->signPostback($this->unsignedV4()));

        $this->assertSame('example123.skadnetwork', $parsed->adNetworkId);
        $this->assertSame('6aafb7a5-0170-41b5-bbe4-fe71dedf1e28', $parsed->postbackId);
        $this->assertSame(525463029, $parsed->appId);
        $this->assertSame(0, $parsed->sequenceIndex);
        $this->assertTrue($parsed->didWin);
        $this->assertSame(SignatureState::VALID, $parsed->signatureState);
        $this->assertNull($parsed->keyId, 'SKAdNetwork postbacks do not name a key');

        $columns = $parsed->columns;
        $this->assertSame(['s', '4.0'], $columns['version']);
        $this->assertSame(['s', '5239'], $columns['source_identifier']);
        $this->assertSame(['i', null], $columns['campaign_id']);
        $this->assertSame(['i', 63], $columns['conversion_value']);
        $this->assertSame(['s', null], $columns['coarse_conversion_value']);
        $this->assertSame(['s', 'download'], $columns['conversion_type']);
        $this->assertSame(['i', 0], $columns['redownload']);
        $this->assertSame(['s', 'click'], $columns['ad_interaction_type']);
        $this->assertSame(['i', 1], $columns['fidelity_type']);
        $this->assertSame(['i', 1234567891], $columns['source_app_id']);
        $this->assertSame(['s', null], $columns['source_domain']);
        $this->assertSame(['s', 'US'], $columns['country_code']);
        $this->assertSame('s', $columns['attribution_signature'][0]);
    }

    public function testProtocolColumnsNeverNameAReceiverOwnedColumn(): void
    {
        $parsed = $this->parsed($this->signPostback($this->unsignedV4()));
        $this->assertSame(
            [],
            array_intersect(array_keys($parsed->columns), PostbackReceiver::GENERIC_COLUMNS),
            'identity, ownership and trust columns are the receiver\'s to write'
        );
    }

    /**
     * @dataProvider conversionTypeCases
     */
    public function testRedownloadDecidesTheConversionType(?bool $redownload, string $expectedType, ?int $expectedFlag): void
    {
        $body = $this->unsignedV4();
        if ($redownload === null) {
            unset($body['redownload']);
        } else {
            $body['redownload'] = $redownload;
        }
        // Carries a signature that will not verify: the verdict is
        // irrelevant to normalization, and parse() must normalize an
        // unverified body exactly like a verified one (reports separate
        // them by signature state, not by shape).
        $body['attribution-signature'] = 'AA==';
        $parsed = $this->parsed($body);
        $this->assertSame(SignatureState::INVALID, $parsed->signatureState);
        $this->assertSame(['s', $expectedType], $parsed->columns['conversion_type']);
        $this->assertSame(['i', $expectedFlag], $parsed->columns['redownload']);
    }

    /** @return array<string, array{0: ?bool, 1: string, 2: ?int}> */
    public static function conversionTypeCases(): array
    {
        return [
            'first download' => [false, 'download', 0],
            'redownload' => [true, 'redownload', 1],
            // Every verifiable version carries the flag; a body without it
            // is a first download for the installs metric, which is what the
            // metric counted before conversion_type existed.
            'flag absent' => [null, 'download', null],
        ];
    }

    /**
     * @dataProvider interactionTypeCases
     */
    public function testFidelityTypeDecidesTheInteractionType(?int $fidelity, ?string $expected): void
    {
        $body = $this->unsignedV4();
        if ($fidelity === null) {
            unset($body['fidelity-type']);
        } else {
            $body['fidelity-type'] = $fidelity;
        }
        $body['attribution-signature'] = 'AA==';
        $parsed = $this->parsed($body);
        $this->assertSame(['s', $expected], $parsed->columns['ad_interaction_type']);
        $this->assertSame(['i', $fidelity], $parsed->columns['fidelity_type']);
    }

    /** @return array<string, array{0: ?int, 1: ?string}> */
    public static function interactionTypeCases(): array
    {
        return [
            'StoreKit-rendered or web ad' => [1, 'click'],
            'view-through' => [0, 'view'],
            // SKAdNetwork 2.1 has no fidelity-type; nothing is guessed.
            'absent' => [null, null],
        ];
    }

    public function testATamperedSignatureIsInvalidAndAnUnknownVersionUnverifiable(): void
    {
        $tampered = $this->signPostback($this->unsignedV4());
        $tampered['source-identifier'] = '1111';
        $this->assertSame(SignatureState::INVALID, $this->parsed($tampered)->signatureState);

        $legacy = $this->parsed([
            'version' => '2.0',
            'ad-network-id' => 'old.skadnetwork',
            'campaign-id' => 9,
            'app-id' => 42,
            'transaction-id' => 'legacy-tx',
            'redownload' => false,
            'attribution-signature' => 'AA==',
        ]);
        $this->assertSame(SignatureState::UNVERIFIABLE, $legacy->signatureState);
        $this->assertNull($legacy->sequenceIndex);
        $this->assertNull($legacy->didWin);
        $this->assertSame(['i', 9], $legacy->columns['campaign_id']);
    }

    public function testFieldErrorsUseApplesFieldNames(): void
    {
        $errors = $this->protocol()->parse([]);
        $this->assertIsArray($errors);
        foreach (['version', 'ad-network-id', 'transaction-id', 'app-id', 'attribution-signature'] as $field) {
            $this->assertArrayHasKey($field, $errors);
        }
    }

    /**
     * @dataProvider rejectedValues
     */
    public function testOutOfRangeAndMistypedValuesAreRejected(string $field, mixed $value): void
    {
        $body = $this->unsignedV4();
        $body['attribution-signature'] = 'AA==';
        $body[$field] = $value;
        $errors = $this->protocol()->parse($body);
        $this->assertIsArray($errors, "$field=" . var_export($value, true) . ' must be rejected');
        $this->assertArrayHasKey($field, $errors);
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function rejectedValues(): array
    {
        return [
            'conversion-value too large' => ['conversion-value', 64],
            'conversion-value negative' => ['conversion-value', -1],
            'conversion-value as string' => ['conversion-value', '63'],
            'coarse value unknown' => ['coarse-conversion-value', 'huge'],
            'sequence index out of range' => ['postback-sequence-index', 3],
            'fidelity out of range' => ['fidelity-type', 2],
            'did-win as string' => ['did-win', 'yes'],
            'redownload as int' => ['redownload', 1],
            'app-id as string' => ['app-id', '525463029'],
            'source-identifier too long' => ['source-identifier', '12345'],
            'source-identifier trailing newline' => ['source-identifier', "1234\n"],
            'version trailing newline' => ['version', "4.0\n"],
            'source-app-id negative' => ['source-app-id', -5],
        ];
    }
}
