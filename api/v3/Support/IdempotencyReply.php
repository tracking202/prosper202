<?php

declare(strict_types=1);

namespace Api\V3\Support;

/** An IdempotencyAnswer, with the response to replay when that is the answer. */
final readonly class IdempotencyReply
{
    /** @param ?array<mixed> $response */
    public function __construct(
        public IdempotencyAnswer $answer,
        public ?array $response = null,
    ) {
    }

    /**
     * The form readers of `['state']` take.
     *
     * @return array{state: value-of<IdempotencyAnswer>, response: ?array<mixed>}
     */
    public function toArray(): array
    {
        return ['state' => $this->answer->value, 'response' => $this->response];
    }
}
