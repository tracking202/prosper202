<?php

declare(strict_types=1);

namespace Tests\Skan;

use Api\V3\Controllers\SkanAppsController;
use Api\V3\Controllers\SkanSchemaController;
use Tests\TestCase;

/**
 * The public schema endpoint is what a shipped iOS build encodes with, so
 * these tests pin the resolution rules (app-specific beats default, highest
 * value wins within a scope) and the caching contract (a stable ETag and
 * 304s) — the parts a device cannot debug once the app is in the store.
 */
final class SkanSchemaTest extends TestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @param array<int, array<string, mixed>> $rules */
    private function controller(array $rules, bool $tokenKnown = true): SkanSchemaController
    {
        $db = $this->createMysqliMock([
            'FROM 202_skan_apps WHERE schema_token' => $tokenKnown
                ? [['skan_app_id' => 3, 'user_id' => 1, 'app_id' => 525463029]]
                : [],
            'FROM 202_skan_conversion_values' => $rules,
        ]);
        return new SkanSchemaController($db);
    }

    public function testMissingTokenIsA400ThatNamesTheHeader(): void
    {
        $result = $this->controller([])->publicSchema(null, null);
        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('X-P202-Schema-Token', (string)$result['body']['message']);

        $this->assertSame(400, $this->controller([])->publicSchema('   ', null)['status']);
    }

    public function testMalformedTokenIsA400NotAnUnknownTokenLookup(): void
    {
        // A pasted API key or a truncated copy is told what a token looks
        // like instead of getting a 404 that reads as "your token was revoked".
        foreach (['not-a-token', str_repeat('f', 63), str_repeat('g', 64)] as $bad) {
            $result = $this->controller([])->publicSchema($bad, null);
            $this->assertSame(400, $result['status'], "token $bad");
            $this->assertStringContainsString('64 hexadecimal', (string)$result['body']['message']);
        }
    }

    public function testUnknownTokenIsA404(): void
    {
        $result = $this->controller([], tokenKnown: false)->publicSchema(self::TOKEN, null);
        $this->assertSame(404, $result['status']);
        $this->assertNull($result['etag']);
    }

    public function testEventResolutionPrefersAppRulesAndHighestValues(): void
    {
        $rules = [
            // purchase: app-specific fine beats the default; an app coarse
            // rule rides along independently.
            ['app_id' => 0, 'fine_value' => 10, 'coarse_value' => null, 'event_name' => 'purchase'],
            ['app_id' => 525463029, 'fine_value' => 63, 'coarse_value' => null, 'event_name' => 'purchase'],
            ['app_id' => 525463029, 'fine_value' => null, 'coarse_value' => 'high', 'event_name' => 'purchase'],
            // signup: default-only fine.
            ['app_id' => 0, 'fine_value' => 5, 'coarse_value' => null, 'event_name' => 'signup'],
            // trial: app coarse beats default coarse.
            ['app_id' => 0, 'fine_value' => null, 'coarse_value' => 'low', 'event_name' => 'trial'],
            ['app_id' => 525463029, 'fine_value' => null, 'coarse_value' => 'medium', 'event_name' => 'trial'],
            // tutorial: two default fines decode to one event; the schema
            // must serve the highest, deterministically.
            ['app_id' => 0, 'fine_value' => 7, 'coarse_value' => null, 'event_name' => 'tutorial'],
            ['app_id' => 0, 'fine_value' => 9, 'coarse_value' => null, 'event_name' => 'tutorial'],
        ];

        $result = $this->controller($rules)->publicSchema(self::TOKEN, null);
        $this->assertSame(200, $result['status']);

        $data = $result['body']['data'];
        $this->assertSame(525463029, $data['app_id']);
        // Always an object (see testEmptyRuleSetServesAnEmptyEventsObject);
        // the resolution result is asserted over its array form.
        $this->assertInstanceOf(\stdClass::class, $data['events']);
        $this->assertSame(
            [
                'purchase' => ['fine_value' => 63, 'coarse_value' => 'high'],
                'signup'   => ['fine_value' => 5, 'coarse_value' => null],
                'trial'    => ['fine_value' => null, 'coarse_value' => 'medium'],
                'tutorial' => ['fine_value' => 9, 'coarse_value' => null],
            ],
            array_map(
                static fn($mapping) => (array)$mapping,
                (array)$data['events']
            )
        );
        $this->assertSame('"' . $data['schema_version'] . '"', $result['etag']);
    }

    public function testSchemaRevealsNoRevenue(): void
    {
        // The document is served to whoever holds the token (it ships inside
        // an app binary); event names and values are what encoding needs,
        // revenue amounts stay server-side.
        $rules = [
            ['app_id' => 0, 'fine_value' => 63, 'coarse_value' => null, 'event_name' => 'purchase'],
        ];
        $result = $this->controller($rules)->publicSchema(self::TOKEN, null);
        $encoded = json_encode($result['body']);
        $this->assertNotFalse($encoded);
        $this->assertStringNotContainsString('revenue', $encoded);
    }

    public function testEtagIsStableAndHonoursIfNoneMatch(): void
    {
        $rules = [
            ['app_id' => 0, 'fine_value' => 63, 'coarse_value' => null, 'event_name' => 'purchase'],
        ];

        $first = $this->controller($rules)->publicSchema(self::TOKEN, null);
        $second = $this->controller($rules)->publicSchema(self::TOKEN, null);
        // generated_at differs between calls; the ETag must not (it covers
        // only what changes encoding behaviour).
        $this->assertSame($first['etag'], $second['etag']);

        $cached = $this->controller($rules)->publicSchema(self::TOKEN, (string)$first['etag']);
        $this->assertSame(304, $cached['status']);
        $this->assertNull($cached['body']);
        $this->assertSame($first['etag'], $cached['etag']);

        $changed = [
            ['app_id' => 0, 'fine_value' => 62, 'coarse_value' => null, 'event_name' => 'purchase'],
        ];
        $this->assertNotSame($first['etag'], $this->controller($changed)->publicSchema(self::TOKEN, null)['etag']);
    }

    public function testEmptyRuleSetServesAnEmptyEventsObject(): void
    {
        $result = $this->controller([])->publicSchema(self::TOKEN, null);
        $this->assertSame(200, $result['status']);
        // {} rather than [] once JSON-encoded: the SDK decodes a dictionary.
        $this->assertInstanceOf(\stdClass::class, $result['body']['data']['events']);
        $this->assertStringContainsString('"events":{}', (string)json_encode($result['body']));
    }

    public function testAppRegistrationMintsAFreshSchemaTokenPerCreate(): void
    {
        $ctrl = new class($this->createMysqliMock(), 1) extends SkanAppsController {
            /** @return array<string, array{type: string, value: mixed}> */
            public function exposeBeforeCreate(array $payload): array
            {
                return $this->beforeCreate($payload);
            }
        };

        $first = $ctrl->exposeBeforeCreate(['app_id' => 42]);
        $second = $ctrl->exposeBeforeCreate(['app_id' => 43]);

        $this->assertSame(1, preg_match('/^[0-9a-f]{64}$/', (string)$first['schema_token']['value']));
        $this->assertSame(1, preg_match('/^[0-9a-f]{64}$/', (string)$second['schema_token']['value']));
        $this->assertNotSame($first['schema_token']['value'], $second['schema_token']['value']);
    }
}
