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
 * a publisher the link's account granted (202_publisher_grants), an active
 * Publisher of the same install, named exactly, by an account that may grant.
 * Every other id names nobody, another account's publisher included, and the
 * click stays the link's account's either way.
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
    /** A second Admin of the same install, who granted no publisher. */
    private const OTHER_OWNER = 990812;
    /** An Admin and a Publisher the account delete removes, made by that test alone. */
    private const DELETED_OWNER = 990815;
    private const DELETED_PUBLISHER = 990816;
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
        self::OTHER_OWNER => [2, self::INSTALL, 1, 0, 'a3a3a3a3a3'],
    ];

    /** The grants the fixture starts each test with: owner => publishers. */
    private const GRANTS = [
        self::OWNER => [
            self::PUBLISHER, self::SWITCHED_OFF, self::REMOVED, self::FOREIGN, self::UNGRANTED, self::BLANK_PUBLISHER,
        ],
        self::MANAGER => [self::PUBLISHER],
        self::BLANK_OWNER => [self::BLANK_PUBLISHER],
    ];

    /** @var list<string>|null 202_version as this class found it */
    private static ?array $versions = null;

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
            self::addUser($user, $role, $install, $active, $deleted, $publicId);
        }
        $found = self::$db->query('SELECT version FROM 202_version');
        self::$versions = $found instanceof \mysqli_result
            ? array_column($found->fetch_all(MYSQLI_ASSOC), 'version')
            : null;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            return;
        }
        // Every grant a check below needs is there, so a refusal is the rule's and not a missing row.
        self::removeGrants();
        foreach (self::GRANTS as $owner => $publishers) {
            foreach ($publishers as $publisher) {
                self::q("INSERT INTO 202_publisher_grants SET user_id = $owner,"
                    . " publisher_user_id = $publisher, granted_at = 1");
            }
        }
    }

    private static function addUser(
        int $user,
        int $role,
        string $install,
        int $active,
        int $deleted,
        string $publicId
    ): void {
        self::q('INSERT INTO 202_users SET user_id = ' . $user . ", user_name = 'pc$user', user_fname = 'Pub',"
            . " user_lname = '$user', user_pass = '', user_email = '', user_dash_email = '', user_time_register = 1,"
            . " install_hash = '$install', user_hash = '', user_active = $active, user_deleted = $deleted,"
            . " user_public_publisher_id = '$publicId'");
        self::q("INSERT INTO 202_user_role SET user_id = $user, role_id = $role");
    }

    /** Every user id the class writes. */
    private static function userList(): string
    {
        return implode(',', [...array_keys(self::USERS), self::DELETED_OWNER, self::DELETED_PUBLISHER]);
    }

    private static function removeGrants(): void
    {
        $users = self::userList();
        self::q("DELETE FROM 202_publisher_grants WHERE user_id IN ($users) OR publisher_user_id IN ($users)");
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
        $users = self::userList();
        self::removeGrants();
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

    /** The hole PB7S closed: any Admin's link credited any Publisher of the install. */
    public function testAPublisherAnotherAccountGrantedIsNotCredited(): void
    {
        self::assertSame([], self::credit()->granted(self::OTHER_OWNER));
        self::assertNull(self::credit()->forClick(self::OTHER_OWNER, 'c0c0c0c0c0'));
        // Still a publisher this account could grant, by making a link for them.
        self::assertNotNull(self::credit()->grantableById(self::OTHER_OWNER, self::PUBLISHER));
        self::credit()->grant(self::OTHER_OWNER, self::PUBLISHER, 5);
        self::assertSame(
            ['user_id' => self::PUBLISHER, 'public_id' => 'c0c0c0c0c0'],
            self::credit()->forClick(self::OTHER_OWNER, 'c0c0c0c0c0')
        );
    }

    public function testARevokedGrantCreditsNoOneAndOnlyThatAccountsIsRevoked(): void
    {
        self::credit()->grant(self::OTHER_OWNER, self::PUBLISHER, 5);
        self::assertTrue(self::credit()->revoke(self::OWNER, self::PUBLISHER));
        self::assertNull(self::credit()->forClick(self::OWNER, 'c0c0c0c0c0'));
        self::assertFalse(self::credit()->revoke(self::OWNER, self::PUBLISHER), 'nothing left to remove');
        self::assertNotNull(
            self::credit()->forClick(self::OTHER_OWNER, 'c0c0c0c0c0'),
            'another account\'s grant stands'
        );
    }

    /** A deleted account's grants go on both sides; every other account's stay. */
    public function testDeletingAUserRemovesTheGrantsThatNameIt(): void
    {
        $pairs = static function (): array {
            $found = self::$db->query("SELECT CONCAT(user_id, '>', publisher_user_id) FROM 202_publisher_grants"
                . ' WHERE user_id IN (' . self::userList() . ') ORDER BY 1');
            self::assertInstanceOf(\mysqli_result::class, $found);

            return array_column($found->fetch_all(), 0);
        };
        self::addUser(self::DELETED_OWNER, 2, self::INSTALL, 1, 0, 'a4a4a4a4a4');
        self::addUser(self::DELETED_PUBLISHER, 6, self::INSTALL, 1, 0, 'b4b4b4b4b4');
        try {
            $made = [
                [self::OWNER, self::DELETED_PUBLISHER],
                [self::OTHER_OWNER, self::DELETED_PUBLISHER],
                [self::DELETED_OWNER, self::PUBLISHER],
            ];
            foreach ($made as [$owner, $publisher]) {
                self::credit()->grant($owner, $publisher, 7);
            }
            $before = $pairs();
            self::assertContains(self::OTHER_OWNER . '>' . self::DELETED_PUBLISHER, $before, 'the fixture landed');

            $purge = new \Prosper202\User\UserDataPurge(self::requireScratch());
            $purge->deleteUser(self::DELETED_PUBLISHER);
            $purge->deleteUser(self::DELETED_OWNER);

            $gone = [
                self::OWNER . '>' . self::DELETED_PUBLISHER,
                self::OTHER_OWNER . '>' . self::DELETED_PUBLISHER,
                self::DELETED_OWNER . '>' . self::PUBLISHER,
            ];
            $expected = array_values(array_diff($before, $gone));
            self::assertCount(count($before) - 3, $expected);
            self::assertSame($expected, $pairs());
            self::assertContains(self::OWNER . '>' . self::PUBLISHER, $expected, 'another grant of the publisher stays');
        } finally {
            $doomed = self::DELETED_OWNER . ', ' . self::DELETED_PUBLISHER;
            self::removeGrants();
            self::q("DELETE FROM 202_user_role WHERE user_id IN ($doomed)");
            self::q("DELETE FROM 202_users WHERE user_id IN ($doomed)");
        }
    }

    public function testAGrantMadeAgainKeepsItsFirstTime(): void
    {
        self::credit()->grant(self::OTHER_OWNER, self::PUBLISHER, 5);
        self::credit()->grant(self::OTHER_OWNER, self::PUBLISHER, 9);
        $row = self::$db->query('SELECT COUNT(*), MIN(granted_at) FROM 202_publisher_grants WHERE user_id = '
            . self::OTHER_OWNER . ' AND publisher_user_id = ' . self::PUBLISHER)->fetch_row();
        self::assertSame(['1', '5'], array_map('strval', $row));
    }

    public function testAGrantNamesTwoDifferentUsers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::credit()->grant(self::OWNER, self::OWNER, 5);
    }

    /**
     * Giving the Publisher role grants the user to the account that gave it,
     * on both paths that give a role: User management and POST /users/{id}/roles.
     */
    public function testGivingThePublisherRoleGrantsItToTheAccountThatGaveIt(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/functions-account-ui.php';
        $grants = static fn (int $owner): array => array_column(self::credit()->granted($owner), 'user_id');
        $publisherRole = PublisherCredit::PUBLISHER_ROLE_ID;
        try {
            $users = new \Api\V3\Controllers\UsersController(self::requireScratch());
            // An account that may not grant gives the role and is granted nothing.
            $users->assignRole(self::UNGRANTED, ['role_id' => $publisherRole], self::MANAGER);
            self::assertNotContains(self::UNGRANTED, $grants(self::MANAGER));
            // Another role grants nothing.
            self::q('DELETE FROM 202_user_role WHERE user_id = ' . self::UNGRANTED . " AND role_id = $publisherRole");
            $users->assignRole(self::UNGRANTED, ['role_id' => 3], self::OTHER_OWNER);
            self::assertSame([], $grants(self::OTHER_OWNER));
            $users->assignRole(self::UNGRANTED, ['role_id' => $publisherRole], self::OTHER_OWNER);
            self::assertSame([self::UNGRANTED], $grants(self::OTHER_OWNER));
            self::assertNotNull(self::credit()->forClick(self::OTHER_OWNER, 'd0d0d0d0d0'));

            // User management gives the role: the user held only Campaign manager before this save.
            self::q('DELETE FROM 202_publisher_grants WHERE user_id = ' . self::OTHER_OWNER);
            self::q('DELETE FROM 202_user_role WHERE user_id = ' . self::UNGRANTED . " AND role_id = $publisherRole");
            $conn = new Connection(self::requireScratch());
            $role = $publisherRole;
            p202_account_save_user($conn, self::UNGRANTED, ['user_fname' => 'Pub'], $role, null, self::OTHER_OWNER);
            self::assertSame([self::UNGRANTED], $grants(self::OTHER_OWNER));
            self::assertNotContains(self::UNGRANTED, $grants(self::MANAGER), 'only the account that gave it');
        } finally {
            self::q('DELETE FROM 202_user_role WHERE user_id = ' . self::UNGRANTED);
            self::q('INSERT INTO 202_user_role SET user_id = ' . self::UNGRANTED . ', role_id = 3');
        }
    }

    /**
     * A grant Get Links took away stays away when the publisher, who already
     * holds the role, is saved again in User management or given the role
     * again through POST /users/{id}/roles.
     */
    public function testSavingAPublisherAgainDoesNotUndoAStop(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/functions-account-ui.php';
        $grants = static fn (): array => array_column(self::credit()->granted(self::OWNER), 'user_id');
        $publisherRole = PublisherCredit::PUBLISHER_ROLE_ID;
        self::assertContains(self::PUBLISHER, $grants(), 'the fixture landed');
        self::assertTrue(self::credit()->revoke(self::OWNER, self::PUBLISHER));

        $conn = new Connection(self::requireScratch());
        p202_account_save_user($conn, self::PUBLISHER, ['user_fname' => 'Pub'], $publisherRole, null, self::OWNER);
        self::assertNotContains(self::PUBLISHER, $grants(), 'a save of a user who held the role grants nothing');
        self::assertNull(self::credit()->forClick(self::OWNER, 'c0c0c0c0c0'));

        (new \Api\V3\Controllers\UsersController(self::requireScratch()))
            ->assignRole(self::PUBLISHER, ['role_id' => $publisherRole], self::OWNER);
        self::assertNotContains(self::PUBLISHER, $grants(), 'a role already held, assigned again, grants nothing');
        self::assertNull(self::credit()->forClick(self::OWNER, 'c0c0c0c0c0'));

        $found = self::$db->query('SELECT role_id FROM 202_user_role WHERE user_id = ' . self::PUBLISHER);
        self::assertInstanceOf(\mysqli_result::class, $found);
        self::assertSame([(string) $publisherRole], array_map('strval', array_column($found->fetch_all(), 0)), 'the role stays');
    }

    public function testGetLinksListsTheGrantsAndWhichOfThemCreditNow(): void
    {
        $granted = self::credit()->granted(self::OWNER);
        $byId = array_column($granted, 'credits', 'user_id');
        // The removed account is not listed; the rest are, crediting only when the rule still holds.
        self::assertEqualsCanonicalizing(
            [self::PUBLISHER, self::SWITCHED_OFF, self::FOREIGN, self::UNGRANTED, self::BLANK_PUBLISHER],
            array_keys($byId)
        );
        self::assertTrue($byId[self::PUBLISHER]);
        self::assertFalse($byId[self::SWITCHED_OFF]);
        self::assertFalse($byId[self::FOREIGN]);
        self::assertFalse($byId[self::UNGRANTED]);
        self::assertFalse($byId[self::BLANK_PUBLISHER]);
    }

    /**
     * Code deployed before the upgrade ran: the table is not there yet. On a
     * stored 1.9.80 the answer is the one the rung will write (any granting
     * account of the install); on a 1.9.81 schema it is an error, which the
     * click path logs and credits nobody.
     */
    public function testAMissingGrantsTableAnswersByTheStoredVersion(): void
    {
        self::q('RENAME TABLE 202_publisher_grants TO 202_publisher_grants_held');
        try {
            self::q('DELETE FROM 202_version');
            self::q("INSERT INTO 202_version SET version = '1.9.80'");
            self::assertNotNull(self::credit()->forClick(self::OTHER_OWNER, 'c0c0c0c0c0'));
            self::assertNull(self::credit()->forClick(self::MANAGER, 'c0c0c0c0c0'), 'the other checks still hold');
            // Get Links and the role writes still save: the rung grants the pair.
            self::credit()->grant(self::OTHER_OWNER, self::PUBLISHER, 5);
            self::assertSame([self::PUBLISHER], array_column(self::credit()->granted(self::OTHER_OWNER), 'user_id'));

            self::q('DELETE FROM 202_version');
            self::q("INSERT INTO 202_version SET version = '1.9.81'");
            $calls = [
                'forClick' => static fn () => self::credit()->forClick(self::OWNER, 'c0c0c0c0c0'),
                'grant' => static fn () => self::credit()->grant(self::OTHER_OWNER, self::PUBLISHER, 5),
                'granted' => static fn () => self::credit()->granted(self::OTHER_OWNER),
            ];
            foreach ($calls as $call => $run) {
                $refused = null;
                try {
                    $run();
                } catch (\RuntimeException $e) {
                    $refused = $e->getMessage();
                }
                self::assertNotNull($refused, "$call answered without the table on a 1.9.81 schema");
                self::assertStringContainsString('202_publisher_grants', $refused, $call);
            }
        } finally {
            self::q('RENAME TABLE 202_publisher_grants_held TO 202_publisher_grants');
            self::restoreVersions();
        }
    }

    /** The 1.9.81 rung grants each pair that could be credited before, once. */
    public function testTheUpgradeGrantsWhatCouldBeCreditedBefore(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/functions-upgrade.php';
        $held = $GLOBALS['db'] ?? null;
        $GLOBALS['db'] = self::$db;
        $users = implode(',', array_keys(self::USERS));
        self::q('CREATE TEMPORARY TABLE pc_grants_held AS SELECT * FROM 202_publisher_grants WHERE user_id NOT IN ('
            . $users . ') AND publisher_user_id NOT IN (' . $users . ')');
        try {
            self::q('DELETE FROM 202_publisher_grants');
            self::assertTrue(_upgrade_publisher_grants());
            $pairs = self::$db->query("SELECT CONCAT(user_id, '>', publisher_user_id) FROM 202_publisher_grants"
                . " WHERE user_id IN ($users) ORDER BY 1")->fetch_all();
            $expected = [];
            foreach ([self::OWNER, self::OTHER_OWNER] as $owner) {
                foreach ([self::PUBLISHER, self::SWITCHED_OFF, self::TWIN_A, self::TWIN_B] as $publisher) {
                    $expected[] = "$owner>$publisher";
                }
            }
            sort($expected);
            // Not the manager (no add_users), the blank install, the removed or the other install's publisher.
            self::assertSame($expected, array_column($pairs, 0));

            // A table that holds grants was seeded already: a re-run adds nothing, a revoke stands.
            self::q('DELETE FROM 202_publisher_grants WHERE user_id = ' . self::OTHER_OWNER);
            self::assertTrue(_upgrade_publisher_grants());
            $left = self::$db->query('SELECT 1 FROM 202_publisher_grants WHERE user_id = ' . self::OTHER_OWNER);
            self::assertSame(0, $left->num_rows);
        } finally {
            self::q('DELETE FROM 202_publisher_grants');
            self::q('INSERT INTO 202_publisher_grants SELECT * FROM pc_grants_held');
            self::q('DROP TEMPORARY TABLE pc_grants_held');
            if ($held === null) {
                unset($GLOBALS['db']);
            } else {
                $GLOBALS['db'] = $held;
            }
        }
    }

    private static function restoreVersions(): void
    {
        self::q('DELETE FROM 202_version');
        foreach (self::$versions ?? [] as $version) {
            self::q("INSERT INTO 202_version SET version = '" . self::$db->real_escape_string($version) . "'");
        }
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
