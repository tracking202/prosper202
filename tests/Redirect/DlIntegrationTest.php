<?php
declare(strict_types=1);

namespace Tests\Redirect;

use PHPUnit\Framework\TestCase;

/**
 * dl.php over HTTP, against a running instance: P202_BASE (default
 * http://localhost:8000, where the Agent Evals job serves its instance) with
 * the admin's REST key in P202_API_KEY, which the suite uses to create the
 * one direct-link tracker it clicks.
 *
 * `@group instance` keeps it out of the database-only integration job
 * (tests/run-integration-suites.sh); the Agent Evals job runs it against the
 * instance it installs, after the eval suite so its clicks cannot change
 * what a case reads. With no instance answering, or no key, every test fails
 * saying so rather than asserting against a status of 0.
 *
 * What it pins is the page's own contract: an id that is not a positive
 * number ends the request with an empty 200 (the guard at the top of
 * dl.php), an unknown tracker is the 404 page, and a real tracker redirects
 * to its campaign with every tracking parameter accepted and no PHP notice
 * in the response. The earlier version of this file expected t202id=0 to
 * redirect, which the guard refuses; it had never run anywhere to find out.
 *
 * @group integration
 * @group instance
 */
class DlIntegrationTest extends TestCase
{
    private const CAMPAIGN_URL = 'https://example.com/dl-integration?offer=1';

    private static string $base = '';
    private static ?string $setupError = null;
    private static string $tracker = '';

    public static function setUpBeforeClass(): void
    {
        self::$base = rtrim((string) (getenv('P202_BASE') ?: 'http://localhost:8000'), '/');
        [$status] = self::request(self::$base . '/api/v3/system/health');
        if ($status === 0) {
            self::$setupError = 'No Prosper202 instance answers at ' . self::$base . ' (set P202_BASE).';
            return;
        }
        $key = (string) getenv('P202_API_KEY');
        if ($key === '') {
            self::$setupError = 'Set P202_API_KEY to the instance admin\'s REST key: the suite creates the tracker it clicks.';
            return;
        }

        $run = 'dl-' . bin2hex(random_bytes(4));
        try {
            $network = self::create($key, 'aff-networks', ['aff_network_name' => "$run network"], 'aff_network_id');
            $campaign = self::create($key, 'campaigns', [
                'aff_campaign_name' => "$run campaign", 'aff_campaign_url' => self::CAMPAIGN_URL,
                'aff_campaign_payout' => 1.5, 'aff_network_id' => $network,
            ], 'aff_campaign_id');
            $ppcNetwork = self::create($key, 'ppc-networks', ['ppc_network_name' => "$run source"], 'ppc_network_id');
            $account = self::create($key, 'ppc-accounts', ['ppc_account_name' => "$run account", 'ppc_network_id' => $ppcNetwork], 'ppc_account_id');
            $row = self::createRow($key, 'trackers', ['aff_campaign_id' => $campaign, 'ppc_account_id' => $account]);
            self::$tracker = (string) ($row['tracker_id_public'] ?? '');
            if (self::$tracker === '') {
                self::$setupError = 'The tracker create answered without a tracker_id_public.';
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
            CURLOPT_TIMEOUT => 20,
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
    private static function createRow(string $key, string $resource, array $body): array
    {
        [$status, , $response] = self::request(self::$base . '/api/v3/' . $resource, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
        ]);
        $decoded = json_decode($response, true);
        if ($status !== 201 || !is_array($decoded) || !is_array($decoded['data'] ?? null)) {
            throw new \RuntimeException("POST /$resource answered $status: " . substr($response, 0, 300));
        }

        return $decoded['data'];
    }

    /** @param array<string, mixed> $body */
    private static function create(string $key, string $resource, array $body, string $idField): int
    {
        $id = (int) (self::createRow($key, $resource, $body)[$idField] ?? 0);
        if ($id <= 0) {
            throw new \RuntimeException("POST /$resource answered without $idField");
        }

        return $id;
    }

    /**
     * @param array<int, mixed> $options
     * @return array{0: int, 1: string, 2: string}
     */
    private function dl(string $query, array $options = []): array
    {
        return self::request(self::$base . '/tracking202/redirect/dl.php' . ($query === '' ? '' : '?' . $query), $options);
    }

    private function assertNoPhpNoise(string $response): void
    {
        foreach (['Fatal error', 'Warning:', 'Notice:', 'Deprecated:', 'Undefined', 'TypeError'] as $noise) {
            self::assertStringNotContainsString($noise, $response);
        }
    }

    /** @return array<string, array{0: string}> */
    public static function idsTheGuardRefuses(): array
    {
        return [
            'zero' => ['t202id=0'], 'negative' => ['t202id=-1'], 'letters' => ['t202id=abc'],
            'hex' => ['t202id=0xFF'], 'digits then letters' => ['t202id=123abc'], 'empty' => ['t202id='],
            'missing' => [''],
        ];
    }

    /**
     * @dataProvider idsTheGuardRefuses
     */
    public function testAnIdThatIsNotAPositiveNumberEndsWithAnEmptyAnswer(string $query): void
    {
        [$status, , $body] = $this->dl($query);
        self::assertSame(200, $status);
        self::assertSame('', trim($body));
    }

    public function testAnUnknownTrackerIsTheNotFoundPage(): void
    {
        [$status, , $body] = $this->dl('t202id=999999999');
        self::assertSame(404, $status);
        self::assertStringContainsString('Tracking Link Not Found', $body);
        $this->assertNoPhpNoise($body);
    }

    public function testATrackerRedirectsToItsCampaignWithEveryParameterAccepted(): void
    {
        $params = [
            't202id' => self::$tracker,
            't202kw' => 'test keyword',
            'c1' => 'campaign1', 'c2' => 'adgroup2', 'c3' => 'keyword3', 'c4' => 'creative4',
            'gclid' => 'test_gclid_123',
            'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'summer_sale',
            'utm_term' => 'discount shoes', 'utm_content' => 'text_ad_1',
            't202b' => '1.50',
            'OVKEY' => 'yahoo keyword', 'OVRAW' => 'yahoo raw', 'target_passthrough' => 'media traffic',
            'keyword' => 'generic keyword', 'search_word' => 'eniro search', 'query' => 'naver query',
            'encquery' => 'aol query', 'terms' => 'about terms', 'rdata' => 'viola data', 'qs' => 'virgilio query',
            'wd' => 'baidu word', 'text' => 'yandex text', 'szukaj' => 'wp.pl search', 'qt' => 'onet query',
            'k' => 'yam keyword', 'words' => 'rambler words', 't202ref' => 'custom referer',
            'ua' => 'Mozilla/5.0 Test User Agent',
        ];
        [$status, $headers, $body] = $this->dl(http_build_query($params), [CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) dl-integration']);

        self::assertSame(302, $status);
        self::assertMatchesRegularExpression('#^location: https://example\.com/dl-integration\?offer=1#mi', $headers);
        $this->assertNoPhpNoise($headers . $body);
    }

    public function testATrackerRedirectsWithoutAReferer(): void
    {
        [$status, $headers, $body] = $this->dl('t202id=' . rawurlencode(self::$tracker) . '&t202kw=test', [
            CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) dl-integration',
        ]);

        self::assertSame(302, $status);
        self::assertMatchesRegularExpression('#^location: https://example\.com/dl-integration#mi', $headers);
        $this->assertNoPhpNoise($headers . $body);
    }

    public function testATrackerRedirectsWhateverCharactersTheParametersCarry(): void
    {
        $params = [
            't202id' => self::$tracker,
            'c1' => 'test & special', 'c2' => 'test < > chars', 'c3' => 'test " quotes', 'c4' => "test ' apostrophe",
            't202kw' => 'keyword with spaces and %20 encoding',
        ];
        [$status, $headers, $body] = $this->dl(http_build_query($params), [CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) dl-integration']);

        self::assertSame(302, $status);
        $this->assertNoPhpNoise($headers . $body);
    }
}
