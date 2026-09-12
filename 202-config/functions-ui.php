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
 * the latter optionally with a 'query' string. A third-party file is always
 * a manifest id — tests/Api/V3/ShellIsolationTest.php refuses a bare path
 * that is not one of the first-party files it names.
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
        ['asset' => 'legacy.bootstrap.css'],
        ['asset' => 'legacy.flat-ui.css'],
        ['asset' => 'legacy.font-awesome.css'],
        ['asset' => 'legacy.tokenfield.css'],
        ['asset' => 'legacy.tokenfield-typeahead.css'],
    ];
    if ($isCampaignsSetup) {
        $css[] = ['asset' => 'legacy.tablesorter-pager.css'];
        $css[] = ['asset' => 'legacy.tablesorter-theme.css'];
    }
    $css[] = ['asset' => 'legacy.select2.css'];
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
        ['asset' => 'legacy.fileinput.js'],
        ['asset' => 'legacy.radiocheck.js'],
        ['asset' => 'legacy.jquery-validate.js'],
        ['asset' => 'legacy.tokenfield.js'],
        ['asset' => 'legacy.typeahead.js'],
        ['asset' => 'tablesort.js'],
        ['asset' => 'list.js'],
        ['asset' => 'list-fuzzysearch.js'],
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
 * The inline Bootstrap Icons used by the shared chrome (header, account menu,
 * setup sub-menu). Inline SVG so the classic shell, which does not load the
 * icon font, draws the same icons. Paths are from Bootstrap Icons (MIT), 16x16
 * viewBox: house, heart, graph (bar-chart-line), play (tv), star, gear,
 * question (question-circle), exit (box-arrow-right), chevron (chevron-down),
 * person (person-circle), moon (moon-stars), sun; and for the setup sub-menu
 * globe, grid, link (link-45deg), file (file-earmark), fonts, repeat
 * (arrow-repeat), chart (bar-chart), terminal, transfer (arrow-left-right),
 * phone.
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
        'globe' => 'M0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8m7.5-6.923c-.67.204-1.335.82-1.887 1.855A8 8 0 0 0 5.145 4H7.5zM4.09 4a9.3 9.3 0 0 1 .64-1.539 7 7 0 0 1 .597-.933A7.03 7.03 0 0 0 2.255 4zm-.582 3.5c.03-.877.138-1.718.312-2.5H1.674a7 7 0 0 0-.656 2.5zM4.847 5a12.5 12.5 0 0 0-.338 2.5H7.5V5zM8.5 5v2.5h2.99a12.5 12.5 0 0 0-.337-2.5zM4.51 8.5a12.5 12.5 0 0 0 .337 2.5H7.5V8.5zm3.99 0V11h2.653c.187-.765.306-1.608.338-2.5zM5.145 12q.208.58.468 1.068c.552 1.035 1.218 1.65 1.887 1.855V12zm.182 2.472a7 7 0 0 1-.597-.933A9.3 9.3 0 0 1 4.09 12H2.255a7 7 0 0 0 3.072 2.472M3.82 11a13.7 13.7 0 0 1-.312-2.5h-2.49c.062.89.291 1.733.656 2.5zm6.853 3.472A7 7 0 0 0 13.745 12H11.91a9.3 9.3 0 0 1-.64 1.539 7 7 0 0 1-.597.933M8.5 12v2.923c.67-.204 1.335-.82 1.887-1.855q.26-.487.468-1.068zm3.68-1h2.146c.365-.767.594-1.61.656-2.5h-2.49a13.7 13.7 0 0 1-.312 2.5m2.802-3.5a7 7 0 0 0-.656-2.5H12.18c.174.782.282 1.623.312 2.5zM11.27 2.461c.247.464.462.98.64 1.539h1.835a7 7 0 0 0-3.072-2.472c.218.284.418.598.597.933M10.855 4a8 8 0 0 0-.468-1.068C9.835 1.897 9.17 1.282 8.5 1.077V4z',
        'grid' => 'M1 2.5A1.5 1.5 0 0 1 2.5 1h3A1.5 1.5 0 0 1 7 2.5v3A1.5 1.5 0 0 1 5.5 7h-3A1.5 1.5 0 0 1 1 5.5zM2.5 2a.5.5 0 0 0-.5.5v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3a.5.5 0 0 0-.5-.5zm6.5.5A1.5 1.5 0 0 1 10.5 1h3A1.5 1.5 0 0 1 15 2.5v3A1.5 1.5 0 0 1 13.5 7h-3A1.5 1.5 0 0 1 9 5.5zm1.5-.5a.5.5 0 0 0-.5.5v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3a.5.5 0 0 0-.5-.5zM1 10.5A1.5 1.5 0 0 1 2.5 9h3A1.5 1.5 0 0 1 7 10.5v3A1.5 1.5 0 0 1 5.5 15h-3A1.5 1.5 0 0 1 1 13.5zm1.5-.5a.5.5 0 0 0-.5.5v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3a.5.5 0 0 0-.5-.5zm6.5.5A1.5 1.5 0 0 1 10.5 9h3a1.5 1.5 0 0 1 1.5 1.5v3a1.5 1.5 0 0 1-1.5 1.5h-3A1.5 1.5 0 0 1 9 13.5zm1.5-.5a.5.5 0 0 0-.5.5v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3a.5.5 0 0 0-.5-.5z',
        'link' => 'M4.715 6.542 3.343 7.914a3 3 0 1 0 4.243 4.243l1.828-1.829A3 3 0 0 0 8.586 5.5L8 6.086a1 1 0 0 0-.154.199 2 2 0 0 1 .861 3.337L6.88 11.45a2 2 0 1 1-2.83-2.83l.793-.792a4 4 0 0 1-.128-1.287z M6.586 4.672A3 3 0 0 0 7.414 9.5l.775-.776a2 2 0 0 1-.896-3.346L9.12 3.55a2 2 0 1 1 2.83 2.83l-.793.792c.112.42.155.855.128 1.287l1.372-1.372a3 3 0 1 0-4.243-4.243z',
        'file' => 'M14 4.5V14a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V2a2 2 0 0 1 2-2h5.5zm-3 0A1.5 1.5 0 0 1 9.5 3V1H4a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V4.5z',
        'fonts' => 'M12.258 3h-8.51l-.083 2.46h.479c.26-1.544.758-1.783 2.693-1.845l.424-.013v7.827c0 .663-.144.82-1.3.923v.52h4.082v-.52c-1.162-.103-1.306-.26-1.306-.923V3.602l.431.013c1.934.062 2.434.301 2.693 1.846h.479z',
        'repeat' => 'M11.534 7h3.932a.25.25 0 0 1 .192.41l-1.966 2.36a.25.25 0 0 1-.384 0l-1.966-2.36a.25.25 0 0 1 .192-.41m-11 2h3.932a.25.25 0 0 0 .192-.41L2.692 6.23a.25.25 0 0 0-.384 0L.342 8.59A.25.25 0 0 0 .534 9 M8 3c-1.552 0-2.94.707-3.857 1.818a.5.5 0 1 1-.771-.636A6.002 6.002 0 0 1 13.917 7H12.9A5 5 0 0 0 8 3M3.1 9a5.002 5.002 0 0 0 8.757 2.182.5.5 0 1 1 .771.636A6.002 6.002 0 0 1 2.083 9z',
        'chart' => 'M4 11H2v3h2zm5-4H7v7h2zm5-5v12h-2V2zm-2-1a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h2a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1zM6 7a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1zm-5 4a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1z',
        'terminal' => 'M6 9a.5.5 0 0 1 .5-.5h3a.5.5 0 0 1 0 1h-3A.5.5 0 0 1 6 9M3.854 4.146a.5.5 0 1 0-.708.708L4.793 6.5 3.146 8.146a.5.5 0 1 0 .708.708l2-2a.5.5 0 0 0 0-.708z M2 1a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V3a2 2 0 0 0-2-2zm12 1a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z',
        'phone' => 'M11 1a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1zM5 0a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2z M8 14a1 1 0 1 0 0-2 1 1 0 0 0 0 2',
        'transfer' => 'M1 11.5a.5.5 0 0 0 .5.5h11.793l-3.147 3.146a.5.5 0 0 0 .708.708l4-4a.5.5 0 0 0 0-.708l-4-4a.5.5 0 0 0-.708.708L13.293 11H1.5a.5.5 0 0 0-.5.5m14-7a.5.5 0 0 1-.5.5H2.707l3.147 3.146a.5.5 0 1 1-.708.708l-4-4a.5.5 0 0 1 0-.708l4-4a.5.5 0 1 1 .708.708L2.707 4H14.5a.5.5 0 0 1 .5.5',
    ];
    if (!isset($paths[$name])) {
        throw new InvalidArgumentException("Unknown chrome icon '$name'");
    }
    return '<svg class="p202c-icon" aria-hidden="true" width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><path d="' . $paths[$name] . '"/></svg>';
}
