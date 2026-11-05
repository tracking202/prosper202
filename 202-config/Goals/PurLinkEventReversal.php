<?php

declare(strict_types=1);

namespace Prosper202\Goals;

/** An event cannot also reverse a goal conversion. */
final class PurLinkEventReversal extends \RuntimeException
{
}
