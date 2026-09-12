<?php

declare(strict_types=1);

namespace Api\V3\Attribution;

/**
 * Apple SKAdNetwork: flat JSON postbacks with an ECDSA signature over a
 * per-version composition of the fields (PostbackVerifier).
 *
 * Normalization maps SKAdNetwork's names onto the shared row: transaction-id
 * is the postback id, app-id the advertised app, source-app-id the
 * publisher app, and two SKAdNetwork-specific flags become the family's
 * generic dimensions — `redownload` decides conversion_type (download or
 * redownload; re-engagement does not exist in SKAdNetwork) and
 * `fidelity-type` decides ad_interaction_type (0 view-through, 1 click,
 * per Apple's definition). The raw flags are stored too.
 */
final class SkadnetworkProtocol implements PostbackProtocol
{
    public const NAME = 'skadnetwork';

    public function __construct(private readonly PostbackVerifier $verifier = new PostbackVerifier())
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function describe(): array
    {
        return [
            'endpoint' => 'skadnetwork-report-attribution',
            'accepts' => 'POST application/json (SKAdNetwork install-validation postbacks)',
        ];
    }

    public function parse(array $body): ParsedPostback|array
    {
        $errors = $this->validate($body);
        if ($errors !== []) {
            return $errors;
        }

        $redownload = array_key_exists('redownload', $body) ? (bool)$body['redownload'] : null;
        $fidelityType = PostbackFields::optInt($body, 'fidelity-type');

        return new ParsedPostback(
            adNetworkId: (string)$body['ad-network-id'],
            postbackId: (string)$body['transaction-id'],
            appId: (int)$body['app-id'],
            sequenceIndex: PostbackFields::optInt($body, 'postback-sequence-index'),
            didWin: array_key_exists('did-win', $body) ? (bool)$body['did-win'] : null,
            signatureState: $this->verifier->verify($body),
            keyId: null,
            columns: [
                'version'                 => ['s', (string)$body['version']],
                'source_identifier'       => ['s', PostbackFields::optString($body, 'source-identifier')],
                'campaign_id'             => ['i', PostbackFields::optInt($body, 'campaign-id')],
                'conversion_value'        => ['i', PostbackFields::optInt($body, 'conversion-value')],
                'coarse_conversion_value' => ['s', PostbackFields::optString($body, 'coarse-conversion-value')],
                // Apple sends redownload on every verifiable version; a body
                // without it is a first download for the installs metric,
                // which is what the metric counted before this column existed.
                'conversion_type'         => ['s', $redownload === true ? 'redownload' : 'download'],
                'redownload'              => ['i', $redownload === null ? null : (int)$redownload],
                'ad_interaction_type'     => ['s', $fidelityType === null ? null : ($fidelityType === 1 ? 'click' : 'view')],
                'fidelity_type'           => ['i', $fidelityType],
                'source_app_id'           => ['i', PostbackFields::optInt($body, 'source-app-id')],
                'source_domain'           => ['s', PostbackFields::optString($body, 'source-domain')],
                'country_code'            => ['s', PostbackFields::optString($body, 'country-code')],
                'attribution_signature'   => ['s', (string)$body['attribution-signature']],
            ],
        );
    }

    /**
     * Strict structural validation. Anything a genuine device would never
     * send is named here and rejected with a 400 — not stored half-parsed.
     * Apple's own hyphenated field names are the error keys, so the caller
     * can match them against the postback documentation.
     *
     * @param array<string, mixed> $postback
     * @return array<string, string>
     */
    private function validate(array $postback): array
    {
        $errors = [];

        $version = $postback['version'] ?? null;
        if (!is_string($version) || preg_match('/^\d{1,2}\.\d{1,2}$/D', $version) !== 1) {
            $errors['version'] = 'Required: a version string such as "4.0"';
        }

        $adNetworkId = $postback['ad-network-id'] ?? null;
        if (!is_string($adNetworkId) || trim($adNetworkId) === '' || strlen($adNetworkId) > 100) {
            $errors['ad-network-id'] = 'Required: a non-empty string of at most 100 characters';
        }

        $transactionId = $postback['transaction-id'] ?? null;
        if (!is_string($transactionId) || trim($transactionId) === '' || strlen($transactionId) > 64) {
            $errors['transaction-id'] = 'Required: a non-empty string of at most 64 characters';
        }

        if (!is_int($postback['app-id'] ?? null) || $postback['app-id'] < 0) {
            $errors['app-id'] = 'Required: a non-negative integer App Store id';
        }

        $signature = $postback['attribution-signature'] ?? null;
        if (!is_string($signature) || trim($signature) === '' || strlen($signature) > 4096) {
            $errors['attribution-signature'] = 'Required: a non-empty base64 string';
        }

        $errors += PostbackFields::errorsFor($postback, 'source-identifier');
        if (array_key_exists('campaign-id', $postback) && (!is_int($postback['campaign-id']) || $postback['campaign-id'] < 0)) {
            $errors['campaign-id'] = 'Must be a non-negative integer';
        }
        $errors += PostbackFields::errorsFor($postback, 'conversion-value');
        $errors += PostbackFields::errorsFor($postback, 'coarse-conversion-value');
        if (array_key_exists('postback-sequence-index', $postback)) {
            $sequenceIndex = $postback['postback-sequence-index'];
            if (!is_int($sequenceIndex) || $sequenceIndex < 0 || $sequenceIndex > 2) {
                $errors['postback-sequence-index'] = 'Must be an integer from 0 to 2';
            }
        }
        if (array_key_exists('redownload', $postback) && !is_bool($postback['redownload'])) {
            $errors['redownload'] = 'Must be a boolean';
        }
        if (array_key_exists('did-win', $postback) && !is_bool($postback['did-win'])) {
            $errors['did-win'] = 'Must be a boolean';
        }
        if (array_key_exists('source-app-id', $postback) && (!is_int($postback['source-app-id']) || $postback['source-app-id'] < 0)) {
            $errors['source-app-id'] = 'Must be a non-negative integer App Store id';
        }
        if (array_key_exists('source-domain', $postback)) {
            $sourceDomain = $postback['source-domain'];
            if (!is_string($sourceDomain) || trim($sourceDomain) === '' || strlen($sourceDomain) > 255) {
                $errors['source-domain'] = 'Must be a non-empty string of at most 255 characters';
            }
        }
        if (array_key_exists('fidelity-type', $postback)) {
            $fidelityType = $postback['fidelity-type'];
            if (!is_int($fidelityType) || $fidelityType < 0 || $fidelityType > 1) {
                $errors['fidelity-type'] = 'Must be 0 (view-through) or 1 (StoreKit-rendered or web ad)';
            }
        }
        $errors += PostbackFields::errorsFor($postback, 'country-code');

        return $errors;
    }
}
