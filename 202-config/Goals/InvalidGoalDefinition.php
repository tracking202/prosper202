<?php

declare(strict_types=1);

namespace Prosper202\Goals;

/**
 * A goal definition (or an event) that does not satisfy the schema.
 *
 * Carries every error found, keyed by the JSON path of the offending value
 * (`trigger.where[0].op`), so an API answer can name each field at once and
 * a stored definition that fails to load names what is wrong with it.
 */
final class InvalidGoalDefinition extends \InvalidArgumentException
{
    /**
     * @param array<string, string> $errors path => message
     */
    public function __construct(private readonly array $errors, string $message = 'The goal definition is invalid')
    {
        $first = $errors === [] ? '' : ': ' . array_key_first($errors) . ' — ' . reset($errors);
        parent::__construct($message . $first);
    }

    /** @return array<string, string> path => message */
    public function errors(): array
    {
        return $this->errors;
    }
}
