<?php

declare(strict_types=1);

namespace Prosper202\Destinations;

/**
 * The destinations' credential key is not in 202_deployment_secrets, or is
 * not 64 lower-case hex characters: the stored credentials cannot be opened
 * until they are set again. A failed read of the key is a database error and
 * is never this, so a lock wait is not reported as unreadable credentials.
 */
final class DestinationKeyMissing extends \RuntimeException
{
}
