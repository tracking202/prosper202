<?php
declare(strict_types=1);
include_once(str_repeat("../", 1).'202-config/connect.php');

AUTH::require_user();

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

/** Where to find help, [title, what it is, url, opens elsewhere]. */
$resources = [
	['Tracking202 tutorials', 'Step-by-step guides to every part of Prosper202.', 'http://support.tracking202.com/', true],
	['202 on YouTube', 'Walkthroughs and interviews.', 'https://youtube.com/t202nana', true],
	['Tracking202 videos', 'Short videos on setting up campaigns and reading reports.', 'http://tracking202.com/videos/', true],
	['Prosper202 blog', 'Release notes and tracking tips.', 'http://prosper.tracking202.com/blog/', true],
];
$attributionDocs = [
	['Advanced Attribution Engine', 'Setup guide.', 'docs.php?doc=attribution-engine'],
	['Attribution troubleshooting', 'When numbers look wrong, start here.', 'docs.php?doc=attribution-troubleshooting'],
	['Attribution API endpoints', 'API reference.', 'docs.php?doc=api-integrations'],
];

template_top('Help Resources', ['ui' => 'v2']);  ?>

<div class="p202-page-header">
	<div class="p202-page-header__icon"><i class="bi bi-question-circle"></i></div>
	<div class="p202-page-header__text">
		<h1 class="p202-page-header__title">Help</h1>
		<p class="p202-page-header__desc">Where to find help with Tracking202 and Prosper202.</p>
	</div>
	<div class="p202-page-header__actions">
		<a class="btn btn-primary" href="http://support.tracking202.com/" target="_blank" rel="noopener"><i class="bi bi-life-preserver"></i> Open the tutorials</a>
	</div>
</div>

<div class="row g-4">
	<div class="col-12 col-lg-7">
		<section class="p202-panel h-100" id="resources">
			<div class="p202-panel__head"><h2 class="p202-panel__title">Guides and videos</h2><span class="p202-panel__sub">open in a new tab</span></div>
			<div class="p202-panel__body">
				<div class="list-group">
					<?php foreach ($resources as [$title, $what, $url]) { ?>
						<a class="list-group-item list-group-item-action" href="<?php echo $e($url); ?>" target="_blank" rel="noopener">
							<strong class="d-block"><?php echo $e($title); ?> <i class="bi bi-box-arrow-up-right small text-secondary" aria-hidden="true"></i></strong>
							<span class="small text-secondary"><?php echo $e($what); ?> <?php echo $e($url); ?></span>
						</a>
					<?php } ?>
				</div>
			</div>
		</section>
	</div>
	<div class="col-12 col-lg-5">
		<section class="p202-panel h-100" id="attribution">
			<div class="p202-panel__head"><h2 class="p202-panel__title">Advanced attribution</h2><span class="p202-panel__sub">documentation that ships with this install</span></div>
			<div class="p202-panel__body">
				<div class="list-group">
					<?php foreach ($attributionDocs as [$title, $what, $url]) { ?>
						<a class="list-group-item list-group-item-action" href="<?php echo $e($url); ?>">
							<strong class="d-block"><?php echo $e($title); ?></strong>
							<span class="small text-secondary"><?php echo $e($what); ?></span>
						</a>
					<?php } ?>
				</div>
			</div>
		</section>
	</div>
</div>
<?php template_bottom();
