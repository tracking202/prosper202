<?php
declare(strict_types=1);

use Tracking202\Redirect\RedirectHelper;

require_once substr(dirname(__FILE__), 0, -21) . '/202-config/connect2.php';

$url = RedirectHelper::getStringParam('q');
if ($url === null) {
    RedirectHelper::redirect('/202-404.php');
}

$referrer = RedirectHelper::getStringParam('r') ?? '';

?>

<html>
	<head>
		<meta name="robots" content="noindex,nofollow">
		<meta name="referrer" content="<?php echo $referrer; ?>">
		<script>window.location='<?php echo $url; ?>';</script>
		<meta http-equiv="refresh" content="0; url=<?php echo $url; ?>">
	</head>
	<body>
		<div style="padding: 30px; text-align: center;">
			Page Stuck? <a href="<?php echo $url; ?>">Click Here</a>.
		</div>
	</body> 
</html> 