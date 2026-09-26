<?php

declare(strict_types=1);

namespace Prosper202\Conversion\Ledger;

/**
 * What produced a ledger row. The one list; the API, the breakdown and the
 * reports all read it from here.
 */
enum ConversionSource: string
{
    case PIXEL = 'pixel';
    case POSTBACK = 'postback';
    case UNIVERSAL_PIXEL = 'universal_pixel';
    case API = 'api';
    case SUBID_UPLOAD = 'subid_upload';
    case REVENUE_UPLOAD = 'revenue_upload';
    case LEGACY_PIXEL = 'legacy_pixel';
    case CLICKBANK = 'clickbank';
    case APP_INSTALL = 'app_install';
    case GOAL = 'goal';
    case LEGACY_BASELINE = 'legacy_baseline';

    /**
     * The source a pre-ledger row is backfilled with on upgrade, from the two
     * things such a row carried: its pixel_type and, for the manual subid
     * upload, the user agent that path wrote.
     */
    public static function fromLegacyRow(int $pixelType, string $userAgent): self
    {
        return match (true) {
            $pixelType === 1 => self::PIXEL,
            $pixelType === 2 => self::POSTBACK,
            $pixelType === 3 => self::UNIVERSAL_PIXEL,
            $userAgent === 'subid-upload' => self::SUBID_UPLOAD,
            default => self::API,
        };
    }

    /** A short human label for the breakdown and the reports. */
    public function label(): string
    {
        return match ($this) {
            self::PIXEL => 'Pixel',
            self::POSTBACK => 'Postback',
            self::UNIVERSAL_PIXEL => 'Universal pixel',
            self::API => 'API',
            self::SUBID_UPLOAD => 'Subid upload',
            self::REVENUE_UPLOAD => 'Revenue upload',
            self::LEGACY_PIXEL => 'Campaign pixel',
            self::CLICKBANK => 'ClickBank',
            self::APP_INSTALL => 'App install',
            self::GOAL => 'Goal',
            self::LEGACY_BASELINE => 'Value before the ledger',
        };
    }
}
