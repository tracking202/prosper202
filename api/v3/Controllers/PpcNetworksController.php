<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Controller;
use Api\V3\Exception\DatabaseException;
use Api\V3\Support\PayloadKeys;
use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;
use Prosper202\Setup\ChatGptAdsSource;

class PpcNetworksController extends Controller
{
    protected function tableName(): string { return '202_ppc_networks'; }
    protected function primaryKey(): string { return 'ppc_network_id'; }
    protected function deletedColumn(): ?string { return 'ppc_network_deleted'; }

    protected function fields(): array
    {
        return [
            'ppc_network_name' => ['type' => 's', 'required' => true, 'max_length' => 50],
        ];
    }

    /**
     * POST /ppc-networks/chatgpt-ads (ChatGptAdsSource::add()). The body is
     * empty: there is nothing to choose.
     *
     * @param array<array-key, mixed> $payload
     * @return array{data: array{ppc_network_id: int, ppc_account_id: int, source_created: bool, account_created: bool, variables_added: list<string>}}
     */
    public function addChatGptAds(array $payload): array
    {
        PayloadKeys::refuseUnknown($payload, [], 'Add ChatGPT Ads');
        try {
            return ['data' => (new ChatGptAdsSource(new Connection($this->db)))->add($this->userId)];
        } catch (QueryException $e) {
            throw new DatabaseException('Add ChatGPT Ads failed', $e);
        }
    }

    #[\Override]
    protected function beforeCreate(array $payload): array
    {
        return [
            'ppc_network_time' => ['type' => 'i', 'value' => time()],
        ];
    }
}
