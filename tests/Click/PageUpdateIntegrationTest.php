<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Click\PageUpdate;
use Prosper202\Click\PurLinkProof;
use Prosper202\Click\RecordedClicks;
use Prosper202\Database\Connection;

/**
 * dcs.updateDataObj()'s click update on a real server in strict mode: only
 * the proof's click, only while it is the proof's account's and on the
 * proof's page, only c1-c4 and the keyword, and the report row with it.
 *
 * @group integration
 */
final class PageUpdateIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const OWNER = 990501;
    private const OTHER = 990502;
    private const FIRST_CLICK = 99050101;
    private const KEY = 'page-update-integration-test-key';

    private static int $page = 0;
    private static int $otherPage = 0;

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
        foreach ([self::OWNER, self::OTHER] as $user) {
            self::user($user);
        }
        self::$page = self::page(self::OWNER);
        self::$otherPage = self::page(self::OTHER);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        RecordedClicks::reset();
    }

    private static function cleanUp(): void
    {
        $range = 'BETWEEN ' . self::FIRST_CLICK . ' AND ' . (self::FIRST_CLICK + 99);
        foreach (
            [
            '202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking', '202_clicks_record',
            '202_clicks_site', '202_clicks_variable', '202_google', '202_clicks_rotator', '202_click_latest_visits',
            '202_dataengine', '202_attribution_rollup_dirty_clicks',
            ] as $table
        ) {
            self::q("DELETE FROM $table WHERE click_id $range");
        }
        $users = self::OWNER . ', ' . self::OTHER;
        self::q("DELETE FROM 202_landing_pages WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_users WHERE user_id IN ($users)");
    }

    private static function page(int $userId): int
    {
        return self::insert("INSERT INTO 202_landing_pages SET user_id = $userId, aff_campaign_id = 0,"
            . " landing_page_nickname = 'update', landing_page_url = 'https://lp.example/', landing_page_time = 1");
    }

    /** A landing page click as record_simple.php records one, with c1 'from the url'. */
    private static function click(int $clickId, int $userId = self::OWNER, ?int $page = null, int $age = 60): void
    {
        $conn = new Connection(self::requireScratch());
        $tracking = new \Prosper202\Repository\Mysql\MysqlTrackingRepository($conn);
        (new MysqlClickRepository($conn))->recordClick(new ClickRecord(
            clickId: $clickId,
            userId: $userId,
            clickTime: time() - $age,
            landingPageId: $page ?? self::$page,
            clickPayout: '2.5',
            clickCpc: '0.4',
            keywordId: $tracking->findOrCreateKeyword('from the url'),
            c1Id: $tracking->findOrCreateC1('from the url'),
            c2Id: $tracking->findOrCreateC2('kept'),
            clickIdPublic: (string) ($clickId - self::FIRST_CLICK + 600000),
        ));
        RecordedClicks::note($clickId);
    }

    private static function proof(int $clickId, int $page, int $user = self::OWNER, int $now = 0): string
    {
        return PurLinkProof::issue(PurLinkProof::UPDATE, $clickId, $page, $user, $now ?: time(), 600, self::KEY);
    }

    /** @param array<string, mixed> $request */
    private static function update(string $proof, array $request, int $now = 0): string
    {
        $fields = PageUpdate::fields($request);
        self::assertNotNull($fields);

        return (new PageUpdate(new Connection(self::requireScratch())))->apply($proof, $fields['values'], $now ?: time(), self::KEY);
    }

    /** @return array<string, string|null> the click's values as reports read them */
    private static function values(int $clickId): array
    {
        $result = self::requireScratch()->query(
            'SELECT t1.c1, t2.c2, t3.c3, t4.c4, k.keyword, c.click_cpc, c.click_payout, c.landing_page_id,'
            . ' de.c1_id = ct.c1_id AS report_c1, de.keyword_id = ca.keyword_id AS report_keyword,'
            . ' (SELECT COUNT(*) FROM 202_attribution_rollup_dirty_clicks d WHERE d.click_id = c.click_id) AS marks'
            . ' FROM 202_clicks c JOIN 202_clicks_tracking ct USING (click_id) JOIN 202_clicks_advance ca USING (click_id)'
            . ' LEFT JOIN 202_tracking_c1 t1 ON t1.c1_id = ct.c1_id LEFT JOIN 202_tracking_c2 t2 ON t2.c2_id = ct.c2_id'
            . ' LEFT JOIN 202_tracking_c3 t3 ON t3.c3_id = ct.c3_id LEFT JOIN 202_tracking_c4 t4 ON t4.c4_id = ct.c4_id'
            . ' LEFT JOIN 202_keywords k ON k.keyword_id = ca.keyword_id'
            . ' LEFT JOIN 202_dataengine de ON de.click_id = c.click_id'
            . " WHERE c.click_id = $clickId"
        );
        self::assertInstanceOf(\mysqli_result::class, $result, (string) self::$db->error);
        $row = $result->fetch_assoc();
        self::assertIsArray($row);

        return $row;
    }

    public function testTheProofsClickTakesC1ToC4AndTheKeywordAndNothingElse(): void
    {
        $clickId = self::FIRST_CLICK + 1;
        $bystander = self::FIRST_CLICK + 2;
        self::click($clickId);
        self::click($bystander);
        $before = self::values($bystander);

        self::assertSame('updated', self::update(self::proof($clickId, self::$page), [
            'proof' => 'ignored here', 't202Subid' => (string) $bystander,
            't202C1' => 'Spring%20sale', 't202c3' => str_repeat('x', 400), 't202c4' => "caf\xC3\xA9", 't202Kw' => 'blue shoes',
            't202c2' => '', 't202Id' => '1', 't202cpc' => '9', 't202Utm_source' => 'news',
        ]));

        $after = self::values($clickId);
        self::assertSame(
            ['Spring sale', 'kept', str_repeat('x', 350), "caf\xC3\xA9", 'blue shoes', '0.40000', '2.50000', (string) self::$page],
            [$after['c1'], $after['c2'], $after['c3'], $after['c4'], $after['keyword'], $after['click_cpc'],
                $after['click_payout'], $after['landing_page_id']],
            'cut as the click recorder cuts; an empty c2 kept; cost and payout untouched'
        );
        self::assertSame(['1', '1'], [$after['report_c1'], $after['report_keyword']], 'the report row follows');
        self::assertSame('0', $after['marks'], 'a click too young to be summed needs no mark');
        self::assertSame($before, self::values($bystander), 'a subid in the request names no click');
    }

    public function testAnOldClickIsMarkedForTheRollup(): void
    {
        $clickId = self::FIRST_CLICK + 3;
        self::click($clickId, age: 7200);
        self::assertSame('updated', self::update(self::proof($clickId, self::$page), ['t202c1' => 'later']));
        self::assertSame(['later', '1'], [self::values($clickId)['c1'], self::values($clickId)['marks']]);
    }

    public function testAnotherAccountsClickOrPageOrAMovedClickIsRefused(): void
    {
        $theirs = self::FIRST_CLICK + 4;
        $mine = self::FIRST_CLICK + 5;
        self::click($theirs, self::OTHER, self::$otherPage);
        self::click($mine);
        $theirsBefore = self::values($theirs);
        $mineBefore = self::values($mine);

        // Signed by this install, as a forged account claim cannot be.
        self::assertSame('refused', self::update(self::proof($theirs, self::$otherPage, self::OWNER), ['t202c1' => 'x']), 'their click, my account');
        self::assertSame('refused', self::update(self::proof($theirs, self::$page, self::OWNER), ['t202c1' => 'x']), 'their click, my page');
        self::assertSame('refused', self::update(self::proof($mine, self::$otherPage, self::OTHER), ['t202c1' => 'x']), 'my click, their account');
        self::assertSame('refused', self::update(self::proof($mine, self::$otherPage), ['t202c1' => 'x']), 'my click, their page');
        self::assertSame($theirsBefore, self::values($theirs));
        self::assertSame($mineBefore, self::values($mine));

        $nextPage = self::page(self::OWNER);
        self::q("UPDATE 202_clicks SET landing_page_id = $nextPage WHERE click_id = $mine");
        self::assertSame('refused', self::update(self::proof($mine, self::$page), ['t202c1' => 'x']), 'moved to another page');
        self::q('UPDATE 202_clicks SET landing_page_id = ' . self::$page . " WHERE click_id = $mine");
        self::assertSame('from the url', self::values($mine)['c1']);

        RecordedClicks::note(self::FIRST_CLICK + 99);
        self::assertSame('refused', self::update(self::proof(self::FIRST_CLICK + 99, self::$page), ['t202c1' => 'x']), 'no such click');
    }

    public function testOnlyAValidUnexpiredUpdateProofIsAccepted(): void
    {
        $clickId = self::FIRST_CLICK + 6;
        self::click($clickId);
        $now = time();
        $proof = self::proof($clickId, self::$page, now: $now);
        $exit = PurLinkProof::issue(PurLinkProof::EXIT, $clickId, self::$page, self::OWNER, $now, 600, self::KEY);
        $pixels = PurLinkProof::issue(PurLinkProof::PIXELS, $clickId, self::$page, self::OWNER, $now, 600, self::KEY);

        foreach (
            [
            'an exit proof' => $exit, 'a pixel proof' => $pixels, 'tampered' => $proof . 'x',
            'another click' => str_replace('.' . $clickId . '.', '.' . ($clickId + 1) . '.', $proof), 'none' => '',
            ] as $why => $candidate
        ) {
            self::assertSame('refused', self::update($candidate, ['t202c1' => 'x'], $now), $why);
        }
        self::assertSame('refused', self::update($proof, ['t202c1' => 'x'], $now + 600), 'expired');
        $refused = (new PageUpdate(new Connection(self::requireScratch())))->apply($proof, ['c1' => 'x'], $now, str_repeat('k', 32));
        self::assertSame('refused', $refused, 'another install\'s key');
        self::assertSame('from the url', self::values($clickId)['c1']);

        self::assertSame('unchanged', self::update($proof, ['t202Id' => '1', 't202c1' => ''], $now), 'nothing a page may change');
        self::assertSame('updated', self::update($proof, ['t202c1' => 'x'], $now));
        self::assertSame('x', self::values($clickId)['c1']);
    }
}
