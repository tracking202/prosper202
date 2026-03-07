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
    private ?bool $cachedIsTablet = null;
    private ?bool $cachedIsMobile = null;

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
        $this->cachedIsTablet = null;
        $this->cachedIsMobile = null;
        return $this;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    public function isTablet(): bool
    {
        if ($this->cachedIsTablet !== null) {
            return $this->cachedIsTablet;
        }

        $ua = $this->userAgent;
        if ($ua === '') {
            return $this->cachedIsTablet = false;
        }

        // iPad is always a tablet
        if (stripos($ua, 'iPad') !== false) {
            return $this->cachedIsTablet = true;
        }

        // Android without "Mobile" is typically a tablet
        if (stripos($ua, 'Android') !== false && stripos($ua, 'Mobile') === false) {
            return $this->cachedIsTablet = true;
        }

        foreach (self::TABLET_KEYWORDS as $keyword) {
            if (stripos($ua, $keyword) !== false) {
                return $this->cachedIsTablet = true;
            }
        }

        return $this->cachedIsTablet = false;
    }

    public function isMobile(): bool
    {
        if ($this->cachedIsMobile !== null) {
            return $this->cachedIsMobile;
        }

        $ua = $this->userAgent;
        if ($ua === '') {
            return $this->cachedIsMobile = false;
        }

        // Tablets are also considered mobile
        if ($this->isTablet()) {
            return $this->cachedIsMobile = true;
        }

        foreach (self::MOBILE_KEYWORDS as $keyword) {
            if (stripos($ua, $keyword) !== false) {
                return $this->cachedIsMobile = true;
            }
        }

        return $this->cachedIsMobile = false;
    }
}
