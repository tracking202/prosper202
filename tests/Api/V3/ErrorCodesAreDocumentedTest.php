<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\ErrorCode;
use Api\V3\Exception\SyncException;
use Api\V3\Support\ScopeRefusal;
use Tests\Support\SourceScan;
use PHPUnit\Framework\TestCase;

/**
 * The codes a v3 error answer can carry are ErrorCode's, and docs/openapi.yaml
 * lists exactly those: the Error schema's enum and a row of the Errors table
 * for each. A code written as a literal in api/v3 is one of them.
 */
final class ErrorCodesAreDocumentedTest extends TestCase
{
    private static function openapi(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/docs/openapi.yaml');
    }

    public function testCodesAreUniqueAndSnakeCase(): void
    {
        $codes = ErrorCode::all();
        self::assertSame($codes, array_values(array_unique($codes)));
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^[a-z]+(_[a-z]+)*$/', $code);
        }
    }

    public function testTheErrorSchemaEnumIsTheRegistry(): void
    {
        $yaml = self::openapi();
        $pattern = '/^    Error:\n(?:      .*\n|\n)*?        code:\n(?:          .*\n)*?'
            . '          enum:\n((?:            - [a-z_]+\n)+)/m';
        self::assertSame(1, preg_match($pattern, $yaml, $m), 'the Error schema has a code enum');
        preg_match_all('/- ([a-z_]+)/', $m[1], $listed);
        self::assertSame(ErrorCode::all(), $listed[1]);
    }

    public function testTheErrorsTableHasARowForEachCode(): void
    {
        preg_match_all('/^    \| `([a-z_]+)` \| [0-9x, ]+ \| \S/m', self::openapi(), $rows);
        $documented = $rows[1];
        sort($documented);
        $codes = ErrorCode::all();
        sort($codes);
        self::assertSame($codes, $documented);
    }

    public function testCodesNamedElsewhereAreRegistered(): void
    {
        $sync = (new \ReflectionClass(SyncException::class))->getConstants();
        self::assertNotEmpty($sync);
        foreach ($sync as $name => $code) {
            self::assertTrue(ErrorCode::isKnown((string) $code), "SyncException::$name");
        }
        self::assertTrue(ErrorCode::isKnown(ScopeRefusal::CODE));
    }

    public function testEveryLiteralCodeInTheApiIsRegistered(): void
    {
        $root = dirname(__DIR__, 3) . '/api/v3';
        $seen = 0;
        $files = new \RecursiveIteratorIterator(SourceScan::tree($root));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Apps/Android/Integrity/')) {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            // A body's 'code' => '…', and AiConnect's $refuse(status, '…', …).
            $pattern = "/'code'\\s*=>\\s*'([^']+)'|\\\$refuse\\(\\s*\\d{3}\\s*,\\s*'([^']*)'/";
            preg_match_all($pattern, $src, $m, PREG_SET_ORDER);
            foreach ($m as $hit) {
                $code = $hit[2] ?? '';
                $code = $code !== '' ? $code : $hit[1];
                $seen++;
                self::assertTrue(ErrorCode::isKnown($code), $file->getPathname() . ': ' . $code);
            }
        }
        self::assertGreaterThan(5, $seen, 'the scan found AiConnect\'s codes');
    }
}
