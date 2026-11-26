<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Controller;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\ValidationException;
use Api\V3\Exception\WriteCommittedException;
use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;
use Prosper202\Setup\AdvancedPageMadeSimple;
use Prosper202\Setup\AdvancedPageTextAds;
use Prosper202\Setup\LandingPageTrackers;

class LandingPagesController extends Controller
{
    /** Set while a page's write holds its lock, having repaired the account's public ids first (inPageTransaction()). */
    private bool $repairDone = false;

    protected function tableName(): string { return '202_landing_pages'; }
    protected function primaryKey(): string { return 'landing_page_id'; }

    #[\Override]
    protected function libraryResource(): ?string
    {
        return 'landing-pages';
    }

    #[\Override]
    protected function listSearchColumns(): array
    {
        return ['landing_page_nickname'];
    }

    #[\Override]
    protected function listSortFields(): array
    {
        return [
            'landing_page_id' => 'landing_page_id',
            'landing_page_nickname' => 'landing_page_nickname',
            'landing_page_url' => 'landing_page_url',
            'aff_campaign_id' => 'aff_campaign_id',
        ];
    }

    protected function deletedColumn(): ?string { return 'landing_page_deleted'; }

    protected function fields(): array
    {
        return [
            // Held to Setup › Landing Pages' rule (HttpUrlPrefix): a host sent without a scheme takes https://.
            'landing_page_url'      => ['type' => 's', 'format' => 'http_url', 'required' => true, 'max_length' => 255],
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

    /**
     * A leave-behind URL sent as a bare host is stored with https://, as
     * Setup › Landing Pages stores it; any other value is kept as sent, as
     * the page keeps it.
     */
    #[\Override]
    protected function validatePayload(array $payload, bool $requireRequired = false, ?array $current = null): array
    {
        $clean = parent::validatePayload($payload, $requireRequired, $current);
        if (is_string($clean['leave_behind_page_url'] ?? null)) {
            $this->completeUrl('leave_behind_page_url', $clean);
            if (mb_strlen($clean['leave_behind_page_url']) > 255) {
                throw new ValidationException('Validation failed', [
                    'leave_behind_page_url' => "Field 'leave_behind_page_url' exceeds max length of 255 once https:// is added",
                ]);
            }
        }

        return $clean;
    }

    /** The answer says which URL was completed: `meta.normalized.<field>` holds what was sent and what is stored. */
    #[\Override]
    public function create(array $payload): array
    {
        return $this->withNormalized(parent::create($payload));
    }

    /**
     * The page is locked before the update reads it and written in the same
     * transaction, so the check that it is not made simple while something
     * uses it (assertLinksOwned()) and the write see the same page. Its
     * change record is written after the commit.
     */
    #[\Override]
    public function update(int|string $id, array $payload): array
    {
        $pageId = (int) $id;

        return $this->withNormalized($this->recordingChangesAfter(
            fn (): array => $this->inPageTransaction(
                fn (LandingPageTrackers $trackers, ?int $type): array => parent::update($id, $payload),
                $pageId
            )
        ));
    }

    /**
     * @param array<string, mixed> $answer
     * @return array<string, mixed>
     */
    private function withNormalized(array $answer): array
    {
        $completed = $this->completedUrls();
        if ($completed !== []) {
            $answer['meta'] = ['normalized' => $completed] + (is_array($answer['meta'] ?? null) ? $answer['meta'] : []);
        }

        return $answer;
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

    /**
     * An advanced page is not made simple while text ads of type 1 or
     * trackers use it: Get Links offers those ads only on an advanced page,
     * and the trackers' links were built for one, so the change left the
     * ads offered nowhere and the trackers pointing at a page that no longer
     * takes advanced traffic, answered 200 (AdvancedPageMadeSimple). The
     * uses are read under the page's lock, in update()'s transaction.
     * Setup › Landing Pages refuses the same change. A page already simple
     * is not refused for ads stored on it before #260 checked them.
     */
    #[\Override]
    protected function assertLinksOwned(array $clean, ?array $current = null): void
    {
        parent::assertLinksOwned($clean, $current);

        $madeSimple = $current !== null && array_key_exists('landing_page_type', $clean)
            && (int) $clean['landing_page_type'] === 0 && (int) ($current['landing_page_type'] ?? 0) === 1;
        if (!$madeSimple) {
            return;
        }
        $pageId = (int) $current['landing_page_id'];
        try {
            $uses = (new AdvancedPageMadeSimple(new Connection($this->db)))->uses($this->userId, $pageId);
        } catch (QueryException $e) {
            throw new DatabaseException('Text ad and tracker lookup failed', $e);
        }
        $ads = $uses['text_ads'] ?? [];
        $named = $uses['trackers'] ?? [];
        if ($ads === [] && $named === []) {
            return;
        }
        $what = [];
        $why = [];
        $steps = [];
        $lists = [];
        if ($ads !== []) {
            $what[] = count($ads) . ' text ' . (count($ads) === 1 ? 'ad' : 'ads') . ' of text_ad_type 1 (text_ad_id '
                . AdvancedPageTextAds::ids($ads) . ')';
            $why[] = 'Get Links offers those ads only on an advanced page, so making it simple would leave them'
                . ' offered nowhere.';
            $steps[] = 'point each text ad at another advanced page (PUT /text-ads/{id} with its landing_page_id) '
                . 'or make it a campaign ad (text_ad_type 0 with aff_campaign_id)';
            $lists[] = "GET /text-ads?filter[landing_page_id]=$pageId&filter[text_ad_type]=1";
        }
        if ($named !== []) {
            $what[] = count($named) . ' ' . (count($named) === 1 ? 'tracker' : 'trackers') . ' (tracker_id '
                . implode(', ', $named) . ')';
            $why[] = 'The trackers were built for an advanced page, so making it simple would leave their links'
                . ' pointing at a page that no longer takes advanced traffic.';
            $steps[] = 'move each tracker to another advanced page (PUT /trackers/{id} with its landing_page_id) '
                . 'or delete it (DELETE /trackers/{id}), which stops its link';
            $lists[] = "GET /trackers?filter[landing_page_id]=$pageId";
        }
        throw new ValidationException('Validation failed', [
            'landing_page_type' => "landing page $pageId is advanced and " . implode(' and ', $what) . ' name it. '
                . implode(' ', $why) . ' First ' . implode(', and ', $steps) . ', then change the type. '
                . implode(' and ', $lists) . (count($lists) === 1 ? ' lists them.' : ' list them.'),
        ]);
    }

    /**
     * An advanced page is not removed while text ads of type 1 or trackers
     * name it: each would be left pointing at a page that is gone, with the
     * delete answered 204. The page is locked first and checked and removed
     * in the same transaction (LandingPageTrackers::lockPage()), so a second
     * write of the page cannot land between the two. Setup › Landing Pages
     * refuses the same delete. A simple page is removed as before.
     */
    #[\Override]
    public function delete(int|string $id): void
    {
        $pageId = (int) $id;
        $deleted = $this->inPageTransaction(function (LandingPageTrackers $trackers, ?int $type) use ($pageId): array {
            $this->refuseWhileLinked($trackers, $pageId, $type);

            return $this->deleteRecord($pageId);
        }, $pageId);
        // After the commit: a failed change record must not roll the delete back (CLAUDE.md #13).
        $this->recordDeleted($deleted);
    }

    /** The preview answers the refusal the delete would: a dry run says what the delete will do. */
    #[\Override]
    public function deletePreview(int|string $id): array
    {
        $pageId = (int) $id;

        return $this->inPageTransaction(function (LandingPageTrackers $trackers, ?int $type) use ($id, $pageId): array {
            $this->refuseWhileLinked($trackers, $pageId, $type);

            return parent::deletePreview($id);
        }, $pageId);
    }

    /**
     * Run $fn with the page's row locked and its type read under the lock
     * (null: no live page of the account's), in a transaction of its own, or
     * in the caller's when one is open (its commit is then the caller's).
     *
     * The account's id-less pages are repaired first, outside it: get()'s
     * repair is an UPDATE over every page of the account, and run under the
     * lock it would take the account's other pages in an order a second
     * page's delete takes them in reverse, a deadlock between two deletes.
     *
     * @template T
     * @param callable(LandingPageTrackers, ?int): T $fn
     * @return T
     */
    private function inPageTransaction(callable $fn, int $pageId): mixed
    {
        $this->repairMissingPublicIds();
        $conn = new Connection($this->db);
        $locked = function () use ($conn, $fn, $pageId): mixed {
            $trackers = new LandingPageTrackers($conn);
            try {
                $type = $trackers->lockPage($this->userId, $pageId);
            } catch (QueryException $e) {
                throw new DatabaseException('Landing page lock failed', $e);
            }

            return $fn($trackers, $type);
        };
        $this->repairDone = true;
        try {
            if ($conn->inTransaction()) {
                return $conn->joinTransaction($locked);
            }
            try {
                return $conn->transaction($locked);
            } catch (WriteCommittedException $e) {
                // Thrown after the write inside the transaction, which rolled the
                // write back with it: what failed is the answer (CLAUDE.md #13).
                // A failed rollback leaves the write's fate unknown, so it stays.
                if ($conn->rollbackFailed()) {
                    throw $e;
                }
                throw $e->getPrevious() ?? $e;
            }
        } finally {
            $this->repairDone = false;
        }
    }

    /**
     * $type is the page's, read under its lock: null (no such page) is
     * answered by the delete's own read, a 404.
     *
     * @throws ConflictException naming the text ads and trackers, when the page is advanced and any name it
     */
    private function refuseWhileLinked(LandingPageTrackers $trackers, int $pageId, ?int $type): void
    {
        if ($type !== 1) {
            return;
        }
        try {
            $ads = (new AdvancedPageTextAds(new Connection($this->db)))->naming($this->userId, $pageId);
            $named = $trackers->naming($this->userId, $pageId);
        } catch (QueryException $e) {
            throw new DatabaseException('Text ad and tracker lookup failed', $e);
        }
        if ($ads === [] && $named === []) {
            return;
        }
        $what = [];
        $steps = [];
        $lists = [];
        if ($ads !== []) {
            $what[] = count($ads) . ' text ' . (count($ads) === 1 ? 'ad' : 'ads') . ' of text_ad_type 1 (text_ad_id '
                . AdvancedPageTextAds::ids($ads) . ')';
            $steps[] = 'point each text ad at another advanced page (PUT /text-ads/{id} with its landing_page_id) '
                . 'or make it a campaign ad (text_ad_type 0 with aff_campaign_id)';
            $lists[] = "GET /text-ads?filter[landing_page_id]=$pageId&filter[text_ad_type]=1";
        }
        if ($named !== []) {
            $what[] = count($named) . ' ' . (count($named) === 1 ? 'tracker' : 'trackers') . ' (tracker_id '
                . implode(', ', $named) . ')';
            $steps[] = 'move each tracker to another page (PUT /trackers/{id} with its landing_page_id) '
                . 'or delete it (DELETE /trackers/{id}), which stops its link';
            $lists[] = "GET /trackers?filter[landing_page_id]=$pageId";
        }
        throw new ConflictException(
            "Landing page $pageId is advanced and " . implode(' and ', $what) . ' name it: '
                . 'deleting it would leave ' . (count($ads) + count($named) === 1 ? 'that link' : 'their links')
                . ' pointing at a page that is gone. First ' . implode(', and ', $steps) . ', then delete the page. '
                . implode(' and ', $lists) . (count($lists) === 1 ? ' lists them.' : ' list them.'),
            [
                'text_ad_ids' => array_map(static fn (array $ad): int => $ad['text_ad_id'], $ads),
                'tracker_ids' => $named,
            ]
        );
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
        if (!$this->repairDone) {
            $this->repairMissingPublicIds();
        }
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
