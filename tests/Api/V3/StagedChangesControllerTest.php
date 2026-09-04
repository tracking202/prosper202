<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Auth;
use Api\V3\AuthException;
use Api\V3\Controllers\StagedChangesController;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\ServerStateStore;
use Tests\TestCase;

final class StagedChangesControllerTest extends TestCase
{
    private string $tmpDir;
    private ServerStateStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/p202-staged-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0700, true);
        $this->store = new ServerStateStore($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }

    private function authFor(int $userId, string $scope = '*', string $role = 'user'): Auth
    {
        $db = $this->createMysqliMock([
            "SHOW COLUMNS FROM 202_api_keys LIKE 'scope'" => ['Field' => 'scope'],
            '202_api_keys' => ['user_id' => $userId, 'scope' => $scope],
            '202_user_role' => [['role_name' => $role]],
        ]);
        return Auth::fromRequest(['Authorization' => 'Bearer key'], $db);
    }

    private function controller(Auth $auth): StagedChangesController
    {
        return new StagedChangesController($this->store, $auth);
    }

    public function testStageRecordsAProposalWithServerIssuedId(): void
    {
        $ctl = $this->controller($this->authFor(5, 'read,stage'));
        $out = $ctl->stage('PUT', '/campaigns/42', ['aff_campaign_payout' => '9.50'], null);

        $change = $out['data'];
        $this->assertMatchesRegularExpression('/^chg_[0-9a-f]{24}$/', $change['change_id']);
        $this->assertSame('staged', $change['status']);
        $this->assertSame('PUT', $change['method']);
        $this->assertSame('/campaigns/42', $change['path']);
        $this->assertSame('campaigns', $change['resource_area']);
        $this->assertSame(5, $change['created_by']);
        $this->assertFalse($change['expired']);
        $this->assertGreaterThan(time(), $change['expires_at_epoch']);
        $this->assertStringContainsString('/apply', $out['hint']);
    }

    public function testListShowsOwnChangesOnlyUnlessAdminAsksForAll(): void
    {
        $this->controller($this->authFor(5))->stage('DELETE', '/campaigns/1', [], null);
        $this->controller($this->authFor(6))->stage('DELETE', '/campaigns/2', [], null);

        $own = $this->controller($this->authFor(5))->list([]);
        $this->assertCount(1, $own['data']);
        $this->assertSame('/campaigns/1', $own['data'][0]['path']);

        $admin = $this->controller($this->authFor(9, '*', 'Admin'))->list(['all' => '1']);
        $this->assertCount(2, $admin['data']);

        $this->expectException(AuthException::class);
        $this->controller($this->authFor(5))->list(['all' => '1']);
    }

    public function testGetRefusesOtherUsersChangesForNonAdmins(): void
    {
        $staged = $this->controller($this->authFor(6))->stage('DELETE', '/campaigns/2', [], null);
        $id = $staged['data']['change_id'];

        // Admin sees it.
        $got = $this->controller($this->authFor(9, '*', 'Admin'))->get($id);
        $this->assertSame($id, $got['data']['change_id']);

        // Another non-admin gets a 403, not a silent 404 lie... and never the record.
        $this->expectException(AuthException::class);
        $this->controller($this->authFor(5))->get($id);
    }

    public function testGetUnknownIdIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller($this->authFor(5))->get('chg_' . str_repeat('a', 24));
    }

    public function testApplyDispatchesTheRecordedWriteAndStampsTheApplier(): void
    {
        $proposer = $this->controller($this->authFor(5, 'read,stage'));
        $id = $proposer->stage('PUT', '/campaigns/42', ['aff_campaign_payout' => '9.50'], null)['data']['change_id'];

        $dispatched = null;
        $applier = $this->controller($this->authFor(5, 'write'));
        $out = $applier->apply($id, function (string $m, string $p, array $body) use (&$dispatched) {
            $dispatched = [$m, $p, $body];
            return ['data' => ['aff_campaign_id' => 42, 'aff_campaign_payout' => '9.50']];
        });

        $this->assertSame(['PUT', '/campaigns/42', ['aff_campaign_payout' => '9.50']], $dispatched);
        $this->assertSame('applied', $out['data']['change']['status']);
        $this->assertSame(5, $out['data']['change']['applied_by']);
        $this->assertSame(42, $out['data']['result']['aff_campaign_id']);
    }

    public function testApplyRequiresTheUnderlyingWriteScope(): void
    {
        $proposer = $this->controller($this->authFor(5, 'read,stage'));
        $id = $proposer->stage('DELETE', '/campaigns/7', [], null)['data']['change_id'];

        // The propose-only key cannot apply its own proposal.
        $this->expectException(AuthException::class);
        $this->expectExceptionMessageMatches("/campaigns:write/");
        $proposer->apply($id, fn() => $this->fail('dispatch must not run without the write scope'));
    }

    public function testApplyTwiceConflicts(): void
    {
        $ctl = $this->controller($this->authFor(5));
        $id = $ctl->stage('DELETE', '/campaigns/7', [], null)['data']['change_id'];
        $ctl->apply($id, fn() => null);

        $this->expectException(ConflictException::class);
        $ctl->apply($id, fn() => $this->fail('an applied change must not dispatch again'));
    }

    public function testFailedDispatchReturnsChangeToStagedWithTheError(): void
    {
        $ctl = $this->controller($this->authFor(5));
        $id = $ctl->stage('PUT', '/campaigns/42', ['aff_campaign_payout' => 'x'], null)['data']['change_id'];

        try {
            $ctl->apply($id, function () {
                throw new \RuntimeException('validation failed downstream');
            });
            $this->fail('dispatch exception must propagate');
        } catch (\RuntimeException) {
            // expected
        }

        $change = $ctl->get($id)['data'];
        $this->assertSame('staged', $change['status']);
        $this->assertSame('validation failed downstream', $change['last_error']);

        // Still applicable after the failure is corrected.
        $out = $ctl->apply($id, fn() => ['data' => ['ok' => true]]);
        $this->assertSame('applied', $out['data']['change']['status']);
        $this->assertNull($out['data']['change']['last_error']);
    }

    public function testExpiredChangesCannotApply(): void
    {
        $ctl = $this->controller($this->authFor(5));
        $id = $ctl->stage('DELETE', '/campaigns/7', [], null)['data']['change_id'];

        $this->store->updateStagedChange(5, $id, function (array $c): array {
            $c['expires_at_epoch'] = time() - 10;
            return $c;
        });

        $this->assertTrue($ctl->get($id)['data']['expired']);
        $this->expectException(ConflictException::class);
        $this->expectExceptionMessageMatches('/expired/');
        $ctl->apply($id, fn() => $this->fail('an expired change must not dispatch'));
    }

    public function testDiscardEndsTheProposalAndIsFinal(): void
    {
        $ctl = $this->controller($this->authFor(5, 'read,stage'));
        $id = $ctl->stage('DELETE', '/campaigns/7', [], null)['data']['change_id'];

        $out = $ctl->discard($id);
        $this->assertSame('discarded', $out['data']['status']);
        $this->assertSame(5, $out['data']['discarded_by']);

        $this->expectException(ConflictException::class);
        $ctl->discard($id);
    }

    public function testMalformedChangeIdIsAValidationError(): void
    {
        $this->expectException(\Api\V3\Exception\ValidationException::class);
        $this->controller($this->authFor(5))->get('not-a-change-id');
    }
    public function testApplyPassesTheProposerIdToTheDispatcher(): void
    {
        // The write must land in the account that proposed it, not the
        // applier's — an admin approving user 5's create must not create the
        // row under their own id.
        $proposer = $this->authFor(5, 'read,stage');
        $id = $this->controller($proposer)->stage(
            'POST',
            '/campaigns',
            ['aff_campaign_name' => 'Proposed'],
            null
        )['data']['change_id'];

        $seen = null;
        $admin = $this->authFor(1, '*', 'admin');
        $this->controller($admin)->apply(
            $id,
            function (string $m, string $p, array $body, int $proposerId) use (&$seen) {
                $seen = [$m, $p, $body, $proposerId];
                return ['data' => ['ok' => true]];
            }
        );

        $this->assertSame(
            ['POST', '/campaigns', ['aff_campaign_name' => 'Proposed'], 5],
            $seen
        );
    }

    public function testStagingRefusesAPayloadCarryingASecret(): void
    {
        $ctl = $this->controller($this->authFor(5));
        $this->expectException(ValidationException::class);
        $ctl->stage('POST', '/users', [
            'user_name' => 'x',
            'user_pass' => 'SuperSecret123!',
        ], null);
    }

    public function testStagedRecordNeverPersistsASecretValue(): void
    {
        $ctl = $this->controller($this->authFor(5));
        try {
            $ctl->stage('POST', '/users', ['user_pass' => 'SuperSecret123!'], null);
            $this->fail('staging a secret-bearing payload must throw');
        } catch (ValidationException) {
            // expected
        }
        $onDisk = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $onDisk .= (string)file_get_contents($file->getPathname());
            }
        }
        $this->assertStringNotContainsString('SuperSecret123!', $onDisk);
    }

    public function testApplyRejectsAMalformedStoredPayload(): void
    {
        $auth = $this->authFor(5);
        $id = $this->controller($auth)->stage('PUT', '/campaigns/42', ['x' => 1], null)['data']['change_id'];
        $this->store->updateStagedChange(5, $id, function (array $c): array {
            $c['payload'] = 'not-an-array';
            return $c;
        });

        // Applying an empty body would execute a different write than the
        // one reviewed, so this must fail rather than dispatch.
        $this->expectException(ConflictException::class);
        $this->controller($auth)->apply($id, fn() => $this->fail('must not dispatch a malformed payload'));
    }

    /**
     * Simulate a process killed mid-dispatch: the claim was taken and never
     * resolved, so whether the handler committed is unknowable.
     */
    private function stageThenStrandInApplying(Auth $auth, string $method = 'POST', string $path = '/campaigns'): string
    {
        $id = $this->controller($auth)->stage($method, $path, ['a' => 1], null)['data']['change_id'];
        $this->store->updateStagedChange(5, $id, function (array $c): array {
            $c['status'] = StagedChangesController::STATUS_APPLYING;
            $c['applying_since'] = time() - (StagedChangesController::STALE_APPLYING_SECONDS + 60);
            return $c;
        });
        return $id;
    }

    public function testAStaleApplyingClaimIsNeverRedispatched(): void
    {
        $auth = $this->authFor(5);
        $id = $this->stageThenStrandInApplying($auth);

        // The dead process may have committed its write before dying, so a
        // second dispatch could duplicate the create. Refuse instead.
        try {
            $this->controller($auth)->apply($id, fn() => $this->fail('an interrupted apply must not be re-dispatched'));
            $this->fail('apply() must refuse a stale applying claim');
        } catch (ConflictException $e) {
            $this->assertStringContainsString('never finished', $e->getMessage());
        }

        $stored = $this->store->getStagedChangeForUser(5, $id);
        $this->assertSame(StagedChangesController::STATUS_APPLY_INTERRUPTED, $stored['status']);
        $this->assertNotEmpty($stored['interrupted_at']);
    }

    public function testAStaleApplyingClaimCannotBeDiscardedEither(): void
    {
        $auth = $this->authFor(5);
        $id = $this->stageThenStrandInApplying($auth);

        // Discarding would file an audit record saying a write that may have
        // executed was abandoned.
        $this->expectException(ConflictException::class);
        try {
            $this->controller($auth)->discard($id);
        } finally {
            $stored = $this->store->getStagedChangeForUser(5, $id);
            $this->assertSame(StagedChangesController::STATUS_APPLY_INTERRUPTED, $stored['status']);
        }
    }

    public function testAnInterruptedChangeStaysTerminal(): void
    {
        $auth = $this->authFor(5);
        $id = $this->stageThenStrandInApplying($auth);

        try {
            $this->controller($auth)->apply($id, fn() => $this->fail('must not dispatch'));
        } catch (ConflictException) {
            // expected; the record is now apply_interrupted
        }

        // A second attempt gets the ordinary "not staged" conflict — the
        // record does not cycle back through the interrupted transition.
        $this->expectException(ConflictException::class);
        $this->expectExceptionMessageMatches('/is apply_interrupted, not staged/');
        $this->controller($auth)->apply($id, fn() => $this->fail('must not dispatch'));
    }

    public function testStagingAnApiKeyDeleteIsRefusedBecauseThePathIsTheKey(): void
    {
        $ctl = $this->controller($this->authFor(5, 'read,stage'));

        // The key is the last path segment, and a staged change stores and
        // displays `path`, so staging would park a live credential in the
        // queue. 202_api_keys has no surrogate id to substitute.
        try {
            $ctl->stage('DELETE', '/users/5/api-keys/live-secret-key-value', [], null);
            $this->fail('staging an API key delete must be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('carries a secret', $e->getMessage());
        }

        $this->assertSame([], $this->store->listStagedChangesForUser(5));
    }

    public function testPresentRedactsASecretPathRecordedByAnOlderBuild(): void
    {
        $auth = $this->authFor(5);
        $id = $this->controller($auth)->stage('DELETE', '/campaigns/42', [], null)['data']['change_id'];
        // Rewrite the record the way a build without the path check wrote it.
        $this->store->updateStagedChange(5, $id, function (array $c): array {
            $c['method'] = 'DELETE';
            $c['path'] = '/users/5/api-keys/live-secret-key-value';
            $c['summary'] = 'DELETE /users/5/api-keys/live-secret-key-value';
            return $c;
        });

        $shown = $this->controller($auth)->get($id)['data'];
        $this->assertSame('/users/5/api-keys/[redacted]', $shown['path']);
        $this->assertSame('DELETE /users/5/api-keys/[redacted]', $shown['summary']);
        $this->assertStringNotContainsString('live-secret-key-value', json_encode($shown));

        // The stored record keeps the key, so such a change stays applicable.
        $this->assertSame(
            '/users/5/api-keys/live-secret-key-value',
            $this->store->getStagedChangeForUser(5, $id)['path']
        );
    }

    public function testAFreshApplyingClaimIsStillProtected(): void
    {
        $auth = $this->authFor(5);
        $id = $this->controller($auth)->stage('POST', '/campaigns', ['a' => 1], null)['data']['change_id'];
        $this->store->updateStagedChange(5, $id, function (array $c): array {
            $c['status'] = StagedChangesController::STATUS_APPLYING;
            $c['applying_since'] = time();
            return $c;
        });

        $this->expectException(ConflictException::class);
        $this->controller($auth)->apply($id, fn() => $this->fail('a live apply must not be double-dispatched'));
    }

}
