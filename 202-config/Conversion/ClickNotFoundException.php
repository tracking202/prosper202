<?php

declare(strict_types=1);

namespace Prosper202\Conversion;

use RuntimeException;

/**
 * Thrown by the conversion repository when the source click does not exist (or is
 * not owned by the given user). Extends RuntimeException so existing callers that
 * catch RuntimeException keep working, while newer callers (e.g. the V3 API) can
 * catch this specific type to map it to a 404 instead of a 500.
 */
final class ClickNotFoundException extends RuntimeException
{
}
