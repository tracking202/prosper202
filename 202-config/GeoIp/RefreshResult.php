<?php

declare(strict_types=1);

namespace Prosper202\GeoIp;

/** How one refresh ended, with the sentence Settings and the cron job say. */
final class RefreshResult
{
    public const REFRESHED = 'refreshed';
    public const CURRENT = 'current';
    public const FAILED = 'failed';
    public const NOT_CONFIGURED = 'not_configured';
    public const BUSY = 'busy';

    private function __construct(
        public readonly string $outcome,
        public readonly string $message,
    ) {
    }

    public static function refreshed(DatabaseStatus $now, DatabaseStatus $before): self
    {
        return new self(self::REFRESHED, 'The GeoIP database was refreshed: it was built on ' . $now->builtOn()
            . ($before->builtOn() === null ? '' : ' (the one it replaced, on ' . $before->builtOn() . ')') . '.');
    }

    public static function current(DatabaseStatus $installed): self
    {
        return new self(self::CURRENT, 'The GeoIP database is current: MaxMind\'s latest, built on '
            . $installed->builtOn()
            . ', is the one installed.');
    }

    public static function failed(string $reason): self
    {
        return new self(self::FAILED, 'The GeoIP database was not refreshed. ' . $reason);
    }

    public static function notConfigured(): self
    {
        return new self(
            self::NOT_CONFIGURED,
            'Enter a MaxMind account ID and licence key first: the refresh downloads with them.'
        );
    }

    public static function busy(): self
    {
        return new self(self::BUSY, 'Another refresh of the GeoIP database is running; try again in a few minutes.');
    }
}
