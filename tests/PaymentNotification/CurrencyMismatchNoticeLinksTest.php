<?php

declare(strict_types=1);

namespace Tests\PaymentNotification;

use PHPUnit\Framework\TestCase;

/**
 * The payment cards' held-currency warning links Setup › Inbound Log only
 * for a role that opens it: a role with API Integrations and no Setup would
 * land on the page that shows it the Shopify refunds alone.
 */
final class CurrencyMismatchNoticeLinksTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/functions-payment-notices.php';
    }

    public function testTheLogIsLinkedOnlyForARoleThatOpensIt(): void
    {
        $counts = ['jvzoo' => ['EUR' => ['count' => 2, 'latest' => 1794300000]]];
        $notice = static fn (bool $linksLog): string => p202_currency_mismatch_notice(
            $counts,
            'jvzoo',
            'JVZoo',
            'USD',
            'https://track.example.com/',
            'https://track.example.com/202-account/api-integrations.php#jvzoo',
            '',
            $linksLog
        );

        $setup = $notice(true);
        self::assertStringContainsString('recorded nothing', $setup, 'sanity: the warning is drawn');
        $log = 'tracking202/setup/inbound_log.php?outcome=currency_mismatch&amp;endpoint=jvzoo';
        self::assertStringContainsString($log, $setup);

        $integrationsOnly = $notice(false);
        self::assertStringContainsString('recorded nothing', $integrationsOnly);
        self::assertStringNotContainsString('inbound_log.php', $integrationsOnly);
        $fix = 'the fix it names is still linked';
        self::assertStringContainsString('202-account/account.php#currency', $integrationsOnly, $fix);
    }
}
