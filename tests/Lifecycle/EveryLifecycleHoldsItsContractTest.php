<?php

declare(strict_types=1);

namespace Tests\Lifecycle;

use PHPUnit\Framework\TestCase;
use Prosper202\Lifecycle\State;
use Tests\Lifecycle\Fixtures\ParcelState;
use Tests\Support\LifecycleContract;

/**
 * Every enum that declares itself a lifecycle (implements
 * Prosper202\Lifecycle\State) holds the contract: an initial state, every
 * state reached from one, no move to itself, the Moves trait. Found by
 * reading the tree, so a new lifecycle is held without being listed.
 *
 * Does not see: a lifecycle that is still strings and UPDATEs (nothing
 * declares it), or a writer that changes a declared lifecycle's column
 * without asking the enum. Each lifecycle's own test holds its writers.
 */
final class EveryLifecycleHoldsItsContractTest extends TestCase
{
    private const SCANNED = ['202-config', 'api', 'tracking202', 'cli'];

    public function testEveryDeclaredLifecycleHoldsTheContract(): void
    {
        $found = self::lifecycles();
        self::assertContains(ParcelState::class, $found, 'the scan finds the fixture, so it reads enums at all');
        $wrong = [];
        foreach ($found as $lifecycle) {
            array_push($wrong, ...LifecycleContract::violations($lifecycle));
        }
        self::assertSame([], $wrong, implode("\n", $wrong));
    }

    public function testAFileNamingTheInterfaceDeclaresALifecycleTheScanLoaded(): void
    {
        // The other half (CLAUDE.md #21): a file that mentions the interface
        // but whose enum the scan could not load would pass unheld.
        $found = array_flip(self::lifecycles());
        $unread = [];
        foreach (self::files() as $file => $source) {
            if (!str_contains($source, 'Lifecycle\\State') || str_contains($file, '/202-config/Lifecycle/')) {
                continue;
            }
            $class = self::enumIn($source);
            if ($class === null || !isset($found[$class])) {
                $unread[] = $file;
            }
        }
        self::assertSame([], $unread, 'these name Prosper202\\Lifecycle\\State but declare no enum the scan loaded');
    }

    public function testTheContractRefusesEachBrokenTable(): void
    {
        self::assertSame([], LifecycleContract::violations(ParcelState::class));
        self::assertStringContainsString('cannot be reached', implode(' ', LifecycleContract::violations(Fixtures\Broken\UnreachedState::class)));
        self::assertStringContainsString('moves to itself', implode(' ', LifecycleContract::violations(Fixtures\Broken\SelfLoopState::class)));
        self::assertStringContainsString('names no initial state', implode(' ', LifecycleContract::violations(Fixtures\Broken\NoStartState::class)));
        self::assertStringContainsString('does not use', implode(' ', LifecycleContract::violations(Fixtures\Broken\NoMovesState::class)));
        self::assertStringContainsString('lists a move twice', implode(' ', LifecycleContract::violations(Fixtures\Broken\TwiceState::class)));
    }

    /** @return list<class-string<State>> */
    private static function lifecycles(): array
    {
        $found = [ParcelState::class];
        foreach (self::files() as $source) {
            $class = self::enumIn($source);
            if ($class !== null && enum_exists($class) && is_subclass_of($class, State::class)) {
                $found[] = $class;
            }
        }

        return array_values(array_unique($found));
    }

    /** @return array<string, string> path => source of every PHP file that names an enum */
    private static function files(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        foreach (self::SCANNED as $dir) {
            $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($walk as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                if (str_contains($source, 'enum ')) {
                    $files[$file->getPathname()] = $source;
                }
            }
        }
        self::assertNotSame([], $files, 'the scan read no file');

        return $files;
    }

    /** The first enum a file declares, by its tokens: namespace\Name, or null. */
    private static function enumIn(string $source): ?string
    {
        $namespace = '';
        $tokens = \PhpToken::tokenize($source);
        foreach ($tokens as $i => $token) {
            if ($token->is(T_NAMESPACE)) {
                for ($j = $i + 1; isset($tokens[$j]) && $tokens[$j]->text !== ';' && $tokens[$j]->text !== '{'; $j++) {
                    if ($tokens[$j]->is([T_NAME_QUALIFIED, T_STRING])) {
                        $namespace = $tokens[$j]->text;
                    }
                }
            }
            if ($token->is(T_ENUM)) {
                for ($j = $i + 1; isset($tokens[$j]); $j++) {
                    if ($tokens[$j]->is(T_STRING)) {
                        return ($namespace === '' ? '' : $namespace . '\\') . $tokens[$j]->text;
                    }
                    if (!$tokens[$j]->is(T_WHITESPACE)) {
                        break;
                    }
                }
            }
        }

        return null;
    }
}
