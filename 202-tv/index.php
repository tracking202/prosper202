<?php

declare(strict_types=1);
include_once(str_repeat("../", 1) . '202-config/connect.php');
require_once dirname(__DIR__) . '/202-config/functions-feeds-ui.php';

AUTH::require_user();

$user_hash = $_SESSION['user_hash'] ?? '';
$user_cirrus_link = $_SESSION['user_cirrus_link'] ?? '';
$result = getData('https://my.tracking202.com/api/feeds/tv202?us=' . rawurlencode((string) $user_hash) . '&t202aid=' . rawurlencode((string) $user_cirrus_link));
$modules = p202_tv_modules(is_string($result) ? $result : '');
$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

template_top('Prosper202 ClickServer TV202', ['ui' => 'v2']);
?>

<div class="p202-page-header">
	<div class="p202-page-header__icon"><i class="bi bi-play-btn"></i></div>
	<div class="p202-page-header__text">
		<h1 class="p202-page-header__title">TV202</h1>
		<p class="p202-page-header__desc">Videos and tutorials to help you become a better internet marketer, updated often.</p>
	</div>
</div>

<?php if ($modules === []) { ?>
	<div class="p202-empty">
		<i class="bi bi-camera-video-off p202-empty__icon"></i>
		<strong class="p202-empty__title">TV202 is not available right now</strong>
		<div>The video feed did not answer. It usually comes back within a few minutes.</div>
		<div class="p202-empty__action"><a class="btn btn-secondary btn-sm" href="<?php echo $e(get_absolute_url() . '202-tv/'); ?>">Try again</a></div>
	</div>
<?php } else { ?>
	<div class="row g-4" id="tv202-modules">
		<?php foreach ($modules as $module) { ?>
			<div class="col-12 col-xl-6">
				<section class="p202-panel h-100">
					<div class="p202-panel__head"><h2 class="p202-panel__title"><?php echo $e($module['title']); ?></h2></div>
					<div class="p202-panel__body">
						<div class="ratio ratio-16x9 mb-3">
							<iframe src="<?php echo $e($module['embed']); ?>" title="<?php echo $e($module['title']); ?>" loading="lazy" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
						</div>
						<?php if ($module['description'] !== '') { ?><p class="text-secondary mb-0"><?php echo $e($module['description']); ?></p><?php } ?>
					</div>
				</section>
			</div>
		<?php } ?>
	</div>
<?php } ?>
<?php template_bottom(); ?>
