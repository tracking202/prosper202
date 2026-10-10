<?php

declare(strict_types=1);

namespace Prosper202\Ltv;

use RuntimeException;

/**
 * A refusal of what the caller sent, naming the field it is about.
 *
 * The LTV repositories refused bad input with a bare RuntimeException, and
 * LtvController::wrap() turned its message into a 422 with no field_errors:
 * "amount must not be negative for purchase events", an unknown custom
 * field, a reused idempotency key — an agent reading the answer had a
 * sentence and nothing to fix. This carries the field (as the body names it:
 * `items.0.quantity`, `custom_fields.tier`), and wrap() answers it as a 422
 * with that field; a bare RuntimeException from a repository is now a
 * server failure (500), so a refusal that forgets its field is loud.
 *
 * It extends RuntimeException so the callers that catch that (the legacy
 * pages, the conversion writer) keep working unchanged.
 */
final class LtvInputException extends RuntimeException
{
    public readonly string $fieldMessage;

    /**
     * @param string $field the request field refused, as the body names it
     * @param string $message the whole refusal, for the error's message
     * @param string|null $fieldMessage what field_errors says about the field; the message when null
     */
    public function __construct(public readonly string $field, string $message, ?string $fieldMessage = null)
    {
        parent::__construct($message);
        $this->fieldMessage = $fieldMessage ?? $message;
    }

    /** @return array<string, string> the field_errors this refusal answers with */
    public function fieldErrors(): array
    {
        return [$this->field => $this->fieldMessage];
    }
}
