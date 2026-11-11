<?php

declare(strict_types=1);

namespace Prosper202\Rotator;

use Prosper202\Click\ClickBotDetector;

/**
 * The visitor's device as a redirector's device rule names it: Tablet,
 * Mobile, Bot or Desktop, as the click is recorded
 * (PLATFORMS::parseUserAgentInfo()). rtr.php and offrtr.php kept the user
 * agent parser's own family for anything it did not call "Other", so a Mac
 * was "Mac" and a crawler "Spider": a "device is desktop" rule matched no
 * Mac, and "device is bot" no crawler.
 */
final class VisitorDevice
{
    private function __construct()
    {
    }

    /** @param string $family the user agent parser's device family */
    public static function of(bool $tablet, bool $mobile, string $family): string
    {
        return match (true) {
            $tablet => 'Tablet',
            $mobile => 'Mobile',
            $family === 'Bot', $family === ClickBotDetector::SPIDER_FAMILY => 'Bot',
            default => 'Desktop',
        };
    }
}
