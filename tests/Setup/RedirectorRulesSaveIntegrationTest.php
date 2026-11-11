<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;
use Prosper202\Rotator\IpCriterion;

/**
 * Setup › Redirectors' save (tracking202/ajax/rotator.php, posted by
 * p202-setup.js) writes the redirector's default and its rule set through
 * RotatorsController::setDefaultAndRules(): in one transaction, under the
 * redirector's lock, or not at all. A kept rule, criterion and destination
 * keep their ids (a click's report rows name them), and a rule the save
 * leaves out goes with its criteria and destinations.
 *
 * The page wrote them statement by statement: a failure part way left part
 * of the old set and part of the new, and a removed rule's destinations
 * stayed behind. The page runs as the account in a process of its own
 * (fixtures/page-runner.php), as the web server runs it.
 *
 * Needs a 202-config.php (connect.php exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class RedirectorRulesSaveIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990261;
    private const OTHER = 990262;
    private const ROLE = 990261;
    private const NETWORK = 990261;
    private const OFFER = 990261;
    private const OTHER_OFFER = 990262;
    private const ROTATOR = 990261;
    private const THEIR_ROTATOR = 990262;
    /** Rule KEPT is posted back; rule GONE is left out. */
    private const KEPT = 990261;
    private const GONE = 990262;
    private const REFUSED_URL = 'https://refused.example/';

    private static string $sessions = '';

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    /** @var list<int> the permission rows this class added */
    private static array $addedPermissions = [];

    private static int $removeRulePermission = 0;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/202-config.php')) {
            self::markTestSkipped('No 202-config.php: connect.php would exit before any page ran.'
                . ' tests/run-integration-suites.sh writes one.');
        }
        if (!self::connectScratch()) {
            return;
        }
        require_once $root . '/202-config/version.php';
        $found = self::$db->query('SELECT version FROM 202_version');
        $rows = $found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [];
        self::$versions = array_column($rows, 'version');
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => PROSPER202_VERSION]);
        self::cleanUp();
        self::seed();
        self::$sessions = sys_get_temp_dir() . '/p202-redirector-save-' . getmypid();
        if (!is_dir(self::$sessions) && !mkdir(self::$sessions, 0700)) {
            throw new \RuntimeException('could not create ' . self::$sessions);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::q('DROP TRIGGER IF EXISTS p202_test_refuse_redirect');
            self::cleanUp();
            if (self::$versions !== null) {
                self::q('DELETE FROM 202_version');
                foreach (self::$versions as $version) {
                    self::row('202_version', ['version' => $version]);
                }
            }
        }
        if (self::$sessions !== '' && is_dir(self::$sessions)) {
            array_map('unlink', glob(self::$sessions . '/sess_*') ?: []);
            rmdir(self::$sessions);
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::seedRedirectors();
    }

    /**
     * The save keeps the rule, criterion and destination it posts back by
     * id, adds the new ones, writes the default with them, and removes the
     * rule it leaves out with its criterion and destination.
     */
    public function testASaveKeepsWhatItPostsBackRemovesWhatItLeavesOutAndWritesTheDefault(): void
    {
        $out = self::save(self::post());

        self::assertSame('DONE', trim($out));
        $after = self::snapshot();
        self::assertSame(['default_url' => 'https://default.example/', 'default_campaign' => null,
            'default_lp' => null, 'auto_monetizer' => null], $after['rotator'], 'the default is written');

        self::assertCount(2, $after['rules'], 'the kept rule and the new one');
        self::assertSame([(string) self::KEPT, 'Kept, renamed', '1', '1', '1'], array_values($after['rules'][0]), 'the kept rule keeps its id');
        self::assertSame(['Added', '0', '0', '2'], array_slice(array_values($after['rules'][1]), 1), 'the new rule is tried second');
        $added = (int) $after['rules'][1]['id'];

        self::assertSame([
            [(string) self::KEPT, (string) self::KEPT, 'country', 'is', 'United Kingdom(GB)'],
            [null, (string) $added, 'device', 'is_not', 'mobile'],
        ], array_map(static fn (array $c): array => [$c['id'] === (string) self::KEPT ? $c['id'] : null,
            $c['rule_id'], $c['type'], $c['statement'], $c['value']], $after['criteria']), 'the kept criterion keeps its id');

        self::assertSame([
            [(string) self::KEPT, (string) self::KEPT, null, (string) self::OTHER_OFFER, '60', 'Campaign: Other offer'],
            [null, (string) self::KEPT, 'https://split.example/', null, '40', 'URL: <a href=https://split.example/>link</a>'],
            [null, (string) $added, 'https://added.example/', null, '100', 'URL: <a href=https://added.example/>link</a>'],
        ], array_map(static fn (array $r): array => [$r['id'] === (string) self::KEPT ? $r['id'] : null, $r['rule_id'],
            $r['redirect_url'], $r['redirect_campaign'], $r['weight'], $r['name']], $after['redirects']), 'the kept destination keeps its id');

        self::assertSame('0|0|0', self::leftOf(self::GONE), 'the rule left out goes with its criterion and destination');
    }

    /**
     * A failure part way, after the default, the removal of the rule left
     * out and the kept rule's update were written, leaves all of them as
     * they were.
     */
    public function testAFailurePartWayLeavesTheDefaultAndTheRulesAsTheyWere(): void
    {
        $before = self::snapshot();
        $post = self::post();
        $post['data'][0]['redirects'][1]['value'] = self::REFUSED_URL;

        self::q("CREATE TRIGGER p202_test_refuse_redirect BEFORE INSERT ON 202_rotator_rules_redirects FOR EACH ROW
            IF NEW.redirect_url = '" . self::REFUSED_URL . "' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'redirect refused by the test';
            END IF");
        try {
            $out = self::save($post);
        } finally {
            self::q('DROP TRIGGER IF EXISTS p202_test_refuse_redirect');
        }

        self::assertStringNotContainsString('DONE', $out);
        self::assertSame($before, self::snapshot(), 'the default, the rules, their criteria and destinations are as they were');
        $refused = self::requireScratch()->query('SELECT COUNT(*) FROM 202_mysql_errors WHERE user_id = ' . self::USER
            . " AND mysql_error_text = 'Failed to insert redirect'");
        self::assertInstanceOf(\mysqli_result::class, $refused);
        self::assertSame('1', (string) $refused->fetch_row()[0], 'the failure the page recorded is the refused insert');

        // The same save without the trigger is written whole.
        self::assertSame('DONE', trim(self::save($post)));
        self::assertSame('0|0|0', self::leftOf(self::GONE));
    }

    /**
     * A rule the set refuses is refused in the page's words, and the default
     * posted with it is not written either.
     */
    public function testARefusedRuleWritesNothingNotEvenTheDefault(): void
    {
        $before = self::snapshot();
        $post = self::post();
        $post['data'][0]['redirects'][1]['weight'] = '150';

        $out = self::save($post);

        self::assertSame('ERROR: Rule 1, destination 2, weight: A whole number from 0 to 100.', trim($out));
        self::assertSame($before, self::snapshot());
    }

    /** JK3D: a default_type the page does not know is refused before any write (it ran `UPDATE 202_rotators SET `). */
    public function testAnUnknownDefaultTypeWritesNothing(): void
    {
        $before = self::snapshot();
        foreach (['bogus', 'default_url', ''] as $type) {
            $post = self::post();
            $post['default_type'] = $type;

            self::assertStringStartsWith('ERROR', trim(self::save($post)), $type);
            self::assertSame($before, self::snapshot(), $type);
        }
    }

    /**
     * A criterion is checked and stored as the API checks and stores one: an
     * IP criterion that is not a list of addresses is refused with
     * IpCriterion's reason, and one that is is stored canonical.
     */
    public function testAnIpCriterionIsRefusedOrStoredAsTheApiDoes(): void
    {
        $before = self::snapshot();
        $post = self::post();
        $post['data'][1]['criteria'][0] = ['criteria_id' => 'none', 'type' => 'ip', 'statement' => 'is', 'value' => '203.0.113.0/24'];
        $reason = IpCriterion::refusal('203.0.113.0/24');
        self::assertNotNull($reason);

        self::assertSame('ERROR: Rule 2, criterion 1: ' . rtrim($reason, '.') . '.', trim(self::save($post)));
        self::assertSame($before, self::snapshot());

        $post['data'][1]['criteria'][0]['value'] = '2001:DB8::77, 198.51.100.88';
        self::assertSame('DONE', trim(self::save($post)));
        $stored = self::requireScratch()->query("SELECT value FROM 202_rotator_rules_criteria WHERE type = 'ip' AND rotator_id = " . self::ROTATOR);
        self::assertInstanceOf(\mysqli_result::class, $stored);
        self::assertSame([['2001:db8::77,198.51.100.88']], $stored->fetch_all());
    }

    /**
     * A country typed as a code and a device in capitals are saved in the
     * form the redirects match, by the API's normalizer, and the save says
     * what it changed. They were refused, and the redirector could not be
     * saved until each was corrected by hand.
     */
    public function testACountryCodeAndACapitalisedDeviceAreSavedAsTheRedirectsMatchThem(): void
    {
        $post = self::post();
        $post['data'][0]['criteria'][0]['value'] = 'uk, us';
        $post['data'][1]['criteria'][0]['value'] = 'Mobile';

        [$done, $said] = explode("\n", trim(self::save($post)), 2) + [1 => ''];
        self::assertSame('DONE', $done);
        self::assertSame([
            'Rule 1: "uk" was saved as "United Kingdom(GB)", the form the redirects match.',
            'Rule 1: "us" was saved as "United States(US)", the form the redirects match.',
            'Rule 2: "Mobile" was saved as "mobile", the form the redirects match.',
        ], json_decode($said, true));
        self::assertSame(
            ['United Kingdom(GB),United States(US)', 'mobile'],
            array_column(self::snapshot()['criteria'], 'value')
        );
        // Saved again as stored, there is nothing to say.
        $post['data'][0]['criteria'][0]['value'] = 'United Kingdom(GB),United States(US)';
        $post['data'][1]['criteria'][0]['value'] = 'mobile';
        $post['data'][1]['rule_id'] = (string) self::snapshot()['rules'][1]['id'];
        $post['data'][1]['criteria'][0]['criteria_id'] = (string) self::snapshot()['criteria'][1]['id'];
        $post['data'][0]['redirects'][1]['id'] = (string) self::snapshot()['redirects'][1]['id'];
        $post['data'][1]['redirects'][0]['id'] = (string) self::snapshot()['redirects'][2]['id'];
        self::assertSame('DONE', trim(self::save($post)));
    }

    /**
     * A rule's value is suggested as it is typed: a country from the list
     * the save normalizes with, a link's value from what the account's own
     * clicks carried (and no other account's).
     */
    public function testAValueIsSuggestedFromTheCountriesAndTheAccountsOwnClicks(): void
    {
        $suggest = static fn (string $type, string $query): mixed => json_decode(
            self::page('tracking202/ajax/rotator.php', ['autocomplete' => 'true', 'type' => $type, 'query' => $query]),
            true
        );
        self::assertSame('United Kingdom(GB)', $suggest('country', 'uk')[0]);
        self::assertContains('Germany(DE)', $suggest('country', 'germ'));

        $now = time();
        $lookup = static function (string $table, string $id, string $column, int $key, string $value): void {
            self::q("DELETE FROM $table WHERE $id = $key");
            self::row($table, [$id => $key, $column => $value]);
        };
        $lookup('202_utm_source', 'utm_source_id', 'utm_source', 990261, 'newsletter');
        $lookup('202_utm_source', 'utm_source_id', 'utm_source', 990262, 'news_partner');
        $lookup('202_utm_source', 'utm_source_id', 'utm_source', 990263, 'newsroom');
        $lookup('202_utm_source', 'utm_source_id', 'utm_source', 990264, 'news,wire');
        $lookup('202_utm_source', 'utm_source_id', 'utm_source', 990265, 'newspaper');
        $click = 99026100;
        $seen = static function (int $user, int $source, int $clicks, int $time) use (&$click): void {
            self::row('202_dataengine', ['user_id' => $user, 'click_id' => ++$click, 'click_time' => $time,
                'ppc_account_id' => 0, 'landing_page_id' => 0, 'utm_source_id' => $source, 'clicks' => $clicks, 'payout' => 0]);
        };
        self::q('DELETE FROM 202_dataengine WHERE user_id IN (' . self::USER . ', ' . self::OTHER . ')');
        try {
            $seen(self::USER, 990261, 2, $now - 3600);
            $seen(self::USER, 990262, 5, $now - 7200);
            $seen(self::OTHER, 990263, 9, $now - 3600);
            $seen(self::USER, 990264, 9, $now - 3600);
            $seen(self::USER, 990265, 9, $now - 40 * 86400);
            self::assertSame(['news_partner', 'newsletter'], $suggest('utm_source', 'news'), 'the busiest first');
            self::assertSame(['news_partner'], $suggest('utm_source', 's_p'), 'an underscore is itself, not any character');
            self::assertSame([], $suggest('utm_source', 'zz'));
            self::assertSame([], $suggest('weekday', 'mon'), 'a type with no list answers nothing');
            // Every type's statement runs on the server.
            $conn = new \Prosper202\Database\Connection(self::requireScratch());
            foreach (['c1', 'c2', 'c3', 'c4', 't202kw', 'referer', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $type) {
                self::assertSame([], \Prosper202\Rotator\SeenValues::matching($conn, self::USER, $type, 'news', $now), $type);
            }
        } finally {
            self::q('DELETE FROM 202_dataengine WHERE user_id IN (' . self::USER . ', ' . self::OTHER . ')');
            self::q('DELETE FROM 202_utm_source WHERE utm_source_id BETWEEN 990261 AND 990265');
        }
    }

    /**
     * The page says, beside the rule, when a rule sends no visitor
     * anywhere: a fraud rule with no IPQualityScore key saved, with the
     * way to the setting.
     */
    public function testThePageWarnsOfARuleThatMatchesNoVisitor(): void
    {
        self::q("UPDATE 202_rotator_rules_criteria SET type = 'fraud', value = 'true' WHERE id = " . self::KEPT);
        $page = self::page('tracking202/setup/rotator.php', ['rotator_id' => (string) self::ROTATOR]);
        self::assertStringContainsString('No IPQualityScore key is saved', $page);
        self::assertStringContainsString('202-account/api-integrations.php#ipqs">Add a key</a>', $page);
        self::assertStringContainsString('check this rule', $page);
        self::assertStringContainsString('data-type-warnings="{&quot;fraud&quot;', $page);

        self::q("UPDATE 202_rotator_rules_criteria SET type = 'country', value = 'United Kingdom(GB)' WHERE id = " . self::KEPT);
        $page = self::page('tracking202/setup/rotator.php', ['rotator_id' => (string) self::ROTATOR]);
        self::assertStringNotContainsString('check this rule', $page);
        self::assertStringContainsString('data-criterion-warning', $page);
    }

    /**
     * Whether an ISP rule can be chosen follows the account's own MaxMind
     * ISP setting, the one its redirects read, not the first account's.
     */
    public function testAnIspRuleFollowsTheAccountsOwnIspSetting(): void
    {
        $page = static fn (): string => self::page('tracking202/setup/rotator.php', ['rotator_id' => (string) self::ROTATOR]);
        $set = static fn (int $user, int $isp) => self::q("INSERT INTO 202_users_pref SET user_id = $user, maxmind_isp = '$isp'"
            . " ON DUPLICATE KEY UPDATE maxmind_isp = '$isp'");
        $firstHad = self::requireScratch()->query('SELECT maxmind_isp FROM 202_users_pref WHERE user_id = 1')->fetch_row();
        try {
            $set(1, 1);
            $set(self::USER, 0);
            self::assertStringContainsString('(needs MaxMind ISP)', $page());
            $set(1, 0);
            $set(self::USER, 1);
            self::assertStringNotContainsString('(needs MaxMind ISP)', $page());
        } finally {
            $set(self::USER, 0);
            if ($firstHad === null) {
                self::q('DELETE FROM 202_users_pref WHERE user_id = 1');
            } else {
                $set(1, (int) $firstHad[0]);
            }
        }
    }

    /**
     * A save that would remove a rule needs remove_rotator_rule, as the
     * page's remove button does, and refuses before anything is written.
     */
    public function testRemovingARuleNeedsRemoveRotatorRule(): void
    {
        $before = self::snapshot();
        self::q('DELETE FROM 202_role_permission WHERE role_id = ' . self::ROLE
            . ' AND permission_id = ' . self::$removeRulePermission);
        try {
            $out = self::save(self::post());
            self::assertSame("This account's role does not have the 'remove_rotator_rule' permission.", trim($out));
            self::assertSame($before, self::snapshot());

            // Keeping every rule needs no such permission.
            $post = self::post();
            $post['data'][] = self::gone();
            self::assertSame('DONE', trim(self::save($post)));
            self::assertSame('1|1|1', self::leftOf(self::GONE));
        } finally {
            self::row('202_role_permission', ['role_id' => self::ROLE, 'permission_id' => self::$removeRulePermission]);
        }
    }

    /** Another account's redirector is not this account's to save. */
    public function testAnotherAccountsRedirectorIsRefused(): void
    {
        $theirs = self::requireScratch()->query('SELECT default_campaign FROM 202_rotators WHERE id = ' . self::THEIR_ROTATOR);
        self::assertInstanceOf(\mysqli_result::class, $theirs);
        $held = $theirs->fetch_row();
        $post = self::post();
        $post['rotator_id'] = (string) self::THEIR_ROTATOR;
        foreach ($post['data'] as &$rule) {
            $rule['rule_id'] = 'none';
            foreach ($rule['criteria'] as &$c) {
                $c['criteria_id'] = 'none';
            }
            foreach ($rule['redirects'] as &$r) {
                $r['id'] = 'none';
            }
        }
        unset($rule, $c, $r);

        self::assertSame('ERROR', trim(self::save($post)));
        $found = self::requireScratch()->query('SELECT default_campaign, (SELECT COUNT(*) FROM 202_rotator_rules WHERE rotator_id = '
            . self::THEIR_ROTATOR . ') FROM 202_rotators WHERE id = ' . self::THEIR_ROTATOR);
        self::assertInstanceOf(\mysqli_result::class, $found);
        self::assertSame([$held[0], '0'], $found->fetch_row(), 'their redirector is as it was');
    }

    /**
     * The page's post: the default as a URL, the kept rule renamed with its
     * criterion and destination changed in place and a second destination
     * added (a split test), and a new rule; rule GONE left out.
     *
     * @return array<string, mixed>
     */
    private static function post(): array
    {
        return [
            'token' => 'SESSION',
            'post_rules' => '1',
            'rotator_id' => (string) self::ROTATOR,
            'default_type' => 'url',
            'defaults' => 'https://default.example/',
            'data' => [
                [
                    'rule_id' => (string) self::KEPT, 'rule_name' => 'Kept, renamed', 'status' => 'active', 'split' => 'true',
                    'criteria' => [['criteria_id' => (string) self::KEPT, 'type' => 'country', 'statement' => 'is', 'value' => 'United Kingdom(GB)']],
                    'redirects' => [
                        ['id' => (string) self::KEPT, 'type' => 'campaign', 'value' => (string) self::OTHER_OFFER, 'weight' => '60'],
                        ['id' => 'none', 'type' => 'url', 'value' => 'https://split.example/', 'weight' => '40'],
                    ],
                ],
                [
                    'rule_id' => 'none', 'rule_name' => 'Added', 'status' => 'inactive', 'split' => 'false',
                    'criteria' => [['criteria_id' => 'none', 'type' => 'device', 'statement' => 'is_not', 'value' => 'mobile']],
                    'redirects' => [['id' => 'none', 'type' => 'url', 'value' => 'https://added.example/']],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> rule GONE posted back as it is */
    private static function gone(): array
    {
        return [
            'rule_id' => (string) self::GONE, 'rule_name' => 'Gone', 'status' => 'active', 'split' => 'false',
            'criteria' => [['criteria_id' => (string) self::GONE, 'type' => 'country', 'statement' => 'is', 'value' => 'Canada(CA)']],
            'redirects' => [['id' => (string) self::GONE, 'type' => 'url', 'value' => 'https://gone.example/']],
        ];
    }

    /** "rules|criteria|destinations" of a rule, a destination counted by its rule or by its own id. */
    private static function leftOf(int $rule): string
    {
        $found = self::requireScratch()->query("SELECT CONCAT(
            (SELECT COUNT(*) FROM 202_rotator_rules WHERE id = $rule), '|',
            (SELECT COUNT(*) FROM 202_rotator_rules_criteria WHERE rule_id = $rule OR id = $rule), '|',
            (SELECT COUNT(*) FROM 202_rotator_rules_redirects WHERE rule_id = $rule OR id = $rule))");
        self::assertInstanceOf(\mysqli_result::class, $found);

        return (string) $found->fetch_row()[0];
    }

    /** @return array{rotator: array<string, ?string>, rules: list<array<string, ?string>>, criteria: list<array<string, ?string>>, redirects: list<array<string, ?string>>} */
    private static function snapshot(): array
    {
        $r = self::ROTATOR;
        $read = static function (string $sql): array {
            $found = self::requireScratch()->query($sql);
            self::assertInstanceOf(\mysqli_result::class, $found, (string) self::$db->error);

            return $found->fetch_all(MYSQLI_ASSOC);
        };
        $rules = "SELECT id FROM 202_rotator_rules WHERE rotator_id = $r";

        return [
            'rotator' => $read("SELECT default_url, default_campaign, default_lp, auto_monetizer FROM 202_rotators WHERE id = $r")[0],
            'rules' => $read("SELECT id, rule_name, splittest, status, position FROM 202_rotator_rules WHERE rotator_id = $r ORDER BY position, id"),
            'criteria' => $read("SELECT id, rule_id, type, statement, value FROM 202_rotator_rules_criteria WHERE rotator_id = $r ORDER BY id"),
            'redirects' => $read('SELECT id, rule_id, redirect_url, redirect_campaign, weight, name FROM 202_rotator_rules_redirects'
                . " WHERE rule_id IN ($rules) OR rule_id IN (" . self::KEPT . ', ' . self::GONE . ') ORDER BY id'),
        ];
    }

    private static function cleanUp(): void
    {
        self::clearRedirectors();
        foreach (['202_user_role', '202_users_pref', '202_users', '202_aff_networks', '202_aff_campaigns', '202_mysql_errors'] as $table) {
            self::q("DELETE FROM $table WHERE user_id IN (" . self::USER . ', ' . self::OTHER . ')');
        }
        self::q('DELETE FROM 202_role_permission WHERE role_id = ' . self::ROLE);
        self::q('DELETE FROM 202_roles WHERE role_id = ' . self::ROLE);
        foreach (self::$addedPermissions as $permission) {
            self::q('DELETE FROM 202_permissions WHERE permission_id = ' . $permission);
        }
        self::$addedPermissions = [];
    }

    private static function clearRedirectors(): void
    {
        $rotators = self::ROTATOR . ', ' . self::THEIR_ROTATOR;
        $rules = "SELECT id FROM 202_rotator_rules WHERE rotator_id IN ($rotators)";
        $own = self::KEPT . ', ' . self::GONE;
        self::q("DELETE FROM 202_rotator_rules_redirects WHERE rule_id IN ($rules) OR rule_id IN ($own) OR id IN ($own)");
        self::q("DELETE FROM 202_rotator_rules_criteria WHERE rotator_id IN ($rotators) OR id IN ($own)");
        self::q("DELETE FROM 202_rotator_rules WHERE rotator_id IN ($rotators) OR id IN ($own)");
        self::q("DELETE FROM 202_rotators WHERE id IN ($rotators)");
    }

    private static function seed(): void
    {
        $t = time();
        $u = self::USER;
        foreach ([$u, self::OTHER] as $user) {
            self::user($user);
            self::row('202_users_pref', ['user_id' => $user]);
        }
        self::q("UPDATE 202_users SET p202_customer_api_key = 'page-runner' WHERE user_id = $u");
        self::row('202_roles', ['role_id' => self::ROLE, 'role_name' => 'redirector save test']);
        foreach (['access_to_setup_section', 'remove_rotator_rule'] as $name) {
            $found = self::$db->query("SELECT permission_id FROM 202_permissions WHERE permission_description = '$name'");
            $permission = $found instanceof \mysqli_result ? ($found->fetch_row()[0] ?? null) : null;
            if ($permission === null) {
                $permission = self::insert("INSERT INTO 202_permissions SET permission_description = '$name'");
                self::$addedPermissions[] = $permission;
            }
            if ($name === 'remove_rotator_rule') {
                self::$removeRulePermission = (int) $permission;
            }
            self::row('202_role_permission', ['role_id' => self::ROLE, 'permission_id' => (int) $permission]);
        }
        self::row('202_user_role', ['user_id' => $u, 'role_id' => self::ROLE]);
        self::row('202_aff_networks', ['aff_network_id' => self::NETWORK, 'user_id' => $u,
            'aff_network_name' => 'Category', 'aff_network_time' => $t]);
        foreach ([self::OFFER => 'Offer', self::OTHER_OFFER => 'Other offer'] as $id => $name) {
            self::row('202_aff_campaigns', [
                'aff_campaign_id' => $id, 'user_id' => $u, 'aff_network_id' => self::NETWORK,
                'aff_campaign_name' => $name, 'aff_campaign_url' => 'https://offer.example/' . $id,
                'aff_campaign_payout' => 1, 'aff_campaign_foreign_payout' => 0, 'aff_campaign_time' => $t,
            ]);
        }
    }

    /** The account's redirector with rules KEPT and GONE, and another account's with none. */
    private static function seedRedirectors(): void
    {
        self::clearRedirectors();
        self::row('202_rotators', ['id' => self::ROTATOR, 'public_id' => self::ROTATOR, 'user_id' => self::USER,
            'name' => 'My Redirector', 'default_campaign' => self::OFFER]);
        self::row('202_rotators', ['id' => self::THEIR_ROTATOR, 'public_id' => self::THEIR_ROTATOR, 'user_id' => self::OTHER,
            'name' => 'Their Redirector', 'default_url' => 'https://their.example/']);
        foreach ([self::KEPT => ['Kept', 1, 'United States(US)'], self::GONE => ['Gone', 2, 'Canada(CA)']] as $id => [$name, $position, $country]) {
            self::row('202_rotator_rules', ['id' => $id, 'rotator_id' => self::ROTATOR, 'rule_name' => $name,
                'splittest' => 0, 'status' => 1, 'position' => $position]);
            self::row('202_rotator_rules_criteria', ['id' => $id, 'rotator_id' => self::ROTATOR, 'rule_id' => $id,
                'type' => 'country', 'statement' => 'is', 'value' => $country]);
        }
        self::row('202_rotator_rules_redirects', ['id' => self::KEPT, 'rule_id' => self::KEPT,
            'redirect_campaign' => self::OFFER, 'weight' => '100', 'name' => 'Campaign: Offer']);
        self::row('202_rotator_rules_redirects', ['id' => self::GONE, 'rule_id' => self::GONE,
            'redirect_url' => 'https://gone.example/', 'weight' => '100', 'name' => 'URL: <a href=https://gone.example/>link</a>']);
        self::q('DELETE FROM 202_mysql_errors WHERE user_id = ' . self::USER);
    }

    /**
     * What the save printed for $post.
     *
     * @param array<string, mixed> $post
     */
    private static function save(array $post): string
    {
        return self::page('tracking202/ajax/rotator.php', [], $post);
    }

    /**
     * What a page printed for the account.
     *
     * @param array<string, string> $get
     * @param array<string, mixed> $post
     */
    private static function page(string $page, array $get, array $post = []): string
    {
        self::requireScratch();
        $env = [
            'P202_TEST_REPORT_USER' => (string) self::USER,
            'P202_TEST_SESSION_DIR' => self::$sessions,
            'P202_TEST_GET' => $get === [] ? '{}' : (string) json_encode($get),
            'P202_TEST_POST' => $post === [] ? '{}' : (string) json_encode($post),
            'P202_TEST_AJAX' => $post === [] ? '0' : '1',
        ] + getenv();
        $proc = proc_open(
            [
                PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED',
                __DIR__ . '/fixtures/page-runner.php', $page,
            ],
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
        $code = proc_close($proc);
        self::assertNotSame(2, $code, "the runner could not set up: $err");
        self::assertStringNotContainsString('Fatal error', $out . $err, $err);

        return $out;
    }
}
