<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PHPUnit\Framework\TestCase;

/**
 * The Setup routes ask for what the Setup pages ask for (CLAUDE.md #5):
 * access_to_setup_section to read, create or change anything, and each
 * removal's own remove_* permission to delete it. Before, any key could
 * write: a Campaign viewer's — a role that "changes nothing" — created and
 * deleted campaigns, and read every campaign's URL and payout, which its
 * pages never show it. A dry-run preview and a staged write are refused as
 * the write is, because the permission runs as the route's group middleware.
 *
 * The reports, clicks and conversions are open to every role, as the pages
 * are, with the figures a role without access_to_campaign_data may not see
 * null and `masked: true` (the pages print '?'); and recording or removing a
 * conversion asks for the Update pages' permissions.
 *
 * Runs over HTTP against a live instance: P202_BASE with the Super user's
 * REST key in P202_API_KEY. It creates a Campaign viewer (role 5) and a
 * Campaign manager (role 3) with keys; the manager — who may set up — makes
 * one of each Setup entity in its own account (keys see their own user's
 * rows); afterwards the manager is made an Admin to remove them, and both
 * users are deleted.
 *
 * @group integration
 * @group instance
 */
final class SetupPermissionsInstanceTest extends TestCase
{
    private static string $base = '';
    private static string $superKey = '';
    /** @var array<string, array{id: int, key: string}> */
    private static array $users = [];
    /** @var array<string, int> resource => fixture id */
    private static array $fixture = [];
    private static int $ruleId = 0;
    private static ?string $setupError = null;

    /** resource => [a create body, the remove_* permission its delete asks for] */
    private static function resources(): array
    {
        $f = self::$fixture;

        return [
            'aff-networks' => [['aff_network_name' => 'perm-net-x'], 'remove_campaign_category'],
            'campaigns' => [['aff_campaign_name' => 'perm-x', 'aff_campaign_url' => 'https://perm.example/', 'aff_campaign_payout' => 1, 'aff_network_id' => $f['aff-networks'] ?? 0], 'remove_campaign'],
            'ppc-networks' => [['ppc_network_name' => 'perm-src-x'], 'remove_traffic_source'],
            'ppc-accounts' => [['ppc_account_name' => 'perm-acct-x', 'ppc_network_id' => $f['ppc-networks'] ?? 0], 'remove_traffic_source_account'],
            'landing-pages' => [['landing_page_url' => 'https://perm-lp.example/', 'aff_campaign_id' => $f['campaigns'] ?? 0, 'landing_page_nickname' => 'perm-lp-x'], 'remove_landing_page'],
            'text-ads' => [['text_ad_name' => 'perm-ad-x', 'aff_campaign_id' => $f['campaigns'] ?? 0, 'text_ad_headline' => 'h', 'text_ad_description' => 'd', 'text_ad_display_url' => 'perm.example'], 'remove_text_ad'],
            'trackers' => [['aff_campaign_id' => $f['campaigns'] ?? 0], 'remove_tracker'],
            'rotators' => [['name' => 'perm-rot-x', 'default_url' => 'https://perm-default.example/'], 'remove_rotator'],
        ];
    }

    public static function setUpBeforeClass(): void
    {
        self::$base = rtrim((string) (getenv('P202_BASE') ?: 'http://localhost:8000'), '/');
        self::$superKey = (string) getenv('P202_API_KEY');
        if (self::$superKey === '') {
            self::$setupError = "Set P202_API_KEY to the Super user's REST key (the installer's).";
            return;
        }
        $run = 'perm' . bin2hex(random_bytes(3));
        foreach (['viewer' => 5, 'manager' => 3] as $who => $role) {
            [$status, $body] = self::call(self::$superKey, 'POST', '/users', [
                'user_name' => "$run$who", 'user_email' => "$run$who@example.com", 'user_pass' => 'pass-' . $run . $who,
            ]);
            $id = (int) ($body['data']['user_id'] ?? 0);
            if ($status !== 201 || $id <= 1) {
                self::$setupError = "Creating the $who answered $status: " . json_encode($body);
                return;
            }
            self::call(self::$superKey, 'POST', "/users/$id/roles", ['role_id' => $role]);
            [$status, $body] = self::call(self::$superKey, 'POST', "/users/$id/api-keys", []);
            $key = (string) ($body['data']['api_key'] ?? '');
            if ($status !== 201 || $key === '') {
                self::$setupError = "Minting the $who's key answered $status: " . json_encode($body);
                return;
            }
            self::$users[$who] = ['id' => $id, 'key' => $key];
        }
        // One of each entity, made by the manager in its own account, in
        // dependency order: a Campaign manager may set up.
        foreach (array_keys(self::resources()) as $resource) {
            [$create] = self::resources()[$resource];
            $create = array_map(static fn ($v) => is_string($v) ? str_replace('-x', '-' . $run, $v) : $v, $create);
            [$status, $body] = self::call(self::$users['manager']['key'], 'POST', "/$resource", $create);
            $data = $body['data'] ?? [];
            $id = (int) ($data['id'] ?? $data[self::idField($resource)] ?? 0);
            if ($status !== 201 || $id <= 0) {
                self::$setupError = "The manager creating the $resource fixture answered $status: " . json_encode($body);
                return;
            }
            self::$fixture[$resource] = $id;
        }
        [$status, $body] = self::call(self::$users['manager']['key'], 'POST', '/rotators/' . self::$fixture['rotators'] . '/rules', [
            'rule_name' => 'perm-rule', 'criteria' => [['type' => 'ip', 'statement' => 'is', 'value' => '192.0.2.1']],
            'redirects' => [['redirect_url' => 'https://perm-rule.example/']],
        ]);
        self::$ruleId = (int) ($body['data']['rules'][0]['id'] ?? 0);
        if (self::$ruleId <= 0) {
            self::$setupError = "Creating the rotator rule answered $status: " . json_encode($body);
        }
    }

    private static function idField(string $resource): string
    {
        return [
            'aff-networks' => 'aff_network_id', 'campaigns' => 'aff_campaign_id', 'ppc-networks' => 'ppc_network_id',
            'ppc-accounts' => 'ppc_account_id', 'landing-pages' => 'landing_page_id', 'text-ads' => 'text_ad_id',
            'trackers' => 'tracker_id', 'rotators' => 'id',
        ][$resource];
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$superKey === '') {
            return;
        }
        // The fixtures are the manager's, so only the manager's key sees
        // them, and its role may not remove them: make it an Admin first.
        // (Deleting a user does not remove its Setup rows.)
        if (isset(self::$users['manager'])) {
            self::call(self::$superKey, 'POST', '/users/' . self::$users['manager']['id'] . '/roles', ['role_id' => 2]);
            foreach (array_reverse(self::$fixture, true) as $resource => $id) {
                self::call(self::$users['manager']['key'], 'DELETE', "/$resource/$id");
            }
        }
        foreach (self::$users as $user) {
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

    private function assertRefused(string $who, string $method, string $path, ?array $body, string $permission): void
    {
        [$status, $response] = self::call(self::$users[$who]['key'], $method, $path, $body);
        $this->assertSame(403, $status, "$who: $method $path should be refused: " . json_encode($response));
        $this->assertStringContainsString("'$permission' permission", (string) ($response['message'] ?? ''), "$who: $method $path refused for the wrong reason");
    }

    public function testAViewerNeitherReadsNorChangesSetup(): void
    {
        foreach (self::resources() as $resource => [$create, $remove]) {
            $id = self::$fixture[$resource];
            $this->assertRefused('viewer', 'GET', "/$resource", null, 'access_to_setup_section');
            $this->assertRefused('viewer', 'GET', "/$resource/$id", null, 'access_to_setup_section');
            [$status] = self::call(self::$users['manager']['key'], 'GET', "/$resource");
            $this->assertSame(200, $status, "manager: GET /$resource reads");
            $this->assertRefused('viewer', 'POST', "/$resource", $create, 'access_to_setup_section');
            $this->assertRefused('viewer', 'POST', "/$resource?staged=1", $create, 'access_to_setup_section');
            $this->assertRefused('viewer', 'PUT', "/$resource/$id", $resource === 'rotators' ? ['name' => 'renamed'] : array_slice($create, 0, 1, true), 'access_to_setup_section');
            $this->assertRefused('viewer', 'DELETE', "/$resource/$id", null, $remove);
            $this->assertRefused('viewer', 'DELETE', "/$resource/$id?dry_run=1", null, $remove);
            if ($resource !== 'rotators') {
                $this->assertRefused('viewer', 'POST', "/$resource/bulk-upsert", ['rows' => [$create]], 'access_to_setup_section');
            }
        }
        $rotator = self::$fixture['rotators'];
        $this->assertRefused('viewer', 'GET', "/rotators/$rotator/rules", null, 'access_to_setup_section');
        $this->assertRefused('viewer', 'GET', '/trackers/' . self::$fixture['trackers'] . '/url', null, 'access_to_setup_section');
        $this->assertRefused('viewer', 'GET', '/apps', null, 'access_to_setup_section');
        $this->assertRefused('viewer', 'POST', "/rotators/$rotator/rules", ['rule_name' => 'x'], 'access_to_setup_section');
        $this->assertRefused('viewer', 'DELETE', "/rotators/$rotator/rules/" . self::$ruleId, null, 'remove_rotator_rule');
        foreach (self::$fixture as $resource => $id) {
            [$status] = self::call(self::$users['manager']['key'], 'GET', "/$resource/$id");
            $this->assertSame(200, $status, "the $resource fixture is still there");
        }
    }

    public function testAManagerSetsUpButDoesNotRemove(): void
    {
        [$status, $body] = self::call(self::$users['manager']['key'], 'PUT', '/campaigns/' . self::$fixture['campaigns'], ['aff_campaign_payout' => 2]);
        $this->assertSame(200, $status, 'a Campaign manager changes a campaign: ' . json_encode($body));
        foreach (self::resources() as $resource => [, $remove]) {
            $id = self::$fixture[$resource];
            $this->assertRefused('manager', 'DELETE', "/$resource/$id", null, $remove);
            $this->assertRefused('manager', 'DELETE', "/$resource/$id?dry_run=1", null, $remove);
            [$status] = self::call(self::$users['manager']['key'], 'GET', "/$resource/$id");
            $this->assertSame(200, $status, "the refused delete left the $resource in place");
        }
        $this->assertRefused('manager', 'DELETE', '/rotators/' . self::$fixture['rotators'] . '/rules/' . self::$ruleId, null, 'remove_rotator_rule');
    }

    /**
     * The viewer (role 5: no access_to_campaign_data) reads every report with
     * the absolute figures null and `masked: true`, ratios kept; the manager
     * (role 3, which has it) reads them in full.
     */
    public function testReportsAreMaskedForARoleWithoutCampaignData(): void
    {
        // Rotator stats are masked the same way (RolePermissionTest); no
        // seeded role both owns a rotator and lacks access_to_campaign_data,
        // and a key reads only its own account's rotators.
        $paths = ['/reports/summary', '/reports/breakdown', '/reports/timeseries', '/reports/daypart',
            '/reports/weekpart', '/reports/groups?by=campaign', '/clicks', '/conversions'];
        foreach ($paths as $path) {
            [$status, $body] = self::call(self::$users['viewer']['key'], 'GET', $path);
            $this->assertSame(200, $status, "viewer: GET $path is a page every role opens: " . json_encode($body));
            $this->assertTrue($body['masked'] ?? false, "viewer: GET $path says it is masked");
            [$status, $body] = self::call(self::$users['manager']['key'], 'GET', $path);
            $this->assertSame(200, $status, "manager: GET $path");
            $this->assertArrayNotHasKey('masked', $body, "manager: GET $path is not masked");
        }
        [, $summary] = self::call(self::$users['viewer']['key'], 'GET', '/reports/summary');
        foreach (['total_clicks', 'total_click_throughs', 'total_leads', 'total_income', 'total_cost', 'total_net'] as $hidden) {
            $this->assertArrayHasKey($hidden, $summary['data']);
            $this->assertNull($summary['data'][$hidden], "viewer: $hidden is hidden");
        }
        foreach (['epc', 'avg_cpc', 'conv_rate', 'roi', 'cpa'] as $ratio) {
            $this->assertIsNumeric($summary['data'][$ratio], "viewer: $ratio stays, as on the pages");
        }
        [, $summary] = self::call(self::$users['manager']['key'], 'GET', '/reports/summary');
        $this->assertIsInt($summary['data']['total_clicks'], 'manager: a count');
    }

    /**
     * Goals are Setup's: Setup › Campaigns edits a campaign's, Setup › Mobile
     * Apps an app's. Every goal route asked for nothing, so a Campaign
     * viewer's key created a paid goal, set its campaign's payout for it,
     * re-evaluated it and read the payouts back (measured live). The viewer
     * is refused every one now, the computations included; the manager
     * (Setup, no manage_attribution_models) keeps a campaign's goals and is
     * refused the account's, as Mobile Apps refuses its writes.
     */
    public function testGoalsAreSetupsAndAnAppsGoalsAskForTheModelsPermission(): void
    {
        $campaign = self::$fixture['campaigns'];
        $manager = self::$users['manager']['key'];
        $paid = ['scope' => 'campaign', 'scope_id' => $campaign, 'payable' => true, 'payout' => '1.5',
            'definition' => ['name' => 'perm-goal-' . bin2hex(random_bytes(3)), 'trigger' => ['event' => 'purchase']]];
        [$status, $body] = self::call($manager, 'POST', '/goals', $paid);
        $goal = (int) ($body['data']['goal_id'] ?? 0);
        $this->assertSame(201, $status, 'manager: a campaign goal is Setup › Campaigns\': ' . json_encode($body));
        $this->assertSame('1.50000', $body['data']['campaigns'][0]['payout'] ?? null, 'manager (access_to_campaign_data): the payout, unmasked');
        try {
            foreach ([
                ['GET', '/goals', null], ['GET', "/goals/$goal", null], ['GET', "/goals/$goal/versions", null],
                ['GET', "/goals/$goal/versions/1", null], ['GET', "/goals/$goal/outcomes", null], ['GET', "/goals/$goal/campaigns", null],
                ['GET', "/goals/$goal/reevaluation", null],
                ['POST', '/goals', $paid], ['POST', '/goals?staged=1', $paid],
                ['PUT', "/goals/$goal", ['definition' => $paid['definition']]],
                ['PUT', "/goals/$goal/campaigns/$campaign", ['payout' => '99.99']],
                ['POST', "/goals/$goal/reevaluation", []],
                ['DELETE', "/goals/$goal/campaigns/$campaign", null], ['DELETE', "/goals/$goal", null], ['DELETE', "/goals/$goal?dry_run=1", null],
                ['POST', '/goals/validate', ['definition' => $paid['definition']]],
                ['POST', '/goals/evaluate', ['goals' => [], 'subject' => [], 'events' => []]],
            ] as [$method, $path, $body]) {
                $this->assertRefused('viewer', $method, $path, $body, 'access_to_setup_section');
            }
            [, $body] = self::call($manager, 'GET', "/goals/$goal/campaigns");
            $this->assertSame('1.50000', $body['data'][0]['payout'] ?? null, "the viewer's refused PUT left the payout as it was");

            $account = ['scope' => 'account', 'scope_id' => 0, 'definition' => ['name' => 'perm-app-goal', 'trigger' => ['event' => 'level_up']]];
            $this->assertRefused('manager', 'POST', '/goals', $account, 'manage_attribution_models');
            [$status, $body] = self::call($manager, 'POST', "/goals/$goal/reevaluation", []);
            $this->assertSame(200, $status, "manager: re-evaluating a campaign's goal: " . json_encode($body));
        } finally {
            [$status, $body] = self::call($manager, 'DELETE', "/goals/$goal");
            $this->assertSame(204, $status, 'manager: archiving a campaign goal: ' . json_encode($body));
        }
    }

    /**
     * Recording a conversion is Update › Subids' (access_to_update_section);
     * removing one is Update › Delete Subids' (delete_individual_subids as
     * well). The viewer has neither; the manager has the first only.
     */
    public function testConversionWritesAskForTheUpdatePagesPermissions(): void
    {
        $this->assertRefused('viewer', 'POST', '/conversions', ['click_id' => 1, 'payout' => 1], 'access_to_update_section');
        $this->assertRefused('viewer', 'POST', '/conversions?staged=1', ['click_id' => 1, 'payout' => 1], 'access_to_update_section');
        $this->assertRefused('viewer', 'DELETE', '/conversions/1', null, 'delete_individual_subids');
        $this->assertRefused('manager', 'DELETE', '/conversions/1', null, 'delete_individual_subids');
        $this->assertRefused('manager', 'DELETE', '/conversions/1?dry_run=1', null, 'delete_individual_subids');
        [$status, $body] = self::call(self::$users['manager']['key'], 'POST', '/conversions', ['click_id' => 999999999, 'payout' => 1]);
        $this->assertNotSame(403, $status, 'manager: recording a conversion passes the role check: ' . json_encode($body));
    }
}
