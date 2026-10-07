<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\RotatorsController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;

/**
 * A redirector's default and its rule redirects, written through the API as
 * Setup > Redirectors writes them: one destination each, the other parts
 * NULL, and only the caller's own live campaigns and landing pages.
 *
 * The API stored 0 in the unused parts. rtr.php tells a redirect's kind by
 * `redirect_campaign != null`, and '0' != null is true, so every URL or
 * landing-page redirect made through the API sent the visitors its rule
 * matched to an empty Location. Setting a default URL on a redirector whose
 * default was a campaign left the campaign in place, and the redirect, which
 * prefers it, never used the URL.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME);
 * it installs the schema there and writes users 5501 and 5502's rows.
 *
 * @group integration
 */
final class RotatorDestinationsIntegrationTest extends TestCase
{
    private const USER = 5501;
    private const OTHER = 5502;

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

    private static function campaign(int $user, int $deleted = 0): int
    {
        self::assertTrue(self::$db->query("INSERT INTO 202_aff_campaigns SET user_id = $user, aff_network_id = 1, aff_campaign_name = 'c', aff_campaign_url = 'https://offer.example',"
            . " aff_campaign_payout = 1, aff_campaign_foreign_payout = 0, aff_campaign_deleted = $deleted, aff_campaign_time = 0"), (string) self::$db->error);

        return (int) self::$db->insert_id;
    }

    private static function landingPage(int $user): int
    {
        self::assertTrue(self::$db->query("INSERT INTO 202_landing_pages SET user_id = $user, aff_campaign_id = 0, landing_page_nickname = 'lp',"
            . " landing_page_url = 'https://lp.example', landing_page_type = 0, landing_page_time = 0"), (string) self::$db->error);

        return (int) self::$db->insert_id;
    }

    private function rotators(): RotatorsController
    {
        return new RotatorsController(self::$db, self::USER);
    }

    /** @return list<?string> default_url, default_campaign, default_lp, auto_monetizer as stored */
    private static function storedDefault(int $id): array
    {
        return self::$db->query("SELECT default_url, default_campaign, default_lp, auto_monetizer FROM 202_rotators WHERE id = $id")->fetch_row();
    }

    public function testSettingOneDefaultClearsTheOthers(): void
    {
        $campaign = self::campaign(self::USER);
        $lp = self::landingPage(self::USER);
        $id = (int) $this->rotators()->create(['name' => 'r', 'default_campaign' => $campaign])['data']['id'];
        self::assertSame([null, (string) $campaign, null, null], self::storedDefault($id));

        self::$db->query("UPDATE 202_rotators SET auto_monetizer = 'true' WHERE id = $id");
        $this->rotators()->update($id, ['default_url' => 'https://fallback.example/']);
        self::assertSame(['https://fallback.example/', null, null, null], self::storedDefault($id), 'the URL replaces the campaign, and the monetizer');

        $this->rotators()->update($id, ['default_lp' => (string) $lp]);
        self::assertSame([null, null, (string) $lp, null], self::storedDefault($id));

        $this->rotators()->update($id, ['name' => 'renamed']);
        self::assertSame([null, null, (string) $lp, null], self::storedDefault($id), 'a write that names no default leaves it alone');
    }

    public function testARedirectStoresNullInThePartsItDoesNotUse(): void
    {
        $id = (int) $this->rotators()->create(['name' => 'r', 'default_url' => 'https://d.example/'])['data']['id'];
        $this->rotators()->createRule($id, [
            'rule_name' => 'us', 'criteria' => [['type' => 'country', 'statement' => 'is', 'value' => 'United States(US)']],
            // As `p202 rotator rule-create --redirects_json` sends them: the
            // unused parts as 0 and '' are "not this kind".
            'redirects' => [['redirect_url' => 'https://rule.example/', 'redirect_campaign' => '0', 'redirect_lp' => 0, 'weight' => '100', 'name' => 'A']],
        ]);
        $row = self::$db->query("SELECT rr.redirect_url, rr.redirect_campaign, rr.redirect_lp, rr.weight FROM 202_rotator_rules_redirects rr JOIN 202_rotator_rules r ON r.id = rr.rule_id WHERE r.rotator_id = $id")->fetch_row();
        self::assertSame(['https://rule.example/', null, null, '100'], $row);
    }

    public function testAnIpCriterionIsStoredAsItsCanonicalAddresses(): void
    {
        $id = (int) $this->rotators()->create(['name' => 'r', 'default_url' => 'https://d.example/'])['data']['id'];
        // As a person types them: upper case, zeros written out, a space
        // after the comma, one address twice.
        $typed = '2001:DB8:0:0::77, 198.51.100.88,2001:db8::77';
        $this->rotators()->createRule($id, [
            'rule_name' => 'ips',
            'criteria' => [['type' => 'ip', 'statement' => 'is', 'value' => $typed]],
            'redirects' => [['redirect_url' => 'https://rule.example/']],
        ]);
        $stored = self::$db->query("SELECT value FROM 202_rotator_rules_criteria WHERE rotator_id = $id")
            ->fetch_row()[0];
        self::assertSame('2001:db8::77,198.51.100.88', $stored);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function refusedDefaults(): iterable
    {
        yield 'two defaults at once' => [['default_url' => 'https://a.example/', 'default_campaign' => '{mine}'], 'default_url'];
        yield 'another account\'s campaign' => [['default_campaign' => '{theirs}'], 'default_campaign'];
        yield 'a deleted campaign' => [['default_campaign' => '{deleted}'], 'default_campaign'];
        yield 'an own campaign id as a fraction' => [['default_campaign' => '{mine}.5'], 'default_campaign'];
        yield 'another account\'s landing page' => [['default_lp' => '{theirlp}'], 'default_lp'];
        yield 'a URL that is not http' => [['default_url' => 'javascript:alert(1)'], 'default_url'];
        yield 'a URL with no host' => [['default_url' => 'https://'], 'default_url'];
    }

    /** @dataProvider refusedDefaults */
    public function testADefaultThePageWouldNotOfferIsRefused(array $payload, string $field): void
    {
        $ids = ['{mine}' => self::campaign(self::USER), '{theirs}' => self::campaign(self::OTHER), '{deleted}' => self::campaign(self::USER, 1), '{theirlp}' => self::landingPage(self::OTHER)];
        $payload = array_map(static fn ($v) => is_string($v) ? strtr($v, array_map('strval', $ids)) : $v, $payload);
        $id = (int) $this->rotators()->create(['name' => 'r', 'default_url' => 'https://keep.example/'])['data']['id'];
        foreach (['create' => fn () => $this->rotators()->create(['name' => 'x'] + $payload), 'update' => fn () => $this->rotators()->update($id, $payload)] as $write => $call) {
            try {
                $call();
                self::fail("$write accepted " . json_encode($payload));
            } catch (ValidationException $e) {
                self::assertArrayHasKey($field, $e->getFieldErrors(), $write);
            }
        }
        self::assertSame(['https://keep.example/', null, null, null], self::storedDefault($id), 'the refused update changed nothing');
        self::assertSame('1', (string) self::$db->query('SELECT COUNT(*) FROM 202_rotators WHERE user_id = ' . self::USER)->fetch_row()[0], 'the refused create made nothing');
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function refusedRules(): iterable
    {
        yield 'a redirect with two destinations' => [['redirects' => [['redirect_url' => 'https://a.example/', 'redirect_campaign' => '{mine}']]], 'redirects.0.redirect_url'];
        yield 'a redirect with none' => [['redirects' => [['weight' => '100']]], 'redirects.0'];
        yield 'a redirect to another account\'s campaign' => [['redirects' => [['redirect_campaign' => '{theirs}']]], 'redirects.0.redirect_campaign'];
        yield 'a redirect that is not an object' => [['redirects' => ['https://a.example/']], 'redirects.0'];
        yield 'a weight over 100' => [['redirects' => [['redirect_url' => 'https://a.example/', 'weight' => '250']]], 'redirects.0.weight'];
        yield 'a criterion type the redirects ignore' => [['criteria' => [['type' => 'county', 'statement' => 'is', 'value' => 'x']]], 'criteria.0.type'];
        yield 'a country as a bare code, which never matches' => [['criteria' => [['type' => 'country', 'statement' => 'is', 'value' => 'United States(US),CA']]], 'criteria.0.value'];
        yield 'a criterion statement they ignore' => [['criteria' => [['type' => 'country', 'statement' => 'isnt', 'value' => 'x']]], 'criteria.0.statement'];
        yield 'a criterion that is not an object' => [['criteria' => ['country']], 'criteria.0'];
        $ip = static fn (string $value): array => [
            ['criteria' => [['type' => 'ip', 'statement' => 'is', 'value' => $value]]],
            'criteria.0.value',
        ];
        yield 'an IP criterion that is not an address' => $ip('203.0.113.9, 203.0.113.500');
        yield 'an IP range, which the redirects never matched' => $ip('203.0.113.0/24');
        yield 'an IP criterion with no address' => $ip(' , ');
        yield 'a status that is not 0 or 1' => [['status' => '1.5'], 'status'];
    }

    /** @dataProvider refusedRules */
    public function testARuleThePageWouldNotSaveIsRefusedAndWritesNothing(array $payload, string $field): void
    {
        $ids = ['{mine}' => self::campaign(self::USER), '{theirs}' => self::campaign(self::OTHER)];
        $payload = json_decode(strtr((string) json_encode($payload), array_map('strval', $ids)), true);
        $id = (int) $this->rotators()->create(['name' => 'r', 'default_url' => 'https://d.example/'])['data']['id'];
        $rules = $this->rotators()->createRule($id, ['rule_name' => 'keep', 'redirects' => [['redirect_url' => 'https://keep.example/']]])['data']['rules'];
        $ruleId = (int) $rules[0]['id'];
        $before = self::$db->query("SELECT COUNT(*) FROM 202_rotator_rules_redirects WHERE rule_id = $ruleId")->fetch_row()[0];

        foreach (['createRule' => fn () => $this->rotators()->createRule($id, ['rule_name' => 'x'] + $payload), 'updateRule' => fn () => $this->rotators()->updateRule($id, $ruleId, $payload)] as $write => $call) {
            try {
                $call();
                self::fail("$write accepted " . json_encode($payload));
            } catch (ValidationException $e) {
                self::assertArrayHasKey($field, $e->getFieldErrors(), $write . ': ' . json_encode($e->getFieldErrors()));
            }
        }
        self::assertSame('1', (string) self::$db->query("SELECT COUNT(*) FROM 202_rotator_rules WHERE rotator_id = $id")->fetch_row()[0], 'no rule was created');
        self::assertSame($before, self::$db->query("SELECT COUNT(*) FROM 202_rotator_rules_redirects WHERE rule_id = $ruleId")->fetch_row()[0], 'the rule kept its redirects');
        self::assertSame('https://keep.example/', (string) self::$db->query("SELECT redirect_url FROM 202_rotator_rules_redirects WHERE rule_id = $ruleId")->fetch_row()[0]);
    }
}
