<?php

declare(strict_types=1);

namespace Tests\Standalone;

use PHPUnit\Framework\TestCase;

/**
 * The helpers behind the standalone pages and the three feed sections (U7):
 * 202-config/functions-standalone-ui.php and functions-feeds-ui.php.
 *
 * The feed readers are the ones that decide what remote content reaches a
 * page, so they are held to "nothing remote is markup": a link that is not
 * a web address is dropped, a video that is not a YouTube embed is dropped,
 * and a feed that cannot be read gives no rows rather than an empty shell.
 */
final class StandaloneUiTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2);
        require_once dirname(__DIR__, 2) . '/202-config/functions-standalone-ui.php';
        require_once dirname(__DIR__, 2) . '/202-config/functions-feeds-ui.php';
    }

    public function testAStoredErrorBecomesItsSentences(): void
    {
        self::assertSame('No key was found like that', p202_standalone_error_text('<div class="error">No key was found like that</div>'));
        self::assertSame(
            'You must type in your desired password You must verify your password',
            p202_standalone_error_text('<div class="error">You must type in your desired password</div><div class="error">You must verify your password</div>')
        );
        self::assertSame('Sorry, this key has expired.', p202_standalone_error_text('Sorry, this key has expired.'));
        self::assertSame('a < b & c', p202_standalone_error_text('a &lt; b &amp; c'));
        self::assertSame('', p202_standalone_error_text(null));
    }

    public function testTheCardIsTheKitsAndEscapes(): void
    {
        $html = p202_standalone_card('Sign <in>', 'to "it"') . p202_standalone_card_end();
        self::assertStringContainsString('<section class="card p202-standalone__card"><div class="card-body">', $html);
        self::assertStringContainsString('<h1 class="p202-standalone__title">Sign &lt;in&gt;</h1>', $html);
        self::assertStringContainsString('<p class="p202-standalone__desc">to &quot;it&quot;</p>', $html);
        self::assertStringEndsWith('</div></section>', $html);
        self::assertStringNotContainsString('p202-standalone__title', p202_standalone_card());
    }

    public function testRequirementsRowsCarryTheirTone(): void
    {
        $html = p202_standalone_requirements([
            ['PHP >= 8.3', '8.4.1', 'good'],
            ['Memcache', 'Missing', 'warn', 'Recommended'],
            ['CURL', '<script>', 'bad'],
            ['Partitioning', 'Disabled', 'neutral'],
        ]);
        self::assertStringContainsString('<span class="p202-pill p202-pill--good">8.4.1</span>', $html);
        self::assertStringContainsString('<span class="p202-pill p202-pill--warn">Missing</span>', $html);
        self::assertStringContainsString('<div class="small text-secondary">Recommended</div>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('<span class="p202-pill">Disabled</span>', $html);
    }

    public function testPartnersKeepOnlyWebLinks(): void
    {
        $html = p202_standalone_partners([
            ['title' => 'Good host', 'description' => 'Fast', 'url' => 'https://host.example/', 'thumb' => 'https://host.example/t.png'],
            ['title' => 'Bad host', 'description' => 'x', 'url' => 'javascript:alert(1)', 'thumb' => ''],
            ['title' => '<b>Loud</b>', 'description' => 'y', 'url' => 'http://loud.example/', 'thumb' => 'javascript:x'],
            'not a partner',
        ]);
        self::assertStringContainsString('href="https://host.example/"', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringContainsString('&lt;b&gt;Loud&lt;/b&gt;', $html);
        self::assertSame(1, substr_count($html, '<img'), 'a thumbnail that is not a web address is dropped');
        self::assertStringContainsString('https://my.tracking202.com/hosting', p202_standalone_partners(null), 'an unreadable feed falls back to the partners page');
    }

    public function testTv202ReadsModulesAndDropsWhatIsNotAYouTubeEmbed(): void
    {
        $feed = <<<'HTML'
<div class="col-xs-12"><h6>Welcome To TV202</h6><small>Intro</small></div>
<div class="col-xs-12">
  <div>
    <div><h6>Module 1: Research &amp; Intel</h6></div>
    <div><iframe width="853" height="480" src="https://www.youtube.com/embed/Oz2YEgDLs2A?list=PLWZ" frameborder="0" allowfullscreen></iframe></div>
    <div><p>The first module.</p></div>
  </div>
  <div>
    <div><h6>Evil</h6></div>
    <div><iframe src="https://evil.example/embed/x"></iframe></div>
  </div>
  <div>
    <div><h6>Also evil</h6></div>
    <div><iframe src="javascript:alert(1)"></iframe></div>
  </div>
  <div>
    <div><h6>Module 4</h6></div>
    <div><iframe src="https://www.youtube.com/embed/videoseries?list=PLx_y-9"></iframe></div>
  </div>
</div>
HTML;
        $modules = p202_tv_modules($feed);
        self::assertCount(2, $modules);
        self::assertSame(['title' => 'Module 1: Research & Intel', 'embed' => 'https://www.youtube.com/embed/Oz2YEgDLs2A?list=PLWZ', 'description' => 'The first module.'], $modules[0]);
        self::assertSame('Module 4', $modules[1]['title']);
        self::assertSame('', $modules[1]['description']);
        self::assertSame([], p202_tv_modules(''));
        self::assertSame([], p202_tv_modules('Sorry, not found'));
    }

    public function testResourceDealsNeedATitleAndAWebLink(): void
    {
        $deals = p202_resource_deals(['deals' => [
            ['title' => 'Host', 'deal-url' => 'http://click.example/?a=1', 'deal-img' => 'http://img.example/a.jpg', 'deal-description' => '<b>Fast</b> hosting', 'deal-coupon' => 'Use HOT'],
            ['title' => 'No link', 'deal-url' => 'javascript:alert(1)'],
            ['title' => '', 'deal-url' => 'https://x.example/'],
            ['name' => 'Legacy keys', 'url' => 'https://legacy.example/', 'image' => 'data:image/png;base64,xx'],
            'junk',
        ]]);
        self::assertCount(2, $deals);
        self::assertSame(['title' => 'Host', 'image' => 'http://img.example/a.jpg', 'description' => 'Fast hosting', 'url' => 'http://click.example/?a=1', 'coupon' => 'Use HOT'], $deals[0]);
        self::assertNull($deals[1]['image'], 'an image that is not a web address is dropped');
        self::assertSame([], p202_resource_deals(null));
        self::assertSame([], p202_resource_deals(['deals' => 'nope']));
    }

    public function testAppStoreResolvesItsOwnIconsAndReadsTheFeedsSpellings(): void
    {
        $apps = p202_appstore_apps(['deals' => [
            ['title' => 'DataEngine', 'app-url' => 'http://click.example/1', 'app-img' => '/202-img/new/icons/rocket.svg', 'app-description' => '', 'app-descriptions' => 'Fast reports', 'app-install' => 'installed', 'app-statusa' => 'popular', 'app-price' => 'No Extra Cost'],
            ['title' => 'Sneaky', 'app-url' => 'javascript:x', 'app-img' => '/202-img/../202-config.php', 'app-install' => 'coming-soon'],
        ]], '/p202/');
        self::assertSame('/p202/202-img/new/icons/rocket.svg', $apps[0]['image']);
        self::assertSame('Fast reports', $apps[0]['description'], "the feed's second spelling of the description is read");
        self::assertTrue($apps[0]['popular']);
        self::assertNull($apps[1]['url']);
        self::assertNull($apps[1]['image'], 'a path that climbs out of 202-img is not an icon');
        self::assertSame([], p202_appstore_apps(false, '/'));
    }

    /**
     * Every page on the standalone shell names itself in <title>. The
     * upgrader's form was the one bare info_top() left (#169), so the tab
     * read the generic name while its sibling states said "Upgrade". The
     * one call without a title is _die(), whose message is the page.
     */
    public function testEveryStandalonePageNamesItsTitle(): void
    {
        $root = dirname(__DIR__, 2);
        $bare = [];
        $calls = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if ($file->getExtension() !== 'php' || preg_match('#^(vendor|tests|\.git|\.claude)/#', $relative) === 1) {
                continue;
            }
            $tokens = array_values(array_filter(\PhpToken::tokenize((string) file_get_contents($file->getPathname())), static fn (\PhpToken $t): bool => !$t->isIgnorable()));
            foreach ($tokens as $i => $token) {
                if ($token->text !== 'info_top' || ($tokens[$i + 1]->text ?? '') !== '(' || ($tokens[$i - 1] ?? null)?->is(T_FUNCTION)) {
                    continue;
                }
                $calls++;
                $titled = ($tokens[$i + 2]->text ?? '') === '[' && in_array($tokens[$i + 3]->text ?? '', ["'title'", '"title"'], true);
                if (!$titled && !($relative === '202-config/functions.php' && ($tokens[$i + 2]->text ?? '') === ')')) {
                    $bare[] = "$relative:{$token->line}";
                }
            }
        }
        self::assertGreaterThan(8, $calls, 'the scan found the standalone pages');
        self::assertSame([], $bare, 'info_top() without a title');
    }

    /**
     * The license key the installer reads travels in a cookie the page sets
     * in script; on HTTPS it is Secure (#173), as every session cookie is.
     */
    public function testTheLicenseKeyCookieIsSecureOnHttps(): void
    {
        $page = (string) file_get_contents(dirname(__DIR__, 2) . '/202-config/get_apikey.php');
        self::assertSame(1, substr_count($page, "document.cookie = 'user_api='"), 'one place sets it');
        self::assertStringContainsString("+ '; SameSite=Lax' + (window.location.protocol === 'https:' ? '; Secure' : '');", $page);
    }
}
