<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Auth;
use Api\V3\AuthException;
use Api\V3\Controllers\StagedChangesController;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\NotFoundException;
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
}
