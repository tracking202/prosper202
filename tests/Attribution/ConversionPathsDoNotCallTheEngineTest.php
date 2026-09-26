<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;

/**
 * The conversion paths no longer know multi-touch attribution exists (plan
 * §2): they write the ledger row and its outbox row, and the worker does the
 * rest. The old engine hooked into gpb, gpx and upx after the commit, outside
 * their try, and ran a 24-hour rebuild inside pixel requests; a broken
 * attribution table then answered 500 for a conversion that had committed.
 *
 * This walks every file a conversion can arrive through and fails if one
 * references the engine's namespace or its worker.
 */
final class ConversionPathsDoNotCallTheEngineTest extends TestCase
{
    private const PATHS = ['tracking202/static', 'tracking202/update', 'tracking202/redirect', '202-config/Conversion', 'api/v3/Controllers/ConversionsController.php', '202-config/static-endpoint-helpers.php', '202-config/connect2.php'];

    public function testNoConversionPathReachesIntoTheEngine(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        foreach (self::PATHS as $path) {
            $full = $root . '/' . $path;
            if (is_file($full)) {
                $files[] = $full;
                continue;
            }
            self::assertDirectoryExists($full);
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($full, \FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->getExtension() === 'php') {
                    $files[] = $f->getPathname();
                }
            }
        }
        self::assertGreaterThan(20, count($files), 'the walk found the conversion paths');

        $offenders = [];
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            if (preg_match('/Prosper202\\\\Attribution\\\\|AttributionWorker|attribution-worker/', $src) === 1) {
                $offenders[] = substr($file, strlen($root) + 1);
            }
        }
        self::assertSame([], $offenders, 'a conversion path calls the attribution engine; queue through the outbox instead');
    }
}
