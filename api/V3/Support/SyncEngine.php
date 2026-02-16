<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\ValidationException;

class SyncEngine
{
    private const ENTITY_ENDPOINTS = [
        'aff-networks' => 'aff-networks',
        'ppc-networks' => 'ppc-networks',
        'ppc-accounts' => 'ppc-accounts',
        'campaigns' => 'campaigns',
        'landing-pages' => 'landing-pages',
        'text-ads' => 'text-ads',
        'rotators' => 'rotators',
        'trackers' => 'trackers',
    ];

    private const DEPENDENCY_ORDER = [
        'aff-networks',
        'ppc-networks',
        'ppc-accounts',
        'campaigns',
        'landing-pages',
        'text-ads',
        'rotators',
        'trackers',
    ];

    private const ENTITY_ID_FIELDS = [
        'aff-networks' => ['aff_network_id', 'id'],
        'ppc-networks' => ['ppc_network_id', 'id'],
        'ppc-accounts' => ['ppc_account_id', 'id'],
        'campaigns' => ['aff_campaign_id', 'id'],
        'landing-pages' => ['landing_page_id', 'id'],
        'text-ads' => ['text_ad_id', 'id'],
        'rotators' => ['id'],
        'trackers' => ['tracker_id', 'id'],
    ];

    private const FK_DEPENDENCIES = [
        'ppc-accounts' => [
            'ppc_network_id' => 'ppc-networks',
        ],
        'campaigns' => [
            'aff_network_id' => 'aff-networks',
        ],
        'landing-pages' => [
            'aff_campaign_id' => 'campaigns',
        ],
        'text-ads' => [
            'aff_campaign_id' => 'campaigns',
            'landing_page_id' => 'landing-pages',
        ],
        'rotators' => [
            'default_campaign' => 'campaigns',
            'default_lp' => 'landing-pages',
        ],
        'trackers' => [
            'aff_campaign_id' => 'campaigns',
            'ppc_account_id' => 'ppc-accounts',
            'landing_page_id' => 'landing-pages',
            'text_ad_id' => 'text-ads',
            'rotator_id' => 'rotators',
        ],
    ];

    private const IMMUTABLE_FIELDS = [
        'campaigns' => ['id', 'user_id', 'aff_campaign_id', 'aff_campaign_time', 'aff_campaign_id_public', 'aff_campaign_deleted'],
        'aff-networks' => ['id', 'user_id', 'aff_network_id', 'aff_network_deleted'],
        'ppc-networks' => ['id', 'user_id', 'ppc_network_id', 'ppc_network_deleted'],
        'ppc-accounts' => ['id', 'user_id', 'ppc_account_id', 'ppc_account_deleted'],
        'rotators' => ['id', 'user_id'],
        'trackers' => ['id', 'user_id', 'tracker_id', 'tracker_time'],
        'landing-pages' => ['id', 'user_id', 'landing_page_id', 'landing_page_deleted'],
        'text-ads' => ['id', 'user_id', 'text_ad_id', 'text_ad_deleted'],
    ];

    private ServerStateStore $store;

    public function __construct(ServerStateStore $store)
    {
        $this->store = $store;
    }

    public static function supportedEntities(): array
    {
        return array_keys(self::ENTITY_ENDPOINTS);
    }

    /**
     * @return array{0: array<string, array<int, array<string, mixed>>>, 1: array<string, array<int, array<string, mixed>>>}
     */
    protected function loadDataSets(array $sourceProfile, array $targetProfile, array $query = []): array
    {
        [$sourceClient, $targetClient] = $this->buildClients($sourceProfile, $targetProfile);
        return [
            $this->fetchPortableData($sourceClient, $query),
            $this->fetchPortableData($targetClient, $query),
        ];
    }

    public function buildPlan(array $sourceProfile, array $targetProfile, string $entityArg = 'all', array $options = []): array
    {
        $planSpan = $this->startTraceSpan('sync.plan', [
            'entity' => $entityArg,
            'source' => $this->profileLabel($sourceProfile),
            'target' => $this->profileLabel($targetProfile),
        ]);
        $traceStatus = 'ok';
        $traceMeta = [];

        try {
            $entities = $this->selectedEntities($entityArg);

            [$sourceData, $targetData] = $this->loadDataSets($sourceProfile, $targetProfile);

            $sourceLookups = $this->buildEntityLookups($sourceData);
            $targetLookups = $this->buildEntityLookups($targetData);

            $results = [];
            $summary = [
                'only_in_source' => 0,
                'only_in_target' => 0,
                'changed' => 0,
                'identical' => 0,
            ];
            $warnings = 0;

            foreach ($entities as $entity) {
                $entitySpan = $this->startTraceSpan('sync.plan.entity', ['entity' => $entity]);
                try {
                    $result = $this->diffEntity($entity, $sourceData[$entity], $targetData[$entity], $sourceLookups, $targetLookups);
                    $results[$entity] = $result;
                    $summary['only_in_source'] += $result['only_in_source_count'];
                    $summary['only_in_target'] += $result['only_in_target_count'];
                    $summary['changed'] += $result['changed_count'];
                    $summary['identical'] += $result['identical_count'];
                    $warnings += count($result['warnings'] ?? []);
                } finally {
                    $this->endTraceSpan($entitySpan, 'ok');
                }
            }

            if (!empty($options['fail_on_collision']) && $warnings > 0) {
                throw new ValidationException(
                    'Natural-key collisions detected; manual resolution required before sync.',
                    ['collisions' => 'Resolve collisions from sync plan warnings and retry']
                );
            }

            $pairKey = $this->pairKey($sourceProfile, $targetProfile);
            $pruneToken = null;
            if (!empty($options['prune_preview']) || !empty($options['prune'])) {
                $pruneToken = $this->store->issuePruneToken($pairKey);
            }

            $traceMeta = [
                'entities' => count($entities),
                'warnings' => $warnings,
            ];
            return [
                'source' => $this->profileLabel($sourceProfile),
                'target' => $this->profileLabel($targetProfile),
                'entity' => $entityArg,
                'summary' => $summary,
                'data' => $results,
                'pair_key' => $pairKey,
                'prune_confirmation_token' => $pruneToken,
                'generated_at' => gmdate('c'),
            ];
        } catch (\Throwable $e) {
            $traceStatus = 'error';
            $traceMeta = ['error' => $e->getMessage()];
            throw $e;
        } finally {
            $this->endTraceSpan($planSpan, $traceStatus, $traceMeta);
        }
    }

    public function execute(array $sourceProfile, array $targetProfile, string $entityArg = 'all', array $options = [], ?callable $eventLogger = null): array
    {
        $execSpan = $this->startTraceSpan('sync.execute', [
            'entity' => $entityArg,
            'source' => $this->profileLabel($sourceProfile),
            'target' => $this->profileLabel($targetProfile),
        ]);
        $traceStatus = 'ok';
        $traceMeta = [];

        try {
            [$sourceClient, $targetClient] = $this->buildClients($sourceProfile, $targetProfile);
            $entities = $this->selectedEntities($entityArg);
            $manifest = is_array($options['manifest'] ?? null) ? $options['manifest'] : ['mappings' => [], 'source_hashes' => []];

            $updatedSince = isset($options['updated_since']) ? (string)$options['updated_since'] : '';
            $sourceData = $this->fetchPortableData($sourceClient, $updatedSince !== '' ? ['updated_since' => $updatedSince] : []);
            $targetData = $this->fetchPortableData($targetClient);

            $sourceLookups = $this->buildEntityLookups($sourceData);
            $targetLookups = $this->buildEntityLookups($targetData);

            $dryRun = (bool)($options['dry_run'] ?? false);
            $skipErrors = (bool)($options['skip_errors'] ?? false);
            $forceUpdate = (bool)($options['force_update'] ?? false);
            $prune = (bool)($options['prune'] ?? false);
            $prunePreview = (bool)($options['prune_preview'] ?? false);

            $results = [];
            $mappings = [];
            $sourceHashes = [];
            $deleteCandidates = [];

            foreach ($entities as $entity) {
                $entitySpan = $this->startTraceSpan('sync.execute.entity', ['entity' => $entity]);
                $remapSpan = $this->startTraceSpan('sync.execute.remap', ['entity' => $entity]);
                $remapOps = 0;
            $result = [
                'synced' => 0,
                'skipped' => 0,
                'failed' => 0,
                'pruned' => 0,
                'created' => 0,
                'updated' => 0,
                'errors' => [],
            ];

            $sourceRows = $sourceData[$entity];
            usort($sourceRows, function (array $a, array $b) use ($entity, $sourceLookups): int {
                return strcmp(
                    $this->naturalKeyForEntity($entity, $a, $sourceLookups),
                    $this->naturalKeyForEntity($entity, $b, $sourceLookups)
                );
            });

            $targetIndex = $this->buildEntityIndex($entity, $targetData[$entity], $targetLookups);
            $sourceKeys = [];

            foreach ($sourceRows as $sourceRow) {
                $remapOps++;
                $key = $this->naturalKeyForEntity($entity, $sourceRow, $sourceLookups);
                if ($key === '') {
                    if (!$this->recordSyncError($result, $entity, $key, new DatabaseException('empty natural key'), $skipErrors)) {
                        throw new DatabaseException('Sync failed: empty natural key');
                    }
                    continue;
                }
                $sourceKeys[$key] = true;

                $sourceComparable = $this->normalizeComparableRecord($entity, $sourceRow, $sourceLookups);
                $sourceId = $this->firstStringFromRow($sourceRow, ...self::ENTITY_ID_FIELDS[$entity]);
                $sourceHash = $this->comparableHash($sourceComparable);
                if ($sourceId !== '') {
                    if (!isset($sourceHashes[$entity])) {
                        $sourceHashes[$entity] = [];
                    }
                    $sourceHashes[$entity][$sourceId] = $sourceHash;
                }

                if (
                    !empty($options['incremental'])
                    && !$forceUpdate
                    && $sourceId !== ''
                    && isset($manifest['mappings'][$entity][$sourceId], $manifest['source_hashes'][$entity][$sourceId])
                    && (string)$manifest['source_hashes'][$entity][$sourceId] === $sourceHash
                ) {
                    $result['skipped']++;
                    if (!isset($mappings[$entity])) {
                        $mappings[$entity] = [];
                    }
                    $mappings[$entity][$sourceId] = (string)$manifest['mappings'][$entity][$sourceId];
                    continue;
                }

                $targetRow = $targetIndex[$key] ?? null;

                if ($targetRow !== null) {
                    $targetComparable = $this->normalizeComparableRecord($entity, $targetRow, $targetLookups);
                    if ($this->comparableEqual($sourceComparable, $targetComparable)) {
                        $result['skipped']++;
                        $this->recordMapping($mappings, $entity, $sourceRow, $targetRow);
                        continue;
                    }

                    if (!$forceUpdate) {
                        $result['skipped']++;
                        continue;
                    }

                    $payload = $this->buildSyncPayload($entity, $sourceRow, $sourceLookups, $targetLookups);
                    $targetId = $this->firstStringFromRow($targetRow, ...self::ENTITY_ID_FIELDS[$entity]);
                    if ($targetId === '') {
                        if (!$this->recordSyncError($result, $entity, $key, new DatabaseException('missing target ID'), $skipErrors)) {
                            throw new DatabaseException('Sync failed: target record missing ID');
                        }
                        continue;
                    }

                    if (!$dryRun) {
                        try {
                            $extraHeaders = [];
                            if (!empty($targetRow['etag'])) {
                                $extraHeaders['If-Match'] = (string)$targetRow['etag'];
                            }
                            $targetClient->put(self::ENTITY_ENDPOINTS[$entity] . '/' . $targetId, $payload, $extraHeaders);
                            if ($entity === 'rotators' && $sourceId !== '') {
                                $this->resyncRotatorRules(
                                    $sourceClient,
                                    $targetClient,
                                    $sourceId,
                                    $targetId,
                                    $sourceLookups,
                                    $targetLookups
                                );
                            }
                        } catch (\Throwable $e) {
                            if (!$this->recordSyncError($result, $entity, $key, $e, $skipErrors)) {
                                throw $e;
                            }
                            continue;
                        }
                    }

                    $result['synced']++;
                    $result['updated']++;
                    $this->recordMapping($mappings, $entity, $sourceRow, $targetRow);
                    if ($eventLogger !== null) {
                        $eventLogger('info', 'Updated target record', ['entity' => $entity, 'key' => $key]);
                    }
                    continue;
                }

                try {
                    $payload = $this->buildSyncPayload($entity, $sourceRow, $sourceLookups, $targetLookups);
                } catch (\Throwable $e) {
                    if (!$this->recordSyncError($result, $entity, $key, $e, $skipErrors)) {
                        throw $e;
                    }
                    continue;
                }

                if ($dryRun) {
                    $result['synced']++;
                    continue;
                }

                try {
                    $created = $targetClient->post(self::ENTITY_ENDPOINTS[$entity], $payload);
                } catch (\Throwable $e) {
                    if (!$this->recordSyncError($result, $entity, $key, $e, $skipErrors)) {
                        throw $e;
                    }
                    continue;
                }

                $createdObj = is_array($created['data'] ?? null) ? $created['data'] : [];
                $createdId = $this->firstStringFromRow($createdObj, ...self::ENTITY_ID_FIELDS[$entity]);
                if ($createdId !== '') {
                    $this->recordMappingById($mappings, $entity, $sourceRow, $createdId);
                }

                $result['synced']++;
                $result['created']++;
                $targetData[$entity][] = $createdObj ?: $payload;
                $targetLookups = $this->buildEntityLookups($targetData);
                $targetIndex = $this->buildEntityIndex($entity, $targetData[$entity], $targetLookups);

                if ($entity === 'rotators' && $createdId !== '') {
                    $sourceId = $this->firstStringFromRow($sourceRow, ...self::ENTITY_ID_FIELDS[$entity]);
                    if ($sourceId !== '') {
                        try {
                            $this->syncRotatorRules($sourceClient, $targetClient, $sourceId, $createdId, $sourceLookups, $targetLookups);
                        } catch (\Throwable $e) {
                            if (!$this->recordSyncError($result, $entity, $key, $e, $skipErrors)) {
                                throw $e;
                            }
                        }
                    }
                }

                if ($eventLogger !== null) {
                    $eventLogger('info', 'Created target record', ['entity' => $entity, 'key' => $key]);
                }
            }

            $this->endTraceSpan($remapSpan, 'ok', ['operations' => $remapOps]);
            $writeSpan = $this->startTraceSpan('sync.execute.write', ['entity' => $entity]);
            $this->endTraceSpan($writeSpan, 'ok', [
                'created' => (int)($result['created'] ?? 0),
                'updated' => (int)($result['updated'] ?? 0),
                'dry_run' => $dryRun,
            ]);

            if ($prune || $prunePreview) {
                $pruneSpan = $this->startTraceSpan('sync.execute.prune', ['entity' => $entity]);
                $allow = $this->normalizeEntitySet($options['prune_allowlist'] ?? []);
                $deny = $this->normalizeEntitySet($options['prune_denylist'] ?? []);

                foreach ($targetData[$entity] as $targetRow) {
                    $targetKey = $this->naturalKeyForEntity($entity, $targetRow, $targetLookups);
                    if ($targetKey === '' || isset($sourceKeys[$targetKey])) {
                        continue;
                    }

                    if (!empty($allow) && !isset($allow[$entity])) {
                        continue;
                    }
                    if (isset($deny[$entity])) {
                        continue;
                    }

                    $deleteCandidates[$entity][] = [
                        'key' => $targetKey,
                        'record' => $this->normalizeComparableRecord($entity, $targetRow, $targetLookups),
                    ];

                    if ($prunePreview || $dryRun) {
                        continue;
                    }

                    $targetId = $this->firstStringFromRow($targetRow, ...self::ENTITY_ID_FIELDS[$entity]);
                    if ($targetId === '') {
                        continue;
                    }

                    try {
                        $targetClient->delete(self::ENTITY_ENDPOINTS[$entity] . '/' . $targetId);
                        $result['pruned']++;
                    } catch (\Throwable $e) {
                        if (!$this->recordSyncError($result, $entity, $targetKey, $e, $skipErrors)) {
                            throw $e;
                        }
                    }
                }
                $this->endTraceSpan($pruneSpan, 'ok', [
                    'candidates' => count($deleteCandidates[$entity] ?? []),
                    'pruned' => (int)($result['pruned'] ?? 0),
                    'dry_run' => ($prunePreview || $dryRun),
                ]);
            }

            $results[$entity] = $result;
            $this->store->incrementMetric('rows_created', (int)($result['created'] ?? 0));
            $this->store->incrementMetric('rows_updated', (int)($result['updated'] ?? 0));
            $this->store->incrementMetric('rows_deleted', (int)($result['pruned'] ?? 0));
            $this->store->incrementMetric('sync_entity_failed', (int)($result['failed'] ?? 0));
                $this->endTraceSpan($entitySpan, 'ok', [
                    'synced' => (int)($result['synced'] ?? 0),
                    'failed' => (int)($result['failed'] ?? 0),
                ]);
        }

            $traceMeta = ['entities' => count($entities)];
            return [
                'source' => $this->profileLabel($sourceProfile),
                'target' => $this->profileLabel($targetProfile),
                'entity' => $entityArg,
                'dry_run' => $dryRun,
                'force_update' => $forceUpdate,
                'prune' => $prune,
                'prune_preview' => $prunePreview,
                'results' => $results,
                'mappings' => $mappings,
                'source_hashes' => $sourceHashes,
                'delete_candidates' => $deleteCandidates,
            ];
        } catch (\Throwable $e) {
            $traceStatus = 'error';
            $traceMeta = ['error' => $e->getMessage()];
            throw $e;
        } finally {
            $this->endTraceSpan($execSpan, $traceStatus, $traceMeta);
        }
    }

    private function startTraceSpan(string $name, array $meta = []): ?string
    {
        try {
            return $this->store->startSpan($name, $meta);
        } catch (\Throwable) {
            return null;
        }
    }

    private function endTraceSpan(?string $spanId, string $status = 'ok', array $meta = []): void
    {
        if ($spanId === null || $spanId === '') {
            return;
        }
        try {
            $this->store->endSpan($spanId, $status, $meta);
        } catch (\Throwable) {
            // Tracing is best-effort and must not affect sync execution.
        }
    }

    private function syncRotatorRules(
        RemoteApiClient $sourceClient,
        RemoteApiClient $targetClient,
        string $sourceRotatorId,
        string $targetRotatorId,
        array $sourceLookups,
        array $targetLookups
    ): void {
        $sourceRotator = $sourceClient->get('rotators/' . $sourceRotatorId);
        $rules = $sourceRotator['data']['rules'] ?? [];

        foreach ($rules as $rule) {
            $rulePayload = [
                'rule_name' => $rule['rule_name'] ?? '',
                'splittest' => $rule['splittest'] ?? 0,
                'status' => $rule['status'] ?? 1,
            ];

            if (!empty($rule['criteria']) && is_array($rule['criteria'])) {
                $rulePayload['criteria'] = [];
                foreach ($rule['criteria'] as $c) {
                    $rulePayload['criteria'][] = [
                        'type' => $c['type'] ?? '',
                        'statement' => $c['statement'] ?? '',
                        'value' => $c['value'] ?? '',
                    ];
                }
            }

            if (!empty($rule['redirects']) && is_array($rule['redirects'])) {
                $rulePayload['redirects'] = [];
                foreach ($rule['redirects'] as $r) {
                    $redirect = [
                        'redirect_url' => $r['redirect_url'] ?? '',
                        'weight' => $r['weight'] ?? 100,
                        'name' => $r['name'] ?? '',
                        'redirect_campaign' => 0,
                        'redirect_lp' => 0,
                    ];

                    $rawCampaign = $this->scalarString($r['redirect_campaign'] ?? null);
                    if ($rawCampaign !== '' && $rawCampaign !== '0') {
                        $natural = $sourceLookups['campaigns']['by_id'][$rawCampaign] ?? '';
                        if ($natural !== '') {
                            $targetId = $targetLookups['campaigns']['by_natural'][$natural] ?? '';
                            if ($targetId !== '') {
                                $redirect['redirect_campaign'] = (int)$targetId;
                            }
                        }
                    }

                    $rawLp = $this->scalarString($r['redirect_lp'] ?? null);
                    if ($rawLp !== '' && $rawLp !== '0') {
                        $natural = $sourceLookups['landing_pages']['by_id'][$rawLp] ?? '';
                        if ($natural !== '') {
                            $targetId = $targetLookups['landing_pages']['by_natural'][$natural] ?? '';
                            if ($targetId !== '') {
                                $redirect['redirect_lp'] = (int)$targetId;
                            }
                        }
                    }

                    $rulePayload['redirects'][] = $redirect;
                }
            }

            $targetClient->post('rotators/' . $targetRotatorId . '/rules', $rulePayload);
        }
    }

    private function resyncRotatorRules(
        RemoteApiClient $sourceClient,
        RemoteApiClient $targetClient,
        string $sourceRotatorId,
        string $targetRotatorId,
        array $sourceLookups,
        array $targetLookups
    ): void {
        $targetRotator = $targetClient->get('rotators/' . $targetRotatorId);
        $targetRules = $targetRotator['data']['rules'] ?? [];
        foreach ($targetRules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $ruleId = $this->scalarString($rule['id'] ?? null);
            if ($ruleId === '') {
                continue;
            }
            $targetClient->delete('rotators/' . $targetRotatorId . '/rules/' . $ruleId);
        }

        $this->syncRotatorRules(
            $sourceClient,
            $targetClient,
            $sourceRotatorId,
            $targetRotatorId,
            $sourceLookups,
            $targetLookups
        );
    }

    protected function buildClients(array $sourceProfile, array $targetProfile): array
    {
        $source = new RemoteApiClient((string)($sourceProfile['url'] ?? ''), (string)($sourceProfile['api_key'] ?? ''));
        $target = new RemoteApiClient((string)($targetProfile['url'] ?? ''), (string)($targetProfile['api_key'] ?? ''));

        return [$source, $target];
    }

    private function selectedEntities(string $entity): array
    {
        $entity = trim($entity);
        if ($entity === '' || $entity === 'all') {
            return self::DEPENDENCY_ORDER;
        }
        if (!isset(self::ENTITY_ENDPOINTS[$entity])) {
            throw new ValidationException('Unsupported entity', ['entity' => 'Valid values: all, ' . implode(', ', self::supportedEntities())]);
        }
        return [$entity];
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    protected function fetchPortableData(RemoteApiClient $client, array $query = []): array
    {
        $out = [];
        foreach (self::DEPENDENCY_ORDER as $entity) {
            $rows = $client->fetchAllRows(self::ENTITY_ENDPOINTS[$entity], $query);
            if ($entity === 'rotators' && !empty($rows)) {
                $enriched = [];
                foreach ($rows as $row) {
                    $row = is_array($row) ? $row : [];
                    $rotatorId = $this->firstStringFromRow($row, ...self::ENTITY_ID_FIELDS['rotators']);
                    if ($rotatorId === '') {
                        $row['rules'] = [];
                        $enriched[] = $row;
                        continue;
                    }

                    try {
                        $detail = $client->get('rotators/' . $rotatorId);
                        $detailData = is_array($detail['data'] ?? null) ? $detail['data'] : [];
                        $rules = $detailData['rules'] ?? [];
                        $row['rules'] = is_array($rules) ? $rules : [];
                    } catch (\Throwable) {
                        $row['rules'] = [];
                    }
                    $enriched[] = $row;
                }
                $rows = $enriched;
            }
            $out[$entity] = $rows;
        }
        return $out;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $data
     * @return array<string, array<string, array<string, string>>>
     */
    private function buildEntityLookups(array $data): array
    {
        [$affById, $affByNatural] = $this->buildIdLookup($data['aff-networks'], ['aff_network_id', 'id'], 'aff_network_name');
        [$ppcNetById, $ppcNetByNatural] = $this->buildIdLookup($data['ppc-networks'], ['ppc_network_id', 'id'], 'ppc_network_name');
        [$ppcAccById, $ppcAccByNatural] = $this->buildIdLookup($data['ppc-accounts'], ['ppc_account_id', 'id'], 'ppc_account_name');
        [$campaignById, $campaignByNatural] = $this->buildIdLookup($data['campaigns'], ['aff_campaign_id', 'id'], 'aff_campaign_name');
        [$landingById, $landingByNatural] = $this->buildIdLookup($data['landing-pages'], ['landing_page_id', 'id'], 'landing_page_url');
        [$textAdById, $textAdByNatural] = $this->buildIdLookup($data['text-ads'], ['text_ad_id', 'id'], 'text_ad_name');
        [$rotatorById, $rotatorByNatural] = $this->buildIdLookup($data['rotators'] ?? [], ['id'], 'public_id');

        return [
            'aff_networks' => ['by_id' => $affById, 'by_natural' => $affByNatural],
            'ppc_networks' => ['by_id' => $ppcNetById, 'by_natural' => $ppcNetByNatural],
            'ppc_accounts' => ['by_id' => $ppcAccById, 'by_natural' => $ppcAccByNatural],
            'campaigns' => ['by_id' => $campaignById, 'by_natural' => $campaignByNatural],
            'landing_pages' => ['by_id' => $landingById, 'by_natural' => $landingByNatural],
            'text_ads' => ['by_id' => $textAdById, 'by_natural' => $textAdByNatural],
            'rotators' => ['by_id' => $rotatorById, 'by_natural' => $rotatorByNatural],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $idFields
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function buildIdLookup(array $rows, array $idFields, string $naturalField): array
    {
        $byId = [];
        $byNatural = [];

        foreach ($rows as $row) {
            $id = $this->firstStringFromRow($row, ...$idFields);
            if ($id === '') {
                continue;
            }
            $natural = $this->scalarString($row[$naturalField] ?? null);
            if ($natural === '') {
                $natural = 'id:' . $id;
            }

            $byId[$id] = $natural;
            if (!isset($byNatural[$natural])) {
                $byNatural[$natural] = $id;
            }
        }

        return [$byId, $byNatural];
    }

    /**
     * @param array<int, array<string, mixed>> $sourceRows
     * @param array<int, array<string, mixed>> $targetRows
     * @param array<string, array<string, array<string, string>>> $sourceLookups
     * @param array<string, array<string, array<string, string>>> $targetLookups
     */
    private function diffEntity(string $entity, array $sourceRows, array $targetRows, array $sourceLookups, array $targetLookups): array
    {
        $sourceIndex = [];
        $targetIndex = [];
        $warnings = [];

        foreach ($sourceRows as $row) {
            $key = $this->naturalKeyForEntity($entity, $row, $sourceLookups);
            if ($key !== '') {
                if (isset($sourceIndex[$key])) {
                    $warnings[] = [
                        'type' => 'natural_key_collision',
                        'side' => 'source',
                        'entity' => $entity,
                        'key' => $key,
                    ];
                }
                $sourceIndex[$key] = $row;
            }
        }
        foreach ($targetRows as $row) {
            $key = $this->naturalKeyForEntity($entity, $row, $targetLookups);
            if ($key !== '') {
                if (isset($targetIndex[$key])) {
                    $warnings[] = [
                        'type' => 'natural_key_collision',
                        'side' => 'target',
                        'entity' => $entity,
                        'key' => $key,
                    ];
                }
                $targetIndex[$key] = $row;
            }
        }

        $sourceKeys = array_keys($sourceIndex);
        $targetKeys = array_keys($targetIndex);
        sort($sourceKeys, SORT_STRING);
        sort($targetKeys, SORT_STRING);

        $onlyInSource = [];
        $onlyInTarget = [];
        $changed = [];
        $identicalCount = 0;
        $seen = [];

        foreach ($sourceKeys as $key) {
            $seen[$key] = true;
            $sourceRow = $sourceIndex[$key];
            $targetRow = $targetIndex[$key] ?? null;

            if ($targetRow === null) {
                $comparable = $this->normalizeComparableRecord($entity, $sourceRow, $sourceLookups);
                $onlyInSource[] = [
                    'key' => $key,
                    'record' => $comparable,
                    'checksum' => $this->comparableHash($comparable),
                ];
                continue;
            }

            $sourceComparable = $this->normalizeComparableRecord($entity, $sourceRow, $sourceLookups);
            $targetComparable = $this->normalizeComparableRecord($entity, $targetRow, $targetLookups);

            if ($this->comparableEqual($sourceComparable, $targetComparable)) {
                $identicalCount++;
                continue;
            }

            $changed[] = [
                'key' => $key,
                'source' => $sourceComparable,
                'target' => $targetComparable,
                'source_checksum' => $this->comparableHash($sourceComparable),
                'target_checksum' => $this->comparableHash($targetComparable),
                'changed_fields' => $this->changedFields($sourceComparable, $targetComparable),
            ];
        }

        foreach ($targetKeys as $key) {
            if (isset($seen[$key])) {
                continue;
            }
            $comparable = $this->normalizeComparableRecord($entity, $targetIndex[$key], $targetLookups);
            $onlyInTarget[] = [
                'key' => $key,
                'record' => $comparable,
                'checksum' => $this->comparableHash($comparable),
            ];
        }

        return [
            'entity' => $entity,
            'only_in_source_count' => count($onlyInSource),
            'only_in_target_count' => count($onlyInTarget),
            'changed_count' => count($changed),
            'identical_count' => $identicalCount,
            'only_in_source' => $onlyInSource,
            'only_in_target' => $onlyInTarget,
            'changed' => $changed,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, array<string, array<string, string>>> $lookups
     * @return array<string, array<string, mixed>>
     */
    private function buildEntityIndex(string $entity, array $rows, array $lookups): array
    {
        $out = [];
        foreach ($rows as $row) {
            $key = $this->naturalKeyForEntity($entity, $row, $lookups);
            if ($key !== '') {
                $out[$key] = $row;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $sourceRow
     * @param array<string, array<string, array<string, string>>> $sourceLookups
     * @param array<string, array<string, array<string, string>>> $targetLookups
     * @return array<string, mixed>
     */
    private function buildSyncPayload(string $entity, array $sourceRow, array $sourceLookups, array $targetLookups): array
    {
        $payload = $this->stripImmutableFields($entity, $sourceRow);

        foreach ((self::FK_DEPENDENCIES[$entity] ?? []) as $fkField => $refEntity) {
            $rawSourceId = $this->scalarString($sourceRow[$fkField] ?? null);
            if ($rawSourceId === '' || $rawSourceId === '0') {
                continue;
            }

            $sourceNatural = $this->referenceNaturalByEntity($refEntity, $rawSourceId, $sourceLookups);
            if ($sourceNatural === '') {
                throw new DatabaseException("Unresolvable source foreign key {$fkField}={$rawSourceId}");
            }

            $targetId = $this->referenceIdByNatural($refEntity, $sourceNatural, $targetLookups);
            if ($targetId === '') {
                throw new DatabaseException("Unresolvable target foreign key {$fkField} via {$refEntity}={$sourceNatural}");
            }

            $payload[$fkField] = $targetId;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<string, array<string, string>>> $lookups
     */
    private function naturalKeyForEntity(string $entity, array $row, array $lookups): string
    {
        switch ($entity) {
            case 'aff-networks':
                return $this->scalarString($row['aff_network_name'] ?? null);
            case 'ppc-networks':
                return $this->scalarString($row['ppc_network_name'] ?? null);
            case 'ppc-accounts':
                return $this->scalarString($row['ppc_account_name'] ?? null);
            case 'campaigns':
                return $this->scalarString($row['aff_campaign_name'] ?? null);
            case 'landing-pages':
                return $this->scalarString($row['landing_page_url'] ?? null);
            case 'text-ads':
                return $this->scalarString($row['text_ad_name'] ?? null);
            case 'rotators':
                return 'pub=' . $this->scalarString($row['public_id'] ?? null);
            case 'trackers':
                $campaign = $this->remapForeignKey($row, 'aff_campaign_id', $lookups['campaigns']['by_id'] ?? []);
                $account = $this->remapForeignKey($row, 'ppc_account_id', $lookups['ppc_accounts']['by_id'] ?? []);
                $landing = $this->remapForeignKey($row, 'landing_page_id', $lookups['landing_pages']['by_id'] ?? []);
                $textAd = $this->remapForeignKey($row, 'text_ad_id', $lookups['text_ads']['by_id'] ?? []);
                $rotator = $this->remapForeignKey($row, 'rotator_id', $lookups['rotators']['by_id'] ?? []);

                return implode('|', [
                    'pub=' . $this->scalarString($row['tracker_id_public'] ?? null),
                    'campaign=' . $campaign,
                    'ppc_account=' . $account,
                    'landing_page=' . $landing,
                    'text_ad=' . $textAd,
                    'rotator=' . $rotator,
                    'click_cpc=' . $this->scalarString($row['click_cpc'] ?? null),
                    'click_cpa=' . $this->scalarString($row['click_cpa'] ?? null),
                    'click_cloaking=' . $this->scalarString($row['click_cloaking'] ?? null),
                ]);
            default:
                return $this->scalarString($row['id'] ?? null);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<string, array<string, string>>> $lookups
     * @return array<string, mixed>
     */
    private function normalizeComparableRecord(string $entity, array $row, array $lookups): array
    {
        $out = $this->stripImmutableFields($entity, $row);
        unset($out['etag'], $out['version']);

        switch ($entity) {
            case 'ppc-accounts':
                $out['ppc_network_id'] = $this->remapForeignKey($row, 'ppc_network_id', $lookups['ppc_networks']['by_id'] ?? []);
                break;
            case 'campaigns':
                $out['aff_network_id'] = $this->remapForeignKey($row, 'aff_network_id', $lookups['aff_networks']['by_id'] ?? []);
                break;
            case 'landing-pages':
                $out['aff_campaign_id'] = $this->remapForeignKey($row, 'aff_campaign_id', $lookups['campaigns']['by_id'] ?? []);
                break;
            case 'text-ads':
                $out['aff_campaign_id'] = $this->remapForeignKey($row, 'aff_campaign_id', $lookups['campaigns']['by_id'] ?? []);
                $out['landing_page_id'] = $this->remapForeignKey($row, 'landing_page_id', $lookups['landing_pages']['by_id'] ?? []);
                break;
            case 'rotators':
                $out['default_campaign'] = $this->remapForeignKey($row, 'default_campaign', $lookups['campaigns']['by_id'] ?? []);
                $out['default_lp'] = $this->remapForeignKey($row, 'default_lp', $lookups['landing_pages']['by_id'] ?? []);
                $out['rules'] = $this->normalizeRulesForComparison($row['rules'] ?? [], $lookups);
                break;
            case 'trackers':
                $out['aff_campaign_id'] = $this->remapForeignKey($row, 'aff_campaign_id', $lookups['campaigns']['by_id'] ?? []);
                $out['ppc_account_id'] = $this->remapForeignKey($row, 'ppc_account_id', $lookups['ppc_accounts']['by_id'] ?? []);
                $out['landing_page_id'] = $this->remapForeignKey($row, 'landing_page_id', $lookups['landing_pages']['by_id'] ?? []);
                $out['text_ad_id'] = $this->remapForeignKey($row, 'text_ad_id', $lookups['text_ads']['by_id'] ?? []);
                break;
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    private function stripImmutableFields(string $entity, array $row): array
    {
        $skip = array_flip(self::IMMUTABLE_FIELDS[$entity] ?? []);
        $out = [];
        foreach ($row as $k => $v) {
            if (isset($skip[$k])) {
                continue;
            }
            $out[$k] = $v;
        }
        return $out;
    }

    /** @param array<string, string> $lookup */
    private function remapForeignKey(array $row, string $field, array $lookup): string
    {
        $rawId = $this->scalarString($row[$field] ?? null);
        if ($rawId === '' || $rawId === '0') {
            return '';
        }
        if (isset($lookup[$rawId]) && $lookup[$rawId] !== '') {
            return $lookup[$rawId];
        }
        return 'id:' . $rawId;
    }

    private function normalizeRulesForComparison(mixed $rawRules, array $lookups): array
    {
        if (!is_array($rawRules) || count($rawRules) === 0) {
            return [];
        }

        $normalized = [];
        foreach ($rawRules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $normalized[] = [
                'rule_name' => $this->scalarString($rule['rule_name'] ?? null),
                'splittest' => $this->scalarString($rule['splittest'] ?? null),
                'status' => $this->scalarString($rule['status'] ?? null),
                'criteria' => $this->normalizeRuleCriteria($rule['criteria'] ?? []),
                'redirects' => $this->normalizeRuleRedirects($rule['redirects'] ?? [], $lookups),
            ];
        }

        usort($normalized, function (array $left, array $right): int {
            $a = $this->scalarString($left['rule_name'] ?? null)
                . '|' . $this->scalarString($left['status'] ?? null)
                . '|' . $this->scalarString($left['splittest'] ?? null);
            $b = $this->scalarString($right['rule_name'] ?? null)
                . '|' . $this->scalarString($right['status'] ?? null)
                . '|' . $this->scalarString($right['splittest'] ?? null);
            return $a <=> $b;
        });

        return $normalized;
    }

    private function normalizeRuleCriteria(mixed $rawCriteria): array
    {
        if (!is_array($rawCriteria) || count($rawCriteria) === 0) {
            return [];
        }

        $normalized = [];
        foreach ($rawCriteria as $criterion) {
            if (!is_array($criterion)) {
                continue;
            }
            $normalized[] = [
                'type' => $this->scalarString($criterion['type'] ?? null),
                'statement' => $this->scalarString($criterion['statement'] ?? null),
                'value' => $this->scalarString($criterion['value'] ?? null),
            ];
        }

        usort($normalized, function (array $left, array $right): int {
            $a = $this->scalarString($left['type'] ?? null)
                . '|' . $this->scalarString($left['statement'] ?? null)
                . '|' . $this->scalarString($left['value'] ?? null);
            $b = $this->scalarString($right['type'] ?? null)
                . '|' . $this->scalarString($right['statement'] ?? null)
                . '|' . $this->scalarString($right['value'] ?? null);
            return $a <=> $b;
        });

        return $normalized;
    }

    private function normalizeRuleRedirects(mixed $rawRedirects, array $lookups): array
    {
        if (!is_array($rawRedirects) || count($rawRedirects) === 0) {
            return [];
        }

        $normalized = [];
        foreach ($rawRedirects as $redirect) {
            if (!is_array($redirect)) {
                continue;
            }
            $normalized[] = [
                'redirect_url' => $this->scalarString($redirect['redirect_url'] ?? null),
                'redirect_campaign' => $this->remapForeignKey($redirect, 'redirect_campaign', $lookups['campaigns']['by_id'] ?? []),
                'redirect_lp' => $this->remapForeignKey($redirect, 'redirect_lp', $lookups['landing_pages']['by_id'] ?? []),
                'weight' => $this->scalarString($redirect['weight'] ?? null),
                'name' => $this->scalarString($redirect['name'] ?? null),
            ];
        }

        usort($normalized, function (array $left, array $right): int {
            $a = $this->scalarString($left['name'] ?? null)
                . '|' . $this->scalarString($left['weight'] ?? null)
                . '|' . $this->scalarString($left['redirect_url'] ?? null);
            $b = $this->scalarString($right['name'] ?? null)
                . '|' . $this->scalarString($right['weight'] ?? null)
                . '|' . $this->scalarString($right['redirect_url'] ?? null);
            return $a <=> $b;
        });

        return $normalized;
    }

    /** @param array<string, array<string, array<string, string>>> $lookups */
    private function referenceNaturalByEntity(string $entity, string $sourceId, array $lookups): string
    {
        return match ($entity) {
            'aff-networks' => $lookups['aff_networks']['by_id'][$sourceId] ?? '',
            'ppc-networks' => $lookups['ppc_networks']['by_id'][$sourceId] ?? '',
            'ppc-accounts' => $lookups['ppc_accounts']['by_id'][$sourceId] ?? '',
            'campaigns' => $lookups['campaigns']['by_id'][$sourceId] ?? '',
            'landing-pages' => $lookups['landing_pages']['by_id'][$sourceId] ?? '',
            'text-ads' => $lookups['text_ads']['by_id'][$sourceId] ?? '',
            'rotators' => $lookups['rotators']['by_id'][$sourceId] ?? '',
            default => '',
        };
    }

    /** @param array<string, array<string, array<string, string>>> $lookups */
    private function referenceIdByNatural(string $entity, string $natural, array $lookups): string
    {
        return match ($entity) {
            'aff-networks' => $lookups['aff_networks']['by_natural'][$natural] ?? '',
            'ppc-networks' => $lookups['ppc_networks']['by_natural'][$natural] ?? '',
            'ppc-accounts' => $lookups['ppc_accounts']['by_natural'][$natural] ?? '',
            'campaigns' => $lookups['campaigns']['by_natural'][$natural] ?? '',
            'landing-pages' => $lookups['landing_pages']['by_natural'][$natural] ?? '',
            'text-ads' => $lookups['text_ads']['by_natural'][$natural] ?? '',
            'rotators' => $lookups['rotators']['by_natural'][$natural] ?? '',
            default => '',
        };
    }

    /** @param array<string, mixed> $result */
    private function recordSyncError(array &$result, string $entity, string $key, \Throwable $error, bool $skipErrors): bool
    {
        $result['failed']++;
        $result['errors'][] = sprintf('%s[%s]: %s', $entity, $key, $error->getMessage());
        $msg = strtolower($error->getMessage());
        if (str_contains($msg, 'version mismatch') || str_contains($msg, 'etag')) {
            $this->store->incrementMetric('conflicts', 1);
        }
        return $skipErrors;
    }

    /** @param array<string, array<string, string>> $mappings */
    private function recordMapping(array &$mappings, string $entity, array $sourceRow, array $targetRow): void
    {
        $sourceId = $this->firstStringFromRow($sourceRow, ...self::ENTITY_ID_FIELDS[$entity]);
        $targetId = $this->firstStringFromRow($targetRow, ...self::ENTITY_ID_FIELDS[$entity]);
        if ($sourceId === '' || $targetId === '') {
            return;
        }
        if (!isset($mappings[$entity])) {
            $mappings[$entity] = [];
        }
        $mappings[$entity][$sourceId] = $targetId;
    }

    /** @param array<string, array<string, string>> $mappings */
    private function recordMappingById(array &$mappings, string $entity, array $sourceRow, string $targetId): void
    {
        $sourceId = $this->firstStringFromRow($sourceRow, ...self::ENTITY_ID_FIELDS[$entity]);
        if ($sourceId === '' || $targetId === '') {
            return;
        }
        if (!isset($mappings[$entity])) {
            $mappings[$entity] = [];
        }
        $mappings[$entity][$sourceId] = $targetId;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private function comparableEqual(array $a, array $b): bool
    {
        $left = $a;
        $right = $b;
        $this->sortRecursive($left);
        $this->sortRecursive($right);

        return json_encode($left, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            === json_encode($right, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string, mixed> $row */
    private function comparableHash(array $row): string
    {
        $copy = $row;
        $this->sortRecursive($copy);
        $json = json_encode($copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return sha1((string)microtime(true));
        }
        return sha1($json);
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @return array<int, string>
     */
    private function changedFields(array $a, array $b): array
    {
        $keys = [];
        foreach ($a as $k => $_) {
            $keys[$k] = true;
        }
        foreach ($b as $k => $_) {
            $keys[$k] = true;
        }

        $sorted = array_keys($keys);
        sort($sorted, SORT_STRING);

        $out = [];
        foreach ($sorted as $key) {
            $left = $a[$key] ?? null;
            $right = $b[$key] ?? null;
            if (is_array($left) || is_array($right)) {
                if (!$this->comparableEqual(['value' => $left], ['value' => $right])) {
                    $out[] = $key;
                }
                continue;
            }
            if ($this->scalarString($left) !== $this->scalarString($right)) {
                $out[] = $key;
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $arr */
    private function sortRecursive(array &$arr): void
    {
        foreach ($arr as &$value) {
            if (is_array($value)) {
                $this->sortRecursive($value);
            }
        }
        ksort($arr);
    }

    private function firstStringFromRow(array $row, string ...$keys): string
    {
        foreach ($keys as $key) {
            $val = $this->scalarString($row[$key] ?? null);
            if ($val !== '') {
                return $val;
            }
        }
        return '';
    }

    private function scalarString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return trim($value);
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return trim((string)$value);
    }

    /** @param mixed $raw */
    private function normalizeEntitySet(mixed $raw): array
    {
        $out = [];
        if (!is_array($raw)) {
            return $out;
        }
        foreach ($raw as $value) {
            $entity = trim((string)$value);
            if ($entity !== '') {
                $out[$entity] = true;
            }
        }
        return $out;
    }

    private function pairKey(array $sourceProfile, array $targetProfile): string
    {
        return sha1(strtolower((string)($sourceProfile['url'] ?? '')) . '|' . strtolower((string)($targetProfile['url'] ?? '')));
    }

    private function profileLabel(array $profile): string
    {
        $name = trim((string)($profile['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        return (string)($profile['url'] ?? 'unknown');
    }
}
