<?php

declare(strict_types=1);

namespace Api\V3\Attribution;

/**
 * One platform-signed attribution protocol: how its postback body is
 * validated, how its signature is checked, and how its fields map onto the
 * shared 202_attribution_postbacks row.
 *
 * Everything the protocols have in common — body limits, rate limiting,
 * dedupe, claiming, retention, storage, the HTTP contract — lives in
 * PostbackReceiver and PostbackEndpoint and is written once. A protocol
 * contributes only what differs.
 */
interface PostbackProtocol
{
    /** The protocol id stored in the `protocol` column, e.g. "skadnetwork". */
    public function name(): string;

    /**
     * Validate, verify and normalize one decoded body.
     *
     * Returns field errors (keyed by the protocol's own field names, for the
     * 400 response) when the body is not a postback of this protocol at all;
     * a ParsedPostback otherwise, whatever its signature verdict. A bad
     * signature is a stored, flagged row, never a rejection: retrying cannot
     * fix it, and reports separate verified from unverified.
     *
     * @param array<string, mixed> $body
     * @return ParsedPostback|array<string, string>
     */
    public function parse(array $body): ParsedPostback|array;

    /**
     * What the well-known endpoint's GET probe reports, so an operator can
     * confirm the URL the platform will use answers for this protocol.
     *
     * @return array{endpoint: string, accepts: string}
     */
    public function describe(): array;
}
