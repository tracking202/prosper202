<?php

declare(strict_types=1);

namespace Tests\Apps\Android;

use Api\V3\Controllers\AppRegistrationsController;
use Api\V3\Controllers\CampaignsController;
use Api\V3\Support\ServerStateStore;
use PHPUnit\Framework\TestCase;
use Prosper202\User\UserDataPurge;

/**
 * Every write of a campaign's app_registration_id reaches the change feed
 * (GET /sync/changes/campaigns) with the value it wrote.
 *
 * The link is written outside the base controller's single UPDATE: a PUT
 * carrying only the link never reached parent::update() and so never
 * recordChange(); a PUT carrying the link and another field recorded the
 * base's snapshot BEFORE the link UPDATE ran, so the feed kept the old link
 * for good; and a registration delete and a user purge unlink campaigns
 * with raw UPDATEs of their own. A poller syncing campaigns from the feed
 * then holds a link the database no longer has.
 *
 * @group integration
 */
final class CampaignLinkChangeFeedTest extends TestCase
{
    use AndroidDatabase {
        setUp as private androidSetUp;
    }

    private string $stateDir = '';
    private string|false $previousStateDir = false;

    protected function setUp(): void
    {
        $this->androidSetUp();
        $this->stateDir = sys_get_temp_dir() . '/p202-rv1-changefeed-' . bin2hex(random_bytes(4));
        $this->previousStateDir = getenv('P202_SERVER_STATE_DIR');
        putenv('P202_SERVER_STATE_DIR=' . $this->stateDir);
        // A second Android app of user 1, for a link to move to.
        self::fixture("INSERT INTO 202_app_registrations SET registration_id=8, user_id=1, platform='android', app_key='com.example.eight',
            app_name='Eight', accept_test_signals=0, attribution_window_days=7, trust_client_revenue=0, app_token='" . str_repeat('8', 64) . "', created_at=1, updated_at=1");
    }

    protected function tearDown(): void
    {
        putenv($this->previousStateDir === false ? 'P202_SERVER_STATE_DIR' : 'P202_SERVER_STATE_DIR=' . $this->previousStateDir);
        if ($this->stateDir !== '' && is_dir($this->stateDir)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->stateDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? rmdir((string) $f) : unlink((string) $f);
            }
            rmdir($this->stateDir);
        }
    }

    /** @return list<array<string, mixed>> the feed's campaign records for campaign 30, oldest first */
    private function feed(): array
    {
        $changes = (new ServerStateStore($this->stateDir))->listChanges('campaigns', null, 1000, 3600);
        $out = [];
        foreach ($changes['data'] as $item) {
            if (is_array($item) && (int) ($item['record']['aff_campaign_id'] ?? 0) === 30) {
                $out[] = $item;
            }
        }

        return $out;
    }

    private static function linkNow(): ?int
    {
        $v = self::$db->query('SELECT app_registration_id FROM 202_aff_campaigns WHERE aff_campaign_id = 30')->fetch_row()[0];

        return $v === null ? null : (int) $v;
    }

    private static function linkIn(array $item): ?int
    {
        $v = $item['record']['app_registration_id'] ?? null;

        return $v === null ? null : (int) $v;
    }

    public function testALinkOnlyUpdateIsInTheFeed(): void
    {
        (new CampaignsController(self::$db, 1))->update(30, ['app_registration_id' => 8]);
        self::assertSame(8, self::linkNow());
        $feed = $this->feed();
        self::assertCount(1, $feed, 'the link-only write is recorded');
        self::assertSame('update', $feed[0]['operation']);
        self::assertSame(8, self::linkIn($feed[0]));

        (new CampaignsController(self::$db, 1))->update(30, ['app_registration_id' => null]);
        $feed = $this->feed();
        self::assertCount(2, $feed);
        self::assertNull(self::linkIn($feed[1]), 'and so is the unlink');
    }

    public function testALinkWithOtherFieldsIsOneWriteRecordedWithTheNewLink(): void
    {
        (new CampaignsController(self::$db, 1))->update(30, ['aff_campaign_name' => 'renamed', 'app_registration_id' => 8]);
        self::assertSame(8, self::linkNow());
        $feed = $this->feed();
        self::assertCount(1, $feed, 'one write, one record');
        self::assertSame('renamed', $feed[0]['record']['aff_campaign_name']);
        self::assertSame(8, self::linkIn($feed[0]), 'the record carries the link as written, not the one before it');
    }

    public function testARefusedFieldLeavesTheLinkAsItWas(): void
    {
        try {
            (new CampaignsController(self::$db, 1))->update(30, ['payout_mode' => 'sometimes', 'app_registration_id' => 8]);
            self::fail('an invalid payout_mode was accepted');
        } catch (\Api\V3\Exception\ValidationException) {
        }
        self::assertSame(5, self::linkNow());
        self::assertSame([], $this->feed());
    }

    public function testARegistrationDeleteRecordsTheCampaignsItUnlinks(): void
    {
        (new AppRegistrationsController(self::$db, 1))->delete(5);
        self::assertNull(self::linkNow());
        $feed = $this->feed();
        self::assertCount(1, $feed);
        self::assertNull(self::linkIn($feed[0]), 'the unlinked campaign as it is now');
    }

    /**
     * The registration's own change record and the campaigns' are each about
     * a write that has committed: one failing must not cost the other, and
     * the delete still says it landed.
     */
    public function testARegistrationDeleteWhoseOwnChangeRecordFailsStillRecordsTheUnlinkedCampaigns(): void
    {
        $apps = new class (self::$db, 1) extends AppRegistrationsController {
            #[\Override]
            protected function recordChange(string $operation, array $record): void
            {
                throw new \RuntimeException('the change log is unavailable');
            }
        };
        try {
            $apps->delete(5);
            self::fail('a failed change record was not reported');
        } catch (\Api\V3\Exception\WriteCommittedException) {
            $this->addToAssertionCount(1);
        }
        self::assertNull(self::linkNow());
        $feed = $this->feed();
        self::assertCount(1, $feed, 'the unlinked campaign reached the feed');
        self::assertNull(self::linkIn($feed[0]));
    }

    public function testAUserPurgeRecordsTheCampaignsItUnlinks(): void
    {
        (new UserDataPurge(self::$db))->deleteUser(1);
        self::assertNull(self::linkNow());
        $feed = $this->feed();
        self::assertCount(1, $feed);
        self::assertNull(self::linkIn($feed[0]));
    }
}
