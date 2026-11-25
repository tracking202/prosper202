<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PHPUnit\Framework\TestCase;

/**
 * The Campaign optimizer role (campaign data, no Setup) runs the commands it
 * is named for (B6OR). `p202 report losers|winners|breakeven
 * --aff-campaign-id` and `p202 campaign optimize` price clicks with the
 * campaign's payout, which they read from GET /campaigns/{id}, Setup's: the
 * role got a 403 before any report was read. The payout is GET
 * /reports/payout's now, under the reports' rules, and the CLI reads it there.
 *
 * Runs over HTTP against a live instance: P202_BASE with the Super user's
 * REST key in P202_API_KEY, and the built Go CLI (P202_BIN, else
 * go-cli/p202). It creates two users, each made a Campaign manager long
 * enough to set up one campaign in its own account and then switched to its
 * role: a Campaign optimizer (4) and a Campaign viewer (5). Afterwards each
 * is made an Admin to remove its rows, and both are deleted.
 *
 * @group integration
 * @group instance
 */
final class OptimizerRoleRankingsInstanceTest extends TestCase
{
    private const PAYOUT = 160.5;

    private static string $base = '';
    private static string $superKey = '';
    /** @var array<string, array{id: int, key: string, network: int, campaign: int, role: int}> */
    private static array $users = [];
    private static ?string $setupError = null;

    public static function setUpBeforeClass(): void
    {
        self::$base = rtrim((string) (getenv('P202_BASE') ?: 'http://localhost:8000'), '/');
        self::$superKey = (string) getenv('P202_API_KEY');
        if (self::$superKey === '') {
            self::$setupError = "Set P202_API_KEY to the Super user's REST key (the installer's).";
            return;
        }
        $run = 'opt' . bin2hex(random_bytes(3));
        foreach (['optimizer' => 4, 'viewer' => 5] as $who => $role) {
            [$status, $body] = self::call(self::$superKey, 'POST', '/users', [
                'user_name' => "$run$who", 'user_email' => "$run$who@example.com", 'user_pass' => 'pass-' . $run . $who,
            ]);
            $id = (int) ($body['data']['user_id'] ?? 0);
            if ($status !== 201 || $id <= 1) {
                self::$setupError = "Creating the $who answered $status: " . json_encode($body);
                return;
            }
            self::$users[$who] = ['id' => $id, 'key' => '', 'network' => 0, 'campaign' => 0, 'role' => 3];
            [$status, $body] = self::call(self::$superKey, 'POST', "/users/$id/roles", ['role_id' => 3]);
            if ($status >= 300) {
                self::$setupError = "Making the $who a Campaign manager answered $status: " . json_encode($body);
                return;
            }
            [$status, $body] = self::call(self::$superKey, 'POST', "/users/$id/api-keys", []);
            $key = (string) ($body['data']['api_key'] ?? '');
            if ($status !== 201 || $key === '') {
                self::$setupError = "Minting the $who's key answered $status: " . json_encode($body);
                return;
            }
            self::$users[$who]['key'] = $key;
            [$status, $body] = self::call($key, 'POST', '/aff-networks', ['aff_network_name' => "net-$run$who"]);
            $network = (int) ($body['data']['aff_network_id'] ?? 0);
            if ($status !== 201 || $network <= 0) {
                self::$setupError = "The $who creating its network answered $status: " . json_encode($body);
                return;
            }
            self::$users[$who]['network'] = $network;
            [$status, $body] = self::call($key, 'POST', '/campaigns', [
                'aff_campaign_name' => "offer-$run$who", 'aff_campaign_url' => 'https://offer.example/?s=[[subid]]',
                'aff_campaign_payout' => self::PAYOUT, 'aff_network_id' => $network,
            ]);
            $campaign = (int) ($body['data']['aff_campaign_id'] ?? 0);
            if ($status !== 201 || $campaign <= 0) {
                self::$setupError = "The $who creating its campaign answered $status: " . json_encode($body);
                return;
            }
            self::$users[$who]['campaign'] = $campaign;
            [$status, $body] = self::call(self::$superKey, 'POST', "/users/$id/roles", ['role_id' => $role]);
            [$removed] = self::call(self::$superKey, 'DELETE', "/users/$id/roles/3");
            if ($status >= 300 || $removed >= 300) {
                self::$setupError = "Switching the $who to role $role answered $status / $removed: " . json_encode($body);
                return;
            }
            self::$users[$who]['role'] = $role;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$superKey === '') {
            return;
        }
        foreach (self::$users as $user) {
            self::call(self::$superKey, 'POST', '/users/' . $user['id'] . '/roles', ['role_id' => 2]);
            if ($user['key'] !== '') {
                if ($user['campaign'] > 0) {
                    self::call($user['key'], 'DELETE', '/campaigns/' . $user['campaign']);
                }
                if ($user['network'] > 0) {
                    self::call($user['key'], 'DELETE', '/aff-networks/' . $user['network']);
                }
            }
            self::call(self::$superKey, 'DELETE', '/users/' . $user['id']);
        }
    }

    protected function setUp(): void
    {
        if (self::$setupError !== null) {
            self::fail(self::$setupError);
        }
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private static function call(string $key, string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init(self::$base . '/api/v3' . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
            CURLOPT_TIMEOUT => 20,
        ] + ($body === null ? [] : [CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR)]));
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($response)) {
            return [0, []];
        }
        $decoded = $response === '' ? [] : json_decode($response, true);

        return [$status, is_array($decoded) ? $decoded : ['raw' => $response]];
    }

    public function testTheOptimizerReadsItsCampaignsPayoutFromTheReports(): void
    {
        $o = self::$users['optimizer'];
        [$status, $body] = self::call($o['key'], 'GET', '/campaigns/' . $o['campaign']);
        $this->assertSame(403, $status, 'the Setup record stays Setup\'s: ' . json_encode($body));
        $this->assertStringContainsString("'access_to_setup_section' permission", (string) ($body['message'] ?? ''));

        [$status, $body] = self::call($o['key'], 'GET', '/reports/payout?aff_campaign_id=' . $o['campaign']);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame(self::PAYOUT, $body['data']['aff_campaign_payout'] ?? null, 'the payout, as a JSON number');
        $this->assertSame($o['campaign'], $body['data']['aff_campaign_id'] ?? null);
        $this->assertArrayNotHasKey('masked', $body);
    }

    public function testAnotherAccountsCampaignAndABadIdAreRefused(): void
    {
        $o = self::$users['optimizer'];
        [$status, $body] = self::call($o['key'], 'GET', '/reports/payout?aff_campaign_id=' . self::$users['viewer']['campaign']);
        $this->assertSame(404, $status, "another account's campaign is no campaign of this one: " . json_encode($body));
        $this->assertSame('Campaign not found', $body['message'] ?? null);

        foreach (['' => 'aff_campaign_id', '?aff_campaign_id=12abc' => 'aff_campaign_id', '?aff_campaign_id=0' => 'aff_campaign_id',
            '?aff_campaign_id=' . $o['campaign'] . '&period=today' => 'period'] as $query => $field) {
            [$status, $body] = self::call($o['key'], 'GET', '/reports/payout' . $query);
            $this->assertSame(422, $status, "$query: " . json_encode($body));
            $this->assertArrayHasKey($field, $body['field_errors'] ?? [], "$query names $field: " . json_encode($body));
        }
    }

    public function testAViewersPayoutIsMasked(): void
    {
        $v = self::$users['viewer'];
        [$status, $body] = self::call($v['key'], 'GET', '/reports/payout?aff_campaign_id=' . $v['campaign']);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertArrayHasKey('aff_campaign_payout', $body['data'] ?? []);
        $this->assertNull($body['data']['aff_campaign_payout'], 'a role without campaign data is not shown the payout');
        $this->assertTrue($body['masked'] ?? false);
    }

    /**
     * Each command, run by the built CLI with the optimizer's key: exit 0 and
     * its JSON on stdout. The viewer's run of losers is refused as masked.
     */
    public function testTheOptimizerRunsEachCommand(): void
    {
        $o = self::$users['optimizer'];
        $id = (string) $o['campaign'];
        foreach ([
            ['report', 'losers', '--aff-campaign-id', $id, '--period', 'last30'],
            ['report', 'winners', '--aff-campaign-id', $id, '--period', 'last30'],
            ['report', 'breakeven', '--aff-campaign-id', $id, '--period', 'last30'],
            ['campaign', 'optimize', $id, '--period', 'last30'],
        ] as $args) {
            [$code, $stdout, $stderr] = $this->cli($o['key'], [...$args, '--json']);
            $line = 'p202 ' . implode(' ', $args);
            $this->assertSame(0, $code, "$line as a Campaign optimizer: $stderr");
            $this->assertIsArray(json_decode($stdout, true)['data'] ?? null, "$line printed its rows: $stdout");
        }

        $v = self::$users['viewer'];
        [$code, $stdout, $stderr] = $this->cli($v['key'], ['report', 'losers', '--aff-campaign-id', (string) $v['campaign'], '--json']);
        $this->assertSame(2, $code, "a viewer's losers is the auth refusal: $stdout $stderr");
        $this->assertStringContainsString('access_to_campaign_data', $stderr);
        $this->assertSame('', $stdout);
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string, 2: string}
     */
    private function cli(string $key, array $args): array
    {
        $bin = (string) (getenv('P202_BIN') ?: dirname(__DIR__, 3) . '/go-cli/p202');
        if (!is_file($bin) || !is_executable($bin)) {
            self::markTestSkipped("No built Go CLI at $bin: build it (cd go-cli && go build -o p202 .) or set P202_BIN.");
        }
        static $home = null;
        if ($home === null) {
            $home = sys_get_temp_dir() . '/p202-opt-' . bin2hex(random_bytes(4));
            mkdir($home, 0700, true);
        }
        $env = ['HOME' => $home, 'PATH' => (string) getenv('PATH')];
        $run = function (array $argv) use ($bin, $env): array {
            $proc = proc_open([$bin, ...$argv], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            $this->assertIsResource($proc, 'the CLI started');
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            return [proc_close($proc), $stdout, $stderr];
        };
        foreach ([['config', 'set-url', self::$base], ['config', 'set-key', $key]] as $setup) {
            [$code, , $stderr] = $run($setup);
            $this->assertSame(0, $code, 'p202 ' . $setup[0] . ' ' . $setup[1] . ": $stderr");
        }

        return $run($args);
    }
}
