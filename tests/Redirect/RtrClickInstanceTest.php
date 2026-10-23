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
     * rtr.php?lpr= rewrites the visitor's last click rather than recording
     * another (LastClickFromAddress: the latest by address, whichever link
     * made it). Here the visitor's last click is an uncloaked one, with no
     * public id (stored as 0), and the re-clicks go through a cloaked
     * redirector: the first is handed a public id of its own, the next keeps
     * it, and every visit is the one click, with its first keyword and the
     * latest visit's values. The writer's own test hands recordReClick() a
     * record it built; this is rtr.php's wiring of it, read back through
     * cl.php (the offer URL the click now holds) and GET /clicks/{id}.
     * 202_clicks has no key on click_id, so each visit is a row of its own
     * there (as REPLACE made it), and GET /clicks/{id} answers the latest:
     * the third visit's campaign.
     */
    public function testAReClickRewritesTheVisitorsLastClick(): void
    {
        $run = self::$run;
        $campaign = self::create('campaigns', [
            'aff_campaign_name' => "$run cloaked",
            'aff_campaign_url' => "https://offer.example/$run/cloaked"
                . '?s=[[subid]]&kw=[[t202kw]]&c1=[[c1]]&cpc=[[cpc]]&end',
            'aff_campaign_cloaking' => 1,
            'aff_campaign_payout' => '1', 'aff_network_id' => self::$ids['network'],
        ], 'aff_campaign_id');
        $rotator = self::create('rotators', ['name' => "$run cloaked redirector"], 'id');
        self::createRow("rotators/$rotator/rules", [
            'rule_name' => "$run cloaked rule",
            'criteria' => [['type' => 'ip', 'statement' => 'is_not', 'value' => '192.0.2.1']],
            'redirects' => [['redirect_campaign' => $campaign, 'weight' => 100, 'name' => "$run cloaked"]],
        ], 200);
        $tracker = (string) (self::createRow('trackers', [
            'aff_campaign_id' => 0, 'rotator_id' => $rotator,
            'ppc_account_id' => self::$ids['account'], 'click_cpc' => '0.1',
        ])['tracker_id_public'] ?? '');
        self::assertNotSame('', $tracker, 'the tracker create answered a tracker_id_public');

        [$status, $headers, $body] = self::request(
            self::$base . '/tracking202/redirect/rtr.php?' . http_build_query([
                't202id' => self::$tracker, 't202kw' => "kw-$run", 'c1' => "first-$run",
            ]),
            [CURLOPT_USERAGENT => self::UA]
        );
        self::assertSame(302, $status, $headers . $body);
        self::assertSame(
            1,
            preg_match('~^Location: https://offer\.example/' . $run . '\?s=([1-9][0-9]*)&~mi', $headers, $plain),
            'the uncloaked first visit goes to the offer: ' . $headers
        );
        $clickId = (int) $plain[1];

        // The re-clicks a second after the first visit: two visits of one
        // click in one second share a time, and nothing orders them once an
        // exit has written its route on every row (LatestVisit says so).
        $first = time();
        while (time() === $first) {
            usleep(20000);
        }
        $second = self::cloakedClick(['t202id' => $tracker, 'lpr' => '1', 't202kw' => "x-$run", 'c1' => "second-$run"]);
        $third = self::cloakedClick(['t202id' => $tracker, 'lpr' => '1', 't202kw' => "y-$run", 'c1' => "third-$run"]);

        self::assertSame(
            [$clickId, "kw-$run", "second-$run", '0.1', $clickId, $second['pci'], "kw-$run", "third-$run", '0.1'],
            [
                $second['click'], $second['kw'], $second['c1'], $second['cpc'],
                $third['click'], $third['pci'], $third['kw'], $third['c1'], $third['cpc'],
            ],
            'each re-click is the first click, with its keyword, the visit\'s c1, the public id handed out first,'
                . ' and the cost of the visit, not the first visit\'s 0.37'
        );

        // The click's cookie names the campaign of its latest visit: pci.php
        // reads the click by its public id, go.php by its id.
        $campaignCookie = '~^Set-Cookie: tracking202subid_a_([0-9]+)=' . $clickId . ';~mi';
        [$status, $headers] = self::request(self::$base . '/tracking202/redirect/pci.php?pci=' . $second['pci']);
        self::assertSame(302, $status, $headers);
        self::assertSame(1, preg_match($campaignCookie, $headers, $cookie), 'pci.php sets a cookie: ' . $headers);
        self::assertSame((string) $campaign, $cookie[1], 'pci.php: the latest visit\'s campaign');
        [, $headers] = self::request(
            self::$base . '/tracking202/redirect/go.php?202v='
                . rawurlencode(base64_encode($clickId . ' ' . $second['pci']))
        );
        self::assertSame(1, preg_match($campaignCookie, $headers, $cookie), 'go.php sets a cookie: ' . $headers);
        self::assertSame((string) $campaign, $cookie[1], 'go.php: the latest visit\'s campaign');

        [$status, , $answer] = self::request(self::$base . '/api/v3/clicks/' . $clickId, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . self::$key],
        ]);
        self::assertSame(200, $status, $answer);
        $click = json_decode($answer, true)['data'] ?? null;
        self::assertIsArray($click, $answer);
        $actual = [];
        foreach (['click_id_public', 'aff_campaign_id', 'rotator_id', 'click_cloaking', 'keyword', 'c1'] as $field) {
            $actual[$field] = array_key_exists($field, $click) ? $click[$field] : '(not served)';
        }
        self::assertSame([
            'click_id_public' => (int) $second['pci'],
            'aff_campaign_id' => $campaign,
            'rotator_id' => $rotator,
            'click_cloaking' => 1,
            'keyword' => "kw-$run",
            'c1' => "third-$run",
        ], $actual, $answer);
        self::assertStringContainsString(
            "c1=third-$run",
            (string) ($click['outbound'] ?? ''),
            'the latest visit\'s link'
        );

        // An advanced landing page's two exits read the click by its id as
        // well: off.php (its offer, by acip and pci) and offrtr.php (its
        // redirector, by rpi and the click cookie) each send the visitor on
        // with the latest visit's values. Last: each records the click's exit,
        // which rewrites the outbound link read above.
        $latest = '~/cloaked\?s=' . $clickId . '&amp;kw=kw-' . $run
            . '&amp;c1=third-' . $run . '&amp;cpc=0\.1&amp;end~';
        $acip = self::read("campaigns/$campaign")['aff_campaign_id_public'] ?? '';
        [$status, $headers, $page] = self::request(
            self::$base . '/tracking202/redirect/off.php?acip=' . $acip . '&pci=' . $second['pci']
        );
        self::assertSame(1, preg_match($latest, $page), "off.php: the latest visit's link: $status $headers$page");
        $rpi = self::read("rotators/$rotator")['public_id'] ?? '';
        [$status, $headers, $page] = self::request(
            self::$base . '/tracking202/redirect/offrtr.php?rpi=' . $rpi,
            [CURLOPT_COOKIE => 'tracking202subid=' . $clickId, CURLOPT_USERAGENT => self::UA]
        );
        self::assertSame(1, preg_match($latest, $page), "offrtr.php: the latest visit's link: $status $headers$page");
    }

    /**
     * A click through a cloaked rule: rtr.php answers its cl.php link, and
     * cl.php the offer URL the click holds, whose subid is the click's id.
     *
     * @param array<string, string> $query
     * @return array{pci: string, click: int, kw: string, c1: string, cpc: string}
     */
    private static function cloakedClick(array $query): array
    {
        [$status, $headers, $body] = self::request(
            self::$base . '/tracking202/redirect/rtr.php?' . http_build_query($query),
            [CURLOPT_USERAGENT => self::UA]
        );
        self::assertSame(302, $status, $headers . $body);
        self::assertSame(
            1,
            preg_match('~^Location: \S+/tracking202/redirect/cl\.php\?pci=([1-9][0-9]*)\b~mi', $headers, $pci),
            'a cloaked click is sent to its cl.php link: ' . $headers
        );
        [$status, , $page] = self::request(self::$base . '/tracking202/redirect/cl.php?pci=' . $pci[1]);
        self::assertSame(200, $status, $page);
        self::assertSame(
            1,
            preg_match(
                '~url=https://offer\.example/' . self::$run
                    . '/cloaked\?s=([1-9][0-9]*)&amp;kw=([^&]*)&amp;c1=([^&]*)&amp;cpc=([^&]*)&amp;end~',
                $page,
                $offer
            ),
            'cl.php forwards to the offer URL the click holds: ' . $page
        );

        return [
            'pci' => $pci[1], 'click' => (int) $offer[1],
            'kw' => urldecode($offer[2]), 'c1' => urldecode($offer[3]), 'cpc' => urldecode($offer[4]),
        ];
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

    /** @return array<string, mixed> the record GET /api/v3/{$resource} answers */
    private static function read(string $resource): array
    {
        [$status, , $response] = self::request(self::$base . '/api/v3/' . $resource, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . self::$key],
        ]);
        $decoded = json_decode($response, true);
        if ($status !== 200 || !is_array($decoded) || !is_array($decoded['data'] ?? null)) {
            throw new \RuntimeException("GET /$resource answered $status: " . substr($response, 0, 300));
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
