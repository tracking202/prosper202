<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;

/**
 * lp.php finds a held-back visitor's click by the p202lpid on the redirect
 * link itself (LandingPageVisitorClick). By the time it asks, $_GET holds
 * the landing page's query, parsed from the referer, so a page reached with
 * ad parameters would read no id there: the link's own query is $vars, kept
 * before the referer replaces $_GET. lpc.php is requested by lp.php with the
 * id on its own URL, and reads $_GET.
 */
final class LandingPageVisitorClickReadsTheLinkTest extends TestCase
{
    private static function code(string $file): string
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);

        return (string) preg_replace('/\s+/', '', $src);
    }

    public function testLpPhpReadsTheLinksOwnQuery(): void
    {
        $code = self::code('tracking202/redirect/lp.php');
        $keep = strpos($code, '$vars=$_GET;');
        $replace = strpos($code, 'parse_str($landing_page_query,$_GET);');
        $find = strpos($code, '\Prosper202\Click\LandingPageVisitorClick::find(');
        self::assertIsInt($keep, 'lp.php keeps the link\'s query as $vars');
        self::assertIsInt($replace, 'lp.php replaces $_GET with the referer\'s query');
        self::assertIsInt($find);
        self::assertLessThan($replace, $keep);
        self::assertStringContainsString(
            '(string)$lpPublicId,$vars,$tracker_row,time())',
            substr($code, $find, 200),
            'the visitor id is read from the link ($vars), not the referer\'s query ($_GET)'
        );
        self::assertSame(1, substr_count($code, '$vars=$_GET;'), '$vars is set once, before anything replaces $_GET');
    }

    public function testTheCloakedHopReadsTheIdLpPhpHandsIt(): void
    {
        $lp = self::code('tracking202/redirect/lp.php');
        self::assertStringContainsString("\$cloaking_site_url.='&p202lpid='.\$lpVisitorId;", $lp);
        $lpc = self::code('tracking202/redirect/lpc.php');
        $find = strpos($lpc, '\Prosper202\Click\LandingPageVisitorClick::find(');
        self::assertIsInt($find);
        self::assertStringContainsString('(string)$landingId,$_GET,$tracker_row,time())', substr($lpc, $find, 200));
        self::assertStringNotContainsString('parse_str(', $lpc, 'nothing in lpc.php replaces its own query');
    }
}
