<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * No test catches its own fail() (Y7YK): PHPUnit's AssertionFailedError is a
 * RuntimeException, so `try { …; $this->fail('…'); } catch (\RuntimeException $e) {}`
 * passes whatever the code under test does.
 */
final class FailInBroadCatchTest extends TestCase
{
    public function testNoTestCatchesItsOwnFailure(): void
    {
        $sites = [];
        $root = SourceScan::repoRoot() . '/tests';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            foreach (FailInBroadCatch::sites((string) file_get_contents($file->getPathname())) as [$line, $type]) {
                $sites[] = substr($file->getPathname(), strlen($root) + 1) . ':' . $line . ' catches ' . $type;
            }
        }
        self::assertSame([], $sites, 'catch AssertionFailedError and rethrow it before the broad catch, or call fail() after the try');
    }

    /** @return iterable<string, array{string, bool}> */
    public static function spellings(): iterable
    {
        $try = "<?php\ntry { run(); %s; } %s\n";
        foreach (['$this->fail(\'x\')', 'self::fail(\'x\')', 'static::fail(\'x\')', 'Assert::fail(\'x\')'] as $call) {
            foreach (['\\RuntimeException', 'RuntimeException', '\\Exception', 'Exception', '\\Throwable', 'Throwable',
                '\\PHPUnit\\Framework\\Exception', '\\LogicException|\\RuntimeException'] as $type) {
                yield "$call, catch ($type)" => [sprintf($try, $call, "catch ($type \$e) {}"), true];
            }
        }
        yield 'guarded first' => [sprintf($try, '$this->fail(\'x\')',
            'catch (\\PHPUnit\\Framework\\AssertionFailedError $f) { throw $f; } catch (\\RuntimeException $e) {}'), false];
        yield 'guarded by its short name' => [sprintf($try, '$this->fail(\'x\')',
            'catch (AssertionFailedError $f) { throw $f; } catch (\\Throwable $e) {}'), false];
        yield 'a guard after the broad catch is no guard' => [sprintf($try, '$this->fail(\'x\')',
            'catch (\\RuntimeException $e) {} catch (\\PHPUnit\\Framework\\AssertionFailedError $f) { throw $f; }'), true];
        yield 'a narrow type' => [sprintf($try, '$this->fail(\'x\')', 'catch (\\InvalidArgumentException $e) {}'), false];
        yield 'no fail() in the try' => [sprintf($try, 'assertTrue(true)', 'catch (\\Throwable $e) {}'), false];
        yield 'fail() only in a nested closure body still counts' => [
            "<?php\ntry { (function () { \$this->fail('x'); })(); } catch (\\Exception \$e) {}\n", true,
        ];
    }

    /** @dataProvider spellings */
    public function testTheScanFindsEverySpelling(string $source, bool $found): void
    {
        self::assertSame($found, FailInBroadCatch::sites($source) !== [], $source);
    }
}
