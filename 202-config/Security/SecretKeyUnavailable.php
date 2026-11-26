<?php

declare(strict_types=1);

namespace Prosper202\Security;

/** The key a sealed value names is not one this process has (SecretsKeyring::keyFor()). Never a database failure. */
final class SecretKeyUnavailable extends \RuntimeException
{
}
