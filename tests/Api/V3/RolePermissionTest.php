<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Support\CampaignFigures;
use PHPUnit\Framework\TestCase;
use Prosper202\Report\CampaignDataMask;

/**
 * Every route on the main router has a role decision, made where it can be
 * read: a permission (group middleware, or the handler's first statement), a
 * mask of the campaign figures a role without access_to_campaign_data may not
 * see, or a reason it needs neither, listed here. A route added without one
 * fails, naming it.
 *
 * Before this, reads asked for nothing: a Campaign viewer's key -- a role
 * whose pages hide Setup and print figures as '?' -- listed every campaign
 * with its payout, read every report's income and cost, recorded and deleted
 * conversions, and the older /system routes asked for Admin but not the
 * Settings page's own permission. UpdateRoutesPermissionTest,
 * SetupRoutesPermissionTest, AdministrationRoutesPermissionTest and
 * AppsRoutePermissionTest pin the families in detail; this one makes sure
 * nothing falls between them. SetupPermissionsInstanceTest proves the
 * behaviour over HTTP.
 */
final class RolePermissionTest extends TestCase
{
    use ReadsRouteRegistrations;

    /** Middleware that asks for a role permission (or Admin). */
    private const ROLE_MIDDLEWARE = '/requirePermission|requireAdmin|\$setupSection|\$setupRemove|\$updateSection|\$settingsPage|\$integrationsPage/';

    /** A handler whose first statement asks for one. */
    private const ROLE_FIRST_STATEMENT = '/^\$(manage|view|setup)\(\);|^\$auth->require(Permission|Admin|MayManageUser|MayChangeRoles|MayDeleteUser|SelfOrMayManageUser|SelfOrAdmin|PersonalSettingsOf)\(/';

    /** Routes that pass their answer through $campaignFigures, and the key list they mask. */
    private const MASKED = [
        'GET /clicks' => '$recordMoney',
        'GET /clicks/{id}' => '$recordMoney',
        'GET /clicks/{id}/conversions' => '$recordMoney',
        'GET /conversions' => '$recordMoney',
        'GET /conversions/{id}' => '$recordMoney',
        'GET /reports/summary' => '$reportFigures',
        'GET /reports/breakdown' => '$reportFigures',
        'GET /reports/timeseries' => '$reportFigures',
        'GET /reports/daypart' => '$reportFigures',
        'GET /reports/weekpart' => '$reportFigures',
        'GET /reports/groups' => '$reportFigures',
        'GET /rotators/{id}/stats' => '$reportFigures',
    ];

    /**
     * Routes that ask for a role permission AND mask the money a role
     * without access_to_campaign_data may not see: the goals, which are
     * Setup's (Setup › Campaigns and Setup › Mobile Apps edit them) and carry
     * payouts and outcome values. They answered every role, unmasked, and
     * let a Campaign viewer create paid goals and re-evaluate them.
     */
    private const GATED_AND_MASKED = [
        'GET /goals' => '$goalFigures',
        'POST /goals' => '$goalFigures',
        'GET /goals/{id}' => '$goalFigures',
        'PUT /goals/{id}' => '$goalFigures',
        'GET /goals/{id}/versions' => '$goalFigures',
        'GET /goals/{id}/versions/{version}' => '$goalFigures',
        'GET /goals/{id}/campaigns' => '$goalFigures',
        'PUT /goals/{id}/campaigns/{campaignId}' => '$goalFigures',
        'GET /goals/{id}/outcomes' => '$outcomeFigures',
        'GET /goals/{id}/reevaluation' => '$outcomeFigures',
        'POST /goals/{id}/reevaluation' => '$outcomeFigures',
    ];

    /** [path pattern, why no role permission] for the rest. */
    private const NO_ROLE_CHECK = [
        ['#^/ltv(/|$)#', 'Analyze › Customer LTV asks for none (tracking202/analyze/ltv.php); keys need ltv scopes'],
        ['#^/events$#', 'goal events, sent by the account\'s own sites; no page'],
        ['#^/staged-changes(/|$)#', 'a proposal; applying runs the target route, its checks included, as the applier'],
        ['#^/capabilities$#', 'what the server offers; no account data'],
        ['#^/$#', 'the API root'],
        ['#^/users/roles$#', 'the role catalogue (names), not an account\'s data'],
        ['#^/apps/\{id\}/install-token$#', 'tracking plumbing a landing page\'s server fetches (AppsRoutePermissionTest)'],
    ];

    /** @return list<array<string, mixed>> */
    private function mainRoutes(): array
    {
        $routes = array_values(array_filter(
            $this->registrations(self::indexSource()),
            static fn (array $r): bool => $r['router'] === '$router'
        ));
        self::assertGreaterThan(150, count($routes), 'the scan reads the main router');

        return $routes;
    }

    private static function hasRoleCheck(array $route, string $first): bool
    {
        return preg_match(self::ROLE_MIDDLEWARE, implode(' ', $route['middleware'])) === 1
            || preg_match(self::ROLE_FIRST_STATEMENT, ltrim($first)) === 1;
    }

    public function testEveryRouteHasARoleDecision(): void
    {
        $undecided = [];
        $seenMasked = [];
        $seenGatedMasked = [];
        foreach ($this->mainRoutes() as $route) {
            $key = $route['method'] . ' ' . $route['path'];
            if (str_contains($route['path'], '{crud}')) {
                continue; // the CRUD loop, below
            }
            $first = $this->firstStatement($route['handler']);
            if (isset(self::GATED_AND_MASKED[$key])) {
                $seenGatedMasked[] = $key;
                self::assertTrue(self::hasRoleCheck($route, $first), "$key (line {$route['line']}) asks for a role permission");
                self::assertMatchesRegularExpression(
                    '/^\s*\$campaignFigures\(.*,\s*' . preg_quote(self::GATED_AND_MASKED[$key], '/') . '\)\s*;?\s*$/s',
                    str_starts_with(ltrim($route['handler']), 'fn') ? $first : $this->returned($route['handler']),
                    "$key (line {$route['line']}) answers through \$campaignFigures(…, " . self::GATED_AND_MASKED[$key] . ')'
                );
                continue;
            }
            if (isset(self::MASKED[$key])) {
                $seenMasked[] = $key;
                self::assertMatchesRegularExpression(
                    '/^\s*\$campaignFigures\(.*,\s*' . preg_quote(self::MASKED[$key], '/') . '\)\s*;?\s*$/s',
                    str_starts_with(ltrim($route['handler']), 'fn') ? $first : $this->returned($route['handler']),
                    "$key (line {$route['line']}) answers through \$campaignFigures(…, " . self::MASKED[$key] . ')'
                );
                self::assertFalse(self::hasRoleCheck($route, $first), "$key is a page every role opens; it masks rather than refuses");
                continue;
            }
            if (self::hasRoleCheck($route, $first)) {
                continue;
            }
            $reason = null;
            foreach (self::NO_ROLE_CHECK as [$pattern, $why]) {
                if (preg_match($pattern, $route['path']) === 1) {
                    $reason = $why;
                    break;
                }
            }
            if ($reason === null) {
                $undecided[] = "$key (line {$route['line']})";
            }
        }
        self::assertSame([], $undecided, 'routes with no role permission, no mask, and no reason here');
        sort($seenMasked);
        $expected = array_keys(self::MASKED);
        sort($expected);
        self::assertSame($expected, $seenMasked, 'every masked route exists');
        sort($seenGatedMasked);
        $expected = array_keys(self::GATED_AND_MASKED);
        sort($expected);
        self::assertSame($expected, $seenGatedMasked, 'every gated and masked route exists');
    }

    /**
     * Every goal route answers behind Setup's permission, as the two pages
     * that edit goals do, and a write to a goal that is not a campaign's
     * (an app's, the account's) asks for manage_attribution_models first,
     * as Setup › Mobile Apps' writes do. What a campaign pays for a goal is
     * set on the campaign's page, so attaching and detaching ask for Setup
     * alone. The two computations write nothing and echo their own input,
     * so they are gated but not masked.
     */
    public function testGoalsAreSetupsAndAppGoalWritesAskForTheModelsPermission(): void
    {
        $routes = $this->mainRoutes();
        $goals = array_values(array_filter($routes, static fn (array $r): bool => preg_match('#^/goals(/|$)#', $r['path']) === 1));
        self::assertCount(15, $goals, 'the goal routes were read');
        $byOwner = ['POST /goals', 'PUT /goals/{id}', 'DELETE /goals/{id}', 'POST /goals/{id}/reevaluation'];
        foreach ($goals as $route) {
            $key = $route['method'] . ' ' . $route['path'];
            self::assertSame(['$setupSection'], $route['middleware'], "$key (line {$route['line']}) runs Setup's permission, and only it, as middleware");
            $first = $this->firstStatement($route['handler']);
            if (in_array($key, $byOwner, true)) {
                $expected = $key === 'POST /goals'
                    ? "\$appGoalWrite(is_string(\$payload['scope']??null)?\$payload['scope']:null);"
                    : '$appGoalWrite($crud($cls)->scopeOf(PathId::of($ctx)));';
                self::assertSame($expected, $first, "$key (line {$route['line']}) asks the goal's owner's permission before anything");
            } else {
                self::assertStringNotContainsString('$appGoalWrite', $route['handler'], "$key (line {$route['line']})");
            }
        }

        $src = self::indexSource();
        self::assertSame(1, preg_match('/\$appGoalWrite = static function \(\?string \$scope\) use \(\$auth, \$db\): void \{\s*if \(\$scope !== null && \$scope !== \'campaign\'\) \{\s*\$auth->requirePermission\(\$db, \'manage_attribution_models\'\);\s*\}\s*\};/', $src), '$appGoalWrite asks for manage_attribution_models of every owner but a campaign');
        self::assertSame(1, substr_count($src, '$appGoalWrite = '), '$appGoalWrite is bound once');
    }

    /**
     * The CRUD loop registers each resource's reads, writes and delete; a
     * Setup resource's reads and writes ask for access_to_setup_section and
     * its delete for its remove_* permission first. The one resource without
     * a Setup page (forecast events) asks for none.
     */
    public function testTheCrudLoopGatesSetupResourcesReadsIncluded(): void
    {
        $src = self::indexSource();
        $start = strpos($src, 'foreach ($crudMap as $resource => $class) {');
        self::assertNotFalse($start, 'the CRUD loop');
        $loop = substr($src, $start, (int) strpos($src, "\n        }\n", $start) - $start);
        self::assertSame(3, substr_count($loop, '$router->group("/$resource"'), 'reads, writes, delete');
        self::assertSame(2, substr_count($loop, '}, isset($setupRemove[$resource]) ? [$setupSection] : []);'), 'reads and writes ask for Setup');
        self::assertSame(1, substr_count($loop, '}, isset($setupRemove[$resource]) ? [$setupRemove[$resource], $setupSection] : []);'), 'the delete asks for remove_* first');
        $readsAt = strpos($loop, "\$r->get('',");
        $firstGate = strpos($loop, '? [$setupSection] : []);');
        self::assertNotFalse($readsAt);
        self::assertLessThan($firstGate, $readsAt, 'the reads are in the first gated group');

        preg_match('/\$crudMap = \[(.*?)\];/s', $src, $map);
        preg_match_all("/'([a-z\-]+)'\s*=>/", $map[1] ?? '', $crud);
        preg_match('/\$setupRemove = \[(.*?)\n        \];/s', $src, $remove);
        preg_match_all("/'([a-z\-]+)'\s*=>/", $remove[1] ?? '', $setup);
        self::assertSame(['forecast-events'], array_values(array_diff($crud[1], $setup[1])), 'only forecast events (no page) go ungated');
    }

    /** The permissions this change added, pinned to the routes that answer. */
    public function testThePagesPermissionsOnReadsConversionsAndSystem(): void
    {
        $routes = $this->mainRoutes();
        $main = $this->router($routes, '$router');
        $expect = [
            ['GET', '/rotators', ['$setupSection']],
            ['GET', '/rotators/5', ['$setupSection']],
            ['GET', '/rotators/5/rules', ['$setupSection']],
            ['GET', '/trackers/5/url', ['$setupSection']],
            ['POST', '/conversions', ['$updateSection']],
            ['DELETE', '/conversions/5', ["staticfunction()use(\$auth,\$db):void{\$auth->requirePermission(\$db,'delete_individual_subids');}", '$updateSection']],
        ];
        foreach (['version', 'db-stats', 'cron', 'errors', 'dataengine', 'metrics'] as $page) {
            $expect[] = ['GET', "/system/$page", ['$auth->requireAdmin(...)', '$settingsPage']];
        }
        foreach ($expect as [$method, $path, $middleware]) {
            $match = $main->match($method, $path);
            self::assertNotNull($match, "$method $path is served");
            $route = $routes[($match['handler'])()];
            self::assertSame($middleware, $route['middleware'], "$method $path (line {$route['line']})");
        }
    }

    /** The mask hides what the pages hide: CampaignDataMask's metrics, as the reports name them. */
    public function testTheMaskHidesThePagesFiguresAndKeepsTheRatios(): void
    {
        $asNamed = ['clicks' => 'total_clicks', 'click_out' => 'total_click_throughs', 'leads' => 'total_leads',
            'income' => 'total_income', 'cost' => 'total_cost', 'net' => 'total_net'];
        self::assertSame(array_keys($asNamed), CampaignDataMask::METRICS);
        self::assertSame(array_values($asNamed), CampaignFigures::REPORT);
        self::assertSame('access_to_campaign_data', CampaignFigures::permission());

        $report = ['data' => [['id' => 3, 'name' => 'A', 'total_clicks' => 4, 'total_income' => 8.6, 'epc' => 2.15,
            'children' => [['total_cost' => 1.5, 'roi' => 10.0]]]], 'totals' => ['total_net' => 7.1, 'cpa' => 0.5]];
        $masked = CampaignFigures::mask($report, CampaignFigures::REPORT);
        self::assertTrue($masked['masked']);
        self::assertSame([null, null, 2.15], [$masked['data'][0]['total_clicks'], $masked['data'][0]['total_income'], $masked['data'][0]['epc']]);
        self::assertSame([null, 10.0], [$masked['data'][0]['children'][0]['total_cost'], $masked['data'][0]['children'][0]['roi']]);
        self::assertSame([null, 0.5], [$masked['totals']['total_net'], $masked['totals']['cpa']]);
        self::assertSame(['id' => 3, 'name' => 'A'], array_intersect_key($masked['data'][0], ['id' => 1, 'name' => 1]));

        $click = CampaignFigures::mask(['data' => ['click_id' => 9, 'click_cpc' => '0.5', 'click_payout' => '2', 'click_lead' => 1]], CampaignFigures::RECORD);
        self::assertSame(['click_id' => 9, 'click_cpc' => null, 'click_payout' => null, 'click_lead' => 1], $click['data']);
    }

    /**
     * A goal's mask hides its money and keeps its definition's conditions:
     * `value` is a predicate's compared value inside a definition, so the
     * definition-carrying answers mask `payout` and `amount` only, and the
     * outcome answers (which carry no definition) mask `value` as well.
     */
    public function testTheGoalMasksHideMoneyAndKeepConditions(): void
    {
        $goal = ['data' => [
            'goal_id' => 2,
            'definition' => ['trigger' => ['event' => 'purchase', 'where' => [['prop' => 'plan', 'op' => 'eq', 'value' => 'pro']]],
                'value' => ['type' => 'fixed', 'amount' => '3.25']],
            'campaigns' => [['campaign_id' => 3, 'payout' => '2.00000', 'payout_mode' => 'replace']],
        ]];
        $masked = CampaignFigures::mask($goal, CampaignFigures::GOAL);
        self::assertTrue($masked['masked']);
        self::assertNull($masked['data']['definition']['value']['amount'], "the goal's fixed value");
        self::assertSame('fixed', $masked['data']['definition']['value']['type']);
        self::assertSame('pro', $masked['data']['definition']['trigger']['where'][0]['value'], "a condition's value is not money");
        self::assertNull($masked['data']['campaigns'][0]['payout'], "a campaign's payout for it");
        self::assertSame('replace', $masked['data']['campaigns'][0]['payout_mode']);

        $outcomes = CampaignFigures::mask(['data' => [['outcome_id' => 1, 'value' => '4.99', 'value_source' => 'event']]], CampaignFigures::GOAL_OUTCOME);
        self::assertSame([null, 'event'], [$outcomes['data'][0]['value'], $outcomes['data'][0]['value_source']]);
    }

    /** The expression a closure handler returns (its body's last return). */
    private function returned(string $handler): string
    {
        // The scan's handler text has its whitespace removed: `return$x`.
        $at = strrpos($handler, 'return');
        self::assertNotFalse($at, 'the handler returns');
        $end = strrpos($handler, ';');
        self::assertNotFalse($end);

        return substr($handler, $at + 6, $end - $at - 6);
    }
}
