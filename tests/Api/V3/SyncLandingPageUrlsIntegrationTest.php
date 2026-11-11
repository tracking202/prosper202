<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\LandingPagesController;
use Api\V3\Support\RemoteApiClient;
use Api\V3\Support\ServerStateStore;
use Api\V3\Support\SyncEngine;
use PHPUnit\Framework\TestCase;
use Tests\Support\LandingPageControllerRemoteApiClient;

/**
 * A landing page whose source stores its URL without a scheme (an install
 * from before the API held the URL to one) syncs to one page on the target,
 * however often it is synced: the target stores the URL with https://, and
 * the sync matches and compares the pages on that form on both sides.
 *
 * Source and target are two accounts on one scratch database, each served
 * by the real LandingPagesController; only the HTTP exchange is replaced.
 *
 * @group integration
 */
final class SyncLandingPageUrlsIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const SOURCE = 5541;
    private const TARGET = 5542;

    private string $stateDir = '';

    public static function setUpBeforeClass(): void
    {
        self::connectScratch();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
        }
    }

    private static function cleanUp(): void
    {
        self::$db->query('DELETE FROM 202_landing_pages WHERE user_id IN (' . self::SOURCE . ', ' . self::TARGET . ')');
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::cleanUp();
        self::$db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $this->stateDir = sys_get_temp_dir() . '/p202-sync-lp-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if ($this->stateDir !== '' && is_dir($this->stateDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->stateDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->stateDir);
        }
    }

    /** An advanced page of the source's, stored as an older install stored it. */
    private static function sourcePage(string $nickname, string $url, string $leaveBehind = ''): void
    {
        self::q('INSERT INTO 202_landing_pages SET user_id = ' . self::SOURCE . ', aff_campaign_id = 0, landing_page_nickname = ' . self::str($nickname)
            . ', landing_page_url = ' . self::str($url) . ', leave_behind_page_url = ' . self::str($leaveBehind)
            . ', landing_page_id_public = ' . random_int(100000, 999999) . ', landing_page_time = 0, landing_page_type = 1');
    }

    /** @return list<array{0: string, 1: string, 2: string}> nickname, URL, leave-behind URL */
    private static function targetPages(): array
    {
        $result = self::$db->query('SELECT landing_page_nickname, landing_page_url, leave_behind_page_url FROM 202_landing_pages'
            . ' WHERE user_id = ' . self::TARGET . ' AND landing_page_deleted = 0 ORDER BY landing_page_nickname');

        return array_map(static fn (array $r): array => [(string) $r[0], (string) $r[1], (string) $r[2]], $result->fetch_all(MYSQLI_NUM));
    }

    /** @return array<string, mixed> the landing pages' result */
    private function sync(): array
    {
        $source = new LandingPageControllerRemoteApiClient(self::$db, self::SOURCE);
        $target = new LandingPageControllerRemoteApiClient(self::$db, self::TARGET);
        $engine = new class (new ServerStateStore($this->stateDir), $source, $target) extends SyncEngine {
            public function __construct(
                ServerStateStore $store,
                private readonly RemoteApiClient $sourceClient,
                private readonly RemoteApiClient $targetClient
            ) {
                parent::__construct($store);
            }

            protected function buildClients(array $sourceProfile, array $targetProfile): array
            {
                return [$this->sourceClient, $this->targetClient];
            }
        };
        $profile = ['url' => 'http://unused.example', 'api_key' => 'key'];
        $out = $engine->execute($profile, $profile, 'landing-pages', ['force_update' => true, 'skip_errors' => true]);

        return $out['results']['landing-pages'];
    }

    public function testAUrlStoredWithoutItsSchemeSyncsToOnePageHoweverOftenItIsSynced(): void
    {
        self::sourcePage('Spring sale', 'lp.example.com/spring', 'offers.example.com/after');
        self::sourcePage('Summer sale', '//lp.example.com/summer');
        self::sourcePage('Winter sale', 'https://lp.example.com/winter');

        $first = $this->sync();
        self::assertSame([3, 0], [$first['created'] ?? null, $first['failed'] ?? null], json_encode($first) ?: '');
        $expected = [
            ['Spring sale', 'https://lp.example.com/spring', 'https://offers.example.com/after'],
            ['Summer sale', 'https://lp.example.com/summer', ''],
            ['Winter sale', 'https://lp.example.com/winter', ''],
        ];
        self::assertSame($expected, self::targetPages());

        foreach ([2, 3] as $run) {
            $again = $this->sync();
            self::assertSame([0, 0, 0], [$again['created'] ?? null, $again['updated'] ?? null, $again['failed'] ?? null], "sync $run: " . json_encode($again));
            self::assertSame($expected, self::targetPages(), "sync $run made no second page");
        }

        // A real change on the source still reaches the one page.
        self::q("UPDATE 202_landing_pages SET landing_page_nickname = 'Spring sale 2' WHERE user_id = " . self::SOURCE . " AND landing_page_url = 'lp.example.com/spring'");
        $changed = $this->sync();
        self::assertSame([0, 1, 0], [$changed['created'] ?? null, $changed['updated'] ?? null, $changed['failed'] ?? null], json_encode($changed) ?: '');
        self::assertCount(3, self::targetPages());
        self::assertSame('Spring sale 2', self::targetPages()[0][0]);
    }

    public function testTheApiSaysWhichUrlItCompleted(): void
    {
        $pages = new LandingPagesController(self::$db, self::TARGET);
        $made = $pages->create(['landing_page_url' => 'lp.example.com/x', 'leave_behind_page_url' => '//offers.example.com/after',
            'aff_campaign_id' => 0, 'landing_page_nickname' => 'Spring sale', 'landing_page_type' => 1]);
        self::assertSame('https://lp.example.com/x', $made['data']['landing_page_url']);
        self::assertSame('https://offers.example.com/after', $made['data']['leave_behind_page_url']);
        self::assertSame([
            'landing_page_url' => ['sent' => 'lp.example.com/x', 'stored' => 'https://lp.example.com/x'],
            'leave_behind_page_url' => ['sent' => '//offers.example.com/after', 'stored' => 'https://offers.example.com/after'],
        ], $made['meta']['normalized']);

        $id = (int) $made['data']['landing_page_id'];
        $whole = $pages->update($id, ['landing_page_url' => 'https://lp.example.com/y']);
        self::assertArrayNotHasKey('meta', $whole, 'a URL sent whole is not reported as completed');
        $bare = $pages->update($id, ['landing_page_url' => 'lp.example.com/z']);
        self::assertSame(['landing_page_url' => ['sent' => 'lp.example.com/z', 'stored' => 'https://lp.example.com/z']], $bare['meta']['normalized']);
        self::assertSame('https://lp.example.com/z', $pages->get($id)['data']['landing_page_url']);
    }
}
