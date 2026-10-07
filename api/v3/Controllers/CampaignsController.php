<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Controller;

class CampaignsController extends Controller
{
    protected function tableName(): string { return '202_aff_campaigns'; }
    protected function primaryKey(): string { return 'aff_campaign_id'; }
    protected function deletedColumn(): ?string { return 'aff_campaign_deleted'; }

    protected function fields(): array
    {
        return [
            'aff_campaign_name'            => ['type' => 's', 'required' => true, 'max_length' => 50],
            'aff_campaign_url'             => ['type' => 's', 'required' => true, 'max_length' => 2048],
            'aff_campaign_url_2'           => ['type' => 's', 'nullable' => true, 'max_length' => 2048],
            'aff_campaign_url_3'           => ['type' => 's', 'nullable' => true, 'max_length' => 2048],
            'aff_campaign_url_4'           => ['type' => 's', 'nullable' => true, 'max_length' => 2048],
            'aff_campaign_url_5'           => ['type' => 's', 'nullable' => true, 'max_length' => 2048],
            'aff_campaign_payout'          => ['type' => 'd', 'required' => true, 'range' => [-999999.99, 999999.99]],
            'aff_campaign_currency'        => ['type' => 's', 'max_length' => 3],
            'aff_campaign_foreign_payout'  => ['type' => 'd', 'default' => 0, 'range' => [-999999.99, 999999.99]],
            'aff_network_id'               => ['type' => 'i', 'required' => true, 'range' => self::MEDIUMINT_UNSIGNED],
            'aff_campaign_cloaking'        => ['type' => 'i', 'range' => self::TINYINT],
            'aff_campaign_rotate'          => ['type' => 'i', 'range' => self::TINYINT],
            // How a click's conversions roll up into its value: the latest
            // one's payout (replace) or their sum (accumulate).
            'payout_mode'                  => ['type' => 's', 'allowed' => ['replace', 'accumulate']],
            // Whether this campaign's clicks carry identity signals (the
            // p202vid cookie, p202lpid, signed customer ids). Compared as the
            // exact string before any cast, so 1.5 or "1e0" is refused rather
            // than read as 1 (CLAUDE.md #18).
            'identity_signals'             => ['type' => 's', 'allowed' => ['0', '1']],
            // The Android app this campaign's store links install (plan
            // §5.4): an install of another app on its click is
            // foreign_click. Written only through create()/update() below,
            // which read the raw value; null or 0 unlinks.
            'app_registration_id'          => ['type' => 'i', 'readonly' => true],
            // The campaign's attribution model, overriding the account's
            // default (Setup > Campaigns > Attribution model): one of the
            // caller's own models, or null/0 for the default. Written, like
            // the app link, only through create()/update() below.
            'attribution_model_id'         => ['type' => 'i', 'readonly' => true],
            // The id advanced landing-page code and go.php carry (acip=…),
            // set by afterCreate() the way the setup page sets it.
            'aff_campaign_id_public'       => ['type' => 'i', 'readonly' => true],
        ];
    }

    #[\Override]
    protected function handledKeys(): array
    {
        return ['app_registration_id', 'attribution_model_id'];
    }

    /**
     * The validated links a create or an update is carrying to
     * beforeCreate()/beforeUpdate(): column => id, or null to unlink.
     *
     * @var array<string, ?int>
     */
    private array $pendingLinks = [];

    /**
     * The links in a payload, each read from its raw value and checked.
     *
     * @param array<string, mixed> $payload
     * @return array<string, ?int>
     */
    private function linksIn(array $payload): array
    {
        $links = [];
        if (array_key_exists('app_registration_id', $payload)) {
            $links['app_registration_id'] = $this->registrationLink($payload['app_registration_id']);
        }
        if (array_key_exists('attribution_model_id', $payload)) {
            $links['attribution_model_id'] = $this->attributionModelLink($payload['attribution_model_id']);
        }

        return $links;
    }

    #[\Override]
    public function create(array $payload): array
    {
        $this->pendingLinks = $this->linksIn($payload);
        try {
            // The links are read here and written through beforeCreate();
            // the base sees only the fields it writes, so it refuses any other
            // key (a read-only one included) rather than this controller
            // having to.
            return parent::create(array_diff_key($payload, $this->pendingLinks));
        } finally {
            $this->pendingLinks = [];
        }
    }

    #[\Override]
    protected function beforeCreate(array $payload): array
    {
        $extras = [
            'aff_campaign_time'      => ['type' => 'i', 'value' => time()],
        ];
        foreach ($this->pendingLinks as $column => $value) {
            $extras[$column] = ['type' => 'i', 'value' => $value];
        }

        return $extras;
    }

    /**
     * The public id: the setup page's rand-id-rand, unique by construction.
     * It was a random 8-digit number, which go.php resolves across every
     * account, so two campaigns could share one.
     */
    #[\Override]
    protected function afterCreate(int $insertId, array $payload): void
    {
        $this->assignPublicId('aff_campaign_id_public', $insertId);
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
     * A campaign with no public id cannot be tracked: go.php and the
     * advanced landing-page code carry acip=<public id>, and the offer
     * redirects resolve the campaign by it. The setup page gives a new
     * campaign one with an UPDATE after its INSERT (aff_campaigns.php), so a
     * campaign whose UPDATE failed, or one from before the column was
     * filled, has none — NULL, or 0, which is no id either: 0 is not a
     * rand-id-rand, and every campaign holding it would answer acip=0.
     *
     * The landing-page code endpoint gave such a campaign one when it named
     * it (SetupCodeController), and the API's own reads did not, so the
     * campaign the API listed had no id to put in a link. Like
     * LandingPagesController::repairMissingPublicIds(), every read here gives
     * this account's id-less campaigns the setup page's form first (a random
     * digit, the row id, a random digit — unique by construction — with a
     * leading digit that keeps it inside INT UNSIGNED): one indexed UPDATE, a
     * no-op once every campaign has one. A campaign that has an id is never
     * touched.
     *
     * Public for the landing-page code (SetupCodeController), which writes
     * the campaign's public id into every outbound link it builds.
     */
    public function repairMissingPublicIds(): void
    {
        $stmt = $this->prepare(
            'UPDATE 202_aff_campaigns
             SET aff_campaign_id_public = CAST(CONCAT(
                 FLOOR(1 + RAND() * IF(aff_campaign_id >= 10000000, 4, 9)), aff_campaign_id, FLOOR(1 + RAND() * 9)
             ) AS UNSIGNED)
             WHERE user_id = ? AND (aff_campaign_id_public IS NULL OR aff_campaign_id_public = 0)'
        );
        $this->bind($stmt, 'i', $this->userId);
        $this->execute($stmt, 'Campaign public id repair failed');
        $stmt->close();
    }

    #[\Override]
    public function update(int|string $id, array $payload): array
    {
        $links = $this->linksIn($payload);
        if ($links === []) {
            return parent::update($id, $payload);
        }
        foreach (array_keys($links) as $column) {
            unset($payload[$column]);
        }

        if ($payload !== []) {
            // One UPDATE: the other fields and the links together, through
            // beforeUpdate(). The base records the change after it, so the
            // change feed's record carries the links as written — a separate
            // link UPDATE after parent::update() left the feed holding the
            // old link for good. A payload the base refuses changes nothing.
            $this->pendingLinks = $links;
            try {
                return parent::update($id, $payload);
            } catch (\Api\V3\Exception\NothingToUpdateException) {
                // The rest of the body is read-only values the campaign
                // already holds (a GET body sent back with a new link), which
                // the base checked: the links are the whole write.
            } finally {
                $this->pendingLinks = [];
            }
        }

        // The links alone: the base refuses a payload with no writable field,
        // so they are their own UPDATE — with the base's preconditions
        // (ownership, If-Match) and its change record. No transaction of its
        // own: bulk-upsert already wraps this call in one, and mysqli's
        // begin_transaction() inside it would commit the outer (CLAUDE.md
        // #13).
        $current = $this->get($id);
        $this->assertIfMatchSatisfied((array)$current['data']);
        // The column names come from linksIn(), never from the request.
        $sets = implode(', ', array_map(static fn (string $column): string => "$column = ?", array_keys($links)));
        $stmt = $this->prepare("UPDATE 202_aff_campaigns SET $sets WHERE aff_campaign_id = ? AND user_id = ?");
        $campaignId = (int)$id;
        $this->bind($stmt, str_repeat('i', count($links)) . 'ii', ...[...array_values($links), $campaignId, $this->userId]);
        $this->execute($stmt, 'Campaign link failed');
        $stmt->close();

        // As in the base update(): the write has landed, so a later failure
        // must not read as "the update did not happen".
        try {
            $updated = $this->get($id);
            $this->recordChange('update', (array)$updated['data']);
        } catch (\Throwable $e) {
            throw new \Api\V3\Exception\WriteCommittedException('campaign', $e);
        }

        return $updated;
    }

    #[\Override]
    protected function beforeUpdate(int|string $id, array $payload): array
    {
        $extras = [];
        foreach ($this->pendingLinks as $column => $value) {
            $extras[$column] = ['type' => 'i', 'value' => $value];
        }

        return $extras;
    }

    /**
     * Put each campaign's current state in the change feed, for a write that
     * changed it from outside this controller: a registration delete and a
     * user purge unlink every campaign linked to the registrations they
     * remove, with an UPDATE of their own. Call it after that write has
     * committed. A campaign that is deleted, or no longer this user's, is
     * not in the feed and is skipped.
     *
     * @param list<int> $campaignIds
     */
    public function recordLinkChanges(array $campaignIds): void
    {
        foreach ($campaignIds as $campaignId) {
            try {
                $record = (array)$this->get($campaignId)['data'];
            } catch (\Api\V3\Exception\NotFoundException) {
                continue;
            }
            $this->recordChange('update', $record);
        }
    }

    /**
     * The attribution model a campaign may name, read from the RAW value as
     * the setup page reads it: null, 0 or '' is the account's default model;
     * otherwise a canonical positive id naming one of the caller's own
     * models (ModelRepository::row, which the page uses). Anything else is a
     * 422, never a cast to some other id.
     */
    private function attributionModelLink(mixed $raw): ?int
    {
        if ($raw === null || $raw === 0 || $raw === '0' || $raw === '') {
            return null;
        }
        $text = is_int($raw) ? (string)$raw : (is_string($raw) ? $raw : null);
        if ($text === null || preg_match('/^[1-9][0-9]{0,9}$/D', $text) !== 1 || (int)$text > 2147483647) {
            throw new \Api\V3\Exception\ValidationException('Invalid attribution_model_id', [
                'attribution_model_id' => 'must be one of your attribution model ids (GET /attribution/models), or null for the account default',
            ]);
        }
        $modelId = (int)$text;
        $model = (new \Prosper202\Attribution\ModelRepository(new \Prosper202\Database\Connection($this->db)))->row($this->userId, $modelId);
        if ($model === null) {
            throw new \Api\V3\Exception\ValidationException('Unknown attribution model', [
                'attribution_model_id' => 'model ' . $modelId . ' is not one of yours (GET /attribution/models lists them)',
            ]);
        }

        return $modelId;
    }

    /**
     * The registration a campaign may be linked to, read from the RAW value
     * before anything casts it (CLAUDE.md #18): null or 0 unlinks; otherwise
     * a canonical positive id (a JSON integer or its digits) naming one of
     * the caller's own ANDROID registrations. Anything else is a 422, never
     * a cast to some other id.
     */
    private function registrationLink(mixed $raw): ?int
    {
        if ($raw === null || $raw === 0 || $raw === '0') {
            return null;
        }
        $text = is_int($raw) ? (string)$raw : (is_string($raw) ? $raw : null);
        if ($text === null || preg_match('/^[1-9][0-9]{0,9}$/D', $text) !== 1 || (int)$text > 4294967295) {
            throw new \Api\V3\Exception\ValidationException('Invalid app_registration_id', [
                'app_registration_id' => 'must be an Android registration id from GET /apps?platform=android, or null to unlink',
            ]);
        }
        $registrationId = (int)$text;
        $stmt = $this->prepare('SELECT platform FROM 202_app_registrations WHERE registration_id = ? AND user_id = ? LIMIT 1');
        $this->bind($stmt, 'ii', $registrationId, $this->userId);
        $this->execute($stmt, 'Registration lookup failed');
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new \Api\V3\Exception\DatabaseException('Registration lookup failed');
        }
        $row = $result->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) {
            throw new \Api\V3\Exception\ValidationException('Unknown app registration', [
                'app_registration_id' => 'registration ' . $registrationId . ' is not one of yours (GET /apps lists them)',
            ]);
        }
        if ((string)$row['platform'] !== \Api\V3\Apps\AppIdentity::ANDROID) {
            throw new \Api\V3\Exception\ValidationException('Not an Android app', [
                'app_registration_id' => 'registration ' . $registrationId . ' is an iOS app; a campaign links the Android app its store links install',
            ]);
        }

        return $registrationId;
    }
}
