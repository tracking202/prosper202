<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

/**
 * A rollup build found the account's campaign model overrides changed since
 * AttributionRollup::sync() recorded them. The build's transaction rolls
 * back; the next run re-syncs.
 */
final class RollupOutOfSync extends \RuntimeException
{
}
