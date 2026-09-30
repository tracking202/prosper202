<?php
declare(strict_types=1);
include_once(str_repeat("../", 1).'202-config/connect.php');

AUTH::require_user();

template_top('API Key Required');  ?>

<div class="p202-page-header">
	<div class="p202-page-header__icon"><i class="bi bi-key"></i></div>
	<div class="p202-page-header__text">
		<h1 class="p202-page-header__title">API key required</h1>
		<p class="p202-page-header__desc">The application you opened needs a valid Prosper202 customer API key.</p>
	</div>
</div>

<div class="p202-empty">
	<i class="bi bi-key p202-empty__icon"></i>
	<strong class="p202-empty__title">Add your customer API key</strong>
	<div>Save it in Personal settings (it is checked with the customer dashboard as you save), then open the application again.</div>
	<div class="p202-empty__action"><a class="btn btn-primary btn-sm" href="<?php echo htmlspecialchars(get_absolute_url() . '202-account/account.php#customer-key', ENT_QUOTES, 'UTF-8'); ?>">Add the key</a></div>
</div>

<?php template_bottom();
