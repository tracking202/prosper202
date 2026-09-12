<?php

declare(strict_types=1);
ob_start();

require_once __DIR__ . '/functions-ui.php';

/**
 * Render the standard Prosper202 page chrome.
 *
 * Historically template_top() accepted up to four loosely defined positional
 * arguments (title, meta description, keywords, body/body-class tweaks). Some
 * legacy pages – and the static analysers that scan them – still pass those
 * extra values. To remain backwards compatible we now accept a flexible
 * options payload while keeping the simple "just pass a title" usage working.
 *
 * Supported invocations:
 *   template_top();
 *   template_top('My Page Title');
 *   template_top('Title', 'Meta description', 'Meta keywords', 'extra-body-class');
 *   template_top('Title', ['meta_description' => '...', 'meta_keywords' => '...',
 *                          'body_class' => 'dashboard', 'body_id' => 'home',
 *                          'body_style' => 'background: #000;',
 *                          'extra_head' => '<link rel="...">',
 *                          'ui' => 'v2']);
 *
 * Two page shells exist while the site migrates to Bootstrap 5 (see
 * functions-ui.php): 'classic' (the default, today's stack) and 'v2'
 * (Bootstrap 5.3 with the Prosper202 theme and component layer). The chrome
 * around the page — navbar, section tabs, sub-menu, footer — is the same
 * markup for both and is styled by 202-css/p202-chrome.css, which depends on
 * neither framework. Add Bootstrap classes to the chrome markup in this file,
 * in tracking202/_config/top.php or in tracking202/_config/sub-menu.php and
 * one of the two shells breaks; tests/Api/V3/NoLegacyBootstrapClassesTest.php
 * checks for the Bootstrap 3 ones.
 *
 * Any unrecognised legacy positional arguments are ignored safely.
 *
 * @param string $title Page title (default: 'Prosper202 ClickServer')
 * @param mixed ...$legacyArgs Variable number of legacy arguments:
 *   - $legacyArgs[0]: string|array Meta description OR options array
 *   - $legacyArgs[1]: string Meta keywords (legacy format only)
 *   - $legacyArgs[2]: string Extra head content (legacy format only)
 *   - $legacyArgs[3]: string Body class (legacy format only)
 *   - $legacyArgs[4]: string Body ID (legacy format only)
 *   - $legacyArgs[5]: string Body style (legacy format only)
 * @return void
 * @since 1.0.0
 */
function template_top($title = 'Prosper202 ClickServer', ...$legacyArgs): void
{
	global $navigation;

	global $userObj;
	$user_data = [];
	if (!isset($_SESSION['publisher'])) {
		if (isset($_SESSION['user_id'])) {
			$user_data = get_user_data_feedback($_SESSION['user_id']);
		}
	}

	// Normalise primary argument to string for HTML output.
	$title = (string) $title;

	// Default page metadata / presentation.
	$metaDescription = 'description';
	$metaKeywords = 'keywords';
	$extraHeadMarkup = '';
	$bodyAttributes = [];
	// The page ground is painted by the chrome stylesheet; a page may still
	// pass its own body_style.
	$bodyStyle = '';

	$options = [];

		if ($legacyArgs !== []) {
		$firstArg = $legacyArgs[0] ?? null;
		if (is_array($firstArg)) {
			$options = $firstArg;
		} elseif ($firstArg !== null) {
			$options['meta_description'] = $firstArg;
		}

			if (isset($legacyArgs[1])) {
				$options['meta_keywords'] = $legacyArgs[1];
			}

			if (isset($legacyArgs[2])) {
				// Historically this slot was occasionally used for extra head markup.
				$options['extra_head'] = ($options['extra_head'] ?? '') . (string) $legacyArgs[2];
			}

			if (isset($legacyArgs[3])) {
				$options['body_class'] = $legacyArgs[3];
			}

		// Allow fifth & sixth positional arguments for completeness (e.g. body id/style)
			if (isset($legacyArgs[4])) {
				$options['body_id'] = $legacyArgs[4];
			}

			if (isset($legacyArgs[5])) {
				$options['body_style'] = $legacyArgs[5];
			}
		}

	$ui = p202_ui_shell($options['ui'] ?? null);
	$GLOBALS['p202_current_ui'] = $ui;

	if (isset($options['meta_description'])) {
		$metaDescription = (string) $options['meta_description'];
	}

	if (isset($options['meta_keywords'])) {
		$metaKeywords = (string) $options['meta_keywords'];
	}

	if (isset($options['extra_head'])) {
		$extraHeadMarkup = (string) $options['extra_head'];
	}

	// The shell, the section and the sub-section, so a stylesheet can scope
	// rules to a page family (custom.css scopes the setup pages' styles to
	// body.p202-sub-setup). Values come from the URL path, so they are slugged.
	$bodyClasses = ['p202-shell-' . $ui];
	$slug = static fn (string $part): string => trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) preg_replace('/\.php$/', '', $part))), '-');
	$sectionSlug = $slug((string) ($navigation[1] ?? ''));
	$subSlug = $slug((string) ($navigation[2] ?? ''));
	if ($sectionSlug !== '') {
		$bodyClasses[] = 'p202-section-' . $sectionSlug;
	}
	if ($subSlug !== '') {
		$bodyClasses[] = 'p202-sub-' . $subSlug;
	}
	if (isset($options['body_class'])) {
		$bodyClassValue = $options['body_class'];
		if (is_array($bodyClassValue)) {
			$bodyClassValue = implode(' ', array_filter(array_map(strval(...), $bodyClassValue)));
		}
		$bodyClassValue = trim((string) $bodyClassValue);
		if ($bodyClassValue !== '') {
			$bodyClasses[] = $bodyClassValue;
		}
	}
	$bodyAttributes[] = 'class="' . htmlspecialchars(implode(' ', $bodyClasses), ENT_QUOTES, 'UTF-8') . '"';

	if (isset($options['body_id'])) {
		$bodyId = trim((string) $options['body_id']);
		if ($bodyId !== '') {
			$bodyAttributes[] = 'id="' . htmlspecialchars($bodyId, ENT_QUOTES, 'UTF-8') . '"';
		}
	}

		if (isset($options['body_style'])) {
			$bodyStyle = (string) $options['body_style'];
		}

		if ($bodyStyle !== '') {
			$bodyAttributes[] = 'style="' . htmlspecialchars($bodyStyle, ENT_QUOTES, 'UTF-8') . '"';
		}
		$bodyAttributeString = ' ' . implode(' ', $bodyAttributes);

	$base = get_absolute_url();
	$assets = p202_shell_assets($ui, [
		'section' => $navigation[1] ?? '',
		'sub' => $navigation[2] ?? '',
		'page' => $navigation[3] ?? '',
		'logged_in' => !empty($_SESSION['user_id']),
		'ddlci' => (string) ($_GET['ddlci'] ?? ''),
	]);
?>

	<!DOCTYPE html>
	<html lang="en"<?php if ($ui === P202_UI_V2) { echo ' data-bs-theme="light"'; } ?>>

	<head>
		<meta charset="utf-8">
		<meta http-equiv="X-UA-Compatible" content="IE=edge">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title><?php echo $title; ?></title>
		<meta name="description" content="<?php echo htmlspecialchars($metaDescription, ENT_QUOTES, 'UTF-8'); ?>" />
		<meta name="keywords" content="<?php echo htmlspecialchars($metaKeywords, ENT_QUOTES, 'UTF-8'); ?>" />
		<meta name="copyright" content="Prosper202, Inc" />
		<meta name="author" content="Prosper202, Inc" />
		<meta name="MSSmartTagsPreventParsing" content="TRUE" />
		<meta name="robots" content="noindex, nofollow" />
		<meta http-equiv="imagetoolbar" content="no" />
		<link rel="shortcut icon" href="<?php echo $base; ?>202-img/favicon.gif" type="image/ico" />
		<?php if ($ui === P202_UI_V2) { ?>
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
		<?php } ?>
		<?php foreach ($assets['css'] as $item) { echo "\t\t" . p202_shell_asset_tag($item, $base) . "\n"; } ?>
		<?php foreach ($assets['js_head'] as $item) { echo "\t\t" . p202_shell_asset_tag($item, $base) . "\n"; } ?>
		<?php if ($extraHeadMarkup !== '') {
			echo $extraHeadMarkup;
		} ?>
		<?php foreach ($assets['js_page'] as $item) { echo "\t\t" . p202_shell_asset_tag($item, $base) . "\n"; } ?>
		<script>
			/* Attach the session token to same-origin POST requests so server-side
			   token checks succeed without modifying every individual caller. */
			(function () {
				var token = <?php echo json_encode($_SESSION['token'] ?? ''); ?>;
				if (!token || !window.jQuery) { return; }
				jQuery.ajaxPrefilter(function (options) {
					if ((options.type || options.method || 'GET').toUpperCase() !== 'POST') { return; }
					if (options.crossDomain) { return; }
					var d = options.data;
					if (window.FormData && d instanceof FormData) { return; }
					if (typeof d === 'string') {
						if (d.charAt(0) === '{' || d.charAt(0) === '[') { return; }
						if (/(^|&)token=/.test(d)) { return; }
						options.data = d + (d.length ? '&' : '') + 'token=' + encodeURIComponent(token);
					} else if (d && typeof d === 'object') {
						if (typeof d.token === 'undefined') { d.token = token; }
					} else {
						options.data = { token: token };
					}
				});
			})();
		</script>
		<script>
			window.addEventListener("message", function(e) {
				if (typeof e.data == 'number') {
					var frame = document.getElementById('adframe');
					if (frame) { frame.height = e.data + 'px'; }
				}
			}, false);
		</script>

	</head>

	<body<?php echo $bodyAttributeString; ?>>

		<div class="p202-frame">
			<?php echo p202_chrome_header($ui, is_array($navigation) ? $navigation : [], $userObj ?? null, $user_data, $base); ?>
			<div id="update_needed"></div>

			<?php if (($navigation[1] ?? '') == 'tracking202') {
				include_once(substr(__DIR__, 0, -10) . '/tracking202/_config/top.php');
			} ?>
			<div class="main">

				<?php if (($navigation[1] ?? '') == 'tracking202') {
					$nav2 = $navigation[2] ?? '';
					if (($nav2 == 'setup') or ($nav2 == 'bots') or ($nav2 == 'overview') or ($nav2 == 'analyze') or ($nav2 == 'update') or ($nav2 == 'export')) {
						include_once(substr(__DIR__, 0, -10) . '/tracking202/_config/sub-menu.php');
					}
				} ?>

			<?php }

/**
 * The shared header: the logo placement, primary navigation, account menu.
 *
 * The logo is the Prosper202 banner iframe, as the old navbar had it, so it
 * can change without a release. The static 202-img/prosper202.png that the
 * first draft of this header put beside it is gone: it was a second logo.
 *
 * Framework-neutral markup (see the class comment above); icons are inline
 * SVG so the classic shell, which loads no icon font, draws the same ones.
 *
 * @param array<int, string> $navigation
 * @param array<string, mixed> $userData
 */
function p202_chrome_header(string $ui, array $navigation, ?object $userObj, array $userData, string $base): string
{
	$nav1 = (string) ($navigation[1] ?? '');
	$nav2 = (string) ($navigation[2] ?? '');
	$can = static fn (string $permission): bool => $userObj !== null && method_exists($userObj, 'hasPermission') && $userObj->hasPermission($permission);
	$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
	$hasPerksBadge = !empty($userData['vip_perks_status']);

	$links = [
		['id' => 'HomePage', 'href' => '202-account/', 'icon' => 'house', 'label' => 'Home', 'active' => $nav1 === '202-account' && $nav2 === ''],
		['id' => 'ClickServerPage', 'href' => 'tracking202/', 'icon' => 'heart', 'label' => 'Prosper202 CS', 'active' => $nav1 === 'tracking202'],
	];
	if ($can('view_attribution_reports')) {
		$links[] = ['id' => 'AttributionPage', 'href' => '202-account/attribution.php', 'icon' => 'graph', 'label' => 'Attribution', 'active' => $nav1 === '202-account' && $nav2 === 'attribution.php'];
	}
	$links[] = ['id' => 'Tv202Page', 'href' => '202-tv/', 'icon' => 'play', 'label' => '<span class="p202c-nav__label--long">Watch </span>TV202', 'raw' => true, 'active' => $nav1 === '202-tv'];
	$links[] = ['id' => 'ResourcesPage', 'href' => '202-resources/', 'icon' => 'star', 'label' => 'Hot Deals<span class="p202c-nav__label--long"> &amp; Discounts</span>', 'raw' => true, 'active' => $nav1 === '202-resources'];

	$menu = [
		['id' => 'PersonalSettingsPage', 'href' => '202-account/account.php', 'label' => 'Personal Settings', 'active' => $nav2 === 'account.php'],
	];
	if ($can('access_to_vip_perks')) {
		$menu[] = ['id' => 'VIPPerksPage', 'href' => '202-account/vip-perks.php', 'label' => 'VIP Perks Profile', 'active' => $nav2 === 'vip-perks.php', 'badge' => $hasPerksBadge ? 'notification-perks' : null];
	}
	if ($can('access_to_api_integrations')) {
		$menu[] = ['id' => '3rdPartyAPIPage', 'href' => '202-account/api-integrations.php', 'label' => '3rd Party API Integrations', 'active' => $nav2 === 'api-integrations.php'];
	}
	if ($can('view_attribution_reports')) {
		$menu[] = ['id' => 'AttributionAnalyticsPage', 'href' => '202-account/attribution.php', 'label' => 'Attribution Analytics', 'active' => $nav2 === 'attribution.php'];
	}
	if ($can('add_users')) {
		$menu[] = ['id' => 'UserManagementPage', 'href' => '202-account/user-management.php', 'label' => 'User Management', 'active' => $nav2 === 'user-management.php'];
	}
	if ($can('access_to_settings')) {
		$menu[] = ['id' => 'SettingsPage', 'href' => '202-account/administration.php', 'label' => 'Settings', 'icon' => 'gear', 'active' => $nav2 === 'administration.php'];
	}
	$menu[] = ['id' => 'HelpPage', 'href' => '202-account/help.php', 'label' => 'Help', 'icon' => 'question', 'active' => $nav2 === 'help.php'];

	$html = '<header class="p202c-header"><div class="p202c-header__inner">';
	if (defined('TRACKING202_ADS_URL')) {
		$html .= '<div class="p202c-brand"><iframe class="advertise-top-left" src="' . $e(TRACKING202_ADS_URL . '/prosper202-cs-topleft/?t202aid=' . ($_SESSION['user_cirrus_link'] ?? '')) . '" scrolling="no" frameborder="0" title="Prosper202"></iframe></div>';
	}
	$html .= '<nav class="p202c-nav" aria-label="Primary">';
	foreach ($links as $link) {
		$label = !empty($link['raw']) ? $link['label'] : $e($link['label']);
		$html .= '<a class="p202c-nav__link' . ($link['active'] ? ' is-active' : '') . '" href="' . $e($base . $link['href']) . '" id="' . $e($link['id']) . '"' . ($link['active'] ? ' aria-current="page"' : '') . '>' . p202_chrome_icon($link['icon']) . '<span>' . $label . '</span></a>';
	}
	$html .= '</nav>';

	$html .= '<div class="p202c-account">';
	$html .= '<details class="p202c-menu' . (($nav1 === '202-account' && $nav2 !== '') ? ' is-active' : '') . '" id="account-dropdown">';
	$html .= '<summary class="p202c-menu__toggle">' . p202_chrome_icon('person') . '<span>My Account</span>';
	if ($hasPerksBadge) {
		$html .= '<span class="p202c-badge" id="notification">1</span>';
	}
	$html .= '<span class="p202c-menu__chevron">' . p202_chrome_icon('chevron') . '</span></summary>';
	$html .= '<ul class="p202c-menu__list">';
	foreach ($menu as $item) {
		$html .= '<li' . ($item['active'] ? ' class="active"' : '') . '><a href="' . $e($base . $item['href']) . '" id="' . $e($item['id']) . '">';
		if (!empty($item['icon'])) {
			$html .= p202_chrome_icon($item['icon']);
		}
		$html .= '<span>' . $e($item['label']) . '</span>';
		if (!empty($item['badge'])) {
			$html .= '<span class="p202c-badge" id="' . $e($item['badge']) . '">1</span>';
		}
		$html .= '</a></li>';
	}
	if ($ui === P202_UI_V2) {
		$html .= '<li class="p202c-menu__sep" role="separator"></li>';
		$html .= '<li><div class="p202c-theme" role="group" aria-label="Theme"><span>Theme</span>'
			. '<button type="button" data-theme-choice="system" aria-pressed="false" title="Follow the system setting">Auto</button>'
			. '<button type="button" data-theme-choice="light" aria-pressed="false" title="Light">' . p202_chrome_icon('sun') . '</button>'
			. '<button type="button" data-theme-choice="dark" aria-pressed="false" title="Dark">' . p202_chrome_icon('moon') . '</button>'
			. '</div></li>';
	}
	$html .= '</ul></details>';
	$html .= '<a class="p202c-nav__link" href="' . $e($base . '202-account/signout.php') . '" id="SignoutPage">' . p202_chrome_icon('exit') . '<span>Sign Out</span></a>';
	$html .= '</div>';

	$html .= '</div></header>';
	return $html;
}

			function template_bottom()
			{
				global $version;
				$ui = $GLOBALS['p202_current_ui'] ?? P202_UI_CLASSIC;
				$base = get_absolute_url();

				?>
				</div>


				<footer class="footer p202c-footer">
					Thank you for marketing with <a href="http://prosper202.com" target="_blank" rel="noopener">Prosper202</a>
					&middot;
					<a href="<?php echo $base; ?>202-account/help.php">Help</a>
					&middot;
					<a href="http://support.tracking202.com" target="_blank" rel="noopener">Documentation</a>
					&middot;

					<?php if (isset($_SESSION['update_needed']) && $_SESSION['update_needed'] == true) { ?>
						<strong class="p202c-footer__warn">Your Prosper202 ClickServer <?php echo $version; ?> is out of date. <a href="https://my.tracking202.com/api/customers/login" target="_blank" rel="noopener">Download New Version</a>.</strong>
					<?php } else { ?>
						Your Prosper202 ClickServer <?php echo $version; ?> is up to date.
					<?php } ?>

					<br>Local time: <?php echo date(DATE_RFC2822); ?>

					<br><a rel="license noopener" href="https://my.tracking202.com/license/" target="_blank">Copyright &copy; <?php echo date("Y") ?> Blue Terra LLC. All rights reserved</a>.
				</footer>
			</div>


		<script type="text/javascript">
			(function(i, s, o, g, r, a, m) {
				i['ProfitWellObject'] = r;
				i[r] = i[r] || function() {
					(i[r].q = i[r].q || []).push(arguments)
				}, i[r].l = 1 * new Date();
				a = s.createElement(o), m = s.getElementsByTagName(o)[0];
				a.async = 1;
				a.src = g;
				m.parentNode.insertBefore(a, m);
			})(window, document, 'script', 'https://dna8twue3dlxq.cloudfront.net/js/profitwell.js', 'profitwell');
			profitwell('auth_token', '574889f9aff2755319487e8819d11658');
			profitwell('user_email', '<?php echo getDashEmail(); ?>');
		</script>


		<?php
				// The VIP Perks survey is Bootstrap 3 markup driven by the classic
				// shell's jQuery; the v2 shell gets its own when the account pages migrate.
				if ($ui === P202_UI_CLASSIC && (!isset($_SESSION['publisher']) || $_SESSION['publisher'] !== true)) {
					include __DIR__ . '/template-parts/survey-modal-classic.php';
				}
		?>
		<script type="text/javascript">
			window.addEventListener('load', function() {
				window.setTimeout(function() {
					if (navigator.sendBeacon) {
						navigator.sendBeacon("//<?php echo getTrackingDomain() . get_absolute_url(); ?>202-cronjobs/");
					}
				}, 3000);
			});
		</script>
		<?php if (!empty($_SESSION['user_id'])) { ?>
			<!-- Prosper202 Messenger: config + command-queue stub + widget -->
			<script type="text/javascript">
				window.P202M_CONFIG = {
					base: <?php echo json_encode(get_absolute_url()); ?>,
					token: <?php echo json_encode($_SESSION['token'] ?? ''); ?>
				};
				/* Buffer any early Prosper202Messenger(...) calls until the widget loads. */
				window.Prosper202Messenger = window.Prosper202Messenger || function () {
					(window.Prosper202Messenger.q = window.Prosper202Messenger.q || []).push(arguments);
				};
			</script>
			<script type="text/javascript" src="<?php echo get_absolute_url(); ?>202-js/messenger.js"></script>
		<?php } ?>
	</body>
	</html>
<?php }
