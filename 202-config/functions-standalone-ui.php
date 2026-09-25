<?php

declare(strict_types=1);

/**
 * The standalone shell (U7): the pages a person reaches before there is a
 * login, or outside the app's chrome — sign in, the password reset, the
 * installer and its wizard, the upgrader, the 404, and every _die() message.
 *
 * info_top() and info_bottom() are the same two calls those pages always
 * made; since U7 they render the v2 shell (Bootstrap 5.3, the Prosper202
 * theme and the component layer, the asset list p202_shell_assets() gives a
 * logged-out v2 page) around one centred column, with no navigation: there
 * is nothing to navigate to yet. The partner wallpaper behind the column and
 * the Google Publisher Tag on the sign-in page are the placements the
 * classic version carried, kept.
 *
 * The component is `.p202-standalone` in 202-css/p202-components.css, and
 * 202-account/ui-kit.php renders it; a page puts Bootstrap cards in the
 * column and the kit's parts in the cards.
 */

require_once __DIR__ . '/functions-ui.php';

/**
 * Open a standalone page.
 *
 * @param array{title?: string, wide?: bool, ads?: bool} $options
 *   title  the document title (default "Prosper202 ClickServer")
 *   wide   a wider column, for the installer's tables and forms
 *   ads    load the Google Publisher Tag for the sign-in page's slot
 */
function info_top(array $options = []): void
{
	$p202Base = get_absolute_url();
	$title = (string) ($options['title'] ?? 'Prosper202 ClickServer');
	$wide = !empty($options['wide']);
	$wp202 = getWallpaper();
	$assets = p202_shell_assets(P202_UI_V2, ['logged_in' => false]);
	$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
	$wallpaperImg = (string) ($wp202['wallpaperImg'] ?? '');
	$wallpaperUrl = (string) ($wp202['wallpaperUrl'] ?? '');
	// Only a web address reaches a style or an href: the wallpaper service
	// is remote, and a `javascript:` link or a `)` in a CSS url() is not an
	// image.
	$isWeb = static fn (string $url): bool => preg_match('~^https?://[^\s"\'()<>]+$~i', $url) === 1;
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo $e($title); ?></title>
	<link rel="shortcut icon" href="<?php echo $e($p202Base); ?>202-img/favicon.gif" type="image/ico">
	<script>
		/* Apply the saved or system theme before first paint so the page never flashes. */
		(function () {
			var theme = 'light';
			try {
				var saved = localStorage.getItem('p202-theme');
				if (saved === 'dark' || saved === 'light') {
					theme = saved;
				} else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
					theme = 'dark';
				}
			} catch (error) {}
			document.documentElement.setAttribute('data-bs-theme', theme);
		})();
	</script>
	<?php foreach ($assets['css'] as $item) { echo "\t" . p202_shell_asset_tag($item, $p202Base) . "\n"; } ?>
	<?php foreach ($assets['js_head'] as $item) { echo "\t" . p202_shell_asset_tag($item, $p202Base) . "\n"; } ?>
	<?php foreach ($assets['js_page'] as $item) { echo "\t" . p202_shell_asset_tag($item, $p202Base, true) . "\n"; } ?>
	<?php if (!empty($options['ads'])) { ?>
	<script>
		var googletag = googletag || {};
		googletag.cmd = googletag.cmd || [];
		(function() {
			var e = document.createElement("script");
			e.async = true;
			e.type = "text/javascript";
			var t = "https:" == document.location.protocol;
			e.src = (t ? "https:" : "http:") + "//www.googletagservices.com/tag/js/gpt.js";
			var n = document.getElementsByTagName("script")[0];
			n.parentNode.insertBefore(e, n)
		})();
		googletag.cmd.push(function() {
			googletag.defineSlot("/1006305/P202_CS_Login_Page_288x200", [288, 200], "div-gpt-ad-1398648278789-0").addService(googletag.pubads());
			googletag.pubads().enableSingleRequest();
			googletag.enableServices()
		});
	</script>
	<?php } ?>
</head>
<body class="p202-shell-<?php echo P202_UI_V2; ?> p202-standalone">
	<?php if ($isWeb($wallpaperImg) && $isWeb($wallpaperUrl)) { ?>
	<a class="p202-standalone__wallpaper" href="<?php echo $e($wallpaperUrl); ?>" target="_blank" rel="noopener" tabindex="-1" aria-hidden="true" style="background-image: url('<?php echo $e($wallpaperImg); ?>');"></a>
	<?php } ?>
	<main class="p202-standalone__main">
		<div class="p202-standalone__column<?php echo $wide ? ' p202-standalone__column--wide' : ''; ?>">
			<div class="p202-standalone__brand"><img src="<?php echo $e($p202Base); ?>202-img/prosper202.png" alt="Prosper202"></div>
<?php
}

/** Close a standalone page. */
function info_bottom(): void
{
?>
			<p class="p202-standalone__foot">Prosper202 ClickServer &middot; <a href="http://support.tracking202.com/" target="_blank" rel="noopener">Help</a></p>
		</div>
	</main>
</body>
</html>
<?php
}

/**
 * A standalone page's card: the kit's shape, a Bootstrap card with an
 * optional title and one-line description. Returns the opening markup; close
 * it with p202_standalone_card_end().
 */
function p202_standalone_card(string $title = '', string $description = ''): string
{
	$html = '<section class="card p202-standalone__card"><div class="card-body">';
	if ($title !== '') {
		$html .= '<h1 class="p202-standalone__title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
	}
	if ($description !== '') {
		$html .= '<p class="p202-standalone__desc">' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '</p>';
	}
	return $html;
}

function p202_standalone_card_end(): string
{
	return '</div></section>';
}

/**
 * A stored error as the sentence the user reads. The installer and the
 * password reset keep their errors as `<div class="error">…</div>` strings
 * (several may be appended to one key); each wrapper becomes one sentence.
 * Anything else between angle brackets is text, not markup.
 */
function p202_standalone_error_text(mixed $stored): string
{
	$text = (string) $stored;
	if ($text === '') {
		return '';
	}
	$text = preg_replace('~</div>\s*~i', "\n", $text) ?? $text;
	$text = preg_replace('~<div\b[^>]*>|</?small>|<span\b[^>]*></span>~i', '', $text) ?? $text;
	$text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
	$lines = array_values(array_filter(array_map('trim', explode("\n", $text)), static fn (string $l): bool => $l !== ''));
	return implode(' ', $lines);
}

/**
 * The server requirements table the installer's first step and the upgrader
 * both show: one row per requirement, with a pill saying how it stands.
 *
 * @param list<array{0: string, 1: string, 2: 'good'|'warn'|'bad'|'neutral', 3?: string}> $rows
 *   [requirement, what this server has, tone, optional note]
 */
function p202_standalone_requirements(array $rows): string
{
	$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
	$html = '<div class="p202-table-wrap"><table class="table p202-table" id="requirements">'
		. '<thead><tr><th>Software or function</th><th>This server</th></tr></thead><tbody>';
	foreach ($rows as $row) {
		[$label, $value, $tone] = $row;
		$pill = $tone === 'neutral' ? 'p202-pill' : 'p202-pill p202-pill--' . $tone;
		$html .= '<tr><td>' . $e($label) . (isset($row[3]) && $row[3] !== '' ? '<div class="small text-secondary">' . $e($row[3]) . '</div>' : '')
			. '</td><td><span class="' . $pill . '">' . $e($value) . '</span></td></tr>';
	}
	return $html . '</tbody></table></div>';
}

/**
 * The hosting partners list, from the remote feed or the fallback. Every
 * value is the feed's and is escaped; a link that is not a web address is
 * dropped rather than rendered.
 *
 * @param mixed $partners the decoded feed
 */
function p202_standalone_partners(mixed $partners): string
{
	if (!is_array($partners) || $partners === []) {
		$partners = [[
			'title' => 'Visit Official Hosting Partners',
			'description' => 'Get recommended hosting for Prosper202',
			'url' => 'https://my.tracking202.com/hosting',
			'thumb' => '',
		]];
	}
	$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
	$web = static fn (mixed $url): bool => is_string($url) && preg_match('~^https?://~i', $url) === 1;
	$html = '<div class="list-group">';
	foreach ($partners as $partner) {
		if (!is_array($partner) || !$web($partner['url'] ?? null)) {
			continue;
		}
		$html .= '<a class="list-group-item list-group-item-action d-flex gap-3 align-items-center" href="' . $e($partner['url']) . '" target="_blank" rel="noopener">';
		if ($web($partner['thumb'] ?? null)) {
			$html .= '<img src="' . $e($partner['thumb']) . '" alt="" width="48" height="48" class="rounded flex-shrink-0">';
		}
		$html .= '<span><strong class="d-block">' . $e($partner['title'] ?? '') . '</strong><span class="small text-secondary">' . $e($partner['description'] ?? '') . '</span></span></a>';
	}
	return $html . '</div>';
}

/**
 * The session token for a page that runs before 202-config.php exists — the
 * setup wizard — where connect.php, which normally starts the session and
 * mints the token, cannot load. Same name and cookie hardening as
 * connect.php's, so the token the wizard mints is the one the rest of the
 * install keeps. '' when the session cannot be started (headers already
 * sent), which p202_standalone_wizard_token_ok() then refuses.
 */
function p202_standalone_wizard_token(): string
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		if (headers_sent()) {
			return '';
		}
		$https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off');
		ini_set('session.cookie_httponly', '1');
		ini_set('session.cookie_samesite', 'Lax');
		ini_set('session.cookie_secure', $https ? '1' : '0');
		ini_set('session.use_strict_mode', '1');
		session_start();
	}
	if (!isset($_SESSION['token']) || !is_string($_SESSION['token']) || $_SESSION['token'] === '') {
		$_SESSION['token'] = bin2hex(random_bytes(16));
	}
	return $_SESSION['token'];
}

/**
 * Whether the posted token is the session's: constant-time, and false when
 * either side is empty (hash_equals('', '') is true), as
 * AUTH::check_csrf_token() does for the pages that can load connect.php.
 */
function p202_standalone_wizard_token_ok(): bool
{
	$sessionToken = (string) ($_SESSION['token'] ?? '');
	$postedToken = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
	if ($sessionToken === '' || $postedToken === '') {
		return false;
	}
	return hash_equals($sessionToken, $postedToken);
}
