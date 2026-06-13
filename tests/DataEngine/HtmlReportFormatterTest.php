<?php

declare(strict_types=1);

namespace {
    // The formatter delegates money rendering to the global dollar_format()
    // helper (defined in 202-config/functions-tracking202.php, which cannot
    // be loaded without the full web bootstrap). Provide a minimal stand-in.
    if (!function_exists('dollar_format')) {
        function dollar_format($amount, $currency = null, $cpv = false)
        {
            return ($currency ?? '$') . number_format((float) $amount, 2);
        }
    }

    if (!function_exists('inet6_ntoa')) {
        function inet6_ntoa($ip)
        {
            return inet_ntop($ip);
        }
    }
}

namespace Tests\DataEngine {

    use PHPUnit\Framework\TestCase;
    use Prosper202\DataEngine\HtmlReportFormatter;

    final class HtmlReportFormatterTest extends TestCase
    {
        private HtmlReportFormatter $formatter;

        protected function setUp(): void
        {
            $this->formatter = new HtmlReportFormatter('$');
        }

        public function testCountColumnsAreNumberFormatted(): void
        {
            $html = $this->formatter->format(['clicks' => '12345', 'leads' => '7', 'click_out' => '999']);

            self::assertSame('12,345', $html['clicks']);
            self::assertSame('7', $html['leads']);
            self::assertSame('999', $html['click_out']);
        }

        public function testMoneyColumnsUseCurrency(): void
        {
            $html = $this->formatter->format(['income' => '1234.5', 'cost' => '10', 'net' => '1224.5']);

            self::assertSame('$1,234.50', $html['income']);
            self::assertSame('$10.00', $html['cost']);
            self::assertSame('$1,224.50', $html['net']);
        }

        public function testCtrIsRecomputedFromRowClicks(): void
        {
            $html = $this->formatter->format(['clicks' => '200', 'click_out' => '50', 'ctr' => '999']);

            // The stored ctr value is ignored; display ctr = click_out/clicks.
            self::assertSame('25%', $html['ctr']);
        }

        public function testCtrIsZeroWithoutClicks(): void
        {
            $html = $this->formatter->format(['clicks' => '0', 'click_out' => '50', 'ctr' => '10']);

            self::assertSame('0%', $html['ctr']);
        }

        public function testPercentageColumns(): void
        {
            $html = $this->formatter->format(['su_ratio' => '12.345', 'roi' => '250.4']);

            self::assertSame('12.35%', $html['su_ratio']);
            self::assertSame('250%', $html['roi']);
        }

        public function testRoiNullRendersAsZeroPercent(): void
        {
            $html = $this->formatter->format(['roi' => null]);

            self::assertSame('0%', $html['roi']);
        }

        public function testTotalRowsArePrefixedAndBackfilled(): void
        {
            $html = $this->formatter->format(['clicks' => '5', 'income' => '10'], 'total');

            self::assertSame('5', $html['total_clicks']);
            self::assertSame('$10.00', $html['total_income']);
            // Keys not present in the row are backfilled with '0'.
            self::assertSame('0', $html['total_roi']);
            self::assertSame('0', $html['total_net']);
            self::assertArrayNotHasKey('clicks', $html);
        }

        public function testEmptyDimensionsGetPlaceholders(): void
        {
            $html = $this->formatter->format([
                'keyword' => '',
                'text_ad_name' => null,
                'referer_name' => '',
                'country_name' => '',
                'country_code' => '',
                'isp_name' => '',
                'landing_page_nickname' => '',
                'ip_address' => '',
                'device_name' => '',
                'browser_name' => null,
                'platform_name' => '',
            ]);

            self::assertSame('[no keyword]', $html['keyword']);
            self::assertSame('[no text ad]', $html['text_ad_name']);
            self::assertSame('[no referer]', $html['referer_name']);
            self::assertSame('[no country]', $html['country_name']);
            self::assertSame('non', $html['country_code']);
            self::assertSame('[no isp]', $html['isp_name']);
            self::assertSame('[direct link]', $html['landing_page_nickname']);
            self::assertSame('[no ip]', $html['ip_address']);
            self::assertSame('[no device]', $html['device_name']);
            self::assertSame('[no browser]', $html['browser_name']);
            self::assertSame('[no platform]', $html['platform_name']);
        }

        public function testDimensionValuesAreHtmlEscaped(): void
        {
            $html = $this->formatter->format(['keyword' => '<script>alert(1)</script>']);

            self::assertStringNotContainsString('<script>', $html['keyword']);
            self::assertStringContainsString('&lt;script&gt;', $html['keyword']);
        }

        public function testTimeDisplayLowercasesMeridiem(): void
        {
            $html = $this->formatter->format(['click_time_from_disp' => 'Jan 01, 2026 at 3PM']);

            self::assertSame('Jan 01, 2026 at 3pm', $html['click_time_from_disp']);
        }

        public function testMissingCampaignNameGetsFallbackLabel(): void
        {
            $html = $this->formatter->format(['clicks' => '1']);

            self::assertSame('[Landing Page/Smart Redirector Campaign]', $html['aff_campaign_name']);
        }

        public function testPresentCampaignNameIsKept(): void
        {
            $html = $this->formatter->format(['aff_campaign_name' => 'My Campaign']);

            self::assertSame('My Campaign', $html['aff_campaign_name']);
        }

        public function testBinaryIpAddressIsDecodedForDisplay(): void
        {
            $packed = inet_pton('2001:db8::1');
            self::assertNotFalse($packed);

            $html = $this->formatter->format(['ip_address' => $packed]);

            self::assertSame('2001:db8::1', $html['ip_address']);
        }
    }
}
