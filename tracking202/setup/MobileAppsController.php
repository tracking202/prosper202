<?php

declare(strict_types=1);

namespace Tracking202\Setup;

use Api\V3\Controllers\AttributionAppsController;
use Api\V3\Controllers\AttributionConversionValuesController;
use Api\V3\Controllers\AttributionPostbacksController;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Api\V3\HttpException;

require_once __DIR__ . '/_base/SetupController.php';
require_once __DIR__ . '/../../202-config/functions-install-helpers.php';

/**
 * Setup › Mobile Apps: register the apps you advertise, decode their
 * SKAdNetwork and AdAttributionKit conversion values, and connect the SDK.
 *
 * Every write goes through the v3 controllers in-process, so this page, the
 * REST API and the CLI enforce the same rules and answer with the same
 * sentences — a message shown here is the API's own words, not a second copy
 * that can drift from them. No API key is involved: the controllers take the
 * session's user id directly.
 *
 * Post-redirect-get throughout, with CSRF from the base controller. The page
 * is the first on the v2 shell (Bootstrap 5.3 + the component layer), so its
 * markup carries no Bootstrap 3 or Flat UI class; NoLegacyBootstrapClassesTest
 * fails the build if one appears.
 */
class MobileAppsController extends SetupController
{
    /** The write permission, shared with attribution models. */
    private const MANAGE_PERMISSION = 'manage_attribution_models';

    private \mysqli $db;
    private AttributionAppsController $apps;
    private AttributionConversionValuesController $rules;
    private AttributionPostbacksController $postbacks;

    /** @var list<array{kind: string, text: string}> */
    private array $flashes = [];
    /** @var array<string, string> field name => the API's own message */
    private array $fieldErrors = [];
    /** @var array<string, mixed> what the form should show again after a failed submit */
    private array $formState = [];

    /** @var array<string, mixed>|null the app being viewed, when ?app=ID */
    private ?array $currentApp = null;
    /** @var array<string, mixed>|null the app being edited, when ?edit=ID */
    private ?array $editingApp = null;

    public function __construct()
    {
        parent::__construct();
        global $db;
        $this->db = $db;
        $userId = $this->getUserId();
        $this->apps = new AttributionAppsController($db, $userId);
        $this->rules = new AttributionConversionValuesController($db, $userId);
        $this->postbacks = new AttributionPostbacksController($db, $userId);
    }

    /** Reading the list needs Setup (the base class); changing anything needs the models permission. */
    private function canManage(): bool
    {
        return $this->user->hasPermission(self::MANAGE_PERMISSION);
    }

    private function requireManage(): void
    {
        if (!$this->canManage()) {
            throw new \InvalidArgumentException('You do not have permission to change mobile app registrations.');
        }
    }

    protected function handleGet(): void
    {
        $appId = (int)($_GET['app'] ?? 0);
        if ($appId > 0) {
            try {
                $this->currentApp = $this->apps->get($appId)['data'];
            } catch (NotFoundException) {
                $this->flash('bad', 'That app registration no longer exists.');
            }
            return;
        }
        $editId = (int)($_GET['edit'] ?? 0);
        if ($editId > 0) {
            try {
                $this->editingApp = $this->apps->get($editId)['data'];
            } catch (NotFoundException) {
                $this->flash('bad', 'That app registration no longer exists.');
            }
        }
    }

    protected function handlePost(): void
    {
        $action = (string)($_POST['action'] ?? '');
        $this->requireManage();

        try {
            match ($action) {
                'register'       => $this->registerApp(),
                'update'         => $this->updateApp(),
                'remove'         => $this->removeApp(),
                'accept_dev'     => $this->setDevelopmentTrust(),
                'rule_save'      => $this->saveRule(),
                'rule_remove'    => $this->removeRule(),
                'starter_schema' => $this->applyStarterSchema(),
                'rotate_token'   => $this->rotateToken(),
                default          => throw new \InvalidArgumentException('Unknown action.'),
            };
        } catch (ValidationException $e) {
            // The API's per-field sentences, shown under the fields they name.
            $this->fieldErrors = $e->getFieldErrors();
            if ($this->fieldErrors === []) {
                $this->flash('bad', $e->getMessage());
            }
            $this->formState = $_POST;
            $this->handleGet();
        } catch (HttpException $e) {
            // 409 duplicate, 404, and the rest: the API's own message.
            $this->flash('bad', $e->getMessage());
            $this->formState = $_POST;
            $this->handleGet();
        }
    }

    // ─── Apps ────────────────────────────────────────────────────────

    /**
     * Register from one field: an App Store link or a bare id.
     *
     * The id, the platform and (when the store answers) the name are derived
     * rather than asked for. What was derived is reported back in the success
     * flash, so nothing is silently assumed. If the name cannot be derived
     * the form comes back with a name field rather than registering the app
     * under a placeholder.
     */
    private function registerApp(): void
    {
        $reference = trim((string)($_POST['app_reference'] ?? ''));
        $parsed = self::parseStoreReference($reference);

        if ($parsed['platform'] !== AttributionAppsController::PLATFORM_IOS) {
            // Recognised store, no receiver for it. Ask the API for the
            // sentence so this page cannot drift from the CLI's answer, and
            // show it under the one field the user filled in.
            try {
                AttributionAppsController::assertSupportedPlatform(['platform' => $parsed['platform']]);
            } catch (ValidationException $e) {
                throw new ValidationException('Validation failed', [
                    'app_reference' => $e->getFieldErrors()['platform'] ?? 'That store is not supported yet.',
                ]);
            }
        }

        if ($parsed['app_id'] === null || $parsed['app_id'] <= 0) {
            throw new ValidationException('Validation failed', [
                'app_reference' => $reference === ''
                    ? 'Paste the app\'s App Store link, or its numeric App Store id.'
                    : 'Must be an App Store link or a positive App Store id (the number after "id" in the app\'s App Store URL).',
            ]);
        }

        // Pasting a link for an app you already have is a navigation, not an
        // error: send the user to it. This runs BEFORE the store lookup
        // because that lookup is a network call to a third party, and without
        // it a re-paste spends a timeout only to ask for a name and then
        // answer 409 — two steps to be told something already known here.
        // list() is scoped to this user, so an app registered by somebody
        // else is not found, is not hinted at, and still gets the API's own
        // "already registered" answer from create() below.
        $mine = $this->apps->list(['filter' => ['app_id' => $parsed['app_id']], 'limit' => 1])['data'];
        if ($mine !== []) {
            $this->redirect('tracking202/setup/mobile_apps.php?app='
                . (int)$mine[0]['attribution_app_id'] . '&already=1');
        }

        $platform = (string)($_POST['platform'] ?? $parsed['platform']);
        $name = trim((string)($_POST['app_name'] ?? ''));
        if ($name === '') {
            $name = $this->lookUpAppName($parsed['app_id'], $parsed['slug']);
        }
        if ($name === '') {
            // Everything except the name was derived; ask only for that.
            $this->formState = $_POST + [
                'derived_app_id' => $parsed['app_id'],
                'derived_platform' => $platform,
                'needs_name' => true,
            ];
            $this->fieldErrors['app_name'] = 'The App Store did not answer, so the name could not be looked up. Type it once and it is saved with the registration.';
            return;
        }

        $payload = [
            'app_id' => $parsed['app_id'],
            'app_name' => $name,
            'platform' => $platform,
            'notes' => trim((string)($_POST['notes'] ?? '')),
            'accept_development_postbacks' => isset($_POST['accept_development_postbacks']) ? 1 : 0,
        ];
        $created = $this->apps->create($payload)['data'];

        $this->sendSlackNotification('mobile_app_registered', [
            'app' => $name,
            'app_id' => $parsed['app_id'],
        ]);
        $this->redirect('tracking202/setup/mobile_apps.php?app=' . (int)$created['attribution_app_id'] . '&registered=1');
    }

    private function updateApp(): void
    {
        $id = (int)($_POST['attribution_app_id'] ?? 0);
        $payload = [
            'app_name' => trim((string)($_POST['app_name'] ?? '')),
            'notes' => trim((string)($_POST['notes'] ?? '')),
            'accept_development_postbacks' => isset($_POST['accept_development_postbacks']) ? 1 : 0,
        ];
        if (isset($_POST['platform'])) {
            $payload['platform'] = (string)$_POST['platform'];
        }
        $this->apps->update($id, $payload);
        $this->redirect('tracking202/setup/mobile_apps.php?saved=1');
    }

    private function removeApp(): void
    {
        $id = (int)($_POST['attribution_app_id'] ?? 0);
        $this->apps->delete($id);
        $this->redirect('tracking202/setup/mobile_apps.php?removed=1');
    }

    /** The nudge's one click, and the Advanced toggle, are the same write. */
    private function setDevelopmentTrust(): void
    {
        $id = (int)($_POST['attribution_app_id'] ?? 0);
        $accept = (string)($_POST['accept'] ?? '0') === '1';
        $this->apps->update($id, ['accept_development_postbacks' => $accept ? 1 : 0]);
        $this->redirect('tracking202/setup/mobile_apps.php?app=' . $id . '&dev=' . ($accept ? '1' : '0'));
    }

    private function rotateToken(): void
    {
        $id = (int)($_POST['attribution_app_id'] ?? 0);
        $this->apps->rotateSchemaToken($id);
        $this->redirect('tracking202/setup/mobile_apps.php?app=' . $id . '&rotated=1');
    }

    // ─── Conversion values ───────────────────────────────────────────

    /**
     * Add or change one rule.
     *
     * The kind is a radio because the API accepts exactly one of fine or
     * coarse; changing an existing rule's kind sends the explicit null for
     * the other one in the same request, which is what the API requires.
     */
    private function saveRule(): void
    {
        $appRowId = (int)($_POST['attribution_app_id'] ?? 0);
        $appStoreId = (int)($_POST['app_id'] ?? 0);
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        $kind = (string)($_POST['kind'] ?? 'fine');

        $payload = [
            'app_id' => $appStoreId,
            'event_name' => trim((string)($_POST['event_name'] ?? '')),
            'revenue' => (string)($_POST['revenue'] ?? '0'),
            'fine_value' => null,
            'coarse_value' => null,
        ];
        if ($kind === 'coarse') {
            $payload['coarse_value'] = (string)($_POST['coarse_value'] ?? '');
        } else {
            $fine = $_POST['fine_value'] ?? '';
            $payload['fine_value'] = $fine === '' ? null : (int)$fine;
            if ($payload['fine_value'] === null) {
                throw new ValidationException('Validation failed', ['fine_value' => 'Choose the fine conversion value this rule decodes (0 to 63).']);
            }
        }

        if ($ruleId > 0) {
            $this->rules->update($ruleId, $payload);
        } else {
            $this->rules->create($payload);
        }
        $this->redirect('tracking202/setup/mobile_apps.php?app=' . $appRowId . '&rule=1');
    }

    private function removeRule(): void
    {
        $appRowId = (int)($_POST['attribution_app_id'] ?? 0);
        $this->rules->delete((int)($_POST['rule_id'] ?? 0));
        $this->redirect('tracking202/setup/mobile_apps.php?app=' . $appRowId . '&rule_removed=1');
    }

    /**
     * The first click after registering: the three rules almost every app
     * starts with, offered in place instead of an empty table.
     */
    private function applyStarterSchema(): void
    {
        $appRowId = (int)($_POST['attribution_app_id'] ?? 0);
        $appStoreId = (int)($_POST['app_id'] ?? 0);
        $starter = [
            ['fine_value' => 1,  'coarse_value' => null,     'event_name' => 'install',       'revenue' => 0],
            ['fine_value' => 10, 'coarse_value' => null,     'event_name' => 'trial_started', 'revenue' => 0],
            ['fine_value' => 40, 'coarse_value' => null,     'event_name' => 'purchase',      'revenue' => 0],
            ['fine_value' => null, 'coarse_value' => 'low',    'event_name' => 'install',       'revenue' => 0],
            ['fine_value' => null, 'coarse_value' => 'medium', 'event_name' => 'trial_started', 'revenue' => 0],
            ['fine_value' => null, 'coarse_value' => 'high',   'event_name' => 'purchase',      'revenue' => 0],
        ];
        $added = 0;
        foreach ($starter as $rule) {
            try {
                $this->rules->create($rule + ['app_id' => $appStoreId]);
                $added++;
            } catch (HttpException) {
                // A value already mapped keeps the rule it has: the starter
                // schema fills the gaps, it never overwrites a decision.
            }
        }
        $this->redirect('tracking202/setup/mobile_apps.php?app=' . $appRowId . '&starter=' . $added);
    }

    // ─── Deriving what the app can derive ────────────────────────────

    /**
     * Read an App Store reference: a store link, or a bare numeric id.
     *
     * @return array{app_id: int|null, platform: string, slug: string}
     */
    public static function parseStoreReference(string $reference): array
    {
        $reference = trim($reference);
        $result = ['app_id' => null, 'platform' => AttributionAppsController::PLATFORM_IOS, 'slug' => ''];
        if ($reference === '') {
            return $result;
        }

        // A bare id: the common paste from App Store Connect.
        if (preg_match('/^\d+$/', $reference) === 1) {
            $id = (int)$reference;
            $result['app_id'] = $id > 0 && (string)$id === $reference ? $id : null;
            return $result;
        }

        $host = strtolower((string)(parse_url($reference, PHP_URL_HOST) ?? ''));
        $path = (string)(parse_url($reference, PHP_URL_PATH) ?? '');
        if (str_contains($host, 'play.google.com') || str_contains($reference, 'play.google.com')) {
            // Recognised, and refused by name further down: the platform is
            // what makes the refusal readable.
            $result['platform'] = 'android';
            $result['app_id'] = 0; // recognised store, no Apple id to use
            return $result;
        }

        // https://apps.apple.com/us/app/summit-run/id990077001?mt=8, and the
        // last segment of that URL on its own — "id990077001" is what someone
        // copying "the number after id" out of the address bar actually
        // lands on, and the refusal sentence invites exactly that paste.
        // Anchored to a segment boundary so "covid19" is not an app id.
        if (preg_match('~(?:^|/)id(\d+)~', $path, $m) === 1) {
            $result['app_id'] = (int)$m[1];
            if (preg_match('~/app/([^/]+)/id\d+~', $path, $slug) === 1) {
                $result['slug'] = urldecode($slug[1]);
            }
            return $result;
        }

        return $result;
    }

    /**
     * The app's name, without asking for it: the App Store lookup service
     * first, then the slug the link already carried.
     *
     * Best effort by design. The store is a third party on the far side of a
     * short timeout, and a name is not worth failing a registration over, so
     * every failure falls through to the slug and then to the caller, which
     * shows the field.
     */
    private function lookUpAppName(int $appId, string $slug): string
    {
        $name = '';
        if (function_exists('curl_init')) {
            $ch = curl_init('https://itunes.apple.com/lookup?id=' . $appId);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 4,
                CURLOPT_USERAGENT => 'Prosper202',
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if (is_string($body) && $status === 200) {
                $decoded = json_decode($body, true);
                if (is_array($decoded) && !empty($decoded['results'][0]['trackName'])) {
                    $name = trim((string)$decoded['results'][0]['trackName']);
                }
            }
        }
        if ($name === '' && $slug !== '') {
            $name = ucwords(str_replace('-', ' ', $slug));
        }
        return mb_substr($name, 0, 255);
    }

    // ─── Rendering ───────────────────────────────────────────────────

    private function flash(string $kind, string $text): void
    {
        $this->flashes[] = ['kind' => $kind, 'text' => $text];
    }

    #[\Override]
    protected function handleError(\Exception $e): void
    {
        error_log('Mobile Apps setup: ' . $e->getMessage());
        if ($e instanceof \InvalidArgumentException) {
            $this->flash('bad', $e->getMessage());
            $this->handleGet();
            $this->render();
            return;
        }
        $this->redirect('tracking202/setup/');
    }

    protected function render(): void
    {
        foreach ($this->flashesFromQuery() as $flash) {
            $this->flashes[] = $flash;
        }

        $view = [
            'canManage' => $this->canManage(),
            'csrf' => $this->renderCsrfField(),
            'flashes' => $this->flashes,
            'fieldErrors' => $this->fieldErrors,
            'form' => $this->formState,
            'baseUrl' => rtrim(get_absolute_url(), '/') . '/',
            // The absolute install URL. Apple is handed this origin and the
            // page prints it for copying, so a path alone will not do;
            // install_request_base_url() is the existing helper for it and
            // documents that the Host header is attacker-controlled, which is
            // why every use of it in the template is escaped.
            'origin' => install_request_base_url($_SERVER, get_absolute_url()),
            'apps' => $this->listApps(),
            'app' => $this->currentApp,
            'editing' => $this->editingApp,
        ];
        if ($this->currentApp !== null) {
            $view['rules'] = $this->rulesFor((int)$this->currentApp['app_id']);
            $view['defaultRules'] = $this->rulesFor(0);
            $view['recent'] = $this->recentPostbacks((int)$this->currentApp['app_id']);
        }
        $view['nudges'] = $this->developmentNudges($view['apps']);

        $mobileApps = $view;
        require __DIR__ . '/templates/mobile_apps.php';
    }

    /** @return list<array{kind: string, text: string}> */
    private function flashesFromQuery(): array
    {
        $out = [];
        if (isset($_GET['registered'])) {
            $out[] = ['kind' => 'ok', 'text' => 'App registered. Its postbacks are claimed from now on, and any already received were claimed too.'];
        }
        if (isset($_GET['already'])) {
            $out[] = ['kind' => 'ok', 'text' => 'You had already registered this app. Here it is.'];
        }
        if (isset($_GET['saved'])) {
            $out[] = ['kind' => 'ok', 'text' => 'Changes saved.'];
        }
        if (isset($_GET['removed'])) {
            $out[] = ['kind' => 'ok', 'text' => 'Registration removed. Postbacks it had claimed keep their owner; development trust is withdrawn.'];
        }
        if (isset($_GET['rotated'])) {
            $out[] = ['kind' => 'ok', 'text' => 'Schema token replaced. The old token stopped working just now; apps keep their last cached schema until they fetch with the new one.'];
        }
        if (isset($_GET['dev'])) {
            $out[] = ['kind' => 'ok', 'text' => (string)$_GET['dev'] === '1'
                ? 'Development-signed postbacks are now trusted for this app, including the ones already received.'
                : 'Development-signed postbacks are untrusted again for this app, including the ones already received.'];
        }
        if (isset($_GET['rule'])) {
            $out[] = ['kind' => 'ok', 'text' => 'Conversion value saved.'];
        }
        if (isset($_GET['rule_removed'])) {
            $out[] = ['kind' => 'ok', 'text' => 'Conversion value removed.'];
        }
        if (isset($_GET['starter'])) {
            $added = (int)$_GET['starter'];
            $out[] = ['kind' => $added > 0 ? 'ok' : 'warn', 'text' => $added > 0
                ? $added . ' starter ' . ($added === 1 ? 'rule' : 'rules') . ' added. Edit them to match what your app reports.'
                : 'Every starter value is already mapped, so nothing was changed.'];
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function listApps(): array
    {
        $result = $this->apps->list(['limit' => 200]);
        $rows = $result['data'] ?? [];
        // The API orders by primary key; the panel reads better by name.
        usort($rows, static fn (array $a, array $b): int => strcasecmp((string)($a['app_name'] ?? ''), (string)($b['app_name'] ?? '')));
        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function rulesFor(int $appStoreId): array
    {
        $result = $this->rules->list(['limit' => 200, 'filter' => ['app_id' => $appStoreId]]);
        $rows = $result['data'] ?? [];
        // Belt and braces: the filter is the API's, this keeps the page
        // honest if a later change widens it.
        $rows = array_values(array_filter($rows, static fn (array $r): bool => (int)($r['app_id'] ?? 0) === $appStoreId));
        // Fine values in numeric order, then the three coarse buckets in
        // their own order. Spelled as a map rather than array_search(...) ?: 0,
        // which returns 0 both for 'low' (index 0) and for no match at all —
        // safe only for as long as nothing unexpected reaches the column.
        $coarseOrder = ['low' => 0, 'medium' => 1, 'high' => 2];
        usort($rows, static function (array $a, array $b) use ($coarseOrder): int {
            $order = static fn (array $r): array => [
                $r['fine_value'] === null ? 1 : 0,
                (int)($r['fine_value'] ?? 0),
                $coarseOrder[(string)($r['coarse_value'] ?? '')] ?? count($coarseOrder),
            ];
            return $order($a) <=> $order($b);
        });
        return $rows;
    }

    /**
     * The newest postbacks for this app, or null when they could not be read.
     *
     * The two answers are kept apart on purpose. An empty list renders as
     * "Nothing received yet", which is a statement of fact about the app; if a
     * failed read also returned [], the page would make that statement on the
     * strength of an error and the user would go looking at their Info.plist
     * for a problem that is not there (error pattern #11 — a failure must not
     * resolve to a confident answer).
     *
     * @return list<array<string, mixed>>|null
     */
    private function recentPostbacks(int $appStoreId): ?array
    {
        try {
            // No signature filter: the card shows what arrived, trusted or
            // not, which is the point of looking at it during setup.
            $result = $this->postbacks->list([
                'limit' => 10,
                'app_id' => $appStoreId,
            ]);
        } catch (HttpException $e) {
            error_log('Mobile Apps setup: could not read postbacks for app ' . $appStoreId . ': ' . $e->getMessage());
            return null;
        }
        return $result['data'] ?? [];
    }

    /**
     * Development-signed postbacks waiting for an app that does not trust
     * them. The page offers the toggle where the count appears instead of
     * leaving a setting for the user to find.
     *
     * Counted through the postbacks controller rather than a query of its
     * own: it already scopes to the owner and knows what "development" means,
     * and a hand-rolled statement here would be a second place to keep right
     * (CLAUDE.md #1 and #7 — the pattern check refuses one).
     *
     * @param list<array<string, mixed>> $apps
     * @return array<int, int> attribution_app_id => count
     */
    private function developmentNudges(array $apps): array
    {
        $nudges = [];
        foreach ($apps as $app) {
            if ((int)($app['accept_development_postbacks'] ?? 0) === 1) {
                continue;
            }
            try {
                $result = $this->postbacks->list([
                    'app_id' => (int)($app['app_id'] ?? 0),
                    'signature' => 'development',
                    'limit' => 1,
                ]);
            } catch (HttpException) {
                continue;
            }
            $count = (int)($result['pagination']['total'] ?? 0);
            if ($count > 0) {
                $nudges[(int)$app['attribution_app_id']] = $count;
            }
        }
        return $nudges;
    }
}
