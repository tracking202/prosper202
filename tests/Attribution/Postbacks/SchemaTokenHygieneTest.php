<?php

declare(strict_types=1);

namespace Tests\Attribution\Postbacks;

use Api\V3\Auth;
use Api\V3\Controllers\StagedChangesController;
use Api\V3\Support\ServerStateStore;
use Tests\TestCase;

/**
 * The schema token is a bearer capability: whoever presents it is served the
 * app's encode schema, and it lives for years inside shipped app binaries.
 * These tests pin every place a token could leak out of the two intended
 * channels (the owner's scoped GET/POST responses, and the
 * X-P202-Schema-Token request header) — each was a real finding, and each
 * regresses silently because the happy path keeps working.
 */
final class SchemaTokenHygieneTest extends TestCase
{
    private static function routerSource(): string
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/api/v3/index.php');
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
            'the /attribution/apps POST route registration must be present'
        );
        self::assertStringNotContainsString('$idempotent(', $m[1]);
    }

    public function testPublicSchemaBranchAcceptsTheTokenOnlyAsAHeader(): void
    {
        // A token in a GET query string lands in access logs, proxies, and
        // browser history. Extract the pre-auth /attribution/schema branch and
        // assert it reads the header and nothing request-parameter shaped.
        $src = self::routerSource();
        $start = strpos($src, "\$path === '/attribution/schema'");
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

    /**
     * The apply response goes to the approver, not the proposer, so a secret
     * the applied write returns must not ride along. Exercised through the
     * real apply() path rather than by reflecting on the redactor: the
     * defect this pins was that apply() called a one-key redactor, so a test
     * that invoked the redactor directly would have proved nothing about
     * which redactor apply() reaches for (CLAUDE.md #9).
     */
    public function testApplyingAStagedChangeRedactsSchemaTokensFromTheResult(): void
    {
        $result = $this->applyWithResult('POST', '/attribution/apps', ['app_id' => 42], [
            'attribution_app_id' => 3,
            'schema_token' => 'top-level-secret',
            'data' => [
                'schema_token' => 'nested-secret',
                'apps' => [
                    ['app_id' => 42, 'schema_token' => 'list-secret'],
                    ['app_id' => 43],
                ],
            ],
        ]);

        $encoded = json_encode($result);
        self::assertIsString($encoded);
        self::assertStringNotContainsString('secret', $encoded);
        // The key survives with a placeholder value: the CLI renders this
        // map verbatim, and a silently missing field reads as "the write did
        // not mint one" rather than "you may not see it".
        self::assertSame('[redacted]', $result['schema_token']);
        self::assertSame('[redacted]', $result['data']['schema_token']);
        self::assertSame('[redacted]', $result['data']['apps'][0]['schema_token']);
        // Redaction must not destroy the rest of the payload.
        self::assertSame(3, $result['attribution_app_id']);
        self::assertSame(43, $result['data']['apps'][1]['app_id']);
    }

    /**
     * The same gap, one route over, and wider than the first pass found.
     * `PUT /users/{id}/preferences` is stageable and its handler answers
     * with `SELECT * FROM 202_users_pref`, so the applying admin is served
     * every credential column of the proposer's row — eight of them, not the
     * two (IPQS key, Slack webhook) that the first extension of
     * SECRET_KEY_SUBSTRINGS covered. PreferenceSecretCoverageTest keeps that
     * list level with the table; this pins the leak through apply() itself.
     */
    public function testApplyingAPreferencesChangeRedactsTheProposersCredentials(): void
    {
        // Every credential `202_users_pref` carries, since the handler
        // answers with the whole row: the two the first fix covered, and the
        // four found still riding along after it. `lpo_bridge_config` is
        // matched by name — its `ctx_key` member is a signing key, and a
        // key-name redactor can only take the column whole.
        $result = $this->applyWithResult('PUT', '/users/5/preferences', ['user_pref_limit' => 50], [
            'user_id' => 5,
            'user_pref_limit' => 50,
            'ipqs_api_key' => 'ipqs-live-value',
            'user_slack_incoming_webhook' => 'https://hooks.slack.com/services/T0/B0/zzz',
            'cb_key' => 'clickbank-live-value',
            'zaxaa_api_signature' => 'zaxaa-live-value',
            'jvzoo_ipn_secret_key' => 'jvzoo-live-value',
            'revcontent_user_secret' => 'revcontent-live-value',
            'lpo_site_key' => 'lpo-live-value',
            'lpo_bridge_config' => '{"webhook_id":3,"ctx_key":"' . str_repeat('a', 64) . '"}',
        ]);

        foreach ([
            'ipqs_api_key', 'user_slack_incoming_webhook', 'cb_key',
            'zaxaa_api_signature', 'jvzoo_ipn_secret_key',
            'revcontent_user_secret', 'lpo_site_key', 'lpo_bridge_config',
        ] as $credential) {
            self::assertSame('[redacted]', $result[$credential], "$credential reached the applier");
        }
        self::assertSame(50, $result['user_pref_limit']);
        self::assertSame(5, $result['user_id']);

        $encoded = json_encode($result);
        self::assertIsString($encoded);
        foreach (['live-value', 'hooks.slack.com', 'ctx_key', str_repeat('a', 64)] as $needle) {
            self::assertStringNotContainsString($needle, $encoded);
        }
    }

    /**
     * The redactor decides by key name, and a scalar has no key — so a
     * non-array `data` is withheld rather than passed through. Unreachable
     * from any handler today; a probe that returned a raw token got it back
     * verbatim, which is the shape this closes before a handler grows it.
     */
    public function testAScalarWriteResultIsWithheldRatherThanReturnedUnredacted(): void
    {
        $applied = $this->applyChange('POST', '/campaigns', ['a' => 1], 'RAW-SCALAR-TOKEN-abc123');

        self::assertSame('[redacted: non-array result]', $applied['data']['result']);
    }

    /**
     * A handler with genuinely nothing to return (a 204 delete) must not be
     * turned into a redaction notice: null stays null.
     */
    public function testAVoidWriteResultStaysNull(): void
    {
        $applied = $this->applyChange('DELETE', '/campaigns/9', [], null);

        self::assertNull($applied['data']['result']);
    }

    /**
     * Stage a write, apply it with a dispatcher that returns $handlerData,
     * and hand back the array `result` the applier is served.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $handlerData
     * @return array<string, mixed>
     */
    private function applyWithResult(string $method, string $path, array $payload, array $handlerData): array
    {
        $result = $this->applyChange($method, $path, $payload, $handlerData)['data']['result'];
        self::assertIsArray($result, 'apply() must hand the applier the write result');
        return $result;
    }

    /**
     * Stage a write and apply it with a dispatcher whose response `data` is
     * $handlerData, whatever shape that is; hand back the whole apply()
     * response.
     *
     * @param array<string, mixed> $payload
     * @return array{data: array{change: array<string, mixed>, result: mixed}}
     */
    private function applyChange(string $method, string $path, array $payload, mixed $handlerData): array
    {
        $dir = sys_get_temp_dir() . '/p202-schema-hygiene-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        try {
            $store = new ServerStateStore($dir);
            $db = $this->createMysqliMock([
                "SHOW COLUMNS FROM 202_api_keys LIKE 'scope'" => ['Field' => 'scope'],
                '202_api_keys' => ['user_id' => 5, 'scope' => '*'],
                '202_user_role' => [['role_name' => 'admin']],
            ]);
            $auth = Auth::fromRequest(['Authorization' => 'Bearer key'], $db);
            $controller = new StagedChangesController($store, $auth);

            $changeId = (string)$controller->stage($method, $path, $payload, null)['data']['change_id'];
            return $controller->apply(
                $changeId,
                static fn(): array => ['data' => $handlerData]
            );
        } finally {
            $this->removeDir($dir);
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
