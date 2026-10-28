<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * The lookups were three implementations: two INDEXES classes (connect.php's
 * and connect2.php's), the get_*_id() wrappers over them, and the
 * repositories. The first two are gone and the repositories are the one
 * implementation. phpstan.neon.dist ignores class.notFound,
 * staticMethod.notFound and function.notFound for the legacy tree, so a call
 * left on a page nobody opened would be a fatal found by the first visitor.
 * No served file names the class, calls a deleted wrapper or declares one
 * again (a second implementation is how the copies drifted).
 *
 * Read from tokens, in any case PHP accepts, with or without a leading `\`.
 * Not seen: a name built at run time (`$f = 'get_ip_id'; $f()`,
 * call_user_func(), a string handed to function_exists()).
 */
final class DeletedLookupHelpersStayDeletedTest extends TestCase
{
    private const FUNCTIONS = [
        'getindexesinstance', 'get_country_id', 'get_city_id', 'get_isp_id', 'get_ip_id',
        'get_site_domain_id', 'get_site_url_id', 'get_keyword_id', 'get_browser_id',
        'get_platform_id', 'get_device_id', 'insertclicksvariable', 'getutmparams', 'getcvars',
        'getreferer',
    ];

    private const MEMBER_ACCESS = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON];

    public function testNoServedFileUsesOrDeclaresADeletedLookupHelper(): void
    {
        $failures = [];
        foreach (SourceScan::phpFiles() as $file => $source) {
            foreach (self::problems($source) as $problem) {
                $failures[] = "$file:$problem";
            }
        }

        self::assertSame([], $failures, 'The lookups are the repositories'
            . " (Prosper202\\Repository\\LookupRepositoryFactory).\n" . implode("\n", $failures));
    }

    /** @return array<string, array{string, int}> */
    public static function shapes(): array
    {
        return [
            'a static call' => ['INDEXES::get_ip_id($db, $ip);', 1],
            'fully qualified' => ['\INDEXES::get_keyword_id($db, $k);', 1],
            'another case' => ['indexes::get_c1_id($db, $c1);', 1],
            'an instance' => ['$i = new INDEXES();', 1],
            'a type check' => ['$ok = $x instanceof INDEXES;', 1],
            'a declaration of the class' => ['class INDEXES {}', 1],
            'a wrapper call' => ['$id = get_ip_id($ip);', 1],
            'a wrapper call, qualified' => ['$id = \get_site_url_id($url);', 1],
            'a wrapper call in another case' => ['$id = GET_KEYWORD_ID($k);', 1],
            'the instance helper' => ['$i = getIndexesInstance();', 1],
            'a wrapper declared again' => ['function get_device_id($n) { return 1; }', 1],
            'a removed click helper' => ['getReferer($mysql);', 1],
            'a property named indexes' => ['$this->indexes[$name] = $c;', 0],
            'a method of that name' => ['$x->get_ip_id($ip); Foo::get_keyword_id($k); $y?->getReferer();', 0],
            'a variable' => ['$indexes = []; $get_ip_id = 1;', 0],
            'a comment' => ["// INDEXES::get_ip_id() was here\n\$a = 1;", 0],
            'a string' => ["\$s = 'get_ip_id(';", 0],
            'a function in a namespace' => ['Other\get_ip_id($ip);', 0],
        ];
    }

    /** @dataProvider shapes */
    public function testTheScanReadsEachShape(string $code, int $expected): void
    {
        self::assertCount($expected, self::problems("<?php\n" . $code), $code);
    }

    /** @return list<string> "line: what" for each use or declaration */
    private static function problems(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $out = [];
        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                continue;
            }
            $name = SourceScan::callableName($token[0], $token[1]);
            if ($name === null) {
                continue;
            }
            $before = $tokens[$i - 1] ?? null;
            $after = $tokens[$i + 1] ?? null;
            if (is_array($before) && in_array($before[0], self::MEMBER_ACCESS, true)) {
                continue;
            }
            if ($name === 'indexes') {
                $classUse = (is_array($after) && $after[0] === T_DOUBLE_COLON)
                    || (is_array($before) && in_array($before[0], [T_NEW, T_INSTANCEOF, T_CLASS], true));
                if ($classUse) {
                    $out[] = $token[2] . ': the INDEXES class';
                }
                continue;
            }
            if (in_array($name, self::FUNCTIONS, true) && $after === '(') {
                $declared = is_array($before) && $before[0] === T_FUNCTION;
                $out[] = $token[2] . ($declared ? ': declares ' : ': calls ') . $token[1] . '()';
            }
        }

        return $out;
    }
}
