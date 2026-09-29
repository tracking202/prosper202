<?php

declare(strict_types=1);

namespace Api\V3\Apps;

/**
 * A registration as the signal paths see it: who owns the app, which app it
 * is, and the policy its signals are judged under. Read through AppRegistry.
 */
final class AppRegistration
{
    /**
     * The abuse limits (AppLimits). A registration built without them has
     * limits nobody could read — the trusting-least reading, never the
     * defaults (CLAUDE.md #11).
     */
    public readonly AppLimits $limits;

    public function __construct(
        public readonly int $registrationId,
        public readonly int $userId,
        public readonly AppIdentity $identity,
        public readonly AppPolicy $policy,
        // Android: the Google Cloud project number standard Play Integrity
        // tokens are requested with (published in the schema document).
        public readonly ?string $integrityCloudProjectNumber = null,
        ?AppLimits $limits = null,
    ) {
        // Named where they are applied (InstallIntake::admit() and the CTIT
        // recording), so a corrupt row is findable in the log of the request
        // it changed, and a registration read where no limit applies (an
        // iOS postback's) says nothing.
        $this->limits = $limits ?? AppLimits::unreadable();
    }
}
