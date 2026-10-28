<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PHPUnit\Framework\TestCase;

/**
 * The report of traffic sources another user's accounts name, its repair and
 * its split answer only behind Admin and access_to_settings, as the install
 * settings do: the report names every user's sources and users. None is
 * stageable. The repair's and the split's own checks that the caller may act
 * on each user they change are TrafficSourceOwnersIntegrationTest's and
 * TrafficSourceSplitIntegrationTest's.
 *
 * Read from api/v3/index.php through ReadsRouteRegistrations, as
 * AdministrationRoutesPermissionTest reads its routes.
 */
final class TrafficSourceOwnersRoutesTest extends TestCase
{
    use ReadsRouteRegistrations;

    /** "METHOD path" => the controller method its handler calls. */
    private const ROUTES = [
        'GET /system/traffic-source-owners' => 'report',
        'POST /system/traffic-source-owners/repair' => 'repair',
        'POST /system/traffic-source-owners/split' => 'split',
    ];

    public function testTheRoutesAnswerBehindAdminAndTheSettingsPermission(): void
    {
        $registrations = $this->registrations(self::indexSource());
        $main = $this->router($registrations, '$router');

        foreach (self::ROUTES as $operation => $method) {
            [$verb, $path] = explode(' ', $operation, 2);
            $match = $main->match($verb, $path);
            self::assertNotNull($match, "$operation is served");
            $route = $registrations[($match['handler'])()];
            self::assertSame(
                ['$auth->requireAdmin(...)', '$settingsPage'],
                $route['middleware'],
                "$operation (line {$route['line']}) runs requireAdmin and \$settingsPage, and only them"
            );
            self::assertMatchesRegularExpression('/->' . $method . '\(/', $route['handler'], "$operation: $method");
            self::assertStringContainsString(
                'TrafficSourceOwnersController',
                $route['group'],
                "$operation builds a TrafficSourceOwnersController"
            );
        }

        foreach (['repair', 'split'] as $write) {
            $route = $registrations[($main->match('POST', '/system/traffic-source-owners/' . $write)['handler'])()];
            self::assertStringContainsString(
                '->' . $write . '($payload)',
                $route['handler'],
                "the $write reads the body alone"
            );
        }

        foreach ($registrations as $route) {
            if (str_contains($route['handler'] . $route['group'], 'TrafficSourceOwnersController')) {
                self::assertArrayHasKey(
                    $route['method'] . ' ' . $route['path'],
                    self::ROUTES,
                    "line {$route['line']}: {$route['method']} {$route['path']} reaches"
                        . ' TrafficSourceOwnersController without being checked here'
                );
            }
        }
    }

    public function testNoneIsStageable(): void
    {
        $registrations = $this->registrations(self::indexSource());
        $stageable = $this->router($registrations, '$stageableRouter');
        self::assertNotNull($stageable->match('POST', '/conversions'), 'the scan reads the stageable router');
        foreach (array_keys(self::ROUTES) as $operation) {
            [$verb, $path] = explode(' ', $operation, 2);
            self::assertNull($stageable->match($verb, $path), "$operation is not stageable");
        }
    }
}
