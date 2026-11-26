<?php

declare(strict_types=1);

namespace Tests\Setup;

use Api\V3\Controller;
use PHPUnit\Framework\TestCase;
use Prosper202\Setup\FieldLength;
use Tests\Support\SourceScan;

/**
 * A Setup page's name field takes as many characters as the API does for
 * the same field, and refuses more when it is posted.
 *
 * The text ad form invited 255 characters for the nickname and nothing at
 * all for the headline, description and display URL, while the columns hold
 * 100 and the page wrote whatever arrived, which MySQL cut to 100 without a
 * word; the category, campaign, traffic source, account and landing page
 * nickname fields invited 255 for 50-character columns. FieldLength is the
 * pages' one list: this holds it to the controllers' max_length (which
 * ControllerFieldsMatchSchemaTest holds to the columns), every input the
 * Setup pages render for one of its fields to its maxlength, every page that
 * renders one to refusing a longer value on the server, and any other input
 * named for a controller field to inviting no more than that field keeps.
 *
 * Not seen: an input with no maxlength whose field is not on the list, an
 * input whose name only matches another resource's field, and the other
 * writers of these columns (the v1 API, the DNI integration's category
 * name), which do not go through a Setup form.
 */
final class SetupFieldLengthsTest extends TestCase
{
    /** @return array<string, int> field => the controller's max_length (the least, where two declare one) */
    private static function controllerLengths(): array
    {
        $out = [];
        foreach (glob(dirname(__DIR__, 2) . '/api/v3/Controllers/*.php') ?: [] as $file) {
            $class = 'Api\\V3\\Controllers\\' . basename($file, '.php');
            if (
                !class_exists($class)
                || !is_subclass_of($class, Controller::class)
                || (new \ReflectionClass($class))->isAbstract()
            ) {
                continue;
            }
            $controller = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            /** @var array<string, array<string, mixed>> $fields */
            $fields = (new \ReflectionMethod($class, 'fields'))->invoke($controller);
            foreach ($fields as $name => $def) {
                if (isset($def['max_length'])) {
                    $out[$name][] = (int) $def['max_length'];
                }
            }
        }
        self::assertArrayHasKey('text_ad_name', $out, 'the scan reads the controllers');
        foreach (array_keys(FieldLength::MAX) as $field) {
            self::assertLessThanOrEqual(
                1,
                count($out[$field] ?? []),
                "$field has a max_length in two controllers; say which one a Setup page follows"
            );
        }
        $out = array_map(static fn (array $lengths): int => min($lengths), $out);

        return $out;
    }

    /** @return array<string, string> path relative to the root => source */
    private static function setupSources(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];
        $it = new \RecursiveIteratorIterator(SourceScan::tree($root . '/tracking202/setup'));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $relative = substr($file->getPathname(), strlen($root) + 1);
                $out[$relative] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($out);

        return $out;
    }

    public function testTheListIsTheControllers(): void
    {
        $lengths = self::controllerLengths();
        foreach (FieldLength::MAX as $field => $max) {
            self::assertArrayHasKey($field, $lengths, "$field has no max_length in an API controller");
            self::assertSame($lengths[$field], $max, "FieldLength::MAX['$field'] is the API's max_length");
        }
    }

    public function testEveryInputForAListedFieldTakesItsLength(): void
    {
        $lengths = self::controllerLengths();
        $problems = [];
        $seen = [];
        foreach (self::setupSources() as $path => $source) {
            $control = '/<(?:input|textarea)\b(?:[^>?]|\?(?!>)|<\?php.*?\?>)*>/s';
            preg_match_all($control, $source, $tags, PREG_OFFSET_CAPTURE);
            foreach ($tags[0] as [$tag, $offset]) {
                if (preg_match('/\bname="([a-z_]+)"/', $tag, $n) !== 1) {
                    continue;
                }
                $field = $n[1];
                $line = substr_count($source, "\n", 0, $offset) + 1;
                $literal = preg_match('/\bmaxlength="(\d+)"/', $tag, $l) === 1 ? (int) $l[1] : null;
                if (isset(FieldLength::MAX[$field])) {
                    $seen[$field] = true;
                    $expected = 'maxlength="<?php echo \\Prosper202\\Setup\\FieldLength::of(\'' . $field . '\'); ?>"';
                    if (!str_contains($tag, $expected)) {
                        $problems[] = "$path:$line: $field's control does not take maxlength "
                            . "from FieldLength::of('$field')";
                    }
                } elseif ($literal !== null && isset($lengths[$field]) && $literal > $lengths[$field]) {
                    $problems[] = "$path:$line: $field invites $literal characters and the API keeps "
                        . "{$lengths[$field]}; put it on FieldLength::MAX";
                }
            }
        }
        foreach (array_keys(FieldLength::MAX) as $field) {
            if (!isset($seen[$field])) {
                $problems[] = "$field is on FieldLength::MAX and no Setup page renders a control for it";
            }
        }

        self::assertSame([], $problems);
    }

    public function testEveryPageWithAListedFieldRefusesALongerOne(): void
    {
        $refused = [];
        foreach (self::setupSources() as $source) {
            preg_match_all("/FieldLength::refusal\(\s*'([a-z_]+)',/", $source, $m);
            foreach ($m[1] as $field) {
                $refused[$field] = true;
            }
            // text_ads.php measures its four in one loop over field => label.
            $loop = '/\$lengthLabels = \[([^\]]*)\];'
                . '\s*foreach \(\$lengthLabels as \$lengthField => \$lengthLabel\)\s*\{'
                . '\s*\$postedText = \(string\) \(\$_POST\[\$lengthField\] \?\? \'\'\);'
                . '\s*\$tooLong = \\\\Prosper202\\\\Setup\\\\FieldLength::refusal\(\$lengthField, \$postedText,/';
            if (preg_match_all($loop, $source, $loops) > 0) {
                foreach ($loops[1] as $list) {
                    preg_match_all("/'([a-z_]+)' => '/", $list, $keys);
                    foreach ($keys[1] as $field) {
                        $refused[$field] = true;
                    }
                }
            }
        }
        $problems = [];
        foreach (array_keys(FieldLength::MAX) as $field) {
            if (!isset($refused[$field])) {
                $problems[] = "no Setup handler refuses a $field longer than " . FieldLength::MAX[$field];
            }
        }

        self::assertSame([], $problems);
    }

    public function testTheRefusalCountsCharacters(): void
    {
        self::assertNull(FieldLength::refusal('text_ad_name', str_repeat('é', 100), 'The ad nickname'));
        self::assertSame(
            'The ad nickname can be at most 100 characters; this one has 101.',
            FieldLength::refusal('text_ad_name', str_repeat('é', 101), 'The ad nickname')
        );
        $this->expectException(\InvalidArgumentException::class);
        FieldLength::of('no_such_field');
    }
}
