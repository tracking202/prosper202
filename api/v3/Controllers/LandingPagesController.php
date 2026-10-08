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
            'landing_page_url'      => ['type' => 's', 'required' => true, 'max_length' => 255],
            'aff_campaign_id'       => ['type' => 'i', 'required' => true, 'range' => self::MEDIUMINT_UNSIGNED],
            'landing_page_nickname' => ['type' => 's', 'required' => true, 'max_length' => 50],
            'leave_behind_page_url' => ['type' => 's', 'nullable' => true, 'max_length' => 255],
            'landing_page_type'     => ['type' => 'i', 'default' => 0, 'range' => self::TINYINT],
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

    /**
     * An advanced landing page (landing_page_type 1) promotes several offers
     * and belongs to no campaign: Setup › Landing Pages stores it with
     * aff_campaign_id 0 and asks for a campaign only for a simple page
     * (type 0). assertLinksOwned() refused that 0 for every page, so POST
     * /landing-pages could not make an advanced page at all.
     */
    #[\Override]
    protected function requiredLinkMayBeNone(string $field, array $clean, ?array $current): bool
    {
        $type = array_key_exists('landing_page_type', $clean) ? $clean['landing_page_type'] : ($current['landing_page_type'] ?? 0);

        return $field === 'aff_campaign_id' && (int) $type === 1;
    }

    #[\Override]
    protected function afterCreate(int $insertId, array $payload): void
    {
        $this->assignPublicId('landing_page_id_public', $insertId);
    }

    /** A list repairs the account's id-less rows once its request has been checked. */
    #[\Override]
    protected function beforeListRead(): void
    {
        $this->repairMissingPublicIds();
    }

    #[\Override]
    public function get(int|string $id): array
    {
        $this->repairMissingPublicIds();
        return parent::get($id);
    }

    /**
     * Landing pages this API created before afterCreate() set a public id
     * have none (NULL; 0 is none as well — no rand-id-rand is 0, and every
     * page holding it would answer lpip=0), so no landing-page code can carry
     * them. The API is where
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
             WHERE user_id = ? AND (landing_page_id_public IS NULL OR landing_page_id_public = 0)'
        );
        $this->bind($stmt, 'i', $this->userId);
        $this->execute($stmt, 'Landing page public id repair failed');
        $stmt->close();
    }
}
