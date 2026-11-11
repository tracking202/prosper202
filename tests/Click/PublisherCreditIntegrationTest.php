<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\ClickRecordBuilder;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Click\PublisherCredit;
use Prosper202\Database\Connection;

/**
 * Publisher links (t202pubid) on a real server: a click is credited only to
 * a publisher the link's account granted, an active Publisher of the same
 * install, named exactly, by an account that may grant. Every other id names
 * nobody, and the click stays the link's account's either way.
 *
 * @group integration
 */
final class PublisherCreditIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const INSTALL = 'pubcredit-install';
    private const OTHER_INSTALL = 'pubcredit-elsewhere';

    /** An Admin: may grant. */
    private const OWNER = 990801;
    /** A Campaign manager with campaigns of its own: may not grant. */
    private const MANAGER = 990802;
    private const PUBLISHER = 990803;
    /** A Campaign manager's public id: no Publisher role. */
    private const UNGRANTED = 990804;
    private const SWITCHED_OFF = 990805;
    private const REMOVED = 990806;
    /** A Publisher of another install. */
    private const FOREIGN = 990807;
    private const TWIN_A = 990808;
    private const TWIN_B = 990809;
    /** An Admin and a Publisher whose install hash is blank (accounts made before 1.8.2.1). */
    private const BLANK_OWNER = 990810;
    private const BLANK_PUBLISHER = 990811;
    private const FIRST_CLICK = 99080101;

    /** user => [role, install, active, deleted, public id] */
    private const USERS = [
        self::OWNER => [2, self::INSTALL, 1, 0, 'a0a0a0a0a0'],
        self::MANAGER => [3, self::INSTALL, 1, 0, 'b0b0b0b0b0'],
        self::PUBLISHER => [6, self::INSTALL, 1, 0, 'c0c0c0c0c0'],
        self::UNGRANTED => [3, self::INSTALL, 1, 0, 'd0d0d0d0d0'],
        self::SWITCHED_OFF => [6, self::INSTALL, 0, 0, 'e0e0e0e0e0'],
        self::REMOVED => [6, self::INSTALL, 1, 1, 'f0f0f0f0f0'],
        self::FOREIGN => [6, self::OTHER_INSTALL, 1, 0, 'a1a1a1a1a1'],
        self::TWIN_A => [6, self::INSTALL, 1, 0, 'b1b1b1b1b1'],
        self::TWIN_B => [6, self::INSTALL, 1, 0, 'b1b1b1b1b1'],
        self::BLANK_OWNER => [2, '', 1, 0, 'a2a2a2a2a2'],
        self::BLANK_PUBLISHER => [6, '', 1, 0, 'b2b2b2b2b2'],
    ];

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
        // The seeded roles and the one permission the grant asks for.
        self::q("INSERT IGNORE INTO 202_roles (role_id, role_name) VALUES (2, 'Admin'), (3, 'Campaign manager'), (6, 'Publisher')");
        self::q("INSERT IGNORE INTO 202_permissions (permission_id, permission_description) VALUES (1, 'add_users')");
        self::q('INSERT IGNORE INTO 202_role_permission (role_id, permission_id) VALUES (2, 1)');
        foreach (self::USERS as $user => [$role, $install, $active, $deleted, $publicId]) {
            self::q('INSERT INTO 202_users SET user_id = ' . $user . ", user_name = 'pc$user', user_fname = 'Pub',"
                . " user_lname = '$user', user_pass = '', user_email = '', user_dash_email = '', user_time_register = 1,"
                . " install_hash = '$install', user_hash = '', user_active = $active, user_deleted = $deleted,"
                . " user_public_publisher_id = '$publicId'");
            self::q("INSERT INTO 202_user_role SET user_id = $user, role_id = $role");
        }
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
        $users = implode(',', array_keys(self::USERS));
        self::q("DELETE FROM 202_user_role WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_users WHERE user_id IN ($users)");
        $range = 'BETWEEN ' . self::FIRST_CLICK . ' AND ' . (self::FIRST_CLICK + 9);
        foreach ([
            '202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking', '202_clicks_record',
            '202_clicks_site', '202_clicks_publisher', '202_click_latest_visits', '202_attribution_rollup_dirty_clicks',
            '202_google',
        ] as $table) {
            self::q("DELETE FROM $table WHERE click_id $range");
        }
    }

    private static function credit(): PublisherCredit
    {
        return new PublisherCredit(new Connection(self::requireScratch()));
    }

    public function testAGrantedPublisherIsCredited(): void
    {
        self::assertSame(
            ['user_id' => self::PUBLISHER, 'public_id' => 'c0c0c0c0c0'],
            self::credit()->forClick(self::OWNER, 'c0c0c0c0c0')
        );
    }

    public function testAUserWithoutThePublisherRoleIsIgnored(): void
    {
        self::assertNull(self::credit()->forClick(self::OWNER, 'd0d0d0d0d0'));
        // Nor is the link's own account credited to itself.
        self::assertNull(self::credit()->forClick(self::OWNER, 'a0a0a0a0a0'));
    }

    public function testAnAccountThatMayNotGrantCreditsNoPublisher(): void
    {
        self::assertNull(self::credit()->forClick(self::MANAGER, 'c0c0c0c0c0'));
        self::assertSame([], self::credit()->grantable(self::MANAGER));
    }

    public function testAnotherInstallsPublisherIsIgnored(): void
    {
        self::assertNull(self::credit()->forClick(self::OWNER, 'a1a1a1a1a1'));
    }

    /** A blank install hash names no install, so two blank ones are not the same install. */
    public function testABlankInstallHashMatchesNothing(): void
    {
        self::assertNull(self::credit()->forClick(self::BLANK_OWNER, 'b2b2b2b2b2'));
        self::assertNull(self::credit()->forClick(self::OWNER, 'b2b2b2b2b2'));
        self::assertSame([], self::credit()->grantable(self::BLANK_OWNER));
    }

    public function testASwitchedOffOrRemovedPublisherIsIgnored(): void
    {
        self::assertNull(self::credit()->forClick(self::OWNER, 'e0e0e0e0e0'));
        self::assertNull(self::credit()->forClick(self::OWNER, 'f0f0f0f0f0'));
    }

    public function testAnUnknownAmbiguousOrInexactIdIsIgnored(): void
    {
        self::assertNull(self::credit()->forClick(self::OWNER, '9999999999'));
        self::assertNull(self::credit()->forClick(self::OWNER, 'b1b1b1b1b1'), 'two users hold it');
        self::assertNull(self::credit()->forClick(self::OWNER, 'C0C0C0C0C0'), 'the column ignores case; the id does not');
        self::assertNull(self::credit()->forClick(self::OWNER, "c0c0c0c0c0' OR '1"));
        self::assertNull(self::credit()->forClick(self::OWNER, null));
    }

    public function testGetLinksOffersOnlyTheGrantedPublishers(): void
    {
        $offered = self::credit()->grantable(self::OWNER);
        self::assertSame([self::PUBLISHER], array_column($offered, 'user_id'));
        self::assertSame('Pub ' . self::PUBLISHER, $offered[0]['name']);
        self::assertNotNull(self::credit()->grantableById(self::OWNER, self::PUBLISHER));
        self::assertNull(self::credit()->grantableById(self::OWNER, self::UNGRANTED), 'a posted id is a claim');
        self::assertNull(self::credit()->grantableById(self::OWNER, self::FOREIGN));
    }

    public function testAUserTheApiCreatesHasAPublisherIdALinkCanCarry(): void
    {
        if (!function_exists('hash_user_pass')) {
            require_once dirname(__DIR__, 2) . '/202-config/functions-auth.php';
        }
        $users = new \Api\V3\Controllers\UsersController(self::requireScratch());
        $created = $users->create([
            'user_name' => 'pc-api-publisher', 'user_email' => 'pc-api-publisher@example.com',
            'user_pass' => 'a long enough password',
        ])['data'];
        try {
            $publicId = $created['user_public_publisher_id'];
            self::assertMatchesRegularExpression('/\A[0-9a-f]{10}\z/', (string) $publicId);
            self::assertSame($publicId, PublisherCredit::requested([PublisherCredit::PARAMETER => $publicId]));
            self::assertSame($publicId, $users->get((int) $created['user_id'])['data']['user_public_publisher_id']);
        } finally {
            $id = (int) $created['user_id'];
            foreach (['202_users', '202_users_pref', '202_attribution_models'] as $table) {
                self::q("DELETE FROM $table WHERE user_id = $id");
            }
        }
    }

    public function testACreditedClickStaysTheAccountsAndNamesItsPublisher(): void
    {
        $repo = new MysqlClickRepository(new Connection(self::requireScratch()));
        $credited = self::FIRST_CLICK;
        $plain = self::FIRST_CLICK + 1;
        $repo->recordClick(ClickRecordBuilder::fromLegacyArray([
            'user_id' => (string) self::OWNER,
            'click_time' => (string) time(),
            'publisher_user_id' => (string) self::PUBLISHER,
            'publisher_tracker_id_public' => '4200000009',
        ], $credited));
        $repo->recordClick(new ClickRecord(clickId: $plain, userId: self::OWNER, clickTime: time()));

        $owner = self::$db->query("SELECT user_id FROM 202_clicks WHERE click_id = $credited")->fetch_row();
        self::assertSame((string) self::OWNER, (string) $owner[0], 'the click is the link\'s account\'s');
        $credit = self::$db->query(
            "SELECT publisher_user_id, tracker_id_public FROM 202_clicks_publisher WHERE click_id = $credited"
        )->fetch_row();
        self::assertSame((string) self::PUBLISHER, (string) $credit[0]);
        self::assertSame('4200000009', (string) $credit[1], 'the link the click came on');
        self::assertSame('c0c0c0c0c0', self::credit()->publicIdOfClick($credited));

        self::assertSame(0, self::$db->query("SELECT 1 FROM 202_clicks_publisher WHERE click_id = $plain")->num_rows);
        self::assertNull(self::credit()->publicIdOfClick($plain));
    }
}
