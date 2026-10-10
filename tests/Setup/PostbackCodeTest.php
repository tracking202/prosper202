<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;
use Prosper202\Setup\PostbackCode;

/**
 * The pixels and postback URLs exist twice: Setup › Postback / Pixel's
 * script rebuilds them in the browser as the choices change (202-js/
 * p202-setup.js, postbackBuilder()), and PostbackCode builds them for the
 * page's defaults and for the REST API (GET /conversions/postback-code).
 * Two implementations of one string drift unless something compares them
 * (CLAUDE.md #24), so this runs the page's own script under node, against a
 * stand-in document (postback-builder-harness.js), and requires the class to
 * write what the script writes for the same choices.
 */
final class PostbackCodeTest extends TestCase
{
    private const ROOT_PATH = 'track.example.com/p202/tracking202/static/';

    /** The script's code-box ids => PostbackCode's [type, snippet]. */
    private const BOXES = [
        'unsecure_pixel' => ['simple', 'pixel'],
        'unsecure_postback' => ['simple', 'postback_url'],
        'unsecure_pixel_2' => ['advanced', 'pixel'],
        'unsecure_postback_2' => ['advanced', 'postback_url'],
        'unsecure_universal_pixel_js' => ['universal', 'javascript'],
        'unsecure_universal_pixel' => ['universal', 'iframe'],
    ];

    public function testTheDefaultsAreThePagesFirstRender(): void
    {
        $root = 'https://' . self::ROOT_PATH;
        $snippets = PostbackCode::snippets($root, '', '', '');
        self::assertSame('<img height="1" width="1" border="0" style="display: none;" src="' . $root . 'gpx.php?amount=&subid=" />', $snippets['simple']['pixel']);
        self::assertSame($root . 'gpb.php?amount=&subid=', $snippets['simple']['postback_url']);
        self::assertSame($root . 'gpb.php?amount=&cid=&subid=', $snippets['advanced']['postback_url']);
        self::assertSame('<iframe height="1" width="1" border="0" style="display: none;" frameborder="0" scrolling="no" src="' . $root . 'upx.php?amount=&subid=" seamless></iframe>', $snippets['universal']['iframe']);
        self::assertStringContainsString('var vars202={amount:"",cid:"",subid:""}', $snippets['universal']['javascript']);
    }

    /**
     * @return array<string, array{bool, string, string, string}>
     */
    public static function choices(): array
    {
        return [
            'the defaults, over http' => [false, '', '', ''],
            'a network macro and a campaign, over https' => [true, '{payout}', '42', '{aff_sub}'],
            'Cake and LinkTrust shapes' => [true, '12.50', '7', '#s2#'],
            'a LinkTrust token' => [false, '', '', '[=SID=]'],
        ];
    }

    /**
     * @dataProvider choices
     */
    public function testTheClassWritesWhatThePagesScriptWrites(bool $secure, string $amount, string $cid, string $subid): void
    {
        $node = self::node();
        $args = json_encode(['root_path' => self::ROOT_PATH, 'secure' => $secure, 'amount' => $amount, 'cid' => $cid, 'subid' => $subid], JSON_THROW_ON_ERROR);
        $command = escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/postback-builder-harness.js') . ' '
            . escapeshellarg(dirname(__DIR__, 2) . '/202-js/p202-setup.js') . ' ' . escapeshellarg($args) . ' 2>&1';
        exec($command, $lines, $status);
        $out = implode("\n", $lines);
        self::assertSame(0, $status, "the page's script ran under node: $out");
        $written = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($written);

        $snippets = PostbackCode::snippets(($secure ? 'https' : 'http') . '://' . self::ROOT_PATH, $amount, $cid, $subid);
        foreach (self::BOXES as $box => [$type, $snippet]) {
            self::assertArrayHasKey($box, $written, "the script wrote $box");
            self::assertSame($written[$box], $snippets[$type][$snippet], "$type $snippet is what the script writes into #$box");
        }
    }

    public function testValuesThatWouldBreakTheSnippetsAreRefused(): void
    {
        foreach (['', '12.50', '{payout}', '{aff_sub}', '#s2#', 'xxC1xx', '[=SID=]', '%subid1%', '{{conversion.value}}'] as $value) {
            self::assertNull(PostbackCode::problem($value), "$value is a value or a macro");
        }
        foreach (['a b', "a\tb", "a\nb", 'a"b', "a'b", 'a<b', 'a>b', 'a\\b', 'a&b=c', "a\x00", str_repeat('9', 256)] as $value) {
            self::assertNotNull(PostbackCode::problem($value), json_encode($value) . ' is refused');
        }
    }

    private static function node(): string
    {
        $found = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($found === '') {
            self::markTestSkipped('node is not installed; the page script cannot be run here');
        }

        return $found;
    }
}
