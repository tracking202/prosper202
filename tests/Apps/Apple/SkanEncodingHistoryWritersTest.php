<?php

declare(strict_types=1);

namespace Tests\Apps\Apple;

use PHPUnit\Framework\TestCase;

/**
 * The encoding history is only as complete as the list of paths that
 * replace or remove an encoding (plan §5.5, SkanEncodingTimeline): a path
 * that edits 202_app_skan_encodings without first copying the meaning into
 * 202_app_skan_encoding_history makes the report decode postbacks set under
 * the old meaning as the new one, silently — no error anywhere, just a
 * different answer. So the writers are held here:
 *
 *  - every runtime PHP file that UPDATEs, DELETEs from, REPLACEs INTO or
 *    upserts 202_app_skan_encodings also calls SkanEncodingHistory, except
 *    the user purge, which deletes the history in the same transaction;
 *  - the encodings controller (whose writes are the base Controller's
 *    generic UPDATE and DELETE, which name no table) retires in both
 *    beforeUpdate() and beforeDelete().
 */
final class SkanEncodingHistoryWritersTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    /** Files allowed to remove encodings without keeping them, and why. */
    private const EXEMPT = [
        'api/v3/Apps/AppDataPurge.php' => 'the user purge deletes the history too',
    ];

    private const WRITE = '/\b(?:UPDATE|DELETE\s+FROM|REPLACE\s+INTO|INSERT\s+INTO)\s+`?202_app_skan_encodings`?\b(?![_a-z])/i';

    /** @return list<string> */
    private static function runtimeFiles(): array
    {
        $out = [];
        foreach (['api', '202-config', '202-account', '202-cronjobs', 'tracking202', 'cli', 'bin'] as $dir) {
            $path = self::ROOT . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                    $out[] = substr($file->getPathname(), strlen(self::ROOT) + 1);
                }
            }
        }
        sort($out);

        return $out;
    }

    public function testEveryPathThatReplacesAnEncodingKeepsItsMeaning(): void
    {
        $writers = [];
        foreach (self::runtimeFiles() as $file) {
            $source = (string) file_get_contents(self::ROOT . '/' . $file);
            if (preg_match_all(self::WRITE, $source, $m) === 0) {
                continue;
            }
            $statements = array_map('strtoupper', array_map(static fn (string $s): string => (string) preg_replace('/\s+/', ' ', $s), $m[0]));
            $onlyInserts = array_filter($statements, static fn (string $s): bool => !str_starts_with($s, 'INSERT')) === [];
            if ($onlyInserts && !preg_match('/ON\s+DUPLICATE\s+KEY\s+UPDATE/i', $source)) {
                continue; // a plain insert replaces nothing
            }
            $writers[] = $file;
            if (isset(self::EXEMPT[$file])) {
                self::assertMatchesRegularExpression('/DELETE\s+FROM\s+202_app_skan_encoding_history/i', $source, $file . ' is exempt because ' . self::EXEMPT[$file]);
                continue;
            }
            self::assertMatchesRegularExpression(
                '/\bSkanEncodingHistory\b[\s\S]*->\s*retire(?:Encoding|Registration)\s*\(/',
                $source,
                $file . ' replaces or removes SKAN encodings; copy their meaning to the history first (SkanEncodingHistory)'
            );
        }
        // The census, so a writer that stops matching the pattern is noticed.
        self::assertSame(['api/v3/Apps/AppDataPurge.php', 'api/v3/Controllers/AppRegistrationsController.php'], $writers);
    }

    public function testTheEncodingsControllerRetiresBeforeItsGenericUpdateAndDelete(): void
    {
        $source = (string) file_get_contents(self::ROOT . '/api/v3/Controllers/AppSkanEncodingsController.php');
        foreach (['beforeUpdate', 'beforeDelete'] as $hook) {
            self::assertSame(1, preg_match('/function ' . $hook . '\(.*?\n    \}\n/s', $source, $m), $hook . ' is missing');
            self::assertMatchesRegularExpression('/->retireEncoding\(\$this->userId, \(int\)\$id,/', $m[0], $hook . ' must keep the meaning it replaces');
        }
        self::assertStringContainsString("'effective_at' => ['type' => 'i', 'value' => \$now]", $source, 'every write restarts effective_at');
    }
}
