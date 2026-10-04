<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;

/**
 * Every place that records a click decides click_bot with ClickBotDetector,
 * and nothing else. Each entry point used to carry its own copy of the
 * device-type-4 test; one copy drifting is how a bot rule stops firing
 * without anyone noticing. A new click entry point fails here by name until
 * it is listed and asks the detector.
 */
final class ClickBotEntryPointsTest extends TestCase
{
    /** Lower-cased: macOS checks out tracking202/redirect/ as Redirect/. */
    private const ENTRY_POINTS = [
        'tracking202/redirect/dl.php',
        'tracking202/redirect/rtr.php',
        'tracking202/static/record_adv.php',
        'tracking202/static/record_simple.php',
    ];

    /** Where click ids are allocated as a service, not where a click is recorded. */
    private const LIBRARIES = [
        '202-config/click/',
        '202-config/connect2.php',
    ];

    private const ALLOCATES = '/->allocateClickId\s*\(|\bgetClickId\s*\(|INSERT\s+INTO\s+`?202_clicks_counter`?/i';

    private const ASKS_THE_DETECTOR = '/\$clickIsBot\s*=\s*\\\\Prosper202\\\\Click\\\\ClickBotDetector::isBot\('
        . '\s*\$\w+->getUserAgent\(\)\s*,\s*\$device_id\s*\)\s*;/';

    private const FILTERS_ON_THE_VERDICT = '/if\s*\(\s*\$clickIsBot\s*\)\s*\{'
        . '\s*\$mysql\[\'click_filtered\'\]\s*=\s*\'1\';'
        . '\s*\}\s*else\s*\{[^}]*FILTER::startFilter\(/s';

    /** The old per-file rule, which would be a second, divergent bot test. */
    private const DEVICE_TYPE_TEST = '/\[\s*[\'"]type[\'"]\s*\]\s*\)?\s*(?:\?\?\s*[\'"]{2}\s*\)?\s*)?'
        . '(?:==|===|!=|!==)\s*[\'"]?4\b/';

    /** @var array<string, string>|null lower-cased relative path => code without comments */
    private static ?array $sources = null;

    public function testTheEntryPointsAreTheFilesThatAllocateAClickId(): void
    {
        $found = [];
        foreach (self::sources() as $path => $code) {
            if (!self::isLibrary($path) && preg_match(self::ALLOCATES, $code) === 1) {
                $found[] = $path;
            }
        }
        sort($found);

        self::assertSame(
            self::ENTRY_POINTS,
            $found,
            'a file records clicks without being held to the bot detector below'
        );
    }

    /** @return array<string, array{string}> */
    public static function entryPoints(): array
    {
        $rows = [];
        foreach (self::ENTRY_POINTS as $path) {
            $rows[$path] = [$path];
        }

        return $rows;
    }

    /** @dataProvider entryPoints */
    public function testTheEntryPointAsksTheDetectorOnce(string $path): void
    {
        $code = self::code($path);

        preg_match_all('/ClickBotDetector::isBot\s*\(/', $code, $calls);
        self::assertCount(1, $calls[0], "$path: one verdict per click");
        self::assertMatchesRegularExpression(
            self::ASKS_THE_DETECTOR,
            $code,
            "$path: the verdict is for the agent get_device_info() parsed, with its result"
        );
    }

    /** @dataProvider entryPoints */
    public function testClickBotIsTheVerdictAndNothingElse(string $path): void
    {
        $code = self::code($path);

        preg_match_all('/\$mysql\[\s*[\'"]click_bot[\'"]\s*\]\s*=\s*([^;]+);/', $code, $assignments);
        self::assertNotEmpty($assignments[1], "$path: click_bot is never set");
        foreach ($assignments[1] as $rhs) {
            self::assertSame(
                "\$clickIsBot ? '1' : '0'",
                trim($rhs),
                "$path: click_bot set from something other than the detector"
            );
        }
    }

    /** @dataProvider entryPoints */
    public function testABotClickIsFilteredOnTheSameVerdict(string $path): void
    {
        $code = self::code($path);

        self::assertMatchesRegularExpression(
            self::FILTERS_ON_THE_VERDICT,
            $code,
            "$path: click_filtered follows the same verdict, and only a human click is run through FILTER"
        );
        self::assertDoesNotMatchRegularExpression(
            self::DEVICE_TYPE_TEST,
            $code,
            "$path: decides bots on device type itself"
        );
    }

    private static function isLibrary(string $path): bool
    {
        foreach (self::LIBRARIES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function code(string $path): string
    {
        $sources = self::sources();
        if (!isset($sources[$path])) {
            self::fail("$path not found");
        }

        return $sources[$path];
    }

    /** @return array<string, string> */
    private static function sources(): array
    {
        if (self::$sources !== null) {
            return self::$sources;
        }
        self::$sources = [];
        $root = dirname(__DIR__, 2);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = strtolower(substr((string) $file->getPathname(), strlen($root) + 1));
            $skipped = preg_match('#^(vendor|tests|node_modules|\.git|\.claude|sdk|go-cli)/#', $path) === 1;
            if (!str_ends_with($path, '.php') || $skipped) {
                continue;
            }
            // Code only: a comment that names a call is not one.
            $code = '';
            foreach (token_get_all((string) file_get_contents((string) $file->getPathname())) as $token) {
                if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                    $code .= str_repeat("\n", substr_count($token[1], "\n"));
                    continue;
                }
                $code .= is_array($token) ? $token[1] : $token;
            }
            self::$sources[$path] = $code;
        }

        return self::$sources;
    }
}
