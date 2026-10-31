<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android;

/**
 * An install carries a p202 token but this server has no install-token key
 * to verify it with. Never read as "the token is bad" (CLAUDE.md #11): the
 * intake answers 503 so the device keeps the install and retries once the
 * key exists again (an upgrade or reinstall mints it).
 */
final class MissingInstallKey extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This server has no install-token key, so the install token cannot be verified; run the upgrade, which mints it.');
    }
}
