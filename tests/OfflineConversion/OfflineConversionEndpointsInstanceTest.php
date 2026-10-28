<?php

declare(strict_types=1);

namespace Tests\OfflineConversion;

use PHPUnit\Framework\TestCase;

/**
 * The offline conversion files over HTTP against a running instance:
 * P202_BASE (default http://localhost:8000, where the Agent Evals job serves
 * its instance) with the admin's REST key in P202_API_KEY. The suite builds a
 * campaign and a direct link, clicks it through dl.php with each platform's
 * click id, converts the clicks through POST /conversions (which names the
 * click) and one through gpx.php with nothing named (a guess from the
 * address), then reads what each file would send and why the rest is left
 * out. Every click is minutes old, so a named conversion with an id is held
 * back for its click's first six hours: the file holds headers only, and the
 * reasons are what this suite reads. OfflineExportIntegrationTest writes the
 * rows themselves from clicks it dates.
 *
 * The fetch URL's key is made on the Setup page only, so here the public
 * route is asked what it answers to everything that is not a key.
 *
 * @group integration
 * @group instance
 */
final class OfflineConversionEndpointsInstanceTest extends TestCase
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/120.0 Safari/537.36';

    private static string $base = '';
    private static string $key = '';
    private static ?string $setupError = null;
    private static string $run = '';
    private static int $campaign = 0;
    private static string $tracker = '';
    private static bool $converted = false;

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
        $run = self::$run = 'oc' . bin2hex(random_bytes(4));
        try {
            $network = (int) self::createRow('aff-networks', ['aff_network_name' => "$run network"])['aff_network_id'];
            self::$campaign = (int) self::createRow('campaigns', [
                'aff_campaign_name' => "$run campaign",
                'aff_campaign_url' => "https://offer.example/$run?s=[[subid]]",
                'aff_campaign_payout' => '3', 'aff_network_id' => $network, 'payout_mode' => 'accumulate',
            ])['aff_campaign_id'];
            $source = (int) self::createRow('ppc-networks', ['ppc_network_name' => "$run source"])['ppc_network_id'];
            $account = (int) self::createRow('ppc-accounts', [
                'ppc_account_name' => "$run account", 'ppc_network_id' => $source,
            ])['ppc_account_id'];
            self::$tracker = (string) (self::createRow('trackers', [
                'aff_campaign_id' => self::$campaign, 'ppc_account_id' => $account, 'click_cpc' => '0.10',
            ])['tracker_id_public'] ?? '');
            if (self::$tracker === '') {
                throw new \RuntimeException('The tracker was answered without its public id.');
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
        if (!self::$converted) {
            self::$converted = true;
            $run = self::$run;
            // An msclkid is 32 hexadecimal digits, or the file leaves it out.
            $msclkid = bin2hex(random_bytes(16));
            foreach (["gclid=$run-G", '', "gbraid=$run-B", "msclkid=$msclkid"] as $i => $ids) {
                self::createRow('conversions', [
                    'click_id' => self::click($ids), 'payout' => 2, 'transaction_id' => "$run-$i",
                ]);
            }
            // Nothing names this one: gpx.php converts the latest click from the address.
            self::click("gclid=$run-E");
            [$status, $headers, $body] = self::request(
                self::$base . '/tracking202/static/gpx.php?amount=1',
                [CURLOPT_USERAGENT => self::UA]
            );
            self::assertSame(200, $status, $headers . $body);
        }
    }

    public function testEachFileSaysWhatItLeavesOutAndWhy(): void
    {
        $expected = [
            // gclid named, no id, gbraid named, msclkid named, gclid guessed;
            // in the order the answer lists its reasons
            'google' => ['same_ip' => 1, 'no_click_id' => 2, 'click_too_recent' => 2],
            'google_uploads' => [
                'same_ip' => 1, 'no_click_id' => 2, 'gbraid_wbraid_only' => 1, 'click_too_recent' => 1,
            ],
            'microsoft' => ['same_ip' => 1, 'no_click_id' => 3, 'click_too_recent' => 1],
        ];
        foreach ($expected as $format => $reasons) {
            $summary = self::get(self::export('/summary', $format))['data'];
            self::assertSame($format, $summary['format']);
            self::assertSame(1, $summary['days']);
            self::assertSame(5, $summary['conversions'], $format);
            self::assertSame(0, $summary['rows'], "$format: every named click is minutes old");
            self::assertSame($reasons, array_filter($summary['left_out']), $format);
            self::assertSame(5, $summary['left_out_total'], $format);
            self::assertStringContainsString('6 hours', $summary['reasons']['click_too_recent']);
            if ($format === 'microsoft') {
                self::assertSame('UTC', $summary['timezone'], 'Microsoft\'s file is written in UTC');
            }
        }
    }

    public function testTheFileIsTheTemplateWithItsCountsInTheHeaders(): void
    {
        $files = [
            'google' => 'Google Click ID,GBRAID,WBRAID,Conversion Name,Conversion Time,Conversion Value,'
                . "Conversion Currency,Order ID,Ad User Data,Ad Personalization\r\n",
            'microsoft' => "Parameters:TimeZone=+0000,,,,,,\r\n"
                . 'Conversion Name,Conversion Time,Conversion Value,Conversion Currency,Microsoft Click ID,'
                . "Hashed Email Address,Hashed Phone Number\r\n",
        ];
        foreach ($files as $format => $file) {
            $url = self::$base . '/api/v3/' . self::export('', $format);
            [$status, $headers, $body] = self::request($url, self::auth());
            self::assertSame(200, $status, $headers . $body);
            self::assertSame($file, $body, $format);
            self::assertMatchesRegularExpression('~^Content-Type: text/csv; charset=utf-8\r$~mi', $headers);
            self::assertMatchesRegularExpression(
                '~^Content-Disposition: attachment; filename="[^"]+\.csv"\r$~mi',
                $headers
            );
            self::assertMatchesRegularExpression('~^X-P202-Rows: 0\r$~mi', $headers);
            self::assertMatchesRegularExpression('~^X-P202-Left-Out: 5\r$~mi', $headers);
            self::assertMatchesRegularExpression('~^Cache-Control: no-store\r$~mi', $headers);
        }

        // The Uploads template names the account's zone above its headers.
        $zone = self::get(self::export('/summary', 'google_uploads'))['data']['timezone'];
        [$status, , $body] = self::request(self::$base . '/api/v3/' . self::export('', 'google_uploads'), self::auth());
        self::assertSame(200, $status, $body);
        self::assertStringStartsWith("Parameters:TimeZone=$zone,,,,,,,\r\nGoogle Click ID,Conversion Name,", $body);
    }

    public function testTheExportRefusesWhatItCannotReadNamingIt(): void
    {
        $refusals = [
            'days=7' => 'format',
            'format=bing' => 'format',
            'format=google&days=0' => 'days',
            'format=google&days=91' => 'days',
            'format=google&days=1.5' => 'days',
            'format=google&campaign_id=abc' => 'campaign_id',
            'format=google&aff_campaign_id=1' => 'aff_campaign_id',
            'format=microsoft&ad_user_data=granted' => 'ad_user_data',
            'format=google&ad_personalization=yes' => 'ad_personalization',
            'format=google&conversion_name=%3DHYPERLINK(1)' => 'conversion_name',
        ];
        foreach (['conversions/offline-export', 'conversions/offline-export/summary'] as $path) {
            foreach ($refusals as $query => $field) {
                [$status, , $body] = self::request(self::$base . '/api/v3/' . $path . '?' . $query, self::auth());
                self::assertSame(422, $status, "$path?$query: $body");
                $decoded = json_decode($body, true);
                self::assertIsArray($decoded, $body);
                self::assertArrayHasKey($field, $decoded['field_errors'] ?? [], "$path?$query names $field: $body");
            }
        }
    }

    public function testTheFetchUrlAnswersNothingButToItsKey(): void
    {
        $unknown = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        self::assertSame(43, strlen($unknown));
        $feed = self::$base . '/api/v3/offline-conversions/';

        $answers = [
            "$unknown?file=conversions.csv" => 404,
            $unknown => 404,
            'short?file=conversions.csv' => 404,
            "$unknown/more?file=conversions.csv" => 404,
            "$unknown?file=conversions.csv&days=90" => 422,
        ];
        foreach ($answers as $tail => $expected) {
            [$status, $headers, $body] = self::request($feed . $tail);
            self::assertSame($expected, $status, "$tail: $headers$body");
            self::assertStringNotContainsString('text/csv', $headers, $tail);
        }

        // The API key is not what opens it: a key in the URL is.
        [$status] = self::request($feed . "$unknown?file=conversions.csv", self::auth());
        self::assertSame(404, $status);

        [$status, $headers] = self::request(
            $feed . "$unknown?file=conversions.csv",
            [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '']
        );
        self::assertSame(405, $status, $headers);
        self::assertMatchesRegularExpression('~^Allow: GET~mi', $headers);
    }

    /**
     * A click through the direct link with the given ad click ids; its id is
     * the subid the offer URL carries (with whatever the link passes on).
     */
    private static function click(string $ids): int
    {
        $url = self::$base . '/tracking202/redirect/dl.php?t202id=' . rawurlencode(self::$tracker)
            . ($ids === '' ? '' : '&' . $ids);
        [$status, $headers, $body] = self::request($url, [CURLOPT_USERAGENT => self::UA]);
        self::assertSame(302, $status, $headers . $body);
        $location = '~^Location: https://offer\.example/' . self::$run . '\?s=([1-9][0-9]*)(?:&[^\r\n]*)?\r?$~mi';
        self::assertSame(1, preg_match($location, $headers, $m), $headers);

        return (int) $m[1];
    }

    /** The suite's campaign's last day of conversions, as the file or its summary ($tail '/summary'). */
    private static function export(string $tail, string $format): string
    {
        return 'conversions/offline-export' . $tail . '?format=' . $format . '&days=1&campaign_id=' . self::$campaign;
    }

    /** @return array<int, mixed> */
    private static function auth(): array
    {
        return [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . self::$key]];
    }

    /** @return array<string, mixed> */
    private static function get(string $path): array
    {
        [$status, , $body] = self::request(self::$base . '/api/v3/' . $path, self::auth());
        self::assertSame(200, $status, "GET /$path: $body");
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded, $body);

        return $decoded;
    }

    /**
     * @param array<int, mixed> $options
     * @return array{0: int, 1: string, 2: string}
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
}
