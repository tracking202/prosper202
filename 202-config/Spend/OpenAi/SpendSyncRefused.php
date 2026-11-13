<?php

declare(strict_types=1);

namespace Prosper202\Spend\OpenAi;

/** A spend sync that must not run as things stand; the message says why. */
final class SpendSyncRefused extends \RuntimeException
{
}
