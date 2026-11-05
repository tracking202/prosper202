<?php

declare(strict_types=1);

namespace Tests\Connect;

use Prosper202\User\ApiKeyStore;
use Api\V3\Controllers\UsersController;
use Api\V3\Support\AiConnect;
use Api\V3\Support\AiWatchers;
use PHPUnit\Framework\TestCase;
use Prosper202\User\UserDataPurge;

/**
 * The watchers of an AI connection's key, over the scratch database and a
 * stub of Tracking202's MCP server (StubRelay), through the code the pages
 * and the API run: Account › API keys lists them only for the account's own
 * AI keys and stops one only with the signed-in user's own key; every delete
 * of such a key asks the server to stop them all first, and deletes the key
 * whatever it answers.
 *
 * The API delete and the user delete reach the server at
 * AiConnect::relayOrigin(), so those tests run in a process of their own
 * with P202_MCP_RELAY_ORIGIN naming the stub.
 *
 * @group integration
 */
final class AiWatchersIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const OWNER = 3981;
    private const OTHER = 3982;
    private const CAMPAIGN = 3981;
    private const OTHERS_CAMPAIGN = 3982;
    private const W1 = 'wch_0123456789abcdef01234567';

    private ?StubRelay $relay = null;
    /** @var array<string, string> */
    private array $keys = [];

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::connectScratch();
        }
        self::requireScratch();
        self::cleanUp();
        self::user(self::OWNER);
        self::user(self::OTHER);
        self::campaign(self::CAMPAIGN, self::OWNER, 'Spring sale', 0);
        self::campaign(self::CAMPAIGN + 10, self::OWNER, 'Old launch', 1);
        self::campaign(self::OTHERS_CAMPAIGN, self::OTHER, 'Not yours', 0);
        $this->keys = [
            'ai' => $this->key(self::OWNER, AiConnect::label('Claude')),
            'plain' => $this->key(self::OWNER, null),
            'othersAi' => $this->key(self::OTHER, AiConnect::label('ChatGPT')),
        ];
        $this->relay = StubRelay::start();
    }

    protected function tearDown(): void
    {
        $this->relay?->stop();
        if (self::$db !== null) {
            self::cleanUp();
        }
    }

    private static function cleanUp(): void
    {
        foreach ([self::OWNER, self::OTHER] as $user) {
            self::q('DELETE FROM 202_api_keys WHERE user_id = ' . $user);
            self::q('DELETE FROM 202_aff_campaigns WHERE user_id = ' . $user);
            self::q('DELETE FROM 202_users WHERE user_id = ' . $user);
        }
    }

    private static function campaign(int $id, int $user, string $name, int $deleted): void
    {
        self::q('INSERT INTO 202_aff_campaigns SET aff_campaign_id = ' . $id . ', user_id = ' . $user
            . ', aff_campaign_id_public = ' . ($id + 100000) . ', aff_network_id = 0, aff_campaign_name = '
            . self::str($name) . ", aff_campaign_url = 'https://shop.example/', aff_campaign_payout = 1,"
            . ' aff_campaign_foreign_payout = 0, aff_campaign_deleted = ' . $deleted
            . ', aff_campaign_time = ' . time());
    }

    private function key(int $user, ?string $label): string
    {
        // Stored as 1.9.80 stores it: hashed, with the watcher token for an AI key.
        $key = bin2hex(random_bytes(32));
        ApiKeyStore::insert(self::requireScratch(), $user, $key, (string) AiConnect::scopeFor('read'), $label, time());

        return $key;
    }

    private function relay(): StubRelay
    {
        self::assertNotNull($this->relay);

        return $this->relay;
    }

    private function keyExists(string $key): bool
    {
        $result = self::requireScratch()
            ->query('SELECT COUNT(*) AS n FROM 202_api_keys WHERE key_hash = ' . self::str(ApiKeyStore::hash($key)));
        self::assertInstanceOf(\mysqli_result::class, $result);

        return (int) ($result->fetch_assoc()['n'] ?? 0) === 1;
    }

    private static function accountUi(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/functions-account-ui.php';
    }

    /**
     * Points AiConnect::relayOrigin() at the stub, and the error log at a
     * file; only in a process of its own. Returns the log's path.
     */
    private function relayIsTheStub(): string
    {
        define('P202_MCP_RELAY_ORIGIN', $this->relay()->origin());
        self::assertSame($this->relay()->origin(), AiConnect::relayOrigin());
        $log = (string) tempnam(sys_get_temp_dir(), 'p202-ai-watchers-it-');
        ini_set('error_log', $log);

        return $log;
    }

    private function logged(string $log): string
    {
        $logged = (string) file_get_contents($log);
        unlink($log);
        foreach ($this->keys as $key) {
            self::assertStringNotContainsString($key, $logged, 'no key is logged');
            self::assertStringNotContainsString(AiConnect::watcherToken($key), $logged, 'nor its token');
        }

        return $logged;
    }

    public function testThePageAsksOnlyForTheAccountsAIKeysAndNamesOnlyItsCampaigns(): void
    {
        self::accountUi();
        $this->relay()->answer('GET /api/watchers', 200, ['data' => [
            StubRelay::watcher(self::W1, self::CAMPAIGN),
            StubRelay::watcher('wch_' . str_repeat('1', 24), self::CAMPAIGN + 10, ['status' => 'completed']),
            StubRelay::watcher('wch_' . str_repeat('2', 24), self::OTHERS_CAMPAIGN),
        ]]);
        $keys = p202_account_api_keys(self::requireScratch(), self::OWNER);

        $watchers = p202_account_ai_watchers($keys, new AiWatchers($this->relay()->origin()));

        $listed = array_map('strval', array_keys($watchers));
        $aiRef = ApiKeyStore::hash($this->keys['ai']);
        self::assertSame([$aiRef], $listed, 'only the AI key has watchers, named by its ref');
        self::assertCount(3, $watchers[$aiRef] ?? []);
        self::assertSame(
            ['Bearer ' . AiConnect::watcherToken($this->keys['ai'])],
            array_column($this->relay()->requests(), 'authorization'),
            'one call, with the AI key\'s token'
        );

        $ids = [self::CAMPAIGN, self::CAMPAIGN + 10, self::OTHERS_CAMPAIGN];
        $names = p202_account_campaign_names(self::requireScratch(), self::OWNER, $ids);
        self::assertSame([self::CAMPAIGN => 'Spring sale', self::CAMPAIGN + 10 => 'Old launch (deleted)'], $names);

        $this->relay()->forget();
        $plainOnly = array_values(array_filter($keys, fn (array $k): bool => $k['ref'] !== $aiRef));
        self::assertSame([], p202_account_ai_watchers($plainOnly, new AiWatchers($this->relay()->origin())));
        self::assertSame([], $this->relay()->requests(), 'an account with no AI key makes no call');
    }

    public function testStopIsTheOwnersOwnKeyOnly(): void
    {
        self::accountUi();
        $this->relay()->answer('POST /api/watchers/stop', 200, ['stopped' => [self::W1]]);
        $db = self::requireScratch();
        $relay = new AiWatchers($this->relay()->origin());

        foreach (
            [
                'another user\'s AI key' => ApiKeyStore::hash($this->keys['othersAi']),
                'a key of no AI connection' => ApiKeyStore::hash($this->keys['plain']),
                'the key in another case' => ApiKeyStore::hash(strtoupper($this->keys['ai'])),
                'the key itself, not its ref' => $this->keys['ai'],
                'no key' => '',
            ] as $case => $posted
        ) {
            $answer = p202_account_stop_ai_watcher($db, self::OWNER, $posted, self::W1, $relay);
            self::assertSame('warn', $answer['kind'], $case);
            self::assertStringContainsString('not found on this account', $answer['text'], $case);
        }
        self::assertSame([], $this->relay()->requests(), 'nothing was asked of the server');

        $answer = p202_account_stop_ai_watcher($db, self::OWNER, ApiKeyStore::hash($this->keys['ai']), self::W1, $relay);
        self::assertSame('ok', $answer['kind'], $answer['text']);
        $requests = $this->relay()->requests();
        self::assertCount(1, $requests);
        self::assertSame('Bearer ' . AiConnect::watcherToken($this->keys['ai']), $requests[0]['authorization']);
        self::assertSame(['watcherId' => self::W1], json_decode($requests[0]['body'], true));

        $this->relay()->answer('POST /api/watchers/stop', 404, ['error' => 'there is no such watcher for this key']);
        foreach ([self::W1, 'wch_x'] as $id) {
            $answer = p202_account_stop_ai_watcher($db, self::OWNER, ApiKeyStore::hash($this->keys['ai']), $id, $relay);
            self::assertSame('warn', $answer['kind'], $id);
        }
    }

    public function testTheDeletesAskForTheAIKeysOwnWatchersOnly(): void
    {
        $this->relay()->answer('POST /api/watchers/stop', 200, ['stopped' => [self::W1]]);
        $db = self::requireScratch();
        $relay = new AiWatchers($this->relay()->origin());

        $plain = $this->keys['plain'];
        self::assertTrue($relay->stopBeforeDelete($db, self::OWNER, $plain), 'no AI key: nothing to stop');
        $others = $this->keys['othersAi'];
        self::assertTrue($relay->stopBeforeDelete($db, self::OWNER, $others), 'another user\'s key is not theirs');
        $upper = strtoupper($this->keys['ai']);
        self::assertTrue($relay->stopBeforeDelete($db, self::OWNER, $upper), 'a key is matched byte for byte');
        self::assertTrue($relay->stopBeforeDelete($db, self::OWNER, null, ApiKeyStore::hash($upper)), 'and by its own ref');
        self::assertSame([], $this->relay()->requests());

        // The API and revoke name the key; the page names its ref.
        self::assertTrue($relay->stopBeforeDelete($db, self::OWNER, $this->keys['ai']));
        self::assertTrue($relay->stopBeforeDelete($db, self::OWNER, null, ApiKeyStore::hash($this->keys['ai'])));
        $requests = $this->relay()->requests();
        self::assertCount(2, $requests);
        foreach ($requests as $request) {
            self::assertSame('Bearer ' . AiConnect::watcherToken($this->keys['ai']), $request['authorization']);
            self::assertSame(['all' => true], json_decode($request['body'], true));
        }

        $this->relay()->answer('POST /api/watchers/stop', 503, 'down');
        self::assertFalse($relay->stopBeforeDelete($db, self::OWNER, $this->keys['ai']), 'not confirmed');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheApiDeleteStopsTheWatchersAndDeletesTheKeyWhateverTheServerSays(): void
    {
        $log = $this->relayIsTheStub();
        $this->relay()->answer('POST /api/watchers/stop', 200, ['stopped' => [self::W1]]);
        $controller = new UsersController(self::requireScratch());

        $controller->deleteApiKey(self::OWNER, $this->keys['ai']);
        self::assertFalse($this->keyExists($this->keys['ai']));
        $requests = $this->relay()->requests();
        self::assertCount(1, $requests);
        self::assertSame('Bearer ' . AiConnect::watcherToken($this->keys['ai']), $requests[0]['authorization']);
        self::assertSame(['all' => true], json_decode($requests[0]['body'], true));

        // A server that cannot confirm does not keep the key.
        $second = $this->key(self::OWNER, AiConnect::label('Claude'));
        $this->relay()->answer('POST /api/watchers/stop', 500, 'oops', 0);
        $controller->deleteApiKey(self::OWNER, $second);
        self::assertFalse($this->keyExists($second));

        // A plain key is deleted without a call.
        $this->relay()->forget();
        $controller->deleteApiKey(self::OWNER, $this->keys['plain']);
        self::assertFalse($this->keyExists($this->keys['plain']));
        self::assertSame([], $this->relay()->requests());
        self::assertStringContainsString('did not confirm that the key\'s watchers stopped', $this->logged($log));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testDeletingAUserStopsTheWatchersOfTheirAIKeysAfterTheDelete(): void
    {
        $log = $this->relayIsTheStub();
        $this->relay()->answer('POST /api/watchers/stop', 200, ['stopped' => []]);

        (new UserDataPurge(self::requireScratch()))->deleteUser(self::OWNER);

        self::assertFalse($this->keyExists($this->keys['ai']));
        self::assertSame(
            ['Bearer ' . AiConnect::watcherToken($this->keys['ai'])],
            array_column($this->relay()->requests(), 'authorization'),
            'the deleted user\'s AI key, not their plain key nor anyone else\'s'
        );
        self::assertTrue($this->keyExists($this->keys['othersAi']));
        self::assertStringNotContainsString('did not confirm', $this->logged($log));
    }
}
