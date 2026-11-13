<?php

declare(strict_types=1);

namespace Prosper202\AdPlatforms;

/**
 * The ad platform could not be asked, or what it answered could not be used: an outage, its rate limit, or an
 * answer that is not what its API documents. It says nothing about the key or anything else the caller sent, so
 * it is never an input refusal (CLAUDE.md #11): the same request can succeed later.
 */
final class AdPlatformUnavailable extends \RuntimeException
{
    /** No answer, a 5xx, or an answer cut off. */
    public const UNREACHABLE = 'unreachable';
    /** The platform's rate limit, or this install's hold to it. */
    public const RATE_LIMITED = 'rate_limited';
    /** An answer that arrived and is not what the platform's API documents. */
    public const UNREADABLE = 'unreadable_answer';

    /**
     * @param array<string, mixed> $context what stands after the failure (a check's connection, a setup's steps)
     */
    public function __construct(public readonly string $reason, string $message, public readonly array $context = [])
    {
        parent::__construct($message);
    }
}
