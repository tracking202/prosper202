<?php

declare(strict_types=1);

namespace Tests\Report;

use Api\V3\Controllers\RotatorsController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecordBuilder;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Database\Connection;
use Prosper202\DataEngine\ClickRollupSql;

/**
 * A click names the redirect that sent it by id (202_clicks_rotator, and
 * 202_dataengine's copy), and Group Overview's Redirect level names it
 * through that id. PUT /rotators/{id}/rules/{ruleId} with `redirects`
 * deleted the rule's redirects and inserted them again, so each took a new
 * id and every click on one was listed under no redirect. It writes them as
 * the set writes a rule it keeps (RotatorsController::writeKeptEntries()): a
 * redirect sent with its id keeps the id, one left out is removed, one sent
 * without an id is added.
 *
 * The click is written by the click writer and rolled up by the rollup; the
 * report is read by Group Overview's own query in a process that boots the
 * app (fixtures/account-scope-runner.php).
 *
 * Needs a 202-config.php (connect.php exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class RulePutKeepsRedirectIdsIntegrationTest extends TestCase
{
    use ScratchReportDatabase;

    private const USER = 5913;
    private const OTHER = 5914;

    private const LEVEL = 'group:DETAIL_LEVEL_ROTATOR_RULE_REDIRECT';

    /** The click rows recordClick() writes beside 202_clicks. */
    private const CLICK_ROWS = [
        '202_clicks_rotator', '202_clicks_advance', '202_clicks_tracking', '202_clicks_record', '202_clicks_site',
        '202_clicks_spy', '202_click_latest_visits',
    ];

    private static int $now = 0;

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/202-config.php')) {
            self::markTestSkipped('No 202-config.php: connect.php would exit before the report ran.'
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
        self::$now = time();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            if (self::$versions !== null) {
                self::q('DELETE FROM 202_version');
                foreach (self::$versions as $version) {
                    self::row('202_version', ['version' => $version]);
                }
            }
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::cleanUp();
        foreach ([self::USER, self::OTHER] as $user) {
            self::user($user);
            self::row('202_users_pref', [
                'user_id' => $user, 'user_pref_time_predefined' => 'today', 'user_pref_show' => 'all',
            ]);
        }
    }

    private static function cleanUp(): void
    {
        foreach ([self::USER, self::OTHER] as $u) {
            foreach (self::CLICK_ROWS as $table) {
                self::q("DELETE t FROM $table t JOIN 202_clicks c ON c.click_id = t.click_id WHERE c.user_id = $u");
            }
            $mine = "JOIN 202_rotators ro ON ro.id = r.rotator_id WHERE ro.user_id = $u";
            foreach (['202_rotator_rules_redirects', '202_rotator_rules_criteria'] as $table) {
                self::q("DELETE e FROM $table e JOIN 202_rotator_rules r ON r.id = e.rule_id $mine");
            }
            self::q("DELETE r FROM 202_rotator_rules r $mine");
            foreach (['202_clicks', '202_dataengine', '202_rotators', '202_users_pref', '202_users'] as $table) {
                self::q("DELETE FROM $table WHERE user_id = $u");
            }
        }
    }

    private static function rotators(int $user = self::USER): RotatorsController
    {
        return new RotatorsController(self::$db, $user);
    }

    /**
     * A redirector with one rule splitting every visitor between A and B.
     *
     * @return array{int, array<string, mixed>} the redirector's id, and its rule as GET answers it
     */
    private static function splitRule(int $user = self::USER): array
    {
        $created = self::rotators($user)->create(['name' => 'r', 'default_url' => 'https://default.example/']);
        $id = (int) $created['data']['id'];
        self::rotators($user)->createRule($id, [
            'rule_name' => 'split', 'splittest' => 1,
            'criteria' => [['type' => 'country', 'value' => 'ALL']],
            'redirects' => [
                ['redirect_url' => 'https://a.example/', 'weight' => 50, 'name' => 'A'],
                ['redirect_url' => 'https://b.example/', 'weight' => 50, 'name' => 'B'],
            ],
        ]);

        return [$id, self::rotators($user)->listRules($id)['data'][0]];
    }

    /** A click a redirect sent, as rtr.php writes it, rolled up as rtr.php has it rolled up. */
    private static function clickOn(int $rotator, int $rule, int $redirect): int
    {
        $repo = new MysqlClickRepository(new Connection(self::$db));
        $clickId = $repo->allocateClickId();
        $repo->recordClick(ClickRecordBuilder::forRedirector([
            'user_id' => self::USER, 'click_time' => self::$now, 'ppc_account_id' => 0,
            'rotator_id' => $rotator, 'rule_id' => $rule, 'rule_redirect_id' => $redirect,
        ], $clickId), static fn (): int => 0);
        self::q(ClickRollupSql::insertSelect('202_dataengine', '2c.click_id = ' . $clickId));

        return $clickId;
    }

    /** Group Overview at the Redirect level, as the account reads it. */
    private static function report(): mixed
    {
        $env = [
            'P202_TEST_REPORT_USER' => (string) self::USER,
            'P202_TEST_FROM' => (string) (self::$now - 3600),
            'P202_TEST_TO' => (string) (self::$now + 3600),
        ] + getenv();
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/fixtures/account-scope-runner.php', self::LEVEL],
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
        self::assertNotSame(2, proc_close($proc), "the runner could not set up: $err");
        self::assertStringStartsWith('RETURNED ', $out, $out . $err);

        return json_decode(substr(trim($out), 9), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<array{int, string, string}> the rule's redirects as stored: id, URL, name */
    private static function storedRedirects(int $rule): array
    {
        $sql = "SELECT id, redirect_url, name FROM 202_rotator_rules_redirects WHERE rule_id = $rule ORDER BY id";
        $result = self::$db->query($sql);
        self::assertInstanceOf(\mysqli_result::class, $result, (string) self::$db->error);

        return array_map(
            static fn (array $r): array => [(int) $r['id'], (string) $r['redirect_url'], (string) $r['name']],
            $result->fetch_all(MYSQLI_ASSOC)
        );
    }

    public function testARedirectAPutKeepsIsTheOneItsClicksAndReportRowsName(): void
    {
        [$id, $rule] = self::splitRule();
        $ruleId = (int) $rule['id'];
        [$a, $b] = $rule['redirects'];
        self::clickOn($id, $ruleId, (int) $a['id']);
        $before = self::report();
        $listed = [['id' => (string) $a['id'], 'title' => 'A', 'clicks' => 1]];
        self::assertSame($listed, $before, 'the click is listed under the redirect that sent it');

        // A new URL for the redirect clicked, the other sent back as GET answered it.
        $after = self::rotators()->updateRule($id, $ruleId, [
            'redirects' => [['redirect_url' => 'https://a2.example/'] + $a, $b],
        ])['data']['rules'][0]['redirects'];
        self::assertSame($listed, self::report(), 'the click is still listed under its redirect, by the same id');
        $ids = array_map('intval', array_column($after, 'id'));
        self::assertSame([(int) $a['id'], (int) $b['id']], $ids, 'each redirect sent with its id keeps it');
        self::assertSame(['https://a2.example/', 'https://b.example/'], array_column($after, 'redirect_url'));
        self::assertSame(
            [[(int) $a['id'], 'https://a2.example/', 'A'], [(int) $b['id'], 'https://b.example/', 'B']],
            self::storedRedirects($ruleId)
        );
    }

    public function testARedirectLeftOutIsRemovedAndOneWithoutAnIdIsAdded(): void
    {
        [$id, $rule] = self::splitRule();
        $ruleId = (int) $rule['id'];
        [$a, $b] = $rule['redirects'];
        self::clickOn($id, $ruleId, (int) $a['id']);
        $before = self::report();

        self::rotators()->updateRule($id, $ruleId, [
            'redirects' => [$a, ['redirect_url' => 'https://c.example/', 'weight' => 50, 'name' => 'C']],
        ]);
        self::assertSame($before, self::report(), 'the click is still listed under its redirect, by the same id');
        $stored = self::storedRedirects($ruleId);
        self::assertSame([(int) $a['id'], 'https://a.example/', 'A'], $stored[0], 'the redirect kept is as it was');
        self::assertCount(2, $stored, 'B, left out, is gone');
        self::assertSame(['https://c.example/', 'C'], [$stored[1][1], $stored[1][2]]);
        self::assertGreaterThan((int) $b['id'], $stored[1][0], 'C is a new row');

        // A body read with GET and sent back writes nothing.
        $read = self::rotators()->listRules($id)['data'][0];
        self::rotators()->updateRule($id, $ruleId, $read);
        self::assertSame($stored, self::storedRedirects($ruleId));
    }

    /**
     * An id is this rule's or refused (CLAUDE.md #27): another rule's
     * redirect, or another account's, would be rewritten from this rule.
     */
    public function testAnotherRulesRedirectIdIsRefusedWithNothingWritten(): void
    {
        [$id, $rule] = self::splitRule();
        [, $siblingRule] = self::splitRule();
        [, $theirRule] = self::splitRule(self::OTHER);
        $ruleId = (int) $rule['id'];
        $held = [
            $ruleId => self::storedRedirects($ruleId),
            (int) $siblingRule['id'] => self::storedRedirects((int) $siblingRule['id']),
            (int) $theirRule['id'] => self::storedRedirects((int) $theirRule['id']),
        ];

        foreach (['another rule\'s' => $siblingRule, 'another account\'s' => $theirRule] as $whose => $other) {
            try {
                self::rotators()->updateRule($id, $ruleId, [
                    // This rule's rule_id, so the id is the one thing refused.
                    'redirects' => [
                        ['rule_id' => $rule['id'], 'redirect_url' => 'https://taken.example/'] + $other['redirects'][0],
                        $rule['redirects'][1],
                    ],
                ]);
                self::fail("$whose redirect id was written from this rule");
            } catch (ValidationException $e) {
                $errors = $e->getFieldErrors();
                self::assertSame(['redirects.0.id'], array_keys($errors), $whose . ': ' . json_encode($errors));
            }
        }
        foreach ($held as $heldRule => $rows) {
            self::assertSame($rows, self::storedRedirects($heldRule), 'nothing was written');
        }
    }
}
