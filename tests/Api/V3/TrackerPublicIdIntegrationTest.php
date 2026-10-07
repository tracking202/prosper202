<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\TrackersController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;
use Prosper202\Setup\TrackerPublicId;

/**
 * No two trackers share a public id (the t202id the click endpoints find a
 * tracker by, alone): a sent id another tracker holds -- in any account -- is
 * refused on create and on update, and a generated one is drawn until free,
 * for the API's ids and for Get Links' alike.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME);
 * it installs the schema there and writes users 6201 and 6202's rows.
 *
 * @group integration
 */
final class TrackerPublicIdIntegrationTest extends TestCase
{
    private const int MINE = 6201;
    private const int THEIRS = 6202;

    private static ?\mysqli $db = null;

    /** @var array<int, int> user => campaign */
    private array $campaign = [];

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) { return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        mysqli_report(MYSQLI_REPORT_STRICT);
        try {
            $db = @mysqli_connect(
                $host,
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
        } catch (\Throwable) {
            return;
        }
        if (!$db) {
            return;
        }
        $db->query("SET SESSION sql_mode=''");
        (new SchemaInstaller($db))->install();
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
        }
        self::$db = null;
    }

    private static function cleanUp(): void
    {
        foreach ([self::MINE, self::THEIRS] as $u) {
            self::$db->query("DELETE FROM 202_trackers WHERE user_id = $u");
            self::$db->query("DELETE FROM 202_aff_campaigns WHERE user_id = $u");
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        self::cleanUp();
        foreach ([self::MINE, self::THEIRS] as $u) {
            self::assertTrue(self::$db->query("INSERT INTO 202_aff_campaigns SET user_id = $u, aff_network_id = 0, aff_campaign_name = 'pid', aff_campaign_url = 'https://o.example', aff_campaign_payout = 1, aff_campaign_foreign_payout = 0, aff_campaign_time = 0"), self::$db->error);
            $this->campaign[$u] = (int) self::$db->insert_id;
        }
    }

    private function create(int $user, array $payload = []): array
    {
        return (new TrackersController(self::$db, $user))->create(['aff_campaign_id' => $this->campaign[$user]] + $payload)['data'];
    }

    private static function refusedOnPublicId(callable $write): string
    {
        try {
            $write();
        } catch (ValidationException $e) {
            self::assertArrayHasKey('tracker_id_public', $e->getFieldErrors());

            return $e->getFieldErrors()['tracker_id_public'];
        }
        self::fail('the write was answered');
    }

    public function testAnotherTrackersIdIsRefusedOnCreateAndUpdate(): void
    {
        $theirs = (int) $this->create(self::THEIRS)['tracker_id_public'];
        self::assertGreaterThanOrEqual(TrackerPublicId::MIN_GENERATED, $theirs);

        $message = self::refusedOnPublicId(fn () => $this->create(self::MINE, ['tracker_id_public' => $theirs]));
        self::assertStringContainsString("another tracker's public id", $message);
        self::assertSame('0', self::$db->query('SELECT COUNT(*) FROM 202_trackers WHERE user_id = ' . self::MINE)->fetch_row()[0], 'nothing was written');

        $mine = $this->create(self::MINE, ['tracker_id_public' => 123_456_789]);
        self::assertSame(123_456_789, (int) $mine['tracker_id_public'], 'a free id that was sent is kept (sync and import carry links across)');
        $controller = new TrackersController(self::$db, self::MINE);
        self::refusedOnPublicId(fn () => $controller->update((int) $mine['tracker_id'], ['tracker_id_public' => $theirs]));
        $controller->update((int) $mine['tracker_id'], ['tracker_id_public' => 123_456_789]);
        self::assertSame('123456789', self::$db->query('SELECT tracker_id_public FROM 202_trackers WHERE tracker_id = ' . (int) $mine['tracker_id'])->fetch_row()[0], 'a tracker keeps its own id');
        self::refusedOnPublicId(fn () => $this->create(self::THEIRS, ['tracker_id_public' => 123_456_789]));
    }

    public function testAGeneratedIdIsDrawnUntilFree(): void
    {
        $taken = (int) $this->create(self::THEIRS)['tracker_id_public'];
        $draws = [$taken, $taken, 55_555_555];
        $random = static function (int $min, int $max) use (&$draws): int {
            return array_shift($draws) ?? throw new \LogicException('drawn too often');
        };
        self::assertSame(55_555_555, TrackerPublicId::generate(self::$db, $random));

        $always = static fn (int $min, int $max): int => $taken;
        try {
            TrackerPublicId::generate(self::$db, $always);
            self::fail('a generator that only finds taken ids answered one');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('No free tracker public id', $e->getMessage());
        }
    }

    public function testGetLinksIdsAreTheirShapeAndFree(): void
    {
        // Their tracker holds 1 + 4321 + 1, the id Get Links would draw first
        // for tracker 4321 of mine.
        self::assertTrue(self::$db->query('INSERT INTO 202_trackers SET user_id = ' . self::THEIRS . ', aff_campaign_id = ' . $this->campaign[self::THEIRS] . ', tracker_id_public = 143211, text_ad_id = 0, ppc_account_id = 0, landing_page_id = 0, click_cloaking = -1, tracker_time = 0'), self::$db->error);
        $digits = [1, 1, 2, 7];
        $random = static function (int $min, int $max) use (&$digits): int {
            return array_shift($digits) ?? throw new \LogicException('drawn too often');
        };
        self::assertSame(243217, TrackerPublicId::forPage(self::$db, 4321, $random));
    }
}
