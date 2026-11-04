<?php

declare(strict_types=1);

namespace Prosper202\Destinations;

/**
 * What a destination may say about one conversion, read at send time. The
 * identifiers are digests (PiiHash), present only when the destination
 * sends user data and the visitor may be tracked in full.
 */
final class ConversionFacts
{
    /**
     * @param array<string, scalar|null> $click TrafficSourcePixels::clickTokens() tokens
     * @param list<string> $emailHashes
     * @param list<string> $phoneHashes
     */
    public function __construct(
        public readonly ?int $convId,
        public readonly int $clickId,
        public readonly int $campaignId,
        public readonly int $convTime,
        public readonly string $value,
        public readonly string $currency,
        public readonly string $transactionId,
        public readonly array $click,
        public readonly ?string $customerRef = null,
        public readonly array $emailHashes = [],
        public readonly array $phoneHashes = [],
    ) {
    }

    /** A made-up conversion for a test fire: no visitor's data. */
    public static function sample(int $now, string $currency): self
    {
        return new self(null, 0, 0, $now, '49.00', $currency, 'p202-test-' . $now, [
            'subid' => '0', 'c1' => '', 'c2' => '', 'c3' => '', 'c4' => '', 't202kw' => 'running shoes',
            'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'spring-sale', 'utm_term' => '', 'utm_content' => '',
            'gclid' => '', 'gbraid' => '', 'wbraid' => '', 'ttclid' => '', 'msclkid' => '', 'fbclid' => '',
        ]);
    }
}
