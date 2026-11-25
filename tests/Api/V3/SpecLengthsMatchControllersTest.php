<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controller;
use PHPUnit\Framework\TestCase;
use Tests\Support\OpenApiBlocks;

/**
 * The maxLength docs/openapi.yaml gives a CRUD resource's field is the
 * max_length its controller refuses past.
 *
 * The spec said a text ad's name, headline, description and display URL took
 * 255, 500, 2000 and 2048 characters; TextAdsController (and the VARCHAR(100)
 * columns) take 100 each, so a client built from the spec sent values the
 * API answered with a 422, and the spec said a landing page's nickname took
 * 255 where the API and its column take 50. ControllerFieldsMatchSchemaTest
 * holds each max_length to its column; this holds the spec to the max_length.
 *
 * Each controller is followed to the operations that create, update and read
 * one of its records, and every property there that names one of its fields
 * is compared: a field with a max_length is documented with that maxLength in
 * the bodies it is written through, and no property of those operations
 * declares a maxLength its field does not have. A property the spec writes
 * with `enum` needs no maxLength, as its values bound it; a property in an
 * answer that is not a field (a name joined in for display) is not held.
 * The create body's `required` list is held to the fields the controller
 * refuses a create without, too.
 */
final class SpecLengthsMatchControllersTest extends TestCase
{
    /**
     * Controllers the CRUD loop does not route, with the path of their
     * collection (`POST path`, `PUT path/{id}`, `GET path/{id}`).
     */
    private const ROUTED_ELSEWHERE = [
        'AppRegistrationsController' => '/apps',
        'AppSkanEncodingsController' => '/apps/skan-encodings',
    ];

    /** @return array<string, string> controller short name => collection path */
    private static function collections(): array
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/api/v3/index.php');
        self::assertSame(1, preg_match('/\$crudMap = \[(.*?)\];/s', $source, $m), 'index.php declares $crudMap');
        $pair = "/'([a-z-]+)'\s*=>\s*\\\\Api\\\\V3\\\\Controllers\\\\(\w+)::class/";
        preg_match_all($pair, $m[1], $pairs, PREG_SET_ORDER);
        $out = [];
        foreach ($pairs as [, $resource, $class]) {
            $out[$class] = "/$resource";
        }
        self::assertGreaterThanOrEqual(8, count($out), 'the $crudMap reader found the CRUD resources');
        foreach (self::ROUTED_ELSEWHERE as $class => $path) {
            self::assertArrayNotHasKey($class, $out, "$class is in \$crudMap now; take it off ROUTED_ELSEWHERE");
            $out[$class] = $path;
        }

        return $out;
    }

    /** @return array<string, array{string}> */
    public static function controllers(): array
    {
        $out = [];
        foreach (glob(dirname(__DIR__, 3) . '/api/v3/Controllers/*.php') ?: [] as $file) {
            $short = basename($file, '.php');
            $class = 'Api\\V3\\Controllers\\' . $short;
            if (
                class_exists($class)
                && is_subclass_of($class, Controller::class)
                && !(new \ReflectionClass($class))->isAbstract()
            ) {
                $out[$short] = [$short];
            }
        }

        return $out;
    }

    public function testEveryCrudControllerHasItsOperations(): void
    {
        $names = array_keys(self::controllers());
        self::assertContains('TextAdsController', $names, 'the scan finds the CRUD controllers');
        self::assertSame(
            [],
            array_values(array_diff($names, array_keys(self::collections()))),
            'every CRUD controller is routed through $crudMap or listed in ROUTED_ELSEWHERE with its path'
        );
    }

    /** @dataProvider controllers */
    public function testItsDocumentedLengthsAreItsOwn(string $short): void
    {
        $path = self::collections()[$short] ?? null;
        self::assertNotNull($path, "$short has no collection path here");
        $class = 'Api\\V3\\Controllers\\' . $short;
        $controller = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $fieldsMethod = new \ReflectionMethod($class, 'fields');
        /** @var array<string, array<string, mixed>> $fields */
        $fields = $fieldsMethod->invoke($controller);

        $spec = new OpenApiBlocks();
        $writes = [
            "POST $path" => $spec->requestProperties('POST', $path),
            "PUT $path/{id}" => $spec->requestProperties('PUT', "$path/{id}"),
        ];
        $read = ["GET $path/{id}" => $spec->responseDataProperties('GET', "$path/{id}", '200')];

        $problems = [];
        foreach ($writes + $read as $operation => $properties) {
            self::assertNotNull($properties, "$operation takes a JSON body");
            foreach ($properties as $name => $property) {
                $documented = $spec->maxLength($property);
                $def = $fields[$name] ?? null;
                if ($def === null) {
                    if ($documented !== null && isset($writes[$operation])) {
                        $problems[] = "$operation $name: maxLength $documented on a property $short has no field for";
                    }
                    continue;
                }
                $declared = isset($def['max_length']) ? (int) $def['max_length'] : null;
                if ($documented === $declared) {
                    continue;
                }
                if ($documented === null && (!isset($writes[$operation]) || $spec->hasEnum($property))) {
                    // An answer may leave a length unsaid, and so may a body
                    // that lists the values; a body otherwise may not.
                    continue;
                }
                $problems[] = "$operation $name: the spec says maxLength " . var_export($documented, true)
                    . ", $short refuses past " . var_export($declared, true)
                    . ' (line ' . ($property['line'] + 1) . ')';
            }
        }
        foreach ($fields as $name => $def) {
            if (!isset($def['max_length']) || ($def['readonly'] ?? false)) {
                continue;
            }
            $documentedSomewhere = false;
            foreach ($writes as $properties) {
                $documentedSomewhere = $documentedSomewhere || isset($properties[$name]);
            }
            if (!$documentedSomewhere) {
                $problems[] = "$name: $short writes it with max_length {$def['max_length']}, "
                    . "and neither POST $path nor PUT $path/{id} documents it";
            }
        }

        self::assertSame([], $problems, "docs/openapi.yaml disagrees with $short");
    }

    /**
     * Fields a controller marks required that its create() fills before
     * validation, so a client need not send them.
     */
    private const FILLED_BEFORE_VALIDATION = [
        // AppIdentity reads them from store_link, or the platform from the
        // key's shape; the spec says to send store_link or app_key.
        'AppRegistrationsController' => ['app_key', 'platform'],
    ];

    /**
     * The spec's `required` for a create body is the set of fields the
     * controller refuses a create without: TextAdCreate listed only the name
     * and LandingPageCreate and CampaignCreate left out fields the API
     * answers 422 for.
     *
     * @dataProvider controllers
     */
    public function testItsCreateBodyRequiresWhatItRefusesWithout(string $short): void
    {
        $path = self::collections()[$short];
        $class = 'Api\\V3\\Controllers\\' . $short;
        $controller = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        /** @var array<string, array<string, mixed>> $fields */
        $fields = (new \ReflectionMethod($class, 'fields'))->invoke($controller);
        $required = [];
        foreach ($fields as $name => $def) {
            if (($def['required'] ?? false) && !in_array($name, self::FILLED_BEFORE_VALIDATION[$short] ?? [], true)) {
                $required[] = $name;
            }
        }
        sort($required);

        self::assertSame(
            $required,
            (new OpenApiBlocks())->requestRequired('POST', $path),
            "the required list of POST $path in docs/openapi.yaml is the fields $short refuses a create without"
        );
    }

    /**
     * The reader is not vacuous: the text ad bodies it follows are the ones
     * the spec writes, with the lengths this change set.
     */
    public function testTheReaderFindsTheTextAdLengths(): void
    {
        $spec = new OpenApiBlocks();
        foreach ([['POST', '/text-ads'], ['PUT', '/text-ads/{id}']] as [$method, $path]) {
            $properties = $spec->requestProperties($method, $path);
            self::assertNotNull($properties);
            foreach (['text_ad_name', 'text_ad_headline', 'text_ad_description', 'text_ad_display_url'] as $field) {
                self::assertArrayHasKey($field, $properties, "$method $path documents $field");
                self::assertSame(100, $spec->maxLength($properties[$field]), "$method $path $field");
            }
        }
        $forecast = $spec->requestProperties('POST', '/forecast-events');
        self::assertNotNull($forecast);
        self::assertSame(255, $spec->maxLength($forecast['event_name']), 'a schema written in place is read too');
    }
}
