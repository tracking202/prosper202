<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * With the database out of reach, connect2.php answers 503 for every script
 * that did not declare, before loading it, that it goes on without one
 * ($p202OutageFallback = true, in the scope that loads it). A script that
 * declares it reaches its own code with $db null, so each is listed with what
 * it does then; and a script one of them includes after the bootstrap ran is
 * past that check, so unless it is listed too it asks for the database itself
 * (p202RequireDatabase($db)). tests/live/outage-fallback.sh runs every
 * endpoint without the database.
 */
final class OutageFallbackIsDeclaredTest extends TestCase
{
    private const BOOTSTRAP = '~(?:include|require)(?:_once)?\s*\(?[^;]*connect2\.php~';

    /** file => what it does without the database */
    private const FALLBACK = [
        'tracking202/redirect/dl.php' => 'the URL it keeps for an outage, or its 503 page',
        'tracking202/redirect/lp.php' => 'the URL it keeps for an outage, or p202Unavailable()',
        'tracking202/redirect/off.php' => 'the URL it keeps for an outage, or p202Unavailable()',
        'tracking202/redirect/cl2.php' => 'never reads the database',
        'tracking202/redirect/go.php' => 'sets no click cookie; lp.php and off.php fall back, offrtr.php asks',
        'api/v1/reports/index.php' => 'the API\'s JSON error, under a 503',
        'api/v2/reports/index.php' => 'the API\'s JSON error, under a 503',
        'api/v2/categories/index.php' => 'the API\'s JSON error, under a 503',
    ];

    /**
     * Read from the tokens, not the text: the declaration is the statement
     * directly before the bootstrap's include, in the same block, and the
     * only write of the name in the file. A comment, a string, a dead branch
     * above it or a later reset all read as declared to a search of the text.
     */
    public function testEveryScriptThatGoesOnWithoutTheDatabaseIsListed(): void
    {
        $declared = [];
        $loaders = 0;
        foreach (SourceScan::phpFiles() as $file => $source) {
            $include = self::bootstrapInclude($tokens = \PhpToken::tokenize($source));
            if ($include === null) {
                continue;
            }
            $loaders++;
            $writes = count(array_filter(
                $tokens,
                static fn (\PhpToken $t): bool => $t->is(T_VARIABLE) && $t->text === '$p202OutageFallback'
            ));
            $statement = self::statementBefore($tokens, $include);
            if ($statement === ['$p202OutageFallback', '=', 'true']) {
                self::assertSame(1, $writes, "$file names the fallback again besides declaring it");
                $declared[] = $file;
            } else {
                self::assertSame(0, $writes, "$file names the fallback, but not as the statement before its include");
            }
        }
        sort($declared);
        $listed = array_keys(self::FALLBACK);
        sort($listed);

        self::assertGreaterThan(20, $loaders, 'the scripts that load connect2.php were read');
        self::assertSame($listed, $declared, 'A script that goes on without the database, unlisted or gone.');
    }

    /**
     * The include keyword of the statement that loads connect2.php, or null.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function bootstrapInclude(array $tokens): ?int
    {
        foreach ($tokens as $i => $token) {
            if (!$token->is([T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE])) {
                continue;
            }
            for ($j = $i + 1; isset($tokens[$j]) && $tokens[$j]->text !== ';'; $j++) {
                if ($tokens[$j]->is(T_CONSTANT_ENCAPSED_STRING) && str_contains($tokens[$j]->text, 'connect2.php')) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * The code of the statement that ends just before $at, lower-cased
     * keywords aside, without whitespace or comments: [] when $at begins its
     * block or follows a statement that does not end in `;`.
     *
     * @param list<\PhpToken> $tokens
     * @return list<string>
     */
    private static function statementBefore(array $tokens, int $at): array
    {
        $i = $at - 1;
        while ($i >= 0 && $tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            $i--;
        }
        if ($i < 0 || $tokens[$i]->text !== ';') {
            return [];
        }
        $code = [];
        for ($i--; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (in_array($token->text, [';', '{', '}'], true) || $token->is([T_OPEN_TAG, T_CLOSE_TAG])) {
                break;
            }
            if (!$token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                $code[] = $token->is(T_STRING) ? strtolower($token->text) : $token->text;
            }
        }

        return array_reverse($code);
    }

    public function testAScriptIncludedPastTheBootstrapAsksForTheDatabase(): void
    {
        $sources = SourceScan::phpFiles();
        $asked = [];
        foreach (array_keys(self::FALLBACK) as $file) {
            preg_match_all(
                '~(?:include|require)(?:_once)?[^;]*[\'"]/(tracking202/(?:redirect|static)/\w+\.php)[\'"]~',
                $sources[$file] ?? '',
                $included
            );
            foreach ($included[1] as $script) {
                if (!isset(self::FALLBACK[$script])) {
                    $asked[$script] = str_contains($sources[$script] ?? '', 'p202RequireDatabase($db);');
                }
            }
        }

        self::assertSame(['tracking202/redirect/offrtr.php' => true], $asked);
    }

    public function testTheBootstrapAsksUnlessTheScriptDeclared(): void
    {
        $bootstrap = SourceScan::phpFiles()['202-config/connect2.php'];

        self::assertMatchesRegularExpression(
            '~if \(\(\$p202OutageFallback \?\? null\) !== true\) \{\s*p202RequireDatabase\(\$db\);\s*\}~',
            $bootstrap
        );
    }
}
