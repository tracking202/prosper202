<?php

declare(strict_types=1);

namespace Prosper202\Conversion;

use RuntimeException;

final class InMemoryConversionRepository implements ConversionRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $conversions = [];
    private int $nextId = 1;

    /** @var array<int, array<string, mixed>> Simulated clicks table for create() validation */
    public array $clicks = [];

    public function list(int $userId, array $filters, int $offset, int $limit): array
    {
        $filtered = array_filter($this->conversions, function (array $c) use ($userId, $filters): bool {
            if ($c['user_id'] !== $userId || !empty($c['deleted'])) {
                return false;
            }
            if (!empty($filters['campaign_id']) && ($c['campaign_id'] ?? 0) !== (int) $filters['campaign_id']) {
                return false;
            }
            if (!empty($filters['time_from']) && ($c['conv_time'] ?? 0) < (int) $filters['time_from']) {
                return false;
            }
            if (!empty($filters['time_to']) && ($c['conv_time'] ?? 0) > (int) $filters['time_to']) {
                return false;
            }
            return true;
        });

        usort($filtered, fn(array $a, array $b) => ($b['conv_time'] ?? 0) <=> ($a['conv_time'] ?? 0));
        $total = count($filtered);

        return ['rows' => array_slice($filtered, $offset, $limit), 'total' => $total];
    }

    public function findById(int $id, int $userId): ?array
    {
        $conv = $this->conversions[$id] ?? null;
        if ($conv === null || $conv['user_id'] !== $userId || !empty($conv['deleted'])) {
            return null;
        }

        return $conv;
    }

    public function create(int $userId, array $data): int
    {
        $clickId = (int) $data['click_id'];
        if ($clickId <= 0) {
            throw new RuntimeException('click_id is required');
        }

        $click = $this->clicks[$clickId] ?? null;
        if ($click === null || ($click['user_id'] ?? 0) !== $userId) {
            throw new RuntimeException('Click not found or not owned by user');
        }

        $id = $this->nextId++;
        $this->conversions[$id] = [
            'conv_id' => $id,
            'click_id' => $clickId,
            'transaction_id' => (string) ($data['transaction_id'] ?? ''),
            'campaign_id' => (int) ($click['aff_campaign_id'] ?? 0),
            'click_payout' => (float) ($data['payout'] ?? $click['click_payout'] ?? 0),
            'user_id' => $userId,
            'click_time' => (int) ($click['click_time'] ?? 0),
            'conv_time' => (int) ($data['conv_time'] ?? time()),
            'deleted' => 0,
        ];

        // Update click (simulates UPDATE 202_clicks SET click_lead=1)
        $this->clicks[$clickId]['click_lead'] = 1;
        $this->clicks[$clickId]['click_payout'] = $this->conversions[$id]['click_payout'];

        return $id;
    }

    public function softDelete(int $id, int $userId): void
    {
        if (isset($this->conversions[$id]) && $this->conversions[$id]['user_id'] === $userId) {
            $this->conversions[$id]['deleted'] = 1;
        }
    }
}
