<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;
use Tests\Support\AssembledStrings;
use Tests\Support\SourceScan;

/**
 * A Direct Network Integration (202_dni_networks) is read only as the
 * signed-in account's, and its key goes nowhere but the hosted service
 * (CLAUDE.md #5, #11, #27).
 *
 * Account › API integrations listed `WHERE user_id = '1'` to whoever opened
 * it: every admin saw account 1's networks, never their own, and the page
 * put each key into a Reveal attribute, the edit form and the import
 * poller's inline JSON.
 *
 * What it reads, from every statement the served tree assembles that names
 * the table (AssembledStrings: literals joined across `.`, interpolation,
 * class constants; an unread part is a hole whose PHP source is kept):
 *
 *  - SELECT, UPDATE and DELETE compare the table's user_id (by its alias, or
 *    bare when the statement names no other table) with the session's
 *    account; INSERT … SET sets it so. The session's account is a hole whose
 *    expression reads `$_SESSION['user_id']` (or `user_own_id`, the same id
 *    at sign-in) bare, cast, or through `$db->real_escape_string()`, or names
 *    a variable or `$name['key']` that every assignment in its file sets that
 *    way; a `?` only in DniNetworks, as its statement's first placeholder,
 *    where every public method binds $userId first or hands it on, and every
 *    caller passes the session's account as $userId; or another table's
 *    user_id the same statement ties so. A literal (`'1'`), any other hole,
 *    an OR anywhere in the statement, or no comparison at all is refused,
 *    unless WITHOUT_ACCOUNT lists it.
 *  - A name stops being the session's when its file also writes it another
 *    way: a compound assignment, `++`, a reference, a foreach or destructuring
 *    target, `global`/`static`, a parameter of the same name, `$GLOBALS[…]`;
 *    and nothing in a file is the session's when the file uses `extract()`,
 *    `parse_str()`, `eval()` or a variable variable.
 *  - No SELECT reads `*` from the table (that reads the key without naming
 *    it); one that selects `apiKey` (not CHAR_LENGTH(apiKey) or
 *    RIGHT(apiKey, 4)) is listed in READS_THE_KEY with where the key goes.
 *  - Every `['apiKey']` element read, and every read of the form's
 *    `['dni_network_api_key']`, is an argument of a call to a function that
 *    sends the key to my.tracking202.com (SENDERS, each checked to do only
 *    that), or is listed in KEY_READS with where it goes.
 *
 * What it does not see: a table name built at runtime; a key copied into a
 * variable and echoed from there (KEY_READS says where each copy goes, and
 * ApiIntegrationsAccountScopeIntegrationTest reads the rendered page); a
 * name written by a file this one includes.
 */
final class DniNetworksReadOnlyTheAccountsTest extends TestCase
{
    private const TABLE = '202_dni_networks';

    private const CLASS_FILE = '202-config/Setup/DniNetworks.php';

    private const CLASS_NAME = 'Prosper202\\Setup\\DniNetworks';

    private const CALLBACK = 'the hosted service\'s callback names a network only by its networkId, with the'
        . ' install hash (InstallHash::matches()) as its credential: it is not an account\'s request, and the'
        . ' key it reads goes back to my.tracking202.com';

    private const CALLBACK_READ = 'SELECT apiKey, install_hash, type FROM 202_dni_networks'
        . " JOIN 202_users USING (user_id) WHERE networkId = '…'";

    /** @var array<string, array<string, string>> file => folded statement => why it names no account */
    private const WITHOUT_ACCOUNT = [
        '202-cronjobs/dni.php' => [
            self::CALLBACK_READ => self::CALLBACK,
            "UPDATE 202_dni_networks SET processed = '1' WHERE networkId = '…' AND apiKey = '…'" => self::CALLBACK,
        ],
    ];

    /** @var array<string, string> the files that name the table outside a statement */
    private const NAMED_ONLY = [
        '202-config/Database/Schema/TableRegistry.php' => 'TableRegistry::DNI_NETWORKS',
        '202-config/Database/Tables/MiscTables.php' => 'the table\'s definition, handed to SchemaBuilder::fromRawSql()',
    ];

    /** @var array<string, array<string, string>> file => folded SELECT => where the key it reads goes */
    private const READS_THE_KEY = [
        'tracking202/ajax/dni_get_offers.php' => [
            'SELECT dni.networkId, dni.apiKey, dni.affiliateId, dni.name, 2u.install_hash FROM 202_dni_networks AS dni'
                . " LEFT JOIN 202_users AS 2u USING(user_id) WHERE dni.user_id = '…' AND dni.id = '…'"
                => 'the offer browser\'s calls to the service (getDniOffers() and its siblings)',
        ],
        'tracking202/setup/aff_campaigns.php' => [
            'SELECT networkId, apiKey, affiliateId FROM 202_dni_networks WHERE user_id = "…" AND id = "…"'
                => 'setupDniOfferTrack(), when a campaign is set up from a network offer',
        ],
        '202-cronjobs/dni.php' => [
            self::CALLBACK_READ => 'the service\'s offer cache request, and the callback\'s own UPDATE',
        ],
        self::CLASS_FILE => [
            'SELECT networkId, apiKey FROM 202_dni_networks WHERE user_id = ? AND id = ?'
                => 'storedKey(), for keptKey(): an edit that keeps its key authenticates with it and writes it back',
            'SELECT id, networkId, apiKey, type FROM 202_dni_networks WHERE user_id = ? AND processed = 0 ORDER BY id'
                => 'progressRequest(), which ajax/dni.php hands to getDNICacheProgress()',
        ],
    ];

    /** The functions a key may be handed to: each sends it to my.tracking202.com and prints nothing. */
    private const SENDERS = [
        'authdninetworks', 'getdnioffers', 'getdniofferbyid', 'requestdniofferaccess',
        'submitdniofferanswers', 'setupdnioffer', 'setupdnioffertrack',
    ];

    /** @var array<string, array<string, string>> file => the line a key is read on => where it goes */
    private const KEY_READS = [
        '202-account/api-integrations.php' => [
            '$dniApiKey = (string)($_POST[\'dni_network_api_key\'] ?? \'\');'
                => 'the key typed into the form, or the one keptKey() answers: authDniNetworks() and the'
                . ' INSERT or UPDATE, never the page',
        ],
        '202-cronjobs/dni.php' => [
            '\'api_key\' => $row[\'apiKey\'],' => 'the service\'s offer cache request (CALLBACK)',
            '$sql = "UPDATE 202_dni_networks SET processed = \'1\' WHERE networkId = \'" . $mysql[\'networkId\']'
                . ' . "\' AND apiKey = \'" . $row[\'apiKey\'] . "\'";'
                => 'the callback marks the network it names (CALLBACK)',
        ],
        self::CLASS_FILE => [
            'return [(string) $stored[\'apiKey\'], null];' => 'keptKey(), read by the page into $dniApiKey (above)',
            '\'api_key\' => (string) $row[\'apiKey\'],' => 'progressRequest(), sent by getDNICacheProgress()',
        ],
    ];

    private const SESSION_ACCOUNT = '/^(?:\$db->real_escape_string\((?:\((?:string|int)\))?'
        . '\$_SESSION\[\'user_(?:own_)?id\'\]\)'
        . '|(?:\((?:string|int)\))?\$_SESSION\[\'user_(?:own_)?id\'\])$/D';

    /** A column that holds an account: `user_id`, perhaps qualified or quoted. */
    private const USER_ID_COLUMN = '/^(?:`?\w+`?\.)?`?user_id`?$/';

    public function testEveryStatementNamesTheSessionsAccount(): void
    {
        $problems = [];
        $seen = [];
        $statements = 0;
        foreach (self::statements() as [$file, $line, $text, $holes]) {
            $statements++;
            $folded = self::fold($text);
            $why = self::accountProblem($file, $text, $holes);
            if ($why === null) {
                continue;
            }
            if (isset(self::WITHOUT_ACCOUNT[$file][$folded])) {
                $seen[$file][$folded] = true;
                continue;
            }
            $problems[] = "$file:$line $why: $folded";
        }
        $blind = 'the scan found too few statements on ' . self::TABLE . ': it is blind, not the tree clean';
        self::assertGreaterThanOrEqual(14, $statements, $blind);
        $why = 'a read of ' . self::TABLE . ' must name the signed-in account (`user_id = ` the session\'s),'
            . ' not account 1 or none';
        self::assertSame([], $problems, $why);
        foreach (self::WITHOUT_ACCOUNT as $file => $entries) {
            foreach (array_keys($entries) as $folded) {
                $stale = "WITHOUT_ACCOUNT lists a statement that is gone or now names the account; remove it:"
                    . " $file: $folded";
                self::assertTrue(isset($seen[$file][$folded]), $stale);
            }
        }
    }

    public function testEveryCallerOfDniNetworksPassesTheSessionsAccount(): void
    {
        $class = new \ReflectionClass(self::CLASS_NAME);
        $positions = [];
        $source = file($class->getFileName() ?: '') ?: [];
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            $position = null;
            foreach ($method->getParameters() as $parameter) {
                if ($parameter->getName() === 'userId') {
                    self::assertSame('int', (string) $parameter->getType(), "$name(): \$userId is an int");
                    $position = $parameter->getPosition();
                }
            }
            $lines = $method->getEndLine() - $method->getStartLine() + 1;
            $body = implode('', array_slice($source, $method->getStartLine() - 1, $lines));
            if ($position === null) {
                $why = "$name() reads the table and takes no \$userId";
                self::assertStringNotContainsString(self::TABLE, $body, $why);
                continue;
            }
            $positions[strtolower($name)] = $position;
            self::assertMatchesRegularExpression(
                '/->bind\(\$stmt, \'i+\', \[\$userId[,\]]|self::\w+\(\$db, \$userId[,)]/',
                $body,
                "$name() binds \$userId first, to the statement's first `?`, or hands it on as the account"
            );
        }
        self::assertNotSame([], $positions, 'DniNetworks has no method that takes the account');

        $calls = 0;
        $problems = [];
        $names = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];
        $members = [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_FUNCTION];
        foreach (self::servedFiles() as $file => $code) {
            if ($file === self::CLASS_FILE || stripos($code, 'DniNetworks') === false) {
                continue;
            }
            $tokens = self::tokens($code);
            $session = self::sessionNames($tokens);
            foreach ($tokens as $i => $t) {
                $named = is_array($t) && in_array($t[0], $names, true);
                if (!$named || preg_match('/(?:^|\\\\)DniNetworks$/i', $t[1]) !== 1) {
                    continue;
                }
                $before = $tokens[$i - 1] ?? null;
                if (is_array($before) && in_array($before[0], $members, true)) {
                    continue; // a method of that name
                }
                $line = $t[2];
                if (strtolower(ltrim($t[1], '\\')) !== strtolower(self::CLASS_NAME)) {
                    $problems[] = "$file:$line names `{$t[1]}`: write the class's full name, \\" . self::CLASS_NAME;
                    continue;
                }
                $colons = $tokens[$i + 1] ?? null;
                $method = $tokens[$i + 2] ?? null;
                if (
                    !is_array($colons) || $colons[0] !== T_DOUBLE_COLON
                    || !is_array($method) || $method[0] !== T_STRING || ($tokens[$i + 3] ?? null) !== '('
                ) {
                    $problems[] = "$file:$line uses DniNetworks other than as a static call,"
                        . ' which the scan cannot follow';
                    continue;
                }
                $position = $positions[strtolower($method[1])] ?? null;
                if ($position === null) {
                    continue;
                }
                $calls++;
                $arg = self::arguments($tokens, $i + 3)[$position] ?? '';
                if (preg_match(self::SESSION_ACCOUNT, $arg) !== 1 && !isset($session[$arg])) {
                    $problems[] = "$file:$line DniNetworks::{$method[1]}() is handed `$arg` as the account,"
                        . ' not the session\'s';
                }
            }
        }
        self::assertGreaterThanOrEqual(4, $calls, 'the scan found too few calls of DniNetworks: it is blind');
        self::assertSame([], $problems);
    }

    public function testNoStatementReadsEveryColumnAndOnlyListedOnesReadTheKey(): void
    {
        $problems = [];
        $seen = [];
        foreach (self::statements() as [$file, $line, $text]) {
            if (preg_match('/^\s*SELECT\b/i', $text) !== 1) {
                continue;
            }
            $folded = self::fold($text);
            $list = self::selectList($text);
            if (preg_match('/(?:^|,)\s*(?:`?\w+`?\.)?\*\s*(?:,|$)/', $list) === 1) {
                $problems[] = "$file:$line selects every column, the key with them: $folded";
                continue;
            }
            $shown = '/\b(?:CHAR_LENGTH|RIGHT)\s*\(\s*(?:\w+\.)?`?apiKey`?\s*(?:,\s*4\s*)?\)/i';
            if (preg_match('/\bapiKey\b/i', (string) preg_replace($shown, '', $list)) !== 1) {
                continue;
            }
            if (isset(self::READS_THE_KEY[$file][$folded])) {
                $seen[$file][$folded] = true;
                continue;
            }
            $problems[] = "$file:$line selects the key; list it in READS_THE_KEY with where it goes,"
                . " or select CHAR_LENGTH(apiKey) / RIGHT(apiKey, 4): $folded";
        }
        self::assertSame([], $problems);
        foreach (self::READS_THE_KEY as $file => $entries) {
            foreach (array_keys($entries) as $folded) {
                $stale = "READS_THE_KEY lists a SELECT that is gone; remove it: $file: $folded";
                self::assertTrue(isset($seen[$file][$folded]), $stale);
            }
        }
    }

    public function testAKeyIsHandedOnlyToTheHostedService(): void
    {
        $functions = (string) file_get_contents(SourceScan::repoRoot() . '/202-config/functions-tracking202.php');
        $found = 0;
        foreach (self::SENDERS as $name) {
            if (preg_match('/^function ' . $name . '\(.*?^\}$/msi', $functions, $m) !== 1) {
                continue;
            }
            $found++;
            $service = "'https://my.tracking202.com/api/v2/dni/'";
            self::assertStringContainsString($service, $m[0], "$name() sends to my.tracking202.com");
            $prints = preg_match('/\b(?:echo|print|printf|var_dump|print_r)\b/', $m[0]);
            self::assertSame(0, $prints, "$name() prints nothing");
        }
        self::assertSame(7, $found, 'every sender the list names is a function of functions-tracking202.php');

        $problems = [];
        $seen = [];
        $reads = 0;
        foreach (self::servedFiles() as $file => $code) {
            if (!str_contains($code, 'apiKey') && !str_contains($code, 'dni_network_api_key')) {
                continue;
            }
            $tokens = self::tokens($code);
            $lines = preg_split('/\r?\n/', $code) ?: [];
            foreach ($tokens as $i => $t) {
                // An index of something (`$row[`, `…][`, `f()[`), not an array literal.
                $indexed = $tokens[$i - 2] ?? null;
                $element = ($tokens[$i - 1] ?? null) === '[' && ($tokens[$i + 1] ?? null) === ']'
                    && is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING
                    && (in_array($indexed, [']', ')', '}'], true)
                        || (is_array($indexed) && $indexed[0] === T_VARIABLE));
                if (!$element || !in_array(substr($t[1], 1, -1), ['apiKey', 'dni_network_api_key'], true)) {
                    continue;
                }
                $reads++;
                if (self::insideASenderCall($tokens, $i - 1)) {
                    continue;
                }
                $text = trim((string) preg_replace('/\s+/', ' ', $lines[$t[2] - 1] ?? ''));
                if (isset(self::KEY_READS[$file][$text])) {
                    $seen[$file][$text] = true;
                    continue;
                }
                $problems[] = "$file:{$t[2]} reads a DNI key outside a call that sends it to the service: $text";
            }
        }
        self::assertGreaterThanOrEqual(10, $reads, 'the scan found too few key reads: it is blind');
        $why = 'a DNI key goes to my.tracking202.com and nowhere else;'
            . ' list a read in KEY_READS only with where it goes';
        self::assertSame([], $problems, $why);
        foreach (self::KEY_READS as $file => $entries) {
            foreach (array_keys($entries) as $text) {
                $stale = "KEY_READS lists a line that is gone; remove it: $file: $text";
                self::assertTrue(isset($seen[$file][$text]), $stale);
            }
        }
    }

    /** The progress endpoint asks for the page's own permission first (CLAUDE.md #5). */
    public function testTheProgressEndpointAsksForThePagesPermission(): void
    {
        $tokens = self::tokens((string) file_get_contents(SourceScan::repoRoot() . '/202-account/ajax/dni.php'));
        $text = implode(' ', array_map(static fn ($t): string => is_array($t) ? $t[1] : $t, $tokens));
        $first = "AUTH :: require_user ( ) ; AUTH :: require_permissions ( 'access_to_api_integrations' ) ;";
        self::assertStringContainsString($first, $text);
    }

    /**
     * PHP, what its refusal says (null: accepted), and the file it is read as.
     *
     * @return iterable<string, array{0: string, 1: string|null, 2?: string}>
     */
    public static function plantedStatements(): iterable
    {
        $session = "\$u = (int) \$_SESSION['user_id']; ";
        $read = "\$s = \"SELECT id FROM 202_dni_networks WHERE user_id = '\$u'\";";
        $byRow = "'\" . \$row['user_id'] . \"'";
        $bySession = "'{\$_SESSION['user_id']}'";
        $other = 'not the session\'s account';
        yield 'account 1' => [
            "\$s = \"SELECT * FROM 202_dni_networks WHERE user_id = '1'\";",
            'compared with a literal',
        ];
        yield 'account 1, unquoted' => [
            '$s = "UPDATE 202_dni_networks SET processed = 1 WHERE user_id = 1";',
            'compared with a literal',
        ];
        yield 'no account' => [
            "\$s = \"SELECT id FROM 202_dni_networks WHERE id = '\" . \$id . \"'\";",
            'names no account',
        ];
        yield 'a row\'s account' => ["\$s = \"SELECT id FROM 202_dni_networks WHERE user_id = $byRow\";", $other];
        yield 'an OR' => ["\$s = \"SELECT id FROM 202_dni_networks WHERE user_id = $bySession OR 1\";", 'an OR'];
        yield 'tied to an untied table' => [
            "\$s = \"SELECT d.id FROM 202_users u JOIN 202_dni_networks d ON (d.user_id = u.user_id)"
                . " WHERE u.user_name = 'x'\";",
            'not tied',
        ];
        yield 'unqualified in a join' => [
            "\$s = \"SELECT d.id FROM 202_dni_networks d JOIN 202_users u USING (user_id)"
                . " WHERE user_id = $bySession\";",
            'names no account',
        ];
        yield 'unqualified in a comma join' => [
            "\$s = \"SELECT d.id FROM 202_dni_networks d, 202_users u WHERE user_id = $bySession\";",
            'names no account',
        ];
        yield 'another alias\'s user_id' => [
            "\$s = \"SELECT d.id FROM 202_dni_networks AS d LEFT JOIN 202_users AS u ON (u.user_id = d.user_id)"
                . " WHERE u.user_id = $byRow\";",
            'not tied',
        ];
        yield 'a placeholder outside the class' => [
            "\$s = 'SELECT id FROM 202_dni_networks WHERE user_id = ?';",
            'a `?`',
        ];
        yield 'a name written twice' => [$session . '$u = $other; ' . $read, $other];
        yield 'a compound write' => [$session . '$u .= "1"; ' . $read, $other];
        yield 'an increment' => [$session . '$u++; ' . $read, $other];
        yield 'a parameter of the name' => [
            "function f(\$u) { return \"SELECT id FROM 202_dni_networks WHERE user_id = '\$u'\"; } " . $session,
            $other,
        ];
        yield 'a reference' => [$session . '$a = &$u; ' . $read, $other];
        yield 'a reference, the other way' => [$session . '$u =& $a; ' . $read, $other];
        yield 'a closure\'s reference' => [$session . '$f = function () use (&$u) {}; ' . $read, $other];
        yield 'the class\'s placeholder after another' => [
            "\$s = 'SELECT id FROM 202_dni_networks WHERE id = ? AND user_id = ?';",
            'not the statement\'s first',
            self::CLASS_FILE,
        ];
        yield 'the class\'s placeholder' => [
            "\$s = 'SELECT id FROM 202_dni_networks WHERE user_id = ? AND id = ?';",
            null,
            self::CLASS_FILE,
        ];
        yield 'a foreach target' => [$session . 'foreach ($rows as $k => $u) {} ' . $read, $other];
        yield 'a destructuring target' => [$session . '[$a, $u] = $pair; ' . $read, $other];
        yield 'a global' => [$session . 'function g() { global $u; } ' . $read, $other];
        yield '$GLOBALS' => [$session . '$GLOBALS[\'u\'] = 7; ' . $read, $other];
        yield 'extract()' => [$session . 'extract($_GET); ' . $read, $other];
        yield 'a variable variable' => [$session . '$$name = 7; ' . $read, $other];
        yield 'the whole array rewritten' => [
            "\$mysql['user_id'] = (int) \$_SESSION['user_id']; \$mysql = \$_GET;"
                . " \$s = \"SELECT id FROM 202_dni_networks WHERE user_id = '{\$mysql['user_id']}'\";",
            $other,
        ];
        yield 'the session, directly' => [
            "\$s = \"SELECT id FROM 202_dni_networks WHERE user_id = '\""
                . " . \$db->real_escape_string((string) \$_SESSION['user_id']) . \"'\";",
            null,
        ];
        yield 'a name the session sets' => [
            "\$mysql = []; \$mysql['user_id'] = \$db->real_escape_string((string)\$_SESSION['user_id']);"
                . " \$s = \"DELETE FROM 202_dni_networks WHERE id = 1 AND user_id = '{\$mysql['user_id']}'\";",
            null,
        ];
        yield 'a name read as an array value' => [$session . '$x = [\'k\' => $u]; ' . $read, null];
        yield 'tied to a tied table' => [
            $session . "\$s = \"SELECT d.id FROM 202_aff_networks AS af LEFT JOIN 202_dni_networks AS d"
                . " ON (d.user_id = af.user_id) WHERE af.user_id = '\$u'\";",
            null,
        ];
        yield 'an insert setting it' => [
            $session . "\$s = \"INSERT INTO 202_dni_networks SET user_id = '\$u', name = 'x'\";",
            null,
        ];
    }

    /**
     * Every shape the first test claims to read, planted.
     *
     * @dataProvider plantedStatements
     */
    public function testTheScanReadsEveryShapeItClaims(
        string $code,
        ?string $refusal,
        string $file = 'planted.php'
    ): void {
        $code = '<?php ' . $code;
        $names = self::sessionNames(self::tokens($code));
        $found = 0;
        foreach (AssembledStrings::of($code) as ['text' => $text, 'holes' => $holes]) {
            if (!self::namesTheTable($text)) {
                continue;
            }
            $found++;
            $why = self::accountProblem($file, $text, $holes, $names);
            if ($refusal === null) {
                self::assertNull($why, $code);
            } else {
                self::assertNotNull($why, "accepted: $code");
                self::assertStringContainsString($refusal, $why, $code);
            }
        }
        self::assertSame(1, $found, "the plant's statement was found: $code");
    }

    /**
     * Every statement naming the table: [file, line, text, holes].
     *
     * @return list<array{string, int, string, list<string>}>
     */
    private static function statements(): array
    {
        $out = [];
        foreach (self::servedFiles() as $file => $code) {
            if (!str_contains($code, self::TABLE) && !str_contains($code, 'DNI_NETWORKS')) {
                continue;
            }
            foreach (AssembledStrings::of($code) as ['line' => $line, 'text' => $text, 'holes' => $holes]) {
                if (!self::namesTheTable($text)) {
                    continue;
                }
                if (trim($text, " `\t\n") === self::TABLE) {
                    $why = "$file:$line names the table outside a statement, which the scan cannot follow"
                        . ' into the SQL it builds';
                    self::assertArrayHasKey($file, self::NAMED_ONLY, $why);
                    continue;
                }
                if (preg_match('/^\s*(?:CREATE|ALTER|DROP|TRUNCATE)\b/i', $text) === 1) {
                    continue;
                }
                $out[] = [$file, $line, $text, $holes];
            }
        }

        return $out;
    }

    private static function namesTheTable(string $text): bool
    {
        return preg_match('/(?<![\w])' . self::TABLE . '(?![\w])/', $text) === 1;
    }

    /**
     * Why a statement does not name the session's account, or null.
     *
     * @param list<string> $holes
     * @param array<string, true>|null $names the file's session names (read from the file when null)
     */
    private static function accountProblem(string $file, string $text, array $holes, ?array $names = null): ?string
    {
        $names ??= self::sessionNames(self::tokens(self::servedFiles()[$file]));
        $verb = strtoupper((string) preg_replace('/^\s*(\w+).*$/s', '$1', $text));
        if (!in_array($verb, ['SELECT', 'UPDATE', 'DELETE', 'INSERT'], true)) {
            return "a statement the scan cannot read ($verb)";
        }
        if (preg_match('/\bOR\b/i', $text) === 1) {
            return 'an OR, which can undo the account\'s condition';
        }
        $table = '/\b(?:FROM|JOIN|UPDATE|INTO)\s+`?' . self::TABLE . '`?(?:\s+(?:AS\s+)?([A-Za-z_]\w*))?/i';
        $named = preg_match_all($table, $text, $m, PREG_SET_ORDER);
        if ($named !== 1) {
            return $named === 0 ? 'the table is named where the scan cannot read it' : 'the table is named twice';
        }
        $alias = $m[0][1] ?? '';
        $keywords = ['WHERE', 'SET', 'ON', 'LEFT', 'RIGHT', 'INNER', 'OUTER', 'CROSS', 'JOIN', 'USING', 'ORDER',
            'GROUP', 'LIMIT', 'STRAIGHT_JOIN'];
        if (in_array(strtoupper($alias), $keywords, true)) {
            $alias = '';
        }
        // Another table in the statement: a bare user_id could be its.
        $joined = preg_match('/\bJOIN\b/i', $text) === 1;
        $references = '/\b(?:FROM|UPDATE)\s+((?:(?!\b(?:WHERE|SET|ORDER|GROUP|LIMIT|HAVING)\b)[^()])*)/i';
        preg_match_all($references, $text, $listed);
        foreach ($listed[1] as $reference) {
            $joined = $joined || str_contains($reference, ',');
        }
        $side = '(?:`?[A-Za-z_]\w*`?\.)?`?user_id`?|\?|\'\x00\'|"\x00"|\x00|\'[^\'\x00]*\'|"[^"\x00]*"|\d+';
        $comparison = '/(?<![\w.`])(' . $side . ')\s*=\s*(' . $side . ')(?![\w.])/';
        if (preg_match_all($comparison, $text, $pairs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            return 'the scan failed';
        }
        $ours = static function (string $side) use ($alias, $joined): bool {
            if (preg_match('/^(?:`?(\w+)`?\.)?`?user_id`?$/', $side, $c) !== 1) {
                return false;
            }
            $qualifier = $c[1] ?? '';

            return $qualifier === '' ? !$joined : ($qualifier === $alias || $qualifier === self::TABLE);
        };
        $fills = [];
        $ties = [];
        foreach ($pairs as $pair) {
            foreach ([[$pair[1], $pair[2]], [$pair[2], $pair[1]]] as [$mine, $other]) {
                if ($ours($mine[0])) {
                    $fills[] = $other;
                } elseif (
                    preg_match(self::USER_ID_COLUMN, $mine[0]) === 1
                    && preg_match(self::USER_ID_COLUMN, $other[0]) !== 1
                ) {
                    $ties[strtolower(str_replace('`', '', $mine[0]))][] = $other;
                }
            }
        }
        if ($fills === []) {
            return 'names no account';
        }
        $verdict = static function (array $fill) use ($text, $holes, $names, $file): ?string {
            [$value, $offset] = $fill;
            if ($value === '?') {
                if ($file !== self::CLASS_FILE) {
                    return 'a `?` outside DniNetworks, whose callers the scan does not hold';
                }

                return strpos($text, '?') === $offset
                    ? null
                    : 'a `?` that is not the statement\'s first, where DniNetworks binds $userId';
            }
            if (str_contains($value, AssembledStrings::UNREAD)) {
                $hole = $holes[substr_count(substr($text, 0, $offset), AssembledStrings::UNREAD)] ?? '';
                $expr = self::expression($hole);

                return preg_match(self::SESSION_ACCOUNT, $expr) === 1 || isset($names[$expr])
                    ? null
                    : "compared with `$hole`, not the session's account";
            }

            return "compared with a literal ($value)";
        };
        foreach ($fills as $fill) {
            if (preg_match(self::USER_ID_COLUMN, $fill[0]) === 1) {
                $tiedBy = $ties[strtolower(str_replace('`', '', $fill[0]))] ?? [];
                $accepted = array_filter($tiedBy, static fn (array $f): bool => $verdict($f) === null);
                if ($accepted === []) {
                    return 'tied to ' . $fill[0] . ', which is not tied to the session\'s account';
                }
                continue;
            }
            $why = $verdict($fill);
            if ($why !== null) {
                return $why;
            }
        }

        return null;
    }

    /** A hole's PHP, as a name: whitespace, an interpolation's braces and a bare key's missing quotes undone. */
    private static function expression(string $hole): string
    {
        $expr = (string) preg_replace('/\s+/', '', $hole);
        if (str_starts_with($expr, '{') && str_ends_with($expr, '}')) {
            $expr = substr($expr, 1, -1);
        }

        return (string) preg_replace('/^(\$\w+)\[(\w+)\]$/', '$1[\'$2\']', $expr);
    }

    /**
     * The names in a file every write of which sets the session's account:
     * `$name` or `$name['key']`.
     *
     * @param list<mixed> $tokens
     * @return array<string, true>
     */
    private static function sessionNames(array $tokens): array
    {
        $assigned = [];
        $spoiled = [];
        $count = count($tokens);
        $compound = [T_CONCAT_EQUAL, T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_MOD_EQUAL,
            T_COALESCE_EQUAL, T_OR_EQUAL, T_AND_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL, T_POW_EQUAL, T_INC, T_DEC];
        foreach ($tokens as $i => $t) {
            if ($t === '$' || self::writesUnnamed($tokens, $i)) {
                return [];
            }
            if (!is_array($t) || $t[0] !== T_VARIABLE) {
                continue;
            }
            $base = $t[1];
            if ($base === '$GLOBALS') {
                $key = $tokens[$i + 2] ?? null;
                if (($tokens[$i + 1] ?? null) !== '[' || !is_array($key) || $key[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    return [];
                }
                $spoiled['$' . substr($key[1], 1, -1)] = true;
                continue;
            }
            $before = $tokens[$i - 1] ?? null;
            if (is_array($before) && $before[0] === T_DOUBLE_COLON) {
                continue;
            }
            $foreachValue = is_array($before) && $before[0] === T_DOUBLE_ARROW && self::inForeachHeader($tokens, $i);
            $declared = is_array($before) && in_array($before[0], [T_AS, T_GLOBAL, T_STATIC], true);
            if (self::isAmpersand($before) || $foreachValue || $declared || self::isParameter($tokens, $i)) {
                $spoiled[$base] = true;
                continue;
            }
            $name = $base;
            $next = $i + 1;
            if (($tokens[$next] ?? null) === '[') {
                $key = $tokens[$next + 1] ?? null;
                if (is_array($key) && $key[0] === T_CONSTANT_ENCAPSED_STRING && ($tokens[$next + 2] ?? null) === ']') {
                    $name = $base . "['" . substr($key[1], 1, -1) . "']";
                    $next += 3;
                } elseif ($key === ']') {
                    continue;
                } else {
                    $close = $next;
                    for ($depth = 0; $close < $count; $close++) {
                        $depth += ($tokens[$close] === '[') ? 1 : (($tokens[$close] === ']') ? -1 : 0);
                        if ($depth === 0) {
                            break;
                        }
                    }
                    if (($tokens[$close + 1] ?? null) === '=') {
                        $spoiled[$base] = true;
                    }
                    continue;
                }
            }
            $op = $tokens[$next] ?? null;
            if ($op === '=' && !self::isAmpersand($tokens[$next + 1] ?? null)) {
                $rhs = self::rightHandSide($tokens, $next + 1);
                if ($name === $base && in_array(strtolower($rhs), ['[]', 'array()'], true)) {
                    continue;
                }
                $assigned[$name][] = $rhs;
            } elseif (
                $op === '=' || (is_array($op) && in_array($op[0], $compound, true))
                || (is_array($before) && in_array($before[0], [T_INC, T_DEC], true))
            ) {
                $spoiled[$name] = true;
            } elseif (in_array($before, ['[', ','], true) || (is_array($before) && $before[0] === T_LIST)) {
                if (self::isDestructuringTarget($tokens, $i)) {
                    $spoiled[$base] = true;
                }
            }
        }
        $names = [];
        foreach ($assigned as $name => $values) {
            $base = (string) preg_replace('/\[.*$/', '', $name);
            if (isset($spoiled[$name]) || isset($spoiled[$base]) || ($base !== $name && isset($assigned[$base]))) {
                continue;
            }
            $other = array_filter($values, static fn (string $v): bool => preg_match(self::SESSION_ACCOUNT, $v) !== 1);
            if ($other === []) {
                $names[$name] = true;
            }
        }

        return $names;
    }

    /**
     * Whether the token at $i writes variables without naming them: eval(),
     * `${…}`, extract() or parse_str().
     *
     * @param list<mixed> $tokens
     */
    private static function writesUnnamed(array $tokens, int $i): bool
    {
        $t = $tokens[$i];
        if (!is_array($t)) {
            return false;
        }

        return in_array($t[0], [T_EVAL, T_DOLLAR_OPEN_CURLY_BRACES], true)
            || ($t[0] === T_STRING && in_array(strtolower($t[1]), ['extract', 'parse_str'], true)
                && ($tokens[$i + 1] ?? null) === '(');
    }

    /**
     * An assignment's right-hand side from $from, without whitespace: up to
     * the `;`, `,` or closing bracket that ends it.
     *
     * @param list<mixed> $tokens
     */
    private static function rightHandSide(array $tokens, int $from): string
    {
        $rhs = '';
        for ($j = $from, $depth = 0; $j < count($tokens); $j++) {
            $u = $tokens[$j];
            if (in_array($u, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($u, [')', ']', '}'], true)) {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif (($u === ';' || $u === ',') && $depth === 0) {
                break;
            }
            $rhs .= is_array($u) ? $u[1] : $u;
        }

        return (string) preg_replace('/\s+/', '', $rhs);
    }

    /** `&`, which PHP 8.1 tokenizes as one of two tokens beside a variable. */
    private static function isAmpersand(mixed $token): bool
    {
        $ampersands = [T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG];

        return $token === '&' || (is_array($token) && in_array($token[0], $ampersands, true));
    }

    /**
     * Whether the variable at $i is inside a `foreach (…)` header.
     *
     * @param list<mixed> $tokens
     */
    private static function inForeachHeader(array $tokens, int $i): bool
    {
        $depth = 0;
        for ($j = $i - 1; $j >= 0; $j--) {
            $u = $tokens[$j];
            if ($u === ')' || $u === ']') {
                $depth++;
            } elseif ($u === '(' || $u === '[') {
                if ($depth === 0) {
                    return $u === '(' && is_array($tokens[$j - 1] ?? null) && $tokens[$j - 1][0] === T_FOREACH;
                }
                $depth--;
            } elseif (in_array($u, [';', '{', '}'], true)) {
                return false;
            }
        }

        return false;
    }

    /**
     * Whether the variable at $i is one of a function's parameters.
     *
     * @param list<mixed> $tokens
     */
    private static function isParameter(array $tokens, int $i): bool
    {
        $depth = 0;
        for ($j = $i - 1; $j >= 0; $j--) {
            $u = $tokens[$j];
            if ($u === ')') {
                $depth++;
            } elseif ($u === '(') {
                if ($depth === 0) {
                    $k = $j - 1;
                    if (is_array($tokens[$k] ?? null) && $tokens[$k][0] === T_STRING) {
                        $k--;
                    }
                    if (self::isAmpersand($tokens[$k] ?? null)) {
                        $k--;
                    }

                    return is_array($tokens[$k] ?? null) && in_array($tokens[$k][0], [T_FUNCTION, T_FN], true);
                }
                $depth--;
            } elseif (in_array($u, [';', '{', '}'], true)) {
                return false;
            }
        }

        return false;
    }

    /**
     * Whether the variable at $i sits in a `[…] =` or `list(…) =` target.
     *
     * @param list<mixed> $tokens
     */
    private static function isDestructuringTarget(array $tokens, int $i): bool
    {
        $depth = 0;
        for ($j = $i - 1; $j >= 0; $j--) {
            $u = $tokens[$j];
            if ($u === ']' || $u === ')') {
                $depth++;
            } elseif ($u === '[' || $u === '(') {
                if ($depth > 0) {
                    $depth--;
                    continue;
                }
                $close = $u === '[' ? ']' : ')';
                $list = $u === '[' || (is_array($tokens[$j - 1] ?? null) && $tokens[$j - 1][0] === T_LIST);
                for ($k = $j, $d = 0; $k < count($tokens); $k++) {
                    if ($tokens[$k] === $u) {
                        $d++;
                    } elseif ($tokens[$k] === $close && --$d === 0) {
                        return $list && ($tokens[$k + 1] ?? null) === '=';
                    }
                }

                return false;
            } elseif (in_array($u, [';', '{', '}'], true)) {
                return false;
            }
        }

        return false;
    }

    /**
     * Whether the `[` at $i is inside the arguments of a call of one of the
     * SENDERS (a global function, not a method).
     *
     * @param list<mixed> $tokens
     */
    private static function insideASenderCall(array $tokens, int $i): bool
    {
        $members = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW];
        $depth = 0;
        for ($j = $i - 1; $j >= 0; $j--) {
            $u = $tokens[$j];
            if ($u === ')' || $u === ']') {
                $depth++;
            } elseif ($u === '(' || $u === '[') {
                if ($depth > 0) {
                    $depth--;
                    continue;
                }
                $callee = $tokens[$j - 1] ?? null;
                $name = is_array($callee) ? SourceScan::callableName($callee[0], $callee[1]) : null;
                $receiver = $tokens[$j - 2] ?? null;
                $isMethod = is_array($receiver) && in_array($receiver[0], $members, true);
                if ($u === '(' && $name !== null && !$isMethod && in_array($name, self::SENDERS, true)) {
                    return true;
                }
            } elseif (in_array($u, [';', '{', '}'], true)) {
                return false;
            }
        }

        return false;
    }

    /**
     * The source of each argument of the call whose `(` is at $open, without whitespace.
     *
     * @param list<mixed> $tokens
     * @return list<string>
     */
    private static function arguments(array $tokens, int $open): array
    {
        $args = [''];
        for ($j = $open + 1, $depth = 0; $j < count($tokens); $j++) {
            $u = $tokens[$j];
            if (in_array($u, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($u, [')', ']', '}'], true)) {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($u === ',' && $depth === 0) {
                $args[] = '';
                continue;
            }
            $args[count($args) - 1] .= is_array($u) ? $u[1] : $u;
        }

        return array_map(static fn (string $a): string => (string) preg_replace('/\s+/', '', $a), $args);
    }

    /** A SELECT's select list: up to its first FROM outside parentheses. */
    private static function selectList(string $text): string
    {
        $body = (string) preg_replace('/^\s*SELECT\b/i', '', $text);
        $depth = 0;
        $length = strlen($body);
        for ($k = 0; $k < $length; $k++) {
            $c = $body[$k];
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
            } elseif (
                $depth === 0 && ($k === 0 || !ctype_alnum($body[$k - 1]))
                && preg_match('/\GFROM\b/i', $body, $m, 0, $k) === 1
            ) {
                return substr($body, 0, $k);
            }
        }

        return $body;
    }

    private static function fold(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', str_replace(AssembledStrings::UNREAD, '…', $text)));
    }

    /**
     * @return list<mixed>
     */
    private static function tokens(string $code): array
    {
        return array_values(array_filter(
            token_get_all($code),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
    }

    /** @return array<string, string> */
    private static function servedFiles(): array
    {
        return array_filter(
            SourceScan::phpFiles(),
            static fn (string $file): bool => !str_starts_with($file, '202-config/PHPStan/')
                && !str_starts_with($file, 'cli/'),
            ARRAY_FILTER_USE_KEY
        );
    }
}
