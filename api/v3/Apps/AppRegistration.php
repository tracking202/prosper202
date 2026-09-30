<?php

declare(strict_types=1);

namespace Api\V3\Apps;

/**
 * A registration as the signal paths see it: who owns the app, which app it
 * is, and the policy its signals are judged under. Read through AppRegistry.
 */
final class AppRegistration
{
    public function __construct(
        public readonly int $registrationId,
        public readonly int $userId,
        public readonly AppIdentity $identity,
        public readonly AppPolicy $policy,
        // Android: the Google Cloud project number standard Play Integrity
        // tokens are requested with (published in the schema document).
        public readonly ?string $integrityCloudProjectNumber = null,
    ) {
    }
}
