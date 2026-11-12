<?php

declare(strict_types=1);

namespace Prosper202\AdPlatforms;

/**
 * The ad connections' credential key is not in 202_deployment_secrets, or is
 * malformed: stored keys cannot be opened until they are set again. A failed
 * read of the key is a database error and is never this.
 */
final class AdConnectionKeyMissing extends \RuntimeException
{
}
