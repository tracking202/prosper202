<?php

declare(strict_types=1);

namespace Prosper202\GeoIp;

/** What DatabaseInstaller::install() did with a download that passed every check. */
final class InstallOutcome
{
    private function __construct(
        public readonly bool $replaced,
        public readonly DatabaseStatus $now,
        public readonly DatabaseStatus $before,
    ) {
    }

    public static function installed(DatabaseStatus $now, DatabaseStatus $before): self
    {
        return new self(true, $now, $before);
    }

    /** The download is the build already installed: nothing was replaced. */
    public static function alreadyInstalled(DatabaseStatus $installed): self
    {
        return new self(false, $installed, $installed);
    }
}
