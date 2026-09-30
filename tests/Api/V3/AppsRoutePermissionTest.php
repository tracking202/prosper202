<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PHPUnit\Framework\TestCase;

/**
 * The /apps routes carry the same role permissions as the Mobile Apps pages
 * (plan §7.1): every write asks for manage_attribution_models, the report and
 * the rows behind it ask for view_attribution_reports, and the reads the Setup
 * page shows without a permission ask for none. A route added to the group
 * without a decision fails here, because every route must appear in exactly
 * one of the three lists below.
 *
 * Each handler is read from its own registration to the next, and the check
 * must be the handler's first statement, so a check after the work, or in a
 * sibling route, does not count (CLAUDE.md #21, #22).
 */
final class AppsRoutePermissionTest extends TestCase
{
    private const MANAGE = [
        'post /skan-encodings', 'put /skan-encodings/{id}', 'delete /skan-encodings/{id}',
        'put /{id}/integrity-credential', 'delete /{id}/integrity-credential',
        'post ', 'put /{id}', 'delete /{id}', 'post /{id}/app-token/rotate',
    ];

    private const VIEW = [
        'get /postbacks', 'get /postbacks/{id}', 'get /report', 'post /verify', 'get /notifications',
        'get /{id}/installs', 'get /{id}/installs/{uuid}',
    ];

    /** Reads the Setup page shows to anyone who can open Setup, and the tracking plumbing. */
    private const NONE = [
        'get /skan-encodings', 'get /skan-encodings/{id}', 'get /{id}/install-token', 'get /{id}/store-link',
        'get /{id}/integrity', 'get ', 'get /{id}',
    ];

    public function testEveryAppsRouteMakesItsPermissionCheckFirst(): void
    {
        $routes = $this->appsRoutes();
        $this->assertNotEmpty($routes, 'the /apps group was found');

        $declared = array_merge(self::MANAGE, self::VIEW, self::NONE);
        $this->assertSame(
            [],
            array_values(array_diff(array_keys($routes), $declared)),
            'every /apps route has a permission decision in this test'
        );
        $this->assertSame([], array_values(array_diff($declared, array_keys($routes))), 'every listed route exists');

        foreach ($routes as $route => $handler) {
            $first = $this->firstStatement($handler);
            if (in_array($route, self::MANAGE, true)) {
                $this->assertSame('$manage();', $first, "$route checks manage_attribution_models first");
            } elseif (in_array($route, self::VIEW, true)) {
                $this->assertSame('$view();', $first, "$route checks view_attribution_reports first");
            } else {
                $this->assertStringNotContainsString('requirePermission', $handler, "$route takes no role check");
            }
        }

        $group = $this->groupSource();
        $checks = ['manage' => 'manage_attribution_models', 'view' => 'view_attribution_reports'];
        foreach ($checks as $var => $permission) {
            $this->assertMatchesRegularExpression(
                '/\$' . $var . ' = static function \(\) use \(\$auth, \$db\): void \{\s*'
                    . '\$auth->requirePermission\(\$db, \'' . $permission . '\'\);\s*\};/',
                $group,
                "\$$var asks for $permission"
            );
        }
        $this->assertSame(1, substr_count($group, '$manage = '), '$manage is assigned once');
        $this->assertSame(1, substr_count($group, '$view = '), '$view is assigned once');
    }

    public function testTheAppsDeletePreviewsRepeatTheManageCheck(): void
    {
        $src = $this->indexSource();
        foreach (['/apps/skan-encodings/{id}', '/apps/{id}'] as $path) {
            $at = strpos($src, "\$previewRouter->delete('$path'");
            $this->assertNotFalse($at, "the $path preview exists");
            $next = strpos($src, '$previewRouter->', $at + 1);
            $body = substr($src, $at, ($next === false ? strlen($src) : $next) - $at);
            $this->assertMatchesRegularExpression(
                '/\{\s*\$auth->requirePermission\(\$db, \'manage_attribution_models\'\);/',
                $body,
                "the $path preview checks manage_attribution_models first"
            );
        }
    }

    private function indexSource(): string
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/api/v3/index.php');
        $this->assertIsString($src);
        return $src;
    }

    private function groupSource(): string
    {
        $src = $this->indexSource();
        $start = strpos($src, "\$router->group('/apps', function (Router \$r)");
        $this->assertNotFalse($start, 'the /apps group opens');
        $end = strpos($src, "\n        });\n", $start);
        $this->assertNotFalse($end, 'the /apps group closes');
        return substr($src, $start, $end - $start);
    }

    /** @return array<string, string> "method path" => the handler's source */
    private function appsRoutes(): array
    {
        $group = $this->groupSource();
        preg_match_all("/\\\$r->(get|post|put|delete)\\('([^']*)',/", $group, $m, PREG_OFFSET_CAPTURE);
        $routes = [];
        $count = count($m[0]);
        for ($i = 0; $i < $count; $i++) {
            $from = $m[0][$i][1] + strlen($m[0][$i][0]);
            $to = $i + 1 < $count ? $m[0][$i + 1][1] : strlen($group);
            $key = $m[1][$i][0] . ' ' . $m[2][$i][0];
            $this->assertArrayNotHasKey($key, $routes, "$key is registered once");
            $routes[$key] = substr($group, $from, $to - $from);
        }
        return $routes;
    }

    private function firstStatement(string $handler): string
    {
        if (!preg_match('/^\s*function\s*\([^)]*\)\s*use\s*\([^)]*\)\s*\{\s*([^;]*;)/', $handler, $m)) {
            return '';
        }
        return trim($m[1]);
    }
}
