<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;

/**
 * Every settings form the Account pages render, submitted as a browser
 * submits it, reaches its version check: without its version it is reloaded
 * as stale, and as rendered it is not. The second half is what a form whose
 * rendered values differ from what its save reads again (a type, a column,
 * another query) would break: every save of it refused as stale.
 *
 * Each form is serialized from a fresh render (its inputs, checked boxes and
 * radios, selected options and first named submit) and posted to its action
 * through settings-page-runner.php, under one ShownSettings secret.
 * SettingsFormsCarryTheirVersionTest holds that every form carries a
 * version; this one holds the names it can render here to that list.
 *
 * @group integration
 */
final class SettingsFormsPostedBackIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const CLOSED = 'http://127.0.0.1:9';
    private const USER = 990151;
    private const EDITED = 990152;
    private const ROLE = 990151;
    private const SECRET = '0f1e2d3c4b5a69788796a5b4c3d2e1f00f1e2d3c4b5a69788796a5b4c3d2e1f0';
    private const STALE = 'changed in another window or session after this page opened';
    private const GUARD_FAILED = [
        'Another save of these settings is still running',
        'Your settings could not be saved just now; nothing changed.',
    ];

    private const PERMISSIONS = [
        'access_to_api_integrations', 'access_to_personal_settings', 'access_to_settings', 'access_to_clickservers',
        'view_attribution_reports', 'manage_attribution_models', 'add_users',
    ];

    /** Forms posted only without their version: posted as rendered, each saves through a hosted service. */
    private const STALE_ONLY = [
        'autocron' => 'registers the install with the hosted cron service',
        'maxmind' => 'downloads the ISP database',
        'geoip' => 'checks the credentials with MaxMind',
        'p202_customer_api_key' => 'checks the key with the licence service',
    ];

    /** Forms this install cannot render, and why. */
    private const NOT_RENDERED = [
        'dni_network' => 'renders only with the hosted DNI network list',
        'lpo_ctx_kw' => 'renders only for an account paired with the Landing Page Optimizer',
    ];

    /** Field values a form is posted with in place of what it rendered, so that its save gets past its own checks. */
    private const FILLED = [
        'auto_database_management' => ['auto_database_management' => '0'],
    ];

    private static string $sessions = '';

    /** @var list<string>|null */
    private static ?array $versions = null;

    /** @var list<int> */
    private static array $addedPermissions = [];

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/202-config.php')) {
            self::markTestSkipped('No 202-config.php: connect.php would exit before any page ran.'
                . ' tests/run-integration-suites.sh writes one.');
        }
        if (!self::connectScratch()) {
            return;
        }
        require_once $root . '/202-config/version.php';
        $found = self::$db->query('SELECT version FROM 202_version');
        $rows = $found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [];
        self::$versions = array_column($rows, 'version');
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => PROSPER202_VERSION]);
        self::cleanUp();
        foreach ([self::USER, self::EDITED] as $user) {
            self::user($user);
            self::row('202_users_pref', ['user_id' => $user]);
        }
        self::q("UPDATE 202_users SET p202_customer_api_key = 'page-runner' WHERE user_id = " . self::USER);
        self::q("UPDATE 202_users_pref SET paykickstart_ipn_secret_keys = 'pk-posted-back-0001'"
            . ' WHERE user_id = ' . self::USER);
        self::row('202_roles', ['role_id' => self::ROLE, 'role_name' => 'settings posted back test']);
        foreach (self::PERMISSIONS as $name) {
            $found = self::$db->query(
                "SELECT permission_id FROM 202_permissions WHERE permission_description = '$name'"
            );
            $permission = $found instanceof \mysqli_result ? ($found->fetch_row()[0] ?? null) : null;
            if ($permission === null) {
                $permission = self::insert("INSERT INTO 202_permissions SET permission_description = '$name'");
                self::$addedPermissions[] = $permission;
            }
            self::row('202_role_permission', ['role_id' => self::ROLE, 'permission_id' => (int) $permission]);
        }
        self::row('202_user_role', ['user_id' => self::USER, 'role_id' => self::ROLE]);
        self::$sessions = sys_get_temp_dir() . '/p202-page-runner-posted-back-' . getmypid();
        if (!is_dir(self::$sessions) && !mkdir(self::$sessions, 0700)) {
            throw new \RuntimeException('could not create ' . self::$sessions);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            if (self::$versions !== null) {
                self::q('DELETE FROM 202_version');
                foreach (self::$versions as $version) {
                    self::row('202_version', ['version' => $version]);
                }
            }
        }
        if (self::$sessions !== '' && is_dir(self::$sessions)) {
            array_map('unlink', glob(self::$sessions . '/sess_*') ?: []);
            rmdir(self::$sessions);
        }
    }

    /** Every row of the two accounts, in every table that names its account by user_id: the saves write several. */
    private static function cleanUp(): void
    {
        $found = self::$db->query("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()"
            . " AND COLUMN_NAME = 'user_id' AND TABLE_NAME LIKE '202\\_%'");
        $tables = array_column($found instanceof \mysqli_result ? $found->fetch_all() : [], 0);
        // The account row last: other rows name it by foreign key.
        usort($tables, static fn (string $a, string $b): int => ($a === '202_users') <=> ($b === '202_users'));
        foreach ($tables as $table) {
            self::q('DELETE FROM `' . $table . '` WHERE user_id IN (' . self::USER . ', ' . self::EDITED . ')');
        }
        self::q('DELETE FROM 202_role_permission WHERE role_id = ' . self::ROLE);
        self::q('DELETE FROM 202_roles WHERE role_id = ' . self::ROLE);
        foreach (self::$addedPermissions as $permission) {
            self::q('DELETE FROM 202_permissions WHERE permission_id = ' . $permission);
        }
        self::$addedPermissions = [];
    }

    /**
     * One run of a page as USER: its output, status and the flashes it queued.
     *
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     * @return array{out: string, status: int|null, flashes: list<array<string, mixed>>}
     */
    private static function open(string $page, array $get, array $post = []): array
    {
        $env = [
            'P202_TEST_REPORT_USER' => (string) self::USER,
            'P202_TEST_SESSION_DIR' => self::$sessions,
            'P202_TEST_GET' => (string) json_encode((object) $get),
            'P202_TEST_POST' => (string) json_encode((object) $post),
            'P202_TEST_SHOWN_SECRET' => self::SECRET,
            // The hosted services sit behind a proxy on a closed port, so nothing leaves the machine.
            'https_proxy' => self::CLOSED, 'HTTPS_PROXY' => self::CLOSED, 'http_proxy' => self::CLOSED,
            'HTTP_PROXY' => self::CLOSED, 'all_proxy' => '', 'ALL_PROXY' => '', 'no_proxy' => '', 'NO_PROXY' => '',
        ] + getenv();
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED',
                dirname(__DIR__) . '/Setup/fixtures/settings-page-runner.php', $page],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertNotSame(2, proc_close($proc), "the runner could not set up: $err");
        self::assertStringNotContainsString('Fatal error', $out . $err, $page . ': ' . $err);
        $at = strrpos($out, "\nP202-RUNNER-END ");
        self::assertNotFalse($at, $page . ' ran to its end');
        $end = json_decode(trim(substr($out, $at + strlen("\nP202-RUNNER-END "))), true, 8, JSON_THROW_ON_ERROR);

        return ['out' => substr($out, 0, $at), 'status' => $end['status'], 'flashes' => array_values($end['flashes'])];
    }

    /**
     * The versioned POST forms of a rendered page, each as a browser would
     * submit it: [form name, the page it posts to, its query, its fields].
     *
     * @return list<array{string, string, array<string, mixed>, list<array{string, string}>}>
     */
    private static function forms(string $html): array
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($doc);
        $forms = [];
        foreach ($xpath->query('//form') ?: [] as $form) {
            \assert($form instanceof \DOMElement);
            if (strtolower($form->getAttribute('method')) !== 'post') {
                continue;
            }
            $fields = [];
            $name = null;
            $submitted = false;
            foreach ($xpath->query('.//input|.//select|.//textarea|.//button', $form) ?: [] as $control) {
                \assert($control instanceof \DOMElement);
                $field = $control->getAttribute('name');
                if ($field === '' || $control->hasAttribute('disabled')) {
                    continue;
                }
                $tag = strtolower($control->tagName);
                $type = strtolower($control->getAttribute('type') ?: ($tag === 'button' ? 'submit' : 'text'));
                if ($tag === 'button' || $type === 'submit') {
                    if (!$submitted) {
                        $fields[] = [$field, $control->getAttribute('value')];
                        $submitted = true;
                    }
                    continue;
                }
                if (($type === 'checkbox' || $type === 'radio') && !$control->hasAttribute('checked')) {
                    continue;
                }
                if ($tag === 'select') {
                    $options = $xpath->query('.//option', $control);
                    $chosen = null;
                    foreach ($options ?: [] as $option) {
                        \assert($option instanceof \DOMElement);
                        $chosen ??= $option;
                        if ($option->hasAttribute('selected')) {
                            $chosen = $option;
                            break;
                        }
                    }
                    if ($chosen !== null) {
                        $value = $chosen->hasAttribute('value') ? $chosen->getAttribute('value') : $chosen->textContent;
                        $fields[] = [$field, $value];
                    }
                    continue;
                }
                $value = $tag === 'textarea' ? $control->textContent : $control->getAttribute('value');
                if ($type === 'checkbox' && !$control->hasAttribute('value')) {
                    $value = 'on';
                }
                if (preg_match('/^p202_shown\[([a-z0-9_]+)\]$/', $field, $m) === 1) {
                    $name = $m[1];
                }
                $fields[] = [$field, $value];
            }
            if ($name === null) {
                continue;
            }
            $action = parse_url($form->getAttribute('action'));
            parse_str((string) ($action['query'] ?? ''), $query);
            $forms[] = [$name, ltrim((string) ($action['path'] ?? ''), '/'), $query, $fields];
        }

        return $forms;
    }

    /**
     * The body a browser sends for $fields, read as PHP reads it.
     *
     * @param list<array{string, string}> $fields
     * @return array<string, mixed>
     */
    private static function body(array $fields): array
    {
        $pairs = array_map(static fn (array $f): string => rawurlencode($f[0]) . '=' . rawurlencode($f[1]), $fields);
        parse_str(implode('&', $pairs), $body);

        return $body;
    }

    /** @param array{out: string, status: int|null, flashes: list<array<string, mixed>>} $answer */
    private static function said(array $answer): string
    {
        $texts = array_map(static fn (array $f): string => (string) ($f['text'] ?? ''), $answer['flashes']);

        return implode(' | ', $texts) . ' ' . $answer['out'];
    }

    /**
     * Each versioned form the page renders: posted without its version it is
     * reloaded as stale, and posted as rendered it is not. A form is taken
     * from a fresh render each time, since a save changes what the next
     * render shows.
     *
     * @param array<string, mixed> $get
     * @return list<string> the forms posted
     */
    private static function postEachForm(string $page, array $get, array &$problems): array
    {
        $count = count(self::forms(self::open($page, $get)['out']));
        $posted = [];
        for ($i = 0; $i < $count; $i++) {
            foreach (['without', 'as rendered'] as $how) {
                if ($how === 'as rendered' && isset(self::STALE_ONLY[$posted[$i] ?? ''])) {
                    continue;
                }
                $forms = self::forms(self::open($page, $get)['out']);
                if (!isset($forms[$i])) {
                    $problems[] = "$page form $i: the page renders fewer forms after a save";
                    continue 2;
                }
                [$name, $action, $query, $fields] = $forms[$i];
                $posted[$i] = $name;
                foreach (self::FILLED[$name] ?? [] as $field => $value) {
                    foreach ($fields as $k => $f) {
                        if ($f[0] === $field) {
                            $fields[$k][1] = $value;
                        }
                    }
                }
                if ($how === 'without') {
                    $unversioned = static fn (array $f): bool => !str_starts_with($f[0], 'p202_shown[');
                    $fields = array_values(array_filter($fields, $unversioned));
                }
                $answer = self::open($action, $query, self::body($fields));
                $said = self::said($answer);
                if ($how === 'without' && !str_contains($said, self::STALE)) {
                    $problems[] = "$page $name, posted without its version, was not refused as stale: "
                        . substr($said, 0, 300);
                }
                if ($how === 'as rendered') {
                    foreach ([self::STALE, ...self::GUARD_FAILED] as $refusal) {
                        if (str_contains($said, $refusal)) {
                            $problems[] = "$page $name, posted as rendered, was refused: $refusal";
                        }
                    }
                }
            }
        }

        return array_values($posted);
    }

    public function testEveryRenderedSettingsFormReachesItsCheckAndSavesAsRendered(): void
    {
        self::requireScratch();
        $model = (new \Api\V3\Controllers\AttributionController(self::$db, self::USER))->createModel([
            'model_name' => 'Posted back',
            'model_type' => 'time_decay',
            'weighting_config' => ['half_life_hours' => 36],
            'lookback_days' => 45,
        ])['data']['model_id'];
        $pages = [
            ['202-account/account.php', []],
            ['202-account/api-integrations.php', []],
            ['202-account/administration.php', []],
            ['202-account/clickservers.php', []],
            ['202-account/attribution.php', ['view' => 'models', 'edit' => (string) $model]],
            ['202-account/user-management.php', ['edit_user_id' => (string) self::EDITED]],
        ];
        $problems = [];
        $posted = [];
        foreach ($pages as [$page, $get]) {
            $posted = array_merge($posted, self::postEachForm($page, $get, $problems));
        }
        self::assertSame([], $problems, implode("\n", $problems));

        $named = self::formNames();
        $expected = array_values(array_diff($named, array_keys(self::NOT_RENDERED)));
        sort($expected);
        $covered = array_values(array_unique($posted));
        sort($covered);
        self::assertSame($expected, $covered, 'every versioned form but those NOT_RENDERED lists was posted');
        $listed = array_values(array_diff(array_keys(self::STALE_ONLY + self::NOT_RENDERED), $named));
        self::assertSame([], $listed, 'every form listed is one the pages name');
    }

    /** @return list<string> the form names the Account pages give p202_account_shown_field(), and the key cards' */
    private static function formNames(): array
    {
        $root = dirname(__DIR__, 2);
        $names = [];
        $files = [...glob($root . '/202-account/*.php') ?: [], ...glob($root . '/202-config/functions-*.php') ?: []];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all("/p202_account_shown_field\\('([a-z0-9_]+)'/", $source, $m);
            $names = array_merge($names, $m[1]);
            if (str_ends_with($file, '/api-integrations.php')) {
                preg_match_all("/'field' => '([a-z0-9_]+)'/", $source, $m);
                $names = array_merge($names, $m[1]);
            }
        }
        $names = array_values(array_unique($names));
        sort($names);
        self::assertGreaterThanOrEqual(20, count($names), 'the scan found the forms');

        return $names;
    }
}
