<?php

/**
 * The setup wizard's pure helpers (202-config/setup-config.php): reading a
 * 202-config.php, telling the legacy format from the current one, and
 * rendering the current one from 202-config-sample.php. Above the Writing
 * line they are pure — no database, no session, no output — and
 * tests/Install/SetupConfigHelpersTest.php calls them directly.
 */

declare(strict_types=1);

/** Matches "$var = 'value';" config lines; \' and \\ are escapes, so any value round-trips. */
const P202_SETUP_CONFIG_LINE_RE = '/(\$\w+) = \'((?:[^\'\\\\]|\\\\.)*)\';/';

/** The settings the wizard writes, and the sample's placeholder for each. */
const P202_SETUP_CONFIG_PLACEHOLDERS = [
    '$dbname' => 'putyourdbnamehere',
    '$dbuser' => 'usernamehere',
    '$dbpass' => 'yourpasswordhere',
    '$dbhost' => 'localhosthere',
    '$dbhostro' => 'localhostreplica',
    '$mchost' => 'localhostmemcache',
];

// Escape a value for inclusion in a single-quoted PHP string in 202-config.php
function escape_config_value(string $value): string
{
    return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
}

// Inverse of escape_config_value(), for reading values back out of the file
function unescape_config_value(string $value): string
{
    return strtr($value, ['\\\\' => '\\', "\\'" => "'"]);
}

/**
 * The settings a 202-config.php assigns, by variable name without the `$`
 * (dbname, dbuser, dbpass, dbhost, dbhostro, mchost); a setting the file
 * does not assign in the plain `$name = '...';` form is absent.
 *
 * @return array<string, string>
 */
function p202_setup_config_values(string $source): array
{
    $values = [];
    foreach (preg_split('/\R/', $source) ?: [] as $line) {
        $matches = [];
        $known = preg_match(P202_SETUP_CONFIG_LINE_RE, $line, $matches) === 1;
        if ($known && isset(P202_SETUP_CONFIG_PLACEHOLDERS[$matches[1]])) {
            $values[substr($matches[1], 1)] = unescape_config_value($matches[2]);
        }
    }
    return $values;
}

/**
 * Whether a 202-config.php is in the legacy format: the plain settings of a
 * release before the `DB` connection class, and nothing else that runs code.
 *
 * The one format the wizard will rewrite on an installed instance
 * (setup-config.php?step=1.1), so it is read narrowly and answers "no"
 * whenever it cannot be sure (error pattern #11): the file must assign
 * $dbname, $dbuser and $dbhost in the plain form, and must not name `DB`
 * anywhere, declare a class or function, or include, require or eval
 * anything — a current configuration, or one an operator extended, is never
 * legacy, so the migration can never rewrite it.
 */
function p202_setup_config_is_legacy(string $source): bool
{
    $values = p202_setup_config_values($source);
    if (!isset($values['dbname'], $values['dbuser'], $values['dbhost'])) {
        return false;
    }
    try {
        $tokens = \PhpToken::tokenize($source);
    } catch (\Throwable) {
        return false;
    }
    foreach ($tokens as $token) {
        if ($token->is(T_INLINE_HTML) && trim($token->text) === '') {
            continue; // the newline after the closing tag
        }
        $code = [
            T_CLASS, T_FUNCTION, T_FN, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_EVAL, T_NEW, T_DOUBLE_COLON,
        ];
        if ($token->is($code) || $token->is(T_INLINE_HTML)) {
            return false;
        }
        if ($token->is(T_STRING) && strcasecmp($token->text, 'DB') === 0) {
            return false;
        }
        if ($token->is(T_NAME_FULLY_QUALIFIED) || $token->is(T_NAME_QUALIFIED)) {
            return false;
        }
    }
    return true;
}

/**
 * The current format, from the sample's lines, with each setting in $values
 * put in place of its placeholder (escaped for a single-quoted string). A
 * setting $values does not name keeps the sample's line.
 *
 * @param list<string> $sampleLines file() of 202-config-sample.php
 * @param array<string, string> $values dbname, dbuser, dbpass, dbhost, dbhostro, mchost
 */
function p202_setup_config_render(array $sampleLines, array $values): string
{
    $out = '';
    foreach ($sampleLines as $line) {
        $matches = [];
        $name = preg_match(P202_SETUP_CONFIG_LINE_RE, $line, $matches) === 1 ? $matches[1] : '';
        $key = substr($name, 1);
        if (isset(P202_SETUP_CONFIG_PLACEHOLDERS[$name], $values[$key])) {
            $line = str_replace(P202_SETUP_CONFIG_PLACEHOLDERS[$name], escape_config_value($values[$key]), $line);
        }
        $out .= $line;
    }
    return $out;
}

// ─── Writing ───────────────────────────────────────────────────────────

/**
 * Replace $path with $content in one rename, so a failed or interrupted
 * write leaves the old file as it was rather than a truncated one. The
 * temporary file sits beside it (a rename is atomic only within one
 * filesystem), is named *.php so a web server that serves it runs it rather
 * than showing the credentials in it, and is owner/group readable only, as
 * the finished file is. False when any step fails; the temporary file is
 * then removed.
 */
function p202_setup_config_replace(string $path, string $content): bool
{
    $temp = dirname($path) . '/.202-config.' . bin2hex(random_bytes(8)) . '.tmp.php';
    $handle = @fopen($temp, 'x');
    if ($handle === false) {
        return false;
    }
    $ok = chmod($temp, 0640)
        && fwrite($handle, $content) === strlen($content)
        && fflush($handle);
    $ok = fclose($handle) && $ok;
    if (!$ok || !rename($temp, $path)) {
        @unlink($temp);
        return false;
    }
    return true;
}
