<?php

declare(strict_types=1);

namespace Tests\Config;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * A cache key's namespace says whose value it is and when: systemHash() is
 * the install, and LookupRepositoryFactory::cacheNamespace() is the install
 * and this code's version, so an upgrade starts the entry cold. An entry
 * this code computes and keeps long (a lookup id, the user-agent parse, the
 * bot ranges' verdict) takes the version; one that is a Setup value an
 * upgrade does not change, or lives minutes, keeps the bare install hash.
 *
 * So every call of systemHash() in the served tree is listed here, by file,
 * with why the bare install hash is right for it, and a new one fails until
 * it is decided. What this does not see: a key built on another prefix, or
 * a long-lived entry under one of the listed calls that changes what it holds.
 */
final class CacheKeyNamespaceTest extends TestCase
{
    private const SETUP_URL = 'the redirect fallback\'s URL (a Setup value no upgrade changes), kept warm across'
        . ' an upgrade, when an outage that needs it is likeliest';

    /** path => [calls, why the bare install hash] */
    private const LISTED = [
        'tracking202/redirect/dl.php' => [2, self::SETUP_URL],
        'tracking202/redirect/lp.php' => [3, self::SETUP_URL],
        'tracking202/redirect/off.php' => [4, self::SETUP_URL],
        '202-account/account.php' => [6, 'the account\'s settings for the redirects, Setup values no upgrade changes'],
        '202-config/functions-shared.php' => [1, 'p202CachedRead(): rows of the SQL that keys it, for minutes'],
        'tracking202/redirect/rtr.php' => [1, 'the Landing Page Optimizer settings read, for three minutes'],
        '202-account/api-integrations.php' => [1, 'removes rtr.php\'s settings entry, so it names the same key'],
        '202-config/Repository/LookupRepositoryFactory.php' => [1, 'cacheNamespace() itself'],
    ];

    public function testEveryBareInstallHashKeyIsListed(): void
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            $calls = self::calls($source);
            if ($calls > 0) {
                $found[$path] = $calls;
            }
        }
        ksort($found);
        $listed = array_map(static fn (array $entry): int => $entry[0], self::LISTED);
        ksort($listed);
        self::assertSame(
            $listed,
            $found,
            "Each call of systemHash() is listed with why the bare install hash is its namespace.\n"
                . 'A value this code computes and keeps long takes LookupRepositoryFactory::cacheNamespace().'
        );
    }

    public function testTheScanCountsCallsNotTheDefinitionOrAComment(): void
    {
        $source = <<<'PHP'
            <?php
            function systemHash(): string { return 'x'; }
            // systemHash() in a comment
            $a = md5('lp_' . $id . systemHash());
            $b = \systemHash();
            $c = $o->systemHash();
            PHP;
        self::assertSame(2, self::calls($source));
    }

    private static function calls(string $source): int
    {
        $tokens = token_get_all($source);
        $calls = 0;
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || SourceScan::callableName($token[0], $token[1]) !== 'systemhash') {
                continue;
            }
            $before = self::neighbour($tokens, $i, -1);
            $member = [T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON];
            if (is_array($before) && in_array($before[0], $member, true)) {
                continue;
            }
            if (self::neighbour($tokens, $i, 1) === '(') {
                $calls++;
            }
        }

        return $calls;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens
     * @return string|array{0: int, 1: string, 2: int}|null
     */
    private static function neighbour(array $tokens, int $i, int $step): string|array|null
    {
        for ($j = $i + $step; isset($tokens[$j]); $j += $step) {
            if (!is_array($tokens[$j]) || !in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $tokens[$j];
            }
        }

        return null;
    }
}
