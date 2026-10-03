<?php
declare(strict_types=1);
include_once(str_repeat("../", 1).'202-config/connect.php');
require_once dirname(__DIR__) . '/202-config/functions-account-ui.php';
require_once dirname(__DIR__) . '/202-config/functions-feeds-ui.php';

AUTH::require_user();

/*
 * The App Store on the v2 shell (U7): the apps the feed lists, and the
 * ClickServer API key that activates them.
 *
 * Decided in U7, because the classic page read two ways: it showed the key
 * form only when a key was already saved, and the apps only when none was —
 * so a new user saw apps and no way to add the key. Both are shown now: the
 * apps, and the key form beside them (masked when a key is saved). The key
 * handler ran after the page had started printing and wrote whatever it was
 * sent once the token matched; it runs first now, refuses a bad token in
 * words, and answers a save with a redirect (post-redirect-get).
 */

$userId = (int) $_SESSION['user_id'];
$errors = [];
$notice = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	if (!AUTH::check_csrf_token()) {
		$notice = P202_ACCOUNT_TOKEN_REFUSED;
	} else {
		$key = is_string($_POST['clickserver_api_key'] ?? null) ? trim($_POST['clickserver_api_key']) : '';
		if (str_contains($key, '*')) {
			// The masked key the form shows came back unchanged.
			p202_account_flash('info', 'Nothing was changed: the key shown is masked. Paste a new key to replace it.');
			p202_account_redirect('202-appstore/');
		}
		if ($key !== '' && !clickserver_api_key_validate($key)) {
			$errors['clickserver_api_key'] = 'This API Key appears invalid.';
		} else {
			$conn = new \Prosper202\Database\Connection($db);
			$stmt = $conn->prepareWrite('UPDATE 202_users SET clickserver_api_key = ? WHERE user_id = ?');
			$conn->bind($stmt, 'si', [$key, $userId]);
			$conn->executeUpdate($stmt);
			p202_account_flash('ok', $key === '' ? 'ClickServer API key removed.' : 'ClickServer API key saved.');
			p202_account_redirect('202-appstore/');
		}
	}
}

$conn = new \Prosper202\Database\Connection($db);
$stmt = $conn->prepareRead('SELECT clickserver_api_key FROM 202_users WHERE user_id = ?');
$conn->bind($stmt, 'i', [$userId]);
$user_row = $conn->fetchOne($stmt) ?? [];
$savedKey = (string) ($user_row['clickserver_api_key'] ?? '');
// Most of a saved key stays hidden: the last characters are enough to tell two apart.
$maskedKey = $savedKey === '' ? '' : str_repeat('*', 22) . substr($savedKey, 22);

$ch = curl_init();
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/feed/appstore/');
$result = curl_exec($ch);
curl_close($ch);
$apps = p202_appstore_apps(is_string($result) ? json_decode($result, true) : null, get_absolute_url());
$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$typedKey = is_string($_POST['clickserver_api_key'] ?? null) ? $_POST['clickserver_api_key'] : $maskedKey;

template_top('Prosper202 ClickServer App Store');
?>

<div class="p202-page-header">
	<div class="p202-page-header__icon"><i class="bi bi-grid-3x3-gap"></i></div>
	<div class="p202-page-header__text">
		<h1 class="p202-page-header__title">App Store</h1>
		<p class="p202-page-header__desc">Apps and services that install into Prosper202 in one click.</p>
	</div>
</div>

<?php
echo p202_account_render_flashes($notice !== '' ? [['kind' => 'bad', 'text' => $notice]] : []);
if ($errors !== []) {
	echo p202_flash('bad', 'Nothing was saved. The sentence under the field says why.');
}
?>

<div class="row g-4">
	<div class="col-12 col-lg-8">
		<?php if ($apps === []) { ?>
			<div class="p202-empty">
				<i class="bi bi-grid-3x3-gap p202-empty__icon"></i>
				<strong class="p202-empty__title">The App Store is not available right now</strong>
				<div>The app feed did not answer. Please try again in a few minutes.</div>
				<div class="p202-empty__action"><a class="btn btn-secondary btn-sm" href="<?php echo $e(get_absolute_url() . '202-appstore/'); ?>">Try again</a></div>
			</div>
		<?php } else { ?>
			<div class="row g-3" id="appstore-apps">
				<?php foreach ($apps as $app) {
					[$pill, $label] = match ($app['status']) {
						'installed' => ['p202-pill p202-pill--good', 'Installed'],
						'coming-soon' => ['p202-pill', 'Coming soon'],
						'un-installed' => ['p202-pill p202-pill--accent', 'Install now'],
						default => ['p202-pill p202-pill--accent', $app['status'] !== '' ? $app['status'] : 'Learn more'],
					}; ?>
					<div class="col-12 col-md-6">
						<section class="p202-panel h-100">
							<div class="p202-panel__body d-flex gap-3">
								<?php if ($app['image'] !== null) { ?><img src="<?php echo $e($app['image']); ?>" alt="" width="48" height="48" class="flex-shrink-0"><?php } ?>
								<div class="flex-grow-1">
									<h2 class="h6 mb-1"><?php echo $e($app['title']); ?></h2>
									<div class="p202-toolbar mb-2">
										<span class="<?php echo $pill; ?>"><?php echo $e($label); ?></span>
										<?php if ($app['popular']) { ?><span class="p202-pill p202-pill--warn">Popular</span><?php } ?>
										<?php if ($app['price'] !== '') { ?><span class="small text-secondary"><?php echo $e($app['price']); ?></span><?php } ?>
									</div>
									<?php if ($app['description'] !== '') { ?><p class="small text-secondary mb-2"><?php echo $e($app['description']); ?></p><?php } ?>
									<?php if ($app['url'] !== null && $app['status'] !== 'coming-soon') { ?>
										<a class="btn btn-secondary btn-sm" href="<?php echo $e($app['url']); ?>" target="_blank" rel="noopener">Find out more</a>
									<?php } ?>
								</div>
							</div>
						</section>
					</div>
				<?php } ?>
			</div>
		<?php } ?>
	</div>
	<div class="col-12 col-lg-4">
		<section class="p202-panel">
			<div class="p202-panel__head"><h2 class="p202-panel__title">ClickServer API key</h2><span class="p202-panel__sub"><?php echo $savedKey === '' ? 'not set' : 'saved'; ?></span></div>
			<div class="p202-panel__body">
				<form method="post" action="" id="appstore-key">
					<?php echo p202_account_token_field(); ?>
					<div class="mb-3">
						<label class="form-label" for="clickserver_api_key">Your ClickServer API key</label>
						<input type="text" class="form-control font-monospace<?php echo isset($errors['clickserver_api_key']) ? ' is-invalid' : ''; ?>" id="clickserver_api_key" name="clickserver_api_key" value="<?php echo $e($typedKey); ?>" autocomplete="off" spellcheck="false">
						<div class="form-text">It activates the App Store. Never share it with anyone. Leave it empty to remove it.</div>
						<?php if (isset($errors['clickserver_api_key'])) { ?><div class="invalid-feedback d-block"><?php echo $e($errors['clickserver_api_key']); ?></div><?php } ?>
					</div>
					<div class="p202-form-actions">
						<button class="btn btn-primary" type="submit">Save API key</button>
					</div>
				</form>
			</div>
		</section>
	</div>
</div>
<?php template_bottom(); ?>
