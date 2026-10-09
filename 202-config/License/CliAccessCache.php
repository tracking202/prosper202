<?php

declare(strict_types=1);

namespace Prosper202\License;

/**
 * Cache of the "paid ClickServer licence" result that gates the Go CLI
 * (capabilities.cli). Same safety rules as ShellAccessCache (private 0700
 * dir, owner-checked, no symlinks), kept in its own directory so a shell
 * result can never be read as a CLI result or the other way round.
 */
class CliAccessCache extends ShellAccessCache
{
    protected static function cacheDirName(): string
    {
        return 'p202-cli-access';
    }
}
