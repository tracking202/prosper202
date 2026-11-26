<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Click\OpenAiBrowserRef;
use Prosper202\Click\OpenAiPixelScript;
use Prosper202\Click\PurLinkProof;
use Prosper202\Click\RecordedClicks;
use Prosper202\Database\Connection;
use Prosper202\Setup\OpenAiPixelSettings;

/**
 * The ChatGPT Ads pixel on a real server in strict mode: the page's switch
 * is kept per account and only for simple pages, record.php's answer names
 * the account's own source's pixel, and the browser reference is kept,
 * replaced and removed only for the proof's click while it is the proof's
 * account's and on the proof's page.
 *
 * @group integration
 */
final class OpenAiBrowserRefIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const OWNER = 990601;
    private const OTHER = 990602;
    private const PURGED = 990603;
    private const FIRST_CLICK = 99060101;
    private const KEY = 'openai-ref-integration-test-key!';

    private static int $page = 0;
    private static int $otherPage = 0;

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
        foreach ([self::OWNER, self::OTHER, self::PURGED] as $user) {
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
            '202_dataengine', '202_attribution_rollup_dirty_clicks', '202_click_openai_refs',
            ] as $table
        ) {
            self::q("DELETE FROM $table WHERE click_id $range");
        }
        $users = self::OWNER . ', ' . self::OTHER . ', ' . self::PURGED;
        self::q('DELETE op FROM 202_landing_page_openai_pixel op JOIN 202_landing_pages lp USING (landing_page_id)'
            . " WHERE lp.user_id IN ($users)");
        self::q('DELETE p FROM 202_ppc_account_pixels p JOIN 202_ppc_accounts a USING (ppc_account_id)'
            . " WHERE a.user_id IN ($users)");
        self::q("DELETE FROM 202_ppc_accounts WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_landing_pages WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_users WHERE user_id IN ($users)");
    }

    private static function page(int $userId, int $type = 0): int
    {
        return self::insert("INSERT INTO 202_landing_pages SET user_id = $userId, aff_campaign_id = 0, landing_page_type = $type,"
            . " landing_page_nickname = 'openai', landing_page_url = 'https://lp.example/', landing_page_time = 1");
    }

    private static function click(int $clickId, int $userId = self::OWNER, ?int $page = null): void
    {
        (new MysqlClickRepository(new Connection(self::requireScratch())))->recordClick(new ClickRecord(
            clickId: $clickId,
            userId: $userId,
            clickTime: time() - 60,
            landingPageId: $page ?? self::$page,
            clickPayout: '0',
            clickCpc: '0',
            clickIdPublic: (string) ($clickId - self::FIRST_CLICK + 700000),
        ));
        RecordedClicks::note($clickId);
    }

    private static function proof(int $clickId, int $page, int $user = self::OWNER, int $now = 0, string $purpose = PurLinkProof::BROWSER_REF): string
    {
        return PurLinkProof::issue($purpose, $clickId, $page, $user, $now ?: time(), OpenAiBrowserRef::LIFETIME, self::KEY);
    }

    private static function apply(string $proof, ?string $obref, int $now = 0): string
    {
        return (new OpenAiBrowserRef(self::conn()))->apply($proof, $obref, $now ?: time(), self::KEY);
    }

    private static function conn(): Connection
    {
        return new Connection(self::requireScratch());
    }

    private static function stored(int $clickId): string
    {
        return OpenAiBrowserRef::of(self::conn(), $clickId);
    }

    public function testTheReferenceIsKeptReplacedAndRemoved(): void
    {
        $clickId = self::FIRST_CLICK + 1;
        $bystander = self::FIRST_CLICK + 2;
        self::click($clickId);
        self::click($bystander);
        self::assertSame('recorded', self::apply(self::proof($bystander, self::$page), 'theirs.1'));

        self::assertSame('', self::stored($clickId), 'none before the page posts');
        self::assertSame('recorded', self::apply(self::proof($clickId, self::$page), 'fb.1.1700000000.abc'));
        self::assertSame('fb.1.1700000000.abc', self::stored($clickId));
        self::assertSame('recorded', self::apply(self::proof($clickId, self::$page), 'fb.1.1700000001.def'));
        self::assertSame('fb.1.1700000001.def', self::stored($clickId), 'a later cookie replaces it');
        self::assertSame('removed', self::apply(self::proof($clickId, self::$page), null));
        self::assertSame('', self::stored($clickId), 'withdrawn consent removes it');
        self::assertSame('removed', self::apply(self::proof($clickId, self::$page), null), 'removing none is no error');
        self::assertSame('theirs.1', self::stored($bystander), 'another click keeps its own');

        $long = str_repeat('a', OpenAiBrowserRef::MAX);
        self::assertSame('recorded', self::apply(self::proof($clickId, self::$page), $long));
        self::assertSame($long, self::stored($clickId), 'kept whole at the column width');
    }

    public function testAnotherAccountsClickOrPageAnExpiredProofOrAnotherPurposeIsRefused(): void
    {
        $theirs = self::FIRST_CLICK + 3;
        $mine = self::FIRST_CLICK + 4;
        self::click($theirs, self::OTHER, self::$otherPage);
        self::click($mine);

        // Signed by this install, as a forged account claim cannot be.
        self::assertSame('refused', self::apply(self::proof($theirs, self::$otherPage, self::OWNER), 'x'), 'their click, my account');
        self::assertSame('refused', self::apply(self::proof($theirs, self::$page), 'x'), 'their click, my page');
        self::assertSame('refused', self::apply(self::proof($mine, self::$otherPage, self::OTHER), 'x'), 'my click, their account');
        self::assertSame('refused', self::apply(self::proof($mine, self::$otherPage), 'x'), 'my click, their page');
        self::assertSame('refused', self::apply(self::proof($theirs, self::$otherPage, self::OWNER), null), 'nor removed');
        self::assertSame('refused', self::apply(self::proof($mine, self::$page, now: time() - OpenAiBrowserRef::LIFETIME - 5), 'x'), 'expired');
        self::assertSame('refused', self::apply(self::proof($mine, self::$page, purpose: PurLinkProof::UPDATE), 'x'), 'a page update proof');
        self::assertSame(['', ''], [self::stored($theirs), self::stored($mine)]);

        $nextPage = self::page(self::OWNER);
        self::q("UPDATE 202_clicks SET landing_page_id = $nextPage WHERE click_id = $mine");
        self::assertSame('refused', self::apply(self::proof($mine, self::$page), 'x'), 'moved to another page');
        self::assertSame('', self::stored($mine));

        RecordedClicks::note(self::FIRST_CLICK + 99);
        self::assertSame('refused', self::apply(self::proof(self::FIRST_CLICK + 99, self::$page), 'x'), 'no such click');
    }

    public function testTheSwitchIsKeptPerAccountAndOnlyForSimplePages(): void
    {
        $settings = new OpenAiPixelSettings(self::conn());
        self::assertNull($settings->load(self::OWNER, self::$page));
        self::assertFalse($settings->isOn(self::OWNER, self::$page), 'no row is off');

        $settings->save(self::OWNER, self::$page, ['status' => 1]);
        self::assertSame(['status' => 1], $settings->load(self::OWNER, self::$page));
        self::assertTrue($settings->isOn(self::OWNER, self::$page));
        self::assertNull($settings->load(self::OTHER, self::$page), 'another account reads none');
        self::assertFalse($settings->isOn(self::OTHER, self::$page));

        $settings->save(self::OTHER, self::$page, ['status' => 0]);
        $settings->switchOff(self::OTHER, self::$page);
        self::assertTrue($settings->isOn(self::OWNER, self::$page), 'another account writes nothing to my page');
        $settings->save(self::OWNER, self::$otherPage, ['status' => 1]);
        self::assertNull($settings->load(self::OTHER, self::$otherPage), 'nor I to theirs');

        $settings->save(self::OWNER, self::$page, ['status' => 0]);
        self::assertSame(['status' => 0], $settings->load(self::OWNER, self::$page));
        self::assertFalse($settings->isOn(self::OWNER, self::$page));

        $advanced = self::page(self::OWNER, 1);
        $settings->save(self::OWNER, $advanced, ['status' => 1]);
        self::assertFalse($settings->isOn(self::OWNER, $advanced), 'an advanced page never loads it');
        $settings->switchOff(self::OWNER, $advanced);
        self::assertSame(['status' => 0], $settings->load(self::OWNER, $advanced));

        $deleted = self::page(self::OWNER);
        $settings->save(self::OWNER, $deleted, ['status' => 1]);
        self::q("UPDATE 202_landing_pages SET landing_page_deleted = 1 WHERE landing_page_id = $deleted");
        self::assertFalse($settings->isOn(self::OWNER, $deleted), 'a deleted page loads nothing');
    }

    public function testRecordPhpNamesTheAccountsOwnSourcesPixel(): void
    {
        $page = self::page(self::OWNER);
        (new OpenAiPixelSettings(self::conn()))->save(self::OWNER, $page, ['status' => 1]);
        $mine = self::insert('INSERT INTO 202_ppc_accounts SET user_id = ' . self::OWNER
            . ", ppc_network_id = 0, ppc_account_name = 'chatgpt', ppc_account_time = 1");
        $theirs = self::insert('INSERT INTO 202_ppc_accounts SET user_id = ' . self::OTHER
            . ", ppc_network_id = 0, ppc_account_name = 'chatgpt', ppc_account_time = 1");
        self::q("INSERT INTO 202_ppc_account_pixels (pixel_code, pixel_type_id, ppc_account_id) VALUES ('not an id !', 7, $mine)");
        self::q("INSERT INTO 202_ppc_account_pixels (pixel_code, pixel_type_id, ppc_account_id) VALUES ('px_mine', 7, $mine)");
        self::q("INSERT INTO 202_ppc_account_pixels (pixel_code, pixel_type_id, ppc_account_id) VALUES ('px_theirs', 7, $theirs)");
        $clickId = self::FIRST_CLICK + 5;
        self::click($clickId, self::OWNER, $page);
        $key = static fn (): string => self::KEY;
        $now = time();

        $answer = OpenAiPixelScript::forClick(self::conn(), self::OWNER, $page, $mine, $clickId, true, $key, $now);
        self::assertSame(1, preg_match('/p202OpenAiPixel\((\{.*\})\);/', $answer, $m), $answer);
        $config = json_decode($m[1], true, 4, JSON_THROW_ON_ERROR);
        self::assertSame('px_mine', $config['pixelId'], 'the first pixel holding an id');
        self::assertSame('recorded', self::apply($config['refProof'], 'ob.1', $now), 'the proof it hands out keeps the reference');
        self::assertSame('ob.1', self::stored($clickId));

        self::assertSame('', OpenAiPixelScript::forClick(self::conn(), self::OWNER, $page, $theirs, $clickId, true, $key, $now), 'another account\'s source');
        self::assertSame('', OpenAiPixelScript::forClick(self::conn(), self::OWNER, self::$page, $mine, $clickId, true, $key, $now), 'a page with the switch off');
        self::assertSame('', OpenAiPixelScript::forClick(self::conn(), self::OWNER, $page, $mine, $clickId, false, $key, $now), 'no tracking allowed');

        self::assertSame('oppref=abc%20d&', OpenAiPixelScript::opprefForLandingPage(self::conn(), self::OWNER, $page, ['oppref' => ' abc d ']));
        self::assertSame('', OpenAiPixelScript::opprefForLandingPage(self::conn(), self::OTHER, $page, ['oppref' => 'abc']), 'another account\'s page');
        self::assertSame('', OpenAiPixelScript::opprefForLandingPage(self::conn(), self::OWNER, $page, ['oppref' => 'abc', 'p202_consent' => '0']), 'consent refused');
    }

    public function testDeletingAnAccountRemovesItsReferencesAndNoOneElses(): void
    {
        $purgedPage = self::page(self::PURGED);
        $theirs = self::FIRST_CLICK + 6;
        $mine = self::FIRST_CLICK + 7;
        self::click($theirs, self::PURGED, $purgedPage);
        self::click($mine);
        self::assertSame('recorded', self::apply(self::proof($theirs, $purgedPage, self::PURGED), 'ob.purged'));
        self::assertSame('recorded', self::apply(self::proof($mine, self::$page), 'ob.kept'));

        (new \Prosper202\User\UserDataPurge(self::requireScratch()))->deleteUser(self::PURGED);

        self::assertSame(['', 'ob.kept'], [self::stored($theirs), self::stored($mine)]);
        self::assertContains(
            ['resource' => '202_click_openai_refs', 'action' => 'delete', 'where' => 'joined to a click of user ' . self::PURGED],
            \Prosper202\User\UserDataPurge::cascade(self::PURGED),
            'the preview says so'
        );
    }
}
