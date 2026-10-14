<?php

declare(strict_types=1);

namespace Tests\Redirect;

use PHPUnit\Framework\TestCase;

/**
 * An offer URL's [[tokens]] over HTTP, against a running instance: P202_BASE
 * (default http://localhost:8000, where the Agent Evals job serves its
 * instance) with the admin's REST key in P202_API_KEY.
 *
 * dl.php redirects before it writes the click's rows, so a read-back of them
 * found nothing and every click value came out blank, and a simple landing
 * page's record_simple.php computed its outbound URL the same way; [[cpc]] was
 * 0 on every path, filled before the click was read, and [[payout]] blank,
 * read under a key nothing set. dl.php and record_simple.php fill their tokens
 * from the values in hand and rtr.php reads them back, so one campaign is
 * clicked through all three.
 *
 * @group integration
 * @group instance
 */
final class ClickUrlTokensInstanceTest extends TestCase
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/120.0 Safari/537.36';
    private const TOKENS = [
        'subid', 't202kw', 'c1', 'c2', 'c3', 'c4', 'gclid', 'utm_source', 'utm_term',
        'country', 'country_code', 'region', 'city', 'cpc', 'cpc2', 'cpa', 'payout', 'sourceid',
    ];

    private static string $base = '';
    private static string $key = '';
    private static ?string $setupError = null;
    private static string $run = '';
    private static int $account = 0;
    private static string $direct = '';
    private static string $viaRotator = '';
    private static string $landingPage = '';
    private static string $cpaTracker = '';

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

        $run = self::$run = 'tok' . bin2hex(random_bytes(4));
        $query = implode('&', array_map(static fn (string $t): string => "$t=[[$t]]", self::TOKENS));
        try {
            $network = self::create('aff-networks', ['aff_network_name' => "$run network"], 'aff_network_id');
            $campaign = self::create('campaigns', [
                'aff_campaign_name' => "$run campaign",
                'aff_campaign_url' => "https://offer.example/$run?$query",
                'aff_campaign_payout' => '2.75', 'aff_network_id' => $network,
            ], 'aff_campaign_id');
            $source = self::create('ppc-networks', ['ppc_network_name' => "$run source"], 'ppc_network_id');
            self::$account = self::create('ppc-accounts', [
                'ppc_account_name' => "$run account", 'ppc_network_id' => $source,
            ], 'ppc_account_id');
            self::$direct = self::tracker(['aff_campaign_id' => $campaign]);
            $rotator = self::create('rotators', ['name' => "$run redirector", 'default_campaign' => $campaign], 'id');
            self::$viaRotator = self::tracker(['aff_campaign_id' => 0, 'rotator_id' => $rotator]);
            self::$cpaTracker = (string) (self::createRow('trackers', [
                'aff_campaign_id' => $campaign, 'ppc_account_id' => self::$account, 'click_cpa' => '1.25',
            ])['tracker_id_public'] ?? '');
            if (self::$cpaTracker === '') {
                throw new \RuntimeException('The CPA tracker create answered without a tracker_id_public.');
            }
            self::$landingPage = (string) (self::createRow('landing-pages', [
                'landing_page_nickname' => "$run page", 'landing_page_url' => "https://lp.example/$run",
                'aff_campaign_id' => $campaign, 'landing_page_type' => 0,
            ])['landing_page_id_public'] ?? '');
            if (self::$landingPage === '') {
                throw new \RuntimeException('The landing page create answered without a landing_page_id_public.');
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

    public function testADirectLinkFillsItsTokensFromTheClickBeforeItIsWritten(): void
    {
        $run = self::$run;
        $tokens = self::click('tracking202/redirect/dl.php', self::$direct, [
            't202kw' => "kw $run", 'c1' => "O'Brien", 'c2' => 'two', 'c3' => 'a&b', 'c4' => "c4 $run",
            'gclid' => "G-$run", 'utm_source' => 'src1', 'utm_term' => 'term 1',
        ]);

        $geo = array_intersect_key($tokens, array_flip(['country', 'country_code', 'region', 'city']));
        self::assertSame([
            't202kw' => "kw $run", 'c1' => "O'Brien", 'c2' => 'two', 'c3' => 'a&b', 'c4' => "c4 $run",
            'gclid' => "G-$run", 'utm_source' => 'src1', 'utm_term' => 'term 1',
            'cpc' => '0.37', 'cpc2' => '0.37000', 'cpa' => '', 'payout' => '2.75',
            'sourceid' => (string) self::$account,
        ], array_diff_key($tokens, array_flip(['subid', 'country', 'country_code', 'region', 'city'])));

        // The geo tokens are what the click was stored with.
        $click = self::clickRow($tokens['subid']);
        self::assertSame(
            [$click['country_name'], $click['country_code'], $click['region_name'], $click['city_name']],
            [$geo['country'], $geo['country_code'], $geo['region'], $geo['city']]
        );
        self::assertNotSame('', $geo['country'], 'a click is stored with a country, "Unknown country" at least');
    }

    public function testARedirectorFillsCostAndPayoutFromTheClick(): void
    {
        $run = self::$run;
        $tokens = self::click('tracking202/redirect/rtr.php', self::$viaRotator, [
            't202kw' => "kw $run", 'c1' => "c1 $run",
        ]);

        self::assertSame(
            [
                't202kw' => "kw $run", 'c1' => "c1 $run", 'cpc' => '0.37', 'cpc2' => '0.37000', 'cpa' => '',
                'payout' => '2.75',
            ],
            array_intersect_key($tokens, array_flip(['t202kw', 'c1', 'cpc', 'cpc2', 'cpa', 'payout']))
        );
        self::assertSame((string) self::$account, $tokens['sourceid']);
    }

    /**
     * [[cpa]] is the tracker's CPA, and blank where it has none (the cases
     * above): round() made a missing one a 0 that read as a cost.
     */
    public function testACpaTrackerFillsItsCpa(): void
    {
        $tokens = self::click('tracking202/redirect/dl.php', self::$cpaTracker, []);

        self::assertSame('1.25', $tokens['cpa']);
        // It has no CPC, which the click stores as 0.
        self::assertSame(['cpc' => '0', 'cpc2' => '0.00000'], array_intersect_key($tokens, ['cpc' => 1, 'cpc2' => 1]));
    }

    public function testASimpleLandingPageFillsItsOutboundTokensFromTheClickBeforeItIsWritten(): void
    {
        $run = self::$run;
        $tokens = self::simpleLandingPage([
            't202kw' => "kw $run", 'c1' => "O'Brien", 'c2' => 'two', 'c3' => 'a&b', 'c4' => "c4 $run",
            'gclid' => "G-$run", 'utm_source' => 'src1', 'utm_term' => 'term 1',
        ]);

        self::assertSame([
            't202kw' => "kw $run", 'c1' => "O'Brien", 'c2' => 'two', 'c3' => 'a&b', 'c4' => "c4 $run",
            'gclid' => "G-$run", 'utm_source' => 'src1', 'utm_term' => 'term 1',
            'cpc' => '0.37', 'cpc2' => '0.37000', 'cpa' => '', 'payout' => '2.75',
            'sourceid' => (string) self::$account,
        ], array_diff_key($tokens, array_flip(['subid', 'country', 'country_code', 'region', 'city'])));
        $click = self::clickRow($tokens['subid']);
        self::assertSame(
            [$click['country_name'], $click['country_code'], $click['region_name'], $click['city_name']],
            [$tokens['country'], $tokens['country_code'], $tokens['region'], $tokens['city']]
        );
    }

    /**
     * A keyword past its column's 150 characters, or a c1 or UTM value past
     * its 350 bytes, is stored cut, and the offer URL carries it as stored. A
     * redirector fills its URL from the stored click, so its keyword and c1
     * are what was stored (it records no UTM values); the direct link and the
     * simple landing page fill theirs before the click is written, through
     * the cut the repository stores with.
     */
    public function testAValuePastItsColumnIsSentAsItIsStored(): void
    {
        $long = [
            't202kw' => self::$run . str_repeat('k', 200),
            'c1' => self::$run . str_repeat('c', 400),
            'utm_source' => self::$run . str_repeat('u', 400),
        ];
        $stored = [
            't202kw' => substr($long['t202kw'], 0, 150),
            'c1' => substr($long['c1'], 0, 350),
            'utm_source' => substr($long['utm_source'], 0, 350),
        ];

        $rtr = 'tracking202/redirect/rtr.php';
        $paths = [
            'redirector' => static fn (): array => self::click($rtr, self::$viaRotator, $long),
            'direct link' => static fn (): array => self::click('tracking202/redirect/dl.php', self::$direct, $long),
            'simple landing page' => static fn (): array => self::simpleLandingPage($long),
        ];
        $keywordIds = [];
        foreach ($paths as $path => $click) {
            $tokens = $click();
            $sent = $path === 'redirector' ? array_diff_key($stored, ['utm_source' => 1]) : $stored;
            self::assertSame($sent, array_intersect_key($tokens, $sent), $path);
            // The keyword the click names is the one the URL carried, and the
            // lookup finds it again: cut by the server alone, the whole value
            // matched no row, and every such click stored the keyword anew.
            $click = self::clickRow($tokens['subid']);
            self::assertSame($stored['t202kw'], $click['keyword'], $path);
            $keywordIds[] = (string) $click['keyword_id'];
        }
        self::assertCount(1, array_unique($keywordIds), 'one keyword row: ' . implode(', ', $keywordIds));
    }

    /**
     * The page's script records the click and answers the outbound link,
     * which pci.php turns into the offer URL the click was stored with.
     *
     * @param array<string, string> $query
     * @return array<string, string> each token's value in the offer URL
     */
    private static function simpleLandingPage(array $query): array
    {
        [$status, $headers, $script] = self::request(
            self::$base . '/tracking202/static/record_simple.php?' . http_build_query([
                'lpip' => self::$landingPage, 't202id' => self::$direct,
            ] + $query),
            [CURLOPT_USERAGENT => self::UA]
        );
        self::assertSame(200, $status, $headers . $script);
        self::assertSame(1, preg_match('/var outbound = ("[^"\n]*");/', $script, $outbound), $script);
        [$status, $headers, $body] = self::request((string) json_decode($outbound[1]), [CURLOPT_USERAGENT => self::UA]);
        self::assertSame(302, $status, $headers . $body);

        return self::tokens($headers);
    }

    /**
     * @param array<string, string> $query
     * @return array<string, string> each token's value in the Location answered
     */
    private static function click(string $path, string $tracker, array $query): array
    {
        [$status, $headers, $body] = self::request(
            self::$base . '/' . $path . '?' . http_build_query(['t202id' => $tracker] + $query),
            [CURLOPT_USERAGENT => self::UA]
        );
        self::assertSame(302, $status, $headers . $body);

        return self::tokens($headers);
    }

    /** @return array<string, string> each token's value in the Location of $headers */
    private static function tokens(string $headers): array
    {
        self::assertSame(1, preg_match('/^Location: (.+?)\r?$/mi', $headers, $location), $headers);
        self::assertStringStartsWith('https://offer.example/' . self::$run . '?', $location[1]);

        $tokens = [];
        foreach (explode('&', (string) parse_url($location[1], PHP_URL_QUERY)) as $pair) {
            [$name, $value] = explode('=', $pair, 2) + [1 => ''];
            $tokens[$name] = rawurldecode($value);
        }
        self::assertSame(self::TOKENS, array_keys($tokens), $location[1]);
        self::assertMatchesRegularExpression('/^[1-9][0-9]*$/', $tokens['subid'], $location[1]);

        return $tokens;
    }

    /** @return array<string, mixed> */
    private static function clickRow(string $clickId): array
    {
        [$status, , $answer] = self::request(self::$base . '/api/v3/clicks/' . $clickId, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . self::$key],
        ]);
        self::assertSame(200, $status, $answer);
        $click = json_decode($answer, true)['data'] ?? null;
        self::assertIsArray($click, $answer);

        return $click;
    }

    /** @param array<string, int> $fields */
    private static function tracker(array $fields): string
    {
        $tracker = (string) (self::createRow('trackers', $fields + [
            'ppc_account_id' => self::$account, 'click_cpc' => '0.37',
        ])['tracker_id_public'] ?? '');
        if ($tracker === '') {
            throw new \RuntimeException('The tracker create answered without a tracker_id_public.');
        }

        return $tracker;
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
    private static function createRow(string $resource, array $body): array
    {
        [$status, , $response] = self::request(self::$base . '/api/v3/' . $resource, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . self::$key, 'Content-Type: application/json'],
        ]);
        $decoded = json_decode($response, true);
        if ($status !== 201 || !is_array($decoded) || !is_array($decoded['data'] ?? null)) {
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
