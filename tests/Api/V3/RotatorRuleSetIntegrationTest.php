<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\AuthException;
use Api\V3\Controllers\RotatorsController;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;
use Prosper202\Rotator\RuleOrder;

/**
 * PUT /rotators/{id}/rules: a redirector's whole rule set, replaced in one
 * transaction (RotatorsController::setRules()), against a real server.
 *
 * What it holds: the set reads back as sent, in the order sent, and a GET
 * body sent back changes nothing; a rule or an entry sent with its id keeps
 * it, one left out is removed; a campaign of another account, another
 * redirector's rule, or anything Setup › Redirectors would refuse is refused
 * with nothing written; a dry run writes nothing; a statement that fails
 * part way through the set leaves the set as it was; and the redirects read
 * the rules in the set's order, and in id order on a database the 1.9.79
 * upgrade has not reached.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME);
 * it installs the schema there and writes users 5511 and 5512's rows.
 *
 * @group integration
 */
final class RotatorRuleSetIntegrationTest extends TestCase
{
    private const USER = 5511;
    private const OTHER = 5512;

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
        self::$db->query('DROP TRIGGER IF EXISTS p202_rule_set_test_boom');
        foreach ([self::USER, self::OTHER] as $u) {
            self::$db->query("DELETE rr FROM 202_rotator_rules_redirects rr JOIN 202_rotator_rules r ON r.id = rr.rule_id JOIN 202_rotators ro ON ro.id = r.rotator_id WHERE ro.user_id = $u");
            self::$db->query("DELETE c FROM 202_rotator_rules_criteria c JOIN 202_rotators ro ON ro.id = c.rotator_id WHERE ro.user_id = $u");
            self::$db->query("DELETE r FROM 202_rotator_rules r JOIN 202_rotators ro ON ro.id = r.rotator_id WHERE ro.user_id = $u");
            foreach (['202_rotators', '202_aff_campaigns', '202_landing_pages'] as $table) {
                self::$db->query("DELETE FROM $table WHERE user_id = $u");
            }
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        self::cleanUp();
    }

    private static function campaign(int $user): int
    {
        self::assertTrue(self::$db->query("INSERT INTO 202_aff_campaigns SET user_id = $user, aff_network_id = 1, aff_campaign_name = 'c', aff_campaign_url = 'https://offer.example',"
            . ' aff_campaign_payout = 1, aff_campaign_foreign_payout = 0, aff_campaign_deleted = 0, aff_campaign_time = 0'), (string) self::$db->error);

        return (int) self::$db->insert_id;
    }

    private function rotators(int $user = self::USER): RotatorsController
    {
        return new RotatorsController(self::$db, $user);
    }

    private function rotator(int $user = self::USER): int
    {
        return (int) $this->rotators($user)->create(['name' => 'r', 'default_url' => 'https://default.example/'])['data']['id'];
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @return array<string, mixed>
     */
    private function set(int $rotator, array $rules, bool $dryRun = false, ?callable $mayRemove = null): array
    {
        return $this->rotators()->setRules($rotator, ['rules' => $rules], $dryRun, $mayRemove ?? static function (): void {
        })['data'];
    }

    /** Every row of the redirector's rules, criteria and redirects, as stored. */
    private static function stored(int $rotator): string
    {
        $rows = [];
        foreach ([
            "SELECT * FROM 202_rotator_rules WHERE rotator_id = $rotator ORDER BY id",
            "SELECT * FROM 202_rotator_rules_criteria WHERE rotator_id = $rotator ORDER BY id",
            "SELECT rr.* FROM 202_rotator_rules_redirects rr JOIN 202_rotator_rules r ON r.id = rr.rule_id WHERE r.rotator_id = $rotator ORDER BY rr.id",
        ] as $sql) {
            $rows[] = self::$db->query($sql)->fetch_all(MYSQLI_ASSOC);
        }

        return (string) json_encode($rows);
    }

    /** @return list<array<string, mixed>> two rules: an IP rule split 70/30, then everyone else to a campaign */
    private static function twoRules(int $campaign): array
    {
        return [
            [
                'rule_name' => 'office', 'splittest' => 1,
                'criteria' => [['type' => 'ip', 'statement' => 'is', 'value' => '203.0.113.7']],
                'redirects' => [
                    ['redirect_url' => 'https://a.example/', 'weight' => 70, 'name' => 'A'],
                    ['redirect_url' => 'https://b.example/', 'weight' => '30', 'name' => 'B'],
                ],
            ],
            [
                'rule_name' => 'everyone',
                'criteria' => [['type' => 'country', 'value' => 'ALL']],
                'redirects' => [['redirect_campaign' => $campaign]],
            ],
        ];
    }

    public function testASetIsStoredAsSentAndAGetBodySentBackChangesNothing(): void
    {
        $campaign = self::campaign(self::USER);
        $id = $this->rotator();
        $answer = $this->set($id, self::twoRules($campaign));
        self::assertFalse($answer['dry_run']);
        self::assertSame(['added' => 2, 'updated' => 0, 'unchanged' => 0, 'removed' => 0], array_slice($answer['changes'], 0, 4));

        $read = $this->rotators()->listRules($id)['data'];
        self::assertSame(['office', 'everyone'], array_column($read, 'rule_name'));
        self::assertSame([1, 0], array_column($read, 'splittest'));
        self::assertSame([1, 1], array_column($read, 'status'));
        self::assertSame(
            [['https://a.example/', null, null, '70', 'A'], ['https://b.example/', null, null, '30', 'B']],
            array_map(static fn (array $r): array => [$r['redirect_url'], $r['redirect_campaign'], $r['redirect_lp'], $r['weight'], $r['name']], $read[0]['redirects']),
            'a URL redirect stores NULL in the parts it does not use, which the redirects read as "none"'
        );
        self::assertSame([null, $campaign, null], [$read[1]['redirects'][0]['redirect_url'], $read[1]['redirects'][0]['redirect_campaign'], $read[1]['redirects'][0]['redirect_lp']]);
        self::assertSame($read, $answer['rules'], 'the answer is the set as GET reads it');

        $before = self::stored($id);
        $again = $this->set($id, $read);
        self::assertSame(['added' => 0, 'updated' => 0, 'unchanged' => 2, 'removed' => 0], array_slice($again['changes'], 0, 4));
        self::assertSame(['keep', 'keep'], array_column($again['changes']['rules'], 'action'));
        self::assertSame($before, self::stored($id), 'a GET body sent back writes the rows as they were, ids included');
        self::assertSame($read, $this->rotators()->listRules($id)['data']);
    }

    public function testTheSetIsTriedInTheOrderSentAndKeepsItsIds(): void
    {
        $id = $this->rotator();
        $this->set($id, self::twoRules(self::campaign(self::USER)));
        [$office, $everyone] = $this->rotators()->listRules($id)['data'];

        // A new rule first, then the two in the other order.
        $answer = $this->set($id, [
            ['rule_name' => 'new', 'criteria' => [['type' => 'device', 'value' => 'mobile']], 'redirects' => [['redirect_url' => 'https://m.example/']]],
            $everyone,
            $office,
        ]);
        self::assertSame(
            [['add', null, 'new', 1, null], ['keep', $everyone['id'], 'everyone', 2, null], ['update', $office['id'], 'office', 3, ['position']]],
            array_map(static fn (array $c): array => [$c['action'], $c['id'], $c['rule_name'], $c['position'], $c['changed'] ?? null], $answer['changes']['rules'])
        );
        $read = $this->rotators()->listRules($id)['data'];
        self::assertSame(['new', 'everyone', 'office'], array_column($read, 'rule_name'));
        self::assertSame([$everyone['id'], $office['id']], [$read[1]['id'], $read[2]['id']], 'a kept rule keeps its id, so its clicks stay its own');
        self::assertSame($office['redirects'], $read[2]['redirects'], 'and so do its destinations');

        // The redirects try them in that order, the new rule's id being the highest.
        self::assertSame(
            array_map('intval', array_column($read, 'id')),
            array_map(static fn (array $r): int => (int) $r['rule_id'], RuleOrder::activeRules(self::$db, $id, self::reader()))
        );
        self::assertGreaterThan($office['id'], $read[0]['id']);
    }

    /** @return callable(string): list<array<string, mixed>> */
    private static function reader(): callable
    {
        return static function (string $sql): array {
            $result = self::$db->query($sql);
            if (!$result instanceof \mysqli_result) {
                throw new \RuntimeException(self::$db->error . ' [errno ' . self::$db->errno . ']');
            }

            return $result->fetch_all(MYSQLI_ASSOC);
        };
    }

    public function testARuleOrEntryLeftOutIsRemovedAndAnEntrySentWithItsIdKeepsIt(): void
    {
        $id = $this->rotator();
        $this->set($id, self::twoRules(self::campaign(self::USER)));
        [$office, $everyone] = $this->rotators()->listRules($id)['data'];

        $office['redirects'] = [
            ['weight' => 50] + $office['redirects'][1],
            ['redirect_url' => 'https://c.example/', 'weight' => 50, 'name' => 'C'],
        ];
        $removed = [];
        $answer = $this->set($id, [$office], false, static function () use (&$removed): void {
            $removed[] = true;
        });
        self::assertSame([true], $removed, 'removing a rule asks for remove_rotator_rule');
        self::assertSame(
            [['update', $office['id'], ['redirects']], ['remove', $everyone['id'], null]],
            array_map(static fn (array $c): array => [$c['action'], $c['id'], $c['changed'] ?? null], $answer['changes']['rules'])
        );

        $read = $this->rotators()->listRules($id)['data'];
        self::assertSame([$office['id']], array_column($read, 'id'));
        $redirects = $read[0]['redirects'];
        self::assertSame($office['redirects'][0]['id'], $redirects[0]['id'], 'the destination sent with its id kept it');
        self::assertSame(['https://b.example/', '50'], [$redirects[0]['redirect_url'], $redirects[0]['weight']]);
        self::assertSame(['https://c.example/', 'C'], [$redirects[1]['redirect_url'], $redirects[1]['name']]);
        self::assertCount(2, $redirects, 'the destination left out is gone');
        $everyoneId = (int) $everyone['id'];
        self::assertSame('0', (string) self::$db->query("SELECT COUNT(*) FROM 202_rotator_rules_criteria WHERE rule_id = $everyoneId")->fetch_row()[0]);
        self::assertSame('0', (string) self::$db->query("SELECT COUNT(*) FROM 202_rotator_rules_redirects WHERE rule_id = $everyoneId")->fetch_row()[0]);
    }

    public function testRemovingARuleWithoutThePermissionWritesNothing(): void
    {
        $id = $this->rotator();
        $this->set($id, self::twoRules(self::campaign(self::USER)));
        $read = $this->rotators()->listRules($id)['data'];
        $before = self::stored($id);
        $refuse = static function (): void {
            throw new AuthException("This account's role does not have the 'remove_rotator_rule' permission.", 403);
        };

        foreach ([false, true] as $dryRun) {
            try {
                $this->set($id, [['rule_name' => 'renamed'] + $read[0]], $dryRun, $refuse);
                self::fail('a set that removes a rule was written without the permission');
            } catch (AuthException $e) {
                self::assertSame(403, $e->getCode());
            }
        }
        self::assertSame($before, self::stored($id));

        // A set that removes nothing does not ask.
        $this->set($id, [['rule_name' => 'renamed'] + $read[0], $read[1]], false, $refuse);
        self::assertSame(['renamed', 'everyone'], array_column($this->rotators()->listRules($id)['data'], 'rule_name'));
    }

    /** @return iterable<string, array{0: callable(list<array<string, mixed>>, int, int): list<array<string, mixed>>, 1: string}> */
    public static function refusedSets(): iterable
    {
        $first = static fn (callable $change): callable => static function (array $rules, int $mine, int $theirs) use ($change): array {
            $rules[0] = $change($rules[0], $mine, $theirs);
            return $rules;
        };
        yield 'another account\'s campaign' => [
            static function (array $rules, int $mine, int $theirs): array {
                $rules[1]['redirects'][0]['redirect_campaign'] = $theirs;
                return $rules;
            },
            'rules.1.redirects.0.redirect_campaign',
        ];
        yield 'a rule with no criterion' => [$first(static fn (array $r): array => ['criteria' => []] + $r), 'rules.0.criteria'];
        yield 'a rule with no destination' => [$first(static function (array $r): array {
            unset($r['redirects']);
            return $r;
        }), 'rules.0.redirects'];
        yield 'two destinations on a rule that is not a split test' => [$first(static fn (array $r): array => ['splittest' => 0] + $r), 'rules.0.redirects'];
        yield 'a split test destination with no weight' => [$first(static function (array $r): array {
            unset($r['redirects'][1]['weight']);
            return $r;
        }), 'rules.0.redirects.1.weight'];
        yield 'a split test whose every weight is 0' => [$first(static function (array $r): array {
            $r['redirects'][0]['weight'] = 0;
            $r['redirects'][1]['weight'] = '0';
            return $r;
        }), 'rules.0.redirects'];
        yield 'a typo inside a criterion' => [$first(static function (array $r): array {
            $r['criteria'][0]['valeu'] = 'x';
            return $r;
        }), 'rules.0.criteria.0.valeu'];
        yield 'a typo in a rule' => [$first(static fn (array $r): array => ['rule_nmae' => 'x'] + $r), 'rules.0.rule_nmae'];
        yield 'a criterion id on a new rule' => [$first(static function (array $r): array {
            $r['criteria'][0]['id'] = 1;
            return $r;
        }), 'rules.0.criteria.0.id'];
        yield 'another redirector\'s id' => [$first(static fn (array $r): array => ['rotator_id' => 999999] + $r), 'rules.0.rotator_id'];
        yield 'a device that is not lower case, which never matches' => [$first(static function (array $r): array {
            $r['criteria'][0] = ['type' => 'device', 'value' => 'mobile, Tablet'];
            return $r;
        }), 'rules.0.criteria.0.value'];
        yield 'a country as a bare code' => [$first(static function (array $r): array {
            $r['criteria'][0] = ['type' => 'country', 'value' => 'United States(US), CA'];
            return $r;
        }), 'rules.0.criteria.0.value'];
        yield 'a value of commas alone' => [$first(static function (array $r): array {
            $r['criteria'][0]['value'] = ' , ';
            return $r;
        }), 'rules.0.criteria.0.value'];
    }

    /** @dataProvider refusedSets */
    public function testASetThePageWouldNotSaveIsRefusedWithNothingWritten(callable $change, string $field): void
    {
        $mine = self::campaign(self::USER);
        $theirs = self::campaign(self::OTHER);
        $id = $this->rotator();
        $this->set($id, [['rule_name' => 'keep', 'criteria' => [['type' => 'country', 'value' => 'ALL']], 'redirects' => [['redirect_url' => 'https://keep.example/']]]]);
        $before = self::stored($id);

        foreach ([true, false] as $dryRun) {
            try {
                $this->set($id, $change(self::twoRules($mine), $mine, $theirs), $dryRun);
                self::fail('the set was accepted');
            } catch (ValidationException $e) {
                self::assertArrayHasKey($field, $e->getFieldErrors(), json_encode($e->getFieldErrors()) ?: '');
            }
        }
        self::assertSame($before, self::stored($id), 'the set as it was');
    }

    public function testAnIdTheRedirectorDoesNotHoldIsRefused(): void
    {
        $campaign = self::campaign(self::USER);
        $id = $this->rotator();
        $other = $this->rotator();
        $this->set($id, self::twoRules($campaign));
        $this->set($other, self::twoRules($campaign));
        $mine = $this->rotators()->listRules($id)['data'];
        $theirs = $this->rotators()->listRules($other)['data'];
        $before = self::stored($id);

        $cases = [
            'another redirector\'s rule' => [[['id' => $theirs[0]['id'], 'rotator_id' => $id] + $theirs[0]], 'rules.0.id'],
            'a rule listed twice' => [[$mine[0], $mine[0]], 'rules.1.id'],
            'another rule\'s destination' => [[['redirects' => $theirs[0]['redirects']] + $mine[0], $mine[1]], 'rules.0.redirects.0.id'],
            'a destination listed twice' => [[['redirects' => [$mine[0]['redirects'][0], $mine[0]['redirects'][0]]] + $mine[0], $mine[1]], 'rules.0.redirects.1.id'],
        ];
        foreach ($cases as $case => [$rules, $field]) {
            try {
                $this->set($id, $rules);
                self::fail("$case was accepted");
            } catch (ValidationException $e) {
                self::assertArrayHasKey($field, $e->getFieldErrors(), $case . ': ' . json_encode($e->getFieldErrors()));
            }
        }
        self::assertSame($before, self::stored($id));

        try {
            $this->rotators(self::OTHER)->setRules($id, ['rules' => $mine], false, static function (): void {
            });
            self::fail('another account set this redirector\'s rules');
        } catch (NotFoundException) {
        }
        self::assertSame($before, self::stored($id));
    }

    public function testADryRunWritesNothingAndAnswersWhatTheWriteWouldStore(): void
    {
        $campaign = self::campaign(self::USER);
        $id = $this->rotator();
        $this->set($id, self::twoRules($campaign));
        $read = $this->rotators()->listRules($id)['data'];
        $before = self::stored($id);
        $next = [$read[1], ['rule_name' => 'office 2'] + $read[0]];

        $preview = $this->set($id, $next, true);
        self::assertTrue($preview['dry_run']);
        self::assertSame($before, self::stored($id), 'a dry run writes nothing');
        self::assertSame(
            [['update', ['position']], ['update', ['rule_name', 'position']]],
            array_map(static fn (array $c): array => [$c['action'], $c['changed']], $preview['changes']['rules'])
        );

        $written = $this->set($id, $next);
        self::assertSame($preview['changes'], $written['changes']);
        self::assertSame($preview['rules'], $written['rules'], 'the preview is the set the write stored');
    }

    /** A statement that fails part way through leaves the set as it was: one transaction. */
    public function testAFailurePartWayThroughLeavesTheSetWhole(): void
    {
        $campaign = self::campaign(self::USER);
        $id = $this->rotator();
        $this->set($id, self::twoRules($campaign));
        $read = $this->rotators()->listRules($id)['data'];
        $before = self::stored($id);

        // The second rule's insert fails, after the first rule was rewritten
        // and the removed one deleted.
        self::assertTrue(self::$db->query(
            "CREATE TRIGGER p202_rule_set_test_boom BEFORE INSERT ON 202_rotator_rules FOR EACH ROW
             BEGIN IF NEW.rule_name = 'boom' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'planted'; END IF; END"
        ), (string) self::$db->error);
        try {
            $this->set($id, [
                ['rule_name' => 'renamed'] + $read[1],
                ['rule_name' => 'boom', 'criteria' => [['type' => 'country', 'value' => 'ALL']], 'redirects' => [['redirect_url' => 'https://x.example/']]],
            ]);
            self::fail('the planted failure did not fail the set');
        } catch (\Throwable $e) {
            self::assertStringNotContainsString('was accepted', $e->getMessage());
            self::assertNotInstanceOf(ValidationException::class, $e);
        } finally {
            self::$db->query('DROP TRIGGER IF EXISTS p202_rule_set_test_boom');
        }
        self::assertSame($before, self::stored($id), 'nothing of the set was kept');
    }

    public function testValuesAreStoredAsThePageStoresThem(): void
    {
        $id = $this->rotator();
        $this->set($id, [[
            'rule_name' => 'values',
            'criteria' => [
                ['type' => 'country', 'value' => ' United States(US), Canada(CA),, '],
                ['type' => 'city', 'statement' => 'is_not', 'value' => 'all'],
            ],
            'redirects' => [['redirect_url' => 'https://v.example/']],
        ]]);
        self::assertSame(
            ['United States(US),Canada(CA)', 'ALL'],
            array_column($this->rotators()->listRules($id)['data'][0]['criteria'], 'value'),
            'split on commas and trimmed, as the page\'s editor sends them, and ALL as both redirects read it'
        );
    }

    public function testAnEmptySetIsRefused(): void
    {
        $id = $this->rotator();
        foreach ([[], null] as $rules) {
            try {
                $this->rotators()->setRules($id, ['rules' => $rules], false, static function (): void {
                });
                self::fail('an empty set was accepted');
            } catch (ValidationException $e) {
                self::assertSame(['rules'], array_keys($e->getFieldErrors()));
            }
        }
        try {
            $this->rotators()->setRules($id, ['rules' => [], 'default_url' => 'https://x.example/'], false, static function (): void {
            });
            self::fail('a key a rule set does not take was accepted');
        } catch (ValidationException $e) {
            self::assertSame(['default_url'], array_keys($e->getFieldErrors()));
        }
    }

    /** A rule added on its own goes after the set; a kept rule omitted splittest and status keeps them. */
    public function testARuleAddedOnItsOwnGoesLast(): void
    {
        $id = $this->rotator();
        $this->set($id, self::twoRules(self::campaign(self::USER)));
        $read = $this->rotators()->listRules($id)['data'];
        $this->rotators()->updateRule($id, (int) $read[1]['id'], ['status' => 0]);
        $this->rotators()->createRule($id, ['rule_name' => 'last', 'redirects' => [['redirect_url' => 'https://l.example/']]]);
        $this->set($id, [
            ['rule_name' => 'first', 'criteria' => [['type' => 'country', 'value' => 'ALL']], 'redirects' => [['redirect_url' => 'https://f.example/']]],
            ...array_map(static function (array $rule): array {
                unset($rule['splittest'], $rule['status']);
                if ($rule['criteria'] === []) {
                    $rule['criteria'] = [['type' => 'country', 'value' => 'ALL']];
                }
                return $rule;
            }, $this->rotators()->listRules($id)['data']),
        ]);
        $after = $this->rotators()->listRules($id)['data'];
        self::assertSame(['first', 'office', 'everyone', 'last'], array_column($after, 'rule_name'));
        self::assertSame([0, 1, 0, 0], array_column($after, 'splittest'));
        self::assertSame([1, 1, 0, 1], array_column($after, 'status'), 'a kept rule sent without its status keeps it');
    }

    /**
     * Code served before the 1.9.79 upgrade has added position reads the
     * rules in id order rather than failing the click.
     */
    public function testTheRedirectsReadTheRulesBeforeTheUpgradeAddsTheirOrder(): void
    {
        $id = $this->rotator();
        $this->set($id, self::twoRules(self::campaign(self::USER)));
        $ids = array_map('intval', array_column($this->rotators()->listRules($id)['data'], 'id'));
        self::$db->query("UPDATE 202_rotator_rules SET position = 9 - position WHERE rotator_id = $id");
        $ordered = array_map(static fn (array $r): int => (int) $r['rule_id'], RuleOrder::activeRules(self::$db, $id, self::reader()));
        self::assertSame(array_reverse($ids), $ordered, 'position decides');

        self::assertTrue(self::$db->query('ALTER TABLE 202_rotator_rules DROP COLUMN position'), (string) self::$db->error);
        try {
            $fallback = array_map(static fn (array $r): int => (int) $r['rule_id'], RuleOrder::activeRules(self::$db, $id, self::reader()));
        } finally {
            self::$db->query("ALTER TABLE 202_rotator_rules ADD COLUMN `position` int(11) unsigned NOT NULL DEFAULT '0' AFTER `status`");
        }
        sort($ids);
        self::assertSame($ids, $fallback);

        // Any other failure is the click's to report.
        $this->expectException(\RuntimeException::class);
        RuleOrder::activeRules(self::$db, $id, static function (string $sql): array {
            throw new \RuntimeException("Table '202_rotator_rules' doesn't exist [errno 1146]");
        });
    }
}
