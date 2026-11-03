<?php

declare(strict_types=1);

namespace Prosper202\Fraud;

/** No fraud score came back from IPQualityScore. The message never holds the key. */
final class IpqsLookupFailed extends \RuntimeException
{
}
