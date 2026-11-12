<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Controller;
use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\ValidationException;
use Api\V3\Exception\WriteCommittedException;
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
            // The ad platform a source is (ChatGptAdsSource::PLATFORM), or ''.
            // Writable so p202 sync and import carry it to another install.
            'ppc_network_platform' => ['type' => 's', 'default' => '', 'allowed' => ['', ChatGptAdsSource::PLATFORM], 'max_length' => 32],
        ];
    }

    /** The allowed list's own message would read "one of: , chatgpt_ads". */
    #[\Override]
    protected function validatePayload(array $payload, bool $requireRequired = false, ?array $current = null): array
    {
        if (array_key_exists('ppc_network_platform', $payload)
            && !in_array($payload['ppc_network_platform'], ['', ChatGptAdsSource::PLATFORM], true)) {
            throw new ValidationException('Validation failed', [
                'ppc_network_platform' => "Must be '' (no ad platform) or '" . ChatGptAdsSource::PLATFORM
                    . "' (the ChatGPT Ads source, which POST /ppc-networks/chatgpt-ads sets up with its account and variables)",
            ]);
        }

        return parent::validatePayload($payload, $requireRequired, $current);
    }

    /**
     * POST /ppc-networks/chatgpt-ads (ChatGptAdsSource::add()). The body is
     * empty: there is nothing to choose.
     *
     * @param array<array-key, mixed> $payload
     * @return array{data: array{ppc_network_id: int, ppc_account_id: int, source_created: bool, source_adopted: bool, account_created: bool, variables_added: list<string>}}
     */
    public function addChatGptAds(array $payload): array
    {
        PayloadKeys::refuseUnknown($payload, [], 'Add ChatGPT Ads');
        try {
            $made = (new ChatGptAdsSource(new Connection($this->db)))->add($this->userId);
        } catch (QueryException $e) {
            throw new DatabaseException('Add ChatGPT Ads failed', $e);
        }

        // What every writer of these tables does after its write (CLAUDE.md
        // #25, #32), as create() does through writeChange(): the Landing Page
        // Optimizer's dimension snapshot is marked for its next push, and the
        // change feed (GET /changes/ppc-networks, /changes/ppc-accounts) lists
        // what was made. The rows are committed, so a failure here is answered
        // as a committed write, never as one to retry (#13).
        try {
            if ($made['source_created'] || $made['source_adopted'] || $made['account_created'] || $made['variables_added'] !== []) {
                \Prosper202\Lpo\DimensionSync::markDirty($this->db, $this->userId);
            }
            if ($made['source_created'] || $made['source_adopted']) {
                $this->stateStore()->recordChange('ppc-networks', $made['source_created'] ? 'create' : 'update', $this->get($made['ppc_network_id'])['data'], $this->userId);
            }
            if ($made['account_created']) {
                $account = (new PpcAccountsController($this->db, $this->userId))->get($made['ppc_account_id'])['data'];
                $this->stateStore()->recordChange('ppc-accounts', 'create', $account, $this->userId);
            }
        } catch (\Throwable $e) {
            throw new WriteCommittedException('ChatGPT Ads traffic source', $e);
        }

        return ['data' => $made];
    }

    #[\Override]
    protected function beforeCreate(array $payload): array
    {
        return [
            'ppc_network_time' => ['type' => 'i', 'value' => time()],
        ];
    }
}
