<?php

declare(strict_types=1);

namespace Prosper202\Http;

/** A lookup that got no answer in its time: a later try may get one. */
final class DnsLookupFailed extends \RuntimeException
{
}
