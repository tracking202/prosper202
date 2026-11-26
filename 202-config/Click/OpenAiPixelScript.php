<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Conversion\OpenAiPixelId;
use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;

/**
 * The ChatGPT Ads pixel's part of a simple landing page's record.php answer
 * (record_simple.php): a call of landing.php's window.p202OpenAiPixel() with
 * the pixel id to load and the proof the page posts the pixel's browser
 * reference with (OpenAiBrowserRef).
 *
 * Nothing is answered unless the visitor may be sent a third-party pixel
 * (FacebookPixelAssistantScript::mayTrack(): the privacy setting in force,
 * the page's consent and the campaign's identity capture), no request of
 * the click refused consent where that is kept, the page's switch is on
 * (OpenAiPixelSettings), and the click's traffic source (the account's own)
 * has a "ChatGPT Ads Pixel" pixel holding a pixel id. Without the
 * installation's key the pixel still loads and its reference is not kept.
 *
 * The click is already recorded when this runs, so a failed read is logged
 * and answers nothing rather than failing the page's script.
 */
final class OpenAiPixelScript
{
    private function __construct()
    {
    }

    /** @param callable(): ?string $key */
    public static function forClick(
        Connection $conn,
        int $userId,
        int $landingPageId,
        int $ppcAccountId,
        int $clickId,
        bool $mayTrack,
        callable $key,
        ?int $now = null
    ): string {
        if (!$mayTrack || $userId <= 0 || $landingPageId <= 0 || $ppcAccountId <= 0 || $clickId <= 0) {
            return '';
        }
        try {
            $pixelId = self::pageIsOn($conn, $userId, $landingPageId) ? self::pixelId($conn, $userId, $ppcAccountId) : null;
            if ($pixelId === null || FacebookPixelAssistantScript::clickRefused($conn, $clickId)) {
                return '';
            }
        } catch (\Throwable $e) {
            error_log('p202 ChatGPT Ads pixel: landing page ' . $landingPageId
                . ' could not be read; the pixel is not loaded for click ' . $clickId . ': ' . PageUpdate::failureText($e));

            return '';
        }
        $config = ['pixelId' => $pixelId];
        $proof = OpenAiBrowserRef::proof($clickId, $landingPageId, $userId, $now ?? time(), $key);
        if ($proof !== null) {
            $config['refProof'] = $proof;
        }
        $json = json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if ($json === false) {
            error_log('p202 ChatGPT Ads pixel: the settings for click ' . $clickId . ' could not be encoded; the pixel is not loaded');

            return '';
        }

        return "\nif (typeof window.p202OpenAiPixel === 'function') { window.p202OpenAiPixel(" . $json . "); }\n";
    }

    /**
     * The query pair a rotator adds for a visit it sends to the user's own
     * landing page: the ad click's oppref, which the rotator otherwise keeps
     * (getPrePopVars()), so the page's pixel can read it. Only when the
     * page's pixel is on and the request did not refuse consent; '' else, and
     * on a failed read (logged), since the click is already recorded.
     *
     * @param array<array-key, mixed> $get
     */
    public static function opprefForLandingPage(Connection $conn, int $userId, int $landingPageId, array $get): string
    {
        $oppref = $get['oppref'] ?? null;
        if (!is_string($oppref) || trim($oppref) === '' || $userId <= 0 || $landingPageId <= 0
            || !\Prosper202\Identity\RequestSignals::consentGiven($get)) {
            return '';
        }
        try {
            if (!self::pageIsOn($conn, $userId, $landingPageId)) {
                return '';
            }
        } catch (\Throwable $e) {
            error_log('p202 ChatGPT Ads pixel: landing page ' . $landingPageId
                . ' could not be read; the rotator does not pass oppref on: ' . PageUpdate::failureText($e));

            return '';
        }

        return 'oppref=' . rawurlencode(trim($oppref)) . '&';
    }

    /**
     * Whether the switch is on for the user's simple page. Before the 1.9.81
     * upgrade makes the table no page has it on.
     */
    private static function pageIsOn(Connection $conn, int $userId, int $landingPageId): bool
    {
        try {
            return (new \Prosper202\Setup\OpenAiPixelSettings($conn))->isOn($userId, $landingPageId);
        } catch (\Throwable $e) {
            for ($current = $e; $current !== null; $current = $current->getPrevious()) {
                if (
                    Connection::isMysqlError($current, 1146, '')
                    && str_contains($current->getMessage(), TableRegistry::LANDING_PAGE_OPENAI_PIXEL)
                ) {
                    return false;
                }
            }
            throw $e;
        }
    }

    /** The source's first pixel of the ChatGPT Ads type that holds a pixel id. */
    private static function pixelId(Connection $conn, int $userId, int $ppcAccountId): ?string
    {
        $stmt = $conn->prepareRead(
            'SELECT p.pixel_id, p.pixel_code
               FROM 202_ppc_account_pixels AS p
               JOIN 202_ppc_accounts AS a ON a.ppc_account_id = p.ppc_account_id AND a.user_id = ?
              WHERE p.ppc_account_id = ? AND p.pixel_type_id = ?
              ORDER BY p.pixel_id'
        );
        $conn->bind($stmt, 'iii', [$userId, $ppcAccountId, OpenAiPixelId::PIXEL_TYPE]);
        foreach ($conn->fetchAll($stmt) as $row) {
            $id = OpenAiPixelId::fromCode($row['pixel_code'] ?? null);
            if ($id !== null) {
                return $id;
            }
            error_log('p202 ChatGPT Ads pixel: pixel ' . (is_numeric($row['pixel_id'] ?? null) ? (int) $row['pixel_id'] : 0)
                . ' of ppc account ' . $ppcAccountId . ' holds no pixel id; skipped');
        }

        return null;
    }
}
