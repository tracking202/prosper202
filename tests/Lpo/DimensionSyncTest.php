<?php

declare(strict_types=1);

namespace Tests\Lpo;

use PHPUnit\Framework\TestCase;
use Prosper202\Lpo\CtxToken;
use Prosper202\Lpo\DimensionSync;
use Prosper202\Lpo\PairingClient;
use Prosper202\Lpo\PairingRequestException;
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
        $rawPref = '{"webhook_id":3,"ctx_key":"' . self::CTX_KEY_HEX . '"}';
        $fake = new FakeMysqliConnection();
        $fake->whenQueryContainsReturnRows('SELECT bandit_status, bandit_bridge_config', [
            ['bandit_status' => 'active', 'bandit_bridge_config' => $rawPref],
        ]);
        $fake->whenQueryContainsReturnRows('SELECT bandit_bridge_config', [
            ['bandit_bridge_config' => $rawPref],
        ]);
        $fake->whenQueryContainsAffectedRows('UPDATE 202_users_pref', 1);

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

        // The write is the byte-guarded CAS, not an unconditional UPDATE: a
        // bridge-config persist landing between markDirty's reads and this
        // write must miss the guard instead of being overwritten with the
        // older JSON (EventBridge routing reads config.enabled_events from
        // these same bytes).
        self::assertStringContainsString('AND bandit_bridge_config = ?', $updates[0]->sql);
        self::assertSame($rawPref, $updates[0]->boundValues[2], 'guarded on the exact fresh bytes read');
    }

    public function testMarkDirtyBumpsDirtyAtOnEverySaveSoMidPushChangesSurvive(): void
    {
        $rawPref = '{"webhook_id":3,"dims_dirty":true,"dims_dirty_at":100}';
        $fake = new FakeMysqliConnection();
        $fake->whenQueryContainsReturnRows('SELECT bandit_status, bandit_bridge_config', [
            ['bandit_status' => 'active', 'bandit_bridge_config' => $rawPref],
        ]);
        $fake->whenQueryContainsReturnRows('SELECT bandit_bridge_config', [
            ['bandit_bridge_config' => $rawPref],
        ]);
        $fake->whenQueryContainsAffectedRows('UPDATE 202_users_pref', 1);

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
        $rawPref = '{"webhook_id":3,"dims_dirty":true,"dims_dirty_at":' . $now . '}';
        $fake = new FakeMysqliConnection();
        $fake->whenQueryContainsReturnRows('SELECT bandit_status, bandit_bridge_config', [
            ['bandit_status' => 'active', 'bandit_bridge_config' => $rawPref],
        ]);
        $fake->whenQueryContainsReturnRows('SELECT bandit_bridge_config', [
            ['bandit_bridge_config' => $rawPref],
        ]);
        $fake->whenQueryContainsAffectedRows('UPDATE 202_users_pref', 1);

        DimensionSync::markDirty($fake, 7);

        $updates = $fake->statementsContaining('UPDATE 202_users_pref SET bandit_bridge_config');
        self::assertCount(1, $updates);
        $state = json_decode((string) $updates[0]->boundValues[0], true);
        self::assertGreaterThan($now, $state['dims_dirty_at'], 'same-second saves must still produce a new token');
    }

    public function testMarkDirtyCarriesAConcurrentConfigWriteInsteadOfClobberingIt(): void
    {
        // Between the status read and the persist, the six-hour pull saved a
        // fresh remote config into the pref. markDirty's CAS re-read sees
        // those bytes, so the dirty keys are layered ONTO them — the old
        // unconditional UPDATE would have written the config-less decode
        // back and silently disabled event routing until the next pull.
        $stalePref = '{"webhook_id":3}';
        $freshPref = '{"webhook_id":3,"config":{"enabled_events":["*"]},"fetched_at":12345}';
        $fake = new FakeMysqliConnection();
        $fake->whenQueryContainsReturnRows('SELECT bandit_status, bandit_bridge_config', [
            ['bandit_status' => 'active', 'bandit_bridge_config' => $stalePref],
        ]);
        $fake->whenQueryContainsReturnRows('SELECT bandit_bridge_config', [
            ['bandit_bridge_config' => $freshPref],
        ]);
        $fake->whenQueryContainsAffectedRows('UPDATE 202_users_pref', 1);

        DimensionSync::markDirty($fake, 7);

        $updates = $fake->statementsContaining('UPDATE 202_users_pref SET bandit_bridge_config');
        self::assertCount(1, $updates);
        $state = json_decode((string) $updates[0]->boundValues[0], true);
        self::assertSame(['enabled_events' => ['*']], $state['config'], 'the mid-save config pull survives the dirty-marking');
        self::assertSame(12345, $state['fetched_at']);
        self::assertTrue($state['dims_dirty']);
        self::assertSame($freshPref, $updates[0]->boundValues[2], 'the guard binds the FRESH bytes, not the status-read decode');
    }

    public function testMarkDirtyRetriesGuardMissesAndNeverThrows(): void
    {
        // Every CAS attempt loses its race (affected_rows stays 0): the
        // one-shot save hook retries with fresh bytes instead of giving up
        // on the first miss, then degrades to the nightly backstop quietly.
        $rawPref = '{"webhook_id":3}';
        $fake = new FakeMysqliConnection();
        $fake->whenQueryContainsReturnRows('SELECT bandit_status, bandit_bridge_config', [
            ['bandit_status' => 'active', 'bandit_bridge_config' => $rawPref],
        ]);
        $fake->whenQueryContainsReturnRows('SELECT bandit_bridge_config', [
            ['bandit_bridge_config' => $rawPref],
        ]);
        $fake->whenQueryContainsAffectedRows('UPDATE 202_users_pref', 0);

        DimensionSync::markDirty($fake, 7);

        self::assertCount(
            3,
            $fake->statementsContaining('UPDATE 202_users_pref SET bandit_bridge_config'),
            'a guard miss on the one-shot save path retries (bounded) rather than dropping the flag'
        );
        $this->addToAssertionCount(1); // reaching here proves the admin save survived the misses
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

    public function testPushForUserBackfillsCtxKeyThroughTheCasWithoutDroppingConcurrentWrites(): void
    {
        // The cron selected this user's pref before the per-user work
        // started; by backfill time an admin save has set dims_dirty and
        // the 6-hour pull has stored a config. The backfill must layer
        // ctx_key onto THOSE fresh bytes via the guarded CAS — the old bare
        // UPDATE from the stale cron decode dropped both (and with the
        // dirty flag, the failed-push retry).
        $freshPref = '{"webhook_id":5,"dims_dirty":true,"dims_dirty_at":100,"config":{"enabled_events":["*"]}}';
        $fake = new FakeMysqliConnection();
        $fake->whenQueryContainsReturnRows('FROM 202_ltv_webhooks', [['webhook_secret' => 'secret-abc']]);
        $fake->whenQueryContainsReturnRows('SELECT bandit_bridge_config', [
            ['bandit_bridge_config' => $freshPref],
        ]);

        DimensionSync::pushForUser(
            new Connection($fake),
            $this->client(),
            7,
            '{"webhook_id":5}', // the cron's stale decode: no dirty flag, no config yet
            'hash-1'
        );

        $updates = $fake->statementsContaining('UPDATE 202_users_pref SET bandit_bridge_config');
        self::assertCount(1, $updates);
        $state = json_decode((string) $updates[0]->boundValues[0], true);
        self::assertSame(bin2hex(CtxToken::deriveKey('secret-abc')), $state['ctx_key']);
        self::assertTrue($state['dims_dirty'], 'the backfill must carry the mid-cron dirty flag through');
        self::assertSame(100, $state['dims_dirty_at']);
        self::assertSame(['enabled_events' => ['*']], $state['config'], 'the mid-cron config pull survives too');
        self::assertStringContainsString('AND bandit_bridge_config = ?', $updates[0]->sql);
        self::assertSame($freshPref, $updates[0]->boundValues[2], 'guarded on the fresh bytes, not the cron decode');
    }

    public function testPushForUserSkipsTheBackfillWhenAnotherWriterAlreadyLandedIt(): void
    {
        // The stale cron decode lacks ctx_key, but by backfill time another
        // run already wrote it: the CAS callback re-checks on fresh bytes
        // and the mutation is a byte-identical no-op — no write at all.
        $fake = new FakeMysqliConnection();
        $fake->whenQueryContainsReturnRows('FROM 202_ltv_webhooks', [['webhook_secret' => 'secret-abc']]);
        $fake->whenQueryContainsReturnRows('SELECT bandit_bridge_config', [
            ['bandit_bridge_config' => '{"webhook_id":5,"ctx_key":"' . self::CTX_KEY_HEX . '"}'],
        ]);

        DimensionSync::pushForUser(
            new Connection($fake),
            $this->client(),
            7,
            '{"webhook_id":5}',
            'hash-1'
        );

        self::assertSame([], $fake->statementsContaining('UPDATE 202_users_pref'), 'an already-backfilled fresh state must not be rewritten');
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
