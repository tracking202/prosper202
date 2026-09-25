<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

/**
 * A model definition that cannot be computed: an unknown type, a weighting
 * config that is not the JSON object its type takes, or a lookback out of
 * range. Carries one message per offending field, so the API can answer 422
 * with field errors and the worker can store the reason on the model row.
 */
final class InvalidModelConfig extends \InvalidArgumentException
{
    /** @param array<string, string> $fieldErrors */
    public function __construct(private readonly array $fieldErrors)
    {
        $parts = [];
        foreach ($fieldErrors as $field => $message) {
            $parts[] = $field . ': ' . $message;
        }
        parent::__construct(implode('; ', $parts));
    }

    /** @return array<string, string> */
    public function fieldErrors(): array
    {
        return $this->fieldErrors;
    }
}
