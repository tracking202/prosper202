<?php

declare(strict_types=1);

namespace Tests\Redirect;

use PHPUnit\Framework\TestCase;

/**
 * rtr.php over HTTP, against a running instance: P202_BASE (default
 * http://localhost:8000, where the Agent Evals job serves its instance) with
 * the admin's REST key in P202_API_KEY, which builds the redirector, its rule
 * and the tracker clicked, and reads the click back.
 *
 * rtr.php builds its ClickRecord from the values it gathered, the rotator,
 * rule and redirect ids among them (ClickRecordBuilder::forRedirector());
 * the writer's own test builds its record directly. So a field mapped to the
 * wrong one in rtr.php (the rule and its redirect swapped, the keyword lost)
 * is seen only by a click: one through a rule (the default stores neither
 * id), read back through GET /clicks/{id}. The redirect URL's placeholders
 * are filled inside the click's transaction from the rows it just wrote, so
 * the Location answered proves that read and the URL it hands out.
 *
 * `@group instance` keeps it out of the database-only integration job; the
 * Agent Evals job runs it against the instance it installs.
 *
 * @group integration
 * @group instance
 */
final class RtrClickInstanceTest extends TestCase
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/120.0 Safari/537.36';

    private static string $base = '';
    private static string $key = '';
    private static ?string $setupError = null;
    private static string $run = '';
    /** @var array<string, int> */
    private static array $ids = [];
    private static string $tracker = '';

    public static function setUpBeforeClass(): void
    {
        self::$base = rtrim((string) (getenv('P202_BASE') ?: 'http://localhost:8000'), '/');
        [$status] = self::request(self::$base . '/api/v3/system/health');
        if ($status === 0) {
            self::$setupError = 'No Prosper202 instance answers at ' . self::$base . ' (set P202_BASE).';
            return;
        }
        self::$key = (string) getenv('P202_API_KEY');
        if (self::$key === '') {
            self::$setupError = "Set P202_API_KEY to the instance admin's REST key: the suite builds what it clicks.";
            return;
        }

        $run = self::$run = 'rtr' . bin2hex(random_bytes(4));
        try {
            $network = self::$ids['network'] = self::create(
                'aff-networks',
                ['aff_network_name' => "$run network"],
                'aff_network_id'
            );
            self::$ids['campaign'] = self::create('campaigns', [
                'aff_campaign_name' => "$run campaign",
                'aff_campaign_url' => "https://offer.example/$run?s=[[subid]]&kw=[[t202kw]]&c1=[[c1]]&c4=[[c4]]",
                'aff_campaign_payout' => '2.5', 'aff_network_id' => $network,
            ], 'aff_campaign_id');
            $source = self::create('ppc-networks', ['ppc_network_name' => "$run source"], 'ppc_network_id');
            self::$ids['account'] = self::create('ppc-accounts', [
                'ppc_account_name' => "$run account", 'ppc_network_id' => $source,
            ], 'ppc_account_id');
            // The rule and its redirect must have different ids, or a swap of
            // the two reads the same. Their tables count apart, but a fresh
            // install starts them level: then a redirect-less rule takes a
            // rule id, and the redirector is built again.
            for ($attempt = 1;; $attempt++) {
                self::$ids['rotator'] = self::create('rotators', [
                    'name' => "$run redirector $attempt", 'default_campaign' => self::$ids['campaign'],
                ], 'id');
                // Every visitor but one TEST-NET address: whatever address the
                // instance sees, the click goes through this rule. The answer
                // is the redirector, with its rules (200).
                $rotator = self::createRow('rotators/' . self::$ids['rotator'] . '/rules', [
                    'rule_name' => "$run rule",
                    'criteria' => [['type' => 'ip', 'statement' => 'is_not', 'value' => '192.0.2.1']],
                    'redirects' => [
                        ['redirect_campaign' => self::$ids['campaign'], 'weight' => 100, 'name' => "$run redirect"],
                    ],
                ], 200);
                self::$ids['rule'] = (int) ($rotator['rules'][0]['id'] ?? 0);
                self::$ids['redirect'] = (int) ($rotator['rules'][0]['redirects'][0]['id'] ?? 0);
                if (self::$ids['rule'] <= 0 || self::$ids['redirect'] <= 0) {
                    throw new \RuntimeException('The rule create answered without a rule and redirect id: '
                        . json_encode($rotator));
                }
                if (self::$ids['rule'] !== self::$ids['redirect']) {
                    break;
                }
                if ($attempt === 3) {
                    throw new \RuntimeException('The rule and its redirect kept the same id: ' . self::$ids['rule']);
                }
                self::createRow('rotators/' . self::$ids['rotator'] . '/rules', ['rule_name' => "$run spare"], 200);
            }
            $tracker = self::createRow('trackers', [
                'aff_campaign_id' => 0, 'rotator_id' => self::$ids['rotator'],
                'ppc_account_id' => self::$ids['account'], 'click_cpc' => '0.37',
            ]);
            self::$tracker = (string) ($tracker['tracker_id_public'] ?? '');
            if (self::$tracker === '') {
                throw new \RuntimeException('The tracker create answered without a tracker_id_public.');
            }
        } catch (\RuntimeException $e) {
            self::$setupError = $e->getMessage();
        }
    }

    protected function setUp(): void
    {
        if (self::$setupError !== null) {
            self::fail(self::$setupError);
        }
    }

    public function testARuleClickIsRecordedWithEveryValueItCarried(): void
    {
        $run = self::$run;
        $query = http_build_query([
            't202id' => self::$tracker, 't202kw' => "kw-$run",
            'c1' => "c1-$run", 'c2' => "c2-$run", 'c3' => "c3-$run", 'c4' => "c4-$run",
        ]);
        [$status, $headers, $body] = self::request(self::$base . '/tracking202/redirect/rtr.php?' . $query, [
            CURLOPT_USERAGENT => self::UA,
            CURLOPT_REFERER => "https://referer.example/$run",
        ]);
        self::assertSame(302, $status, $headers . $body);
        self::assertSame(1, preg_match('/^Location: (.+?)\r?$/mi', $headers, $location), $headers);
        self::assertSame(
            1,
            preg_match('~^https://offer\.example/' . $run . '\?s=([1-9][0-9]*)&kw=kw-' . $run
                . '&c1=c1-' . $run . '&c4=c4-' . $run . '$~', $location[1], $subid),
            'the placeholders are filled from the rows the click just wrote: ' . $location[1]
        );

        [$status, , $answer] = self::request(self::$base . '/api/v3/clicks/' . $subid[1], [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . self::$key],
        ]);
        self::assertSame(200, $status, $answer);
        $click = json_decode($answer, true)['data'] ?? null;
        self::assertIsArray($click, $answer);

        $expected = [
            'click_id' => (int) $subid[1],
            'aff_campaign_id' => self::$ids['campaign'],
            'ppc_account_id' => self::$ids['account'],
            'landing_page_id' => 0,
            'text_ad_id' => 0,
            'click_cpc' => 0.37,
            'click_payout' => 2.5,
            'click_alp' => 0,
            'rotator_id' => self::$ids['rotator'],
            'rule_id' => self::$ids['rule'],
            'rule_redirect_id' => self::$ids['redirect'],
            'click_cloaking' => 0,
            'click_in' => 1,
            'click_out' => 1,
            'keyword' => "kw-$run",
            'c1' => "c1-$run",
            'c2' => "c2-$run",
            'c3' => "c3-$run",
            'c4' => "c4-$run",
            'referer' => "https://referer.example/$run",
            'browser_name' => 'Chrome',
            'platform_name' => 'Windows',
        ];
        $actual = [];
        foreach (array_keys($expected) as $field) {
            $actual[$field] = array_key_exists($field, $click) ? $click[$field] : '(not served)';
        }
        self::assertSame($expected, $actual, $answer);
        self::assertEqualsWithDelta(time(), $click['click_time'], 300, 'the click is stamped now');
    }

    /**
     * A rule's campaign is rotated as the redirector's default is: rtr.php
     * built each rule redirect without the campaign's aff_campaign_rotate, so
     * every click went to its first URL (and logged an undefined key).
     */
    public function testARuleToARotatingCampaignSendsItsUrlsInTurn(): void
    {
        $run = self::$run;
        $campaign = self::create('campaigns', [
            'aff_campaign_name' => "$run rotating",
            'aff_campaign_url' => "https://offer.example/$run/first?s=[[subid]]",
            'aff_campaign_url_2' => "https://offer.example/$run/second?s=[[subid]]",
            'aff_campaign_rotate' => 1,
            'aff_campaign_payout' => '1', 'aff_network_id' => self::$ids['network'],
        ], 'aff_campaign_id');
        $rotator = self::create('rotators', ['name' => "$run rotating redirector"], 'id');
        self::createRow("rotators/$rotator/rules", [
            'rule_name' => "$run rotating rule",
            'criteria' => [['type' => 'ip', 'statement' => 'is_not', 'value' => '192.0.2.1']],
            'redirects' => [['redirect_campaign' => $campaign, 'weight' => 100, 'name' => "$run rotating"]],
        ], 200);
        $tracker = (string) (self::createRow('trackers', [
            'aff_campaign_id' => 0, 'rotator_id' => $rotator,
            'ppc_account_id' => self::$ids['account'], 'click_cpc' => '0.1',
        ])['tracker_id_public'] ?? '');
        self::assertNotSame('', $tracker, 'the tracker create answered a tracker_id_public');

        $sent = [];
        foreach ([1, 2] as $click) {
            [$status, $headers, $body] = self::request(
                self::$base . '/tracking202/redirect/rtr.php?t202id=' . rawurlencode($tracker),
                [CURLOPT_USERAGENT => self::UA]
            );
            self::assertSame(302, $status, "click $click: " . $headers . $body);
            self::assertSame(1, preg_match('/^Location: (.+?)\r?$/mi', $headers, $location), $headers);
            $sent[] = (string) preg_replace('/\?.*/', '', $location[1]);
        }

        self::assertSame(
            ["https://offer.example/$run/first", "https://offer.example/$run/second"],
            $sent,
            'two clicks through the rule go to the campaign\'s two URLs in turn'
        );
    }

    /**
     * @param array<int, mixed> $options
     * @return array{0: int, 1: string, 2: string} status, headers, body
     */
    private static function request(string $url, array $options = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 30,
        ] + $options);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        if (!is_string($response)) {
            return [0, '', ''];
        }

        return [$status, substr($response, 0, $headerSize), substr($response, $headerSize)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed> the created row
     */
    private static function createRow(string $resource, array $body, int $answers = 201): array
    {
        [$status, , $response] = self::request(self::$base . '/api/v3/' . $resource, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . self::$key, 'Content-Type: application/json'],
        ]);
        $decoded = json_decode($response, true);
        if ($status !== $answers || !is_array($decoded) || !is_array($decoded['data'] ?? null)) {
            throw new \RuntimeException("POST /$resource answered $status: " . substr($response, 0, 300));
        }

        return $decoded['data'];
    }

    /** @param array<string, mixed> $body */
    private static function create(string $resource, array $body, string $idField): int
    {
        $id = (int) (self::createRow($resource, $body)[$idField] ?? 0);
        if ($id <= 0) {
            throw new \RuntimeException("POST /$resource answered without $idField");
        }

        return $id;
    }
}
