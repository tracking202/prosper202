<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;
use Prosper202\User\InstallHash;
use Tests\Support\SourceScan;

/**
 * The hosted service's callbacks authenticate by install hash, and an empty
 * one (every account but user 1 on an install upgraded from before the
 * column) authenticates nothing (InstallHash).
 */
final class InstallHashTest extends TestCase
{
    public function testAnEmptyOrMissingSideMatchesNothing(): void
    {
        self::assertTrue(InstallHash::matches('2c567636c91b5e662e6eaac6f577734d', '2c567636c91b5e662e6eaac6f577734d'));
        self::assertFalse(InstallHash::matches('2c567636c91b5e662e6eaac6f577734d', '2c567636c91b5e662e6eaac6f577734e'));
        self::assertFalse(InstallHash::matches('', ''), "hash_equals('', '') is true; an empty stored hash is no secret");
        self::assertFalse(InstallHash::matches('', 'anything'));
        self::assertFalse(InstallHash::matches('2c567636c91b5e662e6eaac6f577734d', ''));
        self::assertFalse(InstallHash::matches(null, null), 'no row');
        self::assertFalse(InstallHash::matches('2c567636c91b5e662e6eaac6f577734d', ['2c567636c91b5e662e6eaac6f577734d']), 'hash[]=');
    }

    /**
     * Nothing compares an install hash with hash_equals() itself: a call whose
     * arguments name install_hash is refused outside InstallHash, so the
     * empty-equals-empty comparison cannot come back in a new callback.
     */
    public function testNoCallbackComparesAnInstallHashItself(): void
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (str_starts_with($path, 'tests/') || $path === '202-config/User/InstallHash.php') {
                continue;
            }
            $tokens = array_values(array_filter(
                token_get_all($source),
                static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
            ));
            foreach ($tokens as $i => $token) {
                if (!is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'hash_equals' || ($tokens[$i + 1] ?? null) !== '(') {
                    continue;
                }
                $depth = 0;
                for ($j = $i + 1; $j < count($tokens); $j++) {
                    $t = $tokens[$j];
                    $text = is_array($t) ? $t[1] : $t;
                    if ($text === '(') {
                        $depth++;
                    } elseif ($text === ')' && --$depth === 0) {
                        break;
                    } elseif (stripos($text, 'install_hash') !== false) {
                        $found[] = $path . ':' . $token[2];
                        break;
                    }
                }
            }
        }

        self::assertSame([], $found, 'An install hash is compared through InstallHash::matches(), which refuses an empty one '
            . "(hash_equals('', '') is true, and every account but user 1 has an empty hash on an upgraded install).");
    }
}
