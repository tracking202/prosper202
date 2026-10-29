<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\RotatorsController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;

/**
 * Which rules a write takes, held to where the redirects send a rule's
 * visitors rather than to splittest. Both redirects (rtr.php, offrtr.php)
 * send every visitor a rule with one destination matches to it, and split a
 * rule with more between them with getSplitTestValue() (connect2.php), run
 * here from the real file; neither reads splittest, which is Setup ›
 * Redirectors' switch. The rule set and POST and PUT of one rule refused two
 * destinations with splittest 0, as "the rule sends every visitor it matches
 * to one destination", while the redirects split them by weight (measured
 * over HTTP): a GET body holding such a rule could not be sent back, nor the
 * rule renamed.
 *
 * A rule is taken exactly when its visitors go where its weights say: one
 * destination takes them all, and between more, each destination weighted
 * above 0 takes a share and none weighted 0 takes any. Every weight 0 is
 * refused, since the redirects then send every visitor to the first; and a
 * rule with more than one destination states each weight. splittest 0 and 1
 * get the same answer.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME);
 * it installs the schema there and writes user 5541's rows.
 *
 * @group integration
 */
final class RotatorRuleSplitIntegrationTest extends TestCase
{
    private const USER = 5541;

    private static ?\mysqli $db = null;

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
        $db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
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
        $u = self::USER;
        self::$db->query("DELETE rr FROM 202_rotator_rules_redirects rr JOIN 202_rotator_rules r ON r.id = rr.rule_id JOIN 202_rotators ro ON ro.id = r.rotator_id WHERE ro.user_id = $u");
        self::$db->query("DELETE c FROM 202_rotator_rules_criteria c JOIN 202_rotators ro ON ro.id = c.rotator_id WHERE ro.user_id = $u");
        self::$db->query("DELETE r FROM 202_rotator_rules r JOIN 202_rotators ro ON ro.id = r.rotator_id WHERE ro.user_id = $u");
        self::$db->query("DELETE FROM 202_rotators WHERE user_id = $u");
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        self::cleanUp();
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * getSplitTestValue() from connect2.php, which needs a database and
     * cannot be included here, as a closure: no global function is
     * declared, so nothing that loads connect2.php later redeclares one.
     *
     * @return \Closure(array<int, array<string, mixed>>): (int|string|null)
     */
    private static function chooser(): \Closure
    {
        $src = (string) file_get_contents(self::root() . '/202-config/connect2.php');
        $head = 'function getSplitTestValue(array $values)';
        self::assertSame(1, preg_match('/^' . preg_quote($head, '/') . '\n\{\n.*?^\}\n/ms', $src, $m), 'getSplitTestValue() not found in connect2.php');
        $chooser = eval('return static function (array $values)' . substr($m[0], strlen($head)) . ';');
        self::assertInstanceOf(\Closure::class, $chooser);

        return $chooser;
    }

    /**
     * The places of the destinations the redirects send this rule's visitors
     * to, as rtr.php and offrtr.php choose: the one destination, or a draw
     * by weight between more.
     *
     * @param list<int|string|null> $weights
     * @return list<int>
     */
    private static function reached(array $weights): array
    {
        if (count($weights) === 1) {
            return [0];
        }
        $chooser = self::chooser();
        $rows = array_map(static fn ($w): array => ['weight' => $w], $weights);
        mt_srand(202);
        $seen = [];
        for ($i = 0; $i < 2000; $i++) {
            $seen[(int) $chooser($rows)] = true;
        }
        $places = array_keys($seen);
        sort($places);

        return $places;
    }

    /**
     * Where the weights say the visitors go: all of them to one destination,
     * and between more, to each weighted above 0.
     *
     * @param list<int|string|null> $weights
     * @return list<int>
     */
    private static function said(array $weights): array
    {
        if (count($weights) === 1) {
            return [0];
        }

        return array_keys(array_filter($weights, static fn ($w): bool => (int) $w > 0));
    }

    /** @return iterable<string, array{list<int|string|null>}> each destination's weight, null for none sent */
    public static function destinations(): iterable
    {
        yield 'one destination' => [[100]];
        yield 'one destination weighted 0' => [[0]];
        yield 'one destination with no weight' => [[null]];
        yield 'two at 50/50' => [[50, 50]];
        yield 'two at 70/30, one as text' => [[70, '30']];
        yield 'two at 100/0' => [[100, 0]];
        yield 'three at 0/60/0' => [[0, 60, 0]];
        yield 'two at 0/0' => [[0, '0']];
        yield 'three at 0/0/0' => [[0, 0, 0]];
        yield 'two, the second with no weight' => [[50, null]];
        yield 'two with no weight' => [[null, null]];
    }

    /**
     * Each write that takes a rule (the set, POST and PUT of one rule, which
     * check it with the same planRule()) answers a rule the same way, with
     * splittest 0 or 1.
     *
     * @dataProvider destinations
     * @param list<int|string|null> $weights
     */
    public function testARuleIsTakenExactlyWhenTheRedirectsSendItsVisitorsWhereItsWeightsSay(array $weights): void
    {
        $missing = array_keys(array_filter($weights, static fn ($w): bool => $w === null));
        if (count($weights) > 1 && $missing !== []) {
            // How the visitors are split is stated, not left to a default.
            $want = array_map(static fn (int $j): string => "redirects.$j.weight", $missing);
        } else {
            $reached = self::reached($weights);
            $want = $reached === self::said($weights) ? 'taken' : ['redirects'];
            if (count($weights) > 1) {
                self::assertNotSame([], $reached, 'the chooser sends every visitor somewhere');
            }
        }

        $controller = new RotatorsController(self::$db, self::USER);
        $writes = [
            'PUT /rotators/{id}/rules' => static fn (int $rotator, array $rule): array => $controller->setRules(
                $rotator,
                ['rules' => [$rule]],
                false,
                static function (): void {
                }
            ),
            'POST /rotators/{id}/rules' => static fn (int $rotator, array $rule): array => $controller->createRule($rotator, $rule),
            'PUT /rotators/{id}/rules/{ruleId}' => static function (int $rotator, array $rule) use ($controller): array {
                $controller->createRule($rotator, ['rule_name' => 'was', 'criteria' => $rule['criteria'], 'redirects' => [['redirect_url' => 'https://was.example/']]]);
                $id = (int) $controller->listRules($rotator)['data'][0]['id'];

                return $controller->updateRule($rotator, $id, $rule);
            },
        ];
        $answers = [];
        foreach ($writes as $write => $send) {
            foreach ([0, 1] as $splittest) {
                $redirects = [];
                foreach ($weights as $j => $weight) {
                    $redirects[] = ['redirect_url' => "https://d$j.example/", 'name' => "d$j"] + ($weight === null ? [] : ['weight' => $weight]);
                }
                $rule = [
                    'rule_name' => 'r', 'splittest' => $splittest,
                    'criteria' => [['type' => 'country', 'value' => 'ALL']],
                    'redirects' => $redirects,
                ];
                $rotator = (int) $controller->create(['name' => 'r', 'default_url' => 'https://default.example/'])['data']['id'];
                try {
                    $stored = $send($rotator, $rule)['data']['rules'][0];
                } catch (ValidationException $e) {
                    // The set names a rule's fields by its place in the set.
                    $errors = array_map(static fn ($k): string => (string) preg_replace('/^rules\.0\./', '', (string) $k), array_keys($e->getFieldErrors()));
                    sort($errors);
                    $answers["$write, splittest $splittest"] = $errors;
                    continue;
                }
                $answers["$write, splittest $splittest"] = 'taken';
                self::assertSame($splittest, (int) $stored['splittest'], "$write: splittest is stored as sent");
                // What the redirects read: the rule's redirects, in id order.
                $result = self::$db->query('SELECT weight FROM 202_rotator_rules_redirects WHERE rule_id = ' . (int) $stored['id'] . ' ORDER BY id');
                self::assertInstanceOf(\mysqli_result::class, $result, (string) self::$db->error);
                $read = array_column($result->fetch_all(MYSQLI_ASSOC), 'weight');
                self::assertSame(array_map(static fn ($w): string => (string) ($w ?? 100), $weights), $read, "$write: each weight is stored as sent");
                self::assertSame(self::said($weights), self::reached($read), "$write: and the redirects send the visitors where the weights say");
            }
        }

        // splittest, which the redirects do not read, changes nothing, and
        // no write takes what another refuses.
        self::assertSame(array_fill_keys(array_keys($answers), $want), $answers);
    }

    /**
     * The premise above, read from the click path: the rule a visitor
     * matches is read with no splittest, and each redirect takes its one
     * usable destination, or draws between more with getSplitTestValue().
     * A click script that came to read splittest would make the check above
     * wrong; the failure says where to look.
     */
    public function testTheRedirectsChooseADestinationWithoutReadingSplittest(): void
    {
        $choices = [
            'tracking202/redirect/rtr.php' => '/if \(count\(\$redirects\) > 1\) \{\s*\$redirect_array = \$redirects\[getSplitTestValue\(\$redirects\)\];\s*\} elseif \(!empty\(\$redirects\)\) \{\s*\$redirect_array = \$redirects\[0\];/',
            'tracking202/redirect/offrtr.php' => '/if \(count\(\$rule_redirect_rows\) > 1\) \{\s*\$rule_redirect_row = \$rule_redirect_rows\[getSplitTestValue\(\$rule_redirect_rows\)\];\s*\} else \{\s*\$rule_redirect_row = \$rule_redirect_rows\[0\] \?\? null;/',
            '202-config/connect2.php' => '/^function getSplitTestValue\(array \$values\)$/m',
            '202-config/Rotator/RuleOrder.php' => '/function activeRules\(/',
        ];
        foreach ($choices as $file => $choice) {
            $src = (string) file_get_contents(self::root() . '/' . $file);
            self::assertSame(1, preg_match($choice, $src), "$file no longer chooses a rule's destination as this test reads it: read it again, and RotatorsController::planRule()'s weight check with it");
            self::assertSame(0, preg_match('/\bsplittest\b/i', $src), "$file reads splittest: RotatorsController::planRule() takes a rule whatever its splittest, because the redirects did not read it");
        }
    }
}
