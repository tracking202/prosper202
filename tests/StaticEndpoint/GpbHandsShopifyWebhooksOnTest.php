<?php

declare(strict_types=1);

namespace Tests\StaticEndpoint;

use PHPUnit\Framework\TestCase;

/**
 * Paid 1.9.56 installs pointed Shopify's order webhooks at gpb.php. A request
 * carrying Shopify's headers is handed to shopify.php before gpb.php reads a
 * subid or boots anything, so it is verified and recorded there. The live
 * pass posts to both URLs (see the pull request).
 */
final class GpbHandsShopifyWebhooksOnTest extends TestCase
{
    public function testShopifyRequestsLeaveGpbBeforeAnythingElseRuns(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/tracking202/static/gpb.php');
        $dispatch = strpos($source, "isset(\$_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'])");
        $handOff = strpos($source, "require __DIR__ . '/shopify.php';");

        self::assertNotFalse($dispatch, 'gpb.php checks for Shopify\'s signature header');
        self::assertNotFalse($handOff, 'and hands the request to shopify.php');
        foreach (['include_once', 'header(', '$_GET', 'p202ParseClickId'] as $later) {
            $at = strpos($source, $later);
            self::assertNotFalse($at);
            self::assertGreaterThan($handOff, $at, $later . ' runs only for requests that are not Shopify\'s');
        }
        self::assertMatchesRegularExpression("~require __DIR__ \\. '/shopify\\.php';\\s*exit;~", $source);
        self::assertFileExists(dirname(__DIR__, 2) . '/tracking202/static/shopify.php');
    }
}
