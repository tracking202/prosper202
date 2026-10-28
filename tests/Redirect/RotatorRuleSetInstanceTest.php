<?php

declare(strict_types=1);

namespace Tests\Redirect;

use PHPUnit\Framework\TestCase;

/**
 * PUT /rotators/{id}/rules over HTTP, read back by clicks through rtr.php,
 * against a running instance: P202_BASE (default http://localhost:8000,
 * where the Agent Evals job serves its instance) with the admin's REST key in
 * P202_API_KEY.
 *
 * The controller's own test reads the stored rows; what only a click can
 * show is that the redirects read them as the set means: a URL destination
 * the set wrote sends the visitor to that URL (a "none" the redirect would
 * read as a campaign sends an empty Location, CLAUDE.md #25), and two rules
 * that both match the visitor are tried in the order the set gives, kept
 * ids and all, not in the order the rules were made. The visitor's address
 * is the X-Forwarded-For the click path honours (VisitorIp).
 *
 * @group integration
 * @group instance
 */
final class RotatorRuleSetInstanceTest extends TestCase
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/120.0 Safari/537.36';

    private static string $base = '';
    private static string $key = '';
    private static ?string $setupError = null;
    private static string $run = '';
    private static int $rotator = 0;
    private static int $campaign = 0;
    private static string $tracker = '';

    public static function setUpBeforeClass(): void
    {
        self::$base = rtrim((string) (getenv('P202_BASE') ?: 'http://localhost:8000'), '/');
        [$status] = self::request('GET', '/api/v3/system/health');
        if ($status === 0) {
            self::$setupError = 'No Prosper202 instance answers at ' . self::$base . ' (set P202_BASE).';
            return;
        }
        self::$key = (string) getenv('P202_API_KEY');
        if (self::$key === '') {
            self::$setupError = "Set P202_API_KEY to the instance admin's REST key: the suite builds what it clicks.";
            return;
        }

        $run = self::$run = 'set' . bin2hex(random_bytes(4));
        try {
            $network = (int) self::call('POST', '/aff-networks', ['aff_network_name' => "$run network"], 201)['aff_network_id'];
            self::$campaign = (int) self::call('POST', '/campaigns', [
                'aff_campaign_name' => "$run campaign", 'aff_campaign_url' => "https://offer.example/$run",
                'aff_campaign_payout' => '1', 'aff_network_id' => $network,
            ], 201)['aff_campaign_id'];
            $source = (int) self::call('POST', '/ppc-networks', ['ppc_network_name' => "$run source"], 201)['ppc_network_id'];
            $account = (int) self::call('POST', '/ppc-accounts', [
                'ppc_account_name' => "$run account", 'ppc_network_id' => $source,
            ], 201)['ppc_account_id'];
            self::$rotator = (int) self::call('POST', '/rotators', [
                'name' => "$run redirector", 'default_url' => "https://default.example/$run",
            ], 201)['id'];
            self::$tracker = (string) (self::call('POST', '/trackers', [
                'aff_campaign_id' => 0, 'rotator_id' => self::$rotator, 'ppc_account_id' => $account, 'click_cpc' => '0.1',
            ], 201)['tracker_id_public'] ?? '');
            if (self::$tracker === '' || self::$rotator <= 0 || self::$campaign <= 0) {
                throw new \RuntimeException('The setup answered without the ids it needs.');
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

    /** @param list<array<string, mixed>> $rules @return array<string, mixed> */
    private static function setRules(array $rules, string $query = '', int $answers = 200): array
    {
        return self::call('PUT', '/rotators/' . self::$rotator . '/rules' . $query, ['rules' => $rules], $answers);
    }

    /** @return array<string, mixed> one rule matching $ip, sending it to $url */
    private static function rule(string $name, string $ip, string $url): array
    {
        return ['rule_name' => $name, 'criteria' => [['type' => 'ip', 'value' => $ip]], 'redirects' => [['redirect_url' => $url]]];
    }

    /** Where rtr.php sends a visitor from $ip. */
    private static function click(string $ip): string
    {
        [$status, $headers, $body] = self::request('GET', '/tracking202/redirect/rtr.php?t202id=' . rawurlencode(self::$tracker), null, [
            CURLOPT_USERAGENT => self::UA,
            CURLOPT_HTTPHEADER => ['X-Forwarded-For: ' . $ip],
        ]);
        self::assertSame(302, $status, $headers . $body);
        self::assertSame(1, preg_match('/^Location: (.+?)\r?$/mi', $headers, $location), $headers);

        return $location[1];
    }

    public function testTheRedirectsFollowTheSetAndItsOrder(): void
    {
        $run = self::$run;
        $x = "https://x.example/$run";
        $y = "https://y.example/$run";
        $both = '203.0.113.30';

        $preview = self::setRules([self::rule('x', $both, $x), self::rule('y', $both, $y)], '?dry_run=1');
        self::assertTrue($preview['dry_run']);
        self::assertSame("https://default.example/$run", self::click($both), 'a dry run writes nothing the redirects read');

        $set = self::setRules([
            self::rule('x', $both, $x),
            self::rule('y', $both, $y),
            ['rule_name' => 'campaign', 'criteria' => [['type' => 'ip', 'value' => '198.51.100.31']],
                'redirects' => [['redirect_campaign' => self::$campaign]]],
        ]);
        self::assertSame(['x', 'y', 'campaign'], array_column($set['rules'], 'rule_name'));
        self::assertSame($x, self::click($both), 'the first rule that matches sends the visitor');
        self::assertSame("https://offer.example/$run", self::click('198.51.100.31'), 'a campaign destination');
        self::assertSame("https://default.example/$run", self::click('192.0.2.31'), 'no rule: the default');

        // The same two rules, kept with their ids, the other way round: the
        // later rule by id is now tried first.
        [$ruleX, $ruleY] = $set['rules'];
        $swapped = self::setRules([$ruleY, $ruleX]);
        self::assertSame([$ruleY['id'], $ruleX['id']], array_column($swapped['rules'], 'id'));
        self::assertSame(
            [['update', ['position']], ['update', ['position']], ['remove', null]],
            array_map(static fn (array $c): array => [$c['action'], $c['changed'] ?? null], $swapped['changes']['rules'])
        );
        self::assertGreaterThan($ruleX['id'], $ruleY['id']);
        self::assertSame($y, self::click($both), 'the order the set gives, not the order the rules were made');
        self::assertSame("https://default.example/$run", self::click('198.51.100.31'), 'the rule left out is gone');
    }

    public function testARefusedSetChangesNothingTheRedirectsRead(): void
    {
        $run = self::$run;
        $keep = "https://keep.example/$run";
        self::setRules([self::rule('keep', '203.0.113.32', $keep)]);
        $before = self::call('GET', '/rotators/' . self::$rotator . '/rules', null, 200);

        $refused = self::setRules([
            self::rule('new', '203.0.113.32', "https://new.example/$run"),
            ['rule_name' => 'nobody\'s', 'criteria' => [['type' => 'country', 'value' => 'ALL']], 'redirects' => [['redirect_campaign' => 2147483647]]],
        ], '', 422);
        self::assertArrayHasKey('rules.1.redirects.0.redirect_campaign', $refused['field_errors'] ?? [], json_encode($refused) ?: '');
        self::assertSame($before, self::call('GET', '/rotators/' . self::$rotator . '/rules', null, 200));
        self::assertSame($keep, self::click('203.0.113.32'));

        // A PATCH naming one rule would remove the others: refused.
        self::call('PATCH', '/rotators/' . self::$rotator . '/rules', ['rules' => [self::rule('only', '203.0.113.33', $keep)]], 405);
        self::assertSame($before, self::call('GET', '/rotators/' . self::$rotator . '/rules', null, 200));
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed> the answer's data, or the whole answer when it is an error
     */
    private static function call(string $method, string $path, ?array $body, int $answers): array
    {
        [$status, , $response] = self::request($method, '/api/v3' . $path, $body);
        $decoded = json_decode($response, true);
        if ($status !== $answers || !is_array($decoded)) {
            throw new \RuntimeException("$method $path answered $status, want $answers: " . substr($response, 0, 400));
        }

        return $status < 300 ? (array) ($decoded['data'] ?? []) : $decoded;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<int, mixed> $options
     * @return array{0: int, 1: string, 2: string}
     */
    private static function request(string $method, string $path, ?array $body = null, array $options = []): array
    {
        $ch = curl_init(self::$base . $path);
        $headers = self::$key === '' || str_starts_with($path, '/tracking202/') ? [] : ['Authorization: Bearer ' . self::$key];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        // The caller's options first: the left side of + wins.
        curl_setopt_array($ch, $options + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ] + ($body === null ? [] : [CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR)]));
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        if (!is_string($response)) {
            return [0, '', ''];
        }

        return [$status, substr($response, 0, $headerSize), substr($response, $headerSize)];
    }
}
