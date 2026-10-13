<?php

declare(strict_types=1);

namespace Tests\Support;

use Api\V3\Support\RemoteApiClient;
use Api\V3\Support\SyncEngine;

/**
 * The sync engine with its two reads in memory and its target's answers
 * scripted (ScriptedRemoteApiClient): everything from the diff to the
 * writes and the outcome is the real engine's.
 */
final class ScriptedTargetSyncEngine extends SyncEngine
{
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $sourceData = [];
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $targetData = [];
    public ?ScriptedRemoteApiClient $target = null;
    private int $fetches = 0;

    protected function buildClients(array $sourceProfile, array $targetProfile): array
    {
        return [new ScriptedRemoteApiClient([]), $this->target ?? new ScriptedRemoteApiClient([])];
    }

    protected function fetchPortableData(RemoteApiClient $client, array $query = []): array
    {
        $this->fetches++;
        $empty = array_fill_keys(SyncEngine::supportedEntities(), []);

        return ($this->fetches === 1 ? $this->sourceData : $this->targetData) + $empty;
    }
}
