<?php

declare(strict_types=1);

namespace Tests\Bandit;

use PHPUnit\Framework\TestCase;
use Prosper202\Bandit\CtxToken;
use Prosper202\Bandit\DimensionSync;
use Prosper202\Bandit\PairingClient;
use Prosper202\Bandit\PairingRequestException;
use Prosper202\Database\Connection;
use Tests\Support\FakeMysqliConnection;

/**
 * The on-change dimension push (segments-v2 G10): the CRUD-side dirty flag
 * lives INSIDE the bandit_bridge_config JSON pref (no new column), marking
 * is gated on an active pairing and can never throw, and the shared
 * pushForUser/clearDirty pair keeps the flag set whenever a push fails so
 * the nightly full sync stays the backstop. No DB, no live HTTP anywhere.
 */
final class DimensionSyncTest extends TestCase
{
    private const CTX_KEY_HEX = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @var list<array{method: string, url: string, body: ?string, headers: array<int, string>}> */
    private array $calls = [];

    private function client(int $status = 200, string $responseBody = '{"status":"accepted"}'): PairingClient
    {
        $this->calls = [];

        return new PairingClient('https://saas.example', function (string $method, string $url, ?string $body, array $extraHeaders = []) use ($status, $responseBody): array {
            $this->calls[] = ['method' => $method, 'url' => $url, 'body' => $body, 'headers' => $extraHeaders];

            return [$status, $responseBody];
        });
    }

    // --- markDirty: the CRUD save-path hook ---

    public function testMarkDirtyFlagsInsideBridgeConfigPreservingExistingKeys(): void
    {
        $fake = new FakeMysqliConnection();
        $fake->whenQueryContainsReturnRows('SELECT bandit_status, bandit_bridge_config', [
            ['bandit_status' => 'active', 'bandit_bridge_config' => '{"webhook_id":3,"ctx_key":"' . self::CTX_KEY_HEX . '"}'],
        ]);

        DimensionSync::markDirty($fake, 7);

        $updates = $fake->statementsContaining('UPDATE 202_users_pref SET bandit_bridge_config');
        self::assertCount(1, $updates, 'exactly one pref write, no new column anywhere');
        $state = json_decode((string) $updates[0]->boundValues[0], true);
        self::assertSame(3, $state['webhook_id'], 'existing pairing state survives the re-encode');
        self::assertSame(self::CTX_KEY_HEX, $state['ctx_key']);
        self::assertTrue($state['dims_dirty']);
        self::assertIsInt($state['dims_dirty_at']);
        self::assertEqualsWithDelta(time(), $state['dims_dirty_at'], 5);
        self::assertSame(7, $updates[0]->boundValues[1]);
    }

    public function testMarkDirtyBumpsDirtyAtOnEverySaveSoMidPushChangesSurvive(): void
    {
        $fake = new FakeMysqliConnection();
        $fake->whenQueryContainsReturnRows('SELECT bandit_status, bandit_bridge_config', [
            ['bandit_status' => 'active', 'bandit_bridge_config' => '{"webhook_id":3,"dims_dirty":true,"dims_dirty_at":100}'],
        ]);

        DimensionSync::markDirty($fake, 7);

        $updates = $fake->statementsContaining('UPDATE 202_users_pref SET bandit_bridge_config');
        self::assertCount(1, $updates);
        $state = json_decode((string) $updates[0]->boundValues[0], true);
        self::assertTrue($state['dims_dirty']);
        self::assertNotSame(100, $state['dims_dirty_at'], 'a fresh save must move dims_dirty_at, or clearDirty could not detect it');
        self::assertEqualsWithDelta(time(), $state['dims_dirty_at'], 5);
    }

    public function testMarkDirtyTokenMovesEvenForSavesWithinTheSameSecond(): void
    {
        // The previous save stamped the CURRENT wall-clock second. A
        // whole-second time() would reuse that value (byte-identical pref
        // JSON), so a push that snapshotted between the two saves would
        // pass both clearDirty guards and clear a flag whose snapshot
        // missed this save. The token must be strictly monotonic instead.
        $now = time();
        $fake = new FakeMysqliConnection();
        $fake->whenQueryContainsReturnRows('SELECT bandit_status, bandit_bridge_config', [
            ['bandit_status' => 'active', 'bandit_bridge_config' => '{"webhook_id":3,"dims_dirty":true,"dims_dirty_at":' . $now . '}'],
        ]);

        DimensionSync::markDirty($fake, 7);

        $updates = $fake->statementsContaining('UPDATE 202_users_pref SET bandit_bridge_config');
        self::assertCount(1, $updates);
        $state = json_decode((string) $updates[0]->boundValues[0], true);
        self::assertGreaterThan($now, $state['dims_dirty_at'], 'same-second saves must still produce a new token');
    }

    public function testMarkDirtyIsANoOpUnlessActivelyPaired(): void
    {
        // Paired-off user: pref row exists but bandit_status is ''.
        $fake = new FakeMysqliConnection();
        $fake->whenQueryContainsReturnRows('SELECT bandit_status, bandit_bridge_config', [
            ['bandit_status' => '', 'bandit_bridge_config' => ''],
        ]);
        DimensionSync::markDirty($fake, 7);
        self::assertSame([], $fake->statementsContaining('UPDATE 202_users_pref'));

        // Unknown user: no pref row at all.
        $fake = new FakeMysqliConnection();
        DimensionSync::markDirty($fake, 7);
        self::assertSame([], $fake->statementsContaining('UPDATE 202_users_pref'));
    }

    public function testMarkDirtyNeverThrows(): void
    {
        // No connection at all (pre-bootstrap edge on a broken page).
        DimensionSync::markDirty(null, 7);

        // The pref read fails hard (covers DB outage and the pre-upgrade
        // schema where the bandit columns do not exist yet).
        $fake = new FakeMysqliConnection();
        $fake->whenQueryContainsExecuteReturns('SELECT bandit_status, bandit_bridge_config', false);
        DimensionSync::markDirty($fake, 7);

        self::assertSame([], $fake->statementsContaining('UPDATE 202_users_pref'));
        $this->addToAssertionCount(1); // reaching here proves the admin save survived
    }

    // --- clearDirty: only after a successful push, and only if still current ---

    public function testClearDirtyRemovesTheFlagWhenNoNewerSaveLanded(): void
    {
        $rawPref = '{"webhook_id":3,"ctx_key":"' . self::CTX_KEY_HEX . '","dims_dirty":true,"dims_dirty_at":100}';
        $fake = new FakeMysqliConnection();
        $fake->whenQueryContainsReturnRows('SELECT bandit_bridge_config', [
            ['bandit_bridge_config' => $rawPref],
        ]);

        DimensionSync::clearDirty(new Connection($fake), 7, 100);

        $updates = $fake->statementsContaining('UPDATE 202_users_pref SET bandit_bridge_config');
        self::assertCount(1, $updates);
        $state = json_decode((string) $updates[0]->boundValues[0], true);
        self::assertArrayNotHasKey('dims_dirty', $state);
        self::assertArrayNotHasKey('dims_dirty_at', $state);
        self::assertSame(3, $state['webhook_id'], 'the rest of the pairing state is untouched');
        self::assertSame(self::CTX_KEY_HEX, $state['ctx_key']);

        // The clear is a compare-and-set on the exact bytes read, so a
        // markDirty() racing in after the read makes this UPDATE match zero
        // rows instead of clobbering the newer dirty flag (the PHP
        // dims_dirty_at precheck alone cannot close that window).
        self::assertStringContainsString('AND bandit_bridge_config = ?', $updates[0]->sql);
        self::assertSame($rawPref, $updates[0]->boundValues[2], 'the CAS guard binds the pref bytes exactly as read');
        self::assertSame(7, $updates[0]->boundValues[1]);
    }

    public function testClearDirtyKeepsTheFlagWhenADictionarySaveRacedThePush(): void
    {
        $fake = new FakeMysqliConnection();
        $fake->whenQueryContainsReturnRows('SELECT bandit_bridge_config', [
            ['bandit_bridge_config' => '{"webhook_id":3,"dims_dirty":true,"dims_dirty_at":200}'],
        ]);

        // The push started when dims_dirty_at was 100; a save moved it to 200
        // mid-push, so the snapshot just sent is already stale.
        DimensionSync::clearDirty(new Connection($fake), 7, 100);

        self::assertSame([], $fake->statementsContaining('UPDATE 202_users_pref'), 'the flag must stay set for the next hourly tick');
    }

    // --- casMutateState: the guarded persist for writers holding stale decodes ---

    public function testCasMutateStateAppliesTheMutationOntoFreshStateGuardedByTheExactBytesRead(): void
    {
        // The pref as it looks NOW — including a dims_dirty a save wrote
        // while the caller (the 6-hour bridge-config pull) was busy with
        // its SaaS fetch, holding an older decode without those keys.
        $rawPref = '{"webhook_id":3,"dims_dirty":true,"dims_dirty_at":100}';
        $fake = new FakeMysqliConnection();
        $fake->whenQueryContainsReturnRows('SELECT bandit_bridge_config', [
            ['bandit_bridge_config' => $rawPref],
        ]);

        DimensionSync::casMutateState(new Connection($fake), 7, function (array $state): array {
            $state['config'] = ['enabled_events' => ['*']];
            $state['fetched_at'] = 12345;
            return $state;
        });

        $updates = $fake->statementsContaining('UPDATE 202_users_pref SET bandit_bridge_config');
        self::assertCount(1, $updates);
        $state = json_decode((string) $updates[0]->boundValues[0], true);
        self::assertSame(12345, $state['fetched_at']);
        self::assertTrue($state['dims_dirty'], 'keys other writers own ride through: the mid-pull markDirty survives the config persist');
        self::assertSame(100, $state['dims_dirty_at']);
        self::assertStringContainsString('AND bandit_bridge_config = ?', $updates[0]->sql);
        self::assertSame($rawPref, $updates[0]->boundValues[2], 'guarded by the exact bytes read — a racing write makes this match zero rows instead of clobbering');
    }

    public function testCasMutateStateSkipsTheWriteWhenAlreadyInTheDesiredShape(): void
    {
        $fake = new FakeMysqliConnection();
        $fake->whenQueryContainsReturnRows('SELECT bandit_bridge_config', [
            ['bandit_bridge_config' => '{"webhook_id":3,"ctx_key":"' . self::CTX_KEY_HEX . '"}'],
        ]);

        DimensionSync::casMutateState(new Connection($fake), 7, fn (array $state): array => $state);

        self::assertSame([], $fake->statementsContaining('UPDATE 202_users_pref'), 'a no-op mutation must not write at all');
    }

    // --- pushForUser: the shared nightly/hourly per-user path ---

    public function testPushForUserBuildsTheCappedSnapshotAndPushesIt(): void
    {
        $fake = new FakeMysqliConnection();
        $fake->whenQueryContainsReturnRows('FROM 202_ltv_webhooks', [['webhook_secret' => 'secret-abc']]);
        $fake->whenQueryContainsReturnRows('FROM 202_ppc_networks', [
            ['ppc_network_id' => 3, 'ppc_network_name' => '  Facebook Ads  '],
        ]);
        $fake->whenQueryContainsReturnRows('FROM 202_ppc_accounts', [
            ['ppc_account_id' => 12, 'ppc_account_name' => str_repeat('a', 95), 'ppc_network_id' => 3],
        ]);
        $fake->whenQueryContainsReturnRows('FROM 202_aff_campaigns', [
            ['aff_campaign_id' => 340, 'aff_campaign_name' => 'Summer Promo', 'aff_network_id' => 7],
        ]);
        $fake->whenQueryContainsReturnRows('FROM 202_landing_pages', [
            ['landing_page_id' => 18, 'landing_page_nickname' => 'Blue LP v2'],
        ]);

        $client = $this->client();
        $counts = DimensionSync::pushForUser(
            new Connection($fake),
            $client,
            7,
            '{"webhook_id":5,"ctx_key":"' . self::CTX_KEY_HEX . '"}',
            'hash-1'
        );

        self::assertSame(['networks' => 1, 'accounts' => 1, 'campaigns' => 1, 'landing_pages' => 1], $counts);
        self::assertCount(1, $this->calls);
        self::assertSame('POST', $this->calls[0]['method']);
        self::assertSame('https://saas.example/api/v2/bandit/dimensions', $this->calls[0]['url']);

        $body = json_decode((string) $this->calls[0]['body'], true);
        self::assertSame('hash-1', $body['install_hash']);
        self::assertSame([['id' => 3, 'name' => 'Facebook Ads']], $body['snapshot']['networks'], 'names are trimmed');
        self::assertSame(80, strlen($body['snapshot']['accounts'][0]['name']), 'names are truncated to 80 chars (§4.1)');
        self::assertSame(3, $body['snapshot']['accounts'][0]['network_id']);
        self::assertSame([['id' => 340, 'name' => 'Summer Promo', 'aff_network_id' => 7]], $body['snapshot']['campaigns']);
        self::assertSame([['id' => 18, 'name' => 'Blue LP v2']], $body['snapshot']['landing_pages']);
        self::assertSame(
            ['X-P202-Signature: sha256=' . hash_hmac('sha256', (string) $this->calls[0]['body'], 'secret-abc')],
            $this->calls[0]['headers'],
            'signed over the exact raw body with the pairing webhook secret'
        );

        // A valid cached ctx_key means no backfill write.
        self::assertSame([], $fake->statementsContaining('UPDATE 202_users_pref'));

        // §4.1 caps ride inside the dictionary queries themselves.
        self::assertStringContainsString('LIMIT 64', $fake->statementsContaining('FROM 202_ppc_networks')[0]->sql);
        self::assertStringContainsString('LIMIT 256', $fake->statementsContaining('FROM 202_ppc_accounts')[0]->sql);
        self::assertStringContainsString('LIMIT 256', $fake->statementsContaining('FROM 202_aff_campaigns')[0]->sql);
        self::assertStringContainsString('LIMIT 128', $fake->statementsContaining('FROM 202_landing_pages')[0]->sql);
    }

    public function testPushForUserBackfillsCtxKeyWithoutDroppingTheDirtyFlag(): void
    {
        $fake = new FakeMysqliConnection();
        $fake->whenQueryContainsReturnRows('FROM 202_ltv_webhooks', [['webhook_secret' => 'secret-abc']]);

        DimensionSync::pushForUser(
            new Connection($fake),
            $this->client(),
            7,
            '{"webhook_id":5,"dims_dirty":true,"dims_dirty_at":100}',
            'hash-1'
        );

        $updates = $fake->statementsContaining('UPDATE 202_users_pref SET bandit_bridge_config');
        self::assertCount(1, $updates);
        $state = json_decode((string) $updates[0]->boundValues[0], true);
        self::assertSame(bin2hex(CtxToken::deriveKey('secret-abc')), $state['ctx_key']);
        self::assertTrue($state['dims_dirty'], 'the backfill re-encode must carry the dirty flag through');
        self::assertSame(100, $state['dims_dirty_at']);
    }

    public function testPushForUserThrowsWhenThePairingHasNoWebhook(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no local webhook recorded for this pairing');

        DimensionSync::pushForUser(new Connection(new FakeMysqliConnection()), $this->client(), 7, '{}', 'hash-1');
    }

    public function testPushForUserPropagatesTransportFailuresSoCallersKeepTheFlagDirty(): void
    {
        $fake = new FakeMysqliConnection();
        $fake->whenQueryContainsReturnRows('FROM 202_ltv_webhooks', [['webhook_secret' => 'secret-abc']]);

        $this->expectException(PairingRequestException::class);

        DimensionSync::pushForUser(
            new Connection($fake),
            $this->client(500, '{"error":"boom"}'),
            7,
            '{"webhook_id":5,"ctx_key":"' . self::CTX_KEY_HEX . '"}',
            'hash-1'
        );
    }
}
