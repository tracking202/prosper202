<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;

/**
 * Every settings form on the Account pages carries the version of what it
 * showed, and its save is refused when what is stored has changed since
 * (ShownSettings, p202_account_require_shown()).
 *
 * A form that posts every field rewrites every field, so two windows of one
 * account that each opened a settings page and saved would each write what
 * they read, and the later save would drop the other's change.
 *
 * What it proves:
 *
 *   - Every `<form method="post">` in 202-account/*.php, and in the
 *     202-config/functions-*.php helpers those pages require, either renders
 *     p202_account_shown_field() inside its own bounds or is listed in
 *     NOT_SETTINGS with why. A listed form is found by a marker that must
 *     match exactly one form of its file, so a list entry cannot outlive or
 *     stand for more than its form.
 *   - Each form name a field is rendered under has a guard in the file that
 *     handles the post (p202_account_require_shown(), or a page's
 *     *RequireShown() closure over it), and each guarded name has a field: a
 *     save checked against a version no form sends refuses every save, and a
 *     version no save reads protects nothing.
 *
 * It does NOT prove the guard runs before the write it protects, nor that the
 * values a guard reads are the ones its form shows; that is the integration
 * test (SettingsSaveOverNewerVersionIntegrationTest) for the PayKickstart
 * keys, and tests/live/account-pages.sh for the pages it drives.
 */
final class SettingsFormsCarryTheirVersionTest extends TestCase
{
    /**
     * Forms that post but are not a settings form, by file and a marker found
     * in exactly one form of that file, each with why it needs no version.
     */
    private const NOT_SETTINGS = [
        '202-account/account.php' => [
            'name="campaign_currency_notice" value="switch"' => 'answers a notice; changes no shown value',
            'name="campaign_currency_notice" value="keep"' => 'answers a notice; changes no shown value',
            'name="record_earlier_currency"' => 'adds a currency history row; rewrites nothing',
            'name="add_rest_api_key"' => 'makes a new key; rewrites nothing',
            'name="replace_rest_api_key"' => 'acts on the key it names',
            'name="remove_rest_api_key"' => 'acts on the key it names',
            'name="change_user_pass"' => 'checks the current password, which is its version',
        ],
        '202-account/administration.php' => [
            'name="database_management"' => 'deletes clicks before a date; rewrites no setting',
            'name="geoip_action" value="refresh"' => 'starts a download; rewrites no setting',
            'name="geoip_action" value="forget"' => 'removes the credentials; posts no values',
            'name="egress_action" value="forget"' => 'removes the saved proxy; posts no values',
        ],
        '202-account/api-integrations.php' => [
            'name="postback_auth_action" value="rotate"' => 'names the credential it rotates',
            'name="delete_dni_network"' => 'deletes the network it names',
            'name="lpo_action" value="disconnect"' => 'posts no values',
            'name="lpo_retry"' => 'retries the pairing; posts no values',
            'type="submit">Connect</button>' => 'starts the pairing; posts no values',
        ],
        '202-account/attribution.php' => [
            'value="set_default"' => 'acts on the model it names',
            'value="set_status"' => 'acts on the model it names',
            'value="delete_model"' => 'deletes the model it names',
            'value="create_export"' => 'makes a new export; rewrites nothing',
            'value="retry_export"' => 'acts on the export it names',
            'value="delete_export"' => 'deletes the export it names',
        ],
        '202-account/auto-upgrade.php' => [
            'name="start_upgrade"' => 'starts an upgrade; rewrites no setting',
        ],
        '202-account/auto-upgrade-premium.php' => [
            'name="start_upgrade"' => 'starts an upgrade; rewrites no setting',
        ],
        '202-account/connect-ai.php' => [
            'name="decision"' => 'answers a consent request; rewrites no setting',
        ],
        '202-account/user-management.php' => [
            'name="delete_user_id"' => 'deletes the user it names',
        ],
        '202-account/vip-perks.php' => [
            'id="survey-form"' => 'sends survey answers; stores no setting',
        ],
        '202-config/functions-account-ui.php' => [
            'name="stop_ai_watcher"' => 'stops the watcher it names',
        ],
        '202-config/functions-alerts-ui.php' => [
            'name="send_test_money_loss_alert"' => 'sends a test alert; rewrites nothing',
        ],
        '202-config/functions-attribution-ui.php' => [
            'value="retry_failed"' => 'retries failed exports; rewrites nothing',
        ],
        '202-config/functions-click-filter-ui.php' => [
            'name="remove_click_filter_entry"' => 'removes the entry it names',
            'name="add_click_filter_entry"' => 'adds an entry; rewrites nothing',
        ],
        '202-config/functions-payment-notices.php' => [
            'name="record_held_notifications"' => 'records held notifications; rewrites no setting',
            'name="change_jvzoo_roleless_role"' => 'carries the version its caller passes in $formFields'
                . ' (testTheRolelessNoticeIsPassedItsVersion)',
        ],
        '202-config/functions-restore-notice-ui.php' => [
            '. $shown' => 'dismisses a notice by the restore it names',
        ],
        '202-config/functions-traffic-source-owners-ui.php' => [
            'Keep it where it is' => 'carries what the operator was shown (error pattern #36)',
            'Keep it as it is' => 'carries what the operator was shown (error pattern #36)',
        ],
    ];

    /** A versioned form posts to another page, whose guard answers for it. */
    private const HANDLED_BY = [
        '202-account/clickservers.php' => '202-account/account.php',
    ];

    /**
     * Forms whose name is an expression, each with where its names come from:
     * the api-integrations key cards render one form per $keyIntegrations
     * entry and save one per $keyCards entry.
     */
    private const DYNAMIC = [
        '202-account/api-integrations.php' => ['field' => '$integration[\'field\']', 'guard' => '$keyField'],
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return list<string> repository-relative paths */
    private static function files(): array
    {
        $root = self::root();
        $files = [];
        foreach (glob($root . '/202-account/*.php') ?: [] as $path) {
            $files[] = substr($path, strlen($root) + 1);
        }
        $helpers = [];
        foreach ($files as $file) {
            preg_match_all('#202-config/(functions-[a-z0-9-]+\.php)#', self::read($file), $m);
            foreach ($m[1] as $helper) {
                $helpers['202-config/' . $helper] = true;
            }
        }
        foreach (array_keys($helpers) as $helper) {
            if (is_file($root . '/' . $helper)) {
                $files[] = $helper;
            }
        }
        sort($files);

        return $files;
    }

    /** The file's source with PHP and HTML comments removed. */
    private static function read(string $file): string
    {
        $source = file_get_contents(self::root() . '/' . $file);
        self::assertIsString($source, $file);
        $out = '';
        foreach (\PhpToken::tokenize($source) as $token) {
            if (!$token->is([T_COMMENT, T_DOC_COMMENT])) {
                $out .= $token->text;
            }
        }

        return (string) preg_replace('/<!--.*?-->/s', '', $out);
    }

    /**
     * Each posting form of $file: its line and its source from `<form` to its
     * `</form>`, whether it is markup or built in PHP strings.
     *
     * @return list<array{line: int, body: string}>
     */
    private static function postForms(string $file): array
    {
        $source = self::read($file);
        $forms = [];
        if (!preg_match_all('/<form\b/i', $source, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        foreach ($m[0] as [, $offset]) {
            $end = stripos($source, '</form>', $offset);
            self::assertNotFalse($end, $file . ': a form opened at offset ' . $offset . ' is never closed');
            $body = substr($source, $offset, $end - $offset);
            $line = substr_count(substr($source, 0, $offset), "\n") + 1;
            // The method is the first one named in the opening tag's span;
            // a form that names none is a GET.
            $named = preg_match('/\bmethod\s*=\s*["\']?(post|get)\b/i', substr($body, 0, 400), $method) === 1;
            if (!$named || strtolower($method[1]) !== 'post') {
                continue;
            }
            $forms[] = ['line' => $line, 'body' => $body];
        }

        return $forms;
    }

    /**
     * Argument $position of every call to $function in $source, as written:
     * $function is a function's name, or a pattern for a closure variable.
     *
     * @return list<string>
     */
    private static function arguments(string $source, string $function, int $position): array
    {
        $found = [];
        $tokens = \PhpToken::tokenize($source);
        $count = count($tokens);
        $byPattern = str_starts_with($function, '/');
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($byPattern) {
                if (!$token->is(T_VARIABLE) || preg_match($function, $token->text) !== 1) {
                    continue;
                }
            } else {
                if (!$token->is(T_STRING) || $token->text !== $function) {
                    continue;
                }
                $prev = $i - 1;
                while ($prev >= 0 && $tokens[$prev]->is(T_WHITESPACE)) {
                    $prev--;
                }
                $notACall = [T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW];
                if ($prev >= 0 && $tokens[$prev]->is($notACall)) {
                    continue;
                }
            }
            $j = $i + 1;
            while ($j < $count && $tokens[$j]->is(T_WHITESPACE)) {
                $j++;
            }
            if ($j >= $count || $tokens[$j]->text !== '(') {
                continue;
            }
            $depth = 0;
            $args = [''];
            for ($k = $j; $k < $count; $k++) {
                $text = $tokens[$k]->text;
                $opens = in_array($text, ['(', '[', '{'], true);
                if ($opens || $tokens[$k]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                    $depth++;
                    if ($depth === 1) {
                        continue;
                    }
                } elseif (in_array($text, [')', ']', '}'], true)) {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                } elseif ($text === ',' && $depth === 1) {
                    $args[] = '';
                    continue;
                }
                $args[count($args) - 1] .= $text;
            }
            if (isset($args[$position])) {
                $found[] = trim($args[$position]);
            }
        }

        return $found;
    }

    /**
     * The form names $file renders fields under and the names its guards
     * check, each as written: a quoted literal or an expression.
     *
     * @return array{fields: list<string>, guards: list<string>}
     */
    private static function names(string $file): array
    {
        $source = self::read($file);
        $guards = self::arguments($source, 'p202_account_require_shown', 2);
        foreach (self::arguments($source, '/^\$[A-Za-z]+RequireShown$/', 0) as $name) {
            $guards[] = $name;
        }
        // A closure that hands its own parameter on is not a guard name.
        $guards = array_values(array_filter($guards, static fn (string $g): bool => $g !== '$form'));

        return ['fields' => self::arguments($source, 'p202_account_shown_field', 0), 'guards' => $guards];
    }

    private static function literal(string $name): ?string
    {
        return preg_match('/^\'([a-z0-9_]+)\'$/', $name, $m) === 1 ? $m[1] : null;
    }

    public function testEveryPostingFormCarriesItsVersionOrIsListed(): void
    {
        $unversioned = [];
        $seen = 0;
        foreach (self::files() as $file) {
            foreach (self::postForms($file) as $form) {
                $seen++;
                if (str_contains($form['body'], 'p202_account_shown_field(')) {
                    continue;
                }
                $listed = false;
                foreach (array_keys(self::NOT_SETTINGS[$file] ?? []) as $marker) {
                    if (str_contains($form['body'], $marker)) {
                        $listed = true;
                    }
                }
                if (!$listed) {
                    $unversioned[] = $file . ':' . $form['line'];
                }
            }
        }

        self::assertGreaterThanOrEqual(55, $seen, 'The scan found too few posting forms to be reading them.');
        self::assertSame([], $unversioned, 'These forms post without p202_account_shown_field(): render the version'
            . ' of what the form shows and guard its save with p202_account_require_shown(), or list the form in'
            . ' NOT_SETTINGS with why it rewrites no shown value.');
    }

    public function testEachListedMarkerNamesExactlyOneUnversionedForm(): void
    {
        $problems = [];
        foreach (self::NOT_SETTINGS as $file => $markers) {
            self::assertFileExists(self::root() . '/' . $file);
            $forms = self::postForms($file);
            foreach (array_keys($markers) as $marker) {
                $matches = array_filter($forms, static fn (array $f): bool => str_contains($f['body'], $marker));
                if (count($matches) !== 1) {
                    $problems[] = $file . ': marker ' . json_encode($marker) . ' matches ' . count($matches) . ' forms';
                    continue;
                }
                $form = array_values($matches)[0];
                if (str_contains($form['body'], 'p202_account_shown_field(')) {
                    $problems[] = $file . ':' . $form['line'] . ' carries a version now; remove its NOT_SETTINGS entry';
                }
            }
        }

        self::assertSame([], $problems);
    }

    public function testEveryVersionedFormNameHasAGuardAndEveryGuardAForm(): void
    {
        $fields = [];
        $guards = [];
        foreach (self::files() as $file) {
            $names = self::names($file);
            $handler = self::HANDLED_BY[$file] ?? $file;
            foreach ($names['fields'] as $name) {
                $fields[$handler][$name] = $file;
            }
            foreach ($names['guards'] as $name) {
                $guards[$file][$name] = true;
            }
        }

        $names = array_sum(array_map('count', $fields));
        self::assertGreaterThanOrEqual(15, $names, 'Too few versioned form names found.');
        $problems = [];
        foreach ($fields as $handler => $names) {
            foreach ($names as $name => $file) {
                if (self::literal($name) === null && (self::DYNAMIC[$file]['field'] ?? null) !== $name) {
                    $problems[] = $file . ': field name ' . $name . ' is neither a literal nor listed in DYNAMIC';
                    continue;
                }
                $guard = self::literal($name) !== null ? $name : (self::DYNAMIC[$file]['guard'] ?? '');
                if (!isset($guards[$handler][$guard])) {
                    $problems[] = $file . ': form ' . $name . ' has no guard in ' . $handler;
                }
            }
        }
        foreach ($guards as $file => $names) {
            foreach (array_keys($names) as $name) {
                $field = self::literal($name) !== null ? $name : null;
                foreach (self::DYNAMIC as $dynamicFile => $dynamic) {
                    if ($dynamicFile === $file && $dynamic['guard'] === $name) {
                        $field = $dynamic['field'];
                    }
                }
                if ($field === null) {
                    $problems[] = $file . ': guard name ' . $name . ' is neither a literal nor listed in DYNAMIC';
                    continue;
                }
                if (!isset($fields[$file][$field])) {
                    $problems[] = $file . ': guard ' . $name . ' checks a version no form sends';
                }
            }
        }

        self::assertSame([], $problems);
    }

    /**
     * The key cards are one form per $keyIntegrations entry and one save per
     * $keyCards entry, the form named by 'field' and the save by the first
     * element: the two lists must name the same flags and fields, and each
     * field must be a form ShownSettings knows the columns of.
     */
    public function testTheKeyCardFormsAndTheirSavesNameTheSameFields(): void
    {
        $source = self::read('202-account/api-integrations.php');
        preg_match_all("/'flag' => '([a-z_]+)', 'field' => '([a-z_]+)'/", $source, $rendered, PREG_SET_ORDER);
        preg_match_all("/'(change_[a-z_]+)' => \\['([a-z_]+)', /", $source, $saved, PREG_SET_ORDER);
        $pairs = static function (array $matches): array {
            $out = [];
            foreach ($matches as $m) {
                $out[$m[1]] = $m[2];
            }
            ksort($out);

            return $out;
        };

        self::assertCount(4, $pairs($rendered), 'The key cards are not where this test reads them.');
        self::assertSame($pairs($rendered), $pairs($saved));
        foreach ($pairs($rendered) as $field) {
            $listed = "/\\\$apiintShownColumns = \\[[^;]*'" . $field . "' => \\[/s";
            self::assertMatchesRegularExpression($listed, $source);
        }
    }

    public function testTheRolelessNoticeIsPassedItsVersion(): void
    {
        $source = self::read('202-account/api-integrations.php');
        $calls = self::arguments($source, 'p202_jvzoo_roleless_notice', 3);

        self::assertNotSame([], $calls, 'api-integrations.php no longer renders the roleless notice here.');
        foreach ($calls as $fields) {
            self::assertStringContainsString(
                "p202_account_shown_field('jvzoo_roleless_role',\$apiintShown('jvzoo_roleless_role',",
                (string) preg_replace('/\s+/', '', $fields)
            );
        }
    }
}
