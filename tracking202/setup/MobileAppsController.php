<?php

declare(strict_types=1);

namespace Tracking202\Setup;

use Api\V3\Apps\AppIdentity;
use Api\V3\Apps\Android\Integrity\IntegrityMode;
use Api\V3\Apps\StoreLink;
use Api\V3\Controllers\AppIntegrityController;
use Api\V3\Controllers\AppInstallsController;
use Api\V3\Controllers\AppLinksController;
use Api\V3\Controllers\AppRegistrationsController;
use Api\V3\Apps\Apple\SkanEncodingTimeline;
use Api\V3\Controllers\AppSkanEncodingsController;
use Api\V3\Controllers\AppPostbacksController;
use Api\V3\Controllers\AppReportController;
use Api\V3\Controllers\CampaignsController;
use Api\V3\Controllers\UsersController;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Api\V3\HttpException;
use Prosper202\Conversion\Ledger\Amount;
use Prosper202\Database\Connection;
use Prosper202\Goals\GoalDefinition;
use Prosper202\Goals\GoalEngineException;
use Prosper202\Goals\GoalScope;
use Prosper202\Goals\InvalidGoalDefinition;
use Prosper202\Goals\PlainGoals;
use Tracking202\Apps\AppIcons;
use Tracking202\Apps\RegisteredApps;
use Tracking202\Apps\StoreListing;

require_once __DIR__ . '/_base/SetupController.php';
require_once __DIR__ . '/../../202-config/functions-install-helpers.php';
require_once __DIR__ . '/_includes/app_goals.php';

/**
 * Setup › Mobile Apps: register the apps you advertise — iOS and Android —
 * and set each one up: its settings, its goals and funnel, the SKAN
 * conversion values an iOS app reports, Play Integrity for an Android app,
 * and the store link its campaigns send clicks to (plan §5.6, PR 11).
 *
 * Every write goes through the v3 controllers in-process, so this page, the
 * REST API and the CLI enforce the same rules and answer with the same
 * sentences — a message shown here is the API's own words, not a second copy
 * that can drift from them. No API key is involved: the controllers take the
 * session's user id directly.
 *
 * Post-redirect-get throughout, with CSRF from the base controller. The page
 * is on the v2 shell (Bootstrap 5.3 + the component layer), so its markup
 * carries no Bootstrap 3 or Flat UI class; NoLegacyBootstrapClassesTest
 * fails the build if one appears.
 */
class MobileAppsController extends SetupController
{
    /** The write permission, shared with attribution models. */
    private const MANAGE_PERMISSION = 'manage_attribution_models';

    /** How many campaigns the link builder offers, newest first. */
    private const LINK_BUILDER_CAMPAIGNS = 200;

    private \mysqli $db;
    private AppRegistrationsController $apps;
    private AppSkanEncodingsController $rules;
    private AppPostbacksController $postbacks;
    private UsersController $users;
    private PlainGoals $plainGoals;

    /** @var list<array{kind: string, text: string}> */
    private array $flashes = [];
    /** @var array<string, string> field name => the API's own message */
    private array $fieldErrors = [];
    /** @var array<string, mixed> what the form should show again after a failed submit */
    private array $formState = [];
    /** Which form on the app page a failed submit came from, so the page opens there. */
    private string $failedForm = '';

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
        $this->apps = new AppRegistrationsController($db, $userId);
        $this->rules = new AppSkanEncodingsController($db, $userId);
        $this->postbacks = new AppPostbacksController($db, $userId);
        $this->users = new UsersController($db);
        $this->plainGoals = new PlainGoals(new Connection($db));
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
                'register'             => $this->registerApp(),
                'update'               => $this->updateApp(),
                'remove'               => $this->removeApp(),
                'accept_dev'           => $this->setDevelopmentTrust(),
                'rule_save'            => $this->saveRule(),
                'rule_remove'          => $this->removeRule(),
                'starter_schema'       => $this->applyStarterSchema(),
                'rotate_token'         => $this->rotateToken(),
                'integrity_mode'       => $this->setIntegrityMode(),
                'integrity_credential' => $this->setIntegrityCredential(),
                'integrity_clear'      => $this->clearIntegrityCredential(),
                'goal_save'            => $this->saveGoal(),
                'goal_archive'         => $this->archiveGoal(),
                'link_apply'           => $this->applyLink(),
                default                => throw new \InvalidArgumentException('Unknown action.'),
            };
        } catch (ValidationException $e) {
            // The API's per-field sentences, shown under the fields they name.
            $this->fieldErrors = $e->getFieldErrors();
            if ($this->fieldErrors === []) {
                $this->flash('bad', $e->getMessage());
            }
            $this->keepForm($action);
            $this->restoreContext($action);
        } catch (HttpException $e) {
            // 409 duplicate, 404, and the rest: the API's own message.
            $this->flash('bad', $e->getMessage());
            $this->keepForm($action);
            $this->restoreContext($action);
        }
    }

    /**
     * What the failed form showed, to show it again — never the service
     * account key: a page that echoed a refused private key back into the
     * form would put it in the HTML, the browser's form cache and any
     * screenshot of the error.
     */
    private function keepForm(string $action): void
    {
        $posted = $_POST;
        unset($posted['credential'], $posted['csrf_token']);
        $this->formState = $posted;
        $this->failedForm = $action;
    }

    /**
     * Put the page back where the failed submit came from.
     *
     * handleGet() reads the app id from the query string, and a POST to this
     * page has none — so a refused rule save re-rendered the apps LIST, where
     * neither the rules form nor its per-field error exists. The user's
     * revenue was rejected and the page said nothing at all (error pattern
     * #4). The id is in the submitted body, which is where it has to come
     * from: it is the only record of which app the form belonged to.
     *
     * A missing or foreign id falls through to the list rather than throwing;
     * apps->get() is scoped to this user, so it cannot restore somebody
     * else's app.
     */
    private function restoreContext(string $action): void
    {
        $id = (int)($_POST['registration_id'] ?? 0);
        if ($id <= 0) {
            $this->fallBackToList();
            return;
        }

        try {
            $app = $this->apps->get($id)['data'];
        } catch (HttpException) {
            $this->fallBackToList();
            return;
        }

        // 'update' from the list's edit form goes back there; the app page's
        // own settings form says so with return_to.
        if ($action === 'update' && (string)($_POST['return_to'] ?? '') !== 'app') {
            $this->editingApp = $app;
            return;
        }
        $this->currentApp = $app;
    }

    /**
     * Render the apps list after a submit whose form cannot be restored.
     *
     * Per-field errors are drawn beside their fields, and the list has none of
     * those fields — so on this path they would be silently dropped, which is
     * the failure this whole branch exists to avoid. Say them as flashes
     * instead: the wrong words in the wrong place still beat none.
     */
    private function fallBackToList(): void
    {
        foreach ($this->fieldErrors as $message) {
            $this->flash('bad', $message);
        }
        $this->fieldErrors = [];
        $this->handleGet();
    }

    private function postedId(): int
    {
        return (int)($_POST['registration_id'] ?? 0);
    }

    // ─── Apps ────────────────────────────────────────────────────────

    /**
     * Register from one field: an App Store or Google Play link, a bare App
     * Store id or a package name.
     *
     * The platform, the key and (when the store answers) the name and icon
     * are derived rather than asked for. What was derived is shown on the
     * app's page, so nothing is silently assumed. If the name cannot be
     * derived the form comes back with a name field rather than registering
     * the app under a placeholder.
     */
    private function registerApp(): void
    {
        $reference = trim((string)($_POST['app_reference'] ?? ''));
        if ($reference === '') {
            throw new ValidationException('Validation failed', [
                'app_reference' => 'Paste the app\'s App Store or Google Play link (or its App Store id or package name).',
            ]);
        }
        // AppIdentity is the one reader of store links, shared with the API's
        // store_link and the CLI's --store-link; its sentence is shown under
        // the one field the user filled in.
        try {
            $identity = AppIdentity::fromStoreLink($reference);
        } catch (ValidationException $e) {
            throw new ValidationException('Validation failed', [
                'app_reference' => $e->getFieldErrors()['store_link'] ?? $e->getMessage(),
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
        $mine = $this->apps->list([
            'filter' => ['platform' => $identity->platform, 'app_key' => $identity->appKey],
            'limit' => 1,
        ])['data'];
        if ($mine !== []) {
            $this->redirect('tracking202/setup/mobile_apps.php?app='
                . (int)$mine[0]['registration_id'] . '&already=1');
        }

        $name = trim((string)($_POST['app_name'] ?? ''));
        $icon = null;
        if ($name === '') {
            $listing = (new StoreListing())->lookUp($identity);
            $name = $listing['name'];
            $icon = $listing['icon'];
        }
        if ($name === '') {
            // Everything except the name was derived; ask only for that.
            $this->formState = $_POST + [
                'derived_app_key' => $identity->appKey,
                'derived_platform' => $identity->platform,
                'needs_name' => true,
            ];
            $store = $identity->platform === AppIdentity::IOS ? 'The App Store' : 'Google Play';
            $this->fieldErrors['app_name'] = $store . ' did not answer, so the name could not be looked up. Type it once and it is saved with the registration.';
            return;
        }

        $payload = [
            'platform' => $identity->platform,
            'app_key' => $identity->appKey,
            'app_name' => $name,
            'notes' => trim((string)($_POST['notes'] ?? '')),
            'accept_test_signals' => isset($_POST['accept_test_signals']) ? 1 : 0,
        ];
        $created = $this->apps->create($payload)['data'];
        $registrationId = (int)$created['registration_id'];

        if ($icon !== null) {
            // Best effort, after the registration committed: an icon is not
            // worth failing a registration over, and the page shows the
            // platform's own mark without one.
            try {
                (new AppIcons(new Connection($this->db)))->store($this->getUserId(), $registrationId, $icon);
            } catch (\Throwable $e) {
                error_log('Mobile Apps setup: the icon of registration ' . $registrationId . ' was not stored: ' . $e->getMessage());
            }
        }

        $this->sendSlackNotification('mobile_app_registered', [
            'app' => $name,
            'app_id' => $identity->appKey,
        ]);
        $this->redirect('tracking202/setup/mobile_apps.php?app=' . $registrationId . '&registered=1');
    }

    /**
     * Save an app's settings: its name and notes, test signals, and for an
     * Android app the attribution window and whether client revenue may be
     * paid. The Android fields are sent as typed (the API reads them raw and
     * refuses `07` or `1.5` by name); a blank window is not sent, so the
     * stored one stands.
     */
    private function updateApp(): void
    {
        $id = $this->postedId();
        $payload = [
            'app_name' => trim((string)($_POST['app_name'] ?? '')),
            'notes' => trim((string)($_POST['notes'] ?? '')),
            'accept_test_signals' => isset($_POST['accept_test_signals']) ? 1 : 0,
        ];
        if ((string)($_POST['platform'] ?? '') === AppIdentity::ANDROID) {
            $window = trim((string)($_POST['attribution_window_days'] ?? ''));
            if ($window !== '') {
                $payload['attribution_window_days'] = $window;
            }
            $payload['trust_client_revenue'] = isset($_POST['trust_client_revenue']) ? 1 : 0;
        }
        $this->apps->update($id, $payload);
        $this->redirect((string)($_POST['return_to'] ?? '') === 'app'
            ? 'tracking202/setup/mobile_apps.php?app=' . $id . '&saved=1'
            : 'tracking202/setup/mobile_apps.php?saved=1');
    }

    private function removeApp(): void
    {
        $this->apps->delete($this->postedId());
        $this->redirect('tracking202/setup/mobile_apps.php?removed=1');
    }

    /** The nudge's one click, and the Advanced toggle, are the same write. */
    private function setDevelopmentTrust(): void
    {
        $id = $this->postedId();
        $accept = (string)($_POST['accept'] ?? '0') === '1';
        $this->apps->update($id, ['accept_test_signals' => $accept ? 1 : 0]);
        $this->redirect('tracking202/setup/mobile_apps.php?app=' . $id . '&dev=' . ($accept ? '1' : '0'));
    }

    private function rotateToken(): void
    {
        $id = $this->postedId();
        $this->apps->rotateAppToken($id);
        $this->redirect('tracking202/setup/mobile_apps.php?app=' . $id . '&rotated=1');
    }

    // ─── Play Integrity (Android) ────────────────────────────────────

    /**
     * The mode and the Cloud project number, in one write, as the API takes
     * them: observe and require need a credential stored first and a
     * project number (sent here, or already stored), and the number is
     * replaced, never cleared — so a blank field is not sent.
     */
    private function setIntegrityMode(): void
    {
        $id = $this->postedId();
        $payload = ['integrity_mode' => (string)($_POST['integrity_mode'] ?? '')];
        $number = trim((string)($_POST['integrity_cloud_project_number'] ?? ''));
        if ($number !== '') {
            $payload['integrity_cloud_project_number'] = $number;
        }
        $this->apps->update($id, $payload);
        $this->redirect('tracking202/setup/mobile_apps.php?app=' . $id . '&integrity=mode#integrity');
    }

    /**
     * Set or rotate the service account, from the key file's JSON pasted or
     * uploaded. Malformed JSON is refused by name (error pattern #4), never
     * sent as an empty object the API would then describe as a bad key.
     */
    private function setIntegrityCredential(): void
    {
        $id = $this->postedId();
        $raw = trim((string)($_POST['credential'] ?? ''));
        $upload = $_FILES['credential_file'] ?? null;
        if ($raw === '' && is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            if ((int)($upload['size'] ?? 0) > 65536) {
                throw new ValidationException('Validation failed', ['credential' => 'A service-account key file is a few kilobytes; this one is over 64 KB.']);
            }
            $read = @file_get_contents((string)$upload['tmp_name']);
            if ($read === false) {
                throw new ValidationException('Validation failed', ['credential' => 'The uploaded key file could not be read.']);
            }
            $raw = trim($read);
        }
        if ($raw === '') {
            throw new ValidationException('Validation failed', ['credential' => 'Paste the service account\'s JSON key file, or choose the file.']);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new ValidationException('Validation failed', ['credential' => json_last_error() === JSON_ERROR_NONE
                ? 'That is JSON but not an object: paste the whole key file, braces included.'
                : 'That is not JSON (' . json_last_error_msg() . '): paste the key file exactly as Google gave it.']);
        }
        try {
            (new AppIntegrityController($this->db, $this->getUserId()))->setCredential($id, ['credential' => $decoded]);
        } catch (ValidationException $e) {
            // The credential's own field errors (credential.private_key, …)
            // under the one field the form has.
            throw new ValidationException($e->getMessage(), ['credential' => implode(' ', array_map(
                static fn (string $field, string $why): string => $field . ': ' . $why,
                array_keys($e->getFieldErrors()),
                array_map('strval', array_values($e->getFieldErrors()))
            )) ?: $e->getMessage()], $e);
        }
        $this->redirect('tracking202/setup/mobile_apps.php?app=' . $id . '&integrity=credential#integrity');
    }

    private function clearIntegrityCredential(): void
    {
        $id = $this->postedId();
        (new AppIntegrityController($this->db, $this->getUserId()))->clearCredential($id);
        $this->redirect('tracking202/setup/mobile_apps.php?app=' . $id . '&integrity=cleared#integrity');
    }

    // ─── Goals ───────────────────────────────────────────────────────

    private function saveGoal(): void
    {
        $id = $this->postedId();
        $this->assertOwnApp($id);
        $errors = p202_app_goal_save($this->db, $this->getUserId(), $id, p202_goal_form_values(null, $_POST));
        if ($errors === []) {
            $this->redirect('tracking202/setup/mobile_apps.php?app=' . $id . '&goal_saved=1#goals');
        }
        $this->fieldErrors = $errors;
        $this->keepForm('goal_save');
        $this->restoreContext('goal_save');
    }

    private function archiveGoal(): void
    {
        $id = $this->postedId();
        $this->assertOwnApp($id);
        $refusal = p202_app_goal_archive($this->db, $this->getUserId(), $id, (string)($_POST['goal_id'] ?? ''));
        if ($refusal === null) {
            $this->redirect('tracking202/setup/mobile_apps.php?app=' . $id . '&goal_archived=1#goals');
        }
        $this->flash('bad', $refusal);
        $this->restoreContext('goal_archive');
    }

    /** The registration is the user's, or a 404 in the API's words. */
    private function assertOwnApp(int $id): void
    {
        $this->apps->get($id);
    }

    // ─── Link builder ────────────────────────────────────────────────

    /**
     * Make a campaign send its clicks to this app's store link: what the
     * link builder's read says to apply, applied through PUT /campaigns —
     * the same round trip as `p202 app link --apply`.
     */
    private function applyLink(): void
    {
        $id = $this->postedId();
        $campaign = trim((string)($_POST['campaign_id'] ?? ''));
        if (preg_match('/^[1-9][0-9]*$/D', $campaign) !== 1) {
            throw new ValidationException('Validation failed', ['campaign_id' => 'Choose the campaign that advertises this app.']);
        }
        $state = (new AppLinksController($this->db, $this->getUserId()))->storeLink($id, ['campaign_id' => $campaign])['data']['campaign'];
        $change = (array)$state['apply'];
        if ($change !== []) {
            (new CampaignsController($this->db, $this->getUserId()))->update((int)$campaign, $change);
        }
        $this->redirect('tracking202/setup/mobile_apps.php?app=' . $id . '&link_campaign=' . (int)$campaign . '&linked=' . ($change === [] ? 'already' : '1') . '#link-builder');
    }

    // ─── Conversion values ───────────────────────────────────────────

    /**
     * Add or change one rule.
     *
     * The kind is a radio because the API accepts exactly one of fine or
     * coarse; changing an existing rule's kind sends the explicit null for
     * the other one in the same request, which is what the API requires.
     *
     * A value means a goal (plan §4.5; PR 8: any goal a device can reach).
     * The form offers the app's and the account's goals by name, and — for
     * what an operator usually knows — an event and a revenue instead: the
     * event becomes the app's plain goal for it (found, or created —
     * PlainGoals) and the revenue the encoding's revenue_override. The goal
     * and the rule are written in one transaction: a rule the API refuses
     * leaves no goal behind. The API's field errors come back under the
     * fields this form has (goal_id → Goal or Event, revenue_override →
     * Revenue).
     */
    private function saveRule(): void
    {
        $appRowId = $this->postedId();
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        $kind = (string)($_POST['kind'] ?? 'fine');
        $eventName = trim((string)($_POST['event_name'] ?? ''));
        $goalChoice = trim((string)($_POST['goal_choice'] ?? ''));
        $revenue = trim((string)($_POST['revenue'] ?? ''));

        if ($revenue !== '' && GoalDefinition::amountUnits($revenue) === null) {
            throw new ValidationException('Validation failed', [
                'revenue' => 'Enter an amount of 0 or more, with at most 5 decimal places (for example 4.99).',
            ]);
        }

        $payload = [
            'registration_id' => $appRowId,
            'revenue_override' => $revenue === '' ? null : $revenue,
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

        $this->inTransaction(function () use ($appRowId, $ruleId, $eventName, $goalChoice, $payload): void {
            $goalField = 'event_name';
            if ($goalChoice !== '' && $goalChoice !== 'event') {
                if (preg_match('/^[1-9][0-9]*$/D', $goalChoice) !== 1) {
                    throw new ValidationException('Validation failed', ['goal_choice' => 'Choose one of the goals listed, or a new event.']);
                }
                $payload['goal_id'] = (int)$goalChoice;
                $goalField = 'goal_choice';
            } else {
                $payload['goal_id'] = $this->goalForEvent($appRowId, $eventName);
            }
            try {
                if ($ruleId > 0) {
                    $this->rules->update($ruleId, $payload);
                } else {
                    $this->rules->create($payload);
                }
            } catch (ValidationException $e) {
                $errors = [];
                foreach ($e->getFieldErrors() as $field => $message) {
                    $errors[['goal_id' => $goalField, 'revenue_override' => 'revenue'][$field] ?? $field] = $message;
                }
                throw new ValidationException($e->getMessage(), $errors, $e);
            }
        });
        $this->redirect('tracking202/setup/mobile_apps.php?app=' . $appRowId . '&rule=' . ($ruleId > 0 ? 'changed' : '1'));
    }

    /** The app's plain goal for an event, as a field error on the form's Event field when it cannot be. */
    private function goalForEvent(int $appRowId, string $eventName): int
    {
        if ($eventName === '') {
            throw new ValidationException('Validation failed', ['event_name' => 'Enter the event this value means, as your app logs it (for example purchase), or choose a goal.']);
        }
        try {
            return $this->plainGoals->forEvent($this->getUserId(), GoalScope::REGISTRATION, $appRowId, $eventName, time());
        } catch (InvalidGoalDefinition) {
            throw new ValidationException('Validation failed', [
                'event_name' => 'An event name is 1-64 letters, digits, _ . : or -, starting with a letter, digit or _ (for example level_up).',
            ]);
        } catch (GoalEngineException $e) {
            throw $e->reason === GoalEngineException::NOT_FOUND
                ? new NotFoundException($e->getMessage(), $e)
                : new ConflictException($e->getMessage(), [], $e);
        }
    }

    /**
     * Run a write that spans the goal and the rule in one transaction.
     *
     * @param callable(): void $work
     */
    private function inTransaction(callable $work): void
    {
        if (!$this->db->begin_transaction()) {
            throw new \RuntimeException('Could not start a transaction for the rule.');
        }
        try {
            $work();
            if (!$this->db->commit()) {
                throw new \RuntimeException('Could not commit the rule.');
            }
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    private function removeRule(): void
    {
        $appRowId = $this->postedId();
        $this->rules->delete((int)($_POST['rule_id'] ?? 0));
        $this->redirect('tracking202/setup/mobile_apps.php?app=' . $appRowId . '&rule_removed=1');
    }

    /**
     * The first click after registering: the three rules almost every app
     * starts with, offered in place instead of an empty table.
     */
    private function applyStarterSchema(): void
    {
        $appRowId = $this->postedId();
        $starter = [
            ['fine_value' => 1,  'coarse_value' => null,     'event' => 'install'],
            ['fine_value' => 10, 'coarse_value' => null,     'event' => 'trial_started'],
            ['fine_value' => 40, 'coarse_value' => null,     'event' => 'purchase'],
            ['fine_value' => null, 'coarse_value' => 'low',    'event' => 'install'],
            ['fine_value' => null, 'coarse_value' => 'medium', 'event' => 'trial_started'],
            ['fine_value' => null, 'coarse_value' => 'high',   'event' => 'purchase'],
        ];
        $added = 0;
        foreach ($starter as $rule) {
            try {
                // Each rule with its goal, or neither: a value already mapped
                // keeps its rule, and the goal it would have named is not
                // created for nothing.
                $this->inTransaction(function () use ($rule, $appRowId): void {
                    $this->rules->create([
                        'registration_id' => $appRowId,
                        'fine_value' => $rule['fine_value'],
                        'coarse_value' => $rule['coarse_value'],
                        'goal_id' => $this->goalForEvent($appRowId, $rule['event']),
                    ]);
                });
                $added++;
            } catch (ConflictException) {
                // A value already mapped keeps the rule it has: the starter
                // schema fills the gaps, it never overwrites a decision. Only
                // the conflict is caught — catching HttpException swallowed a
                // failed prepare, a rejected payload and a
                // WriteCommittedException alike, counted each as "already
                // mapped", and then redirected to a success message computed
                // from $added. A database that faltered halfway left a
                // partial schema while the page said the rest were already
                // there. Everything else reaches handleRequest()'s handler,
                // which says what went wrong.
            }
        }
        $this->redirect('tracking202/setup/mobile_apps.php?app=' . $appRowId . '&starter=' . $added);
    }

    // ─── Deriving what the app can derive ────────────────────────────

    /**
     * How many apps the page is showing, in the two wordings it needs.
     *
     * The "Your apps" pill and the "Getting started" checklist are two
     * readings of one list, so they are derived together: a checklist saying
     * "500 registered" beside a pill saying "500 of 620 apps" is two numbers
     * for one list, on one page, and that is what a second copy of this rule
     * produced. Truncation is never rounded away — a bare count would state
     * a wrong number as fact and the list below would simply end.
     *
     * @param  int      $shown      rows this render is actually displaying
     * @param  int|null $total      rows that exist, or null when unknown
     * @param  bool     $truncated  whether the read hit RegisteredApps::MAX
     * @return array{pill: string, checklist: string}
     */
    public static function appCountLabels(int $shown, ?int $total, bool $truncated): array
    {
        if (!$truncated) {
            return [
                'pill' => $shown . ($shown === 1 ? ' app' : ' apps'),
                'checklist' => $shown . ' registered',
            ];
        }
        if ($total !== null) {
            return [
                'pill' => $shown . ' of ' . $total . ' apps',
                'checklist' => $shown . ' of ' . $total,
            ];
        }

        // Cut, and the total unknown: "500 of 500" would be a figure the
        // reader believes and the reader should not.
        return [
            'pill' => 'first ' . $shown . ' apps',
            'checklist' => 'first ' . $shown . ' shown',
        ];
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
        // The count pill in the "Your apps" panel says whether the list was
        // cut, rather than a flash at the top of the page: the fact belongs
        // beside the list it is about, and an account large enough to hit the
        // ceiling would see that flash on every single page load.
        $registered = $this->listApps();

        $view = [
            'canManage' => $this->canManage(),
            'csrf' => $this->renderCsrfField(),
            'fieldErrors' => $this->fieldErrors,
            'form' => $this->formState,
            'failedForm' => $this->failedForm,
            'baseUrl' => rtrim(get_absolute_url(), '/') . '/',
            // The absolute install URL. Apple is handed this origin and the
            // page prints it for copying, so a path alone will not do;
            // install_request_base_url() is the existing helper for it and
            // documents that the Host header is attacker-controlled, which is
            // why every use of it in the template is escaped.
            'origin' => install_request_base_url($_SERVER, get_absolute_url()),
            'currency' => $this->accountCurrency(),
            'apps' => $registered['apps'],
            'appsTotal' => $registered['total'],
            'appsTruncated' => $registered['truncated'],
            'app' => $this->currentApp,
            'editing' => $this->editingApp,
            'icons' => $this->icons(array_merge(
                array_map(static fn (array $a): int => (int)$a['registration_id'], $registered['apps']),
                $this->currentApp === null ? [] : [(int)$this->currentApp['registration_id']]
            )),
        ];
        foreach ($this->flashesFromQuery($this->currentApp) as $flash) {
            $this->flashes[] = $flash;
        }
        if ($this->currentApp !== null) {
            $rowId = (int)$this->currentApp['registration_id'];
            $isIos = (string)$this->currentApp['platform'] === AppIdentity::IOS;
            $view['goals'] = $this->goalsFor($rowId);
            $view['link'] = $this->linkBuilder($rowId);
            if ($isIos) {
                $view['rules'] = $this->rulesFor($rowId);
                $view['defaultRules'] = $this->rulesFor(0);
                $view['recent'] = $this->recentPostbacks($rowId);
            } else {
                $view['integrity'] = $this->integrityStatus($rowId);
                $view['recentInstalls'] = $this->recentInstalls($rowId);
                $view['funnel'] = $this->funnelCounts($rowId);
            }
        }
        $view['nudges'] = $this->developmentNudges($view['apps']);
        $view['flashes'] = $this->flashes;

        $mobileApps = $view;
        require __DIR__ . '/templates/mobile_apps.php';
    }

    /**
     * @param array<string, mixed>|null $app the app being shown, which some sentences depend on
     * @return list<array{kind: string, text: string}>
     */
    private function flashesFromQuery(?array $app): array
    {
        $android = $app !== null && (string)$app['platform'] === AppIdentity::ANDROID;
        $out = [];
        if (isset($_GET['registered'])) {
            $out[] = ['kind' => 'ok', 'text' => $android
                ? 'App registered, with its built-in install goal. Next: point a campaign at its store link below, and build the app with the SDK and its app token.'
                : 'App registered. Its postbacks are claimed from now on, and any already received were claimed too.'];
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
            $out[] = ['kind' => 'ok', 'text' => 'App token replaced. The old token stopped working just now; apps keep their last cached schema until they fetch with the new one.'];
        }
        if (isset($_GET['dev'])) {
            $out[] = ['kind' => 'ok', 'text' => (string)$_GET['dev'] === '1'
                ? ($android ? 'Test installs now count for this app.' : 'Development-signed postbacks are now trusted for this app, including the ones already received.')
                : ($android ? 'Test installs no longer count for this app.' : 'Development-signed postbacks are untrusted again for this app, including the ones already received.')];
        }
        if (isset($_GET['rule'])) {
            // An edit changes what a value means while devices still hold the
            // schema from before it, and a postback can arrive up to
            // HORIZON_DAYS after the value was set (SkanEncodingTimeline):
            // say so where the edit is made (plan §5.5).
            $out[] = (string)$_GET['rule'] === 'changed'
                ? ['kind' => 'warn', 'text' => 'Conversion value changed. Devices keep the schema they already fetched for a while, and a postback can arrive up to '
                    . SkanEncodingTimeline::HORIZON_DAYS . ' days after the value was set, so until '
                    . gmdate('j M Y', time() + SkanEncodingTimeline::HORIZON_SECONDS) . ' a postback carrying this value is reported as ambiguous_encoding, and credited to neither meaning, wherever the old and new meanings disagree. The report is exact again after that.']
                : ['kind' => 'ok', 'text' => 'Conversion value saved.'];
        }
        if (isset($_GET['rule_removed'])) {
            $out[] = ['kind' => 'ok', 'text' => 'Conversion value removed. Postbacks that carry it keep decoding under its old meaning for '
                . SkanEncodingTimeline::HORIZON_DAYS . ' days, because devices set it before the change.'];
        }
        if (isset($_GET['starter'])) {
            $added = (int)$_GET['starter'];
            $out[] = ['kind' => $added > 0 ? 'ok' : 'warn', 'text' => $added > 0
                ? $added . ' starter ' . ($added === 1 ? 'rule' : 'rules') . ' added. Edit them to match what your app reports.'
                : 'Every starter value is already mapped, so nothing was changed.'];
        }
        if (isset($_GET['goal_saved'])) {
            $out[] = ['kind' => 'ok', 'text' => 'Goal saved. It evaluates events received from now on.'];
        }
        if (isset($_GET['goal_archived'])) {
            $out[] = ['kind' => 'ok', 'text' => 'Goal archived. Its outcomes keep their history; it stops evaluating new events.'];
        }
        if (isset($_GET['integrity'])) {
            $out[] = ['kind' => 'ok', 'text' => match ((string)$_GET['integrity']) {
                'credential' => 'Service account saved. Its key is stored encrypted and is never shown again.',
                'cleared' => 'Service account deleted.',
                default => 'Play Integrity setting saved. Installs already received keep the mode they arrived under.',
            }];
        }
        if (isset($_GET['linked'])) {
            $out[] = ['kind' => 'ok', 'text' => (string)$_GET['linked'] === 'already'
                ? 'That campaign already sends its clicks to this app\'s store link. Nothing was changed.'
                : ($android
                    ? 'Campaign updated: its offer URL is the store link with the install token, and it is linked to this app.'
                    : 'Campaign updated: its offer URL is the App Store link.')];
        }
        return $out;
    }

    /**
     * The account's currency, for rendering revenue.
     *
     * Amounts on this page are money, and this install is not necessarily a
     * dollar one. UsersController owns the answer because it owns preferences,
     * and Analyze > Mobile Apps asks the same question — one validator, so the
     * fallback for an unreadable value cannot differ between two pages that
     * print the same amounts.
     */
    private function accountCurrency(): string
    {
        return $this->users->accountCurrency($this->getUserId());
    }

    /**
     * The registered apps for the panel. RegisteredApps carries the ceiling
     * and the ordering, so this page and Analyze's App filter cannot disagree
     * about which apps exist; a read failure keeps propagating to the page's
     * own handler, which is what turns it into a redirect.
     *
     * @return array{apps: list<array<string, mixed>>, total: int|null, truncated: bool}
     */
    private function listApps(): array
    {
        return RegisteredApps::read($this->apps);
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function icons(array $ids): array
    {
        try {
            return (new AppIcons(new Connection($this->db)))->forApps($this->getUserId(), $ids);
        } catch (\Throwable $e) {
            // An icon is decoration: the platform's own mark stands in.
            error_log('Mobile Apps setup: icons could not be read: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * The app's goals and the account's, for the goals panel and the value
     * editor's goal menu, or null when they could not be read — said on the
     * page rather than shown as "no goals" (error pattern #11).
     *
     * @return array{own: list<array<string, mixed>>, account: list<array<string, mixed>>}|null
     */
    private function goalsFor(int $registrationId): ?array
    {
        try {
            return p202_app_goal_list($this->db, $this->getUserId(), $registrationId);
        } catch (HttpException $e) {
            error_log('Mobile Apps setup: goals of registration ' . $registrationId . ' could not be read: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * How many installs reached each of the app's goals (trusted, and the
     * unvouched beside them), from the report's funnel reading; null when it
     * could not be read.
     *
     * @return array<int, array{installs: int, unvouched: int}>|null
     */
    private function funnelCounts(int $registrationId): ?array
    {
        try {
            $answer = (new AppReportController($this->db, $this->getUserId()))->report([
                'platform' => 'android', 'group_by' => 'goal', 'registration_id' => (string)$registrationId, 'limit' => '500',
            ]);
        } catch (HttpException $e) {
            error_log('Mobile Apps setup: the funnel of registration ' . $registrationId . ' could not be read: ' . $e->getMessage());
            return null;
        }
        $out = [];
        foreach ($answer['data']['groups'] as $group) {
            $out[(int)$group['goal_id']] = ['installs' => (int)$group['installs'], 'unvouched' => (int)$group['unvouched_count']];
        }
        return $out;
    }

    /**
     * The link builder: the app's store link, the user's campaigns to point
     * at it, and — for the one chosen (?link_campaign=) — whether it already
     * does. Null parts are said on the page, never shown as empty lists.
     *
     * @return array<string, mixed>
     */
    private function linkBuilder(int $registrationId): array
    {
        $links = new AppLinksController($this->db, $this->getUserId());
        $chosen = (string)($this->formState['campaign_id'] ?? ($_GET['link_campaign'] ?? ''));
        $chosen = preg_match('/^[1-9][0-9]*$/D', $chosen) === 1 ? $chosen : '';
        $out = ['store' => null, 'campaign' => null, 'campaigns' => null, 'chosen' => $chosen];
        try {
            $store = $links->storeLink($registrationId, $chosen === '' ? [] : ['campaign_id' => $chosen])['data'];
            $out['store'] = $store;
            $out['campaign'] = $store['campaign'] ?? null;
        } catch (NotFoundException) {
            // The chosen campaign is gone (or never was the user's): the
            // builder still shows the link, and the menu offers the rest.
            try {
                $out['store'] = $links->storeLink($registrationId, [])['data'];
            } catch (HttpException $e) {
                error_log('Mobile Apps setup: the store link of registration ' . $registrationId . ' could not be read: ' . $e->getMessage());
            }
            $out['chosen'] = '';
        } catch (HttpException $e) {
            error_log('Mobile Apps setup: the store link of registration ' . $registrationId . ' could not be read: ' . $e->getMessage());
        }
        try {
            $rows = (new CampaignsController($this->db, $this->getUserId()))->list(['limit' => self::LINK_BUILDER_CAMPAIGNS])['data'] ?? [];
            usort($rows, static fn (array $a, array $b): int => (int)$b['aff_campaign_id'] <=> (int)$a['aff_campaign_id']);
            $out['campaigns'] = $rows;
        } catch (HttpException $e) {
            error_log('Mobile Apps setup: campaigns could not be read for the link builder: ' . $e->getMessage());
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    private function integrityStatus(int $registrationId): ?array
    {
        try {
            return (new AppIntegrityController($this->db, $this->getUserId()))->status($registrationId)['data'];
        } catch (HttpException $e) {
            error_log('Mobile Apps setup: Play Integrity status of registration ' . $registrationId . ' could not be read: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * The newest installs of an Android app, or null when they could not be
     * read (kept apart from "none yet" for recentPostbacks()'s reason).
     *
     * @return list<array<string, mixed>>|null
     */
    private function recentInstalls(int $registrationId): ?array
    {
        try {
            return (new AppInstallsController($this->db, $this->getUserId()))->list($registrationId, ['limit' => '10'])['data'] ?? [];
        } catch (HttpException $e) {
            error_log('Mobile Apps setup: could not read installs for registration ' . $registrationId . ': ' . $e->getMessage());
            return null;
        }
    }

    /** @return list<array<string, mixed>> */
    private function rulesFor(int $registrationId): array
    {
        $result = $this->rules->list(['limit' => 200, 'filter' => ['registration_id' => $registrationId]]);
        $rows = $result['data'] ?? [];
        // Belt and braces: the filter is the API's, this keeps the page
        // honest if a later change widens it.
        $rows = array_values(array_filter($rows, static fn (array $r): bool => (int)($r['registration_id'] ?? 0) === $registrationId));
        // What each rule means, in the form's own terms: the event its goal
        // waits for (else the goal's name) and what a decoded postback is
        // worth (the rule's override, else the goal's fixed value, else 0).
        $goals = $this->plainGoals->describe($this->getUserId(), array_map(static fn (array $r): int => (int)$r['goal_id'], $rows));
        foreach ($rows as &$row) {
            $goal = $goals[(int)$row['goal_id']] ?? null;
            $row['event_name'] = $goal['event'] ?? ($goal['name'] ?? ('goal ' . (int)$row['goal_id']));
            $row['goal_name'] = $goal['name'] ?? ('goal ' . (int)$row['goal_id']);
            $row['revenue'] = $row['revenue_override'] !== null
                ? (string)$row['revenue_override']
                : Amount::fromUnits($goal['fixed_units'] ?? 0);
        }
        unset($row);
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
    private function recentPostbacks(int $registrationId): ?array
    {
        try {
            // No signature filter: the card shows what arrived, trusted or
            // not, which is the point of looking at it during setup.
            $result = $this->postbacks->list([
                'limit' => 10,
                'registration_id' => $registrationId,
            ]);
        } catch (HttpException $e) {
            error_log('Mobile Apps setup: could not read postbacks for registration ' . $registrationId . ': ' . $e->getMessage());
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
     * @return array<int, int> registration_id => count
     */
    private function developmentNudges(array $apps): array
    {
        $waiting = [];
        foreach ($apps as $app) {
            // Postbacks are Apple's, so only iOS apps can have them waiting.
            if ((string)($app['platform'] ?? '') === AppIdentity::IOS && (int)($app['accept_test_signals'] ?? 0) !== 1) {
                $waiting[(int)$app['registration_id']] = true;
            }
        }
        if ($waiting === []) {
            return [];
        }

        // ONE grouped read, not one per app. This asked the postback list for
        // a count per app, and list() runs a COUNT and a row SELECT each — so
        // an account near the app ceiling issued something like a thousand
        // queries before the page rendered, every time, including when
        // opening a single app.
        //
        // report() already answers exactly this question — postbacks per app,
        // filtered by signature state — so the grouping is reused rather than
        // a second piece of SQL written for it. Its `postbacks` counts unique
        // postbacks rather than stored rows, which is the number the Analyze
        // report would show for the same app: a replay stops being counted
        // twice here too.
        //
        // registration_ids, not the limit. Groups come back busiest first, so asking
        // for `count($waiting)` groups over EVERY app returned the busiest
        // apps, not the waiting ones: one app that already accepts
        // development postbacks and has more of them took the only slot, and
        // the waiting app's nudge — the actionable one — was dropped by the
        // isset() below. Narrowing the query to the waiting ids makes the
        // limit a formality: there can be no more groups than ids.
        $ids = array_keys($waiting);
        try {
            $answer = $this->postbacks->report([
                'group_by' => 'registration',
                'signature' => 'development',
                'registration_ids' => $ids,
                'limit' => max(1, count($ids)),
            ]);
        } catch (HttpException) {
            return [];
        }

        $nudges = [];
        foreach ($answer['data']['groups'] ?? [] as $group) {
            $registrationId = (int)($group['registration_id'] ?? 0);
            $count = (int)($group['postbacks'] ?? 0);
            if ($count > 0 && isset($waiting[$registrationId])) {
                $nudges[$registrationId] = $count;
            }
        }

        return $nudges;
    }

    /** The Play Integrity modes, in the order the menu offers them. */
    public static function integrityModes(): array
    {
        return IntegrityMode::values();
    }

    /** The store link template for a registration, for places that have no builder read. */
    public static function storeLink(string $platform, string $appKey): string
    {
        return StoreLink::template($platform, $appKey);
    }
}
