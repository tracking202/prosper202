<?php

declare(strict_types=1);

namespace Tests\Goals;

use PHPUnit\Framework\TestCase;

/**
 * Every /goals route the router registers is documented in docs/openapi.yaml,
 * and the SKAN encoding schema documents the goal it names rather than the
 * fields it no longer takes.
 */
final class GoalsOpenApiCoverageTest extends TestCase
{
    public function testEveryGoalsRouteIsDocumented(): void
    {
        $root = dirname(__DIR__, 2);
        $index = (string) file_get_contents($root . '/api/v3/index.php');
        $spec = (string) file_get_contents($root . '/docs/openapi.yaml');

        $start = strpos($index, "\$router->group('/goals'");
        self::assertNotFalse($start, 'the /goals group is not in api/v3/index.php');
        $end = strpos($index, '});', $start);
        preg_match_all("/\\\$r->(get|post|put|delete)\\('([^']*)'/", substr($index, $start, $end - $start), $m, PREG_SET_ORDER);
        self::assertGreaterThanOrEqual(15, count($m), 'the route scan read too few routes to be the group');

        foreach ($m as [, $method, $suffix]) {
            $path = '/goals' . $suffix;
            $at = strpos($spec, "\n  $path:\n");
            self::assertNotFalse($at, "$path is served but not documented");
            $next = preg_match('/\n  \/[^\n]*:\n/', $spec, $n, PREG_OFFSET_CAPTURE, $at + 1) === 1 ? $n[0][1] : strlen($spec);
            self::assertStringContainsString("\n    $method:", substr($spec, $at, $next - $at), strtoupper($method) . " $path is not documented");
        }
    }

    public function testTheEncodingSchemaNamesAGoal(): void
    {
        $spec = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.yaml');
        $at = strpos($spec, "\n    AppSkanEncodingCreate:\n");
        self::assertNotFalse($at);
        $block = substr($spec, $at, 900);
        self::assertStringContainsString('required: [goal_id]', $block);
        self::assertStringNotContainsString('event_name', $block);
    }
}
