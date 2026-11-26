<?php

declare(strict_types=1);

namespace Prosper202\Conversion;

use Prosper202\Database\Schema\TableRegistry;

/**
 * The ChatGPT Ads pixel id a "ChatGPT Ads Pixel" traffic source pixel
 * (202_pixel_types 7) holds in its pixel_code: the id Ads Manager ›
 * Conversions shows, the one a ChatGPT Ads destination sends to
 * (OpenAiAdsDestination's pixel_id), stored bare. The form and the API also
 * take OpenAI's pixel code or image tag pasted whole and keep the id.
 *
 * The landing page loads that pixel for a click from the source when the
 * page's switch is on (Click\OpenAiPixelScript), and a conversion's pixel
 * page fires its event under the id the destination sends
 * (TrafficSourcePixels::chatGptAdsEvent()).
 */
final class OpenAiPixelId
{
    /** 202_pixel_types.pixel_type_id of 'ChatGPT Ads Pixel'. */
    public const PIXEL_TYPE = 7;

    public const TYPE_NAME = 'ChatGPT Ads Pixel';

    /** OpenAiAdsDestination::validate()'s rule for a pixel id. */
    private const ID = '[A-Za-z0-9_\-]{1,128}';

    private function __construct()
    {
    }

    public static function fromCode(mixed $code): ?string
    {
        if (!is_string($code)) {
            return null;
        }
        $code = trim($code);
        if (preg_match('/\A' . self::ID . '\z/D', $code) === 1) {
            return $code;
        }
        // oaiq("init", {pixelId: "…"}) from the pixel's base code.
        if (preg_match('/oaiq\(\s*[\'"]init[\'"]\s*,\s*\{[^}]*?[\'"]?pixelId[\'"]?\s*:\s*[\'"](' . self::ID . ')[\'"]/', $code, $m) === 1) {
            return $m[1];
        }
        // The image tag: https://bzr.openai.com/v1/sdk/events?pid=…
        if (preg_match('#bzr\.openai\.com/v1/sdk/events\?(?:[^"\'\s<>]*?&(?:amp;)?)?pid=(' . self::ID . ')(?![A-Za-z0-9_\-])#i', $code, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * The type's row, at its id, for the installer and the 1.9.81 upgrade
     * alike. INSERT IGNORE: a row already there is kept, and
     * seededAsExpected() says whether it is this one.
     */
    public static function seedStatement(): string
    {
        return 'INSERT IGNORE INTO `' . TableRegistry::PIXEL_TYPES . '` (`pixel_type_id`, `pixel_type`) VALUES ('
            . self::PIXEL_TYPE . ", '" . self::TYPE_NAME . "')";
    }

    /** The statement that reads the type's row back, for seededAsExpected(). */
    public static function seededSql(): string
    {
        return 'SELECT pixel_type FROM `' . TableRegistry::PIXEL_TYPES . '` WHERE pixel_type_id = ' . self::PIXEL_TYPE;
    }

    /** Whether the row read by seededSql() is this type's: another name at the id is not. */
    public static function seededAsExpected(mixed $pixelType): bool
    {
        return $pixelType === self::TYPE_NAME;
    }
}
