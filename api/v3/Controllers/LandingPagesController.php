<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Controller;

class LandingPagesController extends Controller
{
    protected function tableName(): string { return '202_landing_pages'; }
    protected function primaryKey(): string { return 'landing_page_id'; }
    protected function deletedColumn(): ?string { return 'landing_page_deleted'; }

    protected function fields(): array
    {
        return [
            'landing_page_url'      => ['type' => 's', 'required' => true, 'max_length' => 2048],
            'aff_campaign_id'       => ['type' => 'i', 'required' => true],
            'landing_page_nickname' => ['type' => 's', 'required' => true, 'max_length' => 50],
            'leave_behind_page_url' => ['type' => 's', 'max_length' => 2048],
            'landing_page_type'     => ['type' => 'i', 'default' => 0],
            // The id the landing-page code and the go/lp redirects carry
            // (lpip=…). Set by afterCreate(); before it was, a landing page
            // made through the API had none, and no code could track it.
            'landing_page_id_public' => ['type' => 'i', 'readonly' => true],
        ];
    }

    #[\Override]
    protected function beforeCreate(array $payload): array
    {
        return [
            'landing_page_time' => ['type' => 'i', 'value' => time()],
        ];
    }

    #[\Override]
    protected function afterCreate(int $insertId, array $payload): void
    {
        $this->assignPublicId('landing_page_id_public', $insertId);
    }

    #[\Override]
    public function list(array $params): array
    {
        $this->repairMissingPublicIds();
        return parent::list($params);
    }

    #[\Override]
    public function get(int|string $id): array
    {
        $this->repairMissingPublicIds();
        return parent::get($id);
    }

    /**
     * Landing pages this API created before afterCreate() set a public id
     * have none, so no landing-page code can carry them. The API is where
     * such a page's id is read, so it gives this account's id-less pages one
     * there, the setup page's way (rand-id-rand, a leading digit that fits
     * INT UNSIGNED), before answering: one indexed UPDATE, a no-op once
     * every page has one. Rows that have an id are never touched.
     *
     * Public for the landing-page code (SetupCodeController), which reads
     * the public id it writes into every snippet.
     */
    public function repairMissingPublicIds(): void
    {
        $stmt = $this->prepare(
            'UPDATE 202_landing_pages
             SET landing_page_id_public = CAST(CONCAT(
                 FLOOR(1 + RAND() * IF(landing_page_id >= 10000000, 4, 9)), landing_page_id, FLOOR(1 + RAND() * 9)
             ) AS UNSIGNED)
             WHERE user_id = ? AND landing_page_id_public IS NULL'
        );
        $this->bind($stmt, 'i', $this->userId);
        $this->execute($stmt, 'Landing page public id repair failed');
        $stmt->close();
    }
}
