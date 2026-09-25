<?php

declare(strict_types=1);

namespace Tests\Apps\Android;

use Tests\TestCase;

/**
 * Production builds its notification outbox with the operator's correction
 * URLs (plan §5.13).
 *
 * NotificationOutbox takes the destination's correction URL from a
 * resolver, and its default is CorrectionUrls::resolver(). A production
 * call that passed a resolver of its own — `fn () => null` — would record
 * every correction `suppressed` while Setup › Traffic Sources shows the
 * URLs as saved: the configuration accepted and never used. Tests pass
 * resolvers on purpose; code outside tests/ must not, or must pass the
 * configured one by name. This walks every `new NotificationOutbox(` outside
 * tests/ and vendor/ and reads its argument list at depth zero.
 */
final class OutboxResolverWiringTest extends TestCase
{
    public function testEveryProductionOutboxUsesTheConfiguredResolver(): void
    {
        $root = dirname(__DIR__, 3);
        $found = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = substr($file->getPathname(), strlen($root) + 1);
            if ($file->getExtension() !== 'php' || preg_match('~^(tests|vendor|node_modules|\.git|\.claude)/~', $path) === 1) {
                continue;
            }
            $tokens = token_get_all((string) file_get_contents($file->getPathname()));
            $count = count($tokens);
            for ($i = 0; $i < $count; $i++) {
                if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_NEW) {
                    continue;
                }
                $j = $i + 1;
                while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                $name = is_array($tokens[$j] ?? null) ? ltrim($tokens[$j][1], '\\') : '';
                if ($name !== 'NotificationOutbox' && $name !== 'Prosper202\\Notifications\\NotificationOutbox') {
                    continue;
                }
                $found++;
                $args = self::arguments($tokens, $j + 1);
                $line = $tokens[$i][2];
                if (count($args) >= 4) {
                    self::assertSame('CorrectionUrls::resolver(', substr(preg_replace('/\s+|\\\\?Prosper202\\\\Notifications\\\\/', '', $args[3]) ?? '', 0, 25),
                        "$path:$line passes its own correction-URL resolver; production uses the configured one (CorrectionUrls::resolver())");
                }
            }
        }
        self::assertGreaterThanOrEqual(2, $found, 'the scan finds the production outboxes (GoalEngine, the app-installs worker)');
    }

    /**
     * The top-level argument texts of the call whose `(` is at or after $at.
     *
     * @param list<mixed> $tokens
     * @return list<string>
     */
    private static function arguments(array $tokens, int $at): array
    {
        $count = count($tokens);
        while ($at < $count && $tokens[$at] !== '(') {
            $at++;
        }
        $depth = 0;
        $args = [];
        $current = '';
        for ($k = $at; $k < $count; $k++) {
            $t = $tokens[$k];
            $text = is_array($t) ? $t[1] : $t;
            if (in_array($text, ['(', '[', '{'], true) || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0) {
                    if (trim($current) !== '') {
                        $args[] = trim($current);
                    }
                    return $args;
                }
            } elseif ($text === ',' && $depth === 1) {
                $args[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $text;
        }

        return $args;
    }
}
