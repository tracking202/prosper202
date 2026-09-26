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
 * platform shape of the document (goals as an evaluation-only view, plus the
 * encodings, plan §4.3/§5.5), and the caching contract (a stable ETag and
 * 304s) — the parts a device cannot debug once the app is in the store.
 */
final class AppSchemaTest extends TestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /**
     * The fixtures say which event a value means; each event is one live
     * goal (ids from 100 in order of first appearance) whose definition
     * carries a fixed value, so the stripping is exercised. A fixture that
     * sets `goal_id` itself is passed as given, with `found`/`archived_at`
     * defaulting to a live goal. `$versions` replaces the generated
     * 202_goal_versions rows.
     *
     * @param array<int, array<string, mixed>> $rules
     * @param list<array<string, mixed>>|null $versions
     */
    private function controller(array $rules, bool $tokenKnown = true, string $platform = 'ios', ?array $versions = null): AppSchemaController
    {
        $goalIds = [];
        $generated = [];
        foreach ($rules as $i => $rule) {
            if (array_key_exists('event_name', $rule)) {
                $event = $rule['event_name'];
                unset($rule['event_name']);
                if (!isset($goalIds[$event])) {
                    $goalIds[$event] = 100 + count($goalIds);
                    $generated[] = [
                        'goal_id' => $goalIds[$event],
                        'version' => 1,
                        'effective_at' => 1_700_000_000,
                        'archived_at' => null,
                        'definition' => \Prosper202\Goals\GoalDefinition::parse([
                            'name' => $event,
                            'trigger' => ['event' => $event],
                            'value' => ['type' => 'fixed', 'amount' => '4.99'],
                        ])->toJson(),
                    ];
                }
                $rule['goal_id'] = $goalIds[$event];
            }
            $rules[$i] = $rule + ['encoding_id' => $i + 1, 'archived_at' => null, 'found' => $rule['goal_id']];
        }
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
            'FROM 202_goal_versions v JOIN 202_goals g' => $versions ?? $generated,
        ]);
        return new AppSchemaController($db);
    }

    /** @return array<int, array{fine_value: int|null, coarse_value: string|null}> goal id => values */
    private static function encodings(array $data): array
    {
        $out = [];
        foreach ($data['encodings'] as $e) {
            $out[$e['goal_id']] = ['fine_value' => $e['fine_value'], 'coarse_value' => $e['coarse_value']];
        }
        return $out;
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

    public function testEncodingResolutionPrefersAppRulesAndHighestValues(): void
    {
        $rules = [
            // purchase (goal 100): app-specific fine beats the default; an
            // app coarse rule rides along independently.
            ['registration_id' => 0, 'fine_value' => 10, 'coarse_value' => null, 'event_name' => 'purchase'],
            ['registration_id' => 3, 'fine_value' => 63, 'coarse_value' => null, 'event_name' => 'purchase'],
            ['registration_id' => 3, 'fine_value' => null, 'coarse_value' => 'high', 'event_name' => 'purchase'],
            // signup (101): default-only fine.
            ['registration_id' => 0, 'fine_value' => 5, 'coarse_value' => null, 'event_name' => 'signup'],
            // trial (102): app coarse beats default coarse.
            ['registration_id' => 0, 'fine_value' => null, 'coarse_value' => 'low', 'event_name' => 'trial'],
            ['registration_id' => 3, 'fine_value' => null, 'coarse_value' => 'medium', 'event_name' => 'trial'],
            // tutorial (103): two default fines decode to one goal; the
            // schema must serve the highest, deterministically.
            ['registration_id' => 0, 'fine_value' => 7, 'coarse_value' => null, 'event_name' => 'tutorial'],
            ['registration_id' => 0, 'fine_value' => 9, 'coarse_value' => null, 'event_name' => 'tutorial'],
        ];

        $result = $this->controller($rules)->publicSchema(self::TOKEN, null);
        $this->assertSame(200, $result['status']);

        $data = $result['body']['data'];
        $this->assertSame(525463029, $data['app_id']);
        $this->assertSame('ios', $data['platform']);
        $this->assertSame('525463029', $data['app_key']);
        $this->assertArrayNotHasKey('events', $data, 'the event-name map is gone: goals are evaluated on the device');
        $this->assertSame(
            [
                100 => ['fine_value' => 63, 'coarse_value' => 'high'],
                101 => ['fine_value' => 5, 'coarse_value' => null],
                102 => ['fine_value' => null, 'coarse_value' => 'medium'],
                103 => ['fine_value' => 9, 'coarse_value' => null],
            ],
            self::encodings($data)
        );
        $this->assertSame([100, 101, 102, 103], array_column($data['encodings'], 'goal_id'), 'ordered by goal');
        $this->assertSame([100, 101, 102, 103], array_column($data['goals'], 'goal_id'));
        $this->assertSame('"' . $data['schema_version'] . '"', $result['etag']);
    }

    public function testAnAccountValueTheAppGaveItsOwnMeaningIsNotServed(): void
    {
        // The report decodes fine 20 for this app through the app's own
        // encoding (goal 101). A device setting 20 for the account-wide goal
        // 100 would be read as 101, so the account-wide 20 is not served
        // here — goal 100 falls back to its other account value.
        $rules = [
            ['registration_id' => 0, 'fine_value' => 20, 'coarse_value' => null, 'event_name' => 'purchase'],
            ['registration_id' => 0, 'fine_value' => 12, 'coarse_value' => null, 'event_name' => 'purchase'],
            ['registration_id' => 3, 'fine_value' => 20, 'coarse_value' => null, 'event_name' => 'signup'],
        ];
        $data = $this->controller($rules)->publicSchema(self::TOKEN, null)['body']['data'];
        $this->assertSame(
            [100 => ['fine_value' => 12, 'coarse_value' => null], 101 => ['fine_value' => 20, 'coarse_value' => null]],
            self::encodings($data)
        );
    }

    public function testGoalsAreServedAsAnEvaluationOnlyViewWithTheirPrerequisites(): void
    {
        // Goal 9 (encoded) waits for goal 8 (not encoded): the device needs
        // both to evaluate 9, and neither's value.
        $versions = [
            ['goal_id' => 8, 'version' => 1, 'effective_at' => 100, 'archived_at' => null,
                'definition' => '{"name":"Tutorial","trigger":{"event":"tutorial","where":[]},"threshold":{"count":1},"after":[],"within":null,"repeat":{"mode":"once"},"value":{"type":"fixed","amount":"1.00"}}'],
            ['goal_id' => 9, 'version' => 1, 'effective_at' => 100, 'archived_at' => null,
                'definition' => '{"name":"L3","trigger":{"event":"level","where":[{"prop":"level","op":"gte","value":3}]},"threshold":{"count":1},"after":[8],"within":{"days":7,"from":"install"},"repeat":{"mode":"once"},"value":{"type":"from_property","prop":"$revenue"}}'],
            ['goal_id' => 9, 'version' => 2, 'effective_at' => 200, 'archived_at' => null,
                'definition' => '{"name":"L3","trigger":{"event":"level","where":[{"prop":"level","op":"gte","value":3}]},"threshold":{"count":1},"after":[8],"within":null,"repeat":{"mode":"once"},"value":{"type":"none"}}'],
        ];
        $rules = [['goal_id' => 9, 'registration_id' => 3, 'fine_value' => 30, 'coarse_value' => null]];
        $result = $this->controller($rules, versions: $versions)->publicSchema(self::TOKEN, null);
        $data = $result['body']['data'];

        $this->assertSame([8, 9], array_column($data['goals'], 'goal_id'));
        $this->assertSame([9], array_column($data['encodings'], 'goal_id'));
        $goal9 = $data['goals'][1];
        $this->assertSame(0, $goal9['starts_at']);
        $this->assertNull($goal9['ends_at']);
        $this->assertSame([1, 2], array_column($goal9['versions'], 'version'));
        $this->assertSame([100, 200], array_column($goal9['versions'], 'effective_at'));

        $json = (string)json_encode($result['body']);
        $this->assertStringNotContainsString('"value":{', $json, 'no goal value reaches a device');
        $this->assertStringNotContainsString('amount', $json);
        $this->assertStringNotContainsString('from_property', $json);
        $this->assertStringContainsString('"where":[{"prop":"level","op":"gte","value":3}]', $json, 'a predicate value is not a goal value');
        $this->assertStringContainsString('"within":{"days":7,"from":"install"}', $json);

        // Each served definition still parses — the device runs these.
        foreach ($data['goals'] as $goal) {
            foreach ($goal['versions'] as $version) {
                $decoded = json_decode((string)json_encode($version['definition']), true, 64, JSON_THROW_ON_ERROR);
                \Prosper202\Goals\GoalDefinition::parse($decoded, $goal['goal_id']);
            }
        }
    }

    public function testAnEncodingWhoseGoalIsGoneOrArchivedIsNotServed(): void
    {
        // The write path refuses both, so this is damage; the document
        // leaves it out rather than serve a value for a goal nobody keeps.
        $rules = [
            ['registration_id' => 3, 'fine_value' => 1, 'coarse_value' => null, 'event_name' => 'purchase'],
            ['goal_id' => 8, 'registration_id' => 3, 'fine_value' => 2, 'coarse_value' => null, 'archived_at' => 1],
            ['goal_id' => 10, 'registration_id' => 3, 'fine_value' => 4, 'coarse_value' => null, 'found' => null],
        ];
        $data = $this->controller($rules)->publicSchema(self::TOKEN, null)['body']['data'];
        $this->assertSame([100], array_column($data['encodings'], 'goal_id'));
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

        // A new goal version changes what the device evaluates: a new tag.
        $edited = [[
            'goal_id' => 100, 'version' => 2, 'effective_at' => 1_800_000_000, 'archived_at' => null,
            'definition' => '{"name":"purchase","trigger":{"event":"purchase","where":[]},"threshold":{"count":2},"after":[],"within":null,"repeat":{"mode":"once"},"value":{"type":"none"}}',
        ]];
        $this->assertNotSame($first['etag'], $this->controller($rules, versions: $edited)->publicSchema(self::TOKEN, null)['etag']);
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

    public function testEmptyRuleSetServesEmptyLists(): void
    {
        $result = $this->controller([])->publicSchema(self::TOKEN, null);
        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('"goals":[],"encodings":[]', (string)json_encode($result['body']));
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
        $this->assertArrayNotHasKey('goals', $data, 'Android goals are evaluated on the server');
        $this->assertArrayNotHasKey('encodings', $data);
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
