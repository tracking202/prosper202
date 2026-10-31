<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;
use Prosper202\User\ServedPreferences;
use Tests\Support\SourceScan;

/**
 * No served file prints an integration secret (CLAUDE.md #5, #21).
 *
 * Account › API integrations wrote the account's IPQS, ClickBank and Zaxaa
 * keys, its Slack webhook and its JVZIPN key into each card's input, and a
 * refused save wrote back what was posted; PayKickstart showed the last four
 * characters of an 8-character key. The page now says only whether a secret
 * is saved and how a long one ends (SavedSecret), and an empty field keeps it.
 *
 * What it reads, per file, from the tokens (every served file that names a
 * preference secret, and the page and 202-account/ajax/ in full):
 *
 *  - A secret's value is read by a subscript that names a secret
 *    (ServedPreferences::SECRET and the PayKickstart form field; in full,
 *    also the DNI key and the account's own keys; a literal, `"$row[name]"`,
 *    or JVZooKey::COLUMN / PayKickstartKeys::COLUMN), and in full by any
 *    subscript of $_POST, $_GET, $_REQUEST, $_COOKIE or a database row
 *    (`$user_row`, or a variable assigned a fetch) that is not one literal:
 *    `$user_row[$integration['field']]`.
 *  - A variable is tainted when it is assigned (`=`, `.=`, `??=`, a
 *    destructuring, a foreach value) from anything that reads a secret or
 *    names a tainted variable; `$x['k'] = …` taints that element only. A
 *    closure assigned to a variable is part of its right-hand side.
 *  - What a SANITIZER call is handed does not count: each answers less than
 *    the secret (whether one is saved, a hint, a count, an HMAC).
 *  - No echo, print, `<?=`, exit/die, printf-like call or flash holds a read
 *    or a tainted variable, unless LISTED says why.
 *
 * What it does not see: a function that returns a secret from arguments that
 * are not one, or from a whole row (DniNetworks::keptKey(), whose caller's result is held by
 * DniNetworksReadOnlyTheAccountsTest's KEY_READS), a column the SQL renames
 * (`user_slack_incoming_webhook AS url`), a name an included file writes,
 * and an API answer (ServedPreferencesTest holds those). Scoping is per file,
 * not per function, so it can refuse more than it should, never less.
 * ApiIntegrationsAccountScopeIntegrationTest reads the rendered page.
 */
final class IntegrationSecretsAreNotRenderedTest extends TestCase
{
    private const PAGE = '202-account/api-integrations.php';

    /** Form fields and account columns that carry a secret, beside the preference columns. */
    private const MORE_SECRETS = [
        'apiKey', 'dni_network_api_key', 'paykickstart_ipn_secret_key', 'p202_customer_api_key',
        'install_hash', 'user_api_key', 'user_stats202_app_key', 'user_pass',
    ];

    /** Class constants that name a secret column. */
    private const COLUMN_CLASSES = ['JVZooKey', 'PayKickstartKeys'];

    /** @var array<string, string> callee => what it answers instead of the secret */
    private const SANITIZERS = [
        'SavedSecret::hint' => '"Saved, ends in XXXX", "Saved" or "Not saved"',
        'SavedSecret::hintFrom' => 'the same, from a length and an ending',
        'SavedSecret::isSaved' => 'whether one is saved',
        'SavedSecret::keeps' => 'whether a posted field was left empty',
        'PayKickstartKeys::hint' => 'the last four characters of a key of 12 or more, else ""',
        'PayKickstartKeys::removalToken' => 'an HMAC of the key under a secret that never leaves the server',
        'JVZooKey::unreadable' => 'whether the stored key cannot be one',
        'PairingWebhook::state' => 'the optimizer webhook\'s state and id, read from the bridge config',
        'DimensionSync::pushForUser' => 'how many dimensions it sent the optimizer',
        'getAllDniNetworks' => 'the hosted service\'s list of networks, asked with the install hash',
        'count' => 'how many',
    ];

    private const SINK_CALLS = ['printf', 'vprintf', 'print_r', 'var_dump', 'var_export', 'p202_account_flash'];

    private const SUPERGLOBALS = ['$_POST', '$_GET', '$_REQUEST', '$_COOKIE'];

    private const FETCHES = [
        'fetch_assoc', 'fetch_array', 'fetch_row', 'fetch_all', 'fetchone', 'fetchall', 'p202fetchrow',
    ];

    /** @var array<string, array<string, string>> file => the line a secret is printed on => why */
    private const LISTED = [
        '202-account/ajax/update-needed.php' => [
            '\'has_customer_key\' => $hasCustomerKey,'
                => 'whether the account has a customer API key: a comparison with \'\'',
        ],
        self::PAGE => [
            '<?php echo $endpoint(\'Site key\', $lpo_site_key); ?>' => 'the Landing Page Optimizer\'s site key,'
                . ' which the hosted service issues at pairing and the card shows to copy; the API answers it as'
                . ' stored-only, and whether the page should too is left to its owner (backlog 03VE)',
        ],
    ];

    public function testNoServedFilePrintsAnIntegrationSecret(): void
    {
        $files = self::filesThatNameASecret();
        self::assertArrayHasKey(self::PAGE, $files, 'the scan reads the integrations page');
        $found = [];
        $used = [];
        foreach ($files as $file => $source) {
            foreach (self::findings($source, self::isStrict($file)) as [$line, $text]) {
                if (isset(self::LISTED[$file][$text])) {
                    $used[$file][$text] = true;
                    continue;
                }
                $found[] = "$file:$line: $text";
            }
        }
        self::assertSame([], $found, "a secret reaches the page; show SavedSecret::hint() of it instead:\n"
            . implode("\n", $found));
        foreach (self::LISTED as $file => $lines) {
            foreach (array_keys($lines) as $text) {
                self::assertArrayHasKey($text, $used[$file] ?? [], "$file no longer prints `$text`: unlist it");
            }
        }
    }

    public function testEveryKeyCardIsForASecretColumn(): void
    {
        $page = SourceScan::phpFiles()[self::PAGE];
        preg_match_all("/'field' => '([a-z0-9_]+)'/", $page, $m);
        self::assertCount(4, $m[1], 'the four key cards');
        foreach ($m[1] as $field) {
            self::assertContains($field, ServedPreferences::SECRET, "the $field card shows a secret column");
        }
    }

    /** @return iterable<string, array{string}> */
    public static function planted(): iterable
    {
        $o = '<?php $e = fn ($v) => $v; ';
        yield 'a saved key as a value' => [$o . '?><input value="<?php echo $e($user_row[\'cb_key\']); ?>">'];
        yield 'a short echo tag' => [$o . '?><input value="<?= $user_row[\'ipqs_api_key\'] ?>">'];
        yield 'a posted key written back' => [
            $o . '?><input value="<?php echo $e($_POST[\'zaxaa_api_signature\'] ?? \'\'); ?>">',
        ];
        yield 'a card\'s field' => [$o . 'foreach ($cards as $c) { echo $user_row[$c[\'field\']]; }'];
        yield 'a posted field by name' => [$o . 'echo $_POST[$name];'];
        yield 'a class constant' => [$o . 'echo $user_row[JVZooKey::COLUMN];'];
        yield 'a copy' => [$o . '$s = trim((string) ($user_row[\'user_slack_incoming_webhook\'] ?? \'\')); echo $s;'];
        yield 'a copy of a copy, concatenated' => [$o . '$a = $user_row[\'cb_key\']; $b = \'k\'; $b .= $a; print $b;'];
        yield 'a closure that reads it' => [$o . '$v = static function (string $f) use ($user_row): string {'
            . ' return (string) $user_row[$f]; }; ?><input value="<?php echo $e($v(\'cb_key\')); ?>">'];
        yield 'an arrow function' => [$o . '$p = fn ($n) => $_POST[$n]; echo $p(\'jvzoo_ipn_secret_key\');'];
        yield 'a list\'s element' => [$o . '$keys = explode("\n", $user_row[PayKickstartKeys::COLUMN]);'
            . ' foreach ($keys as $k) { echo $k; }'];
        yield 'a data attribute' => [$o . '?><pre data-p202-value="<?php echo $e($row[\'apiKey\']); ?>"></pre>'];
        yield 'an inline script' => [
            $o . '?><script>var k = <?php echo json_encode($user_row[\'jvzoo_ipn_secret_key\']); ?>;</script>',
        ];
        yield 'interpolated' => [$o . 'echo "<b>$user_row[cb_key]</b>";'];
        yield 'interpolated in braces' => [$o . 'echo "<b>{$user_row[\'cb_key\']}</b>";'];
        yield 'a fetched row' => [$o . '$r = $res->fetch_assoc(); echo $r[$col];'];
        yield 'a destructured pair' => [$o . '[$k, $why] = [$user_row[\'cb_key\'], \'\']; echo $k;'];
        yield 'an element' => [$o . '$m[\'k\'] = $user_row[\'cb_key\']; echo $m[\'k\'];'];
        yield 'a flash' => [$o . 'p202_account_flash(\'ok\', \'Saved \' . $_POST[\'cb_key\']);'];
        yield 'printf' => [$o . 'printf(\'%s\', $user_row[\'zaxaa_api_signature\']);'];
        yield 'die' => [$o . 'die($user_row[\'ipqs_api_key\']);'];
        yield 'a sanitizer\'s answer used beside the value' => [
            $o . 'echo SavedSecret::hint($x) . $user_row[\'cb_key\'];',
        ];
    }

    /** @dataProvider planted */
    public function testTheScanFindsEveryShapeItClaims(string $source): void
    {
        self::assertNotSame([], self::findings($source), 'the scan missed a planted secret');
    }

    /** @return iterable<string, array{string}> */
    public static function clean(): iterable
    {
        $o = '<?php $e = fn ($v) => $v; ';
        yield 'a hint' => [$o . 'echo $e(SavedSecret::hint($user_row[\'cb_key\'] ?? null));'];
        yield 'whether one is saved' => [$o . '$saved = SavedSecret::isSaved($user_row[$card[\'field\']] ?? null);'
            . ' echo $saved ? \'Update\' : \'Connect\';'];
        yield 'a field\'s name' => [
            $o . '?><input id="<?php echo $e($card[\'field\']); ?>" name="<?php echo $e(JVZooKey::COLUMN); ?>">',
        ];
        yield 'a key named in an array literal' => [$o . '$errors = [JVZooKey::COLUMN => $error[\'jvzoo\']];'
            . ' echo p202_account_field_error($errors, JVZooKey::COLUMN);'];
        yield 'another element of a tainted array' => [
            $o . '$m[\'key\'] = $_POST[\'cb_key\']; $m[\'id\'] = 1; echo $m[\'id\'];',
        ];
        yield 'a posted value that is not a secret' => [$o . 'echo $e($_POST[\'dni_network_name\'] ?? \'\');'];
        yield 'how many keys' => [$o . '$pk = explode(",", $user_row[PayKickstartKeys::COLUMN]); echo count($pk);'];
        yield 'a foreach key' => [$o . '$stored = explode(",", $user_row[PayKickstartKeys::COLUMN]);'
            . ' foreach ($stored as $place => $key) { echo PayKickstartKeys::hint($key), $place; }'];
    }

    /** @dataProvider clean */
    public function testTheScanAcceptsWhatPrintsNoSecret(string $source): void
    {
        self::assertSame([], self::findings($source));
    }

    /** The page and its endpoints: every subscript of a request or a row counts, and the account's own keys too. */
    private static function isStrict(string $file): bool
    {
        return $file === self::PAGE || str_starts_with($file, '202-account/ajax/');
    }

    /** @return list<string> */
    private static function secretNames(bool $strict): array
    {
        return [...ServedPreferences::SECRET, 'paykickstart_ipn_secret_key', ...($strict ? self::MORE_SECRETS : [])];
    }

    /** @return array<string, string> served files that name an integration secret, and the page's endpoints */
    private static function filesThatNameASecret(): array
    {
        $names = implode('|', array_map('preg_quote', self::secretNames(false)));
        $pattern = '/\b(?:' . $names . ')\b|\b(?:JVZooKey|PayKickstartKeys)::COLUMN\b/';

        return array_filter(
            SourceScan::phpFiles(),
            static fn (string $source, string $file): bool => !str_starts_with($file, '202-config/PHPStan/')
                && !str_starts_with($file, 'cli/') && (self::isStrict($file) || preg_match($pattern, $source) === 1),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * Each print of a secret in $source: [line, the line's trimmed text].
     *
     * @return list<array{int, string}>
     */
    private static function findings(string $source, bool $strict = true): array
    {
        $names = self::secretNames($strict);
        $lines = explode("\n", $source);
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $masked = self::masked($tokens);
        $rows = ['$user_row' => true];
        /** @var array<string, true|array<string, true>> $tainted */
        $tainted = [];
        do {
            $before = [$rows, $tainted];
            self::propagate($tokens, $masked, $names, $strict, $rows, $tainted);
        } while ($before !== [$rows, $tainted]);

        $found = [];
        foreach (self::sinks($tokens) as [$from, $to]) {
            for ($i = $from; $i < $to; $i++) {
                if (!$masked[$i] && self::readsASecret($tokens, $i, $names, $strict, $rows, $tainted)) {
                    $line = self::lineOf($tokens, $i);
                    $found[] = [$line, trim($lines[$line - 1] ?? '')];
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * @param list<mixed> $tokens
     * @return list<bool> whether each token is inside a sanitizer's arguments
     */
    private static function masked(array $tokens): array
    {
        $masked = array_fill(0, count($tokens), false);
        foreach ($tokens as $i => $t) {
            $open = self::sanitizerOpen($tokens, $i);
            if ($open === null) {
                continue;
            }
            $close = self::closing($tokens, $open);
            for ($j = $open; $j <= $close; $j++) {
                $masked[$j] = true;
            }
        }

        return $masked;
    }

    /** @param list<mixed> $tokens the index of a sanitizer call's `(`, when one starts at $i */
    private static function sanitizerOpen(array $tokens, int $i): ?int
    {
        $t = $tokens[$i];
        if (!is_array($t) || !in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }
        $short = static fn (string $name): string => strtolower(substr($name, (int) strrpos('\\' . $name, '\\')));
        $prev = $tokens[$i - 1] ?? null;
        $functions = array_map(
            'strtolower',
            array_filter(array_keys(self::SANITIZERS), static fn ($n) => !str_contains($n, '::'))
        );
        $member = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW];
        if (
            ($tokens[$i + 1] ?? null) === '(' && in_array($short($t[1]), $functions, true)
            && !(is_array($prev) && in_array($prev[0], $member, true))
        ) {
            return $i + 1;
        }
        $colon = $tokens[$i + 1] ?? null;
        $method = $tokens[$i + 2] ?? null;
        if (
            is_array($colon) && $colon[0] === T_DOUBLE_COLON && is_array($method) && $method[0] === T_STRING
            && ($tokens[$i + 3] ?? null) === '('
        ) {
            foreach (array_keys(self::SANITIZERS) as $name) {
                [$class, $fn] = array_pad(explode('::', strtolower($name)), 2, '');
                if ($fn !== '' && $short($t[1]) === $class && strtolower($method[1]) === $fn) {
                    return $i + 3;
                }
            }
        }

        return null;
    }

    /**
     * One pass over every assignment and foreach: what it writes becomes a
     * row when it is handed one, and tainted when it is handed a secret.
     *
     * @param list<mixed> $tokens
     * @param list<bool> $masked
     * @param list<string> $names
     * @param array<string, true> $rows
     * @param array<string, true|array<string, true>> $tainted
     */
    private static function propagate(
        array $tokens,
        array $masked,
        array $names,
        bool $strict,
        array &$rows,
        array &$tainted
    ): void {
        $assignments = ['=', T_CONCAT_EQUAL, T_COALESCE_EQUAL, T_PLUS_EQUAL];
        foreach ($tokens as $k => $t) {
            $id = is_array($t) ? $t[0] : $t;
            if (in_array($id, $assignments, true)) {
                $targets = self::targets($tokens, $k);
                $end = self::expressionEnd($tokens, $k + 1);
                self::flow($tokens, $masked, $names, $strict, $k + 1, $end, $targets, $rows, $tainted);
            } elseif ($id === T_AS) {
                $open = self::enclosingParen($tokens, $k);
                if ($open === null) {
                    continue;
                }
                $close = self::closing($tokens, $open);
                // `as $key => $value`: the value is what the list holds.
                $arrow = null;
                for ($j = $k + 1; $j < $close; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_ARROW) {
                        $arrow = $j;
                    }
                }
                $targets = [];
                for ($j = ($arrow ?? $k) + 1; $j < $close; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_VARIABLE) {
                        $targets[] = [$tokens[$j][1], null];
                    }
                }
                self::flow($tokens, $masked, $names, $strict, $open + 1, $k, $targets, $rows, $tainted);
            }
        }
    }

    /**
     * @param list<mixed> $tokens
     * @param list<bool> $masked
     * @param list<string> $names
     * @param list<array{string, string|null}> $targets variable, and the literal element it writes or null
     * @param array<string, true> $rows
     * @param array<string, true|array<string, true>> $tainted
     */
    private static function flow(
        array $tokens,
        array $masked,
        array $names,
        bool $strict,
        int $from,
        int $to,
        array $targets,
        array &$rows,
        array &$tainted
    ): void {
        $secret = false;
        $row = false;
        for ($i = $from; $i < $to; $i++) {
            if ($masked[$i]) {
                continue;
            }
            $t = $tokens[$i];
            if (
                is_array($t) && in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && in_array(strtolower(substr($t[1], (int) strrpos('\\' . $t[1], '\\'))), self::FETCHES, true)
            ) {
                $row = true;
            }
            if (is_array($t) && $t[0] === T_VARIABLE && isset($rows[$t[1]]) && ($tokens[$i + 1] ?? null) !== '[') {
                $row = true;
            }
            if (self::readsASecret($tokens, $i, $names, $strict, $rows, $tainted)) {
                $secret = true;
            }
        }
        foreach ($targets as [$name, $element]) {
            if ($row && $element === null) {
                $rows[$name] = true;
            }
            if (!$secret || ($tainted[$name] ?? null) === true) {
                continue;
            }
            if ($element === null) {
                $tainted[$name] = true;
            } else {
                $tainted[$name] = [...(is_array($tainted[$name] ?? null) ? $tainted[$name] : []), $element => true];
            }
        }
    }

    /**
     * What the assignment at $k writes: one variable (and the literal
     * element, when that is all it writes), or every variable of a
     * destructuring.
     *
     * @param list<mixed> $tokens
     * @return list<array{string, string|null}>
     */
    private static function targets(array $tokens, int $k): array
    {
        $j = $k - 1;
        $subscripts = [];
        while (($tokens[$j] ?? null) === ']') {
            $open = self::opening($tokens, $j);
            $subscripts[] = [$open, $j];
            $j = $open - 1;
        }
        $t = $tokens[$j] ?? null;
        if (is_array($t) && $t[0] === T_VARIABLE) {
            $element = null;
            if (count($subscripts) === 1) {
                [$open, $close] = $subscripts[0];
                $element = $close === $open + 2 ? self::literalKey($tokens[$open + 1]) : null;
            }

            return [[$t[1], $element]];
        }
        if ($subscripts !== []) {
            // A destructuring: `[$a, $b] = …` (the last bracket read is its opening one).
            $out = [];
            [$open] = end($subscripts);
            for ($i = $open; $i < $k; $i++) {
                if (is_array($tokens[$i]) && $tokens[$i][0] === T_VARIABLE) {
                    $out[] = [$tokens[$i][1], null];
                }
            }

            return $out;
        }
        if ($t === ')') {
            // `list($a, $b) = …`
            $out = [];
            for ($i = self::opening($tokens, $j); $i < $k; $i++) {
                if (is_array($tokens[$i]) && $tokens[$i][0] === T_VARIABLE) {
                    $out[] = [$tokens[$i][1], null];
                }
            }

            return $out;
        }

        return [];
    }

    /**
     * Whether the token at $i reads a secret: a subscript that names one, a
     * superglobal's or a row's subscript that is not one literal, or a
     * tainted variable. The last two only when $strict.
     *
     * @param list<mixed> $tokens
     * @param list<string> $names
     * @param array<string, true> $rows
     * @param array<string, true|array<string, true>> $tainted
     */
    private static function readsASecret(
        array $tokens,
        int $i,
        array $names,
        bool $strict,
        array $rows,
        array $tainted
    ): bool {
        $t = $tokens[$i];
        if (!is_array($t) || $t[0] !== T_VARIABLE) {
            return false;
        }
        $name = $t[1];
        $open = $i + 1;
        if (($tokens[$open] ?? null) !== '[') {
            return isset($tainted[$name]);
        }
        $close = self::closing($tokens, $open);
        $only = $close === $open + 2 ? self::literalKey($tokens[$open + 1]) : null;
        for ($j = $open + 1; $j < $close; $j++) {
            if (self::namesASecret($tokens, $j, $names)) {
                return true;
            }
        }
        if ($strict && (in_array($name, self::SUPERGLOBALS, true) || isset($rows[$name])) && $only === null) {
            return true;
        }
        $taint = $tainted[$name] ?? null;

        return $taint === true || (is_array($taint) && ($only === null || isset($taint[$only])));
    }

    /**
     * @param list<mixed> $tokens
     * @param list<string> $names
     */
    private static function namesASecret(array $tokens, int $j, array $names): bool
    {
        $t = $tokens[$j];
        if (!is_array($t)) {
            return false;
        }
        if (
            in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_STRING], true)
            && in_array(self::literalKey($t), $names, true)
        ) {
            return true;
        }
        if (
            in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            && in_array(substr($t[1], (int) strrpos('\\' . $t[1], '\\')), self::COLUMN_CLASSES, true)
        ) {
            $colon = $tokens[$j + 1] ?? null;
            $const = $tokens[$j + 2] ?? null;

            return is_array($colon) && $colon[0] === T_DOUBLE_COLON && is_array($const) && $const[1] === 'COLUMN';
        }

        return false;
    }

    /** @return string|null a literal subscript's key */
    private static function literalKey(mixed $t): ?string
    {
        if (!is_array($t)) {
            return null;
        }
        if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
            return stripslashes(substr($t[1], 1, -1));
        }

        return in_array($t[0], [T_LNUMBER, T_STRING, T_NUM_STRING], true) ? $t[1] : null;
    }

    /**
     * Every printed expression: [first token, end] for echo, print, `<?=`,
     * exit/die and the SINK_CALLS.
     *
     * @param list<mixed> $tokens
     * @return list<array{int, int}>
     */
    private static function sinks(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $i => $t) {
            if (!is_array($t)) {
                continue;
            }
            if (in_array($t[0], [T_ECHO, T_PRINT, T_OPEN_TAG_WITH_ECHO, T_EXIT], true)) {
                $out[] = [$i + 1, self::expressionEnd($tokens, $i + 1, true)];
            } elseif (
                $t[0] === T_STRING && in_array(strtolower($t[1]), self::SINK_CALLS, true)
                && ($tokens[$i + 1] ?? null) === '('
            ) {
                $out[] = [$i + 2, self::closing($tokens, $i + 1)];
            }
        }

        return $out;
    }

    /**
     * Where the expression starting at $from ends: a `;`, a close tag, or a
     * bracket it did not open (a `,` too, unless $commas, as in `echo a, b`).
     *
     * @param list<mixed> $tokens
     */
    private static function expressionEnd(array $tokens, int $from, bool $commas = false): int
    {
        $depth = 0;
        $n = count($tokens);
        for ($i = $from; $i < $n; $i++) {
            $t = $tokens[$i];
            if (in_array($t, ['(', '[', '{'], true) || self::is($t, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif (in_array($t, [')', ']', '}'], true)) {
                if ($depth === 0) {
                    return $i;
                }
                $depth--;
            } elseif ($depth === 0 && ($t === ';' || ($t === ',' && !$commas) || self::is($t, [T_CLOSE_TAG]))) {
                return $i;
            }
        }

        return $n;
    }

    /** @param list<mixed> $tokens the index of the bracket that closes the one at $open */
    private static function closing(array $tokens, int $open): int
    {
        $depth = 0;
        $n = count($tokens);
        for ($i = $open; $i < $n; $i++) {
            $t = $tokens[$i];
            if (in_array($t, ['(', '[', '{'], true) || self::is($t, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif (in_array($t, [')', ']', '}'], true) && --$depth === 0) {
                return $i;
            }
        }

        return $n - 1;
    }

    /** @param list<mixed> $tokens the bracket that the `]` or `)` at $close closes */
    private static function opening(array $tokens, int $close): int
    {
        $open = $tokens[$close] === ']' ? '[' : '(';
        $depth = 0;
        for ($i = $close; $i >= 0; $i--) {
            if ($tokens[$i] === $tokens[$close]) {
                $depth++;
            } elseif ($tokens[$i] === $open && --$depth === 0) {
                return $i;
            }
        }

        return 0;
    }

    /** @param list<mixed> $tokens the `(` of the parenthesis $k sits in, if any */
    private static function enclosingParen(array $tokens, int $k): ?int
    {
        $depth = 0;
        for ($i = $k - 1; $i >= 0; $i--) {
            if ($tokens[$i] === ')') {
                $depth++;
            } elseif ($tokens[$i] === '(' && $depth-- === 0) {
                return $i;
            }
        }

        return null;
    }

    /** @param list<int> $ids */
    private static function is(mixed $t, array $ids): bool
    {
        return is_array($t) && in_array($t[0], $ids, true);
    }

    /** @param list<mixed> $tokens */
    private static function lineOf(array $tokens, int $i): int
    {
        for (; $i >= 0; $i--) {
            if (is_array($tokens[$i])) {
                return $tokens[$i][2];
            }
        }

        return 1;
    }
}
