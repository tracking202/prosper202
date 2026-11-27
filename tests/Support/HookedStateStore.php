<?php

declare(strict_types=1);

namespace Tests\Support;

use Api\V3\Support\ServerStateStore;

/**
 * A state store that lets a test put another request between two steps of
 * one: each hook runs once, the first time its step is reached.
 */
final class HookedStateStore extends ServerStateStore
{
    /** Run after the next read of a job, before the reader has the copy back. */
    public ?\Closure $afterJobRead = null;

    /** Run when the next job is about to be created. */
    public ?\Closure $beforeJobCreated = null;

    /** Thrown by the next audit record's write, in its place. */
    public ?\Throwable $failNextAudit = null;

    public function appendAudit(array $record): void
    {
        if ($this->failNextAudit !== null) {
            $failure = $this->failNextAudit;
            $this->failNextAudit = null;
            throw $failure;
        }
        parent::appendAudit($record);
    }

    public function getJob(string $jobId): ?array
    {
        $job = parent::getJob($jobId);
        if ($this->afterJobRead !== null) {
            $hook = $this->afterJobRead;
            $this->afterJobRead = null;
            $hook();
        }

        return $job;
    }

    public function createJob(array $payload, int $actorUserId, array $fields = []): array
    {
        if ($this->beforeJobCreated !== null) {
            $hook = $this->beforeJobCreated;
            $this->beforeJobCreated = null;
            $hook();
        }

        return parent::createJob($payload, $actorUserId, $fields);
    }
}
