<?php

declare(strict_types=1);

namespace Tests\Config;

use PHPUnit\Framework\TestCase;
use PHPUnit\Util\Test as TestUtil;
use ReflectionClass;
use ReflectionMethod;

/**
 * Every `@group integration` suite runs in CI, in exactly one place.
 *
 * php-integration.yml once named nine integration files by hand; the other 30
 * (the Goals suites among them) ran nowhere, and one of them exited 0 with no
 * report at all. CI now runs what tests/integration-suites.php selects —
 * database suites in the integration job (tests/run-integration-suites.sh),
 * `@group instance` suites in the Agent Evals job against its instance — so
 * the selector is the thing to hold: it must agree with PHPUnit's own reading
 * of the annotations, and both workflows must call it.
 */
final class IntegrationSuiteSelectionTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return list<string>
     */
    private static function selected(bool $instance): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::root() . '/tests/integration-suites.php')
            . ($instance ? ' --instance' : '');
        exec($command, $lines, $status);
        self::assertSame(0, $status, 'tests/integration-suites.php failed');

        return $lines;
    }

    /**
     * What PHPUnit itself puts in the integration group: every test file that
     * mentions `@group` at all (PHPUnit 9 has no other way to declare one),
     * loaded and asked through PHPUnit's annotation reader.
     *
     * @return array{integration: list<string>, instance: list<string>}
     */
    private static function phpunitsView(): array
    {
        $root = self::root();
        $view = ['integration' => [], 'instance' => []];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/tests', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            if (!str_ends_with($file->getFilename(), 'Test.php')) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (!str_contains($source, '@group')) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            $class = self::classDeclaredIn($source);
            self::assertNotNull($class, "$relative mentions @group but declares no class this test can read");
            require_once $file->getPathname();
            self::assertTrue(class_exists($class, false), "$relative did not declare $class");

            $groups = [];
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if (!str_starts_with($method->getName(), 'test')) {
                    continue;
                }
                foreach (TestUtil::getGroups($class, $method->getName()) as $group) {
                    $groups[$group] = true;
                }
            }
            if (isset($groups['integration'])) {
                $view[isset($groups['instance']) ? 'instance' : 'integration'][] = $relative;
            }
        }
        sort($view['integration']);
        sort($view['instance']);

        return $view;
    }

    private static function classDeclaredIn(string $source): ?string
    {
        $namespace = '';
        if (preg_match('/^namespace\s+([^;]+);/m', $source, $m)) {
            $namespace = trim($m[1]) . '\\';
        }
        if (!preg_match('/^(?:final\s+|abstract\s+)*class\s+(\w+)/m', $source, $m)) {
            return null;
        }

        return $namespace . $m[1];
    }

    public function testTheSelectorAgreesWithPhpunit(): void
    {
        $view = self::phpunitsView();

        self::assertSame($view['integration'], self::selected(false), 'database integration suites');
        self::assertSame($view['instance'], self::selected(true), 'instance integration suites');
        // A floor, so an annotation reader that found nothing on either side
        // cannot agree with a selector that found nothing.
        self::assertGreaterThanOrEqual(35, count($view['integration']));
        self::assertContains('tests/Goals/GoalEngineIntegrationTest.php', $view['integration']);
        self::assertContains('tests/Redirect/DlIntegrationTest.php', $view['instance']);
    }

    public function testBothWorkflowsRunTheSelection(): void
    {
        $integration = (string) file_get_contents(self::root() . '/.github/workflows/php-integration.yml');
        self::assertMatchesRegularExpression('/^\s*run: bash tests\/run-integration-suites\.sh\s*$/m', $integration);
        self::assertStringNotContainsString('--group integration', $integration, 'suites are selected by the runner, not listed by hand');

        $runner = (string) file_get_contents(self::root() . '/tests/run-integration-suites.sh');
        self::assertStringContainsString('mapfile -t SUITES < <(php tests/integration-suites.php)', $runner);

        $evals = (string) file_get_contents(self::root() . '/.github/workflows/agent-evals.yml');
        self::assertStringContainsString('php tests/integration-suites.php --instance', $evals);
    }
}
