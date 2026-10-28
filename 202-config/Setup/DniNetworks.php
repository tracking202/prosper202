<?php

declare(strict_types=1);

namespace Prosper202\Setup;

use mysqli;
use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;

/**
 * An account's Direct Network Integrations (202_dni_networks), as Account ›
 * API integrations lists them and its import poller asks after them.
 *
 * The page listed user 1's networks to whoever opened it, each network's key
 * in a Reveal attribute and the importing ones' keys in the poller's inline
 * JSON. Every read here is of one account's rows, and a key leaves the
 * server only for the hosted service: the page is told that a key is saved
 * and how it ends, and the progress request is built from the account's own
 * rows. DniNetworksReadOnlyTheAccountsTest holds every caller to passing
 * the session's account as $userId.
 *
 * @phpstan-type Shown array{id: int, networkId: string, name: string, type: string, affiliateId: string,
 *     processed: bool, shortDescription: string, favIcon: string, keyHint: string}
 */
final class DniNetworks
{
    /** A key shorter than this shows no ending: four characters would be too much of it. */
    private const SHOWN_ENDING_MIN_LENGTH = 12;

    /** What the page shows of a network: the key only as its length and last four characters. */
    private const SHOWN = 'id, networkId, name, type, affiliateId, processed, shortDescription, favIcon,'
        . ' CHAR_LENGTH(apiKey) AS key_length, RIGHT(apiKey, 4) AS key_end';

    /**
     * The account's networks, oldest first, without their keys.
     *
     * @return list<Shown>
     * @throws QueryException
     */
    public static function listFor(mysqli $db, int $userId): array
    {
        $conn = new Connection($db);
        $stmt = $conn->prepareRead('SELECT ' . self::SHOWN . ' FROM 202_dni_networks WHERE user_id = ? ORDER BY id');
        $conn->bind($stmt, 'i', [$userId]);

        return array_map(self::shown(...), $conn->fetchAll($stmt));
    }

    /**
     * One of the account's networks, without its key; null when the account
     * has none by that id.
     *
     * @return Shown|null
     * @throws QueryException
     */
    public static function oneFor(mysqli $db, int $userId, int $id): ?array
    {
        $conn = new Connection($db);
        $stmt = $conn->prepareRead('SELECT ' . self::SHOWN . ' FROM 202_dni_networks WHERE user_id = ? AND id = ?');
        $conn->bind($stmt, 'ii', [$userId, $id]);
        $row = $conn->fetchOne($stmt);

        return $row === null ? null : self::shown($row);
    }

    /**
     * The saved key an edit that typed none keeps, for the hosted service's
     * authentication and the row's own update: [key, null], or ['', the
     * page's sentence for why there is none]. Never another account's
     * network's key, nor one saved for another network than the edit names.
     *
     * @return array{string, string|null}
     */
    public static function keptKey(mysqli $db, int $userId, int $id, string $networkId): array
    {
        try {
            $stored = self::storedKey($db, $userId, $id);
        } catch (QueryException $failed) {
            error_log('DniNetworks: the saved key could not be read: ' . $failed->getMessage());

            return ['', 'The network could not be saved, and nothing changed; try again.'];
        }
        if ($stored === null) {
            return ['', 'That network was not found; nothing was changed.'];
        }
        if ((string) $stored['networkId'] !== $networkId) {
            return ['', 'A different network needs its own API key: paste it to save the change.'];
        }

        return [(string) $stored['apiKey'], null];
    }

    /**
     * What getDNICacheProgress() sends my.tracking202.com: the account's
     * install hash and its networks still importing their offers, with the
     * keys the service knows them by; null when the account has no row. The
     * poller used to post this from the page.
     *
     * @return array{host: string, install_hash: string,
     *     networks: list<array{id: int, networkId: string, api_key: string, type: string}>}|null
     * @throws QueryException
     */
    public static function progressRequest(mysqli $db, int $userId, string $host): ?array
    {
        $conn = new Connection($db);
        $stmt = $conn->prepareRead('SELECT install_hash FROM 202_users WHERE user_id = ?');
        $conn->bind($stmt, 'i', [$userId]);
        $account = $conn->fetchOne($stmt);
        if ($account === null) {
            return null;
        }
        $stmt = $conn->prepareRead('SELECT id, networkId, apiKey, type FROM 202_dni_networks'
            . ' WHERE user_id = ? AND processed = 0 ORDER BY id');
        $conn->bind($stmt, 'i', [$userId]);
        $networks = [];
        foreach ($conn->fetchAll($stmt) as $row) {
            $networks[] = [
                'id' => (int) $row['id'],
                'networkId' => (string) $row['networkId'],
                'api_key' => (string) $row['apiKey'],
                'type' => (string) $row['type'],
            ];
        }

        return ['host' => $host, 'install_hash' => (string) $account['install_hash'], 'networks' => $networks];
    }

    /** "Saved", and how the key ends when it is long enough to show that much of it. */
    public static function keyHint(int $length, string $end): string
    {
        if ($length <= 0) {
            return 'Not saved';
        }

        return $length >= self::SHOWN_ENDING_MIN_LENGTH ? 'Saved, ends in ' . $end : 'Saved';
    }

    /**
     * @return array<string, mixed>|null the network's id and key, as stored
     * @throws QueryException
     */
    private static function storedKey(mysqli $db, int $userId, int $id): ?array
    {
        $conn = new Connection($db);
        $stmt = $conn->prepareRead('SELECT networkId, apiKey FROM 202_dni_networks WHERE user_id = ? AND id = ?');
        $conn->bind($stmt, 'ii', [$userId, $id]);

        return $conn->fetchOne($stmt);
    }

    /**
     * @param array<string, mixed> $row
     * @return Shown
     */
    private static function shown(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'networkId' => (string) $row['networkId'],
            'name' => (string) $row['name'],
            'type' => (string) $row['type'],
            'affiliateId' => (string) ($row['affiliateId'] ?? ''),
            'processed' => (int) $row['processed'] !== 0,
            'shortDescription' => (string) $row['shortDescription'],
            'favIcon' => (string) $row['favIcon'],
            'keyHint' => self::keyHint((int) $row['key_length'], (string) $row['key_end']),
        ];
    }
}
