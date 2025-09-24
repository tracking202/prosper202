<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

/**
 * Describes the entity scope an attribution snapshot or setting applies to.
 */
enum ScopeType: string
{
    case GLOBAL = 'global';
    case ACCOUNT = 'account';
    case CAMPAIGN = 'campaign';
    case ADGROUP = 'adgroup';
    case LANDING_PAGE = 'landing_page';
    case TRAFFIC_SOURCE = 'traffic_source';

    /**
     * Indicates whether the scope requires an identifier.
     */
    public function requiresIdentifier(): bool
    {
        return match ($this) {
            self::GLOBAL => false,
            default => true,
        };
    }
}
