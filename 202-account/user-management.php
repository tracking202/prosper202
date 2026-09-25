<?php

declare(strict_types=1);
include_once(str_repeat("../", 1) . '202-config/connect.php');
require_once __DIR__ . '/../202-config/functions-account-ui.php';

AUTH::require_user();

if (!$userObj->hasPermission("add_users")) {
	header('location: ' . get_absolute_url() . '202-account/');
	exit;
}

/*
 * Account › User management, on the v2 shell.
 *
 * Add, edit and remove the other users of this install and choose their role.
 * The form posts the fields it always posted (user_fname, user_lname,
 * user_email, user_name, user_password, user_password2, user_role,
 * user_active, and user_id when editing) with the session token.
 *
 * Removing a user was a GET link (?delete_user_id=) guarded only by a
 * confirm() in the browser, so any page could make a signed-in admin's
 * browser remove a user by loading an image. It is a POST now, with the
 * token, behind the same confirmation; a GET to the old address removes
 * nothing and says so.
 */

$e = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$self = get_absolute_url() . '202-account/user-management.php';

/** Roles this form may assign, id => [name, what it can do]. Role 1 (Super user) never is. */
$roles = [
	'2' => ['Admin', 'Everything, including users and settings.'],
	'3' => ['Campaign manager', 'Sets up and changes campaigns, and sees their data.'],
	'4' => ['Campaign optimizer', 'Sees campaign data and updates costs and subids.'],
	'5' => ['Campaign viewer', 'Sees campaign data; changes nothing.'],
];
if (function_exists('random_bytes')) {
	$roles['6'] = ['Publisher', 'Sees only the traffic they send.'];
}

$error = [];
$mysql = [];
$form = [];
$editing = false;
$canManageAdmins = $userObj->hasPermission("add_edit_delete_admin");

$slack = false;
$mysql['user_own_id'] = $db->real_escape_string((string)$_SESSION['user_own_id']);
$user_sql = "SELECT 2u.user_name as username, 2up.user_slack_incoming_webhook AS url FROM 202_users AS 2u INNER JOIN 202_users_pref AS 2up ON (2up.user_id = 1) WHERE 2u.user_id = '" . $mysql['user_own_id'] . "'";
$user_results = $db->query($user_sql);
$user_row = $user_results->fetch_assoc();
$username = $user_row['username'];

if (!empty($user_row['url']))
	$slack = new Slack($user_row['url']);

//set the timezone for the user, for entering their dates.
AUTH::set_timezone($_SESSION['user_timezone']);

$editUserId = (int)($_GET['edit_user_id'] ?? 0);
if ($editUserId > 0) {
	$editing = true;
}

$roleName = static fn (string $roleId): string => $roles[$roleId][0] ?? '';

// ─── Remove ──────────────────────────────────────────────────────────

if (!empty($_GET['delete_user_id'])) {
	// The retired GET address: never a write.
	p202_account_flash('warn', 'Removing a user now asks first. Use remove beside the user in the list.');
	p202_account_redirect('202-account/user-management.php');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_user_id'])) {
	if (!AUTH::check_csrf_token()) {
		p202_account_flash('bad', P202_ACCOUNT_TOKEN_REFUSED);
		p202_account_redirect('202-account/user-management.php');
	}
	if (!$canManageAdmins) {
		p202_account_flash('bad', 'You are not allowed to remove users.');
		p202_account_redirect('202-account/user-management.php');
	}

	$deleteUserId = (int)$_POST['delete_user_id'];
	// The list never offers the super user or yourself; refuse both by id
	// too, because the id is whatever the request says it is.
	if ($deleteUserId <= 1 || $deleteUserId === (int)($_SESSION['user_own_id'] ?? 0)) {
		p202_account_flash('bad', 'That user cannot be removed here.');
		p202_account_redirect('202-account/user-management.php');
	}

	$target = null;
	$target_stmt = $db->prepare('SELECT user_name, role_id FROM 202_users LEFT JOIN 202_user_role USING (user_id) WHERE user_id = ? AND user_deleted != 1 LIMIT 1');
	if ($target_stmt) {
		$target_stmt->bind_param('i', $deleteUserId);
		if ($target_stmt->execute()) {
			$targetResult = $target_stmt->get_result();
			$target = $targetResult !== false ? $targetResult->fetch_assoc() : null;
		}
		$target_stmt->close();
	}
	if (!$target) {
		p202_account_flash('warn', 'That user was not found, or was already removed.');
		p202_account_redirect('202-account/user-management.php');
	}

	// The soft delete and the purge of the data that must not outlive the
	// user (MTA state, the identity graph, app registrations, goals) commit
	// together, through the class DELETE /api/v3/users/{id} uses too.
	try {
		(new \Prosper202\User\UserDataPurge($db))->deleteUser($deleteUserId);
	} catch (\Throwable $exception) {
		error_log('user-management: ' . $exception->getMessage());
		p202_account_flash('bad', 'The user could not be removed, and nothing was changed; they can still sign in. The error log has the reason.');
		p202_account_redirect('202-account/user-management.php');
	}

	if ($slack) {
		$slack->push('user_management_user', ['user' => $username, 'type' => 'Removed', 'username' => $target['user_name'], 'role' => $roleName((string)$target['role_id'])]);
	}

	p202_account_flash('ok', $target['user_name'] . ' is removed and can no longer sign in.');
	p202_account_redirect('202-account/user-management.php');
}

// ─── Add or edit ─────────────────────────────────────────────────────

$user_sql = "SELECT user_id,user_email,user_time_register,user_timezone,install_hash,user_hash,modal_status,vip_perks_status FROM 202_users WHERE user_id=1";
$user_result2 = _mysqli_query($user_sql);
$user_row2 = $user_result2->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	// validate token
	if (!AUTH::check_csrf_token()) {
		p202_account_flash('bad', P202_ACCOUNT_TOKEN_REFUSED);
		p202_account_redirect('202-account/user-management.php' . ($editing ? '?edit_user_id=' . $editUserId : ''));
	}

	$mysql['form_user_id'] = $db->real_escape_string(trim((string)($_POST['user_id'] ?? '')));
	if ($editing && (int)$mysql['form_user_id'] !== $editUserId) {
		// The form posts the id it was opened for; a mismatch with the
		// address means the two were not the same form.
		$error['user_role'] = 'This form was opened for a different user. Open the user again from the list.';
	}
	$mysql['user_fname'] = $db->real_escape_string(trim((string)($_POST['user_fname'] ?? '')));
	if (empty($mysql['user_fname'])) {
		$error['user_fname'] = 'Enter a first name.';
	}

	$mysql['user_lname'] = $db->real_escape_string(trim((string)($_POST['user_lname'] ?? '')));
	if (empty($mysql['user_lname'])) {
		$error['user_lname'] = 'Enter a last name.';
	}

	$mysql['user_email'] = $db->real_escape_string(trim((string)($_POST['user_email'] ?? '')));
	if (empty($mysql['user_email'])) {
		$error['user_email'] = 'Enter an email address.';
	} elseif (!check_email_address(trim((string)$_POST['user_email']))) {
		$error['user_email'] = 'Enter a valid email address.';
	} else {
		// Another account with this email. When editing, the user's own row
		// is not "another": the classic page counted it, so saving any edit
		// without changing the email was refused as a duplicate.
		$check_user_email = "select user_name,user_id from 202_users where user_email='" . $mysql['user_email'] . "'";
		if ($editing) {
			$check_user_email .= " and user_id != '" . $db->real_escape_string((string)$editUserId) . "'";
		}
		$check_user_email_result = _mysqli_query($check_user_email);
		if ($check_user_email_result === false) {
			$error['user_email'] = 'The email could not be checked just now; try again.';
		} elseif ($check_user_email_result->num_rows != 0) {
			$error['user_email'] = 'The email you entered already exists.';
		}
	}

	$mysql['user_name'] = $db->real_escape_string(trim((string)($_POST['user_name'] ?? '')));
	if (empty($mysql['user_name'])) {
		$error['user_name'] = 'Enter a username.';
	}

	if (!empty($mysql['user_name'])) {
		// A username belongs to one account. The classic page skipped this
		// check when editing, so a rename could take another user's name.
		$check_user = "select user_name,user_id from 202_users where user_name='" . $mysql['user_name'] . "'";
		if ($editing) {
			$check_user .= " and user_id != '" . $db->real_escape_string((string)$editUserId) . "'";
		}
		$check_user_result = _mysqli_query($check_user);
		if ($check_user_result === false) {
			$error['user_name'] = 'The username could not be checked just now; try again.';
		} elseif ($check_user_result->num_rows != 0) {
			$error['user_name'] = 'The username you entered already exists.';
		}
	}

	$postedPassword = (string)($_POST['user_password'] ?? '');
	if ($editing !== true) {
		if (trim($postedPassword) === '') {
			$error['user_password'] = 'Enter a password.';
		}
	}
	// A password is set when creating, or when editing and a new one is typed.
	$settingPassword = $editing !== true || trim($postedPassword) !== '';
	if ($settingPassword && !isset($error['user_password'])) {
		$mysql['user_password'] = trim($postedPassword);
		// The same rule as Personal settings: bcrypt reads only the first 72
		// bytes, so a longer password would be accepted with any tail.
		if (strlen($mysql['user_password']) < 8 || strlen($mysql['user_password']) > 72) {
			$error['user_password'] = 'The password must be between 8 and 72 characters long.';
		}
		$mysql['user_password2'] = trim((string)($_POST['user_password2'] ?? ''));
		if ($mysql['user_password2'] === '') {
			$error['user_password2'] = 'Retype the password.';
		} elseif (strcmp($mysql['user_password'], $mysql['user_password2']) !== 0) {
			$error['user_password2'] = 'Make sure the passwords you entered match.';
		}
	}

	$mysql['user_role'] = $db->real_escape_string(trim((string)($_POST['user_role'] ?? '')));
	if (empty($mysql['user_role'])) {
		$error['user_role'] = 'Please select user role';
	}

	if (($_POST['user_active'] ?? '') !== 'on')
		$mysql['user_active'] = 0;
	else
		$mysql['user_active'] = 1;

	// Allow-list the requested role. The dropdown only offers roles 2-6; role 1
	// (Super user) must never be assignable through this form.
	if (!isset($error['user_role']) && !array_key_exists($mysql['user_role'], $roles)) {
		$error['user_role'] = 'Invalid user role.';
	}

	// Authorization gate that MUST run before the write: only users who can manage
	// admins may assign the Admin role or modify an existing Admin/Super-user account.
	// (The role_id==2 check further down only guards the GET form-render path.)
	if (!$canManageAdmins) {
		if ($mysql['user_role'] === '2') {
			$error['user_role'] = 'You are not authorized to assign the Admin role.';
		}
		if ($editing === true) {
			$target_role_sql = "SELECT role_id FROM 202_user_role WHERE user_id = '" . $mysql['form_user_id'] . "'";
			$target_role_result = _mysqli_query($target_role_sql);
			$target_role_row = $target_role_result ? $target_role_result->fetch_assoc() : null;
			$target_role_id = $target_role_row['role_id'] ?? null;
			if ($mysql['form_user_id'] === '1' || $target_role_id === '1' || $target_role_id === '2' || $target_role_result === false) {
				$error['user_role'] = 'You are not authorized to modify this account.';
			}
		}
	}
	if ($editing === true && $mysql['form_user_id'] === '1') {
		$error['user_role'] = 'You are not authorized to modify this account.';
	}

	if (!$error) {

		// Only hash password if it's set (for new users or when changing password)
		if (isset($mysql['user_password'])) {
			$hasher = function_exists('hash_user_pass') ? 'hash_user_pass' : 'salt_user_pass';
			$mysql['user_pass'] = $db->real_escape_string($hasher($mysql['user_password']));
		}
		$user_hash = ''; // Default empty value

		if ($editing === true) {
			$user_sql  = " UPDATE 202_users SET";
		} else {
			$user_sql = "INSERT INTO `202_users` SET";
		}

		$user_sql .= " `user_fname`='" . $mysql['user_fname'] . "',
								  `user_lname`='" . $mysql['user_lname'] . "',
								  `user_email`='" . $mysql['user_email'] . "',
								  `user_name`='" . $mysql['user_name'] . "',
								  `user_time_register`='" . $user_row2['user_time_register'] . "',
								  `user_timezone`='" . $user_row2['user_timezone'] . "',";
		if ($editing !== true) {
			$user_sql .= "`user_pass`='" . $mysql['user_pass'] . "',";

			$user_sql .= "`install_hash`='" . $user_row2['install_hash'] . "',
					`user_hash`='" . $user_hash . "',
				    `modal_status`='" . $user_row2['modal_status'] . "',
					`vip_perks_status`='" . $user_row2['vip_perks_status'] . "',";

			if (function_exists('random_bytes')) {
				$user_public_publisher_id = createId(5);
				$user_sql .= "`user_public_publisher_id`= '" . $user_public_publisher_id . "', ";
			}
		} elseif ($editing === true && isset($mysql['user_pass'])) {
			// When editing, only update password if a new one was provided
			$user_sql .= "`user_pass`='" . $mysql['user_pass'] . "',";
		}
		$user_sql .= "`user_active`='" . $mysql['user_active'] . "'";

		$saved = false;
		if ($editing == true) {
			$user_sql  .= " WHERE user_id='" . $mysql['form_user_id'] . "'";
			$saved = (bool)_mysqli_query($user_sql);
			$role_sql = "UPDATE 202_user_role SET role_id = '" . $mysql['user_role'] . "' WHERE user_id = '" . $mysql['form_user_id'] . "'";
			$saved = $saved && (bool)_mysqli_query($role_sql);
		} else {
			$saved = (bool)_mysqli_query($user_sql);
			if ($saved) {
				$user_id = $db->insert_id;
				$role_sql = "INSERT INTO 202_user_role SET user_id = '" . $user_id . "', role_id = '" . $mysql['user_role'] . "'";
				$pref_sql = "INSERT INTO 202_users_pref SET user_id = '" . $user_id . "'";
				$saved = (bool)_mysqli_query($role_sql) && (bool)_mysqli_query($pref_sql);

				// Every account starts with its default attribution model (plan
				// §6.4). This page writes the account without a transaction, so a
				// failure here cannot undo it; the attribution worker creates the
				// model the first time it meets an account that has none.
				if ($saved) {
					try {
						\Prosper202\Attribution\DefaultModel::ensureFor(new \Prosper202\Database\Connection($db), (int) $user_id);
					} catch (\Throwable $modelError) {
						error_log('user-management: default attribution model for user ' . (int) $user_id . ' not created: ' . $modelError->getMessage());
					}
				}
			}
		}

		if (!$saved) {
			$error['save'] = $editing
				? 'The changes could not be saved in full. Open the user again and check what was kept.'
				: 'The user could not be created in full. Check the list, then try again.';
		} else {
			$role = $roleName((string)$_POST['user_role']);
			if ($slack) {
				if ($editing === true) {
					$slack->push('user_management_user', ['user' => $username, 'type' => 'Updated', 'username' => $_POST['user_name'], 'role' => $role]);
				} else {
					$slack->push('user_management_user', ['user' => $username, 'type' => 'Created', 'username' => $_POST['user_name'], 'role' => $role]);
				}
			}

			p202_account_flash('ok', $editing
				? trim((string)$_POST['user_name']) . ' is saved as ' . $role . '.'
				: trim((string)$_POST['user_name']) . ' is added as ' . $role . '. They sign in with the username and password you set.');
			p202_account_redirect('202-account/user-management.php');
		}
	}

	// Refused: keep what was typed, never a password.
	foreach (['user_fname', 'user_lname', 'user_email', 'user_name', 'user_role'] as $field) {
		$form[$field] = trim((string)($_POST[$field] ?? ''));
	}
	$form['role_id'] = $form['user_role'];
	$form['user_active'] = ($_POST['user_active'] ?? '') === 'on' ? '1' : '0';
}

$editingRow = null;
if ($editing == true) {
	$edit_stmt = $db->prepare('SELECT user_fname,user_lname,user_name,user_id,user_email,user_time_register,user_active,role_id FROM 202_users LEFT JOIN 202_user_role USING (user_id) WHERE user_id = ? AND user_deleted != 1 LIMIT 1');
	if ($edit_stmt) {
		$edit_stmt->bind_param('i', $editUserId);
		if ($edit_stmt->execute()) {
			$editResult = $edit_stmt->get_result();
			$editingRow = $editResult !== false ? $editResult->fetch_assoc() : null;
		}
		$edit_stmt->close();
	}

	if (!$editingRow || $editUserId === 1) {
		p202_account_flash('warn', 'That user was not found, or was removed.');
		p202_account_redirect('202-account/user-management.php');
	}
	if ((string)$editingRow['role_id'] === '2' && !$canManageAdmins) {
		header('location: ' . $self);
		die();
	}
	if (!$form) {
		$form = array_map(static fn ($v): string => (string)$v, $editingRow);
	}
}

$users = [];
$user_sql = "SELECT user_fname,user_lname,user_name,user_id,user_email,user_active,role_id,role_name FROM 202_users LEFT JOIN 202_user_role USING (user_id) LEFT JOIN 202_roles USING (role_id) WHERE user_id!=1 and user_deleted!=1 ORDER BY user_fname, user_name";
$user_result = _mysqli_query($user_sql);
$usersReadable = $user_result !== false;
if ($usersReadable) {
	while ($row = $user_result->fetch_assoc()) {
		$users[] = $row;
	}
}

$formValue = static fn (string $field): string => (string)($form[$field] ?? '');
$active = $editing ? ($formValue('user_active') === '1') : (!$form || $formValue('user_active') === '1');
$currentRole = $formValue('role_id');

template_top('User Management');
?>

<div class="p202-page-header">
	<div class="p202-page-header__icon"><i class="bi bi-people"></i></div>
	<div class="p202-page-header__text">
		<h1 class="p202-page-header__title">Users</h1>
		<p class="p202-page-header__desc">Give the people you work with their own sign-in, and choose what each of them can see and change.</p>
	</div>
</div>

<?php
$extra = [];
if (!$usersReadable) {
	$extra[] = ['kind' => 'bad', 'text' => 'The user list could not be read just now. Reload the page to see it.'];
}
if (isset($error['save'])) {
	$extra[] = ['kind' => 'bad', 'text' => $error['save']];
} elseif ($error) {
	$extra[] = ['kind' => 'bad', 'text' => ($editing ? 'The changes were not saved.' : 'The user was not added.') . ' The fields below say why.'];
}
echo p202_account_render_flashes($extra);
?>

<div class="row g-4">
	<div class="col-12 col-lg-7">
		<section class="p202-panel" id="user-form">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title"><?php echo $editing ? 'Edit ' . $e($formValue('user_name')) : 'Add a user'; ?></h2>
				<span class="p202-panel__sub"><?php echo $editing ? 'leave the password alone to keep it' : 'they sign in with the username and password you set'; ?></span>
			</div>
			<div class="p202-panel__body">
				<form method="post" action="<?php echo $e($self . ($editing ? '?edit_user_id=' . $editUserId : '')); ?>">
					<?php echo p202_account_token_field(); ?>
					<?php if ($editing) { ?>
						<input type="hidden" name="user_id" value="<?php echo $e($editUserId); ?>">
					<?php } ?>
					<div class="row g-3">
						<div class="col-sm-6">
							<label class="form-label" for="user_fname">First name <span class="text-danger">*</span></label>
							<input type="text" class="form-control<?php echo p202_account_invalid($error, 'user_fname'); ?>" id="user_fname" name="user_fname" required autocomplete="off" value="<?php echo $e($formValue('user_fname')); ?>">
							<?php echo p202_account_field_error($error, 'user_fname'); ?>
						</div>
						<div class="col-sm-6">
							<label class="form-label" for="user_lname">Last name <span class="text-danger">*</span></label>
							<input type="text" class="form-control<?php echo p202_account_invalid($error, 'user_lname'); ?>" id="user_lname" name="user_lname" required autocomplete="off" value="<?php echo $e($formValue('user_lname')); ?>">
							<?php echo p202_account_field_error($error, 'user_lname'); ?>
						</div>
						<div class="col-sm-6">
							<label class="form-label" for="user_email">Email <span class="text-danger">*</span></label>
							<input type="email" class="form-control<?php echo p202_account_invalid($error, 'user_email'); ?>" id="user_email" name="user_email" required autocomplete="off" value="<?php echo $e($formValue('user_email')); ?>">
							<?php echo p202_account_field_error($error, 'user_email'); ?>
						</div>
						<div class="col-sm-6">
							<label class="form-label" for="user_name">Username <span class="text-danger">*</span></label>
							<input type="text" class="form-control<?php echo p202_account_invalid($error, 'user_name'); ?>" id="user_name" name="user_name" required autocomplete="off" value="<?php echo $e($formValue('user_name')); ?>">
							<?php echo p202_account_field_error($error, 'user_name'); ?>
						</div>
						<?php
						$passwordFields = '
						<div class="col-sm-6">
							<label class="form-label" for="user_password">' . ($editing ? 'New password' : 'Password <span class="text-danger">*</span>') . '</label>
							<input type="password" class="form-control' . p202_account_invalid($error, 'user_password') . '" id="user_password" name="user_password"' . ($editing ? '' : ' required') . ' minlength="8" autocomplete="new-password">
							<div class="form-text">8 to 72 characters.</div>
							' . p202_account_field_error($error, 'user_password') . '
						</div>
						<div class="col-sm-6">
							<label class="form-label" for="user_password2">Retype password' . ($editing ? '' : ' <span class="text-danger">*</span>') . '</label>
							<input type="password" class="form-control' . p202_account_invalid($error, 'user_password2') . '" id="user_password2" name="user_password2"' . ($editing ? '' : ' required') . ' minlength="8" autocomplete="new-password">
							' . p202_account_field_error($error, 'user_password2') . '
						</div>';
						if (!$editing) {
							echo $passwordFields;
						}
						?>
						<div class="col-sm-6">
							<label class="form-label" for="user_role">Role <span class="text-danger">*</span></label>
							<select class="form-select<?php echo p202_account_invalid($error, 'user_role'); ?>" id="user_role" name="user_role" required>
								<?php foreach ($roles as $roleId => [$label, $can]) {
									if ((string)$roleId === '2' && !$canManageAdmins && $currentRole !== '2') {
										continue;
									} ?>
									<option value="<?php echo $e($roleId); ?>"<?php echo ($currentRole === (string)$roleId || ($currentRole === '' && (string)$roleId === '5')) ? ' selected' : ''; ?>><?php echo $e($label . ' — ' . $can); ?></option>
								<?php } ?>
							</select>
							<div class="form-text">Campaign viewer is the default: it can look and change nothing.</div>
							<?php echo p202_account_field_error($error, 'user_role'); ?>
						</div>
						<div class="col-sm-6">
							<span class="form-label d-block">Status</span>
							<div class="form-check form-switch">
								<input class="form-check-input" type="checkbox" role="switch" id="user_active" name="user_active"<?php echo $active ? ' checked' : ''; ?>>
								<label class="form-check-label" for="user_active">Active</label>
							</div>
							<div class="form-text">An inactive user keeps their account but cannot sign in.</div>
						</div>
					</div>

					<?php if ($editing) { ?>
						<details class="p202-disclosure mt-3" data-p202-remember="account-user-password"<?php echo (isset($error['user_password']) || isset($error['user_password2'])) ? ' open' : ''; ?>>
							<summary>Advanced <span class="p202-disclosure__hint">set a new password</span></summary>
							<div class="p202-disclosure__body">
								<div class="row g-3">
									<?php echo $passwordFields; ?>
								</div>
							</div>
						</details>
					<?php } ?>

					<div class="p202-form-actions">
						<?php if ($editing) { ?>
							<a class="btn btn-secondary" href="<?php echo $e($self); ?>">Cancel</a>
							<button class="btn btn-primary" type="submit">Save changes</button>
						<?php } else { ?>
							<button class="btn btn-primary" type="submit">Add user</button>
						<?php } ?>
					</div>
				</form>
			</div>
		</section>
	</div>

	<div class="col-12 col-lg-5">
		<section class="p202-panel" id="users">
			<div class="p202-panel__head">
				<h2 class="p202-panel__title">Your users</h2>
				<span class="p202-pill"><?php echo count($users) === 1 ? '1 user' : count($users) . ' users'; ?></span>
			</div>
			<div class="p202-panel__body">
				<?php if (!$users && $usersReadable) { ?>
					<div class="p202-empty">
						<i class="bi bi-person-plus p202-empty__icon"></i>
						<strong class="p202-empty__title">No other users yet</strong>
						<div>Everyone you add signs in with their own username and sees only what their role allows.</div>
						<div class="p202-empty__action"><a class="btn btn-secondary btn-sm" href="#user_fname">Add the first user</a></div>
					</div>
				<?php } else { ?>
					<ul class="p202-list">
						<?php foreach ($users as $listed) {
							$listedName = trim((string)$listed['user_fname'] . ' ' . (string)$listed['user_lname']);
							if ($listedName === '') {
								$listedName = (string)$listed['user_name'];
							}
							$isAdmin = (string)$listed['role_id'] === '2';
							$mayChange = !$isAdmin || $canManageAdmins;
							?>
							<li class="p202-list__item<?php echo ($editing && (int)$listed['user_id'] === $editUserId) ? ' is-active' : ''; ?>" data-user-id="<?php echo $e($listed['user_id']); ?>">
								<span class="p202-list__name"><?php echo $e($listedName); ?></span>
								<span class="p202-pill<?php echo $isAdmin ? ' p202-pill--accent' : ''; ?>"><?php echo $e($listed['role_name'] ?? 'no role'); ?></span>
								<?php if ((string)$listed['user_active'] !== '1') { ?>
									<span class="p202-pill p202-pill--warn">inactive</span>
								<?php } ?>
								<?php if ($mayChange) { ?>
									<span class="p202-list__actions">
										<a class="p202-list__action" href="<?php echo $e($self . '?edit_user_id=' . (int)$listed['user_id']); ?>">edit</a>
										<?php if ($canManageAdmins) { ?>
											<form method="post" action="<?php echo $e($self); ?>" class="d-inline" data-p202-confirm="<?php echo $e('Remove ' . $listedName . '? They can no longer sign in, and their attribution models and reports are deleted. Campaigns and click data are kept.'); ?>">
												<?php echo p202_account_token_field(); ?>
												<input type="hidden" name="delete_user_id" value="<?php echo $e($listed['user_id']); ?>">
												<button type="submit" class="p202-list__action p202-list__action--danger">remove</button>
											</form>
										<?php } ?>
									</span>
								<?php } ?>
								<span class="p202-list__meta"><?php echo $e($listed['user_name'] . ' · ' . $listed['user_email']); ?></span>
							</li>
						<?php } ?>
					</ul>
				<?php } ?>
			</div>
		</section>
	</div>
</div>

<?php template_bottom();
