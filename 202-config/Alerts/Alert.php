<?php

declare(strict_types=1);

namespace Prosper202\Alerts;

/**
 * One alert about one campaign. incidentKey tells this incident from the
 * next one of the same kind: a campaign is told once per key.
 */
final class Alert
{
    /**
     * @param array<string, int|float|string|null> $facts the webhook payload's fields, besides the campaign
     */
    public function __construct(
        public readonly AlertKind $kind,
        public readonly int $userId,
        public readonly int $campaignId,
        public readonly string $campaignName,
        public readonly int $incidentKey,
        public readonly string $message,
        public readonly array $facts,
    ) {
    }

    /** @return array<string, int|float|string|null> */
    public function payload(): array
    {
        return [
            'alert' => $this->kind->stateKey(),
            'campaign_id' => $this->campaignId,
            'campaign_name' => $this->campaignName,
            'message' => $this->message,
        ] + $this->facts;
    }
}
