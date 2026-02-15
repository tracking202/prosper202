<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Router;
use Tests\TestCase;

/**
 * Tests for the API v3 Router.
 */
final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new Router();
    }

    // ─── Route registration and matching ────────────────────────────

    public function testGetRouteMatchesGetRequest(): void
    {
        $this->router->get('/campaigns', fn() => 'list');

        $result = $this->router->match('GET', '/campaigns');

        $this->assertNotNull($result);
        $this->assertSame('list', ($result['handler'])());
        $this->assertEmpty($result['pathParams']);
    }

    public function testPostRouteMatchesPostRequest(): void
    {
        $this->router->post('/campaigns', fn() => 'create');

        $result = $this->router->match('POST', '/campaigns');

        $this->assertNotNull($result);
        $this->assertSame('create', ($result['handler'])());
    }

    public function testPutRouteMatchesPutRequest(): void
    {
        $this->router->put('/campaigns/{id}', fn() => 'update');

        $result = $this->router->match('PUT', '/campaigns/5');

        $this->assertNotNull($result);
        $this->assertSame('update', ($result['handler'])());
    }

    public function testDeleteRouteMatchesDeleteRequest(): void
    {
        $this->router->delete('/campaigns/{id}', fn() => 'delete');

        $result = $this->router->match('DELETE', '/campaigns/5');

        $this->assertNotNull($result);
        $this->assertSame('delete', ($result['handler'])());
    }

    public function testGetRouteDoesNotMatchPostRequest(): void
    {
        $this->router->get('/campaigns', fn() => 'list');

        $result = $this->router->match('POST', '/campaigns');

        $this->assertNull($result);
    }

    // ─── Path parameter extraction ──────────────────────────────────

    public function testSinglePathParameterExtraction(): void
    {
        $this->router->get('/users/{id}', fn() => 'get');

        $result = $this->router->match('GET', '/users/42');

        $this->assertNotNull($result);
        $this->assertSame('42', $result['pathParams']['id']);
    }

    public function testMultiplePathParameterExtraction(): void
    {
        $this->router->get('/rotators/{rotator_id}/rules/{rule_id}', fn() => 'getRule');

        $result = $this->router->match('GET', '/rotators/10/rules/25');

        $this->assertNotNull($result);
        $this->assertSame('10', $result['pathParams']['rotator_id']);
        $this->assertSame('25', $result['pathParams']['rule_id']);
    }

    public function testPathParameterDoesNotMatchSlashes(): void
    {
        $this->router->get('/users/{id}', fn() => 'get');

        $result = $this->router->match('GET', '/users/42/extra');

        $this->assertNull($result);
    }

    // ─── No match returns null ──────────────────────────────────────

    public function testNoMatchReturnsNull(): void
    {
        $this->router->get('/campaigns', fn() => 'list');

        $result = $this->router->match('GET', '/nonexistent');

        $this->assertNull($result);
    }

    public function testEmptyRouterReturnsNull(): void
    {
        $result = $this->router->match('GET', '/anything');

        $this->assertNull($result);
    }

    // ─── PUT and PATCH interchangeability ───────────────────────────

    public function testPutRouteMatchesPatchRequest(): void
    {
        $this->router->put('/campaigns/{id}', fn() => 'update');

        $result = $this->router->match('PATCH', '/campaigns/5');

        $this->assertNotNull($result);
        $this->assertSame('update', ($result['handler'])());
    }

    public function testPatchRouteMatchesPutRequest(): void
    {
        $this->router->patch('/campaigns/{id}', fn() => 'update');

        $result = $this->router->match('PUT', '/campaigns/5');

        $this->assertNotNull($result);
        $this->assertSame('update', ($result['handler'])());
    }

    public function testPutDoesNotMatchGetRequest(): void
    {
        $this->router->put('/campaigns/{id}', fn() => 'update');

        $result = $this->router->match('GET', '/campaigns/5');

        $this->assertNull($result);
    }

    // ─── Group prefixing ────────────────────────────────────────────

    public function testGroupPrefixIsApplied(): void
    {
        $this->router->group('/api/v3', function (Router $r) {
            $r->get('/campaigns', fn() => 'list');
        });

        $result = $this->router->match('GET', '/api/v3/campaigns');

        $this->assertNotNull($result);
        $this->assertSame('list', ($result['handler'])());
    }

    public function testGroupPrefixDoesNotMatchWithoutPrefix(): void
    {
        $this->router->group('/api/v3', function (Router $r) {
            $r->get('/campaigns', fn() => 'list');
        });

        $result = $this->router->match('GET', '/campaigns');

        $this->assertNull($result);
    }

    public function testGroupPrefixWithPathParams(): void
    {
        $this->router->group('/api/v3', function (Router $r) {
            $r->get('/campaigns/{id}', fn() => 'get');
        });

        $result = $this->router->match('GET', '/api/v3/campaigns/99');

        $this->assertNotNull($result);
        $this->assertSame('99', $result['pathParams']['id']);
    }

    // ─── Group middleware stacking ──────────────────────────────────

    public function testGroupMiddlewareIsAttachedToRoutes(): void
    {
        $mw1 = fn() => 'auth';

        $this->router->group('/api', function (Router $r) {
            $r->get('/test', fn() => 'handler');
        }, [$mw1]);

        $result = $this->router->match('GET', '/api/test');

        $this->assertNotNull($result);
        $this->assertCount(1, $result['middleware']);
        $this->assertSame($mw1, $result['middleware'][0]);
    }

    public function testRoutesOutsideGroupHaveNoMiddleware(): void
    {
        $mw1 = fn() => 'auth';

        $this->router->group('/api', function (Router $r) {
            $r->get('/inside', fn() => 'handler');
        }, [$mw1]);

        $this->router->get('/outside', fn() => 'handler');

        $insideResult = $this->router->match('GET', '/api/inside');
        $outsideResult = $this->router->match('GET', '/outside');

        $this->assertCount(1, $insideResult['middleware']);
        $this->assertEmpty($outsideResult['middleware']);
    }

    // ─── Nested groups compose middleware ────────────────────────────

    public function testNestedGroupsComposeMiddleware(): void
    {
        $mwOuter = fn() => 'outer';
        $mwInner = fn() => 'inner';

        $this->router->group('/api', function (Router $r) use ($mwInner) {
            $r->group('/v3', function (Router $r2) {
                $r2->get('/campaigns', fn() => 'list');
            }, [$mwInner]);
        }, [$mwOuter]);

        $result = $this->router->match('GET', '/api/v3/campaigns');

        $this->assertNotNull($result);
        $this->assertCount(2, $result['middleware']);
        $this->assertSame($mwOuter, $result['middleware'][0]);
        $this->assertSame($mwInner, $result['middleware'][1]);
    }

    public function testNestedGroupsComposePrefix(): void
    {
        $this->router->group('/api', function (Router $r) {
            $r->group('/v3', function (Router $r2) {
                $r2->get('/users', fn() => 'list');
            });
        });

        $result = $this->router->match('GET', '/api/v3/users');

        $this->assertNotNull($result);
        $this->assertSame('list', ($result['handler'])());
    }

    public function testGroupPrefixRestoredAfterGroup(): void
    {
        $this->router->group('/api', function (Router $r) {
            $r->get('/inside', fn() => 'inside');
        });

        // Route added after group should not have the prefix
        $this->router->get('/outside', fn() => 'outside');

        $this->assertNull($this->router->match('GET', '/api/outside'));
        $this->assertNotNull($this->router->match('GET', '/outside'));
    }

    public function testGroupMiddlewareRestoredAfterGroup(): void
    {
        $mw = fn() => 'auth';

        $this->router->group('/api', function (Router $r) {
            $r->get('/inside', fn() => 'inside');
        }, [$mw]);

        $this->router->get('/outside', fn() => 'outside');

        $insideResult = $this->router->match('GET', '/api/inside');
        $outsideResult = $this->router->match('GET', '/outside');

        $this->assertCount(1, $insideResult['middleware']);
        $this->assertEmpty($outsideResult['middleware']);
    }

    // ─── Trailing slash normalization ───────────────────────────────

    public function testExactPathMatchWithoutTrailingSlash(): void
    {
        $this->router->get('/campaigns', fn() => 'list');

        // Route without trailing slash should not match path with trailing slash
        // because the regex is anchored: ^/campaigns$
        $resultExact = $this->router->match('GET', '/campaigns');
        $this->assertNotNull($resultExact);
    }

    public function testRouteWithTrailingSlashMatchesThat(): void
    {
        $this->router->get('/campaigns/', fn() => 'list');

        $result = $this->router->match('GET', '/campaigns/');
        $this->assertNotNull($result);
    }

    public function testRouteWithoutTrailingSlashDoesNotMatchTrailing(): void
    {
        $this->router->get('/campaigns', fn() => 'list');

        $result = $this->router->match('GET', '/campaigns/');
        $this->assertNull($result);
    }

    // ─── Method case insensitivity ──────────────────────────────────

    public function testMethodMatchingIsCaseInsensitive(): void
    {
        $this->router->get('/test', fn() => 'ok');

        $result = $this->router->match('get', '/test');

        $this->assertNotNull($result);
    }

    // ─── Fluent interface ───────────────────────────────────────────

    public function testAddReturnsSelfForChaining(): void
    {
        $result = $this->router->add('GET', '/a', fn() => null);
        $this->assertSame($this->router, $result);
    }

    public function testGroupReturnsSelfForChaining(): void
    {
        $result = $this->router->group('/prefix', function (Router $r) {});
        $this->assertSame($this->router, $result);
    }

    // ─── First matching route wins ──────────────────────────────────

    public function testFirstMatchingRouteWins(): void
    {
        $this->router->get('/test', fn() => 'first');
        $this->router->get('/test', fn() => 'second');

        $result = $this->router->match('GET', '/test');

        $this->assertNotNull($result);
        $this->assertSame('first', ($result['handler'])());
    }
}
