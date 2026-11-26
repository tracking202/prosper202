<?php

declare(strict_types=1);

namespace Prosper202\Setup;

use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;

/**
 * A simple landing page's ChatGPT Ads pixel switch, kept in
 * 202_landing_page_openai_pixel (one row per landing page). With it on, the
 * page loads OpenAI's pixel for a visit from a traffic source with a
 * "ChatGPT Ads Pixel" pixel holding the pixel id
 * (Prosper202\Click\OpenAiPixelScript), and a rotator that sends the visit
 * to the page carries its oppref along. Off, or with no row, the page loads
 * nothing of OpenAI's. Setup › Landing Pages saves it.
 *
 * @phpstan-type Settings array{status: int}
 */
final class OpenAiPixelSettings
{
    public const FIELD = 'openai_pixel_status';

    public function __construct(private readonly Connection $conn)
    {
    }

    /** @return Settings what a page with no row has */
    public static function defaults(): array
    {
        return ['status' => 0];
    }

    /**
     * The switch as the landing page form posts it: absent, '' or 0 is off,
     * 1 or on is on, and anything else is refused in $errors.
     *
     * @param array<string, mixed> $post
     * @param array<string, string> $errors
     * @return Settings
     */
    public static function fromForm(array $post, array &$errors): array
    {
        $value = $post[self::FIELD] ?? '0';
        if (in_array($value, ['1', 'on'], true)) {
            return ['status' => 1];
        }
        if ($value !== '0' && $value !== '') {
            $errors[self::FIELD] = 'Turn the ChatGPT Ads pixel switch on or off.';
        }

        return ['status' => 0];
    }

    /**
     * The page's settings, when it is the user's; null when the page has
     * none (or is another account's).
     *
     * @return Settings|null
     */
    public function load(int $userId, int $landingPageId): ?array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT op.status
               FROM `' . TableRegistry::LANDING_PAGE_OPENAI_PIXEL . '` AS op
               JOIN 202_landing_pages AS lp ON lp.landing_page_id = op.landing_page_id AND lp.user_id = ?
              WHERE op.landing_page_id = ?
              LIMIT 1'
        );
        $this->conn->bind($stmt, 'ii', [$userId, $landingPageId]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            return null;
        }

        return ['status' => is_numeric($row['status'] ?? null) && (int) $row['status'] === 1 ? 1 : 0];
    }

    /**
     * Whether the pixel is on for the user's simple page: what the click
     * path and the rotator ask. An advanced page, another account's page and
     * a page with no row answer false.
     */
    public function isOn(int $userId, int $landingPageId): bool
    {
        $stmt = $this->conn->prepareRead(
            'SELECT 1 AS on_
               FROM `' . TableRegistry::LANDING_PAGE_OPENAI_PIXEL . '` AS op
               JOIN 202_landing_pages AS lp ON lp.landing_page_id = op.landing_page_id AND lp.user_id = ?
              WHERE op.landing_page_id = ? AND op.status = 1 AND lp.landing_page_type = 0
                AND lp.landing_page_deleted = 0
              LIMIT 1'
        );
        $this->conn->bind($stmt, 'ii', [$userId, $landingPageId]);

        return $this->conn->fetchOne($stmt) !== null;
    }

    /**
     * Store the page's switch. Written only for a page of the user's: the
     * row is selected from 202_landing_pages under the user's id, so another
     * account's page id writes nothing.
     *
     * @param Settings $settings
     */
    public function save(int $userId, int $landingPageId, array $settings): void
    {
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO `' . TableRegistry::LANDING_PAGE_OPENAI_PIXEL . '` (landing_page_id, status)
             SELECT lp.landing_page_id, ?
               FROM 202_landing_pages AS lp
              WHERE lp.landing_page_id = ? AND lp.user_id = ?
             ON DUPLICATE KEY UPDATE status = VALUES(status)'
        );
        $this->conn->bind($stmt, 'iii', [$settings['status'] === 1 ? 1 : 0, $landingPageId, $userId]);
        $this->conn->executeUpdate($stmt);
    }

    /** Switch the pixel off for a page that became an advanced page, which never loads it. */
    public function switchOff(int $userId, int $landingPageId): void
    {
        $stmt = $this->conn->prepareWrite(
            'UPDATE `' . TableRegistry::LANDING_PAGE_OPENAI_PIXEL . '` AS op
               JOIN 202_landing_pages AS lp ON lp.landing_page_id = op.landing_page_id AND lp.user_id = ?
                SET op.status = 0
              WHERE op.landing_page_id = ?'
        );
        $this->conn->bind($stmt, 'ii', [$userId, $landingPageId]);
        $this->conn->executeUpdate($stmt);
    }
}
