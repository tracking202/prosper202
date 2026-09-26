<?php
declare(strict_types=1);
include_once(str_repeat("../", 1).'202-config/connect.php');
include_once(str_repeat("../", 1).'202-config/clickserver_api_management.php');
require_once __DIR__ . '/../202-config/functions-account-ui.php';

AUTH::require_user();

/*
 * Account › ClickServers, on the v2 shell: the domains activated with this
 * account's Prosper202 ClickServer API key, each with a switch.
 *
 * A switch posts to 202-config/clickserver_api_management.php the domain and
 * the method, with the session token. The endpoint uses this account's
 * stored key and checks the permission and the domain itself, so the page
 * no longer hands the key to the browser. Nothing here is linked from the menu; the page
 * answers the users the access_to_clickservers permission names.
 */

if (!$userObj->hasPermission('access_to_clickservers')) {
	header('location: ' . get_absolute_url() . '202-account/');
	exit;
}

//get all of the user data
$mysql['user_id'] = $db->real_escape_string((string)$_SESSION['user_id']);
$user_sql = "	SELECT 	`clickserver_api_key`
				 FROM   	`202_users`
				 WHERE  	`202_users`.`user_id`='".$mysql['user_id']."'";
$user_result = $db->query($user_sql);
$user_row = $user_result ? $user_result->fetch_assoc() : null;
$apiKey = (string)($user_row['clickserver_api_key'] ?? '');

$clickservers = null;
$license = null;
if ($apiKey !== '') {
	$clickservers = clickserver_api_domain_list($apiKey);
	$license = clickserver_api_license($apiKey);
}
$listAvailable = is_array($clickservers);
$domainsUsed = is_array($license) ? (int)($license['domainsUsed'] ?? 0) : null;
$domainsAvail = is_array($license) ? (int)($license['domainsAvail'] ?? 0) : null;
$usage = ($domainsUsed !== null && $domainsAvail) ? round($domainsUsed / $domainsAvail * 100, 1) : null;

$e = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

template_top('ClickServer Management', ['ui' => 'v2']);
?>

<div class="p202-page-header">
	<div class="p202-page-header__icon"><i class="bi bi-hdd-network"></i></div>
	<div class="p202-page-header__text">
		<h1 class="p202-page-header__title">ClickServers</h1>
		<p class="p202-page-header__desc">Every domain activated with your Prosper202 ClickServer API key. Switch a domain off to free its licence for another.</p>
	</div>
</div>

<?php echo p202_account_render_flashes(); ?>
<div data-clickserver-message></div>

<?php if ($apiKey === '') { ?>
	<div class="p202-empty">
		<i class="bi bi-hdd-network p202-empty__icon"></i>
		<strong class="p202-empty__title">No ClickServer API key on file</strong>
		<div>Paste the key from your Prosper202 customer dashboard to see and switch the domains it covers.</div>
		<div class="p202-empty__action">
			<form method="post" action="<?php echo $e(get_absolute_url() . '202-account/account.php'); ?>" class="d-flex gap-2 justify-content-center flex-wrap">
				<?php echo p202_account_token_field(); ?>
				<input type="hidden" name="update_clickserver_api_key" value="1">
				<input type="hidden" name="return_to" value="clickservers">
				<label class="visually-hidden" for="clickserver_api_key">ClickServer API key</label>
				<input type="text" class="form-control form-control-sm w-auto" id="clickserver_api_key" name="clickserver_api_key" required autocomplete="off" spellcheck="false" placeholder="ClickServer API key">
				<button class="btn btn-secondary btn-sm" type="submit">Save key</button>
			</form>
		</div>
	</div>
<?php } elseif (!$listAvailable) { ?>
	<?php echo p202_flash('bad', 'The ClickServer service could not be reached, so your domains cannot be listed just now. Reload the page to try again.'); ?>
<?php } else { ?>
	<div class="row g-4">
		<div class="col-12 col-lg-8">
			<section class="p202-panel">
				<div class="p202-panel__head">
					<h2 class="p202-panel__title">Domains</h2>
					<span class="p202-pill"><?php echo count($clickservers) === 1 ? '1 domain' : count($clickservers) . ' domains'; ?></span>
				</div>
				<div class="p202-panel__body">
					<?php if (!$clickservers) { ?>
						<div class="p202-empty">
							<i class="bi bi-globe p202-empty__icon"></i>
							<strong class="p202-empty__title">No domains activated yet</strong>
							<div>A domain is activated the first time a Prosper202 install on it signs in with this key.</div>
						</div>
					<?php } else { ?>
						<ul class="p202-list">
							<?php foreach ($clickservers as $index => $clickserver) {
								$domain = (string)($clickserver['clickserver']['domain'] ?? '');
								$on = (string)($clickserver['clickserver']['status'] ?? '') === '1';
								?>
								<li class="p202-list__item">
									<span class="p202-list__name"><?php echo $e($domain); ?></span>
									<span class="p202-list__actions">
										<span class="form-check form-switch mb-0">
											<input class="form-check-input" type="checkbox" role="switch" id="clickserver-<?php echo (int)$index; ?>" data-clickserver="<?php echo $e($domain); ?>"<?php echo $on ? ' checked' : ''; ?>>
											<label class="form-check-label" for="clickserver-<?php echo (int)$index; ?>">Active</label>
										</span>
									</span>
								</li>
							<?php } ?>
						</ul>
					<?php } ?>
				</div>
			</section>
		</div>
		<div class="col-12 col-lg-4">
			<div class="p202-tile">
				<div class="p202-tile__label">Licence used</div>
				<div class="p202-tile__value" data-clickserver-usage><?php echo $usage === null ? '–' : $e($usage . '%'); ?></div>
				<div class="p202-tile__sub"><?php echo $domainsUsed === null ? 'The licence could not be read just now.' : '<span data-clickserver-used>' . $e($domainsUsed) . '</span> of ' . $e($domainsAvail) . ' activated domains'; ?></div>
			</div>
		</div>
	</div>

	<script>
		document.addEventListener('DOMContentLoaded', function () {
			var endpoint = <?php echo json_encode(get_absolute_url() . '202-config/clickserver_api_management.php'); ?>;
			var token = <?php echo json_encode((string)($_SESSION['token'] ?? '')); ?>;
			var host = <?php echo json_encode((string)($_SERVER['HTTP_HOST'] ?? '')); ?>;
			var used = <?php echo json_encode($domainsUsed); ?>;
			var avail = <?php echo json_encode($domainsAvail); ?>;
			var message = document.querySelector('[data-clickserver-message]');
			var say = function (kind, text) {
				var alert = document.createElement('div');
				alert.className = 'alert p202-flash ' + (kind === 'ok' ? 'alert-success' : 'alert-danger');
				alert.setAttribute('role', 'status');
				var icon = document.createElement('i');
				icon.className = 'bi ' + (kind === 'ok' ? 'bi-check-circle' : 'bi-x-circle');
				var body = document.createElement('div');
				body.className = 'p202-flash__body';
				body.textContent = text;
				alert.appendChild(icon);
				alert.appendChild(body);
				message.replaceChildren(alert);
			};

			document.querySelectorAll('[data-clickserver]').forEach(function (input) {
				input.addEventListener('change', function () {
					var domain = input.getAttribute('data-clickserver');
					var method = input.checked ? 'activate' : 'deactivate';
					var body = new URLSearchParams();
					body.set('clickserver_id', domain);
					body.set('method', method);
					body.set('token', token);
					input.disabled = true;
					fetch(endpoint, { method: 'POST', credentials: 'same-origin', body: body })
						.then(function (response) { return response.ok ? response.text() : ''; })
						.catch(function () { return ''; })
						.then(function (answer) {
							input.disabled = false;
							if (!answer || answer.trim() === '') {
								input.checked = !input.checked;
								say('bad', 'There was a problem changing ' + domain + '; it is unchanged. Contact support if this keeps happening.');
								return;
							}
							if (domain === host) {
								window.location.href = <?php echo json_encode(get_absolute_url() . '202-account/signout.php'); ?>;
								return;
							}
							if (used !== null && avail) {
								used += method === 'activate' ? 1 : -1;
								var usedEl = document.querySelector('[data-clickserver-used]');
								if (usedEl) { usedEl.textContent = used; }
								document.querySelector('[data-clickserver-usage]').textContent = Math.round(used / avail * 1000) / 10 + '%';
							}
							say('ok', domain + (method === 'activate' ? ' is active.' : ' is switched off.'));
						});
				});
			});
		});
	</script>
<?php } ?>

<?php template_bottom();
