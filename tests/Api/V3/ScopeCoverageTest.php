<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Auth;
use Tests\TestCase;

/**
 * Scope enforcement is driven by a list (Auth::KNOWN_SCOPE_AREAS) maintained
 * separately from the routes themselves, so the two can drift. The dispatcher
 * now refuses an unmapped route rather than serving it unchecked; this test
 * catches the drift at test time instead, by reading the routes the router
 * actually registers and asserting each one maps to an area or is a
 * deliberate exemption.
 */
final class ScopeCoverageTest extends TestCase
{
    /** @return string[] top-level path families registered in api/v3/index.php */
    private function registeredFamilies(): array
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/api/v3/index.php');
        $families = [];

        preg_match_all(
            '/\$(?:router|previewRouter|stageableRouter)->(?:group|get|post|put|patch|delete)\(\s*[\'"](\/[^\'"]*)/',
            $src,
            $matches
        );
        foreach ($matches[1] as $path) {
            $segment = explode('/', ltrim($path, '/'))[0];
            if ($segment !== '' && !str_contains($segment, '$')) {
                $families[$segment] = true;
            }
        }

        // The CRUD families are registered through a loop over $crudMap.
        if (preg_match('/\$crudMap = \[(.*?)\];/s', $src, $map) === 1) {
            preg_match_all("/'([a-z\-]+)'\s*=>/", $map[1], $keys);
            foreach ($keys[1] as $key) {
                $families[$key] = true;
            }
        }

        return array_keys($families);
    }

    public function testTheRouterRegistersFamiliesAtAll(): void
    {
        // Guards the parser itself: a silent zero would make the test below
        // pass while asserting nothing.
        $this->assertGreaterThan(10, count($this->registeredFamilies()));
    }

    public function testEveryRegisteredRouteFamilyMapsToAScopeAreaOrIsExempt(): void
    {
        $unmapped = [];
        foreach ($this->registeredFamilies() as $family) {
            $path = '/' . $family;
            if (Auth::isScopeExemptPath($path)) {
                continue;
            }
            if (Auth::scopeAreaForPath($path) === null) {
                $unmapped[] = $family;
            }
        }

        $this->assertSame([], $unmapped, sprintf(
            "These route families have no scope area, so the dispatcher will refuse them:\n  %s\n"
            . 'Add each to Auth::KNOWN_SCOPE_AREAS (or to isScopeExemptPath if it is deliberately unscoped).',
            implode(', ', $unmapped)
        ));
    }

    public function testExemptionsAreLimitedToDiscoveryAndHealth(): void
    {
        $this->assertTrue(Auth::isScopeExemptPath('/capabilities'));
        $this->assertTrue(Auth::isScopeExemptPath('/versions'));
        $this->assertTrue(Auth::isScopeExemptPath('/'));
        $this->assertTrue(Auth::isScopeExemptPath('/system/health'));

        // Everything else under /system is admin data and stays scoped.
        $this->assertFalse(Auth::isScopeExemptPath('/system/metrics'));
        $this->assertFalse(Auth::isScopeExemptPath('/campaigns'));
        $this->assertSame('system', Auth::scopeAreaForPath('/system/metrics'));
    }

    public function testTheAuditAndChangesFeedsAreScopedAsSync(): void
    {
        $this->assertSame('sync', Auth::scopeAreaForPath('/audit/sync-jobs'));
        $this->assertSame('sync', Auth::scopeAreaForPath('/changes/campaigns'));
    }
}
