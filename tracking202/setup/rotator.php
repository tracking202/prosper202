<?php

declare(strict_types=1);
include_once(substr(__DIR__, 0, -18) . '/202-config/connect.php');

AUTH::require_user();

if (!$userObj->hasPermission("access_to_setup_section")) {
	header('location: ' . get_absolute_url() . 'tracking202/');
	die();
}

// Initialize variables to prevent undefined variable warnings
$error = [];
$html = [];
$mysql = [];
$selected = [];
$add_success = false;
$delete_success = false;
$editing = false;
$copying = false;

$slack = false;
$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
$mysql['user_own_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
$user_sql = "SELECT 2u.user_name as username, 2up.user_slack_incoming_webhook AS url, 2up.maxmind_isp FROM 202_users AS 2u INNER JOIN 202_users_pref AS 2up ON (2up.user_id = 1) WHERE 2u.user_id = '".$mysql['user_own_id']."'";
$user_results = $db->query($user_sql);
$user_row = $user_results->fetch_assoc();

if (!empty($user_row['url']))
	$slack = new Slack($user_row['url']);

if (!empty($_POST['edit_rotator'])) {
	$editing = true;
}

if (!empty($_GET['rules_added'])) {
	$add_success = true;
}

if ($_SERVER['REQUEST_METHOD'] == "POST") {

	// Require a valid session token for this state-changing request.
	if (!hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_POST['token'] ?? ''))) {
		$error['token'] = '<div class="error">Invalid or expired form token. Please reload the page and try again.</div>';
	}

	$rotator_name = trim((string) $_POST['rotator_name']);
	if (empty($rotator_name)) {
		$error['rotator_name'] = '<div class="error">Type in the name of your rotator!</div>';
	}

	if (!$error) {
		$mysql['rotator_name'] = $db->real_escape_string($_POST['rotator_name']);
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);

		if ($editing == true) {
			// scope rename to the acting user's own rotator
			$mysql['rotator_id'] = $db->real_escape_string((string)($_POST['rotator_id'] ?? ''));
			$sql = "UPDATE 202_rotators SET name='" . $mysql['rotator_name'] . "' WHERE id='" . $mysql['rotator_id'] . "' AND user_id='" . $mysql['user_id'] . "'";
			$result = $db->query($sql);
		} else {
			$sql = "INSERT INTO 202_rotators SET name='" . $mysql['rotator_name'] . "', user_id='" . $mysql['user_id'] . "'";
			$result = $db->query($sql);
			$rotator_id = $db->insert_id;

			// public_id is derived from insert_id, so only generate it for the INSERT path
			$sql = "UPDATE 202_rotators SET public_id='" . random_int(1, 9) . $rotator_id . random_int(1, 9) . "' WHERE id='" . $rotator_id . "' AND user_id='" . $mysql['user_id'] . "'";
			$result = $db->query($sql);
		}

		$add_success = true;

		if ($slack)
			$slack->push('rotator_created', ['name' => $_POST['rotator_name'], 'user' => $user_row['username']]);
	}
}

if (isset($_GET['delete_rotator_id'])) {

	// Require a valid session token for this state-changing request.
	if (!hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_GET['token'] ?? ''))) {
		header('location: ' . get_absolute_url() . 'tracking202/setup/rotator.php');
		die();
	}

	if ($userObj->hasPermission("remove_rotator")) {
		$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
		$mysql['rotator_id'] = $db->real_escape_string((string)$_GET['delete_rotator_id']);

		$delete_sql = "DELETE FROM 202_rotators WHERE id='" . $mysql['rotator_id'] . "' AND user_id='" . $mysql['user_id'] . "'";

		if (_mysqli_query($delete_sql)) {
			$rule_sql = "DELETE FROM 202_rotator_rules WHERE rotator_id='" . $mysql['rotator_id'] . "'";

			if (_mysqli_query($rule_sql)) {
				$criteria_sql = "DELETE FROM 202_rotator_rules_criteria WHERE rotator_id='" . $mysql['rotator_id'] . "'";
				if (_mysqli_query($criteria_sql)) {
					$delete_success = true;
					if ($slack)
						$slack->push('rotator_deleted', ['name' => $_GET['delete_rotator_name'], 'user' => $user_row['username']]);
				}
			}
		}
	} else {
		header('location: ' . get_absolute_url() . 'tracking202/setup/rotator.php');
	}
}


// Post-redirect-get: a new redirector opens straight in the rules editor,
// since rules are the next thing it needs; a removal answers with a redirect.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $add_success == true) {
	$newId = $editing ? (int) ($_POST['rotator_id'] ?? 0) : (int) ($rotator_id ?? 0);
	header('location: ' . get_absolute_url() . 'tracking202/setup/rotator.php?' . ($newId > 0 ? 'rotator_id=' . $newId . '&' : '') . ($editing ? 'saved=1' : 'added=1'));
	exit;
}
if ($delete_success == true) {
	header('location: ' . get_absolute_url() . 'tracking202/setup/rotator.php?deleted=1');
	exit;
}

require_once __DIR__ . '/_includes/setup_ui.php';

$base = get_absolute_url();
$self = $base . 'tracking202/setup/rotator.php';
$token = (string) ($_SESSION['token'] ?? '');
$uid = (int) $_SESSION['user_id'];
$ispEnabled = isset($user_row['maxmind_isp']) && $user_row['maxmind_isp'] == '1';

$rotators = p202_setup_rows($db, "SELECT * FROM `202_rotators` WHERE `user_id`='" . $uid . "' ORDER BY `name` ASC");
$rulesByRotator = [];
foreach (p202_setup_rows($db, "SELECT r.* FROM 202_rotator_rules AS r INNER JOIN 202_rotators AS ro ON (ro.id = r.rotator_id) WHERE ro.user_id = '" . $uid . "' ORDER BY r.id ASC") as $rule) {
	$rulesByRotator[(int) $rule['rotator_id']][] = $rule;
}
$criteriaByRule = [];
foreach (p202_setup_rows($db, "SELECT c.* FROM 202_rotator_rules_criteria AS c INNER JOIN 202_rotators AS ro ON (ro.id = c.rotator_id) WHERE ro.user_id = '" . $uid . "' ORDER BY c.id ASC") as $criterion) {
	$criteriaByRule[(int) $criterion['rule_id']][] = $criterion;
}
$redirectsByRule = [];
foreach (p202_setup_rows($db, "SELECT rr.* FROM 202_rotator_rules_redirects AS rr INNER JOIN 202_rotator_rules AS r ON (r.id = rr.rule_id) INNER JOIN 202_rotators AS ro ON (ro.id = r.rotator_id) WHERE ro.user_id = '" . $uid . "' ORDER BY rr.id ASC") as $redirect) {
	$redirectsByRule[(int) $redirect['rule_id']][] = $redirect;
}

// Destinations: this account's campaigns and landing pages. A simple page
// is offered while its campaign is live; an advanced page belongs to no
// campaign (aff_campaign_id 0, and no campaign row 0 exists), so an inner
// join to the campaigns dropped every one of them — a rule that already
// pointed at one rendered its <select> with nothing chosen, and the next
// save repointed it (#164, #173).
$campaignOptions = p202_setup_campaign_options($db, $uid);
$campaignNames = [];
foreach ($campaignOptions as $group) {
	foreach ($group['options'] as $id => $option) {
		$campaignNames[(string) $id] = $option['label'];
	}
}
$pageOptions = [];
foreach (p202_setup_rows($db, "SELECT lp.landing_page_id, lp.landing_page_nickname, lp.landing_page_type FROM 202_landing_pages AS lp"
	. " LEFT JOIN 202_aff_campaigns AS ac ON (ac.aff_campaign_id = lp.aff_campaign_id AND ac.user_id = lp.user_id)"
	. " WHERE lp.user_id = '" . $uid . "' AND COALESCE(lp.landing_page_deleted,0) = 0"
	. " AND (lp.landing_page_type = 1 OR (ac.aff_campaign_id IS NOT NULL AND COALESCE(ac.aff_campaign_deleted,0) = 0))"
	. " ORDER BY lp.landing_page_type, lp.landing_page_nickname") as $page) {
	$pageOptions[(string) $page['landing_page_id']] = (string) $page['landing_page_nickname'] . ((string) $page['landing_page_type'] === '1' ? ' (advanced)' : ' (simple)');
}

$criterionTypes = ['country' => 'Country', 'region' => 'State/Region', 'city' => 'City', 'isp' => 'ISP/Carrier', 'ip' => 'IP address', 'browser' => 'Browser', 'platform' => 'OS', 'device' => 'Device type'];
$suggestUrl = $base . 'tracking202/ajax/rotator.php?autocomplete=true&type=%TYPE%&query=%QUERY';

/** Where a redirect or default goes, in words, for the list. */
$destination = static function (array $row, string $prefix) use ($campaignNames, $pageOptions): string {
	if (!empty($row[$prefix . 'campaign'])) {
		return 'campaign ' . ($campaignNames[(string) $row[$prefix . 'campaign']] ?? '#' . $row[$prefix . 'campaign']);
	}
	if (!empty($row[$prefix . 'lp'])) {
		return 'landing page ' . ($pageOptions[(string) $row[$prefix . 'lp']] ?? '#' . $row[$prefix . 'lp']);
	}
	if (!empty($row[$prefix . 'url'])) {
		return (string) $row[$prefix . 'url'];
	}
	return 'nowhere yet';
};

$selectedId = (int) ($_GET['rotator_id'] ?? 0);
$selected = null;
foreach ($rotators as $rotator) {
	if ((int) $rotator['id'] === $selectedId) {
		$selected = $rotator;
	}
}

// ── The editor's parts, rendered for saved rules and as <template>s ──────
$criterionRow = static function (array $criterion) use ($criterionTypes, $ispEnabled, $suggestUrl): string {
	$type = (string) ($criterion['type'] ?? 'country');
	$typeOptions = '';
	foreach ($criterionTypes as $value => $label) {
		$disabled = $value === 'isp' && !$ispEnabled ? ' disabled' : '';
		$typeOptions .= '<option value="' . $value . '"' . ($type === $value ? ' selected' : '') . $disabled . '>' . p202_setup_e($label) . ($disabled !== '' ? ' (needs MaxMind ISP)' : '') . '</option>';
	}
	return '<div class="row g-2 align-items-end mb-2" data-p202-row data-criteria data-criteria-id="' . p202_setup_e($criterion['id'] ?? 'none') . '">'
		. '<div class="col-6 col-md-3"><label class="form-label small w-100">If<select class="form-select form-select-sm" data-rule-field="type">' . $typeOptions . '</select></label></div>'
		. '<div class="col-6 col-md-2"><label class="form-label small w-100">is or is not<select class="form-select form-select-sm" data-rule-field="statement">'
		. p202_setup_options(['is' => 'is', 'is_not' => 'is not'], (string) ($criterion['statement'] ?? 'is')) . '</select></label></div>'
		. '<div class="col-10 col-md-6"><label class="form-label small w-100">Values, comma separated'
		. '<input type="text" class="form-control form-control-sm" data-rule-field="value" required value="' . p202_setup_e($criterion['value'] ?? '') . '" data-p202-suggest-url="' . p202_setup_e($suggestUrl) . '" autocomplete="off"></label></div>'
		. '<div class="col-2 col-md-1"><button type="button" class="btn btn-link btn-sm text-danger" data-p202-remove-row aria-label="Remove this criterion"><i class="bi bi-x-lg"></i></button></div>'
		. '</div>';
};

$redirectRow = static function (array $redirect) use ($campaignOptions, $pageOptions): string {
	$type = !empty($redirect['redirect_lp']) ? 'lp' : (!empty($redirect['redirect_url']) ? 'url' : 'campaign');
	return '<div class="row g-2 align-items-end mb-2" data-p202-row data-redirect data-redirect-id="' . p202_setup_e($redirect['id'] ?? 'none') . '">'
		. '<div class="col-12 col-md-3"><label class="form-label small w-100">Send to<select class="form-select form-select-sm" data-rule-field="redirect_type">'
		. p202_setup_options(['campaign' => 'Campaign', 'lp' => 'Landing page', 'url' => 'URL'], $type) . '</select></label></div>'
		. '<div class="col-12 col-md-6">'
		. '<label class="form-label small w-100" data-destination="campaign"' . ($type === 'campaign' ? '' : ' hidden') . '>Campaign<select class="form-select form-select-sm" data-rule-field="redirect_campaign" required>'
		. p202_setup_options($campaignOptions, (string) ($redirect['redirect_campaign'] ?? ''), 'Choose a campaign') . '</select></label>'
		. '<label class="form-label small w-100" data-destination="lp"' . ($type === 'lp' ? '' : ' hidden') . '>Landing page<select class="form-select form-select-sm" data-rule-field="redirect_lp" required>'
		. p202_setup_options($pageOptions, (string) ($redirect['redirect_lp'] ?? ''), 'Choose a landing page') . '</select></label>'
		. '<label class="form-label small w-100" data-destination="url"' . ($type === 'url' ? '' : ' hidden') . '>URL<input type="url" class="form-control form-control-sm" data-rule-field="redirect_url" required placeholder="https://" value="' . p202_setup_e($redirect['redirect_url'] ?? '') . '"></label>'
		. '</div>'
		. '<div class="col-8 col-md-2" data-split-only><label class="form-label small w-100">Weight<input type="number" min="1" class="form-control form-control-sm" data-rule-field="weight" required value="' . p202_setup_e($redirect['weight'] ?? '') . '" placeholder="50"></label></div>'
		. '<div class="col-4 col-md-1" data-split-only><button type="button" class="btn btn-link btn-sm text-danger" data-p202-remove-row aria-label="Remove this destination"><i class="bi bi-x-lg"></i></button></div>'
		. '</div>';
};

$ruleCard = static function (array $rule, array $criteria, array $redirects, bool $removable) use ($criterionRow, $redirectRow): string {
	$ruleKey = isset($rule['id']) ? (string) $rule['id'] : 'new';
	$split = !empty($rule['splittest']);
	$inactive = isset($rule['status']) && (string) $rule['status'] === '0';
	if ($criteria === []) {
		$criteria = [[]];
	}
	if ($redirects === []) {
		$redirects = [[]];
	}
	$criteriaHtml = '';
	foreach ($criteria as $criterion) {
		$criteriaHtml .= $criterionRow($criterion);
	}
	$redirectsHtml = '';
	foreach ($redirects as $redirect) {
		$redirectsHtml .= $redirectRow($redirect);
	}
	return '<section class="p202-panel mb-3" data-p202-row data-rule data-rule-id="' . p202_setup_e($rule['id'] ?? 'none') . '">'
		. '<div class="p202-panel__head">'
		. '<h3 class="p202-panel__title">Rule</h3>'
		. '<div class="p202-panel__aside p202-toolbar">'
		. '<div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" id="split-' . $ruleKey . '" data-rule-field="split"' . ($split ? ' checked' : '') . '><label class="form-check-label" for="split-' . $ruleKey . '">Split test</label></div>'
		. '<div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" id="inactive-' . $ruleKey . '" data-rule-field="inactive"' . ($inactive ? ' checked' : '') . '><label class="form-check-label" for="inactive-' . $ruleKey . '">Inactive</label></div>'
		. ($removable ? '<button type="button" class="btn btn-outline-danger btn-sm" data-p202-remove-row>Remove rule</button>' : '')
		. '</div></div>'
		. '<div class="p202-panel__body">'
		. '<div class="mb-3"><label class="form-label" for="rule-name-' . $ruleKey . '">Rule name</label>'
		. '<input type="text" class="form-control" id="rule-name-' . $ruleKey . '" data-rule-field="rule_name" required value="' . p202_setup_e($rule['rule_name'] ?? '') . '" placeholder="US mobile visitors"></div>'
		. '<div class="form-label">When a visitor matches every criterion</div>'
		. '<div data-criteria-list>' . $criteriaHtml . '</div>'
		. '<button type="button" class="btn btn-secondary btn-sm mb-3" data-add-criterion><i class="bi bi-plus"></i> Add a criterion</button>'
		. '<div class="form-label">send them to</div>'
		. '<div data-redirect-list data-split="' . ($split ? '1' : '0') . '">' . $redirectsHtml . '</div>'
		. '<button type="button" class="btn btn-secondary btn-sm" data-add-redirect data-split-only><i class="bi bi-plus"></i> Add a destination</button>'
		. '</div></section>';
};

template_top('Smart Redirector'); ?>

<div class="p202-page-header p202-page-header--accent">
	<div class="p202-page-header__icon"><i class="bi bi-arrow-repeat"></i></div>
	<div class="p202-page-header__text">
		<h1 class="p202-page-header__title">Redirector</h1>
		<p class="p202-page-header__desc">Send each visitor where they convert best: by country, device, browser and more, or split-test several destinations.</p>
	</div>
</div>

<?php
echo p202_setup_query_flashes([
	'added' => 'Redirector added. Give it a default destination and its first rule below.',
	'saved' => 'Redirector renamed.',
	'deleted' => 'Redirector removed, with its rules.',
	'rules_added' => 'Rules saved.',
], $_GET);
if ($error) {
	echo p202_setup_error_flashes($error, ['rotator_name']);
}
?>

<div class="row g-4 mb-4">
	<div class="col-12 col-lg-5">
		<section class="p202-panel" id="rotator-form">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title">Add a redirector</h2>
				<p class="p202-panel__sub">One tracking link that decides where each visitor goes.</p>
			</div>
			<div class="p202-panel__body">
				<form method="post" action="<?php echo p202_setup_e($self); ?>">
					<?php echo p202_setup_token_field($token); ?>
					<div class="mb-3">
						<label class="form-label" for="rotator_name">Redirector name</label>
						<input type="text" class="form-control<?php echo p202_setup_invalid($error, 'rotator_name'); ?>" id="rotator_name" name="rotator_name" value="<?php echo p202_setup_e($_SERVER['REQUEST_METHOD'] == 'POST' ? (string) ($_POST['rotator_name'] ?? '') : ''); ?>" maxlength="255" required>
						<?php echo p202_setup_feedback($error, 'rotator_name'); ?>
					</div>
					<div class="p202-form-actions">
						<button type="submit" class="btn <?php echo $selected === null ? 'btn-primary' : 'btn-secondary'; ?>" id="addRotator">Add redirector</button>
					</div>
				</form>
			</div>
		</section>
	</div>

	<div class="col-12 col-lg-7">
		<section class="p202-panel">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title">Your redirectors</h2>
				<span class="p202-pill p202-pill--accent"><?php echo count($rotators); ?></span>
				<?php if (count($rotators) > 5) { ?>
					<div class="p202-panel__aside"><?php echo p202_setup_list_filter('rotator-list', 'Filter redirectors…'); ?></div>
				<?php } ?>
			</div>
			<div class="p202-panel__body">
				<?php if ($rotators === []) { ?>
					<div class="p202-empty">
						<i class="bi bi-arrow-repeat p202-empty__icon"></i>
						<strong class="p202-empty__title">No redirectors yet</strong>
						<div>Name one, and its rules editor opens here.</div>
						<div class="p202-empty__action"><a class="btn btn-secondary btn-sm" href="#rotator_name">Name your first redirector</a></div>
					</div>
				<?php } else { ?>
					<ul class="p202-list" id="rotator-list">
						<?php foreach ($rotators as $rotator) {
							$rid = (int) $rotator['id'];
							$rules = $rulesByRotator[$rid] ?? []; ?>
							<li class="p202-list__item<?php echo $rid === $selectedId ? ' is-active' : ''; ?>" data-p202-filter-text="<?php echo p202_setup_e($rotator['name']); ?>">
								<span class="p202-list__name"><?php echo p202_setup_e($rotator['name']); ?></span>
								<span class="p202-pill"><?php echo count($rules) . ' ' . (count($rules) === 1 ? 'rule' : 'rules'); ?></span>
								<span class="p202-list__actions">
									<a class="p202-list__action" href="<?php echo p202_setup_e($self . '?rotator_id=' . $rid . '#rules'); ?>">edit rules</a>
									<?php if ($userObj->hasPermission("remove_rotator")) {
										echo p202_setup_remove_form($self, ['delete_rotator_id' => $rid, 'delete_rotator_name' => (string) $rotator['name'], 'token' => $token],
											'Remove the redirector "' . $rotator['name'] . '" and its rules? Tracking links that use it stop redirecting.');
									} ?>
								</span>
								<span class="p202-list__meta">Default: <?php echo p202_setup_e($destination($rotator, 'default_')); ?></span>
								<?php if ($rules !== []) { ?>
									<ul class="p202-list__children">
										<?php foreach ($rules as $rule) {
											$ruleId = (int) $rule['id'];
											$conditions = array_map(
												static fn (array $c): string => ($criterionTypes[$c['type']] ?? $c['type']) . ' ' . ($c['statement'] === 'is_not' ? 'is not' : 'is') . ' ' . $c['value'],
												$criteriaByRule[$ruleId] ?? []
											);
											$targets = array_map(
												static fn (array $r): string => $destination($r, 'redirect_') . ((int) $rule['splittest'] === 1 && $r['weight'] !== null ? ' (weight ' . $r['weight'] . ')' : ''),
												$redirectsByRule[$ruleId] ?? []
											); ?>
											<li class="p202-list__item">
												<span class="p202-list__name"><?php echo p202_setup_e($rule['rule_name']); ?></span>
												<?php if ((string) $rule['status'] === '0') { ?><span class="p202-pill p202-pill--warn">inactive</span><?php } ?>
												<?php if ((int) $rule['splittest'] === 1) { ?><span class="p202-pill">split test</span><?php } ?>
												<span class="p202-list__meta">If <?php echo p202_setup_e($conditions === [] ? 'no criteria' : implode(' and ', $conditions)); ?> · to <?php echo p202_setup_e($targets === [] ? 'nowhere yet' : implode(', ', $targets)); ?></span>
											</li>
										<?php } ?>
									</ul>
								<?php } ?>
							</li>
						<?php } ?>
					</ul>
				<?php } ?>
			</div>
		</section>
	</div>
</div>

<?php if ($rotators !== []) { ?>
<section class="p202-section" id="rules">
	<h2 class="p202-section__title">Rules</h2>
	<form class="p202-toolbar mb-3" method="get" action="<?php echo p202_setup_e($self); ?>" data-rotator-picker>
		<label class="form-label mb-0" for="rotator_id">Redirector</label>
		<select class="form-select" id="rotator_id" name="rotator_id" style="max-width: 20rem;">
			<?php
			$rotatorOptions = [];
			foreach ($rotators as $rotator) {
				$rotatorOptions[(string) $rotator['id']] = (string) $rotator['name'];
			}
			echo p202_setup_options($rotatorOptions, $selectedId > 0 ? (string) $selectedId : null, 'Choose a redirector', '0');
			?>
		</select>
		<button type="submit" class="btn btn-secondary">Open</button>
	</form>

	<?php if ($selected === null) { ?>
		<p class="text-body-secondary">Choose a redirector to edit its default destination and rules.</p>
	<?php } else {
		$rules = $rulesByRotator[(int) $selected['id']] ?? [];
		$defaultType = !empty($selected['default_lp']) ? 'lp' : (!empty($selected['default_url']) ? 'url' : 'campaign'); ?>
		<form id="rules-form" data-rules-url="<?php echo p202_setup_e($base . 'tracking202/ajax/rotator.php'); ?>" data-rotator-id="<?php echo (int) $selected['id']; ?>" data-done-url="<?php echo p202_setup_e($self . '?rotator_id=' . (int) $selected['id'] . '&rules_added=1#rules'); ?>">
			<?php echo p202_setup_token_field($token); ?>
			<div data-rules-error></div>
			<section class="p202-panel mb-3">
				<div class="p202-panel__head">
					<h3 class="p202-panel__title">Default destination</h3>
					<p class="p202-panel__sub">Where a visitor goes when no rule matches.</p>
				</div>
				<div class="p202-panel__body">
					<div class="row g-2 align-items-end" data-defaults>
						<div class="col-12 col-md-3"><label class="form-label w-100" for="default_type_select">Send to
							<select class="form-select" id="default_type_select" name="default_type">
								<?php echo p202_setup_options(['campaign' => 'Campaign', 'lp' => 'Landing page', 'url' => 'URL'], $defaultType); ?>
							</select></label></div>
						<div class="col-12 col-md-9">
							<label class="form-label w-100" data-destination="campaign"<?php echo $defaultType === 'campaign' ? '' : ' hidden'; ?>>Campaign
								<select class="form-select" name="default_campaign" required><?php echo p202_setup_options($campaignOptions, (string) ($selected['default_campaign'] ?? ''), 'Choose a campaign'); ?></select></label>
							<label class="form-label w-100" data-destination="lp"<?php echo $defaultType === 'lp' ? '' : ' hidden'; ?>>Landing page
								<select class="form-select" name="default_lp" required><?php echo p202_setup_options($pageOptions, (string) ($selected['default_lp'] ?? ''), 'Choose a landing page'); ?></select></label>
							<label class="form-label w-100" data-destination="url"<?php echo $defaultType === 'url' ? '' : ' hidden'; ?>>URL
								<input type="url" class="form-control" name="default_url" required placeholder="https://" value="<?php echo p202_setup_e($selected['default_url'] ?? ''); ?>"></label>
						</div>
					</div>
				</div>
			</section>

			<div id="rule-list">
				<?php if ($rules === []) {
					echo $ruleCard([], [], [], false);
				} else {
					foreach ($rules as $index => $rule) {
						echo $ruleCard($rule, $criteriaByRule[(int) $rule['id']] ?? [], $redirectsByRule[(int) $rule['id']] ?? [], $index > 0 && $userObj->hasPermission("remove_rotator_rule"));
					}
				} ?>
			</div>
			<template id="rule-template"><?php echo $ruleCard([], [], [], true); ?></template>
			<template id="criterion-template"><?php echo $criterionRow([]); ?></template>
			<template id="redirect-template"><?php echo $redirectRow([]); ?></template>
			<datalist id="rotator-devices"><option value="bot">Bot</option><option value="mobile">Mobile</option><option value="tablet">Tablet</option><option value="desktop">Desktop</option></datalist>

			<p class="form-text">To split-test every visitor, give a rule one criterion with the value <code>ALL</code>.</p>
			<div class="p202-form-actions">
				<button type="button" class="btn btn-secondary" data-add-rule><i class="bi bi-plus"></i> Add a rule</button>
				<button type="submit" class="btn btn-primary" id="post_rules">Save rules</button>
			</div>
		</form>
	<?php } ?>
</section>
<?php } ?>

<?php echo p202_setup_script_tag($base); ?>
<?php template_bottom();
