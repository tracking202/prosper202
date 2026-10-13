<?php

declare(strict_types=1);

namespace Prosper202\Setup;

use Prosper202\Database\Connection;

/**
 * The pixels a traffic-source account fires on a conversion, as Setup ›
 * Traffic Sources saves them (tracking202/setup/ppc_accounts.php, the
 * account form's Advanced): the form posts parallel arrays, one row per
 * pixel shown, and the account is left with exactly the pixels the form
 * listed. The REST API edits the same rows one at a time
 * (PpcAccountPixelsController).
 *
 * Two defects this replaced, both in the page's own loop:
 *
 * - The pixels the form no longer listed were deleted by a statement built
 *   only inside the branch for a listed pixel, so when the form listed none
 *   (the only pixel's code cleared, the one way the form has to remove its
 *   first row) nothing was deleted and the pixel kept firing.
 * - The edit form read a Raw pixel's code through stripslashes(), so every
 *   save of the account wrote it back with one level of backslashes fewer:
 *   `"a\\nb"` in a script became `"a\nb"`, then `"anb"`. Nothing else
 *   unescapes it (TrafficSourcePixels::fire() emits it as stored), so the
 *   stored bytes are the pixel and are shown and saved as they are.
 */
final class AccountPixels
{
    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * The form's rows: pixel_type_id[], pixel_code[], pixel_id[] and
     * pixel_correction_url[], by position. A row with no code is no pixel
     * (clearing a pixel's code removes it); a row with a code and no type is
     * refused, in $errors, rather than dropped with what was typed. Codes
     * are trimmed, as the API trims them, and a line break is stored as \n:
     * a browser submits every line break in a textarea as CRLF, so a pixel
     * written with \n (by the API, or by the CLI from a file) would
     * otherwise come back from its first form save with \r\n in it. HTML
     * and JavaScript read both alike; a reader comparing bytes does not.
     *
     * @param array<string, mixed> $post
     * @param array<string, string> $errors
     * @return list<array{pixel_id: int, pixel_type_id: int, pixel_code: string, correction_url: string}>
     */
    public static function fromForm(array $post, array &$errors): array
    {
        $types = is_array($post['pixel_type_id'] ?? null) ? $post['pixel_type_id'] : [];
        $codes = is_array($post['pixel_code'] ?? null) ? $post['pixel_code'] : [];
        $ids = is_array($post['pixel_id'] ?? null) ? $post['pixel_id'] : [];
        $corrections = is_array($post['pixel_correction_url'] ?? null) ? $post['pixel_correction_url'] : [];

        $rows = [];
        foreach (array_keys($types + $codes) as $key) {
            $code = is_string($codes[$key] ?? null) ? self::normalizeCode($codes[$key]) : '';
            if ($code === '') {
                continue;
            }
            $type = is_string($types[$key] ?? null) ? trim($types[$key]) : '';
            if (preg_match('/^[1-9][0-9]{0,2}$/D', $type) !== 1) {
                $errors['pixel_type_id'] = 'A pixel code needs its pixel type; choose one for each pixel, or clear the code to remove that pixel.';
                continue;
            }
            $id = is_string($ids[$key] ?? null) ? trim($ids[$key]) : '';
            $rows[] = [
                'pixel_id' => preg_match('/^[1-9][0-9]{0,9}$/D', $id) === 1 ? (int) $id : 0,
                'pixel_type_id' => (int) $type,
                'pixel_code' => $code,
                'correction_url' => is_string($corrections[$key] ?? null) ? trim($corrections[$key]) : '',
            ];
        }

        return $rows;
    }

    /** A pixel code as stored: CRLF and CR as \n, then trimmed. */
    public static function normalizeCode(string $code): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $code));
    }

    /**
     * The account's pixels as they are stored, oldest first.
     *
     * @return list<array{pixel_id: int, pixel_type_id: int, pixel_code: string}>
     */
    public function forAccount(int $accountId): array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT pixel_id, pixel_type_id, pixel_code FROM 202_ppc_account_pixels WHERE ppc_account_id = ? ORDER BY pixel_id ASC'
        );
        $this->conn->bind($stmt, 'i', [$accountId]);
        $out = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $out[] = [
                'pixel_id' => (int) $row['pixel_id'],
                'pixel_type_id' => (int) $row['pixel_type_id'],
                'pixel_code' => (string) $row['pixel_code'],
            ];
        }

        return $out;
    }

    /**
     * Leave the account with exactly these pixels, in one transaction: a row
     * naming one of the account's pixels updates it, any other row (no id,
     * or an id that is not this account's) is inserted, and every pixel of
     * the account no row named is deleted — all of them when there are no
     * rows. The caller has checked that the account is the user's.
     *
     * @param list<array{pixel_id: int, pixel_type_id: int, pixel_code: string, correction_url: string}> $rows
     * @return list<array{pixel_id: int, pixel_type_id: int, pixel_code: string, correction_url: string, previous: array{pixel_type_id: int, pixel_code: string}|null}>
     *         each saved row with its id and, for an updated pixel, what it was
     */
    public function save(int $accountId, array $rows): array
    {
        return $this->conn->transaction(function () use ($accountId, $rows): array {
            $existing = [];
            foreach ($this->forAccount($accountId) as $pixel) {
                $existing[$pixel['pixel_id']] = $pixel;
            }

            $saved = [];
            $kept = [];
            foreach ($rows as $row) {
                $id = $row['pixel_id'];
                if ($id > 0 && isset($existing[$id]) && !isset($kept[$id])) {
                    $stmt = $this->conn->prepareWrite(
                        'UPDATE 202_ppc_account_pixels SET pixel_code = ?, pixel_type_id = ? WHERE pixel_id = ? AND ppc_account_id = ?'
                    );
                    $this->conn->bind($stmt, 'siii', [$row['pixel_code'], $row['pixel_type_id'], $id, $accountId]);
                    $this->conn->executeUpdate($stmt);
                    $previous = ['pixel_type_id' => $existing[$id]['pixel_type_id'], 'pixel_code' => $existing[$id]['pixel_code']];
                } else {
                    $stmt = $this->conn->prepareWrite(
                        'INSERT INTO 202_ppc_account_pixels (ppc_account_id, pixel_code, pixel_type_id) VALUES (?, ?, ?)'
                    );
                    $this->conn->bind($stmt, 'isi', [$accountId, $row['pixel_code'], $row['pixel_type_id']]);
                    $id = $this->conn->executeInsert($stmt);
                    if ($id <= 0) {
                        throw new \RuntimeException('Saving a pixel for traffic source account ' . $accountId . ' yielded no pixel id');
                    }
                    $previous = null;
                }
                $kept[$id] = true;
                $saved[] = ['pixel_id' => $id] + $row + ['previous' => $previous];
            }

            foreach (array_keys($existing) as $id) {
                if (isset($kept[$id])) {
                    continue;
                }
                $stmt = $this->conn->prepareWrite('DELETE FROM 202_ppc_account_pixels WHERE pixel_id = ? AND ppc_account_id = ?');
                $this->conn->bind($stmt, 'ii', [$id, $accountId]);
                $this->conn->executeUpdate($stmt);
            }

            return $saved;
        });
    }
}
