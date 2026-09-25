<?php

declare(strict_types=1);

/**
 * Which files hold `@group integration` tests, for CI to run.
 *
 *   php tests/integration-suites.php            database suites (need MySQL only)
 *   php tests/integration-suites.php --instance suites that also carry
 *                                               `@group instance` (need a
 *                                               running instance over HTTP)
 *
 * One path per line, sorted, relative to the repository root.
 *
 * CI used to name each integration file by hand in php-integration.yml, and
 * 30 of the 39 were never named — the Goals suites among them — so they ran
 * nowhere. The list is derived now, from the same annotation PHPUnit reads:
 * an `@group <name>` annotation in a doc comment, read the way PHPUnit 9
 * reads it. Prose that mentions the group ("Tagged @group integration so…") is not an
 * annotation, and PHPUnit does not read it as one either;
 * IntegrationSuiteSelectionTest holds this reading to PHPUnit's own.
 */

$root = dirname(__DIR__);
$wantInstance = in_array('--instance', array_slice($argv, 1), true);

/**
 * @return list<string> the groups a file's doc comments declare
 */
function p202_groups_declared_in(string $source): array
{
    $groups = [];
    foreach (token_get_all($source) as $token) {
        if (!is_array($token) || $token[0] !== T_DOC_COMMENT) {
            continue;
        }
        // PHPUnit 9's own reading (Util\Annotation\DocBlock::parseDocBlock):
        // drop the `/**` and `*/`, then every `@name value` to the end of its
        // line. So `/** @group integration */` is the group, and "Tagged
        // @group integration so it…" is a group named by the whole rest of
        // that sentence — not this one.
        $body = substr($token[1], 3, -2);
        if (preg_match_all('/@(?P<name>[A-Za-z_-]+)(?:[ \t]+(?P<value>.*?))?[ \t]*\r?$/m', $body, $m) > 0) {
            foreach ($m['name'] as $i => $name) {
                if ($name === 'group') {
                    $groups[] = (string) $m['value'][$i];
                }
            }
        }
    }

    return array_values(array_unique($groups));
}

$selected = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/tests', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    /** @var SplFileInfo $file */
    if (!str_ends_with($file->getFilename(), 'Test.php')) {
        continue;
    }
    $source = file_get_contents($file->getPathname());
    if ($source === false) {
        fwrite(STDERR, 'cannot read ' . $file->getPathname() . "\n");
        exit(2);
    }
    $groups = p202_groups_declared_in($source);
    if (!in_array('integration', $groups, true)) {
        continue;
    }
    if (in_array('instance', $groups, true) !== $wantInstance) {
        continue;
    }
    $selected[] = substr($file->getPathname(), strlen($root) + 1);
}
sort($selected, SORT_STRING);

foreach ($selected as $path) {
    echo $path, "\n";
}
