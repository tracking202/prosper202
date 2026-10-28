<?php

declare(strict_types=1);

namespace Tests\GeoIp;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * Every reader of the GeoIP City database reads the file the refresh
 * replaces, and none keeps it open past its call.
 *
 * The refresh renames a new file over CityDatabase::PATH. A reader that
 * builds the path itself reads whatever file that path names, which the
 * next refactor can move away from the refresh's; a reader kept in a
 * static, a property or a long-lived worker reads the old file's inode
 * until its process ends. So: only CityDatabase names the file, every `new`
 * of a GeoIP reader in the served tree is listed here with why it cannot
 * outlive a refresh, and each is assigned to a plain local variable. A new
 * one fails until it is listed.
 */
final class CityDatabaseReadersTest extends TestCase
{
    private const DEFINES_THE_PATH = '202-config/GeoIp/CityDatabase.php';

    /** Each file that opens a GeoIP reader => what it opens and how long it holds it. */
    private const READERS = [
        '202-config/connect2.php' => 'getGeoData() opens CityDatabase::PATH for one lookup and closes it;'
            . ' getIspData() opens the ISP database, which nothing refreshes',
        '202-config/Http/EuropeanVisitor.php' => 'mayBe() opens the database it is handed (EuropeanVisitor::DATABASE,'
            . ' which is CityDatabase::PATH) for one address and closes it',
        '202-config/GeoIp/CityDatabase.php' => 'status() reads the metadata and closes',
        '202-config/GeoIp/DatabaseInstaller.php' => 'check() opens the candidate before it is renamed into place,'
            . ' and closes it',
    ];

    private const STRING_TOKENS = [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML];

    public function testOnlyCityDatabaseNamesTheFile(): void
    {
        $naming = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            foreach (token_get_all($source) as $token) {
                if (
                    is_array($token) && in_array($token[0], self::STRING_TOKENS, true)
                    && stripos($token[1], 'GeoLite2-City.mmdb') !== false
                ) {
                    $naming[$path] = true;
                }
            }
        }

        self::assertSame(
            [self::DEFINES_THE_PATH],
            array_keys($naming),
            'name the database as \Prosper202\GeoIp\CityDatabase::PATH, the file the refresh replaces'
        );
    }

    public function testEveryReaderIsListedWithWhyItCannotOutliveARefresh(): void
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (self::readerSites($source) !== []) {
                $found[] = $path;
            }
        }
        sort($found);
        $listed = array_keys(self::READERS);
        sort($listed);

        self::assertSame(
            $listed,
            $found,
            'a file that opens a GeoIP reader is not listed (or a listed one no longer does):'
                . ' list it with how long it holds the file'
        );
    }

    public function testNoReaderIsKeptInAStaticOrAProperty(): void
    {
        foreach (array_keys(self::READERS) as $path) {
            self::assertSame(
                [],
                self::kept(SourceScan::phpFiles()[$path]),
                $path . ' keeps a GeoIP reader beyond one call: after a refresh it would read the replaced file'
                    . ' until its process ends'
            );
        }
    }

    public function testTheScansSeeEverySpellingOfTheReader(): void
    {
        $opens = static fn (string $source): bool => self::readerSites($source) !== [];
        self::assertTrue($opens('<?php use GeoIp2\Database\Reader; $r = new Reader($p);'));
        self::assertTrue($opens('<?php $r = new \GeoIp2\Database\Reader($p);'));
        self::assertTrue($opens('<?php $r = new \MaxMind\Db\Reader($p);'));
        self::assertTrue($opens('<?php namespace X; use MaxMind\Db\Reader as Db; $r = new Db($p);'));
        self::assertFalse($opens('<?php $r = new \League\Csv\Reader($p);'));
        self::assertFalse($opens('<?php // new \GeoIp2\Database\Reader($p)'));
        $unreadImports = [
            '<?php use GeoIp2\Database\{Reader}; new Reader($p);',
            '<?php use MaxMind\Db; new Db\Reader($p);',
        ];
        foreach ($unreadImports as $unread) {
            try {
                self::readerSites($unread);
                self::fail('read as no reader: ' . $unread);
            } catch (\LogicException $e) {
                self::assertStringContainsString('not read by this scan', $e->getMessage());
            }
        }

        self::assertSame([], self::kept('<?php function f($p) { $reader = new \MaxMind\Db\Reader($p); }'));
        $keeps = [
            'a static' => '<?php function f($p) { static $r = null; $r ??= new \MaxMind\Db\Reader($p); }',
            'a static beside another' => '<?php function f($p) { static $a, $r; $r = new \MaxMind\Db\Reader($p); }',
            'a global' => '<?php function f($p) { global $r; $r = new \MaxMind\Db\Reader($p); }',
            'a property' => '<?php class C { function f($p) { $this->r = new \MaxMind\Db\Reader($p); } }',
            'a nullsafe property' => '<?php class C { function f($p) { $this?->r = new \MaxMind\Db\Reader($p); } }',
            'a static property' => '<?php class C { function f($p) { self::$r = new \MaxMind\Db\Reader($p); } }',
            'returned' => '<?php function f($p) { return new \MaxMind\Db\Reader($p); }',
            'an element' => '<?php function f($p) { $all[] = new \MaxMind\Db\Reader($p); }',
            'an argument' => '<?php function f($p) { keep(new \MaxMind\Db\Reader($p)); }',
        ];
        foreach ($keeps as $shape => $source) {
            self::assertNotSame([], self::kept($source), 'not seen: ' . $shape);
        }
    }

    /**
     * Why each reader in $source may outlive its call: assigned to anything
     * but a plain local variable (a property, an element, an argument, a
     * return), or to a variable the file declares static or global.
     *
     * @return list<string>
     */
    private static function kept(string $source): array
    {
        $tokens = self::tokens($source);
        $operators = [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR];
        $reasons = [];
        foreach (self::readerSites($source) as $at) {
            $line = ' (line ' . self::line($tokens, $at) . ')';
            $assign = $tokens[$at - 1] ?? null;
            $target = $tokens[$at - 2] ?? null;
            $before = $tokens[$at - 3] ?? null;
            $isAssign = $assign === '=' || (is_array($assign) && $assign[0] === T_COALESCE_EQUAL);
            if (!$isAssign || !is_array($target) || !in_array($target[0], [T_VARIABLE, T_STRING], true)) {
                $reasons[] = 'a reader is not assigned to a plain variable' . $line;
            } elseif (is_array($before) && in_array($before[0], $operators, true)) {
                // self::$r, $this->r, $this?->r
                $reasons[] = 'a reader is kept in a property' . $line;
            } elseif ($target[0] !== T_VARIABLE) {
                $reasons[] = 'a reader is not assigned to a plain variable' . $line;
            } elseif (self::declaredStaticOrGlobal($tokens, $target[1])) {
                $reasons[] = 'a reader is kept in ' . $target[1] . ', declared static or global' . $line;
            }
        }

        return $reasons;
    }

    /** @param list<mixed> $tokens */
    private static function declaredStaticOrGlobal(array $tokens, string $variable): bool
    {
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || !in_array($token[0], [T_STATIC, T_GLOBAL], true)) {
                continue;
            }
            for ($j = $i + 1; isset($tokens[$j]) && $tokens[$j] !== ';'; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_VARIABLE && $tokens[$j][1] === $variable) {
                    return true;
                }
                if ($tokens[$j] === '(' || $tokens[$j] === '{') {
                    break;
                }
            }
        }

        return false;
    }

    /**
     * The index (in tokens()) of each `new` of GeoIp2\Database\Reader or
     * MaxMind\Db\Reader in $source, written in full or through a `use`
     * import (aliased or not). A group or namespace import of either is
     * refused by name: the scan cannot follow it.
     *
     * @return list<int>
     */
    private static function readerSites(string $source): array
    {
        $readers = ['geoip2\database\reader', 'maxmind\db\reader'];
        $namespaces = ['geoip2\database', 'maxmind\db', 'geoip2', 'maxmind'];
        $aliases = [];
        $tokens = self::tokens($source);
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_USE || !isset($tokens[$i + 1]) || !is_array($tokens[$i + 1])) {
                continue;
            }
            $imported = strtolower(ltrim($tokens[$i + 1][1], '\\'));
            if (in_array($imported, $namespaces, true)) {
                throw new \LogicException('a group or namespace import of the GeoIP readers is not read by this scan;'
                    . ' import the class by name');
            }
            if (in_array($imported, $readers, true)) {
                $aliased = isset($tokens[$i + 2], $tokens[$i + 3]) && is_array($tokens[$i + 2])
                    && $tokens[$i + 2][0] === T_AS && is_array($tokens[$i + 3]);
                $aliases[strtolower($aliased ? $tokens[$i + 3][1] : 'Reader')] = true;
            }
        }
        $sites = [];
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_NEW || !isset($tokens[$i + 1]) || !is_array($tokens[$i + 1])) {
                continue;
            }
            $name = strtolower(ltrim($tokens[$i + 1][1], '\\'));
            if (in_array($name, $readers, true) || isset($aliases[$name])) {
                $sites[] = $i;
            }
        }

        return $sites;
    }

    /** @return list<mixed> $source's tokens without whitespace and comments */
    private static function tokens(string $source): array
    {
        return array_values(array_filter(
            token_get_all($source),
            static fn (mixed $t): bool => !is_array($t)
                || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
    }

    /** @param list<mixed> $tokens */
    private static function line(array $tokens, int $at): int
    {
        for ($i = $at; $i >= 0; $i--) {
            if (is_array($tokens[$i])) {
                return (int) $tokens[$i][2];
            }
        }

        return 0;
    }
}
