<?php

declare(strict_types=1);

namespace Api\V3\Attribution;

/**
 * A postback after its protocol has validated, verified and normalized it:
 * the identity the receiver dedupes and claims on, the signature verdict,
 * and the protocol-owned columns to store beside the generic ones.
 */
final class ParsedPostback
{
    /**
     * @param string      $adNetworkId    the ad network's identifier
     * @param string      $postbackId     the vendor's unique id for this
     *                                    postback (SKAdNetwork transaction-id,
     *                                    AdAttributionKit postback-identifier)
     * @param int         $appId          the advertised app's App Store id —
     *                                    the value a registration claims on
     * @param int|null    $sequenceIndex  conversion window 0-2, if carried
     * @param bool|null   $didWin         whether the network won attribution
     * @param SignatureState $signatureState the verifier's verdict
     * @param string|null $keyId          which key verified it, when the
     *                                    protocol names keys
     * @param array<string, array{0: string, 1: mixed}> $columns
     *        protocol-owned columns as aligned (bind type, value) pairs, so
     *        the bind string cannot drift from the values (error pattern #7)
     */
    public function __construct(
        public readonly string $adNetworkId,
        public readonly string $postbackId,
        public readonly int $appId,
        public readonly ?int $sequenceIndex,
        public readonly ?bool $didWin,
        public readonly SignatureState $signatureState,
        public readonly ?string $keyId,
        public readonly array $columns,
    ) {
    }
}
