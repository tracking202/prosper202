<?php

declare(strict_types=1);

namespace Tests\Bandit;

use Api\V3\Controller;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeMysqliConnection;

/**
 * The v3 CRUD API mutates the same dictionaries the Landing Page
 * Optimizer syncs (campaigns, categories, traffic sources/accounts,
 * landing pages), so the shared post-mutation choke point in the base
 * Controller must flag the user's snapshot dirty exactly like the
 * setup-page save/delete hooks — and must leave the bandit pref alone
 * for unrelated resources.
 */
final class ApiDictionaryDirtyTest extends TestCase
{
    private function landingPagesController(FakeMysqliConnection $db): Controller
    {
        return new class ($db, 7) extends Controller {
            protected function tableName(): string
            {
                return '202_landing_pages';
            }

            protected function primaryKey(): string
            {
                return 'landing_page_id';
            }

            protected function deletedColumn(): ?string
            {
                return 'landing_page_deleted';
            }

            protected function fields(): array
            {
                return ['landing_page_nickname' => ['type' => 's', 'max_length' => 100]];
            }
        };
    }

    private function textAdsController(FakeMysqliConnection $db): Controller
    {
        return new class ($db, 7) extends Controller {
            protected function tableName(): string
            {
                return '202_text_ads';
            }

            protected function primaryKey(): string
            {
                return 'text_ad_id';
            }

            protected function deletedColumn(): ?string
            {
                return 'text_ad_deleted';
            }

            protected function fields(): array
            {
                return ['text_ad_name' => ['type' => 's', 'max_length' => 100]];
            }
        };
    }

    /** @param callable(FakeMysqliConnection): void $fn */
    private function withIsolatedStateStore(callable $fn): void
    {
        $stateDir = sys_get_temp_dir() . '/p202-bandit-dirty-' . bin2hex(random_bytes(4));
        mkdir($stateDir, 0700, true);
        putenv('P202_SERVER_STATE_DIR=' . $stateDir);

        try {
            $fn(new FakeMysqliConnection());
        } finally {
            putenv('P202_SERVER_STATE_DIR');
            foreach (glob($stateDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($stateDir);
        }
    }

    public function testApiDeleteOfASyncedDictionaryMarksTheSnapshotDirty(): void
    {
        $this->withIsolatedStateStore(function (FakeMysqliConnection $db): void {
            $db->whenQueryContainsReturnRows('FROM 202_landing_pages', [
                ['landing_page_id' => 18, 'landing_page_nickname' => 'Blue LP', 'user_id' => 7],
            ]);
            $db->whenQueryContainsReturnRows('SELECT bandit_status, bandit_bridge_config', [
                ['bandit_status' => 'active', 'bandit_bridge_config' => '{"webhook_id":3}'],
            ]);
            $db->whenQueryContainsReturnRows('SELECT bandit_bridge_config', [
                ['bandit_bridge_config' => '{"webhook_id":3}'],
            ]); // markDirty persists via the CAS, which re-reads fresh bytes
            $db->whenQueryContainsAffectedRows('UPDATE 202_users_pref', 1); // the guarded write lands first try

            $this->landingPagesController($db)->delete(18);

            $updates = $db->statementsContaining('UPDATE 202_users_pref SET bandit_bridge_config');
            self::assertCount(1, $updates, 'an API soft-delete must markDirty like the setup-page delete does');
            $state = json_decode((string) $updates[0]->boundValues[0], true);
            self::assertTrue($state['dims_dirty']);
            self::assertSame(7, $updates[0]->boundValues[1], 'flags the authenticated API user, not someone else');
        });
    }

    public function testApiUpdateOfASyncedDictionaryMarksTheSnapshotDirty(): void
    {
        $this->withIsolatedStateStore(function (FakeMysqliConnection $db): void {
            $db->whenQueryContainsReturnRows('FROM 202_landing_pages', [
                ['landing_page_id' => 18, 'landing_page_nickname' => 'Blue LP', 'user_id' => 7],
            ]);
            $db->whenQueryContainsReturnRows('SELECT bandit_status, bandit_bridge_config', [
                ['bandit_status' => 'active', 'bandit_bridge_config' => '{"webhook_id":3}'],
            ]);
            $db->whenQueryContainsReturnRows('SELECT bandit_bridge_config', [
                ['bandit_bridge_config' => '{"webhook_id":3}'],
            ]); // markDirty persists via the CAS, which re-reads fresh bytes
            $db->whenQueryContainsAffectedRows('UPDATE 202_users_pref', 1); // the guarded write lands first try

            $this->landingPagesController($db)->update(18, ['landing_page_nickname' => 'Renamed LP']);

            $updates = $db->statementsContaining('UPDATE 202_users_pref SET bandit_bridge_config');
            self::assertCount(1, $updates, 'an API rename must markDirty so Bandit does not keep the stale name');
        });
    }

    public function testApiMutationOfAnUnrelatedResourceLeavesTheBanditPrefAlone(): void
    {
        $this->withIsolatedStateStore(function (FakeMysqliConnection $db): void {
            $db->whenQueryContainsReturnRows('FROM 202_text_ads', [
                ['text_ad_id' => 4, 'text_ad_name' => 'Ad', 'user_id' => 7],
            ]);

            $this->textAdsController($db)->delete(4);

            self::assertSame([], $db->statementsContaining('202_users_pref'), 'non-dictionary resources must not touch the bandit pref at all');
        });
    }
}
