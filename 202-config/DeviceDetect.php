<?php

declare(strict_types=1);

/**
 * Lightweight device detection using User-Agent string matching.
 *
 * Replaces the third-party Mobile_Detect library with a minimal
 * implementation covering the mobile/tablet checks used by Prosper202.
 */
class DeviceDetect
{
    private string $userAgent = '';

    private const MOBILE_KEYWORDS = [
        'Mobile', 'Android', 'iPhone', 'iPod', 'Windows Phone', 'BlackBerry',
        'Opera Mini', 'Opera Mobi', 'IEMobile', 'webOS', 'Fennec',
        'Symbian', 'J2ME', 'MIDP',
    ];

    private const TABLET_KEYWORDS = [
        'PlayBook', 'Kindle', 'Silk', 'Nexus 7', 'Nexus 10', 'Galaxy Tab', 'SM-T', 'Surface',
    ];

    public function __construct()
    {
        $this->userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    }

    public function setUserAgent(string $ua): self
    {
        $this->userAgent = $ua;
        return $this;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    public function isTablet(): bool
    {
        $ua = $this->userAgent;
        if ($ua === '') {
            return false;
        }

        // iPad is always a tablet
        if (stripos($ua, 'iPad') !== false) {
            return true;
        }

        // Android without "Mobile" is typically a tablet
        if (stripos($ua, 'Android') !== false && stripos($ua, 'Mobile') === false) {
            return true;
        }

        foreach (self::TABLET_KEYWORDS as $keyword) {
            if (stripos($ua, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    public function isMobile(): bool
    {
        $ua = $this->userAgent;
        if ($ua === '') {
            return false;
        }

        // Tablets are also considered mobile
        if ($this->isTablet()) {
            return true;
        }

        foreach (self::MOBILE_KEYWORDS as $keyword) {
            if (stripos($ua, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }
}
