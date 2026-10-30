<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\RotatorsController;
use Api\V3\Support\RemoteApiClient;
use Api\V3\Support\ServerStateStore;
use Api\V3\Support\SyncEngine;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;
use Prosper202\Rotator\RuleOrder;
use Tests\Support\RotatorControllerRemoteApiClient;

/**
 * A sync writes a redirector's rules on the target whole or not at all.
 *
 * It removed every rule of the target's redirector and then posted the
 * source's one at a time, so a rule the target refused stopped it with the
 * target holding only the rules before that one: on a re-sync, the
 * target's own rules were gone. Since 1.9.79 the target refuses a rule
 * with no criterion, which rules stored before then can be, so a sync that
 * had worked stripped the target's redirector. It writes the rules as one
 * set now (PUT /rotators/{id}/rules), which the target takes whole or
 * refuses with nothing written, and only an install without that route is
 * written to the old way.
 *
 * Source and target are two accounts on one scratch database, each served
 * by the real RotatorsController (RotatorControllerRemoteApiClient); only
 * the HTTP exchange is replaced. Skips unless a scratch database is
 * configured (P202_TEST_DB_HOST, P202_TEST_DB_PORT, P202_TEST_DB_USER,
 * P202_TEST_DB_PASS, P202_TEST_DB_NAME); the schema is installed into it.
 *
 * @group integration
 */
final class SyncRotatorRulesIntegrationTest extends TestCase
{
    private const SOURCE = 5531;
    private const TARGET = 5532;

    /** The target's refusal of the source rule with no criterion, naming it. */
    private const REFUSED = '422 from PUT rotators/%d/rules: Invalid rules.1.criteria (rules.1.criteria: '
        . 'the source rule "everyone": A rule needs at least one criterion';

    private static ?\mysqli $db = null;

    private string $stateDir = '';

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) '
                . '{ return $sql === null ? null : $dbOrSql->query($sql); }');
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
        foreach ([self::SOURCE, self::TARGET] as $u) {
            self::$db->query('DELETE rr FROM 202_rotator_rules_redirects rr'
                . ' JOIN 202_rotator_rules r ON r.id = rr.rule_id'
                . " JOIN 202_rotators ro ON ro.id = r.rotator_id WHERE ro.user_id = $u");
            self::$db->query('DELETE c FROM 202_rotator_rules_criteria c'
                . " JOIN 202_rotators ro ON ro.id = c.rotator_id WHERE ro.user_id = $u");
            self::$db->query('DELETE r FROM 202_rotator_rules r'
                . " JOIN 202_rotators ro ON ro.id = r.rotator_id WHERE ro.user_id = $u");
            self::$db->query("DELETE FROM 202_rotators WHERE user_id = $u");
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        self::cleanUp();
        $this->stateDir = sys_get_temp_dir() . '/p202-sync-rules-test-' . bin2hex(random_bytes(4));
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

    private static function rotators(int $user): RotatorsController
    {
        return new RotatorsController(self::$db, $user);
    }

    /** A redirector of $user's, with the public id a sync matches it by. */
    private static function rotator(int $user, int $publicId): int
    {
        $made = self::rotators($user)->create(['name' => 'synced', 'default_url' => 'https://default.example/']);
        $id = (int) $made['data']['id'];
        $sql = "UPDATE 202_rotators SET public_id = $publicId WHERE id = $id";
        self::assertTrue(self::$db->query($sql), (string) self::$db->error);

        return $id;
    }

    /**
     * A rule of a set: its name, one criterion ("type value") and one URL.
     *
     * @param array<string, mixed> $more
     * @return array<string, mixed>
     */
    private static function rule(string $name, string $criterion, string $url, array $more = []): array
    {
        [$type, $value] = explode(' ', $criterion, 2);

        return ['rule_name' => $name, 'criteria' => [['type' => $type, 'statement' => 'is', 'value' => $value]],
            'redirects' => [['redirect_url' => $url]]] + $more;
    }

    /** @param list<array<string, mixed>> $rules */
    private static function setRules(int $user, int $rotator, array $rules): void
    {
        self::rotators($user)->setRules($rotator, ['rules' => $rules], false, static function (): void {
        });
    }

    /** A rule as POST stored one before 1.9.79: no criterion, so every visitor. */
    private static function ruleWithNoCriterion(int $rotator, string $name, string $url): void
    {
        $sql = [
            "INSERT INTO 202_rotator_rules SET rotator_id = $rotator, rule_name = '$name',"
                . ' splittest = 0, status = 1, position = 99',
            'INSERT INTO 202_rotator_rules_redirects SET rule_id = LAST_INSERT_ID(),'
                . " redirect_url = '$url', weight = '100', name = ''",
        ];
        foreach ($sql as $statement) {
            self::assertTrue(self::$db->query($statement), (string) self::$db->error);
        }
    }

    /**
     * The redirector's rules as the redirects try them: name, status,
     * criteria and destinations, without the ids a write assigns.
     *
     * @return list<array<string, mixed>>
     */
    private static function rules(int $user, int $rotator): array
    {
        $out = [];
        foreach (self::rotators($user)->listRules($rotator)['data'] as $rule) {
            $out[] = [
                'rule_name' => $rule['rule_name'],
                'status' => (int) $rule['status'],
                'criteria' => array_map(
                    static fn (array $c): string => "{$c['type']} {$c['statement']} {$c['value']}",
                    $rule['criteria']
                ),
                'redirects' => array_map(
                    static fn (array $r): string => (string) $r['redirect_url'],
                    $rule['redirects']
                ),
            ];
        }

        return $out;
    }

    /**
     * A sync of the redirectors, with force_update and skip_errors.
     *
     * @return array{0: array<string, mixed>, 1: RotatorControllerRemoteApiClient} the result, the target
     */
    private function sync(bool $ruleSetRoute = true): array
    {
        $source = new RotatorControllerRemoteApiClient(self::$db, self::SOURCE);
        $target = new RotatorControllerRemoteApiClient(self::$db, self::TARGET, $ruleSetRoute);
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
        $out = $engine->execute($profile, $profile, 'rotators', ['force_update' => true, 'skip_errors' => true]);

        return [$out['results']['rotators'], $target];
    }

    /**
     * A source rule the target refuses (here one stored with no criterion)
     * leaves the target's redirector with the rules it had: the sync fails
     * naming the rule and why, and removes nothing.
     */
    public function testARuleTheTargetRefusesLeavesTheTargetsRulesAsTheyWere(): void
    {
        $source = self::rotator(self::SOURCE, 7300001);
        self::setRules(self::SOURCE, $source, [self::rule('france', 'country France(FR)', 'https://fr.example/')]);
        self::ruleWithNoCriterion($source, 'everyone', 'https://everyone.example/');
        $target = self::rotator(self::TARGET, 7300001);
        self::setRules(self::TARGET, $target, [
            self::rule('kept', 'country ALL', 'https://kept.example/'),
            self::rule('kept too', 'device bot', 'https://bot.example/'),
        ]);
        $before = self::rules(self::TARGET, $target);

        [$result] = $this->sync();

        self::assertSame($before, self::rules(self::TARGET, $target), "the target's rules as they were");
        self::assertSame(1, $result['failed'], json_encode($result) ?: '');
        self::assertCount(1, $result['errors']);
        self::assertStringContainsString(sprintf(self::REFUSED, $target), $result['errors'][0]);
    }

    /** A redirector the sync creates gets every rule of the source's, or none. */
    public function testARedirectorTheSyncCreatesGetsEveryRuleOrNone(): void
    {
        $source = self::rotator(self::SOURCE, 7300002);
        self::setRules(self::SOURCE, $source, [self::rule('france', 'country France(FR)', 'https://fr.example/')]);
        self::ruleWithNoCriterion($source, 'everyone', 'https://everyone.example/');

        [$result] = $this->sync();

        $made = self::rotators(self::TARGET)->list([])['data'];
        self::assertCount(1, $made);
        $target = (int) $made[0]['id'];
        self::assertSame([], self::rules(self::TARGET, $target), 'no rule, not the ones before the refused one');
        self::assertSame(1, $result['created'], json_encode($result) ?: '');
        self::assertSame(1, $result['failed']);
        self::assertStringContainsString(sprintf(self::REFUSED, $target), $result['errors'][0]);
    }

    /** The target's redirector takes the source's rules in place of its own, in the source's order. */
    public function testTheTargetTakesTheSourcesRulesInTheirOrder(): void
    {
        $source = self::rotator(self::SOURCE, 7300003);
        self::setRules(self::SOURCE, $source, [
            self::rule('bots', 'device bot', 'https://bot.example/'),
            self::rule('paused', 'country ALL', 'https://paused.example/', ['status' => 0]),
            self::rule('everyone', 'country ALL', 'https://everyone.example/'),
        ]);
        $target = self::rotator(self::TARGET, 7300003);
        self::setRules(self::TARGET, $target, [self::rule('replaced', 'country ALL', 'https://old.example/')]);

        [$result, $client] = $this->sync();

        self::assertSame([0, 1], [$result['failed'], $result['updated']], json_encode($result) ?: '');
        self::assertSame(self::rules(self::SOURCE, $source), self::rules(self::TARGET, $target));
        self::assertContains("PUT rotators/$target/rules", $client->requests);
    }

    /**
     * A rule stored with a NULL status, which the redirects read as inactive,
     * stays inactive on the target: the redirects there don't try it.
     *
     * @dataProvider routes
     */
    public function testARuleWithANullStatusSyncsInactive(bool $ruleSetRoute): void
    {
        $source = self::rotator(self::SOURCE, 7300009);
        self::setRules(self::SOURCE, $source, [
            self::rule('legacy', 'country ALL', 'https://legacy.example/'),
            self::rule('live', 'country ALL', 'https://live.example/'),
        ]);
        self::assertTrue(self::$db->query(
            "UPDATE 202_rotator_rules SET status = NULL WHERE rotator_id = $source AND rule_name = 'legacy'"
        ), (string) self::$db->error);
        $target = self::rotator(self::TARGET, 7300009);

        [$result] = $this->sync($ruleSetRoute);

        self::assertSame(0, $result['failed'], json_encode($result) ?: '');
        $active = RuleOrder::activeRules(self::$db, $target, static fn (string $sql): array
            => self::$db->query($sql)->fetch_all(MYSQLI_ASSOC));
        $names = [];
        foreach (self::rotators(self::TARGET)->listRules($target)['data'] as $rule) {
            $names[(int) $rule['id']] = $rule['rule_name'];
        }
        self::assertSame(['live'], array_map(static fn (array $row): string => $names[(int) $row['rule_id']], $active));
    }

    /** @return array<string, array{bool}> */
    public static function routes(): array
    {
        return ['as a set' => [true], 'one rule at a time' => [false]];
    }

    /**
     * A source rule with two destinations and splittest 0, which the API
     * stores and the redirects split by weight, reaches the target with its
     * weights. The rule check refused it as sending every visitor to one
     * destination, so the sync of its redirector failed.
     *
     * @dataProvider routes
     */
    public function testARuleSplitWithTheSwitchOffSyncsWithItsWeights(bool $ruleSetRoute): void
    {
        $source = self::rotator(self::SOURCE, 7300010);
        self::setRules(self::SOURCE, $source, [[
            'rule_name' => 'even', 'splittest' => 1,
            'criteria' => [['type' => 'country', 'statement' => 'is', 'value' => 'ALL']],
            'redirects' => [['redirect_url' => 'https://a.example/', 'weight' => 60], ['redirect_url' => 'https://b.example/', 'weight' => 40]],
        ]]);
        // As the API stores a rule sent with no splittest.
        self::assertTrue(self::$db->query("UPDATE 202_rotator_rules SET splittest = 0 WHERE rotator_id = $source"), (string) self::$db->error);
        $target = self::rotator(self::TARGET, 7300010);

        [$result] = $this->sync($ruleSetRoute);

        self::assertSame(0, $result['failed'], json_encode($result) ?: '');
        self::assertSame(self::rules(self::SOURCE, $source), self::rules(self::TARGET, $target));
        $synced = self::rotators(self::TARGET)->listRules($target)['data'][0];
        self::assertSame(0, (int) $synced['splittest']);
        self::assertSame(['60', '40'], array_map('strval', array_column($synced['redirects'], 'weight')));
    }

    /**
     * An install from before 1.9.79 has no PUT /rotators/{id}/rules: its
     * rules are written one at a time, as before, and it stores them.
     */
    public function testAnInstallWithoutTheRuleSetRouteIsWrittenOneRuleAtATime(): void
    {
        $source = self::rotator(self::SOURCE, 7300004);
        self::setRules(self::SOURCE, $source, [
            self::rule('bots', 'device bot', 'https://bot.example/'),
            self::rule('everyone', 'country ALL', 'https://everyone.example/'),
        ]);
        $target = self::rotator(self::TARGET, 7300004);
        self::setRules(self::TARGET, $target, [self::rule('replaced', 'country ALL', 'https://old.example/')]);

        [$result, $client] = $this->sync(false);

        self::assertSame([0, 1], [$result['failed'], $result['updated']], json_encode($result) ?: '');
        self::assertSame(self::rules(self::SOURCE, $source), self::rules(self::TARGET, $target));
        self::assertContains("POST rotators/$target/rules", $client->requests);
    }

    /** A source redirector with no rules leaves the target's with none. */
    public function testASourceWithNoRulesLeavesTheTargetWithNone(): void
    {
        self::rotator(self::SOURCE, 7300005);
        $target = self::rotator(self::TARGET, 7300005);
        self::setRules(self::TARGET, $target, [self::rule('removed', 'country ALL', 'https://old.example/')]);

        [$result] = $this->sync();

        self::assertSame([0, 1], [$result['failed'], $result['updated']], json_encode($result) ?: '');
        self::assertSame([], self::rules(self::TARGET, $target));
    }
}
