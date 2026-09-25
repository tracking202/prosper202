<?php

declare(strict_types=1);

namespace Prosper202\Notifications;

use Prosper202\Database\Connection;

/**
 * The correction URL of a traffic source's server-to-server pixel
 * (202_notification_correction_urls; plan §5.5, PR 11): where the outbox
 * sends a `correction` or `retraction` for an outcome a postback already
 * announced. Off (no row) by default, because most networks have no
 * endpoint for one.
 *
 * Only a server-to-server postback (pixel type 4) carries one: the outbox
 * queues nothing else. The URL is http(s), one URL (the pixel code may
 * list several; a correction goes to one place), at most 2048 characters
 * — the same shape the sender (PostbackSender) will fetch.
 */
final class CorrectionUrls
{
    public const MAX_LENGTH = 2048;

    /** The pixel type that is sent server to server, and the only one that can carry a correction. */
    public const SERVER_PIXEL_TYPE = 4;

    public function __construct(private Connection $conn)
    {
    }

    /**
     * Why a typed correction URL cannot be used, or null when it can (the
     * empty string is usable: it turns corrections off).
     */
    public static function problem(string $url): ?string
    {
        if ($url === '') {
            return null;
        }
        if (strlen($url) > self::MAX_LENGTH) {
            return 'A correction URL is at most ' . self::MAX_LENGTH . ' characters.';
        }
        if (preg_match('~^https?://[^\s/?#]+[^\s]*$~iD', $url) !== 1) {
            return 'A correction URL is one http:// or https:// address with no spaces, such as https://network.example/correct?tx=[[transactionid]]&value=[[p202_goal_value]].';
        }

        return null;
    }

    /** The pixel's correction URL, or null when it has none. */
    public function forPixel(int $userId, int $pixelId): ?string
    {
        $stmt = $this->conn->prepareWrite('SELECT correction_url FROM 202_notification_correction_urls WHERE pixel_id = ? AND user_id = ? LIMIT 1');
        $this->conn->bind($stmt, 'ii', [$pixelId, $userId]);
        $row = $this->conn->fetchOne($stmt);
        $url = $row === null ? '' : trim((string) $row['correction_url']);

        return $url === '' ? null : $url;
    }

    /**
     * Each of these pixels' correction URL, for a form.
     *
     * @param list<int> $pixelIds
     * @return array<int, string>
     */
    public function forPixels(int $userId, array $pixelIds): array
    {
        $pixelIds = array_values(array_unique(array_filter($pixelIds, static fn (int $id): bool => $id > 0)));
        if ($pixelIds === []) {
            return [];
        }
        $stmt = $this->conn->prepareRead(
            'SELECT pixel_id, correction_url FROM 202_notification_correction_urls WHERE user_id = ? AND pixel_id IN ('
            . implode(', ', array_fill(0, count($pixelIds), '?')) . ')'
        );
        $this->conn->bind($stmt, 'i' . str_repeat('i', count($pixelIds)), [$userId, ...$pixelIds]);
        $out = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $out[(int) $row['pixel_id']] = (string) $row['correction_url'];
        }

        return $out;
    }

    /**
     * Save a traffic-source account's correction URLs, from the form that
     * edits its pixels: every pixel the account really has — read here,
     * joined to its owner, never taken from the request — gets the URL
     * posted for it when it is a server pixel, and none otherwise. A pixel
     * id the request names that is not this account's is ignored, so a
     * forged id can neither set another user's URL nor claim the id's row
     * before its owner does.
     *
     * @param array<int, string> $urls pixel id => the URL typed ('' for none)
     */
    public function saveForAccount(int $userId, int $ppcAccountId, array $urls, int $now): void
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT p.pixel_id, p.pixel_type_id FROM 202_ppc_account_pixels p
             JOIN 202_ppc_accounts a ON a.ppc_account_id = p.ppc_account_id
             WHERE p.ppc_account_id = ? AND a.user_id = ?'
        );
        $this->conn->bind($stmt, 'ii', [$ppcAccountId, $userId]);
        foreach ($this->conn->fetchAll($stmt) as $pixel) {
            $pixelId = (int) $pixel['pixel_id'];
            $url = (int) $pixel['pixel_type_id'] === self::SERVER_PIXEL_TYPE ? trim($urls[$pixelId] ?? '') : '';
            $this->set($userId, $pixelId, self::problem($url) === null ? $url : '', $now);
        }
        $this->pruneOrphans($userId);
    }

    /**
     * Forget the correction URLs of a user's pixels that no longer exist
     * (the traffic source form deletes the pixels it no longer lists), so a
     * pixel id can never inherit one.
     */
    public function pruneOrphans(int $userId): void
    {
        $stmt = $this->conn->prepareWrite(
            'DELETE c FROM 202_notification_correction_urls c
             LEFT JOIN 202_ppc_account_pixels p ON p.pixel_id = c.pixel_id
             WHERE c.user_id = ? AND p.pixel_id IS NULL'
        );
        $this->conn->bind($stmt, 'i', [$userId]);
        $this->conn->executeUpdate($stmt);
    }

    /**
     * Set a pixel's correction URL, or clear it with ''. The caller has
     * checked that the pixel is the user's and a server pixel, and that
     * problem() had nothing to say.
     */
    public function set(int $userId, int $pixelId, string $url, int $now): void
    {
        if (self::problem($url) !== null) {
            throw new \InvalidArgumentException('CorrectionUrls::set(): refused URL for pixel ' . $pixelId);
        }
        if ($url === '') {
            $stmt = $this->conn->prepareWrite('DELETE FROM 202_notification_correction_urls WHERE pixel_id = ? AND user_id = ?');
            $this->conn->bind($stmt, 'ii', [$pixelId, $userId]);
            $this->conn->executeUpdate($stmt);

            return;
        }
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_notification_correction_urls (pixel_id, user_id, correction_url, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE correction_url = IF(user_id = VALUES(user_id), VALUES(correction_url), correction_url),
                 updated_at = IF(user_id = VALUES(user_id), VALUES(updated_at), updated_at)'
        );
        $this->conn->bind($stmt, 'iisii', [$pixelId, $userId, $url, $now, $now]);
        $this->conn->executeUpdate($stmt);
    }
}
