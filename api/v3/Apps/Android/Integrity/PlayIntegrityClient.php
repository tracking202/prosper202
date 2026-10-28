<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android\Integrity;

/**
 * Decodes a Play Integrity token through Google (`decodeIntegrityToken`).
 *
 * The worker depends on this interface, not on HTTP: the production
 * implementation is GooglePlayIntegrityClient, and the tests hand the
 * worker a double. What an implementation promises is the three-way answer
 * of DecodeResult — decoded, retry later, or refused for good — and never
 * to throw for anything Google or the network did.
 */
interface PlayIntegrityClient
{
    public function decode(ServiceAccountCredential $credential, string $packageName, string $integrityToken): DecodeResult;
}
