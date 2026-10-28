<?php

declare(strict_types=1);

namespace Tests\PaymentNotification;

use PHPUnit\Framework\TestCase;
use Tests\Support\AssembledStrings;
use Tests\Support\SourceScan;

/**
 * Which account a payment notification belongs to is decided by the secrets
 * ProviderAdapter::storedSecrets() reads, and AccountSecrets::stored() is
 * the one read: it leaves out deleted accounts, whose preferences rows keep
 * the keys they held (an integration test per adapter runs it against a
 * deleted account storing a live account's key).
 *
 * This holds every adapter's storedSecrets() to that call, with no statement
 * of its own naming 202_users_pref, and every other statement in the
 * payment-notification code and its endpoints that names 202_users_pref to a
 * listed reason: each reads or writes the one account a notification was
 * already decided for.
 *
 * What it reads: strings as PHP assembles them (AssembledStrings: literals
 * joined across `.`, interpolation, heredocs, class constants). What it does
 * not see: a table name built from a part it cannot read, and a statement in
 * a file outside 202-config/PaymentNotification that an adapter calls.
 */
final class StoredSecretsReadThroughAccountSecretsTest extends TestCase
{
    private const ACCOUNT_SECRETS = 'Prosper202\\PaymentNotification\\AccountSecrets';

    /** @var array<string, array<string, string>> file => statement => why it may name 202_users_pref */
    private const ALLOWED = [
        '202-config/PaymentNotification/Processor.php' => [
            'SELECT user_account_currency FROM 202_users_pref WHERE user_id = ? LIMIT 1' => 'the currency of the'
                . ' account the click and the verified secret already decided (Processor::currencyRefusal())',
        ],
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return list<string> repository-relative paths */
    private static function scanned(): array
    {
        $files = [];
        $tree = new \RecursiveIteratorIterator(SourceScan::tree(self::root() . '/202-config/PaymentNotification'));
        foreach ($tree as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = substr($file->getPathname(), strlen(self::root()) + 1);
            }
        }
        foreach (glob(self::root() . '/tracking202/static/*.php') ?: [] as $endpoint) {
            if (str_contains((string) file_get_contents($endpoint), 'PaymentNotification\\')) {
                $files[] = substr($endpoint, strlen(self::root()) + 1);
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @return list<mixed> the file's tokens, without whitespace and comments
     */
    private static function tokens(string $relative): array
    {
        return array_values(array_filter(
            token_get_all((string) file_get_contents(self::root() . '/' . $relative)),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
    }

    /**
     * The fully qualified name a class name in the file stands for.
     *
     * @param list<mixed> $tokens
     */
    private static function resolve(array $tokens, string $name): string
    {
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }
        $namespace = '';
        $imports = [];
        foreach ($tokens as $i => $t) {
            if (!is_array($t)) {
                continue;
            }
            $next = $tokens[$i + 1] ?? null;
            if ($t[0] === T_NAMESPACE && is_array($next)) {
                $namespace = $next[1];
            }
            // A class import at the top level: `use A\B\C;` or `use A\B\C as D;`.
            if ($t[0] === T_USE && is_array($next) && in_array($next[0], [T_NAME_QUALIFIED, T_STRING], true)) {
                $after = $tokens[$i + 2] ?? null;
                $alias = substr($next[1], (int) strrpos('\\' . $next[1], '\\'));
                if (is_array($after) && $after[0] === T_AS && is_array($tokens[$i + 3] ?? null)) {
                    $alias = $tokens[$i + 3][1];
                }
                $imports[strtolower($alias)] = $next[1];
            }
        }
        $first = strtolower(explode('\\', $name)[0]);
        if (isset($imports[$first])) {
            $rest = substr($name, strlen($first));

            return $imports[$first] . $rest;
        }

        return ($namespace !== '' ? $namespace . '\\' : '') . $name;
    }

    /**
     * The adapters in the file: each class that names ProviderAdapter among
     * what it implements, with the tokens of its storedSecrets() body (null
     * when it has none of its own).
     *
     * @param list<mixed> $tokens
     * @return array<string, list<mixed>|null> class name => body tokens
     */
    private static function adapters(array $tokens): array
    {
        $adapters = [];
        foreach ($tokens as $i => $t) {
            if (!is_array($t) || $t[0] !== T_CLASS || !is_array($tokens[$i + 1] ?? null)) {
                continue;
            }
            $class = $tokens[$i + 1][1];
            $implements = [];
            for ($j = $i + 2; isset($tokens[$j]) && $tokens[$j] !== '{'; $j++) {
                $u = $tokens[$j];
                if (is_array($u) && in_array($u[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $implements[] = self::resolve($tokens, $u[1]);
                }
            }
            if (!in_array('Prosper202\\PaymentNotification\\ProviderAdapter', $implements, true)) {
                continue;
            }
            $adapters[$class] = self::methodBody($tokens, $j, 'storedSecrets');
        }

        return $adapters;
    }

    /**
     * @param list<mixed> $tokens
     * @return list<mixed>|null the tokens between the method's braces
     */
    private static function methodBody(array $tokens, int $classBrace, string $method): ?array
    {
        $depth = 0;
        for ($i = $classBrace; isset($tokens[$i]); $i++) {
            $t = $tokens[$i];
            if (self::opens($t)) {
                $depth++;
            } elseif ($t === '}') {
                $depth--;
                if ($depth === 0) {
                    return null;
                }
            } elseif (
                $depth === 1 && is_array($t) && $t[0] === T_FUNCTION
                && is_array($tokens[$i + 1] ?? null) && strcasecmp($tokens[$i + 1][1], $method) === 0
            ) {
                for ($open = $i; isset($tokens[$open]) && $tokens[$open] !== '{'; $open++) {
                }
                $inner = 0;
                for ($k = $open; isset($tokens[$k]); $k++) {
                    $u = $tokens[$k];
                    if (self::opens($u)) {
                        $inner++;
                    } elseif ($u === '}' && --$inner === 0) {
                        return array_slice($tokens, $open + 1, $k - $open - 1);
                    }
                }

                return null;
            }
        }

        return null;
    }

    private static function opens(mixed $token): bool
    {
        return $token === '{'
            || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true));
    }

    /**
     * Whether the body calls AccountSecrets::stored().
     *
     * @param list<mixed> $file the whole file's tokens, to resolve the name
     * @param list<mixed> $body
     */
    private static function callsAccountSecrets(array $file, array $body): bool
    {
        foreach ($body as $i => $t) {
            if (
                is_array($t) && in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && self::resolve($file, $t[1]) === self::ACCOUNT_SECRETS
                && is_array($body[$i + 1] ?? null) && $body[$i + 1][0] === T_DOUBLE_COLON
                && is_array($body[$i + 2] ?? null) && strcasecmp($body[$i + 2][1], 'stored') === 0
                && ($body[$i + 3] ?? null) === '('
                && !(is_array($body[$i - 1] ?? null) && in_array($body[$i - 1][0], [T_NEW, T_FUNCTION], true))
            ) {
                return true;
            }
        }

        return false;
    }

    public function testEveryAdapterReadsItsSecretsThroughAccountSecrets(): void
    {
        $found = [];
        foreach (self::scanned() as $relative) {
            $tokens = self::tokens($relative);
            foreach (self::adapters($tokens) as $class => $body) {
                $found[] = $class;
                self::assertNotNull($body, $relative . ': ' . $class . ' has no storedSecrets() of its own to read');
                self::assertTrue(
                    self::callsAccountSecrets($tokens, $body),
                    $relative . ': ' . $class . '::storedSecrets() reads its secrets other than through'
                    . ' AccountSecrets::stored(), which leaves out deleted accounts'
                );
                $source = '<?php ' . implode('', array_map(
                    static fn ($t): string => is_array($t) ? $t[1] . ' ' : $t,
                    $body
                ));
                foreach (AssembledStrings::of($source) as $string) {
                    self::assertStringNotContainsStringIgnoringCase(
                        '202_users_pref',
                        $string['text'],
                        $relative . ': ' . $class . '::storedSecrets() queries 202_users_pref itself'
                    );
                }
            }
        }
        self::assertContains('PayKickstartAdapter', $found, 'the scan finds the adapters it holds');
    }

    public function testNoOtherStatementNamesThePreferencesUnlessListed(): void
    {
        $seen = [];
        foreach (self::scanned() as $relative) {
            if ($relative === '202-config/PaymentNotification/AccountSecrets.php') {
                continue;
            }
            $source = (string) file_get_contents(self::root() . '/' . $relative);
            foreach (AssembledStrings::of($source) as $string) {
                if (stripos($string['text'], '202_users_pref') === false) {
                    continue;
                }
                self::assertArrayHasKey(
                    $string['text'],
                    self::ALLOWED[$relative] ?? [],
                    $relative . ':' . $string['line'] . ' names 202_users_pref: read a secret that decides the'
                    . ' account through AccountSecrets::stored(), or list the statement here with why it reads one'
                    . ' account already decided'
                );
                $seen[$relative][$string['text']] = true;
            }
        }
        foreach (self::ALLOWED as $relative => $statements) {
            foreach (array_keys($statements) as $statement) {
                self::assertArrayHasKey(
                    $statement,
                    $seen[$relative] ?? [],
                    $relative . ' no longer has: ' . $statement
                );
            }
        }
    }
}
