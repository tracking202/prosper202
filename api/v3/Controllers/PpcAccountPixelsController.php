<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Api\V3\Exception\WriteCommittedException;
use Api\V3\Support\StatementHelpers;
use Prosper202\Database\Connection;
use Prosper202\Notifications\CorrectionUrls;
use Prosper202\Notifications\NotificationOutbox;

/**
 * A traffic-source account's pixels (202_ppc_account_pixels): what the
 * account fires when one of its clicks converts — an image, iframe or
 * script URL, a server-to-server postback URL, or raw markup — and, for a
 * postback, where the network takes corrections
 * (202_notification_correction_urls). Setup › Traffic Sources edits them
 * under the account form's Advanced (tracking202/setup/ppc_accounts.php).
 *
 *   GET    /ppc-accounts/{id}/pixels
 *   POST   /ppc-accounts/{id}/pixels              {pixel_type_id, pixel_code, correction_url?}
 *   PUT    /ppc-accounts/{id}/pixels/{pixelId}    any of the three
 *   DELETE /ppc-accounts/{id}/pixels/{pixelId}    (?dry_run=1 previews)
 *
 * The form's rules: the account must be the caller's (and its traffic
 * source too, as the form checks before saving), a pixel is only ever read
 * or changed within its own account, a pixel is saved only with a type and
 * a code (trimmed, as the form trims it), and a correction URL goes on a
 * server-to-server pixel only, matched to the code's URLs by position
 * (CorrectionUrls::problem(), the form's own check). A pixel that stops
 * being a postback keeps no correction URL, and a removed pixel is erased
 * with its correction URL, as the form erases the pixels it no longer
 * lists. Beyond the form: the type must be one 202_pixel_types names
 * (the form's select only offers those), and a postback's URLs must be
 * http(s) — the only ones PostbackSender will call.
 */
final class PpcAccountPixelsController
{
    use StatementHelpers;

    private const FIELDS = ['pixel_type_id', 'pixel_code', 'correction_url'];

    /** pixel_code is TEXT. */
    private const MAX_CODE_BYTES = 65535;

    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
    }

    /** @return array{data: list<array<string, mixed>>} */
    public function list(int $accountId): array
    {
        $this->account($accountId);
        $pixels = $this->pixels($accountId, null);

        return ['data' => $pixels];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{data: array<string, mixed>}
     */
    public function create(int $accountId, array $payload): array
    {
        $this->account($accountId);
        $fields = $this->validate($payload, null);

        $pixelId = $this->transaction(function () use ($accountId, $fields): int {
            $stmt = $this->prepare('INSERT INTO 202_ppc_account_pixels (ppc_account_id, pixel_code, pixel_type_id) VALUES (?, ?, ?)');
            $this->bind($stmt, 'isi', $accountId, $fields['pixel_code'], $fields['pixel_type_id']);
            $this->execute($stmt, 'Failed to create pixel');
            $id = (int) $stmt->insert_id;
            $stmt->close();
            if ($id <= 0) {
                throw new DatabaseException('Failed to create pixel');
            }
            $this->corrections()->set($this->userId, $id, $fields['correction_url'], time());

            return $id;
        });

        // Committed: the pixel exists and fires on the account's next conversion.
        try {
            return ['data' => $this->pixel($accountId, $pixelId)];
        } catch (\Throwable $e) {
            throw new WriteCommittedException('traffic source pixel', $e);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{data: array<string, mixed>}
     */
    public function update(int $accountId, int $pixelId, array $payload): array
    {
        $this->account($accountId);
        $current = $this->pixel($accountId, $pixelId);
        if ($payload === []) {
            throw new ValidationException('No fields to update', ['pixel_code' => 'Send pixel_type_id, pixel_code or correction_url']);
        }
        $fields = $this->validate($payload, $current);

        $this->transaction(function () use ($accountId, $pixelId, $fields): void {
            $stmt = $this->prepare('UPDATE 202_ppc_account_pixels SET pixel_code = ?, pixel_type_id = ? WHERE pixel_id = ? AND ppc_account_id = ?');
            $this->bind($stmt, 'siii', $fields['pixel_code'], $fields['pixel_type_id'], $pixelId, $accountId);
            $this->execute($stmt, 'Failed to update pixel');
            $stmt->close();
            $this->corrections()->set($this->userId, $pixelId, $fields['correction_url'], time());
        });

        try {
            return ['data' => $this->pixel($accountId, $pixelId)];
        } catch (\Throwable $e) {
            throw new WriteCommittedException('traffic source pixel', $e);
        }
    }

    /** @return array{data: array<string, mixed>} */
    public function deletePreview(int $accountId, int $pixelId): array
    {
        $this->account($accountId);
        $pixel = $this->pixel($accountId, $pixelId);

        return ['data' => [
            'dry_run' => true,
            'action' => 'delete',
            'resource' => 'ppc-account-pixels',
            'mode' => 'hard',
            'record' => $pixel,
            'cascade' => [
                ['resource' => 'correction-urls', 'count' => $pixel['correction_url'] === '' ? 0 : 1],
            ],
        ]];
    }

    /** Erase the pixel and its correction URL, as the form erases a pixel it no longer lists. */
    public function delete(int $accountId, int $pixelId): void
    {
        $this->account($accountId);
        $this->pixel($accountId, $pixelId);
        $this->transaction(function () use ($accountId, $pixelId): void {
            $stmt = $this->prepare('DELETE FROM 202_ppc_account_pixels WHERE pixel_id = ? AND ppc_account_id = ?');
            $this->bind($stmt, 'ii', $pixelId, $accountId);
            $this->execute($stmt, 'Failed to delete pixel');
            $stmt->close();
            // So a pixel id can never inherit this one's correction URL.
            $this->corrections()->set($this->userId, $pixelId, '', time());
        });
    }

    /**
     * The pixel as it will be stored: the current one with the payload's
     * fields over it, every rule checked on the result, before anything is
     * written.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $current null on create
     * @return array{pixel_type_id: int, pixel_code: string, correction_url: string}
     */
    private function validate(array $payload, ?array $current): array
    {
        $errors = [];
        foreach (array_keys($payload) as $key) {
            if (!in_array($key, self::FIELDS, true)) {
                $errors[(string) $key] = 'Unknown field; a pixel has pixel_type_id, pixel_code and correction_url';
            }
        }
        $types = $this->pixelTypes();

        $typeId = $current === null ? null : (int) $current['pixel_type_id'];
        if (array_key_exists('pixel_type_id', $payload)) {
            $raw = $payload['pixel_type_id'];
            $typeId = null;
            if (is_int($raw) || (is_string($raw) && preg_match('/^[1-9]\d{0,2}$/D', $raw) === 1)) {
                $typeId = (int) $raw;
            }
            if ($typeId === null || !isset($types[$typeId])) {
                $errors['pixel_type_id'] = 'Must be one of ' . self::describeTypes($types);
                $typeId = null;
            }
        } elseif ($current === null) {
            $errors['pixel_type_id'] = 'Required: ' . self::describeTypes($types);
        }

        $code = $current === null ? null : (string) $current['pixel_code'];
        if (array_key_exists('pixel_code', $payload)) {
            $raw = $payload['pixel_code'];
            $code = is_string($raw) ? trim($raw) : null;
            if ($code === null) {
                $errors['pixel_code'] = 'Must be text';
            } elseif ($code === '') {
                // The form saves a pixel only with a code.
                $errors['pixel_code'] = 'Must not be blank (DELETE the pixel to remove it)';
            } elseif (strlen($code) > self::MAX_CODE_BYTES) {
                $errors['pixel_code'] = 'At most ' . self::MAX_CODE_BYTES . ' bytes';
            }
        } elseif ($current === null) {
            $errors['pixel_code'] = 'Required: for every type except Raw, the URL from the pixel\'s src (several URLs separated by spaces)';
        }

        $isServer = $typeId === CorrectionUrls::SERVER_PIXEL_TYPE;
        if ($isServer && is_string($code) && $code !== '' && !isset($errors['pixel_code'])) {
            foreach (NotificationOutbox::destinations($code) as $url) {
                if (preg_match('~^https?://[^\s/?#]+[^\s]*$~iD', $url) !== 1) {
                    $errors['pixel_code'] = 'A Postback (server-to-server) pixel is http:// or https:// URLs separated by spaces; '
                        . json_encode($url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ' is not one, and the postback sender calls nothing else';
                    break;
                }
            }
        }

        $correction = $current === null ? '' : (string) $current['correction_url'];
        if (array_key_exists('correction_url', $payload)) {
            $raw = $payload['correction_url'];
            $correction = is_string($raw) ? trim($raw) : null;
            if ($correction === null) {
                $errors['correction_url'] = 'Must be text (an empty string removes it)';
                $correction = '';
            } elseif ($correction !== '' && $typeId !== null && !$isServer) {
                // The form's sentence.
                $errors['correction_url'] = 'A correction URL goes on a server-to-server (Postback URL) pixel only: the other pixel types are fired by a browser, which is not there when a correction is sent.';
            }
        } elseif (!$isServer) {
            // A pixel that stopped being a server pixel keeps none (saveForAccount()).
            $correction = '';
        }
        if ($correction !== '' && $isServer && !isset($errors['correction_url']) && is_string($code) && !isset($errors['pixel_code'])) {
            $problem = CorrectionUrls::problem($correction, $code);
            if ($problem !== null) {
                $errors['correction_url'] = $problem;
            }
        }

        if ($errors !== []) {
            throw new ValidationException('Invalid pixel', $errors);
        }

        return ['pixel_type_id' => (int) $typeId, 'pixel_code' => (string) $code, 'correction_url' => $correction];
    }

    /** The caller's live account, whose traffic source is the caller's too, or 404. */
    private function account(int $accountId): void
    {
        $stmt = $this->prepare(
            'SELECT a.ppc_account_id
             FROM 202_ppc_accounts a
             INNER JOIN 202_ppc_networks n ON n.ppc_network_id = a.ppc_network_id AND n.user_id = a.user_id
             WHERE a.ppc_account_id = ? AND a.user_id = ? AND a.ppc_account_deleted = 0
             LIMIT 1'
        );
        $this->bind($stmt, 'ii', $accountId, $this->userId);
        $this->execute($stmt, 'Traffic source account lookup failed');
        $result = $stmt->get_result();
        if ($result === false) {
            // "No row" would answer 404 for a database failure.
            $stmt->close();
            throw new DatabaseException('Traffic source account lookup failed');
        }
        $found = $result->fetch_row() !== null;
        $stmt->close();
        if (!$found) {
            throw new NotFoundException("Traffic source account $accountId not found");
        }
    }

    /** @return array<string, mixed> */
    private function pixel(int $accountId, int $pixelId): array
    {
        $pixels = $this->pixels($accountId, $pixelId);
        if ($pixels === []) {
            throw new NotFoundException("Pixel $pixelId not found on traffic source account $accountId");
        }

        return $pixels[0];
    }

    /**
     * The account's pixels (or the one named), with their type's name and
     * correction URL.
     *
     * @return list<array<string, mixed>>
     */
    private function pixels(int $accountId, ?int $pixelId): array
    {
        $sql = 'SELECT p.pixel_id, p.ppc_account_id, p.pixel_type_id, t.pixel_type, p.pixel_code
                FROM 202_ppc_account_pixels p
                LEFT JOIN 202_pixel_types t ON t.pixel_type_id = p.pixel_type_id
                WHERE p.ppc_account_id = ?' . ($pixelId === null ? '' : ' AND p.pixel_id = ?') . '
                ORDER BY p.pixel_id ASC';
        $stmt = $this->prepare($sql);
        if ($pixelId === null) {
            $this->bind($stmt, 'i', $accountId);
        } else {
            $this->bind($stmt, 'ii', $accountId, $pixelId);
        }
        $this->execute($stmt, 'Pixel lookup failed');
        $result = $stmt->get_result();
        if ($result === false) {
            // Read as no pixels, an account that reports conversions would
            // look like one that reports none.
            $stmt->close();
            throw new DatabaseException('Pixel lookup failed');
        }
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        $corrections = $this->corrections()->forPixels($this->userId, array_map(static fn (array $r): int => (int) $r['pixel_id'], $rows));
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'pixel_id' => (int) $row['pixel_id'],
                'ppc_account_id' => (int) $row['ppc_account_id'],
                'pixel_type_id' => (int) $row['pixel_type_id'],
                'pixel_type' => $row['pixel_type'] === null ? null : (string) $row['pixel_type'],
                'pixel_code' => (string) $row['pixel_code'],
                'correction_url' => (int) $row['pixel_type_id'] === CorrectionUrls::SERVER_PIXEL_TYPE
                    ? (string) ($corrections[(int) $row['pixel_id']] ?? '')
                    : '',
            ];
        }

        return $out;
    }

    /** @return array<int, string> pixel_type_id => name, from 202_pixel_types */
    private function pixelTypes(): array
    {
        $stmt = $this->prepare('SELECT pixel_type_id, pixel_type FROM 202_pixel_types ORDER BY pixel_type_id ASC');
        $this->execute($stmt, 'Pixel type lookup failed');
        $result = $stmt->get_result();
        if ($result === false) {
            // An empty list would refuse every type as unknown.
            $stmt->close();
            throw new DatabaseException('Pixel type lookup failed');
        }
        $types = [];
        while ($row = $result->fetch_assoc()) {
            $types[(int) $row['pixel_type_id']] = (string) $row['pixel_type'];
        }
        $stmt->close();
        if ($types === []) {
            throw new DatabaseException('202_pixel_types is empty');
        }

        return $types;
    }

    /** @param array<int, string> $types */
    private static function describeTypes(array $types): string
    {
        $named = [];
        foreach ($types as $id => $name) {
            $named[] = $id . ' (' . $name . ($id === CorrectionUrls::SERVER_PIXEL_TYPE ? ', server to server' : '') . ')';
        }

        return implode(', ', $named);
    }

    private function corrections(): CorrectionUrls
    {
        return new CorrectionUrls(new Connection($this->db));
    }
}
