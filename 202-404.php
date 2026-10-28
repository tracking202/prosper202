<?php
declare(strict_types=1);
include_once(__DIR__ . '/202-config/connect.php');

http_response_code(404);
info_top(['title' => 'Page not found - Prosper202 ClickServer']);
echo p202_standalone_card('Page not found', 'The link may have expired, or the address may be mistyped.');
?>
	<div class="d-grid gap-2">
		<a class="btn btn-primary" href="<?php echo htmlspecialchars(get_absolute_url(), ENT_QUOTES, 'UTF-8'); ?>">Go to Prosper202</a>
		<a class="btn btn-secondary" href="javascript:history.back();">Back to the previous page</a>
	</div>
<?php
echo p202_standalone_card_end();
info_bottom();
