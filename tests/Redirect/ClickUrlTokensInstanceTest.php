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
 * found nothing and every click value came out blank; [[cpc]] was 0 on every
 * path, filled before the click was read, and [[payout]] blank, read under a
 * key nothing set. dl.php fills its tokens from the values in hand and rtr.php
 * reads them back, so one campaign is clicked through both.
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
        'country', 'country_code', 'region', 'city', 'cpc', 'cpc2', 'payout', 'sourceid',
    ];

    private static string $base = '';
    private static string $key = '';
    private static ?string $setupError = null;
    private static string $run = '';
    private static int $account = 0;
    private static string $direct = '';
    private static string $viaRotator = '';

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
            'cpc' => '0.37', 'cpc2' => '0.37000', 'payout' => '2.75', 'sourceid' => (string) self::$account,
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
            ['t202kw' => "kw $run", 'c1' => "c1 $run", 'cpc' => '0.37', 'cpc2' => '0.37000', 'payout' => '2.75'],
            array_intersect_key($tokens, array_flip(['t202kw', 'c1', 'cpc', 'cpc2', 'payout']))
        );
        self::assertSame((string) self::$account, $tokens['sourceid']);
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
