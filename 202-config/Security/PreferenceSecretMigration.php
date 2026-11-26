<?php

declare(strict_types=1);

namespace Prosper202\Security;

use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;

/**
 * Moves a plaintext secret column of 202_users_pref into the integration
 * secrets store, for the upgrade rung that then drops the column.
 *
 * Every account's value is stored as it is (readers hold it to their own
 * rule, as they held the column), in one transaction, so a failure leaves
 * none moved and the column in place. An account that already has the
 * secret in the store keeps that one: it was saved there later. Each value
 * moved is opened again and compared before the move is answered, so the
 * column is dropped only behind values the store gives back. Re-running
 * moves nothing twice and never seals a sealed value: the store's rows are
 * apart from the column, and a moved account is skipped.
 */
final class PreferenceSecretMigration
{
    private function __construct()
    {
    }

    /**
     * @return array{moved: int, kept: int} kept: accounts whose secret was already in the store
     * @throws \Throwable when any value could not be moved; then none was
     */
    public static function move(Connection $conn, IntegrationSecretStore $store, string $column, string $name, int $now): array
    {
        if (preg_match('/\A[a-z][a-z0-9_]*\z/D', $column) !== 1) {
            throw new \LogicException('"' . $column . '" is not a 202_users_pref column name');
        }

        return $conn->transaction(static function () use ($conn, $store, $column, $name, $now): array {
            $stmt = $conn->prepareWrite(
                'SELECT user_id, `' . $column . '` AS secret FROM `' . TableRegistry::USERS_PREF . '`'
                . ' WHERE `' . $column . '` IS NOT NULL ORDER BY user_id FOR UPDATE'
            );
            $moved = 0;
            $kept = 0;
            foreach ($conn->fetchAll($stmt) as $row) {
                $value = $row['secret'];
                if (!is_string($value) || trim($value) === '') {
                    continue;
                }
                $userId = (int) $row['user_id'];
                if (!$store->migrate($userId, $name, $value, $now)) {
                    ++$kept;
                    continue;
                }
                $back = $store->open($userId, $name, $now);
                if ($back === null || !hash_equals($value, $back->current)) {
                    throw new \RuntimeException('The ' . $name . ' of account ' . $userId . ' was sealed but does not read back as it was');
                }
                ++$moved;
            }

            return ['moved' => $moved, 'kept' => $kept];
        });
    }
}
