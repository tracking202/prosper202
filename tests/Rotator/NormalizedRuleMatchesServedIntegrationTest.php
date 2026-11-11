<?php

declare(strict_types=1);

namespace Tests\Rotator;

use Api\V3\Controllers\RotatorsController;
use PHPUnit\Framework\TestCase;

/**
 * A rule whose value was typed as "gb" or "Mobile" sends its visitors where
 * the rule says, through the real rtr.php on a real database: the scratch
 * database, served by `php -S` over the 202-config.php the integration job
 * writes (tests/run-integration-suites.sh).
 *
 * Two ways such a rule comes to be, both clicked: written now, when the one
 * normalizer (Criterion::stored(), which the API and Setup › Redirectors'
 * save both write through) stores "United Kingdom(GB)" and "mobile"; and
 * stored before, as "GB" and "Mobile", which the redirects never matched
 * and now read by the country's code and the device in any case.
 *
 * The British visitor's address is the one MaxMind's test database places in
 * London (tests/fixtures/geoip/README.md); everyone else's is a
 * documentation address, which no database places.
 *
 * @group integration
 */
final class NormalizedRuleMatchesServedIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990441;
    private const BRITISH = '81.2.69.160';
    private const UNPLACED = '203.0.113.44';
    private const DESKTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    private const MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15';
    private const PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1';

    /** @var resource|null */
    private static $server = null;
    private static string $base = '';
    private static string $log = '';

    /** @var array<string, int> a redirector's tracker, by what its rule holds */
    private static array $trackers = [];

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/202-config.php')) {
            self::$db = null; // no config for the served tree: skipped, as without a database

            return;
        }
        self::cleanUp();
        self::user(self::USER);
        // Nobody is held back: a new account's 'eu' would mask the addresses the rules are about.
        self::q('INSERT INTO 202_users_pref SET user_id = ' . self::USER . ", user_pref_privacy = 'disabled'");

        $api = new RotatorsController(self::$db, self::USER);
        $written = static function (string $name, string $type, string $statement, string $typed) use ($api): int {
            $id = (int) $api->create(['name' => "nr $name", 'default_url' => 'https://default.example/'])['data']['id'];
            $api->createRule($id, [
                'rule_name' => $name,
                'criteria' => [['type' => $type, 'statement' => $statement, 'value' => $typed]],
                'redirects' => [['redirect_url' => 'https://rule.example/']],
            ]);

            return $id;
        };
        $rotators = [
            'country typed' => $written('country typed', 'country', 'is', 'gb'),
            'device typed' => $written('device typed', 'device', 'is', 'Mobile'),
            'country stored' => $written('country stored', 'country', 'is', 'ALL'),
            'country stored, negated' => $written('country stored, negated', 'country', 'is_not', 'ALL'),
            'device stored' => $written('device stored', 'device', 'is', 'ALL'),
            'desktop' => $written('desktop', 'device', 'is', 'desktop'),
        ];
        // As a writer before the normalizer left them.
        foreach (['country stored' => 'GB', 'country stored, negated' => 'GB', 'device stored' => 'Mobile'] as $name => $value) {
            self::q('UPDATE 202_rotator_rules_criteria SET value = ' . self::str($value) . ' WHERE rotator_id = ' . $rotators[$name]);
        }
        $tracker = 9904410;
        foreach ($rotators as $name => $rotator) {
            self::row('202_trackers', [
                'tracker_id' => ++$tracker, 'user_id' => self::USER, 'tracker_id_public' => $tracker,
                'aff_campaign_id' => 0, 'text_ad_id' => 0, 'ppc_account_id' => 0, 'landing_page_id' => 0,
                'rotator_id' => $rotator, 'click_cloaking' => 0, 'tracker_time' => time(),
            ]);
            self::$trackers[$name] = $tracker;
        }

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($probe, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($probe);
        self::$log = (string) tempnam(sys_get_temp_dir(), 'p202nr');
        self::$server = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . self::$log,
                '-S', "127.0.0.1:$port", '-t', $root],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        self::$base = "http://127.0.0.1:$port";
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port);
            if ($socket !== false) {
                fclose($socket);
                break;
            }
            usleep(100000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        if (self::$log !== '') {
            @unlink(self::$log);
        }
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
            self::$db = null;
        }
    }

    private static function cleanUp(): void
    {
        $rotators = 'SELECT id FROM 202_rotators WHERE user_id = ' . self::USER;
        self::q("DELETE FROM 202_rotator_rules_redirects WHERE rule_id IN (SELECT id FROM 202_rotator_rules WHERE rotator_id IN ($rotators))");
        self::q("DELETE FROM 202_rotator_rules_criteria WHERE rotator_id IN ($rotators)");
        self::q("DELETE FROM 202_rotator_rules WHERE rotator_id IN ($rotators)");
        foreach (['202_rotators', '202_trackers', '202_users_pref', '202_users'] as $table) {
            self::q("DELETE FROM $table WHERE user_id = " . self::USER);
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        if (self::$base === '') {
            self::fail('the click scripts could not be served');
        }
    }

    /** Where a click on the redirector's link sends this visitor. */
    private static function click(string $rule, string $address, string $userAgent): string
    {
        $ch = curl_init(self::$base . '/tracking202/redirect/rtr.php?t202id=' . self::$trackers[$rule]);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => $userAgent, CURLOPT_HTTPHEADER => ['X-Forwarded-For: ' . $address]]);
        $out = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        self::assertSame(302, $status, $out . "\nserver log:\n" . (string) @file_get_contents(self::$log));
        self::assertSame(1, preg_match('~^Location: (\S+)\r?$~mi', $out, $m), $out);

        return $m[1];
    }

    private static function storedValue(string $rule): string
    {
        $row = self::requireScratch()->query('SELECT c.value FROM 202_rotator_rules_criteria AS c'
            . ' JOIN 202_trackers AS t ON t.rotator_id = c.rotator_id WHERE t.tracker_id = ' . self::$trackers[$rule])->fetch_row();

        return (string) ($row[0] ?? '');
    }

    public function testACountryTypedAsItsCodeIsStoredByNameAndMatchesItsVisitors(): void
    {
        self::assertSame('United Kingdom(GB)', self::storedValue('country typed'));
        self::assertSame('https://rule.example/', self::click('country typed', self::BRITISH, self::DESKTOP));
        self::assertSame('https://default.example/', self::click('country typed', self::UNPLACED, self::DESKTOP));
    }

    public function testADeviceTypedWithACapitalIsStoredInLowerCaseAndMatchesItsVisitors(): void
    {
        self::assertSame('mobile', self::storedValue('device typed'));
        self::assertSame('https://rule.example/', self::click('device typed', self::UNPLACED, self::PHONE));
        self::assertSame('https://default.example/', self::click('device typed', self::UNPLACED, self::DESKTOP));
    }

    /** Rows already written do not change: the redirects read them as they were meant. */
    public function testARuleStoredAsABareCodeOrACapitalisedDeviceMatchesItsVisitors(): void
    {
        self::assertSame('GB', self::storedValue('country stored'));
        self::assertSame('https://rule.example/', self::click('country stored', self::BRITISH, self::DESKTOP));
        self::assertSame('https://default.example/', self::click('country stored', self::UNPLACED, self::DESKTOP));

        // "is not GB" matched every visitor, the British ones too.
        self::assertSame('https://default.example/', self::click('country stored, negated', self::BRITISH, self::DESKTOP));
        self::assertSame('https://rule.example/', self::click('country stored, negated', self::UNPLACED, self::DESKTOP));

        self::assertSame('Mobile', self::storedValue('device stored'));
        self::assertSame('https://rule.example/', self::click('device stored', self::UNPLACED, self::PHONE));
        self::assertSame('https://default.example/', self::click('device stored', self::UNPLACED, self::DESKTOP));
    }

    /**
     * A desktop is every computer, a Mac included: the user agent parser
     * calls a Mac's device "Mac", which the redirects compared as it was,
     * so "device is desktop" sent every Mac to the default.
     */
    public function testADesktopRuleMatchesAMac(): void
    {
        self::assertSame('https://rule.example/', self::click('desktop', self::UNPLACED, self::MAC));
        self::assertSame('https://rule.example/', self::click('desktop', self::UNPLACED, self::DESKTOP));
        self::assertSame('https://default.example/', self::click('desktop', self::UNPLACED, self::PHONE));
    }
}
