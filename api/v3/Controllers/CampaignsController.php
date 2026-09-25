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
            'aff_campaign_name'            => ['type' => 's', 'required' => true, 'max_length' => 255],
            'aff_campaign_url'             => ['type' => 's', 'required' => true, 'max_length' => 2048],
            'aff_campaign_url_2'           => ['type' => 's', 'max_length' => 2048],
            'aff_campaign_url_3'           => ['type' => 's', 'max_length' => 2048],
            'aff_campaign_url_4'           => ['type' => 's', 'max_length' => 2048],
            'aff_campaign_url_5'           => ['type' => 's', 'max_length' => 2048],
            'aff_campaign_payout'          => ['type' => 'd', 'required' => true],
            'aff_campaign_currency'        => ['type' => 's', 'max_length' => 5],
            'aff_campaign_foreign_payout'  => ['type' => 'd', 'default' => 0],
            'aff_network_id'               => ['type' => 'i', 'required' => true],
            'aff_campaign_cloaking'        => ['type' => 'i'],
            'aff_campaign_rotate'          => ['type' => 'i'],
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
        ];
    }

    /** The validated link a create is carrying to beforeCreate(), when it sent one. */
    private ?array $pendingRegistrationLink = null;

    #[\Override]
    public function create(array $payload): array
    {
        $this->pendingRegistrationLink = null;
        if (array_key_exists('app_registration_id', $payload)) {
            $this->pendingRegistrationLink = ['value' => $this->registrationLink($payload['app_registration_id'])];
        }
        try {
            return parent::create($payload);
        } finally {
            $this->pendingRegistrationLink = null;
        }
    }

    #[\Override]
    protected function beforeCreate(array $payload): array
    {
        $extras = [
            'aff_campaign_time'      => ['type' => 'i', 'value' => time()],
            'aff_campaign_id_public' => ['type' => 'i', 'value' => random_int(1_000_000, 99_999_999)],
        ];
        if ($this->pendingRegistrationLink !== null) {
            $extras['app_registration_id'] = ['type' => 'i', 'value' => $this->pendingRegistrationLink['value']];
        }

        return $extras;
    }

    #[\Override]
    public function update(int|string $id, array $payload): array
    {
        if (!array_key_exists('app_registration_id', $payload)) {
            return parent::update($id, $payload);
        }
        $link = $this->registrationLink($payload['app_registration_id']);
        unset($payload['app_registration_id']);

        // No transaction of its own: bulk-upsert already wraps this call in
        // one, and mysqli's begin_transaction() inside it would commit the
        // outer (CLAUDE.md #13). The other fields go first, so a payload the
        // base controller refuses changes nothing; the link is written after,
        // and a failure there is reported as a write that already landed.
        if ($payload !== []) {
            parent::update($id, $payload);
        } else {
            $this->get($id); // ownership + existence
        }
        try {
            $stmt = $this->prepare('UPDATE 202_aff_campaigns SET app_registration_id = ? WHERE aff_campaign_id = ? AND user_id = ?');
            $campaignId = (int)$id;
            $this->bind($stmt, 'iii', $link, $campaignId, $this->userId);
            $this->execute($stmt, 'Campaign app link failed');
            $stmt->close();

            return $this->get($id);
        } catch (\Throwable $e) {
            if ($payload === []) {
                throw $e;
            }
            throw new \Api\V3\Exception\WriteCommittedException('campaign', $e);
        }
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
