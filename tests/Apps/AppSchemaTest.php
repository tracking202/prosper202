<?php

declare(strict_types=1);

namespace Tests\Apps;

use Api\V3\Controllers\AppRegistrationsController;
use Api\V3\Controllers\AppSchemaController;
use Tests\TestCase;

/**
 * The public schema endpoint is what a shipped build configures itself
 * with, so these tests pin the resolution rules (the registration's own
 * encodings beat account-wide ones, highest value wins within a scope), the
 * platform shape of the document, and the caching contract (a stable ETag
 * and 304s) — the parts a device cannot debug once the app is in the store.
 */
final class AppSchemaTest extends TestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @param array<int, array<string, mixed>> $rules */
    private function controller(array $rules, bool $tokenKnown = true, string $platform = 'ios'): AppSchemaController
    {
        $db = $this->createMysqliMock([
            'FROM 202_app_registrations WHERE app_token' => $tokenKnown
                ? [[
                    'registration_id' => 3,
                    'user_id' => 1,
                    'platform' => $platform,
                    'app_key' => $platform === 'ios' ? '525463029' : 'com.example.app',
                    'accept_test_signals' => 0,
                ]]
                : [],
            'FROM 202_app_skan_encodings' => $rules,
        ]);
        return new AppSchemaController($db);
    }

    public function testMissingTokenIsA400ThatNamesTheHeader(): void
    {
        $result = $this->controller([])->publicSchema(null, null);
        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('X-P202-App-Token', (string)$result['body']['message']);

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
            ['registration_id' => 0, 'fine_value' => 10, 'coarse_value' => null, 'event_name' => 'purchase'],
            ['registration_id' => 3, 'fine_value' => 63, 'coarse_value' => null, 'event_name' => 'purchase'],
            ['registration_id' => 3, 'fine_value' => null, 'coarse_value' => 'high', 'event_name' => 'purchase'],
            // signup: default-only fine.
            ['registration_id' => 0, 'fine_value' => 5, 'coarse_value' => null, 'event_name' => 'signup'],
            // trial: app coarse beats default coarse.
            ['registration_id' => 0, 'fine_value' => null, 'coarse_value' => 'low', 'event_name' => 'trial'],
            ['registration_id' => 3, 'fine_value' => null, 'coarse_value' => 'medium', 'event_name' => 'trial'],
            // tutorial: two default fines decode to one event; the schema
            // must serve the highest, deterministically.
            ['registration_id' => 0, 'fine_value' => 7, 'coarse_value' => null, 'event_name' => 'tutorial'],
            ['registration_id' => 0, 'fine_value' => 9, 'coarse_value' => null, 'event_name' => 'tutorial'],
        ];

        $result = $this->controller($rules)->publicSchema(self::TOKEN, null);
        $this->assertSame(200, $result['status']);

        $data = $result['body']['data'];
        $this->assertSame(525463029, $data['app_id']);
        $this->assertSame('ios', $data['platform']);
        $this->assertSame('525463029', $data['app_key']);
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
            ['registration_id' => 0, 'fine_value' => 63, 'coarse_value' => null, 'event_name' => 'purchase'],
        ];
        $result = $this->controller($rules)->publicSchema(self::TOKEN, null);
        $encoded = json_encode($result['body']);
        $this->assertNotFalse($encoded);
        $this->assertStringNotContainsString('revenue', $encoded);
    }

    public function testEtagIsStableAndHonoursIfNoneMatch(): void
    {
        $rules = [
            ['registration_id' => 0, 'fine_value' => 63, 'coarse_value' => null, 'event_name' => 'purchase'],
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
            ['registration_id' => 0, 'fine_value' => 62, 'coarse_value' => null, 'event_name' => 'purchase'],
        ];
        $this->assertNotSame($first['etag'], $this->controller($changed)->publicSchema(self::TOKEN, null)['etag']);
    }

    /**
     * RFC 7232 requires the WEAK comparison function for If-None-Match, and
     * lets the header carry a comma-separated list or `*`. Exact string
     * equality against the strong ETag matched none of those, so every
     * client behind a proxy that weakens ETags (the nginx gzip filter
     * rewrites `"x"` to `W/"x"`) re-downloaded the whole document on every
     * poll while the endpoint advertised max-age caching.
     *
     * @dataProvider ifNoneMatchCases
     */
    public function testIfNoneMatchIsComparedWeakly(string $headerFormat, int $expectedStatus): void
    {
        $rules = [
            ['registration_id' => 0, 'fine_value' => 63, 'coarse_value' => null, 'event_name' => 'purchase'],
        ];
        $fresh = $this->controller($rules)->publicSchema(self::TOKEN, null);
        $bare = trim((string)$fresh['etag'], '"');

        $result = $this->controller($rules)->publicSchema(self::TOKEN, sprintf($headerFormat, $bare));

        $this->assertSame($expectedStatus, $result['status']);
        // The 304 shape is part of the contract: no body, ETag echoed.
        if ($expectedStatus === 304) {
            $this->assertNull($result['body']);
            $this->assertSame($fresh['etag'], $result['etag']);
        } else {
            $this->assertIsArray($result['body']);
        }
    }

    /** @return array<string, array{string, int}> */
    public function ifNoneMatchCases(): array
    {
        $other = str_repeat('0', 40);
        return [
            'strong exact match'            => ['"%s"', 304],
            'weak validator'                => ['W/"%s"', 304],
            'list whose second entry hits'  => ['W/"' . $other . '", "%s"', 304],
            'star matches an existing resource' => ['*', 304],
            'genuine non-match still serves the document' => ['"' . $other . '"', 200],
            // Apache mod_deflate with `DeflateAlterETag AddSuffix` rewrites the tag
            // itself rather than weakening it, so `"x-gzip"` is a different
            // opaque tag and 200 is the RFC-correct answer; recorded here so
            // the boundary is deliberate rather than accidental.
            'gzip-suffixed tag is a different entity' => ['"%s-gzip"', 200],
        ];
    }

    public function testEmptyRuleSetServesAnEmptyEventsObject(): void
    {
        $result = $this->controller([])->publicSchema(self::TOKEN, null);
        $this->assertSame(200, $result['status']);
        // {} rather than [] once JSON-encoded: the SDK decodes a dictionary.
        $this->assertInstanceOf(\stdClass::class, $result['body']['data']['events']);
        $this->assertStringContainsString('"events":{}', (string)json_encode($result['body']));
    }

    /**
     * An Android registration's document carries its identity and no SKAN
     * encode map: SKAdNetwork values mean nothing there, and the Android
     * intake adds its own SDK settings.
     */
    public function testAnAndroidRegistrationGetsItsOwnDocumentShape(): void
    {
        $rules = [
            ['registration_id' => 0, 'fine_value' => 63, 'coarse_value' => null, 'event_name' => 'purchase'],
        ];
        $result = $this->controller($rules, platform: 'android')->publicSchema(self::TOKEN, null);
        $this->assertSame(200, $result['status']);
        $data = $result['body']['data'];
        $this->assertSame('android', $data['platform']);
        $this->assertSame('com.example.app', $data['app_key']);
        $this->assertArrayNotHasKey('events', $data);
        $this->assertArrayNotHasKey('app_id', $data);
        $this->assertNotSame(
            $this->controller($rules)->publicSchema(self::TOKEN, null)['etag'],
            $result['etag'],
            'the two platforms\' documents are different entities'
        );
    }

    public function testAppRegistrationMintsAFreshAppTokenPerCreate(): void
    {
        $ctrl = new class($this->createMysqliMock(), 1) extends AppRegistrationsController {
            /** @return array<string, array{type: string, value: mixed}> */
            public function exposeBeforeCreate(array $payload): array
            {
                return $this->beforeCreate($payload);
            }
        };

        $first = $ctrl->exposeBeforeCreate(['platform' => 'ios', 'app_key' => '42']);
        $second = $ctrl->exposeBeforeCreate(['platform' => 'ios', 'app_key' => '43']);

        $this->assertSame(1, preg_match('/^[0-9a-f]{64}$/', (string)$first['app_token']['value']));
        $this->assertSame(1, preg_match('/^[0-9a-f]{64}$/', (string)$second['app_token']['value']));
        $this->assertNotSame($first['app_token']['value'], $second['app_token']['value']);
    }
}
