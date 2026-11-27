<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

/**
 * The account's default model is not computed, so it has no credits: a read
 * that leans on the default is refused with this, never answered from nothing.
 */
final class DefaultModelNotComputed extends \DomainException
{
    public function __construct(public readonly int $modelId, public readonly string $status, public readonly ?string $reason)
    {
        parent::__construct(
            'The default attribution model (' . $modelId . ') is ' . $status . ($reason !== null ? ' (' . $reason . ')' : '')
            . ' and has no credits; edit it so its definition is valid, or make another model the default.'
        );
    }
}
