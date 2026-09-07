<?php

declare(strict_types=1);

namespace Tests\Skan;

use Api\V3\Controllers\StagedChangesController;
use PHPUnit\Framework\TestCase;

/**
 * The schema token is a bearer capability: whoever presents it is served the
 * app's encode schema, and it lives for years inside shipped app binaries.
 * These tests pin every place a token could leak out of the two intended
 * channels (the owner's scoped GET/POST responses, and the
 * X-P202-Schema-Token request header) — each was a real finding, and each
 * regresses silently because the happy path keeps working.
 */
final class SkanTokenHygieneTest extends TestCase
{
    private static function routerSource(): string
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/api/v3/index.php');
        self::assertIsString($src);
        return $src;
    }

    public function testAppCreateIsNotWrappedInIdempotencyReplay(): void
    {
        // An $idempotent-wrapped create persists its full response — schema
        // token included — as a replayable record in the server-state store,
        // where a later Idempotency-Key replay (or anyone reading the state
        // directory) recovers it. Same policy as API-key creation; retry
        // safety comes from the UNIQUE app_id answering 409 instead.
        $src = self::routerSource();
        self::assertSame(
            1,
            preg_match("/\\\$r->post\\('\\/apps',\\s*(.+)\\);/", $src, $m),
            'the /skan/apps POST route registration must be present'
        );
        self::assertStringNotContainsString('$idempotent(', $m[1]);
    }

    public function testPublicSchemaBranchAcceptsTheTokenOnlyAsAHeader(): void
    {
        // A token in a GET query string lands in access logs, proxies, and
        // browser history. Extract the pre-auth /skan/schema branch and
        // assert it reads the header and nothing request-parameter shaped.
        $src = self::routerSource();
        $start = strpos($src, "\$path === '/skan/schema'");
        self::assertIsInt($start, 'the pre-auth schema branch must exist');
        $branch = substr($src, $start, strpos($src, 'Auth::fromRequest', $start) - $start);

        self::assertStringContainsString("header('x-p202-schema-token')", $branch);
        foreach (['$_GET', '$_REQUEST', 'queryParams', "'token'", '"token"'] as $leak) {
            self::assertStringNotContainsString(
                $leak,
                $branch,
                "the schema branch must not read the token from anything but the header (found $leak)"
            );
        }
    }

    public function testApplyingAStagedChangeRedactsSchemaTokensFromTheResult(): void
    {
        // The apply response goes to the approver, not the proposer; a token
        // minted by the applied write (app create, token rotation) must not
        // ride along at any nesting depth.
        $redact = new \ReflectionMethod(StagedChangesController::class, 'redactCapabilityValues');
        $redact->setAccessible(true);

        $result = $redact->invoke(null, [
            'schema_token' => 'top-level-secret',
            'data' => [
                'skan_app_id' => 3,
                'schema_token' => 'nested-secret',
                'apps' => [
                    ['app_id' => 42, 'schema_token' => 'list-secret'],
                    ['app_id' => 43],
                ],
            ],
        ]);

        $encoded = json_encode($result);
        self::assertIsString($encoded);
        self::assertStringNotContainsString('schema_token', $encoded);
        self::assertStringNotContainsString('secret', $encoded);
        // Redaction must not destroy the rest of the payload.
        self::assertSame(3, $result['data']['skan_app_id']);
        self::assertSame(43, $result['data']['apps'][1]['app_id']);
    }
}
