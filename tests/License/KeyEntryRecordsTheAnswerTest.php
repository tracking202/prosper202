<?php

declare(strict_types=1);

namespace Tests\License;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * Every path that asks the licence service about a key records the answer
 * for the grace period (ShellAccessCache::recordLicence() or recordAnswer(),
 * or p202_record_key_answer()), in the same function, or in the script when
 * the ask is at its top level. A key confirmed where nothing records it
 * counts as never checked once the service cannot be reached.
 *
 * An ask is a call of api_key_validate() or ClickServerKeyValidator::check().
 */
final class KeyEntryRecordsTheAnswerTest extends TestCase
{
    /** file::function (empty for a script's top level) => why it records nothing there. */
    private const EXEMPT = [
        '202-account/ajax/validate-apikey.php::' => 'checks a key and prints the answer; it saves nothing, and'
            . ' PostReadersCheckTokenTest holds it to writing nothing',
        '202-config/functions-auth.php::askLicenceService' => 'the seam is_valid_api_key() asks; it records the answer',
        'api/v3/Controllers/CapabilitiesController.php::askLicenceService' => 'the seam checkKey() asks; it records'
            . ' the whole answer before releasing its claim',
    ];

    private const RECORDERS = ['recordlicence', 'recordanswer', 'p202_record_key_answer'];

    public function testEveryAskRecordsTheAnswer(): void
    {
        $missing = [];
        $exemptSeen = [];
        foreach (self::asks() as [$file, $function, $line, $scope]) {
            $site = $file . '::' . $function;
            if (isset(self::EXEMPT[$site])) {
                $exemptSeen[$site] = true;
                continue;
            }
            if (!self::records($scope)) {
                $missing[] = "$file:$line" . ($function === '' ? '' : " ($function)");
            }
        }
        self::assertSame([], $missing, "These ask the licence service and do not record its answer:\n  "
            . implode("\n  ", $missing)
            . "\nCall ShellAccessCache::recordLicence() (or p202_record_key_answer()) with the answer.");
        self::assertSame(
            [],
            array_values(array_diff(array_keys(self::EXEMPT), array_keys($exemptSeen))),
            'every exemption still names an ask'
        );
    }

    public function testTheScanFindsTheKnownAsks(): void
    {
        $sites = array_map(static fn (array $a): string => $a[0] . '::' . $a[1], self::asks());
        $known = [
            'api-key-required.php::',
            '202-config/install.php::',
            '202-config/functions-account-ui.php::p202_check_typed_key',
            '202-config/functions-account-ui.php::p202_check_customer_key',
        ];
        foreach ($known as $site) {
            self::assertContains($site, $sites, "the scan sees $site");
        }
    }

    /** @param array{int, string, int}|string $token */
    private static function significant(array|string $token): bool
    {
        return !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    /** @param array{int, string, int}|string $token */
    private static function opens(array|string $token): bool
    {
        return $token === '{'
            || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true));
    }

    /** @param list<array{int, string, int}|string> $tokens */
    private static function records(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_STRING && in_array(strtolower($token[1]), self::RECORDERS)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Every ask: [file, enclosing named function or '', line, the tokens of
     * that function's body, or of the whole script].
     *
     * @return list<array{string, string, int, list<array{int, string, int}|string>}>
     */
    private static function asks(): array
    {
        $asks = [];
        foreach (SourceScan::phpFiles() as $file => $source) {
            if (!str_contains($source, 'api_key_validate') && !str_contains($source, 'KeyValidator::check')) {
                continue;
            }
            $tokens = array_values(array_filter(token_get_all($source), self::significant(...)));
            $frames = [];
            $depth = 0;
            $pending = null;
            foreach ($tokens as $i => $token) {
                $text = is_array($token) ? $token[1] : $token;
                $after = $tokens[$i + 1] ?? null;
                if (is_array($token) && $token[0] === T_FUNCTION && is_array($after) && $after[0] === T_STRING) {
                    $pending = $after[1];
                    continue;
                }
                if ($pending !== null && $text === ';') {
                    $pending = null;
                }
                if (self::opens($token)) {
                    $depth++;
                    if ($pending !== null) {
                        $frames[] = [$pending, $depth, $i];
                        $pending = null;
                    }
                    continue;
                }
                if ($text === '}') {
                    if ($frames !== [] && end($frames)[1] === $depth) {
                        array_pop($frames);
                    }
                    $depth--;
                    continue;
                }
                if (!is_array($token) || $token[0] !== T_STRING) {
                    continue;
                }
                $prev = $tokens[$i - 1] ?? null;
                $next = $tokens[$i + 1] ?? null;
                $isCall = $next === '(';
                $ask = false;
                $called = strtolower($token[1]);
                $member = is_array($prev) && in_array($prev[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
                if ($isCall && $called === 'api_key_validate') {
                    $ask = !$member;
                } elseif ($isCall && $called === 'check' && is_array($prev) && $prev[0] === T_DOUBLE_COLON) {
                    $class = $tokens[$i - 2] ?? null;
                    $name = is_array($class) ? $class[1] : '';
                    $ask = str_ends_with($name, 'ClickServerKeyValidator');
                }
                if (!$ask) {
                    continue;
                }
                $frame = $frames === [] ? null : $frames[0];
                $asks[] = [
                    $file,
                    $frame === null ? '' : $frame[0],
                    $token[2],
                    $frame === null ? $tokens : self::body($tokens, $frame[2]),
                ];
            }
        }
        return $asks;
    }

    /**
     * The tokens from the brace at $open to the one that closes it.
     *
     * @param list<array{int, string, int}|string> $tokens
     * @return list<array{int, string, int}|string>
     */
    private static function body(array $tokens, int $open): array
    {
        $depth = 0;
        $out = [];
        for ($i = $open; $i < count($tokens); $i++) {
            $text = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
            $out[] = $tokens[$i];
            if (self::opens($tokens[$i])) {
                $depth++;
            } elseif ($text === '}' && --$depth === 0) {
                break;
            }
        }
        return $out;
    }
}
