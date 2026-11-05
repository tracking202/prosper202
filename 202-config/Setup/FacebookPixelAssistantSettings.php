<?php

declare(strict_types=1);

namespace Prosper202\Setup;

use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;

/**
 * A simple landing page's Bot202 Facebook Pixel Assistant settings, as paid
 * 1.9.56 kept them in 202_bot202_facebook_pixel_assistant (one row per
 * landing page): whether it is on, whether outbound clicks carry the
 * traffic source's dynamic EPV, and the content name, content type and
 * outbound-click event its Meta Pixel events carry. Setup › Landing Pages
 * saves them; the landing page script fires them
 * (Prosper202\Click\FacebookPixelAssistantScript).
 *
 * The content types and click events are the two lookup tables' rows, by
 * the ids paid 1.9.56 seeded and stored; seedStatements() writes the same
 * rows on a fresh install and on the upgrade.
 *
 * @phpstan-type Settings array{status: int, dynamic_epv: int, content_name: string, content_type: int, outbound_clicks: int}
 */
final class FacebookPixelAssistantSettings
{
    /** content_type_id => [Meta content_type, description] */
    public const CONTENT_TYPES = [
        1 => ['product', 'eCommerce Product For Sale'],
        2 => ['flight', 'Airline Flights'],
        3 => ['hotel', 'Hotel Rooms & General Accommodations'],
        4 => ['destination', 'Travel Destination'],
        5 => ['vehicle', 'New & Used Vehicles'],
        6 => ['home_listing', 'Real Estate & Rental Property'],
    ];

    /** event_type_id => [Meta standard event, description] */
    public const CLICK_EVENTS = [
        1 => ['ViewContent', 'Track As: View Content'],
        2 => ['AddToCart', 'Track As: Add To Cart'],
    ];

    /** b202_fbpa_content_name's length. */
    public const CONTENT_NAME_MAX = 100;

    public function __construct(private readonly Connection $conn)
    {
    }

    /** @return Settings what a page with no row has */
    public static function defaults(): array
    {
        return ['status' => 0, 'dynamic_epv' => 0, 'content_name' => '', 'content_type' => 1, 'outbound_clicks' => 1];
    }

    /**
     * The lookup rows, as INSERT IGNOREs: rows already there (a paid
     * install's, or the 1.9.54 step's) are kept.
     *
     * @return list<string>
     */
    public static function seedStatements(): array
    {
        return [
            'INSERT IGNORE INTO `' . TableRegistry::BOT202_FACEBOOK_PIXEL_CONTENT_TYPE
                . '` (`content_type_id`, `content_type`, `content_type_description`) VALUES ' . self::seedRows(self::CONTENT_TYPES),
            'INSERT IGNORE INTO `' . TableRegistry::BOT202_FACEBOOK_PIXEL_CLICK_EVENTS
                . '` (`event_type_id`, `event_type`, `event_type_description`) VALUES ' . self::seedRows(self::CLICK_EVENTS),
        ];
    }

    /** @param array<int, array{string, string}> $lookup */
    private static function seedRows(array $lookup): string
    {
        $values = [];
        foreach ($lookup as $id => [$name, $description]) {
            $values[] = '(' . $id . ", '" . addslashes($name) . "', '" . addslashes($description) . "')";
        }

        return implode(', ', $values);
    }

    /**
     * The settings the landing page form posts (fbpa_status, fbpa_dynamic_epv,
     * fbpa_content_name, fbpa_content_type, fbpa_outbound_clicks). What
     * cannot be stored as sent is refused in $errors, never cut or dropped.
     * A content type or click event of 0 is none.
     *
     * @param array<string, mixed> $post
     * @param array<string, string> $errors
     * @return Settings
     */
    public static function fromForm(array $post, array &$errors): array
    {
        // Absent or 0 is off, 1 or on is on; anything else is refused like a bad choice.
        $flag = static function (string $field) use ($post, &$errors): int {
            $value = $post[$field] ?? '0';
            if (in_array($value, ['1', 'on'], true)) {
                return 1;
            }
            if ($value !== '0' && $value !== '') {
                $errors[$field] = 'Turn the Facebook Pixel switch on or off.';
            }

            return 0;
        };
        $name = $post['fbpa_content_name'] ?? '';
        $name = is_string($name) ? trim($name) : null;
        if ($name === null || mb_strlen($name, 'UTF-8') > self::CONTENT_NAME_MAX) {
            $errors['fbpa_content_name'] = 'The Facebook Pixel content name can be at most ' . self::CONTENT_NAME_MAX . ' characters.';
        }
        $choice = static function (string $field, array $lookup, string $what) use ($post, &$errors): int {
            $value = $post[$field] ?? '0';
            $value = is_string($value) ? trim($value) : null;
            if ($value === '' || $value === '0') {
                return 0;
            }
            if ($value === null || preg_match('/^[1-9][0-9]{0,2}$/D', $value) !== 1 || !isset($lookup[(int) $value])) {
                $errors[$field] = 'Choose one of the listed Facebook Pixel ' . $what . '.';

                return 0;
            }

            return (int) $value;
        };

        return [
            'status' => $flag('fbpa_status'),
            'dynamic_epv' => $flag('fbpa_dynamic_epv'),
            'content_name' => $name ?? '',
            'content_type' => $choice('fbpa_content_type', self::CONTENT_TYPES, 'content types'),
            'outbound_clicks' => $choice('fbpa_outbound_clicks', self::CLICK_EVENTS, 'outbound click events'),
        ];
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
            'SELECT fb.b202_fbpa_status, fb.b202_fbpa_dynamic_epv, fb.b202_fbpa_content_name,
                    fb.b202_fbpa_content_type, fb.b202_fbpa_outbound_clicks
               FROM 202_bot202_facebook_pixel_assistant AS fb
               JOIN 202_landing_pages AS lp ON lp.landing_page_id = fb.landing_page_id AND lp.user_id = ?
              WHERE fb.landing_page_id = ?
              LIMIT 1'
        );
        $this->conn->bind($stmt, 'ii', [$userId, $landingPageId]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            return null;
        }

        return [
            'status' => self::number($row['b202_fbpa_status'] ?? null) === 1 ? 1 : 0,
            'dynamic_epv' => self::number($row['b202_fbpa_dynamic_epv'] ?? null) === 1 ? 1 : 0,
            'content_name' => is_scalar($row['b202_fbpa_content_name'] ?? null) ? (string) $row['b202_fbpa_content_name'] : '',
            'content_type' => self::number($row['b202_fbpa_content_type'] ?? null),
            'outbound_clicks' => self::number($row['b202_fbpa_outbound_clicks'] ?? null),
        ];
    }

    /**
     * Store the page's settings. Written only for a page of the user's: the
     * row is selected from 202_landing_pages under the user's id, so another
     * account's page id writes nothing.
     *
     * @param Settings $settings
     */
    public function save(int $userId, int $landingPageId, array $settings): void
    {
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_bot202_facebook_pixel_assistant
                    (landing_page_id, b202_fbpa_status, b202_fbpa_dynamic_epv, b202_fbpa_content_name,
                     b202_fbpa_content_type, b202_fbpa_outbound_clicks)
             SELECT lp.landing_page_id, ?, ?, ?, ?, ?
               FROM 202_landing_pages AS lp
              WHERE lp.landing_page_id = ? AND lp.user_id = ?
             ON DUPLICATE KEY UPDATE
                    b202_fbpa_status = VALUES(b202_fbpa_status),
                    b202_fbpa_dynamic_epv = VALUES(b202_fbpa_dynamic_epv),
                    b202_fbpa_content_name = VALUES(b202_fbpa_content_name),
                    b202_fbpa_content_type = VALUES(b202_fbpa_content_type),
                    b202_fbpa_outbound_clicks = VALUES(b202_fbpa_outbound_clicks)'
        );
        $this->conn->bind($stmt, 'iisiiii', [
            $settings['status'] === 1 ? 1 : 0,
            $settings['dynamic_epv'] === 1 ? 1 : 0,
            $settings['content_name'],
            $settings['content_type'],
            $settings['outbound_clicks'],
            $landingPageId,
            $userId,
        ]);
        $this->conn->executeUpdate($stmt);
    }

    /**
     * Switch the assistant off for a page that became an advanced page
     * (which it never ran on), keeping the rest of its settings.
     */
    public function switchOff(int $userId, int $landingPageId): void
    {
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_bot202_facebook_pixel_assistant AS fb
               JOIN 202_landing_pages AS lp ON lp.landing_page_id = fb.landing_page_id AND lp.user_id = ?
                SET fb.b202_fbpa_status = 0
              WHERE fb.landing_page_id = ?'
        );
        $this->conn->bind($stmt, 'ii', [$userId, $landingPageId]);
        $this->conn->executeUpdate($stmt);
    }

    /** A column read as an integer (0 for NULL or anything not numeric). */
    private static function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
