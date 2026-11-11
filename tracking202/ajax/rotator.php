<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-17) . '/202-config/connect.php');

AUTH::require_user();
AUTH::require_permissions('access_to_setup_section');

$slack = false;

$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
$mysql['user_own_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
$user_sql = "SELECT 202_users.user_name AS username, 202_users_pref.maxmind_isp FROM 202_users_pref LEFT JOIN 202_users ON (202_users.user_id = '".$mysql['user_own_id']."') WHERE 202_users_pref.user_id='1'";
$user_result = $db->query($user_sql);
if ($user_result === false) {
    record_mysql_error($user_sql);
}
$user_row = p202FetchRow($user_result, $user_sql);

$slack = Slack::forAccount($db, (int) ($_SESSION['user_id'] ?? 0)) ?? false;

/*
 * Setup › Redirector's two server calls: the rule-value suggestions and saving
 * the rules. The markup fragments this file also served (the classic editor's
 * add_more_*, rule_defaults, generate_rules and rule_details, and the
 * get_rotators select for Get Links) went with the classic pages: the v2 pages
 * render the editor and the lists themselves (U4).
 */

if (isset($_GET['autocomplete']) && isset($_GET['type']) && isset($_GET['query']) && $_GET['autocomplete'] == 'true') {

	header("Content-type: application/json; charset=utf-8");

	// A country from the list the save normalizes with; a link's values and the
	// referer from the account's own clicks; the other lists are hosted.
	$suggestType = is_string($_GET['type']) ? $_GET['type'] : '';
	$suggestQuery = is_string($_GET['query']) ? $_GET['query'] : '';
	$suggestions = null;
	if ($suggestType === 'country') {
		$suggestions = \Prosper202\Rotator\CountryNames::matching($suggestQuery);
	} elseif (\Prosper202\Rotator\SeenValues::suggests($suggestType)) {
		try {
			$suggestions = \Prosper202\Rotator\SeenValues::matching(
				new \Prosper202\Database\Connection($db), (int) $mysql['user_id'], $suggestType, $suggestQuery, time()
			);
		} catch (\Throwable $suggestError) {
			error_log('rotator.php: the seen ' . $suggestType . ' values could not be read: ' . $suggestError->getMessage());
			http_response_code(500);
			die('[]');
		}
	} elseif (in_array($suggestType, ['region', 'city', 'isp', 'browser', 'platform'], true)) {
		print_r(rotator_data(urlencode($suggestQuery), $suggestType));
	} else {
		$suggestions = [];
	}
	if ($suggestions !== null) {
		echo json_encode($suggestions, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
	}
}

if (isset($_POST['post_rules']) && $_POST['post_rules'] == true && isset($_POST['data'])) {

	// Require a valid session token for this state-changing request.
	if (!AUTH::check_csrf_token()) {
		die("ERROR");
	}

	if ($_POST['default_type'] == null || $_POST['defaults'] == null || !is_array($_POST['data'])) {
		die("ERROR");
	}

	foreach ($_POST['data'] as $rule) {
        // A rule with no criterion matches every visitor, so a rule needs
        // one, as the API asks (RotatorsController::planRule()); and one
        // destination at least. The editor posts neither list empty.
        $listed = static fn ($list): bool => is_array($list) && $list !== [];
        if (!is_array($rule) || !$listed($rule['criteria'] ?? null) || !$listed($rule['redirects'] ?? null)) {
            die("ERROR");
        }
		$rule_empty = count($rule) != count(array_filter($rule));
		if ($rule_empty) {
			die("ERROR");
		}
			foreach ($rule['criteria'] as $criteria) {
				if (!is_array($criteria) || count($criteria) != count(array_filter($criteria))) {
					die("ERROR");
				}
			}

			foreach ($rule['redirects'] as $redirect) {
				if (!is_array($redirect) || count($redirect) != count(array_filter($redirect))) {
					die("ERROR");
				}
			}
	}

	$refuse = static function (?string $reason = null): never {
		header('Content-Type: text/plain; charset=utf-8'); // a reason repeats what was typed
		die($reason === null ? "ERROR" : 'ERROR: ' . $reason);
	};
	$rawRotatorId = $_POST['rotator_id'] ?? '';
	if (!is_string($rawRotatorId) || preg_match('/^[1-9]\d{0,9}$/D', $rawRotatorId) !== 1) {
		$refuse();
	}
	$rotator_id = (int) $rawRotatorId;

	// A destination is a URL or one of the account's live campaigns or landing pages, a held one too.
	$destinationOwned = static function (string $type, $value) use ($db, $mysql, $refuse): void {
		if ($type === 'monetizer') {
			$refuse('The auto-monetizer is no longer offered as a destination: choose a campaign, a landing page or a URL.');
		}
		if ($type === 'url') {
			return;
		}
		if ($type !== 'campaign' && $type !== 'lp') {
			$refuse();
		}
		if (!is_string($value) || preg_match('/^[1-9]\d{0,9}$/D', $value) !== 1) {
			$refuse();
		}
		$sql = $type === 'campaign'
			? "SELECT 1 FROM 202_aff_campaigns WHERE aff_campaign_id = ? AND user_id = ? AND COALESCE(aff_campaign_deleted, 0) = 0 LIMIT 1"
			: "SELECT 1 FROM 202_landing_pages WHERE landing_page_id = ? AND user_id = ? AND COALESCE(landing_page_deleted, 0) = 0 LIMIT 1";
		if (!p202_rotator_row_exists($db, $sql, [(int) $value, (int) $mysql['user_id']])) {
			$refuse();
		}
	};
	$destinationOwned((string) $_POST['default_type'], $_POST['defaults']);
	foreach ($_POST['data'] as $rule) {
		foreach ($rule['redirects'] as $redirect) {
			$destinationOwned((string) ($redirect['type'] ?? ''), $redirect['value'] ?? null);
		}
	}

	// The API's set path writes the default and the rules under the redirector's lock, or nothing;
	// it refuses an id that is not this redirector's own.
	$names = p202_rotator_destination_names($db, (int) $mysql['user_id']);
	$payload = p202_rotator_page_payload((string) $_POST['default_type'], (string) $_POST['defaults'], $_POST['data'], $names);
	// Leaving out a saved rule removes it, which the page offers only with remove_rotator_rule.
	$mayRemoveRules = static function () use ($userObj): void {
		if (!$userObj->hasPermission('remove_rotator_rule')) {
			throw new \Api\V3\AuthException("This account's role does not have the 'remove_rotator_rule' permission.", 403);
		}
	};
	try {
		$saved = (new \Api\V3\Controllers\RotatorsController($db, (int) $mysql['user_id']))
			->setDefaultAndRules($rotator_id, $payload, $mayRemoveRules);
	} catch (\Api\V3\Exception\ValidationException $e) {
		$refuse(p202_rotator_refusal($e));
	} catch (\Api\V3\Exception\NotFoundException) {
		$refuse();
	} catch (\Api\V3\AuthException $e) {
		http_response_code($e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 403);
		die($e->getMessage());
	} catch (\Api\V3\Exception\DatabaseException | \Prosper202\Database\Exceptions\QueryException | \mysqli_sql_exception $e) {
		$cause = $e->getPrevious() !== null ? $e->getPrevious()->getMessage() : $e->getMessage();
		record_mysql_error($db, 'saving the rules of redirector ' . $rotator_id, $cause);
	}

	if ($slack && (string) $saved['data']['rotator']['name'] !== '') {
		foreach (\Prosper202\Rotator\SaveNotices::of($saved['data']['before'], $saved['data']['rotator'], $names) as [$notice, $fields]) {
			$slack->push($notice, $fields + ['user' => $user_row['username']]);
		}
	}

	// What the save stored in another form than typed, for the page to say once it has reloaded.
	$rewrites = p202_rotator_rewrites($saved['meta']['normalized'] ?? []);
	$rewritesJson = $rewrites === [] ? false : json_encode($rewrites, JSON_INVALID_UTF8_SUBSTITUTE);
	header('Content-Type: text/plain; charset=utf-8'); // the list repeats what was typed
	echo $rewritesJson === false ? 'DONE' : "DONE\n" . $rewritesJson;

}

/**
 * The values a save rewrote, in the page's words.
 *
 * @param list<array{field: string, sent: string, stored: string}> $normalized setDefaultAndRules()'s meta.normalized
 * @return list<string>
 */
function p202_rotator_rewrites(array $normalized): array
{
	$sentences = [];
	foreach ($normalized as $note) {
		$path = explode('.', $note['field']);
		$sentences[] = 'Rule ' . ((int) ($path[1] ?? 0) + 1) . ': "' . $note['sent'] . '" was saved as "' . $note['stored']
			. '", the form the redirects match.';
	}

	return array_values(array_unique($sentences));
}

/**
 * The names of the account's own campaigns and landing pages, read when first asked for.
 *
 * @return callable(string, mixed): ?string 'campaign' or 'lp', and an id
 */
function p202_rotator_destination_names(mysqli $db, int $userId): callable
{
	$found = [];
	return static function (string $type, mixed $id) use ($db, $userId, &$found): ?string {
		if (($type !== 'campaign' && $type !== 'lp') || !is_scalar($id) || (int) $id <= 0) {
			return null;
		}
		$key = $type . ':' . (int) $id;
		if (!array_key_exists($key, $found)) {
			$sql = $type === 'campaign'
				? 'SELECT aff_campaign_name FROM 202_aff_campaigns WHERE aff_campaign_id = ? AND user_id = ? LIMIT 1'
				: "SELECT landing_page_nickname FROM 202_landing_pages WHERE landing_page_id = ? AND user_id = ? AND landing_page_deleted = '0' LIMIT 1";
			$conn = new \Prosper202\Database\Connection($db);
			$stmt = $conn->prepareWrite($sql);
			$conn->bind($stmt, 'ii', [(int) $id, $userId]);
			$row = $conn->fetchOne($stmt);
			$found[$key] = $row === null ? null : (string) array_values($row)[0];
		}

		return $found[$key];
	};
}

/**
 * The page's post as RotatorsController::setDefaultAndRules() takes it; 'none' is a new entry.
 *
 * @param array<array-key, mixed> $rules the posted `data`, checked for shape
 * @param callable(string, mixed): ?string $names
 * @return array<string, mixed>
 */
function p202_rotator_page_payload(string $defaultType, string $default, array $rules, callable $names): array
{
	$text = static fn (mixed $v): string => is_scalar($v) ? (string) $v : '';
	$payload = ['default_' . $defaultType => $default, 'rules' => []];
	foreach ($rules as $rule) {
		$split = $text($rule['split'] ?? '') === 'true';
		$out = [
			'rule_name' => $text($rule['rule_name'] ?? ''),
			'splittest' => $split ? 1 : 0,
			'status' => $text($rule['status'] ?? '') === 'active' ? 1 : 0,
			'criteria' => [],
			'redirects' => [],
		];
		if ($text($rule['rule_id'] ?? '') !== 'none') {
			$out['id'] = $text($rule['rule_id'] ?? '');
		}
		foreach ($rule['criteria'] as $criteria) {
			$entry = [
				'type' => $text($criteria['type'] ?? ''),
				'statement' => $text($criteria['statement'] ?? ''),
				'value' => $text($criteria['value'] ?? ''),
			];
			if ($text($criteria['criteria_id'] ?? '') !== 'none') {
				$entry['id'] = $text($criteria['criteria_id'] ?? '');
			}
			$out['criteria'][] = $entry;
		}
		foreach ($rule['redirects'] as $redirect) {
			$type = $text($redirect['type'] ?? '');
			$value = $text($redirect['value'] ?? '');
			$name = match ($type) {
				'campaign' => 'Campaign: ' . ($names('campaign', $value) ?? 'Unknown Campaign'),
				'lp' => 'Landing page: ' . ($names('lp', $value) ?? 'Unknown Landing Page'),
				default => 'URL: <a href=' . $value . '>link</a>',
			};
			$entry = ['redirect_' . $type => $value, 'name' => mb_substr($name, 0, 255)];
			// Without a split test the page posts one destination and no weight.
			if ($split && array_key_exists('weight', $redirect)) {
				$entry['weight'] = $text($redirect['weight']);
			}
			if ($text($redirect['id'] ?? '') !== 'none') {
				$entry['id'] = $text($redirect['id'] ?? '');
			}
			$out['redirects'][] = $entry;
		}
		$payload['rules'][] = $out;
	}

	return $payload;
}

/** A refusal in the page's words: each problem with the rule, criterion or destination it is about. */
function p202_rotator_refusal(\Api\V3\Exception\ValidationException $e): string
{
	$errors = $e->getFieldErrors();
	if ($errors === []) {
		return $e->getMessage();
	}
	$parts = [];
	foreach ($errors as $field => $message) {
		$path = explode('.', (string) $field);
		if ($path[0] === 'rules' && isset($path[1]) && ctype_digit($path[1])) {
			$where = 'Rule ' . ((int) $path[1] + 1);
			$list = ['criteria' => 'criterion', 'redirects' => 'destination'][$path[2] ?? ''] ?? null;
			if ($list !== null && isset($path[3]) && ctype_digit($path[3])) {
				$where .= ', ' . $list . ' ' . ((int) $path[3] + 1);
			}
			if (in_array(end($path), ['rule_name', 'weight'], true)) {
				$where .= ', ' . (end($path) === 'rule_name' ? 'name' : 'weight');
			}
		} elseif (str_starts_with((string) $field, 'default_')) {
			$where = 'Default destination';
		} else {
			$where = (string) $field;
		}
		$parts[] = $where . ': ' . rtrim($message, '.') . '.';
	}

	return implode(' ', $parts);
}

/**
 * Whether `$sql` (integer placeholders only) finds a row, read from the
 * primary. A statement that cannot run is an error, not "no row" (CLAUDE.md
 * #1): Connection throws on a failed prepare, execute or get_result, so a
 * failure never reads as a refusal it did not decide.
 *
 * @param list<int> $ids
 */
function p202_rotator_row_exists(mysqli $db, string $sql, array $ids): bool
{
	$conn = new \Prosper202\Database\Connection($db);
	$stmt = $conn->prepareWrite($sql);
	$conn->bind($stmt, str_repeat('i', count($ids)), $ids);
	return $conn->fetchOne($stmt) !== null;
}
