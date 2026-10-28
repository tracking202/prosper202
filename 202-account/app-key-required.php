<?php
declare(strict_types=1);
include_once(str_repeat("../", 1).'202-config/connect.php');

AUTH::require_user();

template_top('App Key Required');  ?>

<div class="p202-page-header">
	<div class="p202-page-header__icon"><i class="bi bi-key"></i></div>
	<div class="p202-page-header__text">
		<h1 class="p202-page-header__title">App key required</h1>
		<p class="p202-page-header__desc">The application you opened needs a valid Stats202 app key.</p>
	</div>
</div>

<div class="p202-empty">
	<i class="bi bi-key p202-empty__icon"></i>
	<strong class="p202-empty__title">This install has no Stats202 app key</strong>
	<div>Personal settings has no field for one any more, so it cannot be added here. Prosper202 support can connect the application for you.</div>
	<div class="p202-empty__action"><a class="btn btn-primary btn-sm" href="<?php echo htmlspecialchars(get_absolute_url() . '202-account/help.php', ENT_QUOTES, 'UTF-8'); ?>">Get help</a></div>
</div>

<?php template_bottom();
