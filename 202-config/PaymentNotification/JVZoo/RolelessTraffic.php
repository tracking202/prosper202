<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification\JVZoo;

use Prosper202\InboundLog\InboundLog;

/**
 * What the URL Prosper202 1.9.56 showed (jvzoo.php with no ?role=) has
 * received for an account lately, read from the inbound log, so the JVZoo
 * card can say that it is still in use and what became of its sales.
 */
final class RolelessTraffic
{
    /** How far back the card counts. */
    public const WINDOW = 30 * 86400;
    /** The newest JVZoo requests read for the counts. */
    public const READ = 500;

    /** Outcomes of a notification that moves no money: not counted either way. */
    private const NO_MONEY = ['test', 'ignored'];

    /**
     * @param int $vendorNoAffiliate named no affiliate, so read as the vendor's whatever the account chose
     * @param int $refused read as an affiliate's and recorded nothing (refused, no click, an unknown click)
     * @param int $asAffiliate read as an affiliate's and accepted
     * @param int $asVendor read as a vendor's because the account chose it
     * @param int $conflict refused because accounts sharing the key chose differently
     */
    public function __construct(
        public readonly int $vendorNoAffiliate = 0,
        public readonly int $refused = 0,
        public readonly int $asAffiliate = 0,
        public readonly int $asVendor = 0,
        public readonly int $conflict = 0,
    ) {
    }

    public static function recent(InboundLog $log, int $userId, int $since): self
    {
        $counts = ['vendorNoAffiliate' => 0, 'refused' => 0, 'asAffiliate' => 0, 'asVendor' => 0, 'conflict' => 0];
        foreach ($log->list($userId, false, ['endpoint' => 'jvzoo'], self::READ) as $row) {
            $read = is_array($row['fields']) ? ($row['fields'][JVZooAdapter::ROLELESS_LOG_FIELD] ?? null) : null;
            if (
                (int) $row['received_at'] < $since || !is_string($read)
                || in_array((string) $row['outcome'], self::NO_MONEY, true)
            ) {
                continue;
            }
            $key = match ($read) {
                JVZooAdapter::READ_VENDOR_NO_AFFILIATE => 'vendorNoAffiliate',
                JVZooAdapter::READ_AFFILIATE => $row['accepted'] === true ? 'asAffiliate' : 'refused',
                JVZooAdapter::READ_VENDOR => 'asVendor',
                JVZooAdapter::READ_CONFLICT => 'conflict',
                default => null,
            };
            if ($key !== null) {
                $counts[$key]++;
            }
        }

        return new self(...$counts);
    }

    /** The URL received something that moves money. */
    public function inUse(): bool
    {
        return $this->vendorNoAffiliate + $this->refused + $this->asAffiliate + $this->asVendor + $this->conflict > 0;
    }

    /** Read as an affiliate's, the account's notifications there look like a vendor's. */
    public function looksLikeAVendor(): bool
    {
        return $this->vendorNoAffiliate > 0 || $this->refused > 0;
    }
}
