<?php

declare(strict_types=1);

/**
 * Attribution (multi-touch). The engine behind this page was rewritten
 * (measurement-rewrite plan §6); its dashboard is rebuilt on this shell in
 * PR 10. Until then the page says so plainly and shows what the engine has:
 * the account's models and the worker's backlog. Every number the old
 * dashboard drew is available now from the v3 API and both CLIs.
 */

include_once __DIR__ . '/../202-config/connect.php';

use Prosper202\Attribution\AttributionReports;
use Prosper202\Attribution\ModelRepository;
use Prosper202\Database\Connection;

AUTH::require_user();

global $userObj, $db;

if (!isset($userObj) || !$userObj->hasPermission('view_attribution_reports')) {
    header('location: ' . get_absolute_url() . '202-account/');
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$models = [];
$queue = null;
$loadError = null;
try {
    $conn = new Connection($db);
    $models = (new ModelRepository($conn))->rows($userId);
    $queue = (new AttributionReports($conn))->queue($userId, 5);
} catch (\Throwable $e) {
    error_log('attribution page: ' . $e->getMessage());
    $loadError = 'The attribution tables could not be read. Check that the upgrade finished, then reload.';
}

template_top('Attribution', ['ui' => 'v2']);
$h = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>

<div class="p202-page-header">
	<div class="p202-page-header__icon"><i class="bi bi-diagram-3"></i></div>
	<div class="p202-page-header__text">
		<h1 class="p202-page-header__title">Attribution</h1>
		<p class="p202-page-header__desc">How much credit each click in a visitor's journey gets for the conversion at its end, under each of your models.</p>
	</div>
</div>

<div class="alert alert-info p202-flash" role="status"><i class="bi bi-info-circle"></i><div class="p202-flash__body"><strong>This dashboard is being rebuilt.</strong> Credits are computed now, for every conversion, under every active model. Read them with <code>p202 attribution breakdown --group-by campaign</code>, or <code>GET /api/v3/attribution/reports/breakdown</code>.</div></div>

<?php if ($loadError !== null) { ?>
<div class="alert alert-danger p202-flash" role="alert"><i class="bi bi-exclamation-octagon"></i><div class="p202-flash__body"><?php echo $h($loadError); ?></div></div>
<?php } else { ?>
<div class="p202-panel mt-4">
	<div class="p202-panel__head"><h3 class="p202-panel__title">Models</h3><span class="p202-panel__sub">manage them with <code>p202 attribution model</code></span></div>
	<div class="p202-panel__body">
	<?php if ($models === []) { ?>
		<div class="p202-empty">
			<i class="bi bi-inbox p202-empty__icon"></i>
			<strong class="p202-empty__title">No models yet</strong>
			<div>Every account gets a last-touch default when the attribution worker first runs for it.</div>
		</div>
	<?php } else { ?>
		<div class="p202-table-wrap">
			<table class="table table-hover p202-table">
				<thead><tr><th>Model</th><th>Type</th><th class="num">Lookback</th><th>Status</th></tr></thead>
				<tbody>
				<?php foreach ($models as $m) { ?>
					<tr>
						<td><?php echo $h($m['model_name']); ?><?php if ((int) ($m['is_default'] ?? 0) === 1) { ?> <span class="p202-pill p202-pill--accent">default</span><?php } ?></td>
						<td><?php echo $h($m['model_type']); ?></td>
						<td class="num"><?php echo (int) $m['lookback_days']; ?> days</td>
						<td>
							<?php if ($m['status'] === 'active') { ?><span class="p202-pill p202-pill--good">active</span>
							<?php } elseif ($m['status'] === 'invalid') { ?><span class="p202-pill p202-pill--bad" title="<?php echo $h($m['status_reason']); ?>">invalid</span>
							<?php } else { ?><span class="p202-pill">inactive</span><?php } ?>
						</td>
					</tr>
				<?php } ?>
				</tbody>
			</table>
		</div>
	<?php } ?>
	</div>
</div>

<?php if ($queue !== null) { ?>
<div class="p202-panel mt-4">
	<div class="p202-panel__head"><h3 class="p202-panel__title">Worker</h3><span class="p202-panel__sub">conversions waiting to be attributed</span></div>
	<div class="p202-panel__body">
		<div class="p202-tiles">
			<div class="p202-tile"><div class="p202-tile__label">Waiting</div><div class="p202-tile__value"><?php echo (int) $queue['pending']; ?></div></div>
			<div class="p202-tile<?php echo (int) $queue['failing'] > 0 ? ' is-bad' : ''; ?>"><div class="p202-tile__label">Failing</div><div class="p202-tile__value"><?php echo (int) $queue['failing']; ?></div><div class="p202-tile__sub">see <code>p202 attribution queue</code></div></div>
		</div>
	</div>
</div>
<?php } ?>
<?php } ?>

<?php template_bottom();
