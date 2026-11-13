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
     * @param string $zone the account's time zone (AccountZone)
     * @param int|null $clickTime when the click was made, null when unknown
     * @param string $transactionId the conversion's DestinationEventId, the one name every channel sends it under
     * @param string|null $ipAddress the visitor's address: only when the destination sends user data, the campaign
     *        captures identity, no request of the click's latest visit refused consent (a refusal holds for the whole
     *        visit: ConsentRefusal), the conversion's own request did not (ConsentWithheld, which withholds the
     *        customer id and hashes too), and it was stored unmasked and public
     * @param string|null $userAgent the visitor's user agent; no click stores one yet, so null
     * @param string|null $pageUrl the click's landing page, else the tracking link it came through: scheme, host, port
     *        and path only (no query, fragment, user:password@ or ;path parameters), at most 2048 characters
     * @param int $ppcAccountId the click's traffic source account, 0 when none of the account's
     * @param bool $restates the conversion replaced one and is sent under that one's event id
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
        public readonly ?int $customerId = null,
        public readonly array $emailHashes = [],
        public readonly array $phoneHashes = [],
        public readonly string $zone = 'UTC',
        public readonly ?int $clickTime = null,
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
        public readonly ?string $pageUrl = null,
        public readonly int $ppcAccountId = 0,
        public readonly bool $restates = false,
    ) {
    }

    /** A made-up conversion for a test fire: no visitor's data. */
    public static function sample(int $now, string $currency, string $zone = 'UTC'): self
    {
        return new self(null, 0, 0, $now, '49.00', $currency, 'p202-test-' . $now, [
            'subid' => '0', 'c1' => '', 'c2' => '', 'c3' => '', 'c4' => '', 't202kw' => 'running shoes',
            'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'spring-sale', 'utm_term' => '', 'utm_content' => '',
            'gclid' => '', 'gbraid' => '', 'wbraid' => '', 'ttclid' => '', 'msclkid' => '', 'fbclid' => '',
            'oppref' => '',
        ], null, [], [], $zone, null, null, null, 'https://shop.example.com/checkout/done');
    }
}
