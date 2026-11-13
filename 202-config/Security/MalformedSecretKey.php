<?php

declare(strict_types=1);

namespace Prosper202\Security;

/** A key in 202_deployment_secrets that is there but is not 64 lower-case hex characters. */
final class MalformedSecretKey extends \RuntimeException
{
}
