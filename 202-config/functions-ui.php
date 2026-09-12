<?php

declare(strict_types=1);

/**
 * Page-shell helpers: the pinned asset manifest and the asset lists of the two
 * page shells.
 *
 * Two shells coexist while the site migrates to Bootstrap 5:
 *
 *   classic  today's stack (Bootstrap 3, Flat UI Pro, jQuery 1.11 and the
 *            first-party CSS layers), unchanged except that every third-party
 *            file is served from this install at a pinned version.
 *   v2       Bootstrap 5.3 with the Prosper202 theme and component layer.
 *
 * A page picks its shell with template_top($title, ['ui' => 'v2']); pages that
 * pass nothing get the classic shell. The chrome (navbar, section tabs,
 * sub-menu, footer) is shared by both and styled by 202-css/p202-chrome.css,
 * which depends on neither framework.
 *
 * Everything here is a pure function of its arguments — no globals, no output —
 * so tests/Api/V3/ShellIsolationTest.php and AssetManifestTest.php can pin the
 * behaviour: the two shells never share a framework file, and every manifest
 * entry matches the bytes on disk.
 */

const P202_UI_CLASSIC = 'classic';
const P202_UI_V2 = 'v2';

/**
 * @return array<string, array<string, string>>
 */
function p202_asset_manifest(): array
{
    static $manifest = null;
    if ($manifest === null) {
        $loaded = require __DIR__ . '/assets.php';
        if (!is_array($loaded)) {
            throw new RuntimeException('202-config/assets.php must return an array');
        }
        $manifest = $loaded;
    }
    return $manifest;
}

/**
 * @return array<string, string>
 */
function p202_asset(string $id): array
{
    $manifest = p202_asset_manifest();
    if (!isset($manifest[$id])) {
        throw new InvalidArgumentException("Unknown asset '$id'; add it to 202-config/assets.php");
    }
    return $manifest[$id];
}

/**
 * The URL a page loads an asset from. Local files carry their version as a
 * query string so a version bump invalidates browser caches; external files
 * carry the version in their pinned URL already.
 */
function p202_asset_url(string $id, string $base): string
{
    $asset = p202_asset($id);
    if (isset($asset['url'])) {
        return $asset['url'];
    }
    return rtrim($base, '/') . '/' . ltrim($asset['path'], '/') . '?v=' . rawurlencode($asset['version']);
}

/**
 * The <link> or <script> tag for an asset, decided by its extension.
 */
function p202_asset_tag(string $id, string $base): string
{
    $asset = p202_asset($id);
    $location = $asset['url'] ?? $asset['path'];
    $extension = strtolower(pathinfo(parse_url($location, PHP_URL_PATH) ?: $location, PATHINFO_EXTENSION));
    $url = htmlspecialchars(p202_asset_url($id, $base), ENT_QUOTES, 'UTF-8');
    return match ($extension) {
        'css' => '<link rel="stylesheet" href="' . $url . '">',
        'js' => '<script src="' . $url . '"></script>',
        default => throw new InvalidArgumentException("Asset '$id' is not a stylesheet or script"),
    };
}

/**
 * Normalise the value a page passed as its shell.
 *
 * Unknown values are an error rather than a fallback: a typo in 'ui' must not
 * silently render the classic shell around Bootstrap 5 markup.
 */
function p202_ui_shell(mixed $requested): string
{
    if ($requested === null || $requested === '') {
        return P202_UI_CLASSIC;
    }
    if ($requested === P202_UI_CLASSIC || $requested === P202_UI_V2) {
        return $requested;
    }
    throw new InvalidArgumentException("Unknown ui shell '" . (is_scalar($requested) ? (string) $requested : gettype($requested)) . "'; use 'classic' or 'v2'");
}

/**
 * The ordered assets of a shell.
 *
 * Returns three ordered lists — 'css', 'js_head' (scripts every page needs,
 * loaded before the page's own extra head markup) and 'js_page' (scripts that
 * depend on the section or page, loaded after it). Each item is either
 * ['asset' => <manifest id>] or ['path' => <repo-relative first-party file>],
 * the latter optionally with a 'query' string.
 *
 * $context keys: section ($navigation[1]), sub ($navigation[2]),
 * page ($navigation[3]), logged_in (bool), ddlci (string, campaigns setup).
 *
 * @param array<string, mixed> $context
 * @return array{css: list<array<string, string>>, js_head: list<array<string, string>>, js_page: list<array<string, string>>}
 */
function p202_shell_assets(string $ui, array $context = []): array
{
    $ui = p202_ui_shell($ui);
    $section = (string) ($context['section'] ?? '');
    $sub = (string) ($context['sub'] ?? '');
    $page = (string) ($context['page'] ?? '');
    $loggedIn = (bool) ($context['logged_in'] ?? false);

    $isCampaignsSetup = $section === 'tracking202' && $sub === 'setup' && $page === 'aff_campaigns.php';
    $isAttributionDashboard = $section === '202-account' && $sub === 'attribution.php';
    $wantsCharts = $section === 'tracking202' || $isAttributionDashboard;

    if ($ui === P202_UI_V2) {
        $css = [
            ['path' => '202-css/p202-chrome.css'],
            ['asset' => 'bootstrap.css'],
            ['asset' => 'bootstrap-icons.css'],
            ['path' => '202-css/p202-theme.css'],
            ['path' => '202-css/p202-components.css'],
        ];
        if ($loggedIn) {
            $css[] = ['path' => '202-css/messenger.css'];
        }
        $jsHead = [
            ['asset' => 'jquery.js'],
            ['asset' => 'bootstrap.js'],
            ['path' => '202-js/tablesort.min.js'],
            ['path' => '202-js/list.min.js'],
            ['path' => '202-js/list.fuzzysearch.min.js'],
        ];
        $jsPage = [];
        if ($wantsCharts) {
            $jsPage[] = ['asset' => 'highcharts.js'];
            $jsPage[] = ['path' => '202-js/chart.theme.js'];
        }
        $jsPage[] = ['path' => '202-js/p202-ui.js'];
        $jsPage[] = ['path' => '202-js/p202-chrome.js'];
        return ['css' => $css, 'js_head' => $jsHead, 'js_page' => $jsPage];
    }

    $css = [
        ['path' => '202-css/css/bootstrap.min.css'],
        ['path' => '202-css/css/flat-ui-pro.min.css'],
        ['path' => '202-css/css/font-awesome.min.css'],
        ['path' => '202-css/css/bootstrap-tokenfield.min.css'],
        ['path' => '202-css/css/tokenfield-typeahead.min.css'],
    ];
    if ($isCampaignsSetup) {
        $css[] = ['asset' => 'legacy.tablesorter-pager.css'];
        $css[] = ['asset' => 'legacy.tablesorter-theme.css'];
    }
    $css[] = ['path' => '202-css/css/select2.css'];
    $css[] = ['path' => '202-css/custom.css'];
    $css[] = ['path' => '202-css/p202-ui.css'];
    $css[] = ['path' => '202-css/design-system.css'];
    // The chrome sheet loads last so it settles the navigation and page frame
    // over anything the three layers above still say about them.
    $css[] = ['path' => '202-css/p202-chrome.css'];
    if ($loggedIn) {
        $css[] = ['path' => '202-css/messenger.css'];
    }

    $jsHead = [
        ['asset' => 'legacy.jquery.js'],
        ['asset' => 'legacy.jquery-ui.js'],
        ['asset' => 'legacy.bootstrap.js'],
        ['path' => '202-js/fileinput.js'],
        ['path' => '202-js/radiocheck.js'],
        ['path' => '202-js/jquery.validate.min.js'],
        ['path' => '202-js/bootstrap-tokenfield.min.js'],
        ['path' => '202-js/typeahead.bundle.js'],
        ['path' => '202-js/tablesort.min.js'],
        ['path' => '202-js/list.min.js'],
        ['path' => '202-js/list.fuzzysearch.min.js'],
    ];

    $jsPage = [];
    if ($section === 'tracking202') {
        $jsPage[] = ['asset' => 'highcharts.js'];
        $jsPage[] = ['path' => '202-js/chart.theme.js'];
        if ($isCampaignsSetup) {
            $jsPage[] = ['asset' => 'legacy.tablesorter.js'];
            $jsPage[] = ['asset' => 'legacy.tablesorter-widgets.js'];
            $jsPage[] = ['asset' => 'legacy.tablesorter-pager.js'];
            $jsPage[] = ['path' => '202-js/dni.search.offers.tablesorter.php', 'query' => 'ddlci=' . rawurlencode((string) ($context['ddlci'] ?? ''))];
        }
    } elseif ($section === '202-account') {
        $jsPage[] = ['path' => '202-js/account.php'];
        if ($isAttributionDashboard) {
            $jsPage[] = ['asset' => 'highcharts.js'];
            $jsPage[] = ['path' => '202-js/chart.theme.js'];
            $jsPage[] = ['path' => '202-js/attribution.js'];
        }
    }
    $jsPage[] = ['asset' => 'legacy.select2.js'];
    $jsPage[] = ['path' => '202-js/custom.php'];
    $jsPage[] = ['path' => '202-js/p202-chrome.js'];

    return ['css' => $css, 'js_head' => $jsHead, 'js_page' => $jsPage];
}

/**
 * Render one shell asset item as its tag.
 *
 * @param array<string, string> $item
 */
function p202_shell_asset_tag(array $item, string $base): string
{
    if (isset($item['asset'])) {
        return p202_asset_tag($item['asset'], $base);
    }
    $path = ltrim($item['path'], '/');
    $url = rtrim($base, '/') . '/' . $path;
    if (isset($item['query']) && $item['query'] !== '') {
        $url .= '?' . $item['query'];
    }
    $url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $extension = strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?: $path, PATHINFO_EXTENSION));
    // First-party .php files under 202-js/ emit JavaScript.
    if ($extension === 'css') {
        return '<link rel="stylesheet" href="' . $url . '">';
    }
    return '<script src="' . $url . '"></script>';
}

/**
 * The inline Bootstrap Icons used by the shared chrome. Inline SVG so the
 * classic shell, which does not load the icon font, draws the same icons.
 * Paths are from Bootstrap Icons (MIT), 16x16 viewBox.
 */
function p202_chrome_icon(string $name): string
{
    static $paths = [
        'house' => 'M8.707 1.5a1 1 0 0 0-1.414 0L.646 8.146a.5.5 0 0 0 .708.708L2 8.207V13.5A1.5 1.5 0 0 0 3.5 15h9a1.5 1.5 0 0 0 1.5-1.5V8.207l.646.647a.5.5 0 0 0 .708-.708L13 5.793V2.5a.5.5 0 0 0-.5-.5h-1a.5.5 0 0 0-.5.5v1.293zM13 7.207V13.5a.5.5 0 0 1-.5.5h-9a.5.5 0 0 1-.5-.5V7.207l5-5z',
        'heart' => 'm8 2.748-.717-.737C5.6.281 2.514.878 1.4 3.053c-.523 1.023-.641 2.5.314 4.385.92 1.815 2.834 3.989 6.286 6.357 3.452-2.368 5.365-4.542 6.286-6.357.955-1.886.838-3.362.314-4.385C13.486.878 10.4.28 8.717 2.01zM8 15C-7.333 4.868 3.279-3.04 7.824 1.143q.09.083.176.171a3 3 0 0 1 .176-.17C12.72-3.042 23.333 4.867 8 15',
        'graph' => 'M0 0h1v15h15v1H0zm14.817 3.113a.5.5 0 0 1 .07.704l-4.5 5.5a.5.5 0 0 1-.74.037L7.06 6.767l-3.656 5.027a.5.5 0 0 1-.808-.588l4-5.5a.5.5 0 0 1 .758-.06l2.609 2.61 4.15-5.073a.5.5 0 0 1 .704-.07',
        'play' => 'M0 5a2 2 0 0 1 2-2h7.5a2 2 0 0 1 1.983 1.738l3.11-1.382A1 1 0 0 1 16 4.269v7.462a1 1 0 0 1-1.406.913l-3.111-1.382A2 2 0 0 1 9.5 13H2a2 2 0 0 1-2-2zm11.5 5.175 3.5 1.556V4.269l-3.5 1.556zM2 4a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h7.5a1 1 0 0 0 1-1V5a1 1 0 0 0-1-1z',
        'star' => 'M3.612 15.443c-.386.198-.824-.149-.746-.592l.83-4.73L.173 6.765c-.329-.314-.158-.888.283-.95l4.898-.696L7.538.792c.197-.39.73-.39.927 0l2.184 4.327 4.898.696c.441.062.612.636.282.95l-3.522 3.356.83 4.73c.078.443-.36.79-.746.592L8 13.187l-4.389 2.256z',
        'gear' => 'M8 4.754a3.246 3.246 0 1 0 0 6.492 3.246 3.246 0 0 0 0-6.492M5.754 8a2.246 2.246 0 1 1 4.492 0 2.246 2.246 0 0 1-4.492 0M9.796 1.343c-.527-1.79-3.065-1.79-3.592 0l-.094.319a.873.873 0 0 1-1.255.52l-.292-.16c-1.64-.892-3.433.902-2.54 2.541l.159.292a.873.873 0 0 1-.52 1.255l-.319.094c-1.79.527-1.79 3.065 0 3.592l.319.094a.873.873 0 0 1 .52 1.255l-.16.292c-.892 1.64.901 3.434 2.541 2.54l.292-.159a.873.873 0 0 1 1.255.52l.094.319c.527 1.79 3.065 1.79 3.592 0l.094-.319a.873.873 0 0 1 1.255-.52l.292.16c1.64.893 3.434-.902 2.54-2.541l-.159-.292a.873.873 0 0 1 .52-1.255l.319-.094c1.79-.527 1.79-3.065 0-3.592l-.319-.094a.873.873 0 0 1-.52-1.255l.16-.292c.893-1.64-.902-3.433-2.541-2.54l-.292.159a.873.873 0 0 1-1.255-.52zm-2.633.283c.246-.835 1.428-.835 1.674 0l.094.319a1.873 1.873 0 0 0 2.693 1.115l.291-.16c.764-.415 1.6.42 1.184 1.185l-.159.292a1.873 1.873 0 0 0 1.116 2.692l.318.094c.835.246.835 1.428 0 1.674l-.319.094a1.873 1.873 0 0 0-1.115 2.693l.16.291c.415.764-.42 1.6-1.185 1.184l-.291-.159a1.873 1.873 0 0 0-2.693 1.116l-.094.318c-.246.835-1.428.835-1.674 0l-.094-.319a1.873 1.873 0 0 0-2.692-1.115l-.292.16c-.764.415-1.6-.42-1.184-1.185l.159-.291A1.873 1.873 0 0 0 1.945 8.93l-.319-.094c-.835-.246-.835-1.428 0-1.674l.319-.094A1.873 1.873 0 0 0 3.06 4.377l-.16-.292c-.415-.764.42-1.6 1.185-1.184l.292.159a1.873 1.873 0 0 0 2.692-1.115z',
        'question' => 'M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14m0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16 M5.255 5.786a.237.237 0 0 0 .241.247h.825c.138 0 .248-.113.266-.25.09-.656.54-1.134 1.342-1.134.686 0 1.314.343 1.314 1.168 0 .635-.374.927-.965 1.371-.673.489-1.206 1.06-1.168 1.987l.003.217a.25.25 0 0 0 .25.246h.811a.25.25 0 0 0 .25-.25v-.105c0-.718.273-.927 1.01-1.486.609-.463 1.244-.977 1.244-2.056 0-1.511-1.276-2.241-2.673-2.241-1.267 0-2.655.59-2.75 2.286m1.557 5.763c0 .533.425.927 1.01.927.609 0 1.028-.394 1.028-.927 0-.552-.42-.94-1.029-.94-.584 0-1.009.388-1.009.94',
        'exit' => 'M10 12.5a.5.5 0 0 1-.5.5h-8a.5.5 0 0 1-.5-.5v-9a.5.5 0 0 1 .5-.5h8a.5.5 0 0 1 .5.5v2a.5.5 0 0 0 1 0v-2A1.5 1.5 0 0 0 9.5 2h-8A1.5 1.5 0 0 0 0 3.5v9A1.5 1.5 0 0 0 1.5 14h8a1.5 1.5 0 0 0 1.5-1.5v-2a.5.5 0 0 0-1 0z M15.854 8.354a.5.5 0 0 0 0-.708l-3-3a.5.5 0 0 0-.708.708L14.293 7.5H5.5a.5.5 0 0 0 0 1h8.793l-2.147 2.146a.5.5 0 0 0 .708.708z',
        'chevron' => 'M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708',
        'person' => 'M11 6a3 3 0 1 1-6 0 3 3 0 0 1 6 0 M0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8m8-7a7 7 0 0 0-5.468 11.37C3.242 11.226 4.805 10 8 10s4.757 1.225 5.468 2.37A7 7 0 0 0 8 1',
        'moon' => 'M6 .278a.77.77 0 0 1 .08.858 7.2 7.2 0 0 0-.878 3.46c0 4.021 3.278 7.277 7.318 7.277q.792-.001 1.533-.16a.79.79 0 0 1 .81.316.73.73 0 0 1-.031.893A8.35 8.35 0 0 1 8.344 16C3.734 16 0 12.286 0 7.71 0 4.266 2.114 1.312 5.124.06A.75.75 0 0 1 6 .278',
        'sun' => 'M8 11a3 3 0 1 1 0-6 3 3 0 0 1 0 6m0 1a4 4 0 1 0 0-8 4 4 0 0 0 0 8M8 0a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-1 0v-2A.5.5 0 0 1 8 0m0 13a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-1 0v-2A.5.5 0 0 1 8 13m8-5a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1 0-1h2a.5.5 0 0 1 .5.5M3 8a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1 0-1h2A.5.5 0 0 1 3 8m10.657-5.657a.5.5 0 0 1 0 .707l-1.414 1.415a.5.5 0 1 1-.707-.708l1.414-1.414a.5.5 0 0 1 .707 0m-9.193 9.193a.5.5 0 0 1 0 .707L3.05 13.657a.5.5 0 0 1-.707-.707l1.414-1.414a.5.5 0 0 1 .707 0m9.193 2.121a.5.5 0 0 1-.707 0l-1.414-1.414a.5.5 0 0 1 .707-.707l1.414 1.414a.5.5 0 0 1 0 .707M4.464 4.465a.5.5 0 0 1-.707 0L2.343 3.05a.5.5 0 1 1 .707-.707l1.414 1.414a.5.5 0 0 1 0 .708',
    ];
    if (!isset($paths[$name])) {
        throw new InvalidArgumentException("Unknown chrome icon '$name'");
    }
    return '<svg class="p202c-icon" aria-hidden="true" width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><path d="' . $paths[$name] . '"/></svg>';
}
