<?php
declare(strict_types=1);

use Tracking202\Redirect\RedirectHelper;

require_once substr(__DIR__, 0, -21) . '/202-config/connect2.php';

$url = RedirectHelper::getStringParam('q');
if ($url === null) {
    RedirectHelper::redirect('/202-404.php');
}

$referrer = RedirectHelper::getStringParam('r') ?? '';

?>

<html>
	<head>
		<meta name="robots" content="noindex,nofollow">
		<meta name="referrer" content="<?php echo htmlspecialchars((string) $referrer, ENT_QUOTES, 'UTF-8'); ?>">
		<script>window.location=<?php echo json_encode((string) $url, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;</script>
		<meta http-equiv="refresh" content="0; url=<?php echo htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8'); ?>">
	</head>
	<body>
		<div style="padding: 30px; text-align: center;">
			Page Stuck? <a href="<?php echo htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8'); ?>">Click Here</a>.
		</div>
	</body> 
</html> 