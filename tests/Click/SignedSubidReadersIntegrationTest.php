<?php

declare(strict_types=1);

namespace Tests\Click;

use Api\V3\Apps\Android\InstallTokenKey;
use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\ClickRef;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Click\PurLinkExit;
use Prosper202\Click\PurLinkProof;
use Prosper202\Click\RecordedClicks;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Conversion\RevenueUploadImporter;
use Prosper202\Database\Connection;
use Prosper202\PaymentNotification\InboundRequest;
use Prosper202\PaymentNotification\Notification;
use Prosper202\PaymentNotification\NotificationKind;
use Prosper202\PaymentNotification\OutcomeKind;
use Prosper202\PaymentNotification\PayKickstart\PayKickstartAdapter;
use Prosper202\PaymentNotification\Processor;
use Prosper202\PaymentNotification\Verification;
use Prosper202\Update\SubidBatch;

/**
 * Every reader of a network's or provider's subid takes a signed PurLink
 * SubID (`click-offer-mac`) as gpb.php and pb.php do: its click, credited on
 * its offer, once the MAC verifies. A forged one records nothing.
 *
 * @group integration
 */
final class SignedSubidReadersIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const OWNER = 990451;
    private const OTHER = 990452;
    private const FIRST_CLICK = 99045101;
    private const OFFER_PUBLIC = 11251;

    private static int $offer = 0;
    private static int $page = 0;
    private static string $key = '';
    private static int $next = 0;

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
        self::user(self::OWNER);
        self::user(self::OTHER);
        self::postbackAuthOff(self::OWNER);
        self::postbackAuthOff(self::OTHER);
        InstallTokenKey::ensure(self::$db);
        self::$key = (string) InstallTokenKey::load(self::$db);
        self::$offer = self::insert('INSERT INTO 202_aff_campaigns SET user_id = ' . self::OWNER . ', aff_network_id = 1,'
            . " aff_campaign_name = 'offer', aff_campaign_url = 'https://shop.example/', aff_campaign_payout = 9.5,"
            . ' aff_campaign_time = 1, aff_campaign_foreign_payout = 0, aff_campaign_id_public = ' . self::OFFER_PUBLIC);
        self::$page = self::insert('INSERT INTO 202_landing_pages SET user_id = ' . self::OWNER
            . ", aff_campaign_id = 0, landing_page_type = 1, landing_page_nickname = 'advanced',"
            . " landing_page_url = 'https://example.test/', landing_page_time = 1");
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
            self::$db = null;
        }
    }

    private static function cleanUp(): void
    {
        $range = 'BETWEEN ' . self::FIRST_CLICK . ' AND ' . (self::FIRST_CLICK + 99);
        self::q('DELETE p FROM 202_attribution_pending p JOIN 202_conversion_logs c ON c.conv_id = p.conv_id'
            . " WHERE c.click_id $range");
        self::q("DELETE FROM 202_conversion_logs WHERE click_id $range");
        foreach ([
            '202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking', '202_clicks_variable',
            '202_google', '202_clicks_rotator', '202_clicks_record', '202_clicks_site', '202_click_latest_visits',
            '202_attribution_rollup_dirty_clicks', '202_purlink_exit_order', '202_dataengine',
        ] as $table) {
            self::q("DELETE FROM $table WHERE click_id $range");
        }
        $users = self::OWNER . ', ' . self::OTHER;
        foreach (['202_landing_pages', '202_aff_campaigns', '202_conversion_uploads', '202_revenue_events',
            '202_dirty_hours', '202_postback_auth', '202_users'] as $table) {
            self::q("DELETE FROM $table WHERE user_id IN ($users)");
        }
    }

    /** A click on the advanced page, and the SubID purlink-prepare.php signs for the offer. */
    private static function signedClick(): array
    {
        $db = self::requireScratch();
        $clickId = self::FIRST_CLICK + self::$next++;
        (new MysqlClickRepository(new Connection($db)))->recordClick(new ClickRecord(
            clickId: $clickId,
            userId: self::OWNER,
            clickTime: time() - 60,
            affCampaignId: 0,
            landingPageId: self::$page,
            clickPayout: '0',
            clickIdPublic: (string) (712000 + self::$next),
            clickCloaking: -1,
            clickRefererSiteUrlId: 40,
            clickLandingSiteUrlId: 41,
            clickOutboundSiteUrlId: 42,
            clickCloakingSiteUrlId: 43,
            clickRedirectSiteUrlId: 44,
        ));
        RecordedClicks::note($clickId);
        $now = time();
        $proof = PurLinkProof::issue(PurLinkProof::EXIT, $clickId, self::$page, self::OWNER, $now, 60, self::$key);
        $subid = (new PurLinkExit(new Connection($db)))->subid($proof, (string) self::OFFER_PUBLIC, $now, self::$key);
        self::assertIsString($subid);

        return [$clickId, $subid];
    }

    private static function forged(string $subid): string
    {
        return substr($subid, 0, -1) . (str_ends_with($subid, '0') ? '1' : '0');
    }

    /** @return list<array{string, string}> each conversion's campaign and payout */
    private static function conversions(int $clickId): array
    {
        $result = self::requireScratch()->query(
            "SELECT campaign_id, click_payout FROM 202_conversion_logs WHERE click_id = $clickId AND deleted = 0 ORDER BY conv_id"
        );
        self::assertInstanceOf(\mysqli_result::class, $result);

        return array_map(static fn (array $row): array => [(string) $row[0], (string) $row[1]], $result->fetch_all());
    }

    public function testParseReadsAClickIdOrASignedSubidAndNothingElse(): void
    {
        self::assertSame([42, null], [ClickRef::parse('42')?->clickId, ClickRef::parse('42')?->purLinkSubid]);
        $signed = ClickRef::parse('41-987-0123456789ab');
        self::assertSame([41, '41-987-0123456789ab'], [$signed?->clickId, $signed?->purLinkSubid]);
        foreach (['41-987', '41-987-0123456789AB', ' 41-987-0123456789ab', '4.2', '', null] as $bad) {
            self::assertNull(ClickRef::parse($bad), var_export($bad, true));
        }
    }

    public function testAPaymentNotificationRecordsASignedSubidOnItsOffer(): void
    {
        [$clickId, $subid] = self::signedClick();
        $processor = new Processor(self::requireScratch());
        $sale = static fn (string $value, string $txn): \Prosper202\PaymentNotification\Outcome => $processor->process(
            new Notification(NotificationKind::SALE, 'sale', $txn, null, '19.00', null, ClickRef::parse($value)),
            new Verification([self::OWNER => 0]),
            new PayKickstartAdapter(),
            new InboundRequest('POST', 'application/x-www-form-urlencoded', [])
        );

        self::assertSame(OutcomeKind::UNKNOWN_CLICK, $sale(self::forged($subid), 'pk-forged')->kind);
        self::assertSame([], self::conversions($clickId));
        $outcome = $sale($subid, 'pk-1');
        self::assertSame(OutcomeKind::RECORDED, $outcome->kind, $outcome->message);
        self::assertSame([[(string) self::$offer, '19.00000']], self::conversions($clickId));
    }

    public function testARevenueUploadRecordsASignedSubidOnItsOffer(): void
    {
        [$clickId, $subid] = self::signedClick();
        $conn = new Connection(self::requireScratch());
        $importer = new RevenueUploadImporter($conn, new MysqlConversionRepository($conn));
        $csv = static function () use ($subid) {
            $handle = fopen('php://memory', 'w+');
            self::assertIsResource($handle);
            fwrite($handle, "subid,commission\n$subid,4.00\n" . self::forged($subid) . ",1.00\n");
            rewind($handle);
            return $handle;
        };

        $preview = $importer->preview(self::OWNER, $csv(), 0, 1);
        self::assertSame([1, 1], [$preview['would_record'], $preview['skipped']]);
        self::assertSame(0, $importer->preview(self::OTHER, $csv(), 0, 1)['would_record'], 'another account');
        $result = $importer->import(self::OWNER, 'report.csv', $csv(), 0, 1);
        self::assertSame([1, 1], [$result['recorded'], $result['skipped']]);
        self::assertSame([[(string) self::$offer, '4.00000']], self::conversions($clickId));
    }

    public function testUpdateSubidsMarksASignedSubidOnItsOffer(): void
    {
        [$clickId, $subid] = self::signedClick();
        $batch = new SubidBatch(new Connection(self::requireScratch()));

        self::assertSame([SubidBatch::NOT_FOUND], array_column($batch->mark(self::OTHER, [$subid])['lines'], 'status'));
        self::assertSame(
            [SubidBatch::WOULD_MARK, SubidBatch::NOT_FOUND],
            array_column($batch->markPreview(self::OWNER, [$subid, self::forged($subid)])['lines'], 'status')
        );
        $marked = $batch->mark(self::OWNER, [$subid . ', 5.00', self::forged($subid)]);
        self::assertSame([SubidBatch::MARKED, SubidBatch::NOT_FOUND], array_column($marked['lines'], 'status'));
        self::assertSame([[(string) self::$offer, '5.00000']], self::conversions($clickId));

        $cleared = $batch->clear(self::OWNER, [$subid]);
        self::assertSame([SubidBatch::CLEARED], array_column($cleared['lines'], 'status'));
        self::assertSame([], self::conversions($clickId));
    }

    /** @return iterable<string, array{string}> */
    public static function pixels(): iterable
    {
        yield 'gpx.php' => ['gpx.php'];
        yield 'upx.php' => ['upx.php'];
    }

    /** @dataProvider pixels */
    public function testAConversionPixelRecordsASignedSubidOnItsOffer(string $script): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/202-config.php')) {
            self::markTestSkipped('No 202-config.php: tests/run-integration-suites.sh writes one.');
        }
        [$clickId, $subid] = self::signedClick();

        self::pixel($script, ['subid' => self::forged($subid), 'amount' => '3.00']);
        self::assertSame([], self::conversions($clickId), 'a forged SubID records nothing');
        self::pixel($script, ['subid' => $subid, 'amount' => '3.00']);
        self::assertSame([[(string) self::$offer, '3.00000']], self::conversions($clickId));
    }

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function postbacks(): iterable
    {
        yield 'gpb.php subid' => ['gpb.php', []];
        yield 'gpb.php sid, subid empty' => ['gpb.php', ['subid' => '']];
        yield 'gpb.php sid, subid garbage' => ['gpb.php', ['subid' => 'abc']];
        yield 'pb.php' => ['pb.php', ['acip' => (string) self::OFFER_PUBLIC]];
    }

    /**
     * @dataProvider postbacks
     * @param array<string, string> $extra
     */
    public function testAPostbackRecordsASignedSubidOnItsOffer(string $script, array $extra): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/202-config.php')) {
            self::markTestSkipped('No 202-config.php: tests/run-integration-suites.sh writes one.');
        }
        [$clickId, $subid] = self::signedClick();
        $name = array_key_exists('subid', $extra) ? 'sid' : 'subid';

        [$status] = self::pixel($script, $extra + [$name => self::forged($subid), 'amount' => '3.00']);
        self::assertSame(404, $status, 'a forged SubID is not found');
        self::assertSame([], self::conversions($clickId));
        [$status, $body] = self::pixel($script, $extra + [$name => $subid, 'amount' => '3.00']);
        self::assertLessThan(300, $status, $body);
        self::assertSame((string) self::$offer, self::conversions($clickId)[0][0] ?? null, $body);
    }

    public function testUpxAnswersUnavailableNotForgedWhenTheKeyCannotBeRead(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/202-config.php')) {
            self::markTestSkipped('No 202-config.php: tests/run-integration-suites.sh writes one.');
        }
        [$clickId, $subid] = self::signedClick();
        // The key is unreadable for this request: it is set aside, then put back.
        self::q("UPDATE 202_deployment_secrets SET secret_name = 'android_install_token_aside' WHERE secret_name = 'android_install_token'");
        try {
            [$status] = self::pixel('upx.php', ['subid' => $subid, 'amount' => '3.00']);
        } finally {
            self::q("UPDATE 202_deployment_secrets SET secret_name = 'android_install_token' WHERE secret_name = 'android_install_token_aside'");
        }
        self::assertSame(503, $status, 'a sender retrying 5xx tries again');
        self::assertSame([], self::conversions($clickId));
        self::assertSame(404, self::pixel('upx.php', ['subid' => self::forged($subid)])[0], 'a forged SubID is not found');
    }

    /**
     * @param array<string, string> $get
     * @return array{int, string} status and body
     */
    private static function pixel(string $script, array $get): array
    {
        $env = getenv();
        $env['P202_TEST_SCRIPT'] = $script;
        $env['P202_TEST_GET'] = json_encode((object) $get, JSON_THROW_ON_ERROR);
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_log=', dirname(__DIR__) . '/StaticEndpoint/fixtures/gpb-runner.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($proc), "$script exited non-zero: $err\n$out");
        [$status, $body] = explode("\n", $out, 2) + [1 => ''];

        return [(int) $status, $body];
    }
}
