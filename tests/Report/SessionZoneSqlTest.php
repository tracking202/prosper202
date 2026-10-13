<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * No SQL in the tree (SourceScan::phpFiles(): every PHP source but the
 * tests) reads the database connection's time zone.
 *
 * A report grouped by FROM_UNIXTIME() is in whatever zone the connection
 * happens to be in, which is nobody's: the API never set one, the report
 * engine set the account's offset *today* rounded to whole hours (India at
 * +06:00, a January click at October's offset) and left it there for every
 * later query of the request, the slim engine sent a positive offset without
 * its sign and was refused, and the export cron inherited UTC from the
 * engine. The account's clock is Prosper202\Report\LocalTime, computed from
 * PHP's zone database; nothing sets the connection's zone at all.
 *
 * So these are refused in any string PHP assembles (literals, interpolated
 * strings and heredocs, joined across `.` — the scan strings() does):
 *
 * - setting the zone (`SET time_zone`, `SET @@session.time_zone`): never
 *   allowed, there is no reason a request should;
 * - reading it: FROM_UNIXTIME(), CONVERT_TZ(), NOW(), CURDATE(), CURTIME(),
 *   SYSDATE(), CURRENT_DATE, CURRENT_TIME, LOCALTIME[STAMP], and
 *   UNIX_TIMESTAMP() of an argument (a wall-clock string read in the
 *   connection's zone). UNIX_TIMESTAMP() with no argument and UTC_*() are
 *   the same on every connection and pass, as does CURRENT_TIMESTAMP as a
 *   column default.
 *
 * A site that reads the zone and is right to (its answer cannot depend on
 * which zone it is) is listed in ALLOWED with the reason; an entry that
 * matches nothing fails, so the list cannot outlive its sites.
 *
 * What this cannot see: a TIMESTAMP column is converted through the
 * connection's zone on every read and write, so DATE(created_at) over one
 * reads the zone without naming a function here — the messaging tables have
 * TIMESTAMP columns, and their readers do their date work in PHP. SQL that
 * reaches MySQL through anything but a PHP string (a .sql file, a stored
 * routine) is not read. testTheScanSeesEverySpelling plants each spelling
 * the scan claims.
 */
final class SessionZoneSqlTest extends TestCase
{
    /** name => pattern, matched case-insensitively against an assembled string */
    private const PATTERNS = [
        'SET time_zone' => '/\bSET\s+(?:SESSION\s+|GLOBAL\s+|@@(?:session\.|global\.)?)?time_zone\b/i',
        'FROM_UNIXTIME()' => '/\bFROM_UNIXTIME\s*\(/i',
        'CONVERT_TZ()' => '/\bCONVERT_TZ\s*\(/i',
        // The call itself, empty or with a precision: "is not what this day
        // names now (no click)" is a sentence in an error message.
        'NOW()' => '/\b(?:NOW|CURDATE|CURTIME|SYSDATE)\(\s*\d?\s*\)/i',
        'CURRENT_DATE/CURRENT_TIME/LOCALTIME' => '/\b(?:CURRENT_DATE|CURRENT_TIME|LOCALTIME|LOCALTIMESTAMP)\b/i',
        'UNIX_TIMESTAMP(x)' => '/\bUNIX_TIMESTAMP\s*\(\s*[^\s)]/i',
    ];

    /** @var array<string, array<string, string>> file => pattern name => why the connection's zone cannot change its answer */
    private const ALLOWED = [
        '202-config/connect2.php' => [
            // The fallback pixel's look-back: NOW() and UNIX_TIMESTAMP() read
            // the same zone, so this is the instant seven days ago in any
            // zone without a DST change in the last week, and an hour either
            // side of it across one — a seven-day bound, not a report.
            'NOW()' => 'UNIX_TIMESTAMP(TIMESTAMPADD(DAY, -7, NOW())): both ends in one zone',
            'UNIX_TIMESTAMP(x)' => 'UNIX_TIMESTAMP(TIMESTAMPADD(DAY, -7, NOW())): both ends in one zone',
        ],
        '202-config/upgrade.php' => [
            // The pre-1.9.3 upgrade form's starting date for the Data Engine
            // rebuild, shown for the operator to edit: no report engine has
            // run on that connection, and a date one day out is corrected by
            // the person reading it. Touching the form is not worth the risk
            // to the pre-login page's token checks for an upgrade from a
            // release this old.
            'FROM_UNIXTIME()' => 'an editable default on the pre-1.9.3 upgrade form',
        ],
    ];

    public function testNoServedSqlReadsOrSetsTheConnectionsZone(): void
    {
        $found = [];
        $seen = [];
        $files = SourceScan::phpFiles();
        self::assertGreaterThan(500, count($files), 'the scan found the tree');
        foreach ($files as $file => $code) {
            foreach (self::findings($code) as [$line, $name]) {
                $seen[$file][$name] = true;
                if (!isset(self::ALLOWED[$file][$name])) {
                    $found[] = "$file:$line $name";
                }
            }
        }
        self::assertSame([], $found, "SQL that reads or sets the connection's time zone. Use Prosper202\\Report\\LocalTime with the account's zone, or list the site in ALLOWED with why the zone cannot change its answer.");

        $stale = [];
        foreach (self::ALLOWED as $file => $names) {
            foreach (array_keys($names) as $name) {
                if (!isset($seen[$file][$name])) {
                    $stale[] = "$file $name";
                }
            }
        }
        self::assertSame([], $stale, 'These ALLOWED entries match nothing any more; remove them.');
    }

    /** @return iterable<string, array{string, string}> code, the pattern it must be found as */
    public static function spellings(): iterable
    {
        yield 'a literal' => ["\$db->query('SELECT FROM_UNIXTIME(click_time) FROM t');", 'FROM_UNIXTIME()'];
        yield 'lower case, a space before the paren' => ["\$sql = \"select from_unixtime (t)\";", 'FROM_UNIXTIME()'];
        yield 'joined across a dot' => ["\$sql = 'SELECT FROM_' . 'UNIXTIME(t)';", 'FROM_UNIXTIME()'];
        yield 'across a variable' => ["\$sql = 'DATE_FORMAT(FROM_UNIXTIME(' . \$col . '), \"%Y\")';", 'FROM_UNIXTIME()'];
        yield 'interpolated' => ["\$sql = \"SELECT FROM_UNIXTIME({\$col}) FROM t\";", 'FROM_UNIXTIME()'];
        yield 'a heredoc' => ["\$sql = <<<SQL\nSELECT HOUR(FROM_UNIXTIME(\$c))\nSQL;", 'FROM_UNIXTIME()'];
        yield 'a nowdoc' => ["\$sql = <<<'SQL'\nSELECT CONVERT_TZ(t, '+00:00', 'UTC')\nSQL;", 'CONVERT_TZ()'];
        yield 'the old engine' => ["\$db->query(\"SET time_zone = '\" . \$offset . \":00'\");", 'SET time_zone'];
        yield 'session, by variable' => ["\$db->query('SET @@session.time_zone = \"+00:00\"');", 'SET time_zone'];
        yield 'SET SESSION' => ["\$db->query('set session time_zone=\"UTC\"');", 'SET time_zone'];
        yield 'NOW()' => ["\$q = 'WHERE t > UNIX_TIMESTAMP() - 3600 AND d < NOW()';", 'NOW()'];
        yield 'CURDATE()' => ["\$q = 'WHERE d = CURDATE()';", 'NOW()'];
        yield 'CURRENT_DATE' => ["\$q = 'WHERE d = CURRENT_DATE';", 'CURRENT_DATE/CURRENT_TIME/LOCALTIME'];
        yield 'UNIX_TIMESTAMP of a date' => ["\$q = \"WHERE t >= UNIX_TIMESTAMP('2026-01-01')\";", 'UNIX_TIMESTAMP(x)'];
    }

    /** @dataProvider spellings */
    public function testTheScanSeesEverySpelling(string $code, string $name): void
    {
        $names = array_column(self::findings("<?php\n" . $code . "\n"), 1);
        self::assertContains($name, $names, "planted: $code");
    }

    public function testWhatDoesNotReadTheZonePasses(): void
    {
        foreach ([
            "\$q = 'SELECT UNIX_TIMESTAMP() - 60';",
            "\$q = 'SELECT UTC_TIMESTAMP(), UTC_DATE()';",
            "\$q = '`created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';",
            "\$t = \$this->now();",
            '// FROM_UNIXTIME(click_time) in a comment',
            "/** SET time_zone, in a docblock */",
            "\$tz = date_default_timezone_get();",
            "\$s = 'the time_zone setting';",
            "\$m = 'is not what this day names now (' . \$x . ')';",
        ] as $code) {
            self::assertSame([], self::findings("<?php\n" . $code . "\n"), $code);
        }
    }

    /**
     * Each pattern found in the strings of $code, with the line its string
     * starts on.
     *
     * @return list<array{0: int, 1: string}>
     */
    private static function findings(string $code): array
    {
        $out = [];
        foreach (self::strings($code) as [$line, $text]) {
            foreach (self::PATTERNS as $name => $pattern) {
                if (preg_match($pattern, $text) === 1) {
                    $out[] = [$line, $name];
                }
            }
        }

        return $out;
    }

    /**
     * The strings of $code as PHP assembles them: literal and interpolated
     * pieces, heredocs and nowdocs, joined across `.` and across whatever
     * sits between two pieces of one expression (a variable, an
     * interpolation, a constant), which reads as `?`. Anything else — a
     * `;`, `,`, `(`, `)`, an operator — ends a string.
     *
     * @return list<array{0: int, 1: string}>
     */
    private static function strings(string $code): array
    {
        $out = [];
        $buffer = null;
        $line = 0;
        $flush = static function () use (&$out, &$buffer, &$line): void {
            if ($buffer !== null) {
                $out[] = [$line, $buffer];
            }
            $buffer = null;
        };
        $joins = [T_VARIABLE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_STRING_VARNAME, T_NUM_STRING,
            T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_STRING, T_DOUBLE_COLON, T_START_HEREDOC, T_END_HEREDOC];
        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                [$id, $text, $at] = $token;
                if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                    continue;
                }
                if ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE) {
                    $piece = $id === T_CONSTANT_ENCAPSED_STRING ? substr($text, 1, -1) : $text;
                    if ($buffer === null) {
                        $buffer = '';
                        $line = $at;
                    }
                    $buffer .= $piece;
                    continue;
                }
                if (in_array($id, $joins, true)) {
                    if ($buffer !== null) {
                        $buffer .= '?';
                    }
                    continue;
                }
                $flush();
                continue;
            }
            // A one-character token: a dot joins, a quote or the brackets of
            // an interpolation sit inside a string, anything else ends one.
            if (in_array($token, ['.', '"', '{', '}', '[', ']'], true)) {
                continue;
            }
            $flush();
        }
        $flush();

        return $out;
    }
}
