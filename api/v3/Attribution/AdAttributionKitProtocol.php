<?php

declare(strict_types=1);

namespace Api\V3\Attribution;

/**
 * Apple AdAttributionKit: the SKAdNetwork successor (iOS 17.4+), delivered
 * as a JSON envelope whose `jws-string` is a compact JWS (JwsVerifier)
 * carrying the signed attribution fields, beside four fields the device
 * adds UNSIGNED — conversion-value, coarse-conversion-value,
 * ad-interaction-type and country-code — exactly as in SKAdNetwork, where
 * conversion values were never signed either.
 *
 * Developers receive copies of winning postbacks when the advertised app's
 * Info.plist names this install in `AttributionCopyEndpoint`; Apple appends
 * /.well-known/appattribution/report-attribution/ itself. Re-engagement
 * copies additionally need `EligibleForAdAttributionKitReengagementPostbackCopies`.
 *
 * Normalization onto the shared row: postback-identifier is the postback
 * id, advertised-item-identifier the advertised app, publisher-item-identifier
 * the publisher app (source_app_id), and the framework's own conversion-type
 * (download, redownload, re-engagement) and ad-interaction-type (view,
 * click) are the family's generic dimensions verbatim — AdAttributionKit
 * is the protocol they were named after. The whole JWS is stored as the
 * row's attribution_signature and the header's `kid` as key_id.
 *
 * Field errors use Apple's hyphenated names; the JWS payload's fields are
 * validated after decoding and reported under their own names, so an
 * operator can match every error against "Identifying the parameters in a
 * postback" (AdAttributionKit).
 */
final class AdAttributionKitProtocol implements PostbackProtocol
{
    public const NAME = 'adattributionkit';

    public const CONVERSION_TYPES = ['download', 'redownload', 're-engagement'];
    public const INTERACTION_TYPES = ['view', 'click'];

    /** Room for any genuine JWS (about 600 bytes) with a wide margin; the column is TEXT. */
    public const MAX_JWS_LENGTH = 8192;

    public function __construct(private readonly JwsVerifier $verifier = new JwsVerifier())
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function describe(): array
    {
        return [
            'endpoint' => 'appattribution-report-attribution',
            'accepts' => 'POST application/json (AdAttributionKit postback copies: jws-string plus the unsigned conversion fields)',
        ];
    }

    public function parse(array $body): ParsedPostback|array
    {
        $errors = $this->validateEnvelope($body);

        $decoded = null;
        $jws = $body['jws-string'] ?? null;
        if (!isset($errors['jws-string']) && is_string($jws)) {
            $decoded = JwsVerifier::decode($jws);
            if (is_string($decoded)) {
                $errors['jws-string'] = $decoded;
                $decoded = null;
            } elseif (strlen((string)$decoded['header']['kid']) > 64) {
                // key_id is varchar(64); Apple's identifiers are under 40
                // characters. Refusing here keeps an absurd kid from failing
                // the INSERT and turning into a retry loop.
                $errors['jws-string'] = 'Header "kid" must be at most 64 characters';
                $decoded = null;
            }
        }
        if ($decoded !== null) {
            $errors += $this->validatePayload($decoded['payload']);
        }
        if ($errors !== [] || $decoded === null) {
            return $errors;
        }

        $payload = $decoded['payload'];
        return new ParsedPostback(
            adNetworkId: (string)$payload['ad-network-identifier'],
            postbackId: (string)$payload['postback-identifier'],
            appId: (int)$payload['advertised-item-identifier'],
            sequenceIndex: (int)$payload['postback-sequence-index'],
            didWin: (bool)$payload['did-win'],
            signatureState: $this->verifier->verify($decoded),
            keyId: (string)$decoded['header']['kid'],
            columns: [
                'conversion_value'        => ['i', PostbackFields::optInt($body, 'conversion-value')],
                'coarse_conversion_value' => ['s', PostbackFields::optString($body, 'coarse-conversion-value')],
                'conversion_type'         => ['s', (string)$payload['conversion-type']],
                'ad_interaction_type'     => ['s', (string)$body['ad-interaction-type']],
                'source_identifier'       => ['s', PostbackFields::optString($payload, 'source-identifier')],
                'source_app_id'           => ['i', PostbackFields::optInt($payload, 'publisher-item-identifier')],
                'marketplace_id'          => ['s', PostbackFields::optString($payload, 'marketplace-identifier')],
                'country_code'            => ['s', PostbackFields::optString($body, 'country-code')],
                'attribution_signature'   => ['s', (string)$jws],
            ],
        );
    }

    /**
     * The unsigned envelope around the JWS. Optional fields are the ones
     * Apple's postback data tier can withhold; a present field is checked
     * strictly.
     *
     * @param array<string, mixed> $body
     * @return array<string, string>
     */
    private function validateEnvelope(array $body): array
    {
        $errors = [];

        $jws = $body['jws-string'] ?? null;
        if (!is_string($jws) || trim($jws) === '' || strlen($jws) > self::MAX_JWS_LENGTH) {
            $errors['jws-string'] = 'Required: the compact JWS string, at most ' . self::MAX_JWS_LENGTH . ' characters';
        }

        if (
            !array_key_exists('ad-interaction-type', $body)
            || !in_array($body['ad-interaction-type'], self::INTERACTION_TYPES, true)
        ) {
            $errors['ad-interaction-type'] = 'Required: one of: view, click';
        }
        $errors += PostbackFields::errorsFor($body, 'conversion-value');
        $errors += PostbackFields::errorsFor($body, 'coarse-conversion-value');
        $errors += PostbackFields::errorsFor($body, 'country-code');

        return $errors;
    }

    /**
     * The signed payload, after JWS decoding. Validated whatever the
     * signature verdict: a forged payload that is structurally a postback
     * is stored flagged, one that is not a postback at all is a 400.
     *
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function validatePayload(array $payload): array
    {
        $errors = [];

        $postbackId = $payload['postback-identifier'] ?? null;
        if (!is_string($postbackId) || trim($postbackId) === '' || strlen($postbackId) > 64) {
            $errors['postback-identifier'] = 'Required in the JWS payload: a non-empty string of at most 64 characters';
        }

        $adNetworkId = $payload['ad-network-identifier'] ?? null;
        if (!is_string($adNetworkId) || trim($adNetworkId) === '' || strlen($adNetworkId) > 100) {
            $errors['ad-network-identifier'] = 'Required in the JWS payload: a non-empty string of at most 100 characters';
        }

        if (!is_int($payload['advertised-item-identifier'] ?? null) || $payload['advertised-item-identifier'] < 0) {
            $errors['advertised-item-identifier'] = 'Required in the JWS payload: a non-negative integer App Store id';
        }

        if (!is_bool($payload['did-win'] ?? null)) {
            $errors['did-win'] = 'Required in the JWS payload: a boolean';
        }

        $sequenceIndex = $payload['postback-sequence-index'] ?? null;
        if (!is_int($sequenceIndex) || $sequenceIndex < 0 || $sequenceIndex > 2) {
            $errors['postback-sequence-index'] = 'Required in the JWS payload: an integer from 0 to 2';
        }

        if (!in_array($payload['conversion-type'] ?? null, self::CONVERSION_TYPES, true)) {
            $errors['conversion-type'] = 'Required in the JWS payload: one of: download, redownload, re-engagement';
        }

        $impressionType = $payload['impression-type'] ?? null;
        if (!is_string($impressionType) || trim($impressionType) === '' || strlen($impressionType) > 32) {
            $errors['impression-type'] = 'Required in the JWS payload: a short string such as "app-impression"';
        }

        // Withheld by the postback data tier: optional, strict when present.
        $errors += PostbackFields::errorsFor($payload, 'source-identifier');
        if (
            array_key_exists('publisher-item-identifier', $payload)
            && (!is_int($payload['publisher-item-identifier']) || $payload['publisher-item-identifier'] < 0)
        ) {
            $errors['publisher-item-identifier'] = 'Must be a non-negative integer App Store id';
        }
        if (array_key_exists('marketplace-identifier', $payload)) {
            $marketplace = $payload['marketplace-identifier'];
            if (!is_string($marketplace) || trim($marketplace) === '' || strlen($marketplace) > 255) {
                $errors['marketplace-identifier'] = 'Must be a non-empty string of at most 255 characters';
            }
        }

        return $errors;
    }
}
