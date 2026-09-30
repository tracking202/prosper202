<?php
declare(strict_types=1);
include_once(str_repeat("../", 1).'202-config/connect.php');

AUTH::require_user();

template_top('PHP Safe Mode Is On');  ?>

<div class="p202-page-header">
	<div class="p202-page-header__icon"><i class="bi bi-shield-exclamation"></i></div>
	<div class="p202-page-header__text">
		<h1 class="p202-page-header__title">PHP safe mode is on</h1>
		<p class="p202-page-header__desc">The application you opened needs PHP safe mode turned off on this server.</p>
	</div>
</div>

<div class="p202-empty">
	<i class="bi bi-hdd-rack p202-empty__icon"></i>
	<strong class="p202-empty__title">Ask your web host to turn off PHP safe mode</strong>
	<div>It is a server setting, so it cannot be changed from Prosper202. Once it is off, open the application again.</div>
</div>

<?php template_bottom();
