<?php

declare(strict_types=1);

/**
 * Account › Attribution: the multi-touch attribution dashboard on the v2
 * shell (measurement-rewrite plan §6.3, §8 PR 10).
 *
 *   ?view=report     attributed conversions, revenue, cost and ROI by a
 *                    click dimension, under the effective model or a chosen
 *                    one, optionally side by side with a second model; a
 *                    CSV of exactly what is shown (&format=csv)
 *   ?view=journeys   journey length, time to convert, and the one-touch
 *                    share by browser (§6.2 "honest limits"): how much
 *                    journey the browsers' storage limits cost
 *   ?view=journey    one conversion's journey: its touches, the signals
 *                    that linked them, and every model's credit, summed
 *   ?view=models     the account's models: add, edit, activate, deactivate,
 *                    make default, delete
 *   ?view=exports    export jobs: now or scheduled, a file to download, and
 *                    an optional webhook (SSRF-checked, signed)
 *
 * Every read and write goes through Api\V3\Controllers\AttributionController,
 * the same object the v3 routes call, so the page cannot enforce a rule the
 * API does not or skip one it does (CLAUDE.md error pattern #5): a model
 * name, type, lookback or weighting the API would refuse is refused here in
 * its sentence, the default model cannot be deactivated or deleted, and a
 * webhook is checked by the same guard.
 *
 * Permissions are the API's too: view_attribution_reports to open the page
 * and to create, retry, delete and download exports; manage_attribution_models
 * to change a model. Every POST checks the session token before it reads
 * anything else, and every form that posts carries it.
 */

include_once __DIR__ . '/../202-config/connect.php';
require_once __DIR__ . '/../202-config/functions-account-ui.php';
require_once __DIR__ . '/../202-config/functions-attribution-ui.php';

use Api\V3\Controllers\AttributionController;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Api\V3\Exception\WriteCommittedException;
use Prosper202\Attribution\AttributionReports;
use Prosper202\Attribution\ExportCsv;
use Prosper202\Attribution\ModelConfig;
use Prosper202\Attribution\ModelType;

AUTH::require_user();

global $userObj, $db;

if (!isset($userObj) || !$userObj->hasPermission('view_attribution_reports')) {
	header('location: ' . get_absolute_url() . '202-account/');
	exit;
}

$canManage = $userObj->hasPermission('manage_attribution_models');
$userId = (int) ($_SESSION['user_id'] ?? 0);
$base = get_absolute_url();
$pageUrl = $base . '202-account/attribution.php';
$views = ['report' => 'Report', 'journeys' => 'Journeys', 'models' => 'Models', 'exports' => 'Exports'];
$view = (string) ($_GET['view'] ?? 'report');
if (!isset($views[$view]) && $view !== 'journey') {
	$view = 'report';
}
$h = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$api = new AttributionController($db, $userId);
$errors = [];
$form = [];
$formError = null;
$editId = null;

/** Where a write goes back to (post-redirect-get). */
$back = static fn (string $to): string => '202-account/attribution.php?view=' . $to;

/**
 * A model form's fields as the API's payload, typed the way the API reads
 * them: numbers that are not numbers are refused here, by field, rather
 * than passed on as strings the API would refuse with a less useful
 * sentence.
 *
 * @param array<string, mixed> $post
 * @param array<string, string> $errors
 * @return array<string, mixed>
 */
$modelPayload = static function (array $post, array &$errors): array {
	$payload = [
		'model_name' => trim((string) ($post['model_name'] ?? '')),
		'model_type' => (string) ($post['model_type'] ?? ''),
	];
	$lookback = trim((string) ($post['lookback_days'] ?? ''));
	if ($lookback === '') {
		$payload['lookback_days'] = ModelConfig::DEFAULT_LOOKBACK_DAYS;
	} elseif (preg_match('/^[0-9]{1,4}$/D', $lookback) !== 1) {
		$errors['lookback_days'] = 'A whole number of days from 1 to 365.';
	} else {
		$payload['lookback_days'] = (int) $lookback;
	}
	$number = static function (string $field) use ($post, &$errors): ?float {
		$raw = trim((string) ($post[$field] ?? ''));
		if ($raw === '') {
			return null;
		}
		if (preg_match('/^[0-9]{1,6}(\.[0-9]{1,8})?$/D', $raw) !== 1) {
			$errors[$field] = 'A number, such as ' . ($field === 'half_life_hours' ? '48' : '0.4') . '.';
			return null;
		}
		return (float) $raw;
	};
	$config = [];
	if ($payload['model_type'] === ModelType::TIME_DECAY->value) {
		$half = $number('half_life_hours');
		if ($half !== null) {
			$config['half_life_hours'] = $half;
		}
	} elseif ($payload['model_type'] === ModelType::POSITION_BASED->value) {
		foreach (['first_weight', 'last_weight'] as $weight) {
			$value = $number($weight);
			if ($value !== null) {
				$config[$weight] = $value;
			}
		}
	}
	$payload['weighting_config'] = $config;
	if (($post['is_default'] ?? '') === '1') {
		$payload['is_default'] = true;
	}
	return $payload;
};

/**
 * The API's field names as the form's: a weighting parameter is its own
 * field here, and anything the form has no field for is said above the form.
 *
 * @param array<string, string> $fieldErrors
 * @return array{0: array<string, string>, 1: string|null}
 */
$formErrors = static function (array $fieldErrors, array $fields): array {
	$mapped = [];
	$general = [];
	foreach ($fieldErrors as $field => $sentence) {
		$field = str_starts_with((string) $field, 'weighting_config.') ? substr((string) $field, 17) : (string) $field;
		if (in_array($field, $fields, true)) {
			$mapped[$field] = p202_attr_sentence((string) $sentence);
		} else {
			$general[] = p202_attr_sentence((string) $sentence);
		}
	}
	return [$mapped, $general === [] ? null : implode(' ', $general)];
};

$modelFields = ['model_name', 'model_type', 'lookback_days', 'half_life_hours', 'first_weight', 'last_weight'];
$exportFields = ['group_by', 'model_id', 'compare_model_id', 'range', 'run_at', 'webhook_url', 'webhook_secret'];

// ─── Writes ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	// The token first, before any field is read.
	if (!AUTH::check_csrf_token()) {
		p202_account_flash('bad', P202_ACCOUNT_TOKEN_REFUSED);
		p202_account_redirect($back($view === 'journey' ? 'report' : $view));
	}

	$action = (string) ($_POST['action'] ?? '');
	$id = preg_match('/^[1-9][0-9]{0,18}$/D', (string) ($_POST['id'] ?? '')) === 1 ? (int) $_POST['id'] : 0;
	$modelActions = ['create_model', 'update_model', 'set_default', 'set_status', 'delete_model'];
	$exportActions = ['create_export', 'retry_export', 'delete_export'];

	if (in_array($action, $modelActions, true) && !$canManage) {
		p202_account_flash('bad', 'Your role cannot change attribution models, so nothing was saved. An admin can grant "manage attribution models".');
		p202_account_redirect($back('models'));
	}
	if (!in_array($action, array_merge($modelActions, $exportActions), true)) {
		p202_account_flash('bad', 'That is not an action this page takes, so nothing was saved.');
		p202_account_redirect($back($view));
	}
	if ($id === 0 && !in_array($action, ['create_model', 'create_export'], true)) {
		p202_account_flash('bad', 'That request named no model or export, so nothing was saved. Reload the page and try again.');
		p202_account_redirect($back(in_array($action, $modelActions, true) ? 'models' : 'exports'));
	}

	try {
		switch ($action) {
			case 'create_model':
			case 'update_model':
				$view = 'models';
				$form = array_map(static fn ($v): string => is_string($v) ? $v : '', $_POST);
				$editId = $action === 'update_model' ? $id : null;
				$payload = $modelPayload($_POST, $errors);
				if ($errors === []) {
					$saved = $action === 'create_model' ? $api->createModel($payload) : $api->updateModel($id, $payload);
					$model = $saved['data'];
					p202_account_flash('ok', $action === 'create_model'
						? $model['model_name'] . ' is added. The next attribution worker run (within a minute) computes its credits for your existing conversions.'
						: $model['model_name'] . ' is saved.' . ($model['recompute_pending'] ? ' Its credits are recomputed by the next attribution worker run.' : ''));
					p202_account_redirect($back('models'));
				}
				break;

			case 'set_default':
				$model = $api->updateModel($id, ['is_default' => true])['data'];
				p202_account_flash('ok', $model['model_name'] . ' is now the default: reports without a chosen model, and campaigns without their own, use it.');
				p202_account_redirect($back('models'));

			case 'set_status':
				$status = (string) ($_POST['status'] ?? '');
				if (!in_array($status, ['active', 'inactive'], true)) {
					p202_account_flash('bad', 'A model is switched on or off; nothing else was asked for, so nothing was saved.');
					p202_account_redirect($back('models'));
				}
				$model = $api->updateModel($id, ['status' => $status])['data'];
				p202_account_flash('ok', $status === 'active'
					? $model['model_name'] . ' is on. The next attribution worker run computes its credits.'
					: $model['model_name'] . ' is off: its credits are removed and it is no longer computed. Switch it on to compute them again.');
				p202_account_redirect($back('models'));

			case 'delete_model':
				$name = (string) ($api->getModel($id)['data']['model_name'] ?? ('Model ' . $id));
				$api->deleteModel($id);
				p202_account_flash('ok', $name . ' is deleted, with its credits and exports. Campaigns that used it use the default now.');
				p202_account_redirect($back('models'));

			case 'create_export':
				$view = 'exports';
				$form = array_map(static fn ($v): string => is_string($v) ? $v : '', $_POST);
				$payload = ['group_by' => (string) ($_POST['group_by'] ?? '')];
				foreach (['model_id', 'compare_model_id'] as $field) {
					$raw = trim((string) ($_POST[$field] ?? ''));
					if ($raw === '') {
						continue;
					}
					if (preg_match('/^[1-9][0-9]{0,18}$/D', $raw) !== 1) {
						$errors[$field] = 'Choose a model from the list.';
						continue;
					}
					$payload[$field] = (int) $raw;
				}
				$window = p202_attr_window((string) ($_POST['range'] ?? ''), (string) ($_POST['from'] ?? ''), (string) ($_POST['to'] ?? ''), time());
				if ($window['error'] !== null) {
					$errors['range'] = $window['error'];
				}
				$payload['time_from'] = $window['from'];
				$payload['time_to'] = $window['to'];
				if (($_POST['when'] ?? 'now') === 'later') {
					$runAt = trim((string) ($_POST['run_at'] ?? ''));
					$parsed = preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/D', $runAt, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && (int) $m[4] < 24 && (int) $m[5] < 60
						? (int) mktime((int) $m[4], (int) $m[5], 0, (int) $m[2], (int) $m[3], (int) $m[1])
						: null;
					if ($parsed === null) {
						$errors['run_at'] = 'Choose the day and time to run it.';
					} elseif ($parsed <= time()) {
						$errors['run_at'] = 'That time has passed. Choose a later one, or run it now.';
					} else {
						$payload['run_at'] = $parsed;
					}
				}
				$webhook = trim((string) ($_POST['webhook_url'] ?? ''));
				if ($webhook !== '') {
					$payload['webhook_url'] = $webhook;
					$secret = trim((string) ($_POST['webhook_secret'] ?? ''));
					if ($secret !== '') {
						$payload['webhook_secret'] = $secret;
					}
				} elseif (trim((string) ($_POST['webhook_secret'] ?? '')) !== '') {
					$errors['webhook_secret'] = 'A secret signs webhook deliveries; add the webhook URL, or leave the secret empty.';
				}
				if ($errors === []) {
					$created = $api->createExport($payload)['data'];
					if (isset($created['webhook_secret'])) {
						$_SESSION['p202_attribution_new_secret'] = ['export_id' => (int) $created['export_id'], 'secret' => (string) $created['webhook_secret']];
					}
					p202_account_flash('ok', 'Export ' . $created['export_id'] . ($created['run_at'] > time() + 60
						? ' is scheduled for ' . date('Y-m-d H:i', (int) $created['run_at']) . '.'
						: ' is queued; the next export run (within a minute) writes it.'));
					p202_account_redirect($back('exports'));
				}
				break;

			case 'retry_export':
				$api->retryExport($id);
				p202_account_flash('ok', 'Export ' . $id . ' is queued again; the next export run (within a minute) tries it.');
				p202_account_redirect($back('exports'));

			case 'delete_export':
				$api->deleteExport($id);
				p202_account_flash('ok', 'Export ' . $id . ' is deleted, with its file.');
				p202_account_redirect($back('exports'));
		}
	} catch (ValidationException $e) {
		$fields = in_array($action, ['create_model', 'update_model'], true) ? $modelFields : $exportFields;
		[$mapped, $general] = $formErrors($e->getFieldErrors(), $fields);
		if (in_array($action, ['create_model', 'update_model', 'create_export'], true)) {
			$errors = $mapped + $errors;
			$formError = $general ?? ($mapped === [] ? $e->getMessage() . '.' : null);
		} else {
			// The API's message and every sentence it gave, whatever field
			// it named: this form has no field to put them under.
			p202_account_flash('bad', trim($e->getMessage() . '. ' . implode(' ', array_map(
				static fn (string $sentence): string => p202_attr_sentence($sentence),
				array_values($e->getFieldErrors())
			))));
			p202_account_redirect($back(in_array($action, $modelActions, true) ? 'models' : 'exports'));
		}
	} catch (ConflictException $e) {
		if (in_array($action, ['create_model', 'update_model'], true) && str_contains($e->getMessage(), 'already exists')) {
			$errors['model_name'] = $e->getMessage();
		} elseif (in_array($action, ['create_model', 'update_model', 'create_export'], true)) {
			$formError = $e->getMessage();
		} else {
			p202_account_flash('bad', $e->getMessage());
			p202_account_redirect($back(in_array($action, $modelActions, true) ? 'models' : 'exports'));
		}
	} catch (NotFoundException $e) {
		p202_account_flash('warn', 'That ' . (in_array($action, $modelActions, true) ? 'model' : 'export') . ' was not found; it may have been deleted already.');
		p202_account_redirect($back(in_array($action, $modelActions, true) ? 'models' : 'exports'));
	} catch (WriteCommittedException $e) {
		// The row is written; only reading it back failed. Saying "not
		// saved" here would invite a second submit and a duplicate
		// (CLAUDE.md error pattern #13).
		error_log('attribution page ' . $action . ': ' . $e->getMessage() . ' cause: ' . ($e->getPrevious()?->getMessage() ?? 'unknown'));
		p202_account_flash('warn', 'It was saved, but the page could not read it back. Reload to see it before saving again.');
		p202_account_redirect($back(in_array($action, $modelActions, true) ? 'models' : 'exports'));
	} catch (\Throwable $e) {
		error_log('attribution page ' . $action . ': ' . get_class($e) . ': ' . $e->getMessage());
		p202_account_flash('bad', 'The change could not be saved: the attribution tables could not be written. Try again; if it keeps failing, the server error log says why.');
		p202_account_redirect($back(in_array($action, $modelActions, true) ? 'models' : 'exports'));
	}
}

/** Send a file and stop: the page's own CSV, or an export's. */
$sendFile = static function (string $body, string $filename): never {
	// template.php buffers output from include time; anything in the buffer
	// (a notice on a non-production install) would precede the file.
	while (ob_get_level() > 0) {
		ob_end_clean();
	}
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="' . (preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?? 'attribution.csv') . '"');
	header('Content-Length: ' . strlen($body));
	header('Cache-Control: no-store');
	echo $body;
	exit;
};

// ─── Reads ───────────────────────────────────────────────────────────
$loadError = null;
$models = [];
$queue = null;
try {
	$models = $api->listModels([])['data'];
	$queue = $api->queue(['limit' => '5'])['data'];
} catch (\Throwable $e) {
	error_log('attribution page: ' . $e->getMessage());
	$loadError = 'The attribution tables could not be read. Check that the upgrade finished, then reload.';
}
$activeModels = array_values(array_filter($models, static fn (array $m): bool => $m['status'] === 'active'));
$defaultModel = null;
foreach ($models as $m) {
	if ($m['is_default']) {
		$defaultModel = $m;
	}
}
$modelLabel = static function (array $m): string {
	$type = ModelType::from($m['model_type'])->label();
	return strcasecmp((string) $m['model_name'], $type) === 0 ? (string) $m['model_name'] : $m['model_name'] . ' · ' . $type;
};
$modelNames = [];
foreach ($models as $m) {
	$modelNames[(int) $m['model_id']] = (string) $m['model_name'];
}

$dimensions = AttributionReports::dimensions();
$window = p202_attr_window(isset($_GET['range']) ? (string) $_GET['range'] : null, (string) ($_GET['from'] ?? ''), (string) ($_GET['to'] ?? ''), time());
$rangeSpec = [
	'range' => $window['range'],
	'from' => $window['from_date'],
	'to' => $window['to_date'],
	'ranges' => p202_attr_ranges(),
	'error' => $window['error'] ?? '',
];

$report = null;
$reportError = null;
$query = ['view' => 'report'];
if ($view === 'report' && $loadError === null) {
	$groupBy = (string) ($_GET['group_by'] ?? 'campaign');
	if (!in_array($groupBy, $dimensions, true)) {
		$reportError = "'" . $groupBy . "' is not a dimension this report has, so it is grouped by campaign.";
		$groupBy = 'campaign';
	}
	$activeIds = array_map(static fn (array $m): string => (string) $m['model_id'], $activeModels);
	$modelChoice = (string) ($_GET['model_id'] ?? '');
	$compareChoice = (string) ($_GET['compare_model_id'] ?? '');
	if ($modelChoice !== '' && !in_array($modelChoice, $activeIds, true)) {
		$reportError = 'The report cannot read that model: it is not one of your active models, so the effective model is shown.';
		$modelChoice = '';
	}
	if ($compareChoice !== '' && !in_array($compareChoice, $activeIds, true)) {
		$reportError = trim(($reportError ?? '') . ' The report cannot compare with that model: it is not one of your active models, so the comparison is left out.');
		$compareChoice = '';
	}
	if ($compareChoice !== '' && $compareChoice === $modelChoice) {
		$compareChoice = '';
	}
	$limit = (string) ($_GET['limit'] ?? '100');
	if (!in_array($limit, ['25', '50', '100', '250', '1000'], true)) {
		$limit = '100';
	}
	$query += ['range' => $window['range'], 'group_by' => $groupBy, 'model_id' => $modelChoice, 'compare_model_id' => $compareChoice, 'limit' => $limit];
	if ($window['range'] === P202_RANGE_CUSTOM) {
		$query['from'] = $window['from_date'];
		$query['to'] = $window['to_date'];
	}
	try {
		$report = $api->breakdown(array_filter([
			'group_by' => $groupBy,
			'model_id' => $modelChoice,
			'compare_model_id' => $compareChoice,
			'time_from' => (string) $window['from'],
			'time_to' => (string) $window['to'],
			'limit' => $limit,
		], static fn (string $v): bool => $v !== ''));
		$journeyShare = $api->journeyMetrics(['time_from' => (string) $window['from'], 'time_to' => (string) $window['to']])['data'];
	} catch (ConflictException | ValidationException $e) {
		$reportError = $e->getMessage() . '.';
	} catch (\Throwable $e) {
		error_log('attribution report: ' . $e->getMessage());
		$reportError = 'The report could not be read just now. Reload to try again.';
	}

	if ($report !== null && ($_GET['format'] ?? '') === 'csv') {
		$sendFile(
			ExportCsv::build($report['data'], $compareChoice !== ''),
			'attribution-' . $groupBy . '-' . $window['from_date'] . '-to-' . $window['to_date'] . '.csv'
		);
	}
}

$journeys = null;
if ($view === 'journeys' && $loadError === null) {
	$query = ['view' => 'journeys', 'range' => $window['range']] + ($window['range'] === P202_RANGE_CUSTOM ? ['from' => $window['from_date'], 'to' => $window['to_date']] : []);
	try {
		$journeys = $api->journeyMetrics(['time_from' => (string) $window['from'], 'time_to' => (string) $window['to']])['data'];
	} catch (\Throwable $e) {
		error_log('attribution journeys: ' . $e->getMessage());
		$reportError = 'The journey metrics could not be read just now. Reload to try again.';
	}
}

$journey = null;
$convId = 0;
if ($view === 'journey' && $loadError === null) {
	$convId = preg_match('/^[1-9][0-9]{0,18}$/D', (string) ($_GET['conv_id'] ?? '')) === 1 ? (int) $_GET['conv_id'] : 0;
	if ($convId === 0) {
		p202_account_flash('warn', 'Choose a conversion from the list to see its journey.');
		p202_account_redirect($back('journeys'));
	}
	try {
		$journey = $api->journey($convId)['data'];
	} catch (NotFoundException) {
		p202_account_flash('warn', 'Conversion ' . $convId . ' was not found in this account.');
		p202_account_redirect($back('journeys'));
	} catch (\Throwable $e) {
		error_log('attribution journey: ' . $e->getMessage());
		$reportError = 'The journey could not be read just now. Reload to try again.';
	}
}

$exports = [];
$newSecret = null;
if ($view === 'exports' && $loadError === null) {
	if (isset($_GET['download'])) {
		$downloadId = preg_match('/^[1-9][0-9]{0,18}$/D', (string) $_GET['download']) === 1 ? (int) $_GET['download'] : 0;
		try {
			if ($downloadId === 0) {
				throw new NotFoundException('Attribution export not found');
			}
			$file = $api->downloadExport($downloadId)['_file'];
			$sendFile($file['body'], $file['filename']);
		} catch (NotFoundException) {
			p202_account_flash('warn', 'That export was not found; it may have been deleted.');
			p202_account_redirect($back('exports'));
		} catch (ConflictException $e) {
			p202_account_flash('bad', $e->getMessage());
			p202_account_redirect($back('exports'));
		}
	}
	try {
		$exports = $api->listExports(['limit' => '100'])['data'];
	} catch (\Throwable $e) {
		error_log('attribution exports: ' . $e->getMessage());
		$reportError = 'The exports could not be read just now. Reload to try again.';
	}
	$held = $_SESSION['p202_attribution_new_secret'] ?? null;
	unset($_SESSION['p202_attribution_new_secret']);
	if (is_array($held) && isset($held['export_id'], $held['secret'])) {
		$newSecret = ['export_id' => (int) $held['export_id'], 'secret' => (string) $held['secret']];
	}
}

if ($view === 'models' && $editId === null && isset($_GET['edit'])) {
	$candidate = preg_match('/^[1-9][0-9]{0,18}$/D', (string) $_GET['edit']) === 1 ? (int) $_GET['edit'] : 0;
	foreach ($models as $m) {
		if ((int) $m['model_id'] === $candidate) {
			$editId = $candidate;
			$config = is_object($m['weighting_config']) ? (array) $m['weighting_config'] : [];
			$form = [
				'model_name' => (string) $m['model_name'],
				'model_type' => (string) $m['model_type'],
				'lookback_days' => (string) $m['lookback_days'],
				'half_life_hours' => isset($config['half_life_hours']) ? (string) $config['half_life_hours'] : '',
				'first_weight' => isset($config['first_weight']) ? (string) $config['first_weight'] : '',
				'last_weight' => isset($config['last_weight']) ? (string) $config['last_weight'] : '',
			];
		}
	}
	if ($editId === null) {
		p202_account_flash('warn', 'That model was not found; it may have been deleted.');
		p202_account_redirect($back('models'));
	}
}

$pageFlashes = [];
if ($reportError !== null) {
	$pageFlashes[] = ['kind' => 'warn', 'text' => $reportError];
}

template_top('Attribution');

$tabView = $view === 'journey' ? 'journeys' : $view;
$field = static fn (string $name, string $default = ''): string => (string) ($form[$name] ?? $default);
$invalid = static fn (string $name): string => p202_account_invalid($errors, $name);
$feedback = static fn (string $name): string => p202_account_field_error($errors, $name);
$statusPill = static function (array $m) use ($h): string {
	return match ($m['status']) {
		'active' => '<span class="p202-pill p202-pill--good">active</span>',
		'invalid' => '<span class="p202-pill p202-pill--bad" title="' . $h($m['status_reason']) . '">invalid</span>',
		default => '<span class="p202-pill">inactive</span>',
	};
};
$modelSettings = static function (array $m): string {
	$config = is_object($m['weighting_config']) ? (array) $m['weighting_config'] : [];
	return match ($m['model_type']) {
		'time_decay' => 'half-life ' . rtrim(rtrim(number_format((float) ($config['half_life_hours'] ?? ModelConfig::DEFAULT_HALF_LIFE_HOURS), 2, '.', ''), '0'), '.') . ' h',
		'position_based' => 'first ' . rtrim(rtrim(number_format((float) ($config['first_weight'] ?? ModelConfig::DEFAULT_FIRST_WEIGHT), 4, '.', ''), '0'), '.')
			. ' · last ' . rtrim(rtrim(number_format((float) ($config['last_weight'] ?? ModelConfig::DEFAULT_LAST_WEIGHT), 4, '.', ''), '0'), '.'),
		default => '—',
	};
};
?>

<div class="p202-page-header">
	<div class="p202-page-header__icon"><i class="bi bi-diagram-3"></i></div>
	<div class="p202-page-header__text">
		<h1 class="p202-page-header__title">Attribution</h1>
		<p class="p202-page-header__desc">How much credit each click in a visitor's journey gets for the conversion at its end, under each of your models.</p>
	</div>
</div>

<nav class="nav p202-tabs" aria-label="Attribution views">
	<?php foreach ($views as $key => $label) { ?>
		<a class="nav-link<?php echo $key === $tabView ? ' active' : ''; ?>"<?php echo $key === $tabView ? ' aria-current="page"' : ''; ?> href="<?php echo $h($pageUrl . '?view=' . $key); ?>"><?php echo $h($label); ?></a>
	<?php } ?>
</nav>

<?php echo p202_account_render_flashes($pageFlashes); ?>

<?php if ($loadError !== null) { ?>
	<?php echo p202_flash('bad', $loadError); ?>
<?php } else { ?>

<?php if ($queue !== null && ((int) $queue['pending'] > 0 || (int) $queue['failing'] > 0)) { ?>
	<div class="p202-strip mb-3" id="attribution-worker">
		<div class="p202-strip__row">
			<span class="p202-pill<?php echo (int) $queue['failing'] > 0 ? ' p202-pill--bad' : ' p202-pill--warn'; ?>"><?php echo (int) $queue['failing'] > 0 ? 'Failing' : 'Waiting'; ?></span>
			<span class="p202-strip__label">Attribution worker</span>
			<span class="p202-strip__value"><?php echo $h((int) $queue['pending'] . ' conversion' . ((int) $queue['pending'] === 1 ? '' : 's') . ' waiting' . ((int) $queue['failing'] > 0 ? ', ' . (int) $queue['failing'] . ' failing (p202 attribution queue shows why)' : '; the figures below fill in when the worker runs, every minute')); ?></span>
		</div>
	</div>
<?php } ?>

<?php if ($view === 'report') { ?>
	<?php
	$modelOptions = [];
	foreach ($activeModels as $m) {
		$modelOptions[(string) $m['model_id']] = $modelLabel($m) . ($m['is_default'] ? ' (default)' : '');
	}
	$dimensionOptions = [];
	foreach ($dimensions as $d) {
		$dimensionOptions[$d] = p202_attr_dimension_label($d);
	}
	$csvUrl = $pageUrl . '?' . http_build_query(array_filter($query, static fn ($v): bool => $v !== '') + ['format' => 'csv']);
	echo p202_report_filter_bar([
		'action' => $pageUrl,
		'id' => 'attribution-filters',
		'hidden' => ['view' => 'report'],
		'range' => $rangeSpec,
		'filters' => [
			['name' => 'group_by', 'label' => 'Group by', 'type' => 'select', 'any' => null, 'options' => $dimensionOptions, 'value' => $query['group_by'] ?? 'campaign'],
			['name' => 'model_id', 'label' => 'Model', 'type' => 'select', 'any' => 'Effective (each campaign\'s model)', 'options' => $modelOptions, 'value' => $query['model_id'] ?? ''],
			['name' => 'compare_model_id', 'label' => 'Compare with', 'type' => 'select', 'any' => 'No comparison', 'options' => $modelOptions, 'value' => $query['compare_model_id'] ?? '', 'advanced' => true,
				'hint' => 'A second model side by side, over the same conversions and clicks.'],
			['name' => 'limit', 'label' => 'Rows', 'type' => 'select', 'any' => null, 'default' => '100', 'options' => ['25' => '25', '50' => '50', '100' => '100', '250' => '250', '1000' => '1000'], 'value' => $query['limit'] ?? '100', 'advanced' => true,
				'hint' => 'The groups with the most attributed revenue.'],
		],
		'reset' => $pageUrl . '?view=report',
		'note' => 'Conversions in the range, credited to the clicks in their journeys; clicks and cost are each group\'s own clicks in the range.',
		'aside' => $report !== null && $report['data'] !== []
			? '<a class="btn btn-secondary btn-sm" id="attribution-csv" href="' . $h($csvUrl) . '"><i class="bi bi-file-earmark-spreadsheet"></i> Download CSV</a>'
			: '',
		'advanced_hint' => 'comparison, rows',
	]);
	?>

	<?php if ($report !== null) {
		$compare = $report['meta']['compare_model'];
		$chosen = $report['meta']['model'];
		$totals = $report['totals'];
		?>
		<?php if (isset($chosen['mode']) && $chosen['mode'] === 'effective') { ?>
			<div class="p202-decided mb-3" id="attribution-decided"><i class="bi bi-check2-circle"></i> Each campaign's model, else your default<?php echo $defaultModel !== null ? ': ' . $h($defaultModel['model_name']) : ''; ?> <a href="<?php echo $h($pageUrl . '?view=models'); ?>">change</a></div>
		<?php } ?>

		<div class="p202-tiles" id="attribution-tiles">
			<div class="p202-tile"><div class="p202-tile__label">Conversions</div><div class="p202-tile__value" data-p202-total="conversions"><?php echo number_format((int) $totals['conversions']); ?></div><div class="p202-tile__sub">with credits in range</div></div>
			<div class="p202-tile is-good"><div class="p202-tile__label">Attributed revenue</div><div class="p202-tile__value" data-p202-total="revenue"><?php echo $h(p202_attr_money((string) $totals['attributed_revenue'])); ?></div><div class="p202-tile__sub"><?php echo $h(isset($chosen['model_name']) ? $chosen['model_name'] : 'effective model'); ?></div></div>
			<?php if ($compare !== null) { ?>
				<div class="p202-tile"><div class="p202-tile__label">Compared revenue</div><div class="p202-tile__value" data-p202-total="compare-revenue"><?php echo $h(p202_attr_money((string) $totals['compare_attributed_revenue'])); ?></div><div class="p202-tile__sub"><?php echo $h($compare['model_name']); ?></div></div>
			<?php } ?>
			<?php if (isset($journeyShare)) { ?>
				<div class="p202-tile<?php echo (int) $journeyShare['conversions'] > 0 && $journeyShare['one_touch_share'] >= 0.5 ? ' is-muted' : ''; ?>"><div class="p202-tile__label">One-touch journeys</div><div class="p202-tile__value"><?php echo $h(number_format((float) $journeyShare['one_touch_share'] * 100, 1)); ?>%</div><div class="p202-tile__sub"><a href="<?php echo $h($pageUrl . '?' . http_build_query(['view' => 'journeys', 'range' => $window['range']] + ($window['range'] === P202_RANGE_CUSTOM ? ['from' => $window['from_date'], 'to' => $window['to_date']] : []))); ?>">by browser</a></div></div>
			<?php } ?>
		</div>

		<?php
		$groups = (int) $report['meta']['groups'];
		$shownAll = $groups <= count($report['data']);
		$columns = [
			['key' => 'name', 'label' => p202_attr_dimension_label((string) $report['meta']['group_by'])],
			['key' => 'clicks', 'label' => 'Clicks', 'num' => true],
			['key' => 'cost', 'label' => 'Cost', 'num' => true],
			['key' => 'attributed_conversions', 'label' => 'Attributed conv.', 'num' => true],
			['key' => 'attributed_revenue', 'label' => 'Attributed revenue', 'num' => true],
			['key' => 'roi', 'label' => 'ROI', 'num' => true],
			['key' => 'assisted_conversions', 'label' => 'Assisted', 'num' => true],
		];
		if ($compare !== null) {
			$columns[] = ['key' => 'compare_attributed_conversions', 'label' => $compare['model_name'] . ' conv.', 'num' => true];
			$columns[] = ['key' => 'compare_attributed_revenue', 'label' => $compare['model_name'] . ' revenue', 'num' => true];
			$columns[] = ['key' => 'compare_roi', 'label' => $compare['model_name'] . ' ROI', 'num' => true];
		}
		$roi = static fn (mixed $v): array => $v === null ? ['text' => '—', 'sort' => ''] : ['text' => number_format((float) $v, 2) . '%', 'sort' => (float) $v];
		$rows = [];
		foreach ($report['data'] as $row) {
			$name = $row['name'] !== null && (string) $row['name'] !== '' ? (string) $row['name'] : ((string) $row['key'] === '0' ? 'Not set' : '#' . $row['key']);
			$cells = [
				'name' => $name,
				'clicks' => ['text' => number_format((int) $row['clicks']), 'sort' => (int) $row['clicks']],
				'cost' => ['text' => p202_attr_money((string) $row['cost']), 'sort' => (float) $row['cost']],
				'attributed_conversions' => ['text' => p202_attr_credit((string) $row['attributed_conversions']), 'sort' => (float) $row['attributed_conversions']],
				'attributed_revenue' => ['text' => p202_attr_money((string) $row['attributed_revenue']), 'sort' => (float) $row['attributed_revenue']],
				'roi' => $roi($row['roi']),
				'assisted_conversions' => ['text' => number_format((int) $row['assisted_conversions']), 'sort' => (int) $row['assisted_conversions']],
			];
			if ($compare !== null) {
				$cells['compare_attributed_conversions'] = ['text' => p202_attr_credit((string) $row['compare_attributed_conversions']), 'sort' => (float) $row['compare_attributed_conversions']];
				$cells['compare_attributed_revenue'] = ['text' => p202_attr_money((string) $row['compare_attributed_revenue']), 'sort' => (float) $row['compare_attributed_revenue']];
				$cells['compare_roi'] = $roi($row['compare_roi']);
			}
			$rows[] = $cells;
		}
		// Credits and revenue are the report's own totals, over every group;
		// clicks and cost are summed here only when every group is on the
		// page, because a sum of the top rows is not the report's total.
		$totalsRow = [
			'name' => 'Totals for report',
			'clicks' => $shownAll ? number_format(array_sum(array_map(static fn (array $r): int => (int) $r['clicks'], $report['data']))) : '',
			'cost' => $shownAll ? p202_attr_money(p202_attr_sum(array_map(static fn (array $r): string => (string) $r['cost'], $report['data']), 5)) : '',
			'attributed_conversions' => p202_attr_credit((string) $totals['attributed_conversions']),
			'attributed_revenue' => p202_attr_money((string) $totals['attributed_revenue']),
			'roi' => '',
			'assisted_conversions' => '',
		];
		if ($compare !== null) {
			$totalsRow['compare_attributed_conversions'] = p202_attr_credit((string) $totals['compare_attributed_conversions']);
			$totalsRow['compare_attributed_revenue'] = p202_attr_money((string) $totals['compare_attributed_revenue']);
			$totalsRow['compare_roi'] = '';
		}
		echo p202_data_table($columns, $rows, [
			'id' => 'attribution-breakdown',
			'caption' => 'Attributed conversions and revenue by ' . strtolower(p202_attr_dimension_label((string) $report['meta']['group_by'])),
			'sortable' => $shownAll,
			'sorted' => $shownAll ? null : ['key' => 'attributed_revenue', 'dir' => 'descending'],
			'totals' => $rows !== [] ? $totalsRow : null,
			'empty' => [
				'icon' => 'bi-diagram-3',
				'title' => 'No attributed conversions in this range',
				'body' => 'Credits appear a minute after a conversion is recorded, once the attribution worker has run. Widen the range, or check the worker above.',
				'action' => $window['range'] !== 'last90' ? 'Show the last 90 days' : '',
				'href' => $pageUrl . '?' . http_build_query(['view' => 'report', 'range' => 'last90'] + array_filter(['group_by' => $query['group_by'] ?? '', 'model_id' => $query['model_id'] ?? ''])),
			],
		]);
		if (!$shownAll) { ?>
			<p class="form-text mt-2" id="attribution-truncated">The <?php echo count($report['data']); ?> groups with the most attributed revenue, of <?php echo $groups; ?>. The Exports tab writes all of them.</p>
		<?php }
	} ?>

<?php } elseif ($view === 'journeys') { ?>
	<?php
	echo p202_report_filter_bar([
		'action' => $pageUrl,
		'id' => 'journey-filters',
		'hidden' => ['view' => 'journeys'],
		'range' => $rangeSpec,
		'filters' => [],
		'reset' => $pageUrl . '?view=journeys',
		'note' => 'Every conversion attributed in the range, whatever the model: a journey is the same under all of them.',
	]);
	if ($journeys !== null) {
		$n = (int) $journeys['conversions'];
		$share = static fn (int $part) => $n > 0 ? number_format($part / $n * 100, 1) . '%' : '—';
		?>
		<div class="p202-tiles" id="journey-tiles">
			<div class="p202-tile"><div class="p202-tile__label">Conversions</div><div class="p202-tile__value"><?php echo number_format($n); ?></div><div class="p202-tile__sub">with a journey</div></div>
			<div class="p202-tile"><div class="p202-tile__label">Average touches</div><div class="p202-tile__value"><?php echo $h(number_format((float) $journeys['average_touches'], 2)); ?></div></div>
			<div class="p202-tile<?php echo $n > 0 && (float) $journeys['one_touch_share'] >= 0.5 ? ' is-muted' : ''; ?>"><div class="p202-tile__label">One-touch journeys</div><div class="p202-tile__value" data-p202-total="one-touch-share"><?php echo $h($share((int) $journeys['one_touch'])); ?></div><div class="p202-tile__sub"><?php echo number_format((int) $journeys['one_touch']); ?> conversions</div></div>
			<div class="p202-tile is-muted"><div class="p202-tile__label">No visitor id</div><div class="p202-tile__value"><?php echo number_format((int) $journeys['unidentified']); ?></div><div class="p202-tile__sub">one touch by design</div></div>
			<div class="p202-tile is-muted"><div class="p202-tile__label">Cut at 25 touches</div><div class="p202-tile__value"><?php echo number_format((int) $journeys['truncated']); ?></div></div>
		</div>

		<?php if ($n === 0) { ?>
			<div class="p202-empty">
				<i class="bi bi-signpost-split p202-empty__icon"></i>
				<strong class="p202-empty__title">No journeys in this range</strong>
				<div>A journey is built for each conversion a minute after it is recorded, from the clicks the same visitor made before it.</div>
				<div class="p202-empty__action"><a class="btn btn-primary btn-sm" href="<?php echo $h($pageUrl . '?view=journeys&range=last90'); ?>">Show the last 90 days</a></div>
			</div>
		<?php } else { ?>
			<div class="row g-4">
				<div class="col-lg-6">
					<div class="p202-panel" id="one-touch-by-browser">
						<div class="p202-panel__head"><h3 class="p202-panel__title">One-touch share by browser</h3><span class="p202-panel__sub">the converting click's browser</span></div>
						<div class="p202-panel__body">
							<p class="form-text mt-0">Browsers that clear a redirect domain's cookies (Safari, and Chrome where third-party cookies are blocked) show more one-touch journeys: their journeys are undercounted, not shorter. A customer id signed by your server links a person across browsers and devices.</p>
							<?php
							$browserRows = [];
							foreach ($journeys['one_touch_by_browser'] as $b) {
								$browserRows[] = [
									'browser' => (string) $b['browser'],
									'conversions' => ['text' => number_format((int) $b['conversions']), 'sort' => (int) $b['conversions']],
									'one_touch' => ['text' => number_format((int) $b['one_touch']), 'sort' => (int) $b['one_touch']],
									'share' => ['text' => number_format((float) $b['one_touch_share'] * 100, 1) . '%', 'sort' => (float) $b['one_touch_share']],
								];
							}
							echo p202_data_table(
								[['key' => 'browser', 'label' => 'Browser'], ['key' => 'conversions', 'label' => 'Conversions', 'num' => true], ['key' => 'one_touch', 'label' => 'One touch', 'num' => true], ['key' => 'share', 'label' => 'Share', 'num' => true]],
								$browserRows,
								['id' => 'browser-table', 'caption' => 'One-touch journeys by browser', 'sortable' => true]
							);
							?>
						</div>
					</div>
				</div>
				<div class="col-lg-6">
					<div class="p202-panel mb-4" id="journey-lengths">
						<div class="p202-panel__head"><h3 class="p202-panel__title">Journey length</h3></div>
						<div class="p202-panel__body">
							<?php
							$lengthRows = [];
							foreach ($journeys['length_distribution'] as $l) {
								$lengthRows[] = ['touches' => (int) $l['touches'] . ' touch' . ((int) $l['touches'] === 1 ? '' : 'es'), 'conversions' => number_format((int) $l['conversions']), 'share' => $share((int) $l['conversions'])];
							}
							echo p202_data_table([['key' => 'touches', 'label' => 'Journey'], ['key' => 'conversions', 'label' => 'Conversions', 'num' => true], ['key' => 'share', 'label' => 'Share', 'num' => true]], $lengthRows, ['caption' => 'Conversions by journey length']);
							?>
						</div>
					</div>
					<div class="p202-panel" id="time-to-convert">
						<div class="p202-panel__head"><h3 class="p202-panel__title">Time to convert</h3><span class="p202-panel__sub">from the first touch</span></div>
						<div class="p202-panel__body">
							<?php
							$ttcLabels = ['under_1h' => 'Under an hour', '1h_to_1d' => '1 hour to 1 day', '1d_to_7d' => '1 to 7 days', '7d_to_30d' => '7 to 30 days', 'over_30d' => 'Over 30 days'];
							$ttcRows = [];
							foreach ($ttcLabels as $bucket => $label) {
								$count = (int) ($journeys['time_to_convert'][$bucket] ?? 0);
								$ttcRows[] = ['bucket' => $label, 'conversions' => number_format($count), 'share' => $share($count)];
							}
							echo p202_data_table([['key' => 'bucket', 'label' => 'Time'], ['key' => 'conversions', 'label' => 'Conversions', 'num' => true], ['key' => 'share', 'label' => 'Share', 'num' => true]], $ttcRows, ['caption' => 'Conversions by time from first touch']);
							?>
						</div>
					</div>
				</div>
			</div>

			<div class="p202-panel mt-4" id="recent-journeys">
				<div class="p202-panel__head"><h3 class="p202-panel__title">Recent conversions</h3><span class="p202-panel__sub">open one to see its journey and every model's credit</span></div>
				<div class="p202-panel__body">
					<?php
					$recentRows = [];
					foreach ($journeys['recent_conversions'] as $r) {
						$recentRows[] = [
							'conv' => ['html' => '<a href="' . $h($pageUrl . '?view=journey&conv_id=' . (int) $r['conv_id']) . '">Conversion ' . (int) $r['conv_id'] . '</a>'],
							'time' => date('Y-m-d H:i', (int) $r['conv_time']),
							'campaign' => (string) ($r['campaign_name'] ?? '—'),
							'amount' => p202_attr_money((string) $r['amount']),
							'touches' => (int) $r['touches'],
							'identity' => ['html' => $r['identified'] ? '<span class="p202-pill p202-pill--good">visitor id</span>' : '<span class="p202-pill">no visitor id</span>'],
						];
					}
					echo p202_data_table(
						[['key' => 'conv', 'label' => 'Conversion'], ['key' => 'time', 'label' => 'When'], ['key' => 'campaign', 'label' => 'Campaign'], ['key' => 'amount', 'label' => 'Amount', 'num' => true], ['key' => 'touches', 'label' => 'Touches', 'num' => true], ['key' => 'identity', 'label' => 'Linked by']],
						$recentRows,
						['id' => 'recent-table', 'caption' => 'The newest attributed conversions', 'sorted' => ['key' => 'time', 'dir' => 'descending']]
					);
					?>
				</div>
			</div>
		<?php } ?>
	<?php } ?>

<?php } elseif ($view === 'journey' && $journey !== null) { ?>
	<p class="mb-3"><a href="<?php echo $h($pageUrl . '?view=journeys'); ?>"><i class="bi bi-arrow-left"></i> Journeys</a></p>
	<?php $meta = $journey['journey']; ?>
	<div class="p202-strip mb-4" id="journey-summary">
		<div class="p202-strip__row"><span class="p202-pill p202-pill--accent">Conversion <?php echo (int) $journey['conv_id']; ?></span><span class="p202-strip__label">Amount</span><span class="p202-strip__value" data-p202-total="amount"><?php echo $h(p202_attr_money((string) $journey['amount'])); ?></span></div>
		<?php if (!$journey['counted'] || $journey['recorded_amount'] !== $journey['amount']) { ?>
			<div class="p202-strip__row"><span class="p202-pill p202-pill--warn"><?php echo $journey['counted'] ? 'Reversed in part' : 'Does not count'; ?></span><span class="p202-strip__label">Recorded</span><span class="p202-strip__value" data-p202-total="recorded"><?php echo $h(p202_attr_money((string) $journey['recorded_amount']) . ($journey['counted'] ? '; reversals leave the amount above, which the credits split' : '; not payable, replaced, deleted, a reversal itself or reversed in full, so no model credits it')); ?></span></div>
		<?php } ?>
		<div class="p202-strip__row"><span class="p202-pill">When</span><span class="p202-strip__label">Converted</span><span class="p202-strip__value"><?php echo $h(date('Y-m-d H:i:s', (int) $journey['conv_time'])); ?></span></div>
		<?php if ($meta !== null) { ?>
			<div class="p202-strip__row"><span class="p202-pill<?php echo $meta['identified'] ? ' p202-pill--good' : ''; ?>"><?php echo $meta['identified'] ? 'Linked' : 'No visitor id'; ?></span><span class="p202-strip__label">Journey</span><span class="p202-strip__value"><?php echo $h((int) $meta['touches'] . ' touch' . ((int) $meta['touches'] === 1 ? '' : 'es') . ', built over ' . (int) $meta['built_lookback_days'] . ' days' . ($meta['truncated'] ? ', cut at 25 touches' : '')); ?></span></div>
		<?php } ?>
		<?php if ($journey['pending'] !== null) { ?>
			<div class="p202-strip__row"><span class="p202-pill p202-pill--warn">Queued</span><span class="p202-strip__label">Worker</span><span class="p202-strip__value"><?php echo $h('Waiting to be recomputed (' . $journey['pending']['reason'] . ')' . ($journey['pending']['last_error'] !== null ? '; last error: ' . $journey['pending']['last_error'] : '')); ?></span></div>
		<?php } ?>
	</div>

	<?php if ($meta === null) { ?>
		<div class="p202-empty">
			<i class="bi bi-hourglass-split p202-empty__icon"></i>
			<strong class="p202-empty__title">No journey yet</strong>
			<div>This conversion's journey is built by the next attribution worker run, within a minute of it being recorded.</div>
		</div>
	<?php } else { ?>
		<div class="p202-panel mb-4" id="journey-touches">
			<div class="p202-panel__head"><h3 class="p202-panel__title">Touches</h3><span class="p202-panel__sub">oldest first; the last is the converting click</span></div>
			<div class="p202-panel__body">
				<?php
				$touchRows = [];
				foreach ($journey['touches'] as $t) {
					$signals = '';
					foreach ($t['signals'] as $signal) {
						$signals .= '<span class="p202-pill">' . $h(p202_attr_signal_label((string) $signal)) . '</span> ';
					}
					$touchRows[] = [
						'position' => (int) $t['position'] + 1,
						'click' => 'Click ' . (int) $t['click_id'],
						'time' => date('Y-m-d H:i', (int) $t['click_time']),
						'before' => p202_attr_before((int) $journey['conv_time'] - (int) $t['click_time']),
						'campaign' => (string) ($t['campaign_name'] ?? '—'),
						'source' => (string) ($t['ppc_account_name'] ?? '—'),
						'signals' => ['html' => $signals !== '' ? trim($signals) : '<span class="text-secondary">none</span>'],
					];
				}
				echo p202_data_table(
					[['key' => 'position', 'label' => '#', 'num' => true], ['key' => 'click', 'label' => 'Click'], ['key' => 'time', 'label' => 'Clicked'], ['key' => 'before', 'label' => 'Before conversion'], ['key' => 'campaign', 'label' => 'Campaign'], ['key' => 'source', 'label' => 'Traffic source account'], ['key' => 'signals', 'label' => 'Linked by']],
					$touchRows,
					['id' => 'touches-table', 'caption' => 'The touches of this journey']
				);
				?>
			</div>
		</div>

		<div class="p202-panel" id="journey-credits">
			<div class="p202-panel__head"><h3 class="p202-panel__title">Credit by model</h3><span class="p202-panel__sub">each model's share of the conversion per touch; each column sums to the whole conversion</span></div>
			<div class="p202-panel__body">
				<?php if ($journey['credits'] === []) { ?>
					<div class="p202-empty">
						<i class="bi bi-inbox p202-empty__icon"></i>
						<strong class="p202-empty__title">No credits</strong>
						<div>The conversion no longer counts (reversed, replaced or deleted), or no model is active. Credits are only kept for conversions that count.</div>
					</div>
				<?php } else {
					$creditColumns = [['key' => 'touch', 'label' => 'Touch']];
					$creditRows = [];
					foreach ($journey['touches'] as $t) {
						$creditRows[(int) $t['position']] = ['touch' => '#' . ((int) $t['position'] + 1) . ' · ' . (string) ($t['campaign_name'] ?? 'click ' . $t['click_id'])];
					}
					$totalsRow = ['touch' => 'Total'];
					foreach ($journey['credits'] as $c) {
						$key = 'm' . (int) $c['model_id'];
						$creditColumns[] = ['key' => $key, 'label' => $c['model_name'], 'num' => true];
						$byPosition = [];
						foreach ($c['touches'] as $ct) {
							$byPosition[(int) $ct['position']] = $ct;
						}
						foreach ($creditRows as $position => $unused) {
							$ct = $byPosition[$position] ?? null;
							$creditRows[$position][$key] = $ct === null
								? ['text' => '—']
								: ['html' => $h(p202_attr_share((string) $ct['credit'])) . '<div class="form-text mt-0">' . $h(p202_attr_money((string) $ct['revenue'])) . '</div>'];
						}
						// Summed here, exactly, from the rows above: the page
						// says what the stored credits add up to, and a
						// column that does not reach 100% and the amount
						// shows it.
						$creditSum = p202_attr_sum(array_map(static fn (array $ct): string => (string) $ct['credit'], $c['touches']), 8);
						$revenueSum = p202_attr_sum(array_map(static fn (array $ct): string => (string) $ct['revenue'], $c['touches']), 5);
						$totalsRow[$key] = ['html' => '<span data-p202-credit-sum="' . (int) $c['model_id'] . '">' . $h(p202_attr_share($creditSum)) . '</span><div class="form-text mt-0" data-p202-revenue-sum="' . (int) $c['model_id'] . '">' . $h(p202_attr_money($revenueSum)) . '</div>'];
					}
					echo p202_data_table($creditColumns, array_values($creditRows), ['id' => 'credits-table', 'caption' => 'Credit per touch under each model', 'totals' => $totalsRow]);
				} ?>
			</div>
		</div>
	<?php } ?>

<?php } elseif ($view === 'models') { ?>
	<div class="row g-4">
		<div class="col-xl-7">
			<div class="p202-panel" id="model-list">
				<div class="p202-panel__head"><h3 class="p202-panel__title">Your models</h3><span class="p202-pill"><?php echo count($models); ?> model<?php echo count($models) === 1 ? '' : 's'; ?></span></div>
				<div class="p202-panel__body">
					<?php if ($models === []) { ?>
						<div class="p202-empty">
							<i class="bi bi-inbox p202-empty__icon"></i>
							<strong class="p202-empty__title">No models yet</strong>
							<div>Every account gets a last-touch default the first time the attribution worker runs for it.</div>
						</div>
					<?php } else { ?>
						<div class="p202-table-wrap">
							<table class="table table-hover p202-table">
								<caption class="visually-hidden">The account's attribution models</caption>
								<thead><tr><th scope="col">Model</th><th scope="col">Type</th><th scope="col" class="num">Lookback</th><th scope="col">Settings</th><th scope="col">Status</th><?php if ($canManage) { ?><th scope="col">Actions</th><?php } ?></tr></thead>
								<tbody>
								<?php foreach ($models as $m) {
									$mid = (int) $m['model_id']; ?>
									<tr data-model-id="<?php echo $mid; ?>">
										<td><?php echo $h($m['model_name']); ?><?php if ($m['is_default']) { ?> <span class="p202-pill p202-pill--accent">default</span><?php } ?><?php if ($m['recompute_pending']) { ?> <span class="p202-pill p202-pill--warn" title="The attribution worker recomputes this model's credits on its next run">recomputing</span><?php } ?></td>
										<td><?php echo $h(ModelType::from($m['model_type'])->label()); ?></td>
										<td class="num"><?php echo (int) $m['lookback_days']; ?> days</td>
										<td><?php echo $h($modelSettings($m)); ?></td>
										<td><?php echo $statusPill($m); ?><?php if ($m['status'] === 'invalid' && $m['status_reason'] !== null) { ?><div class="form-text mt-0"><?php echo $h($m['status_reason']); ?></div><?php } ?></td>
										<?php if ($canManage) { ?>
										<td>
											<div class="p202-toolbar">
												<a class="btn btn-secondary btn-sm" href="<?php echo $h($pageUrl . '?view=models&edit=' . $mid); ?>">Edit</a>
												<?php if (!$m['is_default'] && $m['status'] === 'active') { ?>
													<form method="post" action="<?php echo $h($pageUrl . '?view=models'); ?>" class="d-inline">
														<?php echo p202_account_token_field(); ?>
														<input type="hidden" name="action" value="set_default">
														<input type="hidden" name="id" value="<?php echo $mid; ?>">
														<button type="submit" class="btn btn-outline-primary btn-sm">Make default</button>
													</form>
												<?php } ?>
												<?php if (!$m['is_default']) { ?>
													<form method="post" action="<?php echo $h($pageUrl . '?view=models'); ?>" class="d-inline">
														<?php echo p202_account_token_field(); ?>
														<input type="hidden" name="action" value="set_status">
														<input type="hidden" name="id" value="<?php echo $mid; ?>">
														<input type="hidden" name="status" value="<?php echo $m['status'] === 'active' ? 'inactive' : 'active'; ?>">
														<button type="submit" class="btn btn-secondary btn-sm"><?php echo $m['status'] === 'active' ? 'Switch off' : 'Switch on'; ?></button>
													</form>
													<form method="post" action="<?php echo $h($pageUrl . '?view=models'); ?>" class="d-inline" data-p202-confirm="<?php echo $h('Delete ' . $m['model_name'] . '? Its credits and exports are deleted; campaigns that use it go back to the default model. Conversions and clicks are kept.'); ?>">
														<?php echo p202_account_token_field(); ?>
														<input type="hidden" name="action" value="delete_model">
														<input type="hidden" name="id" value="<?php echo $mid; ?>">
														<button type="submit" class="btn btn-outline-danger btn-sm">Delete…</button>
													</form>
												<?php } ?>
											</div>
										</td>
										<?php } ?>
									</tr>
								<?php } ?>
								</tbody>
							</table>
						</div>
						<p class="form-text mb-0">Credits are computed for every active model. The default is used where a report names no model and a campaign has none of its own; it cannot be switched off or deleted, so make another model the default first.</p>
					<?php } ?>
				</div>
			</div>
		</div>
		<div class="col-xl-5">
			<?php if (!$canManage) { ?>
				<div class="alert alert-info p202-flash" role="status"><i class="bi bi-info-circle"></i><div class="p202-flash__body">Your role can read these models but not change them. An admin can grant "manage attribution models".</div></div>
			<?php } else {
				$editing = $editId !== null;
				$editingDefault = false;
				foreach ($models as $m) {
					if ($editing && (int) $m['model_id'] === $editId && $m['is_default']) {
						$editingDefault = true;
					}
				}
				$type = $field('model_type', ModelType::LAST_TOUCH->value);
				$advancedOpen = $field('half_life_hours') !== '' || $field('first_weight') !== '' || $field('last_weight') !== ''
					|| isset($errors['half_life_hours']) || isset($errors['first_weight']) || isset($errors['last_weight']);
				?>
				<form class="p202-panel" id="model-form" method="post" action="<?php echo $h($pageUrl . '?view=models'); ?>">
					<?php echo p202_account_token_field(); ?>
					<input type="hidden" name="action" value="<?php echo $editing ? 'update_model' : 'create_model'; ?>">
					<?php if ($editing) { ?><input type="hidden" name="id" value="<?php echo (int) $editId; ?>"><?php } ?>
					<div class="p202-panel__head"><h3 class="p202-panel__title"><?php echo $editing ? 'Edit ' . $h($modelNames[(int) $editId] ?? 'model') : 'Add a model'; ?></h3><span class="p202-panel__sub"><?php echo $editing ? 'a change recomputes its credits' : 'computed for existing conversions within a minute'; ?></span></div>
					<div class="p202-panel__body">
						<?php if ($formError !== null) { ?><div class="alert alert-danger p202-flash" role="alert"><i class="bi bi-x-circle"></i><div class="p202-flash__body"><?php echo $h($formError); ?></div></div><?php } ?>
						<div class="mb-3">
							<label class="form-label" for="model_name">Name <span class="text-danger">*</span></label>
							<input type="text" class="form-control<?php echo $invalid('model_name'); ?>" id="model_name" name="model_name" maxlength="255" required value="<?php echo $h($field('model_name')); ?>">
							<div class="form-text">Shown in the report's model list.</div>
							<?php echo $feedback('model_name'); ?>
						</div>
						<div class="mb-3">
							<label class="form-label" for="model_type">How credit is shared</label>
							<select class="form-select<?php echo $invalid('model_type'); ?>" id="model_type" name="model_type">
								<?php foreach ([
									'last_touch' => 'the last click gets it all',
									'first_touch' => 'the first click gets it all',
									'linear' => 'every click the same',
									'time_decay' => 'more to recent clicks',
									'position_based' => 'most to the first and last',
								] as $value => $hint) { ?>
									<option value="<?php echo $h($value); ?>"<?php echo $type === $value ? ' selected' : ''; ?>><?php echo $h(ModelType::from($value)->label() . ' — ' . $hint); ?></option>
								<?php } ?>
							</select>
							<?php echo $feedback('model_type'); ?>
						</div>
						<div class="mb-3">
							<label class="form-label" for="lookback_days">Lookback</label>
							<div class="input-group">
								<input type="number" class="form-control<?php echo $invalid('lookback_days'); ?>" id="lookback_days" name="lookback_days" min="1" max="365" step="1" value="<?php echo $h($field('lookback_days', (string) ModelConfig::DEFAULT_LOOKBACK_DAYS)); ?>">
								<span class="input-group-text">days</span>
							</div>
							<div class="form-text">30 by default: clicks this long before a conversion can earn credit.</div>
							<?php echo $feedback('lookback_days'); ?>
						</div>
						<details class="p202-disclosure mb-3" data-p202-remember="attribution-model-advanced"<?php echo $advancedOpen ? ' open' : ''; ?>>
							<summary>Advanced <span class="p202-disclosure__hint">weights<?php echo $editing ? '' : ', default'; ?></span></summary>
							<div class="p202-disclosure__body">
								<div data-p202-show-when="model_type=time_decay" data-p202-disable-hidden<?php echo $type === 'time_decay' ? '' : ' hidden'; ?>>
									<div class="mb-3">
										<label class="form-label" for="half_life_hours">Half-life</label>
										<div class="input-group">
											<input type="text" inputmode="decimal" class="form-control<?php echo $invalid('half_life_hours'); ?>" id="half_life_hours" name="half_life_hours" placeholder="48" value="<?php echo $h($field('half_life_hours')); ?>">
											<span class="input-group-text">hours</span>
										</div>
										<div class="form-text">48 by default: a click this much older than another gets half its credit.</div>
										<?php echo $feedback('half_life_hours'); ?>
									</div>
								</div>
								<div data-p202-show-when="model_type=position_based" data-p202-disable-hidden<?php echo $type === 'position_based' ? '' : ' hidden'; ?>>
									<div class="row g-2 mb-3">
										<div class="col-6">
											<label class="form-label" for="first_weight">First click</label>
											<input type="text" inputmode="decimal" class="form-control<?php echo $invalid('first_weight'); ?>" id="first_weight" name="first_weight" placeholder="0.4" value="<?php echo $h($field('first_weight')); ?>">
											<?php echo $feedback('first_weight'); ?>
										</div>
										<div class="col-6">
											<label class="form-label" for="last_weight">Last click</label>
											<input type="text" inputmode="decimal" class="form-control<?php echo $invalid('last_weight'); ?>" id="last_weight" name="last_weight" placeholder="0.4" value="<?php echo $h($field('last_weight')); ?>">
											<?php echo $feedback('last_weight'); ?>
										</div>
									</div>
									<div class="form-text mb-3">0.4 and 0.4 by default: the clicks between share the rest.</div>
								</div>
								<div data-p202-show-when="model_type=last_touch,first_touch,linear"<?php echo in_array($type, ['last_touch', 'first_touch', 'linear'], true) ? '' : ' hidden'; ?>>
									<p class="form-text mt-0">This type has no weights to set.</p>
								</div>
								<?php if (!$editingDefault) { ?>
									<div class="form-check">
										<input class="form-check-input" type="checkbox" id="is_default" name="is_default" value="1"<?php echo $field('is_default') === '1' ? ' checked' : ''; ?>>
										<label class="form-check-label" for="is_default">Make this the default model</label>
									</div>
								<?php } ?>
							</div>
						</details>
						<div class="p202-form-actions">
							<?php if ($editing) { ?><a class="btn btn-secondary" href="<?php echo $h($pageUrl . '?view=models'); ?>">Cancel</a><?php } ?>
							<button type="submit" class="btn btn-primary"><?php echo $editing ? 'Save model' : 'Add model'; ?></button>
						</div>
					</div>
				</form>
			<?php } ?>
		</div>
	</div>

<?php } elseif ($view === 'exports') { ?>
	<?php if ($newSecret !== null) { ?>
		<div class="p202-panel mb-4" id="export-secret">
			<div class="p202-panel__head"><h3 class="p202-panel__title">Webhook secret for export <?php echo (int) $newSecret['export_id']; ?></h3><span class="p202-panel__sub">shown this once</span></div>
			<div class="p202-panel__body">
				<p class="form-text mt-0">Each delivery carries <code>X-P202-Signature: sha256=…</code>, the HMAC-SHA256 of the <code>X-P202-Timestamp</code> header, a dot and the body, under this secret. Keep it with the receiver.</p>
				<div class="p202-code">
					<pre class="p202-code__value" id="export-secret-value"><?php echo $h($newSecret['secret']); ?></pre>
					<button type="button" class="btn btn-secondary btn-sm p202-copy" data-p202-copy="<?php echo $h($newSecret['secret']); ?>"><i class="bi bi-clipboard"></i> Copy</button>
				</div>
			</div>
		</div>
	<?php } ?>

	<div class="row g-4">
		<div class="col-xl-5">
			<?php
			$exportRangeSpec = [
				'range' => $field('range', P202_ATTR_DEFAULT_RANGE) !== '' && (isset(p202_attr_ranges()[$field('range', P202_ATTR_DEFAULT_RANGE)]) || $field('range') === P202_RANGE_CUSTOM) ? $field('range', P202_ATTR_DEFAULT_RANGE) : P202_ATTR_DEFAULT_RANGE,
				'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/D', $field('from')) === 1 ? $field('from') : $window['from_date'],
				'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/D', $field('to')) === 1 ? $field('to') : $window['to_date'],
				'ranges' => p202_attr_ranges(),
				'id' => 'export-range',
				'error' => $errors['range'] ?? '',
			];
			$later = $field('when') === 'later';
			$advancedExport = $field('compare_model_id') !== '' || $field('webhook_url') !== '' || $field('webhook_secret') !== ''
				|| isset($errors['compare_model_id']) || isset($errors['webhook_url']) || isset($errors['webhook_secret']);
			$selectedModel = $field('model_id', $defaultModel !== null ? (string) $defaultModel['model_id'] : '');
			?>
			<form class="p202-panel" id="export-form" method="post" action="<?php echo $h($pageUrl . '?view=exports'); ?>">
				<?php echo p202_account_token_field(); ?>
				<input type="hidden" name="action" value="create_export">
				<div class="p202-panel__head"><h3 class="p202-panel__title">New export</h3><span class="p202-panel__sub">every group, as CSV</span></div>
				<div class="p202-panel__body">
					<?php if ($formError !== null) { ?><div class="alert alert-danger p202-flash" role="alert"><i class="bi bi-x-circle"></i><div class="p202-flash__body"><?php echo $h($formError); ?></div></div><?php } ?>
					<div class="mb-3">
						<label class="form-label" for="export_group_by">Group by</label>
						<select class="form-select<?php echo $invalid('group_by'); ?>" id="export_group_by" name="group_by">
							<?php foreach ($dimensions as $d) { ?>
								<option value="<?php echo $h($d); ?>"<?php echo $field('group_by', 'campaign') === $d ? ' selected' : ''; ?>><?php echo $h(p202_attr_dimension_label($d)); ?></option>
							<?php } ?>
						</select>
						<?php echo $feedback('group_by'); ?>
					</div>
					<div class="mb-3">
						<label class="form-label" for="export_model_id">Model</label>
						<select class="form-select<?php echo $invalid('model_id'); ?>" id="export_model_id" name="model_id">
							<?php foreach ($activeModels as $m) { ?>
								<option value="<?php echo (int) $m['model_id']; ?>"<?php echo $selectedModel === (string) $m['model_id'] ? ' selected' : ''; ?>><?php echo $h($modelLabel($m) . ($m['is_default'] ? ' (default)' : '')); ?></option>
							<?php } ?>
						</select>
						<div class="form-text">Your default model unless you choose another.</div>
						<?php echo $feedback('model_id'); ?>
					</div>
					<div class="mb-3">
						<span class="form-label d-block">Conversions from</span>
						<?php echo p202_date_range($exportRangeSpec); ?>
					</div>
					<fieldset class="mb-3">
						<legend class="form-label">When</legend>
						<div class="form-check form-check-inline">
							<input class="form-check-input" type="radio" name="when" id="export_when_now" value="now"<?php echo $later ? '' : ' checked'; ?>>
							<label class="form-check-label" for="export_when_now">Now</label>
						</div>
						<div class="form-check form-check-inline">
							<input class="form-check-input" type="radio" name="when" id="export_when_later" value="later"<?php echo $later ? ' checked' : ''; ?>>
							<label class="form-check-label" for="export_when_later">Later</label>
						</div>
						<div class="form-text">Now by default: the next export run writes it, within a minute.</div>
						<div class="mt-2" data-p202-show-when="when=later" data-p202-disable-hidden<?php echo $later ? '' : ' hidden'; ?>>
							<label class="form-label" for="export_run_at">Run at</label>
							<input type="datetime-local" class="form-control<?php echo $invalid('run_at'); ?>" id="export_run_at" name="run_at" value="<?php echo $h($field('run_at')); ?>">
							<?php echo $feedback('run_at'); ?>
						</div>
					</fieldset>
					<details class="p202-disclosure mb-3" data-p202-remember="attribution-export-advanced"<?php echo $advancedExport ? ' open' : ''; ?>>
						<summary>Advanced <span class="p202-disclosure__hint">comparison, webhook</span></summary>
						<div class="p202-disclosure__body">
							<div class="mb-3">
								<label class="form-label" for="export_compare_model_id">Compare with</label>
								<select class="form-select<?php echo $invalid('compare_model_id'); ?>" id="export_compare_model_id" name="compare_model_id">
									<option value="">No comparison</option>
									<?php foreach ($activeModels as $m) { ?>
										<option value="<?php echo (int) $m['model_id']; ?>"<?php echo $field('compare_model_id') === (string) $m['model_id'] ? ' selected' : ''; ?>><?php echo $h($modelLabel($m)); ?></option>
									<?php } ?>
								</select>
								<?php echo $feedback('compare_model_id'); ?>
							</div>
							<div class="mb-3">
								<label class="form-label" for="export_webhook_url">Webhook URL</label>
								<input type="url" class="form-control<?php echo $invalid('webhook_url'); ?>" id="export_webhook_url" name="webhook_url" maxlength="500" placeholder="https://" value="<?php echo $h($field('webhook_url')); ?>">
								<div class="form-text">Optional. The file is also sent here as a signed POST: https only, to a public address, no redirects.</div>
								<?php echo $feedback('webhook_url'); ?>
							</div>
							<div class="mb-1">
								<label class="form-label" for="export_webhook_secret">Webhook secret</label>
								<input type="text" class="form-control font-monospace<?php echo $invalid('webhook_secret'); ?>" id="export_webhook_secret" name="webhook_secret" maxlength="255" autocomplete="off" value="<?php echo $h($field('webhook_secret')); ?>">
								<div class="form-text">Leave empty and one is made for you, shown once after saving.</div>
								<?php echo $feedback('webhook_secret'); ?>
							</div>
						</div>
					</details>
					<div class="p202-form-actions">
						<button type="submit" class="btn btn-primary">Export</button>
					</div>
				</div>
			</form>
		</div>
		<div class="col-xl-7">
			<div class="p202-panel" id="export-list">
				<div class="p202-panel__head"><h3 class="p202-panel__title">Exports</h3><span class="p202-pill"><?php echo count($exports); ?></span><span class="p202-panel__sub">newest first</span></div>
				<div class="p202-panel__body">
					<?php if ($exports === []) { ?>
						<div class="p202-empty">
							<i class="bi bi-file-earmark-spreadsheet p202-empty__icon"></i>
							<strong class="p202-empty__title">No exports yet</strong>
							<div>An export writes every group of a breakdown as a CSV you can download, now or on a schedule, and can send it to your server.</div>
						</div>
					<?php } else { ?>
						<div class="p202-table-wrap">
							<table class="table table-hover p202-table" id="export-table">
								<caption class="visually-hidden">The account's attribution exports</caption>
								<thead><tr><th scope="col">Export</th><th scope="col">What</th><th scope="col">Status</th><th scope="col" class="num">Rows</th><th scope="col">Actions</th></tr></thead>
								<tbody>
								<?php foreach ($exports as $x) {
									$xid = (int) $x['export_id'];
									$pill = [
										'pending' => '<span class="p202-pill">' . ($x['run_at'] > time() ? 'scheduled' : 'queued') . '</span>',
										'running' => '<span class="p202-pill p202-pill--accent">running</span>',
										'completed' => '<span class="p202-pill p202-pill--good">completed</span>',
										'failed' => '<span class="p202-pill p202-pill--bad">failed</span>',
									][$x['status']] ?? '<span class="p202-pill">' . $h($x['status']) . '</span>';
									$host = $x['webhook_url'] !== null ? (string) (parse_url((string) $x['webhook_url'], PHP_URL_HOST) ?? '') : '';
									?>
									<tr data-export-id="<?php echo $xid; ?>" data-export-status="<?php echo $h($x['status']); ?>">
										<td><?php echo $xid; ?><div class="form-text mt-0"><?php echo $h(date('Y-m-d H:i', (int) ($x['completed_at'] ?? $x['run_at']))); ?></div></td>
										<td><?php echo $h(p202_attr_dimension_label($x['group_by']) . ' · ' . ($modelNames[$x['model_id']] ?? 'model ' . $x['model_id'])
											. ($x['compare_model_id'] !== null ? ' vs ' . ($modelNames[$x['compare_model_id']] ?? 'model ' . $x['compare_model_id']) : '')); ?>
											<div class="form-text mt-0"><?php echo $h(date('Y-m-d', (int) $x['time_from']) . ' to ' . date('Y-m-d', (int) $x['time_to'])); ?><?php if ($host !== '') { ?> · webhook <?php echo $h($host); ?><?php echo $x['webhook_status_code'] !== null ? ' (' . (int) $x['webhook_status_code'] . ')' : ''; ?><?php } ?></div>
										</td>
										<td><?php echo $pill; ?><?php if ($x['last_error'] !== null && $x['last_error'] !== '') { ?><div class="form-text mt-0 export-error"><?php echo $h($x['last_error']); ?></div><?php } ?></td>
										<td class="num"><?php echo $x['rows_exported'] !== null ? number_format((int) $x['rows_exported']) : '—'; ?></td>
										<td>
											<div class="p202-toolbar">
												<?php if ($x['file_ready']) { ?>
													<a class="btn btn-secondary btn-sm" href="<?php echo $h($pageUrl . '?view=exports&download=' . $xid); ?>"><i class="bi bi-download"></i> Download</a>
												<?php } ?>
												<?php if ($x['status'] === 'failed') { ?>
													<form method="post" action="<?php echo $h($pageUrl . '?view=exports'); ?>" class="d-inline">
														<?php echo p202_account_token_field(); ?>
														<input type="hidden" name="action" value="retry_export">
														<input type="hidden" name="id" value="<?php echo $xid; ?>">
														<button type="submit" class="btn btn-outline-primary btn-sm">Retry</button>
													</form>
												<?php } ?>
												<?php if ($x['status'] !== 'running') { ?>
													<form method="post" action="<?php echo $h($pageUrl . '?view=exports'); ?>" class="d-inline" data-p202-confirm="<?php echo $h('Delete export ' . $xid . ' and its file? Your models and reports are not affected.'); ?>">
														<?php echo p202_account_token_field(); ?>
														<input type="hidden" name="action" value="delete_export">
														<input type="hidden" name="id" value="<?php echo $xid; ?>">
														<button type="submit" class="btn btn-outline-danger btn-sm">Delete…</button>
													</form>
												<?php } ?>
											</div>
										</td>
									</tr>
								<?php } ?>
								</tbody>
							</table>
						</div>
					<?php } ?>
				</div>
			</div>
		</div>
	</div>
<?php } ?>

<?php } ?>

<script src="<?php echo $h($base . '202-js/p202-setup.js?v=1'); ?>" defer></script>
<?php template_bottom();
